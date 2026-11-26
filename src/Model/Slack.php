<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */

namespace Slack\Model;

use Notifications\Model\Notification;

/**
 * Model for a Slack notification
 */
class Slack extends Notification implements ISlack
{
    const UPGRADE_LEVEL = 0;
    const KEY_PREFIX    = 'notification-slack-';
}
