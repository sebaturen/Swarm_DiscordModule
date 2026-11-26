<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */
namespace Slack\Model;

use Application\Model\AbstractDAO;

/**
 * DAO for a Slack notification
 */
class SlackDAO extends AbstractDAO implements ISlackDAO
{
    const MODEL = Slack::class;
}
