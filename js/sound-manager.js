/**
 * MyPharm — SoundManager (identidade sonora premium, arquivos MP3)
 *
 * Troca de sons no futuro:
 * - Substitua os arquivos em /assets/sounds/ mantendo os MESMOS nomes, ou
 * - Altere o mapa SOUND_FILES abaixo para apontar para novos nomes.
 *
 * Política de autoplay: use unlock() após primeiro gesto do usuário antes de play().
 *
 * Onde os sons são disparados (tv/index.html):
 * - rankingUpdate → dados do ranking mudaram (debounce longo no manager)
 * - rankUp        → consultora subiu de posição (prioridade abaixo de top3/meta)
 * - top3Enter     → passou a ocupar posição 1–3 vindo de fora do pódio
 * - metaHit       → percentual_meta cruzou de abaixo de 100% para 100% ou mais
 * - warning       → falha ao atualizar dados após o primeiro carregamento com sucesso
 * - clickSoft     → tela cheia, configurações, fechar modal, ligar som
 */
(function (global) {
  'use strict';

  /** Nome lógico → arquivo em baseUrl */
  var SOUND_FILES = {
    rankingUpdate: 'ranking-update.mp3',
    rankUp: 'rank-up.mp3',
    top3Enter: 'top3-enter.mp3',
    metaHit: 'meta-hit.mp3',
    warning: 'warning.mp3',
    clickSoft: 'click-soft.mp3'
  };

  /** Prioridade para interrupção (maior = mais importante). */
  var PRIORITY = {
    warning: 100,
    top3Enter: 85,
    metaHit: 75,
    rankUp: 65,
    rankingUpdate: 45,
    clickSoft: 25
  };

  var DEFAULT_BASE = '../assets/sounds/';
  var DEFAULT_PREFIX = 'mypharm_tv_';

  function SoundManager(options) {
    options = options || {};
    this.baseUrl = (options.baseUrl || DEFAULT_BASE).replace(/\/?$/, '/');
    this.prefix = options.storagePrefix || DEFAULT_PREFIX;
    this.storageKeys = {
      enabled: this.prefix + 'sound_enabled',
      volume: this.prefix + 'sound_volume'
    };

    this.enabled = true;
    /** Volume linear 0–1 aplicado em cada elemento Audio */
    this.volume = 0.45;
    /** Navegador liberou reprodução após gesto */
    this.unlocked = false;

    this._entries = {};
    this._active = null;
    this._lastStartAt = 0;
    this._lastStartPriority = 0;
    this._lastByName = {};
    this._playingPriority = 0;
    this._pendingAfterUnlock = null;

    /** Intervalo mínimo entre quaisquer dois sons (ms) */
    this.minGlobalGapMs = options.minGlobalGapMs != null ? options.minGlobalGapMs : 400;

    this.debounceMs = {
      rankingUpdate: 10000,
      rankUp: 2800,
      top3Enter: 5000,
      metaHit: 6500,
      warning: 4500,
      clickSoft: 140
    };
  }

  SoundManager.prototype.loadPreferences = function () {
    try {
      var en = localStorage.getItem(this.storageKeys.enabled);
      if (en === '0' || en === 'false') this.enabled = false;
      else if (en === '1' || en === 'true') this.enabled = true;
      var vol = localStorage.getItem(this.storageKeys.volume);
      if (vol != null && vol !== '') {
        var v = parseFloat(vol);
        if (!isNaN(v)) this.volume = Math.max(0, Math.min(1, v));
      }
    } catch (e) {
      /* storage indisponível */
    }
  };

  SoundManager.prototype.savePreferences = function () {
    try {
      localStorage.setItem(this.storageKeys.enabled, this.enabled ? '1' : '0');
      localStorage.setItem(this.storageKeys.volume, String(this.volume));
    } catch (e) {}
  };

  SoundManager.prototype.enable = function () {
    this.enabled = true;
    this.savePreferences();
  };

  SoundManager.prototype.disable = function () {
    this.enabled = false;
    this.stopAll();
    this.savePreferences();
  };

  SoundManager.prototype.setVolume = function (value) {
    var v = typeof value === 'number' ? value : parseFloat(value);
    if (isNaN(v)) return;
    this.volume = Math.max(0, Math.min(1, v));
    if (this._active) {
      try {
        this._active.volume = this.volume;
      } catch (e) {}
    }
    this.savePreferences();
  };

  SoundManager.prototype.stopAll = function () {
    if (this._active) {
      try {
        this._active.pause();
        this._active.currentTime = 0;
      } catch (e) {}
      this._active = null;
    }
    this._playingPriority = 0;
  };

  SoundManager.prototype._canPlayNow = function (name, force) {
    var now = Date.now();
    var p = PRIORITY[name] || 0;
    if (!force) {
      var deb = this.debounceMs[name];
      if (deb != null && this._lastByName[name] && now - this._lastByName[name] < deb) {
        return false;
      }
      if (now - this._lastStartAt < this.minGlobalGapMs && p <= this._lastStartPriority) {
        return false;
      }
    }
    return true;
  };

  /**
   * Pré-carrega MP3s. Falhas (arquivo ausente) marcam entry.ok = false — play() ignora.
   */
  SoundManager.prototype.init = function () {
    this.loadPreferences();
    var self = this;
    for (var key in SOUND_FILES) {
      if (!Object.prototype.hasOwnProperty.call(SOUND_FILES, key)) continue;
      var url = this.baseUrl + SOUND_FILES[key];
      var entry = { url: url, ok: true, el: null };
      var a = new Audio();
      a.preload = 'auto';
      (function (k, ent, audio) {
        audio.addEventListener(
          'error',
          function () {
            ent.ok = false;
            ent.el = null;
          },
          { once: true }
        );
        audio.addEventListener(
          'canplaythrough',
          function () {
            if (ent.ok !== false) ent.ok = true;
          },
          { once: true }
        );
        try {
          audio.src = ent.url;
          audio.load();
        } catch (err) {
          ent.ok = false;
        }
        ent.el = audio;
      })(key, entry, a);
      this._entries[key] = entry;
    }

    var unlock = function () {
      self.unlock();
    };
    document.addEventListener('pointerdown', unlock, { once: true, passive: true });
    document.addEventListener('keydown', unlock, { once: true });

    return this;
  };

  /** Marca áudio desbloqueado (gesto do usuário). */
  SoundManager.prototype.unlock = function () {
    if (this.unlocked) {
      this._flushPending();
      return;
    }
    this.unlocked = true;
    /* “silêncio” de desbloqueio: alguns navegadores exigem play() vazio; não tocamos arquivo. */
    this._flushPending();
  };

  SoundManager.prototype._flushPending = function () {
    if (!this._pendingAfterUnlock || !this.enabled) {
      this._pendingAfterUnlock = null;
      return;
    }
    var n = this._pendingAfterUnlock;
    this._pendingAfterUnlock = null;
    this.play(n, { force: true });
  };

  /**
   * Toca um som por nome lógico.
   * @param {string} soundName rankingUpdate | rankUp | top3Enter | metaHit | warning | clickSoft
   * @param {{ force?: boolean }} opts
   */
  SoundManager.prototype.play = function (soundName, opts) {
    opts = opts || {};
    if (!SOUND_FILES[soundName]) return;

    if (!this.enabled) return;

    if (!this.unlocked) {
      var pp = PRIORITY[soundName] || 0;
      var curP = this._pendingAfterUnlock ? PRIORITY[this._pendingAfterUnlock] || 0 : -1;
      if (!this._pendingAfterUnlock || pp > curP) this._pendingAfterUnlock = soundName;
      return;
    }

    var entry = this._entries[soundName];
    if (!entry || entry.ok === false || !entry.url) return;

    if (!this._canPlayNow(soundName, opts.force)) return;

    this.stopAll();

    var audio;
    try {
      audio = new Audio(entry.url);
      audio.volume = this.volume;
    } catch (e) {
      return;
    }

    var self = this;
    var p = PRIORITY[soundName] || 0;
    this._active = audio;
    this._playingPriority = p;
    var started = Date.now();
    this._lastStartAt = started;
    this._lastStartPriority = p;
    this._lastByName[soundName] = started;

    audio.addEventListener(
      'ended',
      function () {
        if (self._active === audio) {
          self._active = null;
          self._playingPriority = 0;
        }
      },
      { once: true }
    );
    audio.addEventListener(
      'error',
      function () {
        if (self._active === audio) {
          self._active = null;
          self._playingPriority = 0;
        }
      },
      { once: true }
    );

    var playPromise = audio.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(function () {
        if (self._active === audio) {
          self._active = null;
          self._playingPriority = 0;
        }
      });
    }
  };

  global.MyPharmSoundManager = SoundManager;
})(typeof window !== 'undefined' ? window : this);
