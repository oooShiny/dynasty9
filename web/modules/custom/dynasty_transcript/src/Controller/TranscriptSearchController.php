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

    if (empty($query)) {
      return new JsonResponse([
        'response' => [
          'numFound' => 0,
          'start' => 0,
          'docs' => [],
        ],
        'highlighting' => [],
      ]);
    }

    $params = [
      'q' => self::TRANSCRIPT_FIELD . ':' . $query,
      'fq' => 'index_id:' . self::INDEX_ID,
      'start' => $start,
      'rows' => $rows,
      'wt' => 'json',
      'hl' => 'true',
      'hl.fl' => self::TRANSCRIPT_FIELD,
      'hl.simple.pre' => '<mark>',
      'hl.simple.post' => '</mark>',
      'hl.fragsize' => '200',
      'hl.snippets' => '3',
    ];

    try {
      $response = $this->httpClient->request('GET', self::SOLR_URL, [
        'query' => $params,
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

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

}
