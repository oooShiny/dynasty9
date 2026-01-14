<?php

/**
 * @file
 * Script to remove position suffixes from player names.
 *
 * Usage: drush php:script scripts/cleanup-player-names.php
 */

use Drupal\node\Entity\Node;

// Get entity type manager.
$entity_type_manager = \Drupal::entityTypeManager();
$storage = $entity_type_manager->getStorage('node');

// Load all player nodes.
$query = $storage->getQuery()
  ->condition('type', 'player')
  ->accessCheck(FALSE);

$nids = $query->execute();

if (empty($nids)) {
  echo "No player nodes found.\n";
  return;
}

$players = $storage->loadMultiple($nids);
$updated_count = 0;
$skipped_count = 0;

echo "Processing " . count($players) . " players...\n\n";

foreach ($players as $player) {
  $original_title = $player->getTitle();

  // Remove position suffix pattern like "(QB)", "(WR)", "(LB-DE)", etc.
  // Pattern matches: space followed by opening paren, then letters/hyphens/slashes, then closing paren at end
  $cleaned_title = preg_replace('/\s+\([A-Z\/\-]+\)$/', '', $original_title);

  if ($cleaned_title !== $original_title) {
    $player->setTitle($cleaned_title);
    $player->save();
    $updated_count++;
    echo "✓ Updated: \"{$original_title}\" → \"{$cleaned_title}\"\n";
  }
  else {
    $skipped_count++;
  }
}

echo "\n";
echo "==========================================\n";
echo "Cleanup complete!\n";
echo "Updated: {$updated_count}\n";
echo "Skipped: {$skipped_count}\n";
echo "Total: " . count($players) . "\n";
echo "==========================================\n";
