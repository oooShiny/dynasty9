<?php

namespace Drupal\dynasty_transcript\Commands;

use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush commands for transcript management.
 */
class TranscriptCommands extends DrushCommands {

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
   * Constructs a TranscriptCommands object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ClientInterface $http_client) {
    parent::__construct();
    $this->httpClient = $http_client;
  }

  /**
   * Import transcripts to Solr.
   *
   * @command dynasty:transcript-import
   * @aliases dti
   * @usage dynasty:transcript-import
   *   Import all transcripts to Solr.
   */
  public function importTranscripts() {
    $transcripts_dir = \Drupal::service('extension.list.module')->getPath('dynasty_transcript') . '/transcripts';
    $metadata = $this->getPodcastMetadata();

    if (empty($metadata)) {
      $this->logger()->error('Failed to fetch podcast metadata.');
      return;
    }

    // Build a lookup by transcript_url
    $metadata_lookup = [];
    foreach ($metadata as $episode) {
      if (!empty($episode['transcript_url'])) {
        $metadata_lookup[$episode['transcript_url']] = $episode;
      }
    }

    $this->logger()->notice('Found ' . count($metadata_lookup) . ' episodes with transcript URLs.');

    $transcript_files = glob($transcripts_dir . '/*.json');
    $this->logger()->notice('Found ' . count($transcript_files) . ' transcript files.');

    $total_segments = 0;
    $imported_episodes = 0;
    $documents = [];

    foreach ($transcript_files as $file) {
      $filename = basename($file);

      if (!isset($metadata_lookup[$filename])) {
        $this->logger()->warning('No metadata for: ' . $filename);
        continue;
      }

      $episode = $metadata_lookup[$filename];
      $transcript_data = json_decode(file_get_contents($file), TRUE);

      if (!is_array($transcript_data)) {
        $this->logger()->error('Invalid JSON in: ' . $filename);
        continue;
      }

      $this->logger()->notice('Processing: ' . $episode['title']);

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
          $this->logger()->notice('Sent batch of 500 segments...');
          $documents = [];
        }
      }

      $imported_episodes++;
    }

    // Send remaining documents
    if (!empty($documents)) {
      $this->sendToSolr($documents);
      $this->logger()->notice('Sent final batch of ' . count($documents) . ' segments.');
    }

    // Commit
    try {
      $this->httpClient->request('POST', self::SOLR_URL . '/update', [
        'query' => ['commit' => 'true'],
        'json' => [],
      ]);
      $this->logger()->success('Committed to Solr.');
    }
    catch (\Exception $e) {
      $this->logger()->error('Failed to commit: ' . $e->getMessage());
    }

    $this->logger()->success("Imported $total_segments segments from $imported_episodes episodes.");
  }

  /**
   * Clear the transcript index.
   *
   * @command dynasty:transcript-clear
   * @aliases dtc
   * @usage dynasty:transcript-clear
   *   Clear all transcripts from Solr.
   */
  public function clearIndex() {
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

      $this->logger()->success('Index cleared successfully.');
    }
    catch (\Exception $e) {
      $this->logger()->error('Failed to clear index: ' . $e->getMessage());
    }
  }

  /**
   * Show transcript index status.
   *
   * @command dynasty:transcript-status
   * @aliases dts
   * @usage dynasty:transcript-status
   *   Show current transcript index status.
   */
  public function status() {
    $count = $this->getIndexedCount();
    $this->logger()->notice("Currently indexed segments: $count");
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
      $this->logger()->error('Failed to send batch: ' . $e->getMessage());
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
   * Get podcast metadata by executing the View directly.
   */
  protected function getPodcastMetadata(): array {
    try {
      $view = \Drupal\views\Views::getView('podcast_metadata');
      if (!$view) {
        $this->logger()->error('View "podcast_metadata" not found.');
        return [];
      }

      $view->setDisplay('rest_export_1');
      $view->execute();

      $metadata = [];
      foreach ($view->result as $row) {
        $node = $row->_entity;
        $game = $node->get('field_game')->entity;

        // Generate transcript filename using same logic as View.
        $transcript_url = '';
        if ($game) {
          $filename = str_replace(' ', '-', $game->getTitle());
          $transcript_url = strtolower(trim($filename)) . '.json';
        }
        else {
          $filename = str_replace([' ', ':'], ['-', ''], $node->getTitle());
          $transcript_url = strtolower(trim($filename)) . '.json';
        }

        $metadata[] = [
          'title' => $node->getTitle(),
          'season' => $node->get('field_season')->value ?? '',
          'episode' => $node->get('field_episode')->value ?? '',
          'mp3' => $node->get('field_mp3')->value ?? '',
          'game_title' => $game ? $game->getTitle() : '',
          'game_url' => $game ? $game->toUrl()->toString() : '',
          'transcript_url' => $transcript_url,
        ];
      }

      return $metadata;
    }
    catch (\Exception $e) {
      $this->logger()->error('Failed to fetch metadata: ' . $e->getMessage());
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
