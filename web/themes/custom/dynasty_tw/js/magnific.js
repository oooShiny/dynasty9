(function ($, Drupal) {
  // jQuery 4.x compatibility polyfill for Magnific Popup
  if (!$.isArray) {
    $.isArray = Array.isArray;
  }

  Drupal.behaviors.magnificModal = {
    attach: function (context) {
      $('a.brady-td-link', context).each(function (i, v) {
        var href = this.href;
        $(v).on('click', function (e) {
          e.preventDefault();
          // Extract Muse ID from the href
          var link_parts = href.split('/');
          var muse_id = link_parts[link_parts.length - 1];

          $.magnificPopup.open({
            items: {
              src: 'https://muse.ai/embed/' + muse_id + '?search=0&links=0&logo=0&title=0&autoplay=1&volume=0&cover_play_position=center" style="width:100%;height:100%;position:absolute;left:0;top:0'
            },
            type: 'iframe',
            mainClass: 'mfp-fade',
            removalDelay: 160,
            preloader: false,
            fixedContentPos: false,
            iframe: {
              markup: '<div class="mfp-iframe-scaler">' +
                        '<div class="mfp-close"></div>' +
                        '<iframe class="mfp-iframe" frameborder="0" allowfullscreen></iframe>' +
                      '</div>',
              patterns: {
                muse: {
                  index: 'muse.ai',
                  id: function(url) {
                    var m = url.match(/^.+muse\.ai\/(embed\/)?([A-Za-z0-9]+)/);
                    if (!m || !m[2]) return null;
                    return m[2];
                  },
                  src: 'https://muse.ai/embed/%id%?search=0&links=0&logo=0&title=0&autoplay=1&volume=0&cover_play_position=center" style="width:100%;height:100%;position:absolute;left:0;top:0'
                }
              }
            }
          });
        });
      });
    }
  }
})(jQuery, Drupal);
