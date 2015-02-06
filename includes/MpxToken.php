<?php

/**
 * Class MpxToken
 *
 * @todo How can we respect the token lifetime and idle_timeout values separately?
 */
class MpxToken {

  /**
   * The account username linked to the token.
   *
   * @var string
   */
  public $username;

  /**
   * The token string.
   *
   * @var string
   */
  public $value;

  /**
   * The UNIX timestamp of when the token expires.
   *
   * @var int
   */
  public $expire;

  /**
   * Construct an MPX token object.
   *
   * @param string $username
   *   The account username linked to the token.
   * @param string $value
   *   The token string.
   * @param int $expire
   *   The UNIX timestamp of when the token expires.
   */
  public function __construct($username, $value, $expire) {
    $this->username = $username;
    $this->value = $value;
    $this->expire = $expire;
  }

  /**
   * Load a token from the cache.
   *
   * In most cases, using MpxToken::acquire() is recommended since this may
   * return an expired token object.
   *
   * @param object $account
   *   The mpx account object.
   *
   * @return MpxToken|bool
   *   The token object if available, otherwise FALSE if no token was available.
   */
  public static function load($account) {
    $tokens = &drupal_static('media_theplatform_mpx_tokens', array());
    $cid = 'token:' . $account->username;

    if (!isset($tokens[$cid])) {
      $tokens[$cid] = FALSE;
      if ($cache = cache_get($cid, 'cache_mpx')) {
        /** @var object $cache */
        $tokens[$cid] = new static($account->username, $cache->data, $cache->expire);
      }
    }

    return $tokens[$cid];
  }

  /**
   * Save the token to the cache.
   */
  public function save() {
    $tokens = &drupal_static('media_theplatform_mpx_tokens', array());
    $cid = 'token:' . $this->username;
    $tokens[$cid] = $this;
    cache_set($cid, $this->value, 'cache_mpx', $this->expire);
  }

  /**
   * Delete the token from the cache.
   */
  public function delete() {
    $tokens = &drupal_static('media_theplatform_mpx_tokens', array());
    $cid = 'token:' . $this->username;
    $tokens[$cid] = FALSE;
    cache_clear_all($cid, 'cache_mpx');

    // If the token is still valid, expire it using the API.
    if ($this->isValid()) {
      $this->expire();
    }
  }

  /**
   * Checks if a token is valid.
   *
   * @param int $duration
   *   The number of seconds for which the token should be valid. Otherwise
   *   this will just check if the token is still valid for the current time.
   *
   * @return bool
   *   TRUE if the token is valid, or FALSE otherwise.
   */
  public function isValid($duration = NULL) {
    return $this->value && $this->expire > (time() + $duration);
  }

  /**
   * Get a current authentication token for an account.
   *
   * @param object $account
   *   The mpx account object.
   * @param int $duration
   *   The number of seconds for which the token should be valid. Default is 60
   *   seconds.
   * @param bool $force
   *   Set to TRUE if a fresh authentication token should always be fetched.
   *
   * @return MpxToken
   *   A valid MPX token object.
   *
   * @throws Exception
   */
  public static function acquire($account, $duration = 60, $force = FALSE) {
    $token = static::load($account);

    if ($force || !$token || !$token->isValid($duration)) {
      // Delete the token from the cache first in case there is a failure in
      // MpxToken::fetch() below.
      if ($token) {
        $token->delete();
      }

      // @todo Should we pass $duration through to MpxToken::fetch()?
      $token = static::fetch($account->username, $account->password);
      // @todo Validate if the new token also valid for $duration.
      $token->save();
    }

    return $token;
  }

  /**
   * Fetch a fresh authentication token using thePlatform API.
   *
   * In most cases, using MpxToken::acquire() is recommended since this does
   * not save the token to the cache.
   *
   * @param string $username
   *   The mpx account username.
   * @param string $password
   *   The mpx account password.
   * @param int $duration
   *   The number of seconds for which the token should be valid.
   *
   * @return MpxToken
   *   The token object if a token was fetched, or FALSE otherwise.
   *
   * @throws Exception
   */
  public static function fetch($username, $password, $duration = NULL) {
    if (!isset($duration)) {
      $duration = variable_get('media_theplatform_mpx__token_ttl', 3);
    }

    $url = url("https://identity.auth.theplatform.com/idm/web/Authentication/signIn", array('query' => array(
      'schema' => '1.0',
      'form' => 'json',
      '_idleTimeout' => $duration * 1000,
    )));
    $options = array(
      'method' => 'POST',
      'data' => http_build_query(array(
        'username' => $username,
        'password' => $password,
      )),
      'timeout' => 15,
      'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
    );
    $time = time();
    $result_data = _media_theplatform_mpx_retrieve_feed_data($url, TRUE, $options);
    if (!empty($result_data['signInResponse']['token'])) {
      $token = new MpxToken(
        $username,
        $result_data['signInResponse']['token'],
        $time + (min($result_data['signInResponse']['duration'], $result_data['signInResponse']['idleTimeout']) / 1000)
      );
      watchdog('media_theplatform_mpx', 'Fetched new mpx token %token for @username that expires on @date.', array('@username' => $username, '%token' => $token->value, '@date' => format_date($token->expire)), WATCHDOG_INFO);
      return $token;
    }
    else {
      throw new Exception("Failed to fetch new token for {$username}");
    }
  }

  /**
   * Release an account's token if it has one.
   *
   * @param object $account
   *   The mpx account object.
   */
  public static function release($account) {
    if ($token = static::load($account)) {
      $token->delete();
    }
  }

  /**
   * Expire an authentication token using thePlatform API.
   *
   * In most cases, using MpxToken::release() is recommended instead. This
   * function only interacts with the thePlatform API and does not delete the
   * token from the cache.
   *
   * @throws Exception
   */
  public function expire() {
    // Expire the token using the API.
    $url = url("https://identity.auth.theplatform.com/idm/web/Authentication/signOut", array('query' => array(
      'schema' => '1.0',
      'form' => 'json',
      '_token' => $this->value,
    )));
    $result_data = _media_theplatform_mpx_retrieve_feed_data($url);
    if (!empty($result_data)) {
      $this->value = NULL;
      $this->expire = NULL;
      watchdog('media_theplatform_mpx', 'Expired mpx authentication token %token for @account.', array('%token' => $this->value, '@account' => $this->username), WATCHDOG_DEBUG);
    }
    else {
      throw new Exception("Failed to expire mpx authenitcation token {$this->value} for {$this->username}");
    }
  }

  /**
   * @return string
   */
  public function __toString() {
    return $this->value;
  }
}
