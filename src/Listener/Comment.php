<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Discord\Listener;

use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Comments\Model\Comment as CommentModel;
use Discord\Model\Discord as DiscordModel;
use Discord\Service\Discord;
use Events\Listener\AbstractEventListener;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Record\Lock\Exception\LockAcquisitionException;
use Record\Lock\Lock;
use Reviews\Model\IReview;
use Reviews\Model\Review;

class Comment extends AbstractEventListener
{
    const LOG_PREFIX = Comment::class;

    protected $discord = null;

    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->discord = $this->services->get(Discord::class);
    }

    protected function shouldAttach($eventName, $eventDetail): bool
    {
        try {
            return $this->discord->isConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function handleReview(Event $event): void
    {
        $activity = $event->getParam('activity');
        $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
        try {
            $comment = CommentModel::fetch($event->getParam(IReview::FIELD_ID), $p4Admin);
            $topic   = $comment->get('topic');
            if (!$activity || !str_starts_with($topic, 'reviews/')) {
                return;
            }
            $context = $comment->getFileContext();
            $review  = Review::fetch($context['review'] ?: str_replace('reviews/', '', $topic), $p4Admin);
            $config  = $this->services->get(IConfigDefinition::CONFIG);
            $ttl     = (int) ConfigManager::getValue($config, IConfigDefinition::LOCKS_TTL, Lock::DEFAULT_TTL);
            $maxWait = (int) ConfigManager::getValue(
                $config,
                IConfigDefinition::LOCKS_MAX_WAIT,
                Lock::DEFAULT_MAX_WAIT
            );
            $lock    = new Lock(DiscordModel::KEY_PREFIX . "review-" . $review->getId(), $p4Admin, $ttl);
            $lock->withLock(
                function () use ($review, $activity, $p4Admin) {
                    $change = $this->services->get(IDao::CHANGE_DAO)->fetchById($review->getChanges()[0], $p4Admin);
                    $this->discord->handleThreadedMessage($change, $activity, $review);
                },
                $maxWait
            );
        } catch (LockAcquisitionException $e) {
            $this->logger->warn(sprintf("%s: could not acquire lock for comment", self::LOG_PREFIX));
        } catch (Exception $e) {
            $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
        }
    }
}
