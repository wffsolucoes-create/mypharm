(function () {
    // Tudo da gestão comercial usa api_gestao.php
    const API_URL = 'api_gestao.php';
    let gcSelectedVendedor = '__ALL__';
    let gcDashboardData = null;
    let gcNomesVendedores = [];
    let gcLoading = false;
    let gcApplyBtnHtml = null;

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
            console.error('Gestão Comercial apiGet:', action, e.message, text ? text.slice(0, 400) : '(vazio)');
            return {
                success: false,
                error: r.ok
                    ? 'A API retornou texto que não é JSON (veja erros PHP ou avisos antes do JSON no Network/F12).'
                    : ('Erro HTTP ' + r.status)
            };
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

    function showGcDashboardError(msg) {
        if (!msg) return;
        console.error('Gestão Comercial (dashboard):', msg);
        var errWrap = document.querySelector('.gc-filters');
        if (!errWrap) return;
        var errEl = document.getElementById('gcApiError');
        if (!errEl) {
            errEl = document.createElement('div');
            errEl.id = 'gcApiError';
            errEl.className = 'gc-api-error';
            errEl.setAttribute('role', 'alert');
            errWrap.appendChild(errEl);
        }
        errEl.textContent = msg;
    }

    function clearGcDashboardError() {
        var errEl = document.getElementById('gcApiError');
        if (errEl) errEl.remove();
    }

    function setGcLoading(isLoading) {
        gcLoading = !!isLoading;
        const app = document.getElementById('gcApp');
        if (app) app.classList.toggle('gc-loading', gcLoading);
        const overlay = document.getElementById('gcLoadingOverlay');
        if (overlay) {
            overlay.classList.toggle('active', gcLoading);
            overlay.setAttribute('aria-hidden', gcLoading ? 'false' : 'true');
        }

        const applyBtn = document.getElementById('gcApplyFiltersBtn');
        if (applyBtn) {
            if (gcApplyBtnHtml === null) gcApplyBtnHtml = applyBtn.innerHTML;
            applyBtn.disabled = gcLoading;
            applyBtn.classList.toggle('is-loading', gcLoading);
            applyBtn.innerHTML = gcLoading
                ? '<i class="fas fa-circle-notch fa-spin"></i> Carregando...'
                : gcApplyBtnHtml;
        }

        document.querySelectorAll('.gc-card .gc-value').forEach(function (el) {
            if (!el.dataset.gcDefaultValue) {
                el.dataset.gcDefaultValue = (el.textContent || '—').trim() || '—';
            }
            if (gcLoading) {
                el.textContent = 'Carregando...';
                el.classList.add('is-loading');
            } else {
                el.classList.remove('is-loading');
                if ((el.textContent || '').trim() === 'Carregando...') {
                    el.textContent = el.dataset.gcDefaultValue || '—';
                }
            }
        });

        const tbodies = [
            { id: 'gcVendEquipeTbody', cols: 9, emptyText: 'Sem dados no período selecionado.' },
            { id: 'gcVendMotivosTbody', cols: 3, emptyText: 'Sem perdas registradas no período.' },
            { id: 'gcVendRejeitadosTbody', cols: 5, emptyText: 'Sem clientes rejeitados no período.' }
        ];
        tbodies.forEach(function (cfg) {
            const body = document.getElementById(cfg.id);
            if (!body) return;
            if (gcLoading) {
                body.innerHTML = '<tr><td colspan="' + cfg.cols + '">Carregando dados...</td></tr>';
                return;
            }
            if (body.textContent && body.textContent.indexOf('Carregando dados...') !== -1) {
                body.innerHTML = '<tr><td colspan="' + cfg.cols + '">' + cfg.emptyText + '</td></tr>';
            }
        });
    }

    async function loadDashboardData() {
        setGcLoading(true);
        try {
            const deEl = document.getElementById('gcDataDe');
            const ateEl = document.getElementById('gcDataAte');
            const params = {};
            if (deEl?.value) params.data_de = deEl.value;
            if (ateEl?.value) params.data_ate = ateEl.value;
            var data = await apiGet('gestao_comercial_dashboard', params);

            var dashboardOk = data && data.success === true && data.crescimento && typeof data.crescimento === 'object';
            if (!dashboardOk) {
                var msg = (data && data.error) ? data.error : '';
                if (!msg) {
                    if (!data || (typeof data === 'object' && Object.keys(data).length === 0)) {
                        msg = 'Resposta vazia da API. Abra o DevTools (F12) → aba Network → gestao_comercial_dashboard e veja o corpo da resposta.';
                    } else {
                        msg = 'O painel não retornou dados válidos (success ou bloco crescimento ausente). Confirme login como administrador e tente Aplicar de novo.';
                    }
                }
                showGcDashboardError(msg);
                return;
            }
            clearGcDashboardError();

            gcDashboardData = data;
            gcNomesVendedores = [];
            try {
                var resLista = await apiGet('gestao_comercial_lista_vendedores', {});
                if (resLista && resLista.success && Array.isArray(resLista.nomes)) {
                    gcNomesVendedores = resLista.nomes;
                }
            } catch (e) {}
            renderExecutivo(data);
            renderRdMetricas(data.rd_metricas, data.rd_metricas_error);
            renderVendedoresTabs(data, gcNomesVendedores);
            renderVendedores(data);
        } finally {
            setGcLoading(false);
        }
    }

    function fmtVal(v, type) {
        if (v === null || v === undefined || v === '') return '—';
        if (type === 'percent') return formatPercent(v);
        if (type === 'money') return formatMoney(v);
        if (type === 'int') return Number(v).toLocaleString('pt-BR');
        if (type === 'float') return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        return String(v);
    }

    function renderExecutivo(data) {
        var c = data?.crescimento || {};
        var e = data?.eficiencia_comercial || {};
        var r = data?.rentabilidade || {};
        var f = data?.fidelizacao || {};
        var map = {
            gcCrescReceitaMes: fmtVal(c.receita_mes, 'money'),
            gcCrescCrescimentoMesAnt: c.crescimento_vs_mes_anterior != null ? fmtVal(c.crescimento_vs_mes_anterior, 'percent') : '—',
            gcCrescCrescimentoAnoPassado: c.crescimento_vs_mes_ano_passado != null ? fmtVal(c.crescimento_vs_mes_ano_passado, 'percent') : '—',
            gcCrescTicketMedio: fmtVal(c.ticket_medio, 'money'),
            gcCrescNumVendas: fmtVal(c.numero_vendas, 'int'),
            gcCrescClientesAtivos6m: fmtVal(c.numero_clientes_ativos_6_meses, 'int'),
            gcEficLeads: fmtVal(e.leads_recebidos, 'int'),
            gcEficConversao: e.conversao_geral != null ? fmtVal(e.conversao_geral, 'percent') : '—',
            gcEficTempoFechamento: e.tempo_medio_fechamento_horas != null && e.tempo_medio_fechamento_horas !== '' ? Number(e.tempo_medio_fechamento_horas).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' h' : '—',
            gcEficTaxaPerda: e.taxa_perda_cliente != null ? fmtVal(e.taxa_perda_cliente, 'percent') : '—',
            gcEficLtv: e.ltv != null ? fmtVal(e.ltv, 'money') : '—',
            gcEficCac: e.cac != null ? fmtVal(e.cac, 'money') : (e.notas && e.notas.cac ? e.notas.cac : '—'),
            gcEficLtvCac: e.ltv_cac != null ? fmtVal(e.ltv_cac, 'float') : (e.notas && e.notas.ltv_cac ? e.notas.ltv_cac : '—'),
            gcRentMargemBruta: r.margem_bruta != null ? fmtVal(r.margem_bruta, 'percent') : '—',
            gcRentMargemContrib: r.margem_contribuicao != null ? fmtVal(r.margem_contribuicao, 'percent') : '—',
            gcRentCmv: r.cmv_sobre_faturamento != null ? fmtVal(r.cmv_sobre_faturamento, 'percent') : '—',
            gcRentPontoEquilibrio: r.ponto_equilibrio != null ? fmtVal(r.ponto_equilibrio, 'money') : (r.notas && r.notas.ponto_equilibrio ? r.notas.ponto_equilibrio : '—'),
            gcRentLucroOper: fmtVal(r.lucro_operacional, 'money'),
            gcFidTaxaRecompra: f.taxa_recompra != null ? fmtVal(f.taxa_recompra, 'percent') : '—',
            gcFidFreqMedia: fmtVal(f.frequencia_media_compra, 'float'),
            gcFidBaseAtiva90: fmtVal(f.base_ativa_90_dias, 'int'),
            gcFidNps: f.nps != null ? fmtVal(f.nps, 'float') : (f.notas && f.notas.nps_csat ? f.notas.nps_csat : '—'),
            gcFidCsat: f.csat != null ? fmtVal(f.csat, 'float') : '—',
        };
        Object.keys(map).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });
    }

    function renderRdMetricas(rd, errorMsg) {
        const block = document.getElementById('gcBlockRd');
        if (!block) return;
        if (!rd && !errorMsg) {
            block.style.display = 'none';
            return;
        }
        block.style.display = 'block';
        if (errorMsg) {
            setRdEl('gcRdReceitaTotal', '—');
            setRdEl('gcRdTotalGanhos', '—');
            setRdEl('gcRdTotalPerdidos', '—');
            setRdEl('gcRdConversao', '—');
            setRdEl('gcRdOportunidades', '—');
            setRdEl('gcRdUpdatedAt', errorMsg);
            document.getElementById('gcRdFunilWrap').style.display = 'none';
            return;
        }
        setRdEl('gcRdReceitaTotal', formatMoney(rd.receita_total));
        setRdEl('gcRdTotalGanhos', (rd.total_ganhos ?? 0).toLocaleString('pt-BR'));
        setRdEl('gcRdTotalPerdidos', (rd.total_perdidos ?? 0).toLocaleString('pt-BR'));
        setRdEl('gcRdConversao', rd.conversao_geral_pct != null ? formatPercent(rd.conversao_geral_pct) : '—');
        setRdEl('gcRdOportunidades', (rd.oportunidades_abertas ?? 0).toLocaleString('pt-BR'));
        setRdEl('gcRdUpdatedAt', rd.updated_at || '—');
        const funilWrap = document.getElementById('gcRdFunilWrap');
        const funilList = document.getElementById('gcRdFunilList');
        if (funilList && Array.isArray(rd.funil_estagios) && rd.funil_estagios.length > 0) {
            funilWrap.style.display = 'block';
            funilList.innerHTML = rd.funil_estagios.map(function (e) {
                return '<div class="gc-rd-funil-item"><span class="gc-rd-funil-stage">' + escapeHtml(e.stage_name || '') + '</span> <span class="gc-rd-funil-pipe">' + escapeHtml(e.pipeline_name || '') + '</span> <strong>' + Number(e.quantidade || 0).toLocaleString('pt-BR') + '</strong></div>';
            }).join('');
        } else {
            funilWrap.style.display = 'none';
        }
        function setRdEl(id, text) {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        }
    }

    function forceLogoutRedirect() {
        localStorage.clear();
        window.location.href = 'index.html';
    }

    function validateLocalAccess() {
        if (!localStorage.getItem('loggedIn')) return false;
        return String(localStorage.getItem('userType') || '').toLowerCase() === 'admin';
    }

    async function enforceAdminAccess() {
        if (!validateLocalAccess()) {
            forceLogoutRedirect();
            return null;
        }
        try {
            const session = await fetchSession();
            if (!session || !session.logged_in || String(session.tipo || '').toLowerCase() !== 'admin') {
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
