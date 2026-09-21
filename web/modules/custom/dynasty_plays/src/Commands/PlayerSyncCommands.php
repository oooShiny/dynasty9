<?php

namespace Drupal\dynasty_plays\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dynasty_plays\Service\GamePlayerMatcher;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush command for syncing Player nodes against nflverse-data's roster
 * releases (github.com/nflverse/nflverse-data, release tag "rosters").
 *
 * Why this exists: every CSV import in this module can only tag a player
 * who already has a matching Player node -- see GamePlayerMatcher. When a
 * new season's CSV is imported and a player has no node yet (or an
 * existing node's `field_seasons_on_team` doesn't cover the new season),
 * every row mentioning them silently stays unmatched (e.g. Randy Moss,
 * Mac Jones, James White all had this problem before being fixed by hand).
 * This command closes that gap by cross-referencing an authoritative
 * roster source instead of waiting for someone to notice a blank group in
 * a report.
 *
 * Source: nflverse-data's roster_<season>.csv (one file per season,
 * 1920-present, openly licensed, no auth/rate-limit needed) has the
 * Patriots' full history under two team codes -- `BOS` (Boston Patriots)
 * through 1970, `NE` from 1971 on -- with full_name/position/
 * jersey_number/birth_date/college for every season. Pro Football
 * Reference's roster pages were considered as a second source but are
 * blocked by a Cloudflare JS challenge for any non-browser request
 * (confirmed by direct testing: even a real browser User-Agent gets a
 * "Just a moment..." challenge page instead of the roster), and
 * nflverse-data's coverage already makes that unnecessary, so this
 * command only uses nflverse-data.
 *
 * This only ever *adds* season numbers to existing Player nodes or
 * creates new ones -- see ::updatePlayer()/::createPlayer() -- it never
 * touches pbp_play/player_game_stat rows directly; re-run the relevant
 * CSV importer with --wipe afterward to pick up any newly-fixed matches.
 */
class PlayerSyncCommands extends DrushCommands {

  /**
   * The season New England renamed from the Boston Patriots.
   */
  const BOS_NE_CUTOVER_SEASON = 1971;

  const ROSTER_URL_TEMPLATE = 'https://github.com/nflverse/nflverse-data/releases/download/rosters/roster_%d.csv';

  /**
   * Maps nflverse's granular position codes onto this site's coarser
   * `position` taxonomy vocabulary (only QB/RB/WR/TE/K/P/CB/LB/DL/OL/Coach
   * terms exist). A code with no confident mapping -- e.g. a safety,
   * since this vocabulary has no distinct safety term, only CB for
   * defensive backs -- is left unset rather than guessed into the wrong
   * bucket.
   *
   * @var string[]
   */
  const POSITION_MAP = [
    'QB' => 'QB',
    'RB' => 'RB',
    'FB' => 'RB',
    'HB' => 'RB',
    'WR' => 'WR',
    'TE' => 'TE',
    'T' => 'OL',
    'G' => 'OL',
    'C' => 'OL',
    'OL' => 'OL',
    'OT' => 'OL',
    'OG' => 'OL',
    'DE' => 'DL',
    'DT' => 'DL',
    'NT' => 'DL',
    'DL' => 'DL',
    'OLB' => 'LB',
    'ILB' => 'LB',
    'MLB' => 'LB',
    'LB' => 'LB',
    'CB' => 'CB',
    'K' => 'K',
    'P' => 'P',
  ];

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\dynasty_plays\Service\GamePlayerMatcher
   */
  protected $matcher;

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, GamePlayerMatcher $matcher, ClientInterface $http_client) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->matcher = $matcher;
    $this->httpClient = $http_client;
  }

  /**
   * Syncs Player nodes against nflverse-data's roster for one season or a
   * season range.
   *
   * @param array $options
   *   Command options.
   *
   * @command dynasty_plays:sync-players
   * @aliases dpsp
   * @option season A single season (e.g. 2021) or an inclusive range (e.g. 2019-2023). Required.
   * @option create-missing Also create a new Player node for any roster name with no matching node. Without this flag, missing names are only reported.
   * @option dry-run Report what would change without writing anything.
   * @usage dynasty_plays:sync-players --season=2021 --dry-run
   *   Preview season-data updates and missing players for the 2021 roster.
   * @usage dynasty_plays:sync-players --season=2019-2023 --create-missing
   *   Update season data and create missing Player nodes for 2019-2023.
   */
  public function syncPlayers(array $options = [
    'season' => NULL,
    'create-missing' => FALSE,
    'dry-run' => FALSE,
  ]) {
    if (!$options['season']) {
      $this->logger()->error('Pass --season=YYYY or --season=YYYY-YYYY.');
      return;
    }
    $range = $this->parseSeasonRange($options['season']);
    if (!$range) {
      $this->logger()->error(sprintf('Could not parse --season=%s. Use YYYY or YYYY-YYYY.', $options['season']));
      return;
    }
    [$start, $end] = $range;

    // Aggregate roster rows across the whole range first, so a player who
    // appears in multiple seasons within the range gets one update/create
    // instead of one attempt per season.
    $roster = [];
    for ($season = $start; $season <= $end; $season++) {
      $team = $season < self::BOS_NE_CUTOVER_SEASON ? 'BOS' : 'NE';
      $rows = $this->fetchRosterRows($season, $team);
      if ($rows === NULL) {
        $this->logger()->warning(sprintf('Could not fetch/parse the %d roster (team %s) -- skipping.', $season, $team));
        continue;
      }
      foreach ($rows as $row) {
        $name = trim($row['full_name'] ?? '');
        if ($name === '') {
          continue;
        }
        $roster[$name]['seasons'][$season] = TRUE;
        foreach (['position', 'jersey_number', 'college', 'birth_date'] as $field) {
          if (!empty($row[$field])) {
            $roster[$name][$field] = $row[$field];
          }
        }
      }
      $this->logger()->notice(sprintf('Fetched %d roster rows for %d (%s).', count($rows), $season, $team));
    }

    $title_map = $this->buildTitleMap();

    $updated = 0;
    $created = 0;
    $missing = [];

    foreach ($roster as $name => $info) {
      $seasons = array_keys($info['seasons']);
      sort($seasons);
      $nid = $title_map[$this->normalizeName($name)] ?? NULL;

      if ($nid) {
        if ($this->updatePlayer($nid, $name, $seasons, $options['dry-run'])) {
          $updated++;
        }
        continue;
      }

      $missing[] = ['name' => $name, 'seasons' => $seasons] + $info;
    }

    if ($missing) {
      $this->logger()->warning(sprintf('%d player(s) on the roster have no matching Player node:', count($missing)));
      foreach ($missing as $entry) {
        $this->logger()->warning(sprintf(
          '  %s -- seasons %s, position %s, #%s',
          $entry['name'],
          implode(',', $entry['seasons']),
          $entry['position'] ?? '?',
          $entry['jersey_number'] ?? '?'
        ));
      }

      if ($options['create-missing']) {
        foreach ($missing as $entry) {
          if (!$options['dry-run']) {
            $this->createPlayer($entry);
          }
          $created++;
        }
      }
    }

    $this->logger()->success(sprintf(
      '%s: %d existing player(s) updated with new season data, %d missing player(s) found%s.',
      $options['dry-run'] ? 'Dry run' : 'Sync',
      $updated,
      count($missing),
      $options['create-missing'] ? sprintf(', %d created', $created) : ' (pass --create-missing to create them)'
    ));
  }

  /**
   * Parses "YYYY" or "YYYY-YYYY" into an inclusive [start, end] pair.
   */
  protected function parseSeasonRange($value) {
    if (preg_match('/^\d{4}$/', $value)) {
      return [(int) $value, (int) $value];
    }
    if (preg_match('/^(\d{4})-(\d{4})$/', $value, $matches)) {
      return (int) $matches[1] <= (int) $matches[2] ? [(int) $matches[1], (int) $matches[2]] : NULL;
    }
    return NULL;
  }

  /**
   * Downloads and parses one season's roster CSV, filtered to $team.
   *
   * @return array[]|null
   *   A list of associative rows (keyed by CSV header), or NULL on any
   *   fetch/parse failure.
   */
  protected function fetchRosterRows($season, $team) {
    $url = sprintf(self::ROSTER_URL_TEMPLATE, $season);
    try {
      $response = $this->httpClient->request('GET', $url, ['http_errors' => TRUE]);
    }
    catch (\Exception $e) {
      return NULL;
    }
    $body = (string) $response->getBody();
    $lines = str_getcsv($body, "\n");
    if (!$lines) {
      return NULL;
    }
    $header = str_getcsv(array_shift($lines));

    $rows = [];
    foreach ($lines as $line) {
      if (trim($line) === '') {
        continue;
      }
      $data = array_combine($header, array_pad(str_getcsv($line), count($header), ''));
      if (($data['team'] ?? '') === $team) {
        $rows[] = $data;
      }
    }
    return $rows;
  }

  /**
   * Builds a map of normalized Player node title to node ID, for exact
   * full-name lookups against nflverse's already-unambiguous full names
   * (unlike the CSV shorthand GamePlayerMatcher has to disambiguate).
   */
  protected function buildTitleMap() {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'player')
      ->accessCheck(FALSE)
      ->execute();

    $map = [];
    foreach ($storage->loadMultiple($nids) as $player) {
      $map[$this->normalizeName($player->label())] = $player->id();
    }
    return $map;
  }

  /**
   * Normalizes a player name for exact-match comparison: lowercase,
   * collapsed whitespace, suffix stripped (same suffixes
   * GamePlayerMatcher::SUFFIX_PATTERN strips, so "James White Jr." and
   * "James White" compare equal).
   */
  protected function normalizeName($name) {
    $name = trim(preg_replace(GamePlayerMatcher::SUFFIX_PATTERN, '', trim($name)));
    return mb_strtolower(preg_replace('/\s+/', ' ', $name));
  }

  /**
   * Adds any of $seasons missing from an existing Player node's
   * `field_seasons_on_team`. Returns TRUE if an update was made (or would
   * be, in dry-run).
   */
  protected function updatePlayer($nid, $name, array $seasons, $dry_run) {
    $player = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$player) {
      return FALSE;
    }
    $existing = array_map('intval', array_column($player->get('field_seasons_on_team')->getValue(), 'value'));
    $to_add = array_diff($seasons, $existing);
    if (!$to_add) {
      return FALSE;
    }

    $this->logger()->notice(sprintf('%s (nid %d): add season(s) %s', $name, $nid, implode(',', $to_add)));
    if (!$dry_run) {
      $merged = array_values(array_unique(array_merge($existing, $to_add)));
      sort($merged);
      $player->set('field_seasons_on_team', $merged);
      $player->save();
    }
    return TRUE;
  }

  /**
   * Creates a new Player node from one aggregated roster entry.
   */
  protected function createPlayer(array $entry) {
    $values = [
      'type' => 'player',
      'title' => $entry['name'],
      'field_seasons_on_team' => $entry['seasons'],
    ];

    if (!empty($entry['jersey_number']) && (int) $entry['jersey_number'] > 0) {
      $values['field_jersey_number'] = (int) $entry['jersey_number'];
    }
    if (!empty($entry['birth_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry['birth_date'])) {
      $values['field_birthday'] = $entry['birth_date'];
    }
    if (!empty($entry['position']) && isset(self::POSITION_MAP[$entry['position']])) {
      $tid = $this->findOrCreateTerm('position', self::POSITION_MAP[$entry['position']], FALSE);
      if ($tid) {
        $values['field_player_position'] = $tid;
      }
    }
    if (!empty($entry['college'])) {
      $tid = $this->findOrCreateTerm('college', $entry['college'], TRUE);
      if ($tid) {
        $values['field_college'] = $tid;
      }
    }

    $player = $this->entityTypeManager->getStorage('node')->create($values);
    $player->save();
    $this->logger()->notice(sprintf('Created Player node %d: %s (seasons %s)', $player->id(), $entry['name'], implode(',', $entry['seasons'])));
    return $player->id();
  }

  /**
   * Finds an existing taxonomy term by name in $vocabulary, creating one
   * only if $auto_create is TRUE (used for `college`, which already
   * allows auto-create on its field; never for `position`, which doesn't
   * -- see POSITION_MAP's doc comment for why).
   */
  protected function findOrCreateTerm($vocabulary, $name, $auto_create) {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', $vocabulary)
      ->condition('name', $name)
      ->accessCheck(FALSE)
      ->execute();
    if ($tids) {
      return reset($tids);
    }
    if (!$auto_create) {
      return NULL;
    }
    $term = Term::create(['vid' => $vocabulary, 'name' => $name]);
    $term->save();
    return $term->id();
  }

}
