<?php

namespace Drupal\social_post_twitter\Plugin\RulesAction;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\rules\Core\RulesActionBase;
use Drupal\social_api\User\UserManagerInterface;
use Drupal\social_post_twitter\Plugin\Network\TwitterPostInterface;
use Drupal\social_post_twitter\TwitterPostManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Tweet' action.
 *
 * @RulesAction(
 *   id = "social_post_twitter_tweet",
 *   label = @Translation("Tweet"),
 *   category = @Translation("Social Post"),
 *   context_definitions = {
 *     "status" = @ContextDefinition("string",
 *       label = @Translation("Tweet content"),
 *       description = @Translation("Specifies the status to post.")
 *     )
 *   }
 * )
 */
class Tweet extends RulesActionBase implements ContainerFactoryPluginInterface {

  /**
   * The twitter post network plugin.
   *
   * @var \Drupal\social_post_twitter\Plugin\Network\TwitterPostInterface
   */
  protected $twitterPoster;

  /**
   * The social api user manager.
   *
   * @var \Drupal\social_post\User\UserManager
   */
  protected $userManager;

  /**
   * The twitter post network plugin.
   *
   * @var \Drupal\social_post_twitter\TwitterPostManager
   */
  protected $postManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \Drupal\social_post_twitter\Plugin\Network\TwitterPost $twitter_post*/
    $twitter_poster = $container->get('plugin.network.manager')->createInstance('social_post_twitter');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $twitter_poster,
      $container->get('social_post.user_manager'),
      $container->get('twitter_post.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Tweet constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\social_post_twitter\Plugin\Network\TwitterPostInterface $twitter_post
   *   The twitter post network plugin.
   * @param \Drupal\social_api\User\UserManagerInterface $user_manager
   *   The social user manager.
   * @param \Drupal\social_post_twitter\TwitterPostManagerInterface $post_manager
   *   The twitter post manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration,
                              $plugin_id,
                              $plugin_definition,
                              TwitterPostInterface $twitter_post,
                              UserManagerInterface $user_manager,
                              TwitterPostManagerInterface $post_manager,
                              AccountInterface $current_user) {

    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->twitterPoster = $twitter_post;
    $this->userManager = $user_manager;
    $this->postManager = $post_manager;
    $this->currentUser = $current_user;

    $client = $this->twitterPoster->getSdk();
    $this->postManager->setClient($client);
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $accounts = $this->userManager->getAccounts('social_post_twitter', $this->currentUser->id());
    $status = $this->getContextValue('status');
    foreach ($accounts as $account) {
      $access_token = json_decode($account->getToken());
      $this->postManager
        ->setOauthToken($access_token->oauth_token, $access_token->oauth_token_secret)
        ->doPost(['status' => $status]);
    }
  }

}
