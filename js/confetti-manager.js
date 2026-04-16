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
    var duration = opts.duration || 1450;
    var count = opts.count || 42;
    var spread = opts.spread || 0.5;
    var centerX = opts.centerX != null ? opts.centerX : w * 0.5;
    var centerY = opts.centerY != null ? opts.centerY : h * 0.26;
    var parts = [];

    for (var i = 0; i < count; i++) {
      var ang = (-Math.PI * spread / 2) + Math.random() * Math.PI * spread;
      var speed = 2.4 + Math.random() * 3.4;
      parts.push({
        x: centerX + (Math.random() * 32 - 16),
        y: centerY + (Math.random() * 20 - 10),
        vx: Math.cos(ang) * speed,
        vy: Math.sin(ang) * speed - 1.4,
        g: 0.08 + Math.random() * 0.06,
        s: 3 + Math.random() * 4.6,
        a: Math.random() * Math.PI,
        va: -0.16 + Math.random() * 0.32,
        c: palette[Math.floor(Math.random() * palette.length)]
      });
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
    this._renderBurst({
      count: 56,
      duration: 1650,
      spread: 0.75,
      centerY: Math.max(120, window.innerHeight * 0.2),
      palette: ['#22d3ee', '#6366f1', '#a5b4fc', '#f59e0b', '#e2e8f0']
    });
  };

  ConfettiManager.prototype.triggerMetaCelebration = function () {
    this._renderBurst({
      count: 36,
      duration: 1200,
      spread: 0.55,
      centerY: Math.max(120, window.innerHeight * 0.24),
      palette: ['#34d399', '#22d3ee', '#a5f3fc', '#86efac']
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
