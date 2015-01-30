<?php
/**
 * @file
 * functions for mpxPlayers.
 */

/**
 * Requests all mpxPlayers for specified thePlatform account.
 *
 * - Returns array of mpxPlayers' data indexed by mpxPlayer id if there are mpxPlayers.
 * - Returns FALSE if no mpxPlayers exist in mpx account.
 * - Returns error msg if no mpx_token variable.
 */
function media_theplatform_mpx_get_players_from_theplatform($account) {

  global $user;

  // Check for the signIn token and account.
  $mpx_token = media_theplatform_mpx_check_token($account->id);
  $mpx_sub_account = $account->import_account;

  if (!$mpx_token) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve mpx players for @acccount. Authentication token not available.',
      array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_ERROR);

    return FALSE;
  }
  if (!$mpx_sub_account) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve mpx players for @acccount. Import account not available.',
      array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_ERROR);

    return FALSE;
  }

  // @todo - do some kind of check to bring back a max # of records?
  // Get the list of players from thePlatform.
  $player_url = 'https://read.data.player.theplatform.com/player/data/Player?schema=1.3.0&form=json&token=' . $mpx_token . '&account=' . $mpx_sub_account;

  $result_data = _media_theplatform_mpx_retrieve_feed_data($player_url);

  if (!isset($result_data['entryCount'])) {
    watchdog('media_theplatform_mpx', 'Failed to retrieve mpx players for @acccount.  "entryCount" field value not set.',
      array('@account' => _media_theplatform_mpx_account_log_string($account)), WATCHDOG_ERROR);

    return FALSE;
  }

  $player_ids = array();
  foreach ($result_data['entries'] as $player) {
    $player_ids[] = basename($player['id']);
    // We only want mpxPlayers which are not disabled.
    if (!$player['plplayer$disabled']) {
      $players[] = array(
        'id' => basename($player['id']),
        'guid' => $player['guid'],
        'title' => $player['title'],
        'description' => $player['description'],
        'pid' => $player['plplayer$pid'],
        'parent_account' => $account->id,
        'account' => $mpx_sub_account,
      );
    }
  }

  watchdog('media_theplatform_mpx', '@count players returned for @account.  Player IDs: @ids',
    array(
      '@account' => _media_theplatform_mpx_account_log_string($account),
      '@count' => $result_data['entryCount'],
      '@ids' => implode(', ', $player_ids),
    ),
    WATCHDOG_DEBUG);

  return $players;
}

/**
 * Returns array of mpxPlayer fid's and Titles.
 */
function media_theplatform_mpx_get_players_select($account = NULL, $key = 'player_id') {

  // Retrieve players from mpx_player.
  $query = db_select('mpx_player', 'p');
  $query->join('mpx_accounts', 'a', 'a.id = p.parent_account');
  $query->fields('p', array($key, 'title', 'pid', 'id'))
    ->fields('a', array('import_account'))
    ->condition('p.status', 1, '=')
    ->orderBy('title', 'ASC');

  if ($account) {
    $query = $query->condition('parent_account', $account->id, '=');
  }

  $result = $query->execute();
  $num_rows = $query->countQuery()->execute()->fetchField();

  if ($num_rows == 0) {
    return array();
  }

  // Index by file fid.
  while ($record = $result->fetchAssoc()) {
    $players[ urldecode($record['import_account']) ][ $record[$key] ] = $record['id'] . ' - ' . $record['pid'] . ' - ' . $record['title'];
  }

  return $players;
}

/**
 * Returns TRUE if given mpxPlayer $fid matches given $account.
 */
function media_theplatform_mpx_is_valid_player_for_account($player_id, $account = NULL) {

  if (!$player_id) {
    return FALSE;
  }

  $player = media_theplatform_mpx_get_mpx_player_by_player_id($player_id);

  if (is_array($player) && !empty($player['player_id'])) {
    return TRUE;
  }

  return FALSE;
}

/**
 * Returns URL string of a player for given $pid.
 */
function media_theplatform_mpx_get_player_url($pid, $account = NULL) {

  return 'https://player.theplatform.com/p/' . $account->account_pid . '/' . $pid;
}

/**
 * Returns HTML from a mpxPlayer's URL.
 *
 * @param String $pid
 *   A mpxPlayer's Pid.
 * @param String $type
 *   Either 'head' or 'body'.
 *
 * @return String
 *   HTML requested.
 */
function media_theplatform_mpx_get_player_html($pid, $type = 'head', $account) {

  $url = media_theplatform_mpx_get_player_url($pid, $account) . '/' . $type;

  return _media_theplatform_mpx_retrieve_feed_data($url, FALSE);
}

/**
 * Imports all mpxPlayers into Media Library.
 *
 * @param String $type
 *   Import type. Possible values 'cron' or 'manual', for sync.
 *
 * @return Array
 *   $data['total'] - # of players retrieved
 *   $data['num_inserts'] - # of players added to mpx_player table
 *   $data['num_updates'] - # of players updated
 *   $data['num_inactives'] - # of players changed from active to inactive
 */
function media_theplatform_mpx_import_all_players($type = NULL) {
  // This log message may seem redundant, but it's important for detecting if an
  // ingestion process has begun and is currently in progress.
  watchdog('media_theplatform_mpx', 'Beginning player import/update process @method for all accounts.', array('@method' => $type), WATCHDOG_INFO);

  // Initalize our counters.
  $inserts = array();
  $updates = array();
  $inactives = array();
  $num_players = 0;
  $incoming = array();

  // Retrieve list of players for all accounts.
  foreach (_media_theplatform_mpx_get_account_data() as $account_data) {
    // Check if player sync has been turned off for this account.
    if (!media_theplatform_mpx_variable_get('account_' . $account_data->id . '_cron_player_sync', 1)) {
      continue;
    }
    $account_players = media_theplatform_mpx_get_players_from_theplatform($account_data);
    if ($account_players) {
      // Loop through players retrieved.
      foreach ($account_players as $player) {
        // Keep track of the incoming ID.
        $incoming[] = $player['id'];
        // Import this player.
        $op = media_theplatform_mpx_import_player($player, $account_data);
        if ($op == 'insert') {
          $inserts[] = $player['id'];
        }
        elseif ($op == 'update') {
          $updates[] = $player['id'];
        }
        $num_players++;
      }
    }
  }

  if (empty($incoming)) {
    return array(
      'total' => $num_players,
      'inserts' => count($inserts),
      'updates' => count($updates),
      'inactives' => count($inactives),
    );
  }

  // Find all mpx_player records NOT in $incoming with status = 1.
  $inactives_result = db_select('mpx_player', 'p')
    ->fields('p', array('player_id', 'fid', 'id'))
    ->condition('id', $incoming, 'NOT IN')
    ->condition('status', 1, '=')
    ->execute();

  // Loop through results:
  while ($record = $inactives_result->fetchAssoc()) {
    // Set status to inactive.
    $inactive = db_update('mpx_player')
      ->fields(array('status' => 0))
      ->condition('player_id', $record['player_id'], '=')
      ->execute();
    if (!$inactive) {
      watchdog('media_theplatform_mpx', 'Failed to disable player @pid with player_id @player_id by settings its status to 0 in mpx_player.',
        array(
          '@pid' => $record['id'],
          '@player_id' => $record['player_id'],
        ),
        WATCHDOG_ERROR);
    }
    else {
      watchdog('media_theplatform_mpx', 'Successfully disabled player @pid with player_id @player_id by settings its status to 0 in mpx_player.',
        array(
          '@pid' => $record['id'],
          '@player_id' => $record['player_id'],
        ),
        WATCHDOG_NOTICE);
    }
    $inactives[] = $record['id'];
    // Unpublish the file entity if the file_admin module is enabled.
    if (module_exists('file_admin')) {
      $player_file = file_load($record['fid']);
      $player_file->published = 0;
      file_save($player_file);
    }
  }

  watchdog('media_theplatform_mpx', 'Processed players @method for all accounts:'
      . '  @insert_count player(s) created' . (count($inserts) ? ' (@inserts).' : '.')
      . '  @update_count player(s) updated' . (count($updates) ? ' (@updates).' : '.')
      . '  @inactive_count player(s) disabled' . (count($inactives) ? ' (@inactives).' : '.'),
    array(
      '@method' => $type,
      '@insert_count' => count($inserts),
      '@inserts' => implode(', ', $inserts),
      '@update_count' => count($updates),
      '@updates' => implode(', ', $updates),
      '@inactive_count' => count($inactives),
      '@inactives' => implode(', ', $inactives),
    ),
    WATCHDOG_INFO);

  // Return counters as an array.
  return array(
    'total' => $num_players,
    'inserts' => count($inserts),
    'updates' => count($updates),
    'inactives' => count($inactives),
  );
}

/**
 * Updates or inserts given mpxPlayer within Media Library.
 *
 * @param array $player
 *   Record of mpxPlayer data requested from thePlatform
 *
 * @return string
 *   Returns output of media_theplatform_mpx_update_player() or media_theplatform_mpx_insert_player()
 */
function media_theplatform_mpx_import_player($player, $account = NULL) {
  $uri = 'mpx://p/' . $player['id'] . '/a/' . basename($account->account_id);
  $fid = db_select('file_managed', 'f')
    ->fields('f', array('fid'))
    ->condition('uri', $uri, '=')
    ->execute()
    ->fetchField();

  // If fid exists:
  if ($fid) {
    // Check if record already exists in mpx_player.
    $existing_player = db_select('mpx_player', 'p')
      ->fields('p')
      ->condition('fid', $fid, '=')
      ->condition('parent_account', $account->id, '=')
      ->execute()
      ->fetchAll();
    $existing_player = (array) reset($existing_player);
    // If mpx_player record exists, then update record.
    if (!empty($existing_player)) {
      return media_theplatform_mpx_update_player($player, $fid, $existing_player, $account);
    }
    // Else insert new mpx_player record with existing $fid.
    else {
      return media_theplatform_mpx_insert_player($player, $fid, $account);
    }
  }
  // Create new mpx_player and create new file.
  else {
    return media_theplatform_mpx_insert_player($player, NULL, $account);
  }
}

/**
 * Inserts given mpxPlayer and File into Media Library.
 *
 * @param array $player
 *   Record of mpxPlayer data requested from thePlatform
 * @param int $fid
 *   File fid of mpxPlayer's File in file_managed if it already exists
 *   NULL if it doesn't exist
 *
 * @return String
 *   Returns 'insert' for counters in media_theplatform_mpx_import_all_players()
 */
function media_theplatform_mpx_insert_player($player, $fid = NULL, $account = NULL) {

  try {
    // If file doesn't exist, write it to file_managed.
    if (!$fid) {
      // Build embed string to create file:
      // "p" is for player.
      $embed_code = 'mpx://p/' . $player['id'] . '/a/' . basename($account->account_id);
      // Create the file.
      $provider = media_internet_get_provider($embed_code);
      $file = $provider->save($account, $player['title']);
      $fid = $file->fid;
      if ($fid) {
        watchdog('media_theplatform_mpx', 'Successfully created file @fid with uri -- @uri -- for player @pid and @account.',
          array(
            '@fid' => $fid,
            '@uri' => $embed_code,
            '@pid' => $player['pid'],
            '@account' => _media_theplatform_mpx_account_log_string($account),
          ),
          WATCHDOG_INFO);
      }
      else {
        watchdog('media_theplatform_mpx', 'Failed to create file with uri -- @uri -- for player @pid and @account.',
          array(
            '@uri' => $embed_code,
            '@pid' => $player['pid'],
            '@account' => _media_theplatform_mpx_account_log_string($account),
          ),
          WATCHDOG_ERROR);
      }
    }

    $head_html = media_theplatform_mpx_get_player_html($player['pid'], 'head', $account);
    $insert_fields = array(
      'title' => $player['title'],
      'id' => $player['id'],
      'pid' => $player['pid'],
      'guid' => $player['guid'],
      'description' => $player['description'],
      'fid' => $fid,
      'parent_account' => $player['parent_account'],
      'account' => $player['account'],
      'head_html' => $head_html,
      'body_html' => media_theplatform_mpx_get_player_html($player['pid'], 'body', $account),
      'player_data' => serialize(media_theplatform_mpx_extract_mpx_player_data($head_html)),
      'created' => REQUEST_TIME,
      'updated' => REQUEST_TIME,
      'status' => 1,
    );

    if (MEDIA_THEPLATFORM_MPX_LOGGING_LEVEL == WATCHDOG_DEBUG || MEDIA_THEPLATFORM_MPX_MESSAGE_LEVEL == WATCHDOG_DEBUG) {
      watchdog('media_theplatform_mpx', 'Inserting new player @pid - "@title" - associated with file @fid with the following data: @data',
        array(
          '@pid' => $player['pid'],
          '@title' => $player['title'],
          '@fid' => $fid,
          '@data' => print_r($insert_fields, TRUE),
        ),
        WATCHDOG_DEBUG);
    }

    // Insert record into mpx_player.
    $player_id = db_insert('mpx_player')
      ->fields($insert_fields)
      ->execute();

    if ($player_id) {
      // When the file_admin module is enabled, setting the "published" property
      // the save handler or in hook_file_presave() won't work.  The value is
      // overridden and set to zero.  Re-save the file entity to publish it.
      if (module_exists('file_admin')) {
        $file->published = 1;
        file_save($file);
      }
      watchdog('media_theplatform_mpx', 'Successfully created new player @pid - "@title" - associated with file @fid for @account.',
        array(
          '@pid' => $player['pid'],
          '@title' => $player['title'],
          '@fid' => $fid,
          '@account' => _media_theplatform_mpx_account_log_string($account),
        ),
        WATCHDOG_NOTICE);
    }
    else {
      watchdog('media_theplatform_mpx', 'Failed to insert new video @pid - "@title" - associated with file @fid for @account into the mpx_video table.',
        array(
          '@pid' => $player['pid'],
          '@title' => $player['title'],
          '@fid' => $fid,
          '@account' => _media_theplatform_mpx_account_log_string($account),
        ),
        WATCHDOG_ERROR);
    }
  }
  catch (Exception $e) {
    watchdog_exception('media_theplatform_mpx', $e,
      'ERROR occurred while creating player @pid -- @title -- for @account.',
      array(
        '@pid' => $player['pid'],
        '@title' => $player['title'],
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ));
  }
  // Return code to be used by media_theplatform_mpx_import_all_players().
  return 'insert';
}

/**
 * Updates given mpxPlayer and File in Media Library.
 *
 * @param array $player
 *   Record of mpxPlayer data requested from thePlatform
 * @param int $fid
 *   File fid of mpxPlayer's File in file_managed
 *
 * @return String
 *   Returns 'update' for counters in media_theplatform_mpx_import_all_players()
 */
function media_theplatform_mpx_update_player($player, $fid, $mpx_player = NULL, $account = NULL) {

  try {
    $head_html = media_theplatform_mpx_get_player_html($player['pid'], 'head', $account);
    $update_fields = array(
      'title' => $player['title'],
      'pid' => $player['pid'],
      'guid' => $player['guid'],
      'description' => $player['description'],
      'head_html' => $head_html,
      'body_html' => media_theplatform_mpx_get_player_html($player['pid'], 'body', $account),
      'player_data' => serialize(media_theplatform_mpx_extract_mpx_player_data($head_html)),
      'status' => 1,
    );

    // Update mpx_player record.
    $update = db_update('mpx_player')
      ->fields($update_fields)
      ->condition('id', $player['id'], '=')
      ->condition('fid', $fid, '=')
      ->execute();

    // Update the "updated" field if player data has changed.
    if ($update) {
      $update = db_update('mpx_player')
        ->fields(array('updated' => REQUEST_TIME))
        ->condition('id', $player['id'], '=')
        ->condition('fid', $fid, '=')
        ->execute();
    }

    if ($update) {
      watchdog('media_theplatform_mpx', 'Successfully updated player @pid -- @title -- associated with file @fid for @account.',
        array(
          '@pid' => $player['pid'],
          '@title' => $player['title'],
          '@fid' => $fid,
          '@account' => _media_theplatform_mpx_account_log_string($account),
        ),
        WATCHDOG_NOTICE);
    }
    else {
      watchdog('media_theplatform_mpx', 'Failed to update existing player  @pid -- "@title" -- associated with file @fid for @account in the mpx_player table.  Player data has likely not changed.',
        array(
          '@pid' => $player['pid'],
          '@title' => $player['title'],
          '@fid' => $fid,
          '@account' => _media_theplatform_mpx_account_log_string($account),
        ),
        WATCHDOG_NOTICE);
    }

    // Update file entity with (new) title of player and (un)publish status if
    // the player data has changed.
    if ($update) {
      $player_file = file_load($fid);
      $player_file->status = 1;
      $player_file->filename = $player['title'];
      if (module_exists('file_admin')) {
        $player_file->published = 1;
      }
      file_save($player_file);
    }
  }
  catch (Exception $e) {
    watchdog_exception('media_theplatform_mpx', $e,
      'ERROR occurred while updating player @pid -- @title -- for @account.',
      array(
        '@pid' => $player['pid'],
        '@title' => $player['title'],
        '@account' => _media_theplatform_mpx_account_log_string($account),
      ));
  }
  // Return code to be used by media_theplatform_mpx_import_all_players().
  return 'update';
}

/**
 * Returns associative array of mpx_player data for given field from the
 * mpx_player table.
 */
function media_theplatform_mpx_get_mpx_player_by_field($fid, $field_name, $field_value, $op = '=') {

  return db_query('mpx_player', 'p')
    ->fields('p')
    ->condition($field_name, $field_value, $op)
    ->execute()
    ->fetchAll();
}

/**
 * Returns associative array of mpx_player data for given File $fid.
 */
function media_theplatform_mpx_get_mpx_player_by_fid($fid) {

  return db_query('SELECT * FROM {mpx_player} WHERE fid = :fid',
    array(':fid' => $fid))->fetchAssoc();
}

/**
 * Returns associative array of mpx_player data for given player player_id.
 */
function media_theplatform_mpx_get_mpx_player_by_player_id($player_id) {

  return db_query('SELECT * FROM {mpx_player} WHERE player_id = :player_id',
    array(':player_id' => $player_id))->fetchAssoc();
}

/**
 * Returns total number of records in mpx_player table.
 */
function media_theplatform_mpx_get_mpx_player_count() {
  return db_query("SELECT count(player_id) FROM {mpx_player}")->fetchField();
}

/**
 * Returns array of all records in mpx_player table as Objects.
 */
function media_theplatform_mpx_get_all_mpx_players() {
  return db_select('mpx_player', 'p')
    ->fields('p')
    ->execute()
    ->fetchAll();
}

/**
 * Returns array of all distinct accounts in mpx_player table.
 */
function media_theplatform_mpx_get_mpx_player_accounts() {
  return db_select('mpx_player', 'p')
    ->fields('p', array('account'))
    ->distinct()
    ->execute()
    ->fetchAll();
}

/**
 * Returns CSS extracted from given Head HTML of a mpxPlayer.
 *
 * @param string $head
 *   HTML from the mpxPlayer's <HEAD>
 *
 * @return String
 *   Returns all CSS (does not include a surrounding <style> wrapper)
 */
function media_theplatform_mpx_get_mpx_player_css($head) {
  // Get inline CSS from all <style> tags (there can be multiple).
  $inline_css = implode(media_theplatform_mpx_extract_all_css_inline($head), "\n");
  $external_css = '';
  // Get css from all external stylesheets.
  $paths = media_theplatform_mpx_extract_all_css_links($head);
  foreach ($paths as $file) {
    $external_css .= media_theplatform_mpx_get_external_css($file) . "\n";
  }
  return $inline_css . "\n" . $external_css;
}

/**
 * Returns array of CSS and JS data extracted from given Head HTML of a mpxPlayer.
 *
 * @param string $pid
 *   The mpxPlayer's Public ID
 *
 * @return Array
 *   Contains CSS classes/code, inline JS, and external JS file URLs
 */
function media_theplatform_mpx_extract_mpx_player_data($head) {

  $player_data = array();

  // mpxPlayer meta tags.
  $player_data['meta'] = media_theplatform_mpx_extract_all_meta_tags($head);

  // mpxPlayer CSS.
  $player_data['css'] = media_theplatform_mpx_get_mpx_player_css($head);

  // External JS files.
  $js_files = media_theplatform_mpx_extract_all_js_links($head);
  if ($js_files) {
    foreach ($js_files as $src) {
      $player_data['js']['external'][] = $src;
    }
  }

  // Add any inline JS.
  $inline = media_theplatform_mpx_extract_all_js_inline($head);
  if ($inline) {
    foreach ($inline as $script) {
      $player_data['js']['inline'][] = $script;
    }
  }

  return $player_data;
}

/**
 * Returns array of CSS and JS data stored in mpxPlayer's player_data field.
 */
function media_theplatform_mpx_get_player_data($player) {
  return unserialize($player['player_data']);
}
