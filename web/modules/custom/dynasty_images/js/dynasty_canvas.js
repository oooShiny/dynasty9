(function (Drupal, drupalSettings) {
  Drupal.behaviors.dynastyCanvas = {
    attach: function (context) {
      let dynasty_images = drupalSettings.dynasty_images;
      console.log(dynasty_images);
      drawImage('top-left', dynasty_images.hidden_1, dynasty_images.hidden_2, dynasty_images.main_img, dynasty_images.bg_color);
      drawImage('top-right', dynasty_images.hidden_3, dynasty_images.hidden_4, dynasty_images.main_img, dynasty_images.bg_color);
      drawImage('bottom-left', dynasty_images.hidden_5, dynasty_images.hidden_6, dynasty_images.main_img, dynasty_images.bg_color);
      drawImage('bottom-right', dynasty_images.hidden_7, dynasty_images.hidden_8, dynasty_images.main_img, dynasty_images.bg_color);
    }
  };
})(Drupal, drupalSettings);

function drawImage(position, top_img, bottom_img, middle_img, bg_color) {
  const canvas = document.getElementById('hidden-' + position);
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = bg_color;
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  // Top image.
  const topImg = new Image();
  topImg.addEventListener('load', function() {
    let sizedImgTop = sizeImage(topImg, 1213, 683);
    ctx.drawImage(topImg,
      sizedImgTop.sx, sizedImgTop.sy,
      sizedImgTop.sw, sizedImgTop.sh,
      0, 0, // Top left of image on canvas.
      1213, 683 // Width & height of image on canvas.
    );

    const midImg = new Image();
    midImg.addEventListener('load', function() {
      let p = quarterImage(position);
      ctx.drawImage(midImg,
        p.sx, p.sy,
        1213, 683,
        0, 683, // Top left of image on canvas.
        1213, 683 // Width & height of image on canvas.
      );
    }, false);
    midImg.crossOrigin = 'anonymous';
    midImg.src = middle_img;


    const btm = new Image();
    btm.addEventListener('load', function() {
      let sizedImgBtm = sizeImage(btm, 1213, 683);
      ctx.drawImage(btm,
        sizedImgBtm.sx, sizedImgBtm.sy,
        sizedImgBtm.sw, sizedImgBtm.sh,
        0, 1366, // Top left of image on canvas.
        1213, 683 // Width & height of image on canvas.
      );
    }, false);
    btm.crossOrigin = 'anonymous';
    btm.src = bottom_img;
  }, false);
  topImg.src = top_img;
}

function quarterImage(position) {
  switch (position) {
    case 'top-left':
      return {sx: 0, sy: 0};
    case 'top-right':
      return {sx: 1213, sy: 0};
    case 'bottom-left':
      return {sx: 0, sy: 683};
    case 'bottom-right':
      return {sx: 1213, sy: 683};
  }

}

function sizeImage(img, size_w, size_h) {
  let img_ratio = img.naturalHeight/img.naturalWidth;
  let box_ratio = size_h/size_w;
  let sh = 0;
  let sw = 0;
  let sx = 0;
  let sy = 0;
  // Wide image.
  if (img_ratio < 1) {
    sh = img.naturalHeight;
    sw = img.naturalWidth;
    sx = (img.naturalWidth - sw)/2;
  }
  // Tall image.
  else if (img_ratio > 1) {
    sh = img.naturalHeight * box_ratio;
    sw = img.naturalWidth;
    sy = (img.naturalHeight - sh)/2;
  }
  else {
    sh = img.naturalHeight * box_ratio;
    sw = img.naturalWidth;
    sy = (img.naturalHeight - sh)/2;
    sx = (img.naturalWidth - sw)/2;
  }

  return {
    sh: sh,
    sw: sw,
    sx: sx,
    sy: sy
  };
}
