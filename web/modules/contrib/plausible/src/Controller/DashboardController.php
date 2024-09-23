<?php

namespace Drupal\plausible\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\gin\GinSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the Plausible dashboard.
 */
class DashboardController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The theme manager.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->configFactory = $container->get('config.factory');
    $instance->themeManager = $container->get('theme.manager');

    return $instance;
  }

  /**
   * Renders the Plausible dashboard in an iframe.
   */
  public function __invoke(): array {
    $config = $this->configFactory->get('plausible.settings');
    $url = $config->get('dashboard.shared_link');

    if ($url == NULL) {
      return [
        '#markup' => $this->t('Unable to display the dashboard. The shared link is not set in the <a href=":settingsUrl">Plausible settings.</a>', [
          ':settingsUrl' => Url::fromRoute('plausible.admin_settings_form')->toString(),
        ]),
      ];
    }

    $params = [];
    $params['embed'] = 'true';
    $params['background'] = 'transparent';
    $params['theme'] = 'light';

    $activeTheme = $this->themeManager->getActiveTheme();
    $isGinInstalled = $activeTheme->getName() === 'gin' || isset($activeTheme->getBaseThemeExtensions()['gin']);
    $hasGinSettings = class_exists(GinSettings::class);

    if ($isGinInstalled && $hasGinSettings) {
      $ginSettings = \Drupal::classResolver(GinSettings::class);
      $enableDarkmode = $ginSettings->get('enable_darkmode');

      if ($enableDarkmode === 'auto') {
        $params['theme'] = 'system';
      }
      elseif ($enableDarkmode) {
        $params['theme'] = 'dark';
      }
    }

    return [
      'iframe' => [
        '#type' => 'html_tag',
        '#tag' => 'iframe',
        '#attributes' => [
          'plausible-embed' => TRUE,
          'src' => $url . '&' . http_build_query($params),
          'scrolling' => 'no',
          'frameborder' => '0',
          'loading' => 'lazy',
          'style' => 'width: 1px; min-width: 100%; height: 1600px;',
        ],
      ],
      'script' => [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#attributes' => [
          'async' => TRUE,
          'src' => 'https://plausible.io/js/embed.host.js',
        ],
      ],
    ];
  }

}
