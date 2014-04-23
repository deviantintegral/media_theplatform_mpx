<?php

/**
 * @file
 * Functions for Media Browser forms.
 */

/**
 * Form constructor for mpx Media Browser.
 *
 * @param string $mpx_type
 *   Valid values are 'videos' or 'players'.
 *
 * @see media_theplatform_mpx_media_browser_plugin_view()
 * @ingroup forms
 */
function media_theplatform_mpx_form_media_browser($form, &$form_state = array(), $mpx_type = NULL) {
  // Need this for media_browser_build_media_item.
  module_load_include('inc', 'media', 'includes/media.browser');

  $form['list']['#prefix'] = '<div id="container"><div id="scrollbox"><ul id="media-browser-library-list" class="media-list-thumbnails ' . $mpx_type . '-browser">';
  $form['list']['#suffix'] = '</ul><div id="status"></div></div></div>';

  $files = array();
  if ($mpx_type == 'videos') {
    $list = media_theplatform_mpx_get_all_mpx_videos();
    $label = 'mpxMedia';
    if (count($list) > 0) {
      $form['mpxmedia_search'] = array(
        '#title' => t('Search:'),
        '#type' => 'textfield',
        '#weight' => -20,
      );
      // Fetch all distinct accounts from mpx_player.
      $accounts = media_theplatform_mpx_get_mpx_player_accounts();
      foreach ($accounts as $account) {
        $account_label = str_replace('%', '-', $account->account);
        $form['player_fid_' . $account_label] = array(
          '#type' => 'select',
          '#title' => t('Render with mpxPlayer:'),
          '#options' => media_theplatform_mpx_get_players_select($account->account),
          '#default_value' => media_theplatform_mpx_variable_get('default_player_fid'),
          '#prefix' => '<div class="mpxplayer_select ' . $account_label . '">',
          '#suffix' => '</div>',
        );
      }
    }
  }
  elseif ($mpx_type == 'players') {
    $list = media_theplatform_mpx_get_all_mpx_players();
    $label = 'mpxPlayers';
  }
  else {
    return FALSE;
  }

  if (count($list) == 0) {
    $form['no-items'] = array(
      '#markup' => t('No :type have been imported.', array(':type' => $label)),
    );
    return $form;
  }

  foreach ($list as $item) {
    $file = file_load($item->fid);
    $account_label = str_replace('%', '-', $item->account);
    media_browser_build_media_item($file);
    $file->preview = l('<span class="' . $account_label . '">' . $file->preview . '</span>', 'media/browser', array(
      'html' => TRUE,
      'attributes' => array(
        'data-uri' => $file->uri,
      ),
      'query' => array('render' => 'media-popup', 'uri' => $file->uri),
    ));
    $form['list'][$file->uri] = array(
      '#markup' => $file->preview,
      '#prefix' => '<li class="mpx-browser">' . '<div class="item-data">' . $item->title . ' ' . $item->description .  '</div>',
      '#suffix' => '</li>',
    );
    $files[$file->uri] = $file;
  }
  $form['selected_file'] = array(
    '#type' => 'hidden',
    '#default_value' => FALSE,
  );
  $form['mpx_type'] = array(
    '#type' => 'hidden',
    '#default_value' => $mpx_type,
  );
  // Add the files to JS so that they are accessible inside the browser.
  drupal_add_js(array('media' => array('files' => $files)), 'setting');
  // Add media_theplatform_mpx browser javascript and CSS.
  drupal_add_js(drupal_get_path('module', 'media_theplatform_mpx') . '/js/media_theplatform_mpx.browser.js');
  // Standard Media module files.
  drupal_add_js(drupal_get_path('module', 'media') . '/js/plugins/media.library.js');
  drupal_add_css(drupal_get_path('module', 'media') . '/js/plugins/media.library.css');

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Select'),
    '#attributes' => array('class' => array('mpx-submit')),
  );
  return $form;
}

/**
 * Form submission handler for media_theplatform_mpx_form_media_browser().
 */
function media_theplatform_mpx_form_media_browser_submit($form, &$form_state) {
  $mpx_type = $form_state['values']['mpx_type'];
  $embed_code = $form_state['values']['selected_file'];

  // If its a mpxPlayer, simply return the fid from file_managed
  if ($mpx_type == 'players') {
    $fid = db_query("SELECT fid FROM {file_managed} WHERE uri=:uri", array(':uri' => $embed_code))->fetchField();
  }
  // Elseif its mpxMedia:
  elseif ($mpx_type == 'videos') {

    // The embed_code given is the file saved on the mpx_video record, so we need to split it out to see check if mpxMedia/mpxPlayer combo exists.
    $url = parse_url($embed_code);
    $parts = explode('/', $url['path']);
    $video = media_theplatform_mpx_get_mpx_video_by_field('guid', $parts[1]);
    $account_label = str_replace('%', '-', $video['account']);

    // Check if this mpxMedia / mpxPlayer combo exists.
    $key = 'player_fid_' . $account_label;
    $player_fid = $form_state['input'][$key];
    $uri = _media_theplatform_mpx_get_video_file_uri($video);
    $fid = db_query("SELECT fid FROM {file_managed} WHERE uri=:uri", array(':uri' => $uri))->fetchField();

    // If it doesn't exist, create the file.
    if (!$fid) {
      watchdog('media_theplatform_mpx', 'Creating file with uri -- @uri -- for video @id and @account.',
        array(
          '@uri' => $uri,
          '@id' => $video['id'],
          '@account' => _media_theplatform_mpx_account_log_string($video['account']),
        ),
        WATCHDOG_DEBUG);
      $provider = media_internet_get_provider($uri);
      $file = $provider->save();
      $fid = $file->fid;
      if ($fid) {
        watchdog('media_theplatform_mpx', 'Successfully created file @fid with uri -- @uri -- for video @id.',
          array(
            '@fid' => $fid,
            '@uri' => $uri,
            '@id' => $video['id'],
          ),
          WATCHDOG_NOTICE);
      }
      else {
        watchdog('media_theplatform_mpx', 'Failed to create file with uri -- @uri -- for video @id.',
          array(
            '@uri' => $uri,
            '@id' => $video['id'],
          ),
          WATCHDOG_ERROR);
      }
      // Update the new file's name.
      $player = media_theplatform_mpx_get_mpx_player_by_fid($player_fid);
      media_theplatform_mpx_update_video_filename($fid, $video['title'], $player['title']);
    }
  }

  // This stops page reload inside the media browser iframe.
  $form_state['redirect'] = array('media/browser', array(
    'query' => array(
      'render' => 'media-popup',
      'fid' => $fid,
    )));
}
