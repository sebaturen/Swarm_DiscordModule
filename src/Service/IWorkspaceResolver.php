<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */
namespace Slack\Service;

/**
 * Interface for the Slack workspace resolver service.
 * @package Slack\Service
 */
interface IWorkspaceResolver
{
    const SERVICE_NAME = 'SlackWorkspaceResolver';
    const DEFAULT_ID   = 'default';

    /**
     * Returns a valid-only list of workspace blocks.
     *
     * Each element has its workspace id embedded under the 'id' key.
     * Legacy flat config is wrapped with id 'default'.
     *
     * @return array List of workspace arrays, each with embedded 'id'.
     */
    public function getWorkspaces(): array;

    /**
     * Returns true if at least one valid workspace is configured.
     *
     * @return bool True when at least one workspace has a non-blank token.
     */
    public function hasConfiguredWorkspace(): bool;
}
