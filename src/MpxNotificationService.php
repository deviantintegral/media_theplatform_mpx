<?php

abstract class MpxNotificationService {

  /** @var \MpxAccount */
  protected $account;

  /** @var array */
  protected $params;

  /** @var array */
  protected $options;

  /** @var string */
  protected $url;

  /** @var string */
  protected $notificationDataValueKey;

  /**
   * Construct an mpx player service.
   *
   * @param MpxAccount $account
   *   The mpx account.
   * @param array $params
   *   An additional array of parameters to pass through when calling
   *   MpxApi::authenticatedRequest().
   * @param array $options
   *   An additional array of options to pass through when calling
   *   MpxApi::authenticatedRequest().
   */
  public function __construct(MpxAccount $account, array $params = array(), array $options = array()) {
    $this->account = $account;
    $this->params = $params + array(
      'account' => $this->account->import_account,
      'clientId' => 'drupal_media_theplatform_mpx_' . $this->account->account_pid,
    );
    $this->options = $options;
  }

  /**
   * Get an instance of an mpx player service.
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
    return new static($account, $params, $options);
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
   * @return array
   *
   * @throws Exception
   * @throws MpxApiException
   * @throws MpxNotificationInvalidException
   */
  public function request(&$notification_id, $run_until_empty = FALSE, array $params = array(), array $options = array()) {
    if (!$notification_id) {
      throw new Exception("Cannot call MpxNotificationService::request() with an empty notification sequence ID.");
    }

    $results = array();
    $count = 0;

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
            array('size' => $notification_id)
          ),
          $options + $this->options
        );
      }
      catch (MpxApiException $exception) {
        // A 404 response means the notification ID that we have is now older than
        // 7 days, and now we have to start ingesting from the beginning again.
        if ($exception->getException()->responseCode == 404) {
          // @todo Should we just watchdog() and return here instead?
          $notification_id = NULL;
          throw new MpxNotificationInvalidException("The notification sequence ID {$notification_id} for {$this->account} is older than 7 days and is too old to fetch notifications.", $exception->getCode(), $exception);
        }
        else {
          throw $exception;
        }
      }

      foreach ($data as $notification) {
        // Update the most recently seen notification ID.
        $notification_id = $notification['id'];

        if (!empty($notification['entry'])) {
          // The ID is always a fully qualified URI, and we only care about the
          // actual ID value, which is at the end.
          $id = basename($notification['entry']['id']);

          // Group results by the 'method' value.
          $method = $notification['method'];
          if (!isset($results[$method])) {
            $results[$method] = array($id);
          }
          elseif (!in_array($id, $results[$method])) {
            $results[$method][] = $id;
          }
        }
      }
      $count += count($data);

    } while ($run_until_empty && count($data) == $params['size']);

    watchdog(
      'media_theplatform_mpx',
      'Fetched @count notifications from @url for @account. @result',
      array(
        '@count' => $count,
        '@url' => $this->url,
        '@account' => (string) $this->account,
        '@result' => $results ? '<br/>' . print_r($results, TRUE) : '',
      ),
      WATCHDOG_INFO
    );

    return $results;
  }

  /**
   * Retrieves the current notification service sequence ID for the account.
   *
   * @return string
   *   The notification sequence ID.
   */
  public function getCurrentNotificationId() {
    return $this->account->getDataValue($this->notificationDataValueKey);
  }

  /**
   * Sets the current notification service sequence ID for the account.
   *
   * @param string
   *   The notification sequence ID.
   */
  public function setCurrentNotificationId($value) {
    if ($value != $this->getCurrentNotificationId()) {
      $this->account->setDataValue($this->notificationDataValueKey, $value);
      watchdog(
        'media_theplatform_mpx',
        'Saved notification sequence ID @value to @key for @account.',
        array(
          '@value' => $value,
          '@key' => $this->notificationDataValueKey,
          '@account' => (string) $this->account,
        ),
        WATCHDOG_INFO
      );
    }
  }

  /**
   * Reset the current notification service sequence ID for the account.
   */
  public function reset() {
    $this->account->deleteDataValue($this->notificationDataValueKey);
    watchdog(
      'media_theplatform_mpx',
      'The saved notification sequence ID @key for @account has been reset.',
      array(
        '@key' => $this->notificationDataValueKey,
        '@account' => (string) $this->account,
      ),
      WATCHDOG_WARNING
    );
  }

}

class MpxPlayerNotificationService extends MpxNotificationService {

  /** @var string */
  protected $url = 'https://read.data.player.theplatform.com/player/notify';

  /** @var string */
  protected $notificationDataValueKey = 'player_notification_id';

}

class MpxMediaNotificationService extends MpxNotificationService {

  /** @var string */
  protected $url = 'https://read.data.media.theplatform.com/media/notify';

  /** @var string */
  protected $notificationDataValueKey = 'last_notification';

  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->account->resetIngestion();
  }

}

class MpxNotificationInvalidException extends MpxException {}
