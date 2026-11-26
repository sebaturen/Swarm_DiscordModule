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
use Application\Config\ConfigException;
use P4\Spec\Change;
use Reviews\Model\Review;

/**
 * Interface ISlackUtility.
 * @package Slack\Service
 */
interface IUtility
{
    // Keys for use in an array of file data
    public const FILES = 'files';
    public const COUNT = 'count';

    public const SERVICE_NAME = "SlackUtility";

    public const NO_ASSOCIATED_PROJECTS_CHANNELS = "*without_project*";
    public const DEFAULT_PROJECTS_CHANNELS       = "*no_project_channel*";
    public const ALL_NOTIFICATIONS_CHANNELS      = "*all*";

    public const NO_PROJECT_CHANNEL = "no_project_channel_swarm_internal_only_channel";

    /**
     * Build file names from file data on the change taking into account pending status. The number of files is
     * restricted by the SUMMARY_FILE_LIMIT value in the Slack configuration. The limit applies to all messages.
     *
     * Files will be returned in an array with a count of all the files to cover the case where the list
     * has been limited, for example
     *  [
     *      // 3 returned due to a restriction
     *      IUtility::FILES => ['file1', 'file2', 'file3'],
     *      // Real number of files
     *      IUtility::COUNT => 100
     *  ]
     * @param Change     $change     the current change model
     * @return array the file names
     * @throws ConfigException
     */
    public function getFileNames(Change $change, array $workspace): array;

    /**
     * Returns the host name url used for the button to open the review in Swarm
     *
     * @throws ConfigException
     * @return string - The host name url
     */
    public function getHostName(): string;

    /**
     * This is building the reply text from the activity model.
     *
     * @param Activity $activity        The activity model that is going to be used.
     * @param array    $projects        The list of projects.
     * @param array    $linkedProjects  The projects linked to this channel post.
     * @return string
     */
    public function buildActivityReply(Activity $activity, array $projects, array $linkedProjects): string;

    /**
     * Returns a list of mappings (channels or users) based on the projects a review is associated with
     *
     * @param array      $projectList - List of projects a review or changelist is associated with
     * @param array|null $mappingList - List of mappings specified in the config under slack.channels
     * @param bool       $purePrivate - If all projects are private.
     * @return array        - List of mappings for review or changelist (channels or users)
     */
    public function getProjectMappings(array $projectList, ?array $mappingList, bool $purePrivate = false): array;

    /**
     * Build the list of project we want to show. If we want to include the markdown title we just set title to true.
     * default to including the markdown title.
     *
     * If markdown is true then we will return the list of data as a new line list:
     * Project1:branch1/n
     * Project2:branch1/n
     * Project2:branch2/n
     *
     * If markdown is false we will return with comma separators:
     * Project1:branch1, Project2:branch1, Project2:branch2
     *
     * @param array|null $projects       The array of project ID, with their branches to iterate over.
     * @param bool       $markdown       If we should build a title and add new lines for the project list.
     * @param array      $linkedProjects The linked projects to this post.
     * @return string
     */
    public function buildProjectText(?array $projects, bool $markdown = true, array $linkedProjects = []): string;

    /**
     * Build the list of files to be show on the Slack message. The number of files included in the message is
     * restricted by the SUMMARY_FILE_LIMIT value in the Slack configuration. The limit applies to all messages.
     * When the list has been limited the text will include an indicator at the end.
     *
     * @param Change      $change This is the change model.
     * @throws ConfigException
     * @return string
     */
    public function buildFileNames(Change $change, array $workspace): string;

    /**
     * Build the title we show at the top of the Slack message in build.
     *
     * @param Change      $change This is the change model.
     * @param Review|null $review This is the Review model or null.
     * @param Activity|null $activity This is the Activity model or null.
     * @return string
     */
    public function buildTitle(Change $change, Review $review = null, Activity $activity = null): string;

    /**
     * Build the url that we are going to put into the payload for the view button.
     * We also need to be aware of multi p4d. For now using the P4_SERVER_ID as can't get the view
     * to work within this class.
     *
     * @param Change      $change This is the change model.
     * @param Review|null $review This is the Review model or null.
     * @return string
     */
    public function buildLink(Change $change, Review $review = null): string;

    /**
     * Build the description in markup for slack. Thinking ahead we could get a format form config
     * that would allow customers to customise. This is the reason for the strtr function. As we could
     * change defaultFormat to customFormat.
     *
     * @param Change $change This is the change model.
     * @return string
     */
    public function buildDescription(Change $change): string;

    /**
     * Build a space-separated string of Slack @mention tokens for the
     * users specified in the notify configuration.
     *
     * Supported notify values: author, branch_default_reviewers,
     * branch_moderators, project_default_reviewers, project_owners,
     * project_members, reviewers.
     * Project-level roles require both $review and $projects.
     * The reviewers role requires only $review.
     *
     * @param  array       $notifyConfig  Values from slack.notify config.
     * @param  Change      $change        The current change model.
     * @param  array|null  $projects      Projects for the review or change.
     * @param  Review|null $review        The review model if applicable.
     * @param  array       $workspace     Workspace block for this message.
     *
     * @return string Space-separated '<@MEMBERID>' tokens or empty string.
     */
    public function buildMentions(
        array $notifyConfig,
        Change $change,
        ?array $projects,
        ?Review $review = null,
        array $workspace = []
    ): string;

    /**
     * Resolve a Swarm user email address to a Slack member ID.
     *
     * @param  string $email        The user's email address.
     * @param  string $workspaceId  Workspace ID for cache namespacing.
     * @param  string $token        Per-workspace token; falls back to global.
     *
     * @return string|null The Slack member ID or null if not found.
     */
    public function resolveSlackUserId(
        string $email,
        string $workspaceId = 'default',
        string $token = ''
    ): ?string;

    /**
     * Resolve an array of Swarm user IDs to Slack member IDs.
     *
     * @param  array $userIds    Swarm user ID strings to resolve.
     * @param  array $workspace  Workspace block for cache namespacing.
     *
     * @return array Array of unique Slack member ID strings.
     */
    public function buildMentionsFromUserIds(
        array $userIds,
        array $workspace = []
    ): array;
}
