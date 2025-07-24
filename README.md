# Embed Migrate

A Drush command to migrate legacy video embeds (e.g. `<video_url>`, Instagram URLs, old `<div>` formats) in Drupal node bodies to the modern `<drupal-url>` format.

## Features

- Converts:
  - JSON video embed blobs
  - `<div data-embed-url>` wrappers
  - `<video-embed src="...">`
  - `<drupal-entity ...>` embeds
- Keeps YouTube, Instagram, Facebook etc. URLs
- Logs node IDs and what was converted
- Skips already updated entries

## Installation

Place the module in `web/modules/custom/embed_migrate/`

Then enable it with:

```bash
drush en embed_migrate
```

## Usage

```bash
drush embed-migrate
```

You can also limit by node type or run in dry-run mode:

```bash
drush embed-migrate --type=article --dry-run
```

## Author

Dimitris Kalamaras – https://github.com/oxy86/

## LICENSE 

MIT
