<?php

/**
 * @file media_theplatform_mpx/includes/MediaThePlatformMpxPlayerBrowser.inc
 *
 * Definition of MediaThePlatformMpxPlayerBrowser.
 */

/**
 * Media browser plugin for displaying a specific view and display.
 */
class MediaThePlatformMpxPlayerBrowser extends MediaBrowserPlugin {
  /**
   * Implements MediaBrowserPluginInterface::access().
   */
  public function access($account = NULL) {
    return file_entity_access('update', NULL, $account);
  }

  /**
   * Implements MediaBrowserPlugin::view().
   */
  public function view() {
    if (user_access('administer media') || user_access('administer files')) {
      $build['form'] = drupal_get_form('media_theplatform_mpx_form_media_browser', 'players');
      return $build;
    }
  }
}
