<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */

namespace Slack\Listener;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Attachments\Model\Attachment;
use Comments\Model\Comment as CommentModel;
use Events\Listener\AbstractEventListener;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Record\Exception\NotFoundException;
use Record\Lock\Lock;
use Record\Lock\Exception\LockAcquisitionException;
use Reviews\Model\IReview;
use Reviews\Model\Review;
use Slack\Model\Slack;
use Slack\Service\ISlack;
use Slack\Service\IWorkspaceResolver;
use Laminas\EventManager\Event;
use Exception;

/**
 * Comment method that calls other services.
 */
class Comment extends AbstractEventListener
{
    const LOG_PREFIX = Comment::class;

    protected mixed $slackService = null;

    /**
     * Ensure we get a service locator and event config on construction.
     *
     * @param ServiceLocator $services    the service locator to use
     * @param array          $eventConfig the event config for this listener
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->slackService = $this->services->get(ISlack::SERVICE_NAME);
    }

    /**
     * Only attach when at least one Slack workspace is configured with a valid token.
     * Without this guard the listener fires in environments that have no Slack config,
     * producing ERR/WARN log entries that break the automation log-level check.
     *
     * @param  mixed $eventName   the event name (unused; required to match parent signature)
     * @param  mixed $eventDetail the event detail (unused; required to match parent signature)
     * @return bool
     */
    protected function shouldAttach($eventName, $eventDetail): bool
    {
        try {
            return $this->services->get(IWorkspaceResolver::SERVICE_NAME)->hasConfiguredWorkspace();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * This function is called when a review comment is event
     *
     * @param Event $event
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ConfigException
     */
    public function handleReview(Event $event): void
    {
        parent::log($event);
        $services = $this->services;
        $logger   = $services->get(SwarmLogger::SERVICE);
        $id       = $event->getParam(IReview::FIELD_ID);
        $activity = $event->getParam('activity');
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);

        try {
            // fetch comment record
            $comment = CommentModel::fetch($id, $p4Admin);
            $topic   = $comment->get('topic');
            // handle review comments
            if (str_starts_with($topic, 'reviews/')) {
                $context  = $comment->getFileContext();
                $review   = $context['review'] ?: str_replace('reviews/', '', $topic);
                $review   = Review::fetch($review, $p4Admin);
                $reviewId = $review->getId();
                $lockName = Slack::KEY_PREFIX . "review-" . $reviewId;
                $config   = $services->get(IConfigDefinition::CONFIG);
                $ttl      = (int) ConfigManager::getValue($config, IConfigDefinition::LOCKS_TTL, Lock::DEFAULT_TTL);
                $maxWait  = (int) ConfigManager::getValue(
                    $config,
                    IConfigDefinition::LOCKS_MAX_WAIT,
                    Lock::DEFAULT_MAX_WAIT
                );
                $lock     = new Lock($lockName, $p4Admin, $ttl);
                try {
                    // withLock guarantees the lock is released on every exit path, including \Throwable
                    $lock->withLock(
                        function () use ($comment, $review, $activity, $p4Admin, $logger) {
                            $changeDAO        = $this->services->get(IDao::CHANGE_DAO);
                            $change           = $changeDAO->fetchById($review->getChanges()[0], $p4Admin);
                            $imageAttachments = [];
                            foreach ($comment->getAttachments() as $attachmentId) {
                                try {
                                    $attachment = Attachment::fetchById(
                                        $attachmentId,
                                        $p4Admin
                                    );
                                    if ($attachment->isWebSafeImage()) {
                                        $imageAttachments[] = $attachment;
                                    }
                                } catch (NotFoundException $e) {
                                    $logger->err(
                                        sprintf(
                                            "%s: Attachment %s not found: %s",
                                            self::LOG_PREFIX,
                                            $attachmentId,
                                            $e->getMessage()
                                        )
                                    );
                                }
                            }
                            $this->slackService->handleThreadedSlackMessage(
                                $change,
                                $activity,
                                $review,
                                $imageAttachments
                            );
                        },
                        $maxWait
                    );
                } catch (LockAcquisitionException $lockError) {
                    // could not acquire the lock; skip the Slack send rather than racing another writer
                    $logger->warn(
                        sprintf(
                            "%s: could not acquire lock '%s' for review %s; skipping Slack comment notification",
                            self::LOG_PREFIX,
                            $lockName,
                            $reviewId
                        )
                    );
                } catch (Exception $e) {
                    $logger->err($e);
                }
            }
        } catch (Exception $e) {
            $logger->err($e);
        }
    }
}
