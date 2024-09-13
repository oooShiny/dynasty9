<?php

namespace Drupal\patreon;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Patreon\API;
use Patreon\OAuth;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service to connect to the Patreon API.
 *
 * @package Drupal\patreon
 */
class PatreonService implements PatreonServiceInterface {

  use StringTranslationTrait;

  /**
   * Returns a list of valid scopes.
   *
   * @var string[]
   *   An array of scopes.
   */
  private array $scopes = [
    'identity',
    'identity[email]',
    'identity.memberships',
    'campaigns',
    'campaigns.members',
    'campaigns.members[email]',
    'campaigns.members.address',
    'campaigns.posts',
  ];

  /**
   * The Drupal path service.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $path;

  /**
   * Drupal\Component\Serialization\SerializationInterface definition.
   *
   * @var \Drupal\Component\Serialization\SerializationInterface
   */
  protected SerializationInterface $serializationJson;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Config for the service.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $config;

  /**
   * Watchdog logger channel for captcha.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * An entity Type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected EntityTypeManager $entityTypeManager;

  /**
   * The API token for this Patreon Service API connection.
   *
   * @var string
   *    The API token for this Patreon Service API connection.
   */
  private string $token = '';

  /**
   * The API refresh token for this Patreon Service API connection.
   *
   * @var string
   *    The API refresh token for this Patreon Service API connection.
   */
  private string $refreshToken = '';

  /**
   * Bool to capture whether a token refresh has been tried.
   *
   * @var bool
   */
  public bool $refreshTried = FALSE;

  /**
   * The Request Stack Service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $stack;

  /**
   * A state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $stateApi;

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
   */
  public function __construct(CurrentPathStack $path, SerializationInterface $serialization_json, ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $logger, MessengerInterface $messenger, EntityTypeManager $entityTypeManager, RequestStack $stack, StateInterface $stateApi) {
    $this->path = $path;
    $this->serializationJson = $serialization_json;
    $this->configFactory = $configFactory;
    $this->config = $this->configFactory->getEditable('patreon.settings');
    $this->logger = $logger->get('patreon');
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
    $this->stack = $stack;
    $this->stateApi = $stateApi;
  }

  /**
   * Helper to return the current scopes.
   *
   * @inheritDoc
   */
  public function getScopes() {
    return $this->scopes;
  }

  /**
   * Helper to set the current scopes.
   *
   * @param array $scopes
   *   An array of API scopes.
   *
   * @return string[]
   *   The current scopes.
   */
  private function setScopes(array $scopes = []) {
    $this->scopes = $scopes;

    return $this->getScopes();
  }

  /**
   * Function to get the supplied token.
   *
   * @return string
   *   Returns the stored token.
   *
   * @throws \Drupal\patreon\PatreonMissingTokenException
   */
  public function getToken() {
    if ($tokens = $this->getStoredTokens()) {
      if (isset($tokens['access_token'])) {
        return $tokens['access_token'];
      }
    }

    throw new PatreonMissingTokenException('An API token has not been set.');
  }

  /**
   * Function to get the supplied refresh token.
   *
   * @inheritDoc
   */
  public function getRefreshToken() {
    if ($tokens = $this->getStoredTokens()) {
      if (isset($tokens['refresh_token'])) {
        return $tokens['refresh_token'];
      }
    }

    throw new PatreonMissingTokenException('An API Refresh token has not been set.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCallback(): Url {
    return Url::fromRoute('patreon.patreon_controller_oauth_callback', [], ['absolute' => TRUE]);
  }

  /**
   * Authorise a new account on the API.
   *
   * @inheritDoc
   */
  public function authoriseAccount(string $client_id, array $scopes = [], string $return_url = '', bool $redirect = TRUE) {
    $scopes = (empty($scopes)) ? $this->getScopes() : $this->setScopes($scopes);
    $redirect_url = $this->getCallback()->toString();
    $return_url = ($return_url == '') ? $this->path->getPath() : $return_url;

    $url = Url::fromUri($this->getAuthoriseUrl($client_id, $redirect_url, $scopes, $return_url));

    if ($redirect) {
      return new TrustedRedirectResponse($url->toString());
    }
    else {
      return $url;
    }
  }

  /**
   * Returns the URL to authorize an account to.
   *
   * @inheritDoc
   */
  public function getAuthoriseUrl(string $clientId, string $redirectUrl, array $scopes, string $returnUrl = ''): string {
    $url = PATREON_URL . '/oauth2/authorize?response_type=code&client_id=' . $clientId . '&redirect_uri=' . UrlHelper::encodePath($redirectUrl);
    $scope_string = '';

    foreach ($scopes as $scope) {
      $scope_string .= $scope . ' ';
    }

    $scope_string = substr($scope_string, 0, -1);
    $url .= '&scope=' . UrlHelper::encodePath($scope_string);

    if ($returnUrl) {
      $state = $this->serializationJson->encode([
        'final_page' => $returnUrl,
      ]);

      $url .= '&state=' . UrlHelper::encodePath(base64_encode($state));
    }

    return $url;
  }

  /**
   * Helper to get a Url Object from a path.
   *
   * @inheritDoc
   */
  public function decodeState(string $state) {
    $return = FALSE;

    if ($decoded = base64_decode($state)) {
      if ($json = $this->serializationJson->decode($decoded)) {
        if (isset($json['final_page'])) {
          $return = \Drupal::service('path.validator')
            ->getUrlIfValid($json['final_page']);
        }
      }
    }


    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function getOauth(): OAuth {
    $key = $this->config->get('patreon_client_id');
    $secret = $this->config->get('patreon_client_secret');
    return new OAuth($key, $secret);
  }

  /**
   * Converts an API return string into tokens.
   *
   * @inheritDoc
   */
  public function tokensFromCode(string $code) {
    $url = $this->getCallback();

    try {
      $oauth = $this->getOauth();
    }
    catch (\Exception $e) {
      throw new PatreonGeneralException($e->getMessage());
    }

    $tokens = $oauth->get_tokens($code, $url->toString());

    if (array_key_exists('error', $tokens)) {
      $error = (isset($tokens['error_description'])) ? $tokens['error'] . ': ' . $tokens['error_description'] : $tokens['error'];
      if ($tokens['error'] == 'access_denied') {
        throw new PatreonUnauthorizedException($error);
      }
      else {
        throw new PatreonGeneralException($error);
      }
    }

    return $tokens;
  }

  /**
   * {@inheritdoc}
   */
  public function storeTokens($tokens, UserInterface $account = NULL) {
    $this->stateApi->set('patreon.access_token', $tokens['access_token']);
    $this->stateApi->set('patreon.refresh_token', $tokens['refresh_token']);
  }

  /**
   * {@inheritdoc}
   */
  public function getStoredTokens(UserInterface $account = NULL): array {
    return [
      'refresh_token' => $this->stateApi->get('patreon.refresh_token'),
      'access_token' => $this->stateApi->get('patreon.access_token'),
    ];
  }

  /**
   * Helper to get refreshed tokens from the Patreon API.
   *
   * @inheritDoc
   */
  public function getRefreshedTokens($token, $redirect) {
    $client = $this->getOauth();
    $tokens = $client->refresh_token($token, $redirect);

    if (array_key_exists('error', $tokens)) {
      $error = (isset($tokens['error_description'])) ? $tokens['error'] . ': ' . $tokens['error_description'] : $tokens['error'];
      if ($tokens['error'] == 'access_denied' || $tokens['error'] == 'invalid_grant') {
        throw new PatreonUnauthorizedException($error);
      }
      else {
        throw new PatreonGeneralException($error);
      }
    }

    return $tokens;
  }

  /**
   * Helper to get a specified value from a Patreon API return.
   *
   * @inheritDoc
   */
  public function getValueByKey(array $array, array $parents) {
    $nested = new NestedArray();

    return $nested->getValue($array, $parents);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchUser(): ?array {
    return $this->apiFetch('fetch_user');
  }

  /**
   * {@inheritdoc}
   */
  public function fetchCampaign(): ?array {
    return $this->apiFetch('fetch_campaigns');
  }

  /**
   * Helper to fetch Campaign Details.
   *
   * @param string $campaign_id
   *   A Patreon Campaign ID.
   *
   * @return array|null
   *   An array of data from the API or false on error.
   */
  public function fetchCampaignDetails(string $campaign_id): ?array {
    return $this->apiFetch('fetch_campaign_details', [$campaign_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function fetchPagePledges($campaign_id, $page_size, $cursor = NULL): ?array {
    return $this->apiFetch('fetch_page_of_members_from_campaign', [
      $campaign_id,
      $page_size,
      $cursor,
    ]);
  }

  /**
   * Helper to fetch membership details for an id.
   *
   * @param string $member_id
   *   A Patreon membership id.
   *
   * @return array|null
   *   An array of data or NULL on error.
   */
  public function fetchMemberDetails(string $member_id): ?array {
    return $this->apiFetch('fetch_member_details', [$member_id]);
  }

  /**
   * Helper function to query the Patreon API.
   *
   * @param string $function
   *   A valid Patreon API function.
   * @param array $parameters
   *   An array of parameters required for the function call. Defaults to empty.
   *
   * @return null|array
   *   An array of the function callback data, or NULL on error.
   */
  private function apiFetch(string $function, array $parameters = []): ?array {
    $return = NULL;

    try {
      $client = new API($this->getToken());

      $return = NULL;

      if (method_exists($client, $function)) {
        if ($parameters) {
          if (count($parameters) < 3) {
            $api_response = $client->{$function}($parameters[0]);
          }
          else {
            [$campaign_id, $page_size, $cursor] = $parameters;
            $api_response = $client->{$function}($campaign_id, $page_size, $cursor);
          }
        }
        else {
          $api_response = $client->{$function}();
        }

        if (!empty($api_response)) {
          if (is_string($api_response)) {
            $api_response = $this->serializationJson->decode($api_response);
          }
          if ($error = $this->getValueByKey($api_response, ['errors', '0'])) {
            if (isset($error['status']) && $error['status'] == '401') {
              if ($this->refreshTried == FALSE) {
                $this->retry($function, $parameters);
              }
              else {
                throw new PatreonUnauthorizedException('The Patreon API has returned an authorized response.');
              }
            }
            else {
              throw new PatreonGeneralException('Patreon API has returned an unknown response.');
            }
          }
          else {
            $return = $api_response;
          }
        }
        else {
          throw new PatreonGeneralException('Patreon API has returned an unknown response.');
        }
      }
    }
    catch (PatreonMissingTokenException $e) {
      $this->logger->error($this->t('The Patreon API returned the following error: :error', [
        ':error' => $e->getMessage(),
      ]));
      $this->messenger->addError($this->t('A valid API token has not been set. Please visit @link', [
        '@link' => Url::fromRoute('patreon.settings_form'),
      ]));
    }
    catch (PatreonUnauthorizedException $e) {
      if (!$this->refreshTried) {
        $this->retry($function, $parameters);
      }
      else {
        $this->logger->error($this->t('The Patreon API returned the following error: :error', [
          ':error' => $e->getMessage(),
        ]));
        $this->messenger->addError($this->t('Your API token has expired or not been set. Please visit @link', [
          '@link' => Url::fromRoute('patreon.settings_form')->toString(),
        ]));
      }
    }
    catch (PatreonGeneralException $e) {
      $message = $this->t('The Patreon API returned the following error: :error', [
        ':error' => $e->getMessage(),
      ]);
      $this->logger->error($message);
      $this->messenger->addError($message);
    }

    return $return;
  }

  /**
   * Helper to try refreshing tokens and rerunning a apiFetch call.
   *
   * @param string $function
   *   The function call that failed.
   * @param array $parameters
   *   Any parameters for that call.
   *
   * @return array|false
   *   The returned API data or FALSe on error.
   */
  private function retry(string $function, array $parameters) {
    $tokens = $this->getStoredTokens();
    $token = $tokens['refresh_token'];
    $redirect = $this->getCallback();
    $return = FALSE;

    try {
      $this->refreshTried = TRUE;
      $new_tokens = $this->getRefreshedTokens($token, $redirect->toString());
      $this->storeTokens($new_tokens);

      // Retry the function callback.
      $return = $this->apiFetch($function, $parameters);
    }
    catch (PatreonUnauthorizedException $error) {
      $this->logger->error($this->t('The Patreon API returned the following error: :error', [
        ':error' => $error->getMessage(),
      ]));
      $this->messenger->addError($this->t('Your API token has expired or not been set. Please visit @link', [
        '@link' => Url::fromRoute('patreon.settings_form')->toString(),
      ]));
    }
    catch (PatreonGeneralException $error) {
      $message = $this->t('The Patreon API returned the following error: :error', [
        ':error' => $error->getMessage(),
      ]);
      $this->logger->error($message);
      $this->messenger->addError($message);
    }

    return $return;
  }

  /**
   * Helper to get tier data from a membership array.
   *
   * @param array $membership
   *   A return from fetchMembership().
   *
   * @return array
   *   An array of tier data: id => attributes.
   */
  public function getTierData(array $membership): array {
    $return = [];

    if (isset($membership['included'])) {
      foreach ($membership['included'] as $included) {
        if ($included['type'] == 'tier') {

          // The API does not currently return attributes so this will always be
          // empty.
          $return[$included['id']] = $included['attributes'];
        }
      }
    }

    return $return;
  }

  /**
   * Helper to create Drupal roles from Patreon reward types.
   */
  public function createRoles(): array {
    $tokens = $this->getStoredTokens();
    $config_data = [];

    if (isset($tokens['access_token'])) {
      if ($campaigns = $this->fetchCampaign()) {
        $this->storeCampaigns($campaigns);
      }

      $roles = $this->getPatreonRoleNames($campaigns);
      $all = user_role_names();

      foreach ($roles as $label => $patreon_id) {
        $id = strtolower(str_replace(' ', '_', $label));
        if (!in_array($label, $all)) {
          $data = [
            'id' => $id,
            'label' => $label,
          ];

          $role = $this->entityTypeManager->getStorage('user_role')->create($data);
          $role->save();
        }

        $key = ($patreon_id) ?: $id;
        $config_data[$key] = $id;
      }

      $this->stateApi->set('patreon.user_roles', $config_data);
    }

    return $config_data;
  }

  /**
   * Helper to make all campaigns into Drupal roles.
   *
   * @param array|null $campaigns
   *   A return campaign endpoint.
   *
   * @return array
   *   An array of reward titles plus default roles.
   */
  public function getPatreonRoleNames(array $campaigns = NULL): array {
    $roles = [
      'Patreon User' => NULL,
      'Deleted Patreon User' => NULL,
    ];

    if ($campaigns && $campaign_data = $this->getValueByKey($campaigns, ['data'])) {
      foreach ($campaign_data as $campaign) {
        if ($details = $this->fetchCampaignDetails($campaign['id'])) {
          if (isset($details['included'])) {
            foreach ($details['included'] as $reward) {
              if ($reward['type'] == 'tier') {

                // The Patreon API PHP library does not allow us to fetch fields
                // from included data so we can no longer get the tier label.
                // Roles will have to be given the tier id instead.
                $roles[$reward['id'] . ' Patron'] = $reward['id'];
              }
            }
          }
        }
      }
    }

    return $roles;
  }

  /**
   * Helper to store a list of a users campaigns.
   *
   * @param array $campaigns
   *   An array of data from ->fetchCampaigns or empty to recall.
   */
  public function storeCampaigns(array $campaigns = []) {
    if (empty($campaigns)) {
      $campaigns = $this->fetchCampaign();
    }

    $store = [];

    if ($campaigns && $campaign_data = $this->getValueByKey($campaigns, ['data'])) {
      foreach ($campaign_data as $campaign) {
        $store[] = $campaign['id'];
      }
    }

    $this->stateApi->set('patreon.campaigns', $store);
  }

  /**
   * Great a link to sign users up to Patreon.
   *
   * @param int $minimum
   *   The minimum pledge amount.
   * @param bool $log_in
   *   Whether to create an account for the user or not.
   *
   * @return \Drupal\Core\Link
   *   A link object.
   */
  public function getSignupLink(int $minimum = 0, bool $log_in = FALSE): Link {

    $redirect_url = ($log_in) ? $this->getCallback()->toString() : $this->stack->getCurrentRequest()->getSchemeAndHttpHost();
    $state = $this->serializationJson->encode([
      'final_page' => $this->path->getPath(),
    ]);

    $url = Url::fromUri('https://www.patreon.com/oauth2/become-patron', [
      'query' => [
        'response_type' => 'code',
        'min_cents' => $minimum,
        'client_id' => $this->config->get('patreon_client_id'),
        'scope' => UrlHelper::encodePath('identity identity[email] identity.memberships campaigns.members'),
        'redirect_uri' => $redirect_url,
        'state' => UrlHelper::encodePath(base64_encode($state)),
      ],
    ]);

    return Link::fromTextAndUrl($this->t('Become a Patron'), $url);
  }

}
