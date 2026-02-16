<?php

namespace Drupal\dynasty_transcript\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for proxying transcript search requests to Solr.
 */
class TranscriptSearchController extends ControllerBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Solr configuration.
   */
  protected const SOLR_URL = 'http://161.35.2.35:8983/solr/dynasty-core/select';
  protected const INDEX_ID = 'transcript_segments';
  protected const TRANSCRIPT_FIELD = 'tm_X3b_und_transcript';

  /**
   * Constructs a TranscriptSearchController object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client')
    );
  }

  /**
   * Handles search requests and proxies them to Solr.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response from Solr.
   */
  public function search(Request $request): JsonResponse {
    $query = $request->query->get('q', '');
    $start = (int) $request->query->get('start', 0);
    $rows = min((int) $request->query->get('rows', 25), 100);

    // Get filter parameters.
    $speaker = $request->query->get('speaker', '');
    $season = $request->query->get('season', '');
    $episode_type = $request->query->get('episode_type', '');

    // For initial load (no query), just return facets.
    $is_facet_only = empty($query) && $request->query->has('facets');

    if (empty($query) && !$is_facet_only) {
      return new JsonResponse([
        'response' => [
          'numFound' => 0,
          'start' => 0,
          'docs' => [],
        ],
        'highlighting' => [],
        'facet_counts' => [
          'facet_fields' => [],
        ],
      ]);
    }

    // Build filter queries.
    $fq = ['index_id:' . self::INDEX_ID];
    if (!empty($speaker)) {
      $fq[] = 'ss_speaker:"' . $this->escapeSolr($speaker) . '"';
    }
    if (!empty($season)) {
      $fq[] = 'ss_season:"' . $this->escapeSolr($season) . '"';
    }
    if ($episode_type === 'game') {
      $fq[] = 'ss_game_url:[* TO *]';
    }
    elseif ($episode_type === 'bonus') {
      $fq[] = '-ss_game_url:[* TO *]';
    }

    // Build query string manually to handle repeated parameters (facet.field, fq).
    $query_parts = [
      'q=' . urlencode($is_facet_only ? '*:*' : self::TRANSCRIPT_FIELD . ':' . $query),
      'start=' . $start,
      'rows=' . ($is_facet_only ? 0 : $rows),
      'wt=json',
      'hl=true',
      'hl.fl=' . urlencode(self::TRANSCRIPT_FIELD),
      'hl.simple.pre=' . urlencode('<mark>'),
      'hl.simple.post=' . urlencode('</mark>'),
      'hl.fragsize=200',
      'hl.snippets=3',
      // Faceting.
      'facet=true',
      'facet.field=ss_speaker',
      'facet.field=ss_season',
      'facet.mincount=1',
      'facet.limit=50',
      'facet.sort=count',
    ];

    // Add filter queries.
    foreach ($fq as $filter) {
      $query_parts[] = 'fq=' . urlencode($filter);
    }

    $url = self::SOLR_URL . '?' . implode('&', $query_parts);

    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      // Transform facet data for easier consumption.
      if (isset($data['facet_counts']['facet_fields'])) {
        $data['facets'] = $this->transformFacets($data['facet_counts']['facet_fields']);
      }

      return new JsonResponse($data);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => [
          'msg' => 'Search request failed: ' . $e->getMessage(),
        ],
      ], 500);
    }
  }

  /**
   * Transform Solr facet array format to key-value pairs.
   *
   * Solr returns facets as [value1, count1, value2, count2, ...].
   * We transform to [{value: value1, count: count1}, ...].
   */
  protected function transformFacets(array $facet_fields): array {
    $result = [];
    foreach ($facet_fields as $field => $values) {
      $facets = [];
      for ($i = 0; $i < count($values); $i += 2) {
        if (isset($values[$i]) && isset($values[$i + 1]) && $values[$i + 1] > 0) {
          $facets[] = [
            'value' => $values[$i],
            'count' => $values[$i + 1],
          ];
        }
      }
      $result[$field] = $facets;
    }
    return $result;
  }

  /**
   * Escape special characters for Solr query.
   */
  protected function escapeSolr(string $value): string {
    $special = ['+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '\\', '/'];
    return str_replace($special, array_map(fn($c) => '\\' . $c, $special), $value);
  }

}
