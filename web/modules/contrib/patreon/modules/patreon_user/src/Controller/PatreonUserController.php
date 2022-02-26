<?php

namespace Drupal\patreon_user\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\patreon\PatreonServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A Controller for the Patreon User vendpoint.
 *
 * @package Drupal\patreon_user\Controller
 */
class PatreonUserController extends ControllerBase {


  /**
   * The Patreon API Service.
   *
   * @var \Drupal\patreon\PatreonServiceInterface
   */
  protected PatreonServiceInterface $service;

  /**
   * The Request Stack Service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $stack;

  /**
   * Watchdog logger channel for captcha.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Creates the controller.
   *
   * @param \Drupal\patreon\PatreonServiceInterface $service
   *   A Patreon API service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $stack
   *   The request stack service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   A Config Factory.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The Language Manager service.
   */
  public function __construct(PatreonServiceInterface $service, RequestStack $stack, LoggerInterface $logger, ConfigFactoryInterface $config_factory, AccountInterface $account, LanguageManagerInterface $language_manager) {
    $this->service = $service;
    $this->stack = $stack;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->currentUser = $account;
    $this->languageManager = $language_manager;
  }

  /**
   * Create function.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Dependency Injection Container.
   *
   * @return \Drupal\patreon_user\Controller\PatreonUserController
   *   The Controller interface.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('patreon_user.api'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('patreon_user'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('language_manager')
    );
  }

  /**
   * Logs user in from Patreon Oauth redirect return.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirects user to /user or 404s.
   */
  public function oauth() {
    $config = $this->configFactory->getEditable('patreon_user.settings');
    $settings = $config->get('patreon_user_registration');
    $route_name = '<front>';
    $route_params = [];

    if ($settings != PATREON_USER_NO_LOGIN) {
      if ($code = $this->stack->getCurrentRequest()->query->get('code')) {
        if ($this->currentUser->isAnonymous()) {
          try {
            if ($tokens = $this->service->tokensFromCode($code)) {
              $token = (isset($tokens['access_token'])) ? $tokens['access_token'] : NULL;

              if ($token) {
                $this->service->setToken($token);

                if ($patreon_data = $this->service->fetchUser()) {
                  if ($this->service->canLogin($patreon_data)) {
                    if ($account = $this->service->getUser($patreon_data)) {
                      $this->service->storeTokens($tokens, $account);

                      if (!user_is_blocked($account->getAccountName())) {
                        $this->service->assignRoles($account, $patreon_data);
                        $login_method = $config->get('patreon_user_login_method');
                        if ($state = $this->stack->getCurrentRequest()->query->get('state')) {
                          if ($url = $this->service->decodeState($state)) {
                            $route_name = $url->getRouteName();
                            $route_params = $url->getRouteParameters();
                          }
                        }

                        if ($login_method == PATREON_USER_SINGLE_SIGN_ON) {
                          user_login_finalize($account);
                        }
                        else {
                          $langcode = $this->languageManager
                            ->getCurrentLanguage()
                            ->getId();
                          $mail = _user_mail_notify('password_reset', $account, $langcode);
                          if (!empty($mail)) {
                            $this->messenger()->addError($this->t('Further instructions have been sent to your email address.'));
                          }
                        }
                      }
                      else {
                        $user_config = $this->configFactory->get('user.settings');
                        if ($user_config->get('verify_mail') && $account->isNew()) {
                          $this->messenger()->addStatus($this->t('Further instructions have been sent to your email address.'));
                        }
                        else {
                          $this->messenger()->addError($this->t('Your account is blocked. Please contact an administrator.'));
                        }
                      }
                    }
                    else {
                      $this->messenger()->addError($this->t('There was a problem creating your account. Please contact an administrator.'));
                    }
                  }
                  else {
                    $message = ($settings == PATREON_USER_ONLY_PATRONS) ? $this->t('Only patrons may log in via Patreon.') : $this->t('Log on via Patreon is not enabled at present.');
                    $message .= ' ' . $this->t('Please contact an administrator if you feel this is in error.');
                    $this->messenger()->addError($message);
                  }
                }
              }
            }
          }
          catch (\Exception $e) {
            $message = $this->t('The Patreon API returned the following error: :error', [
              ':error' => $e->getMessage(),
            ]);
            $this->logger->error($message);
            $this->messenger()->addError($message);
          }
        }
      }
    }

    return $this->redirect($route_name, $route_params);
  }

}
