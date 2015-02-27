<?php

/**
 * @file
 * Helper functions
 */


define('MEDIA_THEPLATFORM_MPX_LOGGING_LEVEL', variable_get('media_theplatform_mpx__watchdog_severity', WATCHDOG_INFO));
define('MEDIA_THEPLATFORM_MPX_MESSAGE_LEVEL', variable_get('media_theplatform_mpx__output_message_watchdog_severity', WATCHDOG_INFO));


/**
 * @deprecated
 *
 * Returns array of all accounts specified thePlatform account.
 */
function media_theplatform_mpx_get_accounts_select($account_id, $username = NULL, $password = NULL, MpxToken $token = NULL) {

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

  try {
    if (empty($token) && empty($account_id) && $username && $password) {
      $token = MpxToken::fetch($username, $password);
    }
    elseif (empty($token) && !empty($account_id)) {
      $account_data = MpxAccount::load($account_id);
      if (empty($account_data)) {
        watchdog('media_theplatform_mpx', 'Failed to retrieve all import accounts.  Account data unavailable for account @id.',
          array('@id' => $account_id), WATCHDOG_ERROR);

        return array();
      }
      $token = $account_data->acquireToken();
    }
    elseif (empty($token)) {
      watchdog('media_theplatform_mpx', 'Failed to retrieve all import accounts because a account ID, token or username and password were not available.',
        array(), WATCHDOG_ERROR);

      return array();
    }
  }
  catch (Exception $e) {
    drupal_set_message($e->getMessage(), 'error');
    watchdog_exception('media_theplatform_mpx', $e);
    return array();
  }

  // Get the list of accounts from thePlatform.
  $url = 'https://access.auth.theplatform.com/data/Account?schema=1.3.0&form=json&byDisabled=false&token=' . rawurlencode($token)
    . '&fields=id,title';

  $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

  if (empty($account_id)) {
    $token->expire();
  }

  if (empty($result_data['entryCount']) || $result_data['entryCount'] == 0) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve import accounts for @for.  The mpx user provided does not have the necessary administrative privileges.',
      array('@for' => $for), WATCHDOG_ERROR);

    return array();
  }

  $sub_accounts = array();
  foreach ($result_data['entries'] as $entry) {
    $title = $entry['title'];
    $sub_accounts[$title] = $title;
  }

  $query = db_select('mpx_accounts', 'mpxa')
    ->fields('mpxa', array('import_account'));
  if (!empty($account_id)) {
    $query->condition('id', $account_id, '<>');
  }
  if ($existing_sub_accounts = $query->execute()->fetchCol()) {
    $sub_accounts = array_diff($sub_accounts, $existing_sub_accounts);
  }

  // Sort accounts alphabetically.
  natcasesort($sub_accounts);

  return $sub_accounts;
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
