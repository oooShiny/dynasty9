<?php

namespace Drupal\dynasty_module\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for player cleanup tasks.
 */
class PlayerCleanupCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new PlayerCleanupCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Remove position suffixes from player names.
   *
   * Removes patterns like "(QB)", "(WR)", "(LB)" from player node titles.
   *
   * @command dynasty:player:cleanup-names
   * @aliases dpcn
   * @usage dynasty:player:cleanup-names
   *   Remove position suffixes from all player names.
   */
  public function cleanupPlayerNames() {
    $storage = $this->entityTypeManager->getStorage('node');

    // Load all player nodes
    $query = $storage->getQuery()
      ->condition('type', 'player')
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (empty($nids)) {
      $this->logger()->warning('No player nodes found.');
      return;
    }

    $players = $storage->loadMultiple($nids);
    $updated_count = 0;
    $skipped_count = 0;

    foreach ($players as $player) {
      $original_title = $player->getTitle();

      // Remove position suffix pattern like "(QB)", "(WR)", "(LB-DE)", etc.
      // Pattern matches: space followed by opening paren, then letters/hyphens/slashes, then closing paren at end
      $cleaned_title = preg_replace('/\s+\([A-Z\/\-]+\)$/', '', $original_title);

      if ($cleaned_title !== $original_title) {
        $player->setTitle($cleaned_title);
        $player->save();
        $updated_count++;
        $this->logger()->success(sprintf('Updated: "%s" → "%s"', $original_title, $cleaned_title));
      }
      else {
        $skipped_count++;
      }
    }

    $this->logger()->success(sprintf(
      'Cleanup complete! Updated: %d, Skipped: %d, Total: %d',
      $updated_count,
      $skipped_count,
      count($players)
    ));
  }

}
