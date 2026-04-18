(function () {
    'use strict';

    const API = 'api_clientes.php';
    const CLI_MAIN_API = 'api.php';
    const SIDEBAR_KEY  = 'mypharm_sidebar_collapsed';
    const THEME_KEY    = 'mypharm_theme';
    const SECTION_KEY  = 'cli_active_section';

    var __cliCsrfToken = '';
    var __cliNotificacoesApi = [];
    var __cliMensagensApi = [];
    var __cliMensagensRecebidasApi = [];
    var _cliCurrentSection = 'visao-geral';

    var CLI_LOADING_TEXT_DEFAULT = 'Carregando dados...';

    /** Overlay full-screen igual ao index (loadingOverlay + styles.css). */
    function cliShowPageLoading(message) {
        var el = document.getElementById('loadingOverlay');
        if (!el) return;
        var txt = el.querySelector('.loading-text');
        if (txt && message) txt.textContent = message;
        el.style.display = 'flex';
        el.setAttribute('aria-busy', 'true');
    }

    function cliHidePageLoading() {
        var el = document.getElementById('loadingOverlay');
        if (!el) return;
        el.style.display = 'none';
        el.setAttribute('aria-busy', 'false');
        var txt = el.querySelector('.loading-text');
        if (txt) txt.textContent = CLI_LOADING_TEXT_DEFAULT;
    }

    function parseJsonCli(text) {
        if (!text || typeof text !== 'string') return null;
        const t = text.trim();
        if (!t) return null;
        try { return JSON.parse(t); } catch (e) { return null; }
    }

    function clearLocalStoragePreservingMyPharmTheme() {
        const backup = {};
        for (let i = localStorage.length - 1; i >= 0; i--) {
            const k = localStorage.key(i);
            if (k && (k === 'mypharm_theme' || k.indexOf('mypharm_theme_') === 0)) {
                backup[k] = localStorage.getItem(k);
            }
        }
        localStorage.clear();
        Object.keys(backup).forEach(function (key) { localStorage.setItem(key, backup[key]); });
    }

    function getThemeStorageKeyCli() {
        const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
        return userName ? 'mypharm_theme_' + userName : 'mypharm_theme';
    }

    async function cliMainApiGet(action, params) {
        const q = new URLSearchParams({ action: action });
        if (params) {
            Object.keys(params).forEach(function (k) {
                if (params[k] !== undefined && params[k] !== null && params[k] !== '') q.set(k, params[k]);
            });
        }
        const response = await fetch(CLI_MAIN_API + '?' + q.toString(), { credentials: 'include', cache: 'no-store' });
        const text = await response.text();
        const data = parseJsonCli(text);
        if (response.status === 401) {
            clearLocalStoragePreservingMyPharmTheme();
            window.location.href = 'index.html';
            return null;
        }
        return data;
    }

    async function cliMainApiPost(action, data) {
        const headers = { 'Content-Type': 'application/json' };
        if (__cliCsrfToken) headers['X-CSRF-Token'] = __cliCsrfToken;
        const response = await fetch(CLI_MAIN_API + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(data || {}),
            credentials: 'include'
        });
        const text = await response.text();
        const parsed = parseJsonCli(text);
        if (response.status === 401) {
            clearLocalStoragePreservingMyPharmTheme();
            window.location.href = 'index.html';
            return null;
        }
        return parsed;
    }

    async function fetchCliCsrf() {
        try {
            const d = await cliMainApiGet('csrf_token', {});
            if (d && d.token) __cliCsrfToken = d.token;
        } catch (_) {}
    }

    function applyAvatarCli(initial, fotoUrl) {
        const av = document.getElementById('userAvatar');
        const img = document.getElementById('userAvatarImg');
        if (!av) return;
        if (img) {
            if (fotoUrl) {
                img.src = CLI_MAIN_API + '?action=get_foto_perfil&t=' + Date.now();
                img.alt = 'Foto de perfil';
                img.style.display = 'block';
                av.style.display = 'none';
                img.onerror = function () {
                    img.style.display = 'none';
                    av.style.display = '';
                    av.textContent = (initial || 'A').charAt(0).toUpperCase();
                    img.src = '';
                    localStorage.removeItem('foto_perfil');
                };
            } else {
                img.src = '';
                img.onerror = null;
                img.style.display = 'none';
                av.style.display = '';
                av.textContent = (initial || 'A').charAt(0).toUpperCase();
            }
        } else {
            av.textContent = (initial || 'A').charAt(0).toUpperCase();
        }
    }

    function setupAvatarUploadCli() {
        const wrap = document.getElementById('avatarWrap');
        const input = document.getElementById('inputFotoPerfil');
        if (!wrap || !input) return;
        wrap.onclick = function () { input.click(); };
        input.onchange = async function () {
            const file = input.files && input.files[0];
            if (!file || !file.type.match(/^image\//)) return;
            if (file.size > 3 * 1024 * 1024) {
                alert('Escolha uma foto de até 3 MB.');
                input.value = '';
                return;
            }
            const formData = new FormData();
            formData.append('foto', file);
            try {
                const response = await fetch(CLI_MAIN_API + '?action=upload_foto_perfil', {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                });
                const text = await response.text();
                var res = null;
                try { res = text ? JSON.parse(text) : null; } catch (_) {}
                if (res && res.success && res.foto_perfil) {
                    localStorage.setItem('foto_perfil', res.foto_perfil);
                    var nome = (document.getElementById('userName') || {}).textContent || 'A';
                    applyAvatarCli(nome.charAt(0), res.foto_perfil);
                } else {
                    alert((res && res.error) ? res.error : 'Não foi possível alterar a foto.');
                }
            } catch (e) {
                alert('Erro ao enviar a foto. Verifique a conexão.');
            }
            input.value = '';
        };
    }

    function getNotificationsListKeyCli() {
        return 'mypharm_notif_list_admin_' + (localStorage.getItem('userName') || '').replace(/\s+/g, '_');
    }
    function getStoredNotificationsListCli() {
        try {
            const raw = localStorage.getItem(getNotificationsListKeyCli());
            return raw ? JSON.parse(raw) : [];
        } catch (e) { return []; }
    }
    function getNotificationsReadKeyCli() {
        return 'mypharm_notif_read_admin_' + (localStorage.getItem('userName') || '').replace(/\s+/g, '_');
    }
    function getReadNotificationsCli() {
        try {
            const raw = localStorage.getItem(getNotificationsReadKeyCli());
            return raw ? JSON.parse(raw) : [];
        } catch (e) { return []; }
    }
    function markNotificationReadCli(id) {
        const read = getReadNotificationsCli();
        if (read.indexOf(id) === -1) read.push(id);
        try { localStorage.setItem(getNotificationsReadKeyCli(), JSON.stringify(read)); } catch (e) {}
        updateNotificationsBadgeCli();
    }
    function deleteNotificationCli(id) {
        const list = getStoredNotificationsListCli().filter(function (v) { return v.id !== id; });
        try { localStorage.setItem(getNotificationsListKeyCli(), JSON.stringify(list)); } catch (e) {}
        renderAllNotificationsCli();
        updateNotificationsBadgeCli();
    }
    function escNotifCli(x) {
        return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function renderAllNotificationsCli() {
        const listEl = document.getElementById('notificationsListAll');
        const emptyEl = document.getElementById('notificationsEmptyAll');
        if (!listEl) return;
        const agendaList = getStoredNotificationsListCli();
        const read = getReadNotificationsCli();
        var itemsHtml = '';
        (__cliNotificacoesApi || []).forEach(function (n) {
            var isRead = !!n.lida;
            var tipoLabel = n.tipo === '30_dias_sem_visita' ? '30 dias sem visita' : (n.tipo === 'dias_sem_compra' ? (n.dias_sem_compra || 15) + ' dias sem compra' : 'Sistema');
            itemsHtml += '<div class="notification-item sistema ' + (isRead ? 'read' : '') + '" data-origin="sistema" data-notif-id="' + escNotifCli(n.id) + '" data-prescritor="' + escNotifCli(n.prescritor_nome) + '">' +
                '<div class="notification-item-main"><div class="notification-sender">' + escNotifCli(tipoLabel) + '</div>' +
                '<div class="notification-title">' + escNotifCli(n.prescritor_nome || '-') + '</div>' +
                '<div class="notification-date">' + escNotifCli(n.mensagem || '') + '</div></div>' +
                '<div class="notification-item-actions"><button type="button" class="notification-btn-lida" data-id="' + n.id + '" title="Marcar como lida"><i class="fas fa-check"></i></button>' +
                '<button type="button" class="notification-item-delete" data-origin="sistema" data-id="' + n.id + '" title="Apagar"><i class="fas fa-trash-alt"></i></button></div></div>';
        });
        (__cliMensagensApi || []).forEach(function (m) {
            var dataFmt = (m.criado_em || '').slice(0, 16).replace('T', ' ');
            itemsHtml += '<div class="notification-item usuario" data-origin="usuario" data-prescritor="' + escNotifCli(m.prescritor_nome) + '">' +
                '<div class="notification-item-main"><div class="notification-sender">' + escNotifCli(m.autor || 'Colega') + '</div>' +
                '<div class="notification-title">' + escNotifCli(m.prescritor_nome) + '</div>' +
                '<div class="notification-date">' + escNotifCli(m.mensagem) + (dataFmt ? ' · ' + dataFmt : '') + '</div></div></div>';
        });
        (__cliMensagensRecebidasApi || []).forEach(function (m) {
            var dataFmt = (m.criado_em || '').slice(0, 16).replace('T', ' ');
            var isRead = !!m.lida;
            itemsHtml += '<div class="notification-item msg-usuario ' + (isRead ? 'read' : '') + '" data-origin="msg_usuario" data-msg-id="' + m.id + '">' +
                '<div class="notification-item-main"><div class="notification-sender">' + escNotifCli(m.autor || 'Usuário') + '</div>' +
                '<div class="notification-title">Mensagem para você</div>' +
                '<div class="notification-date">' + escNotifCli(m.mensagem) + (dataFmt ? ' · ' + dataFmt : '') + '</div></div>' +
                '<div class="notification-item-actions">' +
                '<button type="button" class="notification-btn-lida-msg" data-id="' + m.id + '" title="Marcar como lida"><i class="fas fa-check"></i></button>' +
                '<button type="button" class="notification-btn-ocultar-msg" data-id="' + m.id + '" title="Excluir da lista"><i class="fas fa-trash-alt"></i></button></div></div>';
        });
        agendaList.forEach(function (v) {
            var isRead = read.indexOf(v.id) !== -1;
            var dataFmt = v.data_agendada ? (String(v.data_agendada).trim().length >= 10 ? String(v.data_agendada).slice(8, 10) + '/' + String(v.data_agendada).slice(5, 7) + '/' + String(v.data_agendada).slice(0, 4) : '') : '';
            var sub = (dataFmt + ((v.hora || '').trim() ? ' às ' + (v.hora || '').trim() : '')) || 'Hoje';
            itemsHtml += '<div class="notification-item visita ' + (isRead ? 'read' : '') + '" data-origin="agenda" data-id="' + escNotifCli(v.id) + '" data-prescritor="' + escNotifCli(v.prescritor) + '">' +
                '<div class="notification-item-main"><div class="notification-sender">Agenda</div>' +
                '<div class="notification-title">' + escNotifCli(v.prescritor || '-') + '</div>' +
                '<div class="notification-date">' + escNotifCli(sub) + '</div></div>' +
                '<button type="button" class="notification-item-delete" data-origin="agenda" data-id="' + escNotifCli(v.id) + '" title="Apagar"><i class="fas fa-trash-alt"></i></button></div>';
        });
        if (emptyEl) emptyEl.style.display = itemsHtml ? 'none' : 'block';
        if (itemsHtml) {
            var wrap = listEl.querySelector('.notifications-items-wrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.className = 'notifications-items-wrap';
                listEl.insertBefore(wrap, emptyEl);
            }
            wrap.innerHTML = itemsHtml;
        } else {
            var w = listEl.querySelector('.notifications-items-wrap');
            if (w) w.innerHTML = '';
        }
    }

    function updateNotificationsBadgeCli() {
        var notifs = __cliNotificacoesApi || [];
        var msgs = __cliMensagensApi || [];
        var msgRec = __cliMensagensRecebidasApi || [];
        var list = getStoredNotificationsListCli();
        var read = getReadNotificationsCli();
        var unread = notifs.filter(function (n) { return !n.lida; }).length + msgs.length + msgRec.filter(function (m) { return !m.lida; }).length + list.filter(function (v) { return read.indexOf(v.id) === -1; }).length;
        var badge = document.getElementById('notificationsBadge');
        if (!badge) return;
        badge.textContent = unread > 99 ? '99+' : String(unread);
        badge.style.display = unread > 0 ? 'inline-flex' : 'none';
    }

    async function loadNotificacoesFromAPICli() {
        try {
            var r = await cliMainApiGet('list_notificacoes', {});
            if (r && r.success) {
                __cliNotificacoesApi = r.notificacoes || [];
                __cliMensagensApi = r.mensagens || [];
                __cliMensagensRecebidasApi = r.mensagens_recebidas || [];
            }
        } catch (e) {
            __cliNotificacoesApi = [];
            __cliMensagensApi = [];
            __cliMensagensRecebidasApi = [];
        }
        renderAllNotificationsCli();
        updateNotificationsBadgeCli();
    }

    async function loadUsuariosParaMensagemCli() {
        var sel = document.getElementById('notifParaUsuarioSelect');
        if (!sel) return;
        sel.innerHTML = '<option value="">Carregando...</option>';
        sel.disabled = true;
        try {
            var r = await cliMainApiGet('list_usuarios_para_mensagem', {});
            sel.disabled = false;
            sel.innerHTML = '<option value="">Selecione o usuário</option>';
            if (r && r.success && Array.isArray(r.usuarios)) {
                r.usuarios.forEach(function (u) {
                    sel.appendChild(new Option(u.nome || ('Usuário ' + u.id), String(u.id)));
                });
            }
        } catch (e) {
            sel.disabled = false;
            sel.innerHTML = '<option value="">Erro ao carregar</option>';
        }
    }

    function closeNotificationsOnClickOutsideCli(e) {
        var panel = document.getElementById('notificationsPanel');
        var btn = document.getElementById('btnNotifications');
        var backdrop = document.getElementById('notificationsBackdrop');
        if (panel && btn && !panel.contains(e.target) && !btn.contains(e.target) && !(backdrop && backdrop.contains(e.target))) {
            closeNotificationsPanelCli();
        }
    }

    function closeNotificationsPanelCli() {
        var panel = document.getElementById('notificationsPanel');
        var backdrop = document.getElementById('notificationsBackdrop');
        if (panel) panel.classList.remove('open');
        if (backdrop) backdrop.classList.remove('open');
        document.removeEventListener('click', closeNotificationsOnClickOutsideCli);
    }

    function toggleNotificationsPanelCli(ev) {
        if (ev) ev.stopPropagation();
        var panel = document.getElementById('notificationsPanel');
        var btn = document.getElementById('btnNotifications');
        var backdrop = document.getElementById('notificationsBackdrop');
        if (!panel || !btn) return;
        var isOpen = panel.classList.toggle('open');
        var isMobileOrTablet = window.innerWidth <= 1024;
        if (backdrop) {
            if (isOpen && isMobileOrTablet) backdrop.classList.add('open');
            else backdrop.classList.remove('open');
        }
        if (isOpen) {
            Promise.all([loadNotificacoesFromAPICli(), loadUsuariosParaMensagemCli()]).catch(function () {});
            setTimeout(function () { document.addEventListener('click', closeNotificationsOnClickOutsideCli); }, 0);
        } else {
            document.removeEventListener('click', closeNotificationsOnClickOutsideCli);
        }
    }

    async function enviarMensagemUsuarioUICli() {
        var sel = document.getElementById('notifParaUsuarioSelect');
        var msg = (document.getElementById('notifMensagemUsuarioInput') || {}).value;
        var paraId = sel ? parseInt(sel.value, 10) : 0;
        if (!paraId || !msg) {
            alert('Selecione o usuário e escreva a mensagem.');
            return;
        }
        var btn = document.getElementById('btnEnviarMsgUsuario');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }
        try {
            var r = await cliMainApiPost('enviar_mensagem_usuario', { para_usuario_id: paraId, mensagem: msg.trim() });
            if (r && r.success) {
                if (document.getElementById('notifMensagemUsuarioInput')) document.getElementById('notifMensagemUsuarioInput').value = '';
                loadNotificacoesFromAPICli();
            } else alert((r && r.error) ? r.error : 'Erro ao enviar.');
        } catch (e) { alert('Erro de conexão.'); }
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar para usuário'; }
    }

    function initAdminNotificationsCli() {
        var panel = document.getElementById('notificationsPanel');
        if (!panel || panel.dataset.notificationsBoundCli === '1') return;
        panel.dataset.notificationsBoundCli = '1';
        var btnNotif = document.getElementById('btnNotifications');
        if (btnNotif) btnNotif.addEventListener('click', toggleNotificationsPanelCli);
        var closeHdr = panel.querySelector('.notifications-panel-close');
        if (closeHdr) closeHdr.addEventListener('click', function (e) { e.preventDefault(); closeNotificationsPanelCli(); });
        var bd = document.getElementById('notificationsBackdrop');
        if (bd) bd.addEventListener('click', closeNotificationsPanelCli);
        var sendBtn = document.getElementById('btnEnviarMsgUsuario');
        if (sendBtn) sendBtn.addEventListener('click', function () { enviarMensagemUsuarioUICli(); });
        panel.addEventListener('click', function (e) {
            var btnLida = e.target.closest('.notification-btn-lida');
            if (btnLida && btnLida.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                cliMainApiPost('marcar_notificacao_lida', { id: parseInt(btnLida.dataset.id, 10) }).then(function () { loadNotificacoesFromAPICli(); });
                return;
            }
            var btnLidaMsg = e.target.closest('.notification-btn-lida-msg');
            if (btnLidaMsg && btnLidaMsg.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                cliMainApiPost('marcar_mensagem_usuario_lida', { id: parseInt(btnLidaMsg.dataset.id, 10) }).then(function () { loadNotificacoesFromAPICli(); });
                return;
            }
            var btnOcultarMsg = e.target.closest('.notification-btn-ocultar-msg');
            if (btnOcultarMsg && btnOcultarMsg.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                cliMainApiPost('ocultar_mensagem_usuario', { id: parseInt(btnOcultarMsg.dataset.id, 10) }).then(function () { loadNotificacoesFromAPICli(); });
                return;
            }
            var btnDelete = e.target.closest('.notification-item-delete');
            if (btnDelete && btnDelete.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                if (btnDelete.dataset.origin === 'sistema') {
                    cliMainApiPost('apagar_notificacao', { id: parseInt(btnDelete.dataset.id, 10) }).then(function () { loadNotificacoesFromAPICli(); });
                } else {
                    deleteNotificationCli(btnDelete.dataset.id);
                }
                return;
            }
            var item = e.target.closest('.notification-item');
            if (item && item.dataset.prescritor) {
                if (item.dataset.origin === 'agenda' && item.dataset.id) markNotificationReadCli(item.dataset.id);
                item.classList.add('read');
            }
        });
    }

    function closeMobileSidebarCli() {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebarOverlay');
        var btn = document.getElementById('mobileMenuBtn');
        if (!sidebar || !overlay || !btn) return;
        sidebar.classList.remove('open');
        overlay.classList.remove('visible');
        var ic = btn.querySelector('i');
        if (ic) ic.className = 'fas fa-bars';
    }

    function toggleMobileSidebarCli() {
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebarOverlay');
        var btn = document.getElementById('mobileMenuBtn');
        if (!sidebar || !overlay || !btn) return;
        sidebar.classList.toggle('open');
        overlay.classList.toggle('visible', sidebar.classList.contains('open'));
        var ic = btn.querySelector('i');
        if (ic) ic.className = sidebar.classList.contains('open') ? 'fas fa-times' : 'fas fa-bars';
    }

    function hideSidebarFlyoutTooltipCli() {
        var tip = document.getElementById('sidebarFlyoutTooltip');
        if (tip) {
            tip.classList.remove('is-visible');
            tip.textContent = '';
            tip.style.left = '';
            tip.style.top = '';
        }
    }
    function ensureSidebarFlyoutTooltipElCli() {
        var tip = document.getElementById('sidebarFlyoutTooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'sidebarFlyoutTooltip';
            tip.className = 'sidebar-flyout-tooltip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        return tip;
    }
    function showSidebarFlyoutTooltipCli(text, anchorEl) {
        if (!text || !anchorEl) {
            hideSidebarFlyoutTooltipCli();
            return;
        }
        var tip = ensureSidebarFlyoutTooltipElCli();
        tip.textContent = text;
        tip.classList.add('is-visible');
        tip.style.left = '-9999px';
        tip.style.top = '0';
        var tw = tip.offsetWidth;
        var th = tip.offsetHeight;
        var r = anchorEl.getBoundingClientRect();
        var margin = 10;
        var left = r.right + margin;
        var top = r.top + (r.height - th) / 2;
        if (left + tw > window.innerWidth - 8) {
            left = Math.max(8, r.left - tw - margin);
        }
        top = Math.max(8, Math.min(top, window.innerHeight - th - 8));
        tip.style.left = Math.round(left) + 'px';
        tip.style.top = Math.round(top) + 'px';
    }
    function initSidebarCollapsedLegendsCli() {
        var sidebar = document.getElementById('sidebar');
        if (!sidebar || sidebar.dataset.cliFlyoutBound === '1') return;
        sidebar.dataset.cliFlyoutBound = '1';
        sidebar.querySelectorAll('.nav-item').forEach(function (el) {
            var span = el.querySelector('span');
            var label = span ? span.textContent.trim() : '';
            if (label && !el.getAttribute('title')) el.setAttribute('title', label);
        });
        document.addEventListener(
            'mouseover',
            function (e) {
                var sb = document.getElementById('sidebar');
                if (!sb || !sb.classList.contains('collapsed')) {
                    hideSidebarFlyoutTooltipCli();
                    return;
                }
                if (!e.target.closest('#sidebar')) {
                    hideSidebarFlyoutTooltipCli();
                    return;
                }
                var brand = e.target.closest('.sidebar-header .sidebar-brand');
                if (brand && sb.contains(brand)) {
                    showSidebarFlyoutTooltipCli('MyPharm', brand);
                    return;
                }
                var navEl = e.target.closest('.sidebar .nav-item');
                if (navEl && sb.contains(navEl)) {
                    var sp = navEl.querySelector('span');
                    var lab = sp ? sp.textContent.trim() : '';
                    if (lab) {
                        showSidebarFlyoutTooltipCli(lab, navEl);
                        return;
                    }
                }
                var userRow = e.target.closest('.sidebar-footer .user-info');
                if (userRow && sb.contains(userRow)) {
                    var n = String((document.getElementById('userName') || {}).textContent || '').trim();
                    var rol = String((document.getElementById('userRole') || {}).textContent || '').trim();
                    var t = (n && rol) ? (n + ' · ' + rol) : (n || rol);
                    if (t) {
                        showSidebarFlyoutTooltipCli(t, userRow);
                        return;
                    }
                }
                hideSidebarFlyoutTooltipCli();
            },
            true
        );
        sidebar.addEventListener('focusin', function (e) {
            if (!sidebar.classList.contains('collapsed')) return;
            var a = e.target.closest('.nav-item');
            if (!a) return;
            var sp = a.querySelector('span');
            var label = sp ? sp.textContent.trim() : '';
            if (label) showSidebarFlyoutTooltipCli(label, a);
        });
        sidebar.addEventListener('focusout', function () {
            setTimeout(function () {
                if (!sidebar.contains(document.activeElement)) hideSidebarFlyoutTooltipCli();
            }, 0);
        });
    }

    // ── Charts armazenados para destroy antes de recriar ─────────────────────
    const _charts = {};

    // ── Helpers ───────────────────────────────────────────────────────────────
    function fmt(n) { return Number(n || 0).toLocaleString('pt-BR'); }
    function pct(v, t) { return t > 0 ? (v / t * 100).toFixed(1) + '%' : '—'; }
    function setText(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }
    function setKpiMeter(barId, numer, denom) {
        const el = document.getElementById(barId);
        if (!el) return;
        const n = Number(numer || 0);
        const d = Number(denom || 0);
        const p = d > 0 ? Math.min(100, (n / d) * 100) : 0;
        el.style.width = p.toFixed(2) + '%';
    }

    function mkChart(id, cfg) {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        if (_charts[id]) { _charts[id].destroy(); delete _charts[id]; }
        _charts[id] = new Chart(canvas, cfg);
    }

    const PALETTE = [
        '#E63946','#457B9D','#2A9D8F','#E9C46A','#F4A261','#264653',
        '#A8DADC','#6D6875','#B5838D','#E07A5F','#3D405B','#81B29A',
    ];

    /** Mapa de calor: Brasil (UF) → municípios IBGE (por UF) → gráfico por bairro */
    var _cliUfMap = null;
    var _cliUfGeoLayer = null;
    var _cliBrGeoJsonPromise = null;
    var _cliLastPorUfForMap = null;
    var _cliMapaCtx = { porUf: [], uf: '', municipio: '', bairro: '' };
    var __cliGeoRunId = 0;
    var _cliIbgeMunGeoCache = {};
    var _cliIbgeMunListCache = {};
    var _cliMapaFiltrosInit = false;
    var CLI_BR_UF_GEOJSON = 'https://cdn.jsdelivr.net/gh/codeforamerica/click_that_hood@master/public/data/brazil-states.geojson';

    function normMunKeyCli(s) {
        return String(s || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function corMapaCalorCli(valor, maxVal) {
        if (!maxVal || maxVal <= 0 || !valor || valor <= 0) return '#e2e8f0';
        var t = Math.min(1, Math.max(0, valor / maxVal));
        var start = [255, 247, 237];
        var mid = [251, 146, 60];
        var end = [153, 27, 27];
        var a, b, ratio;
        if (t < 0.5) {
            ratio = t * 2;
            a = start;
            b = mid;
        } else {
            ratio = (t - 0.5) * 2;
            a = mid;
            b = end;
        }
        var r = Math.round(a[0] + (b[0] - a[0]) * ratio);
        var g = Math.round(a[1] + (b[1] - a[1]) * ratio);
        var bl = Math.round(a[2] + (b[2] - a[2]) * ratio);
        return 'rgb(' + r + ',' + g + ',' + bl + ')';
    }

    function setCliMapaHint(t) {
        var h = document.getElementById('cliMapaProHint');
        if (h) h.textContent = t || '';
    }

    function destroyCliUfMap() {
        __cliGeoRunId++;
        if (_cliUfMap) {
            try { _cliUfMap.remove(); } catch (e) { /* ignore */ }
            _cliUfMap = null;
            _cliUfGeoLayer = null;
        }
        if (_charts.cliChartUf) {
            try { _charts.cliChartUf.destroy(); } catch (e2) { /* ignore */ }
            delete _charts.cliChartUf;
        }
        if (_charts.cliChartBairrosMapa) {
            try { _charts.cliChartBairrosMapa.destroy(); } catch (e3) { /* ignore */ }
            delete _charts.cliChartBairrosMapa;
        }
    }

    function fetchBrGeoJsonCli() {
        if (_cliBrGeoJsonPromise) return _cliBrGeoJsonPromise;
        _cliBrGeoJsonPromise = fetch(CLI_BR_UF_GEOJSON, { cache: 'force-cache', credentials: 'omit' })
            .then(function (r) {
                if (!r.ok) throw new Error('geo');
                return r.json();
            })
            .catch(function () {
                _cliBrGeoJsonPromise = null;
                throw new Error('geo');
            });
        return _cliBrGeoJsonPromise;
    }

    function fetchIbgeMalhaMunicipiosCli(uf) {
        var u = String(uf || '').toUpperCase();
        if (!/^[A-Z]{2}$/.test(u)) return Promise.reject(new Error('uf'));
        if (_cliIbgeMunGeoCache[u]) return Promise.resolve(_cliIbgeMunGeoCache[u]);
        var url = 'https://servicodados.ibge.gov.br/api/v3/malhas/estados/' + encodeURIComponent(u) + '?intrarregiao=municipio&formato=application/vnd.geo+json';
        return fetch(url, { cache: 'force-cache', credentials: 'omit' }).then(function (r) {
            if (!r.ok) throw new Error('ibge-malha');
            return r.json();
        }).then(function (g) {
            _cliIbgeMunGeoCache[u] = g;
            return g;
        });
    }

    function fetchIbgeListaMunicipiosCli(uf) {
        var u = String(uf || '').toUpperCase();
        if (!/^[A-Z]{2}$/.test(u)) return Promise.reject(new Error('uf'));
        if (_cliIbgeMunListCache[u]) return Promise.resolve(_cliIbgeMunListCache[u]);
        var url = 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + encodeURIComponent(u) + '/municipios';
        return fetch(url, { cache: 'force-cache', credentials: 'omit' }).then(function (r) {
            if (!r.ok) throw new Error('ibge-lista');
            return r.json();
        }).then(function (list) {
            _cliIbgeMunListCache[u] = list;
            return list;
        });
    }

    /**
     * Calor + círculos por bairro dentro dos limites do município (malha IBGE).
     * Posições em grade ordenada por volume (maior à esquerda/topo): não há lat/lng por bairro na base.
     */
    function __addCliMunicipioBairrosHeat(map, bounds, rows, munLabel, bairroSel, dark) {
        if (!map || !bounds || !rows || !rows.length) return;
        var sw = bounds.getSouthWest();
        var ne = bounds.getNorthEast();
        var dLat = ne.lat - sw.lat;
        var dLng = ne.lng - sw.lng;
        if (!(dLat > 0 && dLng > 0)) return;

        var sorted = rows.slice().sort(function (a, b) {
            return Number(b.total || 0) - Number(a.total || 0);
        });
        var n = sorted.length;
        var cols = Math.max(1, Math.ceil(Math.sqrt(n)));
        var rowsG = Math.max(1, Math.ceil(n / cols));
        var maxT = 0;
        sorted.forEach(function (r) {
            maxT = Math.max(maxT, Number(r.total) || 0);
        });
        if (maxT <= 0) maxT = 1;

        var pad = 0.07;
        var span = 1 - 2 * pad;
        var heatPts = [];
        var lg = L.layerGroup();

        sorted.forEach(function (r, i) {
            var nome = String(r.bairro != null ? r.bairro : 'N/D').trim() || 'N/D';
            var t = Number(r.total) || 0;
            var row = Math.floor(i / cols);
            var col = i % cols;
            var u = (col + 0.5) / cols;
            var v = (row + 0.5) / rowsG;
            var lat = sw.lat + dLat * (pad + (1 - v) * span);
            var lng = sw.lng + dLng * (pad + u * span);
            heatPts.push([lat, lng, t]);
        });

        if (typeof L.heatLayer === 'function' && heatPts.length) {
            lg.addLayer(L.heatLayer(heatPts, {
                radius: 42,
                blur: 36,
                minOpacity: 0.32,
                maxZoom: 18,
                max: maxT * 1.08,
                gradient: { 0.25: '#fff7ed', 0.5: '#fb923c', 0.72: '#c2410c', 0.9: '#7f1d1d' },
            }));
        }

        sorted.forEach(function (r, i) {
            var nome = String(r.bairro != null ? r.bairro : 'N/D').trim() || 'N/D';
            var t = Number(r.total) || 0;
            var row = Math.floor(i / cols);
            var col = i % cols;
            var u = (col + 0.5) / cols;
            var v = (row + 0.5) / rowsG;
            var lat = sw.lat + dLat * (pad + (1 - v) * span);
            var lng = sw.lng + dLng * (pad + u * span);
            var isSel = !!(bairroSel && normMunKeyCli(nome) === normMunKeyCli(bairroSel));
            var rad = 5 + Math.min(24, Math.pow(Math.max(t, 1), 0.52) * 2);
            var fill = corMapaCalorCli(t, maxT);
            var c = L.circleMarker([lat, lng], {
                radius: isSel ? rad + 4 : rad,
                color: isSel ? '#E63946' : (dark ? '#e2e8f0' : '#fff'),
                weight: isSel ? 3 : 1.2,
                fillColor: fill,
                fillOpacity: 0.88,
            });
            c.bindTooltip('<strong>' + esc(nome) + '</strong><br>' + fmt(t) + ' cliente(s)', { direction: 'top', className: 'cli-mapa-calor-tooltip' });
            c.bindPopup(
                '<div class="cli-mapa-bairro-popup" style="min-width:200px;font-size:0.86rem;line-height:1.35;">' +
                '<strong>' + esc(nome) + '</strong><br>' + fmt(t) + ' cliente(s)' +
                '<div style="margin-top:8px;color:#64748b;font-size:0.76rem;">Posição ilustrativa dentro de <strong>' + esc(munLabel) + '</strong> (ordenado por volume; não é o endereço real do bairro).</div></div>'
            );
            lg.addLayer(c);
        });

        lg.addTo(map);

        var top = sorted[0];
        if (top) {
            setCliMapaHint(
                'Mapa de ' + String(munLabel || 'município') + ': calor por bairro. Maior volume agora: «' + String(top.bairro || 'N/D') + '» (' + fmt(Number(top.total) || 0) + ' clientes). Passe o mouse nos círculos ou abra o popup.'
            );
        }
    }

    /** Um ponto por cadastro (endereço). Geocódigo Nominatim (OSM) com limite por abertura; persiste lat/lng na API. */
    function __addCliEnderecosClienteMapa(map, munBounds, ufSel, munSel, bairroSel, dark, brAgregado) {
        var runId = __cliGeoRunId;
        if (!map) return;
        if (!munBounds) {
            try {
                if (_cliUfGeoLayer && _cliUfGeoLayer.getBounds) munBounds = _cliUfGeoLayer.getBounds();
            } catch (e0) { /* ignore */ }
        }
        if (!munBounds) return;

        var cluster = typeof L.markerClusterGroup === 'function'
            ? L.markerClusterGroup({
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: false,
                maxClusterRadius: 52,
                zoomToBoundsOnClick: true,
            })
            : L.layerGroup();

        var boundsPts = [];

        function addrLine(row) {
            var p = [row.logradouro, row.numero].filter(function (x) { return x && String(x).trim(); }).join(', ');
            var tail = [row.bairro, munSel, ufSel].filter(function (x) { return x && String(x).trim(); }).join(' — ');
            return (p ? p + ' · ' : '') + tail + (row.cep ? ' · CEP ' + String(row.cep) : '');
        }

        function addPin(row, lat, lng, aprox) {
            if (runId !== __cliGeoRunId || !map) return;
            var m = L.circleMarker([lat, lng], {
                radius: aprox ? 4 : 6,
                weight: 1.5,
                color: aprox ? '#64748b' : '#fff',
                fillColor: aprox ? '#94a3b8' : '#E63946',
                fillOpacity: 0.9,
            });
            m.bindTooltip(esc(String(row.nome || 'Cliente').slice(0, 80)), { direction: 'top', className: 'cli-mapa-calor-tooltip' });
            m.bindPopup(
                '<div style="min-width:220px;font-size:0.84rem;line-height:1.35;">' +
                '<strong>' + esc(String(row.nome || '')) + '</strong><br>' + esc(addrLine(row)) +
                (aprox ? '<br><span style="color:#64748b;font-size:0.76rem;">Posição aproximada (sem geocódigo válido).</span>' : '') +
                '</div>'
            );
            cluster.addLayer(m);
            boundsPts.push(L.latLng(lat, lng));
        }

        function jitterLatLng(id) {
            var sw = munBounds.getSouthWest();
            var ne = munBounds.getNorthEast();
            var dLat = ne.lat - sw.lat;
            var dLng = ne.lng - sw.lng;
            var h = ((id * 1103515245 + 12345) >>> 0) % 1000000 / 1000000;
            var h2 = ((id * 2048101543 + 54321) >>> 0) % 1000000 / 1000000;
            var u = 0.1 + h * 0.8;
            var v = 0.1 + h2 * 0.8;
            return [sw.lat + dLat * v, sw.lng + dLng * u];
        }

        function fitPins() {
            if (runId !== __cliGeoRunId || !map || boundsPts.length === 0) return;
            try {
                map.fitBounds(L.latLngBounds(boundsPts).pad(0.1), { maxZoom: 17 });
            } catch (eF) { /* ignore */ }
        }

        var params = { action: 'pontos_municipio_mapa', uf: ufSel, municipio: munSel };
        if (bairroSel) params.bairro = bairroSel;

        apiFetch(params).then(function (lista) {
            if (runId !== __cliGeoRunId || !map) return;
            lista = Array.isArray(lista) ? lista : [];
            if (!lista.length) {
                if (brAgregado && brAgregado.length) {
                    __addCliMunicipioBairrosHeat(map, munBounds, brAgregado, munSel, bairroSel, dark);
                } else {
                    setCliMapaHint('Nenhum cadastro neste município para marcar endereços. Verifique o nome do município (igual ao da base).');
                }
                return;
            }

            var toGeo = [];
            lista.forEach(function (row) {
                var la = parseFloat(row.latitude);
                var ln = parseFloat(row.longitude);
                if (Number.isFinite(la) && Number.isFinite(ln) && la >= -35 && la <= 10 && ln >= -81 && ln <= -28) {
                    addPin(row, la, ln, false);
                } else {
                    toGeo.push(row);
                }
            });

            map.addLayer(cluster);
            fitPins();

            var maxNom = Math.min(80, toGeo.length);
            var ix = 0;

            function finishGeoMsg() {
                setCliMapaHint(
                    'Mapa do município: ' + fmt(boundsPts.length) + ' ponto(s) (um por cadastro).' +
                    (toGeo.length
                        ? ' Para quem não tinha lat/lng, usamos o Nominatim (OpenStreetMap), até ' + fmt(maxNom) + ' buscas por vez (~1 s entre cada), gravando no banco quando acha o endereço. O restante fica com posição aproximada na cidade — abra o mapa de novo para geocodificar mais.'
                        : ' Todos os pontos já tinham coordenadas salvas.')
                );
            }

            function nomStep() {
                if (runId !== __cliGeoRunId || !map) return;
                if (ix >= maxNom) {
                    var k;
                    for (k = maxNom; k < toGeo.length; k++) {
                        var jx = jitterLatLng(toGeo[k].id);
                        addPin(toGeo[k], jx[0], jx[1], true);
                    }
                    fitPins();
                    finishGeoMsg();
                    return;
                }
                var row = toGeo[ix];
                var parts = [row.logradouro, row.numero, row.bairro, munSel, ufSel, 'Brasil'].filter(function (x) { return x && String(x).trim(); });
                var q = parts.join(', ');
                ix++;
                if (!q || q.length < 6) {
                    var j0 = jitterLatLng(row.id);
                    addPin(row, j0[0], j0[1], true);
                    setTimeout(nomStep, 40);
                    return;
                }
                var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q);
                fetch(url, { credentials: 'omit', headers: { 'Accept-Language': 'pt-BR,pt;q=0.9' } })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (js) {
                        if (runId !== __cliGeoRunId || !map) return;
                        if (js && js[0] && js[0].lat != null && js[0].lon != null) {
                            var la2 = parseFloat(js[0].lat);
                            var ln2 = parseFloat(js[0].lon);
                            if (Number.isFinite(la2) && Number.isFinite(ln2) && la2 >= -35 && la2 <= 10 && ln2 >= -81 && ln2 <= -28) {
                                addPin(row, la2, ln2, false);
                                fetch(API + '?action=salvar_cliente_geo', {
                                    method: 'POST',
                                    credentials: 'include',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ id: row.id, lat: la2, lng: ln2 }),
                                }).catch(function () { /* ignore */ });
                                return;
                            }
                        }
                        var j1 = jitterLatLng(row.id);
                        addPin(row, j1[0], j1[1], true);
                    })
                    .catch(function () {
                        if (runId !== __cliGeoRunId || !map) return;
                        var j2 = jitterLatLng(row.id);
                        addPin(row, j2[0], j2[1], true);
                    })
                    .finally(function () {
                        setTimeout(nomStep, 1100);
                    });
            }

            if (toGeo.length && maxNom > 0) {
                setTimeout(nomStep, 500);
            } else {
                finishGeoMsg();
            }
        }).catch(function () {
            if (runId !== __cliGeoRunId) return;
            if (brAgregado && brAgregado.length) {
                __addCliMunicipioBairrosHeat(map, munBounds, brAgregado, munSel, bairroSel, dark);
            }
        });
    }

    function renderCliMapaBairrosChart(rows, munLabel, bairroSel) {
        var wrap = document.getElementById('cliMapaBairrosWrap');
        var sub = document.getElementById('cliMapaBairrosSub');
        if (!wrap) return;
        if (!rows || rows.length === 0) {
            wrap.style.display = 'none';
            return;
        }
        wrap.style.display = 'block';
        if (sub) sub.textContent = munLabel ? ('Município: ' + munLabel + (bairroSel ? ' · Bairro: ' + bairroSel : '')) : '';

        var labels = rows.map(function (r) { return r.bairro || 'N/D'; });
        var vals = rows.map(function (r) { return Number(r.total); });
        var colors = labels.map(function (lb) {
            return bairroSel && normMunKeyCli(lb) === normMunKeyCli(bairroSel) ? '#E63946' : '#457B9D';
        });

        mkChart('cliChartBairrosMapa', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: 'Clientes', data: vals, backgroundColor: colors, borderRadius: 4, datalabels: { display: false } }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    datalabels: { display: false },
                },
                scales: {
                    x: { beginAtZero: true, ticks: { callback: function (v) { return fmt(v); } } },
                    y: { ticks: { font: { size: 10 } } },
                },
            },
        });
    }

    /** Lê filtros do DOM e redesenha o mapa */
    function renderCliMapaCalorPro(ctx) {
        _cliMapaCtx = {
            porUf: ctx.porUf || _cliLastPorUfForMap || [],
            uf: String(ctx.uf || '').toUpperCase().trim(),
            municipio: String(ctx.municipio || '').trim(),
            bairro: String(ctx.bairro || '').trim(),
        };
        var el = document.getElementById('cliMapaCalorUf');
        if (!el) return;
        if (typeof L === 'undefined' || typeof L.map !== 'function') {
            el.innerHTML = '<p class="cli-mapa-calor-msg"><i class="fas fa-map"></i> O mapa não pôde ser inicializado. Atualize a página.</p>';
            return;
        }
        destroyCliUfMap();
        el.innerHTML = '';

        var porUf = _cliMapaCtx.porUf;
        var ufSel = _cliMapaCtx.uf;
        var munSel = _cliMapaCtx.municipio;
        var bairroSel = _cliMapaCtx.bairro;

        var wrapB = document.getElementById('cliMapaBairrosWrap');
        if (wrapB) wrapB.style.display = 'none';

        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        var tileUrl = dark
            ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
            : 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';

        function addBaseMap() {
            _cliUfMap = L.map(el, { zoomControl: true, attributionControl: true, scrollWheelZoom: true });
            L.tileLayer(tileUrl, {
                attribution: '&copy; OpenStreetMap &copy; CARTO · IBGE (malhas)',
                subdomains: 'abcd',
                maxZoom: 19,
            }).addTo(_cliUfMap);
        }

        if (!ufSel) {
            setCliMapaHint('Nível Brasil: cada estado colorido pelo total de clientes na base.');
            var totaisUf = {};
            var maxUf = 0;
            (porUf || []).forEach(function (r) {
                var u = String(r.uf || '').trim().toUpperCase();
                var n = Number(r.total);
                if (u && u.length === 2 && /^[A-Z]{2}$/.test(u)) {
                    totaisUf[u] = n;
                    if (n > maxUf) maxUf = n;
                }
            });
            var totalBr = Object.keys(totaisUf).reduce(function (s, k) { return s + totaisUf[k]; }, 0);
            fetchBrGeoJsonCli().then(function (geojson) {
                if (!document.getElementById('cliMapaCalorUf')) return;
                addBaseMap();
                _cliUfGeoLayer = L.geoJSON(geojson, {
                    style: function (feat) {
                        var sigla = (feat.properties && feat.properties.sigla) ? String(feat.properties.sigla).toUpperCase() : '';
                        var v = totaisUf[sigla] || 0;
                        return {
                            fillColor: corMapaCalorCli(v, maxUf),
                            weight: 1,
                            opacity: 1,
                            color: dark ? 'rgba(148,163,184,0.45)' : '#94a3b8',
                            fillOpacity: v > 0 ? 0.9 : 0.45,
                        };
                    },
                    onEachFeature: function (feat, layer) {
                        var sigla = (feat.properties && feat.properties.sigla) ? String(feat.properties.sigla) : '';
                        var nome = (feat.properties && feat.properties.name) ? String(feat.properties.name) : '';
                        var v = totaisUf[String(sigla).toUpperCase()] || 0;
                        var part = (totalBr > 0 && v > 0) ? ' (' + pct(v, totalBr) + ' dos clientes com UF no mapa)' : '';
                        layer.bindTooltip(
                            '<strong>' + esc(sigla) + '</strong> — ' + esc(nome) + '<br>' + fmt(v) + ' cliente(s)' + part,
                            { sticky: true, direction: 'auto', className: 'cli-mapa-calor-tooltip' }
                        );
                    },
                }).addTo(_cliUfMap);
                _cliUfMap.fitBounds(_cliUfGeoLayer.getBounds(), { padding: [14, 14] });
                setTimeout(function () { if (_cliUfMap) _cliUfMap.invalidateSize(); }, 200);
            }).catch(function () {
                el.innerHTML = '<p class="cli-mapa-calor-msg"><i class="fas fa-cloud-arrow-down"></i> Não foi possível carregar a malha dos estados.</p>';
            });
            return;
        }

        setCliMapaHint('Nível UF (' + ufSel + '): municípios coloridos pelo total de clientes. Escolha um município e atualize para ver bairros.');
        Promise.all([
            fetchIbgeMalhaMunicipiosCli(ufSel),
            fetchIbgeListaMunicipiosCli(ufSel),
            apiFetch({ action: 'por_municipio_por_uf', uf: ufSel }),
        ]).then(function (results) {
            var geojson = results[0];
            var ibgeLista = results[1];
            var porMun = results[2] || [];

            var totaisPorNome = {};
            var maxM = 0;
            porMun.forEach(function (r) {
                var k = normMunKeyCli(r.municipio);
                var n = Number(r.total);
                totaisPorNome[k] = n;
                if (n > maxM) maxM = n;
            });

            var totaisPorCod = {};
            (ibgeLista || []).forEach(function (m) {
                var id = String(m.id);
                var nome = m.nome || '';
                var t = totaisPorNome[normMunKeyCli(nome)] || 0;
                totaisPorCod[id] = t;
            });

            var totalUf = Object.keys(totaisPorCod).reduce(function (s, k) { return s + (totaisPorCod[k] || 0); }, 0);

            if (!document.getElementById('cliMapaCalorUf')) return;
            addBaseMap();

            _cliUfGeoLayer = L.geoJSON(geojson, {
                style: function (feat) {
                    var cod = feat.properties && feat.properties.codarea != null ? String(feat.properties.codarea) : '';
                    var v = totaisPorCod[cod] || 0;
                    var hitM = (ibgeLista || []).filter(function (m) { return String(m.id) === cod; })[0];
                    var isSel = !!(munSel && hitM && normMunKeyCli(hitM.nome) === normMunKeyCli(munSel));
                    return {
                        fillColor: corMapaCalorCli(v, maxM),
                        weight: isSel ? 3 : 1,
                        opacity: 1,
                        color: isSel ? '#E63946' : (dark ? 'rgba(148,163,184,0.45)' : '#94a3b8'),
                        fillOpacity: munSel ? (isSel ? 0.92 : 0.38) : 0.88,
                    };
                },
                onEachFeature: function (feat, layer) {
                    var cod = feat.properties && feat.properties.codarea != null ? String(feat.properties.codarea) : '';
                    var hit = (ibgeLista || []).filter(function (m) { return String(m.id) === cod; })[0];
                    var nomeIbge = hit ? hit.nome : cod;
                    var v = totaisPorCod[cod] || 0;
                    var part = totalUf > 0 && v > 0 ? ' (' + pct(v, totalUf) + ' do estado no mapa)' : '';
                    layer.bindTooltip(
                        '<strong>' + esc(nomeIbge) + '</strong><br>' + fmt(v) + ' cliente(s)' + part,
                        { sticky: true, direction: 'auto', className: 'cli-mapa-calor-tooltip' }
                    );
                },
            }).addTo(_cliUfMap);

            _cliUfMap.fitBounds(_cliUfGeoLayer.getBounds(), { padding: [20, 20] });

            if (munSel) {
                var alvo = (ibgeLista || []).filter(function (m) { return normMunKeyCli(m.nome) === normMunKeyCli(munSel); })[0];
                var munBounds = null;
                if (alvo) {
                    var subLayer = null;
                    _cliUfGeoLayer.eachLayer(function (ly) {
                        var feat = ly.feature;
                        if (feat && feat.properties && String(feat.properties.codarea) === String(alvo.id)) subLayer = ly;
                    });
                    if (subLayer && subLayer.getBounds) {
                        try {
                            munBounds = subLayer.getBounds();
                            _cliUfMap.fitBounds(munBounds, { padding: [28, 28], maxZoom: 14 });
                        } catch (eB) { /* ignore */ }
                    }
                }
                apiFetch({ action: 'por_bairro', uf: ufSel, municipio: munSel, bairro: bairroSel }).then(function (br) {
                    renderCliMapaBairrosChart(br, munSel, bairroSel);
                    if (_cliUfMap && munSel) {
                        if (!munBounds && _cliUfGeoLayer) {
                            try { munBounds = _cliUfGeoLayer.getBounds(); } catch (eM) { /* ignore */ }
                        }
                        if (munBounds) {
                            __addCliEnderecosClienteMapa(_cliUfMap, munBounds, ufSel, munSel, bairroSel, dark, br || []);
                        } else {
                            setCliMapaHint('Município «' + munSel + '» no mapa; não foi possível obter limites para marcar endereços.');
                        }
                    }
                }).catch(function () {
                    renderCliMapaBairrosChart([], '', '');
                });
            }

            setTimeout(function () { if (_cliUfMap) _cliUfMap.invalidateSize(); }, 200);
        }).catch(function () {
            el.innerHTML = '<p class="cli-mapa-calor-msg"><i class="fas fa-cloud-arrow-down"></i> Não foi possível carregar malha ou dados do estado. Verifique a rede (IBGE + API).</p>';
        });
    }

    function readCliMapaFiltrosFromDom() {
        var su = document.getElementById('cliMapFiltroUf');
        var sm = document.getElementById('cliMapFiltroMun');
        var sb = document.getElementById('cliMapFiltroBairro');
        return {
            uf: su ? su.value : '',
            municipio: sm ? sm.value : '',
            bairro: sb ? sb.value : '',
        };
    }

    async function initCliMapaFiltrosOnce() {
        if (_cliMapaFiltrosInit) return;
        _cliMapaFiltrosInit = true;
        var su = document.getElementById('cliMapFiltroUf');
        var sm = document.getElementById('cliMapFiltroMun');
        var sb = document.getElementById('cliMapFiltroBairro');
        if (!su || !sm || !sb) return;

        try {
            var ufs = await apiFetch({ action: 'lista_ufs' });
            su.querySelectorAll('option:not(:first-child)').forEach(function (o) { o.remove(); });
            (ufs || []).forEach(function (u) {
                if (!u) return;
                su.appendChild(new Option(u, u));
            });
            setCliMapaHint('Selecione uma UF para ver o mapa de municípios (malha IBGE).');
        } catch (e) {
            setCliMapaHint('Não foi possível carregar a lista de UFs.');
        }

        function renderMapaFromFiltrosCli() {
            var f = readCliMapaFiltrosFromDom();
            renderCliMapaCalorPro({
                porUf: _cliLastPorUfForMap || [],
                uf: f.uf,
                municipio: f.municipio,
                bairro: f.bairro,
            });
        }

        su.addEventListener('change', async function () {
            sm.innerHTML = '<option value="">Todos no estado</option>';
            sm.disabled = true;
            sb.innerHTML = '<option value="">Todos no município</option>';
            sb.disabled = true;
            if (!su.value) {
                renderCliMapaCalorPro({ porUf: _cliLastPorUfForMap || [], uf: '', municipio: '', bairro: '' });
                return;
            }
            renderCliMapaCalorPro({ porUf: _cliLastPorUfForMap || [], uf: su.value, municipio: '', bairro: '' });
            try {
                var muns = await apiFetch({ action: 'lista_municipios', uf: su.value });
                sm.disabled = false;
                (muns || []).forEach(function (m) {
                    sm.appendChild(new Option(m, m));
                });
            } catch (e2) { /* ignore */ }
        });

        sm.addEventListener('change', async function () {
            sb.innerHTML = '<option value="">Todos no município</option>';
            sb.disabled = true;
            if (!su.value) return;
            renderCliMapaCalorPro({
                porUf: _cliLastPorUfForMap || [],
                uf: su.value,
                municipio: sm.value || '',
                bairro: '',
            });
            if (!sm.value) return;
            try {
                var bs = await apiFetch({ action: 'lista_bairros', uf: su.value, municipio: sm.value });
                sb.disabled = false;
                (bs || []).forEach(function (b) {
                    sb.appendChild(new Option(b, b));
                });
            } catch (e3) { /* ignore */ }
        });

        sb.addEventListener('change', function () {
            if (!su.value) return;
            renderMapaFromFiltrosCli();
        });

        var aplicar = document.getElementById('cliMapBtnAplicar');
        var brasil = document.getElementById('cliMapBtnBrasil');
        if (aplicar) aplicar.addEventListener('click', function () {
            renderMapaFromFiltrosCli();
        });
        if (brasil) brasil.addEventListener('click', function () {
            su.value = '';
            sm.innerHTML = '<option value="">Todos no estado</option>';
            sm.disabled = true;
            sb.innerHTML = '<option value="">Todos no município</option>';
            sb.disabled = true;
            renderCliMapaCalorPro({ porUf: _cliLastPorUfForMap || [], uf: '', municipio: '', bairro: '' });
        });
    }

    async function apiFetch(params) {
        const url = API + '?' + new URLSearchParams(params).toString();
        const r = await fetch(url, { credentials: 'include' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    }

    // ── Tema (paridade index.html: theme-toggle + chave por usuário) ───────────
    function applyTheme(t) {
        var theme = t === 'dark' || t === 'light' ? t : 'light';
        document.documentElement.setAttribute('data-theme', theme);
        try {
            localStorage.setItem(getThemeStorageKeyCli(), theme);
            localStorage.setItem(THEME_KEY, theme);
        } catch (e) { /* ignore */ }
    }
    function loadSavedThemeCli() {
        var userKey = getThemeStorageKeyCli();
        var saved = localStorage.getItem(userKey) || localStorage.getItem(THEME_KEY) || 'light';
        applyTheme(saved === 'dark' || saved === 'light' ? saved : 'light');
    }
    function toggleThemeCli() {
        applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        if (_cliCurrentSection === 'geografico' && _cliMapaCtx) {
            renderCliMapaCalorPro(_cliMapaCtx);
        }
    }
    function initTheme() {
        loadSavedThemeCli();
        var tg = document.getElementById('cliThemeToggle');
        if (tg) {
            tg.addEventListener('click', toggleThemeCli);
            tg.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleThemeCli();
                }
            });
        }
    }

    // ── Sidebar (ids como index: #sidebar, overlay, mobile no topbar) ────────
    function initSidebar() {
        var sidebar = document.getElementById('sidebar');
        var toggle = document.getElementById('sidebarToggle');
        var mobileBtn = document.getElementById('mobileMenuBtn');
        var overlay = document.getElementById('sidebarOverlay');
        if (!sidebar) return;

        function syncToggle() {
            var col = sidebar.classList.contains('collapsed');
            if (toggle) {
                var ic = toggle.querySelector('i');
                if (ic) ic.className = col ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
                toggle.setAttribute('aria-label', col ? 'Expandir menu' : 'Recolher menu');
            }
        }
        function setCollapsed(v) {
            sidebar.classList.toggle('collapsed', v);
            try { localStorage.setItem(SIDEBAR_KEY, v ? '1' : '0'); } catch (e) { /* ignore */ }
            syncToggle();
        }
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('collapsed');
            syncToggle();
        } else {
            var saved = localStorage.getItem(SIDEBAR_KEY);
            if (saved === '0') setCollapsed(false); else setCollapsed(true);
        }
        if (toggle) toggle.addEventListener('click', function () {
            if (window.innerWidth <= 768) return;
            setCollapsed(!sidebar.classList.contains('collapsed'));
        });
        if (mobileBtn) mobileBtn.addEventListener('click', function () { toggleMobileSidebarCli(); });
        if (overlay) overlay.addEventListener('click', function () { closeMobileSidebarCli(); });
    }

    // ── Navegação de seções ───────────────────────────────────────────────────
    let _loadedSections = {};

    function showSection(name) {
        document.querySelectorAll('.gc-section').forEach(function (s) {
            s.classList.remove('active');
            s.setAttribute('aria-hidden', 'true');
        });
        document.querySelectorAll('.gc-menu-item').forEach(function (b) {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });

        const sec = document.getElementById('cli-section-' + name);
        const btn = document.querySelector('[data-section="' + name + '"]');
        if (sec) { sec.classList.add('active'); sec.removeAttribute('aria-hidden'); }
        if (btn) { btn.classList.add('active'); btn.setAttribute('aria-selected', 'true'); }

        _cliCurrentSection = name;
        const titles = { 'visao-geral': 'Clientes', 'evolucao': 'Evolução de Cadastros', 'geografico': 'Distribuição Geográfica', 'analise-compras': 'Análise de Compras', 'busca': 'Busca de Clientes' };
        setText('cliPageTitle', titles[name] || 'Clientes');
        const subs = {
            'visao-geral': 'Base importada · resumo e qualidade dos cadastros',
            'evolucao': 'Volume de novos cadastros ao longo do tempo',
            'geografico': 'Mapa de calor por UF e municípios',
            'analise-compras': 'Recorrência, potencial e compras por ano · apoio a rotas (ex. Ariquemes)',
            'busca': 'Pesquisa na base · clique na linha para detalhes'
        };
        var subEl = document.getElementById('cliPageSubtitle');
        if (subEl) subEl.textContent = subs[name] || '';
        localStorage.setItem(SECTION_KEY, name);

        if (!_loadedSections[name]) {
            _loadedSections[name] = true;
            loadSection(name);
        }
        if (name === 'geografico') {
            setTimeout(function () {
                if (_cliUfMap) _cliUfMap.invalidateSize();
            }, 280);
        }
        if (window.innerWidth <= 768) closeMobileSidebarCli();
    }

    function initNav() {
        document.querySelectorAll('.gc-menu-item[data-section]').forEach(function (btn) {
            btn.addEventListener('click', function () { showSection(btn.dataset.section); });
        });
    }

    // ── LOGOUT (api.php como index) ───────────────────────────────────────────
    function initLogout() {
        var btn = document.getElementById('cliLogoutBtn');
        if (!btn) return;
        btn.addEventListener('click', async function () {
            try { await cliMainApiGet('logout', {}); } catch (_) {}
            clearLocalStoragePreservingMyPharmTheme();
            window.location.href = 'index.html';
        });
    }

    // ── USER INFO (mesmos ids do index: userName, userRole, userAvatar) ──────
    function initUserInfo() {
        var nome = localStorage.getItem('nomeUsuario') || localStorage.getItem('userName') || 'Administrador';
        var tipo = (localStorage.getItem('userType') || '').toLowerCase();
        setText('userName', nome);
        setText('userRole', tipo === 'admin' ? 'Administrador' : 'Usuário');
        applyAvatarCli((nome || 'A').charAt(0).toUpperCase(), localStorage.getItem('foto_perfil') || null);
        cliMainApiGet('check_session', {}).then(function (s) {
            if (s && s.foto_perfil) {
                try { localStorage.setItem('foto_perfil', s.foto_perfil); } catch (e) { /* ignore */ }
                applyAvatarCli((nome || 'A').charAt(0), s.foto_perfil);
            }
        }).catch(function () {});
    }

    function refreshClientesData() {
        _loadedSections[_cliCurrentSection] = false;
        showSection(_cliCurrentSection);
    }

    function initTopbarRefresh() {
        var b = document.getElementById('cliRefreshBtn');
        if (b) b.addEventListener('click', function () { refreshClientesData(); });
    }

    // ── CARREGAMENTO POR SEÇÃO ─────────────────────────────────────────────────
    function loadSection(name) {
        if (name === 'visao-geral') loadVisaoGeral();
        if (name === 'evolucao')    loadEvolucao();
        if (name === 'geografico')  loadGeografico();
        if (name === 'analise-compras') loadAnaliseCompras();
        if (name === 'busca')       initBusca();
    }

    function renderCliCruzamento(d) {
        const root = document.getElementById('cliCruzamentoPanel');
        if (!root) return;
        if (!d || !d.ok) {
            root.innerHTML = '<p class="gc-chart-sub" style="margin:0;">Não foi possível calcular o cruzamento com pedidos (tabelas ou permissão).</p>';
            return;
        }
        const t = d.totais || {};
        const f = d.fontes || {};
        const am = d.amostras || {};
        const fontesTxt = [
            f.gestao_pedidos_aprovados ? 'gestão aprovados' : null,
            f.itens_orcamentos_recusado_carrinho ? 'itens recusado/carrinho' : null,
            f.orcamentos_pedidos_nomes ? 'orcamentos_pedidos (nomes)' : null,
        ].filter(Boolean).join(', ') || 'nenhuma tabela disponível';
        const anoTxt = d.ano_filtrado ? 'Ano filtrado: ' + d.ano_filtrado + '.' : 'Todos os anos nas tabelas de pedidos.';
        let html = '<p class="gc-chart-sub" style="margin:0 0 8px 0;">Fontes usadas: <strong>' + esc(fontesTxt) + '</strong>. ' + esc(anoTxt) + '</p>';
        html += '<div class="cli-cruzamento-stats">';
        html += '<div class="cli-cruzamento-stat"><strong>' + fmt(t.cadastro_nomes_distintos || 0) + '</strong><span>Nomes distintos no cadastro</span></div>';
        html += '<div class="cli-cruzamento-stat"><strong>' + fmt(t.cadastro_com_match_pedido || 0) + '</strong><span>Cadastro com pelo menos um pedido (nome)</span></div>';
        html += '<div class="cli-cruzamento-stat"><strong>' + fmt(t.cadastro_sem_match_pedido || 0) + '</strong><span>Cadastro sem pedido (nome)</span></div>';
        html += '<div class="cli-cruzamento-stat"><strong>' + fmt(t.pedido_nomes_distintos_total || 0) + '</strong><span>Nomes distintos nos pedidos (união)</span></div>';
        html += '<div class="cli-cruzamento-stat"><strong>' + fmt(t.pedido_nomes_distintos_somente_aprovados || 0) + '</strong><span>Só em gestão aprovada</span></div>';
        html += '<div class="cli-cruzamento-stat"><strong>' + fmt(t.pedido_nomes_distintos_recusado_ou_carrinho_ou_orc || 0) + '</strong><span>Recusado/carrinho (+ orç. se houver)</span></div>';
        html += '<div class="cli-cruzamento-stat"><strong>' + fmt(t.pedido_sem_match_cadastro || 0) + '</strong><span>Pedido sem match no cadastro</span></div>';
        html += '</div>';
        if (d.metodologia) {
            html += '<p class="gc-chart-sub" style="margin:0 0 8px 0;">' + esc(d.metodologia) + '</p>';
        }
        const ac = am.cadastro_sem_compra_nome || [];
        const ap = am.pedido_sem_cadastro_norm || [];
        if (ac.length) {
            html += '<div class="cli-cruzamento-amostra"><strong>Amostra cadastro sem compra (até 40):</strong><br>' + ac.map(esc).join(' · ') + '</div>';
        }
        if (ap.length) {
            html += '<div class="cli-cruzamento-amostra"><strong>Amostra nome em pedido sem cadastro (normalizado, até 40):</strong><br>' + ap.map(esc).join(' · ') + '</div>';
        }
        root.innerHTML = html;
    }

    // ══ VISÃO GERAL ═══════════════════════════════════════════════════════════
    async function loadVisaoGeral() {
        try {
            const [resumo, porMes, porUf, porSexo, porFonteAno, cruzamento] = await Promise.all([
                apiFetch({ action: 'resumo' }),
                apiFetch({ action: 'por_mes' }),
                apiFetch({ action: 'por_uf' }),
                apiFetch({ action: 'por_sexo' }),
                apiFetch({ action: 'por_fonte_ano' }),
                apiFetch({ action: 'cruzamento_compras' }).catch(function () { return null; }),
            ]);
            renderCliCruzamento(cruzamento);

            const total = Number(resumo.total || 0);
            setText('kpiTotal',       fmt(total));
            setText('kpiComCpf',      fmt(resumo.com_cpf));
            setText('kpiComCpfPct',   pct(resumo.com_cpf, total) + ' da base');
            setText('kpiCompleto',    fmt(resumo.completo));
            setText('kpiCompletoPct', pct(resumo.completo, total) + ' da base');
            setText('kpiSimples',     fmt(resumo.simples));
            setText('kpiSimplesPct',  pct(resumo.simples, total) + ' da base');
            setText('kpiComEmail',    fmt(resumo.com_email));
            setText('kpiComEmailPct', pct(resumo.com_email, total) + ' da base');
            setText('kpiComNasc',     fmt(resumo.com_nasc));
            setText('kpiComNascPct',  pct(resumo.com_nasc, total) + ' da base');
            setText('kpiComCelular',  fmt(resumo.com_celular));
            setText('kpiComCelularPct', pct(resumo.com_celular, total) + ' da base');

            setKpiMeter('kpiTotalMeter', total, total);
            setKpiMeter('kpiComCpfMeter', resumo.com_cpf, total);
            setKpiMeter('kpiCompletoMeter', resumo.completo, total);
            setKpiMeter('kpiSimplesMeter', resumo.simples, total);
            setKpiMeter('kpiComEmailMeter', resumo.com_email, total);
            setKpiMeter('kpiComNascMeter', resumo.com_nasc, total);
            setKpiMeter('kpiComCelularMeter', resumo.com_celular, total);

            // Evolução (mesmo padrão da aba Evolução, canvas dedicado à visão geral)
            (function () {
                const rows = porMes || [];
                const labels   = rows.map(function (r) { return ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][r.mes - 1] + '/' + String(r.ano).slice(2); });
                const totais   = rows.map(function (r) { return Number(r.total); });
                const completo = rows.map(function (r) { return Number(r.completo); });
                const simples  = rows.map(function (r) { return Number(r.simples); });
                mkChart('cliChartVisaoEvolucao', {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            { label: 'Completo', data: completo, backgroundColor: '#2A9D8F', stack: 'a', datalabels: { display: false } },
                            { label: 'Simples',  data: simples,  backgroundColor: '#E9C46A', stack: 'a', datalabels: { display: false } },
                            { label: 'Total', data: totais, type: 'line', borderColor: '#E63946', backgroundColor: 'rgba(230,57,70,.1)', borderWidth: 2, pointRadius: 3, fill: false, yAxisID: 'y', tension: 0.3, datalabels: { display: false } },
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'top' },
                            datalabels: { display: false },
                        },
                        scales: { y: { beginAtZero: true, stacked: true, ticks: { callback: function (v) { return fmt(v); } } }, x: { stacked: true } }
                    }
                });
            })();

            // Distribuição por UF — barras verticais (top 18 + Outros)
            (function () {
                var rows = porUf || [];
                var maxBar = 18;
                var labels = [];
                var vals = [];
                var i;
                for (i = 0; i < rows.length && i < maxBar; i++) {
                    labels.push(rows[i].uf);
                    vals.push(Number(rows[i].total));
                }
                var outros = 0;
                for (; i < rows.length; i++) outros += Number(rows[i].total);
                if (outros > 0) {
                    labels.push('Outros');
                    vals.push(outros);
                }
                var sumUf = vals.reduce(function (a, b) { return a + b; }, 0);
                var colors = labels.map(function (_, idx) { return PALETTE[idx % PALETTE.length]; });
                mkChart('cliChartVisaoUf', {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Clientes',
                            data: vals,
                            backgroundColor: colors,
                            borderRadius: 5,
                            borderSkipped: false,
                            categoryPercentage: 0.78,
                            barPercentage: 0.92,
                        }],
                    },
                    options: {
                        indexAxis: 'x',
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false, axis: 'x' },
                        plugins: {
                            legend: { display: false },
                            datalabels: {
                                display: typeof ChartDataLabels !== 'undefined',
                                anchor: 'end',
                                align: 'end',
                                offset: 2,
                                clamp: true,
                                clip: false,
                                formatter: function (value) { return fmt(Number(value || 0)); },
                                font: { size: 9, weight: '700' },
                                color: function (ctx) {
                                    var v = Number(ctx.dataset.data[ctx.dataIndex] || 0);
                                    var mx = Math.max.apply(null, (ctx.dataset.data || []).map(Number));
                                    return mx > 0 && v < mx * 0.08 ? '#64748b' : '#1e293b';
                                },
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (c) {
                                        var v = Number(c.raw);
                                        return c.dataset.label + ': ' + fmt(v) + (sumUf > 0 ? ' (' + pct(v, sumUf) + ' da base neste gráfico)' : '');
                                    },
                                },
                            },
                        },
                        elements: {
                            bar: {
                                borderWidth: 0,
                                hoverBorderWidth: 3,
                                hoverBorderColor: 'rgba(15, 23, 42, 0.55)',
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grace: '8%',
                                ticks: { callback: function (v) { return fmt(v); } },
                                grid: { color: 'rgba(148, 163, 184, 0.2)' },
                            },
                            x: {
                                ticks: {
                                    maxRotation: 50,
                                    minRotation: 45,
                                    font: { size: 10 },
                                },
                                grid: { display: false },
                            },
                        },
                    },
                });
            })();

            // chart tipo cadastro (donut)
            const tipoData = { Completo: Number(resumo.completo), Simples: Number(resumo.simples) };
            mkChart('cliChartTipo', {
                type: 'doughnut',
                data: {
                    labels: Object.keys(tipoData),
                    datasets: [{ data: Object.values(tipoData), backgroundColor: ['#2A9D8F','#E9C46A'], borderWidth: 2, datalabels: { display: false } }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        datalabels: { display: false },
                        tooltip: { callbacks: { label: function (c) { return c.label + ': ' + fmt(c.raw) + ' (' + pct(c.raw, total) + ')'; } } },
                    },
                }
            });

            // chart sexo (donut)
            mkChart('cliChartSexo', {
                type: 'doughnut',
                data: {
                    labels: porSexo.map(function (r) { return r.sexo; }),
                    datasets: [{ data: porSexo.map(function (r) { return Number(r.total); }), backgroundColor: PALETTE, borderWidth: 2, datalabels: { display: false } }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        datalabels: { display: false },
                        tooltip: { callbacks: { label: function (c) { return c.label + ': ' + fmt(c.raw); } } },
                    },
                }
            });

            // chart fonte ano (barras agrupadas)
            mkChart('cliChartFonteAno', {
                type: 'bar',
                data: {
                    labels: porFonteAno.map(function (r) { return r.ano || 'N/D'; }),
                    datasets: [
                        { label: 'Total', data: porFonteAno.map(function (r) { return Number(r.total); }), backgroundColor: '#E63946', datalabels: { display: false } },
                        { label: 'Completo', data: porFonteAno.map(function (r) { return Number(r.completo); }), backgroundColor: '#2A9D8F', datalabels: { display: false } },
                        { label: 'Com CPF', data: porFonteAno.map(function (r) { return Number(r.com_cpf); }), backgroundColor: '#457B9D', datalabels: { display: false } },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        datalabels: { display: false },
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return fmt(v); } } } }
                }
            });

        } catch (e) { console.error('loadVisaoGeral:', e); }
    }

    // ══ EVOLUÇÃO ══════════════════════════════════════════════════════════════
    async function loadEvolucao() {
        try {
            const rows = await apiFetch({ action: 'por_mes' });
            const labels   = rows.map(function (r) { return ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][r.mes - 1] + '/' + String(r.ano).slice(2); });
            const totais   = rows.map(function (r) { return Number(r.total); });
            const completo = rows.map(function (r) { return Number(r.completo); });
            const simples  = rows.map(function (r) { return Number(r.simples); });

            mkChart('cliChartPorMes', {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Completo', data: completo, backgroundColor: '#2A9D8F', stack: 'a', datalabels: { display: false } },
                        { label: 'Simples',  data: simples,  backgroundColor: '#E9C46A', stack: 'a', datalabels: { display: false } },
                        { label: 'Total', data: totais, type: 'line', borderColor: '#E63946', backgroundColor: 'rgba(230,57,70,.1)', borderWidth: 2, pointRadius: 3, fill: false, yAxisID: 'y', tension: 0.3, datalabels: { display: false } },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        datalabels: { display: false },
                    },
                    scales: { y: { beginAtZero: true, stacked: true, ticks: { callback: function (v) { return fmt(v); } } }, x: { stacked: true } }
                }
            });
        } catch (e) { console.error('loadEvolucao:', e); }
    }

    // ══ GEOGRÁFICO ════════════════════════════════════════════════════════════
    async function loadGeografico() {
        try {
            const [porUf, porMun] = await Promise.all([
                apiFetch({ action: 'por_uf' }),
                apiFetch({ action: 'por_municipio' }),
            ]);

            _cliLastPorUfForMap = porUf;
            await initCliMapaFiltrosOnce();
            renderCliMapaCalorPro({ porUf: porUf, uf: '', municipio: '', bairro: '' });

            mkChart('cliChartMunicipio', {
                type: 'bar',
                data: {
                    labels: porMun.map(function (r) { return r.municipio + (r.uf ? ' (' + r.uf + ')' : ''); }),
                    datasets: [{ label: 'Clientes', data: porMun.map(function (r) { return Number(r.total); }), backgroundColor: PALETTE[1], datalabels: { display: false } }]
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                    },
                    scales: { x: { beginAtZero: true, ticks: { callback: function (v) { return fmt(v); } } } }
                }
            });
        } catch (e) { console.error('loadGeografico:', e); }
    }

    // ══ BUSCA ═════════════════════════════════════════════════════════════════
    let _buscaPg = 1;
    let _buscaParams = {};
    let _buscaSort = 'data_cadastro';
    let _buscaSortDir = 'desc';

    function atualizarIconesOrdenacaoBusca() {
        const thead = document.getElementById('cliBuscaThead');
        if (!thead) return;
        thead.querySelectorAll('.cli-th-sort').forEach(function (btn) {
            const col = btn.getAttribute('data-sort');
            const i = btn.querySelector('i');
            if (!i) return;
            i.className = 'fas fa-sort';
            if (col === _buscaSort) {
                i.className = _buscaSortDir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
            }
        });
    }

    function cliBuscaMontarParamsExport() {
        const o = {
            action: 'exportar_busca_csv',
            q: (document.getElementById('cliBuscaQ') || {}).value || '',
            uf: (document.getElementById('cliBuscaUf') || {}).value || '',
            municipio: (document.getElementById('cliBuscaMunicipio') || {}).value || '',
            tipo: (document.getElementById('cliBuscaTipo') || {}).value || '',
            ano: (document.getElementById('cliBuscaAno') || {}).value || '',
            sort: _buscaSort,
            dir: _buscaSortDir
        };
        return new URLSearchParams(o);
    }

    function cliBuscaAtualizarPrintMeta() {
        const meta = document.getElementById('cliBuscaPrintMeta');
        if (!meta) return;
        const q = (document.getElementById('cliBuscaQ') || {}).value || '';
        const uf = (document.getElementById('cliBuscaUf') || {}).value || '';
        const mun = (document.getElementById('cliBuscaMunicipio') || {}).value || '';
        const tipo = (document.getElementById('cliBuscaTipo') || {}).value || '';
        const ano = (document.getElementById('cliBuscaAno') || {}).value || '';
        const parts = [];
        if (q) parts.push('Texto: «' + q + '»');
        if (uf) parts.push('UF: ' + uf);
        if (mun) parts.push('Município: ' + mun);
        if (tipo) parts.push('Tipo: ' + tipo);
        if (ano) parts.push('Ano CSV: ' + ano);
        parts.push('Ordem: ' + _buscaSort + ' ' + _buscaSortDir);
        const info = document.getElementById('cliBuscaInfo');
        const infoTxt = info ? info.textContent.trim() : '';
        meta.textContent = (parts.length ? parts.join(' · ') + (infoTxt ? ' — ' : '') : '') + (infoTxt || '');
        const pag = document.getElementById('cliBuscaPag');
        if (pag && pag.style.display !== 'none') {
            meta.textContent += ' · Impressão: só a página atual da tabela; use «Baixar CSV» para todos os registros do filtro.';
        }
    }

    async function cliBuscaBaixarCsv() {
        const url = API + '?' + cliBuscaMontarParamsExport().toString();
        cliShowPageLoading('Gerando CSV...');
        try {
            const r = await fetch(url, { credentials: 'include', cache: 'no-store' });
            const ct = (r.headers.get('Content-Type') || '').toLowerCase();
            if (r.status === 400 && ct.indexOf('json') !== -1) {
                const j = await r.json().catch(function () { return null; });
                const msg = (j && j.message) ? j.message : 'Exportação recusada. Refine a busca.';
                if (typeof window.uiToast === 'function') window.uiToast(msg, 'warning'); else window.alert(msg);
                return;
            }
            if (!r.ok) {
                if (typeof window.uiToast === 'function') window.uiToast('Erro ao baixar CSV.', 'error'); else window.alert('Erro ao baixar CSV.');
                return;
            }
            if (ct.indexOf('csv') === -1 && ct.indexOf('text/plain') === -1) {
                if (typeof window.uiToast === 'function') window.uiToast('Resposta inesperada do servidor.', 'error'); else window.alert('Resposta inesperada.');
                return;
            }
            const blob = await r.blob();
            let fname = 'clientes_busca.csv';
            const disp = r.headers.get('Content-Disposition') || '';
            const m = /filename\*?=(?:UTF-8'')?["']?([^"';]+)/i.exec(disp);
            if (m && m[1]) fname = decodeURIComponent(m[1].replace(/["']/g, '').trim());
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = fname;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(a.href);
        } catch (e) {
            if (typeof window.uiToast === 'function') window.uiToast('Falha na exportação.', 'error'); else window.alert('Falha na exportação.');
        } finally {
            cliHidePageLoading();
        }
    }

    function cliBuscaImprimir() {
        const bd = document.getElementById('cliModalBackdrop');
        if (bd) bd.classList.remove('is-open');
        cliBuscaAtualizarPrintMeta();
        window.print();
    }

    let _cliAnaliseFiltrosProntos = false;

    function fmtBrlCli(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    async function initAnaliseComprasFiltros() {
        if (_cliAnaliseFiltrosProntos) return;
        _cliAnaliseFiltrosProntos = true;
        const selUf = document.getElementById('cliAnaliseUf');
        const selMun = document.getElementById('cliAnaliseMunicipio');
        const btn = document.getElementById('cliAnaliseBtn');

        async function carregarMunAnalise() {
            if (!selMun || !selUf) return;
            const uf = selUf.value;
            selMun.innerHTML = '<option value="">Todos do estado</option>';
            if (!uf) {
                selMun.disabled = true;
                selMun.title = 'Selecione UF';
                return;
            }
            selMun.disabled = true;
            selMun.title = 'Carregando…';
            try {
                const list = await apiFetch({ action: 'lista_municipios', uf: uf });
                (list || []).forEach(function (m) {
                    const opt = document.createElement('option');
                    opt.value = m;
                    opt.textContent = m;
                    selMun.appendChild(opt);
                });
            } catch (e) { /* ignore */ }
            selMun.disabled = false;
            selMun.title = '';
        }

        try {
            const ufs = await apiFetch({ action: 'lista_ufs' });
            if (selUf) ufs.forEach(function (uf) {
                const opt = document.createElement('option');
                opt.value = uf;
                opt.textContent = uf;
                selUf.appendChild(opt);
            });
        } catch (_) {}

        if (selUf) selUf.addEventListener('change', function () { carregarMunAnalise(); });
        if (btn) btn.addEventListener('click', function () { executarAnaliseCompras(); });
    }

    async function executarAnaliseCompras() {
        const meta = document.getElementById('cliAnaliseMeta');
        const tbody = document.getElementById('cliAnaliseTopBody');
        const metTxt = document.getElementById('cliAnaliseMetodologia');
        const su = document.getElementById('cliAnaliseUf');
        const sm = document.getElementById('cliAnaliseMunicipio');
        const params = { action: 'analise_compras_clientes' };
        if (su && su.value) params.uf = su.value;
        if (sm && sm.value) params.municipio = sm.value;

        if (meta) meta.textContent = 'Carregando…';
        try {
            const d = await apiFetch(params);
            if (!d || !d.ok) {
                if (meta) meta.textContent = (d && d.error) ? d.error : 'Não foi possível carregar a análise.';
                return;
            }
            const filtro = d.filtro || {};
            const partesF = [];
            if (filtro.uf) partesF.push('UF: ' + filtro.uf);
            if (filtro.municipio) partesF.push('Município: ' + filtro.municipio);
            if (meta) meta.textContent = (partesF.length ? 'Filtro: ' + partesF.join(' · ') + '. ' : 'Brasil inteiro (use UF/município para focar, ex. RO · Ariquemes). ')
                + 'Fontes: ' + (d.fontes && d.fontes.gestao_pedidos ? 'gestão' : 'sem gestão')
                + (d.fontes && d.fontes.itens_orcamentos_pedidos ? ' + itens' : '') + '.';

            const t = d.totais || {};
            setText('cliAkCad', fmt(d.cadastro_total || 0));
            setText('cliAkRec', fmt(t.clientes_recorrentes || 0));
            setText('cliAkUma', fmt(t.clientes_uma_compra || 0));
            setText('cliAkSem', fmt(t.clientes_sem_compra_aprovada || 0));
            setText('cliAkRecPct', t.pct_recorrentes_sobre_base != null ? String(t.pct_recorrentes_sobre_base) + '%' : '—');
            setText('cliAkReceita', fmtBrlCli(t.receita_aprovada_total));
            setText('cliAkTicket', t.ticket_medio_compradores != null ? fmtBrlCli(t.ticket_medio_compradores) : '—');
            setText('cliAkPedidos', fmt(t.pedidos_aprovados_distintos || 0));
            setText('cliAkPipe', fmtBrlCli(t.valor_pipeline_rec_carrinho));
            setText('cliAkPipeCli', fmt(t.clientes_somente_pipeline || 0));

            const porAno = d.compras_por_ano || [];
            if (porAno.length === 0) {
                mkChart('cliChartAnaliseAno', {
                    type: 'bar',
                    data: { labels: ['—'], datasets: [{ label: 'Sem dados', data: [0], backgroundColor: '#e5e7eb', datalabels: { display: false } }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, datalabels: { display: false } }, scales: { y: { beginAtZero: true } } }
                });
            } else {
                mkChart('cliChartAnaliseAno', {
                    type: 'bar',
                    data: {
                        labels: porAno.map(function (r) { return String(r.ano); }),
                        datasets: [
                            {
                                label: 'Receita aprovada (R$)',
                                data: porAno.map(function (r) { return Number(r.receita || 0); }),
                                backgroundColor: 'rgba(230, 57, 70, 0.75)',
                                yAxisID: 'y',
                                datalabels: { display: false },
                            },
                            {
                                label: 'Pedidos distintos',
                                data: porAno.map(function (r) { return Number(r.pedidos_distintos || 0); }),
                                type: 'line',
                                borderColor: '#457B9D',
                                backgroundColor: 'rgba(69, 123, 157, 0.15)',
                                borderWidth: 2,
                                pointRadius: 4,
                                yAxisID: 'y1',
                                datalabels: { display: false },
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'top' },
                            datalabels: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var l = ctx.dataset.label || '';
                                        if (l.indexOf('Receita') !== -1) return l + ': ' + fmtBrlCli(ctx.raw);
                                        return l + ': ' + fmt(ctx.raw);
                                    },
                                },
                            },
                        },
                        scales: {
                            y: {
                                type: 'linear',
                                position: 'left',
                                beginAtZero: true,
                                ticks: { callback: function (v) { return fmtBrlCli(v).replace(/\s/g, ''); } },
                            },
                            y1: {
                                type: 'linear',
                                position: 'right',
                                beginAtZero: true,
                                grid: { drawOnChartArea: false },
                                ticks: { callback: function (v) { return fmt(v); } },
                            },
                        },
                    },
                });
            }

            const top = d.top_recorrentes || [];
            if (tbody) {
                if (top.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="cli-empty" style="padding:20px;">Nenhum cliente recorrente neste filtro (ou sem tabela de gestão).</td></tr>';
                } else {
                    let h = '';
                    top.forEach(function (r) {
                        h += '<tr>'
                            + '<td>' + esc(r.nome) + '</td>'
                            + '<td>' + esc(r.municipio || '—') + '</td>'
                            + '<td>' + esc(r.uf || '—') + '</td>'
                            + '<td class="cli-analise-num">' + fmt(r.pedidos_aprov) + '</td>'
                            + '<td class="cli-analise-num">' + fmt(r.anos_com_compra) + '</td>'
                            + '<td class="cli-analise-num">' + fmtBrlCli(r.receita) + '</td>'
                            + '</tr>';
                    });
                    tbody.innerHTML = h;
                }
            }

            if (metTxt) metTxt.textContent = d.metodologia || '';
        } catch (e) {
            console.error('analiseCompras:', e);
            if (meta) meta.textContent = 'Erro ao carregar análise.';
        }
    }

    async function loadAnaliseCompras() {
        await initAnaliseComprasFiltros();
        await executarAnaliseCompras();
    }

    async function initBusca() {
        const selUf = document.getElementById('cliBuscaUf');
        const selMun = document.getElementById('cliBuscaMunicipio');

        async function carregarMunicipiosBusca() {
            if (!selMun || !selUf) return;
            const uf = selUf.value;
            selMun.innerHTML = '<option value="">Todos os municípios</option>';
            if (!uf) {
                selMun.disabled = true;
                selMun.title = 'Selecione um estado';
                return;
            }
            selMun.disabled = true;
            selMun.title = 'Carregando…';
            try {
                const list = await apiFetch({ action: 'lista_municipios', uf: uf });
                (list || []).forEach(function (m) {
                    const opt = document.createElement('option');
                    opt.value = m;
                    opt.textContent = m;
                    selMun.appendChild(opt);
                });
            } catch (e) { /* ignore */ }
            selMun.disabled = false;
            selMun.title = '';
        }

        try {
            const ufs = await apiFetch({ action: 'lista_ufs' });
            if (selUf) ufs.forEach(function (uf) {
                const opt = document.createElement('option');
                opt.value = uf; opt.textContent = uf;
                selUf.appendChild(opt);
            });
        } catch (_) {}

        if (selUf) selUf.addEventListener('change', function () { carregarMunicipiosBusca(); });

        const btnBusca = document.getElementById('cliBuscaBtn');
        const inpQ     = document.getElementById('cliBuscaQ');
        if (btnBusca) btnBusca.addEventListener('click', function () { _buscaPg = 1; executarBusca(); });
        if (inpQ) inpQ.addEventListener('keydown', function (e) { if (e.key === 'Enter') { _buscaPg = 1; executarBusca(); } });

        const btnPrint = document.getElementById('cliBuscaBtnPrint');
        const btnCsv = document.getElementById('cliBuscaBtnCsv');
        if (btnPrint) btnPrint.addEventListener('click', cliBuscaImprimir);
        if (btnCsv) btnCsv.addEventListener('click', cliBuscaBaixarCsv);

        const thead = document.getElementById('cliBuscaThead');
        if (thead) {
            thead.querySelectorAll('.cli-th-sort').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const col = btn.getAttribute('data-sort');
                    if (!col) return;
                    if (_buscaSort === col) {
                        _buscaSortDir = _buscaSortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        _buscaSort = col;
                        _buscaSortDir = (col === 'data_cadastro' || col === 'fonte_ano' || col === 'receita_aprovada_gestao' || col === 'valor_itens_recusados' || col === 'ultima_compra_aprovada') ? 'desc' : 'asc';
                    }
                    atualizarIconesOrdenacaoBusca();
                    _buscaPg = 1;
                    executarBusca();
                });
            });
        }
        atualizarIconesOrdenacaoBusca();
    }

    async function executarBusca() {
        const q    = (document.getElementById('cliBuscaQ')    || {}).value || '';
        const uf   = (document.getElementById('cliBuscaUf')   || {}).value || '';
        const municipio = (document.getElementById('cliBuscaMunicipio') || {}).value || '';
        const tipo = (document.getElementById('cliBuscaTipo') || {}).value || '';
        const ano  = (document.getElementById('cliBuscaAno')  || {}).value || '';

        _buscaParams = {
            action: 'buscar',
            q: q,
            uf: uf,
            municipio: municipio,
            tipo: tipo,
            ano: ano,
            pg: _buscaPg,
            sort: _buscaSort,
            dir: _buscaSortDir
        };

        const tbody = document.getElementById('cliBuscaTbody');
        const info  = document.getElementById('cliBuscaInfo');
        const pag   = document.getElementById('cliBuscaPag');
        if (info) info.textContent = '';
        if (pag) pag.style.display = 'none';
        cliShowPageLoading('Buscando clientes...');

        try {
            const data = await apiFetch(_buscaParams);
            renderBuscaRows(data);
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="13" class="cli-empty"><i class="fas fa-exclamation-triangle"></i> Erro ao buscar.</td></tr>';
        } finally {
            cliHidePageLoading();
        }
    }

    function renderBuscaRows(data) {
        const tbody = document.getElementById('cliBuscaTbody');
        const info  = document.getElementById('cliBuscaInfo');
        const pag   = document.getElementById('cliBuscaPag');

        if (info) info.textContent = 'Total encontrado: ' + fmt(data.total) + ' registros';

        if (!data.rows || data.rows.length === 0) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="13" class="cli-empty"><i class="fas fa-users-slash"></i> Nenhum cliente encontrado.</td></tr>';
            if (pag) pag.style.display = 'none';
            return;
        }

        const meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
        function fmtDate(s) {
            if (!s) return '—';
            var d = new Date(s + 'T00:00:00');
            return d.getDate().toString().padStart(2,'0') + '/' + meses[d.getMonth()] + '/' + d.getFullYear();
        }
        function fmtUltimaCompra(s) {
            if (!s) return '—';
            var t = String(s).trim();
            if (!t) return '—';
            var iso = t.indexOf(' ') !== -1 ? t.replace(' ', 'T') : t + 'T12:00:00';
            var d = new Date(iso);
            if (isNaN(d.getTime())) return '—';
            return d.getDate().toString().padStart(2,'0') + '/' + meses[d.getMonth()] + '/' + d.getFullYear();
        }
        function fmtCpf(c) {
            if (!c) return '—';
            return c.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
        }
        function fmtTel(t) {
            if (!t) return '—';
            return t.replace(/^(\d{2})(\d{4,5})(\d{4})$/, '($1) $2-$3');
        }
        function fmtMoneyBrl(v) {
            return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        }
        function moneyCellCls(v) {
            var n = Number(v || 0);
            return Math.abs(n) < 0.005 ? 'cli-td-num cli-td-num--muted' : 'cli-td-num';
        }

        const rows = data.rows;
        let html = '';
        rows.forEach(function (r) {
            const badgeClass = r.nome_valido == 0 ? 'cli-badge--invalido' : (r.tipo_cadastro === 'Completo' ? 'cli-badge--completo' : 'cli-badge--simples');
            html += '<tr style="cursor:pointer;" onclick="cliAbrirModal(' + JSON.stringify(r).replace(/</g,'\\u003c') + ')">'
                + '<td>' + esc(r.nome) + (r.nome_valido == 0 ? ' <span title="Nome inválido">⚠</span>' : '') + '</td>'
                + '<td title="Gestão aprovada → itens de orçamento → cabeçalho de orçamento">' + esc(r.prescritor_cliente || '—') + '</td>'
                + '<td title="prescritores_cadastro.visitador">' + esc(r.visitador_prescritor || '—') + '</td>'
                + '<td class="' + moneyCellCls(r.receita_aprovada_gestao) + '">' + fmtMoneyBrl(r.receita_aprovada_gestao) + '</td>'
                + '<td class="' + moneyCellCls(r.valor_itens_recusados) + '">' + fmtMoneyBrl(r.valor_itens_recusados) + '</td>'
                + '<td>' + fmtUltimaCompra(r.ultima_compra_aprovada) + '</td>'
                + '<td><span class="cli-badge ' + badgeClass + '">' + esc(r.tipo_cadastro) + '</span></td>'
                + '<td>' + fmtTel(r.telefone) + '</td>'
                + '<td class="cli-busca-print-hide">' + fmtCpf(r.cpf) + '</td>'
                + '<td class="cli-busca-print-hide">' + esc(r.uf || '—') + '</td>'
                + '<td>' + esc(r.municipio || '—') + '</td>'
                + '<td>' + fmtDate(r.data_cadastro) + '</td>'
                + '<td class="cli-busca-print-hide">' + (r.fonte_ano || '—') + '</td>'
                + '</tr>';
        });
        if (tbody) tbody.innerHTML = html;

        // paginação
        if (pag && data.paginas > 1) {
            pag.style.display = 'flex';
            const pagInfo = document.getElementById('cliBuscaPagInfo');
            const pagBtns = document.getElementById('cliBuscaPagBtns');
            if (pagInfo) pagInfo.textContent = 'Página ' + data.pagina + ' de ' + data.paginas;
            if (pagBtns) {
                let btnHtml = '';
                const cur = Number(data.pagina);
                const tot = Number(data.paginas);
                if (cur > 1) btnHtml += '<button class="cli-pag__btn" onclick="cliIrPag(' + (cur - 1) + ')">‹ Anterior</button>';
                const start = Math.max(1, cur - 2);
                const end   = Math.min(tot, cur + 2);
                for (let i = start; i <= end; i++) {
                    btnHtml += '<button class="cli-pag__btn' + (i === cur ? ' cli-pag__btn--active' : '') + '" onclick="cliIrPag(' + i + ')">' + i + '</button>';
                }
                if (cur < tot) btnHtml += '<button class="cli-pag__btn" onclick="cliIrPag(' + (cur + 1) + ')">Próxima ›</button>';
                pagBtns.innerHTML = btnHtml;
            }
        } else if (pag) {
            pag.style.display = 'none';
        }
        atualizarIconesOrdenacaoBusca();
        cliBuscaAtualizarPrintMeta();
    }

    function esc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    // ── MODAL DETALHE ─────────────────────────────────────────────────────────
    window.cliAbrirModal = function (r) {
        const meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
        function fd(s) {
            if (!s) return '—';
            var d = new Date(s + 'T00:00:00');
            return d.getDate().toString().padStart(2,'0') + '/' + meses[d.getMonth()] + '/' + d.getFullYear();
        }
        function fc(c) { return c ? c.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4') : '—'; }
        function ft(t) { return t ? t.replace(/^(\d{2})(\d{4,5})(\d{4})$/, '($1) $2-$3') : '—'; }
        function fm(v) { return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
        function fuc(s) {
            if (!s) return '—';
            var t = String(s).trim();
            var iso = t.indexOf(' ') !== -1 ? t.replace(' ', 'T') : t + 'T12:00:00';
            var d = new Date(iso);
            if (isNaN(d.getTime())) return '—';
            return d.getDate().toString().padStart(2,'0') + '/' + meses[d.getMonth()] + '/' + d.getFullYear();
        }

        const campos = [
            ['Nome',            r.nome || '—'],
            ['Tipo de cadastro',r.tipo_cadastro || '—'],
            ['Data de cadastro',fd(r.data_cadastro)],
            ['Data de nasc.',   fd(r.data_nasc)],
            ['Sexo',            r.sexo || '—'],
            ['Telefone',        ft(r.telefone)],
            ['CPF',             fc(r.cpf)],
            ['E-mail',          r.email || '—'],
            ['Endereço',        [r.logradouro, r.numero, r.bairro].filter(Boolean).join(', ') || '—'],
            ['CEP',             r.cep ? r.cep.replace(/^(\d{5})(\d{3})$/, '$1-$2') : '—'],
            ['Município / UF',  [r.municipio, r.uf].filter(Boolean).join(' — ') || '—'],
            ['Ano do CSV',      r.fonte_ano || '—'],
            ['Receita aprovada (gestão)', fm(r.receita_aprovada_gestao)],
            ['Última compra (gestão aprovada)', fuc(r.ultima_compra_aprovada)],
            ['Prescritor (gestão / orçamento)', r.prescritor_cliente || '—'],
            ['Visitador do prescritor (carteira)', r.visitador_prescritor || '—'],
            ['Recusados + carrinho (valor líquido em itens)', fm(r.valor_itens_recusados)],
            ['Nome válido',     r.nome_valido == 1 ? 'Sim' : 'Não (parece telefone)'],
        ];

        let html = '';
        campos.forEach(function (c) {
            html += '<div class="cli-modal__row"><span class="cli-modal__key">' + esc(c[0]) + '</span><span class="cli-modal__val">' + esc(c[1]) + '</span></div>';
        });

        const body = document.getElementById('cliModalBody');
        const title = document.getElementById('cliModalTitle');
        const bd = document.getElementById('cliModalBackdrop');
        if (body) body.innerHTML = html;
        if (title) title.textContent = r.nome || 'Detalhes do cliente';
        if (bd) bd.classList.add('is-open');
    };

    window.cliIrPag = function (pg) {
        _buscaPg = pg;
        _buscaParams.pg = pg;
        executarBusca();
    };

    function initModal() {
        const bd    = document.getElementById('cliModalBackdrop');
        const close = document.getElementById('cliModalClose');
        function fechar() { if (bd) bd.classList.remove('is-open'); }
        if (close) close.addEventListener('click', fechar);
        if (bd) bd.addEventListener('click', function (e) { if (e.target === bd) fechar(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fechar(); });
    }

    // ── BOOT ──────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        // Auth check
        if (!localStorage.getItem('loggedIn')) {
            window.location.replace('index.html');
            return;
        }

        if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
            try {
                Chart.defaults.plugins = Chart.defaults.plugins || {};
                Chart.defaults.plugins.datalabels = Object.assign({}, Chart.defaults.plugins.datalabels || {}, { display: false });
                Chart.register(ChartDataLabels);
            } catch (eDl) { /* plugin opcional */ }
        }

        initTheme();
        initSidebar();
        initSidebarCollapsedLegendsCli();
        initNav();
        initLogout();
        initUserInfo();
        initModal();
        initTopbarRefresh();
        fetchCliCsrf();
        setupAvatarUploadCli();
        initAdminNotificationsCli();
        loadNotificacoesFromAPICli();

        // Restaurar seção salva
        const saved = localStorage.getItem(SECTION_KEY) || 'visao-geral';
        showSection(saved);

        document.getElementById('gcApp').style.display = '';
    });

})();
