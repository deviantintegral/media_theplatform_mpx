<?php

class MpxAccount {

  public $id;
  public $username;
  public $password;
  public $import_account;
  public $account_id;
  public $account_pid;
  public $default_player;
  public $last_notification;
  public $proprocessing_batch_url;
  public $proprocessing_batch_item_count;
  public $proprocessing_batch_current_item;

  /**
   * Constructs a new mpx account object, without saving it.
   *
   * @param array $values
   *   (optional) An array of values to set, keyed by property name.
   *
   * @return static
   */
  public static function create(array $values = array()) {
    $instance = new static();
    foreach ($values as $key => $value) {
      $instance->{$key} = $value;
    }
    return $instance;
  }

  /**
   * Loads an mpx account.
   *
   * @param int $id
   *   The mpx account ID to load.
   *
   * @return MpxAccount
   *   An mpx account object.
   */
  public static function load($id) {
    $accounts = static::loadMultiple(array($id));
    if (!empty($accounts[$id])) {
      return $accounts[$id];
    }
  }

  /**
   * Load multiple mpx accounts.
   *
   * @param array $ids
   *   An array of mpx account IDs.
   *
   * @return MpxAccount[]
   *   An array of mpx account objects, indexed by account ID.
   */
  public static function loadMultiple(array $ids) {
    if (empty($ids)) {
      return array();
    }
    $accounts = db_query("SELECT * FROM {mpx_accounts} WHERE id IN (:ids)", array(':ids' => $ids), array('fetch' => get_called_class()))->fetchAllAssoc('id');
    foreach ($accounts as $account) {
      $account->password = decrypt($account->password);
      module_invoke_all('media_theplatform_mpx_account_load', $account);
    }
    return $accounts;
  }

  /**
   * Load all mpx accounts.
   *
   * @return MpxAccount[]
   *   An array of mpx account objects, indexed by account ID.
   */
  public static function loadAll() {
    $accounts = db_query("SELECT * FROM {mpx_accounts}", array(), array('fetch' => get_called_class()))->fetchAllAssoc('id');
    foreach ($accounts as $account) {
      $account->password = decrypt($account->password);
      module_invoke_all('media_theplatform_mpx_account_load', $account);
    }
    return $accounts;
  }

  /**
   * Save the mpx account.
   *
   * @throws Exception
   */
  public function save() {
    $transaction = db_transaction();
    try {
      $this->is_new = empty($this->id);

      // Fetch the account_id and account_pid values.
      if (!empty($this->import_account) && empty($this->account_id) && empty($this->account_pid)) {
        if ($import_account = $this->fetchImportAccount()) {
          $this->account_id = preg_replace('|^http://|', 'https://', $import_account['id']);
          $this->account_pid = $import_account['pid'];
        }
        else {
          throw new Exception("Unable to fetch data about import account {$this->import_account} on mpx account {$this->username}");
        }
      }

      module_invoke_all('media_theplatform_mpx_account_presave', $this);

      $this->password = encrypt($this->password);

      if ($this->is_new) {
        drupal_write_record('mpx_accounts', $this);
        module_invoke_all('media_theplatform_mpx_account_insert', $this);
      }
      else {
        drupal_write_record('mpx_accounts', $this, array('id'));
        module_invoke_all('media_theplatform_mpx_account_update', $this);
      }

      unset($this->is_new);
    }
    catch (Exception $e) {
      $transaction->rollback();
      watchdog_exception('media_theplatform_mpx', $e);
      // If an error happened and we were unable to save the account, ensure
      // the cached token is released.
      $this->releaseToken();
      throw $e;
    }
  }

  /**
   * Deletes the mpx account.
   *
   * @throws Exception
   */
  public function delete() {
    $transaction = db_transaction();
    try {
      module_invoke_all('media_theplatform_mpx_account_delete', $this);
      // @todo Make account deletion use some kind of queue so it can be run without a batch process.
      _media_theplatform_mpx_delete_account($this->id, FALSE);
    }
    catch (Exception $e) {
      $transaction->rollback();
      watchdog_exception('media_theplatform_mpx', $e);
      throw $e;
    }
  }

  /**
   * Get a current authentication token for the account.
   *
   * @param int $duration
   *   The number of seconds for which the token should be valid.
   * @param bool $force
   *   Set to TRUE if a fresh authentication token should always be fetched.
   *
   * @return MpxToken
   *   A valid MPX token object.
   *
   * @throws Exception
   */
  public function acquireToken($duration = NULL, $force = FALSE) {
    try {
      $token = MpxToken::load($this->username);

      if ($force || !$token || !$token->isValid($duration)) {
        // Delete the token from the cache first in case there is a failure in
        // MpxToken::fetch() below.
        if ($token) {
          $token->delete();
        }

        // Log an error if the requested duration is larger than the token TTL.
        if ($duration && ($ttl = variable_get('media_theplatform_mpx__token_ttl')) && $duration > $ttl) {
          watchdog('media_theplatform_mpx', 'MpxToken::acquire() called with $duration @duration greater than the token TTL @ttl.', array('@duration' => $duration, '@ttl' => $ttl));
          $duration = $ttl;
        }

        $token = MpxToken::fetch($this->username, $this->password);
        // @todo Validate if the new token also valid for $duration.
        $token->save();
      }

      return $token;
    }
    catch (Exception $e) {
      watchdog_exception('media_theplatform_mpx', $e);
      throw $e;
    }
  }

  /**
   * Release the account's token if it has one.
   */
  public function releaseToken() {
    if ($token = MpxToken::load($this->username)) {
      $token->delete();
    }
  }

  /**
   * Fetch data about an mpx import account.
   *
   * @param string $import_account
   *   An optional import account title. Otherwise $account->import_account will
   *   be used.
   *
   * @return array|bool
   *   An array of data about the import account if available, otherwise FALSE.
   *
   * @throws InvalidArgumentException
   * @throws MpxApiException
   */
  public function fetchImportAccount($import_account = NULL) {
    if (!isset($import_account)) {
      $import_account = $this->import_account;
    }
    if (empty($import_account)) {
      throw new InvalidArgumentException("Empty parameter import_account provided to MpxAccount::fetchImportAccount.");
    }

    $data = MpxApi::authenticatedRequest(
      $this,
      'https://access.auth.theplatform.com/data/Account',
      array(
        'schema' => '1.3',
        'form' => 'cjson',
        'byDisabled'=> 'false',
        'byTitle' => $import_account,
        'fields' => 'id,guid,title,pid',
      )
    );
    return !empty($data['entries'][0]) ? $data['entries'][0] : FALSE;
  }

  /**
   * Fetch data about all the import accounts for an mpx account.
   *
   * @return array
   *   An array of arrays of data of the import accounts.
   *
   * @throws MpxApiException
   */
  public function fetchImportAccounts() {
    $import_accounts = &drupal_static(__METHOD__, array());
    $cid = 'import-accounts:' . $this->username;

    if (!isset($import_accounts[$cid])) {
      $import_accounts[$cid] = array();
      try {
        $data = MpxApi::authenticatedRequest(
          $this,
          'https://access.auth.theplatform.com/data/Account',
          array(
            'schema' => '1.3',
            'form' => 'cjson',
            'byDisabled'=> 'false',
            'fields' => 'id,guid,title,pid',
          )
        );
        $import_accounts[$cid] = $data['entries'];
      }
      catch (Exception $e) {
        watchdog_exception('media_theplatform_mpx', $e);
      }
    }

    return $import_accounts[$cid];
  }

  /**
   * Get the list of import accounts available to select for an mpx account.
   *
   * @param bool $exclude_existing
   *   If TRUE will exclude any import accounts currently in use by other mpx
   *   accounts. Default is TRUE.
   *
   * @return array
   *   An associative array of import account titles, keyed also by the import
   *   account titles.
   */
  public function getImportAccountOptions($exclude_existing = TRUE) {
    $import_accounts = $this->fetchImportAccounts();
    if (empty($import_accounts)) {
      return array();
    }

    $options = array();
    foreach ($import_accounts as $import_account) {
      $options[$import_account['title']] = $import_account['title'];
    }

    if ($exclude_existing) {
      $query = db_select('mpx_accounts', 'mpxa');
      $query->addField('mpxa', 'import_account');
      if (!empty($this->id)) {
        // Import account names are unique across all users on thePlatform so
        // there is no concern that two different mpx accounts would have the
        // same import account name.
        $query->condition('id', $this->id, '<>');
      }
      if ($existing_import_accounts = $query->execute()->fetchCol()) {
        $options = array_diff($options, $existing_import_accounts);
      }
    }

    natcasesort($options);
    return $options;
  }
}
