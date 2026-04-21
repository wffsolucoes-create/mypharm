(function (global) {
  'use strict';

  var STORAGE_KEY = 'mypharm_tv_presentation_mode';

  var PresentationMode = {
    enabled: false,
    init: function () {
      try {
        this.enabled = localStorage.getItem(STORAGE_KEY) === '1';
      } catch (e) {
        this.enabled = false;
      }
      this.apply();
      this.syncButton();
      return this;
    },
    save: function () {
      try {
        localStorage.setItem(STORAGE_KEY, this.enabled ? '1' : '0');
      } catch (e) {}
    },
    apply: function () {
      document.body.classList.toggle('presentation-mode', !!this.enabled);
    },
    syncButton: function () {
      var btn = document.getElementById('btn-presentation');
      if (!btn) return;
      btn.setAttribute('aria-pressed', this.enabled ? 'true' : 'false');
      btn.title = this.enabled ? 'Desativar modo apresentação' : 'Ativar modo apresentação';
      btn.setAttribute('aria-label', this.enabled ? 'Desativar modo apresentação' : 'Ativar modo apresentação');
      var label = btn.querySelector('.presentation-label');
      if (label) label.textContent = this.enabled ? 'Apresentação ON' : 'Apresentação';
    },
    togglePresentationMode: function () {
      this.enabled = !this.enabled;
      this.apply();
      this.syncButton();
      this.save();
      return this.enabled;
    }
  };

  global.PresentationMode = PresentationMode;
  global.togglePresentationMode = function () {
    if (global.presentationMode) return global.presentationMode.togglePresentationMode();
    return false;
  };
})(typeof window !== 'undefined' ? window : this);
