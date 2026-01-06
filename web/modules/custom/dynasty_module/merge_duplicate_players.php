<?php

/**
 * @file
 * Script to merge duplicate player nodes by updating references and aliases.
 *
 * Run with: ddev drush php:script web/modules/custom/dynasty_module/merge_duplicate_players.php
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

// Get all old player path aliases
$database = \Drupal::database();

// Get mapping of old NID to new NID based on name
echo "Building player NID mapping...\n";

// Get all old player aliases
$old_aliases_query = $database->query("
  SELECT pa.path, pa.alias
  FROM path_alias pa
  WHERE pa.alias LIKE '/player/%'
  AND SUBSTRING(pa.path, 7) + 0 < 9654
");

$nid_map = [];
$skipped = [];

foreach ($old_aliases_query as $row) {
  $old_nid = (int) str_replace('/node/', '', $row->path);

  // Convert URL slug to player name
  // e.g., /player/troy-brown -> Troy Brown
  $slug = str_replace('/player/', '', $row->alias);
  $name_parts = explode('-', $slug);
  $name_parts = array_map('ucfirst', $name_parts);
  $player_name = implode(' ', $name_parts);

  // Find new player node with this name
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'player')
    ->condition('title', $player_name)
    ->condition('nid', 9654, '>=')
    ->accessCheck(FALSE);

  $nids = $query->execute();

  if (count($nids) === 1) {
    $new_nid = reset($nids);
    $nid_map[$old_nid] = $new_nid;
    echo "  {$old_nid} -> {$new_nid}: {$player_name}\n";
  }
  elseif (count($nids) > 1) {
    // Multiple matches - need to use jersey number or position to disambiguate
    $old_jersey = $database->query("SELECT field_jersey_number_value FROM node__field_jersey_number WHERE entity_id = :nid", [':nid' => $old_nid])->fetchField();

    foreach ($nids as $potential_nid) {
      $new_jersey = $database->query("SELECT field_jersey_number_value FROM node__field_jersey_number WHERE entity_id = :nid", [':nid' => $potential_nid])->fetchField();

      if ($old_jersey == $new_jersey) {
        $nid_map[$old_nid] = $potential_nid;
        echo "  {$old_nid} -> {$potential_nid}: {$player_name} (matched by jersey #{$old_jersey})\n";
        break;
      }
    }

    if (!isset($nid_map[$old_nid])) {
      echo "  SKIPPED {$old_nid}: {$player_name} - multiple matches, couldn't disambiguate\n";
      $skipped[] = ['old_nid' => $old_nid, 'name' => $player_name, 'count' => count($nids)];
    }
  }
  else {
    echo "  SKIPPED {$old_nid}: {$player_name} - no match found\n";
    $skipped[] = ['old_nid' => $old_nid, 'name' => $player_name, 'count' => 0];
  }
}

echo "\nMapped " . count($nid_map) . " players\n";
echo "Skipped " . count($skipped) . " players\n\n";

// Update field references in node__field_players_involved
echo "Updating highlight player references...\n";
$updated_count = 0;
foreach ($nid_map as $old_nid => $new_nid) {
  $result = $database->update('node__field_players_involved')
    ->fields(['field_players_involved_target_id' => $new_nid])
    ->condition('field_players_involved_target_id', $old_nid)
    ->execute();

  if ($result > 0) {
    echo "  Updated {$result} references from {$old_nid} to {$new_nid}\n";
    $updated_count += $result;
  }
}

echo "Updated {$updated_count} highlight references\n\n";

// Update paragraph references
echo "Updating paragraph player references...\n";
$paragraph_count = 0;
foreach ($nid_map as $old_nid => $new_nid) {
  $result = $database->update('paragraph__field_players_involved')
    ->fields(['field_players_involved_target_id' => $new_nid])
    ->condition('field_players_involved_target_id', $old_nid)
    ->execute();

  if ($result > 0) {
    echo "  Updated {$result} paragraph references from {$old_nid} to {$new_nid}\n";
    $paragraph_count += $result;
  }
}

echo "Updated {$paragraph_count} paragraph references\n\n";

// Update path aliases
echo "Updating path aliases...\n";
$alias_count = 0;
foreach ($nid_map as $old_nid => $new_nid) {
  $result = $database->update('path_alias')
    ->fields(['path' => '/node/' . $new_nid])
    ->condition('path', '/node/' . $old_nid)
    ->execute();

  if ($result > 0) {
    echo "  Updated alias from /node/{$old_nid} to /node/{$new_nid}\n";
    $alias_count += $result;
  }
}

echo "Updated {$alias_count} path aliases\n\n";

// Delete orphaned path aliases for unmapped old players
echo "Cleaning up orphaned aliases...\n";
$orphaned_count = $database->delete('path_alias')
  ->condition('path', '/node/%', 'LIKE')
  ->condition('path', '/node/9654', '<')
  ->execute();

echo "Deleted {$orphaned_count} orphaned path aliases\n\n";

// Clear caches
echo "Clearing caches...\n";
drupal_flush_all_caches();

echo "Done!\n\n";
echo "Summary:\n";
echo "- Mapped " . count($nid_map) . " old players to new players\n";
echo "- Updated {$updated_count} highlight references\n";
echo "- Updated {$paragraph_count} paragraph references\n";
echo "- Updated {$alias_count} path aliases\n";
echo "- Deleted {$orphaned_count} orphaned aliases\n";
echo "- Skipped " . count($skipped) . " players\n";

if (!empty($skipped)) {
  echo "\nSkipped players:\n";
  foreach ($skipped as $skip) {
    echo "  - {$skip['name']} (old NID: {$skip['old_nid']}, matches: {$skip['count']})\n";
  }
}
