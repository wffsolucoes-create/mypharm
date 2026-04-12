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
    var GC_ACTIVE_TAB_KEY = 'gc_active_tab';

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
    const GC_PHP_API_URL = 'api.php';
    var __gcPedidosList = [];
    var __gcPedidosPage = 1;
    const __gcPedidosPageSize = 20;
    var __gcPedidosSort = { column: 'data_aprovacao', direction: 'desc' };
    var __gcPedidosExpanded = {};
    var __gcPedidosLastGroupedFiltered = [];
    var gcPedidosRelUiBound = false;
    let gcApplyBtnHtml = null;
    const gcChartInstances = {};
    /** Contexto dos gráficos comparativos de meses (toolbar + redesenho). */
    var gcComparativoVendasCtx = { cmp: null, col: null, commonAxis: null, padraoKeys: [] };
    var gcComparativoPedidosCtx = { cmp: null, col: null, commonAxis: null, padraoKeys: [] };
    let gcBgChartsTimer = null;
    let gcDeferredChartsPending = false;
    let gcDeferredChartsData = null;
    let gcVendEquipeSortState = { key: 'receita', dir: 'desc' };
    let gcVendEquipeLastRows = null;
    let gcVendEquipeSortBound = false;
    let gcRelSemSortState = { key: 'total_vendido', dir: 'desc' };
    let gcRelSemLastRows = null;
    let gcRelSemSortBound = false;
    let gcRelMensalLastRows = null;
    let gcRelMensalCrmBound = false;
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

    function gcOpenVendedorPage(vendedoraNome) {
        var nome = String(vendedoraNome || '').trim();
        var url = 'vendedor.html';
        if (nome) {
            url += '?vendedora=' + encodeURIComponent(nome);
        }
        window.location.href = url;
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

    function gcCompareMesesStorageKey(kind, ano) {
        return 'gc_' + kind + '_compare_meses_' + String(ano);
    }

    function gcLoadCompareMesesSelection(ano, padrao, allChavesSet, kind) {
        try {
            var raw = localStorage.getItem(gcCompareMesesStorageKey(kind, ano));
            if (!raw) return padrao.slice();
            var arr = JSON.parse(raw);
            if (!Array.isArray(arr)) return padrao.slice();
            var filtered = arr.filter(function (k) { return allChavesSet[k]; });
            if (!filtered.length) return padrao.slice();
            return filtered;
        } catch (e) {
            return padrao.slice();
        }
    }

    function gcSaveCompareMesesSelection(ano, keys, kind) {
        try {
            localStorage.setItem(gcCompareMesesStorageKey(kind, ano), JSON.stringify(keys));
        } catch (e) { /* ignore */ }
    }

    var GC_CMP_OPT_VENDAS = {
        kind: 'vendas',
        wrapId: 'gcCompareMesesChecks',
        chartKey: 'vendasMesMes',
        canvasId: 'gcChartVendasMesMes',
        subId: 'gcVendasMesMesSub',
        formatTooltip: function (lbl, y) {
            return (lbl || 'Série') + ': ' + formatMoney(y);
        },
        formatSubPair: function (a, b) {
            return formatMoney(a) + ' / ' + formatMoney(b);
        }
    };

    var GC_CMP_OPT_PEDIDOS = {
        kind: 'pedidos',
        wrapId: 'gcCompareMesesChecksPedidos',
        chartKey: 'pedidosMesMes',
        canvasId: 'gcChartPedidosMesMes',
        subId: 'gcPedidosMesMesSub',
        formatTooltip: function (lbl, y) {
            var n = Math.round(Number(y) || 0);
            return (lbl || 'Série') + ': ' + n.toLocaleString('pt-BR') + ' ped.';
        },
        formatSubPair: function (a, b) {
            return Math.round(Number(a) || 0).toLocaleString('pt-BR') + ' / ' + Math.round(Number(b) || 0).toLocaleString('pt-BR') + ' ped.';
        }
    };

    function gcRedrawComparativoMesesChart(ctxRef, opt) {
        var ctx = ctxRef;
        var cmp = ctx.cmp || { labels: [], series: [], meta: {} };
        var col = ctx.col || gcChartColors();
        var commonAxis = ctx.commonAxis || { ticks: { color: col.muted }, grid: { color: col.grid } };
        var wrap = document.getElementById(opt.wrapId);
        var padrao = (Array.isArray(ctx.padraoKeys) && ctx.padraoKeys.length)
            ? ctx.padraoKeys
            : ((cmp.meta && cmp.meta.meses_padrao_selecionados) || []);
        padrao = padrao.map(String);
        var selected = [];
        if (wrap) {
            wrap.querySelectorAll('input[type=checkbox]').forEach(function (el) {
                if (el.checked) {
                    var k = el.getAttribute('data-chave');
                    if (k) selected.push(k);
                }
            });
        }
        if (!selected.length) selected = padrao.slice();

        var labels = Array.isArray(cmp.labels) ? cmp.labels : [];
        var allSeries = Array.isArray(cmp.series) ? cmp.series : [];
        var selectedSet = {};
        selected.forEach(function (k) { selectedSet[k] = true; });

        var chaveToIdx = {};
        allSeries.forEach(function (s, i) {
            var ch = String(s.chave || '');
            if (ch) chaveToIdx[ch] = i;
        });

        var pal = (col.palette && col.palette.length) ? col.palette : ['#6366F1', '#10B981'];
        var datasets = [];
        allSeries.forEach(function (s) {
            var ch = String(s.chave != null && s.chave !== '' ? s.chave : '');
            if (!ch) return;
            if (!selectedSet[ch]) return;
            var idx = chaveToIdx[ch] != null ? chaveToIdx[ch] : 0;
            datasets.push({
                label: String(s.nome || ch).slice(0, 20),
                data: Array.isArray(s.valores) ? s.valores.map(function (v) { return Number(v || 0); }) : [],
                borderColor: pal[idx % pal.length],
                backgroundColor: 'transparent',
                tension: 0.25,
                pointRadius: 2.5,
                pointHoverRadius: 4.5,
                borderWidth: 3
            });
        });

        if (!labels.length || !datasets.length) {
            labels = ['01'];
            datasets = [{
                label: 'Comparação',
                data: [0],
                borderColor: col.primary,
                backgroundColor: 'transparent',
                tension: 0.25,
                pointRadius: 2.5,
                borderWidth: 3
            }];
        }

        var fmtTip = opt.formatTooltip || function (lbl, y) { return (lbl || '') + ': ' + String(y); };
        gcEnsureChart(opt.chartKey, opt.canvasId, function () {
            return {
                type: 'line',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: col.muted, boxWidth: 12, font: { size: 10 } } },
                        tooltip: {
                            callbacks: {
                                label: function (c) {
                                    return fmtTip(c.dataset.label, c.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: col.muted, maxRotation: 0, minRotation: 0 }, grid: { color: col.grid } },
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

    function gcSetupComparativoMesesBlock(cmp, col, commonAxis, ctxRef, opt) {
        ctxRef.cmp = cmp;
        ctxRef.col = col;
        ctxRef.commonAxis = commonAxis;

        var cmpMeta = cmp.meta || {};
        var ano = Number(cmpMeta.ano_referencia || new Date().getFullYear());
        var allSeries = Array.isArray(cmp.series) ? cmp.series : [];
        var allChavesSet = {};
        allSeries.forEach(function (s) {
            var ch = String(s.chave || '');
            if (ch) allChavesSet[ch] = true;
        });
        var padrao = Array.isArray(cmpMeta.meses_padrao_selecionados) ? cmpMeta.meses_padrao_selecionados.map(String) : [];
        if (!padrao.length) {
            var keysOrd = Object.keys(allChavesSet).sort();
            if (keysOrd.length >= 2) padrao = keysOrd.slice(-2);
            else if (keysOrd.length === 1) padrao = [keysOrd[0]];
        }
        ctxRef.padraoKeys = padrao.slice();

        var stored = gcLoadCompareMesesSelection(ano, padrao, allChavesSet, opt.kind);
        var wrap = document.getElementById(opt.wrapId);
        if (wrap) {
            wrap.innerHTML = '';
            allSeries.forEach(function (s) {
                var ch = String(s.chave || '');
                if (!ch) return;
                var lbl = document.createElement('label');
                lbl.className = 'gc-compare-mes-check';
                var inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.setAttribute('data-chave', ch);
                inp.checked = stored.indexOf(ch) !== -1;
                inp.addEventListener('change', function () {
                    var keys = [];
                    wrap.querySelectorAll('input[type=checkbox]').forEach(function (el) {
                        if (el.checked) keys.push(el.getAttribute('data-chave'));
                    });
                    var rePor = (ctxRef.padraoKeys && ctxRef.padraoKeys.length) ? ctxRef.padraoKeys : padrao;
                    if (!keys.length && rePor.length) {
                        rePor.forEach(function (pch) {
                            wrap.querySelectorAll('input[type=checkbox]').forEach(function (el) {
                                if (el.getAttribute('data-chave') === pch) el.checked = true;
                            });
                        });
                        keys = rePor.slice();
                    }
                    gcSaveCompareMesesSelection(ano, keys, opt.kind);
                    gcRedrawComparativoMesesChart(ctxRef, opt);
                });
                lbl.appendChild(inp);
                lbl.appendChild(document.createTextNode(' ' + String(s.nome || ch)));
                wrap.appendChild(lbl);
            });
        }

        var cmpSub = document.getElementById(opt.subId);
        if (cmpSub) {
            var tAnt = Number(cmpMeta.total_mes_anterior || 0);
            var tAtu = Number(cmpMeta.total_mes_atual || 0);
            var cres = cmpMeta.crescimento_percentual;
            var cresTxt = (cres == null || isNaN(Number(cres)))
                ? '—'
                : ((Number(cres) >= 0 ? '+' : '') + Number(cres).toFixed(2).replace('.', ',') + '%');
            var acum = cmpMeta.serie_acumulada === true || cmpMeta.serie_acumulada === 1 || cmpMeta.serie_acumulada === '1';
            var modo = acum ? 'Acumulado no mês até cada dia · ' : '';
            var pairFmt = opt.formatSubPair || function (a, b) { return String(a) + ' / ' + String(b); };
            cmpSub.textContent = modo + 'Ano ' + String(ano)
                + ' · Padrão: ' + String(cmpMeta.mes_anterior_label || '—') + ' vs ' + String(cmpMeta.mes_atual_label || '—')
                + ' (totais ' + pairFmt(tAnt, tAtu) + ', cresc. ' + cresTxt + ')'
                + ' · Marque os meses abaixo para o gráfico.';
        }

        gcRedrawComparativoMesesChart(ctxRef, opt);
    }

    function gcSetupComparativoVendasMesMes(cmp, col, commonAxis) {
        gcSetupComparativoMesesBlock(cmp, col, commonAxis, gcComparativoVendasCtx, GC_CMP_OPT_VENDAS);
    }

    function gcSetupComparativoPedidosMesMes(cmp, col, commonAxis) {
        gcSetupComparativoMesesBlock(cmp, col, commonAxis, gcComparativoPedidosCtx, GC_CMP_OPT_PEDIDOS);
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
            case 'falta_meta':
                return Math.max(0, Number(row.meta_mensal != null ? row.meta_mensal : 0) - Number(row.receita || 0));
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
            equipeBody.innerHTML = '<tr><td colspan="12">Sem dados no período selecionado.</td></tr>';
            gcUpdateVendEquipeSortHeaders();
            return;
        }
        const sorted = gcSortEquipeRows(rows, gcVendEquipeSortState.key, gcVendEquipeSortState.dir);
        const linhasHtml = sorted.map(function (r) {
            const convCls = gcTierConversao(r.conversao_individual);
            const perdaCls = gcTierPerda(r.taxa_perda);
            const tempoCls = gcTierTempoMin(r.tempo_medio_espera_resposta);
            const tempoMin = Number(r.tempo_medio_espera_resposta || 0);
            const nomeRaw = String(r.atendente || '-');
            const nomeFmt = gcDisplayConsultoraName(nomeRaw);
            const recVal = Number(r.receita || 0);
            const metaVal = Number(r.meta_mensal != null ? r.meta_mensal : 0);
            const faltaMeta = Math.max(0, metaVal - recVal);
            const faltaMetaCls = faltaMeta > 0 ? 'gc-cell-warn' : 'gc-cell-good';
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
                    <td class="gc-num ${faltaMetaCls}">${formatMoney(faltaMeta)}</td>
                    <td class="gc-num">${formatMoney(r.previsao_ganho != null ? r.previsao_ganho : 0)}</td>
                    <td class="gc-num">${Number(r.vendas_aprovadas || 0).toLocaleString('pt-BR')}</td>
                    <td class="gc-num">${Number(r.vendas_rejeitadas || 0).toLocaleString('pt-BR')}</td>
                    <td class="gc-num ${convCls}">${formatPercent(r.conversao_individual || 0)}</td>
                    <td class="gc-num ${tempoCls}" title="${escapeHtml(String(Math.round(tempoMin * 10) / 10) + ' min')}">${formatDurationMin(r.tempo_medio_espera_resposta || 0)}</td>
                    <td class="gc-num">${Number(r.clientes_atendidos || 0).toLocaleString('pt-BR')}</td>
                    <td class="gc-num ${perdaCls}">${formatPercent(r.taxa_perda || 0)}</td>
                </tr>`;
        }).join('');

        const total = rows.reduce(function (acc, r) {
            const receita = Number(r.receita || 0);
            const meta = Number(r.meta_mensal != null ? r.meta_mensal : 0);
            const aprov = Number(r.vendas_aprovadas || 0);
            const rej = Number(r.vendas_rejeitadas || 0);
            const tempo = Number(r.tempo_medio_espera_resposta || 0);
            const clientes = Number(r.clientes_atendidos || 0);
            acc.receita += receita;
            acc.meta += meta;
            acc.falta += Math.max(0, meta - receita);
            acc.previsao += Number(r.previsao_ganho != null ? r.previsao_ganho : 0);
            acc.aprov += aprov;
            acc.rej += rej;
            acc.tempoSum += tempo;
            acc.tempoCount += tempo > 0 ? 1 : 0;
            acc.clientes += clientes;
            return acc;
        }, { receita: 0, meta: 0, falta: 0, previsao: 0, aprov: 0, rej: 0, tempoSum: 0, tempoCount: 0, clientes: 0 });

        const denom = total.aprov + total.rej;
        const convTotal = denom > 0 ? (total.aprov / denom) * 100 : 0;
        const perdaTotal = denom > 0 ? (total.rej / denom) * 100 : 0;
        const ticketTotal = total.aprov > 0 ? (total.receita / total.aprov) : 0;
        const tempoMedioTotal = total.tempoCount > 0 ? (total.tempoSum / total.tempoCount) : 0;
        const totalRowHtml = `<tr class="gc-row-total-semanal">
            <td><strong>Total geral</strong></td>
            <td class="gc-num"><strong>${formatMoney(total.receita)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(ticketTotal)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(total.meta)}</strong></td>
            <td class="gc-num ${total.falta > 0 ? 'gc-cell-warn' : 'gc-cell-good'}"><strong>${formatMoney(total.falta)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(total.previsao)}</strong></td>
            <td class="gc-num"><strong>${Math.round(total.aprov).toLocaleString('pt-BR')}</strong></td>
            <td class="gc-num"><strong>${Math.round(total.rej).toLocaleString('pt-BR')}</strong></td>
            <td class="gc-num ${gcTierConversao(convTotal)}"><strong>${formatPercent(convTotal)}</strong></td>
            <td class="gc-num ${gcTierTempoMin(tempoMedioTotal)}"><strong>${formatDurationMin(tempoMedioTotal)}</strong></td>
            <td class="gc-num"><strong>${Math.round(total.clientes).toLocaleString('pt-BR')}</strong></td>
            <td class="gc-num ${gcTierPerda(perdaTotal)}"><strong>${formatPercent(perdaTotal)}</strong></td>
        </tr>`;

        equipeBody.innerHTML = linhasHtml + totalRowHtml;
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
        gcRelSemLastRows = rows;
        if (!Array.isArray(rows) || !rows.length) {
            body.innerHTML = '<tr><td colspan="11">Sem dados no período selecionado.</td></tr>';
            gcUpdateRelSemSortHeaders();
            return;
        }
        const sorted = gcSortRelSemRows(rows, gcRelSemSortState.key, gcRelSemSortState.dir);
        const linhasHtml = sorted.map(function (r) {
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

        const soma = rows.reduce(function (acc, r) {
            acc.meta += Number(r.meta_individual || 0);
            acc.s1 += Number(r.venda_semana_1 || 0);
            acc.s2 += Number(r.venda_semana_2 || 0);
            acc.s3 += Number(r.venda_semana_3 || 0);
            acc.s4 += Number(r.venda_semana_4 || 0);
            acc.total += Number(r.total_vendido || 0);
            acc.bonus += Number(r.bonus_valor || 0);
            acc.comissao += Number(r.comissao_total || r.comissao_valor || 0);
            return acc;
        }, { meta: 0, s1: 0, s2: 0, s3: 0, s4: 0, total: 0, bonus: 0, comissao: 0 });

        const faltaMetaTotal = Math.max(0, soma.meta - soma.total);
        const pctTotal = soma.meta > 0 ? (soma.total / soma.meta) * 100 : 0;
        const pctTotalCls = pctTotal >= 100 ? 'gc-cell-good' : (pctTotal >= 80 ? 'gc-cell-warn' : 'gc-cell-bad');
        const totalRowHtml = `<tr class="gc-row-total-semanal">
            <td><strong>Total geral</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.meta)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.s1)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.s2)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.s3)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.s4)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.total)}</strong></td>
            <td class="gc-num ${pctTotalCls}"><strong>${formatPercent(pctTotal)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(faltaMetaTotal)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.bonus)}</strong></td>
            <td class="gc-num"><strong>${formatMoney(soma.comissao)}</strong></td>
        </tr>`;

        body.innerHTML = linhasHtml + totalRowHtml;
        gcUpdateRelSemSortHeaders();
    }

    function gcRelSemSortValue(row, key) {
        switch (key) {
            case 'consultora':
                return String(row.vendedora || '');
            case 'meta':
                return Number(row.meta_individual || 0);
            case 's1':
                return Number(row.venda_semana_1 || 0);
            case 's2':
                return Number(row.venda_semana_2 || 0);
            case 's3':
                return Number(row.venda_semana_3 || 0);
            case 's4':
                return Number(row.venda_semana_4 || 0);
            case 'total_vendido':
                return Number(row.total_vendido || 0);
            case 'pct':
                return Number(row.percentual_atingido || 0);
            case 'falta':
                return Number(row.falta_meta || 0);
            case 'bonus':
                return Number(row.bonus_valor || 0);
            case 'comissao':
                return Number(row.comissao_total || row.comissao_valor || 0);
            default:
                return 0;
        }
    }

    function gcSortRelSemRows(rows, key, dir) {
        const arr = rows.slice();
        const asc = dir === 'asc';
        arr.sort(function (a, b) {
            let cmp = 0;
            if (key === 'consultora') {
                cmp = String(a.vendedora || '').localeCompare(String(b.vendedora || ''), 'pt-BR', { sensitivity: 'base' });
            } else {
                const va = gcRelSemSortValue(a, key);
                const vb = gcRelSemSortValue(b, key);
                if (va < vb) cmp = -1;
                else if (va > vb) cmp = 1;
            }
            if (cmp !== 0) return asc ? cmp : -cmp;
            return String(a.vendedora || '').localeCompare(String(b.vendedora || ''), 'pt-BR', { sensitivity: 'base' });
        });
        return arr;
    }

    function gcUpdateRelSemSortHeaders() {
        const table = document.getElementById('gcRelSemanalTable');
        if (!table) return;
        table.querySelectorAll('thead th[data-sort]').forEach(function (th) {
            if (th.getAttribute('data-sort') === gcRelSemSortState.key) {
                th.setAttribute('aria-sort', gcRelSemSortState.dir === 'asc' ? 'ascending' : 'descending');
            } else {
                th.removeAttribute('aria-sort');
            }
        });
    }

    function gcEnsureRelSemSortBind() {
        if (gcRelSemSortBound) return;
        const table = document.getElementById('gcRelSemanalTable');
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
            if (gcRelSemSortState.key === key) {
                gcRelSemSortState.dir = gcRelSemSortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                gcRelSemSortState.key = key;
                gcRelSemSortState.dir = key === 'consultora' ? 'asc' : 'desc';
            }
            if (gcRelSemLastRows && gcRelSemLastRows.length) {
                gcRenderRelatorioSemanal(gcRelSemLastRows);
            }
        });
        gcRelSemSortBound = true;
    }

    function gcRelMensalCrmStatus(score) {
        const s = Number(score || 0);
        if (s >= 80) return 'Muito Bom';
        if (s >= 65) return 'Bom';
        if (s >= 50) return 'Plano de Ação';
        return 'Crítico';
    }

    function gcRelMensalCrmStorageKey() {
        const p = (gcDashboardData && gcDashboardData.periodo) || {};
        const de = String(p.data_de || 'sem-data-de');
        const ate = String(p.data_ate || 'sem-data-ate');
        return 'gc_rel_mensal_crm_' + de + '_' + ate;
    }

    function gcLoadRelMensalCrmMap() {
        try {
            const raw = localStorage.getItem(gcRelMensalCrmStorageKey());
            const parsed = raw ? JSON.parse(raw) : {};
            if (!parsed || typeof parsed !== 'object') return {};
            const out = {};
            Object.keys(parsed).forEach(function (k) {
                const v = Number(parsed[k]);
                if (isFinite(v)) out[k] = v;
            });
            return out;
        } catch (e) {
            return {};
        }
    }

    function gcSaveRelMensalCrmMap(map) {
        try {
            localStorage.setItem(gcRelMensalCrmStorageKey(), JSON.stringify(map || {}));
        } catch (e) { /* ignore */ }
    }

    function gcEnsureRelMensalCrmBind() {
        if (gcRelMensalCrmBound) return;
        const body = document.getElementById('gcRelMensalTbody');
        if (!body) return;

        function applyInput(evTarget) {
            const input = evTarget && evTarget.closest ? evTarget.closest('.gc-rel-crm-input') : null;
            if (!input) return;
            const key = String(input.getAttribute('data-crm-key') || '');
            if (!key) return;
            const v = Math.max(0, Number(input.value || 0));
            if (!isFinite(v)) return;
            const map = gcLoadRelMensalCrmMap();
            map[key] = v;
            gcSaveRelMensalCrmMap(map);
            if (gcRelMensalLastRows && gcRelMensalLastRows.length) {
                gcRenderRelatorioMensal(gcRelMensalLastRows);
            }
        }

        body.addEventListener('change', function (ev) {
            applyInput(ev.target);
        });
        body.addEventListener('blur', function (ev) {
            applyInput(ev.target);
        }, true);
        body.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            const input = ev.target && ev.target.closest ? ev.target.closest('.gc-rel-crm-input') : null;
            if (!input) return;
            ev.preventDefault();
            input.blur();
        });

        gcRelMensalCrmBound = true;
    }

    function gcRenderRelatorioMensal(rows) {
        const body = document.getElementById('gcRelMensalTbody');
        if (!body) return;
        gcRelMensalLastRows = rows;
        if (!Array.isArray(rows) || !rows.length) {
            body.innerHTML = '<tr><td colspan="16">Sem dados no período selecionado.</td></tr>';
            return;
        }
        const crmMap = gcLoadRelMensalCrmMap();
        body.innerHTML = rows.map(function (r) {
            const rowKey = gcNameKey(r.vendedora || '');
            const crmOriginal = Number(r.crm || 0);
            const crmEdit = Object.prototype.hasOwnProperty.call(crmMap, rowKey) ? Number(crmMap[rowKey]) : crmOriginal;
            const crmVal = isFinite(crmEdit) ? Math.max(0, crmEdit) : Math.max(0, crmOriginal);
            const pFat = Number(r.pontos_faturamento || 0);
            const pConv = Number(r.pontos_conversao || 0);
            const pErros = Number(r.pontos_erros || 0);
            const pctMeta = Number(r.percentual_meta || 0);
            const convPct = Number(r.conversao || 0);
            const pedidos = Number(r.pedidos || 0);
            const rejeitados = Number(r.rejeitados_itens || 0);
            const erros = Number(r.erros || 0);
            const denRej = pedidos + rejeitados;
            const taxaRej = denRej > 0 ? (rejeitados / denRej) * 100 : 0;
            const totalPts = pFat + pConv + pErros + crmVal;
            const scoreBase = Number(r.score_final || 0) - crmOriginal;
            const score = scoreBase + crmVal;
            const pctMetaCls = pctMeta >= 100 ? 'gc-cell-good' : (pctMeta >= 80 ? 'gc-cell-warn' : 'gc-cell-bad');
            const convCls = convPct >= 40 ? 'gc-cell-good' : (convPct >= 25 ? 'gc-cell-warn' : 'gc-cell-bad');
            const rejCls = taxaRej <= 20 ? 'gc-cell-good' : (taxaRej <= 35 ? 'gc-cell-warn' : 'gc-cell-bad');
            const errosCls = erros <= 0 ? 'gc-cell-good' : (erros <= 2 ? 'gc-cell-warn' : 'gc-cell-bad');
            const crmCls = crmVal >= 5 ? 'gc-cell-good' : (crmVal >= 3 ? 'gc-cell-warn' : 'gc-cell-bad');
            const pFatCls = pFat >= 35 ? 'gc-cell-good' : (pFat >= 20 ? 'gc-cell-warn' : 'gc-cell-bad');
            const pConvCls = pConv >= 14 ? 'gc-cell-good' : (pConv >= 8 ? 'gc-cell-warn' : 'gc-cell-bad');
            const pErrosCls = pErros >= 14 ? 'gc-cell-good' : (pErros >= 8 ? 'gc-cell-warn' : 'gc-cell-bad');
            const scoreCls = score >= 80 ? 'gc-cell-good' : (score >= 65 ? 'gc-cell-warn' : 'gc-cell-bad');
            const totalCls = totalPts >= 80 ? 'gc-cell-good' : (totalPts >= 65 ? 'gc-cell-warn' : 'gc-cell-bad');
            const status = gcRelMensalCrmStatus(score);
            const statusCls = score >= 80 ? 'gc-rel-status-good' : (score >= 65 ? 'gc-rel-status-warn' : 'gc-rel-status-bad');
            return `<tr>
                <td>${escapeHtml(gcDisplayConsultoraName(r.vendedora || ''))}</td>
                <td class="gc-num">${formatMoney(r.meta_individual || 0)}</td>
                <td class="gc-num">${formatMoney(r.venda_total_mes || 0)}</td>
                <td class="gc-num ${pctMetaCls}">${formatPercent(pctMeta)}</td>
                <td class="gc-num">${Number(r.orcamentos || 0).toLocaleString('pt-BR')}</td>
                <td class="gc-num">${Number(r.pedidos || 0).toLocaleString('pt-BR')}</td>
                <td class="gc-num ${convCls}">${formatPercent(convPct)}</td>
                <td class="gc-num ${rejCls}" title="Taxa de rejeição: ${formatPercent(taxaRej)}">${rejeitados.toLocaleString('pt-BR')}</td>
                <td class="gc-num ${errosCls}">${erros.toLocaleString('pt-BR')}</td>
                <td class="gc-num ${crmCls}"><input type="number" min="0" step="0.1" class="gc-rel-crm-input" data-crm-key="${escapeHtml(rowKey)}" value="${crmVal.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 1, useGrouping: false })}" aria-label="Editar CRM de ${escapeHtml(gcDisplayConsultoraName(r.vendedora || ''))}"></td>
                <td class="gc-num ${pFatCls}">${pFat.toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td class="gc-num ${pConvCls}">${pConv.toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td class="gc-num ${pErrosCls}">${pErros.toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td class="gc-num ${totalCls}">${totalPts.toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td class="gc-num ${scoreCls}">${Number(score).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td><span class="gc-rel-status-chip ${statusCls}">${escapeHtml(String(status || '—'))}</span></td>
            </tr>`;
        }).join('');
    }

    function gcNomeMesPt(m) {
        var nomes = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        var i = Number(m) - 1;
        return (i >= 0 && i < 12) ? nomes[i] : String(m);
    }

    function gcComissaoOpOptionsHtml(selectedOp) {
        var ops = ['>=', '>', '<=', '<'];
        var s = String(selectedOp || '>=');
        return ops.map(function (op) {
            return '<option value="' + escapeHtml(op) + '"' + (op === s ? ' selected' : '') + '>' + escapeHtml(op) + '</option>';
        }).join('');
    }

    var gcComissaoSaveBound = false;
    /** Mês cujos dados estão atualmente nas tabelas (após dashboard ou Carregar). */
    var gcComissaoTabelasRef = { ano: 0, mes: 0 };
    /** Se há regras salvas no banco para o mês atualmente mostrado nas tabelas. */
    var gcComissaoEditorSalvasCached = false;
    var gcComissaoSelInited = false;
    /** Campos das tabelas bloqueados até clicar em Editar. */
    var gcComissaoEditMode = false;

    function gcInitComissaoRegrasSelectorsOnce(centerYear) {
        if (gcComissaoSelInited) return;
        gcComissaoSelInited = true;
        var cy = Number(centerYear) || new Date().getFullYear();
        var mesSel = document.getElementById('gcComissaoRegrasMes');
        var anoSel = document.getElementById('gcComissaoRegrasAno');
        if (!mesSel || !anoSel) return;
        mesSel.innerHTML = '';
        for (var m = 1; m <= 12; m++) {
            var o = document.createElement('option');
            o.value = String(m);
            o.textContent = gcNomeMesPt(m);
            mesSel.appendChild(o);
        }
        anoSel.innerHTML = '';
        for (var y = cy - 5; y <= cy + 3; y++) {
            var o2 = document.createElement('option');
            o2.value = String(y);
            o2.textContent = String(y);
            anoSel.appendChild(o2);
        }
    }

    function gcSetComissaoEditorAnoMes(ano, mes) {
        var mesSel = document.getElementById('gcComissaoRegrasMes');
        var anoSel = document.getElementById('gcComissaoRegrasAno');
        if (!mesSel || !anoSel) return;
        var m = Math.max(1, Math.min(12, Number(mes) || 1));
        var y = Number(ano);
        if (!y || y < 2000) y = new Date().getFullYear();
        var hasY = false;
        for (var i = 0; i < anoSel.options.length; i++) {
            if (Number(anoSel.options[i].value) === y) { hasY = true; break; }
        }
        if (!hasY) {
            var vals = [];
            for (var j = 0; j < anoSel.options.length; j++) vals.push(Number(anoSel.options[j].value));
            vals.push(y);
            vals.sort(function (a, b) { return a - b; });
            anoSel.innerHTML = '';
            vals.forEach(function (yy) {
                var op = document.createElement('option');
                op.value = String(yy);
                op.textContent = String(yy);
                anoSel.appendChild(op);
            });
        }
        anoSel.value = String(y);
        mesSel.value = String(m);
    }

    function gcApplyComissaoRegrasEditMode(edit) {
        gcComissaoEditMode = !!edit;
        var wrap = document.getElementById('gcComissaoRegrasTablesWrap');
        if (wrap) {
            wrap.setAttribute('data-edit-mode', edit ? 'true' : 'false');
        }
        if (wrap) {
            wrap.querySelectorAll('.gc-comissao-inp').forEach(function (el) {
                el.disabled = !edit;
            });
        }
        var btnSal = document.getElementById('gcComissaoRegrasSalvarBtn');
        if (btnSal) btnSal.disabled = !edit;
        var lbl = document.getElementById('gcComissaoModoLabel');
        if (lbl) {
            lbl.textContent = edit ? 'Modo edição' : 'Visualização';
            lbl.classList.toggle('is-editing', edit);
        }
        var btnEd = document.getElementById('gcComissaoRegrasEditarBtn');
        if (btnEd) {
            btnEd.innerHTML = edit
                ? '<i class="fas fa-eye" aria-hidden="true"></i> Só visualizar'
                : '<i class="fas fa-pen-to-square" aria-hidden="true"></i> Editar';
            btnEd.setAttribute('aria-pressed', edit ? 'true' : 'false');
        }
    }

    function gcGetComissaoEditorAnoMes() {
        var mesSel = document.getElementById('gcComissaoRegrasMes');
        var anoSel = document.getElementById('gcComissaoRegrasAno');
        if (!mesSel || !anoSel) return { ano: 0, mes: 0 };
        return { ano: Number(anoSel.value) || 0, mes: Number(mesSel.value) || 0 };
    }

    function gcFillComissaoTabelas(com) {
        var comissao = com && typeof com === 'object' ? com : {};
        var indBody = document.getElementById('gcRelComissaoIndTbody');
        if (indBody) {
            var indRows = Array.isArray(comissao.faixas_individuais) ? comissao.faixas_individuais : [];
            indBody.innerHTML = indRows.length
                ? indRows.map(function (r) {
                    var mmx = r.meta_max === null || r.meta_max === undefined || r.meta_max === '' ? '' : String(r.meta_max);
                    return `<tr>
                        <td><input type="text" class="gc-rel-crm-input gc-comissao-inp" style="width:100%;min-width:88px;" value="${escapeHtml(String(r.meta_pct_faixa || ''))}" aria-label="Rótulo faixa percentual meta"></td>
                        <td><input type="text" class="gc-rel-crm-input gc-comissao-inp" style="width:100%;min-width:120px;" value="${escapeHtml(String(r.intervalo || ''))}" aria-label="Descrição faixa em R$"></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:5.2rem;" value="${escapeHtml(String(Number(r.meta_min ?? 0)))}" aria-label="Meta mínima em percentual"></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:5.2rem;" value="${escapeHtml(mmx)}" aria-label="Meta máxima em percentual (vazio = sem teto)" title="Vazio = sem limite superior"></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:4.2rem;" value="${escapeHtml(String(Number(r.comissao_percentual || 0)))}" aria-label="Comissão percentual"></td>
                    </tr>`;
                }).join('')
                : '<tr><td colspan="5">Sem regras cadastradas.</td></tr>';
        }
        var grpBody = document.getElementById('gcRelComissaoGrupoTbody');
        if (grpBody) {
            var grpRows = Array.isArray(comissao.faixas_grupo) ? comissao.faixas_grupo : [];
            grpBody.innerHTML = grpRows.length
                ? grpRows.map(function (r) {
                    var rvmx = r.rev_max === null || r.rev_max === undefined || r.rev_max === '' ? '' : String(r.rev_max);
                    return `<tr>
                        <td><input type="text" class="gc-rel-crm-input gc-comissao-inp" style="width:100%;min-width:120px;" value="${escapeHtml(String(r.faixa_receita || ''))}" aria-label="Descrição faixa grupo"></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:6.5rem;" value="${escapeHtml(String(Number(r.rev_min ?? 0)))}" aria-label="Receita mínima do grupo R$"></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:6.5rem;" value="${escapeHtml(rvmx)}" aria-label="Receita máxima R$ (vazio = sem teto)" title="Vazio = sem limite superior"></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:3.8rem;" value="${escapeHtml(String(Number(r.percentual || 0)))}" aria-label="Percentual comissão grupo"></td>
                    </tr>`;
                }).join('')
                : '<tr><td colspan="4">Sem regras cadastradas.</td></tr>';
        }
        var premBody = document.getElementById('gcRelComissaoPremioTbody');
        if (premBody) {
            var premRows = Array.isArray(comissao.premios_score) ? comissao.premios_score : [];
            premBody.innerHTML = premRows.length
                ? premRows.map(function (r) {
                    var mo = String(r.meta_op || '>=');
                    var so = String(r.score_op || '>');
                    return `<tr>
                        <td><input type="text" class="gc-rel-crm-input gc-comissao-inp" style="width:100%;min-width:140px;" value="${escapeHtml(String(r.regra || ''))}" aria-label="Texto da regra de bônus"></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:5.5rem;" value="${escapeHtml(String(Number(r.premio || 0)))}" aria-label="Valor do prêmio R$"></td>
                        <td><select class="gc-rel-crm-input gc-comissao-inp" style="min-width:3.2rem;padding:4px;" aria-label="Comparador percentual meta">${gcComissaoOpOptionsHtml(mo)}</select></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:3.8rem;" value="${escapeHtml(String(Number(r.meta_val ?? 100)))}" aria-label="Referência percentual meta"></td>
                        <td><select class="gc-rel-crm-input gc-comissao-inp" style="min-width:3.2rem;padding:4px;" aria-label="Comparador score">${gcComissaoOpOptionsHtml(so)}</select></td>
                        <td class="gc-num"><input type="number" step="any" class="gc-rel-crm-input gc-comissao-inp" style="width:3.8rem;" value="${escapeHtml(String(Number(r.score_val ?? 0)))}" aria-label="Referência score"></td>
                    </tr>`;
                }).join('')
                : '<tr><td colspan="6">Sem regras cadastradas.</td></tr>';
        }
    }

    function gcUpdateComissaoRefHint() {
        var wrap = document.getElementById('gcComissaoRegrasRefText');
        if (!wrap) return;
        var com = gcDashboardData && gcDashboardData.relatorios_comerciais && gcDashboardData.relatorios_comerciais.comissao ? gcDashboardData.relatorios_comerciais.comissao : {};
        var ref = com.regras_ref || {};
        wrap.innerHTML = '';
        if (!ref.ano || !ref.mes) return;
        var ed = gcGetComissaoEditorAnoMes();
        var p1 = document.createElement('p');
        p1.className = 'gc-comissao-hint__p';
        p1.textContent = 'Cálculos do relatório (semanal, mensal, % equipe): ' + gcNomeMesPt(ref.mes) + ' de ' + ref.ano + (com.regras_salvas_no_mes ? ' (regras salvas no banco).' : ' (padrão do sistema até salvar).');
        var p2 = document.createElement('p');
        p2.className = 'gc-comissao-hint__p';
        var tablesMatchSel = gcComissaoTabelasRef.ano === ed.ano && gcComissaoTabelasRef.mes === ed.mes;
        var sameSel = ed.ano === ref.ano && ed.mes === ref.mes;
        if (!tablesMatchSel) {
            p2.textContent = 'Selecionado ' + gcNomeMesPt(ed.mes) + ' de ' + ed.ano + ' — clique Carregar para trazer as regras desse mês nas tabelas. O relatório continua usando ' + gcNomeMesPt(ref.mes) + ' de ' + ref.ano + ' (data Até).';
        } else if (sameSel) {
            p2.textContent = 'Tabelas: mesmo mês dos cálculos. Altere ano/mês e use Carregar para editar outro mês civil sem mudar o filtro.';
        } else {
            p2.textContent = 'Tabelas: ' + gcNomeMesPt(ed.mes) + ' de ' + ed.ano + (gcComissaoEditorSalvasCached ? ' (regras salvas).' : ' (padrão).') + ' — o relatório e a equipe ainda usam ' + gcNomeMesPt(ref.mes) + ' de ' + ref.ano + ' até ajustar a data Até.';
        }
        wrap.appendChild(p1);
        wrap.appendChild(p2);
    }

    async function gcCarregarComissaoRegrasEditor() {
        var msg = document.getElementById('gcComissaoRegrasSalvarMsg');
        var ed = gcGetComissaoEditorAnoMes();
        if (!ed.ano || !ed.mes) {
            if (msg) msg.textContent = 'Selecione ano e mês.';
            return;
        }
        if (msg) msg.textContent = 'Carregando...';
        var res = await apiGet('gestao_comercial_comissao_regras_get', { ano: String(ed.ano), mes: String(ed.mes) });
        if (res && res.success && res.payload) {
            gcComissaoEditorSalvasCached = !!res.regras_salvas_no_mes;
            gcComissaoTabelasRef = { ano: Number(res.ano) || ed.ano, mes: Number(res.mes) || ed.mes };
            gcFillComissaoTabelas(res.payload);
            gcApplyComissaoRegrasEditMode(false);
            gcUpdateComissaoRefHint();
            if (msg) msg.textContent = '';
        } else {
            if (msg) msg.textContent = (res && res.error) ? String(res.error) : 'Não foi possível carregar.';
        }
    }

    function gcParseNumInput(v) {
        if (v === null || v === undefined) return NaN;
        var t = String(v).trim().replace(/\s/g, '').replace(',', '.');
        if (t === '') return NaN;
        return Number(t);
    }

    function gcReadComissaoPayloadFromDom() {
        var faixas_individuais = [];
        document.querySelectorAll('#gcRelComissaoIndTbody tr').forEach(function (tr) {
            var tds = tr.querySelectorAll('td');
            if (tds.length < 5) return;
            var meta_pct_faixa = tds[0].querySelector('input') ? tds[0].querySelector('input').value : '';
            var intervalo = tds[1].querySelector('input') ? tds[1].querySelector('input').value : '';
            var meta_min = gcParseNumInput(tds[2].querySelector('input') ? tds[2].querySelector('input').value : '0');
            var maxRaw = tds[3].querySelector('input') ? String(tds[3].querySelector('input').value).trim() : '';
            var metaMaxParsed = maxRaw === '' ? null : gcParseNumInput(maxRaw);
            var comissao_percentual = gcParseNumInput(tds[4].querySelector('input') ? tds[4].querySelector('input').value : '0');
            faixas_individuais.push({
                meta_pct_faixa: meta_pct_faixa,
                intervalo: intervalo,
                meta_min: Number.isFinite(meta_min) ? meta_min : 0,
                meta_max: metaMaxParsed === null ? null : (Number.isFinite(metaMaxParsed) ? metaMaxParsed : null),
                comissao_percentual: Number.isFinite(comissao_percentual) ? comissao_percentual : 0
            });
        });
        var faixas_grupo = [];
        document.querySelectorAll('#gcRelComissaoGrupoTbody tr').forEach(function (tr) {
            var tds = tr.querySelectorAll('td');
            if (tds.length < 4) return;
            var faixa_receita = tds[0].querySelector('input') ? tds[0].querySelector('input').value : '';
            var rev_min = gcParseNumInput(tds[1].querySelector('input') ? tds[1].querySelector('input').value : '0');
            var maxRevRaw = tds[2].querySelector('input') ? String(tds[2].querySelector('input').value).trim() : '';
            var revMaxParsed = maxRevRaw === '' ? null : gcParseNumInput(maxRevRaw);
            var percentual = gcParseNumInput(tds[3].querySelector('input') ? tds[3].querySelector('input').value : '0');
            faixas_grupo.push({
                faixa_receita: faixa_receita,
                rev_min: Number.isFinite(rev_min) ? rev_min : 0,
                rev_max: revMaxParsed === null ? null : (Number.isFinite(revMaxParsed) ? revMaxParsed : null),
                percentual: Number.isFinite(percentual) ? percentual : 0
            });
        });
        var premios_score = [];
        document.querySelectorAll('#gcRelComissaoPremioTbody tr').forEach(function (tr) {
            var tds = tr.querySelectorAll('td');
            if (tds.length < 6) return;
            var regra = tds[0].querySelector('input') ? tds[0].querySelector('input').value : '';
            var premio = gcParseNumInput(tds[1].querySelector('input') ? tds[1].querySelector('input').value : '0');
            var meta_op = tds[2].querySelector('select') ? tds[2].querySelector('select').value : '>=';
            var meta_val = gcParseNumInput(tds[3].querySelector('input') ? tds[3].querySelector('input').value : '0');
            var score_op = tds[4].querySelector('select') ? tds[4].querySelector('select').value : '>';
            var score_val = gcParseNumInput(tds[5].querySelector('input') ? tds[5].querySelector('input').value : '0');
            premios_score.push({
                regra: regra,
                premio: Number.isFinite(premio) ? premio : 0,
                meta_op: meta_op,
                meta_val: Number.isFinite(meta_val) ? meta_val : 0,
                score_op: score_op,
                score_val: Number.isFinite(score_val) ? score_val : 0
            });
        });
        return { faixas_individuais: faixas_individuais, faixas_grupo: faixas_grupo, premios_score: premios_score };
    }

    async function gcSalvarComissaoRegrasDoMes() {
        var msg = document.getElementById('gcComissaoRegrasSalvarMsg');
        var ed = gcGetComissaoEditorAnoMes();
        var ano = ed.ano;
        var mes = ed.mes;
        if (!ano || !mes) {
            if (msg) msg.textContent = 'Selecione ano e mês.';
            return;
        }
        if (!gcComissaoEditMode) {
            if (msg) msg.textContent = 'Clique em Editar para habilitar alterações e salvar.';
            return;
        }
        var edTab = gcComissaoTabelasRef;
        if (edTab.ano !== ano || edTab.mes !== mes) {
            if (msg) msg.textContent = 'Clique Carregar para alinhar as tabelas ao mês selecionado antes de salvar.';
            return;
        }
        var part = gcReadComissaoPayloadFromDom();
        var body = { ano: ano, mes: mes, faixas_individuais: part.faixas_individuais, faixas_grupo: part.faixas_grupo, premios_score: part.premios_score };
        if (msg) msg.textContent = 'Salvando...';
        var res = await apiPost('gestao_comercial_comissao_regras_salvar', body);
        if (res && res.success) {
            if (msg) msg.textContent = 'Salvo.';
            await loadDashboardData();
        } else {
            if (msg) msg.textContent = (res && res.error) ? String(res.error) : 'Falha ao salvar.';
        }
    }

    function gcEnsureComissaoRegrasSaveBind() {
        if (gcComissaoSaveBound) return;
        gcComissaoSaveBound = true;
        var btn = document.getElementById('gcComissaoRegrasSalvarBtn');
        if (btn) btn.addEventListener('click', function () { gcSalvarComissaoRegrasDoMes(); });
        var btnCar = document.getElementById('gcComissaoRegrasCarregarBtn');
        if (btnCar) btnCar.addEventListener('click', function () { gcCarregarComissaoRegrasEditor(); });
        var btnEd = document.getElementById('gcComissaoRegrasEditarBtn');
        if (btnEd) btnEd.addEventListener('click', function () { gcApplyComissaoRegrasEditMode(!gcComissaoEditMode); });
        var mesSel = document.getElementById('gcComissaoRegrasMes');
        var anoSel = document.getElementById('gcComissaoRegrasAno');
        function onSelChange() {
            gcUpdateComissaoRefHint();
        }
        if (mesSel) mesSel.addEventListener('change', onSelChange);
        if (anoSel) anoSel.addEventListener('change', onSelChange);
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

        gcEnsureRelSemSortBind();
        gcEnsureRelMensalCrmBind();
        gcRenderRelatorioSemanal(semanal?.linhas || []);
        gcRenderRelatorioMensal(mensal?.linhas || []);

        gcEnsureComissaoRegrasSaveBind();
        var refC = comissao && comissao.regras_ref ? comissao.regras_ref : {};
        gcInitComissaoRegrasSelectorsOnce(Number(refC.ano) || new Date().getFullYear());
        gcSetComissaoEditorAnoMes(Number(refC.ano) || new Date().getFullYear(), Number(refC.mes) || (new Date().getMonth() + 1));
        gcComissaoEditorSalvasCached = !!comissao.regras_salvas_no_mes;
        gcComissaoTabelasRef = { ano: Number(refC.ano) || 0, mes: Number(refC.mes) || 0 };
        gcFillComissaoTabelas(comissao);
        gcApplyComissaoRegrasEditMode(false);
        gcUpdateComissaoRefHint();
    }

    function gcGetCurrentDateRangeParams() {
        const deEl = document.getElementById('gcDataDe');
        const ateEl = document.getElementById('gcDataAte');
        const out = {};
        if (deEl && deEl.value) out.data_de = deEl.value;
        if (ateEl && ateEl.value) out.data_ate = ateEl.value;
        return out;
    }

    function gcIsRelatoriosTabActive() {
        const sec = document.getElementById('gc-section-relatorios');
        return !!(sec && sec.classList.contains('active'));
    }

    async function gcApiPhpGet(action, params) {
        const query = new URLSearchParams(Object.assign({ action: action || '' }, params || {}));
        const r = await fetch(`${GC_PHP_API_URL}?${query.toString()}`, { credentials: 'include' });
        const text = await r.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch (e) {
            return {};
        }
    }

    function gcSyncPedidosRelDatesFromGlobal() {
        const gDe = document.getElementById('gcDataDe');
        const gA = document.getElementById('gcDataAte');
        const pDe = document.getElementById('gcPedidosRelDataDe');
        const pA = document.getElementById('gcPedidosRelDataAte');
        if (gDe && pDe && gDe.value) pDe.value = gDe.value;
        if (gA && pA && gA.value) pA.value = gA.value;
    }

    function gcSetPedidosRelMsg(text) {
        const el = document.getElementById('gcPedidosRelMsg');
        if (!el) return;
        const t = (text || '').trim();
        if (!t) {
            el.textContent = '';
            el.hidden = true;
            return;
        }
        el.textContent = t;
        el.hidden = false;
    }

    function gcFillPedidosRelSelect(id, names, currentVal) {
        const sel = document.getElementById(id);
        if (!sel) return;
        const cur = currentVal != null ? String(currentVal) : String(sel.value || '');
        const list = Array.isArray(names) ? names.slice() : [];
        sel.innerHTML =
            '<option value="">Todos</option>' +
            list
                .map(function (n) {
                    const s = String(n);
                    return `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`;
                })
                .join('');
        const has = Array.from(sel.options).some(function (o) {
            return o.value === cur;
        });
        if (has) sel.value = cur;
    }

    function gcPedidosRelAnoRef() {
        const pDe = document.getElementById('gcPedidosRelDataDe');
        const y = pDe && pDe.value ? String(pDe.value).slice(0, 4) : '';
        if (y && /^\d{4}$/.test(y)) return y;
        return String(new Date().getFullYear());
    }

    function gcSortPedidosRelList(list, col, dir) {
        const d = dir === 'asc' ? 1 : -1;
        return list.slice().sort(function (a, b) {
            let va = a[col];
            let vb = b[col];
            if (col === 'data_aprovacao' || col === 'data_orcamento') {
                va = (va || '').replace(/-/g, '');
                vb = (vb || '').replace(/-/g, '');
                return (va === vb ? 0 : va < vb ? -1 : 1) * d;
            }
            if (col === 'valor') {
                va = parseFloat(va) || 0;
                vb = parseFloat(vb) || 0;
                return (va - vb) * d;
            }
            if (col === 'numero_pedido' || col === 'serie_pedido') {
                va = Number(va) || 0;
                vb = Number(vb) || 0;
                return (va - vb) * d;
            }
            va = String(va || '').toLowerCase();
            vb = String(vb || '').toLowerCase();
            return (va < vb ? -1 : va > vb ? 1 : 0) * d;
        });
    }

    function gcUpdatePedidosRelSortIcons() {
        const col = __gcPedidosSort.column;
        const dir = __gcPedidosSort.direction;
        document.querySelectorAll('#gcPedidosRelTable thead .gc-pedidos-vd-th-sort').forEach(function (el) {
            const c = el.getAttribute('data-sort-col');
            const icon = el.querySelector('i');
            if (!icon) return;
            icon.className = c === col ? (dir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down') : 'fas fa-sort';
        });
    }

    function gcPedidosRelGoTo(page) {
        __gcPedidosPage = Math.max(1, parseInt(page, 10) || 1);
        gcRenderPedidosRelatorioTabela();
    }

    function gcTogglePedidosRelSeries(numeroPedido) {
        const key = String(numeroPedido || '');
        if (!key) return;
        __gcPedidosExpanded[key] = !__gcPedidosExpanded[key];
        gcRenderPedidosRelatorioTabela();
    }

    function gcSortPedidosRelBy(col) {
        if (!col) return;
        if (__gcPedidosSort.column === col) {
            __gcPedidosSort.direction = __gcPedidosSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            __gcPedidosSort.column = col;
            __gcPedidosSort.direction = 'desc';
        }
        __gcPedidosPage = 1;
        gcRenderPedidosRelatorioTabela();
    }

    function gcRenderPedidosRelatorioTabela() {
        const tbody = document.getElementById('gcPedidosRelTbody');
        const pagEl = document.getElementById('gcPedidosRelPagination');
        if (!tbody) return;
        const searchInput = document.getElementById('gcPedidosRelSearch');
        const q = ((searchInput || {}).value || '').toLowerCase().trim();
        // Filtro de data local (campos específicos da tabela, se existirem)
        // Não usa o topbar como fallback pois a API já filtrou pelo período do topbar
        const dataDe = (document.getElementById('gcPedidosRelDataDe') || {}).value || '';
        const dataAte = (document.getElementById('gcPedidosRelDataAte') || {}).value || '';
        const filtroStatus = (document.getElementById('gcPedidosRelFiltroStatus') || {}).value || '';
        let list = __gcPedidosList.slice();
        if (filtroStatus) {
            list = list.filter(function (p) {
                return String(p.tipo || '') === filtroStatus;
            });
        }
        if (q) {
            list = list.filter(function (p) {
                return (
                    String(p.prescritor || '')
                        .toLowerCase()
                        .indexOf(q) !== -1 ||
                    String(p.cliente || '')
                        .toLowerCase()
                        .indexOf(q) !== -1 ||
                    String(p.numero_pedido || '').indexOf(q) !== -1
                );
            });
        }
        if (dataDe || dataAte) {
            list = list.filter(function (p) {
                const raw =
                    (p.tipo || '') === 'Recusado' || (p.tipo || '') === 'No carrinho'
                        ? p.data_orcamento || p.data_aprovacao
                        : p.data_aprovacao || p.data_orcamento;
                const s = String(raw || '').trim();
                const m = s.match(/^(\d{4}-\d{2}-\d{2})/);
                const d = m ? m[1] : '';
                if (!d && (dataDe || dataAte)) return false;
                if (!d) return false;
                if (dataDe && d < dataDe) return false;
                if (dataAte && d > dataAte) return false;
                return true;
            });
        }
        list = gcSortPedidosRelList(list, __gcPedidosSort.column, __gcPedidosSort.direction);
        const groupsMap = {};
        const groupsOrder = [];
        list.forEach(function (p) {
            const numero = String(p.numero_pedido || '').trim();
            if (!numero) return;
            if (!groupsMap[numero]) {
                groupsMap[numero] = { numero_pedido: numero, rows: [], valor_total: 0 };
                groupsOrder.push(numero);
            }
            groupsMap[numero].rows.push(p);
            groupsMap[numero].valor_total += parseFloat(p.valor) || 0;
        });
        const groupedList = groupsOrder.map(function (numero) {
            const g = groupsMap[numero];
            const rows = g.rows || [];
            const first = rows[0] || {};
            const tipos = {};
            const prescritores = {};
            const clientes = {};
            let dataAprov = '';
            let dataOrc = '';
            rows.forEach(function (r) {
                const tp = String(r.tipo || '').trim() || '—';
                tipos[tp] = true;
                const presc = String(r.prescritor || '').trim();
                if (presc) prescritores[presc] = true;
                const cli = String(r.cliente || '').trim();
                if (cli) clientes[cli] = true;
                const dA = String(r.data_aprovacao || '').trim();
                const dO = String(r.data_orcamento || '').trim();
                if (dA && (!dataAprov || dA > dataAprov)) dataAprov = dA;
                if (dO && (!dataOrc || dO > dataOrc)) dataOrc = dO;
            });
            const tiposKeys = Object.keys(tipos);
            const tipoResumo = tiposKeys.length > 1 ? 'Misto' : tiposKeys[0] || first.tipo || '—';
            const prescKeys = Object.keys(prescritores);
            const cliKeys = Object.keys(clientes);
            const prescritorResumo =
                prescKeys.length <= 1 ? prescKeys[0] || first.prescritor || '—' : prescKeys[0] + ' +' + (prescKeys.length - 1);
            const clienteResumo =
                cliKeys.length <= 1 ? cliKeys[0] || first.cliente || '—' : cliKeys[0] + ' +' + (cliKeys.length - 1);
            return {
                numero_pedido: numero,
                serie_pedido: rows.length,
                data_aprovacao: dataAprov,
                data_orcamento: dataOrc,
                prescritor: prescritorResumo,
                cliente: clienteResumo,
                valor: g.valor_total,
                tipo: tipoResumo,
                rows: rows
            };
        });
        __gcPedidosLastGroupedFiltered = groupedList.slice();
        const fmtBr = function (v) {
            const n = (parseFloat(v) || 0).toFixed(2);
            const p = n.split('.');
            return 'R$ ' + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + p[1];
        };
        // KPIs calculados sobre as rows individuais (ignora tipo "Misto" do grupo)
        let sumValorAprov = 0;
        let sumValorReprov = 0;
        const pedidosAprovSet = new Set();
        const pedidosReprovSet = new Set();
        groupedList.forEach(function (g) {
            (g.rows || []).forEach(function (r) {
                const t = String(r.tipo || '');
                const v = parseFloat(r.valor) || 0;
                if (t === 'Aprovado') {
                    sumValorAprov += v;
                    pedidosAprovSet.add(String(r.numero_pedido));
                } else if (t === 'Recusado' || t === 'No carrinho') {
                    sumValorReprov += v;
                    pedidosReprovSet.add(String(r.numero_pedido) + '_' + String(r.serie_pedido));
                }
            });
        });
        const cardQty = document.getElementById('gcPedidosRelCardQtd');
        const cardValorAprov = document.getElementById('gcPedidosRelCardValorAprov');
        const cardValorReprov = document.getElementById('gcPedidosRelCardValorReprov');
        const cardQtdRecusados = document.getElementById('gcPedidosRelCardQtdRecusados');
        if (cardValorAprov) cardValorAprov.textContent = fmtBr(sumValorAprov);
        if (cardValorReprov) cardValorReprov.textContent = fmtBr(sumValorReprov);
        if (cardQty) cardQty.textContent = pedidosAprovSet.size + ' pedido' + (pedidosAprovSet.size !== 1 ? 's' : '');
        if (cardQtdRecusados) cardQtdRecusados.textContent = pedidosReprovSet.size + ' pedido' + (pedidosReprovSet.size !== 1 ? 's' : '');
        const esc = function (x) {
            return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        };
        function fmtDate(d) {
            if (!d) return '—';
            const s = String(d).trim();
            if (s.length >= 10) return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
            return s;
        }
        function fmtMoneyLocal(v) {
            return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ',');
        }
        function serieStr(p) {
            const v = p.serie_pedido;
            if (v === null || v === undefined) return '0';
            if (v === '') return '0';
            if (typeof v === 'string' && v.trim() === '') return '0';
            return String(v);
        }
        const anoRef = gcPedidosRelAnoRef();
        if (groupedList.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" style="text-align:center; padding:40px 16px; color:var(--text-secondary);">Nenhum pedido encontrado.</td></tr>';
            if (pagEl) pagEl.innerHTML = '';
            gcUpdatePedidosRelSortIcons();
            return;
        }
        const totalPages = Math.max(1, Math.ceil(groupedList.length / __gcPedidosPageSize));
        __gcPedidosPage = Math.min(Math.max(1, __gcPedidosPage), totalPages);
        const start = (__gcPedidosPage - 1) * __gcPedidosPageSize;
        const pageList = groupedList.slice(start, start + __gcPedidosPageSize);
        let html = '';
        pageList.forEach(function (group) {
            const isAprovado = group.tipo === 'Aprovado';
            const isRecusado = group.tipo === 'Recusado';
            const corValor = isAprovado ? 'var(--success)' : isRecusado ? 'var(--danger)' : 'var(--text-primary)';
            const key = String(group.numero_pedido || '');
            const isOpen = !!__gcPedidosExpanded[key];
            const rows = Array.isArray(group.rows) ? group.rows : [];
            const keyJs = key.replace(/'/g, "\\'");
            const bgRow = isAprovado
                ? 'rgba(5,150,105,0.06)'
                : isRecusado
                ? 'rgba(220,38,38,0.07)'
                : 'rgba(234,179,8,0.08)'; // Misto = amarelo
            const borderRow = isAprovado
                ? '2px solid rgba(5,150,105,0.25)'
                : isRecusado
                ? '2px solid rgba(220,38,38,0.25)'
                : '2px solid rgba(234,179,8,0.35)';
            html += '<tr style="background:' + bgRow + ';border-left:' + borderRow + ';">';
            html +=
                '<td><button type="button" onclick="gcTogglePedidosRelSeries(\'' +
                keyJs +
                '\')" style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:1px solid var(--border);border-radius:6px;background:var(--bg-body);cursor:pointer;margin-right:8px;font-weight:700;line-height:1;">' +
                (isOpen ? '−' : '+') +
                '</button>' +
                esc(group.numero_pedido) +
                '</td>';
            html += '<td>' + esc(rows.length + ' série' + (rows.length !== 1 ? 's' : '')) + '</td>';
            html += '<td>' + esc(group.tipo === 'Recusado' || group.tipo === 'No carrinho' ? '—' : fmtDate(group.data_aprovacao)) + '</td>';
            html +=
                '<td>' +
                esc(fmtDate(group.data_orcamento || (group.tipo === 'Aprovado' ? group.data_aprovacao : ''))) +
                '</td>';
            html += '<td>' + esc(group.prescritor) + '</td>';
            html += '<td>' + esc(group.cliente) + '</td>';
            html +=
                '<td style="text-align:right;font-weight:700;color:' +
                corValor +
                ';">' +
                esc(fmtMoneyLocal(group.valor)) +
                '</td>';
            html += '<td>' + esc(group.tipo) + '</td></tr>';
            if (isOpen) {
                rows.forEach(function (p) {
                    const rowAprov = p.tipo === 'Aprovado';
                    const n = p.numero_pedido;
                    const s = p.serie_pedido;
                    const sNum = s === null || s === undefined || s === '' ? 0 : s;
                    const anoRow =
                        p.ano_referencia != null && String(p.ano_referencia).trim() !== ''
                            ? String(p.ano_referencia).trim()
                            : String(anoRef);
                    const subAprov = p.tipo === 'Aprovado';
                    const subRec = p.tipo === 'Recusado' || p.tipo === 'No carrinho';
                    const bgSub = subAprov
                        ? 'rgba(5,150,105,0.03)'
                        : subRec
                        ? 'rgba(220,38,38,0.04)'
                        : 'rgba(234,179,8,0.04)';
                    const borderSub = subAprov
                        ? '2px solid rgba(5,150,105,0.15)'
                        : subRec
                        ? '2px solid rgba(220,38,38,0.15)'
                        : '2px solid rgba(234,179,8,0.2)';
                    html +=
                        '<tr role="button" tabindex="0" onclick="gcOpenModalDetalhePedidoGc(' +
                        n +
                        ',' +
                        sNum +
                        ',\'' +
                        anoRow.replace(/'/g, "\\'") +
                        '\')" style="cursor:pointer;background:' + bgSub + ';border-left:' + borderSub + ';" title="Ver detalhes da série">';
                    html += '<td style="padding-left:36px;color:var(--text-secondary);">↳ ' + esc(p.numero_pedido) + '</td>';
                    html += '<td>' + esc(serieStr(p)) + '</td>';
                    html += '<td>' + esc(p.tipo === 'Recusado' || p.tipo === 'No carrinho' ? '—' : fmtDate(p.data_aprovacao)) + '</td>';
                    html +=
                        '<td>' +
                        esc(fmtDate(p.data_orcamento || (p.tipo === 'Aprovado' ? p.data_aprovacao : ''))) +
                        '</td>';
                    html += '<td>' + esc(p.prescritor) + '</td>';
                    html += '<td>' + esc(p.cliente) + '</td>';
                    html +=
                        '<td style="text-align:right;font-weight:600;color:' +
                        (rowAprov ? 'var(--success)' : 'var(--danger)') +
                        ';">' +
                        esc(fmtMoneyLocal(p.valor)) +
                        '</td>';
                    html += '<td>' + esc(p.tipo) + '</td></tr>';
                });
            }
        });
        tbody.innerHTML = html;
        if (pagEl) {
            const from = start + 1;
            const to = Math.min(start + __gcPedidosPageSize, groupedList.length);
            let pagHtml =
                '<span style="font-weight:500;">Mostrando ' + from + ' – ' + to + ' de ' + groupedList.length + ' pedidos</span>';
            pagHtml += '<span class="gc-pedidos-vd-pag-btns">';
            pagHtml +=
                '<button type="button" onclick="gcPedidosRelGoTo(1)" ' +
                (__gcPedidosPage <= 1 ? 'disabled' : '') +
                ' title="Primeira"><i class="fas fa-angle-double-left"></i></button>';
            pagHtml +=
                '<button type="button" onclick="gcPedidosRelGoTo(' +
                (__gcPedidosPage - 1) +
                ')" ' +
                (__gcPedidosPage <= 1 ? 'disabled' : '') +
                ' title="Anterior"><i class="fas fa-angle-left"></i></button>';
            pagHtml +=
                '<span style="padding:0 10px;font-weight:500;">Página ' +
                __gcPedidosPage +
                ' de ' +
                totalPages +
                '</span>';
            pagHtml +=
                '<button type="button" onclick="gcPedidosRelGoTo(' +
                (__gcPedidosPage + 1) +
                ')" ' +
                (__gcPedidosPage >= totalPages ? 'disabled' : '') +
                ' title="Próxima"><i class="fas fa-angle-right"></i></button>';
            pagHtml +=
                '<button type="button" onclick="gcPedidosRelGoTo(' +
                totalPages +
                ')" ' +
                (__gcPedidosPage >= totalPages ? 'disabled' : '') +
                ' title="Última"><i class="fas fa-angle-double-right"></i></button>';
            pagHtml += '</span>';
            pagEl.innerHTML = pagHtml;
        }
        gcUpdatePedidosRelSortIcons();
    }

    function gcPrintPedidosRelFiltrados() {
        const groupedList = Array.isArray(__gcPedidosLastGroupedFiltered) ? __gcPedidosLastGroupedFiltered : [];
        if (!groupedList.length) {
            window.alert('Não há pedidos filtrados para imprimir.');
            return;
        }
        const dataDe = (document.getElementById('gcPedidosRelDataDe') || {}).value || '';
        const dataAte = (document.getElementById('gcPedidosRelDataAte') || {}).value || '';
        const status = (document.getElementById('gcPedidosRelFiltroStatus') || {}).value || 'Todos';
        const busca = ((document.getElementById('gcPedidosRelSearch') || {}).value || '').trim();
        const fmtDate = function (d) {
            if (!d) return '—';
            const s = String(d).trim();
            if (s.length >= 10) return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
            return s;
        };
        const fmtMoneyP = function (v) {
            const n = parseFloat(v) || 0;
            return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        };
        const esc = function (x) {
            return String(x == null ? '' : x)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };
        const totalValor = groupedList.reduce(function (acc, g) {
            return acc + (parseFloat(g.valor) || 0);
        }, 0);
        let sumAprovP = 0;
        let sumReprovP = 0;
        let sumRecP = 0;
        let qtdRecP = 0;
        groupedList.forEach(function (g) {
            (g.rows || []).forEach(function (r) {
                const t = String(r.tipo || '');
                const v = parseFloat(r.valor) || 0;
                if (t === 'Aprovado') sumAprovP += v;
                if (t === 'Recusado' || t === 'No carrinho') sumReprovP += v;
                if (t === 'Recusado') {
                    sumRecP += v;
                    qtdRecP += 1;
                }
            });
        });
        let linhas = '';
        groupedList.forEach(function (g) {
            const cor = g.tipo === 'Aprovado' ? '#059669' : g.tipo === 'Recusado' ? '#dc2626' : '#111827';
            linhas +=
                '<tr style="background:#f8fafc;">' +
                '<td style="padding:8px;border:1px solid #e5e7eb;font-weight:700;">' +
                esc(g.numero_pedido) +
                '</td>' +
                '<td style="padding:8px;border:1px solid #e5e7eb;">' +
                esc((g.rows || []).length + ' série(s)') +
                '</td>' +
                '<td style="padding:8px;border:1px solid #e5e7eb;">' +
                esc(fmtDate(g.data_aprovacao)) +
                '</td>' +
                '<td style="padding:8px;border:1px solid #e5e7eb;">' +
                esc(fmtDate(g.data_orcamento)) +
                '</td>' +
                '<td style="padding:8px;border:1px solid #e5e7eb;">' +
                esc(g.prescritor || '—') +
                '</td>' +
                '<td style="padding:8px;border:1px solid #e5e7eb;">' +
                esc(g.cliente || '—') +
                '</td>' +
                '<td style="padding:8px;border:1px solid #e5e7eb;text-align:right;color:' +
                cor +
                ';font-weight:700;">' +
                esc(fmtMoneyP(g.valor)) +
                '</td>' +
                '<td style="padding:8px;border:1px solid #e5e7eb;">' +
                esc(g.tipo || '—') +
                '</td></tr>';
        });
        const w = window.open('', '_blank');
        if (!w) return;
        const html =
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pedidos — Gestão comercial</title></head><body style="font-family:system-ui,sans-serif;padding:16px;">' +
            '<h1 style="font-size:1.1rem;">Pedidos (gestão comercial)</h1>' +
            '<p style="font-size:0.85rem;color:#444;">Período: ' +
            esc(dataDe) +
            ' a ' +
            esc(dataAte) +
            ' · Status: ' +
            esc(status) +
            (busca ? ' · Busca: ' + esc(busca) : '') +
            '</p>' +
            '<p style="font-size:0.9rem;"><strong>Total:</strong> ' +
            esc(fmtMoneyP(totalValor)) +
            '</p>' +
            '<p style="font-size:0.85rem;color:#444;">' +
            '<strong>Valor aprovado:</strong> ' +
            esc(fmtMoneyP(sumAprovP)) +
            ' · <strong>Valor reprovado:</strong> ' +
            esc(fmtMoneyP(sumReprovP)) +
            ' · <strong>Valor (recusados):</strong> ' +
            esc(fmtMoneyP(sumRecP)) +
            ' · <strong>Qtd. séries recusadas:</strong> ' +
            esc(String(qtdRecP)) +
            '</p>' +
            '<table style="width:100%;border-collapse:collapse;font-size:0.82rem;"><thead><tr>' +
            '<th style="border:1px solid #ccc;padding:6px;">Nº</th><th style="border:1px solid #ccc;padding:6px;">Séries</th>' +
            '<th style="border:1px solid #ccc;padding:6px;">Aprovação</th><th style="border:1px solid #ccc;padding:6px;">Orçamento</th>' +
            '<th style="border:1px solid #ccc;padding:6px;">Prescritor</th><th style="border:1px solid #ccc;padding:6px;">Cliente</th>' +
            '<th style="border:1px solid #ccc;padding:6px;">Valor</th><th style="border:1px solid #ccc;padding:6px;">Status</th></tr></thead><tbody>' +
            linhas +
            '</tbody></table></body></html>';
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(function () {
            w.print();
        }, 250);
    }

    async function gcLoadPedidosRelatorioVisitadorStyle() {
        gcSyncPedidosRelDatesFromGlobal();
        const tbody = document.getElementById('gcPedidosRelTbody');
        if (!tbody) return;
        tbody.innerHTML =
            '<tr><td colspan="8" style="text-align:center;padding:36px 16px;color:var(--text-secondary);">Carregando…</td></tr>';
        gcSetPedidosRelMsg('');
        const pDe = document.getElementById('gcPedidosRelDataDe');
        const pA = document.getElementById('gcPedidosRelDataAte');
        const params = {};
        if (pDe && pDe.value) params.data_de = pDe.value;
        if (pA && pA.value) params.data_ate = pA.value;
        const vis = (document.getElementById('gcPedidosRelVisitador') || {}).value || '';
        if (vis) params.visitador_carteira = vis;
        const pv = (document.getElementById('gcPedidosRelPrescritor') || {}).value || '';
        if (pv) params.prescritor = pv;
        const vv = (document.getElementById('gcPedidosRelVendedor') || {}).value || '';
        if (vv) params.vendedor = vv;
        let data;
        try {
            data = await apiGet('gestao_comercial_pedidos_visitador_style', params);
        } catch (e) {
            data = { success: false, error: 'Falha na requisição.' };
        }
        if (!data || data.success !== true) {
            __gcPedidosList = [];
            tbody.innerHTML =
                '<tr><td colspan="8" style="text-align:center;padding:36px;color:var(--danger);">Não foi possível carregar os pedidos.</td></tr>';
            gcSetPedidosRelMsg((data && data.error) ? String(data.error) : 'Erro ao carregar.');
            const pagEl = document.getElementById('gcPedidosRelPagination');
            if (pagEl) pagEl.innerHTML = '';
            return;
        }
        const aprov = Array.isArray(data.aprovados) ? data.aprovados : [];
        const rec = Array.isArray(data.recusados_carrinho) ? data.recusados_carrinho : [];
        __gcPedidosList = aprov
            .map(function (p) {
                return Object.assign({}, p, { tipo: 'Aprovado' });
            })
            .concat(
                rec.map(function (p) {
                    const st = String(p.tipo_listagem || p.status_financeiro || '').toLowerCase();
                    let tipo = 'Recusado';
                    if (st.indexOf('carrinho') !== -1) {
                        tipo = 'No carrinho';
                    } else if (
                        st.indexOf('recusad') !== -1
                        || st.indexOf('cancelad') !== -1
                        || st.indexOf('orçamento') !== -1
                        || st.indexOf('orcamento') !== -1
                    ) {
                        tipo = 'Recusado';
                    } else {
                        tipo = 'No carrinho';
                    }
                    return Object.assign({}, p, { tipo: tipo });
                })
            );
        __gcPedidosPage = 1;
        gcFillPedidosRelSelect('gcPedidosRelVisitador', data.visitadores_opcao || [], vis);
        gcFillPedidosRelSelect('gcPedidosRelPrescritor', data.prescritores_opcao || [], pv);
        gcFillPedidosRelSelect('gcPedidosRelVendedor', data.vendedores_opcao || [], vv);
        gcRenderPedidosRelatorioTabela();
    }

    async function gcOpenModalDetalhePedidoGc(numero, serie, ano) {
        const modal = document.getElementById('gcModalDetalhePedido');
        const loading = document.getElementById('gcModalDetalhePedidoLoading');
        const errEl = document.getElementById('gcModalDetalhePedidoError');
        const content = document.getElementById('gcModalDetalhePedidoContent');
        if (!modal || !loading) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        loading.style.display = 'block';
        if (errEl) errEl.style.display = 'none';
        if (content) content.style.display = 'none';
        const params = { numero: String(numero), serie: String(serie) };
        if (ano) params.ano = String(ano);
        const esc = function (x) {
            return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        };
        function fmtDateM(d) {
            if (!d) return '—';
            const s = String(d).trim();
            if (s.length >= 10) return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
            return s;
        }
        function fmtMoneyM(v) {
            return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ',');
        }
        function fmtQtdCalculada(qtd, unidade) {
            if (qtd === null || qtd === undefined || qtd === '') return '—';
            const n = parseFloat(qtd);
            if (isNaN(n)) return String(qtd).trim() + (unidade ? ' ' + String(unidade).trim() : '');
            const s = Number.isInteger(n) ? String(Math.round(n)) : String(n);
            const u = unidade && String(unidade).trim() ? ' ' + String(unidade).trim() : '';
            return s + u;
        }
        try {
            const resDet = await gcApiPhpGet('get_pedido_detalhe', params);
            if (resDet.error && !resDet.pedido) {
                loading.style.display = 'none';
                if (errEl) {
                    errEl.textContent = resDet.error || 'Pedido não encontrado.';
                    errEl.style.display = 'block';
                }
                return;
            }
            const p = resDet.pedido || resDet.resumo || {};
            const itensGestao = Array.isArray(resDet.itens_gestao) ? resDet.itens_gestao : [];
            const itensOrcamento = Array.isArray(resDet.itens_orcamento) ? resDet.itens_orcamento : [];
            const resComp = await gcApiPhpGet('get_pedido_componentes', params);
            const componentes = resComp && resComp.componentes ? resComp.componentes : [];
            loading.style.display = 'none';
            if (content) content.style.display = 'block';
            let fieldsHtml = '';
            const serieDetalhe =
                p.serie_pedido !== undefined && p.serie_pedido !== null && String(p.serie_pedido).trim() !== ''
                    ? String(p.serie_pedido).trim()
                    : '0';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Nº / Série</span><div style="font-weight:600;">' +
                esc(p.numero_pedido) +
                ' / ' +
                esc(serieDetalhe) +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Data aprovação</span><div style="font-weight:600;">' +
                esc(fmtDateM(p.data_aprovacao)) +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Data orçamento</span><div style="font-weight:600;">' +
                esc(fmtDateM(p.data_orcamento)) +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Canal</span><div style="font-weight:600;">' +
                esc(p.canal_atendimento || '—') +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Cliente / Paciente</span><div style="font-weight:600;">' +
                esc(p.cliente || p.paciente || '—') +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Prescritor</span><div style="font-weight:600;">' +
                esc(p.prescritor || '—') +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Status</span><div style="font-weight:600;">' +
                esc(p.status_financeiro || '—') +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Convênio</span><div style="font-weight:600;">' +
                esc(p.convenio || '—') +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Usuário (Orçamento)</span><div style="font-weight:600;">' +
                esc(p.orcamentista || '—') +
                '</div></div>';
            fieldsHtml +=
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Usuário (Aprovação)</span><div style="font-weight:600;">' +
                esc(p.aprovador || '—') +
                '</div></div>';
            if (p.total_gestao != null) {
                fieldsHtml +=
                    '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Total (gestão)</span><div style="font-weight:600;color:var(--success);">' +
                    fmtMoneyM(p.total_gestao) +
                    '</div></div>';
            }
            const fieldsEl = document.getElementById('gcModalDetalhePedidoFields');
            if (fieldsEl) fieldsEl.innerHTML = fieldsHtml;
            const wrapGestao = document.getElementById('gcModalDetalhePedidoItensGestaoWrap');
            const tbodyGestao = document.getElementById('gcModalDetalhePedidoItensGestao');
            if (itensGestao.length > 0 && wrapGestao && tbodyGestao) {
                wrapGestao.style.display = 'block';
                let rows = '';
                itensGestao.forEach(function (it, i) {
                    rows +=
                        '<tr><td>' +
                        (i + 1) +
                        '</td><td>' +
                        esc(it.produto) +
                        '</td><td>' +
                        esc(it.forma_farmaceutica) +
                        '</td><td style="text-align:right;">' +
                        esc(it.quantidade) +
                        '</td><td style="text-align:right;">' +
                        fmtMoneyM(it.preco_liquido) +
                        '</td></tr>';
                });
                tbodyGestao.innerHTML = rows;
            } else if (wrapGestao) wrapGestao.style.display = 'none';
            const wrapOrc = document.getElementById('gcModalDetalhePedidoItensOrcamentoWrap');
            const tbodyOrc = document.getElementById('gcModalDetalhePedidoItensOrcamento');
            if (itensOrcamento.length > 0 && wrapOrc && tbodyOrc) {
                wrapOrc.style.display = 'block';
                let rowsOrc = '';
                itensOrcamento.forEach(function (it, i) {
                    rowsOrc +=
                        '<tr><td>' +
                        (i + 1) +
                        '</td><td>' +
                        esc(it.descricao) +
                        '</td><td>' +
                        esc(it.canal) +
                        '</td><td style="text-align:right;">' +
                        esc(it.quantidade) +
                        '</td><td style="text-align:right;">' +
                        fmtMoneyM(it.valor_liquido) +
                        '</td><td>' +
                        esc(it.usuario_inclusao) +
                        '</td><td>' +
                        esc(it.usuario_aprovador) +
                        '</td></tr>';
                });
                tbodyOrc.innerHTML = rowsOrc;
            } else if (wrapOrc) wrapOrc.style.display = 'none';
            const tbodyC = document.getElementById('gcModalDetalhePedidoComponentes');
            const emptyComp = document.getElementById('gcModalDetalhePedidoComponentesEmpty');
            if (!componentes || componentes.length === 0) {
                if (tbodyC) tbodyC.innerHTML = '';
                if (emptyComp) emptyComp.style.display = 'block';
            } else {
                if (emptyComp) emptyComp.style.display = 'none';
                let ch = '';
                componentes.forEach(function (c, i) {
                    const qtdUn = esc(fmtQtdCalculada(c.qtd_calculada, c.unidade));
                    ch +=
                        '<tr><td>' +
                        (i + 1) +
                        '</td><td>' +
                        esc(c.descricao || c.componente) +
                        '</td><td style="text-align:right;">' +
                        qtdUn +
                        '</td></tr>';
                });
                if (tbodyC) tbodyC.innerHTML = ch;
            }
        } catch (e) {
            loading.style.display = 'none';
            if (errEl) {
                errEl.textContent = e.message || 'Erro ao carregar.';
                errEl.style.display = 'block';
            }
        }
    }

    function gcCloseModalDetalhePedidoGc() {
        const modal = document.getElementById('gcModalDetalhePedido');
        if (modal) {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    var gcComissaoTransferUiBound = false;
    var gcRevendaUiBound = false;

    function gcSetComissaoTransferMsg(text, kind) {
        const el = document.getElementById('gcComissaoTransferMsg');
        if (!el) return;
        const t = (text || '').trim();
        el.classList.remove('gc-ct-msg--error', 'gc-ct-msg--ok');
        if (!t) {
            el.textContent = '';
            el.hidden = true;
            return;
        }
        el.textContent = t;
        el.hidden = false;
        if (kind === 'error') el.classList.add('gc-ct-msg--error');
        else if (kind === 'ok') el.classList.add('gc-ct-msg--ok');
    }

    async function gcLoadComissaoTransferList() {
        const tbody = document.getElementById('gcComissaoTransferTbody');
        if (!tbody) return;
        tbody.innerHTML =
            '<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--text-secondary);">Carregando…</td></tr>';
        gcSetComissaoTransferMsg('');
        const stEl = document.getElementById('gcComissaoTransferFiltroStatus');
        const status = stEl ? stEl.value : 'pendente';
        const params = {};
        if (status && status !== 'todas') params.status = status;
        let data;
        try {
            data = await apiGet('gestao_comercial_comissao_transfer_lista', params);
        } catch (e) {
            data = { success: false };
        }
        if (!data || data.success !== true) {
            tbody.innerHTML =
                '<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--danger);">Não foi possível carregar.</td></tr>';
            gcSetComissaoTransferMsg((data && data.error) ? String(data.error) : 'Erro ao carregar a lista.', 'error');
            return;
        }
        const rows = Array.isArray(data.rows) ? data.rows : [];
        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--text-secondary);">Nenhuma solicitação.</td></tr>';
            return;
        }
        const escCt = function (x) {
            return String(x == null ? '' : x)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/"/g, '&quot;');
        };
        const escAttr = function (x) {
            return String(x == null ? '' : x)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/\n/g, ' ');
        };
        const fmtMoneyCt = function (v) {
            return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ',');
        };
        const statusBadge = function (raw) {
            const s = String(raw || '').trim().toLowerCase();
            const labels = {
                pendente: 'Pendente',
                aprovada: 'Aprovada',
                recusada: 'Recusada',
                cancelada: 'Cancelada'
            };
            const lab = labels[s] || raw || '—';
            const cls = labels[s] ? s.replace(/[^a-z]/g, '') || 'outro' : 'outro';
            return (
                '<span class="gc-ct-status gc-ct-status--' +
                escCt(cls) +
                '">' +
                escCt(lab) +
                '</span>'
            );
        };
        tbody.innerHTML = rows
            .map(function (r) {
                const id = r.id;
                const st = String(r.status || '');
                const pend = st === 'pendente';
                const ref =
                    r.ref_mes && r.ref_ano ? String(r.ref_mes) + '/' + String(r.ref_ano) : '—';
                const np = r.numero_pedido != null && r.numero_pedido !== '' ? parseInt(r.numero_pedido, 10) : 0;
                const sp = r.serie_pedido != null && r.serie_pedido !== '' ? parseInt(r.serie_pedido, 10) : null;
                const cellPed = np > 0 ? escCt(String(np)) : '—';
                const cellSer =
                    np > 0 ? escCt(sp != null && !isNaN(sp) ? String(sp) : '0') : '—';
                const btnVer = '<button type="button" class="gc-ct-icon-btn gc-ct-btn-ver" data-id="' + escCt(id) + '" title="Visualizar detalhes" aria-label="Visualizar"><i class="fas fa-eye"></i></button>';
                const btnDel = '<button type="button" class="gc-ct-icon-btn gc-ct-icon-btn--delete gc-ct-btn-del" data-id="' + escCt(id) + '" title="Excluir registro" aria-label="Excluir"><i class="fas fa-trash"></i></button>';
                const acoes = pend
                    ? '<div class="gc-ct-acoes-btns">' + btnVer +
                      '<button type="button" class="gc-ct-icon-btn gc-ct-icon-btn--approve gc-ct-btn-apr" data-id="' + escCt(id) + '" title="Aprovar" aria-label="Aprovar"><i class="fas fa-check"></i></button>' +
                      '<button type="button" class="gc-ct-icon-btn gc-ct-icon-btn--refuse gc-ct-btn-rec" data-id="' + escCt(id) + '" title="Recusar" aria-label="Recusar"><i class="fas fa-times"></i></button>' +
                      btnDel +
                      '</div>'
                    : '<div class="gc-ct-acoes-btns">' + btnVer + btnDel + '</div>';
                return (
                    '<tr><td class="gc-ct-td-id">' +
                    escCt(id) +
                    '</td><td>' +
                    escCt(r.solicitante_nome) +
                    '</td><td>' +
                    escCt(r.contraparte_nome) +
                    '</td><td class="gc-ct-td-valor">' +
                    escCt(fmtMoneyCt(r.valor)) +
                    '</td><td class="gc-ct-td-ref">' +
                    escCt(ref) +
                    '</td><td class="gc-ct-td-num">' +
                    cellPed +
                    '</td><td class="gc-ct-td-num">' +
                    cellSer +
                    '</td><td class="gc-ct-td-motivo">' +
                    escCt(r.motivo) +
                    '</td><td>' +
                    statusBadge(st) +
                    '</td><td class="gc-ct-td-date">' +
                    escCt(String(r.created_at || '').replace('T', ' ').slice(0, 16)) +
                    '</td><td class="gc-ct-td-acoes">' +
                    acoes +
                    '</td></tr>'
                );
            })
            .join('');
        tbody.querySelectorAll('.gc-ct-btn-apr').forEach(function (btn) {
            btn.addEventListener('click', function () {
                gcDecidirComissaoTransfer(btn.getAttribute('data-id'), 'aprovar');
            });
        });
        tbody.querySelectorAll('.gc-ct-btn-rec').forEach(function (btn) {
            btn.addEventListener('click', function () {
                gcDecidirComissaoTransfer(btn.getAttribute('data-id'), 'recusar');
            });
        });
        tbody.querySelectorAll('.gc-ct-btn-ver').forEach(function (btn) {
            const id = btn.getAttribute('data-id');
            const row = rows.find(function (r) { return String(r.id) === String(id); });
            btn.addEventListener('click', function () {
                gcAbrirModalComissaoTransfer(row);
            });
        });
        tbody.querySelectorAll('.gc-ct-btn-del').forEach(function (btn) {
            btn.addEventListener('click', function () {
                gcExcluirComissaoTransfer(btn.getAttribute('data-id'));
            });
        });
    }

    async function gcExcluirComissaoTransfer(id) {
        if (!id) return;
        const ok = await showConfirm({
            kind: 'danger',
            title: 'Excluir registro',
            message: 'Excluir permanentemente este registro do banco de dados? Esta ação não pode ser desfeita.',
            confirmText: 'Excluir',
            cancelText: 'Cancelar',
            destructive: true
        });
        if (!ok) return;
        const msgEl = document.getElementById('gcComissaoTransferMsg');
        const data = await apiPost('gestao_comercial_comissao_transfer_excluir', { id: parseInt(id, 10) });
        if (!data || data.success !== true) {
            showError((data && data.error) ? data.error : 'Falha ao excluir.');
            return;
        }
        showSuccess('Registro excluído com sucesso.');
        await gcLoadComissaoTransferList();
    }

    function gcAbrirModalComissaoTransfer(r) {
        if (!r) return;
        const backdrop = document.getElementById('gcCtModalBackdrop');
        const body = document.getElementById('gcCtModalBody');
        const footer = document.getElementById('gcCtModalFooter');
        const closeBtn = document.getElementById('gcCtModalClose');
        if (!backdrop || !body || !footer) return;
        const esc = function (s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
        const fmt = function (v) { return 'R$ ' + (parseFloat(v)||0).toFixed(2).replace('.',','); };
        const ref = (r.ref_mes && r.ref_ano) ? String(r.ref_mes).padStart(2,'0') + '/' + r.ref_ano : '—';
        const np = r.numero_pedido ? parseInt(r.numero_pedido,10) : 0;
        const pedido = np > 0 ? String(np) + ' / ' + (r.serie_pedido != null ? String(r.serie_pedido) : '0') : '—';
        const st = String(r.status||'').toLowerCase();
        const stLabels = {pendente:'Pendente',aprovada:'Aprovada',recusada:'Recusada',cancelada:'Cancelada'};
        const stLabel = stLabels[st] || r.status || '—';
        body.innerHTML =
            '<dl class="gc-ct-modal-dl">' +
            '<div class="gc-ct-modal-dl__row"><dt>Destino (crédito)</dt><dd>' + esc(r.solicitante_nome) + '</dd></div>' +
            '<div class="gc-ct-modal-dl__row"><dt>Origem (débito)</dt><dd>' + esc(r.contraparte_nome) + '</dd></div>' +
            '<div class="gc-ct-modal-dl__row"><dt>Valor</dt><dd>' + esc(fmt(r.valor)) + '</dd></div>' +
            '<div class="gc-ct-modal-dl__row"><dt>Pedido / série</dt><dd>' + esc(pedido) + '</dd></div>' +
            '<div class="gc-ct-modal-dl__row"><dt>Referência</dt><dd>' + esc(ref) + '</dd></div>' +
            '<div class="gc-ct-modal-dl__row"><dt>Status</dt><dd><span class="gc-ct-status gc-ct-status--' + esc(st) + '">' + esc(stLabel) + '</span></dd></div>' +
            '<div class="gc-ct-modal-dl__row gc-ct-modal-dl__row--full"><dt>Motivo</dt><dd>' + esc(r.motivo || '—') + '</dd></div>' +
            (r.observacao_gestao ? '<div class="gc-ct-modal-dl__row gc-ct-modal-dl__row--full"><dt>Observação da gestão</dt><dd>' + esc(r.observacao_gestao) + '</dd></div>' : '') +
            (r.decidido_por_nome ? '<div class="gc-ct-modal-dl__row"><dt>Decidido por</dt><dd>' + esc(r.decidido_por_nome) + '</dd></div>' : '') +
            (r.decidido_em ? '<div class="gc-ct-modal-dl__row"><dt>Data da decisão</dt><dd>' + esc(String(r.decidido_em).slice(0,16).replace('T',' ')) + '</dd></div>' : '') +
            '<div class="gc-ct-modal-dl__row"><dt>Criado em</dt><dd>' + esc(String(r.created_at||'').slice(0,16).replace('T',' ')) + '</dd></div>' +
            '</dl>';
        const pend = st === 'pendente';
        footer.innerHTML = pend
            ? '<button type="button" class="gc-filter-btn gc-ct-modal-btn-apr" data-id="' + esc(r.id) + '"><i class="fas fa-check"></i> Aprovar</button>' +
              '<button type="button" class="gc-filter-btn gc-filter-btn--secondary gc-ct-modal-btn-rec" data-id="' + esc(r.id) + '"><i class="fas fa-times"></i> Recusar</button>' +
              '<button type="button" class="gc-filter-btn gc-filter-btn--secondary" id="gcCtModalCloseFooter">Fechar</button>'
            : '<button type="button" class="gc-filter-btn gc-filter-btn--secondary" id="gcCtModalCloseFooter">Fechar</button>';
        const closeModal = function () {
            backdrop.classList.remove('gc-ct-modal-backdrop--open');
            backdrop.setAttribute('aria-hidden','true');
        };
        closeBtn.onclick = closeModal;
        const closeFooter = footer.querySelector('#gcCtModalCloseFooter');
        if (closeFooter) closeFooter.onclick = closeModal;
        backdrop.onclick = function (e) { if (e.target === backdrop) closeModal(); };
        const aprBtn = footer.querySelector('.gc-ct-modal-btn-apr');
        if (aprBtn) aprBtn.addEventListener('click', function () { closeModal(); gcDecidirComissaoTransfer(aprBtn.getAttribute('data-id'), 'aprovar'); });
        const recBtn = footer.querySelector('.gc-ct-modal-btn-rec');
        if (recBtn) recBtn.addEventListener('click', function () { closeModal(); gcDecidirComissaoTransfer(recBtn.getAttribute('data-id'), 'recusar'); });
        backdrop.classList.add('gc-ct-modal-backdrop--open');
        backdrop.setAttribute('aria-hidden','false');
    }

    async function gcDecidirComissaoTransfer(id, decisao) {
        const isAprovar = decisao === 'aprovar';
        const ok = await showConfirm({
            kind: isAprovar ? 'info' : 'danger',
            title: isAprovar ? 'Aprovar transferência' : 'Recusar transferência',
            message: isAprovar
                ? 'Confirmar aprovação desta solicitação de transferência de comissão?'
                : 'Confirmar recusa desta solicitação de transferência de comissão?',
            confirmText: isAprovar ? 'Aprovar' : 'Recusar',
            cancelText: 'Cancelar',
            destructive: !isAprovar
        });
        if (!ok) return;
        const data = await apiPost('gestao_comercial_comissao_transfer_decidir', {
            id: parseInt(id, 10),
            decisao: decisao,
            observacao_gestao: ''
        });
        if (!data || data.success !== true) {
            showError(data && data.error ? String(data.error) : 'Falha ao registrar decisão.');
            return;
        }
        await gcLoadComissaoTransferList();
        showSuccess(isAprovar ? 'Transferência aprovada.' : 'Transferência recusada.');
    }

    function gcEnsureComissaoTransferUi() {
        if (gcComissaoTransferUiBound) return;
        gcComissaoTransferUiBound = true;
        const b = document.getElementById('gcComissaoTransferAtualizarBtn');
        if (b)
            b.addEventListener('click', function () {
                gcLoadComissaoTransferList().catch(function () {});
            });
        const f = document.getElementById('gcComissaoTransferFiltroStatus');
        if (f)
            f.addEventListener('change', function () {
                gcLoadComissaoTransferList().catch(function () {});
            });
    }

    function gcDashboardDateParams() {
        const deEl = document.getElementById('gcDataDe');
        const ateEl = document.getElementById('gcDataAte');
        const params = {};
        if (deEl && deEl.value) params.data_de = deEl.value;
        if (ateEl && ateEl.value) params.data_ate = ateEl.value;
        return params;
    }

    function gcIsRevendaTabActive() {
        const s = document.getElementById('gc-section-revenda');
        return !!(s && s.classList.contains('active'));
    }

    function gcSetRevendaMsg(text, kind) {
        const el = document.getElementById('gcRevendaMsg');
        if (!el) return;
        const t = (text || '').trim();
        el.classList.remove('gc-ct-msg--error', 'gc-ct-msg--ok');
        if (!t) {
            el.textContent = '';
            el.hidden = true;
            return;
        }
        el.textContent = t;
        el.hidden = false;
        if (kind === 'error') el.classList.add('gc-ct-msg--error');
        else if (kind === 'ok') el.classList.add('gc-ct-msg--ok');
    }

    function gcFillRevendaVendedoraSelect() {
        const sel = document.getElementById('gcRevendaVendedora');
        if (!sel) return;
        const nomes = Array.isArray(gcNomesVendedores) ? gcNomesVendedores.slice() : [];
        const prev = sel.value;
        sel.innerHTML = '<option value="">Selecione a consultora…</option>';
        nomes.forEach(function (n) {
            if (!n) return;
            const opt = document.createElement('option');
            opt.value = n;
            opt.textContent = n;
            sel.appendChild(opt);
        });
        if (prev && nomes.indexOf(prev) !== -1) sel.value = prev;
    }

    function gcParseValorRevendaInput(raw) {
        const s = String(raw || '').trim();
        if (!s) return NaN;
        const norm = s.replace(/\s/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
        const n = parseFloat(norm);
        return Number.isFinite(n) ? n : NaN;
    }

    async function gcLoadRevendaList() {
        const tbody = document.getElementById('gcRevendaTbody');
        if (!tbody) return;
        tbody.innerHTML =
            '<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--text-secondary);">Carregando…</td></tr>';
        gcSetRevendaMsg('');
        const filtroSt = (document.getElementById('gcRevendaFiltroStatus') || {}).value || 'pendente';
        const params = Object.assign({}, filtroSt && filtroSt !== 'todas' ? { status: filtroSt } : {});
        let data;
        try {
            data = await apiGet('gestao_comercial_revenda_lista', params);
        } catch (e) {
            data = { success: false };
        }
        if (!data || data.success !== true) {
            tbody.innerHTML =
                '<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--danger);">Não foi possível carregar.</td></tr>';
            gcSetRevendaMsg((data && data.error) ? String(data.error) : 'Erro ao carregar a lista.', 'error');
            return;
        }
        const rows = Array.isArray(data.rows) ? data.rows : [];
        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--text-secondary);">Nenhum lançamento encontrado.</td></tr>';
            return;
        }
        const esc = function (x) {
            return String(x == null ? '' : x)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/"/g, '&quot;');
        };
        const stBadge = {
            pendente:  '<span class="gc-ct-status gc-ct-status--pendente">Pendente</span>',
            aprovada:  '<span class="gc-ct-status gc-ct-status--aprovada">Aprovada</span>',
            recusada:  '<span class="gc-ct-status gc-ct-status--recusada">Recusada</span>',
            cancelada: '<span class="gc-ct-status gc-ct-status--cancelada">Cancelada</span>',
        };
        tbody.innerHTML = rows
            .map(function (r) {
                const stKey = String(r.status || 'pendente').toLowerCase();
                const st = stBadge[stKey] || stKey;
                const pend = stKey === 'pendente';
                const id = esc(String(r.id));
                const btnVer = '<button type="button" class="gc-ct-icon-btn gc-rev-ver" data-id="' + id + '" title="Visualizar detalhes"><i class="fas fa-eye"></i></button>';
                const acoes = pend
                    ? '<div class="gc-ct-acoes-btns">' +
                      btnVer +
                      '<button type="button" class="gc-ct-icon-btn gc-ct-icon-btn--approve gc-rev-apr" data-id="' + id + '" title="Aprovar"><i class="fas fa-check"></i></button>' +
                      '<button type="button" class="gc-ct-icon-btn gc-ct-icon-btn--refuse gc-rev-rec" data-id="' + id + '" title="Recusar"><i class="fas fa-times"></i></button>' +
                      '<button type="button" class="gc-ct-icon-btn gc-ct-icon-btn--delete gc-rev-del" data-id="' + id + '" title="Cancelar/Excluir"><i class="fas fa-trash"></i></button>' +
                      '</div>'
                    : '<div class="gc-ct-acoes-btns">' +
                      btnVer +
                      '<button type="button" class="gc-ct-icon-btn gc-ct-icon-btn--delete gc-rev-del" data-id="' + id + '" title="Cancelar/Excluir"><i class="fas fa-trash"></i></button>' +
                      '</div>';
                const obsGestao = r.observacao_gestao
                    ? '<span title="' + esc(r.observacao_gestao) + '" style="color:var(--text-secondary);font-size:0.8rem;cursor:help;">' +
                      '<i class="fas fa-comment-alt" style="margin-right:3px;"></i>' +
                      esc(String(r.observacao_gestao).slice(0, 40)) + (r.observacao_gestao.length > 40 ? '…' : '') +
                      '</span>'
                    : '—';
                return (
                    '<tr><td class="gc-ct-td-id">' + id +
                    '</td><td>' + esc(r.vendedor_nome) +
                    '</td><td class="gc-ct-td-date">' + esc(String(r.data_venda || '').slice(0, 10)) +
                    '</td><td class="gc-ct-td-id">' + esc(r.numero_pedido || '—') +
                    '</td><td class="gc-ct-td-id">' + esc(r.serie_pedido || '—') +
                    '</td><td style="max-width:200px;">' + esc(r.produto || '—') +
                    '</td><td class="gc-ct-td-valor">' + esc(formatMoney(r.valor_liquido)) +
                    '</td><td class="gc-ct-td-motivo">' + esc(r.descricao || '—') +
                    '</td><td>' + st +
                    '</td><td class="gc-ct-td-date">' + esc(String(r.created_at || '').replace('T', ' ').slice(0, 16)) +
                    '</td><td class="gc-ct-td-acoes">' + acoes +
                    '</td></tr>'
                );
            })
            .join('');
        tbody.querySelectorAll('.gc-rev-ver').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const id = parseInt(btn.getAttribute('data-id') || '0', 10);
                const r = rows.find(function (x) { return Number(x.id) === id; });
                if (!r) return;
                gcAbrirModalVerRevenda(r);
            });
        });
        tbody.querySelectorAll('.gc-rev-apr').forEach(function (btn) {
            btn.addEventListener('click', function () { gcDecidirRevenda(btn.getAttribute('data-id'), 'aprovar'); });
        });
        tbody.querySelectorAll('.gc-rev-rec').forEach(function (btn) {
            btn.addEventListener('click', function () { gcDecidirRevenda(btn.getAttribute('data-id'), 'recusar'); });
        });
        tbody.querySelectorAll('.gc-rev-del').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const id = parseInt(btn.getAttribute('data-id') || '0', 10);
                if (!id) return;
                const okRev = await showConfirm({ kind: 'danger', title: 'Cancelar revenda', message: 'Cancelar este lançamento de revenda?', confirmText: 'Cancelar', cancelText: 'Voltar', destructive: true });
                if (!okRev) return;
                const resp = await apiPost('gestao_comercial_revenda_cancelar', { id: id });
                if (!resp || resp.success !== true) {
                    showError((resp && resp.error) ? String(resp.error) : 'Não foi possível cancelar.');
                    return;
                }
                showSuccess('Lançamento cancelado.');
                await gcLoadRevendaList();
            });
        });
    }

    function gcAbrirModalVerRevenda(r) {
        const modal = document.getElementById('gcModalDetalhePedido');
        const loading = document.getElementById('gcModalDetalhePedidoLoading');
        const errEl = document.getElementById('gcModalDetalhePedidoError');
        const content = document.getElementById('gcModalDetalhePedidoContent');
        const titleEl = document.getElementById('gcModalDetalhePedidoTitle');
        if (!modal) return;
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-store" aria-hidden="true"></i> Revenda #' + String(r.id || '');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        if (loading) loading.style.display = 'none';
        if (errEl) errEl.style.display = 'none';
        if (content) content.style.display = 'block';
        const esc = function (x) { return String(x == null ? '' : x).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
        const stLabels = { pendente: 'Aguardando aprovação', aprovada: 'Aprovada', recusada: 'Recusada', cancelada: 'Cancelada' };
        const stKey = String(r.status || 'pendente').toLowerCase();
        const fieldsEl = document.getElementById('gcModalDetalhePedidoFields');
        if (fieldsEl) {
            fieldsEl.innerHTML =
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">ID</span><div style="font-weight:600;">' + esc(r.id) + '</div></div>' +
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Consultora</span><div style="font-weight:600;">' + esc(r.vendedor_nome) + '</div></div>' +
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Data da venda</span><div style="font-weight:600;">' + esc(String(r.data_venda||'').slice(0,10)) + '</div></div>' +
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Nº Pedido</span><div style="font-weight:600;">' + esc(r.numero_pedido||'—') + '</div></div>' +
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Série</span><div style="font-weight:600;">' + esc(r.serie_pedido||'—') + '</div></div>' +
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Valor</span><div style="font-weight:600;color:var(--success);">' + esc(formatMoney(r.valor_liquido)) + '</div></div>' +
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Status</span><div style="font-weight:600;">' + esc(stLabels[stKey]||stKey) + '</div></div>' +
                '<div><span style="font-size:0.7rem;color:var(--text-secondary);">Lançado em</span><div style="font-weight:600;">' + esc(String(r.created_at||'').replace('T',' ').slice(0,16)) + '</div></div>' +
                '<div style="grid-column:1/-1;"><span style="font-size:0.7rem;color:var(--text-secondary);">Produto</span><div style="font-weight:600;">' + esc(r.produto||'—') + '</div></div>' +
                '<div style="grid-column:1/-1;"><span style="font-size:0.7rem;color:var(--text-secondary);">Observações</span><div>' + esc(r.descricao||'—') + '</div></div>' +
                (r.observacao_gestao ? '<div style="grid-column:1/-1;"><span style="font-size:0.7rem;color:var(--text-secondary);">Observação da gestão</span><div style="color:var(--warning,#d97706);">' + esc(r.observacao_gestao) + '</div></div>' : '');
        }
        // Oculta seções de itens/componentes que não se aplicam à revenda
        ['gcModalDetalhePedidoItensGestaoWrap','gcModalDetalhePedidoItensOrcamentoWrap'].forEach(function(id) {
            var el = document.getElementById(id); if (el) el.style.display = 'none';
        });
        var compWrap = document.querySelector('.gc-modal-det-ped__tbl-wrap:last-of-type');
        if (compWrap) compWrap.style.display = 'none';
    }

    async function gcDecidirRevenda(id, decisao) {
        const isAprovar = decisao === 'aprovar';
        let obs = '';
        if (!isAprovar) {
            const motivo = window.prompt('Recusar revenda — informe o motivo (opcional):');
            if (motivo === null) return; // cancelou
            obs = motivo.trim();
        } else {
            const ok = await showConfirm({
                kind: 'info',
                title: 'Aprovar revenda',
                message: 'Aprovar este lançamento? O valor entrará na receita e comissão da consultora.',
                confirmText: 'Aprovar',
                cancelText: 'Cancelar'
            });
            if (!ok) return;
        }
        const payload = { id: parseInt(id, 10), decisao: decisao };
        if (obs) payload.observacao = obs;
        const data = await apiPost('gestao_comercial_revenda_decidir', payload);
        if (!data || data.success !== true) {
            showError(data && data.error ? String(data.error) : 'Falha ao registrar decisão.');
            return;
        }
        await gcLoadRevendaList();
        showSuccess(isAprovar ? 'Revenda aprovada.' : 'Revenda recusada.');
    }

    function gcEnsureRevendaUi() {
        if (gcRevendaUiBound) return;
        gcRevendaUiBound = true;
        const dataInp = document.getElementById('gcRevendaData');
        if (dataInp && !dataInp.value) {
            dataInp.value = new Date().toISOString().slice(0, 10);
        }
        const salvar = document.getElementById('gcRevendaSalvarBtn');
        if (salvar) {
            salvar.addEventListener('click', async function () {
                const nome = (document.getElementById('gcRevendaVendedora') || {}).value || '';
                const dv = (document.getElementById('gcRevendaData') || {}).value || '';
                const valorRaw = (document.getElementById('gcRevendaValor') || {}).value || '';
                const desc = (document.getElementById('gcRevendaDesc') || {}).value || '';
                const valor = gcParseValorRevendaInput(valorRaw);
                if (!nome) {
                    gcSetRevendaMsg('Selecione a consultora.', 'error');
                    return;
                }
                if (!/^\d{4}-\d{2}-\d{2}$/.test(dv)) {
                    gcSetRevendaMsg('Informe a data da venda.', 'error');
                    return;
                }
                if (!Number.isFinite(valor) || valor <= 0) {
                    gcSetRevendaMsg('Informe um valor válido.', 'error');
                    return;
                }
                gcSetRevendaMsg('');
                const resp = await apiPost('gestao_comercial_revenda_salvar', {
                    vendedor_nome: nome,
                    data_venda: dv,
                    valor_liquido: valor,
                    descricao: desc
                });
                if (!resp || resp.success !== true) {
                    gcSetRevendaMsg((resp && resp.error) ? String(resp.error) : 'Não foi possível salvar.', 'error');
                    return;
                }
                gcSetRevendaMsg('Lançamento salvo.', 'ok');
                const vInp = document.getElementById('gcRevendaValor');
                if (vInp) vInp.value = '';
                const dInp = document.getElementById('gcRevendaDesc');
                if (dInp) dInp.value = '';
                await gcLoadRevendaList();
            });
        }
        const att = document.getElementById('gcRevendaAtualizarBtn');
        if (att) {
            att.addEventListener('click', function () {
                gcLoadRevendaList().catch(function () {});
            });
        }
        const filtro = document.getElementById('gcRevendaFiltroStatus');
        if (filtro) {
            filtro.addEventListener('change', function () {
                gcLoadRevendaList().catch(function () {});
            });
        }
    }

    function gcEnsurePedidosRelatorioUiBind() {
        if (gcPedidosRelUiBound) return;
        gcPedidosRelUiBound = true;
        window.gcTogglePedidosRelSeries = gcTogglePedidosRelSeries;
        window.gcPedidosRelGoTo = gcPedidosRelGoTo;
        window.gcSortPedidosRelBy = gcSortPedidosRelBy;
        window.gcOpenModalDetalhePedidoGc = gcOpenModalDetalhePedidoGc;
        window.gcCloseModalDetalhePedidoGc = gcCloseModalDetalhePedidoGc;
        const buscar = document.getElementById('gcPedidosRelBuscarListaBtn');
        if (buscar) {
            buscar.addEventListener('click', function () {
                gcLoadPedidosRelatorioVisitadorStyle().catch(function () {});
            });
        }
        const printBtn = document.getElementById('gcPedidosRelPrintBtn');
        if (printBtn) printBtn.addEventListener('click', gcPrintPedidosRelFiltrados);
        ['gcPedidosRelDataDe', 'gcPedidosRelDataAte', 'gcPedidosRelFiltroStatus'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', function () {
                __gcPedidosPage = 1;
                gcRenderPedidosRelatorioTabela();
            });
        });
        ['gcPedidosRelVisitador', 'gcPedidosRelPrescritor', 'gcPedidosRelVendedor'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', function () {
                __gcPedidosPage = 1;
                if (__gcPedidosList.length) gcRenderPedidosRelatorioTabela();
            });
        });
        const searchEl = document.getElementById('gcPedidosRelSearch');
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                __gcPedidosPage = 1;
                gcRenderPedidosRelatorioTabela();
            });
        }
        const tbl = document.getElementById('gcPedidosRelTable');
        if (tbl && !tbl.dataset.gcPedSortBound) {
            tbl.dataset.gcPedSortBound = '1';
            tbl.querySelectorAll('thead .gc-pedidos-vd-th-sort').forEach(function (th) {
                th.addEventListener('click', function () {
                    gcSortPedidosRelBy(th.getAttribute('data-sort-col') || '');
                });
            });
        }
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
        const okErr = await showConfirm({ kind: 'danger', title: 'Excluir erro', message: 'Deseja excluir este registro de erro?', confirmText: 'Excluir', cancelText: 'Cancelar', destructive: true });
        if (!okErr) return;
        const resp = await apiPost('gestao_comercial_erros_excluir', { id: n });
        if (!resp || resp.success === false) {
            showError((resp && resp.error) ? resp.error : 'Não foi possível excluir o erro.');
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
            { id: 'gcVendEquipeTbody', cols: 12, emptyText: 'Sem dados no período selecionado.' },
            { id: 'gcRelSemanalTbody', cols: 10, emptyText: 'Sem dados no período selecionado.' },
            { id: 'gcRelMensalTbody', cols: 15, emptyText: 'Sem dados no período selecionado.' },
            { id: 'gcRelComissaoIndTbody', cols: 5, emptyText: 'Sem regras cadastradas.' },
            { id: 'gcRelComissaoGrupoTbody', cols: 4, emptyText: 'Sem regras cadastradas.' },
            { id: 'gcRelComissaoPremioTbody', cols: 6, emptyText: 'Sem regras cadastradas.' },
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
            gcFillRevendaVendedoraSelect();
            if (gcIsRevendaTabActive()) {
                gcLoadRevendaList().catch(function () {});
            }
            renderExecutivo(data);
            renderGcCharts(data);
            renderVendedoresTabs(data, gcNomesVendedores);
            renderVendedores(data);
            renderRelatorios(data);
        } finally {
            setGcLoading(false);
            if (gcIsRelatoriosTabActive()) {
                gcLoadPedidosRelatorioVisitadorStyle().catch(function () {});
            }
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
        var vgResumo = data?.vendedor_gestao?.resumo || {};
        var map = {
            gcCrescReceitaMes: fmtVal(c.receita_mes, 'money'),
            gcCrescCrescimentoMesAnt: c.crescimento_vs_mes_anterior != null ? fmtVal(c.crescimento_vs_mes_anterior, 'percent') : '—',
            gcExecClientesAtendidos: fmtVal(vgResumo.clientes_atendidos, 'int'),
            gcCrescTicketMedio: fmtVal(c.ticket_medio, 'money'),
            gcCrescNumVendas: fmtVal(c.numero_vendas, 'int'),
            gcCrescClientesAtivos6m: fmtVal(c.numero_clientes_ativos_6_meses, 'int'),
            gcEficConversao: e.conversao_geral != null ? fmtVal(e.conversao_geral, 'percent') : '—',
            gcRentMargemBruta: r.margem_bruta != null ? fmtVal(r.margem_bruta, 'percent') : '—',
            gcEficLeads: 'Clientes atendidos no período: ' + fmtVal(vgResumo.clientes_atendidos, 'int'),
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
            gcEficQuickLeads: fmtVal(vgResumo.clientes_atendidos, 'int'),
            gcEficQuickVendas: fmtVal(c.numero_vendas, 'int'),
            gcEficQuickTempo: (e.tempo_medio_fechamento_horas != null && e.tempo_medio_fechamento_horas !== '')
                ? (Number(e.tempo_medio_fechamento_horas).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' h')
                : '—',
            gcFidelQuickLucro: fmtVal(r.lucro_operacional, 'money'),
            gcFidelQuickVendas: fmtVal(c.receita_mes, 'money'),
            gcFidelQuickRecompra: f.taxa_recompra != null ? fmtVal(f.taxa_recompra, 'percent') : '—',
            gcFidelQuickBase: fmtVal(f.base_ativa_90_dias, 'int')
        };
        Object.keys(quickMap).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.textContent = quickMap[id];
        });
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

        /* —— Executivo: visão de gestão comercial —— */
        var c = data?.crescimento || {};
        var r = data?.rentabilidade || {};
        var e = data?.eficiencia_comercial || {};
        var f = data?.fidelizacao || {};
        var fun = data?.funil_comercial || {};
        var fin = data?.financeiro_aplicado || {};
        var vgResumo = data?.vendedor_gestao?.resumo || {};
        var equipeExec = Array.isArray(data?.performance_vendedor) ? data.performance_vendedor.slice() : [];
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

        /* —— CMV por forma farmacêutica —— */
        var cmvFormaRaw = Array.isArray(r?.margem_contribuicao_por?.forma_farmaceutica)
            ? r.margem_contribuicao_por.forma_farmaceutica.slice()
            : [];
        cmvFormaRaw.sort(function (a, b) {
            return Number(b?.custo || 0) - Number(a?.custo || 0);
        });
        var cmvForma = cmvFormaRaw.slice(0, 8);
        gcEnsureChart('execCmvForma', 'gcChartExecCmvForma', function () {
            var hasCmvForma = cmvForma.length > 0;
            return {
                type: 'bar',
                data: {
                    labels: hasCmvForma
                        ? cmvForma.map(function (x) { return String(x.nome || 'Sem forma').slice(0, 28); })
                        : ['Sem dados'],
                    datasets: [
                        {
                            label: 'Receita',
                            data: hasCmvForma
                                ? cmvForma.map(function (x) { return Number(x.receita || 0); })
                                : [0],
                            backgroundColor: col.primary,
                            borderRadius: 6
                        },
                        {
                            label: 'CMV',
                            data: hasCmvForma
                                ? cmvForma.map(function (x) { return Number(x.custo || 0); })
                                : [0],
                            backgroundColor: col.warning,
                            borderRadius: 6
                        },
                        {
                            label: 'Lucro',
                            data: hasCmvForma
                                ? cmvForma.map(function (x) {
                                    var receitaForma = Number(x.receita || 0);
                                    var custoForma = Number(x.custo || 0);
                                    return receitaForma - custoForma;
                                })
                                : [0],
                            backgroundColor: col.success,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { color: col.muted, boxWidth: 12, font: { size: 10 } } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var idx = ctx.dataIndex;
                                    var row = cmvForma[idx] || {};
                                    var receitaForma = Number(row.receita || 0);
                                    var cmvPct = receitaForma > 0 ? (Number(row.custo || 0) / receitaForma) * 100 : 0;
                                    var ds = String(ctx.dataset.label || '');
                                    if (ds === 'CMV') {
                                        return 'CMV: ' + formatMoney(ctx.parsed.x) + ' · ' + cmvPct.toFixed(1).replace('.', ',') + '% da receita da forma';
                                    }
                                    return ds + ': ' + formatMoney(ctx.parsed.x);
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
                        y: { ticks: { color: col.muted, font: { size: 11 } }, grid: { display: false } }
                    }
                }
            };
        });

        /* —— Canais (executivo) —— */
        var canais = Array.isArray(data?.performance_canal) ? data.performance_canal.slice(0, 8) : [];
        var totalCanaisExec = canais.reduce(function (acc, row) {
            return acc + Number(row && row.receita ? row.receita : 0);
        }, 0);
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
                                label: function (ctx) {
                                    var val = Number(ctx.parsed.x || 0);
                                    var pct = totalCanaisExec > 0 ? (val / totalCanaisExec) * 100 : 0;
                                    return formatMoney(val) + ' · ' + pct.toFixed(1).replace('.', ',') + '% do total';
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
                        y: { ticks: { color: col.muted, font: { size: 11 } }, grid: { display: false } }
                    }
                }
            };
        });

        /* —— Base de clientes e atendimento —— */
        var rawMapExec = fun.etapas_raw || {};
        function etapaCountExec(terms) {
            var total = 0;
            Object.keys(rawMapExec || {}).forEach(function (k) {
                var n = String(k || '').toLowerCase();
                for (var i = 0; i < terms.length; i++) {
                    if (n.indexOf(terms[i]) !== -1) {
                        total += Number(rawMapExec[k] || 0);
                        break;
                    }
                }
            });
            return total;
        }
        var noCarrinhoExec = etapaCountExec(['carrinho']);
        var perdidosExec = etapaCountExec(['perdid', 'recus']);
        gcEnsureChart('execEfic', 'gcChartExecEfic', function () {
            return {
                type: 'bar',
                data: {
                    labels: ['Clientes atendidos', 'Clientes ativos 6m', 'No carrinho', 'Perdidos', 'Pedidos aprovados'],
                    datasets: [{
                        data: [
                            Number(vgResumo.clientes_atendidos || 0),
                            Number(c.numero_clientes_ativos_6_meses || 0),
                            Number(noCarrinhoExec || 0),
                            Number(perdidosExec || 0),
                            Number(c.numero_vendas || 0)
                        ],
                        backgroundColor: [col.primary, col.cyan, '#F59E0B', '#EF4444', col.success],
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

        /* —— Produtividade comercial (top consultoras por receita) —— */
        equipeExec.sort(function (a, b) {
            return Number(b?.receita || 0) - Number(a?.receita || 0);
        });
        var topEquipe = equipeExec.slice(0, 8);
        var fidelWrap = document.getElementById('gcExecFidelChartWrap');
        var fidelEmpty = document.getElementById('gcExecFidelEmpty');
        if (!topEquipe.length) {
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
                        labels: topEquipe.map(function (x) { return String(x.atendente || 'Sem nome').slice(0, 22); }),
                        datasets: [{
                            label: 'Receita',
                            data: topEquipe.map(function (x) { return Number(x.receita || 0); }),
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
                                        var idx = ctx.dataIndex;
                                        var row = topEquipe[idx] || {};
                                        var conv = row.conversao_individual != null
                                            ? Number(row.conversao_individual).toFixed(1).replace('.', ',') + '%'
                                            : '—';
                                        var clientes = Number(row.clientes_atendidos || 0).toLocaleString('pt-BR');
                                        return formatMoney(ctx.parsed.x) + ' · Conversão ' + conv + ' · Clientes ' + clientes;
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
        var fun = data?.funil_comercial || {};
        var intel = data?.inteligencia_comercial || {};
        var canaisFull = Array.isArray(data?.performance_canal) ? data.performance_canal : [];

        /* —— Vendas: macro funil + gargalo —— */
        var gargaloEl = document.getElementById('gcVendasGargalo');
        if (gargaloEl) {
            gargaloEl.textContent = 'Gargalo estimado (menor conversão entre etapas): ' + gargaloFunilLabel(fun.gargalo_funil);
        }
        var rawMap = fun.etapas_raw || {};
        function etapaCountByTerms(mapObj, terms) {
            var total = 0;
            Object.keys(mapObj || {}).forEach(function (k) {
                var n = String(k || '').toLowerCase();
                for (var i = 0; i < terms.length; i++) {
                    if (n.indexOf(terms[i]) !== -1) {
                        total += Number(mapObj[k] || 0);
                        break;
                    }
                }
            });
            return total;
        }
        var noCarrinho = etapaCountByTerms(rawMap, ['carrinho']);
        var perdidas = etapaCountByTerms(rawMap, ['recus', 'perdid']);
        var vendas = Number(fun.vendas_fechadas || 0);
        var atendidos = Number(noCarrinho || 0) + Number(perdidas || 0) + vendas;
        gcEnsureChart('vendasMacro', 'gcChartVendasFunilMacro', function () {
            return {
                type: 'bar',
                data: {
                    labels: ['Atendidos', 'No carrinho', 'Perdidas', 'Vendas'],
                    datasets: [{
                        data: [
                            Number(atendidos || 0),
                            Number(noCarrinho || 0),
                            Number(perdidas || 0),
                            vendas
                        ],
                        backgroundColor: [col.primary, '#F59E0B', '#EF4444', col.success],
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

        var etapaKeys = Object.keys(rawMap);
        var etapaLabels = etapaKeys.map(function (k) { return k.length > 24 ? k.slice(0, 22) + '…' : k; });
        var etapaVals = etapaKeys.map(function (k) { return Number(rawMap[k] || 0); });
        if (!etapaKeys.length) {
            etapaLabels = ['Sem dados'];
            etapaVals = [0];
        }
        var etapaTotal = etapaVals.reduce(function (acc, v) { return acc + (Number(v) || 0); }, 0);
        var etapaLabelsComPct = etapaLabels.map(function (lbl, i) {
            if (etapaTotal <= 0) return lbl;
            var pct = ((Number(etapaVals[i] || 0) / etapaTotal) * 100);
            return lbl + ' (' + pct.toFixed(1).replace('.', ',') + '%)';
        });
        gcEnsureChart('vendasEtapas', 'gcChartVendasEtapas', function () {
            return {
                type: 'doughnut',
                data: {
                    labels: etapaLabelsComPct,
                    datasets: [{
                        data: etapaVals,
                        backgroundColor: etapaLabels.map(function (lbl, i) {
                            var n = String(lbl || '').toLowerCase();
                            if (n.indexOf('carrinho') !== -1) return '#F59E0B';
                            if (n.indexOf('recus') !== -1) return '#EF4444';
                            if (n.indexOf('aprov') !== -1) return '#2563EB';
                            return col.palette[i % col.palette.length];
                        }),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { color: col.muted, boxWidth: 12, font: { size: 10 } } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var value = Number(ctx.parsed || 0);
                                    var pct = etapaTotal > 0 ? ((value / etapaTotal) * 100) : 0;
                                    return (ctx.label || '') + ' · ' + value.toLocaleString('pt-BR') + ' (' + pct.toFixed(1).replace('.', ',') + '%)';
                                }
                            }
                        }
                    }
                }
            };
        });

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

        var topClientes = Array.isArray(intel.top_clientes_receita) ? intel.top_clientes_receita.slice(0, 20) : [];
        gcEnsureChart('vendasTopQtd', 'gcChartVendasTopProdQtd', function () {
            return {
                type: 'bar',
                data: {
                    labels: topClientes.length ? topClientes.map(function (x) { return String(x.cliente || '').slice(0, 28); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Receita',
                        data: topClientes.length ? topClientes.map(function (x) { return Number(x.receita || 0); }) : [0],
                        backgroundColor: col.cyan,
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
                            beginAtZero: true,
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

        var topP = Array.isArray(intel.prescritores_maior_receita) ? intel.prescritores_maior_receita.slice(0, 20) : [];
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

        var ticketCli = Array.isArray(intel.ticket_medio_por_cliente) ? intel.ticket_medio_por_cliente.slice(0, 20) : [];
        gcEnsureChart('vendasMargem', 'gcChartVendasMargemProd', function () {
            return {
                type: 'bar',
                data: {
                    labels: ticketCli.length ? ticketCli.map(function (x) { return String(x.cliente || '').slice(0, 28); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Ticket médio',
                        data: ticketCli.length ? ticketCli.map(function (x) { return Number(x.ticket_medio || 0); }) : [0],
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

        var ticketPresc = Array.isArray(intel.ticket_medio_por_prescritor)
            ? intel.ticket_medio_por_prescritor.slice(0, 20)
            : (Array.isArray(intel.ticket_medio_por_especialidade) ? intel.ticket_medio_por_especialidade.slice(0, 20) : []);
        gcEnsureChart('vendasEsp', 'gcChartVendasEspecialidade', function () {
            return {
                type: 'bar',
                data: {
                    labels: ticketPresc.length ? ticketPresc.map(function (x) { return String(x.prescritor || x.especialidade || '').slice(0, 28); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Ticket médio',
                        data: ticketPresc.length ? ticketPresc.map(function (x) { return Number(x.ticket_medio || 0); }) : [0],
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

        var clientesAnalise = intel.clientes_analise || {};
        var mix = clientesAnalise.mix_novos_recorrentes || {};
        var mixNovos = Number(mix.novos || 0);
        var mixRecorrentes = Number(mix.recorrentes || 0);
        var mixReceitaNovos = Number(mix.receita_novos || 0);
        var mixReceitaRecorrentes = Number(mix.receita_recorrentes || 0);
        var mixSub = document.getElementById('gcClientesMixSub');
        if (mixSub) {
            mixSub.textContent = 'Novos: ' + mixNovos.toLocaleString('pt-BR') + ' | Recorrentes: ' + mixRecorrentes.toLocaleString('pt-BR');
        }
        gcEnsureChart('vendasClientesMix', 'gcChartVendasClientesMix', function () {
            return {
                type: 'doughnut',
                data: {
                    labels: ['Novos (1 compra)', 'Recorrentes (2+ compras)'],
                    datasets: [{
                        data: [mixNovos, mixRecorrentes],
                        backgroundColor: [col.warning, col.success],
                        borderColor: 'transparent'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: col.muted } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var idx = ctx.dataIndex;
                                    var qtd = Number(ctx.parsed || 0).toLocaleString('pt-BR');
                                    var receita = idx === 0 ? mixReceitaNovos : mixReceitaRecorrentes;
                                    return qtd + ' clientes · Receita ' + formatMoney(receita);
                                }
                            }
                        }
                    }
                }
            };
        });

        var faixaTicket = Array.isArray(clientesAnalise.faixa_ticket) ? clientesAnalise.faixa_ticket : [];
        gcEnsureChart('vendasClientesFaixaTicket', 'gcChartVendasClientesFaixaTicket', function () {
            return {
                type: 'bar',
                data: {
                    labels: faixaTicket.length ? faixaTicket.map(function (x) { return String(x.faixa || 'Faixa'); }) : ['Sem dados'],
                    datasets: [{
                        label: 'Clientes',
                        data: faixaTicket.length ? faixaTicket.map(function (x) { return Number(x.clientes || 0); }) : [0],
                        backgroundColor: col.primary,
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
                                    var idx = ctx.dataIndex;
                                    var row = faixaTicket[idx] || {};
                                    var qtd = Number(row.clientes || 0).toLocaleString('pt-BR');
                                    return qtd + ' clientes · Receita ' + formatMoney(Number(row.receita || 0));
                                }
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: col.muted }, grid: { display: false } },
                        y: Object.assign({}, commonAxis, {
                            beginAtZero: true,
                            ticks: { color: col.muted, precision: 0 }
                        })
                    }
                }
            };
        });

        var cmp = intel.comparativo_vendas_dia_a_dia || { labels: [], series: [], meta: {} };
        gcSetupComparativoVendasMesMes(cmp, col, commonAxis);
        var cmpPed = intel.comparativo_pedidos_dia_a_dia || { labels: [], series: [], meta: {} };
        gcSetupComparativoPedidosMesMes(cmpPed, col, commonAxis);
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
                    onClick: function (evt, elements) {
                        if (!elements || !elements.length) return;
                        var idx = elements[0].index;
                        if (typeof idx !== 'number' || idx < 0 || idx >= equipe.length) return;
                        var nome = String((equipe[idx] && equipe[idx].atendente) || '').trim();
                        gcOpenVendedorPage(nome);
                    },
                    onHover: function (evt, elements) {
                        var canvas = evt && evt.native ? evt.native.target : null;
                        if (canvas && canvas.style) {
                            canvas.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
                        }
                    },
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

        var anoAtual = new Date().getFullYear();
        var evo = bloco.evolucao_mensal_vendedores || { meses: [], series: [] };
        var evoSub = document.getElementById('gcVendEvolucaoSub');
        if (evoSub) {
            var yFix = typeof evo.ano_fixo === 'number' ? evo.ano_fixo : parseInt(evo.ano_fixo, 10);
            if (!yFix || isNaN(yFix)) yFix = anoAtual;
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
            if (!yA || isNaN(yA)) yA = anoAtual;
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
            if (!yR || isNaN(yR)) yR = anoAtual;
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
                try {
                    localStorage.setItem(GC_ACTIVE_TAB_KEY, String(target || 'vendedores'));
                } catch (e) { /* ignore */ }
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
                // Update page title based on selected section
                const titleEl = document.getElementById('gcPageTitle');
                if (titleEl) {
                    const span = tab.querySelector('span');
                    if (span) titleEl.textContent = span.textContent;
                }
                const section = document.getElementById(`gc-section-${target}`);
                if (section) {
                    section.classList.add('active');
                    section.setAttribute('aria-hidden', 'false');
                }
                if (target === 'vendas') {
                    requestAnimationFrame(function () {
                        ensureGcBackgroundCharts();
                    });
                }
                if (target === 'vendedores') {
                    requestAnimationFrame(function () {
                        if (gcDashboardData) renderVendedorTabCharts(gcDashboardData);
                    });
                }
                if (target === 'relatorios') {
                    gcEnsurePedidosRelatorioUiBind();
                    gcLoadPedidosRelatorioVisitadorStyle().catch(function () {});
                }
                if (target === 'comissoes') {
                    gcEnsureComissaoTransferUi();
                    gcEnsureRevendaUi();
                    gcLoadComissaoTransferList().catch(function () {});
                    gcLoadRevendaList().catch(function () {});
                }
                if (target === 'revenda') {
                    gcEnsureRevendaUi();
                    gcFillRevendaVendedoraSelect();
                    gcLoadRevendaList().catch(function () {});
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
        if (!tab) {
            try {
                tab = String(localStorage.getItem(GC_ACTIVE_TAB_KEY) || '').toLowerCase().trim();
            } catch (e) {
                tab = '';
            }
        }
        const valid = ['executivo', 'vendas', 'vendedores', 'relatorios', 'comissoes', 'revenda', 'erros'];
        if (valid.indexOf(tab) === -1 || tab === 'erros') {
            return;
        }
        if (tab === 'vendedores') {
            try {
                localStorage.setItem(GC_ACTIVE_TAB_KEY, 'vendedores');
            } catch (e) { /* ignore */ }
            return;
        }
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

        gcEnsurePedidosRelatorioUiBind();

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

        // Mobile menu handler
        const gcMobileMenuBtn = document.getElementById('gcMobileMenuBtn');
        if (gcMobileMenuBtn && gcSidebar) {
            gcMobileMenuBtn.addEventListener('click', function () {
                gcSidebar.classList.toggle('open');
                gcMobileMenuBtn.setAttribute('aria-expanded', gcSidebar.classList.contains('open'));
            });
            // Close menu when clicking on a link
            const navItems = gcSidebar.querySelectorAll('.nav-item');
            navItems.forEach(function (item) {
                item.addEventListener('click', function () {
                    if (window.innerWidth <= 768) {
                        gcSidebar.classList.remove('open');
                        gcMobileMenuBtn.setAttribute('aria-expanded', 'false');
                    }
                });
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
