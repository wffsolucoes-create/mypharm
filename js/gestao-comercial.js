(function () {
    // Tudo da gestão comercial usa api_gestao.php
    const API_URL = 'api_gestao.php';

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

    /** Mesma chave do painel admin: preferência de menu recolhido compartilhada entre páginas. */
    var SIDEBAR_COLLAPSED_KEY = 'mypharm_sidebar_collapsed';

    function gcHideSidebarFlyoutTooltip() {
        var tip = document.getElementById('gcSidebarFlyoutTooltip');
        if (tip) {
            tip.classList.remove('is-visible');
            tip.textContent = '';
            tip.style.left = '';
            tip.style.top = '';
        }
    }

    function gcEnsureSidebarFlyoutTooltipEl() {
        var tip = document.getElementById('gcSidebarFlyoutTooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'gcSidebarFlyoutTooltip';
            tip.className = 'sidebar-flyout-tooltip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        return tip;
    }

    function gcShowSidebarFlyoutTooltip(text, anchorEl) {
        if (!text || !anchorEl) {
            gcHideSidebarFlyoutTooltip();
            return;
        }
        var tip = gcEnsureSidebarFlyoutTooltipEl();
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

    function gcSyncSidebarToggleUi() {
        var sidebar = document.getElementById('gcSidebar');
        var toggle = document.getElementById('gcSidebarToggle');
        if (!sidebar || !toggle) return;
        var icon = toggle.querySelector('i');
        var collapsed = sidebar.classList.contains('collapsed');
        if (icon) icon.className = collapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
        toggle.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu');
    }

    function gcInitSidebarCollapsedPreference() {
        var sidebar = document.getElementById('gcSidebar');
        var toggle = document.getElementById('gcSidebarToggle');
        if (!sidebar || !toggle) return;
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('collapsed');
            gcSyncSidebarToggleUi();
            return;
        }
        try {
            var v = localStorage.getItem(SIDEBAR_COLLAPSED_KEY);
            if (v === '0') sidebar.classList.remove('collapsed');
            else sidebar.classList.add('collapsed');
        } catch (e) {
            sidebar.classList.add('collapsed');
        }
        if (!sidebar.classList.contains('collapsed')) gcHideSidebarFlyoutTooltip();
        gcSyncSidebarToggleUi();
    }

    function gcInitSidebarCollapsedLegends() {
        var sidebar = document.getElementById('gcSidebar');
        if (!sidebar || sidebar.dataset.collapsedLegendsBound === '1') return;
        sidebar.dataset.collapsedLegendsBound = '1';

        sidebar.querySelectorAll('.nav-item').forEach(function (a) {
            var span = a.querySelector('span:not(.gc-sr-only)');
            var label = span ? span.textContent.replace(/\s+/g, ' ').trim() : '';
            if (label && !a.getAttribute('title')) a.setAttribute('title', label);
        });

        document.addEventListener(
            'mouseover',
            function (e) {
                var sb = document.getElementById('gcSidebar');
                if (!sb || !sb.classList.contains('collapsed')) {
                    gcHideSidebarFlyoutTooltip();
                    return;
                }
                if (!e.target.closest('#gcSidebar')) {
                    gcHideSidebarFlyoutTooltip();
                    return;
                }
                var brand = e.target.closest('.sidebar-header .sidebar-brand');
                if (brand && sb.contains(brand)) {
                    gcShowSidebarFlyoutTooltip('MyPharm', brand);
                    return;
                }
                var navEl = e.target.closest('#gcSidebar .nav-item');
                if (navEl && sb.contains(navEl)) {
                    var sp = navEl.querySelector('span:not(.gc-sr-only)');
                    var lbl = sp ? sp.textContent.replace(/\s+/g, ' ').trim() : '';
                    if (lbl) {
                        gcShowSidebarFlyoutTooltip(lbl, navEl);
                        return;
                    }
                }
                var userRow = e.target.closest('#gcSidebar .sidebar-footer .user-info');
                if (userRow && sb.contains(userRow)) {
                    var n = (document.getElementById('gcUserName') && document.getElementById('gcUserName').textContent.trim()) || '';
                    var rolEl = userRow.querySelector('.user-role');
                    var rol = rolEl ? rolEl.textContent.trim() : '';
                    var t = n && rol ? n + ' · ' + rol : n || rol;
                    if (t) {
                        gcShowSidebarFlyoutTooltip(t, userRow);
                        return;
                    }
                }
                gcHideSidebarFlyoutTooltip();
            },
            true
        );

        sidebar.addEventListener('focusin', function (e) {
            if (!sidebar.classList.contains('collapsed')) return;
            var a = e.target.closest('.nav-item');
            if (!a) return;
            var sp = a.querySelector('span:not(.gc-sr-only)');
            var label = sp ? sp.textContent.replace(/\s+/g, ' ').trim() : '';
            if (label) gcShowSidebarFlyoutTooltip(label, a);
        });
        sidebar.addEventListener('focusout', function () {
            setTimeout(function () {
                if (!sidebar.contains(document.activeElement)) gcHideSidebarFlyoutTooltip();
            }, 0);
        });
    }

    let gcSelectedVendedor = '__ALL__';
    let gcDashboardData = null;
    let gcNomesVendedores = [];
    let gcLoading = false;
    let gcApplyBtnHtml = null;
    const gcChartInstances = {};
    let gcBgChartsTimer = null;
    let gcDeferredChartsPending = false;
    let gcDeferredChartsData = null;
    let gcVendEquipeSortState = { key: 'receita', dir: 'desc' };
    let gcVendEquipeLastRows = null;
    let gcVendEquipeSortBound = false;
    let gcErroRowsMap = {};
    const GC_ERRO_TIPO_OUTRO = '__outro__';

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

    /** Converte "YYYY-MM" em rótulo curto (ex.: jan/25). */
    function gcFormatYmChartLabel(ym) {
        const parts = String(ym || '').split('-');
        if (parts.length < 2) return ym;
        const y = parts[0];
        const mi = parseInt(parts[1], 10);
        const months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        const m = months[mi - 1] || parts[1];
        return m + '/' + String(y).slice(-2);
    }

    function gcBuildVendEvoLineDatasets(evoBlock, col, emptyLineLabel) {
        var evo = evoBlock || { meses: [], series: [] };
        var evoMeses = Array.isArray(evo.meses) ? evo.meses : [];
        var evoSeries = Array.isArray(evo.series) ? evo.series : [];
        var evoLabels = evoMeses.length ? evoMeses.map(gcFormatYmChartLabel) : ['Sem dados'];
        var palLine = col.palette;
        var evoDatasets = [];
        var emptyLbl = emptyLineLabel || 'Sem dados';
        if (evoSeries.length && evoMeses.length) {
            evoSeries.forEach(function (s, idx) {
                var vals = Array.isArray(s.valores) ? s.valores.slice() : [];
                while (vals.length < evoMeses.length) vals.push(0);
                if (vals.length > evoMeses.length) vals = vals.slice(0, evoMeses.length);
                var c = palLine[idx % palLine.length];
                evoDatasets.push({
                    label: String(gcDisplayConsultoraName(s.atendente || '')).slice(0, 28),
                    data: vals,
                    borderColor: c,
                    backgroundColor: 'transparent',
                    tension: 0.25,
                    borderWidth: 2,
                    pointRadius: evoMeses.length <= 18 ? 4 : 0,
                    pointHoverRadius: 6,
                    pointBackgroundColor: c,
                    pointBorderColor: c,
                    spanGaps: true
                });
            });
        } else {
            evoDatasets.push({
                label: evoMeses.length ? emptyLbl : 'Sem dados',
                data: evoMeses.length ? evoMeses.map(function () { return 0; }) : [0],
                borderColor: col.muted,
                backgroundColor: 'transparent',
                tension: 0.2,
                borderWidth: 2,
                pointRadius: 4,
                pointBackgroundColor: col.muted,
                pointBorderColor: col.muted
            });
        }
        return { labels: evoLabels, datasets: evoDatasets };
    }

    var GC_VEND_EVO_CHART_KEYS = ['vendEvolucao', 'vendEvolucaoAprov', 'vendEvolucaoRej'];

    function gcVendEvoPrimeiroChartComSeries() {
        for (var k = 0; k < GC_VEND_EVO_CHART_KEYS.length; k++) {
            var ch = gcChartInstances[GC_VEND_EVO_CHART_KEYS[k]];
            if (ch && ch.data && ch.data.datasets && ch.data.datasets.length) return ch;
        }
        return null;
    }

    function gcVendEvoTodasSeriesVisiveis(chart) {
        if (!chart || !chart.data || !chart.data.datasets || !chart.data.datasets.length) return true;
        for (var i = 0; i < chart.data.datasets.length; i++) {
            if (typeof chart.isDatasetVisible === 'function' && !chart.isDatasetVisible(i)) return false;
        }
        return true;
    }

    function gcSyncVendEvoTodosButton() {
        var btn = document.getElementById('gcBtnVendEvoTodos');
        var hint = document.getElementById('gcVendEvoTodosHint');
        if (!btn) return;
        var ch = gcVendEvoPrimeiroChartComSeries();
        if (!ch) {
            btn.disabled = true;
            btn.setAttribute('aria-pressed', 'true');
            btn.textContent = 'Todos';
            btn.title = 'Aguarde os gráficos carregarem ou clique em Atualizar dados.';
            if (hint) {
                hint.innerHTML = '<strong>Todos</strong> ficará ativo após carregar o painel. Aplica aos três gráficos de evolução (receita, aprovados, rejeitados).';
            }
            return;
        }
        btn.disabled = false;
        btn.textContent = 'Todos';
        var todas = gcVendEvoTodasSeriesVisiveis(ch);
        btn.setAttribute('aria-pressed', todas ? 'true' : 'false');
        btn.title = todas
            ? 'Clique para ocultar todas as linhas nos três gráficos.'
            : 'Clique para mostrar todas as linhas nos três gráficos.';
        if (hint) {
            hint.innerHTML = todas
                ? 'Clique em <strong>Todos</strong> para <strong>ocultar todas</strong> as consultoras de uma vez nos três gráficos. Clique de novo para <strong>mostrar todas</strong>. Na legenda, você alterna uma por uma.'
                : 'Algumas linhas estão ocultas. Clique em <strong>Todos</strong> para <strong>mostrar todas</strong> de novo nos três gráficos.';
        }
    }

    function gcToggleVendEvoTodos() {
        var ch0 = gcVendEvoPrimeiroChartComSeries();
        if (!ch0) return;
        var mostrar = !gcVendEvoTodasSeriesVisiveis(ch0);
        GC_VEND_EVO_CHART_KEYS.forEach(function (key) {
            var ch = gcChartInstances[key];
            if (!ch || !ch.data || !ch.data.datasets) return;
            for (var i = 0; i < ch.data.datasets.length; i++) {
                if (typeof ch.setDatasetVisibility === 'function') {
                    ch.setDatasetVisibility(i, mostrar);
                } else {
                    var meta = ch.getDatasetMeta(i);
                    if (meta) meta.hidden = !mostrar;
                }
            }
            ch.update();
        });
        gcSyncVendEvoTodosButton();
    }

    function gcVendEvolucaoLineChartOptions(col, commonAxis, tooltipLabelFn) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    onClick: function (e, legendItem, legend) {
                        var chart = legend.chart;
                        var idx = legendItem.datasetIndex != null ? legendItem.datasetIndex : legendItem.index;
                        if (idx === undefined || idx === null) return;
                        var vis = chart.isDatasetVisible(idx);
                        chart.setDatasetVisibility(idx, !vis);
                        chart.update();
                        gcSyncVendEvoTodosButton();
                    },
                    labels: {
                        color: col.muted,
                        boxWidth: 14,
                        padding: 14,
                        font: { size: 13 }
                    }
                },
                tooltip: {
                    bodyFont: { size: 13 },
                    titleFont: { size: 13 },
                    itemSort: function (a, b) {
                        var ya = a.parsed && typeof a.parsed.y === 'number' && !isNaN(a.parsed.y) ? a.parsed.y : 0;
                        var yb = b.parsed && typeof b.parsed.y === 'number' && !isNaN(b.parsed.y) ? b.parsed.y : 0;
                        if (yb !== ya) return yb - ya;
                        return (a.datasetIndex || 0) - (b.datasetIndex || 0);
                    },
                    callbacks: {
                        label: tooltipLabelFn
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: col.muted, maxRotation: 45, minRotation: 0, font: { size: 13 } },
                    grid: { color: col.grid }
                },
                y: Object.assign({}, commonAxis, {
                    beginAtZero: true,
                    ticks: {
                        color: col.muted,
                        font: { size: 12 },
                        callback: function (v) {
                            return Number(v).toLocaleString('pt-BR', { notation: 'compact', maximumFractionDigits: 1 });
                        }
                    }
                })
            }
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
        const theme = saved === 'dark' || saved === 'light' ? saved : 'light';
        document.documentElement.setAttribute('data-theme', theme);
        syncThemeToggleAria();
    }

    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme') || 'light';
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

    /** Minutos (número bruto da API) → texto legível (h, d). */
    function formatDurationMin(min) {
        const n = Number(min);
        if (!isFinite(n) || n < 0) return '—';
        if (n === 0) return '0 min';
        if (n < 1) return '< 1 min';
        if (n < 60) return Math.round(n).toLocaleString('pt-BR') + ' min';
        if (n < 1440) {
            const h = Math.floor(n / 60);
            const m = Math.round(n % 60);
            return m > 0 ? (h + ' h ' + m + ' min') : (h + ' h');
        }
        const d = Math.floor(n / 1440);
        const remMin = n - d * 1440;
        const remH = Math.floor(remMin / 60);
        const m = Math.round(remMin % 60);
        if (remH > 0 && m > 0) return d + ' d ' + remH + ' h ' + m + ' min';
        if (remH > 0) return d + ' d ' + remH + ' h';
        return d + ' d';
    }

    function gcTierConversao(pct) {
        const n = Number(pct);
        if (!isFinite(n)) return '';
        if (n >= 62) return 'gc-cell-good';
        if (n >= 48) return 'gc-cell-warn';
        return 'gc-cell-bad';
    }

    function gcTierPerda(pct) {
        const n = Number(pct);
        if (!isFinite(n)) return '';
        if (n <= 28) return 'gc-cell-good';
        if (n <= 45) return 'gc-cell-warn';
        return 'gc-cell-bad';
    }

    /** Menor tempo (orç. → aprovação) = melhor. Minutos na API. */
    function gcTierTempoMin(min) {
        const n = Number(min);
        if (!isFinite(n) || n <= 0) return '';
        if (n <= 8 * 60) return 'gc-cell-good';
        if (n <= 48 * 60) return 'gc-cell-warn';
        return 'gc-cell-bad';
    }

    function gcEquipeSortValue(row, key) {
        switch (key) {
            case 'atendente':
                return String(row.atendente || '');
            case 'receita':
                return Number(row.receita || 0);
            case 'ticket':
                return Number(row.ticket_medio != null ? row.ticket_medio : 0);
            case 'meta_mensal':
                return Number(row.meta_mensal != null ? row.meta_mensal : 0);
            case 'previsao':
                return Number(row.previsao_ganho != null ? row.previsao_ganho : 0);
            case 'pedidos':
                return Number(row.quantidade_somada != null ? row.quantidade_somada : (row.total_pedidos || 0));
            case 'aprovados':
                return Number(row.vendas_aprovadas || 0);
            case 'rejeitados':
                return Number(row.vendas_rejeitadas || 0);
            case 'conversao':
                return Number(row.conversao_individual != null ? row.conversao_individual : 0);
            case 'tempo':
                return Number(row.tempo_medio_espera_resposta || 0);
            case 'clientes':
                return Number(row.clientes_atendidos || 0);
            case 'perda':
                return Number(row.taxa_perda != null ? row.taxa_perda : 0);
            default:
                return 0;
        }
    }

    function gcSortEquipeRows(rows, key, dir) {
        const arr = rows.slice();
        const asc = dir === 'asc';
        arr.sort(function (a, b) {
            let cmp = 0;
            if (key === 'atendente') {
                cmp = String(a.atendente || '').localeCompare(String(b.atendente || ''), 'pt-BR', { sensitivity: 'base' });
            } else {
                const va = gcEquipeSortValue(a, key);
                const vb = gcEquipeSortValue(b, key);
                if (va < vb) cmp = -1;
                else if (va > vb) cmp = 1;
            }
            if (cmp !== 0) return asc ? cmp : -cmp;
            return String(a.atendente || '').localeCompare(String(b.atendente || ''), 'pt-BR', { sensitivity: 'base' });
        });
        return arr;
    }

    function gcUpdateVendEquipeSortHeaders() {
        const table = document.getElementById('gcVendEquipeTable');
        if (!table) return;
        table.querySelectorAll('thead th[data-sort]').forEach(function (th) {
            if (th.getAttribute('data-sort') === gcVendEquipeSortState.key) {
                th.setAttribute('aria-sort', gcVendEquipeSortState.dir === 'asc' ? 'ascending' : 'descending');
            } else {
                th.removeAttribute('aria-sort');
            }
        });
    }

    function gcRenderVendEquipeTbody(rows) {
        const equipeBody = document.getElementById('gcVendEquipeTbody');
        if (!equipeBody) return;
        gcVendEquipeLastRows = rows;
        if (!rows.length) {
            equipeBody.innerHTML = '<tr><td colspan="11">Sem dados no período selecionado.</td></tr>';
            gcUpdateVendEquipeSortHeaders();
            return;
        }
        const sorted = gcSortEquipeRows(rows, gcVendEquipeSortState.key, gcVendEquipeSortState.dir);
        equipeBody.innerHTML = sorted.map(function (r) {
            const convCls = gcTierConversao(r.conversao_individual);
            const perdaCls = gcTierPerda(r.taxa_perda);
            const tempoCls = gcTierTempoMin(r.tempo_medio_espera_resposta);
            const tempoMin = Number(r.tempo_medio_espera_resposta || 0);
            const nomeRaw = String(r.atendente || '-');
            const nomeFmt = gcDisplayConsultoraName(nomeRaw);
            const recVal = Number(r.receita || 0);
            const metaVal = Number(r.meta_mensal != null ? r.meta_mensal : 0);
            const pctMetaRow = Number(r.percentual_meta);
            const metaHit = Number.isFinite(pctMetaRow) ? pctMetaRow >= 100 : (metaVal > 0 && recVal >= metaVal);
            const rowMetaClass = metaHit ? 'gc-row-meta-hit' : '';
            const badgeMeta = metaHit
                ? ' <span class="gc-meta-badge" title="Meta atingida"><i class="fas fa-check" aria-hidden="true"></i> Meta</span>'
                : '';
            return `<tr class="${rowMetaClass}">
                    <td class="${metaHit ? 'gc-cell-meta-name' : ''}">${escapeHtml(nomeFmt)}${badgeMeta}</td>
                    <td class="gc-num">${formatMoney(r.receita || 0)}</td>
                    <td class="gc-num">${formatMoney(r.ticket_medio != null ? r.ticket_medio : 0)}</td>
                    <td class="gc-num ${metaHit ? 'gc-cell-meta-hit' : ''}">${formatMoney(r.meta_mensal != null ? r.meta_mensal : 0)}</td>
                    <td class="gc-num">${formatMoney(r.previsao_ganho != null ? r.previsao_ganho : 0)}</td>
                    <td class="gc-num">${Number(r.vendas_aprovadas || 0).toLocaleString('pt-BR')}</td>
                    <td class="gc-num">${Number(r.vendas_rejeitadas || 0).toLocaleString('pt-BR')}</td>
                    <td class="gc-num ${convCls}">${formatPercent(r.conversao_individual || 0)}</td>
                    <td class="gc-num ${tempoCls}" title="${escapeHtml(String(Math.round(tempoMin * 10) / 10) + ' min')}">${formatDurationMin(r.tempo_medio_espera_resposta || 0)}</td>
                    <td class="gc-num">${Number(r.clientes_atendidos || 0).toLocaleString('pt-BR')}</td>
                    <td class="gc-num ${perdaCls}">${formatPercent(r.taxa_perda || 0)}</td>
                </tr>`;
        }).join('');
        gcUpdateVendEquipeSortHeaders();
    }

    function gcEnsureVendEquipeSortBind() {
        if (gcVendEquipeSortBound) return;
        const table = document.getElementById('gcVendEquipeTable');
        if (!table) return;
        const thead = table.querySelector('thead');
        if (!thead) return;
        thead.addEventListener('click', function (ev) {
            const btn = ev.target.closest('.gc-th-sort');
            if (!btn) return;
            const th = btn.closest('th[data-sort]');
            if (!th || !table.contains(th)) return;
            const key = th.getAttribute('data-sort');
            if (!key) return;
            if (gcVendEquipeSortState.key === key) {
                gcVendEquipeSortState.dir = gcVendEquipeSortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                gcVendEquipeSortState.key = key;
                gcVendEquipeSortState.dir = key === 'atendente' ? 'asc' : 'desc';
            }
            if (gcVendEquipeLastRows && gcVendEquipeLastRows.length) {
                gcRenderVendEquipeTbody(gcVendEquipeLastRows);
            }
        });
        gcVendEquipeSortBound = true;
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

    function gcSyncErroTipoOutroVisibility() {
        const sel = document.getElementById('gcErroTipo');
        const outro = document.getElementById('gcErroTipoOutro');
        if (!outro || !sel) return;
        const show = sel.value === GC_ERRO_TIPO_OUTRO;
        outro.style.display = show ? '' : 'none';
        if (!show) outro.value = '';
    }

    function gcResolveErroTipoFromForm() {
        const sel = document.getElementById('gcErroTipo');
        const outro = document.getElementById('gcErroTipoOutro');
        if (!sel) return '';
        if (sel.value === GC_ERRO_TIPO_OUTRO) {
            return outro ? String(outro.value || '').trim() : '';
        }
        return String(sel.value || '').trim();
    }

    async function gcLoadErrosTiposDistintos() {
        const data = await apiGet('gestao_comercial_erros_tipos_distintos');
        const tipos = Array.isArray(data && data.tipos) ? data.tipos : [];
        const sel = document.getElementById('gcErroTipo');
        if (!sel) return;
        const prevSel = sel.value;
        const outroEl = document.getElementById('gcErroTipoOutro');
        const prevOutro = outroEl ? outroEl.value : '';
        let html = '<option value="">Selecione o tipo do erro</option>';
        tipos.forEach(function (t) {
            const s = String(t || '').trim();
            if (!s) return;
            html += '<option value="' + escapeHtml(s) + '">' + escapeHtml(s) + '</option>';
        });
        html += '<option value="' + GC_ERRO_TIPO_OUTRO + '">Outro tipo (especificar abaixo)</option>';
        sel.innerHTML = html;
        if (prevSel === GC_ERRO_TIPO_OUTRO) {
            sel.value = GC_ERRO_TIPO_OUTRO;
            if (outroEl) {
                outroEl.value = prevOutro;
                outroEl.style.display = '';
            }
        } else if (prevSel && tipos.indexOf(prevSel) >= 0) {
            sel.value = prevSel;
        } else {
            sel.value = '';
        }
        gcSyncErroTipoOutroVisibility();
    }

    function normalizeName(v) {
        return String(v || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    /** Chave de comparação estável: minúsculas, sem acento e com espaço normalizado. */
    function gcNameKey(v) {
        var s = normalizeName(v);
        if (!s) return '';
        try {
            s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (_) {
            // fallback silencioso para ambientes sem normalize.
        }
        return s;
    }

    /** Nome de exibição padronizado para consultoras no painel. */
    function gcDisplayConsultoraName(nome) {
        var raw = String(nome || '').trim();
        if (!raw) return raw;
        var map = {
            'ananda reis': 'Ananda Reis',
            'carla': 'Carla',
            'clara leticia': 'Clara Letícia',
            'giovanna': 'Giovanna',
            'jessica vitoria': 'Jéssica Vitória',
            'mariana': 'Mariana',
            'micaela': 'Micaela',
            'nailena': 'Nailena',
            'nereida': 'Nereida'
        };
        return map[gcNameKey(raw)] || raw;
    }

    /** Nome normalizado → bateu meta no período (receita ≥ meta ou % ≥ 100). */
    function gcBuildMetaHitMap(equipe) {
        const map = Object.create(null);
        (equipe || []).forEach(function (r) {
            const nome = String(r?.atendente || '').trim();
            if (!nome || nome === '(Sem atendente)') return;
            const key = gcNameKey(nome);
            let hit = false;
            const pct = Number(r.percentual_meta);
            if (Number.isFinite(pct)) {
                hit = pct >= 100;
            } else {
                const meta = Number(r.meta_mensal || 0);
                const rec = Number(r.receita || 0);
                hit = meta > 0 && rec >= meta;
            }
            map[key] = hit;
        });
        return map;
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

        const equipeTabs = data?.vendedor_gestao?.equipe || [];
        const metaHitMap = gcBuildMetaHitMap(equipeTabs);

        wrap.innerHTML = options.map(function (nome) {
            const label = nome === '__ALL__' ? 'Todas as consultoras' : gcDisplayConsultoraName(nome);
            const sel = nome === gcSelectedVendedor;
            const metaHit = nome !== '__ALL__' && !!metaHitMap[gcNameKey(nome)];
            const metaClass = metaHit ? ' gc-vendedor-tab--meta' : '';
            const metaHtml = metaHit
                ? '<span class="gc-vendedor-tab__meta" title="Meta atingida no período"><i class="fas fa-star" aria-hidden="true"></i></span>'
                : '';
            const ariaExtra = metaHit ? ' — meta atingida no período' : '';
            return `<button type="button" role="tab" class="gc-vendedor-tab${sel ? ' active' : ''}${metaClass}" aria-selected="${sel}" data-vendedor="${escapeHtml(nome)}" data-meta-hit="${metaHit ? '1' : '0'}" aria-label="${escapeHtml(label)}${ariaExtra}"><span class="gc-vendedor-tab__label">${escapeHtml(label)}</span>${metaHtml}</button>`;
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
        const equipe = gcSelectedVendedor === '__ALL__'
            ? equipeRaw
            : equipeRaw.filter(function (r) { return gcNameKey(r.atendente) === gcNameKey(gcSelectedVendedor); });

        let resumoView = resumo;
        if (gcSelectedVendedor !== '__ALL__') {
            let receita = 0;
            let conv = 0;
            let tempo = 0;
            let clientes = 0;
            let perda = 0;
            let metaSum = 0;
            let prevSum = 0;
            const total = equipe.length;
            equipe.forEach(function (r) {
                receita += Number(r.receita || 0);
                conv += Number(r.conversao_individual || 0);
                tempo += Number(r.tempo_medio_espera_resposta || 0);
                clientes += Number(r.clientes_atendidos || 0);
                perda += Number(r.taxa_perda || 0);
                metaSum += Number(r.meta_mensal || 0);
                prevSum += Number(r.previsao_ganho || 0);
            });
            resumoView = {
                consultoras_ativas: total,
                receita_equipe: receita,
                conversao_media: total ? (conv / total) : 0,
                tempo_medio_espera_min: total ? (tempo / total) : 0,
                clientes_atendidos: clientes,
                taxa_perda_media: total ? (perda / total) : 0,
                meta_mensal_equipe: metaSum,
                percentual_meta_equipe: metaSum > 0 ? (receita / metaSum) * 100 : null,
                previsao_ganho_equipe: prevSum,
                comissao_pct_grupo_equipe: resumo.comissao_pct_grupo_equipe
            };
        }

        const tempoMinRaw = Number(resumoView.tempo_medio_espera_min ?? 0);
        const pctMetaVal = resumoView.percentual_meta_equipe;
        const map = {
            gcVendConsultorasAtivas: (resumoView.consultoras_ativas ?? 0).toLocaleString('pt-BR'),
            gcVendReceitaEquipe: formatMoney(resumoView.receita_equipe ?? 0),
            gcVendConversaoMedia: formatPercent(resumoView.conversao_media ?? 0),
            gcVendTempoMedio: formatDurationMin(tempoMinRaw),
            gcVendClientesAtendidos: (resumoView.clientes_atendidos ?? 0).toLocaleString('pt-BR'),
            gcVendTaxaPerda: formatPercent(resumoView.taxa_perda_media ?? 0),
            gcVendMetaEquipe: formatMoney(resumoView.meta_mensal_equipe ?? 0),
            gcVendPctMeta: pctMetaVal != null && isFinite(Number(pctMetaVal)) ? formatPercent(pctMetaVal) : '—',
            gcVendPrevisaoGanho: formatMoney(resumoView.previsao_ganho_equipe ?? 0)
        };
        Object.keys(map).forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });
        const tempoEl = document.getElementById('gcVendTempoMedio');
        if (tempoEl) {
            tempoEl.setAttribute('title', isFinite(tempoMinRaw) ? (Math.round(tempoMinRaw * 10) / 10) + ' min (valor bruto)' : '');
        }

        const metaSub = document.getElementById('gcVendMetaSub');
        if (metaSub) {
            metaSub.textContent = gcSelectedVendedor === '__ALL__' ? 'Soma das metas cadastradas' : 'Meta cadastrada (usuário)';
        }
        const prevSub = document.getElementById('gcVendPrevisaoSub');
        if (prevSub) {
            const cg = resumoView.comissao_pct_grupo_equipe;
            const cgTxt = cg != null && isFinite(Number(cg)) ? Number(cg).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 2 }) + '% grupo' : '';
            prevSub.textContent = gcSelectedVendedor === '__ALL__'
                ? ('Comissão ind. + grupo (estimada)' + (cgTxt ? ' · ' + cgTxt : ''))
                : ('Comissão ind. + grupo sobre a receita' + (cgTxt ? ' · ' + cgTxt : ''));
        }

        gcEnsureVendEquipeSortBind();
        gcRenderVendEquipeTbody(equipe);

        setTimeout(function () {
            renderVendedorTabCharts(data);
        }, 0);
    }

    function gcRenderRelatorioSemanal(rows) {
        const body = document.getElementById('gcRelSemanalTbody');
        if (!body) return;
        if (!Array.isArray(rows) || !rows.length) {
            body.innerHTML = '<tr><td colspan="11">Sem dados no período selecionado.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            const pct = Number(r.percentual_atingido || 0);
            const pctCls = pct >= 100 ? 'gc-cell-good' : (pct >= 80 ? 'gc-cell-warn' : 'gc-cell-bad');
            return `<tr>
                <td>${escapeHtml(gcDisplayConsultoraName(r.vendedora || ''))}</td>
                <td class="gc-num">${formatMoney(r.meta_individual || 0)}</td>
                <td class="gc-num">${formatMoney(r.venda_semana_1 || 0)}</td>
                <td class="gc-num">${formatMoney(r.venda_semana_2 || 0)}</td>
                <td class="gc-num">${formatMoney(r.venda_semana_3 || 0)}</td>
                <td class="gc-num">${formatMoney(r.venda_semana_4 || 0)}</td>
                <td class="gc-num">${formatMoney(r.total_vendido || 0)}</td>
                <td class="gc-num ${pctCls}">${formatPercent(pct)}</td>
                <td class="gc-num">${formatMoney(r.falta_meta || 0)}</td>
                <td class="gc-num">${formatMoney(r.bonus_valor || 0)}</td>
                <td class="gc-num">${formatMoney(r.comissao_total || r.comissao_valor || 0)}</td>
            </tr>`;
        }).join('');
    }

    function gcRenderRelatorioMensal(rows) {
        const body = document.getElementById('gcRelMensalTbody');
        if (!body) return;
        if (!Array.isArray(rows) || !rows.length) {
            body.innerHTML = '<tr><td colspan="15">Sem dados no período selecionado.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            const score = Number(r.score_final || 0);
            const scoreCls = score >= 80 ? 'gc-cell-good' : (score >= 65 ? 'gc-cell-warn' : 'gc-cell-bad');
            return `<tr>
                <td>${escapeHtml(gcDisplayConsultoraName(r.vendedora || ''))}</td>
                <td class="gc-num">${formatMoney(r.meta_individual || 0)}</td>
                <td class="gc-num">${formatMoney(r.venda_total_mes || 0)}</td>
                <td class="gc-num">${formatPercent(r.percentual_meta || 0)}</td>
                <td class="gc-num">${Number(r.orcamentos || 0).toLocaleString('pt-BR')}</td>
                <td class="gc-num">${Number(r.pedidos || 0).toLocaleString('pt-BR')}</td>
                <td class="gc-num">${formatPercent(r.conversao || 0)}</td>
                <td class="gc-num">${Number(r.rejeitados_itens || 0).toLocaleString('pt-BR')}</td>
                <td class="gc-num">${Number(r.erros || 0).toLocaleString('pt-BR')}</td>
                <td class="gc-num">${Number(r.crm || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}</td>
                <td class="gc-num">${Number(r.pontos_faturamento || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td class="gc-num">${Number(r.pontos_conversao || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td class="gc-num">${Number(r.pontos_erros || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td class="gc-num ${scoreCls}">${Number(score).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td>${escapeHtml(String(r.status || '—'))}</td>
            </tr>`;
        }).join('');
    }

    function renderRelatorios(data) {
        const bloco = data?.relatorios_comerciais || {};
        const semanal = bloco?.semanal || {};
        const mensal = bloco?.mensal || {};
        const comissao = bloco?.comissao || {};
        const totais = semanal?.totais || {};

        const semMap = {
            gcRelSemTotS1: formatMoney(totais.semana_1 || 0),
            gcRelSemTotS2: formatMoney(totais.semana_2 || 0),
            gcRelSemTotS3: formatMoney(totais.semana_3 || 0),
            gcRelSemTotS4: formatMoney(totais.semana_4 || 0),
            gcRelSemTotGeral: formatMoney(totais.receita_total || 0),
        };
        Object.keys(semMap).forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.textContent = semMap[id];
        });

        gcRenderRelatorioSemanal(semanal?.linhas || []);
        gcRenderRelatorioMensal(mensal?.linhas || []);

        const indBody = document.getElementById('gcRelComissaoIndTbody');
        if (indBody) {
            const indRows = Array.isArray(comissao?.faixas_individuais) ? comissao.faixas_individuais : [];
            indBody.innerHTML = indRows.length
                ? indRows.map(function (r) {
                    return `<tr>
                        <td>${escapeHtml(String(r.meta_pct_faixa || ''))}</td>
                        <td>${escapeHtml(String(r.intervalo || ''))}</td>
                        <td class="gc-num">${Number(r.comissao_percentual || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%</td>
                    </tr>`;
                }).join('')
                : '<tr><td colspan="3">Sem regras cadastradas.</td></tr>';
        }

        const grpBody = document.getElementById('gcRelComissaoGrupoTbody');
        if (grpBody) {
            const grpRows = Array.isArray(comissao?.faixas_grupo) ? comissao.faixas_grupo : [];
            grpBody.innerHTML = grpRows.length
                ? grpRows.map(function (r) {
                    return `<tr>
                        <td>${escapeHtml(String(r.faixa_receita || ''))}</td>
                        <td class="gc-num">${Number(r.percentual || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%</td>
                    </tr>`;
                }).join('')
                : '<tr><td colspan="2">Sem regras cadastradas.</td></tr>';
        }

        const premBody = document.getElementById('gcRelComissaoPremioTbody');
        if (premBody) {
            const premRows = Array.isArray(comissao?.premios_score) ? comissao.premios_score : [];
            premBody.innerHTML = premRows.length
                ? premRows.map(function (r) {
                    return `<tr>
                        <td>${escapeHtml(String(r.regra || ''))}</td>
                        <td class="gc-num">${formatMoney(r.premio || 0)}</td>
                    </tr>`;
                }).join('')
                : '<tr><td colspan="2">Sem regras cadastradas.</td></tr>';
        }
    }

    function gcGetCurrentDateRangeParams() {
        const deEl = document.getElementById('gcDataDe');
        const ateEl = document.getElementById('gcDataAte');
        const out = {};
        if (deEl && deEl.value) out.data_de = deEl.value;
        if (ateEl && ateEl.value) out.data_ate = ateEl.value;
        return out;
    }

    function gcRenderErrosRows(rows) {
        const body = document.getElementById('gcErrosTbody');
        if (!body) return;
        gcErroRowsMap = {};
        if (!Array.isArray(rows) || !rows.length) {
            body.innerHTML = '<tr><td colspan="8">Sem erros registrados no período selecionado.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            const id = Number(r.id || 0);
            gcErroRowsMap[id] = r;
            const cls = String(r.classificacao_erro || 'leve');
            const clsTxt = cls === 'grave' ? 'Grave' : (cls === 'medio' ? 'Médio' : 'Leve');
            const pts = Number(r.pontos_descontados || 0);
            const ptsCls = pts >= 2 ? 'gc-cell-bad' : (pts > 0 ? 'gc-cell-warn' : 'gc-cell-good');
            return `<tr>
                <td>${escapeHtml(String(r.data_erro || '—'))}</td>
                <td>${escapeHtml(gcDisplayConsultoraName(r.vendedor_nome || ''))}</td>
                <td>${escapeHtml(String(r.tipo_erro || '—'))}</td>
                <td>${escapeHtml(clsTxt)}</td>
                <td class="gc-num ${ptsCls}">${pts.toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td>${escapeHtml(String(r.pedido_ref || '—'))}</td>
                <td>${escapeHtml(String(r.descricao || '—'))}</td>
                <td class="gc-num"><button type="button" class="gc-filter-btn" style="padding:6px 10px;" onclick="gcExcluirErroManual(${id})"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        }).join('');
    }

    function gcPopulateErroVendedores(data) {
        const nomes = Array.isArray(data?.lista_vendedores_nomes) ? data.lista_vendedores_nomes.slice() : [];
        const selCad = document.getElementById('gcErroVendedor');
        const selFiltro = document.getElementById('gcErroFiltroVendedor');
        const opts = nomes.map(function (n) {
            return `<option value="${escapeHtml(n)}">${escapeHtml(gcDisplayConsultoraName(n))}</option>`;
        }).join('');
        if (selCad) {
            const current = selCad.value || '';
            selCad.innerHTML = opts;
            if (current && nomes.includes(current)) selCad.value = current;
        }
        if (selFiltro) {
            const currentF = selFiltro.value || '';
            selFiltro.innerHTML = '<option value="">Todas</option>' + opts;
            if (currentF && nomes.includes(currentF)) selFiltro.value = currentF;
        }
    }

    async function gcLoadControleErros() {
        const filtroVendedor = (document.getElementById('gcErroFiltroVendedor') || {}).value || '';
        const filtroBusca = (document.getElementById('gcErroFiltroBusca') || {}).value || '';
        const params = Object.assign({}, gcGetCurrentDateRangeParams(), {
            vendedor: filtroVendedor,
            q: filtroBusca,
            limit: '300'
        });
        const data = await apiGet('gestao_comercial_erros_lista', params);
        if (!data || data.success === false) {
            gcRenderErrosRows([]);
            return;
        }
        const resumo = data.resumo || {};
        const totalEl = document.getElementById('gcErroResumoTotal');
        const pontosEl = document.getElementById('gcErroResumoPontos');
        if (totalEl) totalEl.textContent = Number(resumo.total_erros || 0).toLocaleString('pt-BR');
        if (pontosEl) pontosEl.textContent = Number(resumo.total_pontos || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
        gcRenderErrosRows(Array.isArray(data.rows) ? data.rows : []);
    }

    window.gcExcluirErroManual = async function (id) {
        const n = Number(id || 0);
        if (!n) return;
        if (!window.confirm('Deseja excluir este erro?')) return;
        const resp = await apiPost('gestao_comercial_erros_excluir', { id: n });
        if (!resp || resp.success === false) {
            alert((resp && resp.error) ? resp.error : 'Não foi possível excluir o erro.');
            return;
        }
        await gcLoadControleErros();
        await loadDashboardData(false).catch(function () {});
    };

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
            { id: 'gcVendEquipeTbody', cols: 11, emptyText: 'Sem dados no período selecionado.' },
            { id: 'gcRelSemanalTbody', cols: 10, emptyText: 'Sem dados no período selecionado.' },
            { id: 'gcRelMensalTbody', cols: 15, emptyText: 'Sem dados no período selecionado.' },
            { id: 'gcRelComissaoIndTbody', cols: 3, emptyText: 'Sem regras cadastradas.' },
            { id: 'gcRelComissaoGrupoTbody', cols: 2, emptyText: 'Sem regras cadastradas.' },
            { id: 'gcRelComissaoPremioTbody', cols: 2, emptyText: 'Sem regras cadastradas.' },
            { id: 'gcErrosTbody', cols: 8, emptyText: 'Sem erros registrados no período selecionado.' }
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

    async function loadDashboardData(forceRefresh = false) {
        setGcLoading(true);
        try {
            const deEl = document.getElementById('gcDataDe');
            const ateEl = document.getElementById('gcDataAte');
            const params = {};
            if (deEl?.value) params.data_de = deEl.value;
            if (ateEl?.value) params.data_ate = ateEl.value;
            if (forceRefresh) params.refresh_rd = '1';

            const data = await apiGet('gestao_comercial_dashboard', params);

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
            await gcLoadErrosTiposDistintos();
            gcNomesVendedores = Array.isArray(data.lista_vendedores_nomes) ? data.lista_vendedores_nomes : [];
            renderExecutivo(data);
            renderFinanceiroKpis(data);
            renderGcCharts(data);
            renderVendedoresTabs(data, gcNomesVendedores);
            renderVendedores(data);
            renderRelatorios(data);
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

        var quickMap = {
            gcEficQuickLeads: fmtVal(e.leads_recebidos, 'int'),
            gcEficQuickVendas: fmtVal(data?.funil_comercial?.vendas_fechadas, 'int'),
            gcEficQuickTempo: (e.tempo_medio_fechamento_horas != null && e.tempo_medio_fechamento_horas !== '')
                ? (Number(e.tempo_medio_fechamento_horas).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' h')
                : '—',
            gcFidelQuickLucro: fmtVal(r.lucro_operacional, 'money'),
            gcFidelQuickRecompra: f.taxa_recompra != null ? fmtVal(f.taxa_recompra, 'percent') : '—',
            gcFidelQuickBase: fmtVal(f.base_ativa_90_dias, 'int')
        };
        Object.keys(quickMap).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = quickMap[id];
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
        gcSyncVendEvoTodosButton();
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
        var fidelWrap = document.getElementById('gcExecFidelChartWrap');
        var fidelEmpty = document.getElementById('gcExecFidelEmpty');
        if (!segProd.length) {
            gcDestroyChartKeys(['execFidel']);
            if (fidelWrap) fidelWrap.style.display = 'none';
            if (fidelEmpty) fidelEmpty.style.display = 'block';
        } else {
            if (fidelWrap) fidelWrap.style.display = '';
            if (fidelEmpty) fidelEmpty.style.display = 'none';
            gcEnsureChart('execFidel', 'gcChartExecFidel', function () {
                return {
                    type: 'bar',
                    data: {
                        labels: segProd.map(function (x) { return String(x.nome || '').slice(0, 22); }),
                        datasets: [{
                            label: 'Margem %',
                            data: segProd.map(function (x) { return Number(x.margem_percentual || 0); }),
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
        }

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
            ? equipeRaw.slice()
            : equipeRaw.filter(function (r) { return gcNameKey(r.atendente) === gcNameKey(gcSelectedVendedor); });
        // Chart.js (barras horiz., indexAxis 'y'): o 1º rótulo do array aparece no TOPO do eixo Y.
        // Receita decrescente => maior no topo (leitura de cima para baixo: maior → menor).
        equipe.sort(function (a, b) {
            var recA = Number(a.receita || 0);
            var recB = Number(b.receita || 0);
            if (recB !== recA) return recB - recA;
            var volA = Number(a.vendas_aprovadas || 0) + Number(a.vendas_rejeitadas || 0);
            var volB = Number(b.vendas_aprovadas || 0) + Number(b.vendas_rejeitadas || 0);
            if (volB !== volA) return volB - volA;
            return String(gcDisplayConsultoraName(a.atendente || '')).localeCompare(String(gcDisplayConsultoraName(b.atendente || '')), 'pt-BR');
        });

        gcEnsureChart('vendReceita', 'gcChartVendReceita', function () {
            return {
                type: 'bar',
                data: {
                    labels: equipe.length ? equipe.map(function (x) { return String(gcDisplayConsultoraName(x.atendente || '')).slice(0, 18); }) : ['Sem dados'],
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
                        y: {
                            ticks: { color: col.muted, font: { size: 10 } },
                            grid: { display: false }
                        }
                    }
                }
            };
        });

        gcEnsureChart('vendStack', 'gcChartVendAprovRej', function () {
            return {
                type: 'bar',
                data: {
                    labels: equipe.length ? equipe.map(function (x) { return String(gcDisplayConsultoraName(x.atendente || '')).slice(0, 16); }) : ['Sem dados'],
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
                        y: {
                            stacked: true,
                            ticks: { color: col.muted, font: { size: 9 } },
                            grid: { display: false }
                        }
                    }
                }
            };
        });

        var evo = bloco.evolucao_mensal_vendedores || { meses: [], series: [] };
        var evoSub = document.getElementById('gcVendEvolucaoSub');
        if (evoSub) {
            var yFix = typeof evo.ano_fixo === 'number' ? evo.ano_fixo : parseInt(evo.ano_fixo, 10);
            if (!yFix || isNaN(yFix)) yFix = 2026;
            evoSub.innerHTML = 'Receita aprovada por mês e por consultora (todas), <strong>sempre o ano ' + yFix + '</strong> — não acompanha o filtro de datas do painel.';
        }
        var builtEvo = gcBuildVendEvoLineDatasets(evo, col, 'Sem receita no período');
        gcEnsureChart('vendEvolucao', 'gcChartVendEvolucao', function () {
            return {
                type: 'line',
                data: { labels: builtEvo.labels, datasets: builtEvo.datasets },
                options: gcVendEvolucaoLineChartOptions(col, commonAxis, function (ctx) {
                    var lab = ctx.dataset.label ? ctx.dataset.label + ': ' : '';
                    return lab + formatMoney(ctx.parsed.y);
                })
            };
        });

        var evoA = bloco.evolucao_mensal_aprovados || { meses: [], series: [] };
        var evoASub = document.getElementById('gcVendEvolucaoAprovSub');
        if (evoASub) {
            var yA = typeof evoA.ano_fixo === 'number' ? evoA.ano_fixo : parseInt(evoA.ano_fixo, 10);
            if (!yA || isNaN(yA)) yA = 2026;
            evoASub.innerHTML = 'Pedidos aprovados em gestão por mês e por consultora, <strong>ano ' + yA + '</strong> (fixo) — não usa o filtro do painel.';
        }
        var builtA = gcBuildVendEvoLineDatasets(evoA, col, 'Sem aprovados no período');
        gcEnsureChart('vendEvolucaoAprov', 'gcChartVendEvolucaoAprov', function () {
            return {
                type: 'line',
                data: { labels: builtA.labels, datasets: builtA.datasets },
                options: gcVendEvolucaoLineChartOptions(col, commonAxis, function (ctx) {
                    var lab = ctx.dataset.label ? ctx.dataset.label + ': ' : '';
                    var n = ctx.parsed.y;
                    return lab + (Number.isFinite(n) ? Number(n).toLocaleString('pt-BR') : '—') + ' pedidos';
                })
            };
        });

        var evoR = bloco.evolucao_mensal_rejeitados || { meses: [], series: [] };
        var evoRSub = document.getElementById('gcVendEvolucaoRejSub');
        if (evoRSub) {
            var yR = typeof evoR.ano_fixo === 'number' ? evoR.ano_fixo : parseInt(evoR.ano_fixo, 10);
            if (!yR || isNaN(yR)) yR = 2026;
            evoRSub.innerHTML = 'Pedidos rejeitados em gestão por mês e por consultora, <strong>ano ' + yR + '</strong> (fixo) — não usa o filtro do painel.';
        }
        var builtR = gcBuildVendEvoLineDatasets(evoR, col, 'Sem rejeitados no período');
        gcEnsureChart('vendEvolucaoRej', 'gcChartVendEvolucaoRej', function () {
            return {
                type: 'line',
                data: { labels: builtR.labels, datasets: builtR.datasets },
                options: gcVendEvolucaoLineChartOptions(col, commonAxis, function (ctx) {
                    var lab = ctx.dataset.label ? ctx.dataset.label + ': ' : '';
                    var n = ctx.parsed.y;
                    return lab + (Number.isFinite(n) ? Number(n).toLocaleString('pt-BR') : '—') + ' pedidos';
                })
            };
        });
        gcSyncVendEvoTodosButton();
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

    function forceLogoutRedirect() {
        clearLocalStoragePreservingMyPharmTheme();
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
                if (target === 'erros') {
                    window.location.href = 'controle-erros.html';
                    return;
                }
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
                if (target === 'vendedores') {
                    requestAnimationFrame(function () {
                        if (gcDashboardData) renderVendedorTabCharts(gcDashboardData);
                    });
                }
            });
        });
    }

    function applyInitialTabFromUrl() {
        const params = new URLSearchParams(window.location.search);
        let tab = (params.get('tab') || '').toLowerCase().trim();
        if (!tab && window.location.hash) {
            tab = String(window.location.hash.replace(/^#/, '')).toLowerCase().trim();
        }
        const valid = ['executivo', 'vendas', 'vendedores', 'relatorios', 'erros', 'financeiro', 'reuniao'];
        if (valid.indexOf(tab) === -1 || tab === 'vendedores') return;
        const btn = document.querySelector('.gc-menu-item[data-section="' + tab + '"]');
        if (btn) btn.click();
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

        const erroData = document.getElementById('gcErroData');
        if (erroData && !erroData.value) {
            erroData.value = new Date().toISOString().slice(0, 10);
        }
        const gcErroTipoSel = document.getElementById('gcErroTipo');
        if (gcErroTipoSel) {
            gcErroTipoSel.addEventListener('change', gcSyncErroTipoOutroVisibility);
        }
        const erroSalvarBtn = document.getElementById('gcErroSalvarBtn');
        if (erroSalvarBtn) {
            erroSalvarBtn.addEventListener('click', async function () {
                const vendedor = (document.getElementById('gcErroVendedor') || {}).value || '';
                const dataErro = (document.getElementById('gcErroData') || {}).value || '';
                const tipoErro = gcResolveErroTipoFromForm();
                const classif = (document.getElementById('gcErroClassificacao') || {}).value || 'leve';
                const pedidoRef = (document.getElementById('gcErroPedidoRef') || {}).value || '';
                const descricao = (document.getElementById('gcErroDescricao') || {}).value || '';
                if (!vendedor || !tipoErro.trim()) {
                    alert('Preencha a consultora e o tipo do erro.');
                    return;
                }
                const payload = {
                    vendedor_nome: vendedor,
                    data_erro: dataErro,
                    tipo_erro: tipoErro,
                    classificacao_erro: classif,
                    pedido_ref: pedidoRef,
                    descricao: descricao
                };
                const resp = await apiPost('gestao_comercial_erros_salvar', payload);
                if (!resp || resp.success === false) {
                    alert((resp && resp.error) ? resp.error : 'Não foi possível salvar o erro.');
                    return;
                }
                const tipoEl = document.getElementById('gcErroTipo');
                const tipoOutroEl = document.getElementById('gcErroTipoOutro');
                const pedidoEl = document.getElementById('gcErroPedidoRef');
                const descEl = document.getElementById('gcErroDescricao');
                if (tipoEl) tipoEl.value = '';
                if (tipoOutroEl) tipoOutroEl.value = '';
                gcSyncErroTipoOutroVisibility();
                if (pedidoEl) pedidoEl.value = '';
                if (descEl) descEl.value = '';
                await gcLoadErrosTiposDistintos();
                await gcLoadControleErros();
                await loadDashboardData(false).catch(function () {});
            });
        }

        const erroAtualizarBtn = document.getElementById('gcErroAtualizarBtn');
        if (erroAtualizarBtn) {
            erroAtualizarBtn.addEventListener('click', function () {
                gcLoadControleErros().catch(function () {});
            });
        }
        const erroFiltroVendedor = document.getElementById('gcErroFiltroVendedor');
        if (erroFiltroVendedor) {
            erroFiltroVendedor.addEventListener('change', function () {
                gcLoadControleErros().catch(function () {});
            });
        }
        const erroFiltroBusca = document.getElementById('gcErroFiltroBusca');
        if (erroFiltroBusca) {
            erroFiltroBusca.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    gcLoadControleErros().catch(function () {});
                }
            });
        }

        const gcSbToggle = document.getElementById('gcSidebarToggle');
        const gcSidebar = document.getElementById('gcSidebar');
        if (gcSbToggle && gcSidebar) {
            gcSbToggle.addEventListener('click', function () {
                if (window.innerWidth <= 768) return;
                gcSidebar.classList.toggle('collapsed');
                if (!gcSidebar.classList.contains('collapsed')) gcHideSidebarFlyoutTooltip();
                gcSyncSidebarToggleUi();
                try {
                    localStorage.setItem(SIDEBAR_COLLAPSED_KEY, gcSidebar.classList.contains('collapsed') ? '1' : '0');
                } catch (e) { /* ignore */ }
            });
        }

        const gcBtnVendEvoTodos = document.getElementById('gcBtnVendEvoTodos');
        if (gcBtnVendEvoTodos) {
            gcBtnVendEvoTodos.addEventListener('click', function () {
                gcToggleVendEvoTodos();
            });
        }

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
        gcInitSidebarCollapsedPreference();
        gcInitSidebarCollapsedLegends();
        bindUi(session);
        loadDashboardData(false).catch(function () {}).then(function () {
            applyInitialTabFromUrl();
        });
    });
})();
