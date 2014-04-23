/**
 * @file
 * Handles the JS for the views file browser. Note that this does not currently
 * support multiple file selection
 */


(function ($) {

  Drupal.behaviors.mediaMpxBrowser = {
    attach: function (context, settings) {
    
      $('div.mpxplayer_select').hide();

      // Container for the files that get passed back to the browser
      var files = {};

      // Disable the links on media items list
      $('ul#media-browser-library-list a').click(function() {
        return false;
      });
        
      // Update hidden value for mpx type.
      $('.players-browser .media-item').bind('click', function () {
        $("input[name='mpx_type']").val('players');
        // Hide player selects on video-browser if we clicked on a mpxPlayer item.
        $('div.mpxplayer_select').hide();
      });  
      
      $('.videos-browser .media-item').bind('click', function () {
        $("input[name='mpx_type']").val('videos');
        // Hide all player selects.
        $('div.mpxplayer_select').hide();
        // Display player select for this Video's account.
        account = $(this).closest('span').attr('class');
        $('div.'+account).show();
      });
      
      // Catch the click on a media item
      $('.media-item').bind('click', function () {
        // Remove all currently selected files
        $('.media-item').removeClass('selected');
        // Set the current item to active
        $(this).addClass('selected');
        // Add this FID to the array of selected files
        var uri = $(this).parent().parent('a[data-uri]').attr('data-uri');
        // Get the file from the settings which was stored in
        // template_preprocess_media_views_view_media_browser()
        var file = Drupal.settings.media.files[uri];
        var files = new Array();
        files.push(file);
        Drupal.media.browser.selectMedia(files);
        $("input[name='selected_file']").val(uri);
      });
            
      // Check that file was selected.
      $('.mpx-submit').bind('click', function () {
        if ($("input[name='selected_file']").val() == '') {
          alert(Drupal.t('Please select an item.'));
          return false;
        }
      });
      
      // Filter list if mpxmedia_search is used.
      $('#edit-mpxmedia-search').keyup(function() {
        var value = $(this).val();
        //var exp = new RegExp('^' + value, 'i');
        var exp = new RegExp(value, 'i');
        $('.videos-browser li').each(function() {
          var isMatch = exp.test($('.item-data', this).text());
          $(this).toggle(isMatch);
        });
      });       

    }
  }

}(jQuery));
