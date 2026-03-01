// =====================================================
// MyPharm - Sistema de Gestão e Insights
// Main Application JavaScript
// =====================================================

const API_URL = 'api.php';
let charts = {};
let currentYear = '';
let currentPage = 'dashboard';
let __csrfToken = '';

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ============ Chart.js Global Config ============
Chart.defaults.color = '#9CA3B8';
Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.padding = 16;
Chart.defaults.responsive = true;
Chart.defaults.maintainAspectRatio = false;

const CHART_COLORS = [
    '#E63946', '#06D6A0', '#118AB2', '#FFD166', '#7B68EE',
    '#FF6B6B', '#48BFE3', '#F77F00', '#9B5DE5', '#00F5D4',
    '#FEE440', '#F15BB5'
];

const MONTH_NAMES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

// ============ Utility Functions ============
function formatMoney(value) {
    const num = parseFloat(value) || 0;
    return num.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatNumber(value) {
    const num = parseFloat(value) || 0;
    return num.toLocaleString('pt-BR');
}

function formatCompactMoney(value) {
    const num = parseFloat(value) || 0;
    if (num >= 1000000) return 'R$ ' + (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return 'R$ ' + (num / 1000).toFixed(1) + 'K';
    return formatMoney(num);
}

function truncateText(text, maxLength = 25) {
    if (!text) return '';
    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
}

function getRankClass(index) {
    if (index === 0) return 'rank-1';
    if (index === 1) return 'rank-2';
    if (index === 2) return 'rank-3';
    return 'rank-default';
}

// ============ API Calls ============
function parseJsonResponse(text) {
    if (!text || typeof text !== 'string') return null;
    const t = text.trim();
    if (!t) return null;
    try {
        return JSON.parse(t);
    } catch (e) {
        return null;
    }
}

async function apiGet(action, params = {}) {
    const query = new URLSearchParams({ action, ...params });
    const response = await fetch(`${API_URL}?${query}`, { credentials: 'include' });
    const text = await response.text();
    const data = parseJsonResponse(text);
    if (response.status === 401) {
        if (data && data.error) sessionStorage.setItem('sessionError', data.error);
        localStorage.clear();
        window.location.href = 'index.html';
        return;
    }
    if (data === null) {
        console.error('API GET resposta inválida (não é JSON):', text ? text.slice(0, 200) : 'vazio');
        return { error: 'Resposta inválida do servidor. Tente novamente.' };
    }
    return data;
}

async function apiPost(action, data = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (__csrfToken) headers['X-CSRF-Token'] = __csrfToken;
    const response = await fetch(`${API_URL}?action=${action}`, {
        method: 'POST',
        headers,
        body: JSON.stringify(data),
        credentials: 'include'
    });
    const text = await response.text();
    const parsed = parseJsonResponse(text);
    if (response.status === 401) {
        if (parsed && parsed.error) sessionStorage.setItem('sessionError', parsed.error);
        localStorage.clear();
        window.location.href = 'index.html';
        return;
    }
    if (parsed === null) {
        console.error('API POST resposta inválida (não é JSON):', text ? text.slice(0, 200) : 'vazio');
        return { error: 'Resposta inválida do servidor. Tente novamente.' };
    }
    return parsed;
}

// Delegação de clique para botão Iniciar visita: no modal → iniciarVisita; na Agenda → abrir modal com prescritor filtrado
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-iniciar-visita');
    if (!btn) return;
    const nome = btn.getAttribute('data-prescritor');
    if (!nome) return;
    const dentroDoModal = e.target.closest('#modalPrescritores');
    if (dentroDoModal && typeof window.iniciarVisita === 'function') {
        e.preventDefault();
        e.stopImmediatePropagation();
        window.iniciarVisita(nome);
    } else if (typeof window.openPrescritoresModal === 'function') {
        e.preventDefault();
        e.stopImmediatePropagation();
        window.openPrescritoresModal(nome);
    }
}, true);

// ============ Login ============
function initParticles() {
    const container = document.getElementById('particles');
    if (!container) return;
    for (let i = 0; i < 15; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        const size = Math.random() * 300 + 50;
        particle.style.width = size + 'px';
        particle.style.height = size + 'px';
        particle.style.left = Math.random() * 100 + '%';
        particle.style.top = Math.random() * 100 + '%';
        particle.style.animationDelay = Math.random() * 10 + 's';
        particle.style.animationDuration = (Math.random() * 15 + 15) + 's';
        container.appendChild(particle);
    }
}

async function doLogin(e) {
    e.preventDefault();
    const usuario = document.getElementById('loginUser').value;
    const senha = document.getElementById('loginPass').value;
    const errorEl = document.getElementById('loginError');
    const btn = document.getElementById('loginBtn');

    btn.textContent = 'Entrando...';
    btn.disabled = true;
    errorEl.style.display = 'none';

    try {
        const result = await apiPost('login', { usuario, senha });
        if (result.success) {
            if (result.csrf_token) __csrfToken = result.csrf_token;
            const setorNormalizado = String(result.setor || '').trim().toLowerCase();
            if (result.require_password_change) {
                const changed = await enforceStrongPasswordChangeOnLogin(senha);
                if (!changed) {
                    btn.textContent = 'Entrar';
                    btn.disabled = false;
                    return false;
                }
            }
            localStorage.setItem('loggedIn', 'true');
            localStorage.setItem('userName', result.nome);
            localStorage.setItem('userType', result.tipo);
            localStorage.setItem('userSetor', setorNormalizado);
            if (result.foto_perfil) localStorage.setItem('foto_perfil', result.foto_perfil); else localStorage.removeItem('foto_perfil');
            loadSavedTheme();

            if (setorNormalizado === 'visitador') {
                window.location.href = 'visitador.html';
            } else {
                showApp(result.nome, result.tipo, result.foto_perfil || null, setorNormalizado);
            }
        } else {
            errorEl.textContent = result.error || 'Credenciais inválidas';
            errorEl.style.display = 'block';
        }
    } catch (err) {
        errorEl.textContent = 'Erro de conexão com o servidor';
        errorEl.style.display = 'block';
    }

    btn.textContent = 'Entrar';
    btn.disabled = false;
    return false;
}

function validateStrongPasswordClientSide(password) {
    if (!password || password.length < 8) return false;
    if (!/[A-Z]/.test(password)) return false;
    if (!/[^a-zA-Z0-9]/.test(password)) return false;
    return true;
}

function strongPasswordRuleText() {
    return 'mínimo 8 caracteres, 1 letra maiúscula e 1 caractere especial.';
}

async function enforceStrongPasswordChangeOnLogin(senhaAtual) {
    const errorEl = document.getElementById('loginError');
    const modal = document.getElementById('forcePasswordModal');
    const form = document.getElementById('forcePasswordForm');
    const inputNova = document.getElementById('forcePasswordNew');
    const inputConfirma = document.getElementById('forcePasswordConfirm');
    const modalError = document.getElementById('forcePasswordError');
    const btnSave = document.getElementById('forcePassSave');
    const btnCancel = document.getElementById('forcePassCancel');
    const btnClose = document.getElementById('forcePassClose');

    // Fallback mínimo caso o HTML do modal não esteja disponível.
    if (!modal || !form || !inputNova || !inputConfirma || !modalError || !btnSave || !btnCancel || !btnClose) {
        if (errorEl) {
            errorEl.textContent = 'Atualize a página para concluir a troca obrigatória de senha.';
            errorEl.style.display = 'block';
        }
        return false;
    }

    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    inputNova.value = '';
    inputConfirma.value = '';
    modalError.style.display = 'none';
    modalError.textContent = '';
    setTimeout(() => inputNova.focus(), 30);

    const showModalError = (msg) => {
        modalError.textContent = msg;
        modalError.style.display = 'block';
    };

    const closeAndBlockLogin = async () => {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        try { await apiPost('logout', {}); } catch (e) {}
        localStorage.removeItem('loggedIn');
        localStorage.removeItem('userName');
        localStorage.removeItem('userType');
        localStorage.removeItem('userSetor');
        localStorage.removeItem('foto_perfil');
        if (errorEl) {
            errorEl.textContent = 'Login bloqueado até definir uma senha forte.';
            errorEl.style.display = 'block';
        }
    };

    const result = await new Promise((resolve) => {
        const cleanup = () => {
            form.removeEventListener('submit', onSubmit);
            btnCancel.removeEventListener('click', onCancel);
            btnClose.removeEventListener('click', onCancel);
            document.removeEventListener('keydown', onEsc);
        };

        const onEsc = (e) => {
            if (e.key === 'Escape') onCancel();
        };

        const onCancel = async () => {
            cleanup();
            await closeAndBlockLogin();
            resolve(false);
        };

        const onSubmit = async (e) => {
            e.preventDefault();
            const nova = inputNova.value || '';
            const confirmacao = inputConfirma.value || '';
            modalError.style.display = 'none';
            modalError.textContent = '';

            if (nova !== confirmacao) {
                showModalError('A confirmação da nova senha não confere.');
                return;
            }
            if (!validateStrongPasswordClientSide(nova)) {
                showModalError('Senha inválida: ' + strongPasswordRuleText());
                return;
            }
            if (nova === senhaAtual) {
                showModalError('A nova senha deve ser diferente da senha atual.');
                return;
            }

            btnSave.disabled = true;
            btnSave.textContent = 'Salvando...';
            try {
                const res = await apiPost('update_my_password', { senha_atual: senhaAtual, senha_nova: nova });
                if (res && res.success) {
                    cleanup();
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                    resolve(true);
                    return;
                }
                showModalError((res && res.error) ? res.error : 'Não foi possível atualizar sua senha.');
            } catch (err) {
                showModalError('Erro de conexão ao atualizar senha.');
            } finally {
                btnSave.disabled = false;
                btnSave.textContent = 'Salvar e continuar';
            }
        };

        form.addEventListener('submit', onSubmit);
        btnCancel.addEventListener('click', onCancel);
        btnClose.addEventListener('click', onCancel);
        document.addEventListener('keydown', onEsc);
    });

    if (result) {
        return true;
    }
    return false;
}

function showApp(nome, tipo, fotoPerfil, setorInformado) {
    const setor = String(setorInformado || localStorage.getItem('userSetor') || '').trim().toLowerCase();
    if (setor === 'visitador' && !window.location.pathname.includes('visitador.html')) {
        window.location.href = 'visitador.html';
        return;
    }

    // Se estivermos na página do visitador, não executamos o resto (layout diferente)
    if (window.location.pathname.includes('visitador.html')) {
        const lp = document.getElementById('loginPage');
        if (lp) lp.style.display = 'none';
        return;
    }

    const lp = document.getElementById('loginPage');
    if (lp) lp.style.display = 'none';

    const appLayout = document.getElementById('appLayout');
    if (appLayout) appLayout.style.display = 'flex';

    document.getElementById('userName').textContent = nome || 'Admin';
    document.getElementById('userRole').textContent = tipo === 'admin' ? 'Administrador' : 'Usuário';
    applyAvatarAdmin((nome || 'A').charAt(0).toUpperCase(), fotoPerfil || localStorage.getItem('foto_perfil') || null);
    setupAvatarUploadAdmin();

    // Show admin nav only for admin users
    document.querySelectorAll('.admin-only-nav').forEach(el => {
        el.style.display = tipo === 'admin' ? '' : 'none';
    });

    initPeriodFilter();
    loadDashboard();
    if (document.getElementById('btnNotifications')) {
        initAdminNotifications();
        loadNotificacoesFromAPI();
    }
}

function initPeriodFilter() {
    const deEl = document.getElementById('dataDeFilter');
    const ateEl = document.getElementById('dataAteFilter');
    if (!deEl || !ateEl) return;
    const hoje = new Date();
    const hojeStr = hoje.toISOString().slice(0, 10);
    // Padrão: primeiro dia do mês até hoje para trazer dados no carregamento
    const primeiroDiaMes = new Date(hoje.getFullYear(), hoje.getMonth(), 1).toISOString().slice(0, 10);
    if (!deEl.value) deEl.value = primeiroDiaMes;
    if (!ateEl.value) ateEl.value = hojeStr;
    if (ateEl.value < deEl.value) ateEl.value = deEl.value;
}

function applyAvatarAdmin(initial, fotoUrl) {
    const av = document.getElementById('userAvatar');
    const img = document.getElementById('userAvatarImg');
    if (!av) return;
    if (img) {
        if (fotoUrl) {
            var url = API_URL + '?action=get_foto_perfil&t=' + Date.now();
            img.src = url;
            img.alt = 'Foto de perfil';
            img.style.display = '';
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

function setupAvatarUploadAdmin() {
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
            const response = await fetch(`${API_URL}?action=upload_foto_perfil`, {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            const text = await response.text();
            let res = null;
            try { res = text ? JSON.parse(text) : null; } catch (_) {}
            if (res && res.success && res.foto_perfil) {
                localStorage.setItem('foto_perfil', res.foto_perfil);
                const nome = document.getElementById('userName')?.textContent || 'A';
                applyAvatarAdmin(nome.charAt(0), res.foto_perfil);
            } else {
                const msg = (res && res.error) ? res.error : (!response.ok ? 'Erro do servidor (' + response.status + '). Verifique a pasta uploads/avatars no servidor.' : 'Não foi possível alterar a foto.');
                alert(msg);
            }
        } catch (e) {
            alert('Erro ao enviar a foto. Verifique a conexão.');
        }
        input.value = '';
    };
}

async function doLogout() {
    await apiGet('logout');
    localStorage.clear();
    const appLayout = document.getElementById('appLayout');
    if (appLayout) appLayout.style.display = 'none';
    const loginPage = document.getElementById('loginPage');
    if (loginPage) loginPage.style.display = 'flex';
    const loginUser = document.getElementById('loginUser');
    if (loginUser) loginUser.value = '';
    const loginPass = document.getElementById('loginPass');
    if (loginPass) loginPass.value = '';
}

// ============ Navigation ============
function navigateTo(page) {
    if (window.innerWidth <= 768) closeMobileSidebar();
    currentPage = page;

    document.querySelectorAll('.section-page').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));

    const pageEl = document.getElementById('page-' + page);
    if (pageEl) pageEl.classList.add('active');

    const navItem = document.querySelector(`.nav-item[data-page="${page}"]`);
    if (navItem) navItem.classList.add('active');

    const titles = {
        'dashboard': 'Dashboard',
        'faturamento': 'Faturamento',
        'prescritores': 'Prescritores',
        'visitadores': 'Visitadores',
        'visitas': 'Visitas',
        'clientes': 'Clientes',
        'produtos': 'Produtos',
        'equipe': 'Equipe',
        'insights': 'Insights Estratégicos',
        'importar': 'Importar Dados',
        'admin': 'Administração'
    };
    document.getElementById('pageTitle').textContent = titles[page] || page;

    loadPageData(page);
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (window.innerWidth <= 768) return; // No mobile, usar toggleMobileSidebar
    sidebar.classList.toggle('collapsed');
    const icon = toggle.querySelector('i');
    icon.className = sidebar.classList.contains('collapsed') ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
}

function toggleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('mobileMenuBtn');
    if (!sidebar || !overlay || !btn) return;
    sidebar.classList.toggle('open');
    overlay.classList.toggle('visible', sidebar.classList.contains('open'));
    const icon = btn.querySelector('i');
    icon.className = sidebar.classList.contains('open') ? 'fas fa-times' : 'fas fa-bars';
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('mobileMenuBtn');
    if (!sidebar || !overlay || !btn) return;
    sidebar.classList.remove('open');
    overlay.classList.remove('visible');
    btn.querySelector('i').className = 'fas fa-bars';
}

async function loadYears() {
    const select = document.getElementById('anoFilter');
    if (!select) return;
    const data = await apiGet('anos');
    select.innerHTML = '<option value="">Todos os anos</option>';
    data.forEach(item => {
        select.innerHTML += `<option value="${item.ano}">${item.ano}</option>`;
    });
}

function getFilterParams() {
    const deEl = document.getElementById('dataDeFilter');
    const ateEl = document.getElementById('dataAteFilter');
    const hoje = new Date();
    const hojeStr = hoje.toISOString().slice(0, 10);
    const primeiroDiaMes = new Date(hoje.getFullYear(), hoje.getMonth(), 1).toISOString().slice(0, 10);
    const dataDe = deEl?.value || primeiroDiaMes;
    let dataAte = ateEl?.value || hojeStr;
    if (dataAte < dataDe) dataAte = dataDe;
    return { data_de: dataDe, data_ate: dataAte };
}

function onFilterChange() {
    const ateEl = document.getElementById('dataAteFilter');
    const deEl = document.getElementById('dataDeFilter');
    if (ateEl && deEl && ateEl.value && deEl.value && ateEl.value < deEl.value) ateEl.value = deEl.value;
    currentYear = deEl?.value ? deEl.value.slice(0, 4) : '';
    loadPageData(currentPage);
}

function refreshData() {
    loadPageData(currentPage);
}

// ============ Notificações (sino) – Admin ============
function getNotificationsListKey() {
    return 'mypharm_notif_list_admin_' + (localStorage.getItem('userName') || '').replace(/\s+/g, '_');
}
function getStoredNotificationsList() {
    try {
        const raw = localStorage.getItem(getNotificationsListKey());
        return raw ? JSON.parse(raw) : [];
    } catch (e) { return []; }
}
function getNotificationsReadKey() {
    return 'mypharm_notif_read_admin_' + (localStorage.getItem('userName') || '').replace(/\s+/g, '_');
}
function getReadNotifications() {
    try {
        const raw = localStorage.getItem(getNotificationsReadKey());
        return raw ? JSON.parse(raw) : [];
    } catch (e) { return []; }
}
function markNotificationRead(id) {
    const read = getReadNotifications();
    if (read.indexOf(id) === -1) read.push(id);
    try { localStorage.setItem(getNotificationsReadKey(), JSON.stringify(read)); } catch (e) {}
    updateNotificationsBadge();
}
function deleteNotification(id) {
    const list = getStoredNotificationsList().filter(v => v.id !== id);
    try { localStorage.setItem(getNotificationsListKey(), JSON.stringify(list)); } catch (e) {}
    renderAllNotifications();
    updateNotificationsBadge();
}
function escNotif(x) {
    return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

let __notificacoesApi = [];
let __mensagensApi = [];
let __mensagensRecebidasApi = [];

async function loadNotificacoesFromAPI() {
    const btn = document.getElementById('btnNotifications');
    if (!btn) return;
    try {
        const r = await apiGet('list_notificacoes');
        if (r && r.success) {
            __notificacoesApi = r.notificacoes || [];
            __mensagensApi = r.mensagens || [];
            __mensagensRecebidasApi = r.mensagens_recebidas || [];
        }
    } catch (e) {
        __notificacoesApi = [];
        __mensagensApi = [];
        __mensagensRecebidasApi = [];
    }
    renderAllNotifications();
    updateNotificationsBadge();
}

async function loadUsuariosParaMensagem() {
    const sel = document.getElementById('notifParaUsuarioSelect');
    if (!sel) return;
    sel.innerHTML = '<option value="">Carregando...</option>';
    sel.disabled = true;
    try {
        const r = await apiGet('list_usuarios_para_mensagem');
        sel.disabled = false;
        sel.innerHTML = '<option value="">Selecione o usuário</option>';
        if (r && r.success && Array.isArray(r.usuarios)) {
            r.usuarios.forEach(u => {
                sel.appendChild(new Option(u.nome || 'Usuário ' + u.id, String(u.id)));
            });
            if (r.usuarios.length === 0) {
                sel.appendChild(new Option('Nenhum outro usuário', ''));
            }
        } else {
            sel.innerHTML = '<option value="">' + (r && r.error ? r.error : 'Erro ao carregar') + '</option>';
        }
    } catch (e) {
        sel.disabled = false;
        sel.innerHTML = '<option value="">Erro ao carregar</option>';
    }
}

function renderAllNotifications() {
    const listEl = document.getElementById('notificationsListAll');
    const emptyEl = document.getElementById('notificationsEmptyAll');
    if (!listEl) return;
    const agendaList = getStoredNotificationsList();
    const read = getReadNotifications();
    let itemsHtml = '';
    (__notificacoesApi || []).forEach(n => {
        const isRead = !!n.lida;
        const tipoLabel = n.tipo === '30_dias_sem_visita' ? '30 dias sem visita' : (n.tipo === 'dias_sem_compra' ? (n.dias_sem_compra || 15) + ' dias sem compra' : 'Sistema');
        itemsHtml += '<div class="notification-item sistema ' + (isRead ? 'read' : '') + '" data-origin="sistema" data-notif-id="' + escNotif(n.id) + '" data-prescritor="' + escNotif(n.prescritor_nome) + '">' +
            '<div class="notification-item-main"><div class="notification-sender">' + escNotif(tipoLabel) + '</div>' +
            '<div class="notification-title">' + escNotif(n.prescritor_nome || '-') + '</div>' +
            '<div class="notification-date">' + escNotif(n.mensagem || '') + '</div></div>' +
            '<div class="notification-item-actions"><button type="button" class="notification-btn-lida" data-id="' + n.id + '" title="Marcar como lida"><i class="fas fa-check"></i></button>' +
            '<button type="button" class="notification-item-delete" data-origin="sistema" data-id="' + n.id + '" title="Apagar"><i class="fas fa-trash-alt"></i></button></div></div>';
    });
    (__mensagensApi || []).forEach(m => {
        const dataFmt = (m.criado_em || '').slice(0, 16).replace('T', ' ');
        itemsHtml += '<div class="notification-item usuario" data-origin="usuario" data-prescritor="' + escNotif(m.prescritor_nome) + '">' +
            '<div class="notification-item-main"><div class="notification-sender">' + escNotif(m.autor || 'Colega') + '</div>' +
            '<div class="notification-title">' + escNotif(m.prescritor_nome) + '</div>' +
            '<div class="notification-date">' + escNotif(m.mensagem) + (dataFmt ? ' · ' + dataFmt : '') + '</div></div></div>';
    });
    (__mensagensRecebidasApi || []).forEach(m => {
        const dataFmt = (m.criado_em || '').slice(0, 16).replace('T', ' ');
        const isRead = !!m.lida;
        itemsHtml += '<div class="notification-item msg-usuario ' + (isRead ? 'read' : '') + '" data-origin="msg_usuario" data-msg-id="' + m.id + '">' +
            '<div class="notification-item-main"><div class="notification-sender">' + escNotif(m.autor || 'Usuário') + '</div>' +
            '<div class="notification-title">Mensagem para você</div>' +
            '<div class="notification-date">' + escNotif(m.mensagem) + (dataFmt ? ' · ' + dataFmt : '') + '</div></div>' +
            '<div class="notification-item-actions">' +
            '<button type="button" class="notification-btn-lida-msg" data-id="' + m.id + '" title="Marcar como lida"><i class="fas fa-check"></i></button>' +
            '<button type="button" class="notification-btn-ocultar-msg" data-id="' + m.id + '" title="Excluir da lista"><i class="fas fa-trash-alt"></i></button></div></div>';
    });
    agendaList.forEach(v => {
        const isRead = read.indexOf(v.id) !== -1;
        const dataFmt = v.data_agendada ? (String(v.data_agendada).trim().length >= 10 ? String(v.data_agendada).slice(8, 10) + '/' + String(v.data_agendada).slice(5, 7) + '/' + String(v.data_agendada).slice(0, 4) : '') : '';
        const sub = (dataFmt + ((v.hora || '').trim() ? ' às ' + (v.hora || '').trim() : '')) || 'Hoje';
        itemsHtml += '<div class="notification-item visita ' + (isRead ? 'read' : '') + '" data-origin="agenda" data-id="' + escNotif(v.id) + '" data-prescritor="' + escNotif(v.prescritor) + '">' +
            '<div class="notification-item-main"><div class="notification-sender">Agenda</div>' +
            '<div class="notification-title">' + escNotif(v.prescritor || '-') + '</div>' +
            '<div class="notification-date">' + escNotif(sub) + '</div></div>' +
            '<button type="button" class="notification-item-delete" data-origin="agenda" data-id="' + escNotif(v.id) + '" title="Apagar"><i class="fas fa-trash-alt"></i></button></div>';
    });
    if (emptyEl) emptyEl.style.display = itemsHtml ? 'none' : 'block';
    if (itemsHtml) {
        let wrap = listEl.querySelector('.notifications-items-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'notifications-items-wrap';
            listEl.insertBefore(wrap, emptyEl);
        }
        wrap.innerHTML = itemsHtml;
    } else {
        const w = listEl.querySelector('.notifications-items-wrap');
        if (w) w.innerHTML = '';
    }
}

function updateNotificationsBadge() {
    const notifs = __notificacoesApi || [];
    const msgs = __mensagensApi || [];
    const msgRec = __mensagensRecebidasApi || [];
    const list = getStoredNotificationsList();
    const read = getReadNotifications();
    const unread = notifs.filter(n => !n.lida).length + msgs.length + msgRec.filter(m => !m.lida).length + list.filter(v => read.indexOf(v.id) === -1).length;
    const badge = document.getElementById('notificationsBadge');
    if (!badge) return;
    badge.textContent = unread > 99 ? '99+' : String(unread);
    badge.style.display = unread > 0 ? 'inline-flex' : 'none';
}

async function enviarMensagemUsuarioUI() {
    const sel = document.getElementById('notifParaUsuarioSelect');
    const msg = (document.getElementById('notifMensagemUsuarioInput') || {}).value;
    const paraId = sel ? parseInt(sel.value, 10) : 0;
    if (!paraId || !msg) {
        alert('Selecione o usuário e escreva a mensagem.');
        return;
    }
    const btn = document.getElementById('btnEnviarMsgUsuario');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }
    try {
        const r = await apiPost('enviar_mensagem_usuario', { para_usuario_id: paraId, mensagem: msg.trim() });
        if (r && r.success) {
            if (document.getElementById('notifMensagemUsuarioInput')) document.getElementById('notifMensagemUsuarioInput').value = '';
            loadNotificacoesFromAPI();
        } else alert(r && r.error ? r.error : 'Erro ao enviar.');
    } catch (e) { alert('Erro de conexão.'); }
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar para usuário'; }
}

function closeNotificationsPanel() {
    const panel = document.getElementById('notificationsPanel');
    const backdrop = document.getElementById('notificationsBackdrop');
    if (panel) panel.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
    document.removeEventListener('click', closeNotificationsOnClickOutside);
}

function closeNotificationsOnClickOutside(e) {
    const panel = document.getElementById('notificationsPanel');
    const btn = document.getElementById('btnNotifications');
    const backdrop = document.getElementById('notificationsBackdrop');
    if (panel && btn && !panel.contains(e.target) && !btn.contains(e.target) && !(backdrop && backdrop.contains(e.target))) {
        closeNotificationsPanel();
    }
}

function toggleNotificationsPanel(e) {
    if (e) e.stopPropagation();
    const panel = document.getElementById('notificationsPanel');
    const btn = document.getElementById('btnNotifications');
    const backdrop = document.getElementById('notificationsBackdrop');
    if (!panel || !btn) return;
    const isOpen = panel.classList.toggle('open');
    const isMobileOrTablet = window.innerWidth <= 1024;
    if (backdrop) {
        if (isOpen && isMobileOrTablet) backdrop.classList.add('open');
        else backdrop.classList.remove('open');
    }
    if (isOpen) {
        Promise.all([loadNotificacoesFromAPI(), loadUsuariosParaMensagem()]).catch(() => {});
        setTimeout(() => document.addEventListener('click', closeNotificationsOnClickOutside), 0);
    } else {
        document.removeEventListener('click', closeNotificationsOnClickOutside);
    }
}

function initAdminNotifications() {
    const panel = document.getElementById('notificationsPanel');
    if (!panel || panel.dataset.notificationsBound === '1') return;
    panel.dataset.notificationsBound = '1';
    panel.addEventListener('click', function (e) {
        const btnLida = e.target.closest('.notification-btn-lida');
        if (btnLida && btnLida.dataset.id) {
            e.stopPropagation();
            e.preventDefault();
            apiPost('marcar_notificacao_lida', { id: parseInt(btnLida.dataset.id, 10) }).then(() => loadNotificacoesFromAPI());
            return;
        }
        const btnLidaMsg = e.target.closest('.notification-btn-lida-msg');
        if (btnLidaMsg && btnLidaMsg.dataset.id) {
            e.stopPropagation();
            e.preventDefault();
            apiPost('marcar_mensagem_usuario_lida', { id: parseInt(btnLidaMsg.dataset.id, 10) }).then(() => loadNotificacoesFromAPI());
            return;
        }
        const btnOcultarMsg = e.target.closest('.notification-btn-ocultar-msg');
        if (btnOcultarMsg && btnOcultarMsg.dataset.id) {
            e.stopPropagation();
            e.preventDefault();
            apiPost('ocultar_mensagem_usuario', { id: parseInt(btnOcultarMsg.dataset.id, 10) }).then(() => loadNotificacoesFromAPI());
            return;
        }
        const btnDelete = e.target.closest('.notification-item-delete');
        if (btnDelete && btnDelete.dataset.id) {
            e.stopPropagation();
            e.preventDefault();
            if (btnDelete.dataset.origin === 'sistema') {
                apiPost('apagar_notificacao', { id: parseInt(btnDelete.dataset.id, 10) }).then(() => loadNotificacoesFromAPI());
            } else {
                deleteNotification(btnDelete.dataset.id);
            }
            return;
        }
        const item = e.target.closest('.notification-item');
        if (item && item.dataset.prescritor) {
            if (item.dataset.origin === 'agenda' && item.dataset.id) markNotificationRead(item.dataset.id);
            item.classList.add('read');
        }
    });
}

// ============ Load Page Data ============
async function loadPageData(page) {
    showLoading();
    try {
        switch (page) {
            case 'dashboard': await loadDashboard(); break;
            case 'faturamento': await loadFaturamento(); break;
            case 'prescritores': await loadPrescritores(); break;
            case 'clientes': await loadClientes(); break;
            case 'produtos': await loadProdutos(); break;
            case 'equipe': await loadEquipe(); break;
            case 'visitadores': await loadVisitadoresPage(); break;
            case 'visitas': await loadVisitasPage(); break;
            case 'insights': await loadInsights(); break;
            case 'admin': await loadAdmin(); break;
        }
    } catch (err) {
        console.error('Erro ao carregar dados:', err);
    }
    hideLoading();
}

function showLoading() {
    const el = document.getElementById('loadingOverlay');
    if (el) el.style.display = 'flex';
}

function hideLoading() {
    const el = document.getElementById('loadingOverlay');
    if (el) el.style.display = 'none';
}

// ============ DASHBOARD ============
async function loadDashboard() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate };

    // Carregar KPIs e gráficos em paralelo; exibir tudo junto quando terminar
    try {
        const [kpis, mensal, formas, canais, statusData] = await Promise.all([
            apiGet('kpis', params),
            apiGet('faturamento_mensal', params),
            apiGet('top_formas', { ...params, limit: 8 }),
            apiGet('canais', params),
            apiGet('itens_status', params)
        ]);

        if (kpis) renderKPIs(kpis);
        if (mensal) renderChartFaturamentoMensal(mensal);
        if (formas) renderChartFormas(formas);
        if (canais) renderChartCanais(canais);
        if (statusData) renderChartStatus(statusData);
    } catch (err) {
        console.error('Erro ao carregar dashboard:', err);
    }
}

function renderKPIs(kpis) {
    const grid = document.getElementById('kpiGrid');
    const pagos = kpis.status_financeiro?.find(s => s.status_financeiro === 'Pago');
    const pendentes = kpis.status_financeiro?.find(s => s.status_financeiro === 'Pendente');

    grid.innerHTML = `
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
            <div class="kpi-label">Vendas Aprovadas</div>
            <div class="kpi-value">${formatCompactMoney(kpis.vendas_aprovadas != null ? kpis.vendas_aprovadas : kpis.faturamento_total)}</div>
            <div class="kpi-sub">Faturamento total: ${formatCompactMoney(kpis.faturamento_total)} · Receita bruta: ${formatCompactMoney(kpis.receita_bruta)}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="kpi-label">Lucro Bruto</div>
            <div class="kpi-value">${formatCompactMoney((kpis.vendas_aprovadas != null ? kpis.vendas_aprovadas : kpis.faturamento_total) - kpis.custo_total)}</div>
            <div class="kpi-sub">Margem: <span class="trend-up">${kpis.margem_lucro}%</span></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-shopping-cart"></i></div>
            <div class="kpi-label">Total de Pedidos</div>
            <div class="kpi-value">${formatNumber(kpis.total_pedidos_unicos != null ? kpis.total_pedidos_unicos : kpis.total_pedidos)}</div>
            <div class="kpi-sub">Ticket médio: ${formatMoney(kpis.ticket_medio)}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-users"></i></div>
            <div class="kpi-label">Clientes Únicos</div>
            <div class="kpi-value">${formatNumber(kpis.total_clientes)}</div>
            <div class="kpi-sub">Prescritores: ${formatNumber(kpis.total_prescritores)}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
            <div class="kpi-label">Pedidos Pagos</div>
            <div class="kpi-value">${formatNumber(pagos?.total || 0)}</div>
            <div class="kpi-sub"><span class="trend-up">Pagamentos recebidos</span></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon"><i class="fas fa-clock"></i></div>
            <div class="kpi-label">Pedidos Pendentes</div>
            <div class="kpi-value">${formatNumber(pendentes?.total || 0)}</div>
            <div class="kpi-sub"><span class="trend-down">Aguardando pagamento</span></div>
        </div>
    `;
}

function renderChartFaturamentoMensal(data) {
    destroyChart('chartFaturamentoMensal');
    const ctx = document.getElementById('chartFaturamentoMensal');

    const labels = data.map(d => MONTH_NAMES[(d.mes || 1) - 1]);

    charts['chartFaturamentoMensal'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Faturamento',
                    data: data.map(d => parseFloat(d.faturamento) || 0),
                    backgroundColor: 'rgba(230, 57, 70, 0.7)',
                    borderColor: '#E63946',
                    borderWidth: 1,
                    borderRadius: 6,
                    barPercentage: 0.6,
                },
                {
                    label: 'Custo',
                    data: data.map(d => parseFloat(d.custo) || 0),
                    backgroundColor: 'rgba(17, 138, 178, 0.5)',
                    borderColor: '#118AB2',
                    borderWidth: 1,
                    borderRadius: 6,
                    barPercentage: 0.6,
                }
            ]
        },
        options: {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y)}`
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: v => formatCompactMoney(v) },
                    grid: { color: 'rgba(255,255,255,0.04)' }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
}

function renderChartFormas(data) {
    destroyChart('chartFormas');
    const ctx = document.getElementById('chartFormas');

    charts['chartFormas'] = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.map(d => truncateText(d.forma_farmaceutica, 20)),
            datasets: [{
                data: data.map(d => parseFloat(d.faturamento) || 0),
                backgroundColor: CHART_COLORS.slice(0, data.length),
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            cutout: '60%',
            plugins: {
                legend: { position: 'right', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = ((ctx.parsed / total) * 100).toFixed(1);
                            return `${ctx.label}: ${formatMoney(ctx.parsed)} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

function renderChartCanais(data) {
    destroyChart('chartCanais');
    const ctx = document.getElementById('chartCanais');

    charts['chartCanais'] = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: data.map(d => d.canal_atendimento || 'N/A'),
            datasets: [{
                data: data.map(d => parseInt(d.total) || 0),
                backgroundColor: CHART_COLORS.slice(0, data.length),
                borderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = ((ctx.parsed / total) * 100).toFixed(1);
                            return `${ctx.label}: ${formatNumber(ctx.parsed)} pedidos (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

function renderChartStatus(data) {
    destroyChart('chartStatus');
    const ctx = document.getElementById('chartStatus');

    const statusColors = {
        'Aprovado': '#06D6A0',
        'Recusado': '#EF476F',
        'No Carrinho': '#FFD166',
        'Pendente': '#118AB2'
    };

    charts['chartStatus'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => d.status || 'N/A'),
            datasets: [{
                label: 'Quantidade',
                data: data.map(d => parseInt(d.total) || 0),
                backgroundColor: data.map(d => statusColors[d.status] || '#7B68EE'),
                borderRadius: 8,
                barPercentage: 0.5
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterLabel: ctx => `Valor: ${formatMoney(data[ctx.dataIndex]?.valor_total || 0)}`
                    }
                }
            },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { callback: v => formatNumber(v) } },
                y: { grid: { display: false } }
            }
        }
    });
}

// ============ FATURAMENTO ============
async function loadFaturamento() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate };
    const comparativo = await apiGet('comparativo_anual', params);
    renderFaturamentoAnual(comparativo);
    renderChartComparativoAnual(comparativo);
    renderChartMargemAnual(comparativo);
    renderChartTicketAnual(comparativo);
}

function renderFaturamentoAnual(data) {
    const container = document.getElementById('faturamentoAnual');
    container.innerHTML = data.map(d => `
        <div class="comparison-item">
            <div class="comparison-year">${d.ano}</div>
            <div class="comparison-value">${formatCompactMoney(d.faturamento)}</div>
            <div class="comparison-detail">${formatNumber(d.total_pedidos)} pedidos &bull; ${formatNumber(d.total_clientes)} clientes</div>
        </div>
    `).join('');
}

function renderChartComparativoAnual(data) {
    destroyChart('chartComparativoAnual');
    const ctx = document.getElementById('chartComparativoAnual');

    charts['chartComparativoAnual'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => d.ano),
            datasets: [
                {
                    label: 'Faturamento',
                    data: data.map(d => parseFloat(d.faturamento) || 0),
                    backgroundColor: 'rgba(230, 57, 70, 0.7)',
                    borderColor: '#E63946',
                    borderWidth: 1,
                    borderRadius: 6,
                },
                {
                    label: 'Custo',
                    data: data.map(d => parseFloat(d.custo) || 0),
                    backgroundColor: 'rgba(17, 138, 178, 0.5)',
                    borderColor: '#118AB2',
                    borderWidth: 1,
                    borderRadius: 6,
                },
                {
                    label: 'Lucro Bruto',
                    data: data.map(d => parseFloat(d.lucro_bruto) || 0),
                    backgroundColor: 'rgba(6, 214, 160, 0.5)',
                    borderColor: '#06D6A0',
                    borderWidth: 1,
                    borderRadius: 6,
                }
            ]
        },
        options: {
            plugins: {
                tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y)}` } }
            },
            scales: {
                y: { ticks: { callback: v => formatCompactMoney(v) }, grid: { color: 'rgba(255,255,255,0.04)' } },
                x: { grid: { display: false } }
            }
        }
    });
}

function renderChartMargemAnual(data) {
    destroyChart('chartMargemAnual');
    const ctx = document.getElementById('chartMargemAnual');

    charts['chartMargemAnual'] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.ano),
            datasets: [{
                label: 'Margem de Lucro (%)',
                data: data.map(d => parseFloat(d.margem_pct) || 0),
                borderColor: '#06D6A0',
                backgroundColor: 'rgba(6, 214, 160, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 6,
                pointHoverRadius: 8,
                pointBackgroundColor: '#06D6A0',
                pointBorderColor: '#0F1117',
                pointBorderWidth: 3,
            }]
        },
        options: {
            plugins: {
                tooltip: { callbacks: { label: ctx => `Margem: ${ctx.parsed.y.toFixed(1)}%` } }
            },
            scales: {
                y: { ticks: { callback: v => v + '%' }, grid: { color: 'rgba(255,255,255,0.04)' } },
                x: { grid: { display: false } }
            }
        }
    });
}

function renderChartTicketAnual(data) {
    destroyChart('chartTicketAnual');
    const ctx = document.getElementById('chartTicketAnual');

    charts['chartTicketAnual'] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.ano),
            datasets: [
                {
                    label: 'Ticket Médio (Líq)',
                    data: data.map(d => parseFloat(d.ticket_medio) || 0),
                    borderColor: '#FFD166',
                    backgroundColor: 'rgba(255, 209, 102, 0.1)',
                    fill: false,
                    tension: 0.4,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#FFD166',
                    pointBorderColor: '#0F1117',
                    pointBorderWidth: 3,
                },
                {
                    label: 'Ticket Bruto',
                    data: data.map(d => parseFloat(d.ticket_bruto) || 0),
                    borderColor: '#EE6C4D',
                    backgroundColor: 'rgba(238, 108, 77, 0.1)',
                    fill: false,
                    tension: 0.4,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#EE6C4D',
                    pointBorderColor: '#0F1117',
                    pointBorderWidth: 3,
                    borderDash: [5, 5]
                }
            ]
        },
        options: {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y)}`
                    }
                }
            },
            scales: {
                y: { ticks: { callback: v => formatCompactMoney(v) }, grid: { color: 'rgba(255,255,255,0.04)' } },
                x: { grid: { display: false } }
            }
        }
    });
}

// ============ PRESCRITORES ============
async function loadPrescritores() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate };
    const [prescritores, profissoes, visitadores] = await Promise.all([
        apiGet('top_prescritores', { ...params, limit: 10 }),
        apiGet('profissoes', params),
        apiGet('visitadores', params)
    ]);

    renderTablePrescritores(prescritores);
    renderChartProfissoes(profissoes);
    renderChartVisitadores(visitadores);
}

// ============ VISITADORES (NOVA PÁGINA) ============
async function loadVisitadoresPage() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate };
    const visitadores = await apiGet('visitadores', params);

    // Sort by revenue descending
    visitadores.sort((a, b) => parseFloat(b.total_valor_aprovado) - parseFloat(a.total_valor_aprovado));

    renderKPIsVisitadores(visitadores);
    renderChartVisitadoresRanking(visitadores);
    renderTableVisitadoresPage(visitadores);
}

// ============ VISITAS (PÁGINA ADMIN) ============
let __visitasRelatorioCache = null; // para mapa (dropdown visitador)

async function loadVisitasPage() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate };
    let lista = [];
    try {
        lista = await apiGet('admin_visitas', params) || [];
    } catch (e) {
        console.error('Erro ao carregar visitas', e);
    }
    renderTableVisitasPage(lista);

    try {
        const rel = await apiGet('admin_visitas_relatorio', params) || {};
        __visitasRelatorioCache = rel;
        const totais = rel.totais || {};
        const por_visitador = rel.por_visitador || [];
        const rotas = rel.rotas || [];
        const pontos_rotas = rel.pontos_rotas || [];
        const pontos_atendimento = rel.pontos_atendimento || [];
        const totalKm = por_visitador.reduce((s, p) => s + parseFloat(p.total_km_rotas || 0), 0);
        renderVisitasKPIs(totais, totalKm);
        renderVisitasPorVisitador(por_visitador);
        renderRotasDetalhes(rotas);
        renderVisitasMapaVisitadorOptions(por_visitador);
        renderMapaRotas(pontos_rotas, '', pontos_atendimento);
    } catch (e) {
        console.error('Erro ao carregar relatório visitas', e);
        renderVisitasKPIs({}, 0);
        renderVisitasPorVisitador([]);
        renderRotasDetalhes([]);
        renderMapaRotas([], '', []);
    }
}

function renderVisitasKPIs(totais, totalKm) {
    const elPeriodo = document.getElementById('visitasKpiPeriodo');
    const elSemana = document.getElementById('visitasKpiSemana');
    const elKm = document.getElementById('visitasKpiKm');
    if (elPeriodo) elPeriodo.textContent = formatNumber(totais.total_visitas_periodo ?? 0);
    if (elSemana) elSemana.textContent = formatNumber(totais.total_visitas_semana_atual ?? 0);
    if (elKm) elKm.textContent = (totalKm > 0 ? totalKm.toFixed(1) : '0');
}

function renderVisitasPorVisitador(por_visitador) {
    const tbody = document.querySelector('#tableVisitasPorVisitador tbody');
    const emptyEl = document.getElementById('visitasPorVisitadorEmpty');
    if (!tbody) return;
    if (emptyEl) emptyEl.style.display = 'none';
    if (!Array.isArray(por_visitador) || por_visitador.length === 0) {
        tbody.innerHTML = '';
        if (emptyEl) emptyEl.style.display = 'block';
        return;
    }
    tbody.innerHTML = por_visitador.map(p => `
        <tr>
            <td>${escapeHtml(p.visitador_nome || '—')}</td>
            <td>${formatNumber(p.total_visitas ?? 0)}</td>
            <td>${(p.total_km_rotas ?? 0).toFixed(1)} km</td>
        </tr>
    `).join('');
}

function renderRotasDetalhes(rotas) {
    const tbody = document.querySelector('#tableRotasDetalhes tbody');
    const emptyEl = document.getElementById('rotasDetalhesEmpty');
    if (!tbody) return;
    if (emptyEl) emptyEl.style.display = 'none';
    if (!Array.isArray(rotas) || rotas.length === 0) {
        tbody.innerHTML = '';
        if (emptyEl) emptyEl.style.display = 'block';
        return;
    }
    const fmtDt = (d) => {
        if (!d) return '—';
        const dt = new Date(d);
        return isNaN(dt.getTime()) ? d : dt.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
    };
    tbody.innerHTML = rotas.map(r => `
        <tr>
            <td>${escapeHtml(r.visitador_nome || '—')}</td>
            <td>${fmtDt(r.data_inicio)}</td>
            <td>${fmtDt(r.data_fim)}</td>
            <td>${escapeHtml(r.status || '—')}</td>
            <td>${(r.km ?? 0).toFixed(1)} km</td>
            <td>${formatNumber(r.qtd_pontos ?? 0)}</td>
        </tr>
    `).join('');
}

function renderVisitasMapaVisitadorOptions(por_visitador) {
    const sel = document.getElementById('visitasMapaVisitador');
    if (!sel) return;
    const names = [...new Set((por_visitador || []).map(p => p.visitador_nome).filter(Boolean))].sort();
    sel.innerHTML = '<option value="">Todos os visitadores</option>' +
        names.map(n => `<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`).join('');
}

function renderMapaRotasFromStore() {
    const sel = document.getElementById('visitasMapaVisitador');
    const visitador = sel ? sel.value || '' : '';
    const rel = __visitasRelatorioCache;
    const pontos_rotas = rel && rel.pontos_rotas ? rel.pontos_rotas : [];
    const pontos_atendimento = rel && rel.pontos_atendimento ? rel.pontos_atendimento : [];
    renderMapaRotas(pontos_rotas, visitador, pontos_atendimento);
}

let __mapaRotasVisitasInstance = null;

function renderMapaRotas(pontos_rotas, visitadorSelecionado, pontos_atendimento) {
    const container = document.getElementById('mapaRotasVisitas');
    const emptyEl = document.getElementById('mapaRotasEmpty');
    if (!container) return;
    if (emptyEl) emptyEl.style.display = 'none';
    const filtered = visitadorSelecionado
        ? (pontos_rotas || []).filter(pr => (pr.visitador_nome || '') === visitadorSelecionado)
        : (pontos_rotas || []);
    const temRotas = filtered.length > 0 && filtered.some(pr => (pr.pontos || []).length >= 2);
    const atendimento = (pontos_atendimento || []).filter(pa =>
        Number.isFinite(parseFloat(pa.lat)) && Number.isFinite(parseFloat(pa.lng)) &&
        (!visitadorSelecionado || (pa.visitador_nome || '') === visitadorSelecionado)
    );
    const temAtendimento = atendimento.length > 0;
    if (!temRotas && !temAtendimento) {
        if (__mapaRotasVisitasInstance) {
            __mapaRotasVisitasInstance.remove();
            __mapaRotasVisitasInstance = null;
        }
        if (emptyEl) emptyEl.style.display = 'block';
        return;
    }
    if (typeof L === 'undefined') {
        if (emptyEl) emptyEl.textContent = 'Mapa indisponível.'; emptyEl.style.display = 'block';
        return;
    }
    if (__mapaRotasVisitasInstance) {
        __mapaRotasVisitasInstance.remove();
        __mapaRotasVisitasInstance = null;
    }
    const colors = ['#2563EB', '#059669', '#D97706', '#DC2626', '#7C3AED', '#DB2777'];
    const map = L.map(container, { zoomControl: true }).setView([-8.76, -63.90], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    __mapaRotasVisitasInstance = map;
    let bounds = null;
    filtered.forEach((pr, idx) => {
        const pts = (pr.pontos || []).filter(p => Number.isFinite(p.lat) && Number.isFinite(p.lng));
        if (pts.length < 2) return;
        const latlngs = pts.map(p => [p.lat, p.lng]);
        const color = colors[idx % colors.length];
        const polyline = L.polyline(latlngs, { color, weight: 4, opacity: 0.8 }).addTo(map);
        polyline.bindTooltip(pr.visitador_nome || 'Rota ' + (pr.rota_id || ''), { permanent: false });
        if (!bounds) bounds = L.latLngBounds(latlngs);
        else bounds.extend(latlngs);
    });
    const iconAtendimento = L.divIcon({
        className: '',
        html: '<div style="font-size:30px; line-height:1; color:#E63946; filter:drop-shadow(0 2px 4px rgba(0,0,0,0.35));"><i class="fas fa-location-dot"></i></div>',
        iconSize: [30, 36],
        iconAnchor: [15, 34],
        popupAnchor: [0, -30]
    });
    atendimento.forEach(pa => {
        const lat = parseFloat(pa.lat);
        const lng = parseFloat(pa.lng);
        const dataFmt = pa.data_visita ? new Date(pa.data_visita + 'T12:00:00').toLocaleDateString('pt-BR') : '—';
        const horario = (pa.horario || '').slice(0, 5) || '—';
        const local = (pa.local_visita || '').trim() || '—';
        const prescritor = (pa.prescritor || '—').trim();
        const visitadorNome = (pa.visitador_nome || '').trim();
        const status = (pa.status_visita || '').trim();
        const popupHtml = `<div style="min-width:200px;font-family:inherit;">
            <strong style="font-size:0.95rem;">${escapeHtml(prescritor)}</strong>
            <div style="margin-top:6px;font-size:0.8rem;color:#555;">
                <div><i class="far fa-calendar" style="width:14px;"></i> ${dataFmt} ${horario !== '—' ? ' · ' + horario : ''}</div>
                ${visitadorNome ? `<div><i class="fas fa-user" style="width:14px;"></i> ${escapeHtml(visitadorNome)}</div>` : ''}
                ${local !== '—' ? `<div><i class="fas fa-map-pin" style="width:14px;"></i> ${escapeHtml(local)}</div>` : ''}
                ${status ? `<div><span style="background:#e8f5e9;color:#2e7d32;padding:2px 6px;border-radius:4px;font-size:0.75rem;">${escapeHtml(status)}</span></div>` : ''}
            </div>
        </div>`;
        const marker = L.marker([lat, lng], { icon: iconAtendimento }).addTo(map);
        marker.bindPopup(popupHtml, { maxWidth: 320 });
        if (!bounds) bounds = L.latLngBounds([lat, lng]);
        else bounds.extend([lat, lng]);
    });
    if (bounds) map.fitBounds(bounds.pad(0.15));
    setTimeout(() => map.invalidateSize(), 150);
}

function renderTableVisitasPage(lista) {
    const tbody = document.querySelector('#tableVisitasPage tbody');
    const emptyEl = document.getElementById('visitasPageEmpty');
    if (!tbody) return;
    if (emptyEl) emptyEl.style.display = 'none';
    if (!Array.isArray(lista) || lista.length === 0) {
        tbody.innerHTML = '';
        if (emptyEl) emptyEl.style.display = 'block';
        return;
    }
    const formatDate = (d) => {
        if (!d) return '—';
        const dt = new Date(d);
        return isNaN(dt.getTime()) ? d : dt.toLocaleDateString('pt-BR');
    };
    const formatTime = (h) => h ? String(h).slice(0, 5) : '—';
    const formatInicio = (iv) => {
        if (!iv) return '—';
        const s = String(iv).trim();
        if (/^\d{4}-\d{2}-\d{2}/.test(s) && s.length >= 16) return s.slice(11, 16);
        if (/^\d{4}-\d{2}-\d{2}T/.test(s)) return s.slice(11, 16);
        return s.slice(0, 5) || '—';
    };
    const formatDuracao = (min) => {
        if (min == null || min === '' || isNaN(parseInt(min, 10))) return '—';
        const m = parseInt(min, 10);
        if (m < 60) return m + ' min';
        const h = Math.floor(m / 60);
        const rest = m % 60;
        return rest ? h + 'h ' + rest + 'min' : h + 'h';
    };
    const formatReagend = (r) => {
        if (!r || !String(r).trim()) return '—';
        const s = String(r).trim();
        const match = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{1,2}):(\d{2})?)?/);
        if (match) {
            const [, y, mo, d, h, mi] = match;
            const dt = new Date(parseInt(y, 10), parseInt(mo, 10) - 1, parseInt(d, 10), h ? parseInt(h, 10) : 0, mi ? parseInt(mi, 10) : 0);
            return isNaN(dt.getTime()) ? s : dt.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: h ? 'short' : undefined });
        }
        return s;
    };
    const truncate = (str, max) => {
        const s = (str || '').trim();
        if (!s) return '—';
        return s.length <= max ? s : s.slice(0, max) + '…';
    };
    tbody.innerHTML = lista.map((v, i) => `
        <tr>
            <td>${i + 1}</td>
            <td>${(v.visitador || '—').trim()}</td>
            <td>${(v.prescritor || '—').trim()}</td>
            <td>${formatDate(v.data_visita)}</td>
            <td>${formatInicio(v.inicio_visita)}</td>
            <td>${formatTime(v.horario)}</td>
            <td>${formatDuracao(v.duracao_minutos)}</td>
            <td>${(v.status_visita || '—').trim()}</td>
            <td title="${(v.local_visita || '').replace(/"/g, '&quot;')}">${truncate(v.local_visita, 35)}</td>
            <td>${formatReagend(v.reagendado_para)}</td>
        </tr>
    `).join('');
}

function renderKPIsVisitadores(data) {
    const totalFat = data.reduce((sum, item) => sum + parseFloat(item.total_valor_aprovado || 0), 0);
    const totalPrescritores = data.reduce((sum, item) => sum + parseInt(item.total_prescritores || 0), 0);
    const totalAprovadosCount = data.reduce((sum, item) => sum + parseFloat(item.total_aprovados || 0), 0);

    // Calculate average efficiency
    let totalTentado = 0; // Aprovado + Recusado
    let totalSucesso = 0; // Aprovado

    data.forEach(d => {
        const ap = parseFloat(d.total_valor_aprovado || 0);
        const re = parseFloat(d.total_valor_recusado || 0);
        totalTentado += (ap + re);
        totalSucesso += ap;
    });

    const taxaEficiencia = totalTentado > 0 ? ((totalSucesso / totalTentado) * 100).toFixed(1) : 0;

    const kpis = [
        { title: 'Faturamento da Equipe', value: formatMoney(totalFat), icon: 'coins', color: 'positive' },
        { title: 'Prescritores Visitados', value: formatNumber(totalPrescritores), icon: 'user-md', color: 'info' },
        { title: 'Taxa de conversão (Valor)', value: taxaEficiencia + '%', icon: 'percentage', color: taxaEficiencia > 50 ? 'positive' : 'warning' },
        { title: 'Total Pedidos Aprovados', value: formatNumber(totalAprovadosCount), icon: 'check-circle', color: 'bg-gradient-success' }
    ];

    document.getElementById('kpiGridVisitadores').innerHTML = kpis.map(k => `
        <div class="kpi-card">
            <div class="kpi-icon ${k.color}"><i class="fas fa-${k.icon}"></i></div>
            <div class="kpi-info">
                <h3>${k.value}</h3>
                <p>${k.title}</p>
            </div>
        </div>
    `).join('');
}

function renderChartVisitadoresRanking(data) {
    destroyChart('chartVisitadoresRanking');
    const ctx = document.getElementById('chartVisitadoresRanking');

    // Filter out rows with 0 revenue or empty names
    const filtered = data.filter(d => parseFloat(d.total_valor_aprovado) > 0 && d.visitador);
    const isAdmin = localStorage.getItem('userType') === 'admin';

    charts['chartVisitadoresRanking'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: filtered.map(d => truncateText(d.visitador, 20)),
            datasets: [{
                label: 'Valor Aprovado',
                data: filtered.map(d => parseFloat(d.total_valor_aprovado)),
                backgroundColor: 'rgba(6, 214, 160, 0.7)',
                borderRadius: 4,
                barPercentage: 0.6
            }]
        },
        options: {
            indexAxis: 'y',
            onClick: (evt, elements, chart) => {
                if (!isAdmin || !elements.length) return;
                const idx = elements[0].index;
                const visitador = filtered[idx] && filtered[idx].visitador;
                if (visitador) {
                    window.location.href = 'visitador.html?visitador=' + encodeURIComponent(visitador);
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => formatMoney(ctx.parsed.x),
                        afterBody: isAdmin ? () => '\n(Clique para ver o painel do visitador)' : undefined
                    }
                }
            },
            scales: {
                x: { ticks: { callback: v => formatCompactMoney(v) }, grid: { color: 'rgba(255,255,255,0.04)' } },
                y: { grid: { display: false } }
            }
        }
    });

    if (ctx) ctx.style.cursor = isAdmin ? 'pointer' : 'default';
}

function renderTableVisitadoresPage(data) {
    const tbody = document.querySelector('#tableVisitadoresPage tbody');
    tbody.innerHTML = data.map((d, i) => {
        const aprovado = parseFloat(d.total_valor_aprovado || 0);
        const recusado = parseFloat(d.total_valor_recusado || 0);
        const total = aprovado + recusado;
        const pct = total > 0 ? ((aprovado / total) * 100).toFixed(1) : 0;

        return `
        <tr>
            <td><span class="rank-badge ${getRankClass(i)}">${i + 1}</span></td>
            <td style="color: var(--text-primary); font-weight: 500;">${d.visitador || 'Não Identificado'}</td>
            <td>${formatNumber(d.total_prescritores)}</td>
            <td class="money-value" style="color:var(--success);">${formatMoney(aprovado)}</td>
            <td class="money-value" style="color:var(--danger);">${formatMoney(recusado)}</td>
            <td>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="flex:1;height:6px;background:var(--bg-card);border-radius:3px;overflow:hidden;">
                        <div style="width:${pct}%;height:100%;background:var(--success);"></div>
                    </div>
                    <span style="font-size:0.8rem;min-width:40px;">${pct}%</span>
                </div>
            </td>
        </tr>
    `}).join('');
}

function renderTablePrescritores(data) {
    const tbody = document.querySelector('#tablePrescritores tbody');
    tbody.innerHTML = data.map((d, i) => {
        const valorRec = parseFloat(d.valor_recusado) || 0;
        const qtdRec = parseInt(d.qtd_recusados, 10) || 0;
        const qtdAprov = parseInt(d.total_pedidos, 10) || 0;
        return `
        <tr>
            <td><span class="rank-badge ${getRankClass(i)}">${i + 1}</span></td>
            <td style="color: var(--text-primary); font-weight: 500;">${truncateText(d.prescritor, 35)}</td>
            <td class="money-value">${formatMoney(d.faturamento)}<br><small style="opacity:0.9;">${formatNumber(qtdAprov)} ped.</small></td>
            <td style="color: var(--danger, #EF4444);">${formatMoney(valorRec)}<br><small style="opacity:0.9;">${formatNumber(qtdRec)} ped.</small></td>
            <td>${formatMoney(d.ticket_medio)}</td>
            <td>${formatNumber(d.clientes_atendidos)}</td>
        </tr>
    `}).join('');
}

function renderChartProfissoes(data) {
    destroyChart('chartProfissoes');
    const ctx = document.getElementById('chartProfissoes');

    charts['chartProfissoes'] = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.map(d => d.profissao),
            datasets: [{
                data: data.map(d => parseFloat(d.valor_total) || 0),
                backgroundColor: CHART_COLORS.slice(0, data.length),
                borderWidth: 0,
                hoverOffset: 8,
            }]
        },
        options: {
            cutout: '55%',
            plugins: {
                legend: { position: 'right', labels: { font: { size: 10 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.label}: ${formatMoney(ctx.parsed)} (${data[ctx.dataIndex].total} prescritores)`
                    }
                }
            }
        }
    });
}

function renderChartVisitadores(data) {
    destroyChart('chartVisitadores');
    const ctx = document.getElementById('chartVisitadores');

    charts['chartVisitadores'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => truncateText(d.visitador, 15)),
            datasets: [
                {
                    label: 'Aprovado',
                    data: data.map(d => parseFloat(d.total_valor_aprovado) || 0),
                    backgroundColor: 'rgba(6, 214, 160, 0.7)',
                    borderRadius: 4,
                },
                {
                    label: 'Recusado',
                    data: data.map(d => parseFloat(d.total_valor_recusado) || 0),
                    backgroundColor: 'rgba(239, 71, 111, 0.6)',
                    borderRadius: 4,
                },
                {
                    label: 'No Carrinho',
                    data: data.map(d => parseFloat(d.total_valor_carrinho) || 0),
                    backgroundColor: 'rgba(255, 209, 102, 0.6)',
                    borderRadius: 4,
                }
            ]
        },
        options: {
            plugins: {
                tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y)}` } }
            },
            scales: {
                y: { stacked: true, ticks: { callback: v => formatCompactMoney(v) }, grid: { color: 'rgba(255,255,255,0.04)' } },
                x: { stacked: true, grid: { display: false } }
            }
        }
    });
}

// ============ CLIENTES ============
async function loadClientes() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate, limit: 15 };
    const clientes = await apiGet('top_clientes', params);
    renderTableClientes(clientes);
    renderChartTopClientes(clientes.slice(0, 10));
}

function renderTableClientes(data) {
    const tbody = document.querySelector('#tableClientes tbody');
    tbody.innerHTML = data.map((d, i) => `
        <tr>
            <td><span class="rank-badge ${getRankClass(i)}">${i + 1}</span></td>
            <td style="color: var(--text-primary); font-weight: 500;">${truncateText(d.cliente, 40)}</td>
            <td>${formatNumber(d.total_pedidos)}</td>
            <td class="money-value">${formatMoney(d.faturamento)}</td>
            <td>${formatMoney(d.ticket_medio)}</td>
            <td>${d.ultima_compra ? new Date(d.ultima_compra).toLocaleDateString('pt-BR') : '-'}</td>
        </tr>
    `).join('');
}

function renderChartTopClientes(data) {
    destroyChart('chartTopClientes');
    const ctx = document.getElementById('chartTopClientes');

    charts['chartTopClientes'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => truncateText(d.cliente, 20)),
            datasets: [{
                label: 'Faturamento',
                data: data.map(d => parseFloat(d.faturamento) || 0),
                backgroundColor: CHART_COLORS.map(c => c + 'B3'),
                borderColor: CHART_COLORS,
                borderWidth: 1,
                borderRadius: 6,
                barPercentage: 0.6
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => formatMoney(ctx.parsed.x) } }
            },
            scales: {
                x: { ticks: { callback: v => formatCompactMoney(v) }, grid: { color: 'rgba(255,255,255,0.04)' } },
                y: { grid: { display: false } }
            }
        }
    });
}

// ============ PRODUTOS ============
async function loadProdutos() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate, limit: 15 };
    const formas = await apiGet('top_formas', params);
    renderTableFormas(formas);
    renderChartProdutosFat(formas.slice(0, 10));
}

function renderTableFormas(data) {
    const tbody = document.querySelector('#tableFormas tbody');
    tbody.innerHTML = data.map((d, i) => {
        const margem = parseFloat(d.faturamento) > 0
            ? (((parseFloat(d.faturamento) - parseFloat(d.custo)) / parseFloat(d.faturamento)) * 100).toFixed(1)
            : 0;
        return `
        <tr>
            <td><span class="rank-badge ${getRankClass(i)}">${i + 1}</span></td>
            <td style="color: var(--text-primary); font-weight: 500;">${d.forma_farmaceutica}</td>
            <td>${formatNumber(d.quantidade)}</td>
            <td class="money-value">${formatMoney(d.faturamento)}</td>
            <td>${formatMoney(d.custo)}</td>
            <td><span class="status-badge ${parseFloat(margem) > 80 ? 'status-aprovado' : parseFloat(margem) > 60 ? 'status-pendente' : 'status-recusado'}">${margem}%</span></td>
            <td>${formatMoney(d.ticket_medio)}</td>
        </tr>
    `}).join('');
}

function renderChartProdutosFat(data) {
    destroyChart('chartProdutosFat');
    const ctx = document.getElementById('chartProdutosFat');

    charts['chartProdutosFat'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => truncateText(d.forma_farmaceutica, 20)),
            datasets: [
                {
                    label: 'Faturamento',
                    data: data.map(d => parseFloat(d.faturamento) || 0),
                    backgroundColor: 'rgba(230, 57, 70, 0.7)',
                    borderRadius: 6,
                    barPercentage: 0.5,
                },
                {
                    label: 'Custo',
                    data: data.map(d => parseFloat(d.custo) || 0),
                    backgroundColor: 'rgba(17, 138, 178, 0.5)',
                    borderRadius: 6,
                    barPercentage: 0.5,
                }
            ]
        },
        options: {
            plugins: {
                tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${formatMoney(ctx.parsed.y)}` } }
            },
            scales: {
                y: { ticks: { callback: v => formatCompactMoney(v) }, grid: { color: 'rgba(255,255,255,0.04)' } },
                x: { grid: { display: false } }
            }
        }
    });
}

// ============ EQUIPE ============
async function loadEquipe() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate };
    const atendentes = await apiGet('top_atendentes', params);
    renderTableAtendentes(atendentes);
    renderChartAtendentes(atendentes);
}

function renderTableAtendentes(data) {
    const tbody = document.querySelector('#tableAtendentes tbody');
    tbody.innerHTML = data.map((d, i) => `
        <tr>
            <td><span class="rank-badge ${getRankClass(i)}">${i + 1}</span></td>
            <td style="color: var(--text-primary); font-weight: 500;">${d.atendente}</td>
            <td>${formatNumber(d.total_pedidos)}</td>
            <td class="money-value">${formatMoney(d.faturamento)}</td>
            <td>${formatNumber(d.clientes_atendidos)}</td>
            <td>${formatMoney(d.ticket_medio)}</td>
        </tr>
    `).join('');
}

function renderChartAtendentes(data) {
    destroyChart('chartAtendentes');
    const ctx = document.getElementById('chartAtendentes');

    charts['chartAtendentes'] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => truncateText(d.atendente, 15)),
            datasets: [{
                label: 'Faturamento',
                data: data.map(d => parseFloat(d.faturamento) || 0),
                backgroundColor: CHART_COLORS.map(c => c + 'B3'),
                borderColor: CHART_COLORS,
                borderWidth: 1,
                borderRadius: 6,
                barPercentage: 0.5
            }]
        },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => `Faturamento: ${formatMoney(ctx.parsed.y)}`,
                        afterLabel: ctx => `Pedidos: ${formatNumber(data[ctx.dataIndex].total_pedidos)}\nClientes: ${formatNumber(data[ctx.dataIndex].clientes_atendidos)}`
                    }
                }
            },
            scales: {
                y: { ticks: { callback: v => formatCompactMoney(v) }, grid: { color: 'rgba(255,255,255,0.04)' } },
                x: { grid: { display: false } }
            }
        }
    });
}

// ============ INSIGHTS ============
async function loadInsights() {
    const fp = getFilterParams();
    const params = { data_de: fp.data_de, data_ate: fp.data_ate };

    const [kpis, comparativo, formas, atendentes, visitadores, canais] = await Promise.all([
        apiGet('kpis', params),
        apiGet('comparativo_anual', params),
        apiGet('top_formas', { ...params, limit: 5 }),
        apiGet('top_atendentes', params),
        apiGet('visitadores', params),
        apiGet('canais', params)
    ]);

    const insights = [];

    // Insight 1: Margem de lucro
    const margem = parseFloat(kpis.margem_lucro) || 0;
    insights.push({
        icon: '💰',
        title: 'Margem de Lucro',
        text: margem > 80
            ? `Excelente margem de ${margem}%. A operação está saudável com forte controle de custos.`
            : margem > 60
                ? `Margem de ${margem}% está boa, mas há espaço para otimização de custos.`
                : `Margem de ${margem}% está abaixo do ideal. Revise custos e precificação.`,
        type: margem > 80 ? 'positive' : margem > 60 ? 'warning' : 'negative',
        metric: margem + '%',
        metricClass: margem > 80 ? 'positive' : margem > 60 ? 'warning' : 'negative'
    });

    // Insight 2: Crescimento
    if (comparativo.length >= 2) {
        const ultimo = parseFloat(comparativo[comparativo.length - 1]?.faturamento) || 0;
        const penultimo = parseFloat(comparativo[comparativo.length - 2]?.faturamento) || 0;
        const crescimento = penultimo > 0 ? ((ultimo - penultimo) / penultimo * 100).toFixed(1) : 0;
        const anoAtual = comparativo[comparativo.length - 1]?.ano;
        const anoAnterior = comparativo[comparativo.length - 2]?.ano;

        insights.push({
            icon: crescimento > 0 ? '📈' : '📉',
            title: `Crescimento ${anoAnterior} → ${anoAtual}`,
            text: crescimento > 0
                ? `Crescimento de ${crescimento}% no faturamento. A farmácia está expandindo bem.`
                : `Queda de ${Math.abs(crescimento)}% no faturamento. Atenção: ${anoAtual} pode estar incompleto (ano em andamento).`,
            type: crescimento > 0 ? 'positive' : 'warning',
            metric: (crescimento > 0 ? '+' : '') + crescimento + '%',
            metricClass: crescimento > 0 ? 'positive' : 'negative'
        });
    }

    // Insight 3: Produto estrela
    if (formas.length > 0) {
        const topForma = formas[0];
        const totalFat = formas.reduce((s, f) => s + (parseFloat(f.faturamento) || 0), 0);
        const pct = totalFat > 0 ? ((parseFloat(topForma.faturamento) / totalFat) * 100).toFixed(1) : 0;

        insights.push({
            icon: '⭐',
            title: 'Produto Estrela',
            text: `${topForma.forma_farmaceutica} representa ${pct}% do faturamento com ${formatNumber(topForma.quantidade)} pedidos e ticket médio de ${formatMoney(topForma.ticket_medio)}.`,
            type: 'info',
            metric: formatCompactMoney(topForma.faturamento),
            metricClass: 'positive'
        });
    }

    // Insight 4: Top atendente
    if (atendentes.length > 0) {
        const top = atendentes[0];
        insights.push({
            icon: '🏆',
            title: 'Melhor Atendente',
            text: `${top.atendente} lidera com ${formatNumber(top.total_pedidos)} pedidos, atendendo ${formatNumber(top.clientes_atendidos)} clientes e gerando ${formatMoney(top.faturamento)} em faturamento.`,
            type: 'positive',
            metric: formatCompactMoney(top.faturamento),
            metricClass: 'positive'
        });
    }

    // Insight 5: Visitadores
    if (visitadores.length > 0) {
        const topVisit = visitadores[0];
        insights.push({
            icon: '🎯',
            title: 'Melhor Visitador',
            text: `${topVisit.visitador} trouxe ${formatNumber(topVisit.total_prescritores)} prescritores com ${formatMoney(topVisit.total_valor_aprovado)} aprovados. Taxa de aprovação indica bom trabalho de prospecção.`,
            type: 'positive',
            metric: formatCompactMoney(topVisit.total_valor_aprovado),
            metricClass: 'positive'
        });
    }

    // Insight 6: Canal dominante
    if (canais.length > 0) {
        const totalCanal = canais.reduce((s, c) => s + parseInt(c.total), 0);
        const topCanal = canais[0];
        const pctCanal = ((parseInt(topCanal.total) / totalCanal) * 100).toFixed(1);

        insights.push({
            icon: '📱',
            title: 'Canal Dominante',
            text: `${topCanal.canal_atendimento} responde por ${pctCanal}% dos pedidos (${formatNumber(topCanal.total)} pedidos) com faturamento de ${formatMoney(topCanal.faturamento)}.`,
            type: pctCanal > 80 ? 'warning' : 'info',
            metric: pctCanal + '%',
            metricClass: pctCanal > 80 ? 'warning' : 'positive'
        });

        if (pctCanal > 80) {
            insights.push({
                icon: '⚠️',
                title: 'Concentração de Canal',
                text: `A farmácia tem alta dependência do canal "${topCanal.canal_atendimento}". Considere diversificar os canais de atendimento (online, WhatsApp, delivery) para reduzir riscos.`,
                type: 'warning',
                metric: 'Alto Risco',
                metricClass: 'warning'
            });
        }
    }

    // Insight: Ticket médio
    const ticketMedio = parseFloat(kpis.ticket_medio) || 0;
    insights.push({
        icon: '🎟️',
        title: 'Ticket Médio',
        text: ticketMedio > 100
            ? `Ticket médio de ${formatMoney(ticketMedio)} demonstra boa valorização dos produtos. Estratégias de upsell podem aumentar ainda mais.`
            : `Ticket médio de ${formatMoney(ticketMedio)} está baixo. Considere estratégias de cross-sell e kits de produtos para elevar o valor por pedido.`,
        type: ticketMedio > 100 ? 'positive' : 'warning',
        metric: formatMoney(ticketMedio),
        metricClass: ticketMedio > 100 ? 'positive' : 'warning'
    });

    renderInsights(insights);
}

// ============ VISITADOR DASHBOARD (Página Dedicada) ============
async function loadVisitadorDashboard(nomeVisitador, anoSelecionado = null, mesSelecionado = null, diaSelecionado = null) {
    const nome = (nomeVisitador && String(nomeVisitador).trim()) || (typeof localStorage !== 'undefined' && localStorage.getItem('userName')) || '';
    showLoading();
    try {
        const anoSelect = document.getElementById('anoSelect');
        const mesSelect = document.getElementById('mesSelect');
        const ano = anoSelecionado !== null ? anoSelecionado : (anoSelect ? anoSelect.value : '');
        const mes = mesSelecionado !== null ? mesSelecionado : (mesSelect ? mesSelect.value : '');

        // Atualiza o select se não for o valor atual (ex: carga inicial)
        if (anoSelect && ano !== null && anoSelect.value != ano) anoSelect.value = ano;
        if (mesSelect && mes !== null && mesSelect.value != mes) mesSelect.value = mes;

        const dia = null; // Removed dia filter logic

        const params = { nome: nome, ano };
        if (mes) params.mes = mes;
        if (dia) params.dia = dia;
        const data = await apiGet('visitador_dashboard', params);

        if (data.error) {
            console.error(data.error);
            alert('Erro ao carregar dados: ' + data.error);
            hideLoading();
            return;
        }

        // Na primeira carga, definir o mês padrão como o último mês com dados
        if (mesSelect && !mesSelect.getAttribute('data-default-set') && data.kpis?.ultimo_mes_com_dados) {
            mesSelect.setAttribute('data-default-set', 'true');
            const ultimoMes = data.kpis.ultimo_mes_com_dados.toString();
            mesSelect.value = ultimoMes;
            // Recarregar com o mês correto
            loadVisitadorDashboard(nome, ano, ultimoMes);
            return;
        }

        // Add listeners if not added yet
        if (anoSelect && !anoSelect.getAttribute('data-listener')) {
            anoSelect.setAttribute('data-listener', 'true');
            anoSelect.addEventListener('change', () => {
                loadVisitadorDashboard(nome, anoSelect.value, mesSelect ? mesSelect.value : null);
            });
        }
        if (mesSelect && !mesSelect.getAttribute('data-listener')) {
            mesSelect.setAttribute('data-listener', 'true');
            mesSelect.addEventListener('change', () => {
                loadVisitadorDashboard(nome, anoSelect ? anoSelect.value : null, mesSelect.value);
            });
        }

        // Salvar prescritores para modal
        if (typeof allPrescritores !== 'undefined') {
            allPrescritores = data.top_prescritores || [];
        } else {
            window.allPrescritores = data.top_prescritores || [];
        }

        // 1. KPIs
        // 1. KPIs
        const totalAprovadoAnual = parseFloat(data.kpis?.total_aprovado_anual || 0);
        const totalAprovadoMensal = parseFloat(data.kpis?.total_aprovado_mensal || 0);

        // Calcular Total de Pedidos somando dos top prescritores
        const totalPedidos = (data.top_prescritores || []).reduce((acc, p) => acc + parseFloat(p.qtd_aprovados || 0), 0);

        // Metas
        const metaMensal = parseFloat(data.kpis?.meta_mensal || 50000);
        const metaAnual = parseFloat(data.kpis?.meta_anual || 600000);

        const pctMetaMensal = metaMensal > 0 ? ((totalAprovadoMensal / metaMensal) * 100).toFixed(1) : 0;
        const pctMetaAnual = metaAnual > 0 ? ((totalAprovadoAnual / metaAnual) * 100).toFixed(1) : 0;

        // KPI Cards Principais - segue filtro de mês
        const totalVendidoExibir = mes ? totalAprovadoMensal : totalAprovadoAnual;
        document.getElementById('kpiTotalVendido').textContent = formatMoney(totalVendidoExibir);
        const totalPendentes = parseInt(data.kpis?.total_recusados || 0) + parseInt(data.kpis?.total_no_carrinho || 0);
        document.getElementById('kpiTotalPendentes').textContent = totalPendentes;
        document.getElementById('kpiTotalCarteira').textContent = data.kpis?.total_prescritores || 0;

        // Atualizar Barras de Progresso
        const updateBar = (barId, labelId, valorId, alvoId, atual, meta, pct) => {
            const bar = document.getElementById(barId);
            const label = document.getElementById(labelId);
            const valor = document.getElementById(valorId);
            const alvo = document.getElementById(alvoId);

            if (bar) bar.style.width = Math.min(pct, 100) + '%';
            if (label) label.textContent = pct + '%';
            if (valor) valor.textContent = formatMoney(atual);
            if (alvo) alvo.textContent = 'Meta: ' + formatMoney(meta);
        };

        updateBar('barMetaMensal', 'labelMetaMensal', 'valorMetaMensal', 'alvoMetaMensal', totalAprovadoMensal, metaMensal, pctMetaMensal);
        updateBar('barMetaAnual', 'labelMetaAnual', 'valorMetaAnual', 'alvoMetaAnual', totalAprovadoAnual, metaAnual, pctMetaAnual);

        // Comissão - segue o filtro de mês
        const comissaoPct = parseFloat(data.kpis?.comissao || 1);
        const baseComissao = mes ? totalAprovadoMensal : totalAprovadoAnual;
        const comissaoAtual = baseComissao * (comissaoPct / 100);
        const elComissaoAtual = document.getElementById('comissaoAtual');
        const elComissaoPct = document.getElementById('labelComissaoPct');
        if (elComissaoAtual) elComissaoAtual.textContent = formatMoney(comissaoAtual);
        if (elComissaoPct) elComissaoPct.textContent = comissaoPct + '%';

        // Lógica de Premiação: se bateu todas as metas, somar ao valor da comissão
        let comissaoComPremio = comissaoAtual;
        if (data.kpis_visitas && data.kpis_visitas.premio.conquistado) {
            comissaoComPremio += data.kpis_visitas.premio.valor;
            if (elComissaoAtual) {
                elComissaoAtual.innerHTML = `${formatMoney(comissaoComPremio)} <br><small style="font-size:0.65rem; color:#10B981; font-weight:normal;">(Previsão de ganhos + Prêmio)</small>`;
            }
        }

        // KPIs Visitas e Premiação
        if (data.kpis_visitas) {
            const v = data.kpis_visitas;
            // Semana: x / 30 (x = quantidade de visitas na semana atual)
            const labelMetaSemana = document.getElementById('labelMetaVisitaSemana');
            const pctMetaSemana = document.getElementById('pctMetaVisitaSemana');
            const barMetaSemana = document.getElementById('barMetaVisitaSemana');
            if (labelMetaSemana) labelMetaSemana.textContent = `${v.semanal.atual} / ${v.semanal.meta}`;
            if (pctMetaSemana) pctMetaSemana.textContent = v.semanal.pct + '%';
            if (barMetaSemana) barMetaSemana.style.width = v.semanal.pct + '%';

            // Métrica analítica: semanas/blocos (e destacar em verde quando bateu)
            const visitasPorSemanaEl = document.getElementById('visitasPorSemanaLabel');
            if (visitasPorSemanaEl && Array.isArray(v.visitas_por_semana) && v.visitas_por_semana.length) {
                const txt = v.visitas_por_semana
                    .map(s => {
                        const d = String(s.label || '').replace(/^Sem\s*/i, '').trim();
                        const piece = `${d} ${s.total}`;
                        // cores: verde = bateu; vermelho = não bateu e já está válido; cinza = ainda não chegou (future)
                        const hasStatus = s && typeof s.status === 'string';
                        const isFuture = hasStatus && s.status === 'future';
                        const bateu = s && s.bateu === true;

                        let color = '#94A3B8';
                        let bg = 'rgba(148,163,184,0.10)';
                        let border = 'rgba(148,163,184,0.25)';
                        let weight = 600;

                        if (bateu) {
                            color = '#10B981';
                            bg = 'rgba(16,185,129,0.12)';
                            border = 'rgba(16,185,129,0.30)';
                            weight = 700;
                        } else if (hasStatus && !isFuture) {
                            color = '#EF4444';
                            bg = 'rgba(239,68,68,0.12)';
                            border = 'rgba(239,68,68,0.28)';
                            weight = 700;
                        } else {
                            // future: mais apagado/cinza
                            weight = 600;
                        }

                        return `<span style="
                            display:inline-flex;
                            align-items:center;
                            padding:2px 8px;
                            border-radius:999px;
                            border:1px solid ${border};
                            background:${bg};
                            color:${color};
                            font-weight:${weight};
                            line-height:1.2;
                        ">${piece}</span>`;
                    })
                    .join(' ');
                visitasPorSemanaEl.innerHTML = `${v.visitas_por_semana.length} sem.: ` + txt;
                visitasPorSemanaEl.style.display = 'block';
            } else if (visitasPorSemanaEl) visitasPorSemanaEl.style.display = 'none';

            // Mes
            const labelMetaMes = document.getElementById('labelMetaVisitaMes');
            const pctMetaMes = document.getElementById('pctMetaVisitaMes');
            const barMetaMes = document.getElementById('barMetaVisitaMes');
            if (labelMetaMes) labelMetaMes.textContent = `${v.mes.atual} / ${v.mes.meta}`;
            if (pctMetaMes) pctMetaMes.textContent = v.mes.pct + '%';
            if (barMetaMes) barMetaMes.style.width = v.mes.pct + '%';

            // Premio
            const valorPremio = document.getElementById('valorPremioVisita');
            const statusPremio = document.getElementById('statusPremioVisita');
            const cardPremio = document.getElementById('cardPremioVisita');

            if (valorPremio && statusPremio && cardPremio) {
                valorPremio.textContent = formatMoney(v.premio.valor);
                if (v.premio.conquistado) {
                    valorPremio.style.color = '#10B981'; // Verde sucesso
                    statusPremio.innerHTML = '<i class="fas fa-check-circle" style="color:#10B981"></i> Metas Batidas!';
                    statusPremio.style.color = '#10B981';
                    statusPremio.style.fontWeight = '600';
                    cardPremio.style.borderColor = '#10B981';
                    cardPremio.style.background = 'rgba(16,185,129,0.05)';
                } else {
                    valorPremio.style.color = 'var(--text-primary)';
                    let missing = [];
                    if (!v.premio.batouVendas) missing.push('Vendas');
                    if (!v.premio.batouVisitasMes) missing.push('Visitas (Mês)');
                    if (!v.premio.batouVisitasSemana) missing.push('Visitas (Semana)');

                    statusPremio.textContent = missing.length > 0 ? `Falta: ${missing.join(', ')}` : 'Meta de Visitas';
                    statusPremio.style.color = 'var(--text-secondary)';
                    statusPremio.style.fontWeight = '400';
                    cardPremio.style.borderColor = 'transparent';
                    cardPremio.style.background = 'var(--bg-card)';
                }
            }
        }

        // Última atualização
        const elUltimaAtt = document.getElementById('ultimaAtualizacao');
        if (elUltimaAtt && data.kpis?.ultima_atualizacao) {
            const dt = new Date(data.kpis.ultima_atualizacao);
            const dataFormatada = dt.toLocaleDateString('pt-BR') + ' ' + dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            elUltimaAtt.innerHTML = '<i class="fas fa-clock" style="margin-right:4px;"></i>Última atualização: ' + dataFormatada;
        }

        // Atualizar Gauge lateral - Meta Mensal
        if (document.getElementById('metaMensalPercent')) {
            document.getElementById('metaMensalPercent').textContent = Math.min(pctMetaMensal, 100) + '%';
        }
        // Atualizar Gauge lateral - Meta Anual
        if (document.getElementById('metaPercent')) {
            document.getElementById('metaPercent').textContent = Math.min(pctMetaAnual, 100) + '%';
        }

        // Gauge Chart - Meta MENSAL
        const ctxGaugeMensal = document.getElementById('chartMetaMensalGauge');
        const chartMensalStatus = Chart.getChart("chartMetaMensalGauge");
        if (chartMensalStatus != undefined) { chartMensalStatus.destroy(); }

        if (ctxGaugeMensal) {
            new Chart(ctxGaugeMensal, {
                type: 'doughnut',
                data: {
                    labels: ['Realizado', 'Faltante'],
                    datasets: [{
                        data: [totalAprovadoMensal, Math.max(0, metaMensal - totalAprovadoMensal)],
                        backgroundColor: ['#2563EB', '#E2E8F0'],
                        borderWidth: 0,
                        cutout: '80%',
                        rotation: -90,
                        circumference: 180
                    }]
                },
                options: {
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    aspectRatio: 1.6,
                    responsive: true
                }
            });
        }

        // Gauge Chart - Meta ANUAL
        const ctxGauge = document.getElementById('chartMetaGauge');
        const chartStatus = Chart.getChart("chartMetaGauge");
        if (chartStatus != undefined) { chartStatus.destroy(); }

        if (ctxGauge) {
            new Chart(ctxGauge, {
                type: 'doughnut',
                data: {
                    labels: ['Realizado', 'Faltante'],
                    datasets: [{
                        data: [totalAprovadoAnual, Math.max(0, metaAnual - totalAprovadoAnual)],
                        backgroundColor: ['#10B981', '#E2E8F0'],
                        borderWidth: 0,
                        cutout: '80%',
                        rotation: -90,
                        circumference: 180
                    }]
                },
                options: {
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    aspectRatio: 1.6,
                    responsive: true
                }
            });
        }

        // 2. Chart Evolução Mensal
        const ctxEvo = document.getElementById('chartEvolucaoMENSAL');
        if (ctxEvo) {
            const chartEvoStatus = Chart.getChart("chartEvolucaoMENSAL");
            if (chartEvoStatus != undefined) { chartEvoStatus.destroy(); }

            const valoresMensais = new Array(12).fill(0);
            if (data.evolucao && Array.isArray(data.evolucao)) {
                data.evolucao.forEach(item => {
                    const m = parseInt(item.mes) - 1;
                    if (m >= 0 && m < 12) valoresMensais[m] = parseFloat(item.total);
                });
            }

            new Chart(ctxEvo, {
                type: 'bar',
                data: {
                    labels: MONTH_NAMES,
                    datasets: [{
                        label: 'Vendas Aprovadas',
                        data: valoresMensais,
                        backgroundColor: 'rgba(37, 99, 235, 0.8)',
                        borderRadius: 6,
                        hoverBackgroundColor: 'rgba(37, 99, 235, 1)',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1E293B',
                            padding: 12,
                            callbacks: { label: ctx => formatMoney(ctx.parsed.y) }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { borderDash: [5, 5], color: '#E2E8F0' },
                            ticks: {
                                font: { family: 'Inter' },
                                callback: v => formatCompactMoney(v)
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { family: 'Inter' } }
                        }
                    }
                }
            });
        }

        // 3. Chart Especialidades da carteira (Doughnut) — prescritores por especialidade
        const ctxProd = document.getElementById('chartProdutos');
        if (ctxProd) {
            const chartProdStatus = Chart.getChart("chartProdutos");
            if (chartProdStatus != undefined) { chartProdStatus.destroy(); }

            const topEspecialidades = data.top_especialidades || [];
            const totalEsp = topEspecialidades.reduce((acc, curr) => acc + parseInt(curr.total, 10), 0);
            const labelEspecialidade = (fam) => {
                const s = (fam && String(fam).trim()) || '';
                return s ? truncateText(s, 18) : 'Não informada';
            };

            new Chart(ctxProd, {
                type: 'doughnut',
                data: {
                    labels: topEspecialidades.map(e => labelEspecialidade(e.familia)),
                    datasets: [{
                        data: topEspecialidades.map(e => parseInt(e.total, 10)),
                        backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#64748B', '#EC4899', '#14B8A6', '#F97316', '#6366F1'],
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 10,
                                usePointStyle: true,
                                font: { size: 11, family: 'Inter' }
                            }
                        },
                        tooltip: {
                            backgroundColor: '#1E293B',
                            padding: 12,
                            callbacks: {
                                label: ctx => {
                                    const val = ctx.parsed;
                                    const pct = totalEsp > 0 ? ((val / totalEsp) * 100).toFixed(1) + '%' : '0%';
                                    return ` ${val} prescritor(es) (${pct})`;
                                }
                            }
                        }
                    },
                    cutout: '65%'
                }
            });
        }

        // Global variable update for modal use
        if (typeof allPrescritores !== 'undefined') {
            allPrescritores = data.top_prescritores || [];
        }

        // 4. Tabela Prescritores
        const elTable = document.getElementById('tableMeusPrescritores');
        const tbody = elTable ? elTable.querySelector('tbody') : null;
        if (tbody) {
            tbody.innerHTML = (data.top_prescritores || []).map(p => {
                let dataFormatada = '-';
                if (p.ultima_compra) {
                    const d = new Date(p.ultima_compra);
                    if (!isNaN(d.getTime())) {
                        dataFormatada = d.toLocaleDateString('pt-BR');
                    }
                }

                let diasCompraHTML = '<span style="color:#CBD5E1">-</span>';
                const dias = parseInt(p.dias_sem_compra);

                if (!isNaN(dias)) {
                    if (dias <= 15) {
                        diasCompraHTML = `<span class="status-badge active" style="background:#DCFCE7; color:#166534;">${dias} dias</span>`;
                    } else if (dias <= 30) {
                        diasCompraHTML = `<span class="status-badge warning" style="background:#FEF3C7; color:#92400E;">${dias} dias</span>`;
                    } else if (dias <= 60) {
                        diasCompraHTML = `<span class="status-badge inactive" style="background:#FEE2E2; color:#B91C1C;">${dias} dias</span>`;
                    } else {
                        diasCompraHTML = `<span class="status-badge inactive" style="background:#991B1B; color:#FEF2F2; font-weight:700; border:1px solid #7F1D1D;"><i class="fas fa-exclamation-circle"></i> ${dias} dias</span>`;
                    }
                }

                return `
                <tr>
                    <td style="font-weight:500;">${truncateText(p.prescritor, 40)}</td>
                    <td style="color:#10B981; font-weight:600;">${formatMoney(p.total_aprovado)}</td>
                    <td style="text-align:center;">${p.qtd_aprovados || 0}</td>
                    <td style="color:var(--text-secondary); font-size:0.9rem;">${dataFormatada}</td>
                    <td>${diasCompraHTML}</td>
                    <td><span class="status-badge active"><i class="fas fa-check"></i> Ativo</span></td>
                </tr>
            `}).join('');
        }

        // 4. Alertas Inteligentes (Prescritores com potencial inativos)
        const alertasContainer = document.getElementById('alertasContainer');
        const alertasCountEl = document.getElementById('alertasCount');
        if (alertasContainer) {
            const alertas = data.alertas || [];
            if (alertasCountEl) alertasCountEl.textContent = alertas.length;

            if (alertas.length > 0) {
                alertasContainer.innerHTML = alertas.map((a, idx) => {
                    const diasCompra = parseInt(a.dias_sem_compra) || 0;
                    const diasVisita = a.dias_sem_visita != null ? parseInt(a.dias_sem_visita) : null;
                    const valor = parseFloat(a.valor_total_aprovado) || 0;
                    const score = parseFloat(a.score) || 0;
                    const pedidos = parseInt(a.total_pedidos) || 0;
                    const mediaAnoAnt = parseFloat(a.media_mensal_ano_anterior) || 0;
                    const abaixoDaMedia = !!a.abaixo_da_media;
                    const anoRef = a.ano_anterior_ref || new Date().getFullYear() - 1;

                    const severidade = score >= 60 ? 'critico' : score >= 35 ? 'alto' : 'medio';
                    const sevCfg = {
                        'critico': { color: '#EF4444', bg: 'rgba(239,68,68,0.10)', border: 'rgba(239,68,68,0.25)', icon: 'fa-circle-exclamation', label: 'Crítico' },
                        'alto':    { color: '#F59E0B', bg: 'rgba(245,158,11,0.10)', border: 'rgba(245,158,11,0.25)', icon: 'fa-triangle-exclamation', label: 'Alto' },
                        'medio':   { color: '#3B82F6', bg: 'rgba(37,99,235,0.10)',  border: 'rgba(37,99,235,0.25)',  icon: 'fa-info-circle', label: 'Médio' }
                    };
                    const s = sevCfg[severidade];

                    const compraTag = diasCompra >= 60
                        ? `<span style="color:#EF4444; font-weight:700;">${diasCompra}d</span>`
                        : diasCompra >= 30
                        ? `<span style="color:#F59E0B; font-weight:700;">${diasCompra}d</span>`
                        : `<span style="color:var(--text-secondary);">${diasCompra}d</span>`;

                    const visitaTag = diasVisita === null
                        ? `<span style="color:#EF4444; font-weight:700;">Nunca</span>`
                        : diasVisita >= 30
                        ? `<span style="color:#EF4444; font-weight:700;">${diasVisita}d</span>`
                        : diasVisita >= 15
                        ? `<span style="color:#F59E0B; font-weight:700;">${diasVisita}d</span>`
                        : `<span style="color:var(--text-secondary);">${diasVisita}d</span>`;

                    const mediaVal = mediaAnoAnt > 0 ? formatMoney(mediaAnoAnt) + '/mês' : '—';
                    const mediaAnoAtual = parseFloat(a.media_mensal_ano_atual) || 0;
                    const anoAtualRef = a.ano_atual_ref || new Date().getFullYear();
                    const media2026Val = mediaAnoAtual > 0 ? formatMoney(mediaAnoAtual) + '/mês' : '—';
                    const abaixoBadge = abaixoDaMedia
                        ? `<span style="font-size:0.6rem; font-weight:800; padding:1px 6px; border-radius:4px; background:rgba(239,68,68,0.12); color:#EF4444; border:1px solid rgba(239,68,68,0.3); white-space:nowrap;">Abaixo da média</span>`
                        : '';

                    return `
                        <div style="display:flex; gap:10px; padding:10px 0; ${idx < alertas.length - 1 ? 'border-bottom:1px solid var(--border);' : ''}">
                            <div style="flex-shrink:0; width:32px; height:32px; border-radius:8px; background:${s.bg}; border:1px solid ${s.border}; display:flex; align-items:center; justify-content:center;">
                                <i class="fas ${s.icon}" style="color:${s.color}; font-size:0.75rem;"></i>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">
                                    <span style="font-weight:700; font-size:0.82rem; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${truncateText(a.prescritor, 22)}</span>
                                    <div style="display:flex; align-items:center; gap:4px; flex-shrink:0;">
                                        ${abaixoBadge}
                                        <span style="font-size:0.6rem; font-weight:800; padding:1px 6px; border-radius:4px; background:${s.bg}; color:${s.color}; border:1px solid ${s.border}; white-space:nowrap;">${s.label}</span>
                                    </div>
                                </div>
                                <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:8px 12px; margin-top:8px; font-size:0.7rem;">
                                    <div style="display:flex; flex-direction:column; gap:1px;">
                                        <span style="color:var(--text-secondary); font-size:0.6rem; text-transform:uppercase; letter-spacing:0.3px;">s/compra</span>
                                        <span style="font-weight:700;">${compraTag}</span>
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:1px;">
                                        <span style="color:var(--text-secondary); font-size:0.6rem; text-transform:uppercase; letter-spacing:0.3px;">s/visita</span>
                                        <span style="font-weight:700;">${visitaTag}</span>
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:1px;">
                                        <span style="color:var(--text-secondary); font-size:0.6rem; text-transform:uppercase; letter-spacing:0.3px;">Média ${anoRef}</span>
                                        <span style="font-weight:600; color:var(--text-primary);">${mediaVal}</span>
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:1px;">
                                        <span style="color:var(--text-secondary); font-size:0.6rem; text-transform:uppercase; letter-spacing:0.3px;">Média ${anoAtualRef}</span>
                                        <span style="font-weight:600; color:var(--text-primary);">${media2026Val}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                alertasContainer.innerHTML = `
                    <div style="text-align:center; padding:28px 0;">
                        <div style="width:44px; height:44px; border-radius:12px; background:rgba(16,185,129,0.08); display:inline-flex; align-items:center; justify-content:center; margin-bottom:8px;">
                            <i class="fas fa-check-circle" style="color:#10B981; font-size:1.1rem;"></i>
                        </div>
                        <div style="font-size:0.88rem; font-weight:700; color:#10B981;">Tudo certo!</div>
                        <div style="font-size:0.78rem; color:var(--text-secondary); margin-top:4px;">Sua carteira está ativa e sem prescritores inativos.</div>
                    </div>`;
            }
        }

        // 5. Relatório de Visitas (Semana) — cards premium
        window.__visitasSemanaData = data.relatorio_visitas_semana || [];
        const vsBody = document.getElementById('visitasSemanaBody');
        const vsCount = document.getElementById('visitasSemanaCount');
        const vDiaCount = document.getElementById('visitasDiaCount');
        const vMesCount = document.getElementById('visitasMesCount');
        if (vsBody) {
            const rows = window.__visitasSemanaData;
            if (vsCount) vsCount.textContent = rows.length;
            if (vMesCount) vMesCount.textContent = (data.kpis_visitas && data.kpis_visitas.mes && Number.isFinite(parseInt(data.kpis_visitas.mes.atual, 10)))
                ? String(parseInt(data.kpis_visitas.mes.atual, 10))
                : '0';
            if (vDiaCount) {
                const nowPV = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Porto_Velho' }));
                const todayIso = `${nowPV.getFullYear()}-${String(nowPV.getMonth() + 1).padStart(2, '0')}-${String(nowPV.getDate()).padStart(2, '0')}`;
                const diaCount = Array.isArray(rows) ? rows.filter(r => (r && r.data_visita) === todayIso).length : 0;
                vDiaCount.textContent = String(diaCount);
            }
            if (Array.isArray(rows) && rows.length) {
                const tzPortoVelho = 'America/Porto_Velho';
                vsBody.innerHTML = rows.map((r, idx) => {
                    const isoPV = r.data_visita && r.horario ? (r.data_visita + 'T' + String(r.horario).slice(0, 5) + ':00-04:00') : (r.data_visita ? r.data_visita + 'T12:00:00-04:00' : null);
                    const dt = isoPV ? new Date(isoPV) : null;
                    const dataFmt = dt && !isNaN(dt.getTime()) ? dt.toLocaleDateString('pt-BR', { day:'2-digit', month:'short', timeZone: tzPortoVelho }) : (r.data_visita || '-');
                    const hora = r.horario ? String(r.horario).slice(0, 5) : '';
                    const mins = r.duracao_minutos != null && r.duracao_minutos !== '' ? parseInt(r.duracao_minutos, 10) : null;
                    const duracaoTxt = mins != null && !isNaN(mins) && mins >= 0
                        ? (mins >= 60 ? (Math.floor(mins / 60) + 'h ' + (mins % 60 ? mins % 60 + ' min' : '').trim()) : mins + ' min')
                        : '';
                    const status = r.status_visita || '-';
                    const statusLower = status.toLowerCase();
                    const resumo = r.resumo_visita || '';
                    const local = r.local_visita || '';

                    const statusCfg = {
                        'realizada':     { icon: 'fa-check-circle',      color: '#10B981', bg: 'rgba(16,185,129,0.10)',  border: 'rgba(16,185,129,0.25)' },
                        'remarcada':     { icon: 'fa-calendar-alt',      color: '#F59E0B', bg: 'rgba(245,158,11,0.10)',  border: 'rgba(245,158,11,0.25)' },
                        'cancelada':     { icon: 'fa-times-circle',      color: '#EF4444', bg: 'rgba(239,68,68,0.10)',   border: 'rgba(239,68,68,0.25)' },
                        'não encontrado':{ icon: 'fa-question-circle',   color: '#94A3B8', bg: 'rgba(148,163,184,0.10)', border: 'rgba(148,163,184,0.25)' }
                    };
                    const sc = statusCfg[statusLower] || statusCfg['realizada'];

                    const localHtml = local ? `<span style="display:inline-flex; align-items:center; gap:3px; font-size:0.72rem; color:var(--text-secondary); opacity:0.85;"><i class="fas fa-map-pin" style="font-size:0.6rem;"></i>${truncateText(local, 20)}</span>` : '';
                    const resumoHtml = resumo ? `<div style="margin-top:6px; font-size:0.78rem; color:var(--text-secondary); line-height:1.45; padding:6px 10px; background:var(--bg-body); border-radius:8px; border:1px solid var(--border);">${truncateText(resumo, 80)}</div>` : '';

                    return `
                        <div style="display:flex; gap:12px; padding:12px 0; ${idx < rows.length - 1 ? 'border-bottom:1px solid var(--border);' : ''} cursor:pointer; border-radius:8px; transition:background 0.15s;" onmouseover="this.style.background='var(--bg-body)'" onmouseout="this.style.background='transparent'" onclick="if(typeof openDetalheVisitaModal==='function') openDetalheVisitaModal(window.__visitasSemanaData[${idx}])">
                            <div style="flex-shrink:0; width:38px; height:38px; border-radius:10px; background:${sc.bg}; border:1px solid ${sc.border}; display:flex; align-items:center; justify-content:center;">
                                <i class="fas ${sc.icon}" style="color:${sc.color}; font-size:0.85rem;"></i>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                                    <span style="font-weight:700; font-size:0.88rem; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${truncateText(r.prescritor || '-', 30)}</span>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <span style="display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; background:${sc.bg}; border:1px solid ${sc.border}; font-size:0.68rem; font-weight:700; color:${sc.color}; white-space:nowrap;">
                                            <i class="fas ${sc.icon}" style="font-size:0.55rem;"></i>${status}
                                        </span>
                                        <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:6px; background:var(--bg-body); border:1px solid var(--border); color:var(--text-secondary); font-size:0.6rem;" title="Ver detalhes">
                                            <i class="fas fa-expand"></i>
                                        </span>
                                    </div>
                                </div>
                                <div style="display:flex; align-items:center; gap:8px; margin-top:3px; flex-wrap:wrap;">
                                    <span style="display:inline-flex; align-items:center; gap:3px; font-size:0.72rem; color:var(--text-secondary);"><i class="far fa-clock" style="font-size:0.6rem;"></i>${dataFmt}${hora ? ' · ' + hora : ''}${duracaoTxt ? ' · ' + duracaoTxt : ''}</span>
                                    ${localHtml}
                                </div>
                                ${resumoHtml}
                            </div>
                        </div>
                    `;
                }).join('');
            } else {
                vsBody.innerHTML = `
                    <div style="text-align:center; padding:36px 0;">
                        <div style="width:48px; height:48px; border-radius:12px; background:rgba(148,163,184,0.08); display:inline-flex; align-items:center; justify-content:center; margin-bottom:10px;">
                            <i class="fas fa-clipboard-list" style="color:var(--text-secondary); font-size:1.1rem; opacity:0.5;"></i>
                        </div>
                        <div style="font-size:0.85rem; color:var(--text-secondary);">Nenhuma visita registrada esta semana</div>
                    </div>`;
            }
        }

        // 6. Visitas Agendadas (próximas) — cards premium
        const vaBody = document.getElementById('visitasAgendadasBody');
        const vaCount = document.getElementById('visitasAgendadasCount');
        if (vaBody) {
            const rows = data.visitas_agendadas || [];
            if (vaCount) vaCount.textContent = rows.length;
            if (Array.isArray(rows) && rows.length) {
                const hoje = new Date(); hoje.setHours(0,0,0,0);
                const parseDateOnly = (s) => {
                    if (!s) return null;
                    const str = String(s).trim();
                    const m = str.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    if (m) {
                        const y = parseInt(m[1], 10);
                        const mo = parseInt(m[2], 10) - 1;
                        const d = parseInt(m[3], 10);
                        const dtLocal = new Date(y, mo, d);
                        dtLocal.setHours(0, 0, 0, 0);
                        return dtLocal;
                    }
                    const fallback = new Date(str);
                    if (isNaN(fallback.getTime())) return null;
                    fallback.setHours(0, 0, 0, 0);
                    return fallback;
                };
                vaBody.innerHTML = rows.map((r, idx) => {
                    const dt = parseDateOnly(r.data_agendada);
                    const dataFmt = dt && !isNaN(dt.getTime()) ? dt.toLocaleDateString('pt-BR', { day:'2-digit', month:'short' }) : (r.data_agendada || '-');
                    const hora = r.hora ? String(r.hora).slice(0, 5) : '';
                    const diaSemana = dt && !isNaN(dt.getTime()) ? dt.toLocaleDateString('pt-BR', { weekday:'short' }) : '';
                    const prescritor = (r.prescritor || '-');
                    const prescritorAttr = escapeHtml(prescritor);

                    const isHoje = dt && !isNaN(dt.getTime()) && dt.toDateString() === hoje.toDateString();
                    const isAmanha = dt && !isNaN(dt.getTime()) && (() => { const t = new Date(hoje); t.setDate(t.getDate()+1); return dt.toDateString() === t.toDateString(); })();
                    const diffDias = dt && !isNaN(dt.getTime()) ? Math.ceil((dt - hoje) / 86400000) : null;

                    let timeBadge = '';
                    if (isHoje) timeBadge = `<span style="font-size:0.62rem; font-weight:800; padding:1px 6px; border-radius:4px; background:#10B981; color:white;">HOJE</span>`;
                    else if (isAmanha) timeBadge = `<span style="font-size:0.62rem; font-weight:800; padding:1px 6px; border-radius:4px; background:#F59E0B; color:white;">AMANHÃ</span>`;
                    else if (diffDias !== null && diffDias <= 7) timeBadge = `<span style="font-size:0.62rem; font-weight:700; padding:1px 6px; border-radius:4px; background:rgba(37,99,235,0.12); color:#3B82F6;">em ${diffDias}d</span>`;

                    const borderLeft = isHoje ? '3px solid #10B981' : isAmanha ? '3px solid #F59E0B' : '3px solid rgba(37,99,235,0.3)';
                    const canManage = (typeof canManageVisits !== 'undefined') ? !!canManageVisits : false;
                    const hasActiveVisit = (typeof activeVisit !== 'undefined') && activeVisit && activeVisit.prescritor;
                    const isActiveSame = hasActiveVisit && activeVisit.prescritor === prescritor;

                    let rightAction = `<div style="width:8px; height:8px; border-radius:50%; background:${isHoje ? '#10B981' : '#3B82F6'}; box-shadow:0 0 6px ${isHoje ? 'rgba(16,185,129,0.5)' : 'rgba(59,130,246,0.3)'};"></div>`;
                    if (isHoje && canManage) {
                        if (isActiveSame) {
                            rightAction = `<button type="button" class="btn-encerrar-visita" data-prescritor="${prescritorAttr}"
                                style="padding:6px 10px; border-radius:8px; border:none; background:#EF4444; color:#fff; cursor:pointer; font-weight:700; font-size:0.72rem;">Encerrar</button>`;
                        } else {
                            rightAction = `<button type="button" class="btn-iniciar-visita" data-prescritor="${prescritorAttr}" title="Abrir prescritor no modal para iniciar visita."
                                style="padding:6px 10px; border-radius:8px; border:none; background:var(--success); color:#fff; cursor:pointer; font-weight:700; font-size:0.72rem;">Atender</button>`;
                        }
                    }

                    return `
                        <div style="display:flex; gap:12px; padding:12px 0 12px 12px; border-left:${borderLeft}; margin-bottom:${idx < rows.length - 1 ? '8' : '0'}px; border-radius:0 8px 8px 0; background:var(--bg-body); border-top:1px solid var(--border); border-right:1px solid var(--border); border-bottom:1px solid var(--border);">
                            <div style="flex-shrink:0; text-align:center; min-width:44px;">
                                <div style="font-size:1.15rem; font-weight:900; color:var(--text-primary); line-height:1;">${dt && !isNaN(dt.getTime()) ? String(dt.getDate()).padStart(2,'0') : '-'}</div>
                                <div style="font-size:0.62rem; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">${dt && !isNaN(dt.getTime()) ? dt.toLocaleDateString('pt-BR', { month:'short' }).replace('.','') : ''}</div>
                                <div style="font-size:0.58rem; color:var(--text-secondary); text-transform:uppercase; margin-top:1px;">${diaSemana.replace('.','')}</div>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <span style="font-weight:700; font-size:0.88rem; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${truncateText(prescritor, 28)}</span>
                                    ${timeBadge}
                                </div>
                                <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                                    ${hora ? `<span style="display:inline-flex; align-items:center; gap:3px; font-size:0.72rem; color:var(--text-secondary);"><i class="far fa-clock" style="font-size:0.6rem;"></i>${hora}</span>` : ''}
                                    <span style="display:inline-flex; align-items:center; gap:3px; font-size:0.72rem; color:var(--text-secondary);"><i class="far fa-calendar" style="font-size:0.6rem;"></i>${dataFmt}</span>
                                </div>
                            </div>
                            <div style="flex-shrink:0; display:flex; align-items:center; padding-right:10px;">
                                ${rightAction}
                            </div>
                        </div>
                    `;
                }).join('');
                const todayVisits = rows.filter(function (r) {
                    const dt = parseDateOnly(r.data_agendada);
                    return dt && !isNaN(dt.getTime()) && dt.toDateString() === hoje.toDateString();
                }).map(function (r) {
                    return {
                        id: ((r.prescritor || '').trim() + '|' + (r.data_agendada || '') + '|' + (r.hora ? String(r.hora).slice(0, 5) : '')),
                        prescritor: r.prescritor || '-',
                        data_agendada: r.data_agendada,
                        hora: r.hora ? String(r.hora).slice(0, 5) : ''
                    };
                });
                window.__todayAgendaVisits = todayVisits;
            } else {
                vaBody.innerHTML = `
                    <div style="text-align:center; padding:36px 0;">
                        <div style="width:48px; height:48px; border-radius:12px; background:rgba(148,163,184,0.08); display:inline-flex; align-items:center; justify-content:center; margin-bottom:10px;">
                            <i class="fas fa-calendar-check" style="color:var(--text-secondary); font-size:1.1rem; opacity:0.5;"></i>
                        </div>
                        <div style="font-size:0.85rem; color:var(--text-secondary);">Nenhuma visita agendada</div>
                    </div>`;
                window.__todayAgendaVisits = [];
            }
            if (typeof window.mergeNotificationsFromAgenda === 'function') window.mergeNotificationsFromAgenda();
            else if (typeof window.updateNotificationsFromAgenda === 'function') window.updateNotificationsFromAgenda();
        }

        // 7. Mapa de Visitas (GPS)
        try {
            renderDashboardMapaVisitas(data.visitas_mapa || [], data.visitas_mapa_resumo || null, { ano, mes });
        } catch (e) {
            // não quebrar o dashboard se o mapa falhar
        }

    } catch (err) {
        console.error('Erro loadVisitadorDashboard:', err);
        hideLoading();
    }
    hideLoading();
}

// =========================
// MAPA DE VISITAS (DASHBOARD)
// =========================
let __dashVisitasMap = null;
let __dashVisitasLayer = null;
let __dashVisitasLine = null;
let __dashVisitasDataCache = { visitas: [], resumo: null, contexto: null };

function renderDashboardMapaVisitas(visitas, resumo, contexto) {
    const mapEl = document.getElementById('dashboardVisitasMap');
    const emptyEl = document.getElementById('visitasMapaEmpty');
    const resumoEl = document.getElementById('visitasMapaResumo');
    if (!mapEl) return; // não é a página do visitador

    const safeVisitas = Array.isArray(visitas) ? visitas : [];
    __dashVisitasDataCache = { visitas: safeVisitas, resumo: resumo || null, contexto: contexto || null };

    const totalGPS = safeVisitas.length;
    const totalVisitas = resumo && typeof resumo.total_visitas_periodo === 'number' ? resumo.total_visitas_periodo : null;
    const totalRealizadas = resumo && typeof resumo.total_visitas_realizadas === 'number' ? resumo.total_visitas_realizadas : null;

    if (resumoEl) {
        const parts = [];
        if (totalVisitas !== null) parts.push(`<strong>${totalVisitas}</strong> visitas no período`);
        if (totalRealizadas !== null) parts.push(`<strong>${totalRealizadas}</strong> realizadas`);
        parts.push(`<strong>${totalGPS}</strong> com GPS`);
        resumoEl.innerHTML = parts.join(' • ');
    }

    if (emptyEl) emptyEl.style.display = totalGPS ? 'none' : 'block';
    mapEl.style.display = totalGPS ? 'block' : 'none';
    if (!totalGPS) return;

    if (typeof L === 'undefined') {
        if (resumoEl) resumoEl.innerHTML = 'Mapa indisponível (Leaflet não carregou).';
        return;
    }

    // Inicializar mapa 1x
    if (!__dashVisitasMap) {
        __dashVisitasMap = L.map(mapEl, { zoomControl: true, attributionControl: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(__dashVisitasMap);
    }

    // Limpar layers anteriores
    if (__dashVisitasLayer) __dashVisitasLayer.remove();
    if (__dashVisitasLine) __dashVisitasLine.remove();
    __dashVisitasLayer = L.layerGroup().addTo(__dashVisitasMap);

    const points = [];
    safeVisitas.forEach((v) => {
        const lat = parseFloat(v.lat);
        const lng = parseFloat(v.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        points.push([lat, lng]);

        const tzPV = 'America/Porto_Velho';
        const isoPV = v.data_visita && v.horario ? (v.data_visita + 'T' + String(v.horario).slice(0, 5) + ':00-04:00') : (v.data_visita ? v.data_visita + 'T12:00:00-04:00' : null);
        const dtVisita = isoPV ? new Date(isoPV) : null;
        const dataTxt = dtVisita && !isNaN(dtVisita.getTime()) ? dtVisita.toLocaleDateString('pt-BR', { timeZone: tzPV }) : (v.data_visita ? String(v.data_visita) : '-');
        const hora = v.horario ? String(v.horario).slice(0, 5) : '';
        const prescritor = v.prescritor ? truncateText(String(v.prescritor), 40) : '-';
        const status = v.status_visita ? truncateText(String(v.status_visita), 16) : '-';
        const acc = v.accuracy_m != null ? `±${Math.round(parseFloat(v.accuracy_m))}m` : '';

        const popup = `
            <div style="min-width:220px;">
                <div style="font-weight:900; margin-bottom:6px;">${prescritor}</div>
                <div style="font-size:0.82rem; color:#334155;">${dataTxt}${hora ? ' ' + hora : ''} • ${status}</div>
                <div style="font-size:0.78rem; color:#64748B; margin-top:6px;">${lat.toFixed(6)}, ${lng.toFixed(6)} ${acc}</div>
            </div>
        `;

        const marker = L.circleMarker([lat, lng], {
            radius: 6,
            color: '#2563EB',
            weight: 2,
            fillColor: '#60A5FA',
            fillOpacity: 0.8
        }).bindPopup(popup);
        marker.addTo(__dashVisitasLayer);
    });

    if (points.length >= 2) {
        __dashVisitasLine = L.polyline(points, { color: '#10B981', weight: 3, opacity: 0.6 }).addTo(__dashVisitasMap);
    }

    const bounds = L.latLngBounds(points);
    __dashVisitasMap.fitBounds(bounds.pad(0.18));
    setTimeout(() => __dashVisitasMap.invalidateSize(), 120);
}

function openMapaVisitasStatsModal() {
    const modal = document.getElementById('modalMapaVisitasStats');
    if (!modal) return;
    modal.style.display = 'flex';

    const sub = document.getElementById('mapaStatsSubtitulo');
    const totalEl = document.getElementById('mapaStatTotalVisitas');
    const realizadasEl = document.getElementById('mapaStatRealizadas');
    const gpsEl = document.getElementById('mapaStatComGPS');
    const distEl = document.getElementById('mapaStatDistanciaKm');
    const tbody = document.getElementById('mapaStatsTrechosBody');

    const visitas = (__dashVisitasDataCache && Array.isArray(__dashVisitasDataCache.visitas)) ? __dashVisitasDataCache.visitas : [];
    const resumo = __dashVisitasDataCache ? __dashVisitasDataCache.resumo : null;
    const ctx = __dashVisitasDataCache ? __dashVisitasDataCache.contexto : null;

    const anoTxt = ctx && ctx.ano ? String(ctx.ano) : '';
    const mesTxt = ctx && ctx.mes ? `/${String(ctx.mes).padStart(2, '0')}` : '';
    const kmRotasPadrao = resumo && typeof resumo.km_rotas_periodo === 'number' ? resumo.km_rotas_periodo : null;
    if (sub) {
        sub.innerHTML = kmRotasPadrao !== null
            ? `Período: <strong>${anoTxt}${mesTxt || ''}</strong> • distância padrão por rota do dia (GPS contínuo)`
            : `Período: <strong>${anoTxt}${mesTxt || ''}</strong> • cálculo por GPS (somente onde existe ponto)`;
    }

    const totalVisitas = resumo && typeof resumo.total_visitas_periodo === 'number' ? resumo.total_visitas_periodo : null;
    const totalRealizadas = resumo && typeof resumo.total_visitas_realizadas === 'number' ? resumo.total_visitas_realizadas : null;

    if (totalEl) totalEl.textContent = (totalVisitas !== null) ? String(totalVisitas) : '-';
    if (realizadasEl) realizadasEl.textContent = (totalRealizadas !== null) ? String(totalRealizadas) : '-';
    if (gpsEl) gpsEl.textContent = String(visitas.length);

    const stats = calcDistanciaPorTrechos(visitas);
    const distanciaExibir = kmRotasPadrao !== null ? kmRotasPadrao : stats.distanciaKm;
    if (distEl) distEl.textContent = Number(distanciaExibir).toFixed(2);

    if (tbody) {
        if (stats.trechos.length) {
            tbody.innerHTML = stats.trechos.map(t => `
                <tr>
                    <td style="color:var(--text-secondary); font-size:0.85rem;">${t.data}</td>
                    <td style="color:var(--text-primary); font-weight:600;">${truncateText(t.de, 28)} → ${truncateText(t.para, 28)}</td>
                    <td style="text-align:right; font-weight:800; color:var(--warning);">${t.km.toFixed(2)} km</td>
                </tr>
            `).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; padding:26px; color:var(--text-secondary);">Sem GPS suficiente para calcular distância (precisa de 2+ pontos).</td></tr>`;
        }
    }
}

function closeMapaVisitasStatsModal() {
    const modal = document.getElementById('modalMapaVisitasStats');
    if (modal) modal.style.display = 'none';
}

function calcDistanciaPorTrechos(visitas) {
    const pts = (Array.isArray(visitas) ? visitas : [])
        .map(v => ({
            lat: parseFloat(v.lat),
            lng: parseFloat(v.lng),
            data_visita: v.data_visita || null,
            horario: v.horario || null,
            prescritor: v.prescritor || '-'
        }))
        .filter(p => Number.isFinite(p.lat) && Number.isFinite(p.lng));

    let totalKm = 0;
    const trechos = [];
    for (let i = 1; i < pts.length; i++) {
        const a = pts[i - 1];
        const b = pts[i];
        const km = haversineKm(a.lat, a.lng, b.lat, b.lng);
        if (Number.isFinite(km)) {
            totalKm += km;
            const dt = b.data_visita ? (() => {
                const d = new Date(b.data_visita);
                return !isNaN(d.getTime()) ? d.toLocaleDateString('pt-BR') : String(b.data_visita);
            })() : '-';
            trechos.push({
                data: dt,
                de: String(a.prescritor || '-'),
                para: String(b.prescritor || '-'),
                km
            });
        }
    }
    return { distanciaKm: totalKm, trechos };
}

function haversineKm(lat1, lon1, lat2, lon2) {
    const toRad = (d) => (d * Math.PI) / 180;
    const R = 6371; // km
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function renderInsights(insights) {
    const grid = document.getElementById('insightsGrid');
    grid.innerHTML = insights.map(ins => `
        <div class="insight-card ${ins.type}">
            <div class="insight-icon">${ins.icon}</div>
            <div class="insight-title">${ins.title}</div>
            <div class="insight-text">${ins.text}</div>
            <div class="insight-metric ${ins.metricClass}">${ins.metric}</div>
        </div>
    `).join('');
}

// ============ IMPORT ============
async function importarDados() {
    const btn = document.getElementById('importBtn');
    const progress = document.getElementById('importProgress');
    const progressFill = document.getElementById('progressFill');
    const statusEl = document.getElementById('importStatus');
    const resultsEl = document.getElementById('importResults');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importando...';
    progress.style.display = 'block';
    resultsEl.innerHTML = '';

    let pct = 0;
    const interval = setInterval(() => {
        pct = Math.min(pct + Math.random() * 15, 90);
        progressFill.style.width = pct + '%';
    }, 500);

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 600000);
        const response = await fetch('scripts/importar_dados.php', {
            credentials: 'same-origin',
            signal: controller.signal
        });
        clearTimeout(timeoutId);
        const data = await response.json();

        clearInterval(interval);
        progressFill.style.width = '100%';

        if (data.success) {
            statusEl.textContent = '✅ ' + data.message;
            statusEl.style.color = 'var(--success)';

            resultsEl.innerHTML = Object.entries(data.registros_importados).map(([table, count]) => `
                <div class="import-result-item">
                    <span class="label">${table.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</span>
                    <span class="value">${formatNumber(count)} registros</span>
                </div>
            `).join('');

            // Refresh data
            await loadPageData(currentPage);
        } else {
            statusEl.textContent = '❌ Erro: ' + (data.error || 'Falha na importação');
            statusEl.style.color = 'var(--danger)';
        }
    } catch (err) {
        clearInterval(interval);
        statusEl.textContent = '❌ Erro de conexão: ' + err.message;
        statusEl.style.color = 'var(--danger)';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-upload"></i> Importar Novamente';
}

// ============ ADMIN - User Management ============
async function loadAdmin() {
    try {
        const result = await apiGet('list_users');
        if (!result.success) {
            console.error('Erro:', result.error);
            return;
        }
        const users = result.users || [];

        // Render stats
        const totalUsers = users.length;
        const activeUsers = users.filter(u => u.ativo == 1).length;
        const adminUsers = users.filter(u => u.tipo === 'admin').length;
        const regularUsers = users.filter(u => u.tipo === 'usuario').length;

        document.getElementById('adminStats').innerHTML = `
            <div class="admin-stat-card">
                <div class="admin-stat-number">${totalUsers}</div>
                <div class="admin-stat-label">Total de Usuários</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-number" style="color: var(--success);">${activeUsers}</div>
                <div class="admin-stat-label">Ativos</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-number" style="color: #7B68EE;">${adminUsers}</div>
                <div class="admin-stat-label">Administradores</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-number" style="color: var(--info);">${regularUsers}</div>
                <div class="admin-stat-label">Usuários Comuns</div>
            </div>
        `;

        // Render table
        const tbody = document.querySelector('#tableUsers tbody');
        tbody.innerHTML = users.map(u => {
            const setorIcons = { Visitador: 'walking', Vendedor: 'cash-register', Gerente: 'user-tie', Caixa: 'money-bill-wave', Atendente: 'headset' };
            const setorIcon = setorIcons[u.setor] || 'briefcase';
            const safeNome = (u.nome || '').replace(/'/g, '&#39;');
            const safeUsuario = (u.usuario || '').replace(/'/g, '&#39;');
            const safeSetor = (u.setor || '').replace(/'/g, '&#39;');
            const safeWhatsapp = (u.whatsapp || '').replace(/'/g, '&#39;');
            const whatsappNum = (u.whatsapp || '').replace(/\D/g, '');
            const whatsappLink = whatsappNum ? `https://wa.me/55${whatsappNum}` : '';
            return `
            <tr>
                <td><span style="color:var(--text-muted);font-weight:600;">#${u.id}</span></td>
                <td style="color:var(--text-primary);font-weight:500;">${u.nome}</td>
                <td><code style="background:var(--bg-input);padding:2px 8px;border-radius:4px;font-size:0.82rem;">${u.usuario}</code></td>
                <td>
                    ${u.setor ? `<span class="user-setor-badge"><i class="fas fa-${setorIcon}"></i> ${u.setor}</span>` : '<span style="color:var(--text-muted);">\u2014</span>'}
                </td>
                <td>
                    ${u.whatsapp ? `<a href="${whatsappLink}" target="_blank" style="color:#25D366;text-decoration:none;font-weight:500;display:inline-flex;align-items:center;gap:4px;" title="Abrir no WhatsApp"><i class="fab fa-whatsapp"></i> ${u.whatsapp}</a>` : '<span style="color:var(--text-muted);">\u2014</span>'}
                </td>
                <td>
                    <span class="user-type-badge ${u.tipo}">
                        <i class="fas fa-${u.tipo === 'admin' ? 'shield-alt' : 'user'}"></i>
                        ${u.tipo === 'admin' ? 'Admin' : 'Usu\u00e1rio'}
                    </span>
                </td>
                <td>
                    <span class="user-status-badge ${u.ativo == 1 ? 'active' : 'inactive'}">
                        <i class="fas fa-circle" style="font-size:6px;"></i>
                        ${u.ativo == 1 ? 'Ativo' : 'Inativo'}
                    </span>
                </td>
                <td style="font-size:0.82rem;color:var(--text-secondary);">${u.criado_em || '\u2014'}</td>
                <td style="font-size:0.82rem;color:var(--text-secondary);">${u.ultimo_acesso || 'Nunca'}</td>
                <td>
                    <div class="action-btns">
                        <button class="btn-action edit" onclick="openMetasModal(${u.id}, '${safeNome}', ${u.meta_mensal || 0}, ${u.meta_anual || 0}, ${u.comissao_percentual || 0}, ${u.meta_visitas_semana || 0}, ${u.meta_visitas_mes || 0}, ${u.premio_visitas || 0})" title="Definir Metas">
                            <i class="fas fa-bullseye" style="color:var(--warning);"></i>
                        </button>
                        <button class="btn-action edit" onclick="openEditUserModal(${u.id}, '${safeNome}', '${safeUsuario}', '${u.tipo}', '${safeSetor}', '${safeWhatsapp}')" title="Editar">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="btn-action toggle" onclick="toggleUserStatus(${u.id})" title="${u.ativo == 1 ? 'Desativar' : 'Ativar'}">
                            <i class="fas fa-${u.ativo == 1 ? 'toggle-on' : 'toggle-off'}"></i>
                        </button>
                        <button class="btn-action delete" onclick="deleteUser(${u.id}, '${safeNome}')" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `}).join('');

    } catch (err) {
        console.error('Erro ao carregar admin:', err);
    }
}

// ---- Modal helpers ----
function openAddUserModal() {
    document.getElementById('formAddUser').reset();
    document.getElementById('addUserError').style.display = 'none';
    document.getElementById('modalAddUser').style.display = 'flex';
}

function openEditUserModal(id, nome, usuario, tipo, setor, whatsapp) {
    document.getElementById('editId').value = id;
    document.getElementById('editNome').value = nome.replace(/&#39;/g, "'");
    document.getElementById('editUsuario').value = usuario.replace(/&#39;/g, "'");
    document.getElementById('editTipo').value = tipo;
    document.getElementById('editSetor').value = setor || '';
    document.getElementById('editWhatsapp').value = (whatsapp || '').replace(/&#39;/g, "'");
    document.getElementById('editSenha').value = '';
    document.getElementById('editUserError').style.display = 'none';
    document.getElementById('modalEditUser').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// ---- Edit Metas ----
function openMetasModal(id, nome, mensal, anual, comissao, visitasSemana, visitasMes, premio) {
    document.getElementById('metasUserId').value = id;
    document.getElementById('metasUserName').textContent = 'Usuário: ' + nome.replace(/&#39;/g, "'");
    document.getElementById('metasMensal').value = parseFloat(mensal).toFixed(2);
    document.getElementById('metasAnual').value = parseFloat(anual).toFixed(2);
    document.getElementById('metasComissao').value = parseFloat(comissao).toFixed(2);
    document.getElementById('metasVisitasSemana').value = parseInt(visitasSemana || 0);
    document.getElementById('metasVisitasMes').value = parseInt(visitasMes || 0);
    document.getElementById('metasPremio').value = parseFloat(premio || 0).toFixed(2);
    document.getElementById('metasError').style.display = 'none';
    document.getElementById('modalEditMetas').style.display = 'flex';
}

async function submitMetas(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveMetas');
    const errorEl = document.getElementById('metasError');
    errorEl.style.display = 'none';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    try {
        const result = await apiPost('edit_user_metas', {
            id: parseInt(document.getElementById('metasUserId').value),
            meta_mensal: parseFloat(document.getElementById('metasMensal').value),
            meta_anual: parseFloat(document.getElementById('metasAnual').value),
            comissao_percentual: parseFloat(document.getElementById('metasComissao').value),
            meta_visitas_semana: parseInt(document.getElementById('metasVisitasSemana').value),
            meta_visitas_mes: parseInt(document.getElementById('metasVisitasMes').value),
            premio_visitas: parseFloat(document.getElementById('metasPremio').value)
        });

        if (result.success) {
            closeModal('modalEditMetas');
            showToast('success', result.message);
            await loadAdmin();
        } else {
            errorEl.textContent = result.error;
            errorEl.style.display = 'block';
        }
    } catch (err) {
        errorEl.textContent = 'Erro de conexão';
        errorEl.style.display = 'block';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Salvar Metas';
    return false;
}

// ---- Add User ----
async function submitAddUser(e) {
    e.preventDefault();
    const btn = document.getElementById('btnAddUser');
    const errorEl = document.getElementById('addUserError');
    errorEl.style.display = 'none';
    const senhaAdd = document.getElementById('addSenha').value;
    if (!validateStrongPasswordClientSide(senhaAdd)) {
        errorEl.textContent = 'Senha inválida: ' + strongPasswordRuleText();
        errorEl.style.display = 'block';
        return false;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Criando...';

    try {
        const result = await apiPost('add_user', {
            nome: document.getElementById('addNome').value,
            usuario: document.getElementById('addUsuario').value,
            senha: document.getElementById('addSenha').value,
            tipo: document.getElementById('addTipo').value,
            setor: document.getElementById('addSetor').value,
            whatsapp: document.getElementById('addWhatsapp').value
        });

        if (result.success) {
            closeModal('modalAddUser');
            showToast('success', result.message);
            await loadAdmin();
        } else {
            errorEl.textContent = result.error;
            errorEl.style.display = 'block';
        }
    } catch (err) {
        errorEl.textContent = 'Erro de conexão';
        errorEl.style.display = 'block';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> Criar Usuário';
    return false;
}

// ---- Edit User ----
async function submitEditUser(e) {
    e.preventDefault();
    const btn = document.getElementById('btnEditUser');
    const errorEl = document.getElementById('editUserError');
    errorEl.style.display = 'none';
    const senhaEdit = document.getElementById('editSenha').value;
    if (senhaEdit && !validateStrongPasswordClientSide(senhaEdit)) {
        errorEl.textContent = 'Senha inválida: ' + strongPasswordRuleText();
        errorEl.style.display = 'block';
        return false;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';

    try {
        const result = await apiPost('edit_user', {
            id: parseInt(document.getElementById('editId').value),
            nome: document.getElementById('editNome').value,
            usuario: document.getElementById('editUsuario').value,
            senha: document.getElementById('editSenha').value,
            tipo: document.getElementById('editTipo').value,
            setor: document.getElementById('editSetor').value,
            whatsapp: document.getElementById('editWhatsapp').value
        });

        if (result.success) {
            closeModal('modalEditUser');
            showToast('success', result.message);
            await loadAdmin();
        } else {
            errorEl.textContent = result.error;
            errorEl.style.display = 'block';
        }
    } catch (err) {
        errorEl.textContent = 'Erro de conexão';
        errorEl.style.display = 'block';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';
    return false;
}

// ---- Toggle User Status ----
async function toggleUserStatus(id) {
    try {
        const result = await apiPost('toggle_user', { id });
        if (result.success) {
            showToast('success', result.message);
            await loadAdmin();
        } else {
            showToast('error', result.error);
        }
    } catch (err) {
        showToast('error', 'Erro de conexão');
    }
}

// ---- Delete User ----
async function deleteUser(id, nome) {
    if (!confirm(`Tem certeza que deseja EXCLUIR o usuário "${nome}"?\n\nEsta ação não pode ser desfeita.`)) {
        return;
    }

    try {
        const result = await apiPost('delete_user', { id });
        if (result.success) {
            showToast('success', result.message);
            await loadAdmin();
        } else {
            showToast('error', result.error);
        }
    } catch (err) {
        showToast('error', 'Erro de conexão');
    }
}

// ---- Toast Notification ----
function showToast(type, message) {
    const existing = document.querySelector('.toast-notification');
    if (existing) existing.remove();

    const icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.innerHTML = `<i class="fas fa-${icon}"></i> ${message}`;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100px)';
        toast.style.transition = '0.4s ease';
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}

// ============ Chart Helpers ============
function destroyChart(name) {
    if (charts[name]) {
        charts[name].destroy();
        delete charts[name];
    }
}

// ============ Theme Toggle ============
function getThemeStorageKey() {
    const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
    return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
}

function getSavedThemeForCurrentUser() {
    const userKey = getThemeStorageKey();
    return localStorage.getItem(userKey) || localStorage.getItem('mypharm_theme');
}

function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem(getThemeStorageKey(), newTheme);
    // Mantem chave legada para telas sem usuario identificado
    localStorage.setItem('mypharm_theme', newTheme);
    applyChartTheme(newTheme);
}

function applyChartTheme(theme) {
    if (theme === 'light') {
        Chart.defaults.color = '#5A6178';
        Chart.defaults.borderColor = 'rgba(0,0,0,0.06)';
    } else {
        Chart.defaults.color = '#9CA3B8';
        Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
    }

    const gridColor = theme === 'light' ? 'rgba(0,0,0,0.06)' : 'rgba(255,255,255,0.04)';
    const pointBorderColor = theme === 'light' ? '#FFFFFF' : '#0F1117';

    // Update existing charts
    Object.values(charts).forEach(chart => {
        if (chart.options?.scales?.y?.grid) {
            chart.options.scales.y.grid.color = gridColor;
        }
        if (chart.options?.scales?.x?.grid) {
            chart.options.scales.x.grid.color = gridColor;
        }

        // Update point border colors for line charts
        chart.data.datasets.forEach(dataset => {
            if (dataset.pointBorderColor) {
                dataset.pointBorderColor = pointBorderColor;
            }
        });

        chart.update('none');
    });
}

function loadSavedTheme() {
    const saved = getSavedThemeForCurrentUser();
    if (saved) {
        document.documentElement.setAttribute('data-theme', saved);
        applyChartTheme(saved);
    }
}

async function fetchCsrfToken() {
    try {
        const resp = await fetch(`${API_URL}?action=csrf_token`, { credentials: 'include' });
        const data = await resp.json();
        if (data.token) __csrfToken = data.token;
    } catch (_) {}
}

// ============ Init ============
document.addEventListener('DOMContentLoaded', async () => {
    loadSavedTheme();
    initParticles();

    var sessionError = sessionStorage.getItem('sessionError');
    if (sessionError) {
        sessionStorage.removeItem('sessionError');
        var errEl = document.getElementById('loginError');
        if (errEl) {
            errEl.textContent = sessionError;
            errEl.style.display = 'block';
        }
    }

    await fetchCsrfToken();

    if (localStorage.getItem('loggedIn')) {
        showApp(
            localStorage.getItem('userName'),
            localStorage.getItem('userType'),
            localStorage.getItem('foto_perfil')
        );
        if (!window.location.pathname.includes('visitador.html')) {
            apiGet('check_session').then(function (s) {
                if (s && s.foto_perfil) {
                    localStorage.setItem('foto_perfil', s.foto_perfil);
                    var nome = document.getElementById('userName') && document.getElementById('userName').textContent;
                    applyAvatarAdmin((nome || 'A').charAt(0), s.foto_perfil);
                }
            }).catch(function () {});
        }
    }
});
