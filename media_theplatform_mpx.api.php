<?php

/**
 * @file
 * API integration for the media_theplatform_mpx module.
 */

/**
 * Alter video metadata prior to import.
 *
 * @param array $video_item
 *   The processed video data. When this hook is complete this variable will be
 *   passed into media_theplatform_mpx_import_video().
 * @param array $video
 *   The array of original data from the media API.
 * @param MpxAccount $account
 *   The account that corresponds to the video.
 */
function hook_media_theplatform_mpx_media_import_item_alter(array &$video_item, array $video, MpxAccount $account) {
  // Do not import any videos that mention dogs in the title.
  if (preg_match('/\bdogs?\b/i', $video_item['title'])) {
    $video_item['ignore'] = TRUE;
  }
}

/**
 * Alter a video player iframe element.
 *
 * @param array $element
 *   The render API array containing the iframe element to be rendered.
 * @param array $variables
 *   The variables array from theme_media_theplatform_mpx_player_iframe().
 *
 * @see theme_media_theplatform_mpx_player_iframe()
 */
function hook_media_theplatform_mpx_player_iframe_alter(array &$element, array $variables) {
  // Enable auto-play for all videos.
  $element['#attributes']['src']['options']['query']['autoPlay'] = 'true';
}
