(function ($, Drupal) {
  Drupal.behaviors.expandVideo = {
    attach: function (context) {
      $('a[href*="muse.ai"]', context).each(function (i, v) {
        var href = this.href;
        if (href.indexOf('muse') >= 0) {
          $(v).on('click', function (e) {
            e.preventDefault();
            modalSrc = "<video controls muted autoplay preload='metadata' class='responsive-video'>" +
              "<source src='" + href + "' type='video/mp4; codecs=' avc1.42e01e, mp4a.40.2''>" +
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
          });
        }
      });
    }
  };
})(jQuery, Drupal);
