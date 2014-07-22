<?php
/**
 * @file
 * functions for Videos.
 */


/**
 * Query thePlatform Media Notify service to get Media id's that have changed.
 *
 * @param String $since
 *   The last notfication ID from thePlatform used to Sync mpxMedia.
 *
 * @return Array
 *   Array of mpxMedia id's that have changed since $since.
 */
function media_theplatform_mpx_get_changed_ids($account) {

  $token = media_theplatform_mpx_check_token($account->id);
  $feed_request_item_limit = media_theplatform_mpx_variable_get('cron_videos_per_run', 250);

  $url = 'https://read.data.media.theplatform.com/media/notify?token=' . $token .
    '&account=' . $account->import_account .
    '&block=false&filter=Media&clientId=drupal_media_theplatform_mpx_' . $account->account_pid .
    '&since=' . $account->last_notification .
    '&size=' . $feed_request_item_limit;

  watchdog('media_theplatform_mpx', 'Retrieving changed media IDs for @account.',
    array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_INFO);

  $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

  if (empty($result_data)) {
    return FALSE;
  }

  // Initalize arrays to store active and deleted ID's.
  $actives = array();
  $deletes = array();
  $last_notification = NULL;

  foreach ($result_data as $changed) {
    // Store last notification.
    if ($changed['id']) {
      $last_notification = $changed['id'];
    }

    // If no method has been returned, there are no changes.
    if (!isset($changed['method'])) {
      watchdog('media_theplatform_mpx', 'Fetching changed media IDs for @account returned no changes.  "method" field value not set.',
        array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_INFO);

      break;
    }
    // Grab the last component of the URL.
    $media_id = basename($changed['entry']['id']);
    if ($changed['method'] !== 'delete') {
      // If this entry id isn't already in actives, add it.
      if (!in_array($media_id, $actives)) {
        $actives[] = $media_id;
      }
    }
    else {
      // Only add to deletes array if this mpxMedia already exists.
      $video_exists = media_theplatform_mpx_get_mpx_video_by_field('id', $media_id);
      if ($video_exists) {
        $deletes[] = $media_id;
      }
    }
  }
  
  if ($last_notification) {
    media_theplatform_mpx_set_last_notification($account, $last_notification);
  }

  // Remove any deletes from actives, because it causes errors when updating.
  $actives = array_diff($actives, $deletes);

  watchdog('media_theplatform_mpx',
    "Results while fetching changed media IDs for @account: <br/>
<br /> Changed Media IDs: @actives
<br /> Deleted Media IDs: @deletes
<br /> Last Notification: @last_notification",
    array(
      '@account' => _media_theplatform_mpx_account_log_string($account),
      '@actives' => implode(', ', $actives),
      '@deletes' => implode(', ', $deletes),
      '@last_notification' => $last_notification,
    ),
    WATCHDOG_NOTICE);

  return array(
    'actives' => $actives,
    'deletes' => $deletes,
    'last_notification' => $last_notification,
  );
}

/**
 * Implements hook_cron_queue_info().
 */
function media_theplatform_mpx_cron_queue_info() {

  $queues['media_theplatform_mpx_video_cron_queue'] = array(
    'worker callback' => 'process_media_theplatform_mpx_video_cron_queue_item',
    'time' => media_theplatform_mpx_variable_get('cron_queue_processing_time', 10),
  );

  return $queues;
}

/**
 * Video queue item worker callback.
 */
function process_media_theplatform_mpx_video_cron_queue_item($item) {

  $start_microtime = microtime(TRUE);

  watchdog('media_theplatform_mpx', 'Processing video cron queue item:
    <br /> <pre>@item</pre>',
    array('@item' => print_r($item, TRUE)), WATCHDOG_DEBUG);

  try {

    switch ($item['queue_operation']) {

      case 'publish':
        // Import/Update the video.
        if (!empty($item['video'])) {
          // If an account wasn't stored, it was queued in a previous version of
          // this module.  Assume account 1 in these cases.
          $item['account'] = !empty($item['account']) ? $item['account'] : _media_theplatform_mpx_get_account_data(1);
          // Check if player sync has been turned off for this account.  If so,
          // do not import/update this video.
          if (!media_theplatform_mpx_variable_get('account_' . $item['account']->id . '_cron_video_sync', 1)) {
            return;
          }
          $video = $item['video'];
          // Prepare the video item for import/update.

          // Sometimes MPX will send us invalid URLs like:
          // "{ssl:https:http}://mpxstatic-nbcmpx.nbcuni.com/...jpg". We need to
          // fix the protocol for the ingestion to work.
          // When the root cause is fixed this code may go away.
          $thumbnail_url = $video['plmedia$defaultThumbnailUrl'];
          $thumbnail_url = preg_replace('/(\{.*\})\:\/\/(.*)/', "http://$2", $thumbnail_url);

          $video_item = array(
            'id' => basename($video['id']),
            'guid' => $video['guid'],
            'title' => $video['title'],
            'description' => $video['description'],
            'thumbnail_url' => $thumbnail_url,
            // Additional default mpx fields.
            'released_file_pids' => serialize(_media_theplatform_mpx_flatten_array(_media_theplatform_mpx_get_media_item_data('media$content/plfile$releases/plrelease$pid', $video))),
            'default_released_file_pid' => _media_theplatform_mpx_get_default_released_file_pid($video),
            'categories' => serialize(_media_theplatform_mpx_get_media_item_data('media$categories/media$name', $video)),
            'author' => _media_theplatform_mpx_get_media_item_data('plmedia$provider', $video),
            'airdate' => _media_theplatform_mpx_get_media_item_data('pubDate', $video) / 1000,
            'available_date' => _media_theplatform_mpx_get_media_item_data('media$availableDate', $video) / 1000,
            'expiration_date' => _media_theplatform_mpx_get_media_item_data('media$expirationDate', $video) / 1000,
            'keywords' => _media_theplatform_mpx_get_media_item_data('media$keywords', $video),
            'copyright' => _media_theplatform_mpx_get_media_item_data('media$copyright', $video),
            'related_link' => _media_theplatform_mpx_get_media_item_data('link', $video),
            'fab_rating' => _media_theplatform_mpx_get_rating_media_item_data('fab', $video),
            'fab_sub_ratings' => serialize(_media_theplatform_mpx_get_subrating_media_item_data('fab', $video)),
            'mpaa_rating' => _media_theplatform_mpx_get_rating_media_item_data('mpaa', $video),
            'mpaa_sub_ratings' => serialize(_media_theplatform_mpx_get_subrating_media_item_data('mpaa', $video)),
            'vchip_rating' => _media_theplatform_mpx_get_rating_media_item_data('v-chip', $video),
            'vchip_sub_ratings' => serialize(_media_theplatform_mpx_get_subrating_media_item_data('v-chip', $video)),
            'exclude_countries' => (int)_media_theplatform_mpx_get_media_item_data('media$excludeCountries', $video),
            'countries' => serialize(_media_theplatform_mpx_get_media_item_data('media$countries', $video)),
          );
          // Allow modules to alter the video item for pulling in custom metadata.
          drupal_alter('media_theplatform_mpx_media_import_item', $video_item, $video, $item['account']);
          // Perform the import/update.

          media_theplatform_mpx_import_video($video_item, $item['account']);
        }
        break;

      case 'unpublish':
        if (!empty($item['unpublish_id'])) {
          media_theplatform_mpx_set_mpx_video_inactive($item['unpublish_id'], 'unpublished');
        }
        break;

      case 'delete':
        if (!empty($item['delete_id'])) {
          media_theplatform_mpx_set_mpx_video_inactive($item['delete_id'], 'deleted');
        }
        break;
    }
  }
  catch (Exception $e) {

    watchdog('media_theplatform_mpx', 'FATAL ERROR occurred while processing mpx cron queue item --  "@message"  -- Exception output: @output.',
      array(
        '@message' => $e->getMessage(),
        '@output' => '<pre>' . print_r($e, TRUE) . '</pre>',
      ),
      WATCHDOG_ERROR);
  }

  // @todo: Do this per account?  By type of operation?
  $current_total_videos_processed = media_theplatform_mpx_variable_get('running_total_videos_processed', 0);
  $current_total_video_processing_time = media_theplatform_mpx_variable_get('running_total_video_processing_time', 0);
  $processing_time = microtime(TRUE) - $start_microtime;
  media_theplatform_mpx_variable_set('running_total_videos_processed', 1 + $current_total_videos_processed);
  media_theplatform_mpx_variable_set('running_total_video_processing_time', $current_total_video_processing_time + $processing_time);
}

/**
 * Helper that retrieves and returns data from an mpx feed URL.
 */
function _media_theplatform_mpx_process_video_import_feed_data($result_data, $media_to_update = NULL, $account = NULL) {

  watchdog('media_theplatform_mpx', 'Processing video feed data for @account.',
    array('@account' => is_object($account) && !empty($account->account_id) ? 'account ' . basename($account->account_id) : 'all accounts.'),
    WATCHDOG_INFO);

  // Initalize arrays to store mpxMedia data.
  $queue_items = array();
  $published_ids = array();
  $unpublished_ids = array();
  $deleted_ids = array();

  // Keys entryCount and entries are only set when there is more than 1 entry.
  // If only one row, result_data holds all the mpxMedia data (if its published).
  // If responseCode is returned, it means 1 ID in $ids has been unpublished and
  // would return no data.
  $entries = array();
  if (isset($result_data['entryCount'])) {
    $entries = $result_data['entries'];
  }
  elseif (!isset($result_data['responseCode'])) {
    $entries = array($result_data);
  }

  foreach ($entries as $video) {
    if (empty($video)) {
      continue;
    }
    $published_ids[] = basename($video['id']);
    // Add item to video queue.
    $item = array(
      'queue_operation' => 'publish',
      'video' => $video,
      'account' => $account,
    );
    $queue_items[] = $item;
  }

  // Collect any ids that have been unpublished.
  if (isset($media_to_update)) {
    $unpublished_ids = array_diff($media_to_update['actives'], $published_ids);
    // Filter out any ids which haven't been imported.
    foreach ($unpublished_ids as $key => $unpublished_id) {
      $video_exists = media_theplatform_mpx_get_mpx_video_by_field('id', $unpublished_id);
      if (!$video_exists) {
        unset($unpublished_ids[$key]);
      }
      else {
        // Add to queue.
        $item = array(
          'queue_operation' => 'unpublish',
          'unpublish_id' => $unpublished_id,
        );
        $queue_items[] = $item;
      }
    }
  }

  // Collect any deleted mpxMedia.
  if (isset($media_to_update) && count($media_to_update['deletes']) > 0) {
    foreach ($media_to_update['deletes'] as $delete_id) {
      $deleted_ids[] = $delete_id;
      // Add to queue.
      $item = array(
        'queue_operation' => 'delete',
        'delete_id' => $delete_id,
      );
      $queue_items[] = $item;
    }
  }

  // Add the queue items to the cron queueu.
  $queue = DrupalQueue::get('media_theplatform_mpx_video_cron_queue');
  foreach ($queue_items as $queue_item) {
    $queue->createItem($queue_item);
  }

  watchdog('media_theplatform_mpx', 'Video IDs queued to be processed on cron:
    <br /> @published_count new mpxMedia queued to be created or updated' . (count($published_ids) ? ':' : '.') . ' @published_ids
    <br /> @unpublished_count new mpxMedia queued to be unpublished' . (count($unpublished_ids) ? ':' : '.') . ' @unpublished_ids
    <br /> @deleted_count new mpxMedia queued to be deleted' . (count($deleted_ids) ? ':' : '.') . ' @deleted_ids',
    array(
      '@published_count' => count($published_ids),
      '@published_ids' => implode(', ', $published_ids),
      '@unpublished_count' => count($unpublished_ids),
      '@unpublished_ids' => implode(', ', $unpublished_ids),
      '@deleted_count' => count($deleted_ids),
      '@deleted_ids' => implode(', ', $deleted_ids),
    ),
    WATCHDOG_INFO);

  return TRUE;
}

/**
 * Processes a batch import/update.
 */
function _media_theplatform_mpx_process_batch_video_import($type, $account = NULL) {

  // @todo Add check for valid account object.

  // Get the parts for the batch url and construct it.
  $batch_url = $account->proprocessing_batch_url;
  $batch_item_count = $account->proprocessing_batch_item_count;
  $current_batch_item = (int) $account->proprocessing_batch_current_item;
  $feed_request_item_limit = media_theplatform_mpx_variable_get('cron_videos_per_run', 250);
  $token = media_theplatform_mpx_check_token($account->id);

  $url = $batch_url . '&range=' . $current_batch_item . '-' . ($current_batch_item + ($feed_request_item_limit - 1));
  $url .= '&token=' . $token;

  // Log what type of operation we're performing.
  watchdog('media_theplatform_mpx', 'Processing batch video import @method for @account. <br /><br /> Retrieving @limit videos from:
    <br /><br />  @url.',
    array(
      '@method' => $type,
      '@account' => _media_theplatform_mpx_account_log_string($account),
      '@limit' => $feed_request_item_limit,
      '@url' => $url,
    ),
    WATCHDOG_NOTICE);

  $result_data = _media_theplatform_mpx_retrieve_feed_data($url);
  if (!$result_data) {
    return FALSE;
  }

  $processesing_success = _media_theplatform_mpx_process_video_import_feed_data($result_data, NULL, $account);
  if (!$processesing_success) {
    return FALSE;
  }

  $current_batch_item += $feed_request_item_limit;
  if ($current_batch_item <= $batch_item_count) {
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_current_item', $current_batch_item);
  }
  else {
    // Reset the batch system variables.
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_url', '');
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_item_count', 0);
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_current_item', 0);
    // In case this is the end of the initial import batch, set the last
    // notification id.
    if (!$account->last_notification) {
      media_theplatform_mpx_set_last_notification($account);
    }
  }
}

/**
 * Helper that constructs a video feed url given a comma-delited list of video
 * ids or "all" for all media (used during initial import).
 */
function _media_theplatform_mpx_get_video_feed_url($ids = NULL, $account = NULL) {

  $ids = ($ids == 'all' || !$ids) ? '' : str_replace(',', '|', $ids);

  // Note:  We sort by updated date ascending so that newly updated content is
  // fetched from the feed last.  In cases of large batches, e.g. an initial
  // import, media can be edited before the batch is complete.  This ensures
  // these edited media will be fetched toward the end of the batch.
  $url = 'https://read.data.media.theplatform.com/media/data/Media?schema=1.6.0&form=json&pretty=true' .
    '&byOwnerId=' . $account->account_id .
    '&byApproved=true&byAvailabilityState=notYetAvailable|available|expired' .
    '&byContent=byExists%3Dtrue%26byHasReleases%3Dtrue' .
    '&sort=guid|asc';

  if ($ids) {
    $url .= '&byId=' . $ids;
  }

  return $url;
}

/**
 * Get the total item count for a given feed url.
 */
function _media_theplatform_mpx_get_feed_item_count($url) {

  $count_url = $url . '&count=true&fields=guid&range=1-1';
  $feed_request_timeout = media_theplatform_mpx_variable_get('cron_videos_timeout', 180);

  watchdog('media_theplatform_mpx', 'Retrieving total feed item count.', array(), WATCHDOG_INFO);

  $count_result_data = _media_theplatform_mpx_retrieve_feed_data($count_url);

  if (!isset($count_result_data['totalResults'])) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve total feed item count.  totalResults field not found in response data.',
      array(), WATCHDOG_ERROR);
  }

  $total_result_count = $count_result_data['totalResults'];

  watchdog('media_theplatform_mpx', 'Retrieving total feed item count returned @count items.',
    array('@count' => $total_result_count), WATCHDOG_INFO);

  return $total_result_count;
}

/**
 * Processes a video update.
 */
function _media_theplatform_mpx_process_video_update($type, $account = NULL) {

  watchdog('media_theplatform_mpx', 'Beginning video update process @method for @account.',
    array(
      '@method' => $type,
      '@account' => _media_theplatform_mpx_account_log_string($account),
    ),
    WATCHDOG_INFO);

  // Get all IDs of mpxMedia that have been updated since last notification.
  $media_to_update = media_theplatform_mpx_get_changed_ids($account);

  if (!$media_to_update) {
    $cron_queue = DrupalQueue::get('media_theplatform_mpx_video_cron_queue');
    $num_cron_queue_items = $cron_queue->numberOfItems();
    if ($num_cron_queue_items) {
      $message = 'All mpxMedia is up to date for @account.  There are approximately @num_items videos waiting to be processed for all accounts.';
      $message_variables = array(
        '@account' => _media_theplatform_mpx_account_log_string($account),
        '@num_items' => $num_cron_queue_items
      );
    }
    else {
      $message = 'All mpxMedia is up to date for @account.';
      $message_variables = array(
        '@account' => _media_theplatform_mpx_account_log_string($account),
      );
    }
    watchdog('media_theplatform_mpx', $message, $message_variables, WATCHDOG_NOTICE);

    return FALSE;
  }

  if (count($media_to_update['actives']) > 0) {
    $ids = implode(',', $media_to_update['actives']);
  }
  elseif (count($media_to_update['deletes']) > 0) {
    $result_data = array();
    $processesing_success = _media_theplatform_mpx_process_video_import_feed_data($result_data, $media_to_update, $account);
    if (!$processesing_success) {
      return FALSE;
    }
    // Set last notification for the next update.
    media_theplatform_mpx_set_last_notification($account, $media_to_update['last_notification']);

    return TRUE;
  }
  else {
    watchdog('media_theplatform_mpx', 'There were no active mpxMedia to update for @account.',
      array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_NOTICE);

    return FALSE;
  }

  // Get the feed url.
  $batch_url = _media_theplatform_mpx_get_video_feed_url($ids, $account);

  $token = media_theplatform_mpx_check_token($account->id);
  $url = $batch_url . '&token=' . $token;

  // Get the total result count for this update.  If it is greater than the feed
  // request item limit, start a new batch.
  $total_result_count = count(explode(',', $ids));
  $feed_request_item_limit = media_theplatform_mpx_variable_get('cron_videos_per_run', 250);

  if ($total_result_count && $total_result_count > $feed_request_item_limit) {
    // Set last notification for the next update.
    _media_theplatform_mpx_set_field($account->id, 'last_notification', $media_to_update['last_notification']);
    // Set starter batch system variables.
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_url', $batch_url);
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_item_count', $total_result_count);
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_current_item', 0);
    // Perform the first batch operation, not the update.
    return _media_theplatform_mpx_process_batch_video_import($type, $account);
  }

  $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

  if (!$result_data) {
    return FALSE;
  }

  $processesing_success = _media_theplatform_mpx_process_video_import_feed_data($result_data, $media_to_update, $account);

  if (!$processesing_success) {
    return FALSE;
  }

  // Set last notification for the next update.
  media_theplatform_mpx_set_last_notification($account, $media_to_update['last_notification']);

  return TRUE;
}

/**
 * Processes a video update.
 */
function _media_theplatform_mpx_process_video_import($type, $account = NULL) {

  watchdog('media_theplatform_mpx', 'Running initial video import for @account @method',
    array(
      '@account' => _media_theplatform_mpx_account_log_string($account),
      '@method' => $type,
    ),
    WATCHDOG_NOTICE);

  // Set the first last notification value for subsequent updates.  Setting it
  // now ensures that any updates that happen during the import are processed
  // in subsequent updates.
  media_theplatform_mpx_set_last_notification($account);

  // Get the feed url.
  $batch_url = _media_theplatform_mpx_get_video_feed_url('all', $account);

  $token = media_theplatform_mpx_check_token($account->id);
  $url = $batch_url . '&token=' . $token;

  // Get the total result count for this update.  If it is greater than the feed
  // request item limit, start a new batch.
  $total_result_count = _media_theplatform_mpx_get_feed_item_count($url);
  $feed_request_item_limit = media_theplatform_mpx_variable_get('cron_videos_per_run', 250);

  if ($total_result_count && $total_result_count > $feed_request_item_limit) {
    // Set starter batch system variables.
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_url', $batch_url);
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_item_count', $total_result_count);
    _media_theplatform_mpx_set_field($account->id, 'proprocessing_batch_current_item', 0);
    // Reload the $account object because it does not have the
    // 'proprocessing_batch_*' variables that were set above.
    $account = _media_theplatform_mpx_get_account_data($account->id);

    // Perform the first batch operation, not the update.
    return _media_theplatform_mpx_process_batch_video_import($type, $account);
  }

  watchdog('media_theplatform_mpx', 'Retrieving all media data from thePlatform for @account.',
    array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_INFO);

  $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

  if (empty($result_data)) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve all media data from thePlatform for @account.  Halting the import process.',
      array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_ERROR);

    return FALSE;
  }

  $processesing_success = _media_theplatform_mpx_process_video_import_feed_data($result_data, NULL, $account);

  if (!$processesing_success) {
    return FALSE;
  }

  return TRUE;
}

/**
 * Imports all Videos into Media Library.
 *
 * @param String $type
 *   Import type. Possible values 'via cron' or 'manually', for sync.
 *
 * @return Array
 *   $data['total'] - # of videos retrieved
 *   $data['num_inserts'] - # of videos added to mpx_video table
 *   $data['num_updates'] - # of videos updated
 *   $data['num_inactives'] - # of videos changed from active to inactive
 */
function media_theplatform_mpx_import_all_videos($type) {

  watchdog('media_theplatform_mpx', 'Beginning video import/update process @method for all accounts.', array('@method' => $type), WATCHDOG_NOTICE);

  foreach (_media_theplatform_mpx_get_account_data() as $account_data) {

    // Check if video sync has been turned off for this account.
    if (!media_theplatform_mpx_variable_get('account_' . $account_data->id . '_cron_video_sync', 1)) {
      continue;
    }

    // Don't do anything if we don't have signIn token or import account.
    if (!media_theplatform_mpx_check_token($account_data->id) || empty($account_data->import_account) ||
        !media_theplatform_mpx_is_valid_player_for_account($account_data->default_player, $account_data)) {
      continue;
    }

    // Check if we're running a feed request batch.  If so, construct the batch URL.
    if ($account_data->proprocessing_batch_url) {
      _media_theplatform_mpx_process_batch_video_import($type, $account_data);
      continue;
    }

    // Check if we have a notification stored.  If so, run an update.
    if ($account_data->last_notification) {
      _media_theplatform_mpx_process_video_update($type, $account_data);
      continue;
    }

    // No last notification set, so this would be an initial import.
    _media_theplatform_mpx_process_video_import($type, $account_data);
  }

  watchdog('media_theplatform_mpx', 'Processed video import/update @method for all accounts.', array('@method' => $type), WATCHDOG_NOTICE);
}

/**
 * Helper that updates a file entity URI for an mpx video.
 */
function _media_theplatform_mpx_update_file_uri($fid, $new_file_uri, $table = 'file_managed') {

  watchdog('media_theplatform_mpx', 'Changing URI to "@new_uri" for file @fid in the @table table.',
    array(
      '@new_uri' => $new_file_uri,
      '@fid' => $fid,
      '@table' => $table,
    ),
    WATCHDOG_INFO);

  $file_update_result = db_update($table)
    ->fields(array('uri' => $new_file_uri))
    ->condition('fid', $fid, '=')
    ->execute();

  if ($file_update_result) {
    watchdog('media_theplatform_mpx', 'Successfully updated URI to "@new_uri" for file @fid in the @table table.',
      array(
        '@new_uri' => $new_file_uri,
        '@fid' => $fid,
      '@table' => $table,
      ),
      WATCHDOG_NOTICE);
  }
  else {
    watchdog('media_theplatform_mpx', 'Failed to update URI to "@new_uri" for file @fid in the @table table.',
      array(
        '@new_uri' => $new_file_uri,
        '@fid' => $fid,
      '@table' => $table,
      ),
      WATCHDOG_ERROR);
  }
}

/**
 * Updates or inserts given Video within Media Library.
 *
 * @param array $video
 *   Record of Video data requested from thePlatform Import Feed
 *
 * @return String
 *   Returns output of media_theplatform_mpx_update_video() or media_theplatform_mpx_insert_video()
 */
function media_theplatform_mpx_import_video($video, $account = NULL) {

  watchdog('media_theplatform_mpx', 'Importing/Updating video @id for @account.',
    array(
      '@id' => basename($video['id']),
      '@account' => _media_theplatform_mpx_account_log_string($account),
    ),
    WATCHDOG_INFO);
  watchdog('media_theplatform_mpx', 'Importing/Updating video @id for @account with the following data:
    <br /> <pre>@data</pre>',
    array(
      '@id' => basename($video['id']),
      '@account' => _media_theplatform_mpx_account_log_string($account),
      '@data' => print_r($video, TRUE),
    ),
    WATCHDOG_DEBUG);

  $op = '';

  // Check if the file exists in files_managed table.
  $files = media_theplatform_mpx_get_files_by_guid($video['guid'], $account);

  // If a file exists:
  if (!empty($files)) {
    foreach ($files as $file) {
      // Check if any mpx_video records exist for the file fid.
      $imported_videos = db_select('mpx_video', 'v')
        ->fields('v', array('fid'))
        ->condition('guid', $video['guid'], '=')
        ->condition('parent_account', $account->id, '=')
        ->execute()
        ->fetchField();
      // If mpx_video record exists, then update record.
      if (!empty($imported_videos)) {
        // If there are multiple existing mpx_video records, log an error message.
        if (count($imported_videos) > 1) {
          watchdog('media_theplatform_mpx', 'Multiple mpx_video records found for file fid "@fid" when importing mpx video "@video_title" for @account.',
            array(
              '@fid' => $file->fid,
              '@video_title' => $video['title'],
              '@account' => _media_theplatform_mpx_account_log_string($account),
            ),
            WATCHDOG_NOTICE);
        }
        $op = media_theplatform_mpx_update_video($video, $file->fid, $account);
      }
      // Else insert new mpx_video record with existing $fid.
      else {
        $op = media_theplatform_mpx_insert_video($video, $file->fid, $account);
      }
    }
  }
  // Else insert new file/record:
  else {

    $imported_videos = media_theplatform_mpx_get_videos_by_id($video['id'], $account);
    $imported_video = (array)reset($imported_videos);

    // If there are multiple existing mpx_video records, log an error message.
    if (count($imported_videos) > 1) {
      watchdog('media_theplatform_mpx', 'Multiple mpx_video records found for video id "@id" when importing mpx video "@video_title" for @account.  Using video record with video_id "@video_id".',
        array(
          '@id' => $video['id'],
          '@video_title' => $video['title'],
          '@account' => _media_theplatform_mpx_account_log_string($account),
          '@video_id' => $imported_video->video_id,
        ),
        WATCHDOG_NOTICE);
    }

    // Perform operations for the old GUID if there is an existing video.
    if (!empty($imported_video['fid']) && !empty($imported_video['guid']) && $imported_video['guid'] != $video['guid']) {
      // Delete thumbnail from files_*/media-mpx directory and from all styles.
      _media_theplatform_mpx_delete_video_images($imported_video);
      // Change the file URI to use the new GUID.
      $new_file_uri = _media_theplatform_mpx_get_video_file_uri($video, $account);
      if ($new_file_uri) {
        _media_theplatform_mpx_update_file_uri($imported_video['fid'], $new_file_uri);
      }
      else {
        watchdog('media_theplatform_mpx', 'Failed to update URI for file @fid in the file_managed table for mpx video "@video_title" for @account.  Unable to determine new URI.',
          array(
            '@fid' => $imported_video['fid'],
            '@video_title' => $video['title'],
            '@account' => _media_theplatform_mpx_account_log_string($account),
          ),
          WATCHDOG_ERROR);
      }
    }

    if (!empty($imported_video['fid'])) {
      $op = media_theplatform_mpx_update_video($video, $imported_video['fid'], $account);
    }
    else {
      $op = media_theplatform_mpx_insert_video($video, NULL, $account);
    }
  }

  // Allow modules to perform additional media import tasks.
  module_invoke_all('media_theplatform_mpx_import_media', $op, $video, $account);

  return $op;
}

/**
 * Helper that returns player data for an mpx video.
 */
function _media_theplatform_mpx_get_video_player($video, $account = NULL) {

  if (!empty($video['player_id'])) {

    return media_theplatform_mpx_get_mpx_player_by_player_id($video['player_id']);
  }

  if (is_object($account) && !empty($account->default_player)) {

    return media_theplatform_mpx_get_mpx_player_by_player_id($account->default_player);
  }

  return array();
}

/**
 * Helper that returns the file URI for an mpx video.
 */
function _media_theplatform_mpx_get_video_file_uri($video, $account = NULL) {

  if (!is_array($video) || empty($video['guid'])) {
    return '';
  }

  $player = _media_theplatform_mpx_get_video_player($video, $account);

  if (!is_array($player) || empty($player['fid'])) {
    return '';
  }

  $uri = 'mpx://m/' . $video['guid'] . '/p/' . $player['fid'];

  if (is_object($account) && !empty($account->account_id)) {
    $uri .= '/a/' . basename($account->account_id);
  }

  return $uri;
}

/**
 * Helper that saves a new mpx video file entity.
 */
function _media_theplatform_mpx_create_video_file($video, $account = NULL) {

  // Get uri string to create file:
  //   "m" is for media.  "p" for player.  "a" for account.
  $uri = _media_theplatform_mpx_get_video_file_uri($video, $account);

  if (!$uri) {
    watchdog('media_theplatform_mpx', 'Failed to create file for video "@title" - @id - and @account.  URI could not be determined.',
      array(
        '@id' => $video['title'],
        '@id' => $video['id'],
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ),
      WATCHDOG_ERROR);

    return FALSE;
  }

  // Create the file.
  watchdog('media_theplatform_mpx', 'Creating file with uri "@uri" for video "@title" - @id - and @account.',
    array(
      '@uri' => $uri,
      '@id' => $video['title'],
      '@id' => $video['id'],
      '@account' => _media_theplatform_mpx_account_log_string($account),
    ),
    WATCHDOG_INFO);

  $provider = media_internet_get_provider($uri);
  $file = $provider->save($account);

  if (!is_object($file) || empty($file->fid)) {
    watchdog('media_theplatform_mpx', 'Failed to create file for video "@title" - @id - and @account.',
      array(
        '@id' => $video['title'],
        '@id' => $video['id'],
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ),
      WATCHDOG_ERROR);

    return NULL;
  }

  watchdog('media_theplatform_mpx', 'Successfully created file @fid with uri "@uri" for video "@title" - @id - and @account.',
    array(
      '@fid' => $file->fid,
      '@uri' => $uri,
      '@id' => $video['title'],
      '@id' => $video['id'],
      '@account' => _media_theplatform_mpx_account_log_string($account),
    ),
    WATCHDOG_INFO);

  return $file;
}

/**
 * Inserts given Video and File into Media Library.
 *
 * @param array $video
 *   Record of Video data requested from thePlatform Import Feed
 * @param int $fid
 *   File fid of Video's File in file_managed if it already exists
 *   NULL if it doesn't exist
 *
 * @return String
 *   Returns 'insert' for counters in media_theplatform_mpx_import_all_videos()
 */
function media_theplatform_mpx_insert_video($video, $fid = NULL, $account = NULL) {

  watchdog('media_theplatform_mpx', 'Creating video @id -- @title -- for @account.',
    array(
      '@id' => basename($video['id']),
      '@title' => $video['title'],
      '@account' => _media_theplatform_mpx_account_log_string($account),
    ),
    WATCHDOG_INFO);

  // If file doesn't exist, write it to file_managed.
  if (!$fid) {
    $file = _media_theplatform_mpx_create_video_file($video, $account);
    if (!is_object($file) || empty($file->fid)) {
      return 'failed to insert';
    }
  }

  // Insert record into mpx_video.
  $insert_fields = array(
    'title' => $video['title'],
    'guid' => $video['guid'],
    'description' => $video['description'],
    'fid' => $file->fid,
    'player_id' => !empty($video['player_id']) ? $video['player_id'] : NULL,
    'parent_account' => $account->id,
    'account' => $account->import_account,
    'thumbnail_url' => $video['thumbnail_url'],
    'created' => REQUEST_TIME,
    'updated' => REQUEST_TIME,
    'status' => 1,
    'id' => $video['id'],
    // Additional default mpx fields.
    'released_file_pids' => $video['released_file_pids'],
    'default_released_file_pid' => $video['default_released_file_pid'],
    'categories' => $video['categories'],
    'author' => $video['author'],
    'airdate' => $video['airdate'],
    'available_date' => $video['available_date'],
    'expiration_date' => $video['expiration_date'],
    'keywords' => $video['keywords'],
    'copyright' => $video['copyright'],
    'related_link' => $video['related_link'],
    'fab_rating' => $video['fab_rating'],
    'fab_sub_ratings' => $video['fab_sub_ratings'],
    'mpaa_rating' => $video['mpaa_rating'],
    'mpaa_sub_ratings' => $video['mpaa_sub_ratings'],
    'vchip_rating' => $video['vchip_rating'],
    'vchip_sub_ratings' => $video['vchip_sub_ratings'],
    'exclude_countries' => $video['exclude_countries'],
    'countries' => $video['countries'],
  );

  _media_theplatform_mpx_enforce_db_field_limits($insert_fields, 'media_theplatform_mpx', 'mpx_video');

  watchdog('media_theplatform_mpx', 'Inserting new video @id - "@title" - associated with file @fid with the following data: <br /><br /> <pre>@data</pre>',
    array(
      '@id' => basename($video['id']),
      '@title' => $video['title'],
      '@fid' => $file->fid,
      '@data' => print_r($insert_fields, TRUE),
    ),
    WATCHDOG_DEBUG);

  $video_id = db_insert('mpx_video')
    ->fields($insert_fields)
    ->execute();

  if ($video_id) {
    watchdog('media_theplatform_mpx', 'Successfully created new video @id - "@title" - associated with file @fid for @account.',
      array(
        '@id' => basename($video['id']),
        '@title' => $video['title'],
        '@fid' => $file->fid,
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ),
      WATCHDOG_NOTICE);
  }
  else {
    watchdog('media_theplatform_mpx', 'Failed to insert new video @id - "@title" - associated with file @fid for @account into the mpx_video table.',
      array(
        '@id' => basename($video['id']),
        '@title' => $video['title'],
        '@fid' => $file->fid,
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ),
      WATCHDOG_ERROR);
  }

  // Update the file entity filename with the newly ingested video title.
  media_theplatform_mpx_update_video_filename($file->fid, $video['title']);

  // Return code to be used by media_theplatform_mpx_import_all_videos().
  return 'insert';
}

/**
 * Updates File $fid with given $video_title and $player_title.
 */
function media_theplatform_mpx_update_video_filename($fid, $video_title, $player_title = NULL) {

  // Update file_managed and file_managed_revisions filename with title of video.
  $file_managed_updated = db_update('file_managed')
    ->fields(array(
      'filename' => substr($video_title, 0, 255),
    ))
    ->condition('fid', $fid, '=')
    ->execute();
  $file_managed_revisions_updated = db_update('file_managed_revisions')
    ->fields(array(
      'filename' => substr($video_title, 0, 255),
    ))
    ->condition('fid', $fid, '=')
    ->execute();

  return ($file_managed_updated && $file_managed_revisions_updated);
}

/**
 * Deletes thumbnail images and image styles associated with an mpx video.
 */
function _media_theplatform_mpx_delete_video_images($video) {

  if (!is_array($video) || empty($video['guid'])) {
    return;
  }

  $image_path = 'media-mpx/' . $video['guid'] . '.jpg';

  watchdog('media_theplatform_mpx', 'Deleting image with path: public://@path',
    array('@path' => 'public://' . $image_path), WATCHDOG_INFO);
  if (file_unmanaged_delete('public://' . $image_path)) {
    watchdog('media_theplatform_mpx', 'Successfully deleted image with path: public://@path',
      array('@path' => 'public://' . $image_path), WATCHDOG_NOTICE);
  }
  else {
    watchdog('media_theplatform_mpx', 'Failed to delete image with path: public://@path',
      array('@path' => 'public://' . $image_path), WATCHDOG_ERROR);
  }

  watchdog('media_theplatform_mpx', 'Deleting image with path: private://@path',
    array('@path' => 'private://' . $image_path), WATCHDOG_INFO);
  if (file_unmanaged_delete('private://' . $image_path)) {
    watchdog('media_theplatform_mpx', 'Successfully deleted image with path: private://@path',
      array('@path' => 'private://' . $image_path), WATCHDOG_NOTICE);
  }
  else {
    watchdog('media_theplatform_mpx', 'Failed to delete image with path: private://@path',
      array('@path' => 'private://' . $image_path), WATCHDOG_ERROR);
  }

  // Delete thumbnail from all the styles.
  // Now, the next time file is loaded, MediaThePlatformMpxStreamWrapper
  // will call getOriginalThumbnail to update image.
  image_path_flush($image_path);
  watchdog('media_theplatform_mpx', 'Deleted image styles for image with path: @path.  The next time file is loaded, MediaThePlatformMpxStreamWrapper will call getOriginalThumbnail to update the image.',
    array('@path' => $image_path), WATCHDOG_INFO);
}

/**
 * Updates given Video and File in Media Library.
 *
 * @param array $video
 *   Record of Video data requested from thePlatform Import Feed
 *
 * @return String
 *   Returns 'update' for counters in media_theplatform_mpx_import_all_players()
 */
function media_theplatform_mpx_update_video($video, $fid = NULL, $account = NULL) {

  watchdog('media_theplatform_mpx', 'Updating video @id - "@title" - associated with file @fid for @account.',
    array(
      '@id' => basename($video['id']),
      '@title' => $video['title'],
      '@fid' => $fid ? $fid : 'UNAVAILABLE',
      '@account' => _media_theplatform_mpx_account_log_string($account),
    ),
    WATCHDOG_INFO);

  // Update mpx_video record.
  $update_fields = array(
    'title' => $video['title'],
    'guid' => $video['guid'],
    'description' => $video['description'],
    'thumbnail_url' => $video['thumbnail_url'],
    'status' => 1,
    'updated' => REQUEST_TIME,
    'id' => $video['id'],
    // Additional default mpx fields.
    'released_file_pids' => $video['released_file_pids'],
    'default_released_file_pid' => $video['default_released_file_pid'],
    'categories' => $video['categories'],
    'author' => $video['author'],
    'airdate' => $video['airdate'],
    'available_date' => $video['available_date'],
    'expiration_date' => $video['expiration_date'],
    'keywords' => $video['keywords'],
    'copyright' => $video['copyright'],
    'related_link' => $video['related_link'],
    'fab_rating' => $video['fab_rating'],
    'fab_sub_ratings' => $video['fab_sub_ratings'],
    'mpaa_rating' => $video['mpaa_rating'],
    'mpaa_sub_ratings' => $video['mpaa_sub_ratings'],
    'vchip_rating' => $video['vchip_rating'],
    'vchip_sub_ratings' => $video['vchip_sub_ratings'],
    'exclude_countries' => $video['exclude_countries'],
    'countries' => $video['countries'],
  );

  _media_theplatform_mpx_enforce_db_field_limits($update_fields, 'media_theplatform_mpx', 'mpx_video');

  // Fetch video_id and status from mpx_video table for given $video.
  $mpx_video = media_theplatform_mpx_get_mpx_video_by_field('guid', $video['guid']);

  watchdog('media_theplatform_mpx', 'Updating and publishing @status video @id - "@title" - associated with file fid - @fid - for @account with the following data: <br /><br /> <pre>@data</pre>',
    array(
      '@status' => isset($mpx_video) && $mpx_video['status'] == 1 ? 'published' : 'unpublished',
      '@id' => basename($video['id']),
      '@title' => $video['title'],
      '@fid' => $fid ? $fid : 'UNAVAILABLE',
      '@account' => _media_theplatform_mpx_account_log_string($account),
      '@data' => print_r($update_fields, TRUE),
    ),
    WATCHDOG_DEBUG);

  // Construct the update query.
  $update_query = db_update('mpx_video');
  $update_query->fields($update_fields);
  if ($fid) {
    $update_query->condition('fid', $fid, '=');
    $association = 'file fid "' . $fid . '"';
  }
  else {
    $update_query->condition('guid', $video['guid'], '=');
    $association = 'guid "' . $video['guid'] . '"';
  }
  if (is_object($account) && !empty($account->id)) {
    $update_query->condition('parent_account', $account->id, '=');
  }

  $update_result = $update_query->execute();

  if ($update_result) {
    if (strpos($association, 'guid') !== FALSE) {
      $update_result = db_update('file_managed')
        ->fields(array('filename' => $video['title']))
        ->condition('uri', 'mpx://%' . $video['guid'] . '%', 'LIKE')
        ->execute();
    }
  }
  else {
    $update_result = db_update('file_managed')
      ->fields(array('filename' => $video['title']))
      ->condition('fid', $fid, '=')
      ->execute();
  }

  if ($update_result) {
    watchdog('media_theplatform_mpx', 'Successfully updated video  @id - "@title" associated with @association for @account .',
      array(
        '@id' => basename($video['id']),
        '@title' => $video['title'],
        '@association' => $association,
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ),
      WATCHDOG_NOTICE);
  }
  else {
    watchdog('media_theplatform_mpx', 'Failed to update video  @id - "@title" associated with file fid - @fid - for @account in the mpx_video table.',
      array(
        '@id' => basename($video['id']),
        '@title' => $video['title'],
        '@association' => $association,
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ),
      WATCHDOG_ERROR);
  }

  // Update the file entity filename with the newly ingested video title.
  if ($fid) {
    media_theplatform_mpx_update_video_filename($fid, $video['title']);
  }
  else {
    foreach (array('file_managed', 'file_managed_revisions') as $table_name) {
      $filename_update_query = db_update($table_name)
        ->fields(array('filename' => substr($video['title'], 0, 255)))
        ->condition('uri', 'mpx://m/' . $video['guid'] . '/%', 'LIKE');
      if ($account) {
        $filename_update_query->condition('uri', '%/a/' . basename($account->account_id), 'LIKE');
      }
      $filename_update_query->execute();
    }
  }

  // Delete thumbnail from files_*/media-mpx directory.
  _media_theplatform_mpx_delete_video_images($video);

  // Return code to be used by media_theplatform_mpx_import_all_videos().
  return 'update';
}

/**
 * Returns associative array of mpx_video data for given $field and its $value.
 */
function media_theplatform_mpx_get_mpx_video_by_field($field, $value, $account = NULL) {

  $query = db_select('mpx_video', 'v')
    ->fields('v')
    ->condition($field, $value, '=');

  if (is_object($account) && !empty($account->id)) {
    $query->condition('parent_account', $account->id, '=');
  }

  return $query->execute()->fetchAssoc();
}

/**
 * Returns total number of records in mpx_video table.
 */
function media_theplatform_mpx_get_mpx_video_count() {
  return db_query("SELECT count(video_id) FROM {mpx_video}")->fetchField();
}


/**
 * Returns array of all records in mpx_video table as Objects.
 */
function media_theplatform_mpx_get_all_mpx_videos() {
  return db_select('mpx_video', 'v')
    ->fields('v')
    ->execute()
    ->fetchAll();
}

/**
 * Returns array of all records in mpx_video given a file entity's fid.
 */
function media_theplatform_mpx_get_videos_by_fid($fid) {

  $query = db_select('mpx_video', 'f')
    ->fields('f')
    ->condition('fid', $fid, '=')
    ->orderBy('video_id', 'DESC');

  return $query->execute()->fetchAll();
}

/**
 * Returns array of all records in mpx_video given a file entity's fid.
 */
function media_theplatform_mpx_get_videos_by_id($id, $account = NULL) {

  $query = db_select('mpx_video', 'f')
    ->fields('f')
    ->condition('id', $id, '=')
    ->orderBy('video_id', 'DESC');

  if (is_object($account) && !empty($account->id)) {
    $query->condition('parent_account', $account->id, '=');
  }

  return $query->execute()->fetchAll();
}

/**
 * Returns array of all records in file_managed with mpx://m/$guid/%.
 */
function media_theplatform_mpx_get_files_by_guid($guid, $account = NULL) {

  $query = db_select('file_managed', 'f')
    ->fields('f')
    ->condition('uri', 'mpx://m/' . $guid . '/%', 'LIKE');

  if (is_object($account) && !empty($account->account_id)) {
    $query->condition('uri', '%/a/' . basename($account->account_id), 'LIKE');
  }

  return $query->execute()->fetchAll();
}

/**
 * Returns array of all records in file_managed with mpx://m/%/p/[player_fid].
 */
function media_theplatform_mpx_get_files_by_player_fid($fid) {

  return db_select('file_managed', 'f')
    ->fields('f')
    ->condition('uri', 'mpx://m/%', 'LIKE')
    ->condition('uri', '%/p/' . $fid . '%', 'LIKE')
    ->execute()
    ->fetchAll();
}

/**
 * Returns URL string of the thumbnail object where isDefault == 1.
 */
function media_theplatform_mpx_parse_thumbnail_url($data) {
  foreach ($data as $record) {
    if ($record['plfile$isDefault']) {
      return $record['plfile$url'];
    }
  }
}

/**
 * Returns thumbnail URL string for given guid from mpx_video table.
 */
function media_theplatform_mpx_get_thumbnail_url($guid) {
  return db_query("SELECT thumbnail_url FROM {mpx_video} WHERE guid=:guid", array(':guid' => $guid))->fetchField();
}

/**
 * Returns most recent notification sequence number from thePlatform.
 */
function media_theplatform_mpx_set_last_notification($account, $last_notification = NULL) {

  if (!is_numeric($last_notification)) {

    $token = media_theplatform_mpx_check_token($account->id);
    $url = 'https://read.data.media.theplatform.com/media/notify?token=' . $token .
      '&account=' . $account->import_account .
      '&filter=Media&clientId=drupal_media_theplatform_mpx_' . $account->account_pid;

    watchdog('media_theplatform_mpx', 'Resetting mpx last notification timestamp for @account.',
      array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_INFO);

    $result_data = _media_theplatform_mpx_retrieve_feed_data($url);

    if (empty($result_data[0]['id'])) {
      watchdog('media_theplatform_mpx', 'Failed to reset mpx last notification timestamp for @account.  "id" field value not set.',
        array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_ERROR);

      return FALSE;
    }

    $last_notification = $result_data[0]['id'];
  }

  _media_theplatform_mpx_set_field($account->id, 'last_notification', $last_notification);

  return TRUE;
}

/**
 * Query thePlatform Media service to get all published mpxMedia files.
 *
 * @param String $id
 *   Value for mpx_video.id.
 *
 * @param String $op
 *   Valid values: 'unpublished' or 'deleted'.
 */
function media_theplatform_mpx_set_mpx_video_inactive($id, $op) {

  watchdog('media_theplatform_mpx', 'Disabling video @id.', array('@id' => $id), WATCHDOG_NOTICE);

  // Set status to inactive.
  $inactive = db_update('mpx_video')
    ->fields(array('status' => 0))
    ->condition('id', $id, '=')
    ->execute();

  if ($inactive) {
    watchdog('media_theplatform_mpx', 'Successfully disabled video @id by setting its status to 0 in the mpx_video table.',
      array('@id' => basename($id)), WATCHDOG_INFO);
  }
  else {
    watchdog('media_theplatform_mpx', 'Failed to disable video @id by setting its status to 0 in the mpx_video table.',
      array('@id' => basename($id)), WATCHDOG_ERROR);
  }

  // Let other modules perform operations when videos are disabled.
  module_invoke_all('media_theplatform_mpx_set_video_inactive', $id, $op);
}
