<?php

namespace Drupal\dynasty_plays\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dynasty_plays\Service\GamePlayerMatcher;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing the quarterly offensive stats CSV.
 */
class QuarterlyStatsImportCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The shared game/player matcher.
   *
   * @var \Drupal\dynasty_plays\Service\GamePlayerMatcher
   */
  protected $matcher;

  /**
   * Numeric stat columns in the CSV, keyed by column name, valued by the
   * Player Game Stat field they populate.
   *
   * @var array
   */
  protected $statColumns = [
    'completions' => 'stat_completions',
    'attempts' => 'stat_attempts',
    'pass_yards' => 'stat_pass_yards',
    'interceptions' => 'stat_interceptions',
    'pass_td' => 'stat_pass_td',
    'carries' => 'stat_carries',
    'rush_yards' => 'stat_rush_yards',
    'rush_td' => 'stat_rush_td',
    'targets' => 'stat_targets',
    'receptions' => 'stat_receptions',
    'rec_yards' => 'stat_rec_yards',
    'rec_td' => 'stat_rec_td',
  ];

  /**
   * Constructs a new QuarterlyStatsImportCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\dynasty_plays\Service\GamePlayerMatcher $matcher
   *   The shared game/player matcher.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, GamePlayerMatcher $matcher) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->matcher = $matcher;
  }

  /**
   * Import per-quarter player stats from the quarterly offensive stats CSV.
   *
   * @param array $options
   *   Command options.
   *
   * @command dynasty:import-quarterly-stats
   * @aliases diqs
   * @option file Path to the CSV file. Defaults to the bundled data file in dynasty_plays/data.
   * @option limit Only process the first N data rows (for testing).
   * @option wipe Delete all existing Player Game Stat entities before importing.
   * @option dry-run Parse and match rows but don't create any entities. Prints a summary of unmatched games/players.
   * @usage dynasty:import-quarterly-stats --dry-run
   *   Preview matching results without writing anything.
   * @usage dynasty:import-quarterly-stats --wipe
   *   Clear existing stat entities and re-import from scratch.
   */
  public function import(array $options = [
    'file' => NULL,
    'limit' => NULL,
    'wipe' => FALSE,
    'dry-run' => FALSE,
  ]) {
    $file = $options['file'] ?: \Drupal::service('extension.list.module')->getPath('dynasty_plays') . '/data/ne_quarterly_offensive_stats.csv';
    if (!is_file($file)) {
      $this->logger()->error(sprintf('CSV file not found: %s', $file));
      return;
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
      $this->logger()->error('Unable to open CSV file.');
      return;
    }

    $header = fgetcsv($handle);
    if (!$header) {
      $this->logger()->error('CSV file has no header row.');
      return;
    }
    $header = array_map('trim', $header);

    $stat_storage = $this->entityTypeManager->getStorage('player_game_stat');

    if ($options['wipe']) {
      $existing_ids = $stat_storage->getQuery()->accessCheck(FALSE)->execute();
      if ($existing_ids) {
        $stat_storage->delete($stat_storage->loadMultiple($existing_ids));
        $this->logger()->notice(sprintf('Deleted %d existing Player Game Stat entities.', count($existing_ids)));
      }
    }

    $game_map = $this->matcher->buildGameDateMap();
    $player_index = $this->matcher->buildPlayerIndex();

    $created = 0;
    $processed = 0;
    $missing_games = [];
    $player_matched = 0;
    $player_unmatched = [];
    $limit = $options['limit'] ? (int) $options['limit'] : NULL;

    while (($row = fgetcsv($handle)) !== FALSE) {
      if ($limit !== NULL && $processed >= $limit) {
        break;
      }
      $processed++;

      // Map the row onto the header so column order in the source file
      // doesn't matter.
      $data = array_combine($header, array_pad($row, count($header), ''));

      // The CSV mixes two date formats depending on era: 2000+ rows are
      // already 'Y-m-d', while 1978-1999 rows use PFR's raw "<Month> <Day>"
      // box-score style (matching the play-by-play CSV) and need the
      // season to resolve the year -- ::normalizeGameDate() handles both.
      $season = (int) ($data['season'] ?? 0);
      $game_date = trim($data['game_date'] ?? '');
      $date_key = $this->matcher->normalizeGameDate($game_date, $season);
      $nid = $date_key ? ($game_map[$date_key] ?? NULL) : NULL;
      if (!$nid) {
        $missing_games[$game_date] = ($missing_games[$game_date] ?? 0) + 1;
        continue;
      }

      $player_name = trim($data['player'] ?? '');
      $player_nid = $this->matcher->matchPlayer($player_name, $player_index);
      if ($player_nid) {
        $player_matched++;
      }
      else {
        $player_unmatched[$player_name] = ($player_unmatched[$player_name] ?? 0) + 1;
      }

      if ($options['dry-run']) {
        $created++;
        if ($processed % 2000 === 0) {
          $this->logger()->notice(sprintf('Checked %d rows...', $processed));
        }
        continue;
      }

      $values = [
        'stat_game' => $nid,
        'stat_player_name' => $player_name,
        'stat_quarter' => trim($data['quarter'] ?? ''),
        'stat_category' => trim($data['category'] ?? ''),
      ];
      if ($player_nid) {
        $values['stat_player'] = $player_nid;
      }
      foreach ($this->statColumns as $column => $field_name) {
        $raw = trim($data[$column] ?? '');
        $values[$field_name] = $raw === '' ? NULL : (int) $raw;
      }

      $entity = $stat_storage->create($values);
      $entity->save();
      $created++;

      if ($processed % 1000 === 0) {
        $this->logger()->notice(sprintf('Imported %d rows...', $processed));
      }
    }
    fclose($handle);

    $this->logger()->success(sprintf(
      '%s %d of %d rows. Players matched: %d. Players unmatched: %d distinct names (%d rows). Games not found: %d distinct dates (%d rows).',
      $options['dry-run'] ? 'Checked' : 'Imported',
      $created,
      $processed,
      $player_matched,
      count($player_unmatched),
      array_sum($player_unmatched),
      count($missing_games),
      array_sum($missing_games)
    ));

    if ($missing_games) {
      $this->logger()->warning('Game dates with no matching Game node: ' . implode(', ', array_keys($missing_games)));
    }
    if ($player_unmatched) {
      ksort($player_unmatched);
      $lines = [];
      foreach ($player_unmatched as $name => $count) {
        $lines[] = "$name ($count rows)";
      }
      $this->logger()->warning("Player names that could not be uniquely matched to a Player node (stat_player_name was still saved for these):\n" . implode("\n", $lines));
    }
  }

}
