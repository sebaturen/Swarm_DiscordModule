<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */
namespace Slack\Service;

use Activity\Model\Activity;
use Activity\Model\IActivity;
use Attachments\Model\Attachment;
use Application\Config\IConfigDefinition as IDef;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Filter\Linkify;
use Application\Log\SwarmLogger;
use Exception;
use Interop\Container\ContainerInterface;
use Laminas\Http\Client;
use Laminas\Http\Request;
use Notifications\Model\INotification;
use P4\Log\SecretRedactor;
use P4\Spec\Change;
use Projects\Model\Project;
use Record\Key\AbstractKey;
use Reviews\Model\Review;
use Slack\Model\Message;
use Slack\Model\Slack as SlackModel;
use Slack\Service\IWorkspaceResolver;

/**
 * Class SlackService.
 * @package Slack\Service
 */
class Slack implements ISlack, InvokableService
{
    private $services;
    private $logger;
    private $utility;
    private $workspaceResolver;
    private const LOG_PREFIX = Slack::class;
    // Number of times to retry posting a reply whose image block references a
    // freshly uploaded file. Slack needs a few seconds to process larger
    // uploads before they can be referenced; with the escalating backoff in
    // sleep() this covers roughly 15 seconds of processing time.
    public const IMAGE_BLOCK_MAX_ATTEMPTS = 6;
    private $purePrivate;

    /**
     * Slack service constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services          = $services;
        $this->logger            = $services->get(SwarmLogger::SERVICE);
        $this->utility           = $services->get(IUtility::SERVICE_NAME);
        $this->workspaceResolver = $services->get(IWorkspaceResolver::SERVICE_NAME);
    }

    /**
     * @inheritDoc
     */
    public function handleCreateSlackMessage(Change $change, Activity $activity, $review = null, $projects = null)
    {
        $topic    = $this->getTopic($change, $review);
        $services = $this->services;
        $dao      = $services->get(IDao::SLACK_DAO);
        $p4       = $services->get(ConnectionFactory::P4_ADMIN);

        $this->logger->trace(
            sprintf("%s: Topic %s is running handleCreateSlackMessage", self::LOG_PREFIX, $topic)
        );
        $slackModelExists = $dao->exists($topic, $p4);

        // If we have a model just return early as we shouldn't be creating a new post if it exists.
        if ($slackModelExists) {
            return;
        }

        $type       = $change->getType();
        $workspaces = $this->workspaceResolver->getWorkspaces();
        $callouts   = Linkify::getCallouts($change->getDescription());
        foreach ($workspaces as $workspace) {
            $bypass = $workspace['bypass_restricted_changelist'] ?? false;
            if ($type !== Change::PUBLIC_CHANGE && !$bypass) {
                $this->logger->debug(
                    sprintf("%s: Not allowed to post private information...", self::LOG_PREFIX)
                );
                continue;
            }
            if ($projects === null) {
                $projects = $this->getProjects($change, $review);
            }

            // Check DM-only path first — before building channel mentions, which
            // requires expensive P4 DAO + Slack API calls that are wasted on the DM path.
            // Member IDs are workspace-scoped: resolve fresh per workspace so that
            // workspace B never reuses workspace A's Slack-org-specific member IDs.
            $notifyMentionedOnly = $workspace['notify_mentioned_only'] ?? false;
            if ($notifyMentionedOnly) {
                if (!empty($callouts)) {
                    $slackMemberIds = $this->utility
                        ->buildMentionsFromUserIds(
                            $callouts,
                            $workspace
                        );
                    if (!empty($slackMemberIds)) {
                        $message = new Message(
                            $this->services,
                            $change,
                            $projects,
                            $review,
                            $activity,
                            '',
                            $workspace
                        );
                        $this->dmMentionedUsers($slackMemberIds, $message, null, $workspace);
                        try {
                            $slackDao = $this->services->get(IDao::SLACK_DAO);
                            if (!$slackDao->exists($topic, $p4)) {
                                $emptyModel = new SlackModel($p4, $topic);
                                $emptyModel->setThreadIds([]);
                                $slackDao->save($emptyModel);
                            }
                        } catch (Exception $e) {
                            $this->logger->debug(
                                sprintf(
                                    '%s: could not save empty model for %s',
                                    self::LOG_PREFIX,
                                    $topic
                                )
                            );
                        }
                        continue;
                    }
                }
            }

            // Only resolve channel @mentions when the channel path will execute.
            $notifyConfig = $workspace['notify'] ?? [];
            $mentions     = $this->utility->buildMentions(
                $notifyConfig,
                $change,
                $projects,
                $review,
                $workspace
            );
            $message      = new Message(
                $this->services,
                $change,
                $projects,
                $review,
                $activity,
                $mentions,
                $workspace
            );

            $channels        = $workspace['project_channels'] ?? [];
            $channelThreads  = null;
            $projectChannels = $this->utility->getProjectMappings($projects, $channels, $this->purePrivate);
            $this->logger->trace(
                sprintf("%s: ProjectChannels are:", self::LOG_PREFIX)
                . json_encode($projectChannels, true)
            );
            foreach ($projectChannels as $channel => $linkedProjects) {
                $this->logger->debug(sprintf("%s: posting to %s", self::LOG_PREFIX, $channel));
                $response     = $this->postSlackMessage(
                    $message->getSummary($channel, $linkedProjects),
                    $workspace
                );
                $compositeKey = $workspace['id'] . ':' . $channel;
                $this->getChannelThread($channelThreads, $response, $compositeKey);
            }
            $this->createSlackModel($change, $channelThreads, $review);
        }
    }

    /**
     * @inheritDoc
     */
    public function handleMissingSlackMessage(
        Change $change,
        string $channel,
        Review $review,
        array $workspace,
        array $projects = null,
        array $linkedProjects = []
    ): ?string {
        $bypass = $workspace['bypass_restricted_changelist'] ?? false;
        $type   = $change->getType();
        if ($type !== Change::PUBLIC_CHANGE && !$bypass) {
            $this->logger->debug(sprintf("%s: Not allowed to post private information...", self::LOG_PREFIX));
            return null;
        }
        $message = new Message(
            $this->services,
            $change,
            $projects,
            $review,
            null,
            '',
            $workspace
        );

        $channelThreads = null;
        $this->logger->debug(sprintf("%s: posting to %s", self::LOG_PREFIX, $channel));
        $response     = $this->postSlackMessage(
            $message->getSummary($channel, $linkedProjects),
            $workspace
        );
        $compositeKey = $workspace['id'] . ':' . $channel;
        $this->getChannelThread($channelThreads, $response, $compositeKey);
        $this->createSlackModel($change, $channelThreads, $review);
        return $channelThreads[$compositeKey]["threadId"] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function handleThreadedSlackMessage(
        $change,
        $activity,
        Review $review = null,
        array $imageAttachments = []
    ) {
        $topic    = $this->getTopic($change, $review);
        $projects = $this->getProjects($change, $review);
        $services = $this->services;
        $dao      = $services->get(IDao::SLACK_DAO);
        $p4       = $services->get(ConnectionFactory::P4_ADMIN);

        $this->logger->trace(
            sprintf("%s: Topic %s is running handleThreadedSlackMessage", self::LOG_PREFIX, $topic)
        );
        $slackModelExists = $dao->exists($topic, $p4);

        // If we have no model for this topic attempt to create them.
        if (!$slackModelExists) {
            $this->logger->warn(sprintf("%s: No threaded ids for %s", self::LOG_PREFIX, $topic));
            $this->handleCreateSlackMessage($change, $activity, $review, $projects);
        }
        $slackModel = $dao->fetchById($topic, $p4);
        $threadIds  = $slackModel->getThreadIds();

        $workspaces = $this->workspaceResolver->getWorkspaces();
        foreach ($workspaces as $workspace) {
            $message             = new Message($this->services, $change, $projects, $review, null, '', $workspace);
            $notifyMentionedOnly = $workspace['notify_mentioned_only'] ?? false;
            if ($notifyMentionedOnly && $activity !== null) {
                $details  = $activity->getRawValue(IActivity::DETAILS);
                $desc     = (is_array($details) && !empty($details['transitionComment']))
                    ? (string) $details['transitionComment']
                    : (string) $activity->getRawValue(IActivity::DESCRIPTION);
                $callouts = Linkify::getCallouts($desc);
                if (!empty($callouts)) {
                    $slackMemberIds = $this->utility
                        ->buildMentionsFromUserIds(
                            $callouts,
                            $workspace
                        );
                    if (!empty($slackMemberIds)) {
                        $this->dmMentionedUsers($slackMemberIds, $message, $activity, $workspace);
                        continue;
                    }
                }
            }

            // The initial post was DM-only (empty threadIds saved by handleCreateSlackMessage).
            // Falling through to the channel loop would incorrectly trigger handleMissingSlackMessage
            // and create a new channel post, violating the DM-only guarantee.
            if ($notifyMentionedOnly && empty($threadIds)) {
                continue;
            }

            $channels        = $workspace['project_channels'] ?? [];
            $projectChannels = $this->utility->getProjectMappings($projects, $channels, $this->purePrivate);
            $this->logger->trace(
                sprintf("%s: ProjectChannels are:", self::LOG_PREFIX)
                . json_encode($projectChannels, true)
            );
            foreach ($projectChannels as $channel => $linkedProjects) {
                $this->logger->debug(sprintf("%s: posting to %s", self::LOG_PREFIX, $channel));
                $compositeKey = $workspace['id'] . ':' . $channel;
                $parent       = $threadIds[$compositeKey]['threadId']
                    ?? (IWorkspaceResolver::DEFAULT_ID === $workspace['id']
                        ? ($threadIds[$channel]['threadId'] ?? null)
                        : null);
                if ($parent) {
                    $reply = $message->getReply($activity, $channel, $parent, $linkedProjects);
                    $this->appendImageBlocks($reply, $imageAttachments, $workspace);
                    $this->postReply($reply, $workspace);
                } else {
                    // handleMissingSlackMessage requires a non-nullable Review;
                    // commit-based events (no review) cannot recover a missing thread.
                    if ($review !== null) {
                        $parent = $this->handleMissingSlackMessage(
                            $change,
                            $channel,
                            $review,
                            $workspace,
                            $projects,
                            $linkedProjects
                        );
                        if ($parent) {
                            $reply = $message->getReply($activity, $channel, $parent, $linkedProjects);
                            $this->appendImageBlocks($reply, $imageAttachments, $workspace);
                            $this->postReply($reply, $workspace);
                        } else {
                            $this->logger->warn(
                                sprintf("%s: No threaded ids for %s", self::LOG_PREFIX, $channel)
                            );
                        }
                    } else {
                        $this->logger->warn(
                            sprintf("%s: No threaded ids for %s", self::LOG_PREFIX, $channel)
                        );
                    }
                }
            }
        }
    }

    /**
     * Post a threaded reply, accounting for the delay between uploading an
     * image and Slack finishing processing it. A freshly uploaded large
     * file referenced from an image block can be rejected with an
     * "invalid_blocks" error for a second or two while Slack processes it,
     * so we retry with a short backoff. If the image is still not ready
     * after the final attempt we post the reply without the image blocks so
     * the comment notification itself is never lost.
     *
     * @param  array $reply The chat.postMessage body to post.
     *
     * @return void
     */
    private function postReply(array $reply, array $workspace): void
    {
        $hasImage = (bool) array_filter(
            $reply['blocks'] ?? [],
            static fn($block) => ($block['type'] ?? '') === 'image'
        );
        if (!$hasImage) {
            $this->postSlackMessage($reply, $workspace);
            return;
        }
        $maxAttempts = self::IMAGE_BLOCK_MAX_ATTEMPTS;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->postSlackMessage($reply, $workspace);
            if (!(is_object($response) && ($response->error ?? null) === 'invalid_blocks')) {
                return;
            }
            $this->logger->debug(
                sprintf(
                    "%s: image not ready yet (attempt %d/%d), retrying",
                    self::LOG_PREFIX,
                    $attempt,
                    $maxAttempts
                )
            );
            if ($attempt < $maxAttempts) {
                $this->sleep($attempt);
            }
        }
        // Image never became ready - post the comment text without the images.
        $reply['blocks'] = array_values(
            array_filter(
                $reply['blocks'] ?? [],
                static fn($block) => ($block['type'] ?? '') !== 'image'
            )
        );
        $this->postSlackMessage($reply, $workspace);
        $this->logger->warn(
            sprintf(
                "%s: posted comment without inline image after %d attempts",
                self::LOG_PREFIX,
                $maxAttempts
            )
        );
    }

    /**
     * Sleep wrapper so the retry backoff can be stubbed out in tests.
     *
     * @param  int $seconds Number of seconds to sleep.
     *
     * @return void
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Upload each web-safe image attachment to Slack and append an image
     * block referencing it to the given message body, so the images render
     * inline within the same comment message rather than as separate file
     * posts. Slack image blocks accept a `slack_file` reference by file id,
     * which avoids the need for a publicly reachable image URL.
     *
     * @param  array $reply            The chat.postMessage body to add blocks to.
     * @param  array $imageAttachments Web-safe image Attachment objects to upload.
     *
     * @return void
     */
    private function appendImageBlocks(array &$reply, array $imageAttachments, array $workspace): void
    {
        foreach ($imageAttachments as $attachment) {
            $fileId = $this->uploadAttachmentToSlack($attachment, $workspace);
            if ($fileId) {
                $reply['blocks'][] = [
                    "type"       => "image",
                    "slack_file" => ["id" => $fileId],
                    "alt_text"   => (string) $attachment->get(Attachment::FIELD_NAME),
                ];
            }
        }
    }

    /**
     * Upload an image attachment to Slack as a native file and return its
     * file id. Executes three steps: (1) fetch an upload URL via
     * files.getUploadURLExternal, (2) POST the raw bytes to that URL, and
     * (3) finalise via files.completeUploadExternal *without* a channel so
     * the file is created but not posted as its own message - it is instead
     * referenced from an image block on the comment message. Any failure is
     * logged and null is returned so the parent notification is not aborted.
     *
     * @param  Attachment $attachment The attachment record to upload.
     *
     * @return string|null The Slack file id, or null on failure.
     */
    public function uploadAttachmentToSlack(Attachment $attachment, array $workspace): ?string
    {
        $token   = $workspace[IDef::TOKEN] ?? '';
        $headers = ["Authorization: Bearer " . $token];
        try {
            $name     = $attachment->get(Attachment::FIELD_NAME);
            $size     = (int) $attachment->get(Attachment::FIELD_SIZE);
            $endpoint = "https://slack.com/api/files.getUploadURLExternal";
            $response = $this->postSlackAPI(
                $endpoint,
                $headers,
                ['filename' => $name, 'length' => $size],
                false
            );
            if (empty($response->upload_url) || empty($response->file_id)) {
                $this->logger->err(
                    sprintf(
                        "%s: No upload URL returned for %s",
                        self::LOG_PREFIX,
                        $name
                    )
                );
                return null;
            }
            $uploadUrl = $response->upload_url;
            $fileId    = $response->file_id;
            $this->logger->debug(
                sprintf("%s: Got upload URL for %s (file_id=%s)", self::LOG_PREFIX, $name, $fileId)
            );
            $depotFile = $attachment->get(Attachment::FIELD_DEPOT_FILE);
            $mimeType  = $attachment->get(Attachment::FIELD_TYPE);
            $bytes     = $this->services
                ->get('depot_storage')
                ->read($depotFile);
            if (!$this->uploadBinaryToUrl($uploadUrl, $bytes, $mimeType)) {
                $this->logger->err(
                    sprintf(
                        "%s: Failed to upload binary for %s",
                        self::LOG_PREFIX,
                        $name
                    )
                );
                return null;
            }
            $this->logger->debug(sprintf("%s: Uploaded binary for %s", self::LOG_PREFIX, $name));
            $jsonHeaders = array_merge(
                $headers,
                ["Content-type: application/json"]
            );
            $complete    = $this->postSlackAPI(
                "https://slack.com/api/files.completeUploadExternal",
                $jsonHeaders,
                json_encode(['files' => [['id' => $fileId]]]),
                true
            );
            if (isset($complete->ok) && $complete->ok === false) {
                return null;
            }
            $this->logger->debug(
                sprintf("%s: completeUploadExternal done for %s", self::LOG_PREFIX, $name)
            );
            return $fileId;
        } catch (\Exception $e) {
            $this->logger->err(
                sprintf(
                    "%s: uploadAttachmentToSlack failed: %s",
                    self::LOG_PREFIX,
                    $e->getMessage()
                )
            );
            return null;
        }
    }

    /**
     * Upload raw binary content to a Slack upload URL using an HTTP POST
     * request. Slack's files.getUploadURLExternal flow requires the bytes
     * to be sent with POST; a PUT is accepted with a 2xx status but leaves
     * the file unprocessed (empty mimetype) so it is never shared into the
     * channel, which is why the method must use POST.
     *
     * @param  string $uploadUrl The URL to POST the bytes to.
     * @param  string $bytes     The raw binary content to send.
     * @param  string $mimeType  The MIME type of the content.
     *
     * @return bool True if the server responded with a 2xx status code.
     */
    protected function uploadBinaryToUrl(
        string $uploadUrl,
        string $bytes,
        string $mimeType
    ): bool {
        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        $request->setUri($uploadUrl);
        $request->getHeaders()->addHeaders(['Content-Type: ' . $mimeType]);
        $request->setContent($bytes);
        $client = new Client();
        $client->setUri($uploadUrl);
        $config  = $this->services->get(IDef::CONFIG);
        $options = (array) ($config['http_client_options'] ?? []);
        if (isset($options['hosts'][$client->getUri()->getHost()])) {
            $options = (array) $options['hosts'][$client->getUri()->getHost()] + $options;
        }
        unset($options['hosts']);
        $client->setOptions($options);
        $response = $client->dispatch($request);
        return $response->isSuccess();
    }

    /**
     * @inheritDoc
     */
    public function postSlackMessage(array $body, array $workspace)
    {
        $this->logger->debug(sprintf("%s: posting message", self::SERVICE_NAME));
        $token      = $workspace[IDef::TOKEN] ?? '';
        $userConfig = $workspace[IDef::USER] ?? [];
        $enabled    = $userConfig[IDef::ENABLED] ?? true;
        $user       = null;
        $icon       = null;

        if ($enabled === true) {
            $name            = $userConfig[IDef::NAME] ?? null;
            $icon            = $userConfig[IDef::ICON] ?? null;
            $forceUserHeader = $userConfig[IDef::FORCE_USER_HEADER] ?? false;
            $user            = $name;
            if ($forceUserHeader === true) {
                $user = $user . "-" . $this->getTime();
            }
        }

        $body['username'] = $user;
        $body['icon_url'] = $icon;
        $endpoint         = "https://slack.com/api/chat.postMessage";
        $headers          = [
            "Content-type: application/json",
            "Authorization: Bearer " . $token,
        ];

        $json = json_encode($body);
        return $this->postSlackAPI($endpoint, $headers, $json);
    }

    /**
     * This is for only tests to be mock.
     * @return int
     */
    public function getTime(): int
    {
        return time();
    }

    /**
     * @inheritDoc
     */
    public function postSlackAPI($endpoint, $headers, $body, bool $json = true)
    {
        $config = $this->services->get(IDef::CONFIG);
        $this->logger->debug(sprintf("%s: posting to endpoint %s", self::SERVICE_NAME, $endpoint));
        $response = new \stdClass();
        try {
            $request = new Request();
            $request->setMethod(Request::METHOD_POST);
            $request->setUri($endpoint);
            $request->getHeaders()->addHeaders($headers);

            if ($json === true) {
                $request->setContent($body);
            } else {
                foreach ($body as $key => $value) {
                    $request->getPost()->set($key, $value);
                }
            }
            // the Authorization header carries the Slack bot token
            $this->logger->debug(
                sprintf(
                    "%s: request headers %s",
                    self::SERVICE_NAME,
                    json_encode(SecretRedactor::redactHeaders($headers))
                )
            );
            $this->logger->debug(sprintf("%s: request body %s", self::SERVICE_NAME, $body));

            $client = new Client();
            $client->setEncType(Client::ENC_FORMDATA);
            $client->setUri($endpoint)
                   ->setHeaders($headers);

            // Set the http client options; including any special overrides for our host
            $options = $config + [
                'http_client_options' => []
                ];
            $options = (array)$options['http_client_options'];
            $this->logger->trace(sprintf("%s: slack host %s", self::SERVICE_NAME, $client->getUri()->getHost()));
            if (isset($options['hosts'][$client->getUri()->getHost()])) {
                $this->logger->trace(
                    sprintf(
                        "%s: slack host matched applying options for %s",
                        self::SERVICE_NAME,
                        $client->getUri()->getHost()
                    )
                );
                $options = (array)$options['hosts'][$client->getUri()->getHost()] + $options;
            }
            unset($options['hosts']);
            $client->setOptions($options);

            // POST request
            $response = $client->dispatch($request);
            $this->logger->debug(sprintf("%s: slack response %s", self::SERVICE_NAME, $response->getBody()));

            if (!$response->isSuccess()) {
                $this->logger->err(
                    sprintf(
                        "%s: failed to post to slack endpoint %s, error code %s, reason %s",
                        self::SERVICE_NAME,
                        $endpoint,
                        $response->getStatusCode(),
                        $response->getReasonPhrase()
                    )
                );
            }
            // Decode the response so we can access data.
            $response = json_decode($response->getBody());
            if (isset($response->ok) && $response->ok === false) {
                $this->logger->err(
                    sprintf(
                        "%s: Slack API error from %s: %s",
                        self::SERVICE_NAME,
                        $endpoint,
                        $response->error ?? 'unknown'
                    )
                );
            }
        } catch (Exception $e) {
            $this->logger->err(sprintf("%s: unexpected error %s", self::SERVICE_NAME, $e->getMessage()));
        }
        return $response;
    }

    /**
     * Common function to get the channelThreads
     *
     * @param array|null $channelThreads The array of channels and their thread ids.
     * @param object     $response       The response from the request.
     * @param string     $channel        The channel we are working with.
     * @return void
     */
    private function getChannelThread(?array &$channelThreads, object $response, string $channel)
    {
        $parentThread = $response->ts ?? null;
        if ($parentThread) {
            $channelThreads[$channel]["threadId"] = $parentThread;
        } else {
            $this->logger->debug(
                sprintf(
                    "%s: found no thread id for post response for %s",
                    self::LOG_PREFIX,
                    $channel
                )
            );
        }
    }

    /**
     * If we have ChannelThreads to create we should do that now.
     *
     * @param Change      $change         - The changelist object
     * @param array|null  $channelThreads - The array of channels and their thread ids.
     * @param Review|null $review         - If set, the changelist object represents a review rather than a change
     * @return void
     */
    private function createSlackModel(Change $change, ?array $channelThreads, ?Review $review)
    {
        if ($channelThreads) {
            $id = $review
                ? INotification::REVIEW_TOPIC . $review->getId() : INotification::CHANGE_TOPIC . $change->getId();
            try {
                $this->logger->debug(sprintf("%s: Creating a thread ID for %s", self::LOG_PREFIX, $id));
                $services   = $this->services;
                $p4         = $services->get(ConnectionFactory::P4_ADMIN);
                $slackDao   = $this->services->get(IDao::SLACK_DAO);
                $slackExist = $slackDao->exists($id, $p4);
                if ($slackExist) {
                    $slack     = $slackDao->fetchById($id, $p4);
                    $threadIds = $slack->getThreadIds();
                    $slack->setThreadIds(array_merge($channelThreads, $threadIds));
                } else {
                    $slack = new SlackModel($p4, $id);
                    $slack->setThreadIds($channelThreads);
                }
                $slackDao->save($slack);
            } catch (Exception $saveThreadError) {
                $this->logger->debug(sprintf("%s: failed to create a thread ID for %s", self::LOG_PREFIX, $id));
                $this->logger->debug($saveThreadError->getMessage());
            }
        }
    }

    /**
     * If the review is not null get the projects from the review, otherwise find the affected projects
     * based on the change
     * @param Change $change the change
     * @param mixed $review the review
     * @return mixed
     */
    private function getProjects(Change $change, $review = null)
    {
        $services = $this->services;
        $p4       = $services->get(ConnectionFactory::P4_ADMIN);

        if ($review !== null) {
            $projects = $review->getProjects();
        } else {
            $affected = $services->get(Services::AFFECTED_PROJECTS);
            $projects = $affected->findByChange($p4, $change);
        }
        // If we have projects we will fetch all the projects and filter out private projects. If we are returned no
        // projects we can assume that all projects are private. If one is returned then one of the linked projects is
        // public, so we can publish the message to the public.
        if ($projects) {
            $projectDAO        = $services->get(IDao::PROJECT_DAO);
            $this->purePrivate = !$projectDAO->fetchAll(
                [
                    AbstractKey::FETCH_BY_IDS => array_unique(array_keys($projects)),
                    Project::FETCH_INCLUDE_PRIVATE => false
                ],
                $p4
            )->count();
        }
        return $projects;
    }

    /**
     * Return the topic.
     *
     * @param Change      $change - The changelist object
     * @param Review|null $review - If set, the changelist object represents a review rather than a change
     * @return string
     */
    public function getTopic(Change $change, ?Review $review): string
    {
        return $review
            ? INotification::REVIEW_TOPIC . $review->getId() : INotification::CHANGE_TOPIC . $change->getId();
    }

    /**
     * Open a Slack DM channel with the given member and return its ID.
     *
     * @param  string $slackUserId  The Slack member ID to DM.
     *
     * @return string|null The DM channel ID or null on failure.
     */
    private function openDmChannel(string $slackUserId, array $workspace): ?string
    {
        $token    = $workspace[IDef::TOKEN] ?? '';
        $headers  = [
            'Content-type: application/json',
            'Authorization: Bearer ' . $token,
        ];
        $body     = json_encode(['users' => $slackUserId]);
        $response = $this->postSlackAPI(
            'https://slack.com/api/conversations.open',
            $headers,
            $body
        );
        if ($response && isset($response->ok) && $response->ok
            && isset($response->channel->id)
        ) {
            return $response->channel->id;
        }
        $this->logger->warn(
            sprintf(
                '%s: could not open DM channel for %s',
                self::LOG_PREFIX,
                $slackUserId
            )
        );
        return null;
    }

    /**
     * Post a Slack message to each mentioned user via a direct message.
     *
     * @param  array         $slackMemberIds  Resolved Slack member ID strings.
     * @param  Message       $message         The message to send.
     * @param  Activity|null $activity        Activity for reply; null uses summary.
     *
     * @return void
     */
    private function dmMentionedUsers(
        array $slackMemberIds,
        Message $message,
        ?Activity $activity,
        array $workspace
    ): void {
        $total = count($slackMemberIds);
        $i     = 0;
        foreach ($slackMemberIds as $memberId) {
            $i++;
            $channelId = $this->openDmChannel($memberId, $workspace);
            if ($channelId === null) {
                continue;
            }
            $payload = $activity === null
                ? $message->getSummary($channelId, [])
                : $message->getReply($activity, $channelId, '', []);
            $this->postSlackMessage($payload, $workspace);
            if ($i < $total) {
                $this->sleep(1);
            }
        }
    }
}
