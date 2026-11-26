<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */

namespace Slack\Listener;

use Activity\Model\IActivity;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Events\Listener\AbstractEventListener;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Mail\MailAction;
use P4\Spec\Change;
use P4\Spec\Exception\Exception as P4SpecException;
use P4\Spec\Exception\NotFoundException;
use Record\Lock\Lock;
use Record\Lock\Exception\LockAcquisitionException;
use Reviews\Filter\Keywords;
use Reviews\Model\IReview;
use Reviews\Model\Review;
use Slack\Model\Slack;
use Slack\Service\ISlack;
use Slack\Service\IWorkspaceResolver;
use Laminas\EventManager\Event;
use Exception;

/**
 * Activity method that calls other services.
 */
class Activity extends AbstractEventListener
{
    const LOG_PREFIX = Activity::class;

    protected $slackService = null;

    /**
     * Ensure we get a service locator and event config on construction.
     *
     * @param   ServiceLocator  $services       the service locator to use
     * @param   array           $eventConfig    the event config for this listener
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
     * This function is called when a review is created
     *
     * @param Event $event
     */
    public function handleReview(Event $event)
    {
        $id       = $event->getParam(IReview::FIELD_ID);
        $activity = $event->getParam('activity');
        $quiet    = $event->getParam('quiet');
        $data     = (array) $event->getParam('data') + ['quiet' => null];
        if ($activity && (!$quiet && !$data['quiet'])) {
            $action   = $activity->getRawValue(IActivity::ACTION);
            $p4Admin  = $this->services->get(ConnectionFactory::P4_ADMIN);
            $config   = $this->services->get(IConfigDefinition::CONFIG);
            $ttl      = (int) ConfigManager::getValue($config, IConfigDefinition::LOCKS_TTL, Lock::DEFAULT_TTL);
            $maxWait  = (int) ConfigManager::getValue(
                $config,
                IConfigDefinition::LOCKS_MAX_WAIT,
                Lock::DEFAULT_MAX_WAIT
            );
            $lockName = Slack::KEY_PREFIX . "review-" . $id;
            $lock     = new Lock($lockName, $p4Admin, $ttl);
            try {
                $this->logger->debug(sprintf("%s: handle review id %s", self::LOG_PREFIX, $id));
                $review = $event->getParam('review');
                if (!$review instanceof Review) {
                    $reviewDAO = $this->services->get(IDao::REVIEW_DAO);
                    // We fetch the unfiltered review project so the listen has all information at hand.
                    $review = $reviewDAO->fetchByIdUnrestricted($id, $p4Admin);
                    $event->setParam('review', $review);
                }
                $changeDAO = $this->services->get(IDao::CHANGE_DAO);
                $change    = $changeDAO->fetchById($review->getChanges()[0], $p4Admin);

                // As we can send notification for all activity we build. We can post all activity. Only need to create
                // message for requested reviews others go into the thread.
                $this->logger->trace(sprintf("%s: Review %s is action of %s", self::LOG_PREFIX, $id, $action));
                // withLock guarantees the lock is released on every exit path, including \Throwable
                $lock->withLock(
                    function () use ($action, $change, $activity, $review, $id) {
                        if ($action === IActivity::REQUESTED) {
                            $this->slackService->handleCreateSlackMessage($change, $activity, $review);
                        } else {
                            if (in_array($action, [MailAction::REVIEW_TESTS, MailAction::REVIEW_TESTS_NO_AUTH])) {
                                $this->logger->trace(
                                    sprintf("%s: Review %s, ignoring action '%s'", self::LOG_PREFIX, $id, $action)
                                );
                            } else {
                                $this->slackService->handleThreadedSlackMessage($change, $activity, $review);
                            }
                        }
                    },
                    $maxWait
                );
            } catch (LockAcquisitionException $lockError) {
                // could not acquire the lock; skip the Slack send rather than racing another writer
                $this->logger->warn(
                    sprintf(
                        "%s: could not acquire lock '%s' for review %s; skipping Slack notification",
                        self::LOG_PREFIX,
                        $lockName,
                        $id
                    )
                );
            } catch (NotFoundException $notFoundError) {
                $this->logger->err(
                    sprintf("%s: unexpected error %s", self::LOG_PREFIX, $notFoundError->getMessage())
                );
            } catch (\Exception $e) {
                $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
            }
        } else {
            $this->logger->debug(
                sprintf("%s: no activity to process in handleReview for review id %s", self::LOG_PREFIX, $id)
            );
        }
    }

    /**
     * This function is called when a changelist is committed, this also happens when a review is created
     *
     * @param Event $event
     */
    public function handleCommit(Event $event)
    {
        // connect to all tasks and write activity data
        // we do this late (low-priority) so all handlers have
        // a chance to influence the activity model.
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);

        $this->logger->debug(sprintf("%s: handle commit", self::LOG_PREFIX));

        // task.change doesn't include the change object; fetch it if we need to
        $change   = $event->getParam('change');
        $activity = $event->getParam('activity');
        $quiet    = $event->getParam('quiet');
        if ($activity && !$quiet) {
            try {
                if (!$change instanceof Change) {
                    $changeDAO = $this->services->get(IDao::CHANGE_DAO);
                    $change    = $changeDAO->fetchById($event->getParam(IReview::FIELD_ID), $p4Admin);
                    $event->setParam('change', $change);
                }
                // don't send the message if the commit is related to the review (stops copied messages)
                $keywords = $this->services->get(Keywords::SERVICE);
                $matches  = $keywords->getMatches($change->getRawValue('Description'));
                if ($matches && $matches[ IReview::FIELD_ID ]) {
                    $this->logger->debug(
                        sprintf("%s: committing review %s, do nothing", self::LOG_PREFIX, $matches[ IReview::FIELD_ID ])
                    );
                    return;
                }
                $lockName = Slack::KEY_PREFIX . "commit-" . $change->getId();
                $config   = $this->services->get(IConfigDefinition::CONFIG);
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
                        function () use ($change, $activity) {
                            $this->slackService->handleCreateSlackMessage($change, $activity);
                        },
                        $maxWait
                    );
                } catch (LockAcquisitionException $lockError) {
                    // could not acquire the lock; skip the Slack send rather than racing another writer
                    $this->logger->warn(
                        sprintf(
                            "%s: could not acquire lock '%s' for commit %s; skipping Slack notification",
                            self::LOG_PREFIX,
                            $lockName,
                            $change->getId()
                        )
                    );
                } catch (Exception $e) {
                    $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
                }
            } catch (NotFoundException $notFoundExceptionError) {
                $this->logger->debug(
                    sprintf("%s: unexpected error %s", self::LOG_PREFIX, $notFoundExceptionError->getMessage())
                );
            } catch (P4SpecException $specError) {
                $this->logger->debug(
                    sprintf("%s: unexpected error %s", self::LOG_PREFIX, $specError->getMessage())
                );
            }
        } else {
            $this->logger->debug(
                sprintf("%s: no activity to process in handleCommit", self::LOG_PREFIX)
            );
        }
    }
}
