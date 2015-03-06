<?php

/**
 * @file
 * API integration for media_theplatform_mpx module.
 */

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
