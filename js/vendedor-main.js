(function () {
    const API_URL = 'api_gestao.php';
    const REFRESH_MS = 30000; // Atualização em tempo real a cada 30s (dados da API RD Station)

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

    function currentMonthRange() {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        return { data_de: `${yyyy}-${mm}-01`, data_ate: `${yyyy}-${mm}-${dd}` };
    }

    function fmtDateBr(isoDate) {
        const s = String(isoDate || '').trim();
        const onlyDate = s.substring(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(onlyDate)) return s || '--';
        const p = onlyDate.split('-');
        return `${p[2]}/${p[1]}/${p[0]}`;
    }

    function normalizarNome(n) {
        return String(n || '').trim().toLowerCase();
    }

    function formatTempoMedio(minutos) {
        if (minutos === null || minutos === undefined || isNaN(minutos)) return '-';
        const m = Math.round(Number(minutos));
        if (m <= 0) return '-';
        if (m < 60) return m + ' min';
        const h = Math.floor(m / 60);
        const min = m % 60;
        return min > 0 ? h + ' h ' + min + ' min' : h + ' h';
    }

    function escapeHtml(s) {
        if (s == null) return '';
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    async function loadMetas(session) {
        hideError();
        const nome = (session && session.nome) ? String(session.nome).trim() : (localStorage.getItem('userName') || 'Vendedor').trim();
        setText('vdUserName', nome);
        setText('vdAvatar', nome ? nome.charAt(0).toUpperCase() : 'V');

        const range = currentMonthRange();
        const data = await apiGet('tv_corrida_vendedores', range);
        if (!data || data.success === false) {
            const elRd = document.getElementById('vdRdIntegracao');
            if (elRd) elRd.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--danger);"></i> <span>Erro ao carregar dados. Verifique RDSTATION_CRM_TOKEN no .env ou a conexão com a API.</span>';
            showError((data && data.error) || 'Falha ao carregar dados da corrida de vendas.');
            return;
        }

        const ranking = Array.isArray(data.ranking) ? data.ranking : [];
        const nomeNorm = normalizarNome(nome);
        const me = ranking.find(function (r) {
            return normalizarNome(r.vendedor) === nomeNorm;
        });

        const meta_mensal = me ? (Number(me.meta_mensal_utilizada) || Number(me.meta_mensal) || 0) : 0;
        const faturamento_mes = me ? Number(me.receita || 0) : 0;
        const pctMeta = me ? Number(me.percentual_meta || 0) : 0;

        const fakeDiaria = meta_mensal > 0 ? meta_mensal / 22 : 0;
        const fatDiariaMock = faturamento_mes / 22;
        const pctDia = calcPercent(fatDiariaMock, fakeDiaria);
        const pctSemana = calcPercent(faturamento_mes / 4, meta_mensal / 4);
        const pctMes = meta_mensal > 0 ? pctMeta : calcPercent(faturamento_mes, meta_mensal);

        setText('vdPctMetaDiaria', formatPercent(pctDia));
        setText('vdPctMetaSemanal', formatPercent(pctSemana));
        setText('vdPctMetaMensal', formatPercent(pctMes));

        setText('vdMetaDiaria', 'Meta: ' + formatMoney(fakeDiaria));
        setText('vdMetaSemanal', 'Meta: ' + formatMoney(meta_mensal / 4));
        setText('vdMetaMensal', 'Meta: ' + formatMoney(meta_mensal));

        setText('vdFatDiaAtual', 'Atual: ' + formatMoney(fatDiariaMock));
        setText('vdFatSemAtual', 'Atual: ' + formatMoney(faturamento_mes / 4));
        setText('vdFatMesAtual', 'Atual: ' + formatMoney(faturamento_mes));

        setText('vdComissaoIndividualDetalhe', 'Meta do mês: ' + formatPercent(pctMeta));
        setText('vdComissaoEstimValor', formatMoney(faturamento_mes));

        setProgress('vdProgFatDia', fatDiariaMock, fakeDiaria);
        setProgress('vdProgFatSem', faturamento_mes / 4, meta_mensal / 4);
        setProgress('vdProgFatMes', faturamento_mes, meta_mensal);

        setText('vdVolumeClientes', me ? String(me.posicao || '-') : '0');
        setText('vdTaxaConversao', formatPercent(pctMeta));
        setText('vdConversaoDetalhe', me ? formatMoney(faturamento_mes) + ' / meta ' + formatMoney(meta_mensal) : 'Sem dados');
        setText('vdTMAEspera', formatTempoMedio(me ? me.tempo_medio_espera_min : null));
        setText('vdTMAAtendimento', formatTempoMedio(me ? me.duracao_media_min : null));

        const fonteRD = data.fonte === 'rdstation_crm';
        const taxaPerda = me && (me.taxa_perda_pct !== null && me.taxa_perda_pct !== undefined)
            ? formatPercent(me.taxa_perda_pct) : '-';
        setText('vdTaxaPerda', taxaPerda);
        const elRd = document.getElementById('vdRdIntegracao');
        if (elRd) {
            if (fonteRD) {
                elRd.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success);"></i> <span>Integrado ao <strong>RD Station CRM</strong> · ganhos, perdas, funil e origem em tempo real</span>';
                elRd.style.color = 'var(--text-secondary)';
            } else {
                elRd.innerHTML = '<i class="fas fa-info-circle" style="color:var(--warning);"></i> <span>Integração RD Station: adicione <code>RDSTATION_CRM_TOKEN</code> no .env para usar a API do RD Station.</span>';
                elRd.style.color = 'var(--text-secondary)';
            }
        }
        const topMotivos = (me && Array.isArray(me.top_motivos_perda) && me.top_motivos_perda.length) ? me.top_motivos_perda : [];
        const motivosUL = document.getElementById('vdMotivosPerdaList');
        if (motivosUL) {
            if (topMotivos.length) {
                motivosUL.innerHTML = topMotivos.map(function (m) {
                    return '<li><strong>' + escapeHtml(m.nome || '—') + '</strong> · ' + (m.quantidade || 0) + ' ocorrência(s)</li>';
                }).join('');
            } else {
                motivosUL.innerHTML = fonteRD
                    ? '<li>Nenhuma perda no período ou dados ainda não disponíveis.</li>'
                    : '<li>Configure RD Station no .env para ver motivos de perda.</li>';
            }
        }
        const ganhos = (me && me.total_deals_ganhos != null) ? Number(me.total_deals_ganhos) : null;
        const perdidos = (me && me.total_deals_perdidos != null) ? Number(me.total_deals_perdidos) : null;
        const elPerdasResumo = document.getElementById('vdPerdasResumo');
        if (elPerdasResumo) {
            if (ganhos != null && perdidos != null && fonteRD) {
                elPerdasResumo.textContent = (ganhos + perdidos) + ' negociações no período: ' + ganhos + ' ganhas, ' + perdidos + ' perdidas.';
            } else {
                elPerdasResumo.textContent = '—';
            }
        }

        var funilList = Array.isArray(data.funil_estagios) ? data.funil_estagios : [];
        var elFunil = document.getElementById('vdFunilEstagiosList');
        if (elFunil) {
            if (funilList.length) {
                elFunil.innerHTML = funilList.map(function (e) {
                    return '<li><strong>' + escapeHtml(e.stage_name || e.pipeline_name || '—') + '</strong>' + (e.pipeline_name && e.pipeline_name !== (e.stage_name || '') ? ' <span style="color:var(--text-muted);">(' + escapeHtml(e.pipeline_name) + ')</span>' : '') + ' · ' + (e.quantidade || 0) + '</li>';
                }).join('');
            } else {
                elFunil.innerHTML = fonteRD ? '<li>Nenhum deal em aberto no período ou funil não disponível.</li>' : '<li>Funil disponível com RD Station.</li>';
            }
        }
        var elFunilResumo = document.getElementById('vdFunilResumo');
        if (elFunilResumo) {
            var totalFunil = funilList.reduce(function (acc, e) { return acc + (e.quantidade || 0); }, 0);
            elFunilResumo.textContent = totalFunil > 0 ? totalFunil + ' negociações em aberto no funil.' : '—';
        }

        var origemList = (me && Array.isArray(me.origem_deals) && me.origem_deals.length) ? me.origem_deals : [];
        var elOrigem = document.getElementById('vdOrigemDealsList');
        if (elOrigem) {
            if (origemList.length) {
                elOrigem.innerHTML = origemList.map(function (o) {
                    return '<li><strong>' + escapeHtml(o.nome || '—') + '</strong> · ' + formatMoney(o.receita || 0) + ' (' + (o.quantidade || 0) + ' deal(s))</li>';
                }).join('');
            } else {
                elOrigem.innerHTML = fonteRD ? '<li>Nenhuma origem registrada nos deals ganhos.</li>' : '<li>Origem dos negócios com RD Station.</li>';
            }
        }
        var elOrigemResumo = document.getElementById('vdOrigemResumo');
        if (elOrigemResumo) {
            elOrigemResumo.textContent = origemList.length > 0 ? origemList.length + ' origem(ns) nos seus deals ganhos.' : '—';
        }

        setText('vdQualidadeScore', (me ? me.posicao : 0) + '/100');
        setText('vdPremioPerformance', me ? 'Posição: ' + (me.posicao || '-') + 'º' : 'Bônus: -');

        const tabQualidade = document.getElementById('vdTabelaQualidade');
        if (tabQualidade) {
            const fonteLabel = fonteRD ? 'RD Station CRM (tempo real)' : 'Banco local';
            tabQualidade.innerHTML = `
                <tr><td>Fonte</td><td><span style="color:var(--success);">${fonteLabel}</span></td></tr>
                <tr><td>Período</td><td>${data.periodo && data.periodo.data_de ? fmtDateBr(data.periodo.data_de) + ' a ' + fmtDateBr(data.periodo.data_ate) : '-'}</td></tr>
                <tr><td>Atualizado</td><td>${data.updated_at || '-'}</td></tr>
            `;
        }

        const p = data.periodo || {};
        let periodoStr = (p.data_de && p.data_ate) ? (fmtDateBr(p.data_de) + ' a ' + fmtDateBr(p.data_ate)) : (range.data_de ? fmtDateBr(range.data_de) + ' a ' + fmtDateBr(range.data_ate) : '--');
        if (data.updated_at) {
            const dt = data.updated_at;
            const hora = /^\d{4}-\d{2}-\d{2}\s+(\d{2}:\d{2})/.exec(dt);
            if (hora) periodoStr += ' · Atualizado ' + hora[1];
        }
        setText('vdPeriodoInfo', periodoStr);
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
        if (!session) {
            var loader = document.getElementById('vdLoadingOverlay');
            if (loader) loader.style.display = 'none';
            return;
        }
        const app = document.getElementById('vdApp');
        if (app) app.style.display = 'block';
        var loader = document.getElementById('vdLoadingOverlay');
        if (loader) loader.style.display = 'none';
        bindUi();
        await loadMetas(session);
        setInterval(function () {
            loadMetas(session).catch(function () {});
        }, REFRESH_MS);
    });
})();
