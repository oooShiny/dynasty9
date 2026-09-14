<?php

namespace Drupal\dynasty_plays\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dynasty_plays\Service\GamePlayerMatcher;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing the pre-2000 play-by-play CSVs.
 *
 * Source: patriots_pbp_<season>.csv files (1978-1999), one row per play,
 * scraped from Pro Football Reference box scores. Unlike the 2000+
 * quarterly stats CSV, this is raw play text rather than pre-aggregated
 * per-player numbers -- see \Drupal\dynasty_plays\Entity\PbpPlay.
 */
class PlayByPlayImportCommands extends DrushCommands {

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
   * Constructs a new PlayByPlayImportCommands object.
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
   * Import raw play-by-play rows from the bundled patriots_pbp_*.csv files.
   *
   * @param array $options
   *   Command options.
   *
   * @command dynasty:import-play-by-play
   * @aliases dipbp
   * @option dir Directory containing patriots_pbp_<season>.csv files. Defaults to the bundled data/pbp directory.
   * @option limit Only process the first N data rows per file (for testing).
   * @option wipe Delete all existing Play-by-Play entities before importing.
   * @option dry-run Parse and match rows but don't create any entities. Prints a summary of unmatched games.
   * @usage dynasty:import-play-by-play --dry-run
   *   Preview game-matching results without writing anything.
   * @usage dynasty:import-play-by-play --wipe
   *   Clear existing play-by-play entities and re-import from scratch.
   */
  public function import(array $options = [
    'dir' => NULL,
    'limit' => NULL,
    'wipe' => FALSE,
    'dry-run' => FALSE,
  ]) {
    $dir = $options['dir'] ?: \Drupal::service('extension.list.module')->getPath('dynasty_plays') . '/data/pbp';
    $files = glob(rtrim($dir, '/') . '/patriots_pbp_*.csv');
    sort($files);
    if (!$files) {
      $this->logger()->error(sprintf('No patriots_pbp_*.csv files found in %s', $dir));
      return;
    }

    $pbp_storage = $this->entityTypeManager->getStorage('pbp_play');

    if ($options['wipe']) {
      $existing_ids = $pbp_storage->getQuery()->accessCheck(FALSE)->execute();
      if ($existing_ids) {
        foreach (array_chunk($existing_ids, 500) as $slice) {
          $pbp_storage->delete($pbp_storage->loadMultiple($slice));
        }
        $this->logger()->notice(sprintf('Deleted %d existing Play-by-Play entities.', count($existing_ids)));
      }
    }

    $game_map = $this->matcher->buildGameDateMap();
    $player_index = $this->matcher->buildPlayerIndex();

    $created = 0;
    $processed = 0;
    $missing_games = [];
    $player_matched = 0;
    $limit = $options['limit'] ? (int) $options['limit'] : NULL;

    foreach ($files as $file) {
      $handle = fopen($file, 'r');
      if (!$handle) {
        $this->logger()->warning(sprintf('Unable to open %s, skipping.', $file));
        continue;
      }

      $header = fgetcsv($handle);
      if (!$header) {
        fclose($handle);
        continue;
      }
      $header = array_map('trim', $header);

      $file_rows = 0;
      // Sequence is per-game, not per-file: reset whenever the matched
      // game changes so plays are numbered 1, 2, 3... within each game.
      $sequence_by_game = [];

      while (($row = fgetcsv($handle)) !== FALSE) {
        if ($limit !== NULL && $file_rows >= $limit) {
          break;
        }
        $file_rows++;
        $processed++;

        $data = array_combine($header, array_pad($row, count($header), ''));

        $season = (int) ($data['season'] ?? 0);
        $date_key = $this->buildDateKey($data['game_date'] ?? '', $season);
        $nid = $date_key ? ($game_map[$date_key] ?? NULL) : NULL;
        if (!$nid) {
          $label = trim(($data['game_date'] ?? '') . ' ' . $season);
          $missing_games[$label] = ($missing_games[$label] ?? 0) + 1;
          continue;
        }

        $detail = trim($data['detail'] ?? '');
        $player_nid = $this->matcher->matchPlayerInDetail($detail, $player_index);
        if ($player_nid) {
          $player_matched++;
        }

        if ($options['dry-run']) {
          $created++;
          continue;
        }

        if (!isset($sequence_by_game[$nid])) {
          $sequence_by_game[$nid] = 0;
        }
        $sequence_by_game[$nid]++;

        $values = [
          'pbp_game' => $nid,
          'pbp_sequence' => $sequence_by_game[$nid],
          'pbp_quarter' => $this->mapQuarter(trim($data['quarter'] ?? '')),
          'pbp_time' => trim($data['time'] ?? '') ?: NULL,
          'pbp_down' => $this->intOrNull($data['down'] ?? ''),
          'pbp_distance' => $this->intOrNull($data['yds_to_go'] ?? ''),
          'pbp_location' => trim($data['location'] ?? '') ?: NULL,
          'pbp_patriots_score' => $this->intOrNull($data['patriots_score'] ?? ''),
          'pbp_opponent_score' => $this->intOrNull($data['opponent_score'] ?? ''),
          'pbp_detail' => $detail,
          'pbp_epb' => $this->floatOrNull($data['epb'] ?? ''),
          'pbp_epa' => $this->floatOrNull($data['epa'] ?? ''),
          'pbp_source_url' => trim($data['boxscore_url'] ?? '') ?: NULL,
        ];

        if ($player_nid) {
          $values['pbp_player'] = $player_nid;
        }

        $entity = $pbp_storage->create($values);
        $entity->save();
        $created++;

        if ($processed % 5000 === 0) {
          $this->logger()->notice(sprintf('Imported %d rows...', $processed));
        }
      }
      fclose($handle);
    }

    $this->logger()->success(sprintf(
      '%s %d of %d rows across %d files. Players matched: %d. Games not found: %d distinct labels (%d rows).',
      $options['dry-run'] ? 'Checked' : 'Imported',
      $created,
      $processed,
      count($files),
      $player_matched,
      count($missing_games),
      array_sum($missing_games)
    ));

    if ($missing_games) {
      $this->logger()->warning('Play rows with no matching Game node (by date): ' . implode(', ', array_keys($missing_games)));
    }
  }

  /**
   * Builds a 'Y-m-d' date key from the CSV's "<Month> <Day>" game_date
   * column plus the season. Postseason games in January/February belong
   * to the following calendar year (e.g. season 1985's Super Bowl was
   * played "January 26" 1986).
   *
   * @param string $game_date
   *   E.g. "September 3" or "January 26".
   * @param int $season
   *   The season year, e.g. 1985.
   *
   * @return string|null
   *   A 'Y-m-d' string, or NULL if $game_date couldn't be parsed.
   */
  protected function buildDateKey($game_date, $season) {
    $game_date = trim($game_date);
    if ($game_date === '' || !$season) {
      return NULL;
    }
    $parts = explode(' ', $game_date, 2);
    if (count($parts) !== 2) {
      return NULL;
    }
    [$month, $day] = $parts;
    $year = in_array($month, ['January', 'February'], TRUE) ? $season + 1 : $season;

    $date = \DateTime::createFromFormat('F j Y', "$month $day $year");
    return $date ? $date->format('Y-m-d') : NULL;
  }

  /**
   * Maps the CSV's quarter value ('1'-'4', 'OT', or blank) onto the
   * Q1..OT strings used elsewhere in this module.
   */
  protected function mapQuarter($quarter) {
    if ($quarter === 'OT') {
      return 'OT';
    }
    if (in_array($quarter, ['1', '2', '3', '4'], TRUE)) {
      return 'Q' . $quarter;
    }
    return NULL;
  }

  /**
   * Casts a CSV cell to an integer, preserving NULL for blank values.
   */
  protected function intOrNull($value) {
    $value = trim((string) $value);
    return $value === '' ? NULL : (int) $value;
  }

  /**
   * Casts a CSV cell to a float, preserving NULL for blank values.
   */
  protected function floatOrNull($value) {
    $value = trim((string) $value);
    return $value === '' ? NULL : (float) $value;
  }

}
