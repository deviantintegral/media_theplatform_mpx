<?php

/**
 * @file
 * Helper functions
 */


define('MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE', 'media_theplatform_mpx_account_data_static_cache');
define('MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT', media_theplatform_mpx_variable_get('token_ttl', 3) * 1000);
define('MEDIA_THEPLATFORM_MPX_LOGGING_LEVEL', media_theplatform_mpx_variable_get('watchdog_severity', WATCHDOG_INFO));
define('MEDIA_THEPLATFORM_MPX_MESSAGE_LEVEL', media_theplatform_mpx_variable_get('output_message_watchdog_severity', WATCHDOG_INFO));

/**
 * Retrieves a token for a specified account.
 */
function _media_theplatform_mpx_get_account_data($id = NULL) {

  $account_data = &drupal_static(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);

  if (!isset($account_data)) {
    $account_data = array();
    if (db_table_exists('mpx_accounts')) {
      $account_data = db_query('SELECT * FROM {mpx_accounts}')->fetchAllAssoc('id');
    }
    watchdog('media_theplatform_mpx', 'Retrieving all mpx account data from the database returned the following:
      <br /> <pre>@data<pre>.',
      array('@data' => print_r($account_data, TRUE)), WATCHDOG_DEBUG);
  }

  if (isset($id)) {
    return isset($account_data[ $id ]) ? $account_data[ $id ] : array();
  }

  return $account_data;
}

/**
 * Stores a field value for a specified field and table by specified ID field and value.
 */
function _media_theplatform_mpx_set_field($id, $field_name, $field_value, $table = 'mpx_accounts', $id_field = 'id') {

  if (!$field_name) {
    return FALSE;
  }

  if (in_array($field_name, media_theplatform_mpx_get_encrypted_list())) {
    $field_value = _media_theplatform_mpx_encrypt_value($field_value);
  }

  $result = db_update($table)
    ->fields(array($field_name => $field_value))
    ->condition($id_field, $id, '=')
    ->execute();

  // Clear account data static cache.
  if ($result) {
    watchdog('media_theplatform_mpx', 'Successfully saved field @field_name with value "@field_value" in @table table where @id_field = @id.',
      array(
        '@field_name' => $field_name,
        '@field_value' => $field_value,
        '@table' => $table,
        '@id_field' => $id_field,
        '@id' => $id,
      ),
      WATCHDOG_INFO);
    drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);
  }
  else {
    watchdog('media_theplatform_mpx', 'Failed to save field @field_name with value "@field_value" in @table with @id_field @id.',
      array(
        '@field_name' => $field_name,
        '@field_value' => $field_value,
        '@table' => $table,
        '@id_field' => $id_field,
        '@id' => $id,
      ),
      WATCHDOG_WARNING);
  }

  return $result;
}

/**
 * Retrieves a field value for a specified account.
 */
function _media_theplatform_mpx_get_account_value($account_id, $field_name) {

  $account_data = _media_theplatform_mpx_get_account_data($account_id);

  if (in_array($field_name, media_theplatform_mpx_get_encrypted_list())) {
    return _media_theplatform_mpx_decrypt_value($account_data->{$field_name});
  }

  return isset($account_data->{$field_name}) ? $account_data->{$field_name} : NULL;
}

/**
 * Sets a field value for a specified account.
 */
function _media_theplatform_mpx_set_account_value($account_id, $field_name, $field_value) {

  if (!is_null($field_value) && in_array($field_name, media_theplatform_mpx_get_encrypted_list())) {
    $field_value = _media_theplatform_mpx_encrypt_value($field_value);
  }

  $result = db_update('mpx_accounts')
    ->fields(array($field_name => $field_value))
    ->condition('id', $account_id, '=')
    ->execute();

  return $result;
}

/**
 * Signs into thePlatform and returns TRUE/FALSE on success/failure.
 */
function _media_theplatform_mpx_get_signin_token($username, $password, $idle_timeout = NULL) {

  // IdleTimeout is stored in milliseconds.  Set to 2 weeks.
  $idle_timeout = $idle_timeout ? $idle_timeout : MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT;
  $login_url = "https://identity.auth.theplatform.com/idm/web/Authentication/signIn?schema=1.0&form=json&_idleTimeout=" . $idle_timeout;
  $data = 'username=' . urlencode($username) . '&password=' . urlencode($password);
  $options = array(
    'method' => 'POST',
    'data' => $data,
    'timeout' => 15,
    'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
  );

  $result_data = _media_theplatform_mpx_retrieve_feed_data($login_url, TRUE, $options);

  if (!isset($result_data['signInResponse']['token'])) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve mpx authentication token for user "@username".  "token" field value not found in response from thePlatform.',
      array('@username' => $username), WATCHDOG_ERROR);

    return '';
  }

  watchdog('media_theplatform_mpx', 'Successfully retrieved mpx authentication token for user "@username".',
    array('@username' => $username), WATCHDOG_INFO);

  return $result_data['signInResponse']['token'];
}

/**
 * Signs into thePlatform and sets/returns signIn token variable.  Returns FALSE if fails.
 */
function media_theplatform_mpx_signin($account_id) {

  watchdog('media_theplatform_mpx', 'Signing into mpx account @id.', array('@id' => $account_id), WATCHDOG_NOTICE);

  $account_data = _media_theplatform_mpx_get_account_data($account_id);

  if (empty($account_data)) {
    watchdog('media_theplatform_mpx', 'Signing into mpx account @id FAILED because account data could not be retrieved from the database.',
      array('@id' => $account_id), WATCHDOG_ERROR);

    return FALSE;
  }

  $username = _media_theplatform_mpx_decrypt_value($account_data->username);
  $password = _media_theplatform_mpx_decrypt_value($account_data->password);
  $mpx_token = _media_theplatform_mpx_get_signin_token($username, $password);

  // If valid response, set token variable.
  if ($mpx_token) {
    $token_saved = _media_theplatform_mpx_set_field($account_id, 'token', $mpx_token);
    if ($token_saved) {
      // Store unix timestamp of when this token will idleTimeout.
      $idle_timeout_value = time() + (MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT / 1000);
      _media_theplatform_mpx_set_field($account_id, 'idle_timeout', $idle_timeout_value);
      $account_data = _media_theplatform_mpx_get_account_data($account_id);
      watchdog('media_theplatform_mpx', 'Successfully signed into @account.',
        array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_NOTICE);
    }
    // Reset the account data static cache.
    drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);
  }
  // Else DESTROY the token variable!
  else {
    watchdog('media_theplatform_mpx', 'FAILED TO SIGN INTO @account.',
      array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_ERROR);
    watchdog('media_theplatform_mpx', 'Setting token to 0 for @account.',
      array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_INFO);
    $token_reset = _media_theplatform_mpx_set_account_value($account_id, 'token', NULL);
    if ($token_reset) {
      watchdog('media_theplatform_mpx', 'Successfully set token to NULL for @account.',
        array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_INFO);
    }
    else {
      watchdog('media_theplatform_mpx', 'FAILED TO SET TOKEN to 0 for @account.',
        array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_ERROR);
    }
  }

  return $mpx_token;
}

/**
 * Returns array of all accounts specified thePlatform account.
 */
function media_theplatform_mpx_get_accounts_select($account_id, $username = NULL, $password = NULL, $token = NULL) {

  $for = '';
  if ($account_id) {
    $for = 'account ' . $account_id;
  }
  elseif ($username) {
    $for = 'user "' . $username . '"';
  }
  elseif ($token) {
    $for = 'token "' . $token . '"';
  }

  watchdog('media_theplatform_mpx', 'Retrieving all import accounts for @for.',
    array('@for' => $for), WATCHDOG_NOTICE);

  $token_idle_timeout = MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT;

  if (empty($token) && empty($account_id) && $username && $password) {
    $token = _media_theplatform_mpx_get_signin_token($username, $password, $token_idle_timeout);
    if (!$token) {
      watchdog('media_theplatform_mpx', 'Failed to retrieve all import accounts because username and password are not valid.',
        array(), WATCHDOG_ERROR);

      return array();
    }
  }
  elseif (empty($token) && !empty($account_id)) {
    $account_data = _media_theplatform_mpx_get_account_data($account_id);
    if (empty($account_data)) {
      watchdog('media_theplatform_mpx', 'Failed to retrieve all import accounts.  Account data unavailable for account @id.',
        array('@id' => $account_id), WATCHDOG_ERROR);

      return array();
    }
    $username = _media_theplatform_mpx_decrypt_value($account_data->username);
    $password = _media_theplatform_mpx_decrypt_value($account_data->password);
    $token = _media_theplatform_mpx_get_signin_token($username, $password, $token_idle_timeout);
    if (!$token) {
      watchdog('media_theplatform_mpx', 'Failed to retrieve all import accounts for @account.  Unable to retrieve authentication token.',
        array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_ERROR);

      return array();
    }
  }
  elseif (empty($token)) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve all import accounts because a account ID, token or username and password were not available.',
      array(), WATCHDOG_ERROR);

    return array();
  }

  // Get the list of accounts from thePlatform.
  $url = 'https://access.auth.theplatform.com/data/Account?schema=1.3.0&form=json&byDisabled=false&token=' . $token
    . '&fields=id,title';

  $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

  media_theplatform_mpx_expire_token($token);

  if (empty($result_data['entryCount']) || $result_data['entryCount'] == 0) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve import accounts for @for.  The mpx user provided does not have the necessary administrative privileges.',
      array('@for' => $for), WATCHDOG_ERROR);

    return array();
  }

  $sub_accounts = array();
  $account_ids = array();

  foreach ($result_data['entries'] as $entry) {
    $title = $entry['title'];
    $key = rawurlencode($title);
    $sub_accounts[$key] = $title;
    $account_ids[] = $entry['id'];
  }

  watchdog('media_theplatform_mpx', 'Retrieved @count import accounts for @for.',
    array(
      '@count' => count($sub_accounts),
      '@for' => !empty($account_id) ? 'account ' . $account_id : 'user "' . $username . '"',
    ),
    WATCHDOG_INFO);
  watchdog('media_theplatform_mpx', 'Retrieved the following import accounts for @for:
    <br /> <pre>@sub_accounts</pre>',
    array(
      '@for' => !empty($account_id) ? 'account ' . $account_id : 'user "' . $username . '"',
      '@sub_accounts' => print_r($sub_accounts, TRUE),
    ),
    WATCHDOG_DEBUG);

  $query = db_select('mpx_accounts', 'a')
    ->fields('a', array('import_account'));
  if (!empty($account_id)) {
    $query->condition('id', $account_id, '!=');
  }
  $existing_sub_accounts = $query->execute();
  while ($field = $existing_sub_accounts->fetchField()) {
    unset($sub_accounts[ $field ]);
  }

  // Sort accounts alphabetically.
  natcasesort($sub_accounts);

  return $sub_accounts;
}

/**
 * Requests signin token if the current token's idleTimeout date has passed.
 */
function media_theplatform_mpx_check_token($account_id) {
  // Reset the account data static cache.
  drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);

  $token = _media_theplatform_mpx_get_account_value($account_id, 'token');

  if (!$token) {
    watchdog('media_theplatform_mpx', 'Token check failed for account @id.  Token not set in the mpx_accounts table.  Retrieving and saving new token',
      array('@id' => $account_id), WATCHDOG_INFO);

    // Retrieve, save and return new token.
    return media_theplatform_mpx_signin($account_id);
  }

  // If idleTimeout date has passed, signIn again.
  if (_media_theplatform_mpx_get_account_value($account_id, 'idle_timeout') < time()) {
    watchdog('media_theplatform_mpx', 'Token has expired for account @id - idle_timeout period has been exceeded.  Expiring existing token, then retrieving and saving new token.',
      array('@id' => $account_id), WATCHDOG_INFO);
    // Expire the current token.
    media_theplatform_mpx_expire_token($token, $account_id);

    // Retrieve, save and return new token.
    return media_theplatform_mpx_signin($account_id);
  }

  watchdog('media_theplatform_mpx', 'Token valid for account @id.', array('@id' => $account_id), WATCHDOG_INFO);

  return $token;
}

/**
 * Expires all outstanding authentication tokens.
 */
function _media_theplatform_mpx_expire_all_tokens() {

  foreach (_media_theplatform_mpx_get_account_data() as $account_id => $account_data) {
    $token = _media_theplatform_mpx_decrypt_value($account_data->token);
    if ($token) {
      media_theplatform_mpx_expire_token($token, $account_id);
    }
  }
}

/**
 * Requests expiration of current signin token.
 */
function media_theplatform_mpx_expire_token($token, $account_id = NULL) {

  if ($account_id) {
    _media_theplatform_mpx_set_account_value($account_id, 'token', NULL);
    // Reset the account data static cache.
    drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);
  }

  $url = 'https://identity.auth.theplatform.com/idm/web/Authentication/signOut?schema=1.0&form=json&_token=' . $token;

  $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

  if (empty($result_data)) {
    watchdog('media_theplatform_mpx', 'Expiring mpx token @token FAILED.', array('@token' => $token), WATCHDOG_ERROR);

    return FALSE;
  }

  return TRUE;
}

/**
 * Checks if file is active in its mpx datatable.
 *
 * @param Object $file
 *   A File Object.
 *
 * @return Boolean
 *   TRUE if the file is active, and FALSE if it isn't.
 */
function media_theplatform_mpx_is_file_active($file) {
  $wrapper = file_stream_wrapper_get_instance_by_uri($file->uri);
  $parts = $wrapper->get_parameters();
  if ($parts['mpx_type'] == 'player') {
    $player = media_theplatform_mpx_get_mpx_player_by_fid($file->fid);
    return $player['status'];
  }
  elseif ($parts['mpx_type'] == 'video') {
    $video = media_theplatform_mpx_get_mpx_video_by_field('guid', $parts['mpx_id']);
    return $video['status'];
  }
}

/************************ parsing strings *********************************/

/**
 * Return array of strings of all id's in thePlayer HTML/CSS.
 */
function media_theplatform_mpx_get_tp_ids() {
  return array(
    'categories',
    'header',
    'info',
    'player',
    'releases',
    'search',
    'tpReleaseModel1',
  );
}

/**
 * Finds all #id's in the HTML and appends with $new_id to each #id.
 *
 * @param String $html
 *   String of HTML that needs HTML id's altered.
 * @param String $new_id
 *   The string pattern we need to append to each id in $html.
 *
 * @return String
 *   $html with all id's as #mpx.$new_id, with tp:scopes variables.
 */
function media_theplatform_mpx_replace_html_ids($html, $new_id) {
  // Append new_id to all div id's.
  foreach (media_theplatform_mpx_get_tp_ids() as $tp_id) {
    $html = media_theplatform_mpx_append_html_id($tp_id, $new_id, $html);
  }
  return $html;
}

/**
 * Finds given $div_id in HTML, appends its id with $append.
 *
 * Also adds tp:scopes variable for tpPdk.js.
 *
 * @param String $div_id
 *   The div id we need to append a string to.
 * @param String $append
 *   The string we need to append.
 * @param String $html
 *   The string we need to search to find $find.
 *
 * @return String
 *   $html with $find replaced by $find.$append.
 */
function media_theplatform_mpx_append_html_id($div_id, $append, $html) {
  $find = 'id="' . $div_id . '"';
  // Replace with 'div_id-append'.
  $replace = 'id="' . $div_id . '-' . $append . '" tp:scopes="scope-' . $append . '"';
  return str_replace($find, $replace, $html);
}

/**
 * Finds all #id's in the CSS and appends with $new_id to each #id.
 *
 * @param String $html
 *   String of HTML that needs css id's altered.
 * @param String $new_id
 *   The string pattern we need to append to each #id in $html.
 *
 * @return String
 *   $html with all id's prepended with #mpx-$new_id selector
 */
function media_theplatform_mpx_replace_css_ids($html, $new_id) {

  // Append $new_id to each id selector.
  foreach (media_theplatform_mpx_get_tp_ids() as $tp_id) {
    $html = media_theplatform_mpx_append_string('#' . $tp_id, '-' . $new_id, $html);
  }
  // Replace body selector with #mpx_new_id
  $mpx_id = '#mpx-' . $new_id;
  $html = str_replace('body', $mpx_id, $html);
  // Get rid of tabs, newlines to make it easier to find all classes.
  $html = str_replace(array("\r\n", "\r", "\n", "\t"), '', $html);
  // Add #mpx_id as parent selector of all classes.
  $html = str_replace("}", "}\n " . $mpx_id . " ", $html);
  // Clean up the last }.
  $remove = strlen($mpx_id) + 1;
  $html = substr($html, 0, -$remove);
  // If any commas in the selectors, add mpx to each item after the comma.
  $html = str_replace(",", ", " . $mpx_id . " ", $html);
  return $html;
}

/**
 * Appends a pattern to another string pattern for given $html.
 *
 * @param String $find
 *   The string pattern we need to append a string to.
 * @param String $append
 *   The string we need to append.
 * @param String $html
 *   The string we need to search to find $find.
 *
 * @return String
 *   $html with $find replaced by $find.$append
 */
function media_theplatform_mpx_append_string($find, $append, $html) {
  $replace = $find . $append;
  return str_replace($find, $replace, $html);
}

/**
 * Alters mpxPlayer HTML to render a mpx_video by its Guid.
 *
 * Adds 'byGuid=$guid' to the tp:feedsserviceurl in div#tpReleaseModel.
 *
 * @param String $guid
 *   The Guid string of the mpx_video we want to render.
 * @param String $html
 *   String of mpxPlayer HTML to be used to render the mpx_video.
 *
 * @return String
 *   mpxPlayer HTML for the mpx_video.
 */
function media_theplatform_mpx_add_guid_to_html($guid, $html) {
  $tag = 'tp:feedsServiceURL="';
  // Get the current value for this tag.
  $default_url_value = media_theplatform_mpx_extract_string($tag, '"', $html);
  // Append the byGuid parameter to the current value.
  $new_url_value = $default_url_value . '?byGuid=' . $guid;
  $str_old = $tag . $default_url_value . '"';
  $str_new = $tag . $new_url_value . '"';
  return str_replace($str_old, $str_new, $html);
}

/**
 * Returns the string between two given strings.
 *
 * @param String $start_str
 *   The string pattern that begins what we want to extract.
 * @param String $end_str
 *   The string pattern that ends what we want to extract.
 * @param String $input
 *   The string we need to search.
 *
 * @return String
 *   The string between $start_str and $end_str.
 */
function media_theplatform_mpx_extract_string($start_str, $end_str, $input) {
  $pos_start = strpos($input, $start_str) + strlen($start_str);
  $pos_end = strpos($input, $end_str, $pos_start);
  $result = substr($input, $pos_start, $pos_end - $pos_start);
  return $result;
}

/**
 * Returns the File ID's from given Media WYSIWYG markup.
 *
 * @param String $text
 *   String of WYSIWYG markup.
 *
 * @return Array
 *   The File fid's that the markup contains.
 */
function media_theplatform_mpx_extract_fids($text) {
  $pattern = '/\"fid\":\"(.*?)\"/';
  preg_match_all($pattern, $text, $results);
  return $results[1];
}

/**
 * Returns array of URLs of any external CSS files referenced in $text.
 */
function media_theplatform_mpx_extract_all_css_links($text) {
  $pattern = '/\<link rel\=\"stylesheet\" type\=\"text\/css\" media\=\"screen\" href\=\"(.*?)\" \/\>/';
  preg_match_all($pattern, $text, $results);
  return $results[1];
}

/**
 * Returns array of css data for any <style> tags in $text.
 */
function media_theplatform_mpx_extract_all_css_inline($text) {
  $pattern = '/<style.*>(.*)<\/style>/sU';
  preg_match_all($pattern, $text, $results);
  return $results[1];
}

/**
 * Returns array of css data for any <style> tags in $text.
 */
function media_theplatform_mpx_extract_all_meta_tags($text) {
  $pattern = '/\<meta [^>]+\>/sU';
  preg_match_all($pattern, $text, $results);
  return $results;
}

/**
 * Return array of URLs of any external JS files referenced in $text.
 */
function media_theplatform_mpx_extract_all_js_links($text) {
  $pattern = '/\<script type\=\"text\/javascript\" src\=\"(.*?)\"/';
  preg_match_all($pattern, $text, $results);
  $js_files = $results[1];
  return $js_files;
}

/**
 * Returns array of any inline JS data for all <script> tags in $text.
 */
function media_theplatform_mpx_extract_all_js_inline($text) {
  $pattern = '/<script type\=\"text\/javascript\">(.*)<\/script>/sU';
  preg_match_all($pattern, $text, $results);
  return $results[1];
}


/**
 * Returns string of CSS by requesting data from given stylesheet $href.
 */
function media_theplatform_mpx_get_external_css($href) {
  // Grab its CSS.
  $css = _media_theplatform_mpx_retrieve_feed_data($href, FALSE);

  // If this is PDK stylesheet, change relative image paths to absolute.
  $parts = explode('/', $href);
  if ($parts[2] == 'pdk.theplatform.com') {
    // Remove filename.
    array_pop($parts);
    // Store filepath.
    $css_path = implode('/', $parts) . '/';
    // Replace all relative images with absolute path to skin_url.
    $css = str_replace("url('", "url('" . $css_path, $css);
  }

  return $css;
}
