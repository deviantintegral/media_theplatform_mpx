<?php

/**
 * @file
 * Media integration for the media_theplatform_mpx module.
 */

/**
 * Implements hook_media_browser_plugin_info().
 */
function media_theplatform_mpx_media_browser_plugin_info() {
  $info['theplatform_mpx_mpxplayer'] = array(
    'title' => t('mpxPlayers'),
    'class' => 'MediaThePlatformMpxPlayerBrowser',
  );
  $info['theplatform_mpx_mpxmedia'] = array(
    'title' => t('mpxMedia'),
    'class' => 'MediaThePlatformMpxMediaBrowser',
  );

  return $info;
}
