<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */
namespace Slack\Model;

use Activity\Model\Activity;
use Interop\Container\ContainerInterface;
use P4\Spec\Change;
use Reviews\Model\Review;
use Slack\Service\IUtility;

/**
 * Class to build a message body that can be posted to Slack
 */
class Message
{
    // Slack has a text limit for sections at 3000. We limit to list value so we can truncate text and add on an
    // indicator that the text is truncated
    const SECTION_TXT_CHAR_LIMIT = 2995;
    // Limit a description to 1000 characters
    const DESC_TXT_CHAR_LIMIT = 1000;
    private $utility;
    private $title;
    private $link;
    private $description;
    private $projects;
    private $fileNames;
    private $mentions;

    /**
     * Construct the message
     * @param ContainerInterface $services application services
     * @param Change $change change
     * @param array|null $projects optional projects if set
     * @param Review|null $review optional review
     * @param Activity|null $activity optional activity data.
     * @param string $mentions optional space-separated Slack mention tokens.
     * @param array $workspace workspace config block; per-workspace settings override globals.
     */
    public function __construct(
        ContainerInterface $services,
        Change $change,
        ?array $projects,
        Review $review = null,
        Activity $activity = null,
        string $mentions = '',
        array $workspace = []
    ) {
        $this->projects    = $projects;
        $this->mentions    = $mentions;
        $this->utility     = $services->get(IUtility::SERVICE_NAME);
        $this->title       = $this->utility->buildTitle($change, $review, $activity);
        $this->link        = $this->utility->buildLink($change, $review);
        $this->description = $this->utility->buildDescription($change);
        $this->fileNames   = $this->utility->buildFileNames($change, $workspace);
    }

    /**
     * Modify text to fit within Slack section limits
     * @param string $text the text
     * @param bool $isList if true the text will be truncated to the last new line before the set limit
     * @param int $limit the limit to use for truncating the string
     * @return string
     */
    private function getModifiedText(
        string $text,
        bool $isList = false,
        int $limit = self::SECTION_TXT_CHAR_LIMIT
    ): string {
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
            if ($isList) {
                $index = strrpos($text, "\n");
                if ($index !== false) {
                    $text = mb_substr($text, 0, $index);
                }
            }
            $text = $text . "\n...";
        }
        return $text;
    }

    /**
     * Build a section for Markdown text, truncating the text to keep with the limits for slack
     * @param string $text the text
     * @param string $id   The Id for this block section.
     * @param bool $isList if true the text will be truncated to the last new line before the set limit
     * @param int $limit the limit to use for truncating the string
     * @return array
     */
    private function getMarkdownSection(
        string $text,
        string $id,
        bool $isList = false,
        int $limit = self::SECTION_TXT_CHAR_LIMIT
    ): array {
        $text = $this->getModifiedText($text, $isList, $limit);
        return [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => $text,
            ],
            'block_id' => $id
        ];
    }

    /**
     * Build a header block
     * @return array
     */
    private function getHeader(): array
    {
        return [
            "type" => "header",
            "text" => [
                "type" => "plain_text",
                "text" => $this->title,
                "emoji" => true
            ],
            'block_id' => "header"
        ];
    }

    /**
     * Build a message body for a slack summary
     * @param string $channel        the channel
     * @param array  $linkedProjects The linked projects to this channel.
     * @return array
     */
    public function getSummary(string $channel, array $linkedProjects = []): array
    {
        $host        = $this->utility->getHostName();
        $projectText = $this->utility->buildProjectText($this->projects, true, $linkedProjects);
        $blocks      = [];
        if ($this->mentions !== '') {
            $blocks[] = $this->getMarkdownSection($this->mentions, 'mentions');
        }
        $blocks[] = $this->getHeader();
        $blocks[] = $this->getMarkdownSection(
            $this->description,
            "description",
            false,
            self::DESC_TXT_CHAR_LIMIT
        );
        if ($projectText) {
            $blocks[] = $this->getMarkdownSection($projectText, "projects", true);
        }
        if ($this->fileNames) {
            $blocks[] = $this->getMarkdownSection($this->fileNames, "files", true);
        }
        $blocks[] = $this->getMarkdownSection("<" . $host . $this->link . "|Open in Swarm>", "openLink");


        return [
            "channel" => $channel,
            "text" => $this->title,
            "blocks" => $blocks
        ];
    }

    /**
     * Build a message for a Slack reply
     * @param Activity $activity       the activity to use to build the message
     * @param string   $channel        the channel
     * @param string   $parent         the parent thread identifier
     * @param array    $linkedProjects The linked projects to this channel.
     * @return array
     */
    public function getReply(Activity $activity, string $channel, string $parent, array $linkedProjects = []): array
    {
        $text     = $this->utility->buildActivityReply(
            $activity,
            $this->projects,
            $linkedProjects
        );
        $blocks   = [];
        $blocks[] = $this->getMarkdownSection($text, "replyText");
        return [
            "channel" => $channel,
            "thread_ts" => $parent,
            "text" => $this->getModifiedText($text),
            "blocks" => $blocks
        ];
    }
}
