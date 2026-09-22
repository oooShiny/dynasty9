<?php

namespace Drupal\dynasty_plays\Commands;

use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush command for pulling new seasons' play-by-play -- and the quarterly
 * stat totals derived from it -- straight from nflverse-data
 * (github.com/nflverse/nflverse-data, release tag "pbp"), the same source
 * PlayerSyncCommands already uses for rosters, instead of waiting on a
 * manual run of the nfldata.org sibling project's Python scripts. This
 * class's transformation logic is a faithful PHP port of that project's
 * scripts/extract-patriots-pbp.py (per-play CSV) and
 * scripts/ne_quarterly_stats.py (per-quarter stat totals, structured/2000+
 * path only) -- see those two scripts for the original and their own
 * column-mapping rationale (e.g. why only one sacker/two tacklers are
 * kept).
 *
 * Source: nflverse-data's play_by_play_<season>.csv (one file per season,
 * 1999-present, every team's every play, openly licensed) is filtered to
 * Patriots games and transformed into this site's PFR-prose-style
 * patriots_pbp_<season>.csv format -- the exact format
 * PlayByPlayImportCommands already consumes -- plus this season's rows in
 * ne_quarterly_offensive_stats.csv. This command only ever REFRESHES
 * those two bundled source CSVs; it never touches pbp_play/
 * player_game_stat entities itself. Re-run `dynasty:import-play-by-play
 * --wipe` and `dynasty:import-quarterly-stats --wipe` afterward to pick
 * up the new data (both do a full re-import, not incremental -- see
 * their own docs).
 *
 * Scoped to 2000+ only, same as extract-patriots-pbp.py: pre-2000 seasons
 * use hand-scraped Pro Football Reference prose
 * (scripts/scrape-patriots-pbp.mjs + scripts/enrich-legacy-pbp.py in the
 * nfldata.org project), not nflverse-data, and aren't handled here.
 */
class PbpFetchCommands extends DrushCommands {

  const EARLIEST_SEASON = 2000;

  const PBP_URL_TEMPLATE = 'https://github.com/nflverse/nflverse-data/releases/download/pbp/play_by_play_%d.csv';

  const TEAM = 'NE';

  /**
   * Output column order for patriots_pbp_<season>.csv -- must match
   * PlayByPlayImportCommands' expectations exactly (its import is
   * header-driven, but every column it reads has to exist).
   */
  const CSV_COLUMNS = [
    'season', 'week', 'game_date', 'game_site', 'opponent', 'boxscore_url',
    'quarter', 'time', 'down', 'yds_to_go', 'location',
    'patriots_score', 'opponent_score', 'detail', 'epb', 'epa',
  ];

  const ENRICHED_COLUMNS = [
    'play_type', 'two_point_attempt',
    'passer', 'rusher', 'receiver', 'interceptor', 'sacker',
    'punter', 'kicker', 'returner', 'blocker',
    'tackler_1', 'tackler_2',
    'forced_fumble_player', 'fumble_recovery_player',
    'penalized_player',
  ];

  /**
   * Each entry is a list of source columns tried in order (first non-empty
   * wins) from nflverse's wide per-play row -- see
   * extract-patriots-pbp.py's ROLE_COLUMN_SOURCES, which this mirrors
   * exactly, including its "only one sacker / two tacklers" tradeoff.
   */
  const ROLE_COLUMN_SOURCES = [
    'passer' => ['passer_player_name'],
    'rusher' => ['rusher_player_name'],
    'receiver' => ['receiver_player_name'],
    'interceptor' => ['interception_player_name'],
    'sacker' => ['sack_player_name', 'half_sack_1_player_name'],
    'punter' => ['punter_player_name'],
    'kicker' => ['kicker_player_name'],
    'returner' => ['punt_returner_player_name', 'kickoff_returner_player_name'],
    'blocker' => ['blocked_player_name'],
    'tackler_1' => ['solo_tackle_1_player_name', 'assist_tackle_1_player_name'],
    'tackler_2' => ['solo_tackle_2_player_name', 'assist_tackle_2_player_name'],
    'forced_fumble_player' => ['forced_fumble_player_1_player_name'],
    'fumble_recovery_player' => ['fumble_recovery_1_player_name'],
    'penalized_player' => ['penalty_player_name'],
  ];

  /**
   * pro-football-reference's per-franchise boxscore-URL code -- see
   * extract-patriots-pbp.py's PFR_CODE.
   */
  const PFR_CODE = [
    'ARI' => 'crd', 'ATL' => 'atl', 'BAL' => 'rav', 'BUF' => 'buf', 'CAR' => 'car',
    'CHI' => 'chi', 'CIN' => 'cin', 'CLE' => 'cle', 'DAL' => 'dal', 'DEN' => 'den',
    'DET' => 'det', 'GB' => 'gnb', 'HOU' => 'htx', 'IND' => 'clt', 'JAX' => 'jax',
    'KC' => 'kan', 'LA' => 'ram', 'LAC' => 'sdg', 'LV' => 'rai', 'MIA' => 'mia',
    'MIN' => 'min', 'NE' => 'nwe', 'NO' => 'nor', 'NYG' => 'nyg', 'NYJ' => 'nyj',
    'PHI' => 'phi', 'PIT' => 'pit', 'SEA' => 'sea', 'SF' => 'sfo', 'TB' => 'tam',
    'TEN' => 'oti', 'WAS' => 'was',
  ];

  /**
   * Team names stable across 2000-present -- see extract-patriots-pbp.py's
   * STABLE_NAMES; relocated franchises are handled in ::fullTeamName().
   */
  const STABLE_NAMES = [
    'ARI' => 'Arizona Cardinals', 'ATL' => 'Atlanta Falcons', 'BAL' => 'Baltimore Ravens',
    'BUF' => 'Buffalo Bills', 'CAR' => 'Carolina Panthers', 'CHI' => 'Chicago Bears',
    'CIN' => 'Cincinnati Bengals', 'CLE' => 'Cleveland Browns', 'DAL' => 'Dallas Cowboys',
    'DEN' => 'Denver Broncos', 'DET' => 'Detroit Lions', 'GB' => 'Green Bay Packers',
    'HOU' => 'Houston Texans', 'IND' => 'Indianapolis Colts', 'JAX' => 'Jacksonville Jaguars',
    'KC' => 'Kansas City Chiefs', 'MIA' => 'Miami Dolphins', 'MIN' => 'Minnesota Vikings',
    'NE' => 'New England Patriots', 'NO' => 'New Orleans Saints', 'NYG' => 'New York Giants',
    'NYJ' => 'New York Jets', 'PHI' => 'Philadelphia Eagles', 'PIT' => 'Pittsburgh Steelers',
    'SEA' => 'Seattle Seahawks', 'SF' => 'San Francisco 49ers', 'TB' => 'Tampa Bay Buccaneers',
    'TEN' => 'Tennessee Titans',
  ];

  /**
   * Playoff-round labels by week number, in pro-football-reference's own
   * casing -- see extract-patriots-pbp.py's POST_LABELS_*. The week a
   * round falls on shifts by one starting with the 2021 season, when the
   * regular season grew from 17 to 18 weeks.
   */
  const POST_LABELS_PRE_2021 = [18 => 'Wild Card', 19 => 'Division', 20 => 'Conf. Champ.', 21 => 'SuperBowl'];
  const POST_LABELS_2021_PLUS = [19 => 'Wild Card', 20 => 'Division', 21 => 'Conf. Champ.', 22 => 'SuperBowl'];

  /**
   * Synthetic administrative rows some nflverse seasons splice into `desc`
   * (pregame marker, end-of-quarter/half/game notes, two-minute warning)
   * -- see extract-patriots-pbp.py's NON_PLAY_MARKER_RE, which this
   * mirrors exactly. Timeout rows ARE real PFR rows and are kept.
   */
  const NON_PLAY_MARKER_PATTERN = '/^(GAME$|END GAME\b|END QUARTER \d|End of (Game|Half|Quarter|Regulation) -|Two-Minute Warning\b)/i';

  /**
   * Quarterly-stats output column order -- must match
   * QuarterlyStatsImportCommands' expectations exactly.
   */
  const STATS_COLUMNS = [
    'season', 'week', 'season_type', 'game_label', 'game_date',
    'ne_home_away', 'opponent', 'quarter', 'category', 'player',
    'completions', 'attempts', 'pass_yards', 'interceptions', 'pass_td',
    'carries', 'rush_yards', 'rush_td',
    'targets', 'receptions', 'rec_yards', 'rec_td',
  ];

  const POST_ROUND_NAMES = ['Wild Card', 'Divisional', 'Conference Championship', 'Super Bowl'];

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  public function __construct(ClientInterface $http_client) {
    parent::__construct();
    $this->httpClient = $http_client;
  }

  /**
   * Refreshes patriots_pbp_<season>.csv and this season's rows in
   * ne_quarterly_offensive_stats.csv from nflverse-data's play-by-play
   * release, for one season or an inclusive range.
   *
   * @param array $options
   *   Command options.
   *
   * @command dynasty_plays:fetch-play-by-play
   * @aliases dpfpbp
   * @option season A single season (e.g. 2024) or an inclusive range (e.g. 2023-2025). Must be 2000 or later. Required.
   * @option dir Directory to write patriots_pbp_<season>.csv into. Defaults to the bundled data/pbp directory.
   * @option stats-file Path to the quarterly stats CSV to update. Defaults to the bundled data file.
   * @option skip-stats Only refresh patriots_pbp_<season>.csv; leave the quarterly stats CSV untouched.
   * @usage dynasty_plays:fetch-play-by-play --season=2024
   *   Downloads nflverse-data's 2024 play-by-play, refreshing patriots_pbp_2024.csv and its rows in ne_quarterly_offensive_stats.csv.
   * @usage dynasty_plays:fetch-play-by-play --season=2023-2025
   *   Same, for three seasons at once (e.g. to finish out a partially-imported season and add the following ones).
   */
  public function fetch(array $options = [
    'season' => NULL,
    'dir' => NULL,
    'stats-file' => NULL,
    'skip-stats' => FALSE,
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
    if ($start < self::EARLIEST_SEASON) {
      $this->logger()->error(sprintf(
        "nflverse-data's play-by-play only covers %d+; seasons before that use the hand-scraped legacy pipeline (scripts/scrape-patriots-pbp.mjs + enrich-legacy-pbp.py in the nfldata.org project), not this command.",
        self::EARLIEST_SEASON
      ));
      return;
    }

    $dir = rtrim($options['dir'] ?: \Drupal::service('extension.list.module')->getPath('dynasty_plays') . '/data/pbp', '/');
    $stats_file = $options['stats-file'] ?: \Drupal::service('extension.list.module')->getPath('dynasty_plays') . '/data/ne_quarterly_offensive_stats.csv';

    $new_stats_rows_by_season = [];
    $seasons_processed = [];

    for ($season = $start; $season <= $end; $season++) {
      $this->logger()->notice(sprintf('Fetching %d play-by-play from nflverse-data (this file is ~60-100MB, may take a minute)...', $season));
      $tmp = $this->downloadSeason($season);
      if ($tmp === NULL) {
        $this->logger()->warning(sprintf('Could not fetch %d -- skipping.', $season));
        continue;
      }

      try {
        [$pbp_rows, $game_meta, $stat_accum, $post_weeks] = $this->parseSeasonFile($tmp, $season);
      }
      finally {
        @unlink($tmp);
      }

      if (!$pbp_rows) {
        $this->logger()->warning(sprintf('%d: no Patriots plays found in the downloaded file -- skipping.', $season));
        continue;
      }

      $pbp_path = "$dir/patriots_pbp_$season.csv";
      $this->writeCsv($pbp_path, array_merge(self::CSV_COLUMNS, self::ENRICHED_COLUMNS), $pbp_rows);
      $this->logger()->success(sprintf('%d: wrote %d plays -> %s', $season, count($pbp_rows), $pbp_path));

      if (!$options['skip-stats']) {
        $new_stats_rows_by_season[$season] = array_map(
          fn($row) => $this->normalizeStatsRow($row),
          $this->buildStatsRowsForSeason($game_meta, $stat_accum, $post_weeks)
        );
      }

      $seasons_processed[] = $season;
    }

    if (!$seasons_processed) {
      $this->logger()->error('No seasons were fetched.');
      return;
    }

    if (!$options['skip-stats'] && $new_stats_rows_by_season) {
      $total_stat_rows = $this->mergeStatsFile($stats_file, $new_stats_rows_by_season);
      $this->logger()->success(sprintf('Updated %d quarterly-stat row(s) for season(s) %s in %s', $total_stat_rows, implode(', ', $seasons_processed), $stats_file));
    }

    $this->logger()->success(sprintf(
      'Done. Re-run `drush dynasty:import-play-by-play --wipe`%s to pick up the refreshed data (both do a full re-import, not incremental).',
      $options['skip-stats'] ? '' : ' and `drush dynasty:import-quarterly-stats --wipe`'
    ));
  }

  /**
   * Parses "YYYY" or "YYYY-YYYY" into an inclusive [start, end] pair --
   * same as PlayerSyncCommands::parseSeasonRange().
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
   * Downloads one season's nflverse-data play-by-play CSV, streamed
   * straight to a temp file (never buffered whole in PHP memory -- this
   * file runs 60-100MB, unlike the small roster CSVs PlayerSyncCommands
   * fetches the same way).
   *
   * @return string|null
   *   The temp file path, or NULL on any fetch failure. Caller is
   *   responsible for deleting it.
   */
  protected function downloadSeason(int $season): ?string {
    $url = sprintf(self::PBP_URL_TEMPLATE, $season);
    $tmp = tempnam(sys_get_temp_dir(), 'nflverse_pbp_');
    try {
      $this->httpClient->request('GET', $url, ['sink' => $tmp, 'http_errors' => TRUE, 'timeout' => 300]);
    }
    catch (\Exception $e) {
      @unlink($tmp);
      return NULL;
    }
    return $tmp;
  }

  /**
   * Streams one downloaded season file once, producing everything both
   * outputs need in a single pass: converted Patriots-game pbp rows (for
   * patriots_pbp_<season>.csv), per-game metadata and per-quarter stat
   * accumulation (for ne_quarterly_offensive_stats.csv -- built after the
   * loop by ::buildStatsRowsForSeason(), since playoff-round labeling
   * needs every POST week seen in the whole file, not just Patriots
   * games -- see extract-patriots-pbp.py/ne_quarterly_stats.py's own
   * postseason-label comments), and the set of postseason week numbers
   * seen (from any team's rows).
   *
   * @return array{0: array[], 1: array[], 2: array, 3: array}
   *   [$pbp_rows, $game_meta, $stat_accum, $post_weeks].
   */
  protected function parseSeasonFile(string $path, int $season): array {
    $handle = fopen($path, 'r');
    $header = fgetcsv($handle);
    $header = array_map('trim', $header);
    $column_count = count($header);

    $pbp_rows = [];
    $game_meta = [];
    $stat_accum = [];
    $post_weeks = [];

    while (($row = fgetcsv($handle)) !== FALSE) {
      $data = array_combine($header, array_pad($row, $column_count, ''));

      if (($data['season_type'] ?? '') === 'POST') {
        $post_weeks[(int) $data['week']] = TRUE;
      }

      $is_ne_game = ($data['home_team'] ?? '') === self::TEAM || ($data['away_team'] ?? '') === self::TEAM;
      if (!$is_ne_game) {
        continue;
      }

      $game_id = $data['game_id'] ?? '';
      if ($game_id !== '' && !isset($game_meta[$game_id])) {
        $game_meta[$game_id] = [
          'season' => $data['season'],
          'week' => $data['week'],
          'season_type' => $data['season_type'],
          'game_date' => $data['game_date'],
          'home_team' => $data['home_team'],
          'away_team' => $data['away_team'],
        ];
      }

      if (!preg_match(self::NON_PLAY_MARKER_PATTERN, $data['desc'] ?? '')) {
        $pbp_rows[] = $this->convertRow($data, $season);
      }

      if ($game_id !== '' && ($data['posteam'] ?? '') === self::TEAM) {
        $qtr = $data['qtr'] ?? '';
        if (in_array($qtr, ['1', '2', '3', '4', '5'], TRUE)) {
          $this->accumulateStats($stat_accum, $game_id, $this->quarterLabel($qtr), $data);
        }
      }
    }
    fclose($handle);

    return [$pbp_rows, $game_meta, $stat_accum, $post_weeks];
  }

  /**
   * Converts one nflverse-data row into a patriots_pbp_<season>.csv row --
   * a straight PHP port of extract-patriots-pbp.py's convert_row().
   */
  protected function convertRow(array $row, int $season): array {
    $home = $row['home_team'];
    $away = $row['away_team'];
    $is_home = $home === self::TEAM;
    $opp_abbr = $is_home ? $away : $home;

    $game_site = $row['location'] === 'Neutral' ? 'neutral' : ($is_home ? 'home' : 'away');

    $date_compact = str_replace('-', '', $row['game_date']);
    $home_pfr_code = self::PFR_CODE[$home] ?? '';
    $boxscore_url = "https://www.pro-football-reference.com/boxscores/{$date_compact}0{$home_pfr_code}.htm";

    $down = $row['down'];
    $yds_to_go = $down !== '' ? $row['ydstogo'] : '';

    $out = [
      'season' => $season,
      'week' => $this->weekLabel($season, $row['season_type'], (int) $row['week']),
      'game_date' => $this->formatGameDate($row['game_date']),
      'game_site' => $game_site,
      'opponent' => $this->fullTeamName($opp_abbr, $season),
      'boxscore_url' => $boxscore_url,
      'quarter' => $row['qtr'],
      'time' => $row['time'],
      'down' => $down,
      'yds_to_go' => $yds_to_go,
      'location' => $row['yrdln'],
      'patriots_score' => $is_home ? $row['total_home_score'] : $row['total_away_score'],
      'opponent_score' => $is_home ? $row['total_away_score'] : $row['total_home_score'],
      'detail' => $row['desc'],
      'epb' => $this->formatNum($row['ep'] ?? ''),
      'epa' => $this->formatNum($row['epa'] ?? ''),
      'play_type' => $row['play_type'] ?? '',
      'two_point_attempt' => ($row['two_point_attempt'] ?? '') === '1' ? '1' : '',
    ];

    foreach (self::ROLE_COLUMN_SOURCES as $dest_col => $sources) {
      $value = '';
      foreach ($sources as $src) {
        if (!empty($row[$src])) {
          $value = $row[$src];
          break;
        }
      }
      $out[$dest_col] = $value;
    }

    return $out;
  }

  /**
   * Same rules as extract-patriots-pbp.py's week_label().
   */
  protected function weekLabel(int $season, string $season_type, int $week): string {
    if ($season_type === 'REG') {
      return (string) $week;
    }
    $labels = $season <= 2020 ? self::POST_LABELS_PRE_2021 : self::POST_LABELS_2021_PLUS;
    return $labels[$week] ?? "$season_type-$week";
  }

  /**
   * "2023-11-12" -> "November 12", matching extract-patriots-pbp.py's
   * format_game_date() (and the pre-2000 legacy CSVs' own date style),
   * which GamePlayerMatcher::normalizeGameDate() already parses back.
   */
  protected function formatGameDate(string $iso_date): string {
    $date = \DateTime::createFromFormat('Y-m-d', $iso_date);
    return $date ? $date->format('F j') : $iso_date;
  }

  /**
   * Same 3-decimal formatting as extract-patriots-pbp.py's format_num().
   */
  protected function formatNum($v): string {
    if ($v === NULL || $v === '' || !is_numeric($v)) {
      return '';
    }
    $f = (float) $v;
    if ($f === 0.0) {
      $f = 0.0;
    }
    return number_format($f, 3, '.', '');
  }

  /**
   * Same relocation rules as extract-patriots-pbp.py's full_team_name().
   */
  protected function fullTeamName(string $abbr, int $season): string {
    if (isset(self::STABLE_NAMES[$abbr])) {
      return self::STABLE_NAMES[$abbr];
    }
    switch ($abbr) {
      case 'LA':
        return $season < 2016 ? 'St. Louis Rams' : 'Los Angeles Rams';

      case 'LAC':
        return $season < 2017 ? 'San Diego Chargers' : 'Los Angeles Chargers';

      case 'LV':
        return $season < 2020 ? 'Oakland Raiders' : 'Las Vegas Raiders';

      case 'WAS':
        if ($season <= 2019) {
          return 'Washington Redskins';
        }
        return $season <= 2021 ? 'Washington Football Team' : 'Washington Commanders';
    }
    throw new \InvalidArgumentException("Unknown team abbreviation: $abbr");
  }

  /**
   * "5" -> "OT", else "Q<n>" -- same as ne_quarterly_stats.py's
   * quarter_label().
   */
  protected function quarterLabel(string $qtr): string {
    return $qtr === '5' ? 'OT' : "Q$qtr";
  }

  /**
   * Adds one nflverse row's pass/rush/receiving contribution into
   * $accum[$game_id][$quarter][$category][$player] -- a straight port of
   * ne_quarterly_stats.py's process_season_file() inner loop, just
   * accumulated incrementally during the single streaming pass instead of
   * over a pre-collected list of rows (same totals either way).
   */
  protected function accumulateStats(array &$accum, string $game_id, string $quarter, array $row): void {
    if (($row['pass_attempt'] ?? '') === '1') {
      $passer = trim($row['passer_player_name'] ?? '');
      if ($passer !== '') {
        $s = &$accum[$game_id][$quarter]['Passing'][$passer];
        $s['attempts'] = ($s['attempts'] ?? 0) + 1;
        $s['completions'] = ($s['completions'] ?? 0) + (int) ($row['complete_pass'] ?: 0);
        $s['pass_yards'] = ($s['pass_yards'] ?? 0) + (float) ($row['passing_yards'] ?: 0);
        $s['interceptions'] = ($s['interceptions'] ?? 0) + (int) ($row['interception'] ?: 0);
        $s['pass_td'] = ($s['pass_td'] ?? 0) + (int) ($row['pass_touchdown'] ?: 0);
        unset($s);
      }

      $receiver = trim($row['receiver_player_name'] ?? '');
      if ($receiver !== '') {
        $complete = (int) ($row['complete_pass'] ?: 0);
        $s = &$accum[$game_id][$quarter]['Receiving'][$receiver];
        $s ??= [];
        $s += ['targets' => 0, 'receptions' => 0, 'rec_yards' => 0, 'rec_td' => 0];
        $s['targets']++;
        $s['receptions'] += $complete;
        $s['rec_yards'] += (float) ($row['receiving_yards'] ?: 0);
        if ($complete) {
          $s['rec_td'] += (int) ($row['pass_touchdown'] ?: 0);
        }
        unset($s);
      }
    }

    if (($row['rush_attempt'] ?? '') === '1') {
      $rusher = trim($row['rusher_player_name'] ?? '');
      if ($rusher !== '') {
        $s = &$accum[$game_id][$quarter]['Rushing'][$rusher];
        $s['carries'] = ($s['carries'] ?? 0) + 1;
        $s['rush_yards'] = ($s['rush_yards'] ?? 0) + (float) ($row['rushing_yards'] ?: 0);
        $s['rush_td'] = ($s['rush_td'] ?? 0) + (int) ($row['rush_touchdown'] ?: 0);
        unset($s);
      }
    }
  }

  /**
   * Turns $game_meta/$stat_accum/$post_weeks (built by ::parseSeasonFile())
   * into ne_quarterly_offensive_stats.csv rows for one season -- a port of
   * ne_quarterly_stats.py's per-game output loop (build_game_label() +
   * game_sort_key() + the Q1..OT/Passing-Rushing-Receiving row order).
   */
  protected function buildStatsRowsForSeason(array $game_meta, array $stat_accum, array $post_weeks): array {
    $post_weeks_sorted = array_keys($post_weeks);
    sort($post_weeks_sorted);
    $post_week_order = array_flip($post_weeks_sorted);

    $game_ids = array_keys($game_meta);
    usort($game_ids, function ($a, $b) use ($game_meta) {
      $ga = $game_meta[$a];
      $gb = $game_meta[$b];
      $a_key = [$ga['season_type'] !== 'REG' ? 1 : 0, (int) $ga['week'], $ga['game_date']];
      $b_key = [$gb['season_type'] !== 'REG' ? 1 : 0, (int) $gb['week'], $gb['game_date']];
      return $a_key <=> $b_key;
    });

    $rows = [];
    foreach ($game_ids as $game_id) {
      $meta = $game_meta[$game_id];
      $season_type = $meta['season_type'];
      $week = (int) $meta['week'];
      $opponent = $meta['home_team'] === self::TEAM ? $meta['away_team'] : $meta['home_team'];
      $home_away = $meta['home_team'] === self::TEAM ? 'Home' : 'Away';
      $game_label = $meta['season'] . ' ' . $this->buildGameLabel($season_type, $week, $post_week_order);

      $base = [
        'season' => $meta['season'],
        'week' => $meta['week'],
        'season_type' => $season_type,
        'game_label' => $game_label,
        'game_date' => $meta['game_date'],
        'ne_home_away' => $home_away,
        'opponent' => $opponent,
      ];

      $accum = $stat_accum[$game_id] ?? [];
      foreach (['Q1', 'Q2', 'Q3', 'Q4', 'OT'] as $q) {
        if (!empty($accum[$q]['Passing'])) {
          $entries = $accum[$q]['Passing'];
          uasort($entries, fn($x, $y) => $y['pass_yards'] <=> $x['pass_yards']);
          foreach ($entries as $player => $s) {
            $rows[] = $base + [
              'quarter' => $q,
              'category' => 'Passing',
              'player' => $this->formatPlayerName($player),
              'completions' => (int) $s['completions'],
              'attempts' => $s['attempts'],
              'pass_yards' => (int) $s['pass_yards'],
              'interceptions' => (int) $s['interceptions'],
              'pass_td' => (int) $s['pass_td'],
            ];
          }
        }
        if (!empty($accum[$q]['Rushing'])) {
          $entries = $accum[$q]['Rushing'];
          uasort($entries, fn($x, $y) => $y['rush_yards'] <=> $x['rush_yards']);
          foreach ($entries as $player => $s) {
            $rows[] = $base + [
              'quarter' => $q,
              'category' => 'Rushing',
              'player' => $this->formatPlayerName($player),
              'carries' => $s['carries'],
              'rush_yards' => (int) $s['rush_yards'],
              'rush_td' => (int) $s['rush_td'],
            ];
          }
        }
        if (!empty($accum[$q]['Receiving'])) {
          $entries = $accum[$q]['Receiving'];
          uasort($entries, fn($x, $y) => $y['rec_yards'] <=> $x['rec_yards']);
          foreach ($entries as $player => $s) {
            $rows[] = $base + [
              'quarter' => $q,
              'category' => 'Receiving',
              'player' => $this->formatPlayerName($player),
              'targets' => $s['targets'],
              'receptions' => (int) $s['receptions'],
              'rec_yards' => (int) $s['rec_yards'],
              'rec_td' => (int) $s['rec_td'],
            ];
          }
        }
      }
    }

    return $rows;
  }

  /**
   * Same as ne_quarterly_stats.py's build_game_label().
   */
  protected function buildGameLabel(string $season_type, int $week, array $post_week_order): string {
    if ($season_type === 'REG') {
      return "Week $week";
    }
    $idx = $post_week_order[$week] ?? NULL;
    if ($idx !== NULL && $idx < count(self::POST_ROUND_NAMES)) {
      return self::POST_ROUND_NAMES[$idx];
    }
    return "Postseason Week $week";
  }

  /**
   * "D.Bledsoe" -> "D. Bledsoe" -- same as ne_quarterly_stats.py's
   * format_name().
   */
  protected function formatPlayerName(string $name): string {
    if ($name !== '' && preg_match('/^(\S\.)(\S.*)$/', $name, $m)) {
      return $m[1] . ' ' . $m[2];
    }
    return $name;
  }

  /**
   * Fills in every STATS_COLUMNS key (missing ones as ''), matching
   * Python's csv.DictWriter(restval="").
   */
  protected function normalizeStatsRow(array $row): array {
    $out = [];
    foreach (self::STATS_COLUMNS as $col) {
      $out[$col] = $row[$col] ?? '';
    }
    return $out;
  }

  /**
   * Writes $path as a CSV with $columns as the header row, one line per
   * $rows entry (indexed by column name; missing keys become '').
   */
  protected function writeCsv(string $path, array $columns, array $rows): void {
    $handle = fopen($path, 'w');
    fputcsv($handle, $columns, ',', '"', '\\', "\n");
    foreach ($rows as $row) {
      $line = [];
      foreach ($columns as $col) {
        $line[] = $row[$col] ?? '';
      }
      fputcsv($handle, $line, ',', '"', '\\', "\n");
    }
    fclose($handle);
  }

  /**
   * Merges freshly-fetched season(s)' stat rows into the bundled
   * ne_quarterly_offensive_stats.csv: every existing row for a season
   * being refreshed is dropped and replaced by the new rows for that
   * season; every other season's rows (including the pre-2000 legacy
   * ones, which this command never touches) are carried through as-is.
   *
   * @return int
   *   Total number of newly-written rows across all refreshed seasons.
   */
  protected function mergeStatsFile(string $stats_file, array $new_rows_by_season): int {
    $seasons_refreshed = array_map('intval', array_keys($new_rows_by_season));

    $kept_rows = [];
    if (is_file($stats_file)) {
      $handle = fopen($stats_file, 'r');
      $header = fgetcsv($handle);
      $header = array_map('trim', $header);
      $column_count = count($header);
      while (($row = fgetcsv($handle)) !== FALSE) {
        $data = array_combine($header, array_pad($row, $column_count, ''));
        if (!in_array((int) $data['season'], $seasons_refreshed, TRUE)) {
          $kept_rows[] = $this->normalizeStatsRow($data);
        }
      }
      fclose($handle);
    }

    $all_rows = $kept_rows;
    $new_row_count = 0;
    foreach ($new_rows_by_season as $rows) {
      $all_rows = array_merge($all_rows, $rows);
      $new_row_count += count($rows);
    }

    $this->writeCsv($stats_file, self::STATS_COLUMNS, $all_rows);
    return $new_row_count;
  }

}
