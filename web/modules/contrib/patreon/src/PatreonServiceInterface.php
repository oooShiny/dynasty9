<?php

namespace Drupal\patreon;

use Drupal\user\UserInterface;

/**
 * Interface for the Patreon Service.
 *
 * @package Drupal\patreon
 */
interface PatreonServiceInterface {

  /**
   * Helper to return the valid absolute Oauth Callback URL.
   *
   * @return \Drupal\Core\Url
   *   The absolute URL of the Oauth Callback route,
   */
  public function getCallback();

  /**
   * Helper to return the current scopes.
   *
   * @return string[]
   *   An array of API scopes.
   */
  public function getScopes();

  /**
   * Returns the URL to authorize an account to.
   *
   * @param string $clientId
   *   The client Id to authorise against.
   * @param string $redirectUrl
   *   The URL to redirect to after authorization.
   * @param array $scopes
   *   An array of scopes to use.
   * @param string $returnUrl
   *   The URL to redirect back to after authentication.
   *
   * @return string
   *   A URL string.
   */
  public function getAuthoriseUrl(string $clientId, string $redirectUrl, array $scopes, string $returnUrl = '');

  /**
   * Authorise a new account on the API.
   *
   * @param string $client_id
   *   The client ID registered with the API.
   * @param array $scopes
   *   An array of scopes to authorise the user with.
   * @param string $return_url
   *   A URL to redirect the user back to.
   * @param bool $redirect
   *   Whether to redirect the user directly to the API URL.
   *
   * @return bool|\Drupal\Core\Routing\TrustedRedirectResponse|\Drupal\Core\Url|null
   *   A redirect response or URL
   */
  public function authoriseAccount(string $client_id, array $scopes = [], string $return_url = '', bool $redirect = TRUE);

  /**
   * Converts an API return string into tokens.
   *
   * @param string $code
   *   A string returned by the API.
   *
   * @return array
   *   An array of tokens.
   *
   * @throws \Drupal\patreon\PatreonGeneralException
   * @throws \Drupal\patreon\PatreonUnauthorizedException
   */
  public function tokensFromCode(string $code);

  /**
   * Store the tokens provided by the Patreon Oauth API.
   *
   * @param array $tokens
   *   An array of tokens returned by the API.
   * @param \Drupal\user\UserInterface|null $account
   *   The account of the user storing the tokens. Optional.
   */
  public function storeTokens(array $tokens, UserInterface $account = NULL);

  /**
   * Load the tokens stored by $this->storeTokens().
   *
   * @param \Drupal\user\UserInterface|null $account
   *   The account of the user requesting the tokens. Optional.
   *
   * @return array
   *   An array of tokens.
   */
  public function getStoredTokens(UserInterface $account = NULL);

  /**
   * Helper to get a Url Object from a path.
   *
   * @param string $state
   *   A coded state string returned by the API.
   *
   * @return \Drupal\Core\Url|false
   *   The URl of FALSE if invalid/not provided.
   */
  public function decodeState(string $state);

  /**
   * Function to get the supplied refresh token.
   *
   * @return string
   *   Returns the value of $this->refreshToken.
   *
   * @throws \Drupal\patreon\PatreonMissingTokenException
   */
  public function getRefreshToken();

  /**
   * Helper to get refreshed tokens from the Patreon API.
   *
   * @param string $token
   *   A Patreon API refresh token.
   * @param string $redirect
   *   A valid Patreon API redirect URL.
   *
   * @return mixed
   *   Returns the tokens return from the API or an error.
   *
   * @throws \Drupal\patreon\PatreonGeneralException
   * @throws \Drupal\patreon\PatreonUnauthorizedException
   */
  public function getRefreshedTokens($token, $redirect);

  /**
   * Helper to get a specified value from a Patreon API return.
   *
   * @param array $array
   *   An array of data.
   * @param array $parents
   *   An array of parent keys of the value, starting with the outermost key.
   *
   * @return mixed|null
   *   The value, or NULL on error.
   *
   * @see NestedArray
   */
  public function getValueByKey(array $array, array $parents);

  /**
   * Helper to return user data from the Patreon API.
   *
   * @return null|array
   *   An array of data from the Patreon API, or NULL on error.
   */
  public function fetchUser();

  /**
   * Helper to return campaign data from the Patreon API.
   *
   * @return null|array
   *   An array of data from the Patreon API, or NULL on error.
   */
  public function fetchCampaign();

  /**
   * Fetch a paged list of pledge data from the Patreon API.
   *
   * @param int $campaign_id
   *   A valid Patreon campaign id.
   * @param int $page_size
   *   The number of items per page.
   * @param null|string $cursor
   *   A cursor character.
   *
   * @return null|array
   *   An array of data from the Patreon API or NULL on error.
   */
  public function fetchPagePledges($campaign_id, $page_size, $cursor = NULL);

}
