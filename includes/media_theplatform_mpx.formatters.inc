<?php
/**
 * @file
 * media_theplatform_mpx/includes/media_theplatform_mpx.formatters.inc
 *
 * Define Media:mpx formatters.
 */

/**
 * Implements hook_file_formatter_info().
 */
function media_theplatform_mpx_file_formatter_info() {
  $formatters['media_theplatform_mpx_video'] = array(
    'label' => t('mpx Video'),
    'file types' => array('video'),
    'view callback' => 'media_theplatform_mpx_file_formatter_video_view',
    'settings callback' => 'media_theplatform_mpx_file_formatter_video_settings',
  );
  $formatters['media_theplatform_mpx_image'] = array(
    'label' => t('mpx Preview Image'),
    'file types' => array('video'),
    'default settings' => array(
      'image_style' => '',
    ),
    'view callback' => 'media_theplatform_mpx_file_formatter_image_view',
    'settings callback' => 'media_theplatform_mpx_file_formatter_image_settings',
  );
  return $formatters;
}

/**
 * Implements hook_file_formatter_FORMATTER_view().
 */
function media_theplatform_mpx_file_formatter_video_view($file, $display, $langcode) {
  $scheme = file_uri_scheme($file->uri);
  // WYSIWYG does not yet support video inside a running editor instance.
  if ($scheme == 'mpx' && empty($file->override['wysiwyg'])) {
    $element = array(
      '#theme' => 'media_theplatform_mpx_video',
      '#uri' => $file->uri,
    );
    return $element;
  }
}

/**
 * Implements hook_file_formatter_FORMATTER_settings().
 */
function media_theplatform_mpx_file_formatter_video_settings($form, &$form_state, $settings) {
  $element = array();
  // We don't customize anything in Drupal, its all via thePlatform.
  $element['info'] = array(
    '#markup' => t('All mpxPlayer display settings are configured through your account on thePlatform and imported upon Sync.'),
  );
  return $element;
}

/**
 * Implements hook_file_formatter_FORMATTER_view().
 */
function media_theplatform_mpx_file_formatter_image_view($file, $display, $langcode) {
  $scheme = file_uri_scheme($file->uri);
  if ($scheme == 'mpx') {
    $wrapper = file_stream_wrapper_get_instance_by_uri($file->uri);
    $parts = $wrapper->get_parameters();

    $image_style = $display['settings']['image_style'];
    $valid_image_styles = image_style_options(FALSE);

    if (empty($image_style) || !isset($valid_image_styles[$image_style])) {
      $element = array(
        '#theme' => 'image',
        '#path' => $wrapper->getOriginalThumbnailPath(),
      );
    }
    else {
      $element = array(
        '#theme' => 'image_style',
        '#style_name' => $image_style,
        '#path' => $wrapper->getLocalThumbnailPath(),
      );
    }
    return $element;
  }
}

/**
 * Implements hook_file_formatter_FORMATTER_settings().
 */
function media_theplatform_mpx_file_formatter_image_settings($form, &$form_state, $settings) {
  $element = array();
  $element['image_style'] = array(
    '#title' => t('Image style'),
    '#type' => 'select',
    '#options' => image_style_options(FALSE),
    '#default_value' => $settings['image_style'],
    '#empty_option' => t('None (original image)'),
  );
  return $element;
}

/**
 * Implements hook_file_default_displays().
 */
function media_theplatform_mpx_file_default_displays() {
  $default_displays = array();

  // Default settings for displaying as a video.
  $default_video_settings = array(
    // Must include media_large for this to work.
    'media_large' => array(),
    'media_original' => array(),
  );

  foreach ($default_video_settings as $view_mode => $settings) {
    $display_name = 'video__' . $view_mode . '__media_theplatform_mpx_video';
    $default_displays[$display_name] = (object) array(
      'api_version' => 1,
      'name' => $display_name,
      'status' => 1,
      'weight' => 1,
      'settings' => $settings,
    );
  }

  // Default settings for displaying a video preview image. We enable preview
  // images even for view modes that also play video, for use inside a running
  // WYSIWYG editor. The higher weight ensures that the video display is used
  // where possible.
  $default_image_styles = array(
    'media_preview' => 'square_thumbnail',
    'media_large' => 'large',
    'media_original' => '',
  );
  foreach ($default_image_styles as $view_mode => $image_style) {
    $display_name = 'video__' . $view_mode . '__media_theplatform_mpx_image';
    $default_displays[$display_name] = (object) array(
      'api_version' => 1,
      'name' => $display_name,
      'status' => 1,
      'weight' => 2,
      'settings' => array('image_style' => $image_style),
    );
  }

  return $default_displays;
}
