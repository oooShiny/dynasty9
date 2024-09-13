jQuery(function($){
  $(document).ready(function () {
    var url = 'https://api.gfycat.com/v1/gfycats/'
    $('.gfyitem').each(function (index) {
      var gifID = $(this).data('id')
      var blockID = $(this).attr('id')
      var itemImg = $(this)
      $.get(url + gifID, function (data) {
        var videoString = '<div id="' + blockID + '"><video controls muted preload="metadata" class="responsive-video"><source src="' + data.gfyItem.mp4Url + '" type="video/mp4; codecs=\"avc1.42E01E, mp4a.40.2\""><source src="' + data.gfyItem.webmUrl + '" type="video/webm; codecs=\"vp8, vorbis\""></video></div>'
        itemImg.replaceWith(
          videoString
        )
      }).fail(function () {
        var url = 'https://api.redgifs.com/v1/gfycats/'
        $.get(url + gifID, function (data) {
          var videoString = '<div id="' + blockID + '"><video controls muted preload="metadata" class="responsive-video"><source src="' + data.gfyItem.mp4Url + '" type="video/mp4; codecs=\"avc1.42E01E, mp4a.40.2\""><source src="' + data.gfyItem.webmUrl + '" type="video/webm; codecs=\"vp8, vorbis\""></video></div>'
          itemImg.replaceWith(
            videoString
          )
        })
      });
    })
  })
});
