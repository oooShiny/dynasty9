<?php

namespace Drupal\dynasty_voicemail\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the voicemail recording page.
 */
class VoicemailPageController extends ControllerBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a VoicemailPageController object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user')
    );
  }

  /**
   * Renders the voicemail recording page.
   *
   * @return array
   *   A render array for the voicemail recorder.
   */
  public function content() {
    $config = $this->config('dynasty_voicemail.settings');
    $csrf_token = \Drupal::csrfToken()->get('voicemail_upload');

    return [
      '#theme' => 'voicemail_recorder',
      '#max_duration' => $config->get('max_duration') ?? 120,
      '#csrf_token' => $csrf_token,
      '#honeypot_enabled' => $config->get('honeypot_enabled') ?? TRUE,
      '#attached' => [
        'library' => [
          'dynasty_voicemail/voicemail-recorder',
        ],
        'drupalSettings' => [
          'dynastyVoicemail' => [
            'maxDuration' => $config->get('max_duration') ?? 120,
            'csrfToken' => $csrf_token,
            'uploadUrl' => '/api/voicemail/upload',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['session'],
      ],
    ];
  }

}
