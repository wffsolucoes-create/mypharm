(function (global) {
  'use strict';

  function ConfettiManager(canvasEl) {
    this.canvas = canvasEl || null;
    this.ctx = this.canvas && this.canvas.getContext ? this.canvas.getContext('2d') : null;
    this.running = false;
    this.reduced = false;
  }

  ConfettiManager.prototype.setReducedMotion = function (flag) {
    this.reduced = !!flag;
  };

  ConfettiManager.prototype._renderBurst = function (opts) {
    if (!this.canvas || !this.ctx || this.reduced) return;
    if (this.running) return;
    this.running = true;

    var ctx = this.ctx;
    var dpr = window.devicePixelRatio || 1;
    var w = Math.floor(window.innerWidth);
    var h = Math.floor(window.innerHeight);
    this.canvas.width = Math.floor(w * dpr);
    this.canvas.height = Math.floor(h * dpr);
    this.canvas.style.width = w + 'px';
    this.canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    this.canvas.classList.add('confetti-canvas--active');

    var palette = opts.palette || ['#22d3ee', '#6366f1', '#f59e0b', '#34d399'];
    var duration = opts.duration != null ? opts.duration : 5200;
    var count = opts.count != null ? opts.count : 96;
    var parts = [];

    function pushBurst(cx, cy, n) {
      for (var i = 0; i < n; i++) {
        var ang = Math.random() * Math.PI * 2;
        var speed = 2.2 + Math.random() * 6.2;
        parts.push({
          x: cx + (Math.random() * 36 - 18),
          y: cy + (Math.random() * 36 - 18),
          vx: Math.cos(ang) * speed,
          vy: Math.sin(ang) * speed - 0.9,
          g: 0.07 + Math.random() * 0.07,
          s: 3.2 + Math.random() * 5.4,
          a: Math.random() * Math.PI,
          va: -0.14 + Math.random() * 0.28,
          c: palette[Math.floor(Math.random() * palette.length)]
        });
      }
    }

    var emitters = opts.emitters;
    if (emitters && emitters.length) {
      var each = Math.max(8, Math.floor(count / emitters.length));
      for (var e = 0; e < emitters.length; e++) {
        pushBurst(emitters[e][0], emitters[e][1], each);
      }
    } else {
      var cx = opts.centerX != null ? opts.centerX : w * 0.5;
      var cy = opts.centerY != null ? opts.centerY : h * 0.48;
      pushBurst(cx, cy, count);
    }

    var self = this;
    var t0 = performance.now();
    (function frame(now) {
      var elapsed = now - t0;
      ctx.clearRect(0, 0, w, h);
      for (var j = 0; j < parts.length; j++) {
        var p = parts[j];
        p.x += p.vx;
        p.y += p.vy;
        p.vy += p.g;
        p.a += p.va;
        ctx.save();
        ctx.translate(p.x, p.y);
        ctx.rotate(p.a);
        ctx.fillStyle = p.c;
        ctx.fillRect(-p.s / 2, -p.s / 2, p.s, p.s * 0.72);
        ctx.restore();
      }
      if (elapsed < duration) {
        requestAnimationFrame(frame);
      } else {
        ctx.clearRect(0, 0, w, h);
        self.canvas.classList.remove('confetti-canvas--active');
        self.running = false;
      }
    })(performance.now());
  };

  ConfettiManager.prototype.triggerLeaderCelebration = function () {
    var W = window.innerWidth;
    var H = window.innerHeight;
    this._renderBurst({
      count: 110,
      duration: 5200,
      emitters: [
        [W * 0.5, H * 0.46],
        [W * 0.22, H * 0.5],
        [W * 0.78, H * 0.5],
        [W * 0.5, H * 0.62],
        [W * 0.36, H * 0.38],
        [W * 0.64, H * 0.38]
      ],
      palette: ['#22d3ee', '#6366f1', '#a5b4fc', '#f59e0b', '#e2e8f0', '#f472b6']
    });
  };

  ConfettiManager.prototype.triggerMetaCelebration = function () {
    var W = window.innerWidth;
    var H = window.innerHeight;
    this._renderBurst({
      count: 88,
      duration: 5000,
      emitters: [
        [W * 0.5, H * 0.48],
        [W * 0.28, H * 0.52],
        [W * 0.72, H * 0.52],
        [W * 0.5, H * 0.65]
      ],
      palette: ['#34d399', '#22d3ee', '#a5f3fc', '#86efac', '#fbbf24']
    });
  };

  global.MyPharmConfettiManager = ConfettiManager;
  global.triggerLeaderCelebration = function () {
    if (global.confettiManager) global.confettiManager.triggerLeaderCelebration();
  };
  global.triggerMetaCelebration = function () {
    if (global.confettiManager) global.confettiManager.triggerMetaCelebration();
  };
})(typeof window !== 'undefined' ? window : this);
