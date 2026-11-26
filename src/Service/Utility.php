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
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition as IDef;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Exception;
use Groups\Model\Group;
use Interop\Container\ContainerInterface;
use InvalidArgumentException;
use Laminas\Http\Client;
use Laminas\Http\Request;
use Mail\MailAction;
use P4\Spec\Change;
use Projects\Model\Project;
use Redis\RedisService;
use Reviews\Model\IReview;
use Reviews\Model\Review;
use Users\Model\IUser;

/**
 * SlackUtility function to be used by other services.
 * @package Slack\Service
 */
class Utility implements IUtility, InvokableService
{
    private $services;
    private $config;
    private array $slackUserIdCache = [];

    /**
     * Slack Utility constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->config   = $this->services->get(IDef::CONFIG);
    }

    /**
     * @inheritDoc
     */
    public function getFileNames(Change $change, array $workspace = []) : array
    {
        $limit     = $workspace['summary_file_limit'] ?? 10;
        $fileNames = [];
        $files     = $change->getFileData($change->isPending());
        $count     = count($files);
        if ($count > $limit) {
            $files = array_slice($files, 0, $limit);
        }
        array_map(
            function ($file) use (&$fileNames) {
                $fileNames[] = $file['depotFile'] . '#' . $file['rev'];
            },
            $files
        );
        return [self::FILES => $fileNames, self::COUNT => $count];
    }

    /**
     * @inheritDoc
     */
    public function getHostName(): string
    {
        $hostname    = ConfigManager::getValue($this->config, IDef::ENVIRONMENT_HOSTNAME);
        $externalURL = ConfigManager::getValue($this->config, IDef::ENVIRONMENT_EXTERNAL_URL);

        if ($externalURL !== null) {
            return $externalURL;
        }
        return "http://" . $hostname;
    }

    /**
     * @inheritDoc
     */
    public function buildActivityReply(Activity $activity, array $projects, array $linkedProjects = []): string
    {
        $userDao = $this->services->get(IDao::USER_DAO);
        $p4      = $this->services->get(ConnectionFactory::P4_ADMIN);

        $userId = $activity->getRawValue(IActivity::USER);
        $action = $activity->getRawValue(IActivity::ACTION);
        $target = $activity->getRawValue(IActivity::TARGET);
        // If we are working with a description from a comment
        $rawDescription = $activity->getRawValue(IActivity::DESCRIPTION);
        $description    = '';
        switch ($action) {
            case MailAction::COMMENT_ADDED:
            case MailAction::COMMENT_EDITED:
            case MailAction::COMMENT_REPLY:
            case MailAction::DESCRIPTION_COMMENT_ADDED:
            case MailAction::DESCRIPTION_COMMENT_EDITED:
                $description = $rawDescription;
                break;
            // Fix for SW-12277: include transition comment in Slack notifications
            case MailAction::REVIEW_NEEDS_REVIEW:
            case MailAction::REVIEW_NEEDS_REVISION:
            case MailAction::REVIEW_APPROVED:
            case MailAction::REVIEW_REJECTED:
            case MailAction::REVIEW_ARCHIVED:
                $details = $activity->getRawValue(IActivity::DETAILS);
                if (is_array($details) && isset($details['transitionComment'])) {
                    $description = $details['transitionComment'];
                }
                break;
        }

        // This is put in as we noticed that we were getting errors when deploy system updated tasks.
        $fullName = "Helix Swarm";
        if ($userDao->exists($userId, $p4)) {
            $user     = $userDao->fetchById($userId, $p4);
            $fullName = $user->get(IUser::FIELD_FULLNAME);
        }

        $projects = count($projects) ? " for" . $this->buildProjectText($projects, false, $linkedProjects) : "";

        // Build vote context if vote counts are available in activity details.
        $voteContext = '';
        $details     = $activity->getRawValue(IActivity::DETAILS);
        if (is_array($details) && isset($details['voteCounts'])) {
            $up          = (int) ($details['voteCounts']['up'] ?? 0);
            $down        = (int) ($details['voteCounts']['down'] ?? 0);
            $voteContext = sprintf(" (Votes: %d up, %d down)", $up, $down);
        }

        return sprintf(
            "%s (%s) %s %s%s%s%s",
            $userId,
            $fullName,
            $action,
            $target,
            $voteContext,
            $projects,
            $description ? ":\n" . $description : ""
        );
    }

    /**
     * @inheritDoc
     */
    public function getProjectMappings($projectList, $mappingList, $purePrivate = false): array
    {
        $result = [];

        foreach ($projectList as $project => $branches) {
            foreach ($mappingList as $type => $channels) {
                if ($project === $type) {
                    foreach ($channels as $channel) {
                        if (!isset($result[$channel])) {
                            $result[$channel] = [];
                        }
                        $result[$channel] = array_merge($result[$channel], [$project]);
                    }
                }
            }
        }

        // If we have purePrivate = true, then we shouldn't attempt to include the hardcode Slack notification. If we
        // are false then we can assess if we want to send notifications to one of the three config options.
        if (!$purePrivate) {
            if (empty($result)) {
                if (empty($projectList)) { // The review doesn't have a project, use the *empty* mappings
                    if (array_key_exists(self::NO_ASSOCIATED_PROJECTS_CHANNELS, $mappingList)) {
                        foreach ($mappingList[self::NO_ASSOCIATED_PROJECTS_CHANNELS] as $noAssociatedProjectsChannel) {
                            if (!isset($result[$noAssociatedProjectsChannel])) {
                                $result[$noAssociatedProjectsChannel] = [];
                            }
                            $result[$noAssociatedProjectsChannel] = array_merge(
                                $result[$noAssociatedProjectsChannel],
                                [IUtility::NO_PROJECT_CHANNEL]
                            );
                        }
                    }
                } else { // The review has a project, but it's not specified, use the *default* mappings
                    if (array_key_exists(self::DEFAULT_PROJECTS_CHANNELS, $mappingList)) {
                        foreach ($mappingList[self::DEFAULT_PROJECTS_CHANNELS] as $noMappedProjectsChannel) {
                            if (!isset($result[$noMappedProjectsChannel])) {
                                $result[$noMappedProjectsChannel] = [];
                            }
                            $result[$noMappedProjectsChannel] = array_merge(
                                $result[$noMappedProjectsChannel],
                                [IUtility::NO_PROJECT_CHANNEL]
                            );
                        }
                    }
                }
            }

            if (array_key_exists(self::ALL_NOTIFICATIONS_CHANNELS, $mappingList)) {
                foreach ($mappingList[self::ALL_NOTIFICATIONS_CHANNELS] as $allChannel) {
                    if (!isset($result[$allChannel])) {
                        $result[$allChannel] = [];
                    }
                    // Add '*' mappings for all reviews (regardless of above)
                    $result[$allChannel] = array_merge(
                        $result[$allChannel],
                        [IUtility::NO_PROJECT_CHANNEL]
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function buildProjectText(?array $projects, $markdown = true, array $linkedProjects = []): string
    {
        $projectDao    = $this->services->get(IDao::PROJECT_DAO);
        $p4            = $this->services->get(ConnectionFactory::P4_ADMIN);
        $text          = '';
        $newLine       = ($markdown ? "\n" : "");
        $totalProjects = count((array) $projects);
        if ($projects && $totalProjects > 0) {
            $text         = $markdown ? "*Projects*: " . "\n" : "";
            $projectCount = 0;
            foreach ($projects as $project => $branches) {
                $projectCount++;
                $projectModel    = $projectDao->fetchById($project, $p4);
                $projectBranches = $projectModel->getBranches();
                $projectName     = $projectModel->getName();
                $isPrivate       = $projectModel->isPrivate();
                // If not private create text.
                if (in_array($project, $linkedProjects) || !$isPrivate) {
                    foreach ($branches as $branch) {
                        try {
                            $branchData = $projectModel->getBranch($branch, $projectBranches);
                            $name       = $branchData[Project::FIELD_NAME];
                        } catch (InvalidArgumentException $e) {
                            $name = $branch;
                        }
                        $text = $text . " " . $projectName . ":" . $name;
                        $text = $text . (!$markdown && $projectCount < $totalProjects ?  "," : $newLine);
                    }
                }
            }
        }
        return $text;
    }

    /**
     * @inheritDoc
     */
    public function buildFileNames(Change $change, array $workspace = []): string
    {
        $text             = '';
        $summaryFileNames = $workspace['summary_file_names'] ?? false;

        if ($summaryFileNames === true) {
            $fileData = $this->getFileNames($change, $workspace);
            $files    = $fileData[IUtility::FILES];
            $text     = $text . "*" . $fileData[IUtility::COUNT] . " file"
                . ($fileData[IUtility::COUNT] !== 1 ? "s have" : " has") . " been changed*";
            if ($fileData[IUtility::COUNT] > 0) {
                $text = $text . ":";
                foreach ($files as $file) {
                    $text = $text . "\n" . $file;
                }
                // Total count of files was bigger than the list returned, add an indicator that there are more to list
                if ($fileData[IUtility::COUNT] > count($files)) {
                    $text = $text . "\n...";
                }
            }
        }
        return $text;
    }

    /**
     * @inheritDoc
     */
    public function buildTitle(Change $change, Review $review = null, Activity $activity = null): string
    {
        $user     = $change->getUser();
        $userDao  = $this->services->get(IDao::USER_DAO);
        $title    = '';
        $fullName = $userDao->fetchById($user)->getRawValue(IUser::FIELD_FULLNAME);

        if ($review !== null) {
            // U+2060 word joiner after '#' prevents Slack from parsing '#NNNNNN' as a hex color (SW-10467)
            $title = sprintf("%s (%s) requested review #\u{2060}%s", $fullName, $user,  $review->getId());
        } else {
            if ($activity && $activity->get('behalfOf')) {
                $activityUser     = $activity->get('user');
                $activityFullName = $userDao->fetchById($activityUser)->getRawValue(IUser::FIELD_FULLNAME);

                $title = sprintf(
                    "%s (%s) committed change #\u{2060}%s on behalf of %s (%s)",
                    $activityFullName,
                    $activityUser,
                    $change->getId(),
                    $fullName,
                    $user
                );
            } else {
                $title = sprintf("%s (%s) committed change #\u{2060}%s", $fullName, $user, $change->getId());
            }
        }
        return $title;
    }

    /**
     * @inheritDoc
     */
    public function buildLink(Change $change, Review $review = null): string
    {
        $basePath = "/" . (P4_SERVER_ID ? P4_SERVER_ID . "/" : '');
        return $basePath . ($review ? "reviews/" . $review->getId() : "changes/" . $change->getId());
    }

    /**
     * @inheritDoc
     */
    public function buildDescription(Change $change): string
    {
        $description   = $change->getDescription();
        $defaultFormat = "*Description*:\n{description}\n";
        return strtr($defaultFormat, ['{description}' => $description]);
    }

    /**
     * Create a Laminas HTTP client for Slack API calls.
     *
     * @return Client The HTTP client instance.
     */
    protected function createHttpClient(): Client
    {
        return new Client();
    }

    /**
     * @inheritDoc
     */
    public function resolveSlackUserId(
        string $email,
        string $workspaceId = 'default',
        string $token = ''
    ): ?string {
        if (empty($email)) {
            return null;
        }
        $cacheKey = $workspaceId . ':' . $email;
        if (array_key_exists($cacheKey, $this->slackUserIdCache)) {
            return $this->slackUserIdCache[$cacheKey];
        }
        // Level 2: Redis cross-request cache, workspace-scoped key, 24-hour TTL.
        // Empty string is the not-found sentinel so confirmed misses are cached.
        $redisCacheKey = 'notification-slack-uid-'
            . $workspaceId . '-' . $email;
        try {
            $redis  = $this->services->get(RedisService::class);
            $cached = $redis->get($redisCacheKey);
            if ($cached !== null && $cached !== false) {
                $memberId                          = $cached === '' ? null : $cached;
                $this->slackUserIdCache[$cacheKey] = $memberId;
                return $memberId;
            }
        } catch (Exception $e) {
            // Redis unavailable — fall through to API call
        }
        $memberId = null;
        try {
            $useToken = !empty($token)
                ? $token
                : ConfigManager::getValue($this->config, IDef::SLACK_TOKEN);
            $request  = new Request();
            $request->setMethod(Request::METHOD_GET);
            $request->setUri(
                'https://slack.com/api/users.lookupByEmail'
            );
            $request->getQuery()->set('email', $email);
            $request->getHeaders()->addHeaders(
                ['Authorization' => 'Bearer ' . $useToken]
            );
            $client  = $this->createHttpClient();
            $options = $this->config + ['http_client_options' => []];
            $options = (array)$options['http_client_options'];
            $host    = $request->getUri()->getHost();
            if (isset($options['hosts'][$host])) {
                $options = (array)$options['hosts'][$host] + $options;
            }
            unset($options['hosts']);
            $client->setOptions($options);
            $response = $client->dispatch($request);
            $body     = json_decode($response->getBody());
            if ($body && isset($body->ok) && $body->ok
                && isset($body->user->id)
            ) {
                $memberId = $body->user->id;
            }
        } catch (Exception $e) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->err(
                'Slack resolveSlackUserId failed for '
                . $email . ': ' . $e->getMessage()
            );
        }
        // Store in Redis (empty string as not-found sentinel) and in-memory cache.
        try {
            $redis = $this->services->get(RedisService::class);
            $redis->set($redisCacheKey, $memberId ?? '', 86400);
        } catch (Exception $e) {
            // Redis unavailable — in-memory cache only
        }
        $this->slackUserIdCache[$cacheKey] = $memberId;
        return $memberId;
    }

    /**
     * @inheritDoc
     */
    public function buildMentions(
        array $notifyConfig,
        Change $change,
        ?array $projects,
        ?Review $review = null,
        array $workspace = []
    ): string {
        $wsId    = $workspace['id'] ?? 'default';
        $wsToken = $workspace['token'] ?? '';
        if (empty($notifyConfig)) {
            return '';
        }
        $logger     = $this->services->get(SwarmLogger::SERVICE);
        $validRoles = [
            IDef::SLACK_NOTIFY_AUTHOR,
            IDef::SLACK_NOTIFY_DEFAULT_REVIEWERS,
            IDef::SLACK_NOTIFY_BRANCH_MODERATORS,
            IDef::SLACK_NOTIFY_PROJECT_DEFAULT_REVIEWERS,
            IDef::SLACK_NOTIFY_PROJECT_OWNERS,
            IDef::SLACK_NOTIFY_PROJECT_MEMBERS,
            IDef::SLACK_NOTIFY_REVIEWERS,
        ];
        foreach ($notifyConfig as $role) {
            if (!in_array($role, $validRoles)) {
                $logger->warn(
                    sprintf('Slack buildMentions: unrecognized notify value "%s"', $role)
                );
            }
        }
        $p4      = $this->services->get(ConnectionFactory::P4_ADMIN);
        $userIds = [];
        if (in_array(IDef::SLACK_NOTIFY_AUTHOR, $notifyConfig)) {
            $userIds[] = ($review !== null)
                ? $review->get(IReview::FIELD_AUTHOR)
                : $change->getUser();
        }
        if ($review !== null && $projects !== null) {
            $projectDao      = $this->services->get(IDao::PROJECT_DAO);
            $wantRevs        = in_array(
                IDef::SLACK_NOTIFY_DEFAULT_REVIEWERS,
                $notifyConfig
            );
            $wantMods        = in_array(
                IDef::SLACK_NOTIFY_BRANCH_MODERATORS,
                $notifyConfig
            );
            $wantProjRevs    = in_array(
                IDef::SLACK_NOTIFY_PROJECT_DEFAULT_REVIEWERS,
                $notifyConfig
            );
            $wantProjOwners  = in_array(
                IDef::SLACK_NOTIFY_PROJECT_OWNERS,
                $notifyConfig
            );
            $wantProjMembers = in_array(
                IDef::SLACK_NOTIFY_PROJECT_MEMBERS,
                $notifyConfig
            );
            foreach ($projects as $projectId => $branches) {
                try {
                    $project     = $projectDao->fetchById($projectId, $p4);
                    $allBranches = $project->getBranches();
                } catch (Exception $e) {
                    continue;
                }
                foreach ($branches as $branchId) {
                    try {
                        $branchData = $project->getBranch(
                            $branchId,
                            $allBranches
                        );
                    } catch (InvalidArgumentException $e) {
                        continue;
                    }
                    if ($wantMods) {
                        $mods = $branchData[Project::FIELD_MODERATORS] ?? [];
                        foreach ($mods as $uid) {
                            $userIds[] = $uid;
                        }
                        $modGroups = $branchData[Project::FIELD_MODERATORS_GROUPS] ?? [];
                        foreach ($modGroups as $groupName) {
                            $userIds[] = 'swarm-group-' . $groupName;
                        }
                    }
                    if ($wantRevs) {
                        $defs = $branchData[Project::FIELD_DEFAULTS] ?? [];
                        $revs = $defs[Project::FIELD_REVIEWERS] ?? [];
                        foreach (array_keys($revs) as $uid) {
                            $userIds[] = $uid;
                        }
                    }
                }
                if ($wantProjRevs) {
                    $projDefaults = $project->getDefaults();
                    $projRevs     = $projDefaults[Project::FIELD_REVIEWERS] ?? [];
                    foreach (array_keys($projRevs) as $uid) {
                        $userIds[] = $uid;
                    }
                }
                if ($wantProjOwners) {
                    foreach ($project->getOwners() as $uid) {
                        $userIds[] = $uid;
                    }
                }
                if ($wantProjMembers) {
                    $members = $project->getAllMembers(false, null) ?: [];
                    if (count($members) > 20) {
                        $logger->warn(
                            sprintf(
                                'Slack buildMentions: project_members'
                                . ' for project "%s" has %d members'
                                . ' (>20)',
                                $projectId,
                                count($members)
                            )
                        );
                    }
                    foreach ($members as $uid) {
                        $userIds[] = $uid;
                    }
                }
            }
        }
        if ($review !== null) {
            if (in_array(
                IDef::SLACK_NOTIFY_REVIEWERS,
                $notifyConfig
            )) {
                foreach ($review->getReviewers() as $uid) {
                    $userIds[] = $uid;
                }
            }
        }
        // Expand any group entries to their individual members (handles nested groups recursively).
        $groupDao        = $this->services->get(IDao::GROUP_DAO);
        $expandedUserIds = [];
        foreach (array_unique($userIds) as $uid) {
            if (Group::isGroupName($uid)) {
                try {
                    $members = $groupDao->fetchAllMembers(Group::getGroupName($uid), true, null, null, $p4);
                    foreach (array_keys($members) as $member) {
                        $expandedUserIds[] = $member;
                    }
                } catch (Exception $e) {
                    // skip group if it cannot be expanded
                }
            } else {
                $expandedUserIds[] = $uid;
            }
        }

        $userDao  = $this->services->get(IDao::USER_DAO);
        $logger   = $this->services->get(SwarmLogger::SERVICE);
        $mentions = [];
        foreach (array_unique($expandedUserIds) as $userId) {
            try {
                $user  = $userDao->fetchById($userId, $p4);
                $email = $user->getRawValue(IUser::FIELD_EMAIL);
                if (empty($email)) {
                    continue;
                }
                $memberId = $this->resolveSlackUserId(
                    $email,
                    $wsId,
                    $wsToken
                );
                if ($memberId) {
                    $mentions[$memberId] = '<@' . $memberId . '>';
                }
            } catch (Exception $e) {
                $logger->err(
                    'Slack buildMentions failed for user '
                    . $userId . ': ' . $e->getMessage()
                );
            }
        }
        return implode(' ', $mentions);
    }

    /**
     * @inheritDoc
     */
    public function buildMentionsFromUserIds(
        array $userIds,
        array $workspace = []
    ): array {
        $wsId    = $workspace['id'] ?? 'default';
        $wsToken = $workspace['token'] ?? '';
        if (empty($userIds)) {
            return [];
        }
        $p4      = $this->services->get(ConnectionFactory::P4_ADMIN);
        $userDao = $this->services->get(IDao::USER_DAO);
        $logger  = $this->services->get(SwarmLogger::SERVICE);
        $members = [];
        foreach ($userIds as $userId) {
            try {
                $user  = $userDao->fetchById($userId, $p4);
                $email = $user->getRawValue(IUser::FIELD_EMAIL);
                if (empty($email)) {
                    $logger->warn(
                        sprintf(
                            'Slack buildMentionsFromUserIds:'
                            . ' user "%s" has no email',
                            $userId
                        )
                    );
                    continue;
                }
                $memberId = $this->resolveSlackUserId(
                    $email,
                    $wsId,
                    $wsToken
                );
                if ($memberId !== null) {
                    $members[] = $memberId;
                } else {
                    $logger->warn(
                        sprintf(
                            'Slack buildMentionsFromUserIds:'
                            . ' no Slack account for user "%s"',
                            $userId
                        )
                    );
                }
            } catch (Exception $e) {
                $logger->warn(
                    sprintf(
                        'Slack buildMentionsFromUserIds:'
                        . ' failed for user "%s": %s',
                        $userId,
                        $e->getMessage()
                    )
                );
            }
        }
        return array_values(array_unique($members));
    }
}
