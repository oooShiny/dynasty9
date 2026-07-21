<?php

namespace Drupal\dynasty_social_post\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for posting Reels to Instagram via the Meta Graph API.
 */
class InstagramService {

  /**
   * Facebook OAuth authorization URL.
   */
  const AUTH_URL = 'https://www.facebook.com/v19.0/dialog/oauth';

  /**
   * Facebook Graph API token URL.
   */
  const TOKEN_URL = 'https://graph.facebook.com/v19.0/oauth/access_token';

  /**
   * Graph API base URL.
   */
  const API_BASE_URL = 'https://graph.facebook.com/v19.0';

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs an InstagramService object.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('dynasty_social_post');
    $this->state = $state;
  }

  /**
   * Checks if Instagram is configured and has a valid (non-expired) token.
   *
   * @return bool
   */
  public function isConfigured() {
    $config = $this->configFactory->get('dynasty_social_post.settings');
    return !empty($config->get('instagram_app_id'))
      && !empty($config->get('instagram_app_secret'))
      && !empty($this->state->get('dynasty_social_post.instagram_access_token'))
      && !empty($this->state->get('dynasty_social_post.instagram_user_id'));
  }

  /**
   * Builds the Facebook OAuth URL to start the authorization flow.
   *
   * @return string
   */
  public function getAuthorizationUrl() {
    $config = $this->configFactory->get('dynasty_social_post.settings');
    $app_id = $config->get('instagram_app_id');
    $callback_url = Url::fromRoute('dynasty_social_post.instagram_callback', [], ['absolute' => TRUE])->toString();

    $params = [
      'client_id' => $app_id,
      'redirect_uri' => $callback_url,
      'response_type' => 'code',
      'scope' => implode(',', [
        'instagram_basic',
        'instagram_content_publish',
        'pages_read_engagement',
        'pages_show_list',
      ]),
    ];

    return self::AUTH_URL . '?' . http_build_query($params);
  }

  /**
   * Exchanges an OAuth code for a long-lived access token and stores it.
   *
   * @param string $code
   *   The authorization code from the OAuth callback.
   *
   * @return bool
   */
  public function exchangeCodeForTokens($code) {
    $config = $this->configFactory->get('dynasty_social_post.settings');
    $app_id = $config->get('instagram_app_id');
    $app_secret = $config->get('instagram_app_secret');
    $callback_url = Url::fromRoute('dynasty_social_post.instagram_callback', [], ['absolute' => TRUE])->toString();

    try {
      // Exchange code for short-lived token.
      $response = $this->httpClient->get(self::TOKEN_URL, [
        'query' => [
          'client_id' => $app_id,
          'client_secret' => $app_secret,
          'redirect_uri' => $callback_url,
          'code' => $code,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($data['access_token'])) {
        $this->logger->error('Instagram OAuth missing access token: @response', ['@response' => json_encode($data)]);
        return FALSE;
      }

      // Exchange short-lived token for a long-lived one (valid ~60 days).
      $response = $this->httpClient->get(self::TOKEN_URL, [
        'query' => [
          'grant_type' => 'fb_exchange_token',
          'client_id' => $app_id,
          'client_secret' => $app_secret,
          'fb_exchange_token' => $data['access_token'],
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($data['access_token'])) {
        $this->logger->error('Instagram long-lived token exchange failed: @response', ['@response' => json_encode($data)]);
        return FALSE;
      }

      $expires_in = $data['expires_in'] ?? (60 * 24 * 60 * 60);
      $this->state->set('dynasty_social_post.instagram_access_token', $data['access_token']);
      $this->state->set('dynasty_social_post.instagram_token_expires', time() + $expires_in);

      // Discover and store the Instagram Business Account ID.
      $instagram_user_id = $this->fetchInstagramUserId($data['access_token']);
      if (!$instagram_user_id) {
        $this->logger->error('No Instagram Business or Creator account found linked to this Facebook account. Ensure your Instagram account is a Business/Creator account connected to a Facebook Page.');
        return FALSE;
      }

      $this->state->set('dynasty_social_post.instagram_user_id', $instagram_user_id);
      $this->logger->info('Instagram authorized. Business Account ID: @id', ['@id' => $instagram_user_id]);
      return TRUE;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Instagram OAuth token exchange failed: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Finds the Instagram Business Account ID from the user's Facebook Pages.
   *
   * @param string $access_token
   *
   * @return string|null
   */
  protected function fetchInstagramUserId($access_token) {
    try {
      $response = $this->httpClient->get(self::API_BASE_URL . '/me/accounts', [
        'query' => [
          'access_token' => $access_token,
          'fields' => 'instagram_business_account,name',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      foreach ($data['data'] ?? [] as $page) {
        if (!empty($page['instagram_business_account']['id'])) {
          return $page['instagram_business_account']['id'];
        }
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch Instagram Business Account ID: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Returns a valid access token, refreshing proactively when nearing expiry.
   *
   * @return string|null
   */
  protected function getAccessToken() {
    $access_token = $this->state->get('dynasty_social_post.instagram_access_token');
    $expires = $this->state->get('dynasty_social_post.instagram_token_expires', 0);

    if (empty($access_token)) {
      return NULL;
    }

    if (time() >= $expires) {
      $this->logger->error('Instagram access token has expired. Re-authorization required.');
      return NULL;
    }

    // Proactively refresh when within 30 days of expiry.
    $days_remaining = ($expires - time()) / 86400;
    if ($days_remaining < 30) {
      $this->refreshAccessToken($access_token);
      $access_token = $this->state->get('dynasty_social_post.instagram_access_token');
    }

    return $access_token;
  }

  /**
   * Refreshes the long-lived access token.
   *
   * @param string $current_token
   */
  protected function refreshAccessToken($current_token) {
    $config = $this->configFactory->get('dynasty_social_post.settings');

    try {
      $response = $this->httpClient->get(self::TOKEN_URL, [
        'query' => [
          'grant_type' => 'fb_exchange_token',
          'client_id' => $config->get('instagram_app_id'),
          'client_secret' => $config->get('instagram_app_secret'),
          'fb_exchange_token' => $current_token,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (!empty($data['access_token'])) {
        $expires_in = $data['expires_in'] ?? (60 * 24 * 60 * 60);
        $this->state->set('dynasty_social_post.instagram_access_token', $data['access_token']);
        $this->state->set('dynasty_social_post.instagram_token_expires', time() + $expires_in);
        $this->logger->info('Instagram access token refreshed successfully.');
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Instagram token refresh failed: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Fetches connected Instagram account info (username).
   *
   * @return array|null
   *   Array with 'username' and 'name', or NULL on failure.
   */
  public function getAccountInfo() {
    $access_token = $this->getAccessToken();
    $user_id = $this->state->get('dynasty_social_post.instagram_user_id');

    if (!$access_token || !$user_id) {
      return NULL;
    }

    try {
      $response = $this->httpClient->get(self::API_BASE_URL . '/' . $user_id, [
        'query' => [
          'access_token' => $access_token,
          'fields' => 'username,name',
        ],
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to get Instagram account info: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Creates an Instagram media container for a Reel.
   *
   * @param string $instagram_user_id
   * @param string $video_url
   *   Publicly accessible video URL (fetched by Meta's servers).
   * @param string $caption
   * @param string $access_token
   *
   * @return string|null
   *   The container ID, or NULL on failure.
   */
  protected function createMediaContainer($instagram_user_id, $video_url, $caption, $access_token) {
    try {
      $response = $this->httpClient->post(self::API_BASE_URL . '/' . $instagram_user_id . '/media', [
        'query' => ['access_token' => $access_token],
        'json' => [
          'media_type' => 'REELS',
          'video_url' => $video_url,
          'caption' => $caption,
          'share_to_feed' => TRUE,
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['id'] ?? NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to create Instagram media container: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Polls a media container until it finishes processing.
   *
   * @param string $container_id
   * @param string $access_token
   * @param int $max_attempts
   *
   * @return bool
   */
  protected function waitForContainer($container_id, $access_token, $max_attempts = 60) {
    for ($i = 0; $i < $max_attempts; $i++) {
      try {
        $response = $this->httpClient->get(self::API_BASE_URL . '/' . $container_id, [
          'query' => [
            'access_token' => $access_token,
            'fields' => 'status_code,status',
          ],
        ]);

        $data = json_decode($response->getBody()->getContents(), TRUE);
        $status_code = $data['status_code'] ?? '';

        if ($status_code === 'FINISHED') {
          return TRUE;
        }

        if (in_array($status_code, ['ERROR', 'EXPIRED'])) {
          $this->logger->error('Instagram media container failed with status @status: @detail', [
            '@status' => $status_code,
            '@detail' => $data['status'] ?? 'none',
          ]);
          return FALSE;
        }
      }
      catch (GuzzleException $e) {
        $this->logger->error('Failed to check Instagram container status: @message', ['@message' => $e->getMessage()]);
        return FALSE;
      }

      sleep(5);
    }

    $this->logger->error('Instagram media container timed out: @id', ['@id' => $container_id]);
    return FALSE;
  }

  /**
   * Publishes a finished media container.
   *
   * @param string $instagram_user_id
   * @param string $container_id
   * @param string $access_token
   *
   * @return string|null
   *   The published media ID, or NULL on failure.
   */
  protected function publishMedia($instagram_user_id, $container_id, $access_token) {
    try {
      $response = $this->httpClient->post(self::API_BASE_URL . '/' . $instagram_user_id . '/media_publish', [
        'query' => ['access_token' => $access_token],
        'json' => ['creation_id' => $container_id],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return $data['id'] ?? NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to publish Instagram media: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Gets a random highlight that hasn't been posted to Instagram yet.
   *
   * @return \Drupal\node\NodeInterface|null
   */
  protected function getRandomHighlight() {
    $storage = $this->entityTypeManager->getStorage('node');

    $query = $storage->getQuery()
      ->condition('type', 'highlight')
      ->condition('status', 1)
      ->exists('field_muse_video_id')
      ->accessCheck(FALSE);

    $posted = $this->state->get('dynasty_social_post.instagram_posted_highlights', []);
    if (!empty($posted)) {
      $query->condition('nid', $posted, 'NOT IN');
    }

    $nids = $query->execute();

    if (empty($nids)) {
      // All highlights posted — reset and start over.
      $this->state->set('dynasty_social_post.instagram_posted_highlights', []);

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

    return $storage->load(array_rand(array_flip($nids)));
  }

  /**
   * Builds the Instagram caption from a highlight node.
   *
   * @param \Drupal\node\NodeInterface $highlight
   *
   * @return string
   */
  protected function buildCaption($highlight) {
    $parts = [];

    $season = $highlight->get('field_season')->value;
    if ($season) {
      $parts[] = $season;
    }

    $week_entity = $highlight->get('field_week')->entity;
    if ($week_entity) {
      $parts[] = $week_entity->getName();
    }

    $game = $highlight->get('field_game')->entity;
    $opponent = $highlight->get('field_opponent')->entity;

    if ($game && $opponent) {
      $home_away = $game->get('field_home_away')->value;
      $prefix = ($home_away === 'Away') ? '@' : 'vs';
      $parts[] = "{$prefix} {$opponent->getTitle()}";
    }

    $title = $highlight->getTitle();
    $description = $highlight->get('field_play_description')->value;

    $lines = [];
    $lines[] = 'A Random Dynasty Highlight:';
    $lines[] = '';

    if (!empty($parts)) {
      $lines[] = implode(' ', $parts);
      $lines[] = '';
    }

    if (!empty($description) && $description !== $title) {
      $lines[] = "{$title}\n({$description})";
    }
    else {
      $lines[] = $title;
    }

    $lines[] = '';
    $lines[] = '#Patriots #Dynasty #NFL #Football #NewEnglandPatriots #TomBrady #NFLHighlights';

    return implode("\n", $lines);
  }

  /**
   * Fetches video metadata from the muse.ai API.
   *
   * @param string $muse_video_id
   *
   * @return array|null
   */
  protected function getMuseVideoInfo($muse_video_id) {
    try {
      $response = $this->httpClient->get("https://muse.ai/api/files/info/{$muse_video_id}");
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['url'])) {
        return $data;
      }

      $this->logger->error('Invalid muse.ai API response for video: @id', ['@id' => $muse_video_id]);
      return NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch muse.ai video info: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Marks a highlight as posted to Instagram.
   *
   * @param int $nid
   */
  protected function markAsPosted($nid) {
    $posted = $this->state->get('dynasty_social_post.instagram_posted_highlights', []);
    $posted[] = $nid;
    $this->state->set('dynasty_social_post.instagram_posted_highlights', $posted);
  }

  /**
   * Posts a random highlight to Instagram as a Reel.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function postRandomHighlight() {
    if (!$this->isConfigured()) {
      $this->logger->warning('Instagram is not configured or authorized.');
      return FALSE;
    }

    $access_token = $this->getAccessToken();
    $instagram_user_id = $this->state->get('dynasty_social_post.instagram_user_id');

    if (!$access_token || !$instagram_user_id) {
      return FALSE;
    }

    $max_attempts = 5;

    for ($attempts = 0; $attempts < $max_attempts; $attempts++) {
      $highlight = $this->getRandomHighlight();
      if (!$highlight) {
        $this->logger->warning('No highlights available to post to Instagram.');
        return FALSE;
      }

      $muse_video_id = $highlight->get('field_muse_video_id')->value;
      if (empty($muse_video_id)) {
        $this->logger->error('Highlight @nid has no muse.ai video ID.', ['@nid' => $highlight->id()]);
        $this->markAsPosted($highlight->id());
        continue;
      }

      $video_info = $this->getMuseVideoInfo($muse_video_id);
      if (!$video_info) {
        $this->markAsPosted($highlight->id());
        continue;
      }

      $caption = $this->buildCaption($highlight);

      // Instagram fetches the video from this URL directly — no local download needed.
      $container_id = $this->createMediaContainer($instagram_user_id, $video_info['url'], $caption, $access_token);
      if (!$container_id) {
        $this->logger->warning('Failed to create Instagram media container for highlight @nid.', ['@nid' => $highlight->id()]);
        $this->markAsPosted($highlight->id());
        continue;
      }

      $this->logger->info('Instagram media container created: @id', ['@id' => $container_id]);

      if (!$this->waitForContainer($container_id, $access_token)) {
        $this->markAsPosted($highlight->id());
        continue;
      }

      $media_id = $this->publishMedia($instagram_user_id, $container_id, $access_token);
      if ($media_id) {
        $this->markAsPosted($highlight->id());
        $this->logger->info('Successfully posted highlight @nid to Instagram (media ID: @mid).', [
          '@nid' => $highlight->id(),
          '@mid' => $media_id,
        ]);
        return TRUE;
      }

      return FALSE;
    }

    $this->logger->warning('Failed to find a suitable video for Instagram after @n attempts.', ['@n' => $max_attempts]);
    return FALSE;
  }

  /**
   * Disconnects Instagram by clearing stored tokens and account ID.
   */
  public function disconnect() {
    $this->state->delete('dynasty_social_post.instagram_access_token');
    $this->state->delete('dynasty_social_post.instagram_token_expires');
    $this->state->delete('dynasty_social_post.instagram_user_id');
    $this->logger->info('Instagram account disconnected.');
  }

}
