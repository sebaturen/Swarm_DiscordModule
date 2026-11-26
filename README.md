# Swarm Discord Module

A [P4 Code Review (Helix Swarm)](https://www.perforce.com/products/helix-swarm) module that posts review and changelist notifications to Discord.
It is a port of Swarm's built-in Slack module (Swarm 2026.4), reworked to use the Discord REST API with a bot token.

No separate bot process is needed: the module runs inside Swarm's queue workers and calls Discord directly.

## How it works

**Review flow**

1. A user requests a review → the bot posts a summary in the project's **review** channel and opens a thread under it, named `[<changelist>] <first line of the description>`.
2. Review activity (votes, comments, state changes, new revisions…) → posted as replies inside that thread.
3. The review is committed → the bot posts `Review submitted in [change N](link)` in the thread and **closes** (archives) it, then posts the commit summary in the project's **submit** channel.

**Direct submit flow**

1. A user submits a changelist without a review → the bot posts the commit summary in the project's **submit** channel only.

A closed thread is not locked: anyone can reopen it, and if new activity arrives on the review the bot's next message reopens it automatically.

Each summary is an embed with the title, a link to Swarm, the description, the projects and branches, and (optionally) the list of changed files. Configured users can be @mentioned in the summary.

## Requirements

- Helix Swarm 2026.4 (other versions with the same Slack module structure may work, untested)
- A Discord server where you can add a bot
- Swarm's built-in Slack module **not configured** (leave its token empty), so you don't get both

## 1. Create the Discord bot

1. Go to the [Discord Developer Portal](https://discord.com/developers/applications) → **New Application**.
2. Open **Bot** → **Reset Token** and copy the token (this is `token` in the config). Set the bot name and avatar here; the module can't change them per message.
3. Open **OAuth2 → URL Generator**, select the `bot` scope and these permissions:

| Permission | Why |
|---|---|
| View Channels | See the configured channels |
| Send Messages | Post summaries |
| Embed Links | Summaries are sent as embeds |
| Read Message History | Start a thread from the bot's summary message |
| Create Public Threads | One thread per review |
| Send Messages in Threads | Post review activity in the thread |
| Manage Threads | Close (archive) the thread when the review is submitted |

4. Open the generated URL and invite the bot to your server. If you use private channels, also give the bot access to those channels.

**Getting IDs:** in Discord enable **User Settings → Advanced → Developer Mode**. Then right-click a channel or a user → **Copy ID**.

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
    'token' => 'YOUR_BOT_TOKEN',
    'project_channels' => [
        'racerunner' => [
            'review' => '1575516121556058132',
            'submit' => '1575541610983325826',
        ],
        'otherproject' => ['1575500000000000000'],
        '*all*' => ['1575511111111111111'],
    ],
    'notify' => ['author', 'reviewers'],
    'users' => [
        'sturen' => '345678901234567890',
    ],
    'summary_file_names' => false,
    'summary_file_limit' => 10,
    'bypass_restricted_changelist' => false,
],
```

### `token` (required)

The bot token from the Developer Portal. If it is empty the module stays disabled.

### `project_channels`

Maps a Swarm **project ID** (as shown in the project's URL) to Discord **channel IDs** (not channel names).

Each entry can be written in two ways:

- **Split by type**: `review` and `submit` keys.
  - `review`: review summaries and their threads (review requested, votes, comments, state changes, review submitted).
  - `submit`: commit summaries, for direct submits and for submitted reviews.
  - Each key accepts one ID or a list: `'review' => ['id1', 'id2']`.
  - If you leave out a key, that type is not posted for the project.

  ```php
  'racerunner' => [
      'review' => '1575516121556058132',
      'submit' => '1575541610983325826',
  ],
  ```

- **Single list**: reviews and submits all go to the same channels.

  ```php
  'otherproject' => ['1575500000000000000'],
  ```

There are three special keys, which accept the same two formats:

| Key | Receives |
|---|---|
| `*all*` | Every notification, even ones already sent to a project channel |
| `*no_project_channel*` | Notifications for projects that have no entry in `project_channels` |
| `*without_project*` | Notifications for changes and reviews that don't belong to any project |

If every project of a change or review is private, it is posted only to those projects' own entries, never to the special keys. In other channels, private project names are hidden from the summary.

### `notify`

Which Swarm users are @mentioned in the summary message. Possible values:

| Value | Who |
|---|---|
| `author` | Review author or change submitter |
| `reviewers` | Current reviewers of the review |
| `default_reviewers` | Branch default reviewers |
| `branch_moderators` | Branch moderators (groups are expanded) |
| `project_default_reviewers` | Project default reviewers |
| `project_owners` | Project owners |
| `project_members` | All project members |

Swarm groups are expanded to their members. Only users listed in `users` can be mentioned.

### `users`

Maps Swarm user IDs to Discord user IDs. Discord can't look users up by email (Slack can), so this map is needed for mentions. Users that aren't in the map are simply not mentioned.

```php
'users' => [
    'sturen'    => '345678901234567890',
    'iguerrero' => '456789012345678901',
],
```

### `summary_file_names` / `summary_file_limit`

When `summary_file_names` is `true`, the summary includes the list of changed files, up to `summary_file_limit` files (default `10`).

### `bypass_restricted_changelist`

By default restricted changelists are never posted. Set to `true` to post them anyway.

### HTTP proxy / SSL

The module uses Swarm's `http_client_options` (the same setting the Slack module uses), so proxy or certificate settings configured there also apply to Discord (`discord.com`).

## 4. Apply the changes

1. Clear the config cache (delete `data/cache/*config*` or use **System Information → Reload Configuration**).
2. Restart Swarm (or the container) so the queue workers load the new code.

## Troubleshooting

Set `'log' => ['priority' => 7]` in `data/config.php`, trigger a review or a submit, and check the log:

```
grep -iE "discord|task\.(commit|review)" /opt/perforce/swarm/data/log | tail -50
```

| What you see | Meaning |
|---|---|
| Swarm shows a 500 error after installing | Module registered but broken, see the log |
| No `discord` lines at all | Token empty, module not registered, or workers not restarted |
| `failed with 401` | Wrong token |
| `failed with 403` | Missing permission in that channel (often Create Public Threads or Manage Threads) |
| `failed with 404` | Wrong channel ID |
| `failed with 429` | Discord rate limit; the module retries once, then drops the message |

The module saves thread IDs in Perforce keys, which you can check with:

```
p4 keys -e "*notification-discord*"
```

Remember to lower the log priority again after testing.

## Not supported

Compared to the Slack module, these features are not included:

- Direct messages to mentioned users (`notify_mentioned_only`)
- Uploading images from review comments
- Multiple Discord servers (Slack workspaces)
- Configuration from the Swarm admin UI (only `config.php`)
- Custom bot name or icon per message

## Disclaimer

This module was written autonomously by an AI, **Claude Opus 5.5 (Anthropic)**, based on Perforce's original Slack module. The maintainer did not write or review the code line by line; he only tested it on his own Swarm installation. Use it at your own risk, and test it on a non-production server first.

The original Slack module code is © Perforce Software. See Swarm's license for its terms.
