<?php
/**
 * @file
 * Displays a mpx File Entity.
 *
 * Available variables:
 * - $mpx_id: String ID of mpxPlayer / mpxMedia
 * - $player_html: String of HTML
 *
 * @see media_theplatform_mpx_preprocess_media_theplatform_mpx_video()
 *
 * @ingroup themeable
 */
?>

<div class="media-mpx-wrapper" id="media-mpx-wrapper-<?php print $mpx_id; ?>">
  <div id="mpx-<?php print $mpx_id; ?>">
    <?php print $player_html; ?> 
  </div>
</div>
