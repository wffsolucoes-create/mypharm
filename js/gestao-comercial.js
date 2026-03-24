(function () {
    // Tudo da gestão comercial usa api_gestao.php
    const API_URL = 'api_gestao.php';
    let gcSelectedVendedor = '__ALL__';
    let gcDashboardData = null;
    let gcNomesVendedores = [];
    let gcLoading = false;
    let gcApplyBtnHtml = null;
    const gcChartInstances = {};
    let gcBgChartsTimer = null;
    let gcDeferredChartsPending = false;
    let gcDeferredChartsData = null;

    function gcDestroyAllCharts() {
        Object.keys(gcChartInstances).forEach(function (key) {
            const ch = gcChartInstances[key];
            if (ch && typeof ch.destroy === 'function') {
                try { ch.destroy(); } catch (e) { /* ignore */ }
            }
            gcChartInstances[key] = null;
        });
    }

    function gcChartColors() {
        const root = getComputedStyle(document.documentElement);
        const text = (root.getPropertyValue('--text-primary') || '#0f172a').trim();
        const grid = (root.getPropertyValue('--border') || '#e2e8f0').trim();
        const muted = (root.getPropertyValue('--text-secondary') || '#64748b').trim();
        return {
            text,
            grid,
            muted,
            primary: '#2563EB',
            success: '#10b981',
            danger: '#ef4444',
            warning: '#f59e0b',
            purple: '#8b5cf6',
            cyan: '#06b6d4',
            palette: ['#2563EB', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#14b8a6']
        };
    }

    function gcEnsureChart(name, canvasId, configFactory) {
        if (typeof Chart === 'undefined') return null;
        const canvas = document.getElementById(canvasId);
        if (!canvas || !canvas.getContext) return null;
        if (gcChartInstances[name]) {
            try { gcChartInstances[name].destroy(); } catch (e) { /* ignore */ }
            gcChartInstances[name] = null;
        }
        try {
            const cfg = configFactory();
            if (!cfg) return null;
            gcChartInstances[name] = new Chart(canvas.getContext('2d'), cfg);
            return gcChartInstances[name];
        } catch (e) {
            console.warn('Gestão Comercial chart', name, e);
            return null;
        }
    }

    function getThemeStorageKey() {
        const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
        return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
    }

    function getSavedThemeForCurrentUser() {
        const userKey = getThemeStorageKey();
        return localStorage.getItem(userKey) || localStorage.getItem('mypharm_theme');
    }

    function syncThemeToggleAria() {
        const btn = document.getElementById('gcThemeBtn');
        if (!btn) return;
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        btn.setAttribute('title', isDark ? 'Usar tema claro' : 'Usar tema escuro');
    }

    function loadSavedTheme() {
        const saved = getSavedThemeForCurrentUser();
        if (saved) document.documentElement.setAttribute('data-theme', saved);
        syncThemeToggleAria();
    }

    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem(getThemeStorageKey(), newTheme);
        localStorage.setItem('mypharm_theme', newTheme);
        syncThemeToggleAria();
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
            const label = nome === '__ALL__' ? 'Todas as consultoras' : nome;
            const sel = nome === gcSelectedVendedor;
            return `<button type="button" role="tab" class="gc-vendedor-tab${sel ? ' active' : ''}" aria-selected="${sel}" data-vendedor="${escapeHtml(nome)}">${escapeHtml(label)}</button>`;
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

        setTimeout(function () {
            renderVendedorTabCharts(data);
        }, 0);
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
                ? '<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Atualizando…'
                : gcApplyBtnHtml;
        }

        document.querySelectorAll('.gc-metric-value').forEach(function (el) {
            if (!el.dataset.gcDefaultValue) {
                el.dataset.gcDefaultValue = (el.textContent || '—').trim() || '—';
            }
            if (gcLoading) {
                el.textContent = '…';
                el.classList.add('is-loading');
            } else {
                el.classList.remove('is-loading');
                if ((el.textContent || '').trim() === '…') {
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

    function loadRdMetricasDeferred(dataDe, dataAte) {
        (async function () {
            try {
                const q = {};
                if (dataDe) q.data_de = dataDe;
                if (dataAte) q.data_ate = dataAte;
                const res = await apiGet('gestao_rd_metricas', q);
                if (!gcDashboardData) return;
                if (res && res.success) {
                    const rd = Object.assign({}, res);
                    delete rd.success;
                    gcDashboardData.rd_metricas = rd;
                    gcDashboardData.rd_metricas_error = null;
                    renderRdMetricas(rd, null);
                } else {
                    const err = (res && res.error) ? res.error : 'Métricas RD indisponíveis.';
                    gcDashboardData.rd_metricas = null;
                    gcDashboardData.rd_metricas_error = err;
                    renderRdMetricas(null, err);
                }
            } catch (e) {
                if (!gcDashboardData) return;
                const err = 'Falha ao carregar métricas RD.';
                gcDashboardData.rd_metricas = null;
                gcDashboardData.rd_metricas_error = err;
                renderRdMetricas(null, err);
            }
        })();
    }

    async function loadDashboardData(forceRefresh = false) {
        setGcLoading(true);
        try {
            const deEl = document.getElementById('gcDataDe');
            const ateEl = document.getElementById('gcDataAte');
            const params = {};
            if (deEl?.value) params.data_de = deEl.value;
            if (ateEl?.value) params.data_ate = ateEl.value;
            if (forceRefresh) params.refresh_rd = '1';

            const data = await apiGet('gestao_comercial_dashboard_rd', params);

            var dashboardOk = data && data.success === true && data.crescimento && typeof data.crescimento === 'object';
            if (!dashboardOk) {
                var msg = (data && data.error) ? data.error : '';
                if (!msg) {
                    if (!data || (typeof data === 'object' && Object.keys(data).length === 0)) {
                        msg = 'Resposta vazia da API. Abra o DevTools (F12) → aba Network → gestao_comercial_dashboard_rd e veja o corpo da resposta.';
                    } else {
                        msg = 'O painel não retornou dados válidos (success ou bloco crescimento ausente). Confirme login como administrador e tente Aplicar de novo.';
                    }
                }
                showGcDashboardError(msg);
                return;
            }
            clearGcDashboardError();

            gcDashboardData = data;
            gcNomesVendedores = Array.isArray(data.lista_vendedores_nomes) ? data.lista_vendedores_nomes : [];
            renderExecutivo(data);
            renderFinanceiroKpis(data);
            renderGcCharts(data);
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
            gcEficConversao: e.conversao_geral != null ? fmtVal(e.conversao_geral, 'percent') : '—',
            gcRentMargemBruta: r.margem_bruta != null ? fmtVal(r.margem_bruta, 'percent') : '—',
            gcEficLeads: 'Leads recebidos: ' + fmtVal(e.leads_recebidos, 'int'),
            gcEficTempoFechamento: e.tempo_medio_fechamento_horas != null && e.tempo_medio_fechamento_horas !== ''
                ? ('Tempo médio de fechamento: ' + Number(e.tempo_medio_fechamento_horas).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' h')
                : 'Tempo médio de fechamento: —',
            gcEficTaxaPerda: e.taxa_perda_cliente != null ? ('Taxa de perda do cliente: ' + fmtVal(e.taxa_perda_cliente, 'percent')) : 'Taxa de perda do cliente: —',
            gcEficLtv: e.ltv != null ? ('LTV: ' + fmtVal(e.ltv, 'money')) : ('LTV: —'),
            gcEficCac: e.cac != null ? ('CAC: ' + fmtVal(e.cac, 'money')) : ('CAC: ' + (e.notas && e.notas.cac ? e.notas.cac : '—')),
            gcEficLtvCac: e.ltv_cac != null ? ('LTV/CAC: ' + fmtVal(e.ltv_cac, 'float')) : ('LTV/CAC: ' + (e.notas && e.notas.ltv_cac ? e.notas.ltv_cac : '—')),
            gcRentMargemContrib: r.margem_contribuicao != null ? ('Margem de contribuição: ' + fmtVal(r.margem_contribuicao, 'percent')) : 'Margem de contribuição: —',
            gcRentCmv: r.cmv_sobre_faturamento != null ? ('CMV s/ faturamento: ' + fmtVal(r.cmv_sobre_faturamento, 'percent')) : 'CMV s/ faturamento: —',
            gcRentPontoEquilibrio: r.ponto_equilibrio != null ? ('Ponto de equilíbrio: ' + fmtVal(r.ponto_equilibrio, 'money')) : ('Ponto de equilíbrio: ' + (r.notas && r.notas.ponto_equilibrio ? r.notas.ponto_equilibrio : '—')),
            gcRentLucroOper: 'Lucro operacional: ' + fmtVal(r.lucro_operacional, 'money'),
            gcFidTaxaRecompra: f.taxa_recompra != null ? ('Taxa de recompra: ' + fmtVal(f.taxa_recompra, 'percent')) : 'Taxa de recompra: —',
            gcFidFreqMedia: 'Frequência média de compra: ' + fmtVal(f.frequencia_media_compra, 'float'),
            gcFidBaseAtiva90: 'Base ativa (90 dias): ' + fmtVal(f.base_ativa_90_dias, 'int'),
            gcFidNps: f.nps != null ? ('NPS: ' + fmtVal(f.nps, 'float')) : ('NPS: ' + (f.notas && f.notas.nps_csat ? f.notas.nps_csat : '—')),
            gcFidCsat: f.csat != null ? ('CSAT: ' + fmtVal(f.csat, 'float')) : 'CSAT: —',
        };
        Object.keys(map).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });
    }

    function renderFinanceiroKpis(data) {
        var fin = data?.financeiro_aplicado || {};
        var rec = fin.receita || {};
        var cus = fin.custos || {};
        var rent = fin.rentabilidade || {};
        var cx = fin.caixa || {};
        var map = {
            gcFinFatBruto: formatMoney(rec.faturamento_bruto ?? 0),
            gcFinFatLiq: formatMoney(rec.faturamento_liquido ?? 0),
            gcFinCmv: formatMoney(cus.cmv ?? 0),
            gcFinCustoVenda: cus.custo_variavel_por_venda != null ? formatMoney(cus.custo_variavel_por_venda) : '—',
            gcFinCustoAtend: cus.custo_por_atendimento != null ? formatMoney(cus.custo_por_atendimento) : '—',
            gcFinMargem: rent.margem_bruta != null ? formatPercent(rent.margem_bruta) : '—',
            gcFinLucro: formatMoney(rent.lucro_operacional ?? 0),
        };
        Object.keys(map).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });
        var notasEl = document.getElementById('gcFinNotas');
        if (notasEl) {
            var n = fin.notas && fin.notas.campos_nulos ? fin.notas.campos_nulos : '';
            notasEl.textContent = n || 'Indicadores dependentes de marketing/financeiro podem aparecer como —.';
        }
        var l1 = document.getElementById('gcFinCaixaLinha1');
        var l2 = document.getElementById('gcFinCaixaLinha2');
        var l3 = document.getElementById('gcFinCaixaLinha3');
        if (l1) l1.textContent = 'Prazo médio de recebimento: ' + (cx.prazo_medio_recebimento != null ? cx.prazo_medio_recebimento : '—');
        if (l2) l2.textContent = 'Índice de inadimplência: ' + (cx.indice_inadimplencia != null ? cx.indice_inadimplencia : '—');
        if (l3) {
            l3.textContent = 'Taxa de antecipação: ' + (cx.taxa_antecipacao != null ? cx.taxa_antecipacao : '—')
                + ' · Fluxo projetado (3 meses): ' + (cx.fluxo_projetado_3_meses != null ? cx.fluxo_projetado_3_meses : '—');
        }
    }

    function gargaloFunilLabel(key) {
        var m = {
            atendimento_para_orcamento: 'Atendimento → Orçamento',
            orcamento_para_venda: 'Orçamento → Venda',
            venda_para_recompra: 'Venda → Recompra'
        };
        return m[key] || (key || '—');
    }

    function scheduleGcBackgroundCharts(data) {
        gcDeferredChartsPending = true;
        gcDeferredChartsData = data || null;
    }

    function ensureGcBackgroundCharts() {
        if (!gcDashboardData || typeof Chart === 'undefined') return;
        if (!gcDeferredChartsPending) return;
        if (gcBgChartsTimer) {
            clearTimeout(gcBgChartsTimer);
            gcBgChartsTimer = null;
        }
        gcDeferredChartsPending = false;
        renderGcChartsBackground(gcDeferredChartsData || gcDashboardData);
        gcDeferredChartsData = null;
    }

    function renderGcCharts(data) {
        if (gcBgChartsTimer) {
            clearTimeout(gcBgChartsTimer);
            gcBgChartsTimer = null;
        }
        gcDeferredChartsPending = false;
        gcDestroyAllCharts();
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js não carregado; gráficos da Gestão Comercial desativados.');
            return;
        }
        if (typeof Chart !== 'undefined' && Chart.defaults) {
            Chart.defaults.animation = false;
            Chart.defaults.transitions = { active: { animation: { duration: 0 } } };
        }
        var col = gcChartColors();
        var commonAxis = {
            ticks: { color: col.muted },
            grid: { color: col.grid }
        };

        /* —— Executivo: receita / CMV / lucro —— */
        var c = data?.crescimento || {};
        var r = data?.rentabilidade || {};
        var e = data?.eficiencia_comercial || {};
        var f = data?.fidelizacao || {};
        var fun = data?.funil_comercial || {};
        var fin = data?.financeiro_aplicado || {};
        var receita = Number(c.receita_mes || 0);
        var cmv = Number(fin.custos?.cmv || 0);
        var lucro = Number(r.lucro_operacional || 0);
        gcEnsureChart('execFinance', 'gcChartExecFinance', function () {
            return {
                type: 'bar',
                data: {
                    labels: ['Receita', 'CMV', 'Lucro operacional'],
                    datasets: [{
                        label: 'R$',
                        data: [receita, cmv, lucro],
                        backgroundColor: [col.primary, col.warning, col.success],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return formatMoney(ctx.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, { ticks: { color: col.muted } }),
                        y: Object.assign({}, commonAxis, {
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return Number(v).toLocaleString('pt-BR', { notation: 'compact' }); }
                            }
                        })
                    }
                }
            };
        });

        /* —— Percentuais —— */
        var pctLabels = [];
        var pctVals = [];
        function pushPct(label, val) {
            if (val == null || val === '') return;
            pctLabels.push(label);
            pctVals.push(Number(val));
        }
        pushPct('Δ mês anterior', c.crescimento_vs_mes_anterior);
        pushPct('Δ ano anterior', c.crescimento_vs_mes_ano_passado);
        pushPct('Conversão geral', e.conversao_geral);
        pushPct('Margem bruta', r.margem_bruta);
        pushPct('CMV / fat.', r.cmv_sobre_faturamento);
        pushPct('Taxa recompra', f.taxa_recompra);
        pushPct('Taxa perda cliente', e.taxa_perda_cliente);
        if (pctLabels.length === 0) {
            pctLabels = ['Sem dados'];
            pctVals = [0];
        }
        gcEnsureChart('execPct', 'gcChartExecPercent', function () {
            return {
                type: 'bar',
                data: {
                    labels: pctLabels,
                    datasets: [{
                        label: '%',
                        data: pctVals,
                        backgroundColor: pctLabels.map(function (_, i) { return col.palette[i % col.palette.length]; }),
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return Number(ctx.parsed.x).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return v + '%'; }
                            }
                        }),
                        y: { ticks: { color: col.muted }, grid: { display: false } }
                    }
                }
            };
        });

        /* —— Funil macro —— */
        gcEnsureChart('execFunil', 'gcChartExecFunil', function () {
            return {
                type: 'bar',
                data: {
                    labels: ['Atendimentos', 'Oportunidades', 'Vendas fechadas'],
                    datasets: [{
                        label: 'Volume',
                        data: [
                            Number(fun.volume_atendimentos || 0),
                            Number(fun.oportunidades_abertas || 0),
                            Number(fun.vendas_fechadas || 0)
                        ],
                        backgroundColor: [col.cyan, col.purple, col.success],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: col.muted }, grid: { display: false } },
                        y: Object.assign({}, commonAxis, { beginAtZero: true, ticks: { color: col.muted, precision: 0 } })
                    }
                }
            };
        });

        /* —— Canais (executivo) —— */
        var canais = Array.isArray(data?.performance_canal) ? data.performance_canal.slice(0, 8) : [];
        gcEnsureChart('execCanais', 'gcChartExecCanais', function () {
            return {
                type: 'bar',
                data: {
                    labels: canais.length ? canais.map(function (x) { return String(x.canal || '').slice(0, 28); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Receita',
                        data: canais.length ? canais.map(function (x) { return Number(x.receita || 0); }) : [0],
                        backgroundColor: col.primary,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) { return formatMoney(ctx.parsed.x); }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return Number(v).toLocaleString('pt-BR', { notation: 'compact' }); }
                            }
                        }),
                        y: { ticks: { color: col.muted, font: { size: 11 } }, grid: { display: false } }
                    }
                }
            };
        });

        /* —— Eficiência: leads, vendas funil, base 90d —— */
        gcEnsureChart('execEfic', 'gcChartExecEfic', function () {
            return {
                type: 'bar',
                data: {
                    labels: ['Leads', 'Vendas (funil)', 'Base ativa 90d'],
                    datasets: [{
                        data: [
                            Number(e.leads_recebidos || 0),
                            Number(fun.vendas_fechadas || 0),
                            Number(f.base_ativa_90_dias || 0)
                        ],
                        backgroundColor: [col.primary, col.success, col.warning],
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: col.muted }, grid: { display: false } },
                        y: Object.assign({}, commonAxis, { beginAtZero: true, ticks: { color: col.muted, precision: 0 } })
                    }
                }
            };
        });

        /* —— Margem por produto (top 6) —— */
        var segProd = (r.margem_contribuicao_por && r.margem_contribuicao_por.produto) ? r.margem_contribuicao_por.produto.slice(0, 6) : [];
        gcEnsureChart('execFidel', 'gcChartExecFidel', function () {
            return {
                type: 'bar',
                data: {
                    labels: segProd.length ? segProd.map(function (x) { return String(x.nome || '').slice(0, 22); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Margem %',
                        data: segProd.length ? segProd.map(function (x) { return Number(x.margem_percentual || 0); }) : [0],
                        backgroundColor: col.purple,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return Number(ctx.parsed.x).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            max: 100,
                            ticks: { color: col.muted, callback: function (v) { return v + '%'; } }
                        }),
                        y: { ticks: { color: col.muted, font: { size: 10 } }, grid: { display: false } }
                    }
                }
            };
        });

        scheduleGcBackgroundCharts(data);
    }

    function renderGcChartsBackground(data) {
        if (!data || typeof Chart === 'undefined') return;
        var col = gcChartColors();
        var commonAxis = {
            ticks: { color: col.muted },
            grid: { color: col.grid }
        };
        var c = data?.crescimento || {};
        var r = data?.rentabilidade || {};
        var fin = data?.financeiro_aplicado || {};
        var fun = data?.funil_comercial || {};
        var intel = data?.inteligencia_comercial || {};
        var canaisFull = Array.isArray(data?.performance_canal) ? data.performance_canal : [];
        var receita = Number(c.receita_mes || 0);
        var cmv = Number(fin.custos?.cmv || 0);
        var lucro = Number(r.lucro_operacional || 0);

        /* —— Vendas: macro funil + gargalo —— */
        var gargaloEl = document.getElementById('gcVendasGargalo');
        if (gargaloEl) {
            gargaloEl.textContent = 'Gargalo estimado (menor conversão entre etapas): ' + gargaloFunilLabel(fun.gargalo_funil);
        }
        gcEnsureChart('vendasMacro', 'gcChartVendasFunilMacro', function () {
            return {
                type: 'bar',
                data: {
                    labels: ['Atendimentos', 'Oportunidades', 'Vendas'],
                    datasets: [{
                        data: [
                            Number(fun.volume_atendimentos || 0),
                            Number(fun.oportunidades_abertas || 0),
                            Number(fun.vendas_fechadas || 0)
                        ],
                        backgroundColor: [col.cyan, col.purple, col.success],
                        borderRadius: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: col.muted } },
                        y: Object.assign({}, commonAxis, { beginAtZero: true, ticks: { color: col.muted, precision: 0 } })
                    }
                }
            };
        });

        var rawMap = fun.etapas_raw || {};
        var etapaKeys = Object.keys(rawMap);
        var etapaLabels = etapaKeys.map(function (k) { return k.length > 24 ? k.slice(0, 22) + '…' : k; });
        var etapaVals = etapaKeys.map(function (k) { return Number(rawMap[k] || 0); });
        if (!etapaKeys.length) {
            etapaLabels = ['Sem dados'];
            etapaVals = [0];
        }
        gcEnsureChart('vendasEtapas', 'gcChartVendasEtapas', function () {
            return {
                type: 'doughnut',
                data: {
                    labels: etapaLabels,
                    datasets: [{
                        data: etapaVals,
                        backgroundColor: etapaLabels.map(function (_, i) { return col.palette[i % col.palette.length]; }),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { color: col.muted, boxWidth: 12, font: { size: 10 } } }
                    }
                }
            };
        });

        var canaisFull = Array.isArray(data?.performance_canal) ? data.performance_canal : [];
        gcEnsureChart('vendasCanais', 'gcChartVendasCanais', function () {
            return {
                type: 'bar',
                data: {
                    labels: canaisFull.length ? canaisFull.map(function (x) { return String(x.canal || '').slice(0, 30); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Receita (aprovada)',
                        data: canaisFull.length ? canaisFull.map(function (x) { return Number(x.receita || 0); }) : [0],
                        backgroundColor: col.primary,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var i = ctx.dataIndex;
                                    var row = canaisFull[i];
                                    var marg = row && row.margem_percentual != null
                                        ? ' · Margem ' + Number(row.margem_percentual).toFixed(1) + '%'
                                        : '';
                                    return 'Receita: ' + formatMoney(ctx.parsed.x) + marg;
                                }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return Number(v).toLocaleString('pt-BR', { notation: 'compact' }); }
                            }
                        }),
                        y: { ticks: { color: col.muted, font: { size: 10 } }, grid: { display: false } }
                    }
                }
            };
        });

        var intel = data?.inteligencia_comercial || {};
        var topQ = Array.isArray(intel.top_formulas_mais_vendidas) ? intel.top_formulas_mais_vendidas.slice(0, 10) : [];
        gcEnsureChart('vendasTopQtd', 'gcChartVendasTopProdQtd', function () {
            return {
                type: 'bar',
                data: {
                    labels: topQ.length ? topQ.map(function (x) { return String(x.produto || '').slice(0, 26); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Qtd',
                        data: topQ.length ? topQ.map(function (x) { return Number(x.quantidade || 0); }) : [0],
                        backgroundColor: col.cyan,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: Object.assign({}, commonAxis, { beginAtZero: true, ticks: { color: col.muted, precision: 0 } }),
                        y: { ticks: { color: col.muted, font: { size: 9 } }, grid: { display: false } }
                    }
                }
            };
        });

        var topP = Array.isArray(intel.prescritores_maior_receita) ? intel.prescritores_maior_receita.slice(0, 10) : [];
        gcEnsureChart('vendasPresc', 'gcChartVendasTopPresc', function () {
            return {
                type: 'bar',
                data: {
                    labels: topP.length ? topP.map(function (x) { return String(x.prescritor || '').slice(0, 24); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Receita',
                        data: topP.length ? topP.map(function (x) { return Number(x.receita || 0); }) : [0],
                        backgroundColor: col.purple,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) { return formatMoney(ctx.parsed.x); }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return Number(v).toLocaleString('pt-BR', { notation: 'compact' }); }
                            }
                        }),
                        y: { ticks: { color: col.muted, font: { size: 9 } }, grid: { display: false } }
                    }
                }
            };
        });

        var margemProd = Array.isArray(intel.formulas_maior_margem) ? intel.formulas_maior_margem.slice(0, 10) : [];
        gcEnsureChart('vendasMargem', 'gcChartVendasMargemProd', function () {
            return {
                type: 'bar',
                data: {
                    labels: margemProd.length ? margemProd.map(function (x) { return String(x.produto || '').slice(0, 24); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Margem %',
                        data: margemProd.length ? margemProd.map(function (x) { return Number(x.margem_percentual || 0); }) : [0],
                        backgroundColor: col.success,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return Number(ctx.parsed.x).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            max: 100,
                            ticks: { color: col.muted, callback: function (v) { return v + '%'; } }
                        }),
                        y: { ticks: { color: col.muted, font: { size: 9 } }, grid: { display: false } }
                    }
                }
            };
        });

        var esp = Array.isArray(intel.ticket_medio_por_especialidade) ? intel.ticket_medio_por_especialidade.slice(0, 10) : [];
        gcEnsureChart('vendasEsp', 'gcChartVendasEspecialidade', function () {
            return {
                type: 'bar',
                data: {
                    labels: esp.length ? esp.map(function (x) { return String(x.especialidade || '').slice(0, 22); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Ticket médio',
                        data: esp.length ? esp.map(function (x) { return Number(x.ticket_medio || 0); }) : [0],
                        backgroundColor: col.warning,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) { return formatMoney(ctx.parsed.x); }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return Number(v).toLocaleString('pt-BR', { notation: 'compact' }); }
                            }
                        }),
                        y: { ticks: { color: col.muted, font: { size: 9 } }, grid: { display: false } }
                    }
                }
            };
        });

        /* —— Financeiro —— */
        gcEnsureChart('finResumo', 'gcChartFinResumo', function () {
            return {
                type: 'bar',
                data: {
                    labels: ['Faturamento', 'CMV', 'Lucro operacional'],
                    datasets: [{
                        data: [receita, cmv, lucro],
                        backgroundColor: [col.primary, col.danger, col.success],
                        borderRadius: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) { return formatMoney(ctx.parsed.y); }
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: col.muted } },
                        y: Object.assign({}, commonAxis, {
                            beginAtZero: true,
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return Number(v).toLocaleString('pt-BR', { notation: 'compact' }); }
                            }
                        })
                    }
                }
            };
        });
    }

    function renderVendedorTabCharts(data) {
        var col = gcChartColors();
        var commonAxis = {
            ticks: { color: col.muted },
            grid: { color: col.grid }
        };
        var bloco = data?.vendedor_gestao || {};
        var equipeRaw = bloco.equipe || [];
        var equipe = gcSelectedVendedor === '__ALL__'
            ? equipeRaw
            : equipeRaw.filter(function (r) { return normalizeName(r.atendente) === normalizeName(gcSelectedVendedor); });

        gcEnsureChart('vendReceita', 'gcChartVendReceita', function () {
            return {
                type: 'bar',
                data: {
                    labels: equipe.length ? equipe.map(function (x) { return String(x.atendente || '').slice(0, 18); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Receita',
                        data: equipe.length ? equipe.map(function (x) { return Number(x.receita || 0); }) : [0],
                        backgroundColor: col.primary,
                        borderRadius: 8
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) { return formatMoney(ctx.parsed.x); }
                            }
                        }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            ticks: {
                                color: col.muted,
                                callback: function (v) { return Number(v).toLocaleString('pt-BR', { notation: 'compact' }); }
                            }
                        }),
                        y: { ticks: { color: col.muted, font: { size: 10 } }, grid: { display: false } }
                    }
                }
            };
        });

        gcEnsureChart('vendStack', 'gcChartVendAprovRej', function () {
            return {
                type: 'bar',
                data: {
                    labels: equipe.length ? equipe.map(function (x) { return String(x.atendente || '').slice(0, 16); }) : ['Sem dados'],
                    datasets: [
                        {
                            label: 'Aprovados',
                            data: equipe.length ? equipe.map(function (x) { return Number(x.vendas_aprovadas || 0); }) : [0],
                            backgroundColor: col.success,
                            borderRadius: 6
                        },
                        {
                            label: 'Rejeitados',
                            data: equipe.length ? equipe.map(function (x) { return Number(x.vendas_rejeitadas || 0); }) : [0],
                            backgroundColor: col.danger,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: col.muted } }
                    },
                    scales: {
                        x: Object.assign({}, commonAxis, {
                            stacked: true,
                            beginAtZero: true,
                            ticks: { color: col.muted, precision: 0 }
                        }),
                        y: { stacked: true, ticks: { color: col.muted, font: { size: 9 } }, grid: { display: false } }
                    }
                }
            };
        });

        var motivosRaw = bloco.motivos_perda || [];
        var motivos = gcSelectedVendedor === '__ALL__'
            ? motivosRaw
            : motivosRaw.filter(function (m) { return normalizeName(m.atendente) === normalizeName(gcSelectedVendedor); });
        var motTop = motivos.slice(0, 12);
        gcEnsureChart('vendMotivos', 'gcChartVendMotivos', function () {
            return {
                type: 'bar',
                data: {
                    labels: motTop.length ? motTop.map(function (x) { return String(x.motivo || '').slice(0, 28); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Qtd',
                        data: motTop.length ? motTop.map(function (x) { return Number(x.quantidade || 0); }) : [0],
                        backgroundColor: col.danger,
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: Object.assign({}, commonAxis, { beginAtZero: true, ticks: { color: col.muted, precision: 0 } }),
                        y: { ticks: { color: col.muted, font: { size: 9 } }, grid: { display: false } }
                    }
                }
            };
        });
    }

    function renderRdCharts(rd, errorMsg) {
        gcDestroyChartKeys(['rdDeals', 'rdFunil', 'rdMotivos']);
        var motWrap = document.getElementById('gcRdMotivosWrap');
        if (typeof Chart === 'undefined') {
            if (motWrap) motWrap.style.display = 'none';
            return;
        }
        var col = gcChartColors();
        if (errorMsg || !rd) {
            if (motWrap) motWrap.style.display = 'none';
            return;
        }

        var g = Number(rd.total_ganhos || 0);
        var p = Number(rd.total_perdidos || 0);
        gcEnsureChart('rdDeals', 'gcChartRdDeals', function () {
            if (g === 0 && p === 0) {
                return {
                    type: 'doughnut',
                    data: { labels: ['Sem dados'], datasets: [{ data: [1], backgroundColor: [col.grid] }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
                };
            }
            return {
                type: 'doughnut',
                data: {
                    labels: ['Ganhos', 'Perdidos'],
                    datasets: [{
                        data: [g, p],
                        backgroundColor: [col.success, col.danger],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: col.muted, boxWidth: 12 } }
                    }
                }
            };
        });

        var estagios = (rd.kanban_snapshot && Array.isArray(rd.kanban_snapshot.etapas) && rd.kanban_snapshot.etapas.length)
            ? rd.kanban_snapshot.etapas
            : (Array.isArray(rd.funil_estagios) ? rd.funil_estagios : []);
        if (estagios.length > 0) {
            gcEnsureChart('rdFunil', 'gcChartRdFunil', function () {
                return {
                    type: 'bar',
                    data: {
                        labels: estagios.map(function (e) { return String(e.stage_name || '').slice(0, 20); }),
                        datasets: [{
                            label: 'Qtd',
                            data: estagios.map(function (e) { return Number(e.quantidade || 0); }),
                            backgroundColor: col.palette.map(function (_, i) { return col.palette[i % col.palette.length]; }),
                            borderRadius: 6
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { beginAtZero: true, ticks: { color: col.muted, precision: 0 }, grid: { color: col.grid } },
                            y: { ticks: { color: col.muted, font: { size: 9 } }, grid: { display: false } }
                        }
                    }
                };
            });
        }

        var motGeral = Array.isArray(rd.motivos_perda_geral) ? rd.motivos_perda_geral : [];
        var top3 = motGeral.filter(function (x) { return Number(x.quantidade || 0) > 0; }).slice(0, 3);
        if (motWrap) motWrap.style.display = top3.length ? 'block' : 'none';
        if (top3.length) {
            var bgMot = ['#64748b', '#cbd5e1', col.danger];
            var totalMot = top3.reduce(function (s, x) { return s + Number(x.quantidade || 0); }, 0);
            gcEnsureChart('rdMotivos', 'gcChartRdMotivos', function () {
                return {
                    type: 'doughnut',
                    data: {
                        labels: top3.map(function (x) { return String(x.motivo || '—').trim() || '—'; }),
                        datasets: [{
                            data: top3.map(function (x) { return Number(x.quantidade || 0); }),
                            backgroundColor: top3.map(function (_, i) { return bgMot[i % bgMot.length]; }),
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        rotation: 270,
                        circumference: 180,
                        cutout: '58%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: col.muted,
                                    boxWidth: 12,
                                    padding: 10,
                                    font: { size: 11 },
                                    generateLabels: function (chart) {
                                        var data = chart.data;
                                        var ds = data.datasets[0];
                                        if (!data.labels || !ds) return [];
                                        return data.labels.map(function (label, i) {
                                            var val = Number(ds.data[i] || 0);
                                            var pct = totalMot > 0 ? ((val / totalMot) * 100).toFixed(1) : '0';
                                            var t = String(label);
                                            if (t.length > 38) t = t.slice(0, 36) + '…';
                                            return {
                                                text: t + ' — ' + val.toLocaleString('pt-BR') + ' (' + pct + '%)',
                                                fillStyle: Array.isArray(ds.backgroundColor) ? ds.backgroundColor[i] : ds.backgroundColor,
                                                hidden: false,
                                                index: i
                                            };
                                        });
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var v = Number(ctx.raw || 0);
                                        var pct = totalMot > 0 ? ((v / totalMot) * 100).toFixed(1) : '0';
                                        return v.toLocaleString('pt-BR') + ' (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                };
            });
        }
    }

    function gcDestroyChartKeys(keys) {
        keys.forEach(function (k) {
            var ch = gcChartInstances[k];
            if (ch && typeof ch.destroy === 'function') {
                try { ch.destroy(); } catch (e) { /* ignore */ }
            }
            gcChartInstances[k] = null;
        });
    }

    function renderRdMetricas(rd, errorMsg) {
        const block = document.getElementById('gcBlockRd');
        if (!block) return;
        gcDestroyChartKeys(['rdDeals', 'rdFunil', 'rdMotivos']);
        if (!rd && !errorMsg) {
            block.style.display = 'none';
            var mw0 = document.getElementById('gcRdMotivosWrap');
            if (mw0) mw0.style.display = 'none';
            return;
        }
        block.style.display = 'block';
        const funilWrap = document.getElementById('gcRdFunilWrap');
        if (errorMsg) {
            setRdEl('gcRdReceitaTotal', '—');
            setRdEl('gcRdTotalGanhos', '—');
            setRdEl('gcRdTotalPerdidos', '—');
            setRdEl('gcRdConversao', '—');
            setRdEl('gcRdOportunidades', '—');
            setRdEl('gcRdUpdatedAt', errorMsg);
            var metaErr = document.getElementById('gcRdMetodologia');
            if (metaErr) {
                metaErr.textContent = '';
                metaErr.hidden = true;
            }
            if (funilWrap) funilWrap.style.display = 'none';
            var mwErr = document.getElementById('gcRdMotivosWrap');
            if (mwErr) mwErr.style.display = 'none';
            return;
        }
        var kanban = rd && rd.kanban_snapshot ? rd.kanban_snapshot : null;
        setRdEl('gcRdReceitaTotal', formatMoney(kanban && kanban.valor_ganho != null ? kanban.valor_ganho : rd.receita_total));
        setRdEl('gcRdTotalGanhos', ((kanban && kanban.ganhos != null ? kanban.ganhos : rd.total_ganhos) ?? 0).toLocaleString('pt-BR'));
        setRdEl('gcRdTotalPerdidos', ((kanban && kanban.perdidos != null ? kanban.perdidos : rd.total_perdidos) ?? 0).toLocaleString('pt-BR'));
        setRdEl('gcRdConversao', rd.conversao_geral_pct != null ? formatPercent(rd.conversao_geral_pct) : '—');
        setRdEl('gcRdOportunidades', ((kanban && kanban.abertos != null ? kanban.abertos : rd.oportunidades_abertas) ?? 0).toLocaleString('pt-BR'));
        setRdEl('gcRdUpdatedAt', rd.updated_at || '—');
        const metaEl = document.getElementById('gcRdMetodologia');
        if (metaEl) {
            var n = rd.notas || {};
            var linhas = [];
            if (n.totais) linhas.push(n.totais);
            if (n.divergencia_funil) linhas.push(n.divergencia_funil);
            if (linhas.length) {
                metaEl.textContent = linhas.join(' ');
                metaEl.hidden = false;
            } else {
                metaEl.textContent = '';
                metaEl.hidden = true;
            }
        }
        const funilList = document.getElementById('gcRdFunilList');
        var kanbanEtapas = kanban && Array.isArray(kanban.etapas) ? kanban.etapas : [];
        var funilEtapas = Array.isArray(rd.funil_estagios) ? rd.funil_estagios : [];
        var etapasLista = kanbanEtapas.length ? kanbanEtapas : funilEtapas;
        if (funilWrap && funilList && etapasLista.length > 0) {
            funilWrap.style.display = 'block';
            funilList.innerHTML = etapasLista.map(function (e) {
                var valorTxt = e.valor_total != null && Number(e.valor_total || 0) > 0
                    ? ' · ' + formatMoney(e.valor_total || 0)
                    : '';
                return '<div class="gc-rd-funil-item"><span class="gc-rd-funil-stage">' + escapeHtml(e.stage_name || '') + '</span> <span class="gc-rd-funil-pipe">' + escapeHtml(e.pipeline_name || '') + '</span> <strong>' + Number(e.quantidade || 0).toLocaleString('pt-BR') + valorTxt + '</strong></div>';
            }).join('');
        } else if (funilWrap) {
            funilWrap.style.display = 'none';
        }
        renderRdCharts(rd, null);
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
                tabs.forEach(function (t) {
                    t.classList.remove('active');
                    if (t.getAttribute('role') === 'tab') {
                        t.setAttribute('aria-selected', 'false');
                    }
                });
                sections.forEach(function (s) {
                    s.classList.remove('active');
                    s.setAttribute('aria-hidden', 'true');
                });
                tab.classList.add('active');
                if (tab.getAttribute('role') === 'tab') {
                    tab.setAttribute('aria-selected', 'true');
                }
                const section = document.getElementById(`gc-section-${target}`);
                if (section) {
                    section.classList.add('active');
                    section.setAttribute('aria-hidden', 'false');
                }
                if (target === 'vendas' || target === 'financeiro') {
                    requestAnimationFrame(function () {
                        ensureGcBackgroundCharts();
                    });
                }
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
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                toggleTheme();
                if (gcDashboardData && typeof Chart !== 'undefined') {
                    setTimeout(function () {
                        renderGcCharts(gcDashboardData);
                        renderRdMetricas(gcDashboardData.rd_metricas, gcDashboardData.rd_metricas_error);
                        renderVendedorTabCharts(gcDashboardData);
                    }, 50);
                }
            });
        }

        const applyBtn = document.getElementById('gcApplyFiltersBtn');
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                loadDashboardData(true).catch(function () {});
            });
        }

        ['gcDataDe', 'gcDataAte'].forEach(function (id) {
            const inp = document.getElementById(id);
            if (!inp) return;
            inp.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    loadDashboardData(true).catch(function () {});
                }
            });
        });

        bindTabs();
        syncThemeToggleAria();
    }

    document.addEventListener('DOMContentLoaded', async function () {
        loadSavedTheme();
        setDefaultFilters();
        const session = await enforceAdminAccess();
        if (!session) return;
        const app = document.getElementById('gcApp');
        if (app) app.style.display = 'flex';
        bindUi(session);
        loadDashboardData(false).catch(function () {});
    });
})();
