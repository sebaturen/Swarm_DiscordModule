<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */
namespace Slack\Service;

use Application\Config\IConfigDefinition as IDef;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Configurations\Model\IConfiguration;
use Configurations\Service\IFetchConfig;
use Interop\Container\ContainerInterface;

/**
 * Normalises the Slack config block into a unified workspace list.
 * @package Slack\Service
 */
class WorkspaceResolver implements IWorkspaceResolver, InvokableService
{
    const LOG_PREFIX = WorkspaceResolver::class;

    private $services;

    /**
     * Constructor.
     *
     * @param ContainerInterface $services Service locator.
     * @param array|null         $options  Unused options array.
     */
    public function __construct(
        ContainerInterface $services,
        ?array $options = null
    ) {
        $this->services = $services;
    }

    /**
     * Returns a valid-only list of workspace blocks.
     *
     * Each element has its workspace id embedded under the 'id' key.
     * Legacy flat config is wrapped with id 'default'.
     * Workspaces with empty or whitespace-only tokens are dropped.
     *
     * @return array List of workspace arrays, each with embedded 'id'.
     */
    public function getWorkspaces(): array
    {
        // Check the P4-backed Configurations record first (UI-managed source of truth).
        // If a record exists with active workspaces, use those — the UI value wins.
        // Fall back to config.php (via the existing multi/legacy detection) when the
        // record is absent or has no active workspaces (e.g. fresh installs that have
        // never opened the Slack Configuration UI).
        $workspacesFromRecord = $this->_resolveFromRecord();
        if ($workspacesFromRecord !== null) {
            return $workspacesFromRecord;
        }

        // Fallback: legacy config.php path (flat or multi-workspace convention).
        $config = $this->services->get(IDef::CONFIG);
        $slack  = $config[IDef::SLACK] ?? [];
        if (!is_array($slack)) {
            return [];
        }
        $multiEntries = array_filter(
            $slack,
            function ($v) {
                return is_array($v) && isset($v[IDef::TOKEN]);
            }
        );
        if (!empty($multiEntries)) {
            return $this->_resolveMulti($slack);
        }
        return $this->_resolveLegacy($slack);
    }

    /**
     * Try to load workspaces from the P4-backed Configurations record.
     * Returns the active workspace list if the record exists and has at least one
     * active (non-deleted) workspace with a non-blank token; returns null otherwise
     * so the caller falls back to config.php.
     *
     * @return array|null Active workspace list, or null to trigger fallback.
     */
    private function _resolveFromRecord(): ?array // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        try {
            $fetchConfig = $this->services->get(IFetchConfig::SERVICE);
            $record      = $fetchConfig->getConfiguration(IDef::SLACK, IDef::SLACK);
            if (!is_array($record) || empty($record[IConfiguration::WORKSPACES])) {
                return null;
            }
            $workspaces = $record[IConfiguration::WORKSPACES];
            if (!is_array($workspaces)) {
                return null;
            }
            // Filter to active (non-soft-deleted) workspaces with a non-blank token.
            // FetchConfig::normalizeKeysRecursive converts 'isDeleted' → 'is_deleted'.
            $active = array_values(
                array_filter(
                    $workspaces,
                    function (array $ws): bool {
                        if (!empty($ws['is_deleted'])) {
                            return false;
                        }
                        $token = trim((string) ($ws[IConfiguration::TOKEN] ?? ''));
                        return $token !== '';
                    }
                )
            );
            if (empty($active)) {
                return null;
            }
            // Convert P4 record camelCase keys → snake_case so Slack.php/Utility.php
            // can read them without modification (they were written against config.php
            // conventions which use snake_case, matching IDef constants).
            // Also normalise projectChannels from array-of-objects [{project,channels}]
            // to the keyed dict {'*all*': ['ch']} that Utility::getProjectMappings() expects.
            foreach ($active as &$ws) {
                // project_channels: array-of-objects → keyed dict
                $pc = $ws[IConfiguration::PROJECT_CHANNELS] ?? $ws['project_channels'] ?? [];
                if (!empty($pc) && isset($pc[0]) && is_array($pc[0])) {
                    $dict = [];
                    foreach ($pc as $row) {
                        if (!isset($row['project'], $row['channels'])) {
                            continue;
                        }
                        $project = trim((string) $row['project']);
                        // Normalise bare 'all' → '*all*' for user convenience
                        if ($project === 'all') {
                            $project = '*all*';
                        }
                        $dict[$project] = (array) $row['channels'];
                    }
                    $pc = $dict;
                }
                $ws['project_channels']             = $pc;
                $ws['summary_file_names']           = $ws[IConfiguration::SUMMARY_FILE_NAMES]
                    ?? $ws['summary_file_names'] ?? false;
                $ws['reply_file_names']             = $ws[IConfiguration::REPLY_FILE_NAMES]
                    ?? $ws['reply_file_names'] ?? false;
                $ws['bypass_restricted_changelist'] = $ws[IConfiguration::BYPASS_RESTRICTED_CHANGELIST]
                    ?? $ws['bypass_restricted_changelist'] ?? false;
                $ws['notify_mentioned_only']        = $ws[IConfiguration::NOTIFY_MENTIONED_ONLY]
                    ?? $ws['notify_mentioned_only'] ?? false;
                $ws['summary_file_limit']           = $ws[IConfiguration::SUMMARY_FILE_LIMIT]
                    ?? $ws['summary_file_limit'] ?? 10;
                // user sub-group: keep as-is (Slack.php reads $workspace[IDef::USER] = 'user')
                if (isset($ws[IConfiguration::USER]) && !isset($ws['user'])) {
                    $ws['user'] = $ws[IConfiguration::USER];
                }
                // forceUserHeader inside user group
                if (isset($ws['user'][IConfiguration::FORCE_USER_HEADER])
                    && !isset($ws['user']['force_user_header'])
                ) {
                    $ws['user']['force_user_header'] = $ws['user'][IConfiguration::FORCE_USER_HEADER];
                }
            }
            unset($ws);
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->debug(
                self::LOG_PREFIX . ': loaded ' . count($active) . ' workspace(s) from P4 Configurations record'
            );
            return $active;
        } catch (\Throwable $e) {
            // Catches both Exception and Error (e.g. P4 extension not yet available at bootstrap).
            // Silently fall back to config.php.
            return null;
        }
    }

    /**
     * Returns true if at least one valid workspace is configured.
     *
     * @return bool True when at least one workspace has a non-blank token.
     */
    public function hasConfiguredWorkspace(): bool
    {
        return !empty($this->getWorkspaces());
    }

    /**
     * Resolves multi-workspace config into a list array.
     *
     * @param array $slack The full slack config block.
     *
     * @return array List of workspace arrays with embedded 'id'.
     */
    private function _resolveMulti(array $slack): array // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        $result = [];
        $logger = $this->services->get(SwarmLogger::SERVICE);
        foreach ($slack as $id => $block) {
            if (!is_array($block) || !isset($block[IDef::TOKEN])) {
                continue;
            }
            $token = trim((string) $block[IDef::TOKEN]);
            if ($token === '') {
                $logger->notice(
                    self::LOG_PREFIX
                    . ': workspace "' . $id
                    . '" has a blank token, skipping'
                );
                continue;
            }
            $result[] = ['id' => $id] + $block;
        }
        return $result;
    }

    /**
     * Resolves a flat (legacy) config block into a one-element list.
     *
     * @param array $slack The full flat slack config block.
     *
     * @return array One-element list, or empty if no valid token found.
     */
    private function _resolveLegacy(array $slack): array // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        $raw   = $slack[IDef::TOKEN] ?? '';
        $token = trim((string) $raw);
        if ($token === '') {
            return [];
        }
        return [['id' => self::DEFAULT_ID] + $slack];
    }
}
