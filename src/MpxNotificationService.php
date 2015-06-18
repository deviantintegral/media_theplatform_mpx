<?php

class MpxNotificationService {

  /** @var \MpxAccount */
  protected $account;

  /** @var string */
  protected $url;

  /** @var string */
  protected $dataKey;

  /** @var array */
  protected $params;

  /** @var array */
  protected $options;

  /**
   * Construct an mpx notification service.
   *
   * @param MpxAccount $account
   *   The mpx account.
   * @param string $url
   *   The notification URL.
   * @param string $dataKey
   *   The key to use when retrieving and storing the current notification ID
   *   value using $account->getDataValue() and $account->setDataValue().
   * @param array $params
   *   An additional array of parameters to pass through when calling
   *   MpxApi::authenticatedRequest().
   * @param array $options
   *   An additional array of options to pass through when calling
   *   MpxApi::authenticatedRequest().
   */
  public function __construct(MpxAccount $account, $url, $dataKey = NULL, array $params = array(), array $options = array()) {
    $this->account = $account;
    $this->url = $url;
    $this->dataKey = $dataKey;
    $this->params = $params + array(
      'account' => $this->account->import_account,
      'clientId' => 'drupal_media_theplatform_mpx_' . $this->account->account_pid,
    );
    $this->options = $options;
  }

  /**
   * Retrieves the latest notification sequence ID for an account.
   *
   * @param array $params
   *   An additional array of parameters to pass through when calling
   *   MpxApi::authenticatedRequest().
   * @param array $options
   *   An additional array of options to pass through when calling
   *   MpxApi::authenticatedRequest().
   *
   * @return string
   *   The notification sequence ID.
   *
   * @throws UnexpectedValueException
   * @throws MpxException
   */
  public function fetchLatestNotificationId(array $params = array(), array $options = array()) {
    $data = MpxApi::authenticatedRequest(
      $this->account,
      $this->url,
      drupal_array_merge_deep($this->params, $params),
      $options + $this->options
    );

    if (!isset($data[0]['id'])) {
      throw new MpxException("Unable to fetch the latest notification sequence ID from {$this->url} for {$this->account}.");
    }
    elseif (!is_numeric($data[0]['id'])) {
      throw new UnexpectedValueException("The latest notification sequence ID {$data[0]['id']} from {$this->url} for {$this->account} was not a numeric value.");
    }
    else {
      watchdog(
        'media_theplatform_mpx',
        'Fetched the latest notification sequence ID @value from @url for @account',
        array(
          '@value' => $data[0]['id'],
          '@url' => $this->url,
          '@account' => (string) $this->account,
        ),
        WATCHDOG_INFO
      );
      return $data[0]['id'];
    }
  }

  /**
   * Perform a notification service request.
   *
   * @param int &$notification_id
   *   The last seen notification sequence ID.
   * @param bool $run_until_empty
   *   If TRUE will keep making requests to the notification URL until it
   *   returns no results.
   * @param array $params
   *   An additional array of parameters to pass through when calling
   *   MpxApi::authenticatedRequest().
   * @param array $options
   *   An additional array of options to pass through when calling
   *   MpxApi::authenticatedRequest().
   *
   * @return MpxNotification[]
   *   An array of notification objects.
   *
   * @throws Exception
   * @throws MpxApiException
   * @throws MpxNotificationExpiredException
   */
  public function requestNotifications(&$notification_id, $run_until_empty = FALSE, array $params = array(), array $options = array()) {
    if (!$notification_id) {
      throw new Exception("Cannot call MpxNotificationService::request() with an empty notification sequence ID.");
    }

    $notifications = array();

    $params += array(
      'block' => 'false',
      'size' => variable_get('media_theplatform_mpx_notification_size', 500),
    );
    $options += array(
      'timeout' => variable_get('media_theplatform_mpx__cron_videos_timeout', 180),
    );

    do {
      try {
        $data = MpxApi::authenticatedRequest(
          $this->account,
          $this->url,
          drupal_array_merge_deep(
            $this->params,
            $params,
            array('since' => $notification_id)
          ),
          $options + $this->options
        );

        // Process the notifications.
        $notifications = array_merge(
          $notifications,
          $this->processNotifications($data, $notification_id)
        );
      }
      catch (MpxApiException $exception) {
        // A 404 response means the notification ID that we have is now older than
        // 7 days, and now we have to start ingesting from the beginning again.
        if ($exception->getException()->responseCode == 404) {
          throw new MpxNotificationExpiredException("The notification sequence ID {$notification_id} for {$this->account} is older than 7 days and is too old to fetch notifications.", $exception->getCode(), $exception);
        }
        else {
          throw $exception;
        }
      }
    } while ($run_until_empty && count($data) == $params['size']);

    watchdog(
      'media_theplatform_mpx',
      'Fetched @count notifications from @url for @account.',
      array(
        '@count' => count($notifications),
        '@url' => $this->url,
        '@account' => (string) $this->account,
      ),
      WATCHDOG_INFO
    );

    return $notifications;
  }

  /**
   * Process the notifications from the API.
   *
   * @param array $notifications
   *   The array of raw notification data.
   * @param string &$notification_id
   *   The notification ID to update.
   *
   * @return MpxNotification[]
   *   An array of notification objects.
   */
  public function processNotifications(array $notifications, &$notification_id = NULL) {
    $return = array();

    // Process the notifications.
    foreach ($notifications as $notification) {
      // Update the most recently seen notification ID.
      $notification_id = $notification['id'];

      if (!empty($notification['entry'])) {
        $return[] = new MpxNotification(
          $notification['type'],
          // The ID is always a fully qualified URI, and we only care about the
          // actual ID value, which is at the end.
          basename($notification['entry']['id']),
          $notification['method'],
          $notification['entry']['updated']
        );
      }
    }

    return $return;
  }

  /**
   * Retrieves the current notification service sequence ID for the account.
   *
   * @return string
   *   The notification sequence ID.
   */
  public function getCurrentNotificationId() {
    return $this->account->getDataValue($this->dataKey);
  }

  /**
   * Sets the current notification service sequence ID for the account.
   *
   * @param string
   *   The notification sequence ID.
   */
  public function setCurrentNotificationId($value) {
    if (!$value) {
      $this->resetCurrentNotificationId();
    }
    elseif ($value != $this->getCurrentNotificationId()) {
      $this->account->setDataValue($this->dataKey, $value);
      watchdog(
        'media_theplatform_mpx',
        'Saved notification sequence ID @value to @key for @account.',
        array(
          '@value' => $value,
          '@key' => $this->dataKey,
          '@account' => (string) $this->account,
        ),
        WATCHDOG_INFO
      );
    }
  }

  /**
   * Reset the current notification service sequence ID for the account.
   */
  public function resetCurrentNotificationId() {
    $this->account->deleteDataValue($this->dataKey);
    watchdog(
      'media_theplatform_mpx',
      'The saved notification sequence ID @key for @account has been reset.',
      array(
        '@key' => $this->dataKey,
        '@account' => (string) $this->account,
      ),
      WATCHDOG_WARNING
    );
  }

}

class MpxPlayerNotificationService extends MpxNotificationService {

  /**
   * Get an instance of an mpx player notification service.
   *
   * @param MpxAccount $account
   *   The mpx account.
   * @param array $params
   *   An additional array of parameters to pass through when calling
   *   MpxApi::authenticatedRequest().
   * @param array $options
   *   An additional array of options to pass through when calling
   *   MpxApi::authenticatedRequest().
   *
   * @return static
   */
  public static function getInstance(MpxAccount $account, array $params = array(), array $options = array()) {
    return new static(
      $account,
      'https://read.data.player.theplatform.com/player/notify',
      'player_notification_id',
      $params,
      $options
    );
  }

}

class MpxMediaNotificationService extends MpxNotificationService {

  /**
   * Get an instance of an mpx media notification service.
   *
   * @param MpxAccount $account
   *   The mpx account.
   * @param array $params
   *   An additional array of parameters to pass through when calling
   *   MpxApi::authenticatedRequest().
   * @param array $options
   *   An additional array of options to pass through when calling
   *   MpxApi::authenticatedRequest().
   *
   * @return static
   */
  public static function getInstance(MpxAccount $account, array $params = array(), array $options = array()) {
    return new static(
      $account,
      'https://read.data.media.theplatform.com/media/notify',
      'last_notification',
      $params,
      $options
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resetCurrentNotificationId() {
    $this->account->resetIngestion();
  }

}

class MpxNotification {

  public $type;
  public $id;
  public $method;
  public $updated;

  public function __construct($type, $id, $method, $updated = NULL) {
    $this->type = $type;
    $this->id = $id;
    $this->method = $method;
    $this->updated = $updated;
  }

}

class MpxNotificationExpiredException extends MpxException {}
