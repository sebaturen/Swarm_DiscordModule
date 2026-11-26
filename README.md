# Swarm Discord Module

A Helix Swarm module that posts review and changelist notifications to Discord. It is a port of Swarm's built-in Slack module (Swarm 2026.4), using the Discord REST API with a bot token.

## How it works

- **Review requested** → summary in the **review** channel, with a thread named `[<changelist>] <description first line>`.
- **Review activity** (votes, comments, state changes) → replies in that thread.
- **Review submitted** → `Review submitted in [change N](link)` in the thread, the thread is closed, and the commit summary is posted in the **submit** channel.
- **Direct submit** (no review) → commit summary in the **submit** channel only.

## 1. Discord bot

Create an application in the [Discord Developer Portal](https://discord.com/developers/applications), add a bot, copy its token, and invite it with the `bot` scope and these permissions:

- View Channels
- Send Messages
- Embed Links
- Read Message History
- Create Public Threads
- Send Messages in Threads
- Manage Threads

## 2. Install the module

Copy this repository to Swarm's module folder:

```
/opt/perforce/swarm/module/Discord
```

The folder must contain `Module.php`, `config/` and `src/`.

Register it in `/opt/perforce/swarm/config/custom.modules.config.php` (create the file if it doesn't exist):

```php
<?php
\Laminas\Loader\AutoloaderFactory::factory([
    'Laminas\Loader\StandardAutoloader' => [
        'namespaces' => ['Discord' => BASE_PATH . '/module/Discord/src'],
    ],
]);
return ['Discord'];
```

If you already have other custom modules, add `'Discord'` to the existing namespaces and return list.

### Docker

If you are running Swarm in Docker, mount the custom modules file and the Discord module as volumes:

```yaml
    volumes:
      - /<route>/swarm/custom_modules/custom.modules.config.php:/opt/perforce/swarm/config/custom.modules.config.php
      - /<route>/swarm/Discord_module:/opt/perforce/swarm/module/Discord
```

## 3. Configure

Add a `discord` section to Swarm's `data/config.php`:

```php
'discord' => [
    'token' => '<bot token>',
    'project_channels' => [
        '<project id>' => [
            'review' => '<review channel id>',
            'submit' => '<submit channel id>',
        ],
    ],
    'notify' => ['author', 'reviewers'],
    'users' => [
        '<p4 username>' => '<discord user id>',
    ],
],
```

To get channel and user IDs, enable **Developer Mode** in Discord (User Settings → Advanced), then right-click a channel or user → **Copy ID**.

### `project_channels`

Maps a Swarm project ID to Discord channel IDs (not channel names).

- `review`: review summaries and their threads.
- `submit`: commit summaries, for direct submits and submitted reviews.

Each key accepts one ID or a list of IDs. If you use a plain list instead (`'<project id>' => ['<channel id>']`), reviews and submits go to the same channels.

Special keys, which accept the same formats:

- `*all*`: every notification.
- `*no_project_channel*`: projects without their own entry.
- `*without_project*`: changes and reviews that don't belong to any project.

### `users`

Maps P4 usernames to Discord user IDs. Discord can't look users up by email, so only users listed here can be @mentioned. Who gets mentioned is set in `notify` (`author`, `reviewers`, `default_reviewers`, `branch_moderators`, `project_default_reviewers`, `project_owners`, `project_members`).

## Disclaimer

This module was written autonomously by an AI, **Claude Opus 5.5 (Anthropic)**, based on Perforce's original Slack module. The maintainer did not write or review the code; he only tested it on his own Swarm installation. Use it at your own risk.

The original Slack module code is © Perforce Software.
