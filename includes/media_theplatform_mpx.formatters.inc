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
    'mime types' => array('video/mpx'),
    'hidden' => TRUE,
  );
  $formatters['media_theplatform_mpx_image'] = array(
    'label' => t('mpx Preview Image'),
    'file types' => array('video'),
    'default settings' => array(
      'image_style' => '',
    ),
    'view callback' => 'media_theplatform_mpx_file_formatter_image_view',
    'settings callback' => 'media_theplatform_mpx_file_formatter_image_settings',
    'mime types' => array('video/mpx', 'player/mpx'),
  );
  return $formatters;
}

/**
 * Implements hook_file_formatter_FORMATTER_view().
 */
function media_theplatform_mpx_file_formatter_video_view($file, $display, $langcode) {
  // WYSIWYG does not yet support video inside a running editor instance.
  if ($file->filemime === 'video/mpx' && empty($file->override['wysiwyg'])) {
    $element = array(
      '#theme' => 'media_theplatform_mpx_video',
      '#uri' => $file->uri,
    );
    return $element;
  }
}

/**
 * Implements hook_file_formatter_FORMATTER_view().
 */
function media_theplatform_mpx_file_formatter_image_view($file, $display, $langcode) {
  if ($file->filemime === 'video/mpx' || $file->filemime === 'player/mpx') {
    /** @var MediaThePlatformMpxStreamWrapper $wrapper */
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
