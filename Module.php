<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Discord;

class Module
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
