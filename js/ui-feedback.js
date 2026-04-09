(function () {
    if (window.MyPharmUI) return;

    function escHtml(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function ensureLayer() {
        if (document.getElementById('mpUiLayer')) return;
        var root = document.createElement('div');
        root.id = 'mpUiLayer';
        root.className = 'mp-ui-layer';
        root.innerHTML = [
            '<div id="mpToastStack" class="mp-toast-stack" aria-live="polite" aria-atomic="false"></div>',
            '<div id="mpModalOverlay" class="mp-modal-overlay" aria-hidden="true">',
            '  <div class="mp-modal" role="dialog" aria-modal="true" aria-labelledby="mpModalTitle" aria-describedby="mpModalDesc">',
            '    <div class="mp-modal-head">',
            '      <div id="mpModalIcon" class="mp-modal-icon mp-info"><i class="fas fa-circle-info"></i></div>',
            '      <div>',
            '        <h3 id="mpModalTitle" class="mp-modal-title">Mensagem</h3>',
            '        <p id="mpModalDesc" class="mp-modal-description">-</p>',
            '      </div>',
            '    </div>',
            '    <div id="mpModalActions" class="mp-modal-actions"></div>',
            '  </div>',
            '</div>',
            '<div id="mpLoadingOverlay" class="mp-loading-overlay" aria-hidden="true">',
            '  <div class="mp-loading-box" role="status" aria-live="polite">',
            '    <div class="mp-spinner"></div>',
            '    <p id="mpLoadingMessage" class="mp-loading-message">Processando...</p>',
            '  </div>',
            '</div>'
        ].join('');
        document.body.appendChild(root);
    }

    function toast(type, message, opts) {
        ensureLayer();
        var stack = document.getElementById('mpToastStack');
        if (!stack) return;
        var cfg = opts || {};
        var ttl = Math.max(1200, parseInt(cfg.duration, 10) || 3800);
        var titles = {
            success: 'Sucesso',
            error: 'Erro',
            warning: 'Atenção',
            info: 'Informação'
        };
        var icons = {
            success: 'fa-circle-check',
            error: 'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            info: 'fa-circle-info'
        };
        var item = document.createElement('div');
        item.className = 'mp-toast mp-' + type;
        item.innerHTML = [
            '<div class="mp-toast-icon"><i class="fas ' + icons[type] + '"></i></div>',
            '<div class="mp-toast-content">',
            '  <h4 class="mp-toast-title">' + escHtml(cfg.title || titles[type]) + '</h4>',
            '  <p class="mp-toast-message">' + escHtml(message || '') + '</p>',
            '</div>',
            '<button type="button" class="mp-toast-close" aria-label="Fechar mensagem"><i class="fas fa-xmark"></i></button>'
        ].join('');
        var remove = function () {
            if (!item || !item.parentNode) return;
            item.parentNode.removeChild(item);
        };
        item.querySelector('.mp-toast-close').addEventListener('click', remove);
        stack.appendChild(item);
        window.setTimeout(remove, ttl);
    }

    function modal(opts) {
        ensureLayer();
        var overlay = document.getElementById('mpModalOverlay');
        var icon = document.getElementById('mpModalIcon');
        var title = document.getElementById('mpModalTitle');
        var desc = document.getElementById('mpModalDesc');
        var actions = document.getElementById('mpModalActions');
        if (!overlay || !icon || !title || !desc || !actions) return Promise.resolve(false);

        var cfg = opts || {};
        var kind = cfg.kind || 'info';
        var titleTxt = cfg.title || 'Mensagem';
        var descTxt = cfg.description || '';
        var cancelTxt = cfg.cancelText || 'Cancelar';
        var confirmTxt = cfg.confirmText || 'Confirmar';
        var iconMap = {
            success: 'fa-circle-check',
            error: 'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            info: 'fa-circle-info',
            danger: 'fa-triangle-exclamation'
        };
        var iconKind = kind === 'danger' ? 'danger' : kind;

        icon.className = 'mp-modal-icon mp-' + iconKind;
        icon.innerHTML = '<i class="fas ' + (iconMap[kind] || iconMap.info) + '"></i>';
        title.textContent = titleTxt;
        desc.textContent = descTxt;
        actions.innerHTML = '';

        var btnCancel = document.createElement('button');
        btnCancel.type = 'button';
        btnCancel.className = 'mp-btn mp-btn-neutral';
        btnCancel.textContent = cancelTxt;
        actions.appendChild(btnCancel);

        var btnConfirm = document.createElement('button');
        btnConfirm.type = 'button';
        btnConfirm.className = 'mp-btn ' + ((kind === 'danger' || cfg.destructive) ? 'mp-btn-danger' : 'mp-btn-primary');
        btnConfirm.textContent = confirmTxt;
        actions.appendChild(btnConfirm);

        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        setTimeout(function () { btnConfirm.focus(); }, 25);

        return new Promise(function (resolve) {
            var done = function (ok) {
                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
                document.removeEventListener('keydown', onKey);
                overlay.removeEventListener('click', onOverlay);
                resolve(!!ok);
            };
            var onKey = function (ev) {
                if (!overlay.classList.contains('is-open')) return;
                if (ev.key === 'Escape') {
                    ev.preventDefault();
                    done(false);
                } else if (ev.key === 'Enter') {
                    ev.preventDefault();
                    done(true);
                }
            };
            var onOverlay = function (ev) {
                if (!cfg.closeOnBackdrop) return;
                if (ev.target === overlay) done(false);
            };
            document.addEventListener('keydown', onKey);
            overlay.addEventListener('click', onOverlay);
            btnCancel.addEventListener('click', function () { done(false); });
            btnConfirm.addEventListener('click', function () { done(true); });
        });
    }

    function showLoading(message, opts) {
        ensureLayer();
        var overlay = document.getElementById('mpLoadingOverlay');
        var label = document.getElementById('mpLoadingMessage');
        if (!overlay || !label) return;
        var cfg = opts || {};
        label.textContent = String(message || 'Processando...');
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        overlay.style.pointerEvents = cfg.blockScreen === false ? 'none' : 'auto';
    }

    function hideLoading() {
        var overlay = document.getElementById('mpLoadingOverlay');
        if (!overlay) return;
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    window.MyPharmUI = {
        showSuccess: function (message, opts) { toast('success', message, opts); },
        showError: function (message, opts) { toast('error', message, opts); },
        showWarning: function (message, opts) { toast('warning', message, opts); },
        showInfo: function (message, opts) { toast('info', message, opts); },
        showConfirm: function (opts) {
            var cfg = opts || {};
            return modal({
                kind: cfg.kind || (cfg.destructive ? 'danger' : 'info'),
                title: cfg.title || 'Confirmar ação',
                description: cfg.message || cfg.description || 'Deseja continuar?',
                confirmText: cfg.confirmText || 'Confirmar',
                cancelText: cfg.cancelText || 'Cancelar',
                destructive: !!cfg.destructive,
                closeOnBackdrop: cfg.closeOnBackdrop !== false
            });
        },
        showLoading: showLoading,
        hideLoading: hideLoading
    };

    window.showSuccess = function (message, opts) { return window.MyPharmUI.showSuccess(message, opts); };
    window.showError = function (message, opts) { return window.MyPharmUI.showError(message, opts); };
    window.showWarning = function (message, opts) { return window.MyPharmUI.showWarning(message, opts); };
    window.showInfo = function (message, opts) { return window.MyPharmUI.showInfo(message, opts); };
    window.showConfirm = function (opts) { return window.MyPharmUI.showConfirm(opts); };
    window.showLoading = function (message, opts) { return window.MyPharmUI.showLoading(message, opts); };
    window.hideLoading = function () { return window.MyPharmUI.hideLoading(); };
})();
