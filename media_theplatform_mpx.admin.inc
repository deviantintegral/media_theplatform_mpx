<?php

/**
 * @file
 * MPX Functions for forms.
 */

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
    $action_label = t('Save');
    $title = t('Add Accounts');
    $description = t('Enter the credentials from the new thePlatform.com account and then click "Save."');
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

  $add_account_action_label = t('Add New Account');
  $added_accounts = !empty($form_state['values']['accounts']['new']) ?
    $form_state['values']['accounts']['new'] : array();

  if (isset($form_state['values']['op']) && $form_state['values']['op'] == $add_account_action_label) {
    $form['accounts']['#collapsed'] = FALSE;
  }
  $added_accounts[] = array('theplatform_username' => '', 'theplatform_password' => '');

  // If there are no added account, lets add one so there can be something to
  // fill out.
  if (empty($added_accounts) && empty($accounts)) {
    $added_accounts[] = array('theplatform_username' => '', 'theplatform_password' => '');
  }

  foreach ($added_accounts as $key => $account) {
    if ($key > 0) {
      $form['accounts']['new'][ $key ]['separator'] = array(
        '#type' => 'item',
        '#markup' => '<hr>',
      );
    }

    $form['accounts']['new'][ $key ]['theplatform_username'] = array(
      '#type' => 'textfield',
      '#title' => t('Username for New Account'),
      '#description' => t('Prefix your username with "mpx/". e.g. "mpx/admin@example.com".'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#default_value' => $account['theplatform_username'],
    );
    $form['accounts']['new'][$key]['theplatform_password'] = array(
      '#type' => 'password',
      '#title' => t('Password for New Account'),
      '#maxlength' => 255,
      '#required' => TRUE,
    );
  }

  $form['accounts']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => $action_label,
  );

  // Only show the Add New Account button if we're already logged in.
  if (!empty($accounts)) {
    $form['accounts']['add_account'] = array(
      '#type' => 'button',
      '#value' => $add_account_action_label,
      '#href' => '',
      '#ajax' => array(
        'callback' => 'media_theplatform_mpx_signin_form_add_account',
        'wrapper' => 'media-theplatform-mpx-accounts-form-container',
      ),
    );
  }

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
      '#default_value' => _media_theplatform_mpx_decrypt_value($account->username),
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

  $all_account_data = _media_theplatform_mpx_get_account_data();

  $form_signin = drupal_get_form('media_theplatform_mpx_signin_form', $all_account_data);
  $output = render($form_signin);

  if (!empty($all_account_data)) {

    // Add the account list.
    $account_list_form = drupal_get_form('media_theplatform_mpx_account_list_form', $all_account_data);
    $output .= render($account_list_form);

    // Add the import account form.
    $import_account_form = drupal_get_form('media_theplatform_mpx_import_account_form', $all_account_data);
    $output .= render($import_account_form);

    // Add the cron form if accounts exist.
    $cron_form = drupal_get_form('media_theplatform_mpx_form_cron_settings');
    $output .= render($cron_form);
  }

  return $output;
}

/**
 * Form validation handler for media_theplatform_mpx_signin_form().
 */
function media_theplatform_mpx_signin_form_validate($form, &$form_state) {

  // Validate any credential changes to existing accounts.
  if (!empty($form_state['values']['accounts']['existing'])) {
    foreach ($form_state['values']['accounts']['existing'] as $id => $values) {
      $account_data = _media_theplatform_mpx_get_account_data($id);
      $existing_username = _media_theplatform_mpx_decrypt_value($account_data->username);
      $existing_password = _media_theplatform_mpx_decrypt_value($account_data->password);
      if ($values['theplatform_password'] &&
          ($values['theplatform_username'] != $existing_username || $values['theplatform_password'] != $existing_password)) {
        $mpx_token_ttl = MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT;
        $mpx_token = _media_theplatform_mpx_get_signin_token($values['theplatform_username'], $values['theplatform_password'], $mpx_token_ttl);
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

  // Validate new account credentials.
  if (!empty($form_state['values']['accounts']['new'])) {
    foreach ($form_state['values']['accounts']['new'] as $key => $values) {
      $mpx_token_ttl = MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT;
      $mpx_token = _media_theplatform_mpx_get_signin_token($values['theplatform_username'], $values['theplatform_password'], $mpx_token_ttl);
      if (!$mpx_token) {
        form_set_error('accounts][new][' . $key . '][theplatform_password',
          t('Login failed for new account with username @username.', array('@username' => $values['theplatform_username'])));
      }
      else {
        drupal_set_message(t('Login successful for new account with username @username.',
          array('@username' => $values['theplatform_username'])));
        $sub_account_list = media_theplatform_mpx_get_accounts_select(NULL, NULL, NULL, $mpx_token);
        if (empty($sub_account_list)) {
          form_set_error('accounts][new][' . $key . '][theplatform_username', t('There are no more sub-accounts for this account.'));
        }
      }
    }
  }
}

/**
 * Form submit handler for media_theplatform_mpx_signin_form().
 */
function media_theplatform_mpx_signin_form_submit($form, &$form_state) {
  // Add new accounts.
  if (!empty($form_state['values']['accounts']['new'])) {
    foreach ($form_state['values']['accounts']['new'] as $key => $values) {
      try {
        $account_id = db_insert('mpx_accounts')
          ->fields(array(
            'username' => _media_theplatform_mpx_encrypt_value($values['theplatform_username']),
            'password' => _media_theplatform_mpx_encrypt_value($values['theplatform_password']),
          ))
          ->execute();
        drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);
        media_theplatform_mpx_signin($account_id);
        watchdog('media_theplatform_mpx', 'Successfully created account for user "@username".',
          array('@username' => $values['theplatform_username']), WATCHDOG_NOTICE);
      }
      catch (Exception $e) {

        watchdog('media_theplatform_mpx', 'FATAL ERROR occurred while creating account @account --  "@message"  -- Exception output: @output.',
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

/**
 * Form validation handler for media_theplatform_mpx_account_list_form().
 *
 * @see media_theplatform_mpx_account_list_form()
 */
function media_theplatform_mpx_account_list_form_validate($form, &$form_state) {
  // Validate any credential changes to existing accounts.
  if (!empty($form_state['values']['account_list'])) {
    foreach ($form_state['values']['account_list'] as $id => $values) {
      $account_data = _media_theplatform_mpx_get_account_data($id);
      $existing_username = _media_theplatform_mpx_decrypt_value($account_data->username);
      $existing_password = _media_theplatform_mpx_decrypt_value($account_data->password);
      if ($values['theplatform_password'] &&
        ($values['theplatform_username'] != $existing_username || $values['theplatform_password'] != $existing_password)) {
        $mpx_token_ttl = MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT;
        $mpx_token = _media_theplatform_mpx_get_signin_token($values['theplatform_username'], $values['theplatform_password'], $mpx_token_ttl);
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
      $account_data = _media_theplatform_mpx_get_account_data($id);
      $existing_username = _media_theplatform_mpx_decrypt_value($account_data->username);
      $existing_password = _media_theplatform_mpx_decrypt_value($account_data->password);
      if ($values['theplatform_password'] &&
          ($values['theplatform_username'] != $existing_username ||
          $values['theplatform_password'] != $existing_password)) {

        // Update the username, password and the rest of the account data.  Users
        // should resave the sub-account to ensure valid data and a valid auth
        // token.
        watchdog('media_theplatform_mpx', 'Updating username and password for @account in the mpx_accounts table.',
          array('@account' => _media_theplatform_mpx_account_log_string($account_data)), WATCHDOG_INFO);

        try {
          db_update('mpx_accounts')
            ->fields(array(
                'username' => _media_theplatform_mpx_encrypt_value($values['theplatform_username']),
                'password' => _media_theplatform_mpx_encrypt_value($values['theplatform_password']),
              ))
            ->condition('id', $id, '=')
            ->execute();
          drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);
          media_theplatform_mpx_signin($id);
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

    $account_data = _media_theplatform_mpx_get_account_data($account_id);

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

    $username = _media_theplatform_mpx_decrypt_value($account_data->username);
    $password = _media_theplatform_mpx_decrypt_value($account_data->password);
    $token_ttl = MEDIA_THEPLATFORM_MPX_TOKEN_IDLE_TIMEOUT;
    $token = _media_theplatform_mpx_get_signin_token($username, $password, $token_ttl);
    $url = 'https://access.auth.theplatform.com/data/Account?schema=1.3.0&form=json&byDisabled=false&byTitle=' . $import_account . '&token=' . $token . '&fields=id,pid';

    $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

    media_theplatform_mpx_expire_token($token);

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

  // Reset the account data static cache.
  drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);

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
  $account_data = _media_theplatform_mpx_get_account_data($account_id);

  if ($account_id) {
    $account_configured = !empty($account_data->token) && !empty($account_data->import_account);
  }
  else {
    foreach ($account_data as $account) {
      if (!empty($account->token) && !empty($account->import_account)) {
        $account_configured = TRUE;
        break;
      }
    }
  }

  if (!$account_configured) {
    if (user_access('administer mpx account')) {
      drupal_set_message(t('Your mpx Account is not configured.') . ' ' . l(t('Configure mpx Account.'), MPX_PATH_ADMIN), 'error');
    }
    else {
      drupal_set_message(t('Your mpx Account is not configured.') . ' ' . t('Please contact your System Administrator.'), 'error');
    }
    return FALSE;
  }

  return TRUE;
}

/**
 * System settings form constructor for Cron settings.
 *
 * @ingroup forms
 */
function media_theplatform_mpx_form_cron_settings() {

  $form['cron'] = array(
    '#type' => 'fieldset',
    '#title' => t('Cron Settings'),
    '#collapsible' => FALSE,
    '#collapsed' => FALSE,
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'cron_players'] = array(
    '#type' => 'checkbox',
    '#title' => t('Sync mpxPlayers on Cron'),
    '#default_value' => media_theplatform_mpx_variable_get('cron_players'),
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'cron_videos'] = array(
    '#type' => 'checkbox',
    '#title' => t('Sync mpxMedia on Cron'),
    '#default_value' => media_theplatform_mpx_variable_get('cron_videos'),
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'cron_videos_per_run'] = array(
    '#type' => 'textfield',
    '#title' => t('Media Processing Limit'),
    '#description' => t('The number of videos to pull from the media feed per cron run.'),
    '#default_value' => media_theplatform_mpx_variable_get('cron_videos_per_run', 250),
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'cron_videos_timeout'] = array(
    '#type' => 'textfield',
    '#title' => t('Media Feed Request Timeout'),
    '#description' => t('The amount in seconds to wait for a response from a media feed request.'),
    '#default_value' => media_theplatform_mpx_variable_get('cron_videos_timeout', 180),
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'cron_queue_processing_time'] = array(
    '#type' => 'textfield',
    '#title' => t('Cron Queue Processing Time'),
    '#description' => t('The amount of time to allot to the cron queue for processing during each cron run.'),
    '#default_value' => media_theplatform_mpx_variable_get('cron_queue_processing_time', 10),
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'output_message_watchdog_severity'] = array(
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
    '#default_value' => MEDIA_THEPLATFORM_MPX_MESSAGE_LEVEL,
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'watchdog_severity'] = array(
    '#type' => 'select',
    '#title' => t('MPX Debug Logging Level'),
    '#options' => array(
      WATCHDOG_NOTICE => t('Basic'),
      WATCHDOG_INFO => t('Advanced'),
      WATCHDOG_DEBUG => t('Extremely Verbose'),
    ),
    '#description' => t('Determines how much information is saved in logs during MPX operations.'),
    '#default_value' => MEDIA_THEPLATFORM_MPX_LOGGING_LEVEL,
  );
  $form['cron'][media_theplatform_mpx_NAMESPACE . 'token_ttl'] = array(
    '#type' => 'textfield',
    '#title' => t('Authentication Token TTL'),
    '#description' => t('The amount of time in seconds an authentication token remains valid.  Increasing this value may help if requests to thePlatform are failing due to invalid authentication tokens.  Use the smallest value possible.  Min: 3 seconds - Max: 180 seconds (3 minutes)'),
    '#default_value' => media_theplatform_mpx_variable_get('token_ttl', 3),
  );

  return system_settings_form($form);
}

/**
 * Form validation handler for the media_theplatform_mpx_form_cron_settings form.
 */
function media_theplatform_mpx_form_cron_settings_validate(&$form, &$form_state) {

  if ($form_state['values'][media_theplatform_mpx_NAMESPACE . 'cron_videos_per_run'] > 500) {
    form_set_error(media_theplatform_mpx_NAMESPACE . 'cron_videos_per_run', t('The maximum number of media items to process on cron is 500.'));
  }

  if ($form_state['values'][media_theplatform_mpx_NAMESPACE . 'token_ttl'] < 3) {
    form_set_error(media_theplatform_mpx_NAMESPACE . 'token_ttl', t('The minimum TTL for authentication tokens is 3 seconds.'));
  }
  if ($form_state['values'][media_theplatform_mpx_NAMESPACE . 'token_ttl'] > 180) {
    form_set_error(media_theplatform_mpx_NAMESPACE . 'token_ttl', t('The maximum TTL for authentication tokens is 180 seconds (3 minutes).'));
  }
}


/******************* mpxPlayers *****************************/

/**
 * Page callback to return all mpx_players and forms.
 */
function media_theplatform_mpx_page_mpx_players() {
  $output = '';
  // Display forms if signin and import_account
  if (media_theplatform_mpx_check_account_settings() && user_access('sync mpx_player')) {
    $form_player_sync = drupal_get_form('media_theplatform_mpx_form_mpx_player_sync');
    $output .= render($form_player_sync);
  }
  $output .= media_theplatform_mpx_get_table_mpx_players();
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

  $import = media_theplatform_mpx_import_all_players('manually');
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
    ->extend('TableSort');
  $query->fields('p');
  $result = $query
    ->orderByHeader($header)
    ->execute();
  $num_rows = $query->countQuery()->execute()->fetchField();
  if ($num_rows == 0) {
    return '<div class="message">' . t('No mpxPlayers have been imported.') . '</div>';
  }
  foreach ($result as $player) {
    if ($player->fid == media_theplatform_mpx_variable_get('default_player_fid')) {
      $default = '[default]';
    }
    else {
      $default = NULL;
    }
    $rows[] = array(
      $player->player_id,
      $default,
      l($player->title, MPX_PATH_FILE . '/' . $player->fid),
      $player->description,
      // $player->id,
      $player->status,
      format_date($player->created, 'short'),
      format_date($player->updated, 'short'),
    );
  }
  return theme('table', array('header' => $header, 'rows' => $rows));
}


/******************** mpxMedia *******************************/

/**
 * Page callback - display all mpx Video media and forms.
 */
function media_theplatform_mpx_page_mpx_videos() {
  $output = '';
  // If no mpxPlayers, you cant do anything with mpxMedia.
  if (!media_theplatform_mpx_get_mpx_player_count()) {
    $output .= t('No mpxPlayers have been imported.');
    if (user_access('sync mpx_player')) {
      $output .= ' ' . l(t('Sync mpxPlayers.'), MPX_PATH_PLAYER);
    }
    else {
      $output .= ' ' . t('Please contact your System Administrator.');
    }
    return $output;
  }
  if (media_theplatform_mpx_check_account_settings() && user_access('sync mpx_video')) {
    $form_video_sync = drupal_get_form('media_theplatform_mpx_form_mpx_video_sync');
    $output .= render($form_video_sync);
  }
  $output .= media_theplatform_mpx_get_table_mpx_videos();
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

  foreach (_media_theplatform_mpx_get_account_data() as $account) {
    $player_select = media_theplatform_mpx_get_players_select();
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
      $account = _media_theplatform_mpx_get_account_data($account_id);
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

  drupal_set_message(t('!cron_link to complete the import/update process.',
    array('!cron_link' => l(t('Run cron'), 'admin/config/system/cron'))));
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
    ->extend('TableSort');
  $query->fields('v');
  $result = $query
    ->orderByHeader($header)
    ->execute();
  $num_rows = $query->countQuery()->execute()->fetchField();
  if ($num_rows == 0) {
    return '<div class="message">' . t('No mpxMedia has been imported.') . '</div>';
  }
  foreach ($result as $video) {
    $file = file_load($video->fid);
    $thumbnail = media_get_thumbnail_preview($file);
    $rows[] = array(
      $video->video_id,
      l(drupal_render($thumbnail), MPX_PATH_FILE . '/' . $video->fid, array('html' => TRUE, 'attributes' => array('class' => 'mpxmedia'))),
      $video->title,
      $video->description,
      // $video->guid,
      $video->status,
      format_date($video->created, 'short'),
      format_date($video->updated, 'short'),
    );
  }
  return theme('table', array('header' => $header, 'rows' => $rows));
}
