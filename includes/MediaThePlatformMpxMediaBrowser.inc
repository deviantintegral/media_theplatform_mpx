<?php

/**
 * @file media_theplatform_mpx/includes/MediaThePlatformMpxMediaBrowser.inc
 *
 * Definition of MediaThePlatformMpxMediaBrowser.
 */

/**
 * Media browser plugin for displaying a specific view and display.
 */
class MediaThePlatformMpxMediaBrowser extends MediaBrowserPlugin {
  /**
   * Implements MediaBrowserPluginInterface::access().
   */
  public function access($account = NULL) {
    return user_access('administer files', $account);
  }

  /**
   * Implements MediaBrowserPlugin::view().
   */
  public function view() {
    $build['form'] = drupal_get_form('media_theplatform_mpx_form_media_browser', 'videos');
    return $build;
  }
}
