<?php

/**
 * @file
 * MPX integration for the media_theplatform_mpx module.
 */
/**
 * Implements hook_media_theplatform_mpx_account_status().
 */
function media_theplatform_mpx_media_theplatform_mpx_account_status(MpxAccount $account) {
  $status = array();

  // Report the number of ingested players.
  $video_count = db_select('mpx_video', 'mpxv')
    ->condition('account', $account->import_account)
    ->countQuery()->execute()->fetchField();
  $status['video_count'] = array(
    'title' => t('Ingested videos'),
    'value' => $video_count,
    'severity' => $video_count ? REQUIREMENT_OK : REQUIREMENT_WARNING,
  );

  // Report the number of ingested players.
  $player_count = db_select('mpx_player', 'mpxp')
    ->condition('account', $account->import_account)
    ->countQuery()->execute()->fetchField();
  $status['player_count'] = array(
    'title' => t('Ingested players'),
    'value' => $player_count,
    'severity' => $player_count ? REQUIREMENT_OK : REQUIREMENT_WARNING,
  );

  // Report if there are any videos to be processed in the queue.
  $cron_queue_count = db_select('queue', 'q')
    ->condition('name', 'media_theplatform_mpx_video_cron_queue')
    ->condition('data', '%' . db_like('s:10:"account_id";s:' . strlen($account->id) . ':"' . $account->id . '";') . '%', 'LIKE')
    ->countQuery()->execute()->fetchField();
  $status['cron_queue_count'] = array(
    'title' => t('Queued videos to process'),
    'value' => $cron_queue_count,
  );
  if (user_access('administer site configuration') && $cron_queue_count) {
    $cron_url = url('admin/reports/status/run-cron', array('query' => drupal_get_destination()));
    $status['cron_queue_count']['description'] = t('<a href="@cron">Run cron now</a> to process the videos from the <em>media_theplatform_mpx_video_cron_queue</em> queue.', array('@cron' => $cron_url));
  }

  // Report if the account has a current and valid API token.
  $status['token'] = array(
    'title' => t('API token'),
  );
  try {
    $token = $account->acquireToken();
    $status['token']['value'] = $token->value;
    $status['token']['description'] = t('Expires in !interval', array('!interval' => format_interval($token->expire - REQUEST_TIME)));
  }
  catch (Exception $exception) {
    $status['token']['value'] = filter_xss($exception->getMessage());
    $status['token']['severity'] = REQUIREMENT_ERROR;
    // Anything beyond this point requires an API token, so just return.
    return $status;
  }

  // Report on the last notification sequence ID that has been processed.
  $status['last_notification_id'] = array(
    'title' => t('Last notification sequence ID processed'),
    'description' => t('Sequence IDs are only stored by thePlatform for 7 days, after which they are invalid and a complete re-ingestion of all videos will be automatically performed.')
  );
  if ($last_notification = $account->getDataValue('last_notification')) {
    try {
      // Verify that the current sequence ID will return data without error.
      MpxApi::authenticatedRequest(
        $account,
        'https://read.data.media.theplatform.com/media/notify',
        array(
          'account' => $account->import_account,
          'block' => 'false',
          'filter' => 'Media',
          'clientId' => 'drupal_media_theplatform_mpx_' . $account->account_pid,
          'since' => $last_notification,
          'size' => 1,
        )
      );
      $status['last_notification_id']['value'] = $last_notification;
    }
    catch (MpxApiException $exception) {
      // A 404 response means the notification ID that we have is now older than
      // 7 days, and now we have to start ingesting from the beginning again.
      if ($exception->getException()->responseCode == 404) {
        $exception->setMessage("The last notification ID {$last_notification} for {$account} is older than 7 days and is too old to fetch notifications. The last notification ID has been reset to re-start ingestion of all videos.");
      }
      $status['last_notification_id']['value'] = $exception->getMessage();
      $status['last_notification_id']['severity'] = REQUIREMENT_ERROR;
    }
    catch (Exception $exception) {
      $status['last_notification_id']['value'] = $exception->getMessage();
      $status['last_notification_id']['severity'] = REQUIREMENT_ERROR;
    }
  }
  else {
    $status['last_notification_id']['value'] = t('Not available');
    $status['last_notification_id']['severity'] = REQUIREMENT_WARNING;
  }

  // Report on the current notification sequence ID from the API.
  if ($last_notification) {
    $status['current_notification_id'] = array(
      'title' => t('Current notification sequence ID from thePlatform'),
    );
    try {
      $current_notification = media_theplatform_mpx_get_last_notification($account);
      $status['current_notification_id']['value'] = $current_notification;
      if ($last_notification != $current_notification) {
        // Check to see how far behind in notifications we are.
        $data = MpxApi::authenticatedRequest(
          $account,
          'https://read.data.media.theplatform.com/media/notify',
          array(
            'account' => $account->import_account,
            'filter' => 'Media',
            'clientId' => 'drupal_media_theplatform_mpx_' . $account->account_pid,
            'history' => $last_notification . '-' . $current_notification,
          ),
          array(
            'timeout' => variable_get('media_theplatform_mpx__cron_videos_timeout', 180),
          )
        );

        // Only warn if the number of notifications is greater than the amount
        // we can process in one run.
        if (count($data) >= variable_get('media_theplatform_mpx_notification_size', 500)) {
          $status['current_notification_id']['severity'] = REQUIREMENT_WARNING;
          $status['current_notification_id']['description'] = t('There are 500 or more notification sequence IDs that have yet to be processed.');
        }
        else {
          $status['current_notification_id']['description'] = t('There are @count notification sequence IDs that have yet to be processed.', array('@count' => count($data)));
        }
      }

    }
    catch (Exception $exception) {
      $status['current_notification_id']['value'] = $exception->getMessage();
      $status['current_notification_id']['severity'] = REQUIREMENT_ERROR;
    }
  }

  return $status;
}
