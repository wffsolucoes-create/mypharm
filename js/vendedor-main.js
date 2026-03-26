(function () {
    const API_URL = 'api_gestao.php';
    const REFRESH_MS = 45000; // Painel alimentado por gestao_pedidos (importação)

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

    function fmtDateTimeBr(value) {
        const s = String(value || '').trim();
        const m = /^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})(?::\d{2})?$/.exec(s);
        if (!m) return s || '-';
        return `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}`;
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

    function renderValueCards(container, items, emptyText) {
        if (!container) return;
        if (!Array.isArray(items) || !items.length) {
            container.innerHTML = '<div class="value-card-item value-card-empty">' + escapeHtml(emptyText || 'Sem dados') + '</div>';
            return;
        }
        container.innerHTML = items.map(function (it) {
            return '<div class="value-card-item">' +
                '<div class="value-card-title">' + escapeHtml(it.title || '-') + '</div>' +
                '<div class="value-card-value">' + escapeHtml(it.value || '-') + '</div>' +
                '<div class="value-card-sub">' + escapeHtml(it.sub || '—') + '</div>' +
                '</div>';
        }).join('');
    }

    const perdasState = {
        page: 1,
        perPage: 10,
        sortBy: 'data_perda',
        sortDir: 'desc',
        q: '',
        dataDe: '',
        dataAte: '',
        totalPages: 1,
        rowMap: {},
        currentPedido: null
    };
    let currentSession = null;
    let perdasSearchDebounce = null;

    function fmtQtdCalculada(qtd, unidade) {
        if (qtd === null || qtd === undefined || qtd === '') return '-';
        const n = parseFloat(qtd);
        if (isNaN(n)) return String(qtd).trim() + (unidade ? ' ' + String(unidade).trim() : '');
        const s = Number.isInteger(n) ? String(Math.round(n)) : String(n).replace('.', ',');
        const u = (unidade && String(unidade).trim()) ? ' ' + String(unidade).trim() : '';
        return s + u;
    }

    async function loadPedidosPerdidos() {
        const tbody = document.getElementById('vdPedidosPerdidosBody');
        if (!tbody) return;
        const range = (perdasState.dataDe && perdasState.dataAte)
            ? { data_de: perdasState.dataDe, data_ate: perdasState.dataAte }
            : currentMonthRange();
        const data = await apiGet('vendedor_perdas_lista', {
            data_de: range.data_de,
            data_ate: range.data_ate,
            page: perdasState.page,
            per_page: perdasState.perPage,
            sort_by: perdasState.sortBy,
            sort_dir: perdasState.sortDir,
            q: perdasState.q
        });
        if (!data || data.success === false) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Erro ao carregar pedidos perdidos.</td></tr>';
            return;
        }

        const rows = Array.isArray(data.rows) ? data.rows : [];
        perdasState.rowMap = {};
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Nenhum pedido perdido encontrado no período.</td></tr>';
        } else {
            tbody.innerHTML = rows.map(function (r, idx) {
                const key = String(idx);
                perdasState.rowMap[key] = r;
                const acaoTxt = (r.motivo_perda && String(r.motivo_perda).trim() !== '')
                    ? ('Motivo: ' + escapeHtml(r.motivo_perda))
                    : 'Sem ação registrada';
                const pontosTxt = Number(r.pontos_descontados || 0) > 0
                    ? (' · -' + Number(r.pontos_descontados || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' pts')
                    : '';
                return (
                    '<tr>' +
                    '<td><button type="button" class="btn-action-primary" style="padding:6px 9px; font-size:0.75rem;" onclick="abrirModalPedidoGestao(\'' + key + '\')"><i class="fas fa-file-lines"></i> ' + escapeHtml(r.pedido_ref || '-') + '</button></td>' +
                    '<td>' + escapeHtml(r.cliente || '-') + '</td>' +
                    '<td><div style="display:flex; flex-direction:column; gap:2px;"><strong>' + escapeHtml(r.prescritor || '-') + '</strong><span class="vd-mini-muted">' + escapeHtml(r.contato || 'Sem contato') + '</span></div></td>' +
                    '<td>' + escapeHtml(r.status_principal || '-') + '</td>' +
                    '<td>' + formatMoney(r.valor_rejeitado || 0) + '</td>' +
                    '<td>' + fmtDateBr(r.data_perda || '-') + '</td>' +
                    '<td><div style="display:flex; flex-direction:column; gap:6px;">' +
                    '<span style="font-size:0.75rem; color:var(--text-secondary);">' + acaoTxt + pontosTxt + '</span>' +
                    '<button type="button" class="btn-action-primary" style="padding:7px 10px; font-size:0.76rem; width:max-content;" onclick="abrirModalPerdaAcao(\'' + key + '\')"><i class="fas fa-pen"></i> Registrar ação</button>' +
                    '</div></td>' +
                    '</tr>'
                );
            }).join('');
        }

        const pg = data.pagination || {};
        const resumo = data.resumo || {};
        const total = Number(pg.total || 0);
        const pages = Math.max(1, Number(pg.pages || 1));
        perdasState.totalPages = pages;
        perdasState.page = Math.max(1, Math.min(Number(pg.page || perdasState.page || 1), pages));
        const pageInfo = document.getElementById('vdPerdasPageInfo');
        if (pageInfo) pageInfo.textContent = 'Página ' + perdasState.page + ' de ' + pages + ' · ' + formatInt(total) + ' pedido(s)';
        setText('vdPerdasTotalQtd', formatInt(resumo.total_pedidos || total) + ' pedidos');
        setText('vdPerdasTotalValor', formatMoney(resumo.total_valor_rejeitado || 0));

        const prevBtn = document.getElementById('vdPerdasPrevBtn');
        const nextBtn = document.getElementById('vdPerdasNextBtn');
        if (prevBtn) prevBtn.disabled = perdasState.page <= 1;
        if (nextBtn) nextBtn.disabled = perdasState.page >= pages;
    }

    window.abrirModalPerdaAcao = function (rowKey) {
        const row = perdasState.rowMap[String(rowKey)];
        if (!row) return;
        perdasState.currentPedido = row;
        setText('vdPerdaPedidoInfo', 'Pedido: ' + (row.pedido_ref || '-') + ' · Cliente: ' + (row.cliente || '-'));
        const motivo = document.getElementById('vdAcaoMotivoPerda');
        const classif = document.getElementById('vdAcaoClassificacaoErro');
        const pontos = document.getElementById('vdAcaoPontosDescontados');
        const obs = document.getElementById('vdAcaoObservacoes');
        if (motivo) motivo.value = row.motivo_perda || '';
        if (classif) classif.value = row.classificacao_erro || 'nenhum';
        if (pontos) pontos.value = Number(row.pontos_descontados || 0) > 0 ? String(row.pontos_descontados) : '';
        if (obs) obs.value = row.observacoes || '';
        const modal = document.getElementById('modalPerdaAcao');
        if (modal) {
            modal.style.display = 'flex';
            setTimeout(function () { modal.classList.add('active'); }, 10);
            modal.setAttribute('aria-hidden', 'false');
        }
    };

    window.fecharModalPerdaAcao = function () {
        const modal = document.getElementById('modalPerdaAcao');
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        setTimeout(function () { modal.style.display = 'none'; }, 220);
    };

    window.abrirModalPedidoGestao = async function (rowKey) {
        const row = perdasState.rowMap[String(rowKey)];
        if (!row) return;
        const modal = document.getElementById('modalPedidoGestao');
        if (!modal) return;
        const loading = document.getElementById('vdPedidoDetalheLoading');
        const erro = document.getElementById('vdPedidoDetalheErro');
        const wrap = document.getElementById('vdPedidoDetalheWrap');
        if (loading) loading.style.display = 'block';
        if (erro) { erro.style.display = 'none'; erro.textContent = ''; }
        if (wrap) wrap.style.display = 'none';
        modal.style.display = 'flex';
        setTimeout(function () { modal.classList.add('active'); }, 10);
        modal.setAttribute('aria-hidden', 'false');

        try {
            const params = new URLSearchParams({
                action: 'get_pedido_detalhe',
                numero: row.numero_pedido || '',
                serie: row.serie_pedido || '',
                ano: String(row.ano_referencia || '')
            });
            const resDetalhe = await fetch('api.php?' + params.toString(), { credentials: 'include' });
            const txtDetalhe = await resDetalhe.text();
            const dataDetalhe = txtDetalhe ? JSON.parse(txtDetalhe) : {};
            if (!resDetalhe.ok || (dataDetalhe && dataDetalhe.error && !dataDetalhe.pedido)) {
                throw new Error((dataDetalhe && dataDetalhe.error) ? dataDetalhe.error : 'Erro ao carregar pedido.');
            }
            const pedido = dataDetalhe.pedido || {};

            const paramsComp = new URLSearchParams({
                action: 'get_pedido_componentes',
                numero: row.numero_pedido || '',
                serie: row.serie_pedido || '',
                ano: String(row.ano_referencia || '')
            });
            const resComp = await fetch('api.php?' + paramsComp.toString(), { credentials: 'include' });
            const txtComp = await resComp.text();
            const dataComp = txtComp ? JSON.parse(txtComp) : {};
            const componentes = (dataComp && Array.isArray(dataComp.componentes)) ? dataComp.componentes : [];

            const fields = [
                ['Nº / Série', (pedido.numero_pedido || row.numero_pedido || '-') + ' / ' + (pedido.serie_pedido || row.serie_pedido || '0')],
                ['Data aprovação', fmtDateBr(pedido.data_aprovacao || pedido.data || row.data_perda || '-')],
                ['Data orçamento', fmtDateBr(pedido.data_orcamento || pedido.data || '-')],
                ['Canal', pedido.canal || pedido.canal_atendimento || '-'],
                ['Cliente / Paciente', pedido.paciente || pedido.cliente || row.cliente || '-'],
                ['Prescritor', pedido.prescritor || row.prescritor || '-'],
                ['Status', pedido.status_financeiro || pedido.status || row.status_principal || '-'],
                ['Convênio', pedido.convenio || '-'],
                ['Total (gestão)', formatMoney(pedido.valor_liquido || row.valor_rejeitado || 0)]
            ];
            const fieldsWrap = document.getElementById('vdPedidoFields');
            if (fieldsWrap) {
                fieldsWrap.innerHTML = fields.map(function (f) {
                    return '<div class="field"><label>' + escapeHtml(f[0]) + '</label><div class="value">' + escapeHtml(f[1]) + '</div></div>';
                }).join('');
            }

            const itensBody = document.getElementById('vdPedidoItensGestaoBody');
            if (itensBody) {
                itensBody.innerHTML = '<tr>' +
                    '<td>1</td>' +
                    '<td>' + escapeHtml(pedido.descricao || '-') + '</td>' +
                    '<td>' + escapeHtml(pedido.forma_farmaceutica || '-') + '</td>' +
                    '<td>' + escapeHtml((pedido.quantidade || '-') + ' ' + (pedido.unidade || '')) + '</td>' +
                    '<td class="text-right">' + formatMoney(pedido.valor_liquido || 0) + '</td>' +
                    '</tr>';
            }

            const compBody = document.getElementById('vdPedidoCompsBody');
            const compEmpty = document.getElementById('vdPedidoCompsEmpty');
            const compTable = document.getElementById('vdPedidoCompsTable');
            if (compBody && compEmpty && compTable) {
                if (!componentes.length) {
                    compBody.innerHTML = '';
                    compTable.style.display = 'none';
                    compEmpty.style.display = 'block';
                } else {
                    compTable.style.display = 'table';
                    compEmpty.style.display = 'none';
                    compBody.innerHTML = componentes.map(function (c, i) {
                        return '<tr>' +
                            '<td>' + (i + 1) + '</td>' +
                            '<td>' + escapeHtml(c.descricao || c.componente || '-') + '</td>' +
                            '<td>' + escapeHtml(c.quantidade || '-') + '</td>' +
                            '<td>' + escapeHtml(c.qsp || '-') + '</td>' +
                            '<td>' + escapeHtml(c.tipo || 'Componente') + '</td>' +
                            '<td class="text-right">' + escapeHtml(fmtQtdCalculada(c.qtd_calculada, c.unidade)) + '</td>' +
                            '</tr>';
                    }).join('');
                }
            }

            if (loading) loading.style.display = 'none';
            if (wrap) wrap.style.display = 'block';
        } catch (e) {
            if (loading) loading.style.display = 'none';
            if (erro) {
                erro.style.display = 'block';
                erro.textContent = (e && e.message) ? e.message : 'Erro ao carregar detalhe do pedido.';
            }
        }
    };

    window.fecharModalPedidoGestao = function () {
        const modal = document.getElementById('modalPedidoGestao');
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        setTimeout(function () { modal.style.display = 'none'; }, 220);
    };

    async function salvarAcaoPerdaPedido() {
        if (!perdasState.currentPedido) return;
        const classifEl = document.getElementById('vdAcaoClassificacaoErro');
        const pontosEl = document.getElementById('vdAcaoPontosDescontados');
        const payload = {
            ano_referencia: perdasState.currentPedido.ano_referencia,
            numero_pedido: perdasState.currentPedido.numero_pedido,
            serie_pedido: perdasState.currentPedido.serie_pedido || '',
            data_perda: perdasState.currentPedido.data_perda || '',
            motivo_perda: (document.getElementById('vdAcaoMotivoPerda') || {}).value || '',
            classificacao_erro: classifEl ? classifEl.value : 'nenhum',
            pontos_descontados: pontosEl && pontosEl.value !== '' ? Number(pontosEl.value) : null,
            observacoes: (document.getElementById('vdAcaoObservacoes') || {}).value || ''
        };
        const resp = await apiPost('vendedor_perdas_salvar_acao', payload);
        if (!resp || resp.success === false) {
            alert((resp && resp.error) ? resp.error : 'Não foi possível salvar a ação.');
            return;
        }
        window.fecharModalPerdaAcao();
        await loadPedidosPerdidos();
        if (currentSession) {
            loadMetas(currentSession).catch(function () {});
        }
    }

    async function loadMetas(session) {
        hideError();
        const nome = (session && session.nome) ? String(session.nome).trim() : (localStorage.getItem('userName') || 'Vendedor').trim();
        setText('vdUserName', nome);
        setText('vdAvatar', nome ? nome.charAt(0).toUpperCase() : 'V');

        const range = currentMonthRange();
        const data = await apiGet('vendedor_dashboard_gestao', range);
        if (!data || data.success === false) {
            const elRd = document.getElementById('vdRdIntegracao');
            if (elRd) elRd.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:var(--danger);"></i> <span>Erro ao carregar dados da gestão de pedidos.</span>';
            showError((data && data.error) || 'Falha ao carregar o painel do vendedor.');
            return;
        }

        const ranking = Array.isArray(data.ranking) ? data.ranking : [];
        const nomeNorm = normalizarNome(nome);
        const meFromPayload = data && data.me ? data.me : null;
        const me = meFromPayload || ranking.find(function (r) {
            return normalizarNome(r.vendedor) === nomeNorm;
        });

        const meta_mensal = me ? (Number(me.meta_mensal_utilizada) || Number(me.meta_mensal) || 0) : 0;
        const faturamento_mes = me ? Number(me.receita || 0) : 0;
        const pctMeta = me ? Number(me.percentual_meta || 0) : 0;
        const comissaoPct = me
            ? Number(me.comissao_percentual != null ? me.comissao_percentual : (data.comissao_percentual_usuario != null ? data.comissao_percentual_usuario : 0))
            : Number(data.comissao_percentual_usuario != null ? data.comissao_percentual_usuario : 0);
        const comissaoIndPct = me ? Number(me.comissao_percentual_individual || 0) : 0;
        const comissaoGrpPct = me ? Number(me.comissao_percentual_grupo || 0) : 0;
        const comissaoEstimada = me
            ? Number(me.comissao_estimada_valor != null ? me.comissao_estimada_valor : ((faturamento_mes * comissaoPct) / 100))
            : 0;
        const premioPerformance = me ? Number(me.premio_performance_valor || 0) : 0;
        const totalComPremio = me ? Number(me.total_estimado_com_premio || (comissaoEstimada + premioPerformance)) : 0;

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

        setText('vdComissaoIndividualDetalhe', 'Comissão: ' + formatPercent(comissaoIndPct) + ' (individual) + ' + formatPercent(comissaoGrpPct) + ' (grupo) = ' + formatPercent(comissaoPct) + (premioPerformance > 0 ? ' · Prêmio score: +' + formatMoney(premioPerformance) : ''));
        setText('vdComissaoEstimValor', formatMoney(totalComPremio));

        setProgress('vdProgFatDia', fatDiariaMock, fakeDiaria);
        setProgress('vdProgFatSem', faturamento_mes / 4, meta_mensal / 4);
        setProgress('vdProgFatMes', faturamento_mes, meta_mensal);

        var volCli = me && me.volume_clientes != null ? Number(me.volume_clientes) : null;
        setText('vdVolumeClientes', volCli != null && !isNaN(volCli) ? formatInt(volCli) : (me ? String(me.posicao || '0') : '0'));
        var taxaConvLinhas = me && me.taxa_conversao_linhas_pct != null ? Number(me.taxa_conversao_linhas_pct) : null;
        var scoreTotal = me && me.score_performance ? Number(me.score_performance.total || 0) : 0;
        var perdaPontos = Math.max(0, 100 - scoreTotal);
        var qtdAprovados = me ? Number(me.total_deals_ganhos || me.linhas_aprovadas || 0) : 0;
        var qtdReprovados = me ? Number(me.total_deals_perdidos || me.linhas_perdidas || 0) : 0;
        var valorAprovado = faturamento_mes;
        var valorReprovado = me ? Number(me.valor_rejeitado || 0) : 0;
        var ticketMedioAprovado = qtdAprovados > 0 ? (valorAprovado / qtdAprovados) : 0;
        var ticketMedioReprovado = qtdReprovados > 0 ? (valorReprovado / qtdReprovados) : 0;
        setText('vdTaxaConversao', taxaConvLinhas != null && !isNaN(taxaConvLinhas) && me && ((me.linhas_aprovadas || 0) > 0 || (me.linhas_orcamento || 0) > 0 || (me.linhas_perdidas || 0) > 0)
            ? formatPercent(taxaConvLinhas)
            : formatPercent(pctMeta));
        if (me && (me.linhas_aprovadas > 0 || me.linhas_orcamento > 0 || me.linhas_perdidas > 0)) {
            const aprovDist = Number(me.total_deals_ganhos || me.linhas_aprovadas || 0);
            const perdDist = Number(me.total_deals_perdidos || me.linhas_perdidas || 0);
            setText('vdConversaoDetalhe', formatInt(aprovDist) + ' aprov. · ' + formatInt(perdDist) + ' recus./carrinho');
        } else {
            setText('vdConversaoDetalhe', me ? formatMoney(faturamento_mes) + ' faturado / meta ' + formatMoney(meta_mensal) : 'Sem dados');
        }
        setText('vdTMAEspera', formatTempoMedio(me ? me.tempo_medio_espera_min : null));
        setText('vdTMAAtendimento', formatTempoMedio(me ? me.duracao_media_min : null));
        setText('vdScorePerformance', me && me.score_performance ? (formatInt(perdaPontos) + ' pts') : '-');
        setText('vdScorePerformanceDesc', me && me.score_performance ? ('Base de score: ' + formatInt(scoreTotal) + '/100') : 'Sem score detalhado disponível');
        setText('vdValorAprovado', formatMoney(valorAprovado));
        setText('vdValorAprovadoDesc', qtdAprovados > 0 ? (formatInt(qtdAprovados) + ' pedido(s) aprovados') : 'Sem pedidos aprovados no período');
        setText('vdValorReprovado', formatMoney(valorReprovado));
        setText('vdValorReprovadoDesc', qtdReprovados > 0 ? (formatInt(qtdReprovados) + ' pedido(s) recusados/no carrinho') : 'Sem pedidos reprovados no período');
        setText('vdTicketMedioAprovado', qtdAprovados > 0 ? formatMoney(ticketMedioAprovado) : 'R$ 0,00');
        setText('vdTicketMedioAprovadoDesc', qtdAprovados > 0 ? (formatInt(qtdAprovados) + ' pedido(s) na base do cálculo') : 'Sem pedidos aprovados no período');
        setText('vdTicketMedioReprovado', qtdReprovados > 0 ? formatMoney(ticketMedioReprovado) : 'R$ 0,00');
        setText('vdTicketMedioReprovadoDesc', qtdReprovados > 0 ? (formatInt(qtdReprovados) + ' pedido(s) na base do cálculo') : 'Sem pedidos reprovados no período');

        const fonteRD = data.fonte === 'rdstation_crm';
        const fonteGestao = data.fonte === 'gestao_pedidos';
        const taxaPerda = me && (me.taxa_perda_pct !== null && me.taxa_perda_pct !== undefined)
            ? formatPercent(me.taxa_perda_pct) : '-';
        setText('vdTaxaPerda', taxaPerda);
        const elRd = document.getElementById('vdRdIntegracao');
        if (elRd) {
            if (fonteRD) {
                elRd.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success);"></i> <span>Integrado ao <strong>RD Station CRM</strong> · dados completos externos em tempo real</span>';
                elRd.style.color = 'var(--text-secondary)';
            } else if (fonteGestao) {
                elRd.innerHTML = '<i class="fas fa-database" style="color:var(--success);"></i> <span>Dados da tabela <strong>gestao_pedidos</strong> (relatório importado) · mesmo critério da gestão comercial / TV</span>';
                elRd.style.color = 'var(--text-secondary)';
            } else {
                elRd.innerHTML = '<i class="fas fa-info-circle" style="color:var(--warning);"></i> <span>Fonte de dados não reconhecida. Verifique a API.</span>';
                elRd.style.color = 'var(--text-secondary)';
            }
        }
        const topMotivos = (me && Array.isArray(me.top_motivos_perda) && me.top_motivos_perda.length) ? me.top_motivos_perda : [];
        const motivosUL = document.getElementById('vdMotivosPerdaList');
        if (motivosUL) {
            const cardsMotivos = topMotivos.map(function (m) {
                return {
                    title: m.nome || '—',
                    value: formatInt(m.quantidade || 0),
                    sub: 'ocorrência(s)'
                };
            });
            renderValueCards(
                motivosUL,
                cardsMotivos,
                fonteRD
                    ? 'Nenhuma perda no período ou dados ainda não disponíveis.'
                    : (fonteGestao
                        ? 'Nenhum registro com status de orçamento/recusa no período para o seu atendente.'
                        : 'Sem motivos de perda para esta fonte.')
            );
        }
        const ganhos = (me && me.total_deals_ganhos != null) ? Number(me.total_deals_ganhos) : null;
        const perdidos = (me && me.total_deals_perdidos != null) ? Number(me.total_deals_perdidos) : null;
        const elPerdasResumo = document.getElementById('vdPerdasResumo');
        if (elPerdasResumo) {
            if (ganhos != null && perdidos != null && fonteRD) {
                elPerdasResumo.textContent = (ganhos + perdidos) + ' negociações no período: ' + ganhos + ' ganhas, ' + perdidos + ' perdidas.';
            } else if (fonteGestao && me) {
                elPerdasResumo.textContent = 'Pedidos aprovados (distintos): ' + formatInt(me.total_deals_ganhos || 0) + ' · linhas com recusa/carrinho: ' + formatInt(me.linhas_perdidas || 0) + '.';
            } else {
                elPerdasResumo.textContent = '—';
            }
        }

        var funilList = Array.isArray(data.funil_estagios) ? data.funil_estagios : [];
        var elFunil = document.getElementById('vdFunilEstagiosList');
        if (elFunil) {
            if (funilList.length) {
                renderValueCards(elFunil, funilList.map(function (e) {
                    const stage = e.stage_name || e.pipeline_name || '—';
                    const sub = e.pipeline_name && e.pipeline_name !== (e.stage_name || '') ? e.pipeline_name : 'status financeiro';
                    return {
                        title: stage,
                        value: formatInt(e.quantidade || 0),
                        sub: sub
                    };
                }), 'Sem dados de funil.');
            } else {
                renderValueCards(elFunil, [], fonteRD
                    ? 'Nenhum deal em aberto no período ou funil não disponível.'
                    : (fonteGestao ? 'Nenhuma linha no período em gestao_pedidos.' : 'Sem dados de funil.'));
            }
        }
        var elFunilResumo = document.getElementById('vdFunilResumo');
        if (elFunilResumo) {
            var totalFunil = funilList.reduce(function (acc, e) { return acc + (e.quantidade || 0); }, 0);
            if (totalFunil > 0) {
                elFunilResumo.textContent = fonteGestao
                    ? totalFunil + ' linhas no período (soma por status_financeiro).'
                    : totalFunil + ' negociações em aberto no funil.';
            } else {
                elFunilResumo.textContent = '—';
            }
        }

        var origemList = (me && Array.isArray(me.origem_deals) && me.origem_deals.length) ? me.origem_deals : [];
        var elOrigem = document.getElementById('vdOrigemDealsList');
        if (elOrigem) {
            if (origemList.length) {
                renderValueCards(elOrigem, origemList.map(function (o) {
                    return {
                        title: o.nome || '—',
                        value: formatMoney(o.receita || 0),
                        sub: formatInt(o.quantidade || 0) + ' deal(s)'
                    };
                }), 'Sem origem.');
            } else {
                renderValueCards(elOrigem, [], fonteRD
                    ? 'Nenhuma origem registrada nos deals ganhos.'
                    : (fonteGestao ? 'Sem canal de atendimento no período para o seu usuário.' : 'Sem origem.'));
            }
        }
        var elOrigemResumo = document.getElementById('vdOrigemResumo');
        if (elOrigemResumo) {
            if (origemList.length > 0) {
                elOrigemResumo.textContent = fonteGestao
                    ? origemList.length + ' canal(is) de atendimento no período.'
                    : origemList.length + ' origem(ns) nos seus deals ganhos.';
            } else {
                elOrigemResumo.textContent = '—';
            }
        }

        setText('vdQualidadeScore', formatInt(scoreTotal) + '/100');
        if (me) {
            const premioTxt = premioPerformance > 0
                ? ('Prêmio Performance: ' + formatMoney(premioPerformance) + ' · Total estimado: ' + formatMoney(totalComPremio))
                : ('Prêmio Performance: - · Meta do mês: ' + formatPercent(pctMeta));
            setText('vdPremioPerformance', premioTxt);
        } else {
            setText('vdPremioPerformance', 'Prêmio Performance: -');
        }

        const tabQualidade = document.getElementById('vdTabelaQualidade');
        if (tabQualidade) {
            const fonteLabel = fonteRD ? 'RD Station CRM (tempo real)' : (fonteGestao ? 'gestao_pedidos (importação)' : 'Banco local');
            const score = me && me.score_performance ? me.score_performance : null;
            const fmtPts = function (v) {
                return Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' pts';
            };
            const linhaProjecao = function (atual, maximo) {
                const a = Number(atual || 0);
                const m = Number(maximo || 0);
                const faltam = Math.max(0, m - a);
                return 'Atual: ' + fmtPts(a) + ' · Projeção p/ máximo: +' + fmtPts(faltam) + ' (meta: ' + fmtPts(m) + ')';
            };
            const scoreDetalheHtml = score
                ? (`
                    <div class="quality-score-lines">
                        <div class="quality-score-line"><strong>Faturamento</strong><span>${linhaProjecao(score.faturamento, 50)}</span></div>
                        <div class="quality-score-line"><strong>Conversão</strong><span>${linhaProjecao(score.conversao, 20)}</span></div>
                        <div class="quality-score-line"><strong>Erros</strong><span>${linhaProjecao(score.erros, 20)}${Number(score.penalidade_manual_erros || 0) > 0 ? (' · Penalidade manual: -' + fmtPts(score.penalidade_manual_erros)) : ''}</span></div>
                        <div class="quality-score-line"><strong>Organização/CRM</strong><span>${linhaProjecao(score.organizacao_crm, 10)}</span></div>
                    </div>
                `)
                : '-';
            const scoreTotalFmt = score ? fmtPts(score.total) : '-';
            const perdaPtsFmt = score ? fmtPts(Math.max(0, 100 - Number(score.total || 0))) : '-';
            tabQualidade.innerHTML = `
                <tr><td>Fonte</td><td><span style="color:var(--success);">${fonteLabel}</span></td></tr>
                <tr><td>Período</td><td>${data.periodo && data.periodo.data_de ? fmtDateBr(data.periodo.data_de) + ' a ' + fmtDateBr(data.periodo.data_ate) : '-'}</td></tr>
                <tr><td>Score (detalhe)</td><td class="quality-score-cell">${scoreDetalheHtml}</td></tr>
                <tr><td>Score total</td><td><span class="quality-pill quality-pill-good">${score ? (scoreTotalFmt + ' de 100 pts') : '-'}</span></td></tr>
                <tr><td>Perda de pontos</td><td><span class="quality-pill quality-pill-warn">${perdaPtsFmt}</span></td></tr>
                <tr><td>Atualizado</td><td>${fmtDateBr(data.updated_at)}</td></tr>
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

        const sortBy = document.getElementById('vdPerdasSortBy');
        const sortDir = document.getElementById('vdPerdasSortDir');
        const perdasSearch = document.getElementById('vdPerdasSearch');
        const perdasDataDe = document.getElementById('vdPerdasDataDe');
        const perdasDataAte = document.getElementById('vdPerdasDataAte');
        const perdasPerPage = document.getElementById('vdPerdasPerPage');

        const range = currentMonthRange();
        perdasState.dataDe = range.data_de;
        perdasState.dataAte = range.data_ate;
        if (perdasDataDe) perdasDataDe.value = range.data_de;
        if (perdasDataAte) perdasDataAte.value = range.data_ate;
        if (perdasPerPage) perdasPerPage.value = String(perdasState.perPage);

        if (perdasSearch) {
            perdasSearch.addEventListener('input', function () {
                if (perdasSearchDebounce) clearTimeout(perdasSearchDebounce);
                perdasSearchDebounce = setTimeout(function () {
                    perdasState.q = String(perdasSearch.value || '').trim();
                    perdasState.page = 1;
                    loadPedidosPerdidos().catch(function () {});
                }, 300);
            });
        }
        if (perdasDataDe) {
            perdasDataDe.addEventListener('change', function () {
                perdasState.dataDe = perdasDataDe.value || range.data_de;
                if (perdasDataAte && perdasDataAte.value && perdasDataAte.value < perdasState.dataDe) {
                    perdasDataAte.value = perdasState.dataDe;
                    perdasState.dataAte = perdasState.dataDe;
                }
                perdasState.page = 1;
                loadPedidosPerdidos().catch(function () {});
            });
        }
        if (perdasDataAte) {
            perdasDataAte.addEventListener('change', function () {
                perdasState.dataAte = perdasDataAte.value || range.data_ate;
                if (perdasDataDe && perdasDataDe.value && perdasState.dataAte < perdasDataDe.value) {
                    perdasState.dataAte = perdasDataDe.value;
                    perdasDataAte.value = perdasState.dataAte;
                }
                perdasState.page = 1;
                loadPedidosPerdidos().catch(function () {});
            });
        }
        if (perdasPerPage) {
            perdasPerPage.addEventListener('change', function () {
                const val = Number(perdasPerPage.value || 10);
                perdasState.perPage = Math.max(5, Math.min(50, val));
                perdasState.page = 1;
                loadPedidosPerdidos().catch(function () {});
            });
        }

        if (sortBy) {
            sortBy.value = perdasState.sortBy;
            sortBy.addEventListener('change', function () {
                perdasState.sortBy = sortBy.value || 'data_perda';
                perdasState.page = 1;
                loadPedidosPerdidos().catch(function () {});
            });
        }
        if (sortDir) {
            sortDir.value = perdasState.sortDir;
            sortDir.addEventListener('change', function () {
                perdasState.sortDir = sortDir.value || 'desc';
                perdasState.page = 1;
                loadPedidosPerdidos().catch(function () {});
            });
        }
        const prevBtn = document.getElementById('vdPerdasPrevBtn');
        const nextBtn = document.getElementById('vdPerdasNextBtn');
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (perdasState.page <= 1) return;
                perdasState.page -= 1;
                loadPedidosPerdidos().catch(function () {});
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (perdasState.page >= perdasState.totalPages) return;
                perdasState.page += 1;
                loadPedidosPerdidos().catch(function () {});
            });
        }
        const salvarBtn = document.getElementById('vdSalvarPerdaAcaoBtn');
        if (salvarBtn) salvarBtn.addEventListener('click', function () { salvarAcaoPerdaPedido().catch(function () {}); });

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
        currentSession = session;
        const app = document.getElementById('vdApp');
        if (app) app.style.display = 'block';
        var loader = document.getElementById('vdLoadingOverlay');
        if (loader) loader.style.display = 'none';
        bindUi();
        await loadMetas(session);
        await loadPedidosPerdidos();
        setInterval(function () {
            loadMetas(session).catch(function () {});
            loadPedidosPerdidos().catch(function () {});
        }, REFRESH_MS);
    });
})();
