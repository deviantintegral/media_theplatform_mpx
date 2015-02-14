<?php

/**
 * @file
 * MPX Functions for forms.
 */

/**
 * Form builder
 *
 * @ingroup forms
 */
function media_theplatform_mpx_settings_form($form, &$form_state) {
  $form['cron'] = array(
    '#type' => 'fieldset',
    '#title' => t('Cron'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );
  $form['cron']['media_theplatform_mpx__cron_players'] = array(
    '#type' => 'checkbox',
    '#title' => t('Sync mpxPlayers on Cron'),
    '#default_value' => variable_get('media_theplatform_mpx__cron_players', 1),
  );
  $form['cron']['media_theplatform_mpx__cron_videos'] = array(
    '#type' => 'checkbox',
    '#title' => t('Sync mpxMedia on Cron'),
    '#default_value' => variable_get('media_theplatform_mpx__cron_videos', 1),
  );
  $form['cron']['media_theplatform_mpx__cron_videos_per_run'] = array(
    '#type' => 'numberfield',
    '#title' => t('Media Processing Limit'),
    '#description' => t('The number of videos to pull from the media feed per cron run. Maximum of 500.'),
    '#default_value' => variable_get('media_theplatform_mpx__cron_videos_per_run', 250),
    '#min' => 0,
    '#max' => 500,
  );
  $form['cron']['media_theplatform_mpx__cron_videos_timeout'] = array(
    '#type' => 'numberfield',
    '#title' => t('Media Feed Request Timeout'),
    '#description' => t('The amount in seconds to wait for a response from a media feed request.'),
    '#default_value' => variable_get('media_theplatform_mpx__cron_videos_timeout', 180),
    '#min' => 1,
  );
  $form['cron']['media_theplatform_mpx__cron_queue_processing_time'] = array(
    '#type' => 'numberfield',
    '#title' => t('Cron Queue Processing Time'),
    '#description' => t('The amount of time, in seconds, to allot to the cron queue for processing during each cron run.'),
    '#default_value' => variable_get('media_theplatform_mpx__cron_queue_processing_time', 10),
    '#min' => 1,
  );

  $form['logging'] = array(
    '#type' => 'fieldset',
    '#title' => t('Logging'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );
  $form['logging']['media_theplatform_mpx__output_message_watchdog_severity'] = array(
    '#type' => 'select',
    '#title' => t('Display MPX Debug Message Level'),
    '#options' => array(
      WATCHDOG_NOTICE => t('Basic'),
      WATCHDOG_INFO => t('Advanced'),
      WATCHDOG_DEBUG => t('Extremely Verbose'),
    ),
    '#description' => t('Determines how much information is reported to the user after MPX operations.
<br />Basic - minimal information provided about player, video and setting changes
<br />Advanced - provides feedback on all calls to thePlatform, database inserts/updates and file system writes
<br />Extremely Verbose - outputs all data received from thePlatform and all data inserted/updated in the database.'),
    '#default_value' => variable_get('media_theplatform_mpx__output_message_watchdog_severity', WATCHDOG_INFO),
  );
  $form['logging']['media_theplatform_mpx__watchdog_severity'] = array(
    '#type' => 'select',
    '#title' => t('MPX Debug Logging Level'),
    '#options' => array(
      WATCHDOG_NOTICE => t('Basic'),
      WATCHDOG_INFO => t('Advanced'),
      WATCHDOG_DEBUG => t('Extremely Verbose'),
    ),
    '#description' => t('Determines how much information is saved in logs during MPX operations.'),
    '#default_value' => variable_get('media_theplatform_mpx__watchdog_severity', WATCHDOG_INFO),
  );

  $form['advanced'] = array(
    '#type' => 'fieldset',
    '#title' => t('Advanced'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );
  $form['advanced']['media_theplatform_mpx__token_ttl'] = array(
    '#type' => 'numberfield',
    '#title' => t('Override authentication token lifetime'),
    '#description' => t('Leave blank to use the default token lifetime. Minimum: 3 seconds. Maximum: 2 weeks (1209600 seconds).'),
    '#default_value' => variable_get('media_theplatform_mpx__token_ttl'),
    '#field_suffix' => t('seconds'),
    '#min' => 3,
    '#max' => 1209600, // Two weeks
  );

  return system_settings_form($form);
}

/********* thePlatform signIn / Account ***********************/


/**
 * AJAX callback for adding accounts in the signin form.
 */
function media_theplatform_mpx_signin_form_add_account($form, $form_state) {
  return $form['accounts'];
}

/**
 * Form constructor for thePlatform username/password.
 *
 * @ingroup forms
 */
function media_theplatform_mpx_signin_form($form, &$form_state, $accounts = array()) {
  $collapsed = FALSE;
  $action_label = t('Connect to MPX');
  $title = t('mpx Login');
  $description = t('Enter your administrator login for thePlatform.com.');

  // If token exists already, collapse this form.
  if (!empty($accounts)) {
    $collapsed = TRUE;
    $title = t('Add MPX Account');
  }

  $form['accounts'] = array(
    '#type' => 'fieldset',
    '#title' => $title,
    '#description' => $description,
    '#collapsible' => $collapsed,
    '#collapsed' => $collapsed,
    '#prefix' => '<div id="media-theplatform-mpx-accounts-form-container">',
    '#suffix' => '</div>',
    '#tree' => TRUE,
  );

  $form['accounts']['theplatform_username'] = array(
    '#type' => 'textfield',
    '#title' => t('Username for New Account'),
    '#description' => t('Prefix your username with "mpx/". e.g. "mpx/admin@example.com".'),
    '#maxlength' => 255,
    '#required' => TRUE,
  );
  $form['accounts']['theplatform_password'] = array(
    '#type' => 'password',
    '#title' => t('Password for New Account'),
    '#maxlength' => 255,
    '#required' => TRUE,
  );

  $form['accounts']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Add Account'),
  );

  return $form;
}

/**
 * Form constructor for thePlatform that lists the current accounts.
 *
 * @ingroup forms
 */
function media_theplatform_mpx_account_list_form($form, &$form_state, $accounts = array()) {
  if (isset($form_state['storage']['confirm']) && !empty($form_state['storage']['delete_account'])) {
    $question = t('Are you sure you want to delete account @id?', array('@id' => $form_state['storage']['delete_account']));
    $path = 'admin/config/media/theplatform';
    $description = t('You are about to delete account @id and all of its assets.  This action cannot be undone.', array('@id' => $form_state['storage']['delete_account']));
    return confirm_form($form, $question, $path, $description, t('Delete'), t('Cancel'));
  }

  $form['account_list'] = array(
    '#type' => 'fieldset',
    '#title' => t('Account List'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
    '#tree' => TRUE,
  );

  $accounts_index = 0;
  foreach ($accounts as $id => $account) {
    if ($accounts_index > 0) {
      $form['account_list'][ $id ]['separator'] = array(
        '#type' => 'item',
        '#markup' => '<hr>',
      );
    }
    $accounts_index++;

    $form['account_list'][ $id ]['theplatform_username'] = array(
      '#type' => 'textfield',
      '#title' => t('Username for Account @theplatform_account_id:',
        array('@theplatform_account_id' => $account->id)),
      '#description' => t('Prefix your username with "mpx/". e.g. "mpx/admin@example.com".'),
      '#maxlength' => 255,
      '#default_value' => $account->username,
      '#required' => TRUE,
    );
    $form['account_list'][ $id ]['theplatform_password'] = array(
      '#type' => 'password',
      '#title' => t('Password for Account @theplatform_account_id:',
        array('@theplatform_account_id' => $account->id)),
      '#maxlength' => 255,
    );
    $form['account_list'][ $id ]['update_account'] = array(
      '#type' => 'submit',
      '#value' => t('Update Account @id', array('@id' => $id)),
    );
    $form['account_list'][ $id ]['delete_account'] = array(
      '#type' => 'submit',
      '#value' => t('Delete Account @id', array('@id' => $id)),
      '#suffix' => '<br />',
    );
  }

  return $form;
}

/**
 * Form constructor for selecting Import Account.
 *
 * @ingroup forms
 */
function media_theplatform_mpx_import_account_form($form, &$form_state, $accounts = array()) {

  $form['import_accounts'] = array(
    '#type' => 'fieldset',
    '#title' => t('Import Accounts'),
    '#description' => t('Set the account from which to import mpxPlayers and Feeds from.'),
    '#collapsible' => FALSE,
    '#collapsed' => FALSE,
    '#tree' => TRUE,
  );

  $error_message = '';
  foreach ($accounts as $account_id => $account) {
    $sub_account_list = array();
    if (!empty($account->import_account)) {
      $sub_account_list = array($account->import_account => rawurldecode($account->import_account));
    }
    else {
      $sub_account_list = media_theplatform_mpx_get_accounts_select($account_id);
    }
    // If current account doesn't exist, error msg.
    if ($account->import_account && !array_key_exists($account->import_account, $sub_account_list)) {
      $error_message .= '<div class="message">' . t('The current Import Account (:name) was not found in thePlatform; it may have been disabled or deleted.  Please select a new Account to use as the Import Account.', array(':name' => rawurldecode($account->import_account))) . '</div>';
    }
    $form['import_accounts']['settings'][$account->id] = array(
      '#type' => 'select',
      '#title' => t('Select Import Account for Account @theplatform_account_id:',
        array('@theplatform_account_id' => $account->id)),
      '#options' => $sub_account_list,
      '#required' => TRUE,
      '#empty_option' => t('- Select -'),
      '#default_value' => !empty($account->import_account) ? $account->import_account : '',
      '#disabled' => !empty($account->import_account),
    );
  }

  if ($error_message) {
    drupal_set_message($error_message, 'error');
  }

  $form['import_accounts']['actions'] = array('#type' => 'actions');
  $form['import_accounts']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Set Import Account'),
  );

  return $form;
}

/**
 * Page callback to return signin / account forms.
 */
function media_theplatform_mpx_page_account() {

  $all_account_data = media_theplatform_mpx_account_load_multiple();

  $form_signin = drupal_get_form('media_theplatform_mpx_signin_form', $all_account_data);
  $output = render($form_signin);

  if (!empty($all_account_data)) {

    // Add the account list.
    $account_list_form = drupal_get_form('media_theplatform_mpx_account_list_form', $all_account_data);
    $output .= render($account_list_form);

    // Add the import account form.
    $import_account_form = drupal_get_form('media_theplatform_mpx_import_account_form', $all_account_data);
    $output .= render($import_account_form);
  }

  return $output;
}

/**
 * Form validation handler for media_theplatform_mpx_signin_form().
 */
function media_theplatform_mpx_signin_form_validate($form, &$form_state) {

  $values = $form_state['values']['accounts'];
  // Validate new account credentials.
  if (!empty($form_state['values']['accounts'])) {
    $mpx_token = MpxToken::fetch($values['theplatform_username'], $values['theplatform_password']);
    if (!$mpx_token) {
      form_set_error('accounts][theplatform_password',
        t('Login failed for new account with username @username.', array('@username' => $values['theplatform_username'])));
    }
    else {
      drupal_set_message(t('Login successful for new account with username @username.',
        array('@username' => $values['theplatform_username'])));
      $sub_account_list = media_theplatform_mpx_get_accounts_select(NULL, NULL, NULL, $mpx_token);
      if (empty($sub_account_list)) {
        form_set_error('accounts][theplatform_username', t('There are no more sub-accounts for this account.'));
      }
    }
  }
}

/**
 * Form submit handler for media_theplatform_mpx_signin_form().
 */
function media_theplatform_mpx_signin_form_submit($form, &$form_state) {
  // Add new accounts.
  $values = $form_state['values']['accounts'];
  if (!empty($form_state['values']['accounts'])) {
    $account_id = db_insert('mpx_accounts')
      ->fields(array(
        'username' => $values['theplatform_username'],
        'password' => encrypt($values['theplatform_password']),
      ))
      ->execute();
    watchdog('media_theplatform_mpx', 'Successfully created account for user "@username".',
      array('@username' => $values['theplatform_username']), WATCHDOG_NOTICE);
  }
}

/**
 * Form validation handler for media_theplatform_mpx_account_list_form().
 *
 * @see media_theplatform_mpx_account_list_form()
 */
function media_theplatform_mpx_account_list_form_validate($form, &$form_state) {
  // Validate any credential changes to existing accounts.
  if (!empty($form_state['values']['account_list'])) {
    foreach ($form_state['values']['account_list'] as $id => $values) {
      $account_data = media_theplatform_mpx_account_load($id);
      if ($values['theplatform_password'] &&
        ($values['theplatform_username'] != $account_data->username || $values['theplatform_password'] != $account_data->password)) {
        $mpx_token = MpxToken::fetch($values['theplatform_username'], $values['theplatform_password']);
        if (!$mpx_token) {
          form_set_error('accounts][existing][' . $id . '][theplatform_password',
            t('Login failed for account @account_id', array('@account_id' => basename($account_data->account_id))));
        }
        else {
          drupal_set_message(t('Login successful for account @account_id', array('@account_id' => basename($account_data->account_id))));
        }
      }
    }
  }
}

/**
 * Form submit handler for media_theplatform_mpx_account_list_form().
 */
function media_theplatform_mpx_account_list_form_submit($form, &$form_state) {
  // Prepare for an account deletion if a "Delete Account X" button was clicked.
  if (!empty($form_state['values']['account_list'])) {
    foreach ($form_state['values']['account_list'] as $id => $values) {
      if ($form_state['clicked_button']['#value'] == t('Delete Account @id', array('@id' => $id))) {
        $form_state['storage']['confirm'] = TRUE;
        $form_state['storage']['delete_account'] = $id;
        $form_state['rebuild'] = TRUE;
        return;
      }
    }
  }

  // Delete an account after the user has confirmed the action.
  if (!empty($form_state['storage']['delete_account'])) {
    _media_theplatform_mpx_delete_account($form_state['storage']['delete_account']);
    return;
  }

  // Process any changes to existing accounts.
  if (!empty($form_state['values']['account_list'])) {
    foreach ($form_state['values']['account_list'] as $id => $values) {
      $account_data = media_theplatform_mpx_account_load($id);
      if ($values['theplatform_password'] &&
          ($values['theplatform_username'] != $account_data->username ||
          $values['theplatform_password'] != $account_data->password)) {

        // Update the username, password and the rest of the account data.  Users
        // should resave the sub-account to ensure valid data and a valid auth
        // token.
        watchdog('media_theplatform_mpx', 'Updating username and password for @account in the mpx_accounts table.',
          array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_INFO);

        try {
          db_update('mpx_accounts')
            ->fields(array(
                'username' => $values['theplatform_username'],
                'password' => encrypt($values['theplatform_password']),
              ))
            ->condition('id', $id, '=')
            ->execute();
          watchdog('media_theplatform_mpx', 'Successfully updated @account.',
            array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_NOTICE);
        }
        catch (Exception $e) {

          watchdog('media_theplatform_mpx', 'FATAL ERROR occurred while updating account @account --  "@message"  -- Exception output: @output.',
            array(
              '@account' => _media_theplatform_mpx_account_log_string($account_data),
              '@message' => $e->getMessage(),
              '@output' => '<pre>' . print_r($e, TRUE) . '</pre>',
            ),
            WATCHDOG_ERROR);
        }
      }
    }
  }
}

/**
 * Form validation handler for media_theplatform_mpx_form_account_theplatform().
 */
function media_theplatform_mpx_import_account_form_validate($form, &$form_state) {

  $selected_import_accounts = array();

  foreach ($form_state['values']['import_accounts']['settings'] as $account_id => $import_account) {
    if (in_array($import_account, $selected_import_accounts)) {
      form_set_error('import_accounts][settings][' . $account_id,
        t('The same import account cannot be selected for multiple accounts.'));
    }
    else {
      $selected_import_accounts[] = $import_account;
    }
  }
}

/**
 * Form submit handler for media_theplatform_mpx_form_account_theplatform().
 */
function media_theplatform_mpx_import_account_form_submit($form, &$form_state) {

  global $user;

  foreach ($form_state['values']['import_accounts']['settings'] as $account_id => $import_account) {

    $account_data = media_theplatform_mpx_account_load($account_id);

    // If an import account is already set, resetting it will cause an
    // unwanted complete re-ingestion of all videos.
    if (!empty($account_data->import_account)) {
      continue;
    }

    watchdog('media_theplatform_mpx', 'Setting import account "@import_account" for @account.',
      array(
        '@import_account' => $import_account,
        '@account' => _media_theplatform_mpx_account_log_string($account_data),
      ),
      WATCHDOG_NOTICE);

    if (!$account_data) {
      watchdog('media_theplatform_mpx', 'Failed to set import account for @account.  Account data could not be retrieved from the database.',
        array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_ERROR);
      continue;
    }

    // Query MPX to get the id and account$pid for the selected account.
    watchdog('media_theplatform_mpx', 'Retrieving import account information for "@import_account".',
      array('@import_account' => $import_account), WATCHDOG_NOTICE);

    $token = media_theplatform_mpx_token_acquire($account_data);
    $url = 'https://access.auth.theplatform.com/data/Account?schema=1.3.0&form=json&byDisabled=false&byTitle=' . $import_account . '&token=' . rawurlencode($token) . '&fields=id,pid';

    $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

    if (!isset($result_data['entries'][0]['id']) || !isset($result_data['entries'][0]['placcount$pid'])) {
      watchdog('media_theplatform_mpx', 'Failed to retrieve import account information for "@import_account".  "id" and/or "placcount$pid" field value not set.',
        array('@import_account' => $import_account), WATCHDOG_ERROR);

      return FALSE;
    }

    $mpx_account_id = preg_replace('|^http://|', 'https://', $result_data['entries'][0]['id']);
    $mpx_account_pid = $result_data['entries'][0]['placcount$pid'];

    watchdog('media_theplatform_mpx', 'Updating
      <br /> import_account = @import_account,
      <br /> account_id = @account_id,
      <br /> account_pid = @account_pid,
      <br /> last_notification = NULL
<br />in mpx_accounts table for @account.',
      array(
        '@import_account' => $import_account,
        '@account_id' => $mpx_account_id,
        '@account_pid' => $mpx_account_pid,
        '@account' => _media_theplatform_mpx_account_log_string($account_data),
      ),
      WATCHDOG_INFO);

    // Save the new import account data for this account. Reset last
    // notification, since a newly set import account will need a
    // complete video ingestion.
    try {

      db_update('mpx_accounts')
        ->fields(array(
            'import_account' => $import_account,
            'account_id' => $mpx_account_id,
            'account_pid' => $mpx_account_pid,
            'last_notification' => NULL,
          ))
        ->condition('id', $account_id, '=')
        ->execute();

      watchdog('media_theplatform_mpx', 'Updated import account data for @account successfully.',
        array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_NOTICE);
    }
    catch (Exception $e) {

      watchdog('media_theplatform_mpx', 'EXCEPTION THROWN occurred while updating import account data for @account. --  "@message"  -- Exception output: @output.',
        array(
          '@account' => _media_theplatform_mpx_account_log_string($account_data),
          '@message' => $e->getMessage(),
          '@output' => '<pre>' . print_r($e, TRUE) . '</pre>',
        ),
        WATCHDOG_ERROR);
    }
  }

  // Import all players.
  $import = media_theplatform_mpx_import_all_players('manually');

  drupal_set_message(t('Proceed to !link to set a default MPX player and complete the MPX configuration process.',
    array('!link' => l('the MPX video library page', 'admin/content/file/mpxmedia'))));
}

/**
 * Returns TRUE if token and import_account set.  If not, drupal_set_message and returns FALSE.
 */
function media_theplatform_mpx_check_account_settings($account_id = NULL) {

  // If an account ID is passed, check settings for that account.  Otherwise,
  // check all available accounts that at least one account is configured
  // properly.
  $account_configured = FALSE;
  $account_ids = !empty($account_id) ? array($account_id) : NULL;
  $accounts = media_theplatform_mpx_account_load_multiple($account_ids);
  foreach ($accounts as $account) {
    if (media_theplatform_mpx_token_acquire($account) && !empty($account->import_account)) {
      $account_configured = TRUE;
      break;
    }
  }

  if (!$account_configured) {
    if (user_access('administer mpx account')) {
      drupal_set_message(t('Your mpx Account is not configured.') . ' ' . l(t('Configure mpx Account.'), 'admin/config/media/theplatform'), 'error');
    }
    else {
      drupal_set_message(t('Your mpx Account is not configured.') . ' ' . t('Please contact your System Administrator.'), 'error');
    }
    return FALSE;
  }

  return TRUE;
}

/******************* mpxPlayers *****************************/

/**
 * Page callback to return all mpx_players and forms.
 */
function media_theplatform_mpx_page_mpx_players() {
  $output = array();
  // Display forms if signin and import_account
  if (media_theplatform_mpx_check_account_settings() && user_access('sync mpx_player')) {
    $output['sync'] = drupal_get_form('media_theplatform_mpx_form_mpx_player_sync');
  }
  $output += media_theplatform_mpx_get_table_mpx_players();
  return $output;
}

/**
 * Form constructor for mpxPlayer Sync.
 *
 * @ingroup forms
 */
function media_theplatform_mpx_form_mpx_player_sync($form, &$form_state) {
  if (media_theplatform_mpx_get_mpx_player_count() > 0) {
    $collapsed = TRUE;
  }
  else {
    $collapsed = FALSE;
  }
  $form['player_sync'] = array(
    '#type' => 'fieldset',
    '#title' => t('Sync mpxPlayers'),
    '#description' => t('Note: Any mpxPlayer marked as "Disabled" in thePlatform mpx will not be retrieved in the Sync process.'),
    '#collapsible' => TRUE,
    '#collapsed' => $collapsed,
  );
  $form['player_sync']['actions'] = array('#type' => 'actions');
  $form['player_sync']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Sync mpxPlayers Now'),
  );
  return $form;
}

/**
 * Form submit handler for media_theplatform_mpx_form_mpx_player_sync().
 */
function media_theplatform_mpx_form_mpx_player_sync_submit($form, &$form_state) {
  media_theplatform_mpx_import_all_players('manually');
}

/**
 * Returns a themed table of mpx_player data.
 */
function media_theplatform_mpx_get_table_mpx_players() {
  $header = array(
    // The header gives the table the information it needs in order to make
    // the query calls for ordering. TableSort uses the field information
    // to know what database column to sort by.
    array('data' => t('ID'), 'field' => 'p.player_id'),
    array('data' => NULL),
    array('data' => t('Title'), 'field' => 'p.title'),
    array('data' => t('Description'), 'field' => 'p.description'),
    // array('data' => t('mpx ID'), 'field' => 'p.id'),
    array('data' => t('Status'), 'field' => 'p.status'),
    array('data' => t('First Imported'), 'field' => 'p.created'),
    array('data' => t('Last Updated'), 'field' => 'p.updated'),
  );

  $query = db_select('mpx_player', 'p')
    ->extend('TableSort')
    ->extend('PagerDefault');
  $query->fields('p');
  $query->orderByHeader($header);
  $query->limit(25);
  $results = $query->execute();

  $rows = array();
  foreach ($results as $player) {
    if ($player->fid == variable_get('media_theplatform_mpx__default_player_fid')) {
      $default = '[default]';
    }
    else {
      $default = NULL;
    }
    $rows[] = array(
      $player->player_id,
      $default,
      l($player->title, 'file/' . $player->fid),
      $player->description,
      // $player->id,
      $player->status ? t('Enabled') : t('Disabled'),
      format_date($player->created, 'short'),
      format_date($player->updated, 'short'),
    );
  }

  $output = array();
  $output['table'] = array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#empty' => t('No mpxPlayers have been imported.'),
  );
  $output['pager'] = array(
    '#theme' => 'pager',
  );
  return $output;
}


/******************** mpxMedia *******************************/

/**
 * Page callback - display all mpx Video media and forms.
 */
function media_theplatform_mpx_page_mpx_videos() {
  $output = array();
  // If no mpxPlayers, you cant do anything with mpxMedia.
  if (!media_theplatform_mpx_get_mpx_player_count()) {
    $output['#markup'] = t('No mpxPlayers have been imported.');
    if (user_access('sync mpx_player')) {
      $output['#markup'] .= ' ' . l(t('Sync mpxPlayers.'), 'admin/content/file/mpxplayer');
    }
    else {
      $output['#markup'] .= ' ' . t('Please contact your System Administrator.');
    }
    return $output;
  }
  if (media_theplatform_mpx_check_account_settings() && user_access('sync mpx_video')) {
    $output['sync'] = drupal_get_form('media_theplatform_mpx_form_mpx_video_sync');
  }
  $output += media_theplatform_mpx_get_table_mpx_videos();
  return $output;
}

/**
 * Form constructor for mpx_video Sync.
 *
 * @ingroup forms
 */
function media_theplatform_mpx_form_mpx_video_sync($form, &$form_state) {

  $form['video_sync'] = array(
    '#type' => 'fieldset',
    '#title' => t('Sync mpxMedia'),
    '#description' => t('Note: Any mpxMedia which has been unpublished or deleted from thePlatform mpx will be set to "Inactive".'),
    '#collapsible' => TRUE,
    '#collapsed' => media_theplatform_mpx_get_mpx_video_count() > 0 ? TRUE : FALSE,
    '#tree' => TRUE,
  );

  foreach (media_theplatform_mpx_account_load_multiple() as $account) {
    $player_select = media_theplatform_mpx_get_players_select($account);
    if (empty($player_select)) {
      continue;
    }
    $form['video_sync']['settings'][ $account->id ]['default_player_id'] = array(
      '#type' => 'select',
      '#title' => t('Import new mpxMedia for account @account with mpxPlayer:',
        array('@account' => '"' . urldecode($account->import_account) . '" (' . basename($account->account_id) . ')')),
      '#options' => $player_select,
      '#empty_option' => t('- Select -'),
      '#required' => TRUE,
      '#default_value' => $account->default_player,
    );
  }

  $form['video_sync']['actions'] = array('#type' => 'actions');
  $form['video_sync']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Sync mpxMedia Now'),
  );

  return $form;
}

/**
 * Form submission handler for media_theplatform_mpx_form_mpx_video_sync().
 */
function media_theplatform_mpx_form_mpx_video_sync_submit($form, &$form_state) {

  // Set default mpxPlayer variable.
  foreach ($form_state['values']['video_sync']['settings'] as $account_id => $values) {
    $currently_stored_default_player = db_select('mpx_accounts', 'mpx_accounts')
      ->fields('mpx_accounts', array('default_player'))
      ->condition('id', $account_id, '=')
      ->execute()
      ->fetchField();
    if ($currently_stored_default_player != $values['default_player_id']) {
      _media_theplatform_mpx_set_field($account_id, 'default_player', $values['default_player_id']);
      $account = media_theplatform_mpx_account_load($account_id);
      $player = media_theplatform_mpx_get_mpx_player_by_player_id($values['default_player_id']);
      watchdog('media_theplatform_mpx', 'Player @pid -- @player -- set as default player for account @account.',
        array(
          '@pid' => $player['pid'],
          '@player' => $player['title'],
          '@account' => basename($account->account_id),
        ),
        WATCHDOG_NOTICE);
    }
  }

  // Import videos.
  media_theplatform_mpx_import_all_videos('manually');

  drupal_set_message(t('<a href="@cron">Run cron now</a> to complete the import/update process.',
    array('@cron' => url('admin/reports/status/run-cron', array('query' => drupal_get_destination())))));
}

/**
 * Returns themed table of mpx_video data.
 */
function media_theplatform_mpx_get_table_mpx_videos() {
  $header = array(
    // The header gives the table the information it needs in order to make
    // the query calls for ordering. TableSort uses the field information
    // to know what database column to sort by.
    array('data' => t('ID'), 'field' => 'v.video_id'),
    array('data' => NULL),
    array('data' => t('Title'), 'field' => 'v.title'),
    array('data' => t('Description'), 'field' => 'v.description'),
    // array('data' => t('mpx ID (Guid)'), 'field' => 'v.guid'),
    array('data' => t('Status'), 'field' => 'v.status'),
    array('data' => t('First Imported'), 'field' => 'v.created'),
    array('data' => t('Last Updated'), 'field' => 'v.updated'),
  );
  $query = db_select('mpx_video', 'v')
    ->extend('TableSort')
    ->extend('PagerDefault');
  $query->fields('v');
  $query->orderByHeader($header);
  $query->limit(50);
  $results = $query->execute();

  $rows = array();
  foreach ($results as $video) {
    $file = file_load($video->fid);
    $thumbnail = media_get_thumbnail_preview($file);
    $rows[] = array(
      $video->video_id,
      l(drupal_render($thumbnail), 'file/' . $video->fid, array('html' => TRUE, 'attributes' => array('class' => 'mpxmedia'))),
      $video->title,
      $video->description,
      // $video->guid,
      $video->status ? t('Enabled') : t('Disabled'),
      format_date($video->created, 'short'),
      format_date($video->updated, 'short'),
    );
  }

  $output = array();
  $output['table'] = array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#empty' => t('No mpxMedia has been imported.'),
  );
  $output['pager'] = array(
    '#theme' => 'pager',
  );
  return $output;
}
