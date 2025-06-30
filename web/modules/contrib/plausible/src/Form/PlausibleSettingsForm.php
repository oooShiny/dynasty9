<?php

namespace Drupal\plausible\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;

/**
 * Configure Plausible settings for this site.
 */
class PlausibleSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'plausible.settings';

  /**
   * A string map, from config key to form element key.
   *
   * @var string[]
   */
  const FORM_CONFIG_KEY_MAP = [
    'script_domain' => 'script.domain',
    'script_api' => 'script.api',
    'script_src' => 'script.src',
    'shared_link' => 'dashboard.shared_link',
    'visibility_enable' => 'visibility.enable',
    'visibility_admin_route_mode' => 'visibility.admin_route_mode',
    'visibility_request_path_mode' => 'visibility.request_path_mode',
    'visibility_request_path_pages' => 'visibility.request_path_pages',
    'visibility_user_role_mode' => 'visibility.user_role_mode',
    'visibility_user_role_roles' => 'visibility.user_role_roles',
    'event_403' => 'events.403',
    'event_404' => 'events.404',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'plausible_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    $intro_message = $this->t('Visit <a href=":plausibleWebsite" target="_blank">Plausible.io</a> to sign up for a free account.', [
      ':plausibleWebsite' => 'https://plausible.io',
    ]);
    $form['intro'] = [
      '#markup' => '<p>' . $intro_message . '</p>',
    ];

    $front_url = Url::fromRoute('<front>')->setAbsolute()->toString();
    $current_domain = parse_url($front_url, PHP_URL_HOST);

    $form['script_domain'] = [
      '#default_value' => $config->get('script.domain'),
      '#type' => 'textfield',
      '#title' => $this->t('Domain'),
      '#description' => $this->t('The domain of your site as configured in Plausible. If this is left empty, the current domain will be used.'),
      '#placeholder' => $current_domain,
    ];

    $form['script_api'] = [
      '#default_value' => $config->get('script.api'),
      '#type' => 'textfield',
      '#title' => $this->t('API endpoint'),
      '#description' => $this->t('The endpoint where the data should be sent. If this is left empty, the default endpoint will be used.'),
    ];

    $form['script_src'] = [
      '#default_value' => $config->get('script.src'),
      '#type' => 'textfield',
      '#title' => $this->t('Script source'),
      '#description' => $this->t('The path or url of the tracking script'),
      '#required' => TRUE,
    ];

    $form['dashboard'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Dashboard'),
      '#description' => $this->t('You can view your site stats from the comfort of the Drupal administration interface by embedding a shared link. Read more about Shared links in the <a href=":sharedLinkDocsUrl">Plausible documentation</a>.', [
        ':sharedLinkDocsUrl' => 'https://plausible.io/docs/shared-links',
      ]),
    ];

    $form['dashboard']['shared_link'] = [
      '#default_value' => $config->get('dashboard.shared_link'),
      '#type' => 'textfield',
      '#title' => $this->t('Shared link'),
    ];

    $form['events'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Custom events'),
      '#description' => $this->t('Custom events are used to track specific actions on your site. Plausible allows you to set goals for events, these can be configured <a href="https://plausible.io/docs/custom-event-goals">here</a>.'),
    ];

    $form['events']['event_403'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('403 status code'),
      '#description' => $this->t('Track visits returning a 403 Forbidden status code.'),
      '#default_value' => $config->get('events.403'),
    ];

    $form['events']['event_404'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('404 status code'),
      '#description' => $this->t('Track visits returning a 404 Not Found status code.'),
      '#default_value' => $config->get('events.404'),
    ];

    // Visibility settings.
    $form['tracking_scope'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Tracking scope'),
    ];

    // General.
    $form['tracking']['general_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('General'),
      '#group' => 'tracking_scope',
    ];

    $form['tracking']['general_settings']['visibility_enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable tracking'),
      '#description' => $this->t('Global toggle for enabling/disabling tracking.'),
      '#default_value' => $config->get('visibility.enable'),
    ];

    // Pages.
    $form['tracking']['page_visibility_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Pages'),
      '#group' => 'tracking_scope',
    ];

    $form['tracking']['page_visibility_settings']['visibility_admin_route_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Add tracking to admin pages'),
      '#description' => $this->t('Whether admin pages should be included in tracking.'),
      '#options' => [
        $this->t('Include admin pages'),
        $this->t('Exclude admin pages'),
        $this->t('Only admin pages'),
      ],
      '#default_value' => $config->get('visibility.admin_route_mode'),
    ];

    $form['tracking']['page_visibility_settings']['visibility_request_path_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Add tracking to specific pages'),
      '#options' => [
        $this->t('Disabled'),
        $this->t('Every page except the listed pages'),
        $this->t('The listed pages only'),
      ],
      '#default_value' => $config->get('visibility.request_path_mode'),
    ];

    $visibility_request_path_pages = $config->get('visibility.request_path_pages');
    $title = $this->t('Pages');
    $description = $this->t("Specify pages by using their paths. Enter one path per line.
     The '*' character is a wildcard. Example paths are %blog for the blog page and
     %blog-wildcard for every personal blog. %front is the front page.", [
       '%blog' => '/blog',
       '%blog-wildcard' => '/blog/*',
       '%front' => '<front>',
     ]);

    $form['tracking']['page_visibility_settings']['visibility_request_path_pages'] = [
      '#type' => 'textarea',
      '#title' => $title,
      '#title_display' => 'invisible',
      '#default_value' => !empty($visibility_request_path_pages) ? $visibility_request_path_pages : '',
      '#description' => $description,
      '#rows' => 10,
      '#states' => [
        'visible' => [
          ':input[name="visibility_request_path_mode"]' => ['!value' => '0'],
        ],
      ],
    ];

    // Roles.
    $visibility_user_role_roles = $config->get('visibility.user_role_roles');
    $form['tracking']['role_visibility_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Roles'),
      '#group' => 'tracking_scope',
    ];

    $form['tracking']['role_visibility_settings']['visibility_user_role_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Add tracking for specific roles'),
      '#options' => [
        $this->t('Disabled'),
        $this->t('Add to the selected roles only'),
        $this->t('Add to every role except the selected ones'),
      ],
      '#default_value' => $config->get('visibility.user_role_mode'),
    ];

    $options = array_map(function (Role $role) {
      return Html::escape($role->label());
    }, Role::loadMultiple());

    $form['tracking']['role_visibility_settings']['visibility_user_role_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#default_value' => !empty($visibility_user_role_roles) ? $visibility_user_role_roles : [],
      '#options' => $options,
      '#states' => [
        'visible' => [
          ':input[name="visibility_user_role_mode"]' => ['!value' => '0'],
        ],
      ],
    ];

    $this->disableOverriddenElements($form);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $domains = explode(',', $form_state->getValue('script_domain'));
    $domain_validation = '/[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\&\.\/\?\:@\-_=#]{2,}/';

    foreach ($domains as $domain) {
      if ($domain === '') {
        continue;
      }

      if (!preg_match($domain_validation, $domain)) {
        $form_state->setErrorByName('script_domain', $this->t('%domain is not a valid domain. Please enter the full domain.', ['%domain' => $domain]));
      }
    }

    // Verify that every path is prefixed with a slash, but do not check for
    // slashes if no paths configured.
    if ($form_state->getValue('visibility_request_path_mode') != 2
      && !empty($form_state->getValue('visibility_request_path_pages'))) {

      $pages = preg_split('/(\r\n?|\n)/', $form_state->getValue('visibility_request_path_pages'));
      foreach ($pages as $page) {
        if (strpos($page, '/') !== 0 && $page !== '<front>') {
          $msg = $this->t('Path "@page" not prefixed with slash.', ['@page' => $page]);
          $form_state->setErrorByName('visibility_request_path_pages', $msg);
          // Drupal forms show one error only.
          break;
        }
      }
    }

    // Validate shared link.
    $shared_link = $form_state->getValue('shared_link');
    $shared_link_validation = '/^(?=.*\bshare\b)(?=.*\bauth\b).*$/';

    if ($shared_link) {
      $shared_link_domain = parse_url($shared_link, PHP_URL_HOST);
      if (!preg_match($domain_validation, $shared_link_domain)) {
        $form_state->setErrorByName('shared_link_domain', $this->t('%domain is not a valid domain. Please enter the full domain.', ['%domain' => $shared_link_domain]));
      }
      if (!preg_match($shared_link_validation, $shared_link)) {
        $form_state->setErrorByName('shared_link', $this->t('%shared_link is not a valid shared Link.', ['%shared_link' => $shared_link]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $config = $this->configFactory->getEditable(static::SETTINGS);

    // Set the submitted configuration setting.
    foreach (static::FORM_CONFIG_KEY_MAP as $element_key => $config_key) {
      $config->set($config_key, $form_state->getValue($element_key));
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Recursively disable elements whose config values are overridden.
   */
  protected function disableOverriddenElements(array &$form): void {
    $config = $this->configFactory->get(static::SETTINGS);

    foreach (Element::children($form) as $element_key) {
      $config_key = static::FORM_CONFIG_KEY_MAP[$element_key] ?? NULL;

      if ($config_key !== NULL && $config->get($config_key) !== $config->getOriginal($config_key, FALSE)) {
        $form[$element_key]['#disabled'] = TRUE;
        $form[$element_key]['#description'] = $this->t('This config cannot be changed because it is overridden.');
      }

      if (is_array($form[$element_key])) {
        $this->disableOverriddenElements($form[$element_key]);
      }
    }
  }

}
