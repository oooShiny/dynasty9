<?php

namespace Drupal\dynasty_plays\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dynasty_plays\Service\GamePlayerMatcher;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush command for creating missing `game` nodes from nflverse-data's
 * schedules release (github.com/nflverse/nflverse-data, release tag
 * "schedules", games.csv -- one row per game, 1999-present, every team,
 * openly licensed).
 *
 * Why this exists: dynasty:import-play-by-play/dynasty:import-quarterly-
 * stats can only attach a play/stat line to a Game node that already
 * exists -- a play whose date has no matching node is just reported as
 * unmatched and dropped (see those commands' "Games not found" summary).
 * This closes that gap the same way PlayerSyncCommands closes the
 * analogous Player-node gap: cross-reference an authoritative source and
 * create what's missing. Run this BEFORE re-importing pbp/quarterly
 * stats, so the newly-created games are there to match plays against.
 *
 * Only populates the core/contextual fields nflverse-data actually has --
 * date, season, week, opponent, home/away, scores, result, OT, playoff
 * round, starting QB, head coaches, temperature, surface, over/under.
 * This site's Game nodes also carry a lot of hand-curated editorial
 * content (game summary, drive charts, offensive/defensive leaders,
 * screenshots, video, coach's-record notes, Wikipedia/PFR links, draft
 * picks, Pro Bowlers, coordinators, etc.) that has no equivalent in
 * nflverse-data and is deliberately left unset here for manual
 * follow-up.
 *
 * This only ever *creates* Game nodes -- see ::createGame() -- it never
 * updates an existing one, even if nflverse-data disagrees with it on
 * some field.
 */
class GameSyncCommands extends DrushCommands {

  const GAMES_URL = 'https://github.com/nflverse/nflverse-data/releases/download/schedules/games.csv';

  const TEAM = 'NE';

  /**
   * nflverse team abbreviation -> this site's (single, current-name) Team
   * node title. Unlike PbpFetchCommands::fullTeamName() (which returns
   * the era-correct historical name for CSV text), every relocated
   * franchise has exactly one Team node under its current name -- see the
   * `team` content type -- so this map is season-independent.
   */
  const TEAM_TITLES = [
    'ARI' => 'Arizona Cardinals', 'ATL' => 'Atlanta Falcons', 'BAL' => 'Baltimore Ravens',
    'BUF' => 'Buffalo Bills', 'CAR' => 'Carolina Panthers', 'CHI' => 'Chicago Bears',
    'CIN' => 'Cincinnati Bengals', 'CLE' => 'Cleveland Browns', 'DAL' => 'Dallas Cowboys',
    'DEN' => 'Denver Broncos', 'DET' => 'Detroit Lions', 'GB' => 'Green Bay Packers',
    'HOU' => 'Houston Texans', 'IND' => 'Indianapolis Colts', 'JAX' => 'Jacksonville Jaguars',
    'KC' => 'Kansas City Chiefs', 'LA' => 'Los Angeles Rams', 'LAC' => 'Los Angeles Chargers',
    'LV' => 'Las Vegas Raiders', 'MIA' => 'Miami Dolphins', 'MIN' => 'Minnesota Vikings',
    'NO' => 'New Orleans Saints', 'NYG' => 'New York Giants', 'NYJ' => 'New York Jets',
    'PHI' => 'Philadelphia Eagles', 'PIT' => 'Pittsburgh Steelers', 'SEA' => 'Seattle Seahawks',
    'SF' => 'San Francisco 49ers', 'TB' => 'Tampa Bay Buccaneers', 'TEN' => 'Tennessee Titans',
    'WAS' => 'Washington Commanders',
  ];

  /**
   * nflverse's `week` column (a plain regular-season week number when
   * `game_type` is REG) maps directly onto this site's "Week <n>" terms;
   * postseason games instead key off `game_type` -- see this map. Every
   * one of these terms already exists in the `week` vocabulary
   * (field_week doesn't allow auto-create -- see ::buildWeekTermMap()),
   * so this is an exact-name lookup, not a fragile hardcoded ID.
   */
  const POSTSEASON_WEEK_TERMS = [
    'WC' => 'AFC Wildcard',
    'DIV' => 'AFC Divisional Round',
    'CON' => 'AFC Conference Championship',
    'SB' => 'Super Bowl',
  ];

  /**
   * nflverse's `surface` column (e.g. "grass", "fieldturf", "astroturf")
   * onto this site's coarser grass/turf field_surf values.
   */
  const SURFACE_MAP = [
    'grass' => 'grass',
    'turf' => 'turf',
    'fieldturf' => 'turf',
    'astroturf' => 'turf',
    'sportturf' => 'turf',
    'matrixturf' => 'turf',
    'a_turf' => 'turf',
  ];

  /**
   * A gap of more than this many days since the Patriots' previous game
   * that season means the new game follows a bye week.
   */
  const BYE_GAP_DAYS = 13;

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
   * Creates missing `game` nodes for one season or a season range, from
   * nflverse-data's schedule.
   *
   * @param array $options
   *   Command options.
   *
   * @command dynasty_plays:sync-games
   * @aliases dpsg
   * @option season A single season (e.g. 2025) or an inclusive range (e.g. 2023-2025). Required.
   * @option create-missing Create a new Game node for any nflverse-data game with no matching node. Without this flag, missing games are only reported.
   * @option dry-run Report what would change without writing anything.
   * @usage dynasty_plays:sync-games --season=2025 --dry-run
   *   Preview which 2025 games have no matching Game node.
   * @usage dynasty_plays:sync-games --season=2025 --create-missing
   *   Create Game nodes for any 2025 game not already on the site, then re-run the pbp/quarterly-stats importers to attach plays/stats to them.
   */
  public function syncGames(array $options = [
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

    $rows = $this->fetchGameRows();
    if ($rows === NULL) {
      $this->logger()->error("Could not fetch/parse nflverse-data's games.csv.");
      return;
    }

    $ne_rows_by_season = [];
    foreach ($rows as $row) {
      $season = (int) ($row['season'] ?? 0);
      if ($season < $start || $season > $end) {
        continue;
      }
      if (($row['home_team'] ?? '') === self::TEAM || ($row['away_team'] ?? '') === self::TEAM) {
        $ne_rows_by_season[$season][] = $row;
      }
    }

    if (!$ne_rows_by_season) {
      $this->logger()->warning('No Patriots games found in that season range.');
      return;
    }

    $game_date_map = $this->matcher->buildGameDateMap();

    // Bye-week detection needs the previous game's date within the SAME
    // season (a large gap is expected -- and not a bye -- between one
    // season's last game and the next season's opener), so each season's
    // rows are sorted and walked independently.
    $missing = [];
    foreach ($ne_rows_by_season as $season_rows) {
      usort($season_rows, fn($a, $b) => strcmp($a['gameday'], $b['gameday']));
      $previous_date = NULL;
      foreach ($season_rows as $row) {
        $date = $row['gameday'];
        $is_after_bye = $previous_date !== NULL
          && (strtotime($date) - strtotime($previous_date)) > (self::BYE_GAP_DAYS * 86400);
        $previous_date = $date;

        if (isset($game_date_map[$date])) {
          continue;
        }
        $missing[] = $row + ['_after_bye' => $is_after_bye];
      }
    }

    if (!$missing) {
      $this->logger()->success('Every Patriots game in that range already has a matching Game node.');
      return;
    }

    $this->logger()->warning(sprintf('%d game(s) have no matching Game node:', count($missing)));
    foreach ($missing as $row) {
      $opponent = $row['home_team'] === self::TEAM ? $row['away_team'] : $row['home_team'];
      $this->logger()->warning(sprintf('  %s: %s vs %s (%s)', $row['gameday'], $row['season'], $opponent, $row['game_type']));
    }

    $created = 0;
    if ($options['create-missing']) {
      $team_title_map = $this->buildTeamTitleMap();
      $week_term_map = $this->buildWeekTermMap();
      $player_index = $this->matcher->buildPlayerIndex();

      foreach ($missing as $row) {
        if (!$options['dry-run'] && $this->createGame($row, $team_title_map, $week_term_map, $player_index)) {
          $created++;
        }
      }
    }

    $this->logger()->success(sprintf(
      '%s: %d missing game(s) found%s.',
      $options['dry-run'] ? 'Dry run' : 'Sync',
      count($missing),
      $options['create-missing'] ? sprintf(', %d created', $created) : ' (pass --create-missing to create them)'
    ));
  }

  /**
   * Parses "YYYY" or "YYYY-YYYY" into an inclusive [start, end] pair --
   * same as PlayerSyncCommands::parseSeasonRange()/
   * PbpFetchCommands::parseSeasonRange().
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
   * Downloads and parses nflverse-data's games.csv -- small enough
   * (~2MB, every team, 1999-present) to buffer whole, same as
   * PlayerSyncCommands::fetchRosterRows().
   *
   * @return array[]|null
   *   A list of associative rows (keyed by CSV header), or NULL on any
   *   fetch/parse failure.
   */
  protected function fetchGameRows() {
    try {
      $response = $this->httpClient->request('GET', self::GAMES_URL, ['http_errors' => TRUE]);
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
      $rows[] = array_combine($header, array_pad(str_getcsv($line), count($header), ''));
    }
    return $rows;
  }

  /**
   * Maps nflverse team abbreviation -> Team node ID, for every
   * TEAM_TITLES entry whose node actually exists.
   */
  protected function buildTeamTitleMap(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', 'team')
      ->accessCheck(FALSE)
      ->execute();
    $by_title = [];
    foreach ($storage->loadMultiple($nids) as $team) {
      $by_title[$team->label()] = (int) $team->id();
    }
    $map = [];
    foreach (self::TEAM_TITLES as $abbr => $title) {
      if (isset($by_title[$title])) {
        $map[$abbr] = $by_title[$title];
      }
    }
    return $map;
  }

  /**
   * Maps a regular-season week number (int) or postseason `game_type`
   * (string, see POSTSEASON_WEEK_TERMS) -> week term ID, for every term
   * that actually exists in the `week` vocabulary.
   */
  protected function buildWeekTermMap(): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', 'week')
      ->accessCheck(FALSE)
      ->execute();
    $by_name = [];
    foreach ($storage->loadMultiple($tids) as $term) {
      $by_name[$term->label()] = (int) $term->id();
    }

    $map = [];
    foreach (range(1, 18) as $n) {
      if (isset($by_name["Week $n"])) {
        $map[$n] = $by_name["Week $n"];
      }
    }
    foreach (self::POSTSEASON_WEEK_TERMS as $game_type => $term_name) {
      if (isset($by_name[$term_name])) {
        $map[$game_type] = $by_name[$term_name];
      }
    }
    return $map;
  }

  /**
   * Finds an existing `head_coaches` term by exact name, creating one if
   * none exists (field_patriots_head_coach/field_opposing_coach both
   * allow auto-create on this vocabulary already).
   */
  protected function findOrCreateCoachTerm(string $name): ?int {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', 'head_coaches')
      ->condition('name', $name)
      ->accessCheck(FALSE)
      ->execute();
    if ($tids) {
      return (int) reset($tids);
    }
    $term = Term::create(['vid' => 'head_coaches', 'name' => $name]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Creates one Game node from an nflverse-data schedule row. Only sets
   * the core/contextual fields listed in this class's docblock -- see
   * there for what's deliberately left for manual follow-up.
   *
   * @return int|null
   *   The new node's ID, or NULL if the row couldn't be matched to a
   *   Team node or week term (logged as a warning; nothing is created).
   */
  protected function createGame(array $row, array $team_title_map, array $week_term_map, array $player_index): ?int {
    $is_home = $row['home_team'] === self::TEAM;
    $opp_abbr = $is_home ? $row['away_team'] : $row['home_team'];
    $opponent_nid = $team_title_map[$opp_abbr] ?? NULL;
    if (!$opponent_nid) {
      $this->logger()->warning(sprintf('%s: unknown opponent abbreviation "%s" -- skipping.', $row['gameday'], $opp_abbr));
      return NULL;
    }

    $week_key = $row['game_type'] === 'REG' ? (int) $row['week'] : $row['game_type'];
    $week_tid = $week_term_map[$week_key] ?? NULL;
    if (!$week_tid) {
      $this->logger()->warning(sprintf('%s: could not map week/game_type (%s/%s) to an existing week term -- skipping.', $row['gameday'], $row['week'], $row['game_type']));
      return NULL;
    }
    $week_term_name = $this->entityTypeManager->getStorage('taxonomy_term')->load($week_tid)->label();

    $patriots_score = (int) ($is_home ? $row['home_score'] : $row['away_score']);
    $opponent_score = (int) ($is_home ? $row['away_score'] : $row['home_score']);
    $result = $patriots_score <=> $opponent_score;
    $result = $result > 0 ? 'Win' : ($result < 0 ? 'Loss' : 'Tie');

    $season = (int) $row['season'];
    $date = new \DateTime($row['gameday']);

    $values = [
      'type' => 'game',
      'title' => "$season $week_term_name",
      'field_date' => $row['gameday'],
      'field_season' => $season,
      'field_week' => $week_tid,
      'field_month' => $date->format('F'),
      'field_weekday' => $row['weekday'] ?: $date->format('l'),
      'field_opponent' => $opponent_nid,
      'field_home_away' => $row['location'] ?: ($is_home ? 'Home' : 'Away'),
      'field_patriots_score' => $patriots_score,
      'field_opponent_score' => $opponent_score,
      'field_score_differential' => $patriots_score - $opponent_score,
      'field_result' => $result,
      'field_ot' => ($row['overtime'] ?? '') === '1',
      'field_playoff_game' => $row['game_type'] !== 'REG',
      'field_after_bye' => !empty($row['_after_bye']),
    ];

    $patriots_coach = trim($is_home ? ($row['home_coach'] ?? '') : ($row['away_coach'] ?? ''));
    if ($patriots_coach !== '') {
      $values['field_patriots_head_coach'] = $this->findOrCreateCoachTerm($patriots_coach);
    }
    $opposing_coach = trim($is_home ? ($row['away_coach'] ?? '') : ($row['home_coach'] ?? ''));
    if ($opposing_coach !== '') {
      $values['field_opposing_coach'] = $this->findOrCreateCoachTerm($opposing_coach);
    }

    $qb_name = trim($is_home ? ($row['home_qb_name'] ?? '') : ($row['away_qb_name'] ?? ''));
    if ($qb_name !== '') {
      $qb_nid = $this->matcher->matchPlayer($qb_name, $player_index, $season);
      if ($qb_nid) {
        $values['field_starting_qb'] = $qb_nid;
      }
      else {
        $this->logger()->warning(sprintf('%s: could not match starting QB "%s" to a single Player node.', $row['gameday'], $qb_name));
      }
    }

    if (is_numeric($row['total_line'] ?? NULL)) {
      $values['field_over_under'] = $row['total_line'];
    }
    if (is_numeric($row['temp'] ?? NULL)) {
      $values['field_temperature'] = (int) $row['temp'];
    }
    $surface = strtolower(trim($row['surface'] ?? ''));
    if (isset(self::SURFACE_MAP[$surface])) {
      $values['field_surf'] = self::SURFACE_MAP[$surface];
    }

    $game = $this->entityTypeManager->getStorage('node')->create($values);
    $game->save();
    $this->logger()->notice(sprintf('Created Game node %d: %s', $game->id(), $values['title']));
    return (int) $game->id();
  }

}
