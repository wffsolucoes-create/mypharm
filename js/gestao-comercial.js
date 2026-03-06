(function () {
    // Tudo da gestão comercial usa api_gestao.php
    const API_URL = 'api_gestao.php';
    let gcSelectedVendedor = '__ALL__';
    let gcDashboardData = null;
    let gcNomesVendedores = [];

    function getThemeStorageKey() {
        const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
        return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
    }

    function getSavedThemeForCurrentUser() {
        const userKey = getThemeStorageKey();
        return localStorage.getItem(userKey) || localStorage.getItem('mypharm_theme');
    }

    function loadSavedTheme() {
        const saved = getSavedThemeForCurrentUser();
        if (saved) document.documentElement.setAttribute('data-theme', saved);
    }

    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem(getThemeStorageKey(), newTheme);
        localStorage.setItem('mypharm_theme', newTheme);
    }

    async function fetchSession() {
        const r = await fetch(`${API_URL}?action=check_session`, { credentials: 'include' });
        const text = await r.text();
        try { return text ? JSON.parse(text) : {}; } catch (e) { return {}; }
    }

    async function apiPost(action, data = {}) {
        const r = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
            credentials: 'include'
        });
        return r.json();
    }

    async function apiGet(action, params = {}) {
        const query = new URLSearchParams({ action, ...params });
        const r = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'include' });
        const text = await r.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch (e) {
            if (!r.ok) console.error('Resposta não-JSON:', text ? text.slice(0, 200) : r.status);
            return { success: false, error: r.ok ? '' : 'Erro ' + r.status };
        }
    }

    function formatMoney(v) {
        const n = Number(v || 0);
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function formatPercent(v) {
        const n = Number(v || 0);
        return `${n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}%`;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeName(v) {
        return String(v || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function setDefaultFilters() {
        const deEl = document.getElementById('gcDataDe');
        const ateEl = document.getElementById('gcDataAte');
        if (!deEl || !ateEl) return;
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        deEl.value = `${yyyy}-${mm}-01`;
        ateEl.value = `${yyyy}-${mm}-${dd}`;
    }

    function renderVendedoresTabs(data, nomesFromApi) {
        const wrap = document.getElementById('gcVendedoresTabs');
        if (!wrap) return;
        var nomes = Array.isArray(nomesFromApi) && nomesFromApi.length > 0
            ? nomesFromApi.slice()
            : [];
        if (nomes.length === 0) {
            const cadastrados = data?.vendedor_gestao?.vendedores_cadastrados || [];
            const equipe = data?.vendedor_gestao?.equipe || [];
            cadastrados.forEach(function (v) {
                var n = String(v?.nome || '').trim();
                if (n && nomes.indexOf(n) === -1) nomes.push(n);
            });
            equipe.forEach(function (r) {
                var nomeEq = String(r?.atendente || '').trim();
                if (nomeEq && nomeEq !== '(Sem atendente)' && nomes.indexOf(nomeEq) === -1) nomes.push(nomeEq);
            });
        }

        const options = ['__ALL__'].concat(nomes);
        if (gcSelectedVendedor !== '__ALL__' && !nomes.includes(gcSelectedVendedor)) {
            gcSelectedVendedor = '__ALL__';
        }

        wrap.innerHTML = options.map(function (nome) {
            const label = nome === '__ALL__' ? 'Todos' : nome;
            const active = nome === gcSelectedVendedor ? ' active' : '';
            return `<button type="button" class="gc-vendedor-tab${active}" data-vendedor="${escapeHtml(nome)}">${escapeHtml(label)}</button>`;
        }).join('');

        wrap.querySelectorAll('.gc-vendedor-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const novo = btn.getAttribute('data-vendedor') || '__ALL__';
                gcSelectedVendedor = novo;
                renderVendedoresTabs(gcDashboardData || {}, gcNomesVendedores);
                renderVendedores(gcDashboardData || {});
            });
        });
    }

    function renderVendedores(data) {
        const bloco = data?.vendedor_gestao || {};
        const resumo = bloco.resumo || {};
        const equipeRaw = bloco.equipe || [];
        const motivosRaw = bloco.motivos_perda || [];
        const rejeitadosRaw = bloco.clientes_rejeitados_com_contato || [];

        const equipe = gcSelectedVendedor === '__ALL__'
            ? equipeRaw
            : equipeRaw.filter(function (r) { return normalizeName(r.atendente) === normalizeName(gcSelectedVendedor); });

        const motivos = gcSelectedVendedor === '__ALL__'
            ? motivosRaw
            : motivosRaw.filter(function (m) { return normalizeName(m.atendente) === normalizeName(gcSelectedVendedor); });

        const rejeitados = gcSelectedVendedor === '__ALL__'
            ? rejeitadosRaw
            : rejeitadosRaw.filter(function (c) { return normalizeName(c.atendente) === normalizeName(gcSelectedVendedor); });

        let resumoView = resumo;
        if (gcSelectedVendedor !== '__ALL__') {
            let receita = 0;
            let conv = 0;
            let tempo = 0;
            let clientes = 0;
            let perda = 0;
            const total = equipe.length;
            equipe.forEach(function (r) {
                receita += Number(r.receita || 0);
                conv += Number(r.conversao_individual || 0);
                tempo += Number(r.tempo_medio_espera_resposta || 0);
                clientes += Number(r.clientes_atendidos || 0);
                perda += Number(r.taxa_perda || 0);
            });
            resumoView = {
                consultoras_ativas: total,
                receita_equipe: receita,
                conversao_media: total ? (conv / total) : 0,
                tempo_medio_espera_min: total ? (tempo / total) : 0,
                clientes_atendidos: clientes,
                taxa_perda_media: total ? (perda / total) : 0
            };
        }

        const map = {
            gcVendConsultorasAtivas: (resumoView.consultoras_ativas ?? 0).toLocaleString('pt-BR'),
            gcVendReceitaEquipe: formatMoney(resumoView.receita_equipe ?? 0),
            gcVendConversaoMedia: formatPercent(resumoView.conversao_media ?? 0),
            gcVendTempoMedio: `${Number(resumoView.tempo_medio_espera_min ?? 0).toLocaleString('pt-BR')} min`,
            gcVendClientesAtendidos: (resumoView.clientes_atendidos ?? 0).toLocaleString('pt-BR'),
            gcVendTaxaPerda: formatPercent(resumoView.taxa_perda_media ?? 0),
        };
        Object.keys(map).forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });

        const equipeBody = document.getElementById('gcVendEquipeTbody');
        if (equipeBody) {
            equipeBody.innerHTML = equipe.map(function (r) {
                return `<tr>
                    <td>${escapeHtml(r.atendente || '-')}</td>
                    <td>${formatMoney(r.receita || 0)}</td>
                    <td>${Number(r.total_pedidos || 0).toLocaleString('pt-BR')}</td>
                    <td>${Number(r.vendas_aprovadas || 0).toLocaleString('pt-BR')}</td>
                    <td>${Number(r.vendas_rejeitadas || 0).toLocaleString('pt-BR')}</td>
                    <td>${formatPercent(r.conversao_individual || 0)}</td>
                    <td>${Number(r.tempo_medio_espera_resposta || 0).toLocaleString('pt-BR')} min</td>
                    <td>${Number(r.clientes_atendidos || 0).toLocaleString('pt-BR')}</td>
                    <td>${formatPercent(r.taxa_perda || 0)}</td>
                </tr>`;
            }).join('');
            if (!equipe.length) {
                equipeBody.innerHTML = `<tr><td colspan="9">Sem dados no período selecionado.</td></tr>`;
            }
        }

        const motivosBody = document.getElementById('gcVendMotivosTbody');
        if (motivosBody) {
            motivosBody.innerHTML = motivos.map(function (m) {
                return `<tr>
                    <td>${escapeHtml(m.motivo || '-')}</td>
                    <td>${Number(m.quantidade || 0).toLocaleString('pt-BR')}</td>
                    <td>${formatMoney(m.valor_total || 0)}</td>
                </tr>`;
            }).join('');
            if (!motivos.length) {
                motivosBody.innerHTML = `<tr><td colspan="3">Sem perdas registradas no período.</td></tr>`;
            }
        }

        const rejeitadosBody = document.getElementById('gcVendRejeitadosTbody');
        if (rejeitadosBody) {
            rejeitadosBody.innerHTML = rejeitados.map(function (c) {
                return `<tr>
                    <td>${escapeHtml(c.cliente || '-')}</td>
                    <td>${escapeHtml(c.prescritor || '-')}</td>
                    <td>${escapeHtml(c.contato || 'Sem contato')}</td>
                    <td>${Number(c.qtd_rejeicoes || 0).toLocaleString('pt-BR')}</td>
                    <td>${formatMoney(c.valor_rejeitado || 0)}</td>
                </tr>`;
            }).join('');
            if (!rejeitados.length) {
                rejeitadosBody.innerHTML = `<tr><td colspan="5">Sem clientes rejeitados no período.</td></tr>`;
            }
        }
    }

    async function loadDashboardData() {
        const deEl = document.getElementById('gcDataDe');
        const ateEl = document.getElementById('gcDataAte');
        const params = {};
        if (deEl?.value) params.data_de = deEl.value;
        if (ateEl?.value) params.data_ate = ateEl.value;
        var data = await apiGet('gestao_comercial_dashboard', params);
        if (data && data.error) {
            console.error('Gestão Comercial (dashboard):', data.error);
        }
        if (!data || data.success === false) {
            data = {};
        }
        gcDashboardData = data;
        gcNomesVendedores = [];
        try {
            var resLista = await apiGet('gestao_comercial_lista_vendedores', {});
            if (resLista && resLista.success && Array.isArray(resLista.nomes)) {
                gcNomesVendedores = resLista.nomes;
            }
        } catch (e) {}
        renderVendedoresTabs(data, gcNomesVendedores);
        renderVendedores(data);
    }

    function forceLogoutRedirect() {
        localStorage.clear();
        window.location.href = 'index.html';
    }

    function validateLocalAccess() {
        if (!localStorage.getItem('loggedIn')) return false;
        return (localStorage.getItem('userType') || '') === 'admin';
    }

    async function enforceAdminAccess() {
        if (!validateLocalAccess()) {
            forceLogoutRedirect();
            return null;
        }
        try {
            const session = await fetchSession();
            if (!session || !session.logged_in || session.tipo !== 'admin') {
                forceLogoutRedirect();
                return null;
            }
            return session;
        } catch (_) {
            forceLogoutRedirect();
            return null;
        }
    }

    function bindTabs() {
        const tabs = Array.from(document.querySelectorAll('.gc-menu-item'));
        const sections = Array.from(document.querySelectorAll('.gc-section'));
        if (!tabs.length || !sections.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                if (e) e.preventDefault();
                const target = tab.getAttribute('data-section');
                tabs.forEach(t => t.classList.remove('active'));
                sections.forEach(s => s.classList.remove('active'));
                tab.classList.add('active');
                const section = document.getElementById(`gc-section-${target}`);
                if (section) section.classList.add('active');
            });
        });
    }

    function bindUi(session) {
        const nome = (session && session.nome) || localStorage.getItem('userName') || 'Administrador';
        const nomeEl = document.getElementById('gcUserName');
        if (nomeEl) nomeEl.textContent = nome;
        const avatarEl = document.getElementById('gcAvatar');
        if (avatarEl) avatarEl.textContent = (nome || 'A').charAt(0).toUpperCase();

        const logoutBtn = document.getElementById('gcLogoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', async function () {
                try { await apiPost('logout', {}); } catch (_) {}
                forceLogoutRedirect();
            });
        }

        const themeBtn = document.getElementById('gcThemeBtn');
        if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

        const applyBtn = document.getElementById('gcApplyFiltersBtn');
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                loadDashboardData().catch(function () {});
            });
        }

        bindTabs();
    }

    document.addEventListener('DOMContentLoaded', async function () {
        loadSavedTheme();
        setDefaultFilters();
        const session = await enforceAdminAccess();
        if (!session) return;
        const app = document.getElementById('gcApp');
        if (app) app.style.display = 'flex';
        bindUi(session);
        loadDashboardData().catch(function () {});
    });
})();
