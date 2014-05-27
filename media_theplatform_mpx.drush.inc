<?php


define('MEDIA_THEPLATFORM_MPX_VIDEO_INGESTION_LOCK_BASENAME', 'drush_media_theplatform_mpx_ingest_mpx_videos');


/**
 * Implements hook_drush_command().
 */
function media_theplatform_mpx_drush_command() {

  $commands = array();

  $commands['ingest-mpx-videos'] = array(
    'description' => dt('Runs a continuous mpx video ingestion process.'),
    'aliases' => array('mpxv'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_DATABASE,
    'arguments' => array(
      'account_id' => dt('The ID of the account to ingest videos for.  DEFAULT: all'),
    ),
    'options' => array(
      'force' => array(
        'description' => dt('Force the ingestion process to run even if other ingestion processes are currently running.  WARNING: Forcing this command to run while other mpx video ingestion processes are active may, and likely will, have unwanted side-effects.') . "\n",
        'example_value' => '1',
        'value' => 'optional',
      ),
      'quit-when-complete' => array(
        'description' => dt('Have the command exit once all video assets have been processed.  Otherwise, it will run indefinitely listening for updates as they are returned from thePlatform.') . "\n",
        'example_value' => '1',
        'value' => 'optional',
      ),
      'process-count' => array(
        'description' => dt('The number of ingestion processes that will be run concurrently.  IMPORTANT NOTE: This value should be a multiple of 2, a "retriever" process that retrieves video data from thePlatform and a "worker" process that creates/updates/deletes videos.') . "\n",
        'example_value' => 6,
        'value' => 'optional',
      ),
      'x-account-id' => array(
        'description' => dt('Internal use only.  Used when invoking child processes.'),
        'value' => 'optional',
      ),
      'x-parent-id' => array(
        'description' => dt('Internal use only.  Used when invoking child processes.'),
        'value' => 'optional',
      ),
      'x-child-id' => array(
        'description' => dt('Internal use only.  Used when invoking child processes.'),
        'value' => 'optional',
      ),
      'x-child-type' => array(
        'description' => dt('Internal use only.  Used when invoking child processes.'),
        'value' => 'optional',
      ),
      'x-sibling-count' => array(
        'description' => dt('Internal use only.  Used when invoking child processes.'),
        'value' => 'optional',
      ),
      'x-max-processing-time' => array(
        'description' => dt('Internal use only.  Used when invoking child processes.'),
        'value' => 'optional',
      ),
    ),
    'examples' => array(
      'drush ingest-mpx-videos' => dt('Ingest videos for all configured accounts.'),
      'drush mpxv' => dt('Ingest videos for all configured accounts.'),
      'drush mpxv all' => dt('Ingest videos for all configured accounts.'),
      'drush mpxv 1' => dt('Ingest videos for account 1.'),
      'drush mpxv --force=1 1' => dt('Ingest videos for account 1, even if other ingestion processes are currently running.'),
      'drush mpxv --quit-when-complete=1' => dt('Ingest videos for all configured accounts, stopping after all videos and video updates have been ingested.'),
      'drush mpxv --process-count=6' => dt('Ingest videos for all configured accounts, limiting the number of concurrent retriever and worker processes across accounts to 6.  However, if there are 4 accounts configured, the process limit will automatically be raised to 8, so that a retriever and worker process can be invoked for each account.  If there are 5 configured accounts, the process limit is raised to 10.  And so on.'),
    ),
  );

  return $commands;
}

/**
 * Command validation handler for the `drush ingest-mpx-videos` command.
 */
function drush_media_theplatform_mpx_ingest_mpx_videos_validate($account_id = 'all') {

  $forced_to_run = drush_get_option('force');

  // Do not run CLI ingestion if cron video ingestion is enabled.
  if (!$forced_to_run && media_theplatform_mpx_variable_get('cron_videos')) {
    return _drush_media_theplatform_mpx_print('MPX cron video ingestion is enabled.  Aborting drush mpx video ingestion.',
      array(), 'error');
  }

  // Return an error if no accounts are configured.
  $account_data = $account_id == 'all' ? _media_theplatform_mpx_get_account_data()
    : _media_theplatform_mpx_get_account_data($account_id);

  if (empty($account_data)) {
    return _drush_media_theplatform_mpx_print('There are no mpx accounts configured for this site.  Aborting mpx video ingestion.',
      array(), 'error');
  }

  // Special validation handling when processing all accounts.
  if ($account_id == 'all') {

    // Check if an ingestion process is already running for all accounts.
    if (!$forced_to_run && !lock_acquire('media_theplatform_mpx_ingest_videos_all')) {
      return _drush_media_theplatform_mpx_print('MPX video ingestion already running for all accounts.  Aborting.',
        array(), 'error');
    }

    if (!$forced_to_run) {
      foreach ($account_data as $id => $account) {
        // If a parent ingestion process is running for any account, return an error.
        if (!lock_may_be_available('media_theplatform_mpx_ingest_videos_' . $id)) {
          return _drush_media_theplatform_mpx_print('MPX video ingestion already running for account @id.  Aborting mpx video ingestion for all accounts.',
            array('@id' => $id), 'error');
        }
        // Throw an error if any of the account data is incorrect or absent.
        if (empty($account->import_account)) {
          return _drush_media_theplatform_mpx_print('Import account not set for account @id.  Aborting mpx video ingestion for all accounts.',
            array('@id' => $id), 'error');
        }
        if (!media_theplatform_mpx_check_token($account->id)) {
          return _drush_media_theplatform_mpx_print('MPX authentication token invalid for account @id.  Aborting mpx video ingestion for all accounts.',
            array('@id' => $id), 'error');
        }
        if (!media_theplatform_mpx_is_valid_player_for_account($account->default_player, $account)) {
          return _drush_media_theplatform_mpx_print('Default player invalid for account @id.  Aborting mpx video ingestion for all accounts.',
            array('@id' => $id), 'error');
        }
      }
    }

    return TRUE;
  }

  // Return an error if an mpx video ingestion for all accounts is running.
  if (!$forced_to_run && !lock_may_be_available('media_theplatform_mpx_ingest_videos_all')) {
    return _drush_media_theplatform_mpx_print('MPX video ingestion already running for all accounts.  Aborting mpx video ingestion for account @id.',
      array('@id' => $account_id), 'error');
  }

  // If a parent ingestion process is running for this account, return an error.
  if (!$forced_to_run && !lock_may_be_available('media_theplatform_mpx_ingest_videos_' . $id)) {
    return _drush_media_theplatform_mpx_print('MPX video ingestion already running for account @id.  Aborting mpx video ingestion.',
      array('@id' => $id), 'error');
  }

  // Throw an error if any of the account data is incorrect or absent.
  if (!$forced_to_run && empty($account_data->import_account)) {
    return _drush_media_theplatform_mpx_print('Import account not set for account @id.  Aborting mpx video ingestion.',
      array('@id' => $id), 'error');
  }
  if (!$forced_to_run && !media_theplatform_mpx_check_token($account_data->id)) {
    return _drush_media_theplatform_mpx_print('MPX authentication token invalid for account @id.  Aborting mpx video ingestion.',
      array('@id' => $id), 'error');
  }
  if (!$forced_to_run && !media_theplatform_mpx_is_valid_player_for_account($account_data->default_player, $account_data)) {
    return _drush_media_theplatform_mpx_print('Default player invalid for account @id.  Aborting mpx video ingestion.',
      array('@id' => $id), 'error');
  }

  return TRUE;
}

/**
 * Command handler for the `drush ingest-mpx-videos` command.
 */
function drush_media_theplatform_mpx_ingest_mpx_videos($account_id = 'all') {

  global $process_metadata;

  $start_time = time();

  // If the memory limit is set to less than 256M, increase it.
  $memory_limit = ini_get('memory_limit');
  // A memory_limit of -1 is unlimited.
  if ($memory_limit != -1) {
    $memory_limit_in_bytes = _media_theplatform_mpx_convert_shorthand_to_bytes($memory_limit);
    /*
    if ($memory_limit_in_bytes < 268435456) {
      ini_set('memory_limit', '256M');
    }
    //*/
    if ($memory_limit_in_bytes < 536870912) {
      ini_set('memory_limit', '512M');
    }
  }

  $parent_id = drush_get_option('x-parent-id');

  // A parent ID has been passed, so this is a child process.
  if ($parent_id) {
    return _drush_media_theplatform_mpx_start_child_process();
  }

  // If no parent ID is passed as a command option, this process should serve
  // as the top-level parent process that invokes the account handler processes,
  // which in-turn invoke the retriever and worker processes.

  // Acquire a lock to identify this as a parent process.
  $process_metadata = _drush_media_theplatform_mpx_acquire_parent_process_lock($account_id);

  // If a lock could not be acquired, bail.
  if (empty($process_metadata)) {
    return _drush_media_theplatform_mpx_print('Aborting video ingestion.', array(), 'error');
  }

  // Announce what this process will be doing, and get the relevant account data.
  if ($account_id == 'all') {
    $account_data = _media_theplatform_mpx_get_account_data();
    _drush_media_theplatform_mpx_print('Ingesting mpx videos for all configured accounts.');

    // Invoke the account handler processes that will be doing all of the work.
    _drush_media_theplatform_mpx_invoke_account_handler_processes($account_data);
  }
  else {
    $account_data = _media_theplatform_mpx_get_account_data($account_id);
    _drush_media_theplatform_mpx_print('Ingesting mpx videos for account @id "@name".',
      array('@id' => $account_id, '@name' => urldecode($account_data->import_account)));

    // Call the account handler function that will be doing all of the work.
    _drush_media_theplatform_mpx_process_video_ingestion_as_account_handler($account_data);
  }


  return _drush_media_theplatform_mpx_print('*** MPX video ingestion completed in @time seconds. ***',
    array('@time' => (time() - $start_time)));
}

/**
 * Helper that generates prefix for process messages.
 */
function _drush_media_theplatform_mpx_print_prefix() {

  global $process_metadata;

  $prefix = '';

  if ($parent_id = drush_get_option('x-parent-id')) {
    $child_id = drush_get_option('x-child-id', t('[Unknown ID]'));
    $child_type = drush_get_option('x-child-type', '[Unknown Type]');
    $prefix = dt('@type: child @child_id of parent @parent_id: ',
      array(
        '@type' => $child_type,
        '@child_id' => $child_id,
        '@parent_id' => $parent_id,
      ))
      . $prefix;
  }
  else {
    $prefix = dt('PARENT PROCESS: ') . $prefix;
  }

  if ($account_id = drush_get_option('x-account-id')) {
    $account = _media_theplatform_mpx_get_account_data($account_id);
    $prefix = dt('Account @id "@name": ',
      array(
        '@id' => $account_id,
        '@name' => urldecode($account->import_account),
      ))
      . $prefix;
  }

  return $prefix;
}

/**
 * Helper for outputting messages, automatically prefixing messages with
 * process information.
 */
function _drush_media_theplatform_mpx_print($message, $message_variables = array(), $type = 'status') {

  $prefix = &drupal_static(__FUNCTION__, _drush_media_theplatform_mpx_print_prefix());

  switch ($type) {

    case 'error':
      // watchdog() will output the error to the console for us.
      watchdog('drush_media_theplatform_mpx', $prefix . $message, $message_variables, WATCHDOG_ERROR);
      return FALSE;

    case 'warning':
      // watchdog() will output the error to the console for us.
      watchdog('drush_media_theplatform_mpx', $prefix . $message, $message_variables, WATCHDOG_WARNING);
      return FALSE;

    default:
      drush_print($prefix . dt($message, $message_variables), 2);
      return TRUE;
  }
}

/**
 * Helper function that assigns a lock name to a parent process, and acquires
 * the corresponding lock to maintain while the process is running.
 */
function _drush_media_theplatform_mpx_acquire_parent_process_lock($account_id) {

  $process_metadata = array();

  $base_lock_name = MEDIA_THEPLATFORM_MPX_VIDEO_INGESTION_LOCK_BASENAME . '_parent_' . $account_id;
  $lock_timeout = 600.0;

  // If this process is forced to run, append a sub-ID to the process lock name.
  // Arbitrary maximum of 1024 processes.  Anything more than that, and
  // something is probably wrong and the process shouldn't run.
  if (lock_acquire($base_lock_name, $lock_timeout)) {
    $process_metadata = array(
      'type' => 'parent',
      'id' => $account_id,
      'lock_name' => $base_lock_name,
    );
  }
  elseif (drush_get_option('force')) {
    for ($i = 1; $i <= 1024; $i++) {
      $lock_name = $base_lock_name . '_' . $i;
      if (lock_acquire($lock_name, $lock_timeout)) {
        $process_metadata = array(
          'type' => 'parent',
          'id' => $account_id . '-' . $i,
          'lock_name' => $lock_name,
        );
        break;
      }
    }
  }

  if (!empty($process_metadata['lock_name'])) {
    _drush_media_theplatform_mpx_print('@time: Lock "@lock" acquired for parent process for @timeout seconds.',
      array('@time' => time(), '@lock' => $process_metadata['lock_name'], '@timeout' => $lock_timeout));
  }
  else {
    _drush_media_theplatform_mpx_print('@time: Failed to acquire lock for parent process: @lock',
      array('@time' => time(), '@lock' => $base_lock_name), 'error');
  }

  return $process_metadata;
}

/**
 * thePlatform only stores notification sequence IDs for 7 days.  After that
 * period, they are expired and no longer any use.  If a notification
 * sequence ID is expired, reset it to NULL for this account, so that
 * a complete re-ingestion can be performed.
 */
function _drush_media_theplatform_mpx_check_notification_sequence_id($account) {

  $token = media_theplatform_mpx_signin($account->id);
  $url = 'https://read.data.media.theplatform.com/media/notify?token=' . $token
    . '&account=' . $account->import_account
    . '&clientId=drupal_media_theplatform_mpx_reports_' . $account->account_pid
    . '&since=' . $account->last_notification
    . '&block=false&filter=Media';

  $response = _media_theplatform_mpx_retrieve_feed_data($url);

  if (isset($response[0]['type']) && $response[0]['type'] == 'Exception') {

    _media_theplatform_mpx_set_field($account->id, 'last_notification', NULL);
    drupal_static_reset(MEDIA_THEPLATFORM_MPX_ACCOUNT_DATA_STATIC_CACHE);

    return _drush_media_theplatform_mpx_print('Last notification sequence ID is invalid.  Setting last_notification value to NULL to perform a complete re-ingestion for this account.', array(), 'error');;
  }

  return TRUE;
}

/**
 * Invoke account handler child processes.
 */
function _drush_media_theplatform_mpx_invoke_account_handler_processes($account_data) {

  global $process_metadata;

  $command_args = drush_get_arguments();
  array_shift($command_args);
  $process_count = drush_get_option('process-count', 10);
  $quit_when_complete = drush_get_option('quit-when-complete');

  // Set processing times for this parent process and each account handler.
  // Add 0.1 to avoid a bug due to microtime()'s lack of fine precision on
  // (some of) Acquia's servers.
  $parent_lock_timeout = 600.1;
  $child_max_processing_time = 480;

  $invocation_command = array(
    'site' => '@self',
    'command' => 'mpxv',
    'args' => array(),
    'options' => array(
      'x-child-type' => 'account_handler',
      'x-parent-id' => $process_metadata['id'],
      'x-sibling-count' => count($account_data),
      'x-max-processing-time' => $child_max_processing_time,
      'process-count' => $process_count,
      'quit-when-complete' => (int) $quit_when_complete,
      'force' => 1,
    ),
  );

  $invocations = array();
  $lock_id_counter = 0;

  foreach ($account_data as $account) {
    $command = $invocation_command;
    $command['options']['x-child-id'] = ++$lock_id_counter;
    $command['options']['x-account-id'] = $account->id;
    $invocations[] = $command;
  }

  do {

    if (!lock_acquire($process_metadata['lock_name'], $parent_lock_timeout)) {
      return _drush_media_theplatform_mpx_print('Unable to (re-)acquire lock "@lock" for parent process.  Aborting video ingestion.',
        array('@lock' => $process_metadata['lock_name']), 'error');
    }

    _drush_media_theplatform_mpx_print('Invoking @count child account handler processes.',
      array('@count' => count($invocations)));

    drush_backend_invoke_concurrent($invocations, array(), array('concurrency' => count($invocations)));
  }
  while (!$quit_when_complete);
}

/**
 * Invoke the processes that retrieve video data from thePlatform and process
 * the cron queue items created from this retrieved data.
 */
function _drush_media_theplatform_mpx_invoke_retriever_and_processes($account, $max_processing_time = 300, $request_item_limit = NULL) {

  global $process_metadata;

  if (!_drush_media_theplatform_mpx_check_child_processes($max_processing_time * 1.25)) {
    return _drush_media_theplatform_mpx_print('Aborting retriever and worker process invocation.', array(), 'warning');
  }

  if (!$request_item_limit) {
    $request_item_limit = media_theplatform_mpx_variable_get('drush_video_ingestion_request_item_limit', 100);
  }

  // Ensure the batch and cron queues exist, and initialize them.
  DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id)->createQueue();
  $request_queue = DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id);
  DrupalQueue::get('media_theplatform_mpx_video_cron_queue')->createQueue();
  $cron_queue = DrupalQueue::get('media_theplatform_mpx_video_cron_queue');

  $command_args = drush_get_arguments();
  array_shift($command_args);
  $account_id = drush_get_option('x-account-id', 1);

  // Vary the number of invoked processes by the number of queue items.
  $request_queue_item_count = $request_queue->numberOfItems();
  $cron_queue_item_count = $cron_queue->numberOfItems();
  $max_process_count = drush_get_option('process-count', 10);
  // If the max process count is already at the minimum, that's what it has to be.
  if ($max_process_count == 2) {
    $process_count = $max_process_count;
  }
  // Often when change notifications are being processed, there will only be
  // a single request queue item created.  Prevent the full gamut of retriever
  // and worker processes from being invoked, if there isn't that much to do.
  // e.g. if ($request_queue_item_count == 2 && $cron_queue_item_count <= (2 * $request_item_limit))
  elseif ($cron_queue_item_count <= ($request_queue_item_count * $request_item_limit)
      && ($request_queue_item_count * 2) < $max_process_count) {
    $process_count = $request_queue_item_count * 2;
  }
  elseif ($cron_queue_item_count <= ((2 * $request_queue_item_count) * $request_item_limit)
      && ($request_queue_item_count * 4) < $max_process_count) {
    $process_count = $request_queue_item_count * 4;
  }
  elseif ($cron_queue_item_count <= ((3 * $request_queue_item_count) * $request_item_limit)
      && ($request_queue_item_count * 6) < $max_process_count) {
    $process_count = $request_queue_item_count * 6;
  }
  // If there is a lot to be done, simply call the full gamut.
  else {
    $process_count = $max_process_count;
  }

  $invocation_command = array(
    'site' => '@self',
    'command' => 'mpxv',
    'args' => $command_args,
    'options' => array(
      'x-parent-id' => !empty($process_metadata['id']) ? $process_metadata['id'] : 0,
      'x-account-id' => $account->id,
      'x-sibling-count' => $process_count,
      'x-max-processing-time' => (int) $max_processing_time,
      'process-count' => $process_count,
      'quit-when-complete' => 1,
      'force' => 1,
    ),
  );

  $invocations = array();
  $lock_id_counter = 0;

  for ($i = 0; $i < ceil($process_count / 2); $i++) {
    foreach (array('retriever', 'worker') as $type) {
      $command = $invocation_command;
      $command['options']['x-child-id'] = ++$lock_id_counter;
      $command['options']['x-child-type'] = $type;
      $invocations[] = $command;
    }
  }

  while ($request_queue->numberOfItems() || $cron_queue->numberOfItems()) {
    if (!_drush_media_theplatform_mpx_check_child_processes()) {
      return _drush_media_theplatform_mpx_print('Aborting child retriever and worker process invocation.', array(), 'warning');
    }
    _drush_media_theplatform_mpx_print('Invoking @count child retriever and worker processes to process request and cron queue items.',
      array('@count' => count($invocations)));
    drush_backend_invoke_concurrent($invocations, array(), array('concurrency' => count($invocations)));
  }

  return TRUE;
}

/**
 * Calls process handler function for the appropriate child process type, as well
 * as handles the lock for the child process.
 */
function _drush_media_theplatform_mpx_start_child_process() {

  global $process_metadata;

  $parent_id = drush_get_option('x-parent-id');
  $child_id = drush_get_option('x-child-id');
  $child_type = drush_get_option('x-child-type');
  $account_id = drush_get_option('x-account-id');
  $process_count = drush_get_option('process-count', 10);
  $sibling_count = drush_get_option('x-sibling-count', $process_count);
  $max_processing_time = drush_get_option('x-max-processing-time', 90.0);
  $initial_lock_timeout = 60.0;

  if (!$parent_id) {
    return _drush_media_theplatform_mpx_print('Unable to determine parent ID.  Aborting child process.', array(), 'error');
  }
  if (!$child_id) {
    return _drush_media_theplatform_mpx_print('Unable to determine child ID.  Aborting child process.', array(), 'error');
  }
  if (!$child_type) {
    return _drush_media_theplatform_mpx_print('Unable to determine child type.  Aborting child process.', array(), 'error');
  }
  if (!$account_id) {
    return _drush_media_theplatform_mpx_print('Unable to determine account ID.  Aborting child process.', array(), 'error');
  }

  $account = _media_theplatform_mpx_get_account_data($account_id);

  $child_lock_name = MEDIA_THEPLATFORM_MPX_VIDEO_INGESTION_LOCK_BASENAME . '_' . $parent_id . '_' . $child_id;

  if (!lock_acquire($child_lock_name, $initial_lock_timeout)) {
    $message_variables = array(
      '@time' => time(),
      '@name' => $child_lock_name,
      '@type' => $child_type,
    );
    return _drush_media_theplatform_mpx_print('@time: Unable to acquire lock "@name".  Aborting @type process.',
      $message_variables, 'error');
  }

  $message_variables = array(
    '@time' => time(),
    '@name' => $child_lock_name,
    '@timeout' => $initial_lock_timeout,
  );
  _drush_media_theplatform_mpx_print('@time: Successfully acquired lock "@name" for @timeout seconds.', $message_variables);

  $process_metadata = array(
    'type' => 'child',
    'parent_id' => $parent_id,
    'child_type' => $child_type,
    'child_id' => $child_id,
    'account' => $account,
    'lock_name' => $child_lock_name,
    'process_count' => $process_count,
    'sibling_count' => $sibling_count,
    'max_processing_time' => $max_processing_time,
  );

  $function = '_drush_media_theplatform_mpx_process_video_ingestion_as_' . $child_type;

  if (!function_exists($function)) {
    return _drush_media_theplatform_mpx_print('Unable to find child process function.  Aborting.', array(), 'error');
  }

  $function($account);

  lock_release($child_lock_name);

  return _drush_media_theplatform_mpx_print('@time: Released lock "@name".  Ending process.',
    array('@time' => time(), '@name' => $child_lock_name));
}

/**
 * Checks that all sibling processes are still running.  If this fails, the
 * assumption is that the current process should shutdown.  It then checks
 * that the current child process is able to re-acquire its own lock.
 */
function _drush_media_theplatform_mpx_check_child_processes($lock_timeout = 60.0) {

  global $process_metadata;

  static $start_time = NULL;

  if (!$start_time) {
    $start_time = time();
  }

  if (!$lock_timeout) {
    $lock_timeout = 60.0;
  }

  $max_processing_time = drush_get_option('x-max-processing-time');
  $run_time = time() - $start_time;

  if ($process_metadata['type'] != 'parent' && $run_time >= $max_processing_time) {
    return _drush_media_theplatform_mpx_print('@time: Process has run for @runtime second(s), over its maximum processing time of @max seconds.',
      array('@time' => $run_time, '@max' => $max_processing_time), 'warning');
  }

  // Check that all sibling processes are still running, by checking if their
  // locks are available.  If the sibling process lock is available, it's safe
  // to assume that it has quit.  But only do this after giving them enough time
  // (1 second per sibling) to be invoked by the parent process.
  if (!empty($process_metadata['sibling_count']) && $run_time > $process_metadata['sibling_count']) {
    for ($i = 1; $i <= $process_metadata['sibling_count']; $i++) {
      $sibling_lock_name = MEDIA_THEPLATFORM_MPX_VIDEO_INGESTION_LOCK_BASENAME . '_' . $process_metadata['parent_id'] . '_' . $i;
      if ($sibling_lock_name != $process_metadata['lock_name'] && lock_may_be_available($sibling_lock_name)) {
        $message = '@time: A sibling process with lock "@lock" has quit.  Stopping process to restart all sibling processes.';
        $message_variables = array(
          '@time' => time(),
          '@lock' => $sibling_lock_name,
        );

        return _drush_media_theplatform_mpx_print($message, $message_variables, 'warning');
      }
    }
  }

  // Once the sibling process check is successful, re-acquire the lock for
  // this child process.
  $message_variables = array(
    '@time' => time(),
    '@name' => $process_metadata['lock_name'],
    '@timeout' => $lock_timeout,
  );

  // usleep() for 1/100th of a second to avoid a bug due to microtime()'s lack of
  // fine precision on (some of) Acquia's servers.
  usleep(10000);

  if (!lock_acquire($process_metadata['lock_name'], $lock_timeout)) {
    return _drush_media_theplatform_mpx_print('@time: Unable to re-acquire lock "@name".', $message_variables, 'error');
  }

  return _drush_media_theplatform_mpx_print('@time: Re-acquired lock "@name" for @timeout seconds.', $message_variables);
}

/**
 * Performs a new account video ingestion or a complete video re-ingestion.
 */
function _drush_media_theplatform_mpx_process_complete_video_ingestion($account) {

  $start_time = time();

  // Ensure the request queue exists.  No harm in re-creating it if it does.
  DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id)->createQueue();
  $request_queue = DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id);

  // Populate the request queue for the complete ingestion process if not done
  // so already by another process.
  if (!$request_queue->numberOfItems()) {

    _drush_media_theplatform_mpx_print('Starting complete video (re-)ingestion.');
    _drush_media_theplatform_mpx_print('Adding request queue item(s) for complete video (re-)ingestion.');

    // The default 100 item limit was found to be the most performant. The
    // wait/reward is less ideal when requesting more or less video data at
    // a time.  Though this could be different for non-test environments.
    $request_item_limit = media_theplatform_mpx_variable_get('drush_video_ingestion_request_item_limit', 100);
    $request_count = $account->proprocessing_batch_item_count;
    $current_batch_item = (int) $account->proprocessing_batch_current_item;

    for ($i = $current_batch_item; $i < $request_count; $i += $request_item_limit) {
      $range = $i . '-' . ($i + ($request_item_limit - 1));
      $request_queue_item = array(
        'request_url' => $account->proprocessing_batch_url,
        'request_range' => $range,
        'request_count' => $request_count,
        'account' => $account,
      );
      $request_queue->createItem($request_queue_item);
    }

    _drush_media_theplatform_mpx_print('Created @count video request queue item(s) for a complete (re-)ingestion.',
      array('@count' => $request_queue->numberOfItems()));
  }
  else {

    _drush_media_theplatform_mpx_print('Continuing complete video (re-)ingestion.');
  }

  // Invoke the child retriever and worker processes that will actually request
  // and process the video data.
  _drush_media_theplatform_mpx_invoke_retriever_and_processes($account, 480);

  // Reset the batch system variables to indicate the ingestion is done.
  if (!$request_queue->numberOfItems()) {
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_url', '');
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_item_count', 0);
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_current_item', 0);

    if (!$account->last_notification) {
      media_theplatform_mpx_set_last_notification($account);
    }

    return _drush_media_theplatform_mpx_print('Full video (re-)ingestion completed.');
  }
  // Otherwise, something went wrong with the retriever and worker processes.
  else {
    return _drush_media_theplatform_mpx_print('Complete (re-)ingestion was unable to complete.  Process will continue from where it left off during the next invocation.', array(), 'warning');
  }
}

/**
 * Parse notification data for updated and deleted videos, and create
 * request queue and cron queue items from this parsed data to be processed
 * by retriever and worker child processes.
 */
function _drush_media_theplatform_mpx_process_notification_data($notification_data, $account) {

  // Ensure the request and cron queues exist, and initialize them.
  DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id)->createQueue();
  $request_queue = DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id);
  DrupalQueue::get('media_theplatform_mpx_video_cron_queue')->createQueue();
  $cron_queue = DrupalQueue::get('media_theplatform_mpx_video_cron_queue');

  $updated_video_ids = array();
  $deleted_video_ids = array();
  $last_notification_id = NULL;
  $request_queue_item_count = 0;
  $cron_queue_item_count = 0;

  // Parse the notification data for updated and deleted videos.
  foreach ($notification_data as $notification) {
    $last_notification_id = $notification['id'];
    if (!empty($notification['entry']['id']) && !empty($notification['method'])) {
      $video_id = basename($notification['entry']['id']);
      if ($notification['method'] == 'delete') {
        $deleted_video_ids[$video_id] = $video_id;
      }
      else {
        $updated_video_ids[$video_id] = $video_id;
      }
    }
  }

  // Queue the updated video request items for the retriever process.
  if (!empty($updated_video_ids)) {
    $request_item_limit = media_theplatform_mpx_variable_get('drush_video_notification_ingestion_request_item_limit', 100);
    for ($i = 0; $i < count($updated_video_ids); $i += $request_item_limit) {
      $request_video_ids = array_slice($updated_video_ids, $i, $request_item_limit);
      $request_ids = implode(',', $request_video_ids);
      $request_queue_item = array(
        'request_url' => _media_theplatform_mpx_get_video_feed_url($request_video, $account),
        'request_ids' => $request_ids,
        'request_count' => count($request_video_ids),
        'account' => $account,
      );
      $request_queue->createItem($request_queue_item);
      $request_queue_item_count++;
    }
  }

  // Queue the deleted videos for the worker process.
  foreach ($deleted_video_ids as $video_id) {
    $cron_queue_item = array(
      'queue_operation' => 'delete',
      'delete_id' => $video_id,
      'account' => $account,
    );
    $cron_queue->createItem($cron_queue_item);
    $cron_queue_item_count++;
  }

  _drush_media_theplatform_mpx_print('Created @count request queue item(s).', array('@count' => $request_queue_item_count));
  _drush_media_theplatform_mpx_print('Created @count cron queue item(s).', array('@count' => $cron_queue_item_count));

  // Invoke the retriever and worker processes to actually ingest the videos.
  if ($request_queue_item_count || $cron_queue_item_count) {
    _drush_media_theplatform_mpx_invoke_retriever_and_processes($account, 300, 25);
  }

  media_theplatform_mpx_set_last_notification($account, $last_notification_id);

  return $last_notification_id;
}

/**
 * Either request or listen for change notifications returned from thePlatform,
 * depending on the value of $blocking.
 */
function _drush_media_theplatform_mpx_process_outstanding_video_notifications($account, $blocking = FALSE) {

  global $process_metadata;

  // The timeout setting is hardly respected by drupal_http_request().  Set
  // a 2-minute lock timeout to be sure it doesn't expire while the request
  // is being made.
  if (!_drush_media_theplatform_mpx_check_child_processes(120.0)) {
    return _drush_media_theplatform_mpx_print('Aborting video change notification processing.', array(), 'warning');
  }

  $memory_limit = ini_get('memory_limit');
  $memory_limit_in_bytes = _media_theplatform_mpx_convert_shorthand_to_bytes($memory_limit);

  // Set a timer.
  $start_time = time();

  // Build the notification service request base URL.
  $last_notification_id = $account->last_notification;
  $base_update_url = 'https://read.data.media.theplatform.com/media/notify?account=' . $account->import_account
    . '&clientId=drupal_drush_media_theplatform_mpx_' . $account->account_pid
    . '&block=' . ($blocking ? 'true' : 'false')
    . '&filter=Media&size=500';

  // Build the URL for the next notication service request.
  $token = media_theplatform_mpx_check_token($account->id);
  $url = $base_update_url . '&since=' . $last_notification_id . '&token=' . $token;

  $message_variables = array(
    '@action' => ($blocking ? dt('Listening for') : dt('Requesting')),
    '!url' => $url,
  );
  _drush_media_theplatform_mpx_print('@action video change notification data from: !url', $message_variables);

  $default_request_timeout = media_theplatform_mpx_variable_get('drush_video_ingestion_notification_timeout', 20.0);
  $request_options = array('timeout' => $default_request_timeout);

  // The $blocking check needs to come second, otherwise the request is never made.
  while (($result_data = _media_theplatform_mpx_retrieve_feed_data($url, TRUE, $request_options)) || $blocking) {

    // If not listening for change notifications, return on any exception
    // returned by thePlatform.
    if (!$blocking && isset($result_data[0]['type']) && $result_data[0]['type'] == 'Exception') {
      return _drush_media_theplatform_mpx_print("Exception returned from thePlatform: \n@e\n.  Aborting change notification processing.",
        array('@e' => print_r($result_data, TRUE)), 'error');
    }

    // If not listening for change notifications, return if the first
    // notification ID returned is the same as the notification ID we processed
    // in the previous request.
    if (!$blocking && isset($result_data[0]['id']) && $result_data[0]['id'] == $last_notification_id) {
      return _drush_media_theplatform_mpx_print('Notification sequence ID is the same as the ID already processed.  Finished processing video change notifications.');
    }

    // If not listening for change notification, and if all that is returned
    // by thePlatform is a notification ID, save it for this account and break.
    if (!$blocking && !empty($result_data[0]['id']) && count($result_data) == 1 && count($result_data[0]) == 1) {
      media_theplatform_mpx_set_last_notification($account, $result_data[0]['id']);

      return _drush_media_theplatform_mpx_print('Only a sequence ID was returned in the change notification data.  Saving ID for this account.  Finished processing video change notifications.');
    }

    $message = "Notification data returned by thePlatform: \n!data\n  ^ End of notification data. ^";
    _drush_media_theplatform_mpx_print($message, array('!data' => print_r($result_data, TRUE)));

    if (!_drush_media_theplatform_mpx_check_child_processes()) {
      return _drush_media_theplatform_mpx_print('Aborting video change notification data processing.', array(), 'warning');
    }

    // Process the notification data, if any was returned.
    if (!empty($result_data)) {
      $last_notification_id = _drush_media_theplatform_mpx_process_notification_data($result_data, $account);
    }

    $message_variables = array(
      '@time' => (time() - $start_time),
      '@mem' => round(memory_get_peak_usage(TRUE) / 1048576),
    );
    _drush_media_theplatform_mpx_print('Finished processing outstanding video change notifications in @time seconds.  Peak memory usage is @mem MB.', $message_variables);

    // Check memory usage.  Return if getting too close to the current limit.
    $peak_mem_usage = memory_get_peak_usage(TRUE);
    if (($peak_mem_usage / $memory_limit_in_bytes) > 0.80) {
      return _drush_media_theplatform_mpx_print('Peak real memory usage at @pct% of memory limit.  Aborting video change notification request.',
        array('@pct' => round(($peak_mem_usage / $memory_limit_in_bytes) * 100)), 'warning');
    }

    // Reset the timer.
    $start_time = time();

    // The timeout setting is hardly respected by drupal_http_request().  Set
    // a 2-minute lock timeout to be sure it doesn't expire while the request
    // is being made.
    if (!_drush_media_theplatform_mpx_check_child_processes(120.0)) {
      return _drush_media_theplatform_mpx_print('Aborting video change notification request.', array(), 'warning');
    }

    // Build the URL for the next notication service request.
    $token = media_theplatform_mpx_check_token($account->id);
    $url = $base_update_url . '&since=' . $last_notification_id . '&token=' . $token;

    $message_variables = array(
      '@action' => ($blocking ? dt('Listening for') : dt('Requesting')),
      '!url' => $url,
    );
    _drush_media_theplatform_mpx_print('@action video change notification data from: !url', $message_variables);
  }

  return TRUE;
}

/**
 * Ingestion handler for accounts that run a complete (re-)ingestion if
 * necessary and then request change notifications.
 */
function _drush_media_theplatform_mpx_process_video_ingestion_as_account_handler($account) {

  global $process_metadata;

  // Account handlers will act as parent processes to the child retriever and
  // worker processes they invoke.  Acquire a parent lock, and set
  // $process_metadata['id'] to indicated this is a parent process, if not already.
  if (empty($process_metadata['id'])) {
    $parent_process_metadata = _drush_media_theplatform_mpx_acquire_parent_process_lock($account->id);
    if (empty($parent_process_metadata)) {
      return _drush_media_theplatform_mpx_print('Aborting account processing.', array(), 'error');
    }
    $process_metadata['id'] = $parent_process_metadata['id'];
  }

  // Ensure we have a valid notification sequence ID.  If not, then
  // reset the last notification value and start a complete video ingestion.
  if (!_drush_media_theplatform_mpx_check_notification_sequence_id($account)) {
    // Retrieve current account data.
    $account = _media_theplatform_mpx_get_account_data($account->id);
  }

  // If a batch url stored or a last_notification sequence ID is not stored,
  // start an initial ingestion, or a complete re-ingestion.
  if (empty($account->last_notification) || $account->proprocessing_batch_url) {
    _drush_media_theplatform_mpx_process_complete_video_ingestion($account);
  }

  // Check if there are any cron queue items left, and process them.  If the
  // complete (re-)ingestion could not complete, this can act as a second
  // attempt to complete it.
  _drush_media_theplatform_mpx_invoke_retriever_and_processes($account);

  $quit_when_complete = drush_get_option('quit-when-complete');

  // If the command is supposed to quit after pulling updates, do not
  // establish a polling connection to thePlatform, and simply retrieve and
  // process all outstanding updates instead.
  if ($account->last_notification && $quit_when_complete) {
    return _drush_media_theplatform_mpx_process_outstanding_video_notifications($account);
  }
  // Begin listening for change notifications.
  elseif ($account->last_notification) {
    return _drush_media_theplatform_mpx_process_outstanding_video_notifications($account, TRUE);
  }
  // Something is wrong if we don't have a last_notification value by this point.
  else {
    return _drush_media_theplatform_mpx_print('Account missing a last_notification value.', array(), 'error');
  }
}

/**
 * Perform actual requests for video data from thePlatform, retrying the
 * request up to 20 times until the request is successful.
 */
function _drush_media_theplatform_mpx_request_video_data($request_queue_item) {

  $request_timeout = media_theplatform_mpx_variable_get('drush_video_ingestion_request_timeout', 60);

  $request_url = $request_queue_item->data['request_url'];
  $request_account = $request_queue_item->data['account'];
  $request_count = $request_queue_item->data['request_count'];

  if ($request_queue_item->data['request_range']) {
    $request_range = $request_queue_item->data['request_range'];
    $base_url = $request_url . '&range=' . $request_range;
  }
  elseif ($request_queue_item->data['request_ids']) {
    $request_range = $request_queue_item->data['request_ids'];
    $base_url = $request_url . '&byId=' . str_replace(',', '|', $request_range);
  }
  else {
    $request_queue->releaseItem($request_queue_item);

    return _drush_media_theplatform_mpx_print('Request range or IDs missing from request queue item.  Aborting request queue processing.', array(), 'error');
  }

  // Rertrieve the video data with a maximum of 20 request attempts.
  for ($i = 0; $i < 20; $i++) {

    if (!_drush_media_theplatform_mpx_check_child_processes(30)) {
      return _drush_media_theplatform_mpx_print('Aborting request queue processing.', array(), 'warning');
    }

    // Retrieve the authentication token for the media data service request.
    $token = media_theplatform_mpx_check_token($request_account->id);

    if (!$token) {
      _drush_media_theplatform_mpx_print('Failed to retrieve authentication token.  Trying again.', array(), 'error');
      continue;
    }

    // Setting a lock timeout with a buffer above the request timeout.
    if (!_drush_media_theplatform_mpx_check_child_processes($request_timeout * 1.3)) {
      return _drush_media_theplatform_mpx_print('Aborting request queue processing.', array(), 'warning');
    }

    // Construct the url for the media data service request.
    $url = $base_url . '&token=' . $token;

    $message = 'Retrieving videos @range of @total via drush from url:  !url.';
    $message_variables = array(
      '@range' => $request_range,
      '@total' => $request_count,
      '!url' => $url,
    );
    _drush_media_theplatform_mpx_print($message, $message_variables);

    // Retrieve the video data from thePlatform's media data service.
    $result_data = array();

    try {
      $result_data = _media_theplatform_mpx_retrieve_feed_data($url, TRUE, array('timeout' => $request_timeout));
    }
    catch (Exception $e) {
      _drush_media_theplatform_mpx_print("Exception thrown when retrieving video data: \n!e\n", array('!e' => $e->getMessage()));
      continue;
    }

    // If the result data is not empty, the request was successful and we have
    // data to process.  Otherwise, try the request again in case of a timeout
    // or other problem another request attempt will resolve.
    if (!empty($result_data)) {
      break;
    }
    else {
      _drush_media_theplatform_mpx_print('Missing or invalid data returned from media data service request.  Trying again.',
        array(), 'error');
    }
  }

  return $result_data;
}

/**
 * Processes request queue items to retrieve video data from thePlatform, and
 * create cron queue items from the data.
 */
function _drush_media_theplatform_mpx_process_video_ingestion_as_retriever($account) {

  global $process_metadata;

  $memory_limit = ini_get('memory_limit');
  $memory_limit_in_bytes = _media_theplatform_mpx_convert_shorthand_to_bytes($memory_limit);
  $request_queue = DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id);
  $cron_queue = DrupalQueue::get('media_theplatform_mpx_video_cron_queue');

  // Grab an item from the batch queue and queue up the videos retrieved.
  while ($request_queue->numberOfItems() || $cron_queue->numberOfItems()) {

    if (!_drush_media_theplatform_mpx_check_child_processes()) {
      return _drush_media_theplatform_mpx_print('Aborting request queue processing.', array(), 'warning');
    }

    // Reset expired items in the queue table.
    db_update('queue')
      ->fields(array('expire' => 0))
      ->condition('expire', 0, '<>')
      ->condition('expire', time(), '<')
      ->execute();

    // Claim an actual request queue item.
    $request_queue_item = $request_queue->claimItem();

    // If a request queue item could not be claimed, then either the request
    // queue has not been populated yet (and being done so by another process),
    // or mor likely, the request queue is empty and the worker process(es) are
    // busy churning through the cron queue.  In the latter case, wait until the
    // worker process(es) are finished before leaving this function and quitting
    // this process.
    if (!$request_queue_item) {
      _drush_media_theplatform_mpx_print('Waiting for request queue item(s) to become available for processing, or for sibling worker processes to complete processing the cron queue.');
      sleep(1);
      continue;
    }

    $start_time = time();

    // Request the video data to be processed.
    $result_data = _drush_media_theplatform_mpx_request_video_data($request_queue_item);

    // Ensure we have data and that an exception was not returned from
    // thePlatform.  In either case, release the request queue item and start
    // over.
    if (!is_array($result_data) || empty($result_data)) {
      $request_queue->releaseItem($request_queue_item);
      _drush_media_theplatform_mpx_print('No video data returned from thePlatform.', array(), 'warning');
      continue;
    }
    elseif (isset($result_data['isException']) && $result_data['isException'] === TRUE) {
      $request_queue->releaseItem($request_queue_item);
      _drush_media_theplatform_mpx_print("Exception returned by thePlatform: \n@e\n",
        array('@e' => print_r($result_data, TRUE)), 'error');
      continue;
    }

    // Parse the video data and create the cron queue items for the worker
    // process(es).
    $entries = array();
    $published_video_ids = array();

    // If multiple videos are returned, they should be in the "entries" element.
    // This can be the case for a single returned video as well.
    if (!empty($result_data['entryCount']) && !empty($result_data['entries'])) {
      $entries = $result_data['entries'];
    }
    // A single returned video's data can also be the entire response.
    elseif (!empty($result_data['entryCount'])) {
      $entries = array($result_data);
    }
    // If neither of the above cases apply, then no video data was returned.
    else {
      $entries = array();
    }

    // Create the cron queue items for each updated/created video for the worker
    // process(es).
    foreach ($entries as $data) {
      if (empty($data) || empty($data['id'])) {
        _drush_media_theplatform_mpx_print('Video data entry is empty.', array(), 'error');
        drush_print_r($data);
        _drush_media_theplatform_mpx_print('  ^ End of returned video data.', array(), 'error');
        continue;
      }
      $published_video_ids[] = basename($data['id']);
      $item = array(
        'queue_operation' => 'publish',
        'video' => $data,
        'account' => $request_queue_item->data['account'],
      );
      $cron_queue->createItem($item);
    }

    // If a response contains less videos than were requested, then the videos
    // that were not returned should be unpublished.
    if (isset($result_data['entryCount']) && ($result_data['entryCount'] != $request_queue_item->data['request_count'])) {
      foreach (explode(',', $request_queue_item->data['request_ids']) as $video_id) {
        if (!in_array($video_id, $published_video_ids)) {
          $item = array(
            'queue_operation' => 'unpublish',
            'delete_id' => $video_id,
            'account' => $request_queue_item->data['account'],
          );
          $cron_queue->createItem($item);
        }
      }
    }

    // Delete the request queue item.  It has been successfully processed.
    $request_queue->deleteItem($request_queue_item);

    $message = 'Retreiving and parsing video data via drush took @time seconds.  Peak memory usage is @mem MB.';
    $message_variables = array(
      '@time' => (time() - $start_time),
      '@mem' => round(memory_get_peak_usage(TRUE) / 1048576),
    );
    _drush_media_theplatform_mpx_print($message, $message_variables);

    // Check memory usage.  Return if getting too close to the current limit.
    $peak_mem_usage = memory_get_peak_usage(TRUE);
    if (($peak_mem_usage / $memory_limit_in_bytes) > 0.80) {
      return _drush_media_theplatform_mpx_print('Peak real memory usage at @pct% of memory limit.  Aborting video change notification request.',
        array('@pct' => round(($peak_mem_usage / $memory_limit_in_bytes) * 100)), 'warning');
    }

    _drush_media_theplatform_mpx_print('There are @count item(s) in the request ingestion queue.',
      array('@count' => $request_queue->numberOfItems()));
  }
}

/**
 * Processes cron queue items to create, update, or delete videos.
 */
function _drush_media_theplatform_mpx_process_video_ingestion_as_worker($account) {

  global $process_metadata;

  $memory_limit = ini_get('memory_limit');
  $memory_limit_in_bytes = _media_theplatform_mpx_convert_shorthand_to_bytes($memory_limit);
  $request_queue = DrupalQueue::get('media_theplatform_mpx_video_request_queue_' . $account->id);
  $cron_queue = DrupalQueue::get('media_theplatform_mpx_video_cron_queue');
  $request_item_limit = media_theplatform_mpx_variable_get('drush_video_ingestion_request_item_limit', 100);

  while ($request_queue->numberOfItems() || $cron_queue->numberOfItems()) {

    if (!_drush_media_theplatform_mpx_check_child_processes()) {
      return _drush_media_theplatform_mpx_print('Aborting cron queue processing.', array(), 'warning');
    }

    // Reset expired items in the queue table.
    db_update('queue')
      ->fields(array('expire' => 0))
      ->condition('expire', 0, '<>')
      ->condition('expire', time(), '<')
      ->execute();

    $start_time = time();
    $item_count = 0;

    // Process cron queue items while they are available.
    while ($item_count < $request_item_limit && $item = $cron_queue->claimItem()) {

      $message_variables = array(
        '@id' => $item->item_id,
        '@video_id' => !empty($item->data['video']['id']) ? basename($item->data['video']['id']) : dt('[UKNOWN ID]'),
        '@video_title' => !empty($item->data['video']['title']) ? $item->data['video']['title'] : dt('[UKNOWN TITLE]'),
      );
      _drush_media_theplatform_mpx_print('Processing cron queue item @id, video @video_id "@video_title".', $message_variables);

      try {
        process_media_theplatform_mpx_video_cron_queue_item($item->data);
        $cron_queue->deleteItem($item);
        $item_count++;
      }
      catch (Exception $e) {
        $cron_queue->releaseItem($item);
        $message_variables = array(
          '@e' => $e->getMessage(),
          '!data' => print_r($item_data, TRUE),
        );
        _drush_media_theplatform_mpx_print("Exception \"@e\" thrown when processing cron queue item data: \n!data\n",
          $message_variables, 'error');
      }

      // Re-acquire lock every 10 queue items.
      if ($item_count && !($item_count % 10) && !_drush_media_theplatform_mpx_check_child_processes()) {
        return _drush_media_theplatform_mpx_print('Aborting cron queue processing.', array(), 'warning');
      }

      // Check peak memory usage every 25 items.  Break if it's getting too
      // close to the memory limit.
      if ($item_count && !($item_count % 25) && (memory_get_peak_usage(TRUE) / $memory_limit_in_bytes) > 0.80) {
        return _drush_media_theplatform_mpx_print('Peak real memory usage at @pct% of memory limit.  Aborting cron queue processing.',
          array('@pct' => round((memory_get_peak_usage(TRUE) / $memory_limit_in_bytes) * 100)), 'warning');
      }
    }

    // If no cron queue items could be processed, the cron queue is likely empty.
    // Wait a second for the cron queue to be populated by a retriever process
    // and try again.
    if (!$item_count) {
      _drush_media_theplatform_mpx_print('Waiting for cron queue item to become available for processing.');
      sleep(1);
      continue;
    }

    _drush_media_theplatform_mpx_print('There are @count item(s) in the cron ingestion queue.',
      array('@count' => $cron_queue->numberOfItems()));

    $message = 'Processing @count cron queue items via drush took @time seconds.  Peak memory usage is @mem MB.';
    $message_variables = array(
      '@count' => $item_count,
      '@time' => (time() - $start_time),
      '@mem' => round(memory_get_peak_usage(TRUE) / 1048576),
    );
    _drush_media_theplatform_mpx_print($message, $message_variables);
  }
}
