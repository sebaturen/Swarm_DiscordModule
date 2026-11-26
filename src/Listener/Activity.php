<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Discord\Listener;

use Activity\Model\IActivity;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Discord\Model\Discord as DiscordModel;
use Discord\Service\Discord;
use Events\Listener\AbstractEventListener;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Mail\MailAction;
use P4\Spec\Change;
use P4\Spec\Exception\Exception as P4SpecException;
use P4\Spec\Exception\NotFoundException;
use Record\Lock\Exception\LockAcquisitionException;
use Record\Lock\Lock;
use Reviews\Filter\Keywords;
use Reviews\Model\IReview;
use Reviews\Model\Review;

class Activity extends AbstractEventListener
{
    const LOG_PREFIX = Activity::class;

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

    private function getLock(string $name, $p4Admin): array
    {
        $config  = $this->services->get(IConfigDefinition::CONFIG);
        $ttl     = (int) ConfigManager::getValue($config, IConfigDefinition::LOCKS_TTL, Lock::DEFAULT_TTL);
        $maxWait = (int) ConfigManager::getValue($config, IConfigDefinition::LOCKS_MAX_WAIT, Lock::DEFAULT_MAX_WAIT);
        return [new Lock(DiscordModel::KEY_PREFIX . $name, $p4Admin, $ttl), $maxWait];
    }

    public function handleReview(Event $event)
    {
        $id       = $event->getParam(IReview::FIELD_ID);
        $activity = $event->getParam('activity');
        $data     = (array) $event->getParam('data') + ['quiet' => null];
        if (!$activity || $event->getParam('quiet') || $data['quiet']) {
            return;
        }
        $action  = $activity->getRawValue(IActivity::ACTION);
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        [$lock, $maxWait] = $this->getLock("review-" . $id, $p4Admin);
        try {
            $review = $event->getParam('review');
            if (!$review instanceof Review) {
                $review = $this->services->get(IDao::REVIEW_DAO)->fetchByIdUnrestricted($id, $p4Admin);
                $event->setParam('review', $review);
            }
            $change = $this->services->get(IDao::CHANGE_DAO)->fetchById($review->getChanges()[0], $p4Admin);
            $lock->withLock(
                function () use ($action, $change, $activity, $review) {
                    if ($action === IActivity::REQUESTED) {
                        $this->discord->handleCreateMessage($change, $activity, $review);
                    } elseif (!in_array($action, [MailAction::REVIEW_TESTS, MailAction::REVIEW_TESTS_NO_AUTH])) {
                        $this->discord->handleThreadedMessage($change, $activity, $review);
                    }
                },
                $maxWait
            );
        } catch (LockAcquisitionException $e) {
            $this->logger->warn(sprintf("%s: could not acquire lock for review %s", self::LOG_PREFIX, $id));
        } catch (Exception $e) {
            $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
        }
    }

    private function findReview(Change $change, $p4Admin): ?Review
    {
        $reviews = $this->services->get(IDao::REVIEW_DAO)->fetchAll(
            [IReview::FETCH_BY_CHANGE => $change->getOriginalId()],
            $p4Admin
        );
        return $reviews->count() ? $reviews->first() : null;
    }

    public function handleCommit(Event $event)
    {
        $change   = $event->getParam('change');
        $activity = $event->getParam('activity');
        if (!$activity || $event->getParam('quiet')) {
            return;
        }
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        try {
            if (!$change instanceof Change) {
                $change = $this->services->get(IDao::CHANGE_DAO)->fetchById($event->getParam(IReview::FIELD_ID), $p4Admin);
                $event->setParam('change', $change);
            }
            $review = $this->findReview($change, $p4Admin);
            if ($review) {
                [$lock, $maxWait] = $this->getLock("review-" . $review->getId(), $p4Admin);
                $lock->withLock(
                    function () use ($review, $change, $activity, $p4Admin) {
                        $reviewChange = $this->services->get(IDao::CHANGE_DAO)
                            ->fetchById($review->getChanges()[0], $p4Admin);
                        $this->discord->handleThreadedMessage($reviewChange, $activity, $review, $change);
                    },
                    $maxWait
                );
                return;
            }
            $matches = $this->services->get(Keywords::SERVICE)->getMatches($change->getRawValue('Description'));
            if ($matches && $matches[IReview::FIELD_ID]) {
                return;
            }
            [$lock, $maxWait] = $this->getLock("commit-" . $change->getId(), $p4Admin);
            $lock->withLock(
                function () use ($change, $activity) {
                    $this->discord->handleCreateMessage($change, $activity);
                },
                $maxWait
            );
        } catch (NotFoundException | P4SpecException $e) {
            $this->logger->debug(sprintf("%s: %s", self::LOG_PREFIX, $e->getMessage()));
        } catch (LockAcquisitionException $e) {
            $this->logger->warn(sprintf("%s: could not acquire lock for commit %s", self::LOG_PREFIX, $change->getId()));
        } catch (Exception $e) {
            $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
        }
    }
}
