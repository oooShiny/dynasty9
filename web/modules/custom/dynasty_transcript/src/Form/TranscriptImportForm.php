<?php

namespace Drupal\dynasty_transcript\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for importing transcripts directly to Solr.
 */
class TranscriptImportForm extends FormBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Solr configuration.
   */
  protected const SOLR_URL = 'http://161.35.2.35:8983/solr/dynasty-core';
  protected const INDEX_ID = 'transcript_segments';

  /**
   * Constructs a TranscriptImportForm object.
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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dynasty_transcript_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $transcripts_dir = \Drupal::service('extension.list.module')->getPath('dynasty_transcript') . '/transcripts';
    $metadata = $this->getPodcastMetadata();

    // Count available transcripts
    $transcript_files = glob($transcripts_dir . '/*.json');
    $matched_count = 0;
    $unmatched_files = [];

    foreach ($transcript_files as $file) {
      $filename = basename($file);
      $found = FALSE;
      foreach ($metadata as $episode) {
        if (!empty($episode['transcript_url']) && $episode['transcript_url'] === $filename) {
          $matched_count++;
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $unmatched_files[] = $filename;
      }
    }

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--status">' .
        '<p><strong>Transcript Files:</strong> ' . count($transcript_files) . '</p>' .
        '<p><strong>Matched with Metadata:</strong> ' . $matched_count . '</p>' .
        '<p><strong>Unmatched Files:</strong> ' . count($unmatched_files) . '</p>' .
        '</div>',
    ];

    if (!empty($unmatched_files)) {
      $form['unmatched'] = [
        '#type' => 'details',
        '#title' => $this->t('Unmatched transcript files'),
        '#open' => FALSE,
      ];
      $form['unmatched']['list'] = [
        '#type' => 'markup',
        '#markup' => '<ul><li>' . implode('</li><li>', $unmatched_files) . '</li></ul>',
      ];
    }

    // Check current index status
    $indexed_count = $this->getIndexedCount();
    $form['index_status'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' .
        '<p><strong>Currently Indexed Segments:</strong> ' . number_format($indexed_count) . '</p>' .
        '</div>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear Index'),
      '#submit' => ['::clearIndex'],
      '#attributes' => ['class' => ['button', 'button--danger']],
    ];

    $form['actions']['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import All Transcripts'),
      '#submit' => ['::importTranscripts'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Default submit handler - not used.
  }

  /**
   * Clear the transcript index.
   */
  public function clearIndex(array &$form, FormStateInterface $form_state) {
    try {
      $response = $this->httpClient->request('POST', self::SOLR_URL . '/update', [
        'query' => ['commit' => 'true'],
        'json' => [
          'delete' => [
            'query' => 'index_id:' . self::INDEX_ID,
          ],
        ],
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

      $this->messenger()->addStatus($this->t('Index cleared successfully.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to clear index: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Import all transcripts to Solr.
   */
  public function importTranscripts(array &$form, FormStateInterface $form_state) {
    $transcripts_dir = \Drupal::service('extension.list.module')->getPath('dynasty_transcript') . '/transcripts';
    $metadata = $this->getPodcastMetadata();

    // Build a lookup by transcript_url
    $metadata_lookup = [];
    foreach ($metadata as $episode) {
      if (!empty($episode['transcript_url'])) {
        $metadata_lookup[$episode['transcript_url']] = $episode;
      }
    }

    $transcript_files = glob($transcripts_dir . '/*.json');
    $total_segments = 0;
    $imported_episodes = 0;
    $documents = [];

    foreach ($transcript_files as $file) {
      $filename = basename($file);

      if (!isset($metadata_lookup[$filename])) {
        continue;
      }

      $episode = $metadata_lookup[$filename];
      $transcript_data = json_decode(file_get_contents($file), TRUE);

      if (!is_array($transcript_data)) {
        $this->messenger()->addWarning($this->t('Invalid JSON in @file', ['@file' => $filename]));
        continue;
      }

      foreach ($transcript_data as $index => $segment) {
        $timestamp_parts = $this->parseTimestamp($segment['timestamp'] ?? '00:00-00:00');

        $doc_id = 'transcript-' . md5($filename . '-' . $index);

        $documents[] = [
          'id' => $doc_id,
          'index_id' => self::INDEX_ID,
          'ss_episode_title' => $episode['title'] ?? '',
          'ss_mp3' => $episode['mp3'] ?? '',
          'ss_game_url' => $episode['game_url'] ?? '',
          'ss_season' => $episode['season'] ?? '',
          'ss_episode_num' => $episode['episode'] ?? '',
          'ss_transcript_file' => $filename,
          'tm_X3b_und_transcript' => $segment['text'] ?? '',
          'ss_speaker' => $segment['speaker'] ?? '',
          'ss_timestamp_display' => $segment['timestamp'] ?? '',
          'its_timestamp_start' => $timestamp_parts['start'],
          'its_timestamp_end' => $timestamp_parts['end'],
          'boost_document' => 1.0,
        ];

        $total_segments++;

        // Send in batches of 500
        if (count($documents) >= 500) {
          $this->sendToSolr($documents);
          $documents = [];
        }
      }

      $imported_episodes++;
    }

    // Send remaining documents
    if (!empty($documents)) {
      $this->sendToSolr($documents);
    }

    // Commit
    try {
      $this->httpClient->request('POST', self::SOLR_URL . '/update', [
        'query' => ['commit' => 'true'],
        'json' => [],
      ]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to commit: @error', ['@error' => $e->getMessage()]));
    }

    $this->messenger()->addStatus($this->t('Imported @segments segments from @episodes episodes.', [
      '@segments' => number_format($total_segments),
      '@episodes' => $imported_episodes,
    ]));
  }

  /**
   * Send documents to Solr.
   */
  protected function sendToSolr(array $documents) {
    try {
      $this->httpClient->request('POST', self::SOLR_URL . '/update', [
        'json' => $documents,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to send batch: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Parse timestamp string like "00:22-00:37" or "1:04-1:13" into seconds.
   */
  protected function parseTimestamp(string $timestamp): array {
    $parts = explode('-', $timestamp);

    return [
      'start' => $this->timeToSeconds($parts[0] ?? '0:00'),
      'end' => $this->timeToSeconds($parts[1] ?? '0:00'),
    ];
  }

  /**
   * Convert time string to seconds.
   */
  protected function timeToSeconds(string $time): int {
    $parts = array_map('intval', explode(':', trim($time)));

    if (count($parts) === 3) {
      // H:M:S
      return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
    }
    elseif (count($parts) === 2) {
      // M:S
      return ($parts[0] * 60) + $parts[1];
    }

    return 0;
  }

  /**
   * Get podcast metadata from the site.
   */
  protected function getPodcastMetadata(): array {
    try {
      $response = $this->httpClient->request('GET', 'https://dynasty9.ddev.site/admin/dynasty/podcast-metadata', [
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return is_array($data) ? $data : [];
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to fetch metadata: @error', ['@error' => $e->getMessage()]));
      return [];
    }
  }

  /**
   * Get count of indexed segments.
   */
  protected function getIndexedCount(): int {
    try {
      $response = $this->httpClient->request('GET', self::SOLR_URL . '/select', [
        'query' => [
          'q' => '*:*',
          'fq' => 'index_id:' . self::INDEX_ID,
          'rows' => 0,
          'wt' => 'json',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['response']['numFound'] ?? 0;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

}
