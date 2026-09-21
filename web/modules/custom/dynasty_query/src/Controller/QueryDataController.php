<?php

namespace Drupal\dynasty_query\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON endpoints for the ad-hoc Query Builder.
 *
 * This is a whitelist-driven group-by/aggregate query layer over a small
 * set of "fact tables" -- player_game_stat rows and game nodes (Phase 1),
 * plus pbp_play rows (Phase 2) -- for questions dynasty_search's flat-
 * JSON-then-filter-client-side pattern can't answer, because they need a
 * server-side GROUP BY/aggregate rather than browsing one bounded dataset
 * (e.g. "QB stats by quarter", "Brady vs opposing DC", "longest fumble
 * recoveries").
 *
 * Every dimension/measure/filter/sort key the client can request is drawn
 * from self::schemaDefinition() -- a fixed PHP array, never a raw column
 * name or SQL fragment from the request -- so arbitrary queries are
 * possible from the whitelist's combinations without the request ever
 * controlling actual SQL identifiers. Filter/measure *values* are still
 * user input and go through Drupal's parameterized Query API
 * (->condition(), placeholders), same as anywhere else in this codebase.
 *
 * Like SearchDataController::playByPlay()/::stats(), this queries base
 * tables directly with the Database API rather than loading entities --
 * essential here since an aggregate query has no per-row entity to load
 * in the first place.
 */
class QueryDataController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }

  /**
   * Per-game-field join specs, shared by every fact table that has a game
   * association (games itself via its own nid, player_game_stats via
   * stat_game, pbp_play via pbp_game -- see each fact's `game_id_expr`).
   *
   * Every game field lives in Drupal's default per-field storage (one
   * `node__field_<name>` table per field, joined on `entity_id`), rather
   * than a single base table -- there's no way around that many small
   * joins for game-level dimensions, but each is only joined when a
   * request actually asks for it (see ensureJoin() in buildQuery()).
   */
  protected static function gameFieldJoins(): array {
    return [
      'season' => ['table' => 'node__field_season', 'column' => 'field_season_value', 'resolve' => NULL],
      'week' => ['table' => 'node__field_week', 'column' => 'field_week_target_id', 'resolve' => 'taxonomy_term'],
      'opponent' => ['table' => 'node__field_opponent', 'column' => 'field_opponent_target_id', 'resolve' => 'node'],
      'home_away' => ['table' => 'node__field_home_away', 'column' => 'field_home_away_value', 'resolve' => NULL],
      'result' => ['table' => 'node__field_result', 'column' => 'field_result_value', 'resolve' => NULL],
      'opposing_coach' => ['table' => 'node__field_opposing_coach', 'column' => 'field_opposing_coach_target_id', 'resolve' => 'taxonomy_term'],
      'opp_dc' => ['table' => 'node__field_opp_dc', 'column' => 'field_opp_dc_target_id', 'resolve' => 'taxonomy_term'],
      'opp_oc' => ['table' => 'node__field_opp_oc', 'column' => 'field_opp_oc_target_id', 'resolve' => 'taxonomy_term'],
      'patriots_head_coach' => ['table' => 'node__field_patriots_head_coach', 'column' => 'field_patriots_head_coach_target_id', 'resolve' => 'taxonomy_term'],
      'patriots_score' => ['table' => 'node__field_patriots_score', 'column' => 'field_patriots_score_value', 'resolve' => NULL],
      'opponent_score' => ['table' => 'node__field_opponent_score', 'column' => 'field_opponent_score_value', 'resolve' => NULL],
    ];
  }

  /**
   * A dimension sourced from a per-game-field join (see gameFieldJoins()).
   */
  protected static function gameDimension(string $game_field, string $label): array {
    return ['source' => 'game_field', 'game_field' => $game_field, 'label' => $label];
  }

  /**
   * The Query Builder's whitelist: every fact table's dimensions and
   * measures the client is allowed to request, and how each maps to SQL.
   *
   * - A dimension is any column that can be selected, grouped by, filtered
   *   on, or sorted by. `source: own` reads directly off the fact's base
   *   table; `source: game_field` joins the shared per-field game tables
   *   (see gameFieldJoins()) via the fact's `game_id_expr`.
   * - A measure is an aggregate function over a dimension/column, only
   *   meaningful when at least one is requested (see run() for the
   *   grouped-vs-raw-row-list distinction).
   */
  protected static function schemaDefinition(): array {
    $game_dims = [
      'season' => self::gameDimension('season', 'Season'),
      'week' => self::gameDimension('week', 'Week'),
      'opponent' => self::gameDimension('opponent', 'Opponent'),
      'home_away' => self::gameDimension('home_away', 'Home/Away'),
      'result' => self::gameDimension('result', 'Result'),
      'opposing_coach' => self::gameDimension('opposing_coach', 'Opposing Head Coach'),
      'opp_dc' => self::gameDimension('opp_dc', 'Opposing DC'),
      'opp_oc' => self::gameDimension('opp_oc', 'Opposing OC'),
      'patriots_head_coach' => self::gameDimension('patriots_head_coach', 'Patriots Head Coach'),
    ];

    return [
      'player_game_stats' => [
        'label' => 'Player Game Stats',
        'base_table' => 'player_game_stat',
        'base_alias' => 'pgs',
        'base_condition' => 'pgs.status = 1',
        'game_id_expr' => 'pgs.stat_game',
        'dimensions' => $game_dims + [
          'quarter' => ['source' => 'own', 'column' => 'pgs.stat_quarter', 'label' => 'Quarter'],
          'category' => ['source' => 'own', 'column' => 'pgs.stat_category', 'label' => 'Stat Category'],
          'player' => ['source' => 'own', 'column' => 'pgs.stat_player', 'label' => 'Player', 'resolve' => 'node'],
        ],
        'measures' => [
          'games_played' => ['fn' => 'count', 'column' => 'pgs.stat_game', 'distinct' => TRUE, 'label' => 'Games'],
          'attempts' => ['fn' => 'sum', 'column' => 'pgs.stat_attempts', 'label' => 'Attempts'],
          'completions' => ['fn' => 'sum', 'column' => 'pgs.stat_completions', 'label' => 'Completions'],
          'pass_yards' => ['fn' => 'sum', 'column' => 'pgs.stat_pass_yards', 'label' => 'Pass Yards'],
          'pass_td' => ['fn' => 'sum', 'column' => 'pgs.stat_pass_td', 'label' => 'Pass TDs'],
          'interceptions' => ['fn' => 'sum', 'column' => 'pgs.stat_interceptions', 'label' => 'Interceptions'],
          'carries' => ['fn' => 'sum', 'column' => 'pgs.stat_carries', 'label' => 'Carries'],
          'rush_yards' => ['fn' => 'sum', 'column' => 'pgs.stat_rush_yards', 'label' => 'Rush Yards'],
          'rush_td' => ['fn' => 'sum', 'column' => 'pgs.stat_rush_td', 'label' => 'Rush TDs'],
          'targets' => ['fn' => 'sum', 'column' => 'pgs.stat_targets', 'label' => 'Targets'],
          'receptions' => ['fn' => 'sum', 'column' => 'pgs.stat_receptions', 'label' => 'Receptions'],
          'rec_yards' => ['fn' => 'sum', 'column' => 'pgs.stat_rec_yards', 'label' => 'Receiving Yards'],
          'rec_td' => ['fn' => 'sum', 'column' => 'pgs.stat_rec_td', 'label' => 'Receiving TDs'],
        ],
      ],
      'games' => [
        'label' => 'Games',
        'base_table' => 'node_field_data',
        'base_alias' => 'g',
        'base_condition' => "g.type = 'game' AND g.status = 1",
        'game_id_expr' => 'g.nid',
        'dimensions' => $game_dims,
        'measures' => [
          'games_played' => ['fn' => 'count', 'column' => 'g.nid', 'distinct' => TRUE, 'label' => 'Games'],
          'wins' => ['fn' => 'sum_eq', 'game_field' => 'result', 'equals' => 'Win', 'label' => 'Wins'],
          'losses' => ['fn' => 'sum_eq', 'game_field' => 'result', 'equals' => 'Loss', 'label' => 'Losses'],
          'ties' => ['fn' => 'sum_eq', 'game_field' => 'result', 'equals' => 'Tie', 'label' => 'Ties'],
          'avg_patriots_score' => ['fn' => 'avg', 'game_field' => 'patriots_score', 'label' => 'Avg Patriots Score'],
          'avg_opponent_score' => ['fn' => 'avg', 'game_field' => 'opponent_score', 'label' => 'Avg Opponent Score'],
        ],
      ],
      'plays' => [
        'label' => 'Plays',
        'base_table' => 'pbp_play',
        'base_alias' => 'pp',
        'base_condition' => 'pp.status = 1',
        'game_id_expr' => 'pp.pbp_game',
        'dimensions' => $game_dims + [
          'quarter' => ['source' => 'own', 'column' => 'pp.pbp_quarter', 'label' => 'Quarter'],
          'down' => ['source' => 'own', 'column' => 'pp.pbp_down', 'label' => 'Down'],
          'play_type' => ['source' => 'own', 'column' => 'pp.pbp_play_type', 'label' => 'Play Type'],
          'scoring_play' => ['source' => 'own', 'column' => 'pp.pbp_scoring_play', 'label' => 'Scoring Play'],
          'scoring_team' => ['source' => 'own', 'column' => 'pp.pbp_scoring_team', 'label' => 'Scoring Team'],
          'yards_gained' => ['source' => 'own', 'column' => 'pp.pbp_yards_gained', 'label' => 'Yards Gained'],
          'fumble_return_yards' => ['source' => 'own', 'column' => 'pp.pbp_fumble_return_yards', 'label' => 'Fumble Return Yards'],
          'detail' => ['source' => 'own', 'column' => 'pp.pbp_detail__value', 'label' => 'Play Detail'],
          'passer' => ['source' => 'own', 'column' => 'pp.pbp_passer', 'label' => 'Passer', 'resolve' => 'node'],
          'rusher' => ['source' => 'own', 'column' => 'pp.pbp_rusher', 'label' => 'Rusher', 'resolve' => 'node'],
          'receiver' => ['source' => 'own', 'column' => 'pp.pbp_receiver', 'label' => 'Receiver', 'resolve' => 'node'],
          'interceptor' => ['source' => 'own', 'column' => 'pp.pbp_interceptor', 'label' => 'Interceptor', 'resolve' => 'node'],
          'sacker' => ['source' => 'own', 'column' => 'pp.pbp_sacker', 'label' => 'Sacker', 'resolve' => 'node'],
          'returner' => ['source' => 'own', 'column' => 'pp.pbp_returner', 'label' => 'Returner', 'resolve' => 'node'],
          'forced_fumble_player' => ['source' => 'own', 'column' => 'pp.pbp_forced_fumble_player', 'label' => 'Forced Fumble By', 'resolve' => 'node'],
          'fumble_recovery_player' => ['source' => 'own', 'column' => 'pp.pbp_fumble_recovery_player', 'label' => 'Fumble Recovered By', 'resolve' => 'node'],
        ],
        'measures' => [
          'play_count' => ['fn' => 'count', 'column' => '*', 'label' => 'Plays'],
          'total_yards' => ['fn' => 'sum', 'column' => 'pp.pbp_yards_gained', 'label' => 'Total Yards'],
          'avg_yards' => ['fn' => 'avg', 'column' => 'pp.pbp_yards_gained', 'label' => 'Avg Yards'],
          'scoring_plays' => ['fn' => 'sum', 'column' => 'pp.pbp_scoring_play', 'label' => 'Scoring Plays'],
          'fumble_recoveries' => ['fn' => 'count', 'column' => 'pp.pbp_fumble_recovery_player', 'label' => 'Fumble Recoveries'],
          'interceptions' => ['fn' => 'count', 'column' => 'pp.pbp_interceptor', 'label' => 'Interceptions'],
          'max_yards' => ['fn' => 'max', 'column' => 'pp.pbp_yards_gained', 'label' => 'Longest Play (yards)'],
          'max_fumble_return_yards' => ['fn' => 'max', 'column' => 'pp.pbp_fumble_return_yards', 'label' => 'Longest Fumble Return (yards)'],
        ],
      ],
    ];
  }

  /**
   * GET /dynasty/query/schema -- the whitelist, so the frontend can build
   * its fact-table/dimension/measure pickers without duplicating it.
   */
  public function schema(): JsonResponse {
    $response = [];
    foreach (self::schemaDefinition() as $fact_key => $fact) {
      $response[$fact_key] = [
        'label' => $fact['label'],
        'dimensions' => $this->pluckKeyLabel($fact['dimensions']),
        'measures' => $this->pluckKeyLabel($fact['measures']),
      ];
    }
    return new JsonResponse($response);
  }

  /**
   * @return array<int, array{key: string, label: string}>
   */
  protected function pluckKeyLabel(array $defs): array {
    $out = [];
    foreach ($defs as $key => $def) {
      $out[] = ['key' => $key, 'label' => $def['label']];
    }
    return $out;
  }

  /**
   * GET /dynasty/query/options?fact=X&dimension=Y -- distinct value/label
   * pairs for any dimension, to populate a filter dropdown (labels are
   * resolved via resolveLabels() for entity-reference dimensions; every
   * other dimension's raw values -- e.g. Q1-Q4, Passing/Rushing/Receiving
   * -- are already human-readable and used as their own label). Small
   * enough to run unpaginated at this dataset's size (at most a few
   * hundred distinct players/coaches/teams/seasons).
   */
  public function options(Request $request): JsonResponse {
    $facts = self::schemaDefinition();
    $fact_key = (string) $request->query->get('fact', '');
    $dimension_key = (string) $request->query->get('dimension', '');

    if (!isset($facts[$fact_key]['dimensions'][$dimension_key])) {
      return new JsonResponse(['error' => 'Unknown fact or dimension.'], 400);
    }
    $fact = $facts[$fact_key];
    $def = $fact['dimensions'][$dimension_key];
    $resolve = $this->resolveTypeFor($def);

    $query = $this->database->select($fact['base_table'], $fact['base_alias']);
    if (!empty($fact['base_condition'])) {
      $query->where($fact['base_condition']);
    }
    $joined = [];
    $column = $this->ensureColumn($query, $fact, $dimension_key, $joined);
    $query->addExpression($column, 'value');
    $query->isNotNull($column);
    $query->groupBy($column);
    $query->range(0, 500);
    $ids = $query->execute()->fetchCol();

    $labels = $resolve ? $this->resolveLabels($resolve, $ids) : [];
    $options = [];
    foreach ($ids as $id) {
      $options[] = ['value' => $id, 'label' => $labels[$id] ?? (string) $id];
    }
    usort($options, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

    return new JsonResponse($options);
  }

  /**
   * POST /dynasty/query/run -- runs one query.
   *
   * With at least one measure requested: a GROUP BY aggregate query (one
   * row per distinct combination of the requested dimensions). With none:
   * a flat row list of the requested dimension columns (e.g. browsing
   * individual plays sorted by yardage) -- still filtered/sorted/limited,
   * just not aggregated.
   */
  public function run(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400);
    }

    $facts = self::schemaDefinition();
    $fact_key = (string) ($body['fact'] ?? '');
    if (!isset($facts[$fact_key])) {
      return new JsonResponse(['error' => 'Unknown fact table.'], 400);
    }
    $fact = $facts[$fact_key];

    $dimension_keys = array_values(array_intersect(
      array_map('strval', (array) ($body['dimensions'] ?? [])),
      array_keys($fact['dimensions'])
    ));
    $measure_keys = array_values(array_intersect(
      array_map('strval', (array) ($body['measures'] ?? [])),
      array_keys($fact['measures'])
    ));

    if (!$dimension_keys && !$measure_keys) {
      return new JsonResponse(['error' => 'Select at least one dimension or measure.'], 400);
    }

    $filters = [];
    foreach ((array) ($body['filters'] ?? []) as $filter) {
      $field = (string) ($filter['field'] ?? '');
      if (!isset($fact['dimensions'][$field])) {
        continue;
      }
      $value = $filter['value'] ?? NULL;
      if ($value === NULL || $value === '' || $value === []) {
        continue;
      }
      $filters[] = ['field' => $field, 'value' => $value];
    }

    $sortable_keys = array_merge($dimension_keys, $measure_keys);
    $sort = NULL;
    $requested_sort = $body['sort'] ?? NULL;
    if (is_array($requested_sort) && in_array($requested_sort['key'] ?? '', $sortable_keys, TRUE)) {
      $sort = [
        'key' => $requested_sort['key'],
        'direction' => strtoupper((string) ($requested_sort['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
      ];
    }

    $limit = (int) ($body['limit'] ?? 100);
    $limit = max(1, min($limit, 500));

    $query = $this->buildQuery($fact, $dimension_keys, $measure_keys, $filters, $sort, $limit);
    $result = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $rows = $this->resolveRowLabels($fact, $dimension_keys, $result);

    return new JsonResponse([
      'columns' => [
        'dimensions' => array_map(fn($k) => ['key' => $k, 'label' => $fact['dimensions'][$k]['label']], $dimension_keys),
        'measures' => array_map(fn($k) => ['key' => $k, 'label' => $fact['measures'][$k]['label']], $measure_keys),
      ],
      'rows' => $rows,
    ]);
  }

  /**
   * Builds the query for run(). See the class docblock for the whitelist
   * rationale; every identifier here comes from self::schemaDefinition()
   * or self::gameFieldJoins(), never from $filters'/$sort's caller-supplied
   * values (those only ever become bound condition/orderBy-alias values).
   */
  protected function buildQuery(array $fact, array $dimension_keys, array $measure_keys, array $filters, ?array $sort, int $limit) {
    $query = $this->database->select($fact['base_table'], $fact['base_alias']);
    if (!empty($fact['base_condition'])) {
      $query->where($fact['base_condition']);
    }

    $joined = [];

    foreach ($dimension_keys as $key) {
      $column = $this->ensureColumn($query, $fact, $key, $joined);
      $query->addExpression($column, $key);
      if ($measure_keys) {
        $query->groupBy($column);
      }
    }

    foreach ($measure_keys as $key) {
      $def = $fact['measures'][$key];
      $expr = $this->measureExpression($query, $fact, $def, $joined);
      $query->addExpression($expr, $key);
    }

    foreach ($filters as $filter) {
      $column = $this->ensureColumn($query, $fact, $filter['field'], $joined);
      $value = $filter['value'];
      if (is_array($value)) {
        $query->condition($column, array_values($value), 'IN');
      }
      else {
        $query->condition($column, $value, '=');
      }
    }

    if ($sort) {
      // $sort['key'] is one of the SELECT expression aliases added above
      // (validated against $dimension_keys/$measure_keys in run()), which
      // every supported database accepts in ORDER BY.
      $query->orderBy($sort['key'], $sort['direction']);
    }

    $query->range(0, $limit);

    return $query;
  }

  /**
   * Resolves a dimension/filter key to its SQL column expression, joining
   * the underlying per-game-field table on first use (memoized in $joined
   * for the lifetime of one buildQuery() call).
   */
  protected function ensureColumn($query, array $fact, string $key, array &$joined): string {
    $def = $fact['dimensions'][$key];
    if ($def['source'] === 'own') {
      return $def['column'];
    }

    $game_field = $def['game_field'];
    if (!isset($joined[$game_field])) {
      $spec = self::gameFieldJoins()[$game_field];
      $alias = 'gf_' . $game_field;
      $query->leftJoin($spec['table'], $alias, "$alias.entity_id = {$fact['game_id_expr']} AND $alias.bundle = 'game'");
      $joined[$game_field] = $alias;
    }
    $alias = $joined[$game_field];
    $spec = self::gameFieldJoins()[$game_field];
    return "$alias.{$spec['column']}";
  }

  /**
   * Builds one measure's SQL aggregate expression.
   */
  protected function measureExpression($query, array $fact, array $def, array &$joined): string {
    switch ($def['fn']) {
      case 'count':
        $column = $def['column'] === '*' ? '*' : $def['column'];
        return !empty($def['distinct']) ? "COUNT(DISTINCT $column)" : "COUNT($column)";

      case 'sum':
        return "SUM({$def['column']})";

      case 'avg':
        if (isset($def['game_field'])) {
          $spec = self::gameFieldJoins()[$def['game_field']];
          if (!isset($joined[$def['game_field']])) {
            $alias = 'gf_' . $def['game_field'];
            $query->leftJoin($spec['table'], $alias, "$alias.entity_id = {$fact['game_id_expr']} AND $alias.bundle = 'game'");
            $joined[$def['game_field']] = $alias;
          }
          $alias = $joined[$def['game_field']];
          return "AVG($alias.{$spec['column']})";
        }
        return "AVG({$def['column']})";

      case 'max':
        return "MAX({$def['column']})";

      case 'sum_eq':
        $spec = self::gameFieldJoins()[$def['game_field']];
        if (!isset($joined[$def['game_field']])) {
          $alias = 'gf_' . $def['game_field'];
          $query->leftJoin($spec['table'], $alias, "$alias.entity_id = {$fact['game_id_expr']} AND $alias.bundle = 'game'");
          $joined[$def['game_field']] = $alias;
        }
        $alias = $joined[$def['game_field']];
        $column = "$alias.{$spec['column']}";
        // $def['equals'] is a whitelist constant from schemaDefinition(),
        // never request input, so inline literal quoting is safe here.
        $literal = "'" . addslashes($def['equals']) . "'";
        return "SUM(CASE WHEN $column = $literal THEN 1 ELSE 0 END)";

      default:
        throw new \InvalidArgumentException('Unknown measure function: ' . $def['fn']);
    }
  }

  /**
   * The label-resolution strategy for a dimension, if any.
   */
  protected function resolveTypeFor(array $def): ?string {
    if ($def['source'] === 'own') {
      return $def['resolve'] ?? NULL;
    }
    return self::gameFieldJoins()[$def['game_field']]['resolve'] ?? NULL;
  }

  /**
   * Replaces raw dimension values (node/term IDs) in $rows with their
   * display labels, batching one lookup query per resolve-able dimension
   * across the whole (small, aggregated) result set rather than resolving
   * per row.
   */
  protected function resolveRowLabels(array $fact, array $dimension_keys, array $rows): array {
    $to_resolve = [];
    foreach ($dimension_keys as $key) {
      $resolve = $this->resolveTypeFor($fact['dimensions'][$key]);
      if ($resolve) {
        $to_resolve[$key] = $resolve;
      }
    }
    if (!$to_resolve) {
      return $rows;
    }

    $labels_by_key = [];
    foreach ($to_resolve as $key => $resolve) {
      $ids = array_unique(array_filter(array_column($rows, $key), fn($v) => $v !== NULL && $v !== ''));
      $labels_by_key[$key] = $this->resolveLabels($resolve, array_values($ids));
    }

    foreach ($rows as &$row) {
      foreach ($to_resolve as $key => $resolve) {
        if (isset($row[$key]) && $row[$key] !== NULL) {
          $row[$key] = $labels_by_key[$key][$row[$key]] ?? $row[$key];
        }
      }
    }

    return $rows;
  }

  /**
   * Batch-resolves a list of node or taxonomy term IDs to display labels.
   */
  protected function resolveLabels(string $resolve, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
      return [];
    }

    if ($resolve === 'node') {
      $result = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid', 'title'])
        ->condition('n.nid', $ids, 'IN')
        ->execute();
    }
    elseif ($resolve === 'taxonomy_term') {
      $result = $this->database->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid', 'name'])
        ->condition('t.tid', $ids, 'IN')
        ->execute();
    }
    else {
      return [];
    }

    $labels = [];
    foreach ($result as $record) {
      $id = $resolve === 'node' ? $record->nid : $record->tid;
      $label = $resolve === 'node' ? $record->title : $record->name;
      $labels[$id] = $label;
    }
    return $labels;
  }

}
