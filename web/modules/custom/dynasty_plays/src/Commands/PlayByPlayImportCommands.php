<?php

namespace Drupal\dynasty_plays\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dynasty_plays\Service\GamePlayerMatcher;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing the play-by-play CSVs.
 *
 * Source: patriots_pbp_<season>.csv files (1978-present), one row per
 * play. Unlike the quarterly stats CSV, this is raw play text (plus,
 * since the enriched CSV format, a play_type and per-role player columns
 * -- see ROLE_FIELD_MAP below) rather than pre-aggregated per-player
 * numbers -- see \Drupal\dynasty_plays\Entity\PbpPlay.
 */
class PlayByPlayImportCommands extends DrushCommands {

  /**
   * Maps a source CSV role column to its pbp_play entity field.
   *
   * Populated by enrich-legacy-pbp.py (pre-2000, regex-derived from the
   * prose `detail` text) or extract-patriots-pbp.py (2000+, carried
   * straight through from nflfastR's own passer_player_name/etc.
   * columns) in the nfldata.org sibling project -- see PbpPlay's field
   * definitions for what each role means. Absent from older CSVs that
   * haven't been regenerated with these columns yet, in which case
   * $data[$csv_column] is simply empty and the field is left unset, same
   * as any other row with no confident match.
   *
   * @var string[]
   */
  const ROLE_FIELD_MAP = [
    'passer' => 'pbp_passer',
    'rusher' => 'pbp_rusher',
    'receiver' => 'pbp_receiver',
    'interceptor' => 'pbp_interceptor',
    'sacker' => 'pbp_sacker',
    'punter' => 'pbp_punter',
    'kicker' => 'pbp_kicker',
    'returner' => 'pbp_returner',
    'blocker' => 'pbp_blocker',
    'tackler_1' => 'pbp_tackler_1',
    'tackler_2' => 'pbp_tackler_2',
    'forced_fumble_player' => 'pbp_forced_fumble_player',
    'fumble_recovery_player' => 'pbp_fumble_recovery_player',
    'penalized_player' => 'pbp_penalized_player',
  ];

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
    $role_matched = 0;
    $play_types_found = 0;
    $scoring_plays_found = 0;
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
      // Tracks the last [patriots_score, opponent_score] seen for each
      // game, so a scoring play can be detected as a delta against the
      // previous row -- reliable, unlike guessing from detail text.
      $prev_score_by_game = [];

      while (($row = fgetcsv($handle)) !== FALSE) {
        if ($limit !== NULL && $file_rows >= $limit) {
          break;
        }
        $file_rows++;
        $processed++;

        $data = array_combine($header, array_pad($row, count($header), ''));

        $season = (int) ($data['season'] ?? 0);
        $date_key = $this->matcher->normalizeGameDate($data['game_date'] ?? '', $season);
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

        // Per-role player matches, from whichever role columns this CSV
        // has (see ROLE_FIELD_MAP). Each role name is resolved the same
        // way as any other CSV player column -- matchPlayer() already
        // handles both the "Initial.Surname" shorthand (2000+) and full
        // "First Last" names (pre-2000) -- so this needs no era branch.
        $role_values = [];
        foreach (self::ROLE_FIELD_MAP as $csv_column => $field_name) {
          $name = trim($data[$csv_column] ?? '');
          if ($name === '') {
            continue;
          }
          $role_nid = $this->matcher->matchPlayer($name, $player_index);
          if ($role_nid) {
            $role_values[$field_name] = $role_nid;
            $role_matched++;
          }
        }

        $play_type = trim($data['play_type'] ?? '') ?: NULL;
        $two_point_attempt = trim($data['two_point_attempt'] ?? '') === '1';
        if ($play_type) {
          $play_types_found++;
        }

        if ($options['dry-run']) {
          $created++;
          continue;
        }

        if (!isset($sequence_by_game[$nid])) {
          $sequence_by_game[$nid] = 0;
        }
        $sequence_by_game[$nid]++;

        $patriots_score = $this->intOrNull($data['patriots_score'] ?? '');
        $opponent_score = $this->intOrNull($data['opponent_score'] ?? '');

        // Scoring play/team: a play is scoring when the running score
        // strictly increased versus the previous play in this game. The
        // scoring team's name comes straight from the CSV's own `opponent`
        // column when the opponent scored, so it matches whatever label
        // that season's source data already uses.
        $scoring_play = FALSE;
        $scoring_team = NULL;
        if (isset($prev_score_by_game[$nid])) {
          [$prev_patriots_score, $prev_opponent_score] = $prev_score_by_game[$nid];
          if ($patriots_score !== NULL && $prev_patriots_score !== NULL && $patriots_score > $prev_patriots_score) {
            $scoring_play = TRUE;
            $scoring_team = 'Patriots';
          }
          elseif ($opponent_score !== NULL && $prev_opponent_score !== NULL && $opponent_score > $prev_opponent_score) {
            $scoring_play = TRUE;
            $scoring_team = trim($data['opponent'] ?? '') ?: NULL;
          }
        }
        $prev_score_by_game[$nid] = [$patriots_score, $opponent_score];

        $values = [
          'pbp_game' => $nid,
          'pbp_sequence' => $sequence_by_game[$nid],
          'pbp_quarter' => $this->mapQuarter(trim($data['quarter'] ?? '')),
          'pbp_time' => trim($data['time'] ?? '') ?: NULL,
          'pbp_down' => $this->intOrNull($data['down'] ?? ''),
          'pbp_distance' => $this->intOrNull($data['yds_to_go'] ?? ''),
          'pbp_location' => trim($data['location'] ?? '') ?: NULL,
          'pbp_patriots_score' => $patriots_score,
          'pbp_opponent_score' => $opponent_score,
          'pbp_detail' => $detail,
          'pbp_epb' => $this->floatOrNull($data['epb'] ?? ''),
          'pbp_epa' => $this->floatOrNull($data['epa'] ?? ''),
          'pbp_source_url' => trim($data['boxscore_url'] ?? '') ?: NULL,
          'pbp_scoring_play' => $scoring_play,
          'pbp_scoring_team' => $scoring_team,
          'pbp_play_type' => $play_type,
          'pbp_two_point_attempt' => $two_point_attempt,
        ];

        if ($player_nid) {
          $values['pbp_player'] = $player_nid;
        }

        $values += $role_values;

        if ($scoring_play) {
          $scoring_plays_found++;
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
      '%s %d of %d rows across %d files. Players matched: %d. Play types found: %d. Role players matched: %d. Scoring plays: %d. Games not found: %d distinct labels (%d rows).',
      $options['dry-run'] ? 'Checked' : 'Imported',
      $created,
      $processed,
      count($files),
      $player_matched,
      $play_types_found,
      $role_matched,
      $scoring_plays_found,
      count($missing_games),
      array_sum($missing_games)
    ));

    if ($missing_games) {
      $this->logger()->warning('Play rows with no matching Game node (by date): ' . implode(', ', array_keys($missing_games)));
    }
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
