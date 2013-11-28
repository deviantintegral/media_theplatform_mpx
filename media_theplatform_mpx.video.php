<?php
/**
 * @file
 * functions for Videos.
 */


/**
 * Query thePlatform Media Notify service to get Media IDs that have changed.
 *
 * @param String $since
 *   The last notification ID from thePlatform used to Sync mpxMedia.
 *
 * @return Array
 *   Array of mpxMedia id's that have changed since $since.
 */
function media_theplatform_mpx_get_changed_ids($since) {
  $url = 'http://data.media.theplatform.com/media/notify?token=' . media_theplatform_mpx_variable_get('token') . '&account=' . media_theplatform_mpx_variable_get('import_account') . '&block=false&filter=Media&clientId=drupal_media_theplatform_mpx_' . media_theplatform_mpx_variable_get('account_pid') . '&since=' . $since;
  $options = array('timeout' => media_theplatform_mpx_variable_get('cron_get_changed_ids_timeout', 120) );
  $result = drupal_http_request($url, $options);
  if (isset($result->data)) {
    $result_data = drupal_json_decode($result->data);
    if (isset($result_data) && count($result_data) > 0) {

      // Initialize arrays to store active and deleted IDs
      $actives = array();
      $deletes = array();

      foreach ($result_data as $changed) {
        // If no method has been returned, there are no changes.
        if (!isset($changed['method'])) {
          return FALSE;
        }
        // Store last notification.
        $last_notification = $changed['id'];
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
      // Remove any deletes from actives because it causes errors when updating.
      $actives = array_diff($actives, $deletes);
      return array(
        'actives' => $actives,
        'deletes' => $deletes,
        'last_notification' => $last_notification,
      );
    }
  }
  return FALSE;
}

/**
 * Query thePlatform Media service to get all published mpxMedia files.
 *
 * @param String $ids
 *   If 'all', return all published mpxMedia for the account. Else its a string of ids.
 *
 * @return Array
 *   Returns multi dimensional array: data for published ids, and array of published ids.
 */
function media_theplatform_mpx_get_mpxmedia($ids) {

  $token = media_theplatform_mpx_variable_get('token');
  $account = urlencode(media_theplatform_mpx_variable_get('account_id'));
  if ($ids == 'all') {
    $ids = '';
  }
  else {
    $id_array = explode(',', $ids);
    $ids = '/' . $ids;
  }
  // Initialize arrays to store mpxMedia data.
  $videos = array();
  $published_ids = array();
  $unpublished_ids = array();

  // Get all Media that HasReleases (published) and belongs to import account id.
  $url = 'http://data.media.theplatform.com/media/data/Media' . $ids . '?schema=1.4.0&form=json&pretty=true&byOwnerId=' . $account . '&byContent=byHasReleases%3Dtrue&token=' . $token;
  $result = drupal_http_request($url);

  if (isset($result->data)) {
    $result_data = drupal_json_decode($result->data);
    if ($result_data) {
      // Keys entryCount and entries are only set when there is more than 1 entry.
      if (isset($result_data['entryCount'])) {
        foreach ($result_data['entries'] as $video) {
          $published_ids[] = basename($video['id']);
          $video_item = array(
            'id' => basename($video['id']),
            'guid' => $video['guid'],
            'title' => $video['title'],
            'description' => $video['description'],
            'thumbnail_url' => $video['plmedia$defaultThumbnailUrl'],
            'release_id' => $video['media$content'][0]['plfile$releases'][0]['plrelease$pid'],
          );

          // Allow modules to alter the video item for pulling in custom metadata.
          drupal_alter('media_theplatform_mpx_media_import_item', $video_item, $video);

          $videos[] = $video_item;
        }
      }
      // If only one row, result_data holds all the mpxMedia data (if its published).
      // If responseCode is returned, it means 1 id in $ids has been unpublished and
      // would return no data.
      elseif (!isset($result_data['responseCode'])) {
        $video = $result_data;
        $published_ids[] = basename($video['id']);
        $video_item = array(
          'id' => basename($video['id']),
          'guid' => $video['guid'],
          'title' => $video['title'],
          'description' => $video['description'],
          'thumbnail_url' => $video['plmedia$defaultThumbnailUrl'],
          'release_id' => $video['media$content'][0]['plfile$releases'][0]['plrelease$pid'],
        );

        // Allow modules to alter the video item for pulling in custom metadata.
        drupal_alter('media_theplatform_mpx_media_import_item', $video_item, $video);

        $videos[] = $video_item;
      }
      // Store any ids that have been unpublished.
      if (isset($id_array)) {
        $unpublished_ids = array_diff($id_array, $published_ids);
        // Filter out any ids which haven't been imported.
        foreach ($unpublished_ids as $key => $unpublished_id) {
          $video_exists = media_theplatform_mpx_get_mpx_video_by_field('id', $unpublished_id);
          if (!$video_exists) {
            unset($unpublished_ids[$key]);
          }
        }
      }
      return array(
        'published' => $videos,
        'unpublished' => $unpublished_ids,
      );
    }
  }
  return FALSE;
}

/**
 * Imports all videos into Media Library.
 *
 * @param String $type
 *   Import type. Possible values 'cron' or 'manual', for sync.
 *
 * @return Array
 *   $data['total'] - # of videos retrieved
 *   $data['num_inserts'] - # of videos added to mpx_video table
 *   $data['num_updates'] - # of videos updated
 *   $data['num_inactives'] - # of videos changed from active to inactive
 */
function media_theplatform_mpx_import_all_videos($type) {

  // Clicked on Videos Sync form.
  if ($type == 'manual') {
    global $user;
    $uid = $user->uid;
  }
  else {
    $uid = 0;
  }
  $log = array(
    'uid' => $uid,
    'type' => 'video',
    'type_id' => NULL,
    'action' => 'import',
    'details' => $type,
  );
  media_theplatform_mpx_insert_log($log);

  // Check if we have a notification stored.
  $since = media_theplatform_mpx_variable_get('last_notification');
  // If we do, just check for updates.
  if ($since) {
    // Get all IDs of mpxMedia that have been updated since last notification.
    $changed = media_theplatform_mpx_get_changed_ids($since);
    if (!$changed) {
      drupal_set_message(t('All mpxMedia is up to date.'));
      return FALSE;
    }
    $ids = NULL;
    if (count($changed['actives']) > 0) {
      $ids = implode(',', $changed['actives']);
    }
  }
  else {
    $ids = 'all';
  }

  // Initalize our counters.
  $num_inserted = 0;
  $num_updated = 0;
  $num_unpublished = 0;
  $num_deleted = 0;

  // Import mpxMedia.
  if ($ids != NULL) {
    $videos = media_theplatform_mpx_get_mpxmedia($ids);
    // If no result, return FALSE.
    if (!$videos) {
      return FALSE;
    }
    // Loop through published videos and update.
    foreach ($videos['published'] as $video) {
      // Import this video.
      $op = media_theplatform_mpx_import_video($video);
      // Allow modules to perform additional media import tasks.
      module_invoke_all('media_theplatform_mpx_import_media', $op, $video);
      if ($op == 'insert') {
        $num_inserted++;
      }
      elseif ($op == 'update') {
        $num_updated++;
      }
    }
    // Set any unpublished mpxMedia to inactive.
    foreach ($videos['unpublished'] as $unpublish_id) {
      media_theplatform_mpx_set_mpx_video_inactive($unpublish_id, 'unpublished');
      $num_unpublished++;
    }
  }

  // Set any deleted mpxMedia to inactive.
  if ($since && count($changed['deletes'] > 0)) {
    foreach ($changed['deletes'] as $delete_id) {
      media_theplatform_mpx_set_mpx_video_inactive($delete_id, 'deleted');
      $num_deleted++;
    }
  }

  // If this was an update, set last notification.
  if ($since) {
    media_theplatform_mpx_variable_set('last_notification', $changed['last_notification']);
  }
  // Else set last notification by querying thePlatform for a notification.
  else {
    media_theplatform_mpx_set_last_notification();
  }

  // Return counters as an array.
  return array(
    'inserted' => $num_inserted,
    'updated' => $num_updated,
    'unpublished' => $num_unpublished,
    'deleted' => $num_deleted,
  );
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
function media_theplatform_mpx_import_video($video) {
  // Check if fid exists in files table for URI = mpx://m/GUID/*.
  $guid = $video['guid'];
  $files = media_theplatform_mpx_get_files_by_guid($guid);

  // If a file exists:
  if ($files) {
    // Check if record already exists in mpx_video.
    $imported = db_query("SELECT video_id FROM {mpx_video} WHERE guid=:guid", array(':guid' => $guid))->fetchField();
    // If mpx_video record exists, then update record.
    if ($imported) {
      return media_theplatform_mpx_update_video($video);
    }
    // Else insert new mpx_video record with existing $fid.
    else {
      return media_theplatform_mpx_insert_video($video, $files[0]->fid);
    }
  }
  // Else insert new file/record:
  else {
    return media_theplatform_mpx_insert_video($video, NULL);
  }
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
function media_theplatform_mpx_insert_video($video, $fid = NULL) {
  $timestamp = REQUEST_TIME;
  $player_id = !empty($video['player_id']) ? $video['player_id'] : media_theplatform_mpx_variable_get('default_player_fid');

  // If file doesn't exist, write it to file_managed.
  if (!$fid) {
    // Build embed string to create file:
    // "m" is for media.
    $embed_code = 'mpx://m/' . $video['guid'] . '/p/' . $player_id;
    // Create the file.
    $provider = media_internet_get_provider($embed_code);
    $file = $provider->save();
    $fid = $file->fid;
    $details = 'new fid = ' . $fid;
  }
  else {
    $details = 'existing fid = ' . $fid;
  }

  // Insert record into mpx_video.
  $video_id = db_insert('mpx_video')
    ->fields(array(
      'title' => $video['title'],
      'guid' => $video['guid'],
      'description' => $video['description'],
      'fid' => $fid,
      'player_id' => !empty($video['player_id']) ? $video['player_id'] : null,
      'account' => media_theplatform_mpx_variable_get('import_account'),
      'thumbnail_url' => $video['thumbnail_url'],
      'release_id' => $video['release_id'],
      'created' => $timestamp,
      'updated' => $timestamp,
      'status' => 1,
      'id' => $video['id'],
    ))
    ->execute();

  // Load default mpxPlayer for appending to Filename.
  $player = media_theplatform_mpx_get_mpx_player_by_fid($player_id);
  media_theplatform_mpx_update_video_filename($fid, $video['title'], $player['title']);

  // Write mpx_log record.
  global $user;
  $log = array(
    'uid' => $user->uid,
    'type' => 'video',
    'type_id' => $video_id,
    'action' => 'insert',
    'details' => $details,
  );
  media_theplatform_mpx_insert_log($log);

  // Return code to be used by media_theplatform_mpx_import_all_videos().
  return 'insert';
}

/**
 * Updates File $fid with given $video_title and $player_title.
 */
function media_theplatform_mpx_update_video_filename($fid, $video_title, $player_title) {
  // Update file_managed filename with title of video.
  $file_title = db_update('file_managed')
    ->fields(array(
      'filename' => $video_title . ' - ' . $player_title,
    ))
    ->condition('fid', $fid, '=')
    ->execute();
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
function media_theplatform_mpx_update_video($video) {
  $timestamp = REQUEST_TIME;

  // Fetch video_id and status from mpx_video table for given $video.
  $mpx_video = media_theplatform_mpx_get_mpx_video_by_field('guid', $video['guid']);

  // If we're performing an update, it means this video is active.
  // Check if the video was inactive and is being re-activated:
  if ($mpx_video['status'] == 0) {
    $details = 'video re-activated';
  }
  else {
    $details = NULL;
  }

  // Update mpx_video record.
  $update = db_update('mpx_video')
    ->fields(array(
      'title' => $video['title'],
      'guid' => $video['guid'],
      'description' => $video['description'],
      'thumbnail_url' => $video['thumbnail_url'],
      'status' => 1,
      'release_id' => $video['release_id'],
      'updated' => $timestamp,
      'id' => $video['id'],
    ))
    ->condition('guid', $video['guid'], '=')
    ->execute();

  // @todo: (maybe). Update all files with guid with new title if the title is different.
  $image_path = 'media-mpx/' . $video['guid'] . '.jpg';
  // Delete thumbnail from files/media-mpx directory.
  file_unmanaged_delete('public://' . $image_path);
  // Delete thumbnail from all the styles.
  // Now, the next time file is loaded, MediaThePlatformMpxStreamWrapper
  // will call getOriginalThumbnail to update image.
  image_path_flush($image_path);

  // Write mpx_log record.
  global $user;
  $log = array(
    'uid' => $user->uid,
    'type' => 'video',
    'type_id' => $mpx_video['video_id'],
    'action' => 'update',
    'details' => $details,
  );
  media_theplatform_mpx_insert_log($log);

  // Return code to be used by media_theplatform_mpx_import_all_videos().
  return 'update';
}

/**
 * Returns associative array of mpx_video data for given File $fid.
 */
function media_theplatform_mpx_get_mpx_video_by_fid($fid) {
  return db_select('mpx_video', 'v')
    ->fields('v')
    ->condition('fid', $fid, '=')
    ->execute()
    ->fetchAssoc();
}

/**
 * Returns associative array of mpx_video data for given $field and its $value.
 */
function media_theplatform_mpx_get_mpx_video_by_field($field, $value) {
  return db_select('mpx_video', 'v')
    ->fields('v')
    ->condition($field, $value, '=')
    ->execute()
    ->fetchAssoc();
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
 * Returns array of all records in file_managed with mpx://m/$guid/%.
 */
function media_theplatform_mpx_get_files_by_guid($guid) {
  return db_select('file_managed', 'f')
    ->fields('f')
    ->condition('uri', 'mpx://m/' . $guid . '/%', 'LIKE')
    ->execute()
    ->fetchAll();
}

/**
 * Returns array of all records in file_managed with mpx://m/%/p/[player_fid].
 */
function media_theplatform_mpx_get_files_by_player_fid($fid) {
  return db_select('file_managed', 'f')
    ->fields('f')
    ->condition('uri', 'mpx://m/%', 'LIKE')
    ->condition('uri', '%/p/' . $fid, 'LIKE')
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
function media_theplatform_mpx_set_last_notification() {

  $url = 'http://data.media.theplatform.com/media/notify?token=' . media_theplatform_mpx_variable_get('token') . '&account=' . media_theplatform_mpx_variable_get('import_account') . '&filter=Media&clientId=drupal_media_theplatform_mpx_' . media_theplatform_mpx_variable_get('account_pid');
  $result = drupal_http_request($url);
  $result_data = drupal_json_decode($result->data);
  if ($result_data) {
    media_theplatform_mpx_variable_set('last_notification', $result_data[0]['id']);
  }
  return FALSE;
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
  // Set status to inactive.
  $inactive = db_update('mpx_video')
  ->fields(array('status' => 0))
  ->condition('id', $id, '=')
  ->execute();

  // Let other modules perform operations when videos are disabled.
  module_invoke_all('media_theplatform_mpx_set_video_inactive', $id, $op);

  // Write mpx_log record.
  // @todo: Set type_id to $id once type_id gets changed to varchar.
  global $user;
  $log = array(
    'uid' => $user->uid,
    'type' => 'video',
    'type_id' => NULL,
    'action' => $op,
    'details' => $id,
  );
  media_theplatform_mpx_insert_log($log);
}
