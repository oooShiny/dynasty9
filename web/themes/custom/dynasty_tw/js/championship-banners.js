(function () {
  'use strict';

  var IMG_PATH = '/themes/custom/dynasty_tw/images/banners/';

  var SUPER_BOWLS = [
    { year: '2001', sbLogo: IMG_PATH + 'Super_Bowl_XXXVI_Logo.svg'  },
    { year: '2003', sbLogo: IMG_PATH + 'Super_Bowl_XXXVIII.svg'     },
    { year: '2004', sbLogo: IMG_PATH + 'Super_Bowl_XXXIX.svg'       },
    { year: '2014', sbLogo: IMG_PATH + 'SuperBowlXLIXLogo.png'      },
    { year: '2016', sbLogo: IMG_PATH + 'Super_Bowl_LI_logo.svg'     },
    { year: '2018', sbLogo: IMG_PATH + 'super_bowl_LIII.svg'        },
  ];

  // Flat banner dimensions (matching the repo HTML proportions)
  var BW = 140;
  var BH = 290;

  // Cloth grid
  var COLS = 10;
  var ROWS = 16;

  // Canvas left/right padding so cloth can swing without clipping
  var PAD = 14;

  // Physics
  var GRAVITY    = 0.30;
  var FRICTION   = 0.989;
  var ITERATIONS = 8;
  var M_RADIUS   = 120;
  var M_FORCE    = 6.0;

  // ── Particle (Verlet integration) ─────────────────────────
  function Particle(x, y) {
    this.x = x; this.y = y;
    this.px = x; this.py = y;
    this.pinned = false;
  }
  Particle.prototype.step = function () {
    if (this.pinned) return;
    var vx = (this.x - this.px) * FRICTION;
    var vy = (this.y - this.py) * FRICTION;
    this.px = this.x; this.py = this.y;
    this.x += vx;
    this.y += vy + GRAVITY;
  };
  Particle.prototype.nudge = function (fx, fy) {
    if (this.pinned) return;
    this.px -= fx; this.py -= fy;
  };

  // ── Distance constraint ───────────────────────────────────
  function Constraint(a, b) {
    this.a = a; this.b = b;
    var dx = a.x - b.x, dy = a.y - b.y;
    this.rest = Math.sqrt(dx * dx + dy * dy);
  }
  Constraint.prototype.satisfy = function () {
    var dx = this.a.x - this.b.x;
    var dy = this.a.y - this.b.y;
    var d  = Math.sqrt(dx * dx + dy * dy) || 1e-6;
    var k  = (d - this.rest) / d * 0.5;
    if (!this.a.pinned) { this.a.x -= dx * k; this.a.y -= dy * k; }
    if (!this.b.pinned) { this.b.x += dx * k; this.b.y += dy * k; }
  };

  // ── Banner texture — mirrors the repo's HTML layout ──────
  function makeBannerTexture(sb, patsLogo, sbLogo) {
    var oc = document.createElement('canvas');
    oc.width  = BW;
    oc.height = BH;
    var c = oc.getContext('2d');

    // ── Background: #062e5e from the repo ──────────────────
    c.fillStyle = '#062e5e';
    c.fillRect(0, 0, BW, BH);

    // Very subtle vertical weave hint
    c.strokeStyle = 'rgba(0,0,0,0.08)';
    c.lineWidth   = 1;
    for (var wx = 0; wx < BW; wx += 3) {
      c.beginPath(); c.moveTo(wx, 0); c.lineTo(wx, BH); c.stroke();
    }

    // ── White border (banner-content in the repo) ──────────
    // 0.25em border ≈ 4px at the canvas scale
    var bPad = 5;
    c.strokeStyle = 'white';
    c.lineWidth   = 4;
    c.strokeRect(bPad + 2, bPad + 2, BW - bPad*2 - 4, BH - bPad*2 - 4);

    // ── Content starts inside border ───────────────────────
    // inner padding: 0.5em ≈ 7px; border: 4px; outer pad: 5px
    var cx  = bPad + 4 + 7;   // left edge of content = 16px
    var cy  = bPad + 4 + 7;   // top edge of content  = 16px
    var cw  = BW - cx * 2;    // content width        = 108px

    c.textAlign    = 'center';
    c.textBaseline = 'top';

    // ── Patriots logo (img, width:100%, height:4em ≈ 44px) ──
    var logoH = 44;
    if (patsLogo) {
      c.drawImage(patsLogo, cx, cy, cw, logoH);
    }
    cy += logoH + 4;

    // ── "New England Patriots" — slightly larger ──────────
    c.fillStyle = 'white';
    c.font       = 'bold 13px Arial, sans-serif';
    c.fillText('NEW ENGLAND', BW / 2, cy);
    cy += 16;
    c.fillText('PATRIOTS', BW / 2, cy);
    cy += 20;

    // ── Super Bowl logo ────────────────────────────────────
    var sbH = 60;
    if (sbLogo) {
      c.drawImage(sbLogo, cx, cy, cw, sbH);
    }
    cy += sbH + 8;

    // ── "World Champions" — 2 lines, slightly larger ──────
    c.fillStyle = 'white';
    c.font       = 'bold 13px Arial, sans-serif';
    c.fillText('WORLD', BW / 2, cy);
    cy += 16;
    c.fillText('CHAMPIONS', BW / 2, cy);
    cy += 20;

    // ── Year — auto-sized to fill banner width ─────────────
    var yearSize = 46;
    c.font = 'bold ' + yearSize + 'px Arial, sans-serif';
    while (c.measureText(sb.year).width > cw && yearSize > 20) {
      yearSize -= 1;
      c.font = 'bold ' + yearSize + 'px Arial, sans-serif';
    }
    c.fillStyle = 'white';
    c.fillText(sb.year, BW / 2, cy);

    return oc;
  }

  // ── Cloth banner ─────────────────────────────────────────
  function ClothBanner(canvas, sb, patsLogo, sbLogo) {
    this.canvas  = canvas;
    this.ctx     = canvas.getContext('2d');
    this.texture = makeBannerTexture(sb, patsLogo, sbLogo);
    this.pts     = [];
    this.cons    = [];
    this._build();
  }

  ClothBanner.prototype._build = function () {
    var cw = BW / (COLS - 1);
    var ch = BH / (ROWS - 1);
    var r, c;

    for (r = 0; r < ROWS; r++) {
      for (c = 0; c < COLS; c++) {
        var p = new Particle(PAD + c * cw, r * ch);
        if (r === 0) p.pinned = true;
        if (r === ROWS - 1 && (c === 0 || c === COLS - 1)) p.pinned = true;
        this.pts.push(p);
      }
    }

    var cons = this.cons;
    var pts  = this.pts;
    function link(a, b) { cons.push(new Constraint(a, b)); }

    for (r = 0; r < ROWS; r++) {
      for (c = 0; c < COLS; c++) {
        if (c < COLS - 1) link(pts[r*COLS+c],     pts[r*COLS+c+1]);
        if (r < ROWS - 1) link(pts[r*COLS+c],     pts[(r+1)*COLS+c]);
        if (r < ROWS-1 && c < COLS-1) {
          link(pts[r*COLS+c],   pts[(r+1)*COLS+c+1]);
          link(pts[r*COLS+c+1], pts[(r+1)*COLS+c]);
        }
      }
    }
  };

  ClothBanner.prototype.update = function (mx, my) {
    var i;
    for (i = 0; i < this.pts.length; i++) {
      var p = this.pts[i];
      if (p.pinned) continue;
      var dx = p.x - mx, dy = p.y - my;
      var d  = Math.sqrt(dx*dx + dy*dy);
      if (d < M_RADIUS && d > 0) {
        var f = (1 - d / M_RADIUS) * M_FORCE;
        p.nudge(dx / d * f, dy / d * f);
      }
    }
    for (i = 0; i < this.pts.length; i++) this.pts[i].step();
    for (i = 0; i < ITERATIONS; i++) {
      for (var j = 0; j < this.cons.length; j++) this.cons[j].satisfy();
    }
  };

  // Affine texture-map a triangle
  ClothBanner.prototype._tri = function (x0,y0, x1,y1, x2,y2, u0,v0, u1,v1, u2,v2) {
    var ctx = this.ctx;
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(x0,y0); ctx.lineTo(x1,y1); ctx.lineTo(x2,y2);
    ctx.closePath();
    ctx.clip();

    var det = u0*(v1-v2) + u1*(v2-v0) + u2*(v0-v1);
    if (Math.abs(det) < 0.1) { ctx.restore(); return; }

    var a  = (x0*(v1-v2) + x1*(v2-v0) + x2*(v0-v1)) / det;
    var b  = (x0*(u2-u1) + x1*(u0-u2) + x2*(u1-u0)) / det;
    var cv = (x0*(u1*v2 - u2*v1) + x1*(u2*v0 - u0*v2) + x2*(u0*v1 - u1*v0)) / det;
    var d  = (y0*(v1-v2) + y1*(v2-v0) + y2*(v0-v1)) / det;
    var e  = (y0*(u2-u1) + y1*(u0-u2) + y2*(u1-u0)) / det;
    var ff = (y0*(u1*v2 - u2*v1) + y1*(u2*v0 - u0*v2) + y2*(u0*v1 - u1*v0)) / det;

    ctx.transform(a, d, b, e, cv, ff);
    ctx.drawImage(this.texture, 0, 0);
    ctx.restore();
  };

  ClothBanner.prototype.draw = function () {
    this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    var cw = BW / (COLS - 1);
    var ch = BH / (ROWS - 1);

    for (var r = 0; r < ROWS - 1; r++) {
      for (var c = 0; c < COLS - 1; c++) {
        var tl = this.pts[r*COLS + c];
        var tr = this.pts[r*COLS + c + 1];
        var bl = this.pts[(r+1)*COLS + c];
        var br = this.pts[(r+1)*COLS + c + 1];
        var u0 = c*cw, v0 = r*ch, u1 = u0+cw, v1 = v0+ch;

        this._tri(tl.x,tl.y, tr.x,tr.y, bl.x,bl.y, u0,v0, u1,v0, u0,v1);
        this._tri(tr.x,tr.y, br.x,br.y, bl.x,bl.y, u1,v0, u1,v1, u0,v1);
      }
    }
  };

  // ── Init: load images, then build simulation ──────────────
  function initBanners() {
    var container = document.getElementById('championship-banners');
    if (!container) return;

    // Collect all image URLs to load: Patriots logo + 6 SB logos
    var patsLogoUrl = IMG_PATH + 'New_England_Patriots_logo.svg';
    var sbLogoUrls  = SUPER_BOWLS.map(function (sb) { return sb.sbLogo; });
    var allUrls     = [patsLogoUrl].concat(sbLogoUrls);

    var loaded  = 0;
    var images  = new Array(allUrls.length);

    allUrls.forEach(function (url, i) {
      var img = new Image();
      img.onload = function () {
        images[i] = img;
        if (++loaded === allUrls.length) startSim();
      };
      img.onerror = function () {
        images[i] = null;
        if (++loaded === allUrls.length) startSim();
      };
      img.src = url;
    });

    function startSim() {
      var patsLogo = images[0];
      var mouse    = { x: -9999, y: -9999 };
      var banners  = [];
      var active   = true;

      SUPER_BOWLS.forEach(function (sb, idx) {
        var canvas = document.getElementById('banner-canvas-' + idx);
        if (!canvas) return;
        canvas.width  = BW + PAD * 2;
        canvas.height = BH + 8;

        // Sync the gold pole width to the canvas width
        var pole = canvas.previousElementSibling;
        if (pole && pole.classList.contains('banner-pole')) {
          pole.style.width = canvas.width + 'px';
        }

        var sbLogo = images[idx + 1] || null;
        banners.push(new ClothBanner(canvas, sb, patsLogo, sbLogo));
      });

      window.addEventListener('mousemove', function (e) {
        mouse.x = e.clientX;
        mouse.y = e.clientY;
      });

      document.addEventListener('visibilitychange', function () {
        active = !document.hidden;
        if (active) tick();
      });

      function tick() {
        if (!active) return;
        banners.forEach(function (b) {
          var r = b.canvas.getBoundingClientRect();
          b.update(mouse.x - r.left, mouse.y - r.top);
          b.draw();
        });
        requestAnimationFrame(tick);
      }

      tick();
    }
  }

  // ── Night mode (follows system prefers-color-scheme) ────────────────────────
  function initNightToggle() {
    var section = document.getElementById('banner-section');
    if (!section) return;

    localStorage.removeItem('bannerNight');

    function setNight(on) {
      if (on) {
        section.classList.add('night');
      } else {
        section.classList.remove('night');
      }
    }

    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    setNight(mq.matches);
    mq.addEventListener('change', function (e) { setNight(e.matches); });
  }

  function boot() {
    initNightToggle();
    initBanners();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

})();
