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
