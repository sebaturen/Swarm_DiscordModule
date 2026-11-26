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
use P4\Exception;
use P4\Spec\Change;
use Reviews\Model\Review;

/**
 * Interface ISlackService.
 * @package Slack\Service
 */
interface ISlack
{
    public const SERVICE_NAME = "SlackService";

    /**
     * This function is called when a review is created or a changelist is submitted
     *
     * @param Change      $change   - The changelist object
     * @param Activity    $activity - The activity that will be on the event.
     * @param Review|null $review   - If set, the changelist object represents a review rather than a change
     * @param mixed|null  $projects - If set, use the given projects, otherwise work out the projects from the review
     *                                or change
     *
     * @throws ConfigException|Exception
     */
    public function handleCreateSlackMessage(
        Change $change,
        Activity $activity,
        Review $review = null,
        $projects = null
    );

    /**
     * This function is called when a review is updated, and we want to add message into the thread.
     *
     * @param Change      $change           - The changelist object
     * @param Activity    $activity         - The activity that will be on the event.
     * @param Review|null $review           - If set, the changelist object represents a review rather than a change
     * @param array       $imageAttachments Web-safe image Attachment objects to upload.
     *
     * @throws ConfigException|Exception
     */
    public function handleThreadedSlackMessage(
        Change $change,
        Activity $activity,
        Review $review = null,
        array $imageAttachments = []
    );

    /**
     * This function is called when a new project is linked to a review and doesn't have a thread id yet.
     * It will create the post and then save that threadId to the key data. Then it will return the threadId, so we can
     * use that right away instead of fetching the saved data.
     *
     * @param Change     $change         - The changelist object
     * @param string     $channel        - The channel we are going to post to.
     * @param Review     $review         - The changelist object represents a review rather than a change
     * @param array|null $projects       - The projects for this given item.
     * @param array|null $linkedProjects - The linked project by channels.
     *
     * @return string This will return the threadId from the post.
     *
     * @throws ConfigException|Exception
     */
    public function handleMissingSlackMessage(
        Change $change,
        string $channel,
        Review $review,
        array $workspace,
        array $projects = null,
        array $linkedProjects = []
    ): ?string;

    /**
     * This function builds the required headers and body for sending Slack messages
     *
     * @param array $body - The current POST body to send to the Slack API, this might be edited
     * @throws ConfigException
     */
    public function postSlackMessage(array $body, array $workspace);

    /**
     * This function sends the request to Slack's API services to post messages
     *
     * @param string        $endpoint - The endpoint type to use, there are different endpoints for sending messages
     *                                   and files
     * @param array         $headers  - The list of request headers to prove authentication and body encoding types
     * @param array|string  $body     - The POST body to send to the endpoint
     * @param bool          $json     - Whether the POST body is encoded with JSON, if not form data is assumed
     * @return mixed
     */
    public function postSlackAPI(string $endpoint, array $headers, $body, bool $json = true);
}
