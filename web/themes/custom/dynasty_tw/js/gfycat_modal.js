(function ($, Drupal) {
  Drupal.behaviors.expandGif = {
    attach: function (context) {
      $('a[href*="gfycat.com"]', context).each(function (i, v) {
        var href = this.href;
        if (href.indexOf('gfycat') >= 0) {
          $(v).addClass('gyf');
          $(v).on('click', function (e) {
            e.preventDefault();
            var gfycat = href.split('/');
            var gifID = gfycat[3];
            var modalSrc = '';
            var url = 'https://api.gfycat.com/v1/gfycats/';
            $.get( url+gifID, function( data ) {
              modalSrc = "<video controls muted autoplay preload='metadata' class='responsive-video'>" +
                "<source src='" + data.gfyItem.mp4Url + "' type='video/mp4; codecs=' avc1.42e01e, mp4a.40.2''>" +
                "<source src='" + data.gfyItem.webmUrl + "' type='video/webm; codecs=' vp8, vorbis''>" +
                "</video>";
              // Create modal.
              var imageModal = Drupal.dialog(modalSrc, {
                resizable: false,
                closeOnEscape: true,
                position: { my: "left top", at: "left top+64", of: window },
                height: 'auto',
                width: 'auto',
                beforeClose: false,
                close: function (event) {
                  $(event.target).remove();
                }
              });
              // Attach modal functionality to link on click.
              imageModal.showModal();
              $(document).find('.ui-widget-overlay').click(function () {
                imageModal.close();
              });
            }).fail(function() {
              var url = 'https://api.redgifs.com/v1/gfycats/';
              $.get( url+gifID, function( data ) {
                modalSrc = "<video controls muted autoplay preload='metadata' class='responsive-video'>" +
                  "<source src='" + data.gfyItem.mp4Url + "' type='video/mp4; codecs=' avc1.42e01e, mp4a.40.2''>" +
                  "<source src='" + data.gfyItem.webmUrl + "' type='video/webm; codecs=' vp8, vorbis''>" +
                  "</video>";
                // Create modal.
                var imageModal = Drupal.dialog(modalSrc, {
                  resizable: false,
                  closeOnEscape: true,
                  position: { my: "left top", at: "left top+64", of: window },
                  height: 'auto',
                  width: 'auto',
                  beforeClose: false,
                  close: function (event) {
                    $(event.target).remove();
                  }
                });
                // Attach modal functionality to link on click.
                imageModal.showModal();
                $(document).find('.ui-widget-overlay').click(function () {
                  imageModal.close();
                });
              })
            });
          });
        }
      });
    }
  };
})(jQuery, Drupal);
