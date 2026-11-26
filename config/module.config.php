<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2026 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2026.4/3138518
 */

use Application\Config\IConfigDefinition as IDef;
use Application\Crypto\EncryptedFieldsRegistry;
use Application\Factory\InvokableServiceFactory;
use Events\Listener\ListenerFactory as EventListenerFactory;
use Slack\Listener\Activity;
use Slack\Listener\Comment;
use Slack\Service\ISlack;
use Slack\Service\IUtility;
use Slack\Service\IWorkspaceResolver;
use Slack\Service\Slack;
use Slack\Service\Utility;
use Slack\Service\WorkspaceResolver;
use Slack\Model\SlackDAO;
use Application\Config\IDao;

$listeners = [Activity::class, Comment::class];

return [
    // Everything in the 'slack' namespace is meant to be configurable
    IDef::SLACK => [
        // REQUIRED, must be set to the OAuth Token for the app
        // IDef::TOKEN => "",
        // This allows individual projects to be configured to send messages to
        // different Slack channels. There are three special values:
        //    '*no_project_channel*'   Notifications with a project which isn't specified here
        //    '*without_project*'      Notifications with no associated project
        //    '*all*'                  All notifications, even those matched already
        //
        // Individual projects can be specified, and directed to one or more
        // channels. Either the channel name, or a channel ID can be used
        // The channels must be public.
        IDef::PROJECT_CHANNELS => [
//            'myproject' => [
//                'myproject-channel',
//            ],
//            // Project with no setting will go to no-project-channel
//            '*no_project_channel*' => [
//                'no-project-channel',
//            ],
//            // No project on this review/change.
//            '*without_project*' => [
//                'no-projects-linked',
//            ],
//            // All notification get sent here even if we matched above.
//            '*all*' => [
//                'all-notifications',
//            ]
        ],

        // These two options control whether a list of files in the review is included. They can
        // be either inserted directly into the main message, or added as a reply to the message.
        // Recommended that you don't set both to true, or you'll just be repeating information.
        // If a review has a lot of files, then messages could get quite long, so hiding them in
        // a reply might be preferred.
        IDef::SUMMARY_FILE_NAMES => false,
        IDef::REPLY_FILE_NAMES => false,
        IDef::BYPASS_RESTRICTED_CHANGELIST => false,
        IDef::NOTIFY_MENTIONED_ONLY => false,
        // Notify specific users via @mention in Slack messages.
        // Valid values: 'author', 'default_reviewers', 'branch_moderators'
        // Requires the bot token to have the users:read.email OAuth scope.
        // IDef::NOTIFY => [
        //     IDef::SLACK_NOTIFY_AUTHOR,
        //     IDef::SLACK_NOTIFY_DEFAULT_REVIEWERS,
        //     IDef::SLACK_NOTIFY_BRANCH_MODERATORS,
        // ],
        IDef::NOTIFY => [],
        // The number of files that will be listed in a Slack message
        IDef::SUMMARY_FILE_LIMIT => 10,
        IDef::USER => [
            IDef::ENABLED => true, // Forces the swarm bot to use a custom username and avatar
            IDef::NAME => 'Helix Swarm', // The name that the swarm bot uses when posting messages
            IDef::ICON => // The icon that the swarm bot uses when posting messages
               'https://swarm.workshop.perforce.com/view/guest/perforce_software/slack/main/images/60x60-Helix-Bee.png',
            IDef::FORCE_USER_HEADER => false, // Forces Slack to add the user header (name and avatar) for every message
        ]
    ],
    'listeners' => $listeners,
    'service_manager' => [
        'factories' => [
            Activity::class          => EventListenerFactory::class,
            Comment::class           => EventListenerFactory::class,
            Slack::class             => InvokableServiceFactory::class,
            Utility::class           => InvokableServiceFactory::class,
            SlackDAO::class          => InvokableServiceFactory::class,
            WorkspaceResolver::class => InvokableServiceFactory::class,
        ],
        'aliases' => [
            ISlack::SERVICE_NAME             => Slack::class,
            IUtility::SERVICE_NAME           => Utility::class,
            IDao::SLACK_DAO                  => SlackDAO::class,
            IWorkspaceResolver::SERVICE_NAME => WorkspaceResolver::class,
        ]
    ],
    EventListenerFactory::EVENT_LISTENER_CONFIG => [
        EventListenerFactory::TASK_COMMIT => [
            Activity::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleCommit',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        EventListenerFactory::TASK_REVIEW => [
            Activity::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleReview',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        EventListenerFactory::TASK_COMMENT => [
            Comment::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleReview',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ]
    ],
    // Fields encrypted at rest by the Encryption module (SW-15523). The path
    // walks each Slack workspace in the stored config and encrypts its token.
    EncryptedFieldsRegistry::CONFIG_KEY => [
        IDef::SLACK => ['workspaces.*.token'],
    ]
];
