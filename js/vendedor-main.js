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
            showError((data && data.error) || 'Falha ao carregar metas corporativas.');
            return;
        }

        const vendedor = data.vendedor || {};
        const metas = data.metas || {};
        const progresso = data.progresso || {};
        const calculos = data.calculos || {};
        const periodo = data.periodo || {};

        const nome = vendedor.nome || localStorage.getItem('userName') || 'Vendedor';
        setText('vdUserName', nome);
        setText('vdAvatar', String(nome).charAt(0).toUpperCase());

        // Mocks gerados para simular os novos KPIs temporariamente enquanto a API de Dashboard V2 não é entregue
        const fakeDiaria = (metas.meta_mensal || 0) / 22; 
        const fatDiariaMock = (progresso.faturamento_mes || 0) / 22;

        const pctDia = calcPercent(fatDiariaMock, fakeDiaria);
        const pctSemana = calcPercent(progresso.faturamento_mes / 4, (metas.meta_mensal || 0)/4); // Simples
        const pctMes = calcPercent(progresso.faturamento_mes, metas.meta_mensal);

        // Preenchendo Metas Financeiras
        setText('vdPctMetaDiaria', formatPercent(pctDia));
        setText('vdPctMetaSemanal', formatPercent(pctSemana));
        setText('vdPctMetaMensal', formatPercent(pctMes));

        setText('vdMetaDiaria', 'Meta: ' + formatMoney(fakeDiaria));
        setText('vdMetaSemanal', 'Meta: ' + formatMoney((metas.meta_mensal || 0)/4));
        setText('vdMetaMensal', 'Meta: ' + formatMoney(metas.meta_mensal));

        setText('vdFatDiaAtual', 'Atual: ' + formatMoney(fatDiariaMock));
        setText('vdFatSemAtual', 'Atual: ' + formatMoney((progresso.faturamento_mes || 0) / 4));
        setText('vdFatMesAtual', 'Atual: ' + formatMoney(progresso.faturamento_mes));

        setText('vdComissaoIndividualDetalhe', 'Faixa atual: ' + formatPercent(calculos.comissao_individual_percentual || 0));
        setText('vdComissaoEstimValor', formatMoney(calculos.comissao_estimada_valor || 0));

        setProgress('vdProgFatDia', fatDiariaMock, fakeDiaria);
        setProgress('vdProgFatSem', (progresso.faturamento_mes || 0) / 4, (metas.meta_mensal || 0)/4);
        setProgress('vdProgFatMes', progresso.faturamento_mes, metas.meta_mensal);

        // Preenchendo Mocks de Produtividade
        setText('vdVolumeClientes', formatInt(progresso.visitas_mes || 147));
        setText('vdTaxaConversao', '34%'); // Mock win rate
        setText('vdConversaoDetalhe', '49 aprovados / 147 propostas'); // Mock
        setText('vdTMAEspera', '4 min'); // Mock
        setText('vdTMAAtendimento', '12 min'); // Mock

        // Preenchendo Mocks de Retenção
        setText('vdTaxaPerda', '66%'); // 147 - 49 = 98 perdidos
        
        const motivosUL = document.getElementById('vdMotivosPerdaList');
        if (motivosUL) {
            motivosUL.innerHTML = `
                <li><span>Preço</span> <span class="loss-count">56%</span></li>
                <li><span>Concorrência / Frete</span> <span class="loss-count">24%</span></li>
                <li><span>Não respondeu</span> <span class="loss-count">20%</span></li>
            `;
        }

        // Avaliação Renata / Qualidade
        setText('vdQualidadeScore', formatInt(calculos.score_total || 95) + '/100');
        setText('vdPremioPerformance', 'Bônus Adicional: ' + formatMoney(calculos.premio_estimado || 0));

        const tabQualidade = document.getElementById('vdTabelaQualidade');
        if (tabQualidade) {
            tabQualidade.innerHTML = `
                <tr><td>Qualidade de Envio</td><td><span style="color:var(--success);">Excelente</span></td></tr>
                <tr><td>Ausência de Erros</td><td><span style="color:var(--success);">Aprovado</span></td></tr>
                <tr><td>Retornos</td><td><span style="color:var(--warning);">1 Alerta</span></td></tr>
            `;
        }

        if (periodo.mes_inicio && periodo.mes_fim) {
            setText(
                'vdPeriodoInfo',
                `${periodo.mes_inicio} a ${periodo.mes_fim}`
            );
        }
    }

    // Modal Repescagem functions
    window.abrirModalRepescagem = function() {
        const modal = document.getElementById('modalRepescagem');
        if(modal) {
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    window.fecharModalRepescagem = function() {
        const modal = document.getElementById('modalRepescagem');
        if(modal) {
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
            setTimeout(() => modal.style.display = 'none', 300);
        }
    }

    function bindUi() {
        const themeBtn = document.getElementById('vdThemeBtn');
        if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

        const repescagemBtn = document.getElementById('btnRepescagem');
        if (repescagemBtn) repescagemBtn.addEventListener('click', window.abrirModalRepescagem);

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
