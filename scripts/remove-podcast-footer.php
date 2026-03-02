<?php

/**
 * @file
 * Script to remove Acast footer text from existing podcast_episode nodes.
 *
 * Usage: ddev drush php:script scripts/remove-podcast-footer.php
 */

use Drupal\node\Entity\Node;

$storage = \Drupal::entityTypeManager()->getStorage('node');

$nids = $storage->getQuery()
  ->condition('type', 'podcast_episode')
  ->accessCheck(FALSE)
  ->execute();

$updated = 0;
$skipped = 0;

foreach ($nids as $nid) {
  $node = Node::load($nid);
  if (!$node || $node->body->isEmpty()) {
    $skipped++;
    continue;
  }

  $original = $node->body->value;

  // Remove old Acast footer format.
  $footer = "<br /><hr><p style='color:grey; font-size:0.75em;'>";
  $value = explode($footer, $original)[0];

  // Remove new Acast footer format: "Support this show" paragraph and
  // everything that follows (the <hr> and privacy notice).
  $value = preg_replace('/<p[^>]*>\s*Support this show.*$/s', '', $value);
  $value = trim($value);

  if ($value === trim($original)) {
    $skipped++;
    continue;
  }

  $node->body->value = $value;
  $node->save();
  $updated++;
  echo "Updated: [{$nid}] " . $node->label() . "\n";
}

echo "\nDone. Updated: {$updated}, Skipped (no change): {$skipped}\n";
