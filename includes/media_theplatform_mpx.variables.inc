<?php
/**
 * @file
 * media_theplatform_mpx/includes/media_theplatform_mpx.variables.inc
 *
 * Define Media:mpx variables.
 */

use Zend\Crypt\BlockCipher;
use Zend\Crypt\Symmetric\Mcrypt;

/**
 * This is the variable namespace, automatically prepended to module variables.
 */
define('media_theplatform_mpx_NAMESPACE', 'media_theplatform_mpx__');


/**
 * Returns a truly! encrypted value.
 */
function _media_theplatform_mpx_encrypt_value($value) {

  if (empty($value)) {
    return NULL;
  }

  if (!extension_loaded('mcrypt')) {
    return base64_encode($value);
  }

  global $drupal_hash_salt;
  $key = md5($drupal_hash_salt);

  $library = libraries_load('zend_crypt');
  $key = md5($drupal_hash_salt);

  // If library doesn't exist, use a less secure means of encryption/decryption.
  if (!$library['loaded']) {
    $block = mcrypt_get_block_size('des', 'ecb');
    $pad = $block - (strlen($str) % $block);
    $str .= str_repeat(chr($pad), $pad);
    return mcrypt_encrypt(MCRYPT_DES, $key, $str, MCRYPT_MODE_ECB);
  }

  $blockCipher = BlockCipher::factory('mcrypt', array('algo' => 'aes'));
  $blockCipher->setKey($key);
  return $blockCipher->encrypt($value);
}

/**
 * Returns a decrypted value.
 */
function _media_theplatform_mpx_decrypt_value($value) {

  if (empty($value)) {
    return NULL;
  }

  if (!extension_loaded('mcrypt')) {
    return base64_decode($value);
  }

  global $drupal_hash_salt;

  $library = libraries_load('zend_crypt');
  $key = md5($drupal_hash_salt);

  // If library doesn't exist, use a less secure means of encryption/decryption.
  if (!$library['loaded']) {
    $str = mcrypt_decrypt(MCRYPT_DES, $key, $str, MCRYPT_MODE_ECB);
    $block = mcrypt_get_block_size('des', 'ecb');
    $pad = ord($str[($len = strlen($str)) - 1]);
    return substr($str, 0, strlen($str) - $pad);
  }

  $blockCipher = BlockCipher::factory('mcrypt', array('algo' => 'aes'));
  $blockCipher->setKey($key);
  return $blockCipher->decrypt($value);
}

function media_theplatform_mpx_get_encrypted_list() {
  return array('password', 'token', 'username');
}

/**
 * Wrapper for variable_get() using the Media: mpx variable registry.
 *
 * @see media_theplatform_mpx_variable_set()
 * @see media_theplatform_mpx_variable_del()
 * @see media_theplatform_mpx_variable_default()
 */
function media_theplatform_mpx_variable_get($name, $default = NULL) {
  if (!isset($default)) {
    $default = media_theplatform_mpx_variable_default($name);
  }
  // Namespace all variables.
  $variable_name = media_theplatform_mpx_NAMESPACE . $name;
  if (in_array($name, media_theplatform_mpx_get_encrypted_list())) {
    return unserialize(_media_theplatform_mpx_decrypt_value(variable_get($variable_name, $default)));
  }
  return variable_get($variable_name, $default);
}

/**
 * Wrapper for variable_set() using the Media: mpx variable registry.
 *
 * @see media_theplatform_mpx_variable_get()
 * @see media_theplatform_mpx_variable_del()
 * @see media_theplatform_mpx_variable_default()
 */
function media_theplatform_mpx_variable_set($name, $value) {
  $variable_name = media_theplatform_mpx_NAMESPACE . $name;
  if (in_array($name, media_theplatform_mpx_get_encrypted_list())) {
    $value = _media_theplatform_mpx_encrypt_value(serialize($value));
  }

  watchdog('media_theplatform_mpx', 'Setting system variable @variable to value "@value".',
    array(
      '@variable' => $variable_name,
      '@value' => $value,
    ),
    WATCHDOG_DEBUG);

  return variable_set($variable_name, $value);
}

/**
 * Wrapper for variable_del() using the Media: mpx variable registry.
 *
 * @see media_theplatform_mpx_variable_get()
 * @see media_theplatform_mpx_variable_set()
 * @see media_theplatform_mpx_variable_default()
 */
function media_theplatform_mpx_variable_del($name) {
  $variable_name = media_theplatform_mpx_NAMESPACE . $name;
  variable_del($variable_name);
}

/**
 * The default variables within the Media: ThePlatform namespace.
 *
 * @see media_theplatform_mpx_variable_get()
 * @see media_theplatform_mpx_variable_set()
 * @see media_theplatform_mpx_variable_del()
 */
function media_theplatform_mpx_variable_default($name = NULL) {
  static $defaults;

  if (!isset($defaults)) {
    $defaults = array(
      'account_pid' => NULL,
      'account_id' => NULL,
      'cron_players' => 1,
      'cron_videos' => 1,
      'date_idletimeout' => NULL,
      'default_player_fid' => NULL,
      'import_account' => NULL,
      'last_notification' => NULL,
      'password' => NULL,
      'token' => NULL,
      'username' => NULL,
    );
  }

  if (!isset($name)) {
    return $defaults;
  }

  if (isset($defaults[$name])) {
    return $defaults[$name];
  }
}

/**
 * Return the fully namespace variable name.
 *
 * @param string $name
 *   The variable name to retrieve the namespaced name.
 *
 * @return string
 *   The fully namespace variable name, prepended with
 *   media_theplatform_mpx_NAMESPACE.
 */
function media_theplatform_mpx_variable_name($name) {
  return media_theplatform_mpx_NAMESPACE . $name;
}
