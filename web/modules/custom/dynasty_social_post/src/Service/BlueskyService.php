<?php

namespace Drupal\dynasty_social_post\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for posting videos to Bluesky.
 */
class BlueskyService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a BlueskyService object.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('dynasty_social_post');
    $this->state = $state;
  }

  /**
   * Authenticates with Bluesky and returns access token.
   *
   * @return array|null
   *   Array with 'accessJwt', 'did', and 'didDoc' keys, or NULL on failure.
   */
  protected function authenticate() {
    $config = $this->configFactory->get('dynasty_social_post.settings');
    $identifier = $config->get('bluesky_identifier');
    $password = $config->get('bluesky_password');

    if (empty($identifier) || empty($password)) {
      $this->logger->error('Bluesky credentials not configured.');
      return NULL;
    }

    try {
      $response = $this->httpClient->post('https://bsky.social/xrpc/com.atproto.server.createSession', [
        'json' => [
          'identifier' => $identifier,
          'password' => $password,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['accessJwt']) && isset($data['did'])) {
        return [
          'accessJwt' => $data['accessJwt'],
          'did' => $data['did'],
          'didDoc' => $data['didDoc'] ?? NULL,
        ];
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Bluesky authentication failed: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Creates a service authentication token for video uploads.
   *
   * @param string $access_token
   *   The access JWT from authentication.
   * @param array $did_doc
   *   The DID document from authentication.
   *
   * @return string|null
   *   The service authentication token, or NULL on failure.
   */
  protected function createServiceAuth($access_token, $did_doc) {
    try {
      // Extract PDS service endpoint from DID document.
      $pds_endpoint = NULL;
      if (isset($did_doc['service'])) {
        foreach ($did_doc['service'] as $service) {
          if ($service['type'] === 'AtprotoPersonalDataServer') {
            $pds_endpoint = $service['serviceEndpoint'];
            break;
          }
        }
      }

      if (!$pds_endpoint) {
        $this->logger->error('Could not find PDS endpoint in DID document.');
        return NULL;
      }

      // Convert PDS endpoint URL to DID format.
      // e.g., https://bsky.social -> did:web:bsky.social
      $pds_host = parse_url($pds_endpoint, PHP_URL_HOST);
      $pds_did = "did:web:$pds_host";

      $response = $this->httpClient->get('https://bsky.social/xrpc/com.atproto.server.getServiceAuth', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
        'query' => [
          'aud' => $pds_did,
          'lxm' => 'com.atproto.repo.uploadBlob',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['token'] ?? NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to create service auth token: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Uploads a video to Bluesky video service.
   *
   * @param string $video_path
   *   The local file path to the video.
   * @param string $service_token
   *   The service authentication token.
   * @param string $did
   *   The DID (decentralized identifier).
   *
   * @return string|null
   *   The job ID for the video upload, or NULL on failure.
   */
  protected function uploadVideo($video_path, $service_token, $did) {
    if (!file_exists($video_path)) {
      $this->logger->error('Video file not found: @path', ['@path' => $video_path]);
      return NULL;
    }

    try {
      $video_content = file_get_contents($video_path);
      $response = $this->httpClient->post('https://video.bsky.app/xrpc/app.bsky.video.uploadVideo', [
        'headers' => [
          'Authorization' => 'Bearer ' . $service_token,
          'Content-Type' => 'video/mp4',
        ],
        'body' => $video_content,
        'query' => [
          'did' => $did,
          'name' => basename($video_path),
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['jobId'] ?? NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Video upload failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Checks the status of a video processing job.
   *
   * @param string $job_id
   *   The job ID from the video upload.
   * @param string $service_token
   *   The service authentication token.
   *
   * @return array|null
   *   Job status data, or NULL on failure.
   */
  protected function getJobStatus($job_id, $service_token) {
    try {
      $response = $this->httpClient->get('https://video.bsky.app/xrpc/app.bsky.video.getJobStatus', [
        'headers' => [
          'Authorization' => 'Bearer ' . $service_token,
        ],
        'query' => [
          'jobId' => $job_id,
        ],
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to get job status: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Waits for video processing to complete.
   *
   * @param string $job_id
   *   The job ID from the video upload.
   * @param string $service_token
   *   The service authentication token.
   * @param int $max_attempts
   *   Maximum number of status check attempts.
   *
   * @return array|null
   *   The blob reference for the processed video, or NULL on failure.
   */
  protected function waitForProcessing($job_id, $service_token, $max_attempts = 60) {
    $attempts = 0;

    while ($attempts < $max_attempts) {
      $status = $this->getJobStatus($job_id, $service_token);

      if (!$status) {
        return NULL;
      }

      $state = $status['jobStatus']['state'] ?? '';

      if ($state === 'JOB_STATE_COMPLETED') {
        // Video is ready, return the blob reference.
        return $status['jobStatus']['blob'] ?? NULL;
      }
      elseif ($state === 'JOB_STATE_FAILED') {
        $this->logger->error('Video processing failed for job: @job_id', ['@job_id' => $job_id]);
        return NULL;
      }

      // Wait 5 seconds before checking again.
      sleep(5);
      $attempts++;
    }

    $this->logger->error('Video processing timed out for job: @job_id', ['@job_id' => $job_id]);
    return NULL;
  }

  /**
   * Creates facets for hashtags in the post text.
   *
   * @param string $text
   *   The post text.
   *
   * @return array
   *   Array of facets for hashtags.
   */
  protected function createHashtagFacets($text) {
    $facets = [];
    $hashtags = ['#Patriots', '#Dynasty'];

    foreach ($hashtags as $hashtag) {
      $position = mb_strpos($text, $hashtag);
      if ($position !== FALSE) {
        $facets[] = [
          'index' => [
            'byteStart' => strlen(mb_substr($text, 0, $position)),
            'byteEnd' => strlen(mb_substr($text, 0, $position + mb_strlen($hashtag))),
          ],
          'features' => [
            [
              '$type' => 'app.bsky.richtext.facet#tag',
              'tag' => ltrim($hashtag, '#'),
            ],
          ],
        ];
      }
    }

    return $facets;
  }

  /**
   * Creates a post on Bluesky with a video.
   *
   * @param string $text
   *   The post text.
   * @param array $video_blob
   *   The video blob reference.
   * @param string $access_token
   *   The access JWT.
   * @param string $did
   *   The DID (decentralized identifier).
   * @param array $video_dimensions
   *   Array with 'width' and 'height' keys.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected function createPost($text, $video_blob, $access_token, $did, $video_dimensions = ['width' => 1920, 'height' => 1080]) {
    try {
      // Create facets for hashtags.
      $facets = $this->createHashtagFacets($text);

      $post_data = [
        '$type' => 'app.bsky.feed.post',
        'text' => $text,
        'createdAt' => date('c'),
        'embed' => [
          '$type' => 'app.bsky.embed.video',
          'video' => $video_blob,
          'aspectRatio' => [
            'width' => $video_dimensions['width'],
            'height' => $video_dimensions['height'],
          ],
        ],
      ];

      // Add facets if we found any hashtags.
      if (!empty($facets)) {
        $post_data['facets'] = $facets;
      }

      $response = $this->httpClient->post('https://bsky.social/xrpc/com.atproto.repo.createRecord', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'repo' => $did,
          'collection' => 'app.bsky.feed.post',
          'record' => $post_data,
        ],
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);
      return isset($result['uri']);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to create post: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Gets a random highlight that hasn't been posted yet.
   *
   * @return \Drupal\node\NodeInterface|null
   *   A highlight node, or NULL if none available.
   */
  protected function getRandomHighlight() {
    $storage = $this->entityTypeManager->getStorage('node');

    // Query for highlight nodes that haven't been posted to Bluesky.
    $query = $storage->getQuery()
      ->condition('type', 'highlight')
      ->condition('status', 1)
      ->exists('field_muse_video_id')
      ->accessCheck(FALSE);

    // Exclude highlights that have been posted.
    $posted_highlights = $this->state->get('dynasty_social_post.posted_highlights', []);
    if (!empty($posted_highlights)) {
      $query->condition('nid', $posted_highlights, 'NOT IN');
    }

    $nids = $query->execute();

    if (empty($nids)) {
      // If all highlights have been posted, reset the list.
      $this->state->set('dynasty_social_post.posted_highlights', []);

      // Try again without exclusions.
      $query = $storage->getQuery()
        ->condition('type', 'highlight')
        ->condition('status', 1)
        ->exists('field_muse_video_id')
        ->accessCheck(FALSE);

      $nids = $query->execute();

      if (empty($nids)) {
        return NULL;
      }
    }

    // Get a random highlight.
    $random_nid = array_rand(array_flip($nids));
    return $storage->load($random_nid);
  }

  /**
   * Builds the post text with game context information.
   *
   * @param \Drupal\node\NodeInterface $highlight
   *   The highlight node.
   *
   * @return string|null
   *   The formatted post text, or NULL on failure.
   */
  protected function buildPostText($highlight) {
    $parts = [];

    // Get season.
    $season = $highlight->get('field_season')->value;
    if ($season) {
      $parts[] = $season;
    }

    // Get week.
    $week_entity = $highlight->get('field_week')->entity;
    if ($week_entity) {
      $parts[] = $week_entity->getName();
    }

    // Get home/away and opponent.
    $game = $highlight->get('field_game')->entity;
    $opponent = $highlight->get('field_opponent')->entity;

    if ($game && $opponent) {
      $home_away = $game->get('field_home_away')->value;
      $location_prefix = 'vs';

      if ($home_away === 'Away') {
        $location_prefix = '@';
      }
      elseif ($home_away === 'Neutral') {
        $location_prefix = 'vs';
      }

      $opponent_name = $opponent->getTitle();
      $parts[] = "{$location_prefix} {$opponent_name}";
    }

    // Get the play description.
    $description = $highlight->get('field_play_description')->value;
    if (empty($description)) {
      $description = $highlight->getTitle();
    }

    // Build the full post text.
    $post_parts = [];
    $post_parts[] = "A Random Dynasty Highlight:";
    $post_parts[] = "";

    // Add game context if available.
    $context = !empty($parts) ? implode(' ', $parts) : '';
    if ($context) {
      $post_parts[] = $context;
      $post_parts[] = "";
    }

    // Add description.
    $post_parts[] = $description;
    $post_parts[] = "";

    // Add hashtags.
    $post_parts[] = "#Patriots #Dynasty";

    return implode("\n", $post_parts);
  }

  /**
   * Fetches video metadata from muse.ai API.
   *
   * @param string $muse_video_id
   *   The muse.ai video ID.
   *
   * @return array|null
   *   Video metadata including download URL, or NULL on failure.
   */
  protected function getMuseVideoInfo($muse_video_id) {
    try {
      $response = $this->httpClient->get("https://muse.ai/api/files/info/{$muse_video_id}");
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['url']) && isset($data['width']) && isset($data['height'])) {
        return $data;
      }

      $this->logger->error('Invalid response from muse.ai API for video: @id', ['@id' => $muse_video_id]);
      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch muse.ai video info: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Downloads a video from muse.ai to a temporary file.
   *
   * @param string $video_url
   *   The video download URL from muse.ai.
   * @param string $filename
   *   The filename to use for the temporary file.
   * @param int $max_size
   *   Maximum file size in bytes (default 100MB for Bluesky).
   *
   * @return string|null
   *   Path to the temporary file, or NULL on failure.
   */
  protected function downloadVideoToTemp($video_url, $filename, $max_size = 100000000) {
    try {
      $temp_dir = $this->fileSystem->getTempDirectory();
      $temp_file = $temp_dir . '/' . $filename;

      $this->logger->info('Downloading video from muse.ai to: @path', ['@path' => $temp_file]);

      $response = $this->httpClient->get($video_url, [
        'sink' => $temp_file,
        'timeout' => 300,
      ]);

      if (file_exists($temp_file) && filesize($temp_file) > 0) {
        $file_size = filesize($temp_file);

        // Check if file exceeds Bluesky's size limit.
        if ($file_size > $max_size) {
          $this->logger->warning('Video file too large (@size bytes, max @max bytes): @path', [
            '@size' => $file_size,
            '@max' => $max_size,
            '@path' => $temp_file,
          ]);
          unlink($temp_file);
          return NULL;
        }

        return $temp_file;
      }

      $this->logger->error('Failed to download video or file is empty: @path', ['@path' => $temp_file]);
      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Error downloading video: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Posts a random highlight to Bluesky.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function postRandomHighlight() {
    $temp_file = NULL;
    $max_attempts = 5;
    $attempts = 0;

    // Try up to 5 times to find a suitable video.
    while ($attempts < $max_attempts) {
      $attempts++;

      try {
        // Get a random highlight.
        $highlight = $this->getRandomHighlight();
        if (!$highlight) {
          $this->logger->warning('No highlights available to post.');
          return FALSE;
        }

        // Get the muse.ai video ID.
        $muse_video_id = $highlight->get('field_muse_video_id')->value;
        if (empty($muse_video_id)) {
          $this->logger->error('Highlight @nid has no muse.ai video ID.', ['@nid' => $highlight->id()]);

          // Mark as posted so we don't try it again.
          $posted_highlights = $this->state->get('dynasty_social_post.posted_highlights', []);
          $posted_highlights[] = $highlight->id();
          $this->state->set('dynasty_social_post.posted_highlights', $posted_highlights);

          continue;
        }

        // Fetch video metadata from muse.ai.
        $video_info = $this->getMuseVideoInfo($muse_video_id);
        if (!$video_info) {
          // Mark as posted so we don't try it again.
          $posted_highlights = $this->state->get('dynasty_social_post.posted_highlights', []);
          $posted_highlights[] = $highlight->id();
          $this->state->set('dynasty_social_post.posted_highlights', $posted_highlights);

          continue;
        }

        $this->logger->info('Fetched muse.ai video info for @id: @info', [
          '@id' => $muse_video_id,
          '@info' => json_encode($video_info),
        ]);

        // Check file size before downloading.
        $file_size = $video_info['size'] ?? 0;
        if ($file_size > 100000000) {
          $this->logger->warning('Skipping highlight @nid - video too large (@size bytes)', [
            '@nid' => $highlight->id(),
            '@size' => $file_size,
          ]);

          // Mark as posted so we don't try it again.
          $posted_highlights = $this->state->get('dynasty_social_post.posted_highlights', []);
          $posted_highlights[] = $highlight->id();
          $this->state->set('dynasty_social_post.posted_highlights', $posted_highlights);

          continue;
        }

        // Download the video to a temporary file.
        $filename = $video_info['filename'] ?? "video_{$muse_video_id}.mp4";
        $temp_file = $this->downloadVideoToTemp($video_info['url'], $filename);
        if (!$temp_file) {
          // Mark as posted so we don't try it again.
          $posted_highlights = $this->state->get('dynasty_social_post.posted_highlights', []);
          $posted_highlights[] = $highlight->id();
          $this->state->set('dynasty_social_post.posted_highlights', $posted_highlights);

          continue;
        }

        // Build the post text with game context.
        $post_text = $this->buildPostText($highlight);
        if (!$post_text) {
          $this->logger->warning('Could not build post text for highlight @nid', ['@nid' => $highlight->id()]);
          continue;
        }

        // Authenticate with Bluesky.
        $auth = $this->authenticate();
        if (!$auth) {
          return FALSE;
        }

        // Create service auth token for video upload.
        $service_token = $this->createServiceAuth($auth['accessJwt'], $auth['didDoc']);
        if (!$service_token) {
          return FALSE;
        }

        // Upload the video to Bluesky.
        $job_id = $this->uploadVideo($temp_file, $service_token, $auth['did']);
        if (!$job_id) {
          return FALSE;
        }

        $this->logger->info('Video uploaded to Bluesky, job ID: @job_id', ['@job_id' => $job_id]);

        // Wait for video processing.
        $video_dimensions = [
          'width' => $video_info['width'],
          'height' => $video_info['height'],
        ];
        $video_blob = $this->waitForProcessing($job_id, $service_token);
        if (!$video_blob) {
          return FALSE;
        }

        // Create the post.
        $success = $this->createPost($post_text, $video_blob, $auth['accessJwt'], $auth['did'], $video_dimensions);

        if ($success) {
          // Mark this highlight as posted.
          $posted_highlights = $this->state->get('dynasty_social_post.posted_highlights', []);
          $posted_highlights[] = $highlight->id();
          $this->state->set('dynasty_social_post.posted_highlights', $posted_highlights);

          $this->logger->info('Successfully posted highlight @nid to Bluesky.', ['@nid' => $highlight->id()]);
          return TRUE;
        }

        return FALSE;
      }
      finally {
        // Clean up temporary file.
        if ($temp_file && file_exists($temp_file)) {
          unlink($temp_file);
          $this->logger->info('Cleaned up temporary file: @path', ['@path' => $temp_file]);
        }
      }
    }

    // If we've tried max_attempts and still haven't succeeded.
    $this->logger->warning('Failed to find suitable video after @attempts attempts.', ['@attempts' => $max_attempts]);
    return FALSE;
  }

}
