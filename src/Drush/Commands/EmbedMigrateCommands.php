<?php

namespace Drupal\embed_migrate\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Query\Condition;
use Drupal\node\Entity\Node;

/**
 * Fix drupal-url embeds in node body and auto-fill missing provider.
 */
function fix_drupal_url_embeds(Node $node) {
  $body = $node->get('body')->value;

  // Regular expression to find all <drupal-url> tags.
  $pattern = '/<drupal-url([^>]*)data-embed-url="([^"]+)"([^>]*)><\/drupal-url>/i';

  $body = preg_replace_callback($pattern, function ($matches) {
    $attrs_before = $matches[1];
    $url = html_entity_decode($matches[2]);
    $attrs_after = $matches[3];

    // Try to find existing provider.
    preg_match('/data-url-provider="([^"]*)"/', $attrs_before . $attrs_after, $provider_match);
    $provider = $provider_match[1] ?? '';

    // Auto-detect provider if missing.
    if (empty($provider)) {
      if (strpos($url, 'instagram.com') !== false) {
        $provider = 'Instagram';
      } elseif (strpos($url, 'facebook.com') !== false) {
        $provider = 'Facebook';
      } elseif (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
        $provider = 'YouTube';
      } else {
        $provider = 'Unknown';
      }
    }

    // Build cleaned tag.
    return '<drupal-url data-embed-url="' . $url . '" data-url-provider="' . $provider . '"></drupal-url>';
  }, $body);

  // Update node.
  $node->set('body', ['value' => $body, 'format' => $node->body->format]);
  $node->save();
}


final class EmbedMigrateCommands extends DrushCommands
{

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  #[CLI\Command(name: 'embed:migrate', aliases: ['emigrate'])]
  #[CLI\Option(name: 'dry-run', description: 'Show which nodes would be updated without modifying them')]
  public function embedMigrate(array $options = ['dry-run' => false]): int
  {
    $dryRun = (bool) ($options['dry-run'] ?? false);
    $this->output()->writeln('🔍 Searching for nodes with legacy embeds.');

    $query = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])
      ->distinct();

    $query->join('node__body', 'b', 'n.nid = b.entity_id AND n.langcode = b.langcode');

    $group = $query->orConditionGroup()
      ->condition('b.body_value', '%video_url%', 'LIKE')
      ->condition('b.body_value', '%data-embed-url%', 'LIKE')
      ->condition('b.body_value', '%video_embed%', 'LIKE')
      ->condition('b.body_value', '%data-entity-type="media"%', 'LIKE')
      ->condition('b.body_value', '%drupal-entity data-embed-button="video_embed"%', 'LIKE');

    $query->condition($group);

    $nids = $query->execute()->fetchCol();

    if (empty($nids)) {
      $this->output()->writeln('✅ No matching nodes found.');
      return 0;
    }

    $this->output()->writeln('🔧 Found ' . count($nids) . ' node(s) with legacy embed code.');

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $updated = 0;

    foreach ($nids as $nid) {
      $node = $nodeStorage->load($nid);
      if (!$node || !$node->hasField('body')) {
        continue;
      }

      $body = $node->get('body')->value;

      # $this->output()->writeln("🔎 Node $nid original body snippet: " . substr($body, 0, 1200));

      $original = $body;

      // 1. Replace <p>{"video_url":...}</p> blocks with <drupal-url>
      $body = preg_replace_callback(
        '/<p>\s*\{.*?"video_url"\s*:\s*"([^"]+?)".*?\}<\/p>/is',
        function ($matches) {
          $url = html_entity_decode($matches[1]); // Decodes &amp;amp; -> & etc
          return '<drupal-url data-embed-url="' . $url . '"></drupal-url>';
        },
        $body
      );

      // 2. Fix existing <drupal-url> with double-encoded ampersands.
      $body = preg_replace_callback(
        '/<drupal-url[^>]+data-embed-url="([^"]+)"[^>]*>/i',
        function ($matches) {
          $url = html_entity_decode($matches[1]);
          return str_replace($matches[1], $url, $matches[0]);
        },
        $body
      );

      // 3. Strip &nbsp; from drupal-url inner HTML
      $body = preg_replace(
        '/<drupal-url([^>]*)>(&nbsp;|\s*)<\/drupal-url>/i',
        '<drupal-url$1></drupal-url>',
        $body
      );

      // Legacy case 2: <div data-embed-url="..."></div>
      $body = preg_replace_callback('/<div[^>]*data-embed-url=\"(.*?)\"[^>]*><\\/div>/is', function ($matches) {
        $url = html_entity_decode($matches[1]);
        return '<drupal-url data-embed-url="' . $url . '" data-url-provider="YouTube"></drupal-url>';
      }, $body);

      // Legacy case 3: <video-embed src="...">
      $body = preg_replace_callback('/<video-embed[^>]*src=\"(.*?)\"[^>]*><\\/video-embed>/is', function ($matches) {
        $url = html_entity_decode($matches[1]);
        return '<drupal-url data-embed-url="' . $url . '" data-url-provider="YouTube"></drupal-url>';
      }, $body);

      // Additional case: Fix incomplete or broken <drupal-url> tags
      $body=$this->fix_drupal_url_embeds($body);

      if ($body !== $original) {
        if ($dryRun) {
          $this->output()->writeln("📝 Would update node $nid");
          $this->output()->writeln("🔎 Node $nid original snippet: " . substr($original, 0, 1200));
          $this->output()->writeln("🔎 Node $nid updated body snippet: " . substr($body, 0, 1200));
        } else {
          $node->get('body')->value = $body;
          $node->save();
          $this->output()->writeln("✅ Updated node $nid");
        }
        $updated++;
      }
    }

    if ($dryRun) {
      $this->output()->writeln("🧪 Dry-run complete: $updated node(s) would be updated.");
    }
    else {
      $this->output()->writeln("✅ Migration complete: $updated node(s) updated.");
    }

    return 0;
  }




  /**
   * HELPER - Fix drupal-url embeds in node body and auto-fill missing provider.
   */
  public function fix_drupal_url_embeds($body)
  {

    // Regular expression to find all <drupal-url> tags.
    $pattern = '/<drupal-url([^>]*)data-embed-url=\"([^\"]+)\"([^>]*)><\\/drupal-url>/i';

      $body = preg_replace_callback($pattern, function ($matches) {
        $attrs_before = $matches[1];
        $url = html_entity_decode($matches[2]);
        $attrs_after = $matches[3];

        preg_match('/data-url-provider=\"([^\"]*)\"/', $attrs_before . $attrs_after, $provider_match);
        $provider = $provider_match[1] ?? '';

        if (empty($provider)) {
          if (strpos($url, 'instagram.com') !== false) {
            $provider = 'Instagram';
          } elseif (strpos($url, 'facebook.com') !== false) {
            $provider = 'Facebook';
          } elseif (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            $provider = 'YouTube';
          } else {
            $provider = 'Unknown';
          }
        }

        return '<drupal-url data-embed-url="' . $url . '" data-url-provider="' . $provider . '"></drupal-url>';
      }, $body);

    // Update node.
    return $body;

  }
}
