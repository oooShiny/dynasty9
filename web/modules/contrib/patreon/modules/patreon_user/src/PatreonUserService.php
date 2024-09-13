<?php

namespace Drupal\patreon_user;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Password\DefaultPasswordGenerator;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\patreon\PatreonService;
use Drupal\user\UserInterface;
use Drupal\Core\Url;
use Drupal\patreon\PatreonGeneralException;
use Drupal\Component\Utility\Xss;
use Patreon\OAuth;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class extending the PatreonAPI service with user specific functions.
 *
 * @package Drupal\patreon_user
 */
class PatreonUserService extends PatreonService {

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * An entity Type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected EntityTypeManager $entityTypeManager;

  /**
   * A module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected ModuleHandler $handler;

  /**
   * Password Generator service.
   *
   * @var \Drupal\Core\Password\DefaultPasswordGenerator
   *   Password Generator service.
   */
  protected DefaultPasswordGenerator $passwordGenerator;

  /**
   * A token for use with the API.
   *
   * @var string
   *   An API token,
   */
  protected string $token = '';

  /**
   * The API refresh token for this Patreon Service API connection.
   *
   * @var string
   *    The API refresh token for this Patreon Service API connection.
   */
  private string $refreshToken = '';

  /**
   * Constructs a ParagraphsTypeIconUuidLookup instance.
   *
   * @param \Drupal\Core\Path\CurrentPathStack $path
   *   The Drupal Path service.
   * @param \Drupal\Component\Serialization\SerializationInterface $serialization_json
   *   A Drupal JSON serialization service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   A Drupal Config Factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   A logger channel.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   An Entity Type Manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $stack
   *   The request stack service.
   * @param \Drupal\Core\State\StateInterface $stateApi
   *   A state service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param \Drupal\Core\Extension\ModuleHandler $handler
   *   A Module Handler.
   * @param \Drupal\Core\Password\DefaultPasswordGenerator $password_generator
   *   Password Generator service.
   */
  public function __construct(CurrentPathStack $path, SerializationInterface $serialization_json, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $logger, MessengerInterface $messenger, EntityTypeManager $entityTypeManager, RequestStack $stack, StateInterface $stateApi, AccountInterface $account, ModuleHandler $handler, DefaultPasswordGenerator $password_generator) {
    parent::__construct($path, $serialization_json, $configFactory, $logger, $messenger, $entityTypeManager, $stack, $stateApi);

    $this->config = $this->configFactory->getEditable('patreon_user.settings');
    $this->currentUser = $account;
    $this->handler = $handler;
    $this->passwordGenerator = $password_generator;
  }

  /**
   * {@inheritdoc}
   */
  public function getOauth(): OAuth {
    $key = $this->configFactory->get('patreon.settings')->get('patreon_client_id');
    $secret = $this->configFactory->get('patreon.settings')->get('patreon_client_secret');
    return new OAuth($key, $secret);
  }

  /**
   * A helper to store a token against the service.
   *
   * @param string $token
   *   A valid API token.
   */
  public function setToken(string $token) {
    $this->token = $token;
  }

  /**
   * Helper to store a fresh token against the service.
   *
   * @param string $token
   *   The refresh token to store.
   */
  public function setRefresh(string $token) {
    $this->refreshToken = $token;
  }

  /**
   * Returns the redirect path from settings.
   *
   * @return array|mixed|null
   *   The return of the config get call.
   */
  public function getReturnPath() {
    return $this->config->get('patreon_user_redirect_path');
  }

  /**
   * {@inheritdoc}
   */
  public function getCallback(): Url {
    return Url::fromRoute('patreon_user.patreon_user_controller_oauth', [], ['absolute' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function storeTokens($tokens, UserInterface $account = NULL) {
    if (!$account) {
      $current = $this->currentUser;
      $account = $this->entityTypeManager->getStorage('user')->load($current->id());
    }

    if ($account->id() > 0) {
      $account->set('user_patreon_token', $tokens['access_token']);
      $account->set('user_patreon_refresh_token', $tokens['refresh_token']);
      $account->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStoredTokens(UserInterface $account = NULL): array {
    $return = [];

    if ($account && $account->id() > 0) {
      $return['refresh_token'] = $account->get('user_patreon_refresh_token')->getString();
      $return['access_token'] = $account->get('user_patreon_token')->getString();
    }
    else {

      // In cases where users are logging in, we don't have a Drupal account to
      // link them to but do have an API access token. This should only occur
      // after a user has been directed to the Oauth endpoint on Patreon so we
      // will not require a refresh token.
      $return['access_token'] = $this->token;
      $return['refresh_token'] = $this->refreshToken;
    }

    return $return;
  }

  /**
   * Helper to check if a Patreon user is a patron of the client.
   *
   * @param array $patreon_return
   *   Results array from the user endpoint.
   *
   * @return bool
   *   TRUE is user's pledges match creator id. Defaults to FALSE.
   */
  public function isPatron(array $patreon_return): bool {
    $return = FALSE;

    if ($creator_campaigns = $this->stateApi->get('patreon.campaigns')) {
      if ($memberships = $this->getValueByKey($patreon_return, [
        'data',
        'relationships',
        'memberships',
        'data',
      ])) {
        $key = 0;

        while ($return == FALSE && array_key_exists($key, $memberships)) {
          if ($member_data = $this->fetchMemberDetails($memberships[$key]['id'])) {
            if ($campaigns = $this->getValueByKey($member_data, [
              'data',
              'relationships',
              'campaign',
              'data',
            ])) {
              if (in_array($campaigns['id'], $creator_campaigns)) {
                $return = TRUE;
              }
            }
          }

          $key++;
        }
      }
    }

    return $return;
  }

  /**
   * Helper to check whether current Patreon user is allowed to log in.
   *
   * @param array $user_return
   *   Results array from the user endpoint.
   *
   * @return bool
   *   TRUE if user meets current Patreon settings restrictions on log in.
   */
  public function canLogin(array $user_return): bool {
    $return = FALSE;

    if ($settings = $this->config->get('patreon_user_registration')) {
      if ($settings != PATREON_USER_NO_LOGIN) {
        if ($settings == PATREON_USER_ONLY_PATRONS) {
          if ($this->isPatron($user_return)) {
            $return = TRUE;
          }
        }
        else {
          $return = TRUE;
        }
      }
    }

    return $return;
  }

  /**
   * Helper to fetch an existing user or create a new one from Patreon account.
   *
   * @param array $patreon_user
   *   Results array from the user endpoint.
   *
   * @return bool|\Drupal\Core\Entity\EntityInterface
   *   A Drupal user object, or FALSE on error.
   *
   * @throws \Drupal\patreon_user\PatreonUserUserException
   */
  public function getUser(array $patreon_user) {
    $return = FALSE;

    if ($patreon_id = $this->getValueByKey($patreon_user, ['data', 'id'])) {
      try {
        if ($account = $this->getUserFromId($patreon_id)) {
          $return = $account;
        }
        else {
          $return = $this->createUserFromReturn($patreon_user);
        }
      }
      catch (\Exception $e) {

        // Pass the Exception up to the next level.
        throw new PatreonUserUserException($e->getMessage());
      }
    }

    return $return;
  }

  /**
   * Returns a Drupal user account linked to a Patreon account id.
   *
   * @param int $patreon_id
   *   A valid patreon account id.
   *
   * @return \Drupal\user\UserInterface|bool
   *   A loaded user or FALSE on error.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\patreon\PatreonGeneralException
   */
  public function getUserFromId(int $patreon_id) {
    $return = FALSE;
    $result = $this->entityTypeManager->getStorage('user')->getQuery()
      ->condition('user_patreon_id', $patreon_id)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($result)) {
      if (count($result) > 1) {
        throw new PatreonGeneralException('Multiple users linked to the Patreon account :id', [':id' => $patreon_id]);
      }
      elseif ($account = $this->entityTypeManager->getStorage('user')->load(key($result))) {
        if ($account->id() == 1) {
          $this->logger
            ->notice($this->t('Patreon user :id linked to User 1. This could cause security issues.', [':id' => $patreon_id]));
        }
        /** @var \Drupal\user\UserInterface $return */
        $return = $account;
      }
    }

    return $return;
  }

  /**
   * Creates a Drupal user account from Patreon API data.
   *
   * @param array $data
   *   Results array from the user endpoint.
   *
   * @return bool|\Drupal\user\UserInterface
   *   A Drupal user object, or FALSE on error.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\patreon_user\PatreonUserUserException
   */
  public function createUserFromReturn(array $data) {
    $return = FALSE;
    if ($patreon_id = $this->getValueByKey($data, ['data', 'id'])) {

      // We need an email address, or we can't continue.
      if ($mail = $this->getValueByKey($data, ['data', 'attributes', 'email'])) {

        // If the user mail exists, it must be the same user.
        if ($existing_mail = $this->entityTypeManager->getStorage('user')->loadByProperties([
          'mail' => $mail,
        ])) {

          /** @var \Drupal\User\UserInterface $return */
          $return = reset($existing_mail);
          unset($existing_mail);
        }
        else {

          /** @var \Drupal\User\UserInterface $return */
          $return = $this->entityTypeManager->getStorage('user')->create([
            'mail' => $mail,
          ]);
          $return->setPassword($this->passwordGenerator->generate(20));
          $return->enforceIsNew();
          $return->activate();
        }

        // But if the name exists, it could be someone else.
        if ($name = Xss::filter($this->getValueByKey($data, [
          'data',
          'attributes',
          'full_name',
        ]))) {
          $name = $this->getUniqueUserName($name, $patreon_id);
          $return->setUsername($name);
          $alter = ['#user' => &$return, '#patreon_data' => $data];

          // Allow other modules to add field data.
          $this->handler->alter('patreon_user_create_user', $alter);

          // Add the Patreon ID.
          $return->set('user_patreon_id', Xss::filter($patreon_id));
          $this->assignRoles($return, $data);
        }
      }
      else {
        throw new PatreonUserUserException('No Patreon Email address in provided data array.');
      }
    }
    else {
      throw new PatreonUserUserException('No Patreon ID in provided data array. Please check your scopes.');
    }

    if ($return) {
      try {
        $return->save();
      }
      catch (\Exception $e) {
        throw new PatreonUserUserException($e->getMessage());
      }
    }
    else {
      throw new PatreonUserUserException('Error creating user.');
    }

    return $return;
  }

  /**
   * Assign the patreon user or deleted patreon user roles based on status.
   *
   * @param \Drupal\User\UserInterface $account
   *   A Drupal user account to update.
   * @param array $patreon_user
   *   Results array from the user endpoint.
   */
  public function assignRoles(UserInterface &$account, array $patreon_user) {
    $deleted = $this->isDeletedUser($patreon_user, $account->getAccountName());
    $patreon_user_roles = $this->stateApi->get('patreon.user_roles');

    if (!$patreon_user_roles) {
      $this->createRoles();
      $patreon_user_roles = $this->stateApi->get('patreon.user_roles');
    }

    if ($deleted) {
      $account->addRole('deleted_patreon_user');
    }
    else {
      $account->addRole('patreon_user');
    }

    foreach ($this->getPatronPledges($patreon_user) as $id => $pledge) {
      if ($pledge['patron_status'] == 'active_patron') {
        if ($membership = $this->fetchMemberDetails($id)) {
          if ($tier = $this->getTierData($membership)) {
            $key = key($tier);

            if (array_key_exists($key, $patreon_user_roles)) {
              $account->addRole($patreon_user_roles[$key]);
            }
          }
        }
      }
    }
  }

  /**
   * Helper to get a user's pledges.
   *
   * @param array $patreon_return
   *   Results array from the user endpoint.
   *
   * @return array
   *   An array of all pledges.
   */
  public function getPatronPledges(array $patreon_return): array {
    $return = [];

    if ($pledges = $this->getValueByKey($patreon_return, [
      'included',
    ])) {
      foreach ($pledges as $pledge) {
        if (isset($pledge['type']) && $pledge['type'] == 'member') {
          $return[$pledge['id']] = $pledge['attributes'];
        }
      }
    }

    return $return;
  }

  /**
   * Helper to find if a Patreon User has been deleted or blocked.
   *
   * @param array $patreon_account
   *   Results array from the user endpoint.
   * @param string $drupal_account_name
   *   A valid Drupal user account name.
   *
   * @return bool
   *   Returns TRUE if any value
   */
  public function isDeletedUser(array $patreon_account, string $drupal_account_name): bool {
    return $this->getValueByKey($patreon_account, [
      'data',
      'attributes',
      'is_deleted',
    ]) == TRUE ||
    $this->getValueByKey($patreon_account, [
      'data',
      'attributes',
      'is_nuked',
    ]) == TRUE ||
    $this->getValueByKey($patreon_account, [
      'data',
      'attributes',
      'is_suspended',
    ]) == TRUE ||
    user_is_blocked($drupal_account_name) == TRUE;
  }

  /**
   * Helper to make a username unique if it exists.
   *
   * @param string $name
   *   A Patreon user's full name.
   * @param string $patreon_id
   *   A Patreon user's patreon id.
   *
   * @return string
   *   A de-duped username if required. Defaults to provided.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getUniqueUserName(string $name, string $patreon_id): string {
    if ($this->entityTypeManager->getStorage('user')->loadByProperties([
      'name' => $name,
    ])) {
      $name .= '_' . $patreon_id;
    }

    // By rights, if the combination of Patreon Fullname and Patreon ID already
    // exists as a username, it must have been because of this function. But to
    // reach this point, we have already failed to find the user by their email
    // address so we will deduplicate the user name to be sure we do not expose
    // private data to the wrong people.
    if ($this->entityTypeManager->getStorage('user')
      ->loadByProperties([
        'name' => $name,
      ])) {
      $key = 0;

      while ($this->entityTypeManager->getStorage('user')
        ->loadByProperties([
          'name' => $name . '_' . $key,
        ])) {
        $key++;
      }

      $name = $name . '_' . $key;

    }

    return $name;
  }

}
