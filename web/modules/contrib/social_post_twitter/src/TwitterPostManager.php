<?php

namespace Drupal\social_post_twitter;

use Abraham\TwitterOAuth\TwitterOAuth;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\social_api\SocialApiException;

/**
 * Manages the authorization process before getting a long lived access token.
 */
class TwitterPostManager implements TwitterPostManagerInterface {
  use LoggerChannelTrait;

  /**
   * The Twitter client.
   *
   * @var \Abraham\TwitterOAuth\TwitterOAuth
   */
  protected $client;

  /**
   * The tweet text (with optional media ids).
   *
   * @var array
   */
  protected $tweet;

  /**
   * {@inheritdoc}
   */
  public function setClient(TwitterOAuth $client) {
    $this->client = $client;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOauthToken($oauth_token, $oauth_token_secret) {
    $this->client->setOauthToken($oauth_token, $oauth_token_secret);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function doPost($tweet) {

    // Make backwards-compatible if someone just posts a tweet text as a string.
    $this->tweet['status'] = is_array($tweet) && !empty($tweet['status']) ? $tweet['status'] : $tweet;

    // Check if there needs to be media uploaded. If so, upload and store ids.
    if (!empty($tweet['media_paths'])) {
      $this->tweet['media_ids'] = $this->uploadMedia($tweet['media_paths']);
    }

    try {
      return $this->post();
    }
    catch (SocialApiException $e) {
      $this->getLogger('social_post_twitter')->error($e->getMessage());

      return FALSE;
    }

  }

  /**
   * {@inheritdoc}
   */
  public function uploadMedia(array $paths) {
    $media_ids = [];
  
    foreach ($paths as $path) {
      // Upload the media from the path.
      $media = $this->client->post('media/upload', [
        'media' => file_get_contents($path),
        'type' => mime_content_type($path),
      ]);
  
      // The response contains the media_key to attach the media to the post.
      $media_ids[] = $media->media_key;
    }
  
    return $media_ids;
  }
  

  /**
   * Post the tweet with the client.
   *
   * @return bool
   *   TRUE on success, FALSE otherwise (with a logger message).
   */
  protected function post() {
    $post = $this->client->post('tweets', ['text' => $this->tweet['status']]);

    $httpCode = $this->client->getLastHttpCode();
    if ($httpCode !== 200 || $httpCode !== 201) {
      $this->getLogger('social_post_twitter')->error("HTTP Error code: $httpCode");

      return FALSE;
    }

    if (isset($post->errors)) {
      $this->getLogger('social_post_twitter')->error($post->errors[0]->message);

      return FALSE;
    }

    return TRUE;
  }

}
