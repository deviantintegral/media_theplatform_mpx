<?php

class MpxRequestQueue {

  /**
   * @param MpxAccount $account
   *   The account to use
   *
   * @return DrupalReliableQueueInterface
   */
  public static function get(MpxAccount $account) {
    $name = 'media_theplatform_mpx_request_' . $account->id;
    return DrupalQueue::get($name, TRUE);
  }

  /**
   * Queue worker callback for a request queue item.
   *
   * @param array $data
   *   The queue item data as added in MpxRequestQueue::populateItems().
   *
   * @return bool
   *   TRUE on success, otherwise FALSE.
   */
  public static function processItem($data) {
    $account = MpxAccount::load($data['account_id']);
    $url = $data['url'];
    $data += array('params' => array(), 'options' => array());

    $data['options'] += array(
      'timeout' => variable_get('media_theplatform_mpx__cron_videos_timeout', 180),
    );

    $data = MpxApi::authenticatedRequest($account, $url, $data['params'], $data['options']);
    return _media_theplatform_mpx_process_video_import_feed_data($data, NULL, $account);
  }

  /**
   * Populate the request queue with the rest of an account's current batch.
   *
   * @param MpxAccount $account
   *   The mpx account.
   * @param int $limit
   *   The number of items to fetch in each request.
   *
   * @return int
   *   The number of request queue tasks that were added.
   *
   * @todo Add specific $url $start $count parameters instead of using proprocesing data.
   */
  public static function populateItems(MpxAccount $account, $limit = NULL) {
    $batch_url = $account->getDataValue('proprocessing_batch_url');

    if (!$batch_url) {
      // Nothing to batch.
      return 0;
    }

    $queue = MpxRequestQueue::get($account);
    $queue->createQueue();

    $batch_item_count = $account->getDataValue('proprocessing_batch_item_count');
    $current_batch_item = $account->getDataValue('proprocessing_batch_current_item');
    $limit = $limit ? $limit : variable_get('media_theplatform_mpx__cron_videos_per_run', 100);

    $count = 0;
    while ($current_batch_item <= $batch_item_count) {
      $data = array();
      $data['account_id'] = $account->id;
      $data['url'] = $batch_url;
      $data['params'] = array(
        'range' => $current_batch_item . '-' . ($current_batch_item + $limit - 1),
      );
      $queue->createItem($data);
      $count++;
      $current_batch_item += $limit;
    }

    $account->deleteMultipleDataValues(array(
      'proprocessing_batch_url',
      'proprocessing_batch_item_count',
      'proprocessing_batch_current_item',
    ));

    // Ensure a last_notification value is set now.
    if (!$account->getDataValue('last_notification')) {
      $account->setDataValue('last_notification', media_theplatform_mpx_get_last_notification($account));
    }

    return $count;
  }

}
