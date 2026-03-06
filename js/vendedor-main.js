(function () {
    const API_URL = 'api_vendedor.php';

    function formatMoney(v) {
        const n = Number(v || 0);
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function formatInt(v) {
        return Number(v || 0).toLocaleString('pt-BR');
    }

    function formatPercent(v) {
        return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + '%';
    }

    function getThemeStorageKey() {
        const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
        return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
    }

    function loadSavedTheme() {
        const userKey = getThemeStorageKey();
        const saved = localStorage.getItem(userKey) || localStorage.getItem('mypharm_theme');
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

    async function apiGet(action, params = {}) {
        const query = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'include' });
        const text = await res.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch (_) {
            return { success: false, error: `Erro ${res.status}` };
        }
    }

    async function apiPost(action, data = {}) {
        const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const text = await res.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch (_) {
            return { success: false, error: `Erro ${res.status}` };
        }
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function setProgress(id, atual, meta) {
        const bar = document.getElementById(id);
        if (!bar) return;
        const m = Number(meta || 0);
        const a = Number(atual || 0);
        let p = 0;
        if (m > 0) p = (a / m) * 100;
        if (p < 0) p = 0;
        if (p > 100) p = 100;
        bar.style.width = p.toFixed(2) + '%';
    }

    function calcPercent(atual, meta) {
        const a = Number(atual || 0);
        const m = Number(meta || 0);
        if (m <= 0) return 0;
        return (a / m) * 100;
    }

    function renderTable(tbodyId, rows, mapRow) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        if (!Array.isArray(rows) || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="2">Sem dados.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(mapRow).join('');
    }

    function showError(msg) {
        const err = document.getElementById('vdError');
        if (!err) return;
        err.textContent = msg || 'Não foi possível carregar seus dados.';
        err.style.display = 'block';
    }

    function hideError() {
        const err = document.getElementById('vdError');
        if (err) err.style.display = 'none';
    }

    async function enforceVendedorAccess() {
        const session = await apiGet('check_session');
        if (!session || !session.logged_in) {
            localStorage.clear();
            window.location.href = 'index.html';
            return null;
        }
        const setor = String(session.setor || '').trim().toLowerCase();
        if (setor.indexOf('vendedor') === -1 && String(session.tipo || '').toLowerCase() !== 'admin') {
            localStorage.clear();
            window.location.href = 'index.html';
            return null;
        }
        return session;
    }

    async function loadMetas() {
        hideError();
        const data = await apiGet('vendedor_metas');
        if (!data || data.success === false) {
            showError((data && data.error) || 'Falha ao carregar metas.');
            return;
        }

        const vendedor = data.vendedor || {};
        const metas = data.metas || {};
        const progresso = data.progresso || {};
        const regras = data.regras || {};
        const calculos = data.calculos || {};
        const periodo = data.periodo || {};

        const nome = vendedor.nome || localStorage.getItem('userName') || 'Vendedor';
        setText('vdUserName', nome);
        setText('vdAvatar', String(nome).charAt(0).toUpperCase());

        const pctMes = calcPercent(progresso.faturamento_mes, metas.meta_mensal);
        const pctAno = calcPercent(progresso.faturamento_ano, metas.meta_anual);
        const pctVisSem = calcPercent(progresso.visitas_semana, metas.meta_visitas_semana);
        const pctVisMes = calcPercent(progresso.visitas_mes, metas.meta_visitas_mes);

        setText('vdPctMetaMensal', formatPercent(pctMes));
        setText('vdPctMetaAnual', formatPercent(pctAno));
        setText('vdPctVisSem', formatPercent(pctVisSem));
        setText('vdPctVisMes', formatPercent(pctVisMes));

        setText('vdMetaMensal', 'Meta: ' + formatMoney(metas.meta_mensal));
        setText('vdMetaAnual', 'Meta: ' + formatMoney(metas.meta_anual));
        setText('vdMetaVisSem', 'Meta: ' + formatInt(metas.meta_visitas_semana));
        setText('vdMetaVisMes', 'Meta: ' + formatInt(metas.meta_visitas_mes));

        setText('vdFatMesAtual', 'Atual: ' + formatMoney(progresso.faturamento_mes));
        setText('vdFatAnoAtual', 'Atual: ' + formatMoney(progresso.faturamento_ano));
        setText('vdVisSemAtual', 'Atual: ' + formatInt(progresso.visitas_semana));
        setText('vdVisMesAtual', 'Atual: ' + formatInt(progresso.visitas_mes));

        setText('vdFaixaIndividual', calculos.faixa_individual || '-');
        setText('vdFaixaGrupo', calculos.faixa_grupo || '-');
        setText('vdComissaoIndividualDetalhe', 'Comissão individual: ' + formatPercent(calculos.comissao_individual_percentual || 0));
        setText('vdComissaoGrupoDetalhe', 'Comissão grupo: ' + formatPercent(calculos.comissao_grupo_percentual || 0));
        setText('vdFaturamentoGrupo', formatMoney(calculos.faturamento_grupo_mes || 0));
        setText('vdComissaoEstimValor', formatMoney(calculos.comissao_estimada_valor || 0));
        setText('vdComissaoEstimPct', 'Percentual total: ' + formatPercent(calculos.comissao_total_percentual || 0));
        setText('vdPremioPerformance', formatMoney(calculos.premio_estimado || 0));
        setText('vdScoreTotal', 'Score total: ' + formatInt(calculos.score_total || 0) + ' / 100');

        setProgress('vdProgFatMes', progresso.faturamento_mes, metas.meta_mensal);
        setProgress('vdProgFatAno', progresso.faturamento_ano, metas.meta_anual);
        setProgress('vdProgVisSem', progresso.visitas_semana, metas.meta_visitas_semana);
        setProgress('vdProgVisMes', progresso.visitas_mes, metas.meta_visitas_mes);

        renderTable('vdTabelaMetaIndividual', regras.meta_individual || [], function (r) {
            return `<tr><td>${r.label || '-'}</td><td>${formatPercent(r.comissao_percentual || 0)}</td></tr>`;
        });
        renderTable('vdTabelaMetaGrupo', regras.meta_grupo || [], function (r) {
            return `<tr><td>${r.label || '-'}</td><td>${formatPercent(r.percentual || 0)}</td></tr>`;
        });
        renderTable('vdTabelaScore', regras.score || [], function (r) {
            return `<tr><td>${r.item || '-'}</td><td>${formatInt(r.max_pontos || 0)}</td></tr>`;
        });
        renderTable('vdTabelaPremio', regras.premio_performance || [], function (r) {
            return `<tr><td>${r.regra || '-'}</td><td>${formatMoney(r.premio || 0)}</td></tr>`;
        });

        if (periodo.mes_inicio && periodo.mes_fim) {
            setText(
                'vdPeriodoInfo',
                `Período do mês: ${periodo.mes_inicio} até ${periodo.mes_fim} | Atingimento mensal: ${formatPercent(calculos.percentual_meta_mensal || 0)}`
            );
        }
    }

    function bindUi() {
        const themeBtn = document.getElementById('vdThemeBtn');
        if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

        const logoutBtn = document.getElementById('vdLogoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', async function () {
                try { await apiPost('logout', {}); } catch (_) {}
                localStorage.clear();
                window.location.href = 'index.html';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', async function () {
        loadSavedTheme();
        const session = await enforceVendedorAccess();
        if (!session) return;
        const app = document.getElementById('vdApp');
        if (app) app.style.display = 'block';
        bindUi();
        await loadMetas();
    });
})();
