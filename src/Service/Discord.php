<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Discord\Service;

use Activity\Model\Activity;
use Activity\Model\IActivity;
use Application\Config\IConfigDefinition as IDef;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Discord\Model\Discord as DiscordModel;
use Discord\Model\DiscordDAO;
use Exception;
use Interop\Container\ContainerInterface;
use Laminas\Http\Client;
use Laminas\Http\Request;
use Notifications\Model\INotification;
use P4\Spec\Change;
use Projects\Model\Project;
use Record\Key\AbstractKey;
use Reviews\Model\Review;

class Discord implements InvokableService
{
    const LOG_PREFIX    = Discord::class;
    const API           = 'https://discord.com/api/v10';
    const CONTENT_LIMIT = 2000;
    const THREAD_LIMIT  = 100;
    const COMMITTED     = 'committed';

    private $services;
    private $logger;
    private $utility;
    private $config;
    private $purePrivate = false;

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->logger   = $services->get(SwarmLogger::SERVICE);
        $this->utility  = $services->get(Utility::class);
        $this->config   = $services->get(IDef::CONFIG)['discord'] ?? [];
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['token'] ?? '')) !== '';
    }

    public function handleCreateMessage(
        Change $change,
        Activity $activity,
        Review $review = null,
        $projects = null
    ): void {
        $topic = $this->getTopic($change, $review);
        $dao   = $this->services->get(DiscordDAO::class);
        $p4    = $this->services->get(ConnectionFactory::P4_ADMIN);
        if ($dao->exists($topic, $p4) || !$this->isAllowed($change)) {
            return;
        }
        $projects = $projects ?? $this->getProjects($change, $review);
        $mentions = $this->utility->buildMentions($this->config, $change, $projects, $review);
        $threads  = [];
        foreach ($this->getChannels($projects, $review) as $channel => $linkedProjects) {
            $threadId = $this->postSummary($change, $review, $activity, $projects, $linkedProjects, $channel, $mentions);
            if ($threadId) {
                $threads[$channel] = ['threadId' => $threadId];
            }
        }
        $this->saveThreads($topic, $threads);
    }

    public function handleThreadedMessage(Change $change, Activity $activity, Review $review): void
    {
        $topic    = $this->getTopic($change, $review);
        $projects = $this->getProjects($change, $review);
        $dao      = $this->services->get(DiscordDAO::class);
        $p4       = $this->services->get(ConnectionFactory::P4_ADMIN);
        if (!$dao->exists($topic, $p4)) {
            $this->handleCreateMessage($change, $activity, $review, $projects);
        }
        $threads = $dao->exists($topic, $p4) ? $dao->fetchById($topic, $p4)->getThreadIds() : [];
        $created = [];
        foreach ($this->getChannels($projects, $review) as $channel => $linkedProjects) {
            $threadId = $threads[$channel]['threadId'] ?? null;
            if (!$threadId && $this->isAllowed($change)) {
                $threadId = $this->postSummary($change, $review, null, $projects, $linkedProjects, $channel);
                if ($threadId) {
                    $created[$channel] = ['threadId' => $threadId];
                }
            }
            if ($threadId) {
                $text      = $this->utility->buildActivityReply($activity, (array) $projects, $linkedProjects);
                $committed = $activity->getRawValue(IActivity::ACTION) === self::COMMITTED;
                if ($committed) {
                    $text .= $this->utility->buildCommitLink($review);
                }
                $this->post(
                    "/channels/$threadId/messages",
                    [
                        'content'          => $this->utility->truncate($text, self::CONTENT_LIMIT),
                        'allowed_mentions' => ['parse' => []],
                    ]
                );
                if ($committed) {
                    $this->post("/channels/$threadId", ['archived' => true], Request::METHOD_PATCH);
                }
            }
        }
        $this->saveThreads($topic, $created);
    }

    private function postSummary(
        Change $change,
        ?Review $review,
        ?Activity $activity,
        ?array $projects,
        array $linkedProjects,
        $channel,
        string $mentions = ''
    ): ?string {
        $title = $this->utility->buildTitle($change, $review, $activity);
        $body  = [
            'embeds'           => [
                $this->utility->buildEmbed($change, $review, $title, $projects, $linkedProjects, $this->config)
            ],
            'allowed_mentions' => ['parse' => ['users']],
        ];
        if ($mentions !== '') {
            $body['content'] = $mentions;
        }
        $messageId = $this->post("/channels/$channel/messages", $body)['id'] ?? null;
        if (!$messageId || !$review) {
            return $messageId;
        }
        $thread = $this->post(
            "/channels/$channel/messages/$messageId/threads",
            [
                'name'                  => mb_substr(
                    sprintf("[%s] %s", $change->getId(), strtok(trim((string) $change->getDescription()), "\n")),
                    0,
                    self::THREAD_LIMIT
                ),
                'auto_archive_duration' => 10080,
            ]
        );
        return $thread['id'] ?? null;
    }

    private function post(string $path, array $body, string $method = Request::METHOD_POST): array
    {
        try {
            $request = new Request();
            $request->setMethod($method);
            $request->setUri(self::API . $path);
            $request->getHeaders()->addHeaders(
                [
                    'Authorization' => 'Bot ' . $this->config['token'],
                    'Content-Type'  => 'application/json',
                    'User-Agent'    => 'DiscordBot (https://www.perforce.com, 1.0)',
                ]
            );
            $request->setContent(json_encode($body));

            $options = (array) ($this->services->get(IDef::CONFIG)['http_client_options'] ?? []);
            $host    = $request->getUri()->getHost();
            if (isset($options['hosts'][$host])) {
                $options = (array) $options['hosts'][$host] + $options;
            }
            unset($options['hosts']);
            $client = new Client();
            $client->setOptions($options);

            $response = $client->dispatch($request);
            if (!$response->isSuccess()) {
                $this->logger->err(
                    sprintf(
                        "%s: %s %s failed with %s: %s",
                        self::LOG_PREFIX,
                        $method,
                        $path,
                        $response->getStatusCode(),
                        $response->getBody()
                    )
                );
                return [];
            }
            return (array) json_decode($response->getBody(), true);
        } catch (Exception $e) {
            $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
            return [];
        }
    }

    private function saveThreads(string $topic, array $threads): void
    {
        if (!$threads) {
            return;
        }
        $dao = $this->services->get(DiscordDAO::class);
        $p4  = $this->services->get(ConnectionFactory::P4_ADMIN);
        try {
            if ($dao->exists($topic, $p4)) {
                $model = $dao->fetchById($topic, $p4);
                $model->setThreadIds($threads + $model->getThreadIds());
            } else {
                $model = new DiscordModel($p4, $topic);
                $model->setThreadIds($threads);
            }
            $dao->save($model);
        } catch (Exception $e) {
            $this->logger->err(sprintf("%s: failed to save threads for %s: %s", self::LOG_PREFIX, $topic, $e->getMessage()));
        }
    }

    private function isAllowed(Change $change): bool
    {
        return $change->getType() === Change::PUBLIC_CHANGE || !empty($this->config['bypass_restricted_changelist']);
    }

    private function getChannels(?array $projects, ?Review $review): array
    {
        return $this->utility->getProjectMappings(
            (array) $projects,
            $this->config['project_channels'] ?? [],
            $review ? Utility::TYPE_REVIEW : Utility::TYPE_SUBMIT,
            $this->purePrivate
        );
    }

    private function getProjects(Change $change, ?Review $review)
    {
        $p4 = $this->services->get(ConnectionFactory::P4_ADMIN);
        if ($review !== null) {
            $projects = $review->getProjects();
        } else {
            $projects = $this->services->get(Services::AFFECTED_PROJECTS)->findByChange($p4, $change);
        }
        if ($projects) {
            $this->purePrivate = !$this->services->get(IDao::PROJECT_DAO)->fetchAll(
                [
                    AbstractKey::FETCH_BY_IDS      => array_unique(array_keys($projects)),
                    Project::FETCH_INCLUDE_PRIVATE => false
                ],
                $p4
            )->count();
        }
        return $projects;
    }

    private function getTopic(Change $change, ?Review $review): string
    {
        return $review
            ? INotification::REVIEW_TOPIC . $review->getId()
            : INotification::CHANGE_TOPIC . $change->getId();
    }
}
