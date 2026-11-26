<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

use Application\Factory\InvokableServiceFactory;
use Discord\Listener\Activity;
use Discord\Listener\Comment;
use Discord\Model\DiscordDAO;
use Discord\Service\Discord;
use Discord\Service\Utility;
use Events\Listener\ListenerFactory as EventListenerFactory;

return [
    'discord' => [
        'token'                        => '',
        'project_channels'             => [],
        'notify'                       => [],
        'users'                        => [],
        'summary_file_names'           => false,
        'summary_file_limit'           => 10,
        'bypass_restricted_changelist' => false,
    ],
    'listeners' => [Activity::class, Comment::class],
    'service_manager' => [
        'factories' => [
            Activity::class   => EventListenerFactory::class,
            Comment::class    => EventListenerFactory::class,
            Discord::class    => InvokableServiceFactory::class,
            Utility::class    => InvokableServiceFactory::class,
            DiscordDAO::class => InvokableServiceFactory::class,
        ],
    ],
    EventListenerFactory::EVENT_LISTENER_CONFIG => [
        EventListenerFactory::TASK_COMMIT => [
            Activity::class => [
                [
                    EventListenerFactory::PRIORITY        => -110,
                    EventListenerFactory::CALLBACK        => 'handleCommit',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        EventListenerFactory::TASK_REVIEW => [
            Activity::class => [
                [
                    EventListenerFactory::PRIORITY        => -110,
                    EventListenerFactory::CALLBACK        => 'handleReview',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        EventListenerFactory::TASK_COMMENT => [
            Comment::class => [
                [
                    EventListenerFactory::PRIORITY        => -110,
                    EventListenerFactory::CALLBACK        => 'handleReview',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ]
    ],
];
