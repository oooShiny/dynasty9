(function ($, Drupal) {
  Drupal.behaviors.expandVideo = {
    attach: function (context) {
      $('a[href*="muse.ai"]', context).each(function (i, v) {
        var href = this.href;
        if (href.indexOf('muse') >= 0) {
          $(v).on('click', function (e) {
            e.preventDefault();
            var link_parts = href.split('/');
            var muse_id = link_parts[link_parts.length - 1];
            modalSrc = '<iframe src="https://muse.ai/embed/'+ muse_id +'" frameborder="0" allowfullscreen></iframe>';
            // Create modal.
            var imageModal = Drupal.dialog(modalSrc, {
              resizable: false,
              closeOnEscape: true,
              position: { my: "center center", at: "center center", of: window },
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
