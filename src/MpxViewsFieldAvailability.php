<?php

/**
 * @file
 * Contains \MpxViewsFieldAvailability.
 */

/**
 * Views field handler for if a video is unavailable, available, or expired.
 */
class MpxViewsFieldAvailability extends views_handler_field {

  /**
   * {@inheritdoc}
   */
  public function render($values) {
    if ($values->{$this->field_alias} && $values->{$this->field_alias} > REQUEST_TIME) {
      return t('Unavailable');
    }
    elseif ($values->{$this->aliases['expiration_date']} && $values->{$this->aliases['expiration_date']} <= REQUEST_TIME) {
      return t('Expired');
    }
    else {
      return t('Available');
    }
  }

}
