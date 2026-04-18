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
 * - iniciar / clicarusuario / ficarsemvemda → tv/public/audio/*.mp3
 * - pedido + aplausos quando há nova venda (salesGain no poll); pedido não toca a cada refresh vazio
 * - rankingUpdate, rankUp, top3Enter, metaHit → efeitos legados (reservados)
 * - warning       → falha ao atualizar dados após o primeiro carregamento com sucesso
 * - sessionEnter  → fanfare (legado)
 * - clickSoft     → tela cheia, configurações, fechar modal, ligar som
 */
(function (global) {
  'use strict';

  /** Nome lógico → arquivo em baseUrl */
  var SOUND_FILES = {
    rankingUpdate: '../dist/audio/u_oepgi4ep3v-som_matricula-464025.mp3',
    rankUp: '../dist/audio/universfield-new-notification-036-485897.mp3',
    top3Enter: 'champion.wav',
    metaHit: '../dist/audio/susan-lu4esm-aplausos-433039.mp3',
    warning: 'alert.wav',
    sessionEnter: '../dist/audio/u_ss015dykrt-brass-fanfare-reverberated-146263.mp3',
    clickSoft: 'alert.wav',
    /** TV — tv/public/audio (paths relativos a baseUrl ../assets/sounds/) */
    iniciar: '../../tv/public/audio/iniciar.mp3',
    pedido: '../../tv/public/audio/pedido.mp3',
    aplausos: '../../tv/public/audio/aplausos.mp3',
    clicarusuario: '../../tv/public/audio/clicarusuario.mp3',
    ficarsemvemda: '../../tv/public/audio/ficarsemvemda.mp3'
  };

  /** Prioridade para interrupção (maior = mais importante). */
  var PRIORITY = {
    warning: 100,
    sessionEnter: 92,
    iniciar: 88,
    top3Enter: 85,
    aplausos: 78,
    metaHit: 75,
    rankUp: 65,
    pedido: 48,
    ficarsemvemda: 42,
    rankingUpdate: 45,
    clicarusuario: 38,
    clickSoft: 25
  };

  var DEFAULT_BASE = '../assets/sounds/';
  var DEFAULT_PREFIX = 'mypharm_tv_';
  var DEFAULT_CATALOG_FILE = 'manifest.json';

  function mergeUniqueLists(a, b) {
    var seen = {};
    var out = [];
    function add(arr) {
      if (!arr || !arr.length) return;
      for (var i = 0; i < arr.length; i++) {
        var s = String(arr[i]);
        if (s && !seen[s]) {
          seen[s] = true;
          out.push(s);
        }
      }
    }
    add(a);
    add(b);
    return out;
  }

  function allSoundFilePaths() {
    var seen = {};
    var out = [];
    for (var k in SOUND_FILES) {
      if (!Object.prototype.hasOwnProperty.call(SOUND_FILES, k)) continue;
      var fn = SOUND_FILES[k];
      if (fn && !seen[fn]) {
        seen[fn] = true;
        out.push(fn);
      }
    }
    return out;
  }

  function SoundManager(options) {
    options = options || {};
    this.baseUrl = (options.baseUrl || DEFAULT_BASE).replace(/\/?$/, '/');
    /** Se true, libera reprodução sem esperar gesto (ex.: TV com autoplay permitido no Firefox). */
    this.autoUnlock = !!options.autoUnlock;
    this.prefix = options.storagePrefix || DEFAULT_PREFIX;
    this.storageKeys = {
      enabled: this.prefix + 'sound_enabled',
      volume: this.prefix + 'sound_volume',
      soundMask: this.prefix + 'sound_mask'
    };
    /** { nomeLógico: boolean } — false = não tocar esse efeito (persistido em localStorage). */
    this.soundMask = {};
    this.catalogFile = options.catalogFile || DEFAULT_CATALOG_FILE;
    this.catalogLoaded = false;

    this.enabled = true;
    /** Volume linear 0–1 aplicado em cada elemento Audio */
    this.volume = 0.8;
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
      sessionEnter: 13000,
      clickSoft: 140,
      iniciar: 60000,
      pedido: 12000,
      aplausos: 3500,
      clicarusuario: 500,
      ficarsemvemda: 3300000
    };
  }

  SoundManager.prototype._resolveMediaUrl = function (relativePath) {
    var rel = this.baseUrl + relativePath;
    try {
      if (typeof window !== 'undefined' && window.location && window.URL) {
        return new URL(rel, window.location.href).href;
      }
    } catch (e) {}
    return rel;
  };

  SoundManager.prototype._setCatalog = function (availableNames) {
    var allow = {};
    for (var i = 0; i < availableNames.length; i++) {
      allow[String(availableNames[i])] = true;
    }
    for (var key in SOUND_FILES) {
      if (!Object.prototype.hasOwnProperty.call(SOUND_FILES, key)) continue;
      var fileKey = SOUND_FILES[key];
      var url = this._resolveMediaUrl(fileKey);
      this._entries[key] = {
        url: url,
        ok: !!allow[fileKey],
        el: null
      };
    }
    this.catalogLoaded = true;
  };

  SoundManager.prototype.loadPreferences = function () {
    try {
      var en = localStorage.getItem(this.storageKeys.enabled);
      if (en === '0' || en === 'false') this.enabled = false;
      else if (en === '1' || en === 'true') this.enabled = true;
      var vol = localStorage.getItem(this.storageKeys.volume);
      if (vol != null && vol !== '') {
        var v = parseFloat(vol);
        if (!isNaN(v)) {
          if (v > 1 && v <= 100) v = v / 100;
          this.volume = Math.max(0, Math.min(1, v));
        }
      }
    } catch (e) {
      /* storage indisponível */
    }
  };

  SoundManager.prototype._defaultSoundMask = function () {
    var m = {};
    for (var k in SOUND_FILES) {
      if (Object.prototype.hasOwnProperty.call(SOUND_FILES, k)) {
        m[k] = true;
      }
    }
    return m;
  };

  /** Carrega preferências de quais sons tocar (merge com defaults). */
  SoundManager.prototype.loadSoundMask = function () {
    this.soundMask = this._defaultSoundMask();
    try {
      var raw = localStorage.getItem(this.storageKeys.soundMask);
      if (!raw) return;
      var o = JSON.parse(raw);
      if (o && typeof o === 'object') {
        for (var k in o) {
          if (Object.prototype.hasOwnProperty.call(this.soundMask, k)) {
            this.soundMask[k] = !!o[k];
          }
        }
      }
    } catch (e) {
      this.soundMask = this._defaultSoundMask();
    }
  };

  /** @param {string} id chave em SOUND_FILES */
  SoundManager.prototype.isSoundIdEnabled = function (id) {
    if (!SOUND_FILES[id]) return true;
    if (!this.soundMask || typeof this.soundMask !== 'object') return true;
    return this.soundMask[id] !== false;
  };

  /** @param {string} id */
  SoundManager.prototype.setSoundIdEnabled = function (id, enabled) {
    if (!SOUND_FILES[id]) return;
    if (!this.soundMask) this.soundMask = this._defaultSoundMask();
    this.soundMask[id] = !!enabled;
    try {
      localStorage.setItem(this.storageKeys.soundMask, JSON.stringify(this.soundMask));
    } catch (e) {}
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
    this.loadSoundMask();
    var self = this;
    var defaultList = allSoundFilePaths();
    // Catálogo síncrono: sem isto, play() falha até o fetch terminar e todos os sons
    // disparados no primeiro frame / antes do manifest eram ignorados (catalogLoaded false).
    self._setCatalog(defaultList);

    fetch(this.baseUrl + this.catalogFile + '?_=' + Date.now(), { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('missing catalog');
        return r.json();
      })
      .then(function (json) {
        var available = (json && Array.isArray(json.available)) ? json.available : [];
        self._setCatalog(mergeUniqueLists(available, defaultList));
      })
      .catch(function () {
        self._setCatalog(defaultList);
      });

    if (this.autoUnlock) {
      this.unlock();
    } else {
      var unlock = function () {
        self.unlock();
      };
      document.addEventListener('pointerdown', unlock, { once: true, passive: true });
      document.addEventListener('keydown', unlock, { once: true });
    }

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
    if (typeof document !== 'undefined' && typeof CustomEvent !== 'undefined') {
      try {
        document.dispatchEvent(new CustomEvent('mypharm-sound-unlocked'));
      } catch (e) {}
    }
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
   * @param {string} soundName rankingUpdate | rankUp | top3Enter | metaHit | warning | sessionEnter | clickSoft
   * @param {{ force?: boolean }} opts
   */
  SoundManager.prototype.play = function (soundName, opts) {
    opts = opts || {};
    if (!SOUND_FILES[soundName]) return;

    if (!this.enabled) return;
    if (!this.catalogLoaded) return;
    if (!this.isSoundIdEnabled(soundName)) return;

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
      try {
        audio.currentTime = 0;
      } catch (eCt) {}
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
    function clearIfStillActive() {
      if (self._active === audio) {
        self._active = null;
        self._playingPriority = 0;
      }
    }
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch(function () {
        try {
          audio.load();
          audio.currentTime = 0;
          var p2 = audio.play();
          if (p2 && typeof p2.catch === 'function') {
            p2.catch(clearIfStillActive);
          }
        } catch (e2) {
          clearIfStillActive();
        }
      });
    }
  };

  global.MyPharmSoundManager = SoundManager;
})(typeof window !== 'undefined' ? window : this);
