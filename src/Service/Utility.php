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
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition as IDef;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Exception;
use Groups\Model\Group;
use Interop\Container\ContainerInterface;
use InvalidArgumentException;
use Mail\MailAction;
use P4\Spec\Change;
use Projects\Model\Project;
use Reviews\Model\IReview;
use Reviews\Model\Review;
use Users\Model\IUser;

class Utility implements InvokableService
{
    const WITHOUT_PROJECT    = '*without_project*';
    const NO_PROJECT_CHANNEL = '*no_project_channel*';
    const ALL                = '*all*';
    const TYPE_REVIEW        = 'review';
    const TYPE_SUBMIT        = 'submit';

    const NOTIFY_AUTHOR                    = 'author';
    const NOTIFY_REVIEWERS                 = 'reviewers';
    const NOTIFY_DEFAULT_REVIEWERS         = 'default_reviewers';
    const NOTIFY_BRANCH_MODERATORS         = 'branch_moderators';
    const NOTIFY_PROJECT_DEFAULT_REVIEWERS = 'project_default_reviewers';
    const NOTIFY_PROJECT_OWNERS            = 'project_owners';
    const NOTIFY_PROJECT_MEMBERS           = 'project_members';

    private $services;
    private $config;

    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->config   = $services->get(IDef::CONFIG);
    }

    public function truncate(string $text, int $limit, bool $isList = false): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $text = mb_substr($text, 0, $limit - 4);
        if ($isList && ($index = mb_strrpos($text, "\n")) !== false) {
            $text = mb_substr($text, 0, $index);
        }
        return $text . "\n...";
    }

    public function getHostName(): string
    {
        return ConfigManager::getValue($this->config, IDef::ENVIRONMENT_EXTERNAL_URL)
            ?? "http://" . ConfigManager::getValue($this->config, IDef::ENVIRONMENT_HOSTNAME);
    }

    public function buildLink(Change $change, Review $review = null): string
    {
        $basePath = "/" . (P4_SERVER_ID ? P4_SERVER_ID . "/" : '');
        return $basePath . ($review ? "reviews/" . $review->getId() : "changes/" . $change->getId());
    }

    public function buildTitle(Change $change, Review $review = null, Activity $activity = null): string
    {
        $userDao  = $this->services->get(IDao::USER_DAO);
        $user     = $change->getUser();
        $fullName = $userDao->fetchById($user)->getRawValue(IUser::FIELD_FULLNAME);

        if ($review !== null) {
            return sprintf("%s (%s) requested review #%s", $fullName, $user, $review->getId());
        }
        if ($activity && $activity->get('behalfOf')) {
            $activityUser = $activity->get('user');
            return sprintf(
                "%s (%s) committed change #%s on behalf of %s (%s)",
                $userDao->fetchById($activityUser)->getRawValue(IUser::FIELD_FULLNAME),
                $activityUser,
                $change->getId(),
                $fullName,
                $user
            );
        }
        return sprintf("%s (%s) committed change #%s", $fullName, $user, $change->getId());
    }

    public function buildEmbed(
        Change $change,
        ?Review $review,
        string $title,
        ?array $projects,
        array $linkedProjects,
        array $discordConfig
    ): array {
        $fields      = [];
        $projectText = $this->buildProjectText($projects, true, $linkedProjects);
        if ($projectText !== '') {
            $fields[] = ['name' => 'Projects', 'value' => $this->truncate($projectText, 1024, true)];
        }
        if (!empty($discordConfig['summary_file_names'])) {
            $files = $change->getFileData($change->isPending());
            $count = count($files);
            if ($count > 0) {
                $limit = (int) ($discordConfig['summary_file_limit'] ?? 10);
                $names = array_map(
                    fn($file) => $file['depotFile'] . '#' . $file['rev'],
                    array_slice($files, 0, $limit)
                );
                $fields[] = [
                    'name'  => sprintf("%d file%s changed", $count, $count === 1 ? '' : 's'),
                    'value' => $this->truncate(implode("\n", $names) . ($count > $limit ? "\n..." : ''), 1024, true),
                ];
            }
        }
        return array_filter(
            [
                'title'       => $this->truncate($title, 256),
                'url'         => $this->getHostName() . $this->buildLink($change, $review),
                'description' => $this->truncate((string) $change->getDescription(), 1000),
                'fields'      => $fields,
            ]
        );
    }

    public function buildProjectText(?array $projects, bool $multiline = true, array $linkedProjects = []): string
    {
        $projectDao = $this->services->get(IDao::PROJECT_DAO);
        $p4         = $this->services->get(ConnectionFactory::P4_ADMIN);
        $names      = [];
        foreach ((array) $projects as $project => $branches) {
            $projectModel = $projectDao->fetchById($project, $p4);
            if (!in_array($project, $linkedProjects) && $projectModel->isPrivate()) {
                continue;
            }
            $allBranches = $projectModel->getBranches();
            foreach ($branches as $branch) {
                try {
                    $name = $projectModel->getBranch($branch, $allBranches)[Project::FIELD_NAME];
                } catch (InvalidArgumentException $e) {
                    $name = $branch;
                }
                $names[] = $projectModel->getName() . ":" . $name;
            }
        }
        return implode($multiline ? "\n" : ", ", $names);
    }

    public function buildActivityReply(Activity $activity, array $projects, array $linkedProjects = []): string
    {
        $userDao     = $this->services->get(IDao::USER_DAO);
        $p4          = $this->services->get(ConnectionFactory::P4_ADMIN);
        $userId      = $activity->getRawValue(IActivity::USER);
        $action      = $activity->getRawValue(IActivity::ACTION);
        $target      = $activity->getRawValue(IActivity::TARGET);
        $details     = $activity->getRawValue(IActivity::DETAILS);
        $description = '';
        switch ($action) {
            case MailAction::COMMENT_ADDED:
            case MailAction::COMMENT_EDITED:
            case MailAction::COMMENT_REPLY:
            case MailAction::DESCRIPTION_COMMENT_ADDED:
            case MailAction::DESCRIPTION_COMMENT_EDITED:
                $description = $activity->getRawValue(IActivity::DESCRIPTION);
                break;
            case MailAction::REVIEW_NEEDS_REVIEW:
            case MailAction::REVIEW_NEEDS_REVISION:
            case MailAction::REVIEW_APPROVED:
            case MailAction::REVIEW_REJECTED:
            case MailAction::REVIEW_ARCHIVED:
                $description = is_array($details) ? ($details['transitionComment'] ?? '') : '';
                break;
        }

        $fullName = "Helix Swarm";
        if ($userDao->exists($userId, $p4)) {
            $fullName = $userDao->fetchById($userId, $p4)->get(IUser::FIELD_FULLNAME);
        }

        $votes = '';
        if (is_array($details) && isset($details['voteCounts'])) {
            $votes = sprintf(
                " (Votes: %d up, %d down)",
                (int) ($details['voteCounts']['up'] ?? 0),
                (int) ($details['voteCounts']['down'] ?? 0)
            );
        }

        $projectText = $this->buildProjectText($projects, false, $linkedProjects);

        return sprintf(
            "**%s** (%s) %s %s%s%s%s",
            $fullName,
            $userId,
            $action,
            $target,
            $votes,
            $projectText !== '' ? " for " . $projectText : '',
            $description ? ":\n" . $description : ''
        );
    }

    private function getChannels($mapping, string $type): array
    {
        $mapping = (array) $mapping;
        if (isset($mapping[self::TYPE_REVIEW]) || isset($mapping[self::TYPE_SUBMIT])) {
            return (array) ($mapping[$type] ?? []);
        }
        return $mapping;
    }

    public function getProjectMappings(array $projects, array $mappings, string $type, bool $purePrivate = false): array
    {
        $result = [];
        foreach (array_keys($projects) as $project) {
            foreach ($this->getChannels($mappings[$project] ?? [], $type) as $channel) {
                $result[$channel][] = $project;
            }
        }
        if ($purePrivate) {
            return $result;
        }
        $extra = $this->getChannels($mappings[self::ALL] ?? [], $type);
        if (!$result) {
            $fallback = $mappings[$projects ? self::NO_PROJECT_CHANNEL : self::WITHOUT_PROJECT] ?? [];
            $extra    = array_merge($this->getChannels($fallback, $type), $extra);
        }
        foreach ($extra as $channel) {
            $result[$channel] = $result[$channel] ?? [];
        }
        return $result;
    }

    public function buildMentions(array $discordConfig, Change $change, ?array $projects, ?Review $review): string
    {
        $notify = $discordConfig['notify'] ?? [];
        $users  = $discordConfig['users'] ?? [];
        if (!$notify || !$users) {
            return '';
        }
        $p4      = $this->services->get(ConnectionFactory::P4_ADMIN);
        $userIds = [];
        if (in_array(self::NOTIFY_AUTHOR, $notify)) {
            $userIds[] = $review ? $review->get(IReview::FIELD_AUTHOR) : $change->getUser();
        }
        if ($review !== null) {
            if (in_array(self::NOTIFY_REVIEWERS, $notify)) {
                $userIds = array_merge($userIds, $review->getReviewers());
            }
            $projectDao = $this->services->get(IDao::PROJECT_DAO);
            foreach ((array) $projects as $projectId => $branches) {
                try {
                    $project = $projectDao->fetchById($projectId, $p4);
                } catch (Exception $e) {
                    continue;
                }
                $allBranches = $project->getBranches();
                foreach ($branches as $branchId) {
                    try {
                        $branch = $project->getBranch($branchId, $allBranches);
                    } catch (InvalidArgumentException $e) {
                        continue;
                    }
                    if (in_array(self::NOTIFY_BRANCH_MODERATORS, $notify)) {
                        $userIds = array_merge($userIds, $branch[Project::FIELD_MODERATORS] ?? []);
                        foreach ($branch[Project::FIELD_MODERATORS_GROUPS] ?? [] as $group) {
                            $userIds[] = 'swarm-group-' . $group;
                        }
                    }
                    if (in_array(self::NOTIFY_DEFAULT_REVIEWERS, $notify)) {
                        $defaults = $branch[Project::FIELD_DEFAULTS][Project::FIELD_REVIEWERS] ?? [];
                        $userIds  = array_merge($userIds, array_keys($defaults));
                    }
                }
                if (in_array(self::NOTIFY_PROJECT_DEFAULT_REVIEWERS, $notify)) {
                    $userIds = array_merge($userIds, array_keys($project->getDefaults()[Project::FIELD_REVIEWERS] ?? []));
                }
                if (in_array(self::NOTIFY_PROJECT_OWNERS, $notify)) {
                    $userIds = array_merge($userIds, $project->getOwners());
                }
                if (in_array(self::NOTIFY_PROJECT_MEMBERS, $notify)) {
                    $userIds = array_merge($userIds, $project->getAllMembers(false, null) ?: []);
                }
            }
        }

        $groupDao = $this->services->get(IDao::GROUP_DAO);
        $mentions = [];
        foreach (array_unique($userIds) as $userId) {
            $members = [$userId];
            if (Group::isGroupName($userId)) {
                try {
                    $members = array_keys(
                        $groupDao->fetchAllMembers(Group::getGroupName($userId), true, null, null, $p4)
                    );
                } catch (Exception $e) {
                    continue;
                }
            }
            foreach ($members as $member) {
                if (isset($users[$member])) {
                    $mentions[$users[$member]] = '<@' . $users[$member] . '>';
                }
            }
        }
        return implode(' ', $mentions);
    }
}
