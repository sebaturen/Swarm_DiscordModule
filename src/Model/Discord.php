<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Discord\Model;

use Notifications\Model\Notification;

class Discord extends Notification
{
    const UPGRADE_LEVEL = 0;
    const KEY_PREFIX    = 'notification-discord-';
}
