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

```bash
composer require drupal/embed_migrate
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

Dimitris Kalamaras – socnetv.org

### LICENSE 

MIT