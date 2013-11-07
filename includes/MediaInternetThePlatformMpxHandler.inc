<?php
/**
 * @file
 * Contains MediaInternetThePlatformMpxHandler.
 */

class MediaInternetThePlatformMpxHandler extends MediaInternetBaseHandler {

  /**
   * Return TRUE if $embed_code is for a mpx file.
   */
  public function parse($embed_code) {
    // Valid format: mpx://[anything here].
    preg_match('/mpx:\/\//i', $embed_code, $matches);
    if(isset($matches[0])) {
      return file_stream_wrapper_uri_normalize($embed_code);
    }
    else {
      return FALSE;
    }
  }

  /**
   * Mpx handler is import-only and thus no ability to 'claim' entered URLs.
   */
  public function claim($embed_code) {
    if ($this->parse($embed_code)) {
      return TRUE;
    }
  }

  /**
   * There is no need to validate - all videos are ingested by thePlatform API.
   */
  public function validate() {

  }

  /**
   * Save file object.
   */
  public function save() {
    $file = $this->getFileObject();
    file_save($file);
    return $file;
  }

  /**
   * Get file type by mimetype.
   */
  public function getFileType($mime_type) {
    $file_type = 'video';
    drupal_alter('media_theplatform_mpx_file_type', $file_type, $mime_type);
    return $file_type;
  }

  /**
   * Return mpx File Object with type video.
   */
  public function getFileObject() {
    $uri = $this->parse($this->embedCode);
    $file = file_uri_to_object($uri);
    $file->type = $this->getFileType($file->filemime);
    return $file;
  }

  /**
   * Not valid for ThePlatform.
   */
  public function getMRSS() {
  }

  /**
   * Not valid for ThePlatform.
   */
  public function getOEmbed() {
  }
}
