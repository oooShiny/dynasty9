<?php

namespace Drupal\dynasty_transcript\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for displaying transcript import status.
 */
class TranscriptStatusController extends ControllerBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a TranscriptStatusController object.
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
   * Display transcript import status.
   */
  public function status() {
    $transcripts_dir = \Drupal::service('extension.list.module')->getPath('dynasty_transcript') . '/transcripts';
    $metadata = $this->getPodcastMetadata();

    // Build lookup of metadata by transcript_url
    $metadata_lookup = [];
    foreach ($metadata as $episode) {
      if (!empty($episode['transcript_url'])) {
        $metadata_lookup[$episode['transcript_url']] = $episode;
      }
    }

    // Get all transcript files
    $transcript_files = glob($transcripts_dir . '/*.json');

    $matched = [];
    $unmatched_files = [];

    foreach ($transcript_files as $file) {
      $filename = basename($file);
      if ($filename === '.DS_Store') {
        continue;
      }

      if (isset($metadata_lookup[$filename])) {
        $matched[] = [
          'filename' => $filename,
          'title' => $metadata_lookup[$filename]['title'],
          'mp3' => $metadata_lookup[$filename]['mp3'],
        ];
      }
      else {
        $unmatched_files[] = $filename;
      }
    }

    // Episodes with transcript_url but no file
    $missing_files = [];
    foreach ($metadata as $episode) {
      if (!empty($episode['transcript_url'])) {
        $filepath = $transcripts_dir . '/' . $episode['transcript_url'];
        if (!file_exists($filepath)) {
          $missing_files[] = [
            'expected_file' => $episode['transcript_url'],
            'title' => $episode['title'],
          ];
        }
      }
    }

    // Episodes without any transcript_url
    $no_transcript_url = [];
    foreach ($metadata as $episode) {
      if (empty($episode['transcript_url'])) {
        $no_transcript_url[] = [
          'title' => $episode['title'],
          'mp3' => $episode['mp3'] ?? '',
        ];
      }
    }

    // Build render array
    $build = [];

    $build['actions'] = [
      '#type' => 'markup',
      '#markup' => '<p>' .
        '<a href="/admin/dynasty/transcript-mapping" class="button button--primary">Map Unmatched Files</a> ' .
        '<a href="/admin/dynasty/transcript-import" class="button">Import to Solr</a>' .
        '</p>',
    ];

    $build['summary'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--status">' .
        '<p><strong>Total Transcript Files:</strong> ' . count($transcript_files) . '</p>' .
        '<p><strong>Matched (will import):</strong> ' . count($matched) . '</p>' .
        '<p><strong>Unmatched Files (no metadata):</strong> ' . count($unmatched_files) . '</p>' .
        '<p><strong>Missing Files (metadata but no file):</strong> ' . count($missing_files) . '</p>' .
        '<p><strong>Episodes Without Transcript URL:</strong> ' . count($no_transcript_url) . '</p>' .
        '</div>',
    ];

    // Unmatched files table
    if (!empty($unmatched_files)) {
      $build['unmatched_header'] = [
        '#type' => 'markup',
        '#markup' => '<h2>Transcript Files Without Metadata Match</h2>' .
          '<p>These files exist but have no matching <code>transcript_url</code> in the podcast metadata.</p>',
      ];

      $rows = [];
      foreach ($unmatched_files as $filename) {
        $rows[] = [$filename];
      }

      $build['unmatched_table'] = [
        '#type' => 'table',
        '#header' => ['Filename'],
        '#rows' => $rows,
        '#empty' => 'All files have metadata matches.',
      ];
    }

    // Missing files table
    if (!empty($missing_files)) {
      $build['missing_header'] = [
        '#type' => 'markup',
        '#markup' => '<h2>Metadata Entries Missing Files</h2>' .
          '<p>These episodes have a <code>transcript_url</code> but the file doesn\'t exist.</p>',
      ];

      $rows = [];
      foreach ($missing_files as $item) {
        $rows[] = [$item['expected_file'], $item['title']];
      }

      $build['missing_table'] = [
        '#type' => 'table',
        '#header' => ['Expected Filename', 'Episode Title'],
        '#rows' => $rows,
        '#empty' => 'All metadata entries have matching files.',
      ];
    }

    // Episodes without transcript URL
    if (!empty($no_transcript_url)) {
      $build['no_url_header'] = [
        '#type' => 'markup',
        '#markup' => '<h2>Episodes Without Transcript URL</h2>' .
          '<p>These episodes don\'t have a <code>transcript_url</code> set in the metadata.</p>',
      ];

      $rows = [];
      foreach ($no_transcript_url as $item) {
        $rows[] = [$item['title']];
      }

      $build['no_url_table'] = [
        '#type' => 'table',
        '#header' => ['Episode Title'],
        '#rows' => $rows,
        '#empty' => 'All episodes have transcript URLs.',
      ];
    }

    // Matched files (collapsible)
    if (!empty($matched)) {
      $build['matched'] = [
        '#type' => 'details',
        '#title' => $this->t('Successfully Matched (@count)', ['@count' => count($matched)]),
        '#open' => FALSE,
      ];

      $rows = [];
      foreach ($matched as $item) {
        $rows[] = [$item['filename'], $item['title']];
      }

      $build['matched']['table'] = [
        '#type' => 'table',
        '#header' => ['Filename', 'Episode Title'],
        '#rows' => $rows,
      ];
    }

    return $build;
  }

  /**
   * Get podcast metadata from the site.
   */
  protected function getPodcastMetadata(): array {
    try {
      $base_url = \Drupal::request()->getSchemeAndHttpHost();
      $response = $this->httpClient->request('GET', $base_url . '/admin/dynasty/podcast-metadata', [
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return is_array($data) ? $data : [];
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError('Failed to fetch metadata: ' . $e->getMessage());
      return [];
    }
  }

}
