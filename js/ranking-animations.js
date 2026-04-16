(function (global) {
  'use strict';

  function animateNumber(el, toValue, suffix, duration, reduced) {
    if (!el) return;
    if (reduced) {
      el.textContent = Math.round(toValue) + (suffix || '');
      return;
    }
    var start = parseFloat(el.getAttribute('data-anim-from') || '0') || 0;
    var target = Number(toValue) || 0;
    var t0 = performance.now();
    var ms = duration || 900;
    function step(now) {
      var p = Math.min(1, (now - t0) / ms);
      var ease = 1 - Math.pow(1 - p, 3);
      var cur = start + (target - start) * ease;
      el.textContent = Math.round(cur) + (suffix || '');
      if (p < 1) requestAnimationFrame(step);
      else el.setAttribute('data-anim-from', String(target));
    }
    requestAnimationFrame(step);
  }

  var RankingAnimations = {
    reducedMotion: false,
    init: function () {
      this.reducedMotion = !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
      return this;
    },
    setReducedMotion: function (flag) {
      this.reducedMotion = !!flag;
    },
    animateRankingUpdate: function () {
      if (this.reducedMotion) return;
      var grid = document.querySelector('.page-main__grid');
      if (!grid) return;
      grid.classList.remove('ranking-update-flash');
      void grid.offsetWidth;
      grid.classList.add('ranking-update-flash');
    },
    animateProgressBars: function (scope) {
      var root = scope || document;
      var bars = root.querySelectorAll('.rank-card__bar-fill[data-target-width]');
      for (var i = 0; i < bars.length; i++) {
        var bar = bars[i];
        var target = Math.max(0, Math.min(100, Number(bar.getAttribute('data-target-width')) || 0));
        if (this.reducedMotion) {
          bar.style.width = target + '%';
        } else {
          bar.style.width = '0%';
          (function (el, t, idx) {
            setTimeout(function () {
              el.style.width = t + '%';
            }, 140 + idx * 52);
          })(bar, target, i);
        }
      }

      var pctEls = root.querySelectorAll('.rank-card__pct-val[data-pct-target]');
      for (var j = 0; j < pctEls.length; j++) {
        var pctEl = pctEls[j];
        animateNumber(pctEl, Number(pctEl.getAttribute('data-pct-target')) || 0, '%', 780, this.reducedMotion);
      }
    },
    showTop3EntryBadge: function (name) {
      var badge = document.getElementById('top3-entry-badge');
      if (!badge) return;
      badge.textContent = 'Entrou no Top 3: ' + (name || 'Consultora');
      badge.classList.remove('top3-entry-badge--show');
      void badge.offsetWidth;
      badge.classList.add('top3-entry-badge--show');
      clearTimeout(this._top3Timer);
      this._top3Timer = setTimeout(function () {
        badge.classList.remove('top3-entry-badge--show');
      }, 2200);
    },
    highlightSellerPhoto: function (seller, reason) {
      var panel = document.getElementById('leader-spotlight');
      if (!panel || !seller) return;
      var img = document.getElementById('leader-spotlight-avatar');
      var nm = document.getElementById('leader-spotlight-name');
      var val = document.getElementById('leader-spotlight-value');
      var sub = document.getElementById('leader-spotlight-sub');
      if (img) img.src = seller.foto || '';
      if (nm) nm.textContent = seller.nome || 'Líder';
      if (val) val.textContent = seller.vendasFmt || '';
      if (sub) sub.textContent = reason || 'Destaque em tempo real';

      panel.classList.remove('leader-spotlight--show');
      void panel.offsetWidth;
      panel.classList.add('leader-spotlight--show');
      clearTimeout(this._leaderTimer);
      this._leaderTimer = setTimeout(function () {
        panel.classList.remove('leader-spotlight--show');
      }, this.reducedMotion ? 1400 : 3000);
    },
    animateLeaderHighlight: function (seller, reason) {
      if (!seller) return;
      var firstSlot = document.querySelector('.podium-slot--1st');
      if (firstSlot) {
        firstSlot.classList.remove('leader-emphasis');
        void firstSlot.offsetWidth;
        firstSlot.classList.add('leader-emphasis');
        setTimeout(function () {
          firstSlot.classList.remove('leader-emphasis');
        }, this.reducedMotion ? 700 : 1800);
      }
      this.highlightSellerPhoto(seller, reason);
    },
    animateTop3Change: function (list, top3Name) {
      var row = document.getElementById('podium-row');
      if (row && !this.reducedMotion) {
        row.classList.remove('top3-shift');
        void row.offsetWidth;
        row.classList.add('top3-shift');
        setTimeout(function () { row.classList.remove('top3-shift'); }, 1200);
      }
      this.showTop3EntryBadge(top3Name || (list && list[0] && list[0].nome) || '');
    }
  };

  global.RankingAnimations = RankingAnimations;

  /* API pública esperada para integração rápida e testes manuais no console */
  global.animateRankingUpdate = function () {
    if (global.rankingAnimations) global.rankingAnimations.animateRankingUpdate();
  };
  global.animateLeaderHighlight = function (seller, reason) {
    if (global.rankingAnimations) global.rankingAnimations.animateLeaderHighlight(seller, reason);
  };
  global.animateTop3Change = function (list, name) {
    if (global.rankingAnimations) global.rankingAnimations.animateTop3Change(list, name);
  };
  global.animateProgressBars = function (scope) {
    if (global.rankingAnimations) global.rankingAnimations.animateProgressBars(scope || document);
  };
  global.highlightSellerPhoto = function (seller, reason) {
    if (global.rankingAnimations) global.rankingAnimations.highlightSellerPhoto(seller, reason);
  };
  global.showTop3EntryBadge = function (name) {
    if (global.rankingAnimations) global.rankingAnimations.showTop3EntryBadge(name);
  };
})(typeof window !== 'undefined' ? window : this);
