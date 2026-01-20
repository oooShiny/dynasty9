<?php

namespace Drupal\dynasty_social_post\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for posting videos to YouTube.
 */
class YouTubeService {

  /**
   * YouTube OAuth authorization URL.
   */
  const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

  /**
   * YouTube OAuth token URL.
   */
  const TOKEN_URL = 'https://oauth2.googleapis.com/token';

  /**
   * YouTube API base URL.
   */
  const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';

  /**
   * YouTube upload URL.
   */
  const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

  /**
   * YouTube category ID for Sports.
   */
  const CATEGORY_SPORTS = '17';

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
   * Constructs a YouTubeService object.
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
   * Checks if YouTube is configured and authorized.
   *
   * @return bool
   *   TRUE if YouTube is ready to use.
   */
  public function isConfigured() {
    $config = $this->configFactory->get('dynasty_social_post.settings');
    return !empty($config->get('youtube_client_id'))
      && !empty($config->get('youtube_client_secret'))
      && !empty($this->state->get('dynasty_social_post.youtube_refresh_token'));
  }

  /**
   * Gets the OAuth authorization URL.
   *
   * @return string
   *   The authorization URL.
   */
  public function getAuthorizationUrl() {
    $config = $this->configFactory->get('dynasty_social_post.settings');
    $client_id = $config->get('youtube_client_id');

    $callback_url = Url::fromRoute('dynasty_social_post.youtube_callback', [], ['absolute' => TRUE])->toString();

    $params = [
      'client_id' => $client_id,
      'redirect_uri' => $callback_url,
      'response_type' => 'code',
      'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
      'access_type' => 'offline',
      'prompt' => 'consent',
    ];

    return self::AUTH_URL . '?' . http_build_query($params);
  }

  /**
   * Exchanges an authorization code for tokens.
   *
   * @param string $code
   *   The authorization code from OAuth callback.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function exchangeCodeForTokens($code) {
    $config = $this->configFactory->get('dynasty_social_post.settings');
    $client_id = $config->get('youtube_client_id');
    $client_secret = $config->get('youtube_client_secret');

    $callback_url = Url::fromRoute('dynasty_social_post.youtube_callback', [], ['absolute' => TRUE])->toString();

    try {
      $response = $this->httpClient->post(self::TOKEN_URL, [
        'form_params' => [
          'code' => $code,
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'redirect_uri' => $callback_url,
          'grant_type' => 'authorization_code',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['access_token']) && isset($data['refresh_token'])) {
        // Store tokens in state (refresh token is long-lived).
        $this->state->set('dynasty_social_post.youtube_access_token', $data['access_token']);
        $this->state->set('dynasty_social_post.youtube_refresh_token', $data['refresh_token']);
        $this->state->set('dynasty_social_post.youtube_token_expires', time() + ($data['expires_in'] ?? 3600));

        $this->logger->info('YouTube OAuth tokens obtained successfully.');
        return TRUE;
      }

      $this->logger->error('YouTube OAuth response missing tokens: @response', ['@response' => json_encode($data)]);
      return FALSE;
    }
    catch (GuzzleException $e) {
      $this->logger->error('YouTube OAuth token exchange failed: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Gets a valid access token, refreshing if necessary.
   *
   * @return string|null
   *   The access token, or NULL on failure.
   */
  protected function getAccessToken() {
    $access_token = $this->state->get('dynasty_social_post.youtube_access_token');
    $expires = $this->state->get('dynasty_social_post.youtube_token_expires', 0);
    $refresh_token = $this->state->get('dynasty_social_post.youtube_refresh_token');

    // Check if token is still valid (with 5 minute buffer).
    if ($access_token && time() < ($expires - 300)) {
      return $access_token;
    }

    // Need to refresh the token.
    if (!$refresh_token) {
      $this->logger->error('No YouTube refresh token available.');
      return NULL;
    }

    $config = $this->configFactory->get('dynasty_social_post.settings');
    $client_id = $config->get('youtube_client_id');
    $client_secret = $config->get('youtube_client_secret');

    try {
      $response = $this->httpClient->post(self::TOKEN_URL, [
        'form_params' => [
          'refresh_token' => $refresh_token,
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'grant_type' => 'refresh_token',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['access_token'])) {
        $this->state->set('dynasty_social_post.youtube_access_token', $data['access_token']);
        $this->state->set('dynasty_social_post.youtube_token_expires', time() + ($data['expires_in'] ?? 3600));

        return $data['access_token'];
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('YouTube token refresh failed: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Gets the connected YouTube channel info.
   *
   * @return array|null
   *   Channel info with 'title' and 'id', or NULL on failure.
   */
  public function getChannelInfo() {
    $access_token = $this->getAccessToken();
    if (!$access_token) {
      return NULL;
    }

    try {
      $response = $this->httpClient->get(self::API_BASE_URL . '/channels', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
        'query' => [
          'part' => 'snippet',
          'mine' => 'true',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (!empty($data['items'][0])) {
        return [
          'id' => $data['items'][0]['id'],
          'title' => $data['items'][0]['snippet']['title'],
        ];
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to get YouTube channel info: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Finds or creates the Patriots Highlights playlist.
   *
   * @return string|null
   *   The playlist ID, or NULL on failure.
   */
  protected function getOrCreatePlaylist() {
    $access_token = $this->getAccessToken();
    if (!$access_token) {
      return NULL;
    }

    $playlist_name = 'Patriots Highlights';

    // Check if we have a cached playlist ID.
    $cached_playlist_id = $this->state->get('dynasty_social_post.youtube_playlist_id');
    if ($cached_playlist_id) {
      return $cached_playlist_id;
    }

    try {
      // Search for existing playlist.
      $response = $this->httpClient->get(self::API_BASE_URL . '/playlists', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
        'query' => [
          'part' => 'snippet',
          'mine' => 'true',
          'maxResults' => 50,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      foreach ($data['items'] ?? [] as $playlist) {
        if ($playlist['snippet']['title'] === $playlist_name) {
          $this->state->set('dynasty_social_post.youtube_playlist_id', $playlist['id']);
          return $playlist['id'];
        }
      }

      // Playlist doesn't exist, create it.
      $response = $this->httpClient->post(self::API_BASE_URL . '/playlists', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'query' => [
          'part' => 'snippet,status',
        ],
        'json' => [
          'snippet' => [
            'title' => $playlist_name,
            'description' => 'Highlights from the New England Patriots Dynasty era (2001-2019)',
          ],
          'status' => [
            'privacyStatus' => 'public',
          ],
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['id'])) {
        $this->state->set('dynasty_social_post.youtube_playlist_id', $data['id']);
        $this->logger->info('Created YouTube playlist: @name (@id)', [
          '@name' => $playlist_name,
          '@id' => $data['id'],
        ]);
        return $data['id'];
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to get/create YouTube playlist: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Adds a video to the playlist.
   *
   * @param string $video_id
   *   The YouTube video ID.
   * @param string $playlist_id
   *   The playlist ID.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected function addToPlaylist($video_id, $playlist_id) {
    $access_token = $this->getAccessToken();
    if (!$access_token) {
      return FALSE;
    }

    try {
      $response = $this->httpClient->post(self::API_BASE_URL . '/playlistItems', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'query' => [
          'part' => 'snippet',
        ],
        'json' => [
          'snippet' => [
            'playlistId' => $playlist_id,
            'resourceId' => [
              'kind' => 'youtube#video',
              'videoId' => $video_id,
            ],
          ],
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return isset($data['id']);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to add video to playlist: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Uploads a video to YouTube using resumable upload.
   *
   * @param string $video_path
   *   Path to the video file.
   * @param array $metadata
   *   Video metadata (title, description, tags).
   *
   * @return string|null
   *   The YouTube video ID, or NULL on failure.
   */
  protected function uploadVideo($video_path, array $metadata) {
    $access_token = $this->getAccessToken();
    if (!$access_token) {
      return NULL;
    }

    if (!file_exists($video_path)) {
      $this->logger->error('Video file not found: @path', ['@path' => $video_path]);
      return NULL;
    }

    $file_size = filesize($video_path);

    try {
      // Step 1: Initialize resumable upload and get upload URI.
      $response = $this->httpClient->post(self::UPLOAD_URL, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
          'X-Upload-Content-Length' => $file_size,
          'X-Upload-Content-Type' => 'video/mp4',
        ],
        'query' => [
          'uploadType' => 'resumable',
          'part' => 'snippet,status',
        ],
        'json' => [
          'snippet' => [
            'title' => $metadata['title'],
            'description' => $metadata['description'],
            'tags' => $metadata['tags'],
            'categoryId' => self::CATEGORY_SPORTS,
          ],
          'status' => [
            'privacyStatus' => 'public',
            'selfDeclaredMadeForKids' => FALSE,
          ],
        ],
      ]);

      $upload_url = $response->getHeader('Location')[0] ?? NULL;

      if (!$upload_url) {
        $this->logger->error('No upload URL returned from YouTube.');
        return NULL;
      }

      $this->logger->info('Got YouTube upload URL, uploading @size bytes', ['@size' => $file_size]);

      // Step 2: Upload the video file.
      $video_content = file_get_contents($video_path);

      $response = $this->httpClient->put($upload_url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'video/mp4',
          'Content-Length' => $file_size,
        ],
        'body' => $video_content,
        'timeout' => 600, // 10 minutes for upload.
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['id'])) {
        $this->logger->info('Video uploaded to YouTube with ID: @id', ['@id' => $data['id']]);
        return $data['id'];
      }

      $this->logger->error('YouTube upload response missing video ID: @response', ['@response' => json_encode($data)]);
      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('YouTube video upload failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
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
   *
   * @return string|null
   *   Path to the temporary file, or NULL on failure.
   */
  protected function downloadVideoToTemp($video_url, $filename) {
    try {
      $temp_dir = $this->fileSystem->getTempDirectory();
      $temp_file = $temp_dir . '/' . $filename;

      $this->logger->info('Downloading video from muse.ai to: @path', ['@path' => $temp_file]);

      $response = $this->httpClient->get($video_url, [
        'sink' => $temp_file,
        'timeout' => 300,
      ]);

      if (file_exists($temp_file) && filesize($temp_file) > 0) {
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
   * Gets a random highlight that hasn't been posted to YouTube yet.
   *
   * @return \Drupal\node\NodeInterface|null
   *   A highlight node, or NULL if none available.
   */
  protected function getRandomHighlight() {
    $storage = $this->entityTypeManager->getStorage('node');

    // Query for highlight nodes that haven't been posted to YouTube.
    $query = $storage->getQuery()
      ->condition('type', 'highlight')
      ->condition('status', 1)
      ->exists('field_muse_video_id')
      ->accessCheck(FALSE);

    // Exclude highlights that have been posted to YouTube.
    $posted_highlights = $this->state->get('dynasty_social_post.youtube_posted_highlights', []);
    if (!empty($posted_highlights)) {
      $query->condition('nid', $posted_highlights, 'NOT IN');
    }

    $nids = $query->execute();

    if (empty($nids)) {
      // If all highlights have been posted, reset the list.
      $this->state->set('dynasty_social_post.youtube_posted_highlights', []);

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
   * Builds video metadata from a highlight node.
   *
   * @param \Drupal\node\NodeInterface $highlight
   *   The highlight node.
   *
   * @return array
   *   Array with 'title', 'description', and 'tags'.
   */
  protected function buildVideoMetadata($highlight) {
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
    $opponent_name = '';

    if ($game && $opponent) {
      $home_away = $game->get('field_home_away')->value;
      $location_prefix = 'vs';

      if ($home_away === 'Away') {
        $location_prefix = '@';
      }

      $opponent_name = $opponent->getTitle();
      $parts[] = "{$location_prefix} {$opponent_name}";
    }

    // Get the play description.
    $description = $highlight->get('field_play_description')->value;
    if (empty($description)) {
      $description = $highlight->getTitle();
    }

    // Build title: "Patriots Highlight: [Season] [Week] [vs/@ Opponent] - [Description]"
    $context = !empty($parts) ? implode(' ', $parts) : '';
    $title = 'Patriots Highlight';
    if ($context) {
      $title .= ': ' . $context;
    }
    // Truncate title to 100 chars max (YouTube limit is 100).
    if (strlen($title) > 100) {
      $title = substr($title, 0, 97) . '...';
    }

    // Build description.
    $desc_parts = [];
    $desc_parts[] = $description;
    $desc_parts[] = '';
    if ($context) {
      $desc_parts[] = 'Game: ' . $context;
    }
    $desc_parts[] = '';
    $desc_parts[] = 'From the New England Patriots Dynasty era (2001-2019)';
    $desc_parts[] = '';
    $desc_parts[] = '#Patriots #Dynasty #NFL #Football';

    // Add opponent tag if available.
    $tags = ['nfl', 'football', 'patriots', 'dynasty', 'new england patriots', 'tom brady'];
    if ($opponent_name) {
      $tags[] = strtolower($opponent_name);
    }
    if ($season) {
      $tags[] = $season . ' nfl season';
    }

    return [
      'title' => $title,
      'description' => implode("\n", $desc_parts),
      'tags' => $tags,
    ];
  }

  /**
   * Posts a highlight to YouTube.
   *
   * @param \Drupal\node\NodeInterface|null $highlight
   *   The highlight node to post, or NULL to select a random one.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function postHighlight($highlight = NULL) {
    if (!$this->isConfigured()) {
      $this->logger->warning('YouTube is not configured or authorized.');
      return FALSE;
    }

    $temp_file = NULL;
    $max_attempts = 5;
    $attempts = 0;

    while ($attempts < $max_attempts) {
      $attempts++;

      try {
        // Get a highlight if not provided.
        if (!$highlight) {
          $highlight = $this->getRandomHighlight();
        }

        if (!$highlight) {
          $this->logger->warning('No highlights available to post to YouTube.');
          return FALSE;
        }

        // Get the muse.ai video ID.
        $muse_video_id = $highlight->get('field_muse_video_id')->value;
        if (empty($muse_video_id)) {
          $this->logger->error('Highlight @nid has no muse.ai video ID.', ['@nid' => $highlight->id()]);
          $this->markAsPosted($highlight->id());
          $highlight = NULL;
          continue;
        }

        // Fetch video metadata from muse.ai.
        $video_info = $this->getMuseVideoInfo($muse_video_id);
        if (!$video_info) {
          $this->markAsPosted($highlight->id());
          $highlight = NULL;
          continue;
        }

        // Download the video.
        $filename = $video_info['filename'] ?? "video_{$muse_video_id}.mp4";
        $temp_file = $this->downloadVideoToTemp($video_info['url'], $filename);
        if (!$temp_file) {
          $this->markAsPosted($highlight->id());
          $highlight = NULL;
          continue;
        }

        // Build video metadata.
        $metadata = $this->buildVideoMetadata($highlight);

        // Upload to YouTube.
        $video_id = $this->uploadVideo($temp_file, $metadata);
        if (!$video_id) {
          return FALSE;
        }

        // Add to playlist.
        $playlist_id = $this->getOrCreatePlaylist();
        if ($playlist_id) {
          $this->addToPlaylist($video_id, $playlist_id);
        }

        // Mark as posted.
        $this->markAsPosted($highlight->id());

        $this->logger->info('Successfully posted highlight @nid to YouTube (video ID: @vid).', [
          '@nid' => $highlight->id(),
          '@vid' => $video_id,
        ]);

        return TRUE;
      }
      finally {
        // Clean up temporary file.
        if ($temp_file && file_exists($temp_file)) {
          unlink($temp_file);
          $this->logger->info('Cleaned up temporary file: @path', ['@path' => $temp_file]);
        }
      }
    }

    $this->logger->warning('Failed to find suitable video for YouTube after @attempts attempts.', ['@attempts' => $max_attempts]);
    return FALSE;
  }

  /**
   * Marks a highlight as posted to YouTube.
   *
   * @param int $nid
   *   The node ID.
   */
  protected function markAsPosted($nid) {
    $posted_highlights = $this->state->get('dynasty_social_post.youtube_posted_highlights', []);
    $posted_highlights[] = $nid;
    $this->state->set('dynasty_social_post.youtube_posted_highlights', $posted_highlights);
  }

  /**
   * Disconnects YouTube (clears tokens).
   */
  public function disconnect() {
    $this->state->delete('dynasty_social_post.youtube_access_token');
    $this->state->delete('dynasty_social_post.youtube_refresh_token');
    $this->state->delete('dynasty_social_post.youtube_token_expires');
    $this->state->delete('dynasty_social_post.youtube_playlist_id');
    $this->logger->info('YouTube account disconnected.');
  }

}
