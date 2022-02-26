<?php

namespace Drupal\patreon_user\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Config;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\patreon\PatreonServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides a 'PatreonUserBlock' block.
 *
 * @Block(
 *  id = "patreon_user_block",
 *  admin_label = @Translation("Patreon user block"),
 * )
 */
class PatreonUserBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Drupal\patreon_user\PatreonUserService definition.
   *
   * @var \Drupal\patreon\PatreonServiceInterface
   */
  protected PatreonServiceInterface $patreonUserApi;

  /**
   * The module configuration.
   *
   * @var \Drupal\Core\Config\Config
   *   The configuration object.
   */
  protected Config $config;

  /**
   * The login method.
   *
   * @var int
   */
  protected int $loginMethod;

  /**
   * Constructs a new PatreonUserBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\patreon\PatreonServiceInterface $patreon_user_api
   *   The API User Service.
   * @param \Drupal\Core\Config\Config $config
   *   The module config.
   * @param int $login
   *   The current login setting.
   */
  public function __construct(
        array $configuration,
        string $plugin_id,
        array $plugin_definition,
        PatreonServiceInterface $patreon_user_api,
        Config $config,
    int $login
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->patreonUserApi = $patreon_user_api;
    $this->config = $config;
    $this->loginMethod = $login;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $configFactory = $container->get('config.factory');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('patreon_user.api'),
      $configFactory->getEditable('patreon.settings'),
      $configFactory->getEditable('patreon_user.settings')->get('patreon_user_registration')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    if ($account->isAnonymous()) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.roles:anonymous']);
    }
    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];

    if ($this->loginMethod !== PATREON_USER_NO_LOGIN) {
      $key = $this->config->get('patreon_client_id');
      if ($path = $this->patreonUserApi->getReturnPath()) {
        $url = Url::fromRoute($path['route_name'], $path['route_parameters']);
        $return = $url->toString();
      }
      else {
        $return = '';
      }
      $url = $this->patreonUserApi->authoriseAccount($key, [
        'identity',
        'identity[email]',
        'identity.memberships',
        'campaigns.members',
      ], $return, FALSE);

      $build['patreon_user_block'] = [
        '#title' => $this->t('Login via Patreon'),
        '#type' => 'link',
        '#url' => $url,
      ];
    }

    return $build;
  }

  /**
   * @inheritDoc
   */
  public function getCacheTags(): array {
    $tags = [
      'config:patreon_user.patreon_user_registration',
    ];
    if ($this->patreonUserApi->getReturnPath()) {
      $tags[] = 'user.roles';
    }
    else {
      $tags[] = 'url.path';
    }
    return Cache::mergeTags(parent::getCacheTags(), $tags);
  }

}
