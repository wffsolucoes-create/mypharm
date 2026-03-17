function getThemeStorageKeyVisitador() {
            const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
            return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
        }

        // Aplicar tema salvo imediatamente (por usuario, com fallback legado)
        (function () {
            const savedTheme = localStorage.getItem(getThemeStorageKeyVisitador()) || localStorage.getItem('mypharm_theme');
            if (savedTheme) {
                document.documentElement.setAttribute('data-theme', savedTheme);
            }
        })();

        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem(getThemeStorageKeyVisitador(), newTheme);
            // Mantem chave legada para compatibilidade
            localStorage.setItem('mypharm_theme', newTheme);
        }

        // Nome do visitador cujo painel está sendo exibido (próprio usuário ou, se admin, o da URL ?visitador=)
        let currentVisitadorName = '';
        // Apenas usuários do setor visitador podem iniciar/encerrar visitas
        let canManageVisits = false;
        // Apenas perfil visitador pode iniciar/pausar/finalizar rota e gravar GPS de rota
        let canManageRota = false;
        // Visita ativa do visitador logado (quando existir)
        let activeVisit = null;

        let allPrescritores = [];
        let filteredPrescritores = [];
        let prescritorContatos = {};
        let currentSort = { column: 'total_aprovado', direction: 'desc' };

        async function openPrescritoresModal(filterPrescritor) {
            const modal = document.getElementById('modalPrescritores');
            modal.style.display = 'flex';
            const searchEl = document.getElementById('searchPrescritor');
            searchEl.value = (filterPrescritor && typeof filterPrescritor === 'string') ? filterPrescritor.trim() : '';

            const modalTitle = document.getElementById('modalPrescritorTitle');
            const modalSubtitle = document.getElementById('modalPrescritorSubtitle');
            if (modalTitle) modalTitle.textContent = currentVisitadorName ? `Todos os Prescritores — ${currentVisitadorName}` : 'Todos os Prescritores';
            if (modalSubtitle) modalSubtitle.textContent = currentVisitadorName ? `Carteira: ${currentVisitadorName}` : 'Carteira completa do visitador';

            const tbody = document.getElementById('modalPrescritoresBody');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:40px; color:var(--text-secondary);">Carregando...</td></tr>';
            document.getElementById('paginationContainer').style.display = 'none';

            const visitadorParam = currentVisitadorName || localStorage.getItem('userName') || '';

            try {
                prescritorContatos = await apiGet('get_prescritor_contatos');
            } catch (e) {
                prescritorContatos = {};
            }

            // Buscar visita ativa
            activeVisit = null;
            if (canManageVisits) {
                try {
                    const av = await apiGet('visita_ativa', { visitador_nome: currentVisitadorName });
                    activeVisit = av && av.active ? av.active : null;
                } catch (e) {
                    activeVisit = null;
                }
            }

            // Na página Prescritores (admin) os dados já vêm agregados por ano em all_prescritores.
            // Para o modal do visitador, usamos a mesma API com a mesma assinatura (apenas visitador),
            // reaproveitando exatamente os campos valor_aprovado / valor_recusado / total_pedidos.
            const params = { visitador: visitadorParam };
            let list = [];
            try {
                list = await apiGet('all_prescritores', params) || [];
            } catch (e) {
                console.error('Erro ao carregar prescritores', e);
            }
            // API retorna valor_aprovado, valor_recusado, total_pedidos; modal espera total_aprovado, total_recusado, qtd_aprovados.
            allPrescritores = list.map(p => ({
                ...p,
                total_aprovado: p.valor_aprovado ?? p.total_aprovado ?? 0,
                total_recusado: p.valor_recusado ?? p.total_recusado ?? 0,
                valor_recusado: p.valor_recusado ?? p.total_recusado ?? 0,
                qtd_recusados: p.qtd_recusados ?? 0,
                qtd_aprovados: p.total_pedidos ?? p.qtd_aprovados ?? 0
            }));
            filteredPrescritores = [...allPrescritores];
            modalCurrentPage = 1;
            sortData(filteredPrescritores, currentSort.column, currentSort.direction);
            if (searchEl.value) filterPrescritores(); else renderModalPrescritores(filteredPrescritores);
        }

        function closePrescritoresModal() {
            document.getElementById('modalPrescritores').style.display = 'none';
        }

        let modalPedidosAprovados = [];
        let modalPedidosRecusados = [];
        let modalPedidosAprovadosFiltered = [];
        let modalPedidosRecusadosFiltered = [];
        let modalPedidosCurrentPage = 1;
        let modalPedidosPageSize = 20;
        let modalPedidosSortPedidos = { column: 'data_aprovacao', direction: 'desc' };
        let modalPedidosAno = '';
        let modalPedidosTipoFilter = null;

        async function openModalPedidosVisitador(prescritorNome, tipoFilter) {
            modalPedidosTipoFilter = tipoFilter || null;
            const modal = document.getElementById('modalPedidosVisitador');
            const titleEl = document.getElementById('modalPedidosVisitadorTitle');
            const subtitleEl = document.getElementById('modalPedidosVisitadorSubtitle');
            const bodyEl = document.getElementById('modalPedidosVisitadorBody');
            const paginationEl = document.getElementById('modalPedidosVisitadorPagination');
            const searchEl = document.getElementById('searchPedidosVisitador');
            if (!modal || !bodyEl) return;
            const nome = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '').trim() || (localStorage.getItem('userName') || '').trim();
            const anoEl = document.getElementById('anoSelect');
            const mesEl = document.getElementById('mesSelect');
            let ano = (anoEl && anoEl.value) ? anoEl.value : (new Date().getFullYear()).toString();
            let mes = mesEl && mesEl.value ? mesEl.value : '';
            if (prescritorNome) {
                // Na página Prescritores, os cards/lista são anuais; o modal não deve herdar mês.
                mes = '';
            }
            if (prescritorNome) {
                if (modalPedidosTipoFilter === 'recusados') {
                    if (titleEl) titleEl.innerHTML = '<i class="fas fa-times-circle" style="color:var(--danger); margin-right:8px;"></i>Lista de recusados';
                    if (subtitleEl) subtitleEl.textContent = prescritorNome + (mes ? ' — ' + mes + '/' + ano : ' — ' + ano);
                } else if (modalPedidosTipoFilter === 'aprovados') {
                    if (titleEl) titleEl.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success); margin-right:8px;"></i>Lista de aprovados';
                    if (subtitleEl) subtitleEl.textContent = prescritorNome + (mes ? ' — ' + mes + '/' + ano : ' — ' + ano);
                } else {
                    if (titleEl) titleEl.innerHTML = '<i class="fas fa-list-alt" style="color:var(--primary); margin-right:8px;"></i>Pedidos do prescritor';
                    if (subtitleEl) subtitleEl.textContent = prescritorNome + (mes ? ' — ' + mes + '/' + ano : ' — ' + ano);
                }
            } else {
                modalPedidosTipoFilter = null;
                if (titleEl) titleEl.innerHTML = '<i class="fas fa-list-alt" style="color:var(--primary); margin-right:8px;"></i>Pedidos Aprovados e Recusados + Carrinho';
                if (subtitleEl) subtitleEl.textContent = nome ? (mes ? 'Carteira: ' + nome + ' — ' + mes + '/' + ano : 'Carteira: ' + nome + ' — ' + ano) : 'Carregando...';
            }
            if (searchEl) searchEl.value = '';
            bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
            if (paginationEl) paginationEl.textContent = 'Mostrando 0-0 de 0';
            modal.style.display = 'flex';
            try {
                if (!nome) {
                    bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--danger);">Visitador não identificado. Faça login ou selecione a carteira.</div>';
                    return;
                }
                const params = { nome: nome, ano: ano };
                if (mes) params.mes = mes;
                if (prescritorNome) params.prescritor = prescritorNome;
                const res = await apiGet('list_pedidos_visitador', params);
                if (res && res.error) {
                    bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--danger);">' + (res.error || 'Erro ao carregar pedidos.') + '</div>';
                    return;
                }
                modalPedidosAprovados = Array.isArray(res.aprovados) ? res.aprovados : [];
                modalPedidosRecusados = Array.isArray(res.recusados_carrinho) ? res.recusados_carrinho : [];
                modalPedidosAprovadosFiltered = [...modalPedidosAprovados];
                modalPedidosRecusadosFiltered = [...modalPedidosRecusados];
                modalPedidosCurrentPage = 1;
                modalPedidosAno = ano || '';
                renderModalPedidosVisitadorBody();
            } catch (e) {
                bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--danger);">Erro ao carregar pedidos.</div>';
            }
        }

        function filterPedidosVisitadorModal() {
            const q = (document.getElementById('searchPedidosVisitador') || {}).value || '';
            const term = String(q).toLowerCase().trim();
            if (!term) {
                modalPedidosAprovadosFiltered = [...modalPedidosAprovados];
                modalPedidosRecusadosFiltered = [...modalPedidosRecusados];
            } else {
                modalPedidosAprovadosFiltered = modalPedidosAprovados.filter(function (p) {
                    return (p.prescritor || '').toLowerCase().indexOf(term) !== -1 || (p.cliente || '').toLowerCase().indexOf(term) !== -1 || String(p.numero_pedido || '').indexOf(term) !== -1;
                });
                modalPedidosRecusadosFiltered = modalPedidosRecusados.filter(function (p) {
                    return (p.prescritor || '').toLowerCase().indexOf(term) !== -1 || (p.cliente || '').toLowerCase().indexOf(term) !== -1 || String(p.numero_pedido || '').indexOf(term) !== -1;
                });
            }
            modalPedidosCurrentPage = 1;
            renderModalPedidosVisitadorBody();
        }

        function sortPedidosModal(list, col, dir) {
            const d = dir === 'asc' ? 1 : -1;
            return list.slice().sort(function (a, b) {
                let va = a[col]; let vb = b[col];
                if (col === 'data_aprovacao') { va = (va || '').replace(/-/g, ''); vb = (vb || '').replace(/-/g, ''); return (va === vb ? 0 : (va < vb ? -1 : 1)) * d; }
                if (col === 'valor') { va = parseFloat(va) || 0; vb = parseFloat(vb) || 0; return (va - vb) * d; }
                if (col === 'numero_pedido' || col === 'serie_pedido') { va = Number(va) || 0; vb = Number(vb) || 0; return (va - vb) * d; }
                va = String(va || '').toLowerCase(); vb = String(vb || '').toLowerCase();
                return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
            });
        }

        function sortTablePedidosModal(column) {
            if (modalPedidosSortPedidos.column === column) modalPedidosSortPedidos.direction = modalPedidosSortPedidos.direction === 'asc' ? 'desc' : 'asc';
            else { modalPedidosSortPedidos.column = column; modalPedidosSortPedidos.direction = 'desc'; }
            modalPedidosCurrentPage = 1;
            renderModalPedidosVisitadorBody();
        }

        function changePagePedidosModal(delta) {
            const aprovados = modalPedidosAprovadosFiltered || [];
            const recusados = modalPedidosRecusadosFiltered || [];
            let combined;
            if (modalPedidosTipoFilter === 'aprovados') {
                combined = aprovados.map(function (p) { return { ...p, tipo: 'Aprovado' }; });
            } else if (modalPedidosTipoFilter === 'recusados') {
                combined = recusados.map(function (p) { return { ...p, tipo: 'Recusado' }; });
            } else {
                combined = aprovados.map(function (p) { return { ...p, tipo: 'Aprovado' }; }).concat(recusados.map(function (p) { return { ...p, tipo: 'Recusado' }; }));
            }
            const sorted = sortPedidosModal(combined, modalPedidosSortPedidos.column, modalPedidosSortPedidos.direction);
            const total = sorted.length;
            const totalPages = Math.max(1, Math.ceil(total / modalPedidosPageSize));
            modalPedidosCurrentPage = Math.max(1, Math.min(totalPages, modalPedidosCurrentPage + delta));
            renderModalPedidosVisitadorBody();
        }

        function renderModalPedidosVisitadorBody() {
            const bodyEl = document.getElementById('modalPedidosVisitadorBody');
            const paginationEl = document.getElementById('modalPedidosVisitadorPagination');
            const btnPrev = document.getElementById('btnPedidosPrev');
            const btnNext = document.getElementById('btnPedidosNext');
            if (!bodyEl) return;
            const esc = function (x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
            const fmtDate = function (d) {
                if (!d) return '—';
                var s = String(d).trim();
                if (s.length >= 10) return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
                return s;
            };
            const fmtMoney = typeof formatMoney === 'function' ? formatMoney : function (v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); };
            const aprovados = modalPedidosAprovadosFiltered || [];
            const recusados = modalPedidosRecusadosFiltered || [];
            let combined;
            if (modalPedidosTipoFilter === 'aprovados') {
                combined = aprovados.map(function (p) { return { ...p, tipo: 'Aprovado' }; });
            } else if (modalPedidosTipoFilter === 'recusados') {
                combined = recusados.map(function (p) { return { ...p, tipo: 'Recusado' }; });
            } else {
                combined = aprovados.map(function (p) { return { ...p, tipo: 'Aprovado' }; }).concat(recusados.map(function (p) { return { ...p, tipo: 'Recusado' }; }));
            }
            const sorted = sortPedidosModal(combined, modalPedidosSortPedidos.column, modalPedidosSortPedidos.direction);
            const total = sorted.length;
            const totalPages = Math.max(1, Math.ceil(total / modalPedidosPageSize));
            const page = Math.max(1, Math.min(modalPedidosCurrentPage, totalPages));
            const start = (page - 1) * modalPedidosPageSize;
            const pageRows = sorted.slice(start, start + modalPedidosPageSize);
            const sortIcon = function (col) {
                if (modalPedidosSortPedidos.column !== col) return '<i class="fas fa-sort" style="margin-left:4px; opacity:0.3;"></i>';
                return modalPedidosSortPedidos.direction === 'asc' ? '<i class="fas fa-sort-up" style="margin-left:4px;"></i>' : '<i class="fas fa-sort-down" style="margin-left:4px;"></i>';
            };
            var thTheme = 'neutral';
            if (modalPedidosTipoFilter === 'aprovados') thTheme = 'success';
            else if (modalPedidosTipoFilter === 'recusados') thTheme = 'danger';
            let html = '<div style="padding:0;"><table class="relatorio-table">';
            html += '<thead><tr>';
            html += '<th data-theme="' + thTheme + '">#</th>';
            html += '<th data-theme="' + thTheme + '" onclick="sortTablePedidosModal(\'numero_pedido\')" style="cursor:pointer; user-select:none;">Nº Pedido ' + sortIcon('numero_pedido') + '</th>';
            html += '<th data-theme="' + thTheme + '" onclick="sortTablePedidosModal(\'serie_pedido\')" style="text-align:center; cursor:pointer; user-select:none;">Série ' + sortIcon('serie_pedido') + '</th>';
            html += '<th data-theme="' + thTheme + '" onclick="sortTablePedidosModal(\'data_aprovacao\')" style="cursor:pointer; user-select:none;">Data ' + sortIcon('data_aprovacao') + '</th>';
            html += '<th data-theme="' + thTheme + '" onclick="sortTablePedidosModal(\'prescritor\')" style="cursor:pointer; user-select:none;">Prescritor ' + sortIcon('prescritor') + '</th>';
            html += '<th data-theme="' + thTheme + '" onclick="sortTablePedidosModal(\'cliente\')" style="cursor:pointer; user-select:none;">Cliente ' + sortIcon('cliente') + '</th>';
            html += '<th data-theme="' + thTheme + '" onclick="sortTablePedidosModal(\'valor\')" style="text-align:right; cursor:pointer; user-select:none;">Valor ' + sortIcon('valor') + '</th>';
            html += '<th data-theme="' + thTheme + '" style="text-align:center;">Status</th></tr></thead><tbody>';
            if (pageRows.length === 0) {
                var emptyMsg = 'Nenhum pedido encontrado.';
                if (modalPedidosTipoFilter === 'aprovados') emptyMsg = 'Nenhum pedido aprovado encontrado para este prescritor.';
                if (modalPedidosTipoFilter === 'recusados') emptyMsg = 'Nenhum pedido recusado/no carrinho encontrado para este prescritor.';
                html += '<tr><td colspan="8" style="padding:24px; text-align:center; color:var(--text-secondary);">' + emptyMsg + '</td></tr>';
            } else {
                pageRows.forEach(function (p, i) {
                    const idx = start + i + 1;
                    const isAprovado = p.tipo === 'Aprovado';
                    const n = p.numero_pedido;
                    var sv = p.serie_pedido !== undefined ? p.serie_pedido : p.serie;
                    var isEmpty = (sv === null || sv === undefined || sv === '' || (typeof sv === 'string' && String(sv).trim() === ''));
                    var sVal = isEmpty ? 0 : sv;
                    var serDisplay = isEmpty ? '0' : esc(String(sv));
                    const a = modalPedidosAno || '';
                    var rowClass = isAprovado ? 'row-tema-success' : 'row-tema-danger';
                    html += '<tr class="' + rowClass + '" role="button" tabindex="0" onclick="openModalDetalhePedido(' + n + ',' + sVal + ',\'' + String(a).replace(/'/g, "\\'") + '\')" style="cursor:pointer;" title="Ver detalhes e componentes do pedido">';
                    html += '<td style="padding:12px 16px; color:var(--text-secondary);">' + idx + '</td>';
                    html += '<td style="padding:12px 16px;">' + esc(p.numero_pedido) + '</td>';
                    html += '<td style="padding:12px 16px; text-align:center;">' + serDisplay + '</td>';
                    html += '<td style="padding:12px 16px;">' + esc(fmtDate(p.data_aprovacao)) + '</td>';
                    html += '<td style="padding:12px 16px;">' + esc(p.prescritor) + '</td>';
                    html += '<td style="padding:12px 16px;">' + esc(p.cliente) + '</td>';
                    html += '<td style="padding:12px 16px; text-align:right; font-weight:600; color:' + (isAprovado ? 'var(--success)' : 'var(--danger)') + ';">' + esc(fmtMoney(p.valor)) + '</td>';
                    html += '<td style="padding:12px 16px; text-align:center;"><span class="badge-status ' + (isAprovado ? 'aprovado' : 'recusado') + '">' + esc(p.tipo) + '</span></td></tr>';
                });
            }
            html += '</tbody></table></div>';
            bodyEl.innerHTML = html;
            const end = Math.min(start + modalPedidosPageSize, total);
            if (paginationEl) paginationEl.textContent = total ? ('Mostrando ' + (start + 1) + '-' + end + ' de ' + total) : 'Mostrando 0-0 de 0';
            if (btnPrev) btnPrev.disabled = page <= 1;
            if (btnNext) btnNext.disabled = page >= totalPages;
        }

        function closeModalPedidosVisitador() {
            const modal = document.getElementById('modalPedidosVisitador');
            if (modal) modal.style.display = 'none';
        }

        function closeModalComponentesPrescritor() {
            const modal = document.getElementById('modalComponentesPrescritor');
            if (modal) modal.style.display = 'none';
        }

        var __modalAnalisesCharts = {};
        var __modalAnalisesPrescritorNome = '';

        function closeModalAnalisesPrescritor() {
            var modal = document.getElementById('modalAnalisesPrescritor');
            if (modal) modal.style.display = 'none';
            Object.keys(__modalAnalisesCharts).forEach(function(k) {
                if (__modalAnalisesCharts[k]) { __modalAnalisesCharts[k].destroy(); __modalAnalisesCharts[k] = null; }
            });
            __modalAnalisesCharts = {};
        }

        function __analiseCalcScore(kpis, comparativo, tendencia) {
            var s = 0;
            var tk = kpis.ticket_medio || 0;
            var tkCart = comparativo.media_ticket_carteira || 1;
            var ratio = tk / tkCart;
            s += ratio >= 1.5 ? 25 : ratio >= 1 ? 20 : ratio >= 0.5 ? Math.round(ratio * 30) : Math.round(ratio * 20);
            var vp = tendencia.variacao_pct || 0;
            s += vp >= 30 ? 25 : vp >= 10 ? 20 : vp >= 0 ? 15 : vp >= -20 ? 8 : 0;
            s += Math.min(25, Math.round((kpis.taxa_aprovacao || 0) / 100 * 25));
            var d = kpis.dias_ultima_compra;
            s += d === null ? 0 : d <= 14 ? 25 : d <= 30 ? 20 : d <= 60 ? 15 : d <= 90 ? 10 : d <= 120 ? 5 : 0;
            return Math.min(100, Math.max(0, s));
        }

        function __fmtR$(v) { return 'R$ ' + parseFloat(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
        function __fmtPct(v) { return parseFloat(v || 0).toFixed(1) + '%'; }
        function __fmtInt(v) { return parseInt(v || 0).toLocaleString('pt-BR'); }
        function __fmtData(d) {
            if (!d) return '—';
            var p = String(d).substring(0,10).split('-');
            return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : d;
        }

        function __renderAnaliseCompleta(bodyEl, data) {
            if (!bodyEl) return;
            var kpis = data.kpis || {};
            var comp = data.comparativo || {};
            var tend = data.tendencia || {};
            var vis = data.visitas || {};
            var rec = data.recorrencia || {};
            var score = __analiseCalcScore(kpis, comp, tend);
            var scoreCor = score >= 70 ? '#059669' : score >= 40 ? '#d97706' : '#dc2626';
            var scoreLabel = score >= 70 ? 'Saudável' : score >= 40 ? 'Atenção' : 'Crítico';
            var scoreIcon = score >= 70 ? 'fa-heart' : score >= 40 ? 'fa-exclamation-triangle' : 'fa-times-circle';

            var insights = [];
            if (kpis.concentracao_carteira > 0) insights.push('<i class="fas fa-chart-pie" style="color:#6366f1;margin-right:4px;"></i>Representa <b>' + __fmtPct(kpis.concentracao_carteira) + '</b> do faturamento da carteira');
            if (tend.variacao_pct > 0) insights.push('<i class="fas fa-arrow-up" style="color:#059669;margin-right:4px;"></i>Tendência de <b>crescimento de ' + __fmtPct(tend.variacao_pct) + '</b> (3 meses recentes vs anteriores)');
            else if (tend.variacao_pct < 0) insights.push('<i class="fas fa-arrow-down" style="color:#dc2626;margin-right:4px;"></i>Tendência de <b>queda de ' + __fmtPct(Math.abs(tend.variacao_pct)) + '</b> (3 meses recentes vs anteriores)');
            if (comp.media_margem_carteira > 0) {
                var diffM = (kpis.margem_media || 0) - comp.media_margem_carteira;
                if (diffM > 0) insights.push('<i class="fas fa-arrow-up" style="color:#059669;margin-right:4px;"></i>Margem <b>' + Math.abs(diffM).toFixed(1) + '% acima</b> da média da carteira');
                else if (diffM < 0) insights.push('<i class="fas fa-arrow-down" style="color:#dc2626;margin-right:4px;"></i>Margem <b>' + Math.abs(diffM).toFixed(1) + '% abaixo</b> da média da carteira');
            }
            if (vis.total_visitas > 0 && kpis.pedidos_aprovados > 0) insights.push('<i class="fas fa-route" style="color:#8b5cf6;margin-right:4px;"></i>' + vis.total_visitas + ' visitas resultaram em <b>' + kpis.pedidos_aprovados + ' pedidos aprovados</b>');
            if (rec.taxa_recorrencia > 0) insights.push('<i class="fas fa-redo" style="color:#0891b2;margin-right:4px;"></i><b>' + __fmtPct(rec.taxa_recorrencia) + '</b> dos pacientes retornam para novas compras');

            function kpiCard(icon, label, value, sub) {
                return '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:12px 14px;min-width:0;flex:1;">' +
                    '<div style="font-size:0.7rem;color:var(--text-secondary);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><i class="fas ' + icon + '" style="margin-right:4px;"></i>' + label + '</div>' +
                    '<div style="font-size:1rem;font-weight:700;color:var(--text-primary);white-space:nowrap;">' + value + '</div>' +
                    (sub ? '<div style="font-size:0.65rem;color:var(--text-secondary);margin-top:2px;">' + sub + '</div>' : '') +
                    '</div>';
            }

            function compSub(val, media, fmt) {
                if (!media || media === 0) return '';
                var diff = val - media;
                var icon = diff >= 0 ? '▲' : '▼';
                var cor = diff >= 0 ? '#059669' : '#dc2626';
                return '<span style="color:' + cor + ';">' + icon + ' ' + (fmt === 'pct' ? Math.abs(diff).toFixed(1) + '%' : __fmtR$(Math.abs(diff))) + '</span> vs carteira';
            }

            var html = '';

            // ── Score ──
            html += '<div class="analise-score-row" style="display:flex;align-items:center;gap:16px;margin-bottom:16px;padding:14px 18px;background:var(--bg-body);border:1px solid var(--border);border-radius:12px;">';
            html += '<div class="analise-score-circle" style="position:relative;width:70px;height:70px;flex-shrink:0;">';
            html += '<svg viewBox="0 0 36 36" style="width:70px;height:70px;transform:rotate(-90deg);">';
            html += '<circle cx="18" cy="18" r="15.5" fill="none" stroke="rgba(128,128,128,0.15)" stroke-width="3"/>';
            html += '<circle cx="18" cy="18" r="15.5" fill="none" stroke="' + scoreCor + '" stroke-width="3" stroke-dasharray="' + (score * 97.4 / 100) + ' 97.4" stroke-linecap="round"/>';
            html += '</svg>';
            html += '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:1.1rem;font-weight:800;color:' + scoreCor + ';">' + score + '</div></div>';
            html += '<div style="flex:1;min-width:0;">';
            html += '<div style="font-size:0.95rem;font-weight:700;color:' + scoreCor + ';"><i class="fas ' + scoreIcon + '" style="margin-right:6px;"></i>Score de Saúde: ' + scoreLabel + '</div>';
            html += '<div style="font-size:0.75rem;color:var(--text-secondary);margin-top:4px;">Volume + Tendência + Aprovação + Recência</div>';
            html += '</div></div>';

            // ── Insights ──
            if (insights.length > 0) {
                html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">';
                insights.forEach(function(ins) {
                    html += '<div class="analise-insight-item" style="flex:1;min-width:220px;padding:8px 12px;background:var(--bg-body);border:1px solid var(--border);border-radius:8px;font-size:0.75rem;color:var(--text-secondary);line-height:1.4;">' + ins + '</div>';
                });
                html += '</div>';
            }

            // ── KPI Cards ──
            html += '<div class="analise-kpi-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:18px;">';
            html += kpiCard('fa-check-circle', 'Faturamento aprovado', __fmtR$(kpis.total_aprovado), '');
            html += kpiCard('fa-times-circle', 'Total reprovado', __fmtR$(kpis.total_reprovado), '');
            html += kpiCard('fa-percentage', 'Taxa aprovação', __fmtPct(kpis.taxa_aprovacao), compSub(kpis.taxa_aprovacao, comp.media_taxa_aprovacao_carteira, 'pct'));
            html += kpiCard('fa-receipt', 'Ticket médio', __fmtR$(kpis.ticket_medio), compSub(kpis.ticket_medio, comp.media_ticket_carteira, 'money'));
            html += kpiCard('fa-coins', 'Margem média', __fmtPct(kpis.margem_media), compSub(kpis.margem_media, comp.media_margem_carteira, 'pct'));
            html += kpiCard('fa-users', 'Pacientes', __fmtInt(kpis.total_pacientes), rec.pacientes_recorrentes > 0 ? rec.pacientes_recorrentes + ' recorrentes' : '');
            html += kpiCard('fa-shopping-bag', 'Pedidos', __fmtInt(kpis.total_pedidos), kpis.pedidos_aprovados + ' aprov. / ' + (kpis.pedidos_reprovados||0) + ' reprov.');
            html += kpiCard('fa-clock', 'Última compra', kpis.dias_ultima_compra !== null ? kpis.dias_ultima_compra + ' dias' : '—', '');
            html += '</div>';

            // ── Gráficos de evolução (3 linhas) ──
            var meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
            function chartSection(id, titulo) {
                return '<div class="analise-chart-section" style="margin-bottom:18px;"><h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">' + titulo + '</h4><div class="analise-chart-wrap" style="height:200px;position:relative;"><canvas id="' + id + '"></canvas></div></div>';
            }
            html += chartSection('chartAnalVendas', 'Evolução mensal — Valores aprovados x reprovados (R$)');
            html += chartSection('chartAnalPedidos', 'Evolução mensal — Pedidos aprovados x reprovados (qtd)');
            html += chartSection('chartAnalComponentes', 'Evolução mensal — Componentes aprovados x reprovados (qtd)');

            // ── Distribuições (doughnuts + top componentes) ──
            var topFormas = data.top_formas || [];
            var distCanal = data.distribuicao_canal || [];
            var topCompAprov = data.top_componentes || [];
            var topCompReprov = data.top_componentes_reprovados || [];

            if (topFormas.length > 0 || distCanal.length > 0) {
                html += '<div class="analise-grid-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">';
                if (topFormas.length > 0) {
                    html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;">';
                    html += '<h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">Top formas farmacêuticas</h4>';
                    html += '<div class="analise-doughnut-wrap" style="height:180px;position:relative;"><canvas id="chartAnalFormas"></canvas></div></div>';
                }
                if (distCanal.length > 0) {
                    html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;">';
                    html += '<h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;">Canais de atendimento</h4>';
                    html += '<div class="analise-doughnut-wrap" style="height:180px;position:relative;"><canvas id="chartAnalCanais"></canvas></div></div>';
                }
                html += '</div>';
            }

            html += '<div class="analise-grid-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px;">';
            html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;">';
            html += '<h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;"><i class="fas fa-check-circle" style="color:#059669;margin-right:6px;"></i>Top 10 componentes aprovados (por pedido)</h4>';
            html += '<div class="analise-bar-wrap" style="height:' + Math.max(140, Math.min(10, topCompAprov.length) * 28) + 'px;position:relative;min-height:140px;"><canvas id="chartAnalTopCompAprov"></canvas></div>';
            if (topCompAprov.length === 0) html += '<p style="font-size:0.8rem;color:var(--text-secondary);margin:8px 0 0 0;">Nenhum componente aprovado no período.</p>';
            html += '</div>';
            html += '<div style="background:var(--bg-body);border:1px solid var(--border);border-radius:10px;padding:14px;">';
            html += '<h4 style="font-size:0.85rem;color:var(--text-secondary);margin:0 0 8px 0;font-weight:600;"><i class="fas fa-times-circle" style="color:#dc2626;margin-right:6px;"></i>Top 10 componentes reprovados (por pedido)</h4>';
            html += '<div class="analise-bar-wrap" style="height:' + Math.max(140, Math.min(10, topCompReprov.length) * 28) + 'px;position:relative;min-height:140px;"><canvas id="chartAnalTopCompReprov"></canvas></div>';
            if (topCompReprov.length === 0) html += '<p style="font-size:0.8rem;color:var(--text-secondary);margin:8px 0 0 0;">Nenhum componente reprovado no período.</p>';
            html += '</div>';
            html += '</div>';

            // ── Visitas e recorrência ──
            html += '<div class="analise-kpi-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:8px;">';
            html += kpiCard('fa-map-marker-alt', 'Visitas realizadas', __fmtInt(vis.total_visitas), '');
            html += kpiCard('fa-calendar-check', 'Última visita', __fmtData(vis.ultima_visita), vis.dias_sem_visita !== null ? vis.dias_sem_visita + ' dias atrás' : '');
            html += kpiCard('fa-redo', 'Recorrência', __fmtPct(rec.taxa_recorrencia), rec.pacientes_recorrentes + ' de ' + rec.total_pacientes + ' pacientes');
            html += kpiCard('fa-calendar-alt', 'Meses ativos', __fmtInt(kpis.meses_ativos) + ' de 12', '');
            html += '</div>';

            // ── Botão fechar ──
            html += '<div style="text-align:right;padding-top:12px;border-top:1px solid var(--border);margin-top:8px;"><button type="button" onclick="closeModalAnalisesPrescritor()" style="padding:10px 20px;border-radius:8px;border:1px solid var(--border);background:var(--bg-body);color:var(--text-primary);font-weight:600;cursor:pointer;">Fechar</button></div>';

            bodyEl.classList.add('analise-content');
            bodyEl.innerHTML = html;

            // ── Criar gráficos Chart.js ──
            Object.keys(__modalAnalisesCharts).forEach(function(k) { if (__modalAnalisesCharts[k]) __modalAnalisesCharts[k].destroy(); });
            __modalAnalisesCharts = {};

            var chartOpts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } }, scales: { y: { beginAtZero: true } } };
            var vendas = data.vendas_mensal || [];
            var pedidos = data.pedidos_mensal || [];
            var comps = data.componentes_mensal || [];

            var cvEl = document.getElementById('chartAnalVendas');
            if (cvEl) {
                __modalAnalisesCharts.vendas = new Chart(cvEl.getContext('2d'), {
                    type: 'line', data: { labels: meses, datasets: [
                        { label: 'Aprovadas (R$)', data: vendas.map(function(r){return parseFloat(r.valor_aprovado)||0;}), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.08)', fill: true, tension: 0.3, pointRadius: 3 },
                        { label: 'Reprovadas (R$)', data: vendas.map(function(r){return parseFloat(r.valor_reprovado)||0;}), borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.08)', fill: true, tension: 0.3, pointRadius: 3 }
                    ]}, options: chartOpts
                });
            }
            var cpEl = document.getElementById('chartAnalPedidos');
            if (cpEl) {
                __modalAnalisesCharts.pedidos = new Chart(cpEl.getContext('2d'), {
                    type: 'line', data: { labels: meses, datasets: [
                        { label: 'Aprovados (qtd)', data: pedidos.map(function(r){return parseInt(r.qtd_aprovado)||0;}), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.08)', fill: true, tension: 0.3, pointRadius: 3 },
                        { label: 'Reprovados (qtd)', data: pedidos.map(function(r){return parseInt(r.qtd_reprovado)||0;}), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.08)', fill: true, tension: 0.3, pointRadius: 3 }
                    ]}, options: chartOpts
                });
            }
            var ccEl = document.getElementById('chartAnalComponentes');
            if (ccEl) {
                __modalAnalisesCharts.componentes = new Chart(ccEl.getContext('2d'), {
                    type: 'line', data: { labels: meses, datasets: [
                        { label: 'Aprovados (qtd)', data: comps.map(function(r){return parseFloat(r.qtd_aprovado)||0;}), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.08)', fill: true, tension: 0.3, pointRadius: 3 },
                        { label: 'Reprovados (qtd)', data: comps.map(function(r){return parseFloat(r.qtd_reprovado)||0;}), borderColor: '#DC2626', backgroundColor: 'rgba(220,38,38,0.08)', fill: true, tension: 0.3, pointRadius: 3 }
                    ]}, options: chartOpts
                });
            }

            var doughnutOpts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 10 }, boxWidth: 12 } } } };
            var cores = ['#6366f1','#059669','#f59e0b','#dc2626','#0891b2','#8b5cf6','#ec4899'];

            if (topFormas.length > 0) {
                var cfEl = document.getElementById('chartAnalFormas');
                if (cfEl) {
                    __modalAnalisesCharts.formas = new Chart(cfEl.getContext('2d'), {
                        type: 'doughnut', data: { labels: topFormas.map(function(f){return f.forma||'—';}), datasets: [{ data: topFormas.map(function(f){return parseFloat(f.total)||0;}), backgroundColor: cores }] }, options: doughnutOpts
                    });
                }
            }
            if (distCanal.length > 0) {
                var cnEl = document.getElementById('chartAnalCanais');
                if (cnEl) {
                    __modalAnalisesCharts.canais = new Chart(cnEl.getContext('2d'), {
                        type: 'doughnut', data: { labels: distCanal.map(function(c){return c.canal||'—';}), datasets: [{ data: distCanal.map(function(c){return parseFloat(c.total)||0;}), backgroundColor: cores }] }, options: doughnutOpts
                    });
                }
            }
            if (topCompAprov.length > 0) {
                var tcAEl = document.getElementById('chartAnalTopCompAprov');
                if (tcAEl) {
                    __modalAnalisesCharts.topCompAprov = new Chart(tcAEl.getContext('2d'), {
                        type: 'bar', data: { labels: topCompAprov.map(function(c){return c.componente||'—';}), datasets: [{ label: 'Quantidade', data: topCompAprov.map(function(c){return parseFloat(c.qtd)||0;}), backgroundColor: '#059669', borderRadius: 4 }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                    });
                }
            }
            if (topCompReprov.length > 0) {
                var tcREl = document.getElementById('chartAnalTopCompReprov');
                if (tcREl) {
                    __modalAnalisesCharts.topCompReprov = new Chart(tcREl.getContext('2d'), {
                        type: 'bar', data: { labels: topCompReprov.map(function(c){return c.componente||'—';}), datasets: [{ label: 'Qtd', data: topCompReprov.map(function(c){return parseFloat(c.qtd)||0;}), backgroundColor: '#dc2626', borderRadius: 4 }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
                    });
                }
            }
        }

        async function openModalAnalisesPrescritor(prescritorNome) {
            var modal = document.getElementById('modalAnalisesPrescritor');
            var subtitleEl = document.getElementById('modalAnalisesPrescritorSubtitle');
            var bodyEl = document.getElementById('modalAnalisesPrescritorBody');
            var anoEl = document.getElementById('modalAnalisesPrescritorAno');
            if (!modal || !bodyEl) return;
            __modalAnalisesPrescritorNome = prescritorNome || '';
            var nomeVisitadorContexto = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '').trim() || (localStorage.getItem('userName') || '').trim();
            if (subtitleEl) subtitleEl.textContent = __modalAnalisesPrescritorNome || 'Prescritor';
            var ano = (anoEl && anoEl.value) ? anoEl.value : String(new Date().getFullYear());
            var y = new Date().getFullYear();
            if (anoEl) {
                var opts = [];
                for (var i = 0; i <= 3; i++) opts.push('<option value="' + (y - i) + '"' + (parseInt(ano, 10) === (y - i) ? ' selected' : '') + '>' + (y - i) + '</option>');
                anoEl.innerHTML = opts.join('');
                anoEl.onchange = function () { if (__modalAnalisesPrescritorNome) openModalAnalisesPrescritor(__modalAnalisesPrescritorNome); };
            }
            var jaTinhaConteudo = bodyEl.classList.contains('analise-content') && bodyEl.children.length > 0;
            if (!jaTinhaConteudo) {
                bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando análise completa...</div>';
            } else {
                var overlay = document.getElementById('analiseLoadingOverlay');
                if (overlay) overlay.remove();
                overlay = document.createElement('div');
                overlay.id = 'analiseLoadingOverlay';
                overlay.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;z-index:100;border-radius:0 0 12px 12px;';
                overlay.innerHTML = '<div style="background:var(--bg-card);padding:16px 24px;border-radius:10px;border:1px solid var(--border);display:flex;align-items:center;gap:12px;"><i class="fas fa-spinner fa-spin" style="font-size:1.2rem;color:var(--primary);"></i><span style="color:var(--text-primary);font-weight:600;">Atualizando...</span></div>';
                bodyEl.style.position = 'relative';
                bodyEl.appendChild(overlay);
                if (anoEl) anoEl.disabled = true;
            }
            modal.style.display = 'flex';
            try {
                var res = await apiGet('analise_prescritor', { prescritor: __modalAnalisesPrescritorNome, ano: ano, nome: nomeVisitadorContexto });
                var overlay2 = document.getElementById('analiseLoadingOverlay');
                if (overlay2) overlay2.remove();
                if (anoEl) anoEl.disabled = false;
                if (res && res.error) {
                    bodyEl.classList.remove('analise-content');
                    bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">' + String(res.error).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</div>';
                    return;
                }
                __renderAnaliseCompleta(bodyEl, res);
            } catch (e) {
                var overlay3 = document.getElementById('analiseLoadingOverlay');
                if (overlay3) overlay3.remove();
                if (anoEl) anoEl.disabled = false;
                bodyEl.classList.remove('analise-content');
                bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">Erro ao carregar análises.</div>';
            }
        }

        function fmtQtdComponente(qtd, unidade) {
            if (qtd === null || qtd === undefined || qtd === '') return '—';
            var n = parseFloat(qtd);
            if (isNaN(n)) return String(qtd).trim() + (unidade ? ' ' + String(unidade).trim() : '');
            var s = Number.isInteger(n) ? String(Math.round(n)) : String(n);
            var u = (unidade && String(unidade).trim()) ? ' ' + String(unidade).trim() : '';
            return s + u;
        }

        function renderModalComponentesTabela(bodyEl, sortCol, sortDir) {
            var data = window.__modalComponentesData;
            if (!data || !bodyEl) return;
            var list = (data.list || []).slice();
            var filterText = (data.filterText || '').trim().toLowerCase();
            if (filterText) {
                list = list.filter(function (c) {
                    var comp = (c.componente || '').toLowerCase();
                    var un = (c.unidade || '').toLowerCase();
                    return comp.indexOf(filterText) !== -1 || un.indexOf(filterText) !== -1;
                });
            }
            var isAprovados = data.isAprovados;
            var thTheme = isAprovados ? 'success' : 'danger';
            var rowClass = isAprovados ? 'row-tema-success' : 'row-tema-danger';
            var esc = function (x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
            var sortNum = function (a, b, key) { var va = parseFloat(a[key]) || 0; var vb = parseFloat(b[key]) || 0; return va - vb; };
            var sortStr = function (a, b, key) { var va = (a[key] || '').toString().toLowerCase(); var vb = (b[key] || '').toString().toLowerCase(); return va < vb ? -1 : (va > vb ? 1 : 0); };
            var d = sortDir === 'asc' ? 1 : -1;
            list.sort(function (a, b) {
                if (sortCol === 'componente') return sortStr(a, b, 'componente') * d;
                if (sortCol === 'unidade') return sortStr(a, b, 'unidade') * d;
                if (sortCol === 'quantidade_total') return sortNum(a, b, 'quantidade_total') * d;
                if (sortCol === 'qtd_pedidos') { var va = parseInt(a.qtd_pedidos, 10) || 0; var vb = parseInt(b.qtd_pedidos, 10) || 0; return (va - vb) * d; }
                return 0;
            });
            var icon = function (col) {
                if (col !== sortCol) return ' <i class="fas fa-sort" style="opacity:0.5; font-size:0.75rem;"></i>';
                return sortDir === 'asc' ? ' <i class="fas fa-sort-up" style="font-size:0.75rem;"></i>' : ' <i class="fas fa-sort-down" style="font-size:0.75rem;"></i>';
            };
            var totalPedidos = list.reduce(function (acc, c) { return acc + (parseInt(c.qtd_pedidos, 10) || 0); }, 0);
            var totalComponentes = list.length;
            var cardClass = isAprovados ? 'card-success' : 'card-danger';
            var dataDeVal = (data.dataDe || '').trim() || (function () { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01'; })();
            var dataAteVal = (data.dataAte || '').trim() || (function () { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
            var filterValue = (data.filterText || '');
            var filtersHtml = '<div class="pedidos-filters">' +
                '<div><label for="modalComponentesDataDe">De</label><input type="date" id="modalComponentesDataDe" value="' + esc(dataDeVal) + '"></div>' +
                '<div><label for="modalComponentesDataAte">Até</label><input type="date" id="modalComponentesDataAte" value="' + esc(dataAteVal) + '"></div>' +
                '<div><button type="button" class="btn-aplicar" id="modalComponentesBtnAplicar"><i class="fas fa-filter" style="margin-right:6px;"></i>Aplicar</button></div>' +
                '<div class="pedidos-search-wrap"><i class="fas fa-search pedidos-search-icon"></i><input type="text" id="modalComponentesFilterInput" placeholder="Buscar por componente ou unidade..." value="' + esc(filterValue) + '" autocomplete="off"></div>' +
                '</div>';
            var cardsHtml = '<div class="modal-componentes-cards">' +
                '<div class="card-mini ' + cardClass + '"><div class="card-mini-label"><i class="fas fa-cubes" style="margin-right:4px;"></i>Componentes</div><div class="card-mini-value">' + totalComponentes + '</div></div>' +
                '<div class="card-mini ' + cardClass + '"><div class="card-mini-label"><i class="fas fa-shopping-cart" style="margin-right:4px;"></i>Total pedidos</div><div class="card-mini-value">' + totalPedidos + '</div></div>' +
                '</div>';
            var html = filtersHtml + cardsHtml + '<div class="modal-componentes-table-wrap"><table class="relatorio-table">' +
                '<thead><tr>' +
                '<th data-theme="' + thTheme + '" style="text-align:left; cursor:pointer; user-select:none; min-width:140px;" title="Clique para ordenar">Componente' + icon('componente') + '</th>' +
                '<th data-theme="' + thTheme + '" style="text-align:left; cursor:pointer; user-select:none; width:80px;" title="Clique para ordenar">Unidade' + icon('unidade') + '</th>' +
                '<th data-theme="' + thTheme + '" style="text-align:right; cursor:pointer; user-select:none; width:120px;" title="Clique para ordenar">Quantidade total' + icon('quantidade_total') + '</th>' +
                '<th data-theme="' + thTheme + '" style="text-align:center; cursor:pointer; user-select:none; width:80px;" title="Clique para ordenar">Pedidos' + icon('qtd_pedidos') + '</th></tr></thead><tbody>';
            if (list.length === 0) {
                var emptyMsg = filterText ? 'Nenhum componente encontrado para o filtro.' : 'Nenhum componente no período selecionado.';
                html += '<tr><td colspan="4" style="padding:24px 16px; text-align:center; color:var(--text-secondary);">' + emptyMsg + '</td></tr>';
            } else {
                list.forEach(function (c) {
                    var qtdStr = fmtQtdComponente(c.quantidade_total, c.unidade);
                    var qtdPed = (c.qtd_pedidos != null && c.qtd_pedidos !== '') ? parseInt(c.qtd_pedidos, 10) : 0;
                    html += '<tr class="' + rowClass + '">' +
                        '<td style="padding:12px 16px;">' + esc(c.componente) + '</td>' +
                        '<td style="padding:12px 16px; color:var(--text-secondary);">' + esc(c.unidade) + '</td>' +
                        '<td style="padding:12px 16px; text-align:right; font-weight:600; color:' + (isAprovados ? 'var(--success)' : 'var(--danger)') + ';">' + esc(qtdStr) + '</td>' +
                        '<td style="padding:12px 16px; text-align:center;">' + qtdPed + '</td></tr>';
                });
            }
            html += '</tbody></table></div>';
            bodyEl.setAttribute('data-sort-col', sortCol);
            bodyEl.setAttribute('data-sort-dir', sortDir);
            bodyEl.innerHTML = html;
            var filterInput = document.getElementById('modalComponentesFilterInput');
            if (filterInput) {
                filterInput.oninput = function () {
                    if (window.__modalComponentesData) window.__modalComponentesData.filterText = this.value;
                    var selStart = this.selectionStart, selEnd = this.selectionEnd;
                    renderModalComponentesTabela(bodyEl, bodyEl.getAttribute('data-sort-col') || 'qtd_pedidos', bodyEl.getAttribute('data-sort-dir') || 'desc');
                    var newInput = document.getElementById('modalComponentesFilterInput');
                    if (newInput) {
                        newInput.focus();
                        newInput.setSelectionRange(selStart, selEnd);
                    }
                };
            }
            var inputDe = document.getElementById('modalComponentesDataDe');
            var inputAte = document.getElementById('modalComponentesDataAte');
            var btnAplicar = document.getElementById('modalComponentesBtnAplicar');
            if (btnAplicar) {
                btnAplicar.onclick = function () {
                    if (!window.__modalComponentesData || !inputDe || !inputAte) return;
                    var de = (inputDe.value || '').trim();
                    var ate = (inputAte.value || '').trim();
                    if (!de || !ate || de > ate) return;
                    window.__modalComponentesData.dataDe = de;
                    window.__modalComponentesData.dataAte = ate;
                    if (typeof refetchModalComponentes === 'function') refetchModalComponentes();
                };
            }
            bodyEl.querySelectorAll('thead th').forEach(function (th, idx) {
                var cols = ['componente', 'unidade', 'quantidade_total', 'qtd_pedidos'];
                var col = cols[idx];
                if (!col) return;
                th.onclick = function () {
                    var curCol = bodyEl.getAttribute('data-sort-col');
                    var curDir = bodyEl.getAttribute('data-sort-dir');
                    var newDir = (curCol === col && curDir === 'asc') ? 'desc' : 'asc';
                    renderModalComponentesTabela(bodyEl, col, newDir);
                };
            });
        }

        async function refetchModalComponentes() {
            var data = window.__modalComponentesData;
            if (!data || !data.prescritorNome) return;
            var bodyEl = document.getElementById('modalComponentesPrescritorBody');
            if (!bodyEl) return;
            var dataDe = (data.dataDe || '').trim();
            var dataAte = (data.dataAte || '').trim();
            if (!dataDe || !dataAte || dataDe > dataAte) return;
            var nome = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '').trim() || (localStorage.getItem('userName') || '').trim();
            var params = { prescritor: data.prescritorNome, tipo: data.tipo || (data.isAprovados ? 'aprovados' : 'recusados'), data_de: dataDe, data_ate: dataAte };
            if (nome) params.nome = nome;
            bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Atualizando...</div>';
            try {
                var res = await apiGet('list_componentes_prescritor', params);
                if (res && res.error) {
                    bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">' + String(res.error).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;') + '</div>';
                    return;
                }
                var list = Array.isArray(res.componentes) ? res.componentes : [];
                window.__modalComponentesData.list = list;
                window.__modalComponentesData.dataDe = dataDe;
                window.__modalComponentesData.dataAte = dataAte;
                window.__modalComponentesData.filterText = (window.__modalComponentesData.filterText || '').trim();
                renderModalComponentesTabela(bodyEl, 'qtd_pedidos', 'desc');
            } catch (e) {
                bodyEl.innerHTML = '<div style="text-align:center; padding:24px; color:var(--danger);">Erro ao atualizar por período.</div>';
            }
        }

        async function openModalComponentesPrescritor(prescritorNome, tipo) {
            const modal = document.getElementById('modalComponentesPrescritor');
            const titleEl = document.getElementById('modalComponentesPrescritorTitle');
            const subtitleEl = document.getElementById('modalComponentesPrescritorSubtitle');
            const bodyEl = document.getElementById('modalComponentesPrescritorBody');
            if (!modal || !bodyEl) return;
            const nome = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '').trim() || (localStorage.getItem('userName') || '').trim();
            const anoEl = document.getElementById('anoSelect');
            const ano = (anoEl && anoEl.value) ? anoEl.value : (new Date().getFullYear()).toString();
            const isAprovados = (tipo || 'aprovados') === 'aprovados';
            if (titleEl) titleEl.innerHTML = isAprovados
                ? '<i class="fas fa-atom" style="color:var(--success); margin-right:8px;"></i>Componentes aprovados'
                : '<i class="fas fa-atom" style="color:var(--danger); margin-right:8px;"></i>Componentes recusados';
            var d = new Date();
            var dataAteDefault = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            var dataDeDefault = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01';
            if (subtitleEl) subtitleEl.textContent = (prescritorNome || '') + ' — ' + ano;
            bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
            modal.style.display = 'flex';
            function esc(x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
            try {
                if (!prescritorNome) {
                    bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--danger);">Prescritor não informado.</div>';
                    return;
                }
                const params = { prescritor: prescritorNome, ano: ano, tipo: isAprovados ? 'aprovados' : 'recusados', data_de: dataDeDefault, data_ate: dataAteDefault };
                if (nome) params.nome = nome;
                const res = await apiGet('list_componentes_prescritor', params);
                if (res && res.error) {
                    bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--danger);">' + esc(res.error) + '</div>';
                    return;
                }
                const componentes = Array.isArray(res.componentes) ? res.componentes : [];
                window.__modalComponentesData = { list: componentes.slice(), isAprovados: isAprovados, filterText: '', prescritorNome: prescritorNome, tipo: isAprovados ? 'aprovados' : 'recusados', dataDe: dataDeDefault, dataAte: dataAteDefault };
                renderModalComponentesTabela(bodyEl, 'qtd_pedidos', 'desc');
            } catch (e) {
                bodyEl.innerHTML = '<div style="text-align:center; padding:40px 24px; color:var(--danger);">Erro ao carregar componentes.</div>';
            }
        }

        async function openModalDetalhePedido(numero, serie, ano) {
            var modal = document.getElementById('modalDetalhePedido');
            var loading = document.getElementById('modalDetalhePedidoLoading');
            var errEl = document.getElementById('modalDetalhePedidoError');
            var content = document.getElementById('modalDetalhePedidoContent');
            if (!modal || !loading) return;
            modal.style.display = 'flex';
            loading.style.display = 'block';
            if (errEl) errEl.style.display = 'none';
            if (content) content.style.display = 'none';
            var params = { numero: numero, serie: serie };
            if (ano) params.ano = ano;
            function esc(x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
            function fmtDate(d) {
                if (!d) return '—';
                var s = String(d).trim();
                if (s.length >= 10) return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
                return s;
            }
            function fmtMoney(v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); }
            function fmtQtdCalculada(qtd, unidade) {
                if (qtd === null || qtd === undefined || qtd === '') return '—';
                var n = parseFloat(qtd);
                if (isNaN(n)) return String(qtd).trim() + (unidade ? ' ' + String(unidade).trim() : '');
                var s = Number.isInteger(n) ? String(Math.round(n)) : String(n);
                var u = (unidade && String(unidade).trim()) ? ' ' + String(unidade).trim() : '';
                return s + u;
            }
            try {
                var resDet = await apiGet('get_pedido_detalhe', params);
                if (resDet.error && !resDet.pedido) {
                    loading.style.display = 'none';
                    if (errEl) { errEl.textContent = resDet.error || 'Pedido não encontrado.'; errEl.style.display = 'block'; }
                    return;
                }
                var p = resDet.pedido || resDet.resumo || {};
                var itensGestao = Array.isArray(resDet.itens_gestao) ? resDet.itens_gestao : [];
                var itensOrcamento = Array.isArray(resDet.itens_orcamento) ? resDet.itens_orcamento : [];
                var resComp = await apiGet('get_pedido_componentes', params);
                var componentes = (resComp && resComp.componentes) ? resComp.componentes : [];
                loading.style.display = 'none';
                content.style.display = 'block';
                var fieldsHtml = '';
                var serieDetalhe = (p.serie_pedido !== undefined && p.serie_pedido !== null && String(p.serie_pedido).trim() !== '') ? String(p.serie_pedido).trim() : '0';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Nº / Série</span><div style="font-weight:600;">' + esc(p.numero_pedido) + ' / ' + esc(serieDetalhe) + '</div></div>';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Data aprovação</span><div style="font-weight:600;">' + esc(fmtDate(p.data_aprovacao)) + '</div></div>';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Data orçamento</span><div style="font-weight:600;">' + esc(fmtDate(p.data_orcamento)) + '</div></div>';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Canal</span><div style="font-weight:600;">' + esc(p.canal_atendimento || '—') + '</div></div>';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Cliente / Paciente</span><div style="font-weight:600;">' + esc(p.cliente || p.paciente || '—') + '</div></div>';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Prescritor</span><div style="font-weight:600;">' + esc(p.prescritor || '—') + '</div></div>';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Status</span><div style="font-weight:600;">' + esc(p.status_financeiro || '—') + '</div></div>';
                fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Convênio</span><div style="font-weight:600;">' + esc(p.convenio || '—') + '</div></div>';
                if (p.total_gestao != null) fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Total (gestão)</span><div style="font-weight:600; color:var(--success);">' + fmtMoney(p.total_gestao) + '</div></div>';
                if (p.total_autorizado != null && p.total_autorizado !== '') fieldsHtml += '<div><span style="font-size:0.7rem; color:var(--text-secondary);">Total autorizado</span><div style="font-weight:600; color:var(--success);">' + fmtMoney(p.total_autorizado) + '</div></div>';
                document.getElementById('modalDetalhePedidoFields').innerHTML = fieldsHtml;
                var wrapGestao = document.getElementById('modalDetalhePedidoItensGestaoWrap');
                var tbodyGestao = document.getElementById('modalDetalhePedidoItensGestao');
                if (itensGestao.length > 0 && wrapGestao && tbodyGestao) {
                    wrapGestao.style.display = 'block';
                    var rows = '';
                    itensGestao.forEach(function (it, i) {
                        rows += '<tr style="border-bottom:1px solid var(--border);"><td style="padding:10px 12px;">' + (i + 1) + '</td><td style="padding:10px 12px;">' + esc(it.produto) + '</td><td style="padding:10px 12px;">' + esc(it.forma_farmaceutica) + '</td><td style="padding:10px 12px; text-align:right;">' + esc(it.quantidade) + '</td><td style="padding:10px 12px; text-align:right;">' + fmtMoney(it.preco_liquido) + '</td></tr>';
                    });
                    tbodyGestao.innerHTML = rows;
                } else if (wrapGestao) { wrapGestao.style.display = 'none'; }
                var wrapOrc = document.getElementById('modalDetalhePedidoItensOrcamentoWrap');
                var tbodyOrc = document.getElementById('modalDetalhePedidoItensOrcamento');
                if (itensOrcamento.length > 0 && wrapOrc && tbodyOrc) {
                    wrapOrc.style.display = 'block';
                    var rowsOrc = '';
                    itensOrcamento.forEach(function (it, i) {
                        rowsOrc += '<tr style="border-bottom:1px solid var(--border);"><td style="padding:10px 12px;">' + (i + 1) + '</td><td style="padding:10px 12px;">' + esc(it.descricao) + '</td><td style="padding:10px 12px;">' + esc(it.canal) + '</td><td style="padding:10px 12px; text-align:right;">' + esc(it.quantidade) + '</td><td style="padding:10px 12px; text-align:right;">' + fmtMoney(it.valor_liquido) + '</td><td style="padding:10px 12px;">' + esc(it.usuario_inclusao) + '</td><td style="padding:10px 12px;">' + esc(it.usuario_aprovador) + '</td></tr>';
                    });
                    tbodyOrc.innerHTML = rowsOrc;
                } else if (wrapOrc) { wrapOrc.style.display = 'none'; }
                var tbody = document.getElementById('modalDetalhePedidoComponentes');
                var emptyComp = document.getElementById('modalDetalhePedidoComponentesEmpty');
                if (!componentes || componentes.length === 0) {
                    tbody.innerHTML = '';
                    emptyComp.style.display = 'block';
                } else {
                    emptyComp.style.display = 'none';
                    var ch = '';
                    componentes.forEach(function (c, i) {
                        var qtdUn = esc(fmtQtdCalculada(c.qtd_calculada, c.unidade));
                        ch += '<tr style="border-bottom:1px solid var(--border);"><td style="padding:10px 12px;">' + (i + 1) + '</td><td style="padding:10px 12px;">' + esc(c.descricao || c.componente) + '</td><td style="padding:10px 12px; text-align:right;">' + qtdUn + '</td></tr>';
                    });
                    tbody.innerHTML = ch;
                }
            } catch (e) {
                loading.style.display = 'none';
                if (errEl) { errEl.textContent = e.message || 'Erro ao carregar.'; errEl.style.display = 'block'; }
            }
        }
        function closeModalDetalhePedido() {
            var modal = document.getElementById('modalDetalhePedido');
            if (modal) modal.style.display = 'none';
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
        function getNotificationsListKey() {
            var v = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '').trim() || (localStorage.getItem('userName') || '').trim();
            return 'mypharm_notif_list_' + (v ? String(v).replace(/\s+/g, '_') : 'user');
        }
        function getStoredNotificationsList() {
            try {
                var raw = localStorage.getItem(getNotificationsListKey());
                return raw ? JSON.parse(raw) : [];
            } catch (e) { return []; }
        }
        function setStoredNotificationsList(list) {
            try {
                localStorage.setItem(getNotificationsListKey(), JSON.stringify(Array.isArray(list) ? list : []));
            } catch (e) {}
        }
        window.mergeNotificationsFromAgenda = function () {
            var today = window.__todayAgendaVisits;
            if (!Array.isArray(today)) today = [];
            var stored = getStoredNotificationsList();
            var ids = {};
            stored.forEach(function (x) { ids[x.id] = true; });
            today.forEach(function (v) {
                if (v && v.id && !ids[v.id]) {
                    stored.push({ id: v.id, prescritor: v.prescritor || '-', data_agendada: v.data_agendada, hora: v.hora || '' });
                    ids[v.id] = true;
                }
            });
            setStoredNotificationsList(stored);
            renderNotificationsVisitas();
            updateNotificationsBadge();
        };
        function getNotificationsReadKey() {
            var v = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '').trim() || (localStorage.getItem('userName') || '').trim();
            return 'mypharm_notif_read_' + (v ? String(v).replace(/\s+/g, '_') : 'user');
        }
        function getReadNotifications() {
            try {
                var raw = localStorage.getItem(getNotificationsReadKey());
                return raw ? JSON.parse(raw) : [];
            } catch (e) { return []; }
        }
        function markNotificationRead(id) {
            var read = getReadNotifications();
            if (read.indexOf(id) === -1) read.push(id);
            try { localStorage.setItem(getNotificationsReadKey(), JSON.stringify(read)); } catch (e) {}
            updateNotificationsBadge();
        }
        function deleteNotification(id) {
            var list = getStoredNotificationsList().filter(function (v) { return v.id !== id; });
            setStoredNotificationsList(list);
            renderAllNotifications();
            updateNotificationsBadge();
        }
        window.__notificacoesApi = [];
        window.__mensagensApi = [];
        window.__mensagensRecebidasApi = [];
        async function loadNotificacoesFromAPI() {
            try {
                var r = await apiGet('list_notificacoes');
                if (r && r.success) {
                    window.__notificacoesApi = r.notificacoes || [];
                    window.__mensagensApi = r.mensagens || [];
                    window.__mensagensRecebidasApi = r.mensagens_recebidas || [];
                }
            } catch (e) { window.__notificacoesApi = []; window.__mensagensApi = []; window.__mensagensRecebidasApi = []; }
            renderAllNotifications();
            updateNotificationsBadge();
        }
        async function loadUsuariosParaMensagem() {
            var sel = document.getElementById('notifParaUsuarioSelect');
            if (!sel) return;
            try {
                var r = await apiGet('list_usuarios_para_mensagem');
                sel.innerHTML = '<option value="">Selecione o usuário</option>';
                if (r && r.success && r.usuarios && r.usuarios.length) {
                    r.usuarios.forEach(function (u) {
                        sel.appendChild(new Option(u.nome || 'Usuário ' + u.id, u.id));
                    });
                }
            } catch (e) { sel.innerHTML = '<option value="">Erro ao carregar</option>'; }
        }
        function escNotif(x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
        function renderAllNotifications() {
            const listEl = document.getElementById('notificationsListAll');
            const emptyEl = document.getElementById('notificationsEmptyAll');
            if (!listEl) return;
            var notifs = window.__notificacoesApi || [];
            var msgs = window.__mensagensApi || [];
            var agendaList = getStoredNotificationsList();
            var read = getReadNotifications();
            var itemsHtml = '';
            notifs.forEach(function (n) {
                var isRead = !!n.lida;
                var tipoLabel = (n.tipo === '30_dias_sem_visita') ? '30 dias sem visita' : ((n.tipo === 'dias_sem_compra') ? (n.dias_sem_compra || 15) + ' dias sem compra' : 'Sistema');
                itemsHtml += '<div class="notification-item sistema ' + (isRead ? 'read' : '') + '" data-origin="sistema" data-notif-id="' + escNotif(n.id) + '" data-prescritor="' + escNotif(n.prescritor_nome) + '">' +
                    '<div class="notification-item-main"><div class="notification-sender">' + escNotif(tipoLabel) + '</div>' +
                    '<div class="notification-title">' + escNotif(n.prescritor_nome || '-') + '</div>' +
                    '<div class="notification-date">' + escNotif(n.mensagem || '') + '</div></div>' +
                    '<div class="notification-item-actions"><button type="button" class="notification-btn-lida" data-id="' + n.id + '" title="Marcar como lida"><i class="fas fa-check"></i></button>' +
                    '<button type="button" class="notification-item-delete" data-origin="sistema" data-id="' + n.id + '" title="Apagar"><i class="fas fa-trash-alt"></i></button></div></div>';
            });
            msgs.forEach(function (m) {
                var dataFmt = (m.criado_em || '').slice(0, 16).replace('T', ' ');
                itemsHtml += '<div class="notification-item usuario" data-origin="usuario" data-prescritor="' + escNotif(m.prescritor_nome) + '">' +
                    '<div class="notification-item-main"><div class="notification-sender">' + escNotif(m.autor || 'Colega') + '</div>' +
                    '<div class="notification-title">' + escNotif(m.prescritor_nome) + '</div>' +
                    '<div class="notification-date">' + escNotif(m.mensagem) + (dataFmt ? ' · ' + dataFmt : '') + '</div></div></div>';
            });
            var msgRec = window.__mensagensRecebidasApi || [];
            msgRec.forEach(function (m) {
                var dataFmt = (m.criado_em || '').slice(0, 16).replace('T', ' ');
                var isRead = !!m.lida;
                itemsHtml += '<div class="notification-item msg-usuario ' + (isRead ? 'read' : '') + '" data-origin="msg_usuario" data-msg-id="' + m.id + '">' +
                    '<div class="notification-item-main"><div class="notification-sender">' + escNotif(m.autor || 'Usuário') + '</div>' +
                    '<div class="notification-title">Mensagem para você</div>' +
                    '<div class="notification-date">' + escNotif(m.mensagem) + (dataFmt ? ' · ' + dataFmt : '') + '</div></div>' +
                    '<div class="notification-item-actions">' +
                    '<button type="button" class="notification-btn-lida-msg" data-id="' + m.id + '" title="Marcar como lida"><i class="fas fa-check"></i></button>' +
                    '<button type="button" class="notification-btn-ocultar-msg" data-id="' + m.id + '" title="Excluir da lista"><i class="fas fa-trash-alt"></i></button></div></div>';
            });
            agendaList.forEach(function (v) {
                var isRead = read.indexOf(v.id) !== -1;
                var dataFmt = v.data_agendada ? (String(v.data_agendada).trim().length >= 10 ? String(v.data_agendada).slice(8, 10) + '/' + String(v.data_agendada).slice(5, 7) + '/' + String(v.data_agendada).slice(0, 4) : '') : '';
                var sub = dataFmt + ((v.hora || '').trim() ? ' às ' + (v.hora || '').trim() : '') || 'Hoje';
                itemsHtml += '<div class="notification-item visita ' + (isRead ? 'read' : '') + '" data-origin="agenda" data-id="' + escNotif(v.id) + '" data-prescritor="' + escNotif(v.prescritor) + '" role="button" tabindex="0">' +
                    '<div class="notification-item-main"><div class="notification-sender">Agenda</div>' +
                    '<div class="notification-title">' + escNotif(v.prescritor || '-') + '</div>' +
                    '<div class="notification-date">' + escNotif(sub) + '</div></div>' +
                    '<button type="button" class="notification-item-delete" data-origin="agenda" data-id="' + escNotif(v.id) + '" title="Apagar"><i class="fas fa-trash-alt"></i></button></div>';
            });
            if (emptyEl) emptyEl.style.display = itemsHtml ? 'none' : 'block';
            if (itemsHtml) {
                var wrap = listEl.querySelector('.notifications-items-wrap');
                if (!wrap) { wrap = document.createElement('div'); wrap.className = 'notifications-items-wrap'; listEl.insertBefore(wrap, emptyEl); }
                wrap.innerHTML = itemsHtml;
            } else {
                var w = listEl.querySelector('.notifications-items-wrap');
                if (w) w.innerHTML = '';
            }
        }
        function renderNotificationsVisitas() {
            if (typeof loadNotificacoesFromAPI === 'function') loadNotificacoesFromAPI();
            else renderAllNotifications();
        }
        function updateNotificationsBadge() {
            var notifs = window.__notificacoesApi || [];
            var msgs = window.__mensagensApi || [];
            var msgRec = window.__mensagensRecebidasApi || [];
            var list = getStoredNotificationsList();
            var read = getReadNotifications();
            var unread = notifs.filter(function (n) { return !n.lida; }).length + msgs.length + msgRec.filter(function (m) { return !m.lida; }).length + list.filter(function (v) { return read.indexOf(v.id) === -1; }).length;
            var badge = document.getElementById('notificationsBadge');
            if (!badge) return;
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.style.display = unread > 0 ? 'inline-flex' : 'none';
            badge.classList.toggle('has-unread', unread > 0);
        }
        async function enviarMensagemUsuarioUI() {
            var sel = document.getElementById('notifParaUsuarioSelect');
            var msg = (document.getElementById('notifMensagemUsuarioInput') || {}).value;
            var paraId = sel ? parseInt(sel.value, 10) : 0;
            if (!paraId || !msg) { alert('Selecione o usuário e escreva a mensagem.'); return; }
            var btn = document.getElementById('btnEnviarMsgUsuario');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }
            try {
                var r = await apiPost('enviar_mensagem_usuario', { para_usuario_id: paraId, mensagem: msg.trim() });
                if (r && r.success) {
                    if (document.getElementById('notifMensagemUsuarioInput')) document.getElementById('notifMensagemUsuarioInput').value = '';
                    loadNotificacoesFromAPI();
                } else alert(r && r.error ? r.error : 'Erro ao enviar.');
            } catch (e) { alert('Erro de conexão.'); }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane" style="margin-right:6px;"></i>Enviar para usuário'; }
        }
        async function enviarMensagemVisitadorUI() {
            var presc = (document.getElementById('notifPrescritorInput') || {}).value;
            var msg = (document.getElementById('notifMensagemInput') || {}).value;
            if (!presc || !msg) { alert('Informe o prescritor e a mensagem.'); return; }
            var btn = document.getElementById('btnEnviarNotifMsg');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...'; }
            try {
                var r = await apiPost('enviar_mensagem_visitador', { prescritor_nome: presc.trim(), mensagem: msg.trim() });
                if (r && r.success) {
                    if (document.getElementById('notifPrescritorInput')) document.getElementById('notifPrescritorInput').value = '';
                    if (document.getElementById('notifMensagemInput')) document.getElementById('notifMensagemInput').value = '';
                    loadNotificacoesFromAPI();
                } else alert(r && r.error ? r.error : 'Erro ao enviar.');
            } catch (e) { alert('Erro de conexão.'); }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane" style="margin-right:6px;"></i>Enviar (prescritor)'; }
        }
        window.updateNotificationsFromAgenda = function () {
            if (typeof window.mergeNotificationsFromAgenda === 'function') window.mergeNotificationsFromAgenda();
            else {
                renderNotificationsVisitas();
                updateNotificationsBadge();
            }
        };
        document.getElementById('notificationsPanel').addEventListener('click', function (e) {
            var btnLida = e.target.closest('.notification-btn-lida');
            if (btnLida && btnLida.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                apiPost('marcar_notificacao_lida', { id: parseInt(btnLida.dataset.id, 10) }).then(function () { loadNotificacoesFromAPI(); });
                return;
            }
            var btnLidaMsg = e.target.closest('.notification-btn-lida-msg');
            if (btnLidaMsg && btnLidaMsg.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                apiPost('marcar_mensagem_usuario_lida', { id: parseInt(btnLidaMsg.dataset.id, 10) }).then(function () { loadNotificacoesFromAPI(); });
                return;
            }
            var btnOcultarMsg = e.target.closest('.notification-btn-ocultar-msg');
            if (btnOcultarMsg && btnOcultarMsg.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                apiPost('ocultar_mensagem_usuario', { id: parseInt(btnOcultarMsg.dataset.id, 10) }).then(function () { loadNotificacoesFromAPI(); });
                return;
            }
            var btnDelete = e.target.closest('.notification-item-delete');
            if (btnDelete && btnDelete.dataset.id) {
                e.stopPropagation();
                e.preventDefault();
                if (btnDelete.dataset.origin === 'sistema') {
                    apiPost('apagar_notificacao', { id: parseInt(btnDelete.dataset.id, 10) }).then(function () { loadNotificacoesFromAPI(); });
                } else {
                    deleteNotification(btnDelete.dataset.id);
                }
                return;
            }
            var item = e.target.closest('.notification-item');
            if (item && item.dataset.prescritor) {
                if (item.dataset.origin === 'agenda' && item.dataset.id) markNotificationRead(item.dataset.id);
                item.classList.add('read');
                if (typeof openPrescritoresModal === 'function') openPrescritoresModal(item.dataset.prescritor);
            }
        });
        var agendaCalendarioDiasEl = document.getElementById('agendaCalendarioDias');
        if (agendaCalendarioDiasEl) agendaCalendarioDiasEl.addEventListener('click', function (e) {
            var item = e.target.closest('.agenda-visita-item');
            if (item && item.dataset.prescritor !== undefined && typeof openPrescritoresModal === 'function') openPrescritoresModal(item.dataset.prescritor || '');
        });
        function toggleNotificationsPanel(e) {
            if (e) e.stopPropagation();
            const panel = document.getElementById('notificationsPanel');
            const btn = document.getElementById('btnNotifications');
            const backdrop = document.getElementById('notificationsBackdrop');
            if (!panel || !btn) return;
            const isOpen = panel.classList.toggle('open');
            var isMobileOrTablet = window.innerWidth <= 1024;
            if (backdrop) {
                if (isOpen && isMobileOrTablet) backdrop.classList.add('open');
                else backdrop.classList.remove('open');
            }
            if (isOpen) {
                loadNotificacoesFromAPI();
                loadUsuariosParaMensagem();
                setTimeout(function () { document.addEventListener('click', closeNotificationsOnClickOutside); }, 0);
            } else {
                document.removeEventListener('click', closeNotificationsOnClickOutside);
            }
        }

        // Fechar modal ao clicar fora
        document.getElementById('modalPrescritores').addEventListener('click', function (e) {
            if (e.target === this) closePrescritoresModal();
        });
        document.getElementById('modalPedidosVisitador').addEventListener('click', function (e) {
            if (e.target === this) closeModalPedidosVisitador();
        });
        document.getElementById('modalDetalhePedido').addEventListener('click', function (e) {
            if (e.target === this) closeModalDetalhePedido();
        });
        document.getElementById('modalWhatsApp').addEventListener('click', function (e) {
            if (e.target === this) closeWhatsAppModal();
        });
        // Delegação de clique para botões Iniciar/Encerrar visita e WhatsApp (modal e tabela Relatório de visitas)
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-iniciar-visita');
            if (btn) {
                const nome = btn.getAttribute('data-prescritor');
                if (!nome) return;
                const dentroDoModal = e.target.closest('#modalPrescritores');
                if (dentroDoModal && typeof iniciarVisita === 'function') {
                    iniciarVisita(nome);
                } else if (typeof openPrescritoresModal === 'function') {
                    openPrescritoresModal(nome);
                }
                return;
            }
            const btnEncerrar = e.target.closest('.btn-encerrar-visita');
            if (btnEncerrar && typeof openEncerrarVisitaModal === 'function') {
                const nome = btnEncerrar.getAttribute('data-prescritor');
                if (nome) openEncerrarVisitaModal(nome);
                return;
            }
            const btnWhats = e.target.closest('.btn-whatsapp-open');
            if (btnWhats && typeof openWhatsAppModal === 'function') {
                const nome = btnWhats.getAttribute('data-prescritor');
                const numero = btnWhats.getAttribute('data-numero') || '';
                if (nome !== null) openWhatsAppModal(nome, numero);
            }
        });
        document.getElementById('modalEncerrarVisita').addEventListener('click', function (e) {
            if (e.target === this) closeEncerrarVisitaModal();
        });

        // fechar modal de estatísticas do mapa ao clicar fora
        document.getElementById('modalMapaVisitasStats').addEventListener('click', function (e) {
            if (e.target === this) closeMapaVisitasStatsModal();
        });

        // fechar modal de detalhe da visita ao clicar fora
        document.getElementById('modalDetalheVisita').addEventListener('click', function (e) {
            if (e.target === this) closeDetalheVisitaModal();
        });

        function filterPrescritores() {
            const search = document.getElementById('searchPrescritor').value.toLowerCase();
            filteredPrescritores = allPrescritores.filter(p => p.prescritor.toLowerCase().includes(search));
            // Aplicar ordenação atual ao filtro
            sortData(filteredPrescritores, currentSort.column, currentSort.direction);
            modalCurrentPage = 1; // Reseta para a primeira página ao filtrar
            renderModalPrescritores(filteredPrescritores);
        }

        function sortTable(column) {
            // Alternar direção se clicar na mesma coluna
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
                // Ajuste para colunas numéricas onde DESC faz mais sentido inicialmente
                if (['total_aprovado', 'qtd_aprovados', 'dias_sem_compra', 'ultima_visita'].includes(column)) {
                    currentSort.direction = 'desc';
                }
            }

            // Atualizar ícones visualmente (opcional, mas bom pra UX)
            updateSortIcons(column, currentSort.direction);

            sortData(filteredPrescritores, currentSort.column, currentSort.direction);
            modalCurrentPage = 1;
            renderModalPrescritores(filteredPrescritores);
        }

        function sortData(data, column, direction) {
            data.sort((a, b) => {
                let valA = a[column];
                let valB = b[column];

                // Tratamento específico para dias s/ visita (baseado em ultima_visita)
                if (column === 'ultima_visita') {
                    // Queremos ordenar por "dias sem visita".
                    // Se ultima_visita é mais recente (maior data), "dias sem visita" é MENOR.
                    // Então se ordenamos dias ASC, data deve ser DESC.
                    // Vamos simplificar: converter data para timestamp.
                    // Quem não tem visita (null) tem "infinitos" dias sem visita.
                    const getTimestamp = (dt) => dt ? new Date(dt).getTime() : 0;
                    valA = getTimestamp(valA);
                    valB = getTimestamp(valB);

                    // Se direction for 'desc' (maiores dias sem visita primeiro), queremos as MENORES datas (ou null) primeiro.
                    // A lógica padrão de sort(a-b) ordena crescente. 
                    // Vamos inverter a lógica aqui pois estamos lidando com "Dias SEM":
                    // Mais dias sem visita = Data mais antiga (valor menor)

                    // Se quero DIAS (SEM VISITA) CRESCENTE (1d, 2d...): Quero datas MAIS RECENTES primeiro.
                    // Se quero DIAS (SEM VISITA) DECRESCENTE (Sem visita, 100d...): Quero datas MAIS ANTIGAS primeiro.

                    // Vamos inverter o valor para o sort genérico funcionar como "Dias":
                    // Se data é recente (grande), dias é pequeno. 
                    // Se data é antiga (pequena), dias é grande.
                    // Se data é 0 (null), dias é infinito.

                    // Vamos calcular os dias virtuais para ordenar corretamente
                    const getDias = (dateStr) => {
                        if (!dateStr) return 999999; // Sem visita = muitos dias
                        const d = new Date(dateStr);
                        return Math.floor((new Date() - d) / (1000 * 60 * 60 * 24));
                    };

                    valA = getDias(a['ultima_visita']);
                    valB = getDias(b['ultima_visita']);
                } else if (column === 'dias_sem_compra') {
                    // Tratar nulos como infinitos dias
                    valA = valA !== null ? parseInt(valA) : 999999;
                    valB = valB !== null ? parseInt(valB) : 999999;
                } else {
                    // Numéricos padrão
                    if (column !== 'prescritor') {
                        valA = parseFloat(valA) || 0;
                        valB = parseFloat(valB) || 0;
                    } else {
                        // String
                        valA = valA.toString().toLowerCase();
                        valB = valB.toString().toLowerCase();
                    }
                }

                if (valA < valB) return direction === 'asc' ? -1 : 1;
                if (valA > valB) return direction === 'asc' ? 1 : -1;
                return 0;
            });
        }

        function updateSortIcons(column, direction) {
            // Remove todos os ícones ativos
            document.querySelectorAll('th i.fa-sort, th i.fa-sort-up, th i.fa-sort-down').forEach(i => {
                i.className = 'fas fa-sort';
                i.style.opacity = '0.3';
            });

            // Encontrar o header clicado
            const headers = document.querySelectorAll('th');
            let targetHeader;
            if (column === 'prescritor') targetHeader = headers[1];
            if (column === 'total_aprovado' || column === 'qtd_aprovados') targetHeader = headers[2];
            if (column === 'total_recusado') targetHeader = headers[3];
            if (column === 'dias_sem_compra') targetHeader = headers[4];
            if (column === 'ultima_visita') targetHeader = headers[5];

            if (targetHeader) {
                const icon = targetHeader.querySelector('i');
                if (icon) {
                    icon.className = direction === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
                    icon.style.opacity = '1';
                }
            }
        }

        let modalCurrentPage = 1;
        const itemsPerPage = 50;

        function changePage(delta) {
            modalCurrentPage += delta;
            renderModalPrescritores(filteredPrescritores);
            // Scroll para o topo da tabela
            document.getElementById('modalBodyWrapper').scrollTop = 0;
        }

        function renderModalPrescritores(data) {
            const tbody = document.getElementById('modalPrescritoresBody');
            const subtitle = document.getElementById('modalPrescritorSubtitle');
            subtitle.textContent = currentVisitadorName
                ? `Carteira: ${currentVisitadorName} — ${data.length} prescritor(es)`
                : `${data.length} prescritor(es) encontrado(s)`;

            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:40px; color:var(--text-secondary);"><i class="fas fa-search" style="font-size:1.5rem; margin-bottom:8px; display:block; opacity:0.3;"></i>Nenhum prescritor encontrado</td></tr>';
                document.getElementById('paginationContainer').style.display = 'none';
                return;
            }

            document.getElementById('paginationContainer').style.display = 'flex';

            const totalPages = Math.ceil(data.length / itemsPerPage);
            if (modalCurrentPage < 1) modalCurrentPage = 1;
            if (modalCurrentPage > totalPages) modalCurrentPage = totalPages;

            const startIdx = (modalCurrentPage - 1) * itemsPerPage;
            const endIdx = Math.min(startIdx + itemsPerPage, data.length);
            const paginatedData = data.slice(startIdx, endIdx);

            document.getElementById('paginationInfo').textContent = `Mostrando ${startIdx + 1}-${endIdx} de ${data.length}`;
            document.getElementById('prevPage').disabled = modalCurrentPage === 1;
            document.getElementById('nextPage').disabled = modalCurrentPage === totalPages;
            document.getElementById('prevPage').style.opacity = modalCurrentPage === 1 ? '0.5' : '1';
            document.getElementById('nextPage').style.opacity = modalCurrentPage === totalPages ? '0.5' : '1';

            // Função auxiliar: bolinha = ícone; número sempre fora da bolinha. Verificado=verde, Atenção=amarelo.
            function diasBadge(days, emptyText) {
                if (days === null || isNaN(days) || days === 999999) {
                    return `<div class="days-badge danger"><div class="circle"><i class="fas fa-exclamation"></i></div> ${emptyText}</div>`;
                }

                let type = 'success';
                let icon = '<i class="fas fa-check"></i>';
                let label = ' ' + days + 'd';

                if (days > 15) {
                    type = 'danger';
                    icon = '<i class="fas fa-exclamation"></i>';
                } else if (days > 7) {
                    type = 'warning';
                    icon = '<i class="fas fa-exclamation-triangle"></i>';
                }

                return `<div class="days-badge ${type}"><div class="circle">${icon}</div>${label}</div>`;
            }

            tbody.innerHTML = paginatedData.map((p, i) => {
                const globalIndex = startIdx + i + 1;
                // Dias sem compra
                const diasCompra = p.dias_sem_compra !== null ? parseInt(p.dias_sem_compra) : null;
                const diasCompraHTML = diasBadge(diasCompra, 'Sem compras');

                // Dias sem visita
                let diasVisitaHTML = diasBadge(null, 'Sem visita');
                if (p.ultima_visita) {
                    const dv = new Date(p.ultima_visita);
                    if (!isNaN(dv.getTime())) {
                        const diasVisita = Math.floor((new Date() - dv) / (1000 * 60 * 60 * 24));
                        diasVisitaHTML = diasBadge(diasVisita, 'Sem visita');
                    }
                }

                return `
                    <tr style="border-bottom:1px solid var(--border); transition:background 0.15s;" onmouseover="this.style.background='rgba(37,99,235,0.03)'" onmouseout="this.style.background='transparent'">
                        <td style="padding:10px 16px; color:var(--text-secondary); font-weight:600;">${globalIndex}</td>
                        <td style="padding:10px 16px; font-weight:500; color:var(--text-primary); text-transform: capitalize;">${p.prescritor.toLowerCase()}</td>
                        <td class="val-approved" style="padding:10px 16px; text-align:right;">${formatMoney(p.total_aprovado)}<br><small style="opacity:0.9;">${p.qtd_aprovados ?? 0} ped.</small></td>
                        <td class="val-refused" style="padding:10px 16px; text-align:right; color:var(--danger, #EF4444);">${formatMoney(p.total_recusado ?? p.valor_recusado ?? 0)}<br><small style="opacity:0.9;">${p.qtd_recusados ?? 0} ped.</small></td>
                        <td style="padding:10px 16px; text-align:center;">${diasCompraHTML}</td>
                        <td style="padding:10px 16px; text-align:center;">${diasVisitaHTML}</td>
                        <td style="padding:10px 16px; text-align:center;">
                            ${renderVisitaActionCell(p.prescritor)}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function escapeAttr(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function renderVisitaActionCell(prescritorNome) {
            if (!canManageVisits) return '<span style="color:var(--text-secondary);">-</span>';
            const safeAttr = escapeAttr(prescritorNome);

            // Se existe visita ativa
            if (activeVisit && activeVisit.prescritor) {
                if (activeVisit.prescritor === prescritorNome) {
                    return `
                        <button type="button" class="btn-encerrar-visita" data-prescritor="${safeAttr}"
                            style="padding:6px 10px; border-radius:8px; border:none; background:#EF4444; color:white; cursor:pointer; font-weight:700; font-size:0.75rem;">
                            Encerrar
                        </button>
                    `;
                }
                return `
                    <button type="button" disabled
                        style="padding:6px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-body); color:var(--text-secondary); cursor:not-allowed; font-weight:700; font-size:0.75rem; opacity:0.75;">
                        Em visita
                    </button>
                `;
            }

            // Sem visita ativa: após 19h não permite iniciar nova visita
            if (typeof isApós19h === 'function' && isApós19h()) {
                return `
                    <button type="button" disabled title="Não é permitido iniciar visita após as 19h."
                        style="padding:6px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-body); color:var(--text-secondary); cursor:not-allowed; font-weight:700; font-size:0.75rem; opacity:0.8;">
                        Após 19h
                    </button>
                `;
            }
            return `
                <button type="button" class="btn-iniciar-visita" data-prescritor="${safeAttr}"
                    style="padding:6px 10px; border-radius:8px; border:none; background:var(--success); color:white; cursor:pointer; font-weight:700; font-size:0.75rem;">
                    Iniciar
                </button>
            `;
        }

        // ações de prescritor extraídas para js/visitador/prescritores-feature.js

        // =========================
        // DETALHE DA VISITA (Modal)
        // =========================
        let __dvGeoMap = null;
        let __dvGeoMarker = null;
        let __dvVisita = null;
        let __dvPodeEditar = false;

        function openDetalheVisitaModal(visita) {
            if (!visita) return;
            __dvVisita = visita;
            const modal = document.getElementById('modalDetalheVisita');
            if (!modal) return;
            dvToggleEdit(false);

            const statusLower = (visita.status_visita || '').toLowerCase();
            const statusCfg = {
                'realizada':      { icon: 'fa-check-circle',    color: '#10B981', bg: 'rgba(16,185,129,0.12)',  border: 'rgba(16,185,129,0.30)' },
                'remarcada':      { icon: 'fa-calendar-alt',    color: '#F59E0B', bg: 'rgba(245,158,11,0.12)',  border: 'rgba(245,158,11,0.30)' },
                'cancelada':      { icon: 'fa-times-circle',    color: '#EF4444', bg: 'rgba(239,68,68,0.12)',   border: 'rgba(239,68,68,0.30)' },
                'não encontrado': { icon: 'fa-question-circle', color: '#94A3B8', bg: 'rgba(148,163,184,0.12)', border: 'rgba(148,163,184,0.30)' }
            };
            const sc = statusCfg[statusLower] || statusCfg['realizada'];

            const iconBox = document.getElementById('dvIconBox');
            const iconEl = document.getElementById('dvIcon');
            if (iconBox) { iconBox.style.background = sc.bg; iconBox.style.border = `1px solid ${sc.border}`; }
            if (iconEl)  { iconEl.className = `fas ${sc.icon}`; iconEl.style.color = sc.color; }

            const sub = document.getElementById('dvSubtitulo');
            if (sub) sub.textContent = visita.prescritor || '—';

            document.getElementById('dvPrescritor').textContent = visita.prescritor || '—';

            const badge = document.getElementById('dvStatusBadge');
            if (badge) {
                badge.textContent = visita.status_visita || '—';
                badge.style.background = sc.bg;
                badge.style.color = sc.color;
                badge.style.border = `1px solid ${sc.border}`;
            }

            const tzPV = 'America/Porto_Velho';
            const isoPV = visita.data_visita && visita.horario ? (visita.data_visita + 'T' + String(visita.horario).slice(0, 5) + ':00-04:00') : (visita.data_visita ? visita.data_visita + 'T12:00:00-04:00' : null);
            const dt = isoPV ? new Date(isoPV) : null;
            document.getElementById('dvData').textContent = dt && !isNaN(dt.getTime())
                ? dt.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric', timeZone: tzPV })
                : (visita.data_visita || '—');
            const horarioInicioEl = document.getElementById('dvHorarioInicio');
            const horarioFimEl = document.getElementById('dvHorarioFim');
            const duracaoEl = document.getElementById('dvDuracao');
            if (horarioInicioEl) {
                const iv = visita.inicio_visita;
                if (iv) {
                    const s = String(iv).trim();
                    const iso = s.match(/^\d{4}-\d{2}-\d{2}/) ? (s.length >= 16 ? s.slice(0, 16).replace(' ', 'T') + '-04:00' : s + 'T12:00:00-04:00') : null;
                    const d = iso ? new Date(iso) : null;
                    horarioInicioEl.textContent = d && !isNaN(d.getTime()) ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: tzPV }) : (s.length >= 5 ? s.slice(11, 16) || s.slice(0, 5) : s || '—');
                } else horarioInicioEl.textContent = '—';
            }
            if (horarioFimEl) horarioFimEl.textContent = visita.horario ? String(visita.horario).slice(0, 5) : '—';
            if (duracaoEl) {
                const min = visita.duracao_minutos;
                if (min != null && min !== '' && !isNaN(parseInt(min, 10))) {
                    const m = parseInt(min, 10);
                    duracaoEl.textContent = m < 60 ? m + ' min' : (Math.floor(m / 60) + 'h ' + (m % 60 ? (m % 60) + ' min' : ''));
                } else duracaoEl.textContent = '—';
            }

            const localRow = document.getElementById('dvLocalRow');
            if (visita.local_visita) {
                localRow.style.display = 'block';
                document.getElementById('dvLocal').textContent = visita.local_visita;
            } else { localRow.style.display = 'none'; }

            const resumoRow = document.getElementById('dvResumoRow');
            if (visita.resumo_visita) {
                resumoRow.style.display = 'block';
                document.getElementById('dvResumo').textContent = visita.resumo_visita;
            } else { resumoRow.style.display = 'none'; }

            const itensRow = document.getElementById('dvItensRow');
            const itensEl = document.getElementById('dvItens');
            const itens = [];
            if (visita.amostra) itens.push({ label: 'Amostra', value: visita.amostra, icon: 'fa-flask', color: '#8B5CF6' });
            if (visita.brinde)  itens.push({ label: 'Brinde',  value: visita.brinde,  icon: 'fa-gift',  color: '#EC4899' });
            if (visita.artigo)  itens.push({ label: 'Artigo',  value: visita.artigo,  icon: 'fa-file-lines', color: '#3B82F6' });
            if (itens.length) {
                itensRow.style.display = 'block';
                itensEl.innerHTML = itens.map(it => `
                    <div style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:999px; background:var(--bg-body); border:1px solid var(--border); font-size:0.78rem;">
                        <i class="fas ${it.icon}" style="color:${it.color}; font-size:0.7rem;"></i>
                        <span style="font-weight:700; color:var(--text-secondary);">${it.label}:</span>
                        <span style="color:var(--text-primary); font-weight:600;">${it.value}</span>
                    </div>
                `).join('');
            } else { itensRow.style.display = 'none'; }

            const reagendRow = document.getElementById('dvReagendRow');
            const reagendEl = document.getElementById('dvReagend');
            if (visita.reagendado_para && reagendEl) {
                reagendRow.style.display = 'block';
                const s = String(visita.reagendado_para).trim();
                const match = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{1,2}):(\d{2})?)?/);
                if (match) {
                    const [, y, mo, d, h, mi] = match;
                    const dt = new Date(parseInt(y, 10), parseInt(mo, 10) - 1, parseInt(d, 10), h ? parseInt(h, 10) : 0, mi ? parseInt(mi, 10) : 0);
                    reagendEl.textContent = isNaN(dt.getTime()) ? s : dt.toLocaleString('pt-BR', { dateStyle: 'long', timeStyle: h ? 'short' : undefined });
                } else reagendEl.textContent = s;
            } else { reagendRow.style.display = 'none'; }

            const geoRow = document.getElementById('dvGeoRow');
            const geoInfo = document.getElementById('dvGeoInfo');
            const geoMapEl = document.getElementById('dvGeoMapContainer');
            const lat = parseFloat(visita.geo_lat);
            const lng = parseFloat(visita.geo_lng);
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                geoRow.style.display = 'block';
                const acc = visita.geo_accuracy ? `±${Math.round(parseFloat(visita.geo_accuracy))}m` : '';
                geoInfo.textContent = `${lat.toFixed(6)}, ${lng.toFixed(6)} ${acc}`;
                geoMapEl.style.display = 'block';
                setTimeout(() => {
                    if (typeof L === 'undefined') return;
                    if (!__dvGeoMap) {
                        __dvGeoMap = L.map(geoMapEl, { zoomControl: true, attributionControl: false });
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(__dvGeoMap);
                    }
                    __dvGeoMap.setView([lat, lng], 17);
                    if (__dvGeoMarker) __dvGeoMarker.remove();
                    __dvGeoMarker = L.marker([lat, lng]).addTo(__dvGeoMap);
                    __dvGeoMap.invalidateSize();
                }, 200);
            } else {
                geoRow.style.display = 'none';
                geoMapEl.style.display = 'none';
            }

            var dataStr = (visita.data_visita || visita.data_agendada || '').toString().slice(0, 10);
            var semana = typeof getSemanaAtualBounds === 'function' ? getSemanaAtualBounds() : { segunda: '', domingo: '' };
            __dvPodeEditar = !!dataStr && semana.segunda && dataStr >= semana.segunda && dataStr <= semana.domingo;
            var btnEditar = document.getElementById('dvBtnEditar');
            if (btnEditar) btnEditar.style.display = __dvPodeEditar ? 'inline-block' : 'none';

            modal.style.display = 'flex';
        }

        function dvToggleEdit(showEdit) {
            const viewEl = document.getElementById('dvViewContainer');
            const editEl = document.getElementById('dvEditContainer');
            const btnEditar = document.getElementById('dvBtnEditar');
            const footerEdit = document.getElementById('dvFooterEdit');
            if (!viewEl || !editEl) return;
            if (showEdit && __dvVisita) {
                viewEl.style.display = 'none';
                editEl.style.display = 'block';
                if (btnEditar) btnEditar.style.display = 'none';
                if (footerEdit) footerEdit.style.display = 'inline-flex';
                var elResumo = document.getElementById('dvEditResumo');
                var elAmostra = document.getElementById('dvEditAmostra');
                var elBrinde = document.getElementById('dvEditBrinde');
                var elArtigo = document.getElementById('dvEditArtigo');
                if (elResumo) elResumo.value = __dvVisita.resumo_visita || '';
                if (elAmostra) elAmostra.value = __dvVisita.amostra || '';
                if (elBrinde) elBrinde.value = __dvVisita.brinde || '';
                if (elArtigo) elArtigo.value = __dvVisita.artigo || '';
            } else {
                viewEl.style.display = 'block';
                editEl.style.display = 'none';
                if (btnEditar) btnEditar.style.display = (typeof __dvPodeEditar !== 'undefined' && __dvPodeEditar) ? 'inline-block' : 'none';
                if (footerEdit) footerEdit.style.display = 'none';
            }
        }

        async function dvSalvarDetalheVisita() {
            if (!__dvVisita || !__dvVisita.id) return;
            var historicoId = parseInt(__dvVisita.id, 10);
            var elResumo = document.getElementById('dvEditResumo');
            var elAmostra = document.getElementById('dvEditAmostra');
            var elBrinde = document.getElementById('dvEditBrinde');
            var elArtigo = document.getElementById('dvEditArtigo');
            var payload = {
                historico_id: historicoId,
                visitador: typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '',
                resumo_visita: elResumo ? elResumo.value : '',
                amostra: elAmostra ? elAmostra.value : '',
                brinde: elBrinde ? elBrinde.value : '',
                artigo: elArtigo ? elArtigo.value : ''
            };
            var btn = document.getElementById('dvBtnSalvar');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Salvando...'; }
            try {
                var res = await apiPost('update_detalhe_visita', payload);
                if (res && res.success) {
                    var vis = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || (localStorage.getItem('userName') || '');
                    var updated = await apiGet('get_detalhe_visita', { historico_id: historicoId, visitador: vis });
                    if (updated && updated.success && updated.visita) {
                        __dvVisita = updated.visita;
                        openDetalheVisitaModal(updated.visita);
                        dvToggleEdit(false);
                    }
                    if (typeof loadVisitadorDashboard === 'function') loadVisitadorDashboard(currentVisitadorName);
                    if (typeof loadAgendaMes === 'function') loadAgendaMes(__agendaAno, __agendaMes);
                } else alert(res && res.error ? res.error : 'Erro ao salvar.');
            } catch (e) {
                alert('Erro ao salvar alterações.');
            }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check" style="margin-right:6px;"></i>Salvar'; }
        }

        function closeDetalheVisitaModal() {
            const modal = document.getElementById('modalDetalheVisita');
            if (modal) modal.style.display = 'none';
        }

        // =========================
        // VISITAS (Iniciar / Encerrar)
        // =========================
        let encerrarVisitId = null;
        let visitaGeo = { lat: null, lng: null, accuracy: null };
        let leafletMap = null;
        let leafletMarker = null;
        let leafletCircle = null;

        function isApós19h() {
            try {
                const hour = parseInt(new Date().toLocaleString('en-CA', { timeZone: 'America/Porto_Velho', hour: '2-digit', hour12: false }), 10);
                return !isNaN(hour) && hour >= 19;
            } catch (e) { return new Date().getHours() >= 19; }
        }

        async function iniciarVisita(prescritor) {
            if (!canManageVisits) return;
            if (isApós19h()) {
                alert('Não é permitido iniciar visita após as 19h.');
                return;
            }
            const subtitle = document.getElementById('modalPrescritorSubtitle');
            const old = subtitle ? subtitle.textContent : '';
            if (subtitle) subtitle.textContent = 'Iniciando visita...';
            try {
                const res = await apiPost('iniciar_visita', { prescritor, visitador_nome: currentVisitadorName });
                if (res && res.success) {
                    const av = await apiGet('visita_ativa', { visitador_nome: currentVisitadorName });
                    activeVisit = av && av.active ? av.active : null;
                    if (subtitle) subtitle.textContent = 'Visita iniciada. Para finalizar, clique em “Encerrar”.';
                    renderModalPrescritores(filteredPrescritores);
                    var modalPrescritores = document.getElementById('modalPrescritores');
                    if (modalPrescritores) modalPrescritores.style.display = 'flex';
                    var relEl = document.getElementById('paginaRelatorioVisitas');
                    if (relEl && relEl.style.display !== 'none') {
                        var q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
                        var term = q.toLowerCase().trim();
                        var data = term ? relatorioPrescritoresFiltered.filter(function(p) { return (p.prescritor || '').toLowerCase().includes(term); }) : relatorioPrescritoresFiltered;
                        renderRelatorioPrescritoresTable(data);
                    }
                } else {
                    if (subtitle) subtitle.textContent = (res && res.error) ? res.error : 'Não foi possível iniciar a visita.';
                    if (res && res.error && res.error.indexOf('19h') !== -1) alert(res.error);
                }
            } catch (e) {
                if (subtitle) subtitle.textContent = 'Erro de conexão ao iniciar a visita.';
            }
            // restaurar subtítulo após 4s
            setTimeout(() => {
                const s = document.getElementById('modalPrescritorSubtitle');
                if (s && old) s.textContent = old;
            }, 4000);
        }

        function aplicarSugestaoProximaData(opcao) {
            var el = document.getElementById('visitaProximaData');
            if (!el) return;
            var d = new Date();
            if (opcao === 'amanha') {
                d.setDate(d.getDate() + 1);
            } else if (opcao === 'segunda') {
                var dia = d.getDay();
                var add = dia === 0 ? 1 : (8 - dia);
                d.setDate(d.getDate() + add);
            } else if (typeof opcao === 'number') {
                d.setDate(d.getDate() + opcao);
            } else return;
            el.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        function aplicarSugestaoProximaHora(hora) {
            var el = document.getElementById('visitaProximaHora');
            if (el && hora) el.value = String(hora).slice(0, 5);
        }

        function openEncerrarVisitaModal(prescritor) {
            if (!canManageVisits) return;
            if (!activeVisit || !activeVisit.id) return;
            if (activeVisit.prescritor !== prescritor) return;

            encerrarVisitId = activeVisit.id;
            document.getElementById('encerrarVisitaPrescritor').textContent = prescritor;
            const inicioTxt = activeVisit.inicio ? (() => {
                const s = String(activeVisit.inicio).trim().replace(' ', 'T');
                const d = new Date(s.indexOf('Z') !== -1 || s.match(/[+-]\d{2}:?\d{2}$/) ? s : s + '-04:00');
                return 'Início: ' + (isNaN(d.getTime()) ? activeVisit.inicio : d.toLocaleString('pt-BR', { timeZone: 'America/Porto_Velho' }));
            })() : '';
            document.getElementById('encerrarVisitaInicio').textContent = inicioTxt;

            // limpar campos
            document.getElementById('visitaStatus').value = 'Realizada';
            document.getElementById('visitaLocal').value = '';
            document.getElementById('visitaAmostra').value = '';
            document.getElementById('visitaBrinde').value = '';
            document.getElementById('visitaArtigo').value = '';
            document.getElementById('visitaResumo').value = '';
            document.getElementById('visitaProximaData').value = '';
            document.getElementById('visitaProximaHora').value = '';
            resetGeoUI();
            const msg = document.getElementById('encerrarVisitaMsg');
            if (msg) msg.style.display = 'none';

            // Preencher "Local de atendimento" com o valor do cadastro do prescritor (mesmas opções do select)
            (async function () {
                try {
                    var vis = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || '';
                    var r = await apiGet('get_prescritor_dados', { nome: prescritor, visitador: vis });
                    if (r && r.dados && r.dados.local_atendimento) {
                        var localEl = document.getElementById('visitaLocal');
                        if (localEl && !localEl.value) {
                            var val = (r.dados.local_atendimento || '').trim();
                            var opt = Array.prototype.find.call(localEl.options, function (o) { return o.value === val; });
                            if (opt) localEl.value = val;
                        }
                    }
                } catch (e) { /* sem problema se falhar */ }
            })();

            document.getElementById('modalEncerrarVisita').style.display = 'flex';
            setTimeout(() => document.getElementById('visitaResumo').focus(), 50);
            // Captura automática do GPS ao abrir (sem depender de clique)
            try { capturarGPS({ mode: 'auto' }); } catch (e) { }
        }

        function closeEncerrarVisitaModal() {
            document.getElementById('modalEncerrarVisita').style.display = 'none';
            encerrarVisitId = null;
        }

        async function confirmEncerrarVisita() {
            if (!canManageVisits) return;
            const btn = document.getElementById('btnEncerrarVisita');
            const msg = document.getElementById('encerrarVisitaMsg');
            if (!encerrarVisitId) return;

            const status_visita = document.getElementById('visitaStatus').value;
            const local_visita = document.getElementById('visitaLocal').value;
            const amostra = document.getElementById('visitaAmostra').value;
            const brinde = document.getElementById('visitaBrinde').value;
            const artigo = document.getElementById('visitaArtigo').value;
            const resumo_visita = document.getElementById('visitaResumo').value;
            const nextDate = document.getElementById('visitaProximaData').value;
            const nextTime = document.getElementById('visitaProximaHora').value;
            const reagendado_para = nextDate ? (nextTime ? `${nextDate} ${nextTime}` : nextDate) : '';

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Salvando...';
            if (msg) { msg.style.display = 'none'; }
            try {
                // Garantir captura automática antes de salvar (não bloqueia se falhar)
                if ((visitaGeo.lat == null || visitaGeo.lng == null) && navigator.geolocation) {
                    try { await capturarGPS({ mode: 'silent', timeoutMs: 5000 }); } catch (e) { }
                }
                const res = await apiPost('encerrar_visita', {
                    id: encerrarVisitId,
                    visitador_nome: currentVisitadorName,
                    status_visita,
                    local_visita,
                    resumo_visita,
                    amostra,
                    brinde,
                    artigo,
                    reagendado_para,
                    geo_lat: visitaGeo.lat,
                    geo_lng: visitaGeo.lng,
                    geo_accuracy: visitaGeo.accuracy
                });
                if (res && res.success) {
                    if (msg) { msg.textContent = 'Visita encerrada e registrada.'; msg.style.color = 'var(--success)'; msg.style.display = 'block'; }
                    // Atualizar "Dias s/ Visita" na hora: marcar ultima_visita como agora para o prescritor encerrado
                    var prescritorEncerrado = (activeVisit && activeVisit.prescritor) ? activeVisit.prescritor : ((document.getElementById('encerrarVisitaPrescritor') && document.getElementById('encerrarVisitaPrescritor').textContent) || '').trim();
                    var nowIso = new Date().toISOString();
                    if (prescritorEncerrado) {
                        relatorioPrescritoresFiltered.forEach(function(p) { if ((p.prescritor || '').trim() === prescritorEncerrado) { p.ultima_visita = nowIso; } });
                        allPrescritores.forEach(function(p) { if ((p.prescritor || '').trim() === prescritorEncerrado) { p.ultima_visita = nowIso; } });
                        filteredPrescritores.forEach(function(p) { if ((p.prescritor || '').trim() === prescritorEncerrado) { p.ultima_visita = nowIso; } });
                    }
                    // atualizar visita ativa
                    const av = await apiGet('visita_ativa', { visitador_nome: currentVisitadorName });
                    activeVisit = av && av.active ? av.active : null;
                    // atualizar tabela do modal
                    renderModalPrescritores(filteredPrescritores);
                    var relEl = document.getElementById('paginaRelatorioVisitas');
                    if (relEl && relEl.style.display !== 'none') {
                        var q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
                        var term = q.toLowerCase().trim();
                        var data = term ? relatorioPrescritoresFiltered.filter(function(p) { return (p.prescritor || '').toLowerCase().includes(term); }) : relatorioPrescritoresFiltered;
                        renderRelatorioPrescritoresTable(data);
                    }
                    // recarregar dados do painel automaticamente (KPIs, gráficos e relatórios)
                    try {
                        const anoEl = document.getElementById('anoSelect');
                        const mesEl = document.getElementById('mesSelect');
                        const ano = anoEl ? anoEl.value : null;
                        const mes = mesEl ? mesEl.value : null;
                        const vis = currentVisitadorName || localStorage.getItem('userName') || '';
                        // manter filtros atuais; mês vazio vira null
                        await loadVisitadorDashboard(vis, ano, mes || null);
                        // se estiver na página Relatório de visitas, atualizar os cards (Semanal, Agenda, Mapa)
                        var relEl = document.getElementById('paginaRelatorioVisitas');
                        if (relEl && relEl.style.display !== 'none' && typeof copyDashboardToRelatorio === 'function') {
                            copyDashboardToRelatorio();
                        }
                    } catch (e) {
                        // se falhar o refresh do painel, não bloquear o fechamento
                    }
                    setTimeout(() => closeEncerrarVisitaModal(), 700);
                } else {
                    if (msg) { msg.textContent = (res && res.error) ? res.error : 'Não foi possível encerrar a visita.'; msg.style.color = 'var(--danger)'; msg.style.display = 'block'; }
                }
            } catch (e) {
                if (msg) { msg.textContent = 'Erro de conexão ao encerrar a visita.'; msg.style.color = 'var(--danger)'; msg.style.display = 'block'; }
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-flag-checkered"></i> Encerrar visita';
        }

        function resetGeoUI() {
            visitaGeo = { lat: null, lng: null, accuracy: null };
            const status = document.getElementById('geoStatusText');
            const info = document.getElementById('geoInfoLine');
            const mapWrap = document.getElementById('mapContainer');
            const btnMap = document.getElementById('btnAbrirMapaExterno');
            if (status) status.textContent = 'Ao abrir este modal, o sistema tenta capturar o GPS automaticamente.';
            if (info) { info.style.display = 'none'; info.textContent = ''; }
            if (mapWrap) mapWrap.style.display = 'none';
            if (btnMap) { btnMap.style.display = 'none'; btnMap.href = '#'; }
        }

        async function capturarGPS(opts) {
            if (!canManageVisits) return;
            const options = opts && typeof opts === 'object' ? opts : { mode: 'manual' };
            const mode = options.mode || 'manual'; // manual | auto | silent
            const timeoutMs = Number.isFinite(options.timeoutMs) ? options.timeoutMs : 15000;
            const btn = document.getElementById('btnCapturarGPS');
            const status = document.getElementById('geoStatusText');
            const info = document.getElementById('geoInfoLine');
            const mapWrap = document.getElementById('mapContainer');
            const btnMap = document.getElementById('btnAbrirMapaExterno');

            if (!navigator.geolocation) {
                if (status) status.textContent = 'Este dispositivo não suporta GPS no navegador.';
                return;
            }

            const setBtnLoading = (isLoading) => {
                if (!btn) return;
                // no modo auto/silent, não “travar” UI como se fosse ação do usuário
                if (mode === 'manual') {
                    btn.disabled = !!isLoading;
                    btn.innerHTML = isLoading
                        ? '<i class="fas fa-circle-notch fa-spin"></i> Capturando...'
                        : '<i class="fas fa-location-crosshairs"></i> Capturar GPS';
                }
            };

            const setStatusCapturing = () => {
                if (!status) return;
                if (mode === 'silent') status.textContent = 'Salvando... (capturando GPS em segundo plano)';
                else status.textContent = 'Capturando localização...';
            };

            setBtnLoading(true);
            setStatusCapturing();

            // Em modo auto: aceitar posição em cache de até 45s para resposta mais rápida
            const maximumAge = (mode === 'auto' || mode === 'silent') ? 45000 : 0;
            const effectiveTimeout = (mode === 'auto' || mode === 'silent') ? Math.max(timeoutMs, 12000) : timeoutMs;

            try {
            const pos = await new Promise((resolve, reject) => {
                    navigator.geolocation.getCurrentPosition(resolve, reject, {
                        enableHighAccuracy: true,
                        timeout: effectiveTimeout,
                        maximumAge: maximumAge
                    });
            });

            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            const acc = pos.coords.accuracy;
            visitaGeo = { lat, lng, accuracy: acc };

            const accTxt = acc ? `±${Math.round(acc)}m` : '';
            if (status) status.textContent = 'GPS capturado automaticamente.';
            if (info) {
                info.style.display = 'block';
                info.textContent = `GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)} ${accTxt}`;
            }

            const extUrl = `https://www.openstreetmap.org/?mlat=${encodeURIComponent(lat)}&mlon=${encodeURIComponent(lng)}#map=18/${encodeURIComponent(lat)}/${encodeURIComponent(lng)}`;
            if (btnMap) { btnMap.href = extUrl; btnMap.style.display = 'inline-flex'; }

            if (mapWrap) mapWrap.style.display = 'block';
            renderLeafletMap(lat, lng, acc);
            } catch (err) {
                if (status) {
                    status.textContent = mode === 'silent'
                        ? 'GPS não capturado (salvando visita sem local).'
                        : 'Não foi possível obter o GPS. Clique em "Capturar GPS" para tentar novamente.';
                }
            }

            setBtnLoading(false);
        }

        function renderLeafletMap(lat, lng, accuracy) {
            if (typeof L === 'undefined') return;
            const el = document.getElementById('visitaMap');
            if (!el) return;

            // Inicializar uma vez
            if (!leafletMap) {
                leafletMap = L.map(el, { zoomControl: true, attributionControl: false });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19
                }).addTo(leafletMap);
            }

            leafletMap.setView([lat, lng], 17);
            if (leafletMarker) leafletMarker.remove();
            if (leafletCircle) leafletCircle.remove();
            leafletMarker = L.marker([lat, lng]).addTo(leafletMap);
            if (accuracy) {
                leafletCircle = L.circle([lat, lng], { radius: accuracy, color: '#2563EB', fillColor: '#2563EB', fillOpacity: 0.15 }).addTo(leafletMap);
            }
            setTimeout(() => leafletMap.invalidateSize(), 150);
        }

        // ========== PÁGINA RELATÓRIO DE VISITAS ==========
        let relatorioPrescritoresFiltered = [];
        let relatorioCurrentPage = 1;
        const relatorioItemsPerPage = 50;

        function copyDashboardToRelatorio() {
            const vDiaCount = document.getElementById('visitasDiaCount');
            const vsBody = document.getElementById('visitasSemanaBody');
            const vsCount = document.getElementById('visitasSemanaCount');
            const vMesCount = document.getElementById('visitasMesCount');
            const vaBody = document.getElementById('visitasAgendadasBody');
            const vaCount = document.getElementById('visitasAgendadasCount');
            const mapaResumo = document.getElementById('visitasMapaResumo');
            const mapaEmpty = document.getElementById('visitasMapaEmpty');
            const rDiaCount = document.getElementById('relatorioKpiDiaCount');
            const rKpiSemanaCount = document.getElementById('relatorioKpiSemanaCount');
            const rMesCount = document.getElementById('relatorioKpiMesCount');
            const rSemana = document.getElementById('relatorioSemanaBody');
            const rSemanaCount = document.getElementById('relatorioSemanaCount');
            const rAgenda = document.getElementById('relatorioAgendadasBody');
            const rAgendaCount = document.getElementById('relatorioAgendadasCount');
            const rMapaResumo = document.getElementById('relatorioMapaResumo');
            const rMapaEmpty = document.getElementById('relatorioMapaEmpty');
            if (vDiaCount && rDiaCount) rDiaCount.textContent = vDiaCount.textContent || '0';
            if (vsCount && rKpiSemanaCount) rKpiSemanaCount.textContent = vsCount.textContent || '0';
            if (vMesCount && rMesCount) rMesCount.textContent = vMesCount.textContent || '0';
            if (vsBody && rSemana) rSemana.innerHTML = vsBody.innerHTML;
            if (vsCount && rSemanaCount) rSemanaCount.textContent = vsCount ? vsCount.textContent : '0';
            if (vaBody && rAgenda) rAgenda.innerHTML = vaBody.innerHTML;
            if (vaCount && rAgendaCount) rAgendaCount.textContent = vaCount ? vaCount.textContent : '0';
            if (mapaResumo && rMapaResumo) rMapaResumo.innerHTML = mapaResumo.innerHTML;
            if (mapaEmpty && rMapaEmpty) { rMapaEmpty.style.display = mapaEmpty.style.display; }
        }

        function copyDashboardToAgendaKpis() {
            var vDia = document.getElementById('visitasDiaCount');
            var vSemana = document.getElementById('visitasSemanaCount');
            var vMes = document.getElementById('visitasMesCount');
            var aDia = document.getElementById('agendaKpiDiaCount');
            var aSemana = document.getElementById('agendaKpiSemanaCount');
            var aMes = document.getElementById('agendaKpiMesCount');
            if (vDia && aDia) aDia.textContent = vDia.textContent || '0';
            if (vSemana && aSemana) aSemana.textContent = vSemana.textContent || '0';
            if (vMes && aMes) aMes.textContent = vMes.textContent || '0';
        }

        async function openPaginaRelatorioVisitas() {
            goVisitadorPage('relatorio', null);
        }

        function showVisitadorPage(page) {
            var dash = document.getElementById('dashboardContent');
            var agenda = document.getElementById('paginaAgenda');
            var rel = document.getElementById('paginaRelatorioVisitas');
            var presc = document.getElementById('paginaPrescritores');
            var pedidos = document.getElementById('paginaPedidos');
            var roteiro = document.getElementById('paginaRoteiroVisitas');
            if (dash) dash.style.display = (page === 'dashboard') ? 'block' : 'none';
            if (agenda) agenda.style.display = (page === 'agenda') ? 'block' : 'none';
            if (rel) rel.style.display = (page === 'relatorio') ? 'block' : 'none';
            if (presc) presc.style.display = (page === 'prescritores') ? 'block' : 'none';
            if (pedidos) pedidos.style.display = (page === 'pedidos') ? 'block' : 'none';
            if (roteiro) roteiro.style.display = (page === 'roteiro') ? 'block' : 'none';
            setVisitadorNavActive(page);
            if (page === 'roteiro') initRoteiroPage();
        }

        function closePaginaRelatorioVisitas() {
            showVisitadorPage('dashboard');
        }

        var __roteiroMap = null;
        var __roteiroMarkers = [];
        var __roteiroPolylines = [];
        function initRoteiroPage() {
            var deEl = document.getElementById('roteiroDataDe');
            var ateEl = document.getElementById('roteiroDataAte');
            if (deEl && !deEl.value) {
                var hoje = new Date();
                var y = hoje.getFullYear(), m = String(hoje.getMonth() + 1).padStart(2, '0'), d = String(hoje.getDate()).padStart(2, '0');
                deEl.value = y + '-' + m + '-' + d;
            }
            if (ateEl && !ateEl.value) ateEl.value = deEl ? deEl.value : '';
            loadRoteiroVisitas();
        }
        function fmtMinutos(min) {
            if (!min || min <= 0) return '—';
            var h = Math.floor(min / 60), m = min % 60;
            return h > 0 ? h + 'h ' + m + 'min' : m + 'min';
        }
        function fmtDataHoraBr(dt) {
            if (!dt) return '—';
            var s = String(dt).replace('T', ' ').slice(0, 16);
            var partes = s.split(' ');
            if (partes.length < 2) return s;
            var dp = partes[0].split('-');
            return dp.length === 3 ? dp[2] + '/' + dp[1] + '/' + dp[0] + ' ' + partes[1] : s;
        }
        function fmtHoraBr(dt) {
            if (!dt) return '—';
            return String(dt).replace('T', ' ').slice(11, 16) || '—';
        }
        async function loadRoteiroVisitas() {
            var deEl = document.getElementById('roteiroDataDe');
            var ateEl = document.getElementById('roteiroDataAte');
            var resumoEl = document.getElementById('roteiroResumo');
            var dirEl = document.getElementById('roteiroDirecoes');
            var container = document.getElementById('roteiroMapContainer');
            var emptyEl = document.getElementById('roteiroMapEmpty');
            var kpisEl = document.getElementById('roteiroKpis');
            var detalhesEl = document.getElementById('roteiroDetalhes');
            if (!deEl || !ateEl) return;
            var dataDe = deEl.value || new Date().toISOString().slice(0, 10);
            var dataAte = ateEl.value || dataDe;
            if (resumoEl) resumoEl.textContent = 'Carregando...';
            if (dirEl) { dirEl.style.display = 'none'; dirEl.innerHTML = ''; }
            if (kpisEl) kpisEl.style.display = 'none';
            if (detalhesEl) { detalhesEl.style.display = 'none'; detalhesEl.innerHTML = ''; }
            try {
                var visitador = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || (localStorage.getItem('userName') || '');
                var res = await apiGet('get_relatorio_rota_completo', { data_de: dataDe, data_ate: dataAte, visitador: visitador });
                if (!res || !res.success) {
                    if (resumoEl) resumoEl.textContent = (res && res.error) || 'Erro ao carregar.';
                    return;
                }
                // KPIs
                if (kpisEl) {
                    kpisEl.style.display = 'grid';
                    document.getElementById('roteiroKpiKm').textContent = (res.total_km || 0).toFixed(1) + ' km';
                    document.getElementById('roteiroKpiTempoRota').textContent = fmtMinutos(res.total_tempo_rota_min);
                    document.getElementById('roteiroKpiTempoVisita').textContent = fmtMinutos(res.total_tempo_visita_min);
                    document.getElementById('roteiroKpiVisitas').textContent = res.total_visitas || 0;
                }
                if (resumoEl) resumoEl.textContent = (res.total_rotas || 0) + ' rota(s) encontrada(s) no período. ' + (res.total_visitas || 0) + ' visita(s).';
                // Detalhes das rotas
                renderRoteiroDetalhes(res.rotas || [], detalhesEl);
                // Mapa com pontos de todas as rotas
                renderRoteiroMapaCompleto(res.rotas || [], container, emptyEl, dirEl);
            } catch (e) {
                if (resumoEl) resumoEl.textContent = 'Erro ao carregar roteiro.';
                if (emptyEl) { emptyEl.style.display = 'block'; emptyEl.innerHTML = '<p style="margin:0;">Erro ao carregar dados.</p>'; }
                if (container) container.style.display = 'none';
            }
        }
        function renderRoteiroDetalhes(rotas, el) {
            if (!el) return;
            if (!rotas || rotas.length === 0) { el.style.display = 'none'; return; }
            var html = '';
            rotas.forEach(function (r, idx) {
                var statusBg = r.status === 'finalizada' ? 'var(--success)' : (r.status === 'pausada' ? 'var(--warning)' : 'var(--primary)');
                var statusLabel = r.status === 'finalizada' ? 'Finalizada' : (r.status === 'pausada' ? 'Pausada' : 'Em andamento');
                html += '<div style="background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:18px 20px; margin-bottom:16px;">';
                html += '<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:14px;">';
                html += '<h3 style="margin:0; font-size:1rem; font-weight:700; color:var(--text-primary);"><i class="fas fa-route" style="color:var(--primary); margin-right:8px;"></i>Rota #' + (idx + 1) + '</h3>';
                html += '<span style="padding:4px 12px; border-radius:999px; font-size:0.75rem; font-weight:700; color:white; background:' + statusBg + ';">' + statusLabel + '</span>';
                html += '</div>';
                var endInicio = r.local_inicio_endereco || (r.local_inicio_lat ? (r.local_inicio_lat.toFixed(5) + ', ' + r.local_inicio_lng.toFixed(5)) : '—');
                var endFim = r.local_fim_endereco || (r.local_fim_lat ? (r.local_fim_lat.toFixed(5) + ', ' + r.local_fim_lng.toFixed(5)) : '—');
                html += '<div style="display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:12px;">';
                html += roteiroInfoCard('fa-play-circle', 'Início da rota', fmtDataHoraBr(r.data_inicio));
                html += roteiroInfoCard('fa-flag-checkered', 'Fim da rota', r.data_fim ? fmtDataHoraBr(r.data_fim) : '—');
                html += roteiroInfoCard('fa-road', 'Distância', (r.km || 0).toFixed(1) + ' km');
                html += roteiroInfoCard('fa-clock', 'Tempo de percurso', fmtMinutos(r.tempo_rota_min));
                html += '</div>';
                html += '<div style="display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:14px;">';
                html += roteiroInfoCard('fa-user-clock', 'Tempo em visitas', fmtMinutos(r.tempo_visita_min));
                html += roteiroInfoCard('fa-map-pin', 'Pontos GPS', r.qtd_pontos || 0);
                html += roteiroInfoCard('fa-map-marker-alt', 'Local de início', endInicio);
                html += roteiroInfoCard('fa-map-marker-alt', 'Local de encerramento', endFim);
                if (r.pausado_em) html += roteiroInfoCard('fa-pause-circle', 'Pausada em', fmtDataHoraBr(r.pausado_em));
                html += '</div>';
                // Visitas da rota
                if (r.visitas && r.visitas.length > 0) {
                    html += '<div style="font-size:0.8rem; font-weight:700; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;"><i class="fas fa-clipboard-list" style="margin-right:6px;"></i>Visitas do dia (' + r.visitas.length + ')</div>';
                    html += '<div style="overflow-x:auto;">';
                    html += '<table style="width:100%; border-collapse:collapse; font-size:0.82rem;">';
                    html += '<thead><tr style="background:var(--bg-body); color:var(--text-secondary);">';
                    html += '<th style="padding:8px 10px; text-align:left; font-weight:700; border-bottom:1px solid var(--border);">Prescritor</th>';
                    html += '<th style="padding:8px 10px; text-align:left; font-weight:700; border-bottom:1px solid var(--border);">Início</th>';
                    html += '<th style="padding:8px 10px; text-align:left; font-weight:700; border-bottom:1px solid var(--border);">Fim</th>';
                    html += '<th style="padding:8px 10px; text-align:left; font-weight:700; border-bottom:1px solid var(--border);">Duração</th>';
                    html += '<th style="padding:8px 10px; text-align:left; font-weight:700; border-bottom:1px solid var(--border);">Status</th>';
                    html += '<th style="padding:8px 10px; text-align:left; font-weight:700; border-bottom:1px solid var(--border);">Local</th>';
                    html += '</tr></thead><tbody>';
                    r.visitas.forEach(function (v) {
                        var stColor = (v.status_visita === 'Realizada') ? 'var(--success)' : 'var(--text-secondary)';
                        html += '<tr style="border-bottom:1px solid var(--border);">';
                        html += '<td style="padding:8px 10px; color:var(--text-primary); font-weight:600;">' + escNotif(v.prescritor || '—') + '</td>';
                        html += '<td style="padding:8px 10px; color:var(--text-secondary);">' + fmtHoraBr(v.inicio_visita) + '</td>';
                        html += '<td style="padding:8px 10px; color:var(--text-secondary);">' + fmtHoraBr(v.fim_visita) + '</td>';
                        html += '<td style="padding:8px 10px; color:var(--primary); font-weight:600;">' + fmtMinutos(v.duracao_min > 0 ? v.duracao_min : 0) + '</td>';
                        html += '<td style="padding:8px 10px; color:' + stColor + '; font-weight:600;">' + escNotif(v.status_visita || '—') + '</td>';
                        html += '<td style="padding:8px 10px; color:var(--text-secondary);">' + escNotif(v.local_visita || '—') + '</td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<div style="font-size:0.82rem; color:var(--text-secondary); opacity:0.7;">Nenhuma visita registrada neste dia.</div>';
                }
                html += '</div>';
            });
            el.innerHTML = html;
            el.style.display = 'block';
        }
        function roteiroInfoCard(icon, label, value) {
            return '<div style="padding:10px 14px; border:1px solid var(--border); border-radius:10px; background:var(--bg-body);">' +
                '<div style="font-size:0.68rem; color:var(--text-secondary); font-weight:700; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:4px;"><i class="fas ' + icon + '" style="margin-right:4px;"></i>' + label + '</div>' +
                '<div style="font-size:0.92rem; font-weight:700; color:var(--text-primary);">' + value + '</div></div>';
        }
        function renderRoteiroMapaCompleto(rotas, containerEl, emptyEl, dirEl) {
            if (!containerEl) return;
            var temPontos = false;
            rotas.forEach(function (r) { if (r.pontos && r.pontos.length > 0) temPontos = true; });
            var temVisitasGeo = false;
            rotas.forEach(function (r) {
                if (r.visitas) r.visitas.forEach(function (v) { if (v.geo_lat) temVisitasGeo = true; });
            });
            if (!temPontos && !temVisitasGeo) {
                containerEl.style.display = 'none';
                if (emptyEl) emptyEl.style.display = 'block';
                if (dirEl) dirEl.style.display = 'none';
                if (typeof L !== 'undefined' && __roteiroMap) { __roteiroMap.remove(); __roteiroMap = null; }
                __roteiroMarkers = [];
                __roteiroPolylines = [];
                return;
            }
            containerEl.style.display = 'block';
            if (emptyEl) emptyEl.style.display = 'none';
            if (typeof L === 'undefined') {
                if (emptyEl) { emptyEl.style.display = 'block'; emptyEl.innerHTML = '<p>Mapa indisponível (Leaflet).</p>'; }
                containerEl.style.display = 'none';
                return;
            }
            if (__roteiroMap) {
                __roteiroMap.remove();
                __roteiroMarkers.forEach(function (m) { if (m && m.remove) m.remove(); });
                __roteiroPolylines.forEach(function (p) { if (p && p.remove) p.remove(); });
            }
            __roteiroMap = L.map(containerEl, { zoomControl: true, attributionControl: false }).setView([-8.76, -63.86], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(__roteiroMap);
            __roteiroMarkers = [];
            __roteiroPolylines = [];
            var allLatLngs = [];
            var colors = ['#2563EB', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4'];
            // Traçar rotas
            rotas.forEach(function (r, rIdx) {
                if (!r.pontos || r.pontos.length === 0) return;
                var latLngs = r.pontos.map(function (p) { return [p.lat, p.lng]; });
                allLatLngs = allLatLngs.concat(latLngs);
                var cor = colors[rIdx % colors.length];
                var poly = L.polyline(latLngs, { color: cor, weight: 4, opacity: 0.8 }).addTo(__roteiroMap);
                __roteiroPolylines.push(poly);
                var startM = L.circleMarker(latLngs[0], { radius: 7, color: '#10B981', fillColor: '#10B981', fillOpacity: 1 }).addTo(__roteiroMap);
                startM.bindTooltip('<b>Início rota</b><br>' + fmtDataHoraBr(r.data_inicio), { direction: 'top', className: 'roteiro-tooltip' });
                __roteiroMarkers.push(startM);
                if (latLngs.length > 1) {
                    var endM = L.circleMarker(latLngs[latLngs.length - 1], { radius: 7, color: '#EF4444', fillColor: '#EF4444', fillOpacity: 1 }).addTo(__roteiroMap);
                    endM.bindTooltip('<b>Fim rota</b><br>' + (r.data_fim ? fmtDataHoraBr(r.data_fim) : 'Em andamento'), { direction: 'top', className: 'roteiro-tooltip' });
                    __roteiroMarkers.push(endM);
                }
            });
            // Marcadores de visitas com GPS (numerados)
            var visitasPts = [];
            var visitaNumGlobal = 0;
            rotas.forEach(function (r) {
                if (!r.visitas) return;
                r.visitas.forEach(function (v) {
                    var lat = parseFloat(v.geo_lat);
                    var lng = parseFloat(v.geo_lng);
                    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
                    visitaNumGlobal++;
                    var num = visitaNumGlobal;
                    allLatLngs.push([lat, lng]);
                    visitasPts.push({ prescritor: v.prescritor, lat: lat, lng: lng });
                    var numIcon = L.divIcon({
                        className: 'roteiro-visita-icon',
                        html: '<span style="font-size:13px; font-weight:800; color:#fff; line-height:30px;">' + num + '</span>',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15],
                        popupAnchor: [0, -15]
                    });
                    var dataStr = v.data_visita ? v.data_visita.split('-').reverse().join('/') : '—';
                    var horaInicio = fmtHoraBr(v.inicio_visita);
                    var horaFim = v.hora_fim || fmtHoraBr(v.fim_visita);
                    var enderecoVisita = v.geo_endereco || v.local_visita || '—';
                    var popupHtml = '<div style="min-width:200px;">';
                    popupHtml += '<div style="font-weight:800; font-size:0.95rem; margin-bottom:6px; color:#1e293b;">' + num + '. ' + escNotif(v.prescritor || '—') + '</div>';
                    popupHtml += '<div style="font-size:0.82rem; color:#555; margin-bottom:5px;"><i class="fas fa-map-marker-alt" style="margin-right:4px; color:#EF4444;"></i>' + escNotif(enderecoVisita) + '</div>';
                    popupHtml += '<div style="font-size:0.82rem; color:#555; margin-bottom:3px;"><i class="far fa-calendar" style="margin-right:4px; color:#2563EB;"></i>' + dataStr + '</div>';
                    popupHtml += '<div style="font-size:0.82rem; color:#555; margin-bottom:3px;"><i class="far fa-clock" style="margin-right:4px; color:#2563EB;"></i>' + horaInicio + ' → ' + horaFim + '</div>';
                    if (v.duracao_min > 0) popupHtml += '<div style="font-size:0.82rem; color:#555; margin-bottom:3px;"><i class="fas fa-hourglass-half" style="margin-right:4px; color:#F59E0B;"></i>' + fmtMinutos(v.duracao_min) + '</div>';
                    if (v.status_visita) popupHtml += '<div style="font-size:0.78rem; margin-top:4px; padding:2px 8px; display:inline-block; border-radius:999px; background:' + (v.status_visita === 'Realizada' ? '#10B981' : '#94a3b8') + '; color:#fff; font-weight:700;">' + escNotif(v.status_visita) + '</div>';
                    popupHtml += '</div>';
                    var marker = L.marker([lat, lng], { icon: numIcon }).addTo(__roteiroMap);
                    marker.bindPopup(popupHtml, { minWidth: 220 });
                    marker.bindTooltip('<b>' + num + '</b> — ' + escNotif(v.prescritor || '—'), { direction: 'top', className: 'roteiro-tooltip' });
                    __roteiroMarkers.push(marker);
                });
            });
            // Direção da rota (visitas)
            if (dirEl && visitasPts.length > 0) {
                var dirHtml = '<strong>Ordem das visitas:</strong> ';
                dirHtml += visitasPts.map(function (p, i) { return (i + 1) + '. ' + (p.prescritor || '—'); }).join(' → ');
                dirEl.innerHTML = dirHtml;
                dirEl.style.display = 'block';
            }
            if (allLatLngs.length > 1) {
                __roteiroMap.fitBounds(allLatLngs, { padding: [30, 30] });
            } else if (allLatLngs.length === 1) {
                __roteiroMap.setView(allLatLngs[0], 16);
            }
        }

        var __agendaAno = new Date().getFullYear();
        var __agendaMes = new Date().getMonth() + 1;
        var __agendaVisitas = [];

        function renderPaginaAgenda() {
            var titulo = document.getElementById('agendaMesTitulo');
            if (titulo) titulo.textContent = getAgendaMesNome(__agendaMes) + ' ' + __agendaAno;
            loadAgendaMes(__agendaAno, __agendaMes);
        }

        function getAgendaMesNome(mes) {
            var nomes = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
            return nomes[mes] || '';
        }

        async function loadAgendaMes(ano, mes) {
            var titulo = document.getElementById('agendaMesTitulo');
            if (titulo) titulo.textContent = getAgendaMesNome(mes) + ' ' + ano + '...';
            try {
                var visitador = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || (localStorage.getItem('userName') || '');
                var res = await apiGet('get_visitas_agendadas_mes', { ano: ano, mes: mes, visitador: visitador });
                __agendaVisitas = (res && res.visitas) ? res.visitas : [];
                if (titulo) titulo.textContent = getAgendaMesNome(mes) + ' ' + ano;
                renderAgendaCalendario(__agendaVisitas, ano, mes);
            } catch (e) {
                __agendaVisitas = [];
                if (titulo) titulo.textContent = getAgendaMesNome(mes) + ' ' + ano;
                renderAgendaCalendario([], ano, mes);
            }
        }

        function getSemanaAtualBounds() {
            var hoje = new Date();
            var diff = (hoje.getDay() + 6) % 7;
            var segunda = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - diff);
            var domingo = new Date(segunda.getFullYear(), segunda.getMonth(), segunda.getDate() + 6);
            var fmt = function (d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
            return { segunda: fmt(segunda), domingo: fmt(domingo) };
        }
        function renderAgendaCalendario(visitas, ano, mes) {
            var container = document.getElementById('agendaCalendarioDias');
            if (!container) return;
            var primeiroDia = new Date(ano, mes - 1, 1).getDay();
            var diasNoMes = new Date(ano, mes, 0).getDate();
            var hoje = new Date();
            var hojeStr = hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0') + '-' + String(hoje.getDate()).padStart(2, '0');
            var semana = getSemanaAtualBounds();
            var visitasPorDia = {};
            visitas.forEach(function (v) {
                var d = (v.data_agendada || '').toString().slice(0, 10);
                if (!visitasPorDia[d]) visitasPorDia[d] = [];
                visitasPorDia[d].push(v);
            });
            var html = '';
            var totalCells = Math.ceil((primeiroDia + diasNoMes) / 7) * 7;
            var diaAtual = 1;
            for (var i = 0; i < totalCells; i++) {
                var vazio = i < primeiroDia || diaAtual > diasNoMes;
                var dia = vazio ? '' : diaAtual;
                var dataStr = vazio ? '' : ano + '-' + String(mes).padStart(2, '0') + '-' + String(dia).padStart(2, '0');
                var list = (dataStr && visitasPorDia[dataStr]) ? visitasPorDia[dataStr] : [];
                var isHoje = dataStr === hojeStr;
                if (!vazio) diaAtual++;
                var bg = vazio ? 'var(--bg-body)' : (isHoje ? 'rgba(59,130,246,0.15)' : 'var(--bg-card)');
                html += '<div class="agenda-dia-cell" style="min-height:100px; padding:8px; background:' + bg + '; border-radius:6px; font-size:0.8rem;">';
                if (dia) {
                    html += '<div style="font-weight:700; color:var(--text-primary); margin-bottom:6px;">' + dia + '</div>';
                    list.forEach(function (v) {
                        var tipo = (v.tipo || 'agendada');
                        var isRealizada = (tipo === 'realizada');
                        var dataVisit = (v.data_agendada || '').toString().slice(0, 10);
                        var horaVisit = (v.hora || '').slice(0, 5);
                        var isNaSemanaAtual = dataVisit && dataVisit >= semana.segunda && dataVisit <= semana.domingo;
                        var bordaCor = isRealizada ? '#16a34a' : 'var(--primary)';
                        var badgeCor = isRealizada ? '#16a34a' : 'var(--primary)';
                        var hora = (v.hora || '').slice(0, 5);
                        var prescCompleto = (v.prescritor || '-').trim();
                        var esc = function (x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
                        var hid = (v.historico_id && parseInt(v.historico_id, 10)) ? parseInt(v.historico_id, 10) : '';
                        var id = v.id ? parseInt(v.id, 10) : '';
                        var dataStrEsc = esc((v.data_agendada || '').toString().slice(0, 10));
                        html += '<div class="agenda-visita-item" data-tipo="' + esc(tipo) + '" data-prescritor="' + esc(v.prescritor || '') + '" data-id="' + id + '" data-historicoid="' + hid + '" data-agendamento-id="' + id + '" data-agendamento-prescritor="' + esc(v.prescritor || '') + '" data-agendamento-data="' + dataStrEsc + '" data-agendamento-hora="' + esc(hora) + '" style="border-left:3px solid ' + bordaCor + '; padding:8px 8px 8px 10px; margin-bottom:8px; cursor:pointer; background:var(--bg-body); border-radius:0 8px 8px 0; box-shadow:0 1px 2px rgba(0,0,0,0.04); transition:box-shadow 0.15s;">';
                        html += '<div style="display:flex; flex-direction:column; gap:4px;">';
                        html += '<div class="agenda-visita-nome" title="Clique para atender" style="font-size:0.78rem; font-weight:700; color:var(--text-primary); line-height:1.3; word-break:break-word;">' + esc(prescCompleto) + (hora ? ' <span style="font-weight:500; color:var(--text-secondary); font-size:0.72rem;">' + esc(hora) + '</span>' : '') + '</div>';
                        html += '<div class="agenda-visita-acoes" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">';
                        html += '<span style="font-size:0.65rem; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; color:' + badgeCor + ';">' + (isRealizada ? 'Realizada' : 'Agendada') + '</span>';
                        if (hid) html += '<button type="button" class="agenda-btn-relatorio" data-historicoid="' + hid + '" onclick="event.stopPropagation();openRelatorioAgendamento(' + hid + ');" title="Ver relatório da visita" style="background:none; border:none; padding:2px; color:var(--primary); cursor:pointer; font-size:0.75rem;"><i class="fas fa-clipboard-list"></i></button>';
                        if (id && isNaSemanaAtual) {
                            html += '<button type="button" class="agenda-btn-editar" data-id="' + id + '" onclick="event.stopPropagation();openEditarAgendamentoModal(this);" title="Editar" style="background:none; border:none; padding:2px; color:var(--primary); cursor:pointer; font-size:0.75rem;"><i class="fas fa-pen"></i></button>';
                            html += '<button type="button" class="agenda-btn-excluir" data-id="' + id + '" onclick="event.stopPropagation();excluirAgendamentoConfirm(' + id + ');" title="Excluir" style="background:none; border:none; padding:2px; color:var(--danger); cursor:pointer; font-size:0.75rem;"><i class="fas fa-trash-alt"></i></button>';
                        }
                        html += '</div></div></div>';
                    });
                }
                html += '</div>';
            }
            container.innerHTML = html;
        }

        function agendaMesAnterior() {
            __agendaMes--;
            if (__agendaMes < 1) { __agendaMes = 12; __agendaAno--; }
            loadAgendaMes(__agendaAno, __agendaMes);
        }

        function agendaMesProximo() {
            __agendaMes++;
            if (__agendaMes > 12) { __agendaMes = 1; __agendaAno++; }
            loadAgendaMes(__agendaAno, __agendaMes);
        }

        function openNovoPrescritorModal() {
            // Reaproveita o formulário completo de prescritor em modo novo cadastro.
            if (typeof openEditarPrescritorModal === 'function') {
                openEditarPrescritorModal('', { isNovoCadastro: true });
            }
        }

        async function openNovoAgendamentoModal(editItem) {
            var idEl = document.getElementById('novoAgendamentoId');
            var titleEl = document.getElementById('modalNovoAgendamentoTitle');
            var btnSalvar = document.getElementById('btnSalvarNovoAgendamento');
            var sel = document.getElementById('novoAgendamentoPrescritor');
            var dataEl = document.getElementById('novoAgendamentoData');
            var horaEl = document.getElementById('novoAgendamentoHora');
            if (!sel || !dataEl) return;
            var isEdit = editItem && (editItem.id || (editItem.dataset && (editItem.dataset.agendamentoId || editItem.dataset.id)));
            if (idEl) idEl.value = isEdit ? (editItem.dataset && (editItem.dataset.agendamentoId || editItem.dataset.id)) || editItem.id || '' : '';
            if (titleEl) titleEl.innerHTML = isEdit ? '<i class="fas fa-pen" style="margin-right:8px; color:var(--primary);"></i>Editar agendamento' : '<i class="fas fa-calendar-plus" style="margin-right:8px; color:var(--primary);"></i>Novo agendamento';
            if (btnSalvar) btnSalvar.innerHTML = isEdit ? '<i class="fas fa-save" style="margin-right:6px;"></i>Salvar alterações' : '<i class="fas fa-check" style="margin-right:6px;"></i>Agendar';
            sel.innerHTML = '<option value="">Carregando...</option>';
            document.getElementById('modalNovoAgendamento').style.display = 'flex';
            if (isEdit && (editItem.dataset || editItem)) {
                var d = editItem.dataset || {};
                dataEl.value = (d.agendamentoData || editItem.data_agendada || '').toString().slice(0, 10);
                if (horaEl) horaEl.value = (d.agendamentoHora || editItem.hora || '').slice(0, 5);
            } else {
                dataEl.value = __agendaAno + '-' + String(__agendaMes).padStart(2, '0') + '-01';
                if (horaEl) horaEl.value = '';
            }
            try {
                var list = await apiGet('all_prescritores', { visitador: currentVisitadorName || '', ano: __agendaAno });
                var prescritores = Array.isArray(list) ? list : [];
                sel.innerHTML = '<option value="">Selecione o prescritor</option>';
                prescritores.forEach(function (p) {
                    var nome = p.prescritor || p.nome || '-';
                    sel.appendChild(new Option(nome, nome));
                });
                if (isEdit && (editItem.dataset || editItem)) {
                    var presc = (editItem.dataset && editItem.dataset.agendamentoPrescritor) || editItem.prescritor || '';
                    sel.value = presc;
                }
            } catch (e) {
                sel.innerHTML = '<option value="">Erro ao carregar prescritores</option>';
            }
        }

        function openEditarAgendamentoModal(btn) {
            var row = btn.closest('.agenda-visita-item');
            if (!row) return;
            openNovoAgendamentoModal(row);
        }

        async function openRelatorioAgendamento(historicoId) {
            if (!historicoId) return;
            try {
                var visitador = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || (localStorage.getItem('userName') || '');
                var res = await apiGet('get_detalhe_visita', { historico_id: historicoId, visitador: visitador });
                if (res && res.success && res.visita && typeof openDetalheVisitaModal === 'function') {
                    openDetalheVisitaModal(res.visita);
                } else {
                    alert('Relatório não encontrado.');
                }
            } catch (e) {
                alert('Erro ao carregar relatório.');
            }
        }

        async function excluirAgendamentoConfirm(id) {
            if (!id || !confirm('Excluir este agendamento?')) return;
            try {
                var res = await apiPost('excluir_agendamento', { id: id });
                if (res && res.success) {
                    loadAgendaMes(__agendaAno, __agendaMes);
                    if (typeof loadVisitadorDashboard === 'function') loadVisitadorDashboard(currentVisitadorName);
                } else {
                    alert(res && res.error ? res.error : 'Erro ao excluir.');
                }
            } catch (e) {
                alert('Erro de conexão.');
            }
        }

        function closeNovoAgendamentoModal() {
            document.getElementById('modalNovoAgendamento').style.display = 'none';
            var idEl = document.getElementById('novoAgendamentoId');
            if (idEl) idEl.value = '';
        }

        async function saveNovoAgendamento() {
            var idEl = document.getElementById('novoAgendamentoId');
            var id = (idEl && idEl.value) ? idEl.value.trim() : '';
            var prescritor = (document.getElementById('novoAgendamentoPrescritor') || {}).value;
            var data = (document.getElementById('novoAgendamentoData') || {}).value;
            var hora = (document.getElementById('novoAgendamentoHora') || {}).value;
            if (!prescritor || !data) {
                alert('Selecione o prescritor e a data.');
                return;
            }
            var btn = document.getElementById('btnSalvarNovoAgendamento');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; }
            try {
                var payload = { prescritor: prescritor, data_agendada: data };
                if (hora) payload.hora = hora;
                var action = id ? 'update_agendamento' : 'criar_agendamento';
                if (id) payload.id = parseInt(id, 10);
                var res = await apiPost(action, payload);
                if (res && res.success) {
                    closeNovoAgendamentoModal();
                    loadAgendaMes(__agendaAno, __agendaMes);
                    if (typeof loadVisitadorDashboard === 'function') loadVisitadorDashboard(currentVisitadorName);
                } else {
                    alert(res && res.error ? res.error : (id ? 'Erro ao atualizar.' : 'Erro ao agendar.'));
                }
            } catch (e) {
                alert('Erro de conexão.');
            }
            if (btn) { btn.disabled = false; btn.innerHTML = id ? '<i class="fas fa-save" style="margin-right:6px;"></i>Salvar alterações' : '<i class="fas fa-check" style="margin-right:6px;"></i>Agendar'; }
        }

        function setVisitadorNavActive(page) {
            var nav = document.getElementById('visitadorNav');
            var drawer = document.getElementById('visitadorNavDrawer');
            if (nav) nav.querySelectorAll('.visitador-nav-link').forEach(function (a) {
                a.classList.toggle('active', (a.getAttribute('data-page') || '') === page);
            });
            if (drawer) drawer.querySelectorAll('.visitador-nav-drawer-link').forEach(function (a) {
                a.classList.toggle('active', (a.getAttribute('data-page') || '') === page);
            });
        }

        function toggleVisitadorNavDrawer() {
            var drawer = document.getElementById('visitadorNavDrawer');
            var overlay = document.getElementById('visitadorNavDrawerOverlay');
            if (!drawer || !overlay) return;
            var isOpen = drawer.classList.toggle('open');
            overlay.classList.toggle('open', isOpen);
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }

        function closeVisitadorNavDrawer() {
            var drawer = document.getElementById('visitadorNavDrawer');
            var overlay = document.getElementById('visitadorNavDrawerOverlay');
            if (drawer) drawer.classList.remove('open');
            if (overlay) overlay.classList.remove('open');
            document.body.style.overflow = '';
        }

        function goVisitadorPageAndCloseDrawer(page, e) {
            if (e) e.preventDefault();
            goVisitadorPage(page, e);
            closeVisitadorNavDrawer();
            return false;
        }

        function goVisitadorPage(page, e) {
            if (e) e.preventDefault();
            if (page === 'dashboard') {
                showVisitadorPage('dashboard');
                return false;
            }
            if (page === 'agenda') {
                showVisitadorPage('agenda');
                (async function () {
                    if (typeof loadVisitadorDashboard === 'function') {
                        var anoEl = document.getElementById('anoSelect');
                        var mesEl = document.getElementById('mesSelect');
                        var ano = anoEl ? anoEl.value : null;
                        var mes = mesEl && mesEl.value ? mesEl.value : null;
                        await loadVisitadorDashboard(currentVisitadorName, ano, mes);
                    }
                    copyDashboardToAgendaKpis();
                    renderPaginaAgenda();
                })();
                return false;
            }
            if (page === 'relatorio') {
                goVisitadorPage('agenda', e);
                return false;
            }
            if (page === 'prescritores') {
                showVisitadorPage('prescritores');
                loadPaginaPrescritores();
                return false;
            }
            if (page === 'pedidos') {
                showVisitadorPage('pedidos');
                loadPaginaPedidos();
                return false;
            }
            if (page === 'roteiro') {
                showVisitadorPage('roteiro');
                return false;
            }
            return false;
        }

        var __paginaPrescritoresList = [];

        async function loadPaginaPrescritores() {
            var tbody = document.getElementById('paginaPrescritoresTbody');
            if (!tbody) return;
            var anoAtual = (new Date().getFullYear()).toString();
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:48px 24px; color:var(--text-secondary); font-size:0.9rem;"><i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Carregando...</td></tr>';
            try {
                var visitador = currentVisitadorName || localStorage.getItem('userName') || '';
                var params = { visitador: visitador, ano: anoAtual };
                var list = await apiGet('all_prescritores', params) || [];
                var contatos = {};
                try { contatos = await apiGet('get_prescritor_contatos') || {}; } catch (e) {}
                __paginaPrescritoresList = list.map(function (p) {
                    return {
                        prescritor: p.prescritor || p.nome || '-',
                        profissao: (p.profissao || '').trim() || '—',
                        whatsapp: contatos[p.prescritor] || contatos[p.nome] || '',
                        ultima_compra: p.ultima_compra || p.ultima_compra_aprovacao || '',
                        valor_aprovado: p.valor_aprovado ?? p.total_aprovado ?? 0,
                        total_pedidos: p.total_pedidos ?? p.qtd_aprovados ?? 0,
                        valor_recusado: p.valor_recusado ?? p.total_recusado ?? 0,
                        qtd_recusados: p.qtd_recusados ?? 0
                    };
                });
                renderPaginaPrescritores();
                var searchEl = document.getElementById('searchPrescritoresPage');
                if (searchEl) searchEl.oninput = renderPaginaPrescritores;
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:48px 24px; color:var(--danger); font-size:0.9rem;"><i class="fas fa-exclamation-circle" style="margin-right:8px;"></i>Erro ao carregar prescritores.</td></tr>';
            }
        }

        var __paginaPrescritoresPage = 1;
        var __paginaPrescritoresPageSize = 15;
        var __paginaPrescritoresSort = { column: 'valor_aprovado', direction: 'desc' };

        function sortPaginaPrescritoresBy(col) {
            if (__paginaPrescritoresSort.column === col) __paginaPrescritoresSort.direction = __paginaPrescritoresSort.direction === 'asc' ? 'desc' : 'asc';
            else { __paginaPrescritoresSort.column = col; __paginaPrescritoresSort.direction = (col === 'prescritor' ? 'asc' : 'desc'); }
            __paginaPrescritoresPage = 1;
            renderPaginaPrescritores();
        }

        function sortPaginaPrescritoresList(list, col, dir) {
            var d = dir === 'asc' ? 1 : -1;
            return list.slice().sort(function (a, b) {
                var va = a[col], vb = b[col];
                if (col === 'valor_aprovado' || col === 'valor_recusado') { va = parseFloat(va) || 0; vb = parseFloat(vb) || 0; return (va - vb) * d; }
                if (col === 'prescritor') { va = String(va || '').toLowerCase(); vb = String(vb || '').toLowerCase(); return (va < vb ? -1 : (va > vb ? 1 : 0)) * d; }
                va = String(va || '').toLowerCase(); vb = String(vb || '').toLowerCase();
                return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
            });
        }

        function updatePaginaPrescritoresSortIcons() {
            var col = __paginaPrescritoresSort.column;
            var dir = __paginaPrescritoresSort.direction;
            var map = { prescritor: 'prescritoresThPrescritor', valor_aprovado: 'prescritoresThValorAprovado', valor_recusado: 'prescritoresThValorRecusado' };
            for (var key in map) {
                var el = document.getElementById(map[key]);
                if (!el) continue;
                var icon = el.querySelector('i');
                if (!icon) continue;
                icon.className = key === col ? (dir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down') : 'fas fa-sort';
                icon.style.opacity = key === col ? '1' : '0.4';
            }
        }

        function renderPaginaPrescritores() {
            var tbody = document.getElementById('paginaPrescritoresTbody');
            var pagEl = document.getElementById('paginaPrescritoresPagination');
            if (!tbody) return;
            var searchInput = document.getElementById('searchPrescritoresPage');
            var hadFocus = searchInput && document.activeElement === searchInput;
            var savedSelStart = searchInput ? searchInput.selectionStart : 0;
            var savedSelEnd = searchInput ? searchInput.selectionEnd : 0;
            var fullList = __paginaPrescritoresList || [];
            var totalAprovado = fullList.reduce(function (acc, p) { return acc + (parseFloat(p.valor_aprovado) || 0); }, 0);
            var totalRecusado = fullList.reduce(function (acc, p) { return acc + (parseFloat(p.valor_recusado) || 0); }, 0);
            var totalQtdAprovados = fullList.reduce(function (acc, p) { return acc + (parseInt(p.total_pedidos, 10) || 0); }, 0);
            var totalQtdRecusados = fullList.reduce(function (acc, p) { return acc + (parseInt(p.qtd_recusados, 10) || 0); }, 0);
            var fmtBr = function (v) { var n = (parseFloat(v) || 0).toFixed(2); var p = n.split('.'); return 'R$ ' + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + p[1]; };
            var cardValorAprov = document.getElementById('prescritoresCardValorAprovado');
            var cardValorRec = document.getElementById('prescritoresCardValorRecusado');
            var cardQtdAprov = document.getElementById('prescritoresCardQtdAprovados');
            var cardQtdRec = document.getElementById('prescritoresCardQtdRecusados');
            if (cardValorAprov) cardValorAprov.textContent = fmtBr(totalAprovado);
            if (cardValorRec) cardValorRec.textContent = fmtBr(totalRecusado);
            if (cardQtdAprov) cardQtdAprov.textContent = totalQtdAprovados + ' ped.';
            if (cardQtdRec) cardQtdRec.textContent = totalQtdRecusados + ' ped.';
            var q = (document.getElementById('searchPrescritoresPage') || {}).value || '';
            var list = fullList;
            if (q) {
                q = q.toLowerCase().trim();
                list = list.filter(function (p) { return (p.prescritor || '').toLowerCase().indexOf(q) !== -1; });
            }
            list = sortPaginaPrescritoresList(list, __paginaPrescritoresSort.column, __paginaPrescritoresSort.direction);
            var esc = function (x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
            var fmtMoney = function (v) { var n = parseFloat(v) || 0; return 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:48px 24px; color:var(--text-secondary); font-size:0.9rem;"><i class="fas fa-user-slash" style="margin-right:8px; opacity:0.6;"></i>Nenhum prescritor encontrado.</td></tr>';
                if (pagEl) pagEl.innerHTML = '';
                updatePaginaPrescritoresSortIcons();
                if (hadFocus && searchInput) {
                    searchInput.focus();
                    try { searchInput.setSelectionRange(savedSelStart, savedSelEnd); } catch (e) { }
                }
                return;
            }
            var totalPages = Math.max(1, Math.ceil(list.length / __paginaPrescritoresPageSize));
            __paginaPrescritoresPage = Math.min(Math.max(1, __paginaPrescritoresPage), totalPages);
            var start = (__paginaPrescritoresPage - 1) * __paginaPrescritoresPageSize;
            var pageList = list.slice(start, start + __paginaPrescritoresPageSize);
            var html = '';
            pageList.forEach(function (p) {
                var nome = p.prescritor || '-';
                var whatsapp = (p.whatsapp || '').trim();
                var onlyDigits = whatsapp.replace(/\D/g, '');
                var waNum = onlyDigits.length >= 10 && onlyDigits.length <= 11 ? '55' + onlyDigits : (onlyDigits.length >= 12 ? onlyDigits : '');
                var valorAprovado = (p.valor_aprovado != null && p.valor_aprovado !== '') ? fmtMoney(p.valor_aprovado) : '—';
                var qtdAprov = (p.total_pedidos != null && p.total_pedidos !== '') ? parseInt(p.total_pedidos, 10) : 0;
                var valorRecusado = (p.valor_recusado != null && p.valor_recusado !== '') ? fmtMoney(p.valor_recusado) : '—';
                var qtdRec = (p.qtd_recusados != null && p.qtd_recusados !== '') ? parseInt(p.qtd_recusados, 10) : 0;
                var btnWa = waNum
                    ? '<a href="https://wa.me/' + esc(waNum) + '" target="_blank" rel="noopener noreferrer" class="prescritores-btn" style="color:#25D366; text-decoration:none; padding:6px 10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-body); display:inline-flex; align-items:center; justify-content:center;" title="Enviar mensagem no WhatsApp"><i class="fab fa-whatsapp"></i></a>'
                    : '<button type="button" class="prescritores-btn btn-whatsapp-prescritor-page" data-nome="' + esc(nome) + '" title="Cadastrar WhatsApp para enviar mensagem" style="color:var(--text-secondary); padding:6px 10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-body); cursor:pointer;"><i class="fab fa-whatsapp"></i></button>';
                html += '<tr>' +
                    '<td>' + esc(nome) + '</td>' +
                    '<td style="color:var(--text-secondary);">' + esc(p.profissao || '—') + '</td>' +
                    '<td style="text-align:right; font-weight:600; color:var(--success);">' + valorAprovado + '<br><small style="opacity:0.9; font-weight:500;">' + qtdAprov + ' ped.</small></td>' +
                    '<td style="text-align:right; font-weight:600; color:var(--danger, #EF4444);">' + valorRecusado + '<br><small style="opacity:0.9; font-weight:500;">' + qtdRec + ' ped.</small></td>' +
                    '<td>' +
                    '<span class="prescritores-actions">' +
                    btnWa +
                    '<button type="button" class="prescritores-btn btn-pedidos-prescritor-page" data-nome="' + esc(nome) + '" title="Ver lista de todos os pedidos (aprovados + recusados)"><i class="fas fa-list-alt"></i></button>' +
                    '<button type="button" class="prescritores-btn prescritores-btn-editar btn-editar-prescritor-page" data-nome="' + esc(nome) + '" title="Editar prescritor"><i class="fas fa-user-edit"></i></button>' +
                    '<button type="button" class="prescritores-btn prescritores-btn-relatorio btn-relatorio-visitas-prescritor-page" data-nome="' + esc(nome) + '" title="Relatório de visitas"><i class="fas fa-clipboard-list"></i></button>' +
                    '<button type="button" class="prescritores-btn prescritores-btn-aprovados btn-aprovados-prescritor-page" data-nome="' + esc(nome) + '" title="Ver lista dos pedidos aprovados (valor verde acima)"><i class="fas fa-check"></i></button>' +
                    '<button type="button" class="prescritores-btn prescritores-btn-recusados btn-recusados-prescritor-page" data-nome="' + esc(nome) + '" title="Ver lista dos pedidos recusados (valor vermelho acima)"><i class="fas fa-times"></i></button>' +
                    '<button type="button" class="prescritores-btn btn-componentes-aprovados-prescritor-page" data-nome="' + esc(nome) + '" title="Ver lista de componentes aprovados"><i class="fas fa-atom" style="color:var(--success);"></i></button>' +
                    '<button type="button" class="prescritores-btn btn-componentes-recusados-prescritor-page" data-nome="' + esc(nome) + '" title="Ver lista de componentes recusados"><i class="fas fa-atom" style="color:var(--danger);"></i></button>' +
                    '<button type="button" class="prescritores-btn prescritores-btn-analises btn-analises-prescritor-page" data-nome="' + esc(nome) + '" title="Análises e evolução: vendas e componentes por mês"><i class="fas fa-chart-line"></i></button>' +
                    '</span></td></tr>';
            });
            tbody.innerHTML = html;
            if (pagEl) {
                var from = start + 1;
                var to = Math.min(start + __paginaPrescritoresPageSize, list.length);
                var pagHtml = '<span style="font-weight:500;">Mostrando ' + from + ' – ' + to + ' de ' + list.length + ' prescritores</span>';
                pagHtml += '<span class="prescritores-pag-btns">';
                pagHtml += '<button type="button" onclick="paginaPrescritoresGoTo(1)" ' + (__paginaPrescritoresPage <= 1 ? 'disabled' : '') + ' title="Primeira página"><i class="fas fa-angle-double-left"></i></button>';
                pagHtml += '<button type="button" onclick="paginaPrescritoresGoTo(__paginaPrescritoresPage - 1)" ' + (__paginaPrescritoresPage <= 1 ? 'disabled' : '') + ' title="Anterior"><i class="fas fa-angle-left"></i></button>';
                pagHtml += '<span style="padding:0 10px; font-weight:500;">Página ' + __paginaPrescritoresPage + ' de ' + totalPages + '</span>';
                pagHtml += '<button type="button" onclick="paginaPrescritoresGoTo(__paginaPrescritoresPage + 1)" ' + (__paginaPrescritoresPage >= totalPages ? 'disabled' : '') + ' title="Próxima"><i class="fas fa-angle-right"></i></button>';
                pagHtml += '<button type="button" onclick="paginaPrescritoresGoTo(' + totalPages + ')" ' + (__paginaPrescritoresPage >= totalPages ? 'disabled' : '') + ' title="Última página"><i class="fas fa-angle-double-right"></i></button>';
                pagHtml += '</span>';
                pagEl.innerHTML = pagHtml;
                pagEl.style.display = 'flex';
            }
            updatePaginaPrescritoresSortIcons();
            tbody.querySelectorAll('.btn-whatsapp-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openWhatsAppModal === 'function') openWhatsAppModal(nome, '');
                };
            });
            tbody.querySelectorAll('.btn-pedidos-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openModalPedidosVisitador === 'function') openModalPedidosVisitador(nome);
                };
            });
            tbody.querySelectorAll('.btn-editar-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openEditarPrescritorModal === 'function') openEditarPrescritorModal(nome);
                };
            });
            tbody.querySelectorAll('.btn-aprovados-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openModalPedidosVisitador === 'function') openModalPedidosVisitador(nome, 'aprovados');
                };
            });
            tbody.querySelectorAll('.btn-recusados-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openModalPedidosVisitador === 'function') openModalPedidosVisitador(nome, 'recusados');
                };
            });
            tbody.querySelectorAll('.btn-componentes-aprovados-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openModalComponentesPrescritor === 'function') openModalComponentesPrescritor(nome, 'aprovados');
                };
            });
            tbody.querySelectorAll('.btn-componentes-recusados-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openModalComponentesPrescritor === 'function') openModalComponentesPrescritor(nome, 'recusados');
                };
            });
            tbody.querySelectorAll('.btn-relatorio-visitas-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openRelatorioVisitasPrescritorModal === 'function') openRelatorioVisitasPrescritorModal(nome);
                };
            });
            tbody.querySelectorAll('.btn-analises-prescritor-page').forEach(function (btn) {
                btn.onclick = function () {
                    var nome = btn.getAttribute('data-nome');
                    if (typeof openModalAnalisesPrescritor === 'function') openModalAnalisesPrescritor(nome);
                };
            });
            if (hadFocus && searchInput) {
                searchInput.focus();
                try { searchInput.setSelectionRange(savedSelStart, savedSelEnd); } catch (e) { }
            }
        }

        function paginaPrescritoresGoTo(page) {
            __paginaPrescritoresPage = Math.max(1, parseInt(page, 10) || 1);
            renderPaginaPrescritores();
        }

        var __paginaPedidosList = [];
        var __paginaPedidosPage = 1;
        var __paginaPedidosPageSize = 20;
        var __paginaPedidosSort = { column: 'data_aprovacao', direction: 'desc' };

        async function loadPaginaPedidos() {
            var tbody = document.getElementById('paginaPedidosTbody');
            if (!tbody) return;
            var hoje = new Date();
            var primeiroDiaMes = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
            var fmt = function (d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
            var dataDeEl = document.getElementById('paginaPedidosDataDe');
            var dataAteEl = document.getElementById('paginaPedidosDataAte');
            var filtroStatusEl = document.getElementById('paginaPedidosFiltroStatus');
            if (dataDeEl) dataDeEl.value = fmt(primeiroDiaMes);
            if (dataAteEl) dataAteEl.value = fmt(hoje);
            if (filtroStatusEl) filtroStatusEl.value = 'Aprovado';
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:48px 24px; color:var(--text-secondary); font-size:0.9rem;"><i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Carregando...</td></tr>';
            var ano = String(new Date().getFullYear());
            var mes = '';
            // Sempre usar o visitador logado: se for admin visualizando outro, usa currentVisitadorName; senão usa nome da sessão/localStorage
            var nome = (typeof currentVisitadorName !== 'undefined' ? currentVisitadorName : '') || (localStorage.getItem('userName') || '');
            if (!nome) nome = (localStorage.getItem('userName') || '').trim();
            try {
                var params = { nome: nome, ano: ano };
                if (mes) params.mes = mes;
                var res = await apiGet('list_pedidos_visitador', params);
                var aprovados = Array.isArray(res.aprovados) ? res.aprovados : [];
                var recusados = Array.isArray(res.recusados_carrinho) ? res.recusados_carrinho : [];
                __paginaPedidosList = aprovados.map(function (p) { return { ...p, tipo: 'Aprovado' }; }).concat(recusados.map(function (p) { return { ...p, tipo: 'Recusado' }; }));
                __paginaPedidosPage = 1;
                renderPaginaPedidos();
                var searchEl = document.getElementById('searchPedidosPage');
                if (searchEl) searchEl.oninput = function () { __paginaPedidosPage = 1; renderPaginaPedidos(); };
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:48px 24px; color:var(--danger); font-size:0.9rem;"><i class="fas fa-exclamation-circle" style="margin-right:8px;"></i>Erro ao carregar pedidos.</td></tr>';
            }
            var dataDeEl = document.getElementById('paginaPedidosDataDe');
            var dataAteEl = document.getElementById('paginaPedidosDataAte');
            var filtroStatusEl = document.getElementById('paginaPedidosFiltroStatus');
            if (dataDeEl) dataDeEl.onchange = function () { __paginaPedidosPage = 1; renderPaginaPedidos(); };
            if (dataAteEl) dataAteEl.onchange = function () { __paginaPedidosPage = 1; renderPaginaPedidos(); };
            if (filtroStatusEl) filtroStatusEl.onchange = function () { __paginaPedidosPage = 1; renderPaginaPedidos(); };
        }

        function sortPaginaPedidosBy(col) {
            if (__paginaPedidosSort.column === col) __paginaPedidosSort.direction = __paginaPedidosSort.direction === 'asc' ? 'desc' : 'asc';
            else { __paginaPedidosSort.column = col; __paginaPedidosSort.direction = 'desc'; }
            __paginaPedidosPage = 1;
            renderPaginaPedidos();
        }

        function sortPaginaPedidosList(list, col, dir) {
            var d = dir === 'asc' ? 1 : -1;
            return list.slice().sort(function (a, b) {
                var va = a[col], vb = b[col];
                if (col === 'data_aprovacao' || col === 'data_orcamento') { va = (va || '').replace(/-/g, ''); vb = (vb || '').replace(/-/g, ''); return (va === vb ? 0 : (va < vb ? -1 : 1)) * d; }
                if (col === 'valor') { va = parseFloat(va) || 0; vb = parseFloat(vb) || 0; return (va - vb) * d; }
                if (col === 'numero_pedido' || col === 'serie_pedido') { va = Number(va) || 0; vb = Number(vb) || 0; return (va - vb) * d; }
                va = String(va || '').toLowerCase(); vb = String(vb || '').toLowerCase();
                return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
            });
        }

        function renderPaginaPedidos() {
            var tbody = document.getElementById('paginaPedidosTbody');
            var pagEl = document.getElementById('paginaPedidosPagination');
            if (!tbody) return;
            var searchInput = document.getElementById('searchPedidosPage');
            var hadFocus = searchInput && document.activeElement === searchInput;
            var savedSelStart = searchInput ? searchInput.selectionStart : 0;
            var savedSelEnd = searchInput ? searchInput.selectionEnd : 0;
            var q = (searchInput || {}).value || '';
            var dataDe = (document.getElementById('paginaPedidosDataDe') || {}).value || '';
            var dataAte = (document.getElementById('paginaPedidosDataAte') || {}).value || '';
            var filtroStatus = (document.getElementById('paginaPedidosFiltroStatus') || {}).value || '';
            var list = __paginaPedidosList;
            if (filtroStatus) {
                list = list.filter(function (p) { return (p.tipo || '') === filtroStatus; });
            }
            if (q) {
                q = q.toLowerCase().trim();
                list = list.filter(function (p) {
                    return (p.prescritor || '').toLowerCase().indexOf(q) !== -1 || (p.cliente || '').toLowerCase().indexOf(q) !== -1 || String(p.numero_pedido || '').indexOf(q) !== -1;
                });
            }
            if (dataDe || dataAte) {
                list = list.filter(function (p) {
                    var d = (p.data_aprovacao || p.data_orcamento || '').trim().slice(0, 10);
                    if (!d) return false;
                    if (dataDe && d < dataDe) return false;
                    if (dataAte && d > dataAte) return false;
                    return true;
                });
            }
            list = sortPaginaPedidosList(list, __paginaPedidosSort.column, __paginaPedidosSort.direction);
            var totalValor = list.reduce(function (acc, p) { return acc + (parseFloat(p.valor) || 0); }, 0);
            var cardValor = document.getElementById('pedidosCardValor');
            var cardQty = document.getElementById('pedidosCardQuantidade');
            var fmtBr = function (v) {
                var n = (parseFloat(v) || 0).toFixed(2);
                var p = n.split('.');
                return 'R$ ' + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + p[1];
            };
            if (cardValor) cardValor.textContent = fmtBr(totalValor);
            if (cardQty) cardQty.textContent = list.length + ' pedido' + (list.length !== 1 ? 's' : '');
            var esc = function (x) { return String(x || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); };
            function fmtDate(d) {
                if (!d) return '—';
                var s = String(d).trim();
                if (s.length >= 10) return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
                return s;
            }
            function fmtMoney(v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); }
            function serieStr(p) {
                var v = p.serie_pedido;
                if (v === null || v === undefined) return '0';
                if (v === '') return '0';
                if (typeof v === 'string' && v.trim() === '') return '0';
                return String(v);
            }
            var ano = String(new Date().getFullYear());
            if (list.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:48px 24px; color:var(--text-secondary); font-size:0.9rem;"><i class="fas fa-inbox" style="margin-right:8px; opacity:0.6;"></i>Nenhum pedido encontrado.</td></tr>';
                if (pagEl) pagEl.innerHTML = '';
                updatePaginaPedidosSortIcons();
                if (hadFocus && searchInput) {
                    searchInput.focus();
                    try { searchInput.setSelectionRange(savedSelStart, savedSelEnd); } catch (e) { }
                }
                return;
            }
            var totalPages = Math.max(1, Math.ceil(list.length / __paginaPedidosPageSize));
            __paginaPedidosPage = Math.min(Math.max(1, __paginaPedidosPage), totalPages);
            var start = (__paginaPedidosPage - 1) * __paginaPedidosPageSize;
            var pageList = list.slice(start, start + __paginaPedidosPageSize);
            var html = '';
            pageList.forEach(function (p) {
                var isAprovado = p.tipo === 'Aprovado';
                var n = p.numero_pedido, s = p.serie_pedido;
                html += '<tr role="button" tabindex="0" onclick="openModalDetalhePedido(' + n + ',' + (s === null || s === undefined || s === '' ? 0 : s) + ',\'' + String(ano || '').replace(/'/g, "\\'") + '\')" style="cursor:pointer;" title="Ver detalhes e componentes">';
                html += '<td>' + esc(p.numero_pedido) + '</td>';
                html += '<td>' + esc(serieStr(p)) + '</td>';
                html += '<td>' + esc(fmtDate(p.data_aprovacao)) + '</td>';
                html += '<td>' + esc(p.data_orcamento ? fmtDate(p.data_orcamento) : '—') + '</td>';
                html += '<td>' + esc(p.prescritor) + '</td>';
                html += '<td>' + esc(p.cliente) + '</td>';
                html += '<td style="text-align:right; font-weight:600; color:' + (isAprovado ? 'var(--success)' : 'var(--danger)') + ';">' + esc(fmtMoney(p.valor)) + '</td>';
                html += '<td>' + esc(p.tipo) + '</td></tr>';
            });
            tbody.innerHTML = html;
            if (pagEl) {
                var from = start + 1;
                var to = Math.min(start + __paginaPedidosPageSize, list.length);
                var pagHtml = '<span style="font-weight:500;">Mostrando ' + from + ' – ' + to + ' de ' + list.length + ' pedidos</span>';
                pagHtml += '<span class="prescritores-pag-btns">';
                pagHtml += '<button type="button" onclick="paginaPedidosGoTo(1)" ' + (__paginaPedidosPage <= 1 ? 'disabled' : '') + ' title="Primeira página"><i class="fas fa-angle-double-left"></i></button>';
                pagHtml += '<button type="button" onclick="paginaPedidosGoTo(__paginaPedidosPage - 1)" ' + (__paginaPedidosPage <= 1 ? 'disabled' : '') + ' title="Anterior"><i class="fas fa-angle-left"></i></button>';
                pagHtml += '<span style="padding:0 10px; font-weight:500;">Página ' + __paginaPedidosPage + ' de ' + totalPages + '</span>';
                pagHtml += '<button type="button" onclick="paginaPedidosGoTo(__paginaPedidosPage + 1)" ' + (__paginaPedidosPage >= totalPages ? 'disabled' : '') + ' title="Próxima"><i class="fas fa-angle-right"></i></button>';
                pagHtml += '<button type="button" onclick="paginaPedidosGoTo(' + totalPages + ')" ' + (__paginaPedidosPage >= totalPages ? 'disabled' : '') + ' title="Última página"><i class="fas fa-angle-double-right"></i></button>';
                pagHtml += '</span>';
                pagEl.innerHTML = pagHtml;
                pagEl.style.display = 'flex';
            }
            if (hadFocus && searchInput) {
                searchInput.focus();
                try { searchInput.setSelectionRange(savedSelStart, savedSelEnd); } catch (e) { }
            }
            updatePaginaPedidosSortIcons();
        }

        function updatePaginaPedidosSortIcons() {
            var col = __paginaPedidosSort.column;
            var dir = __paginaPedidosSort.direction;
            var map = { numero_pedido: 'pedidosThNumero', serie_pedido: 'pedidosThSerie', data_aprovacao: 'pedidosThDataAprovacao', data_orcamento: 'pedidosThDataOrcamento', prescritor: 'pedidosThPrescritor', cliente: 'pedidosThCliente', valor: 'pedidosThValor', tipo: 'pedidosThStatus' };
            for (var key in map) {
                var el = document.getElementById(map[key]);
                if (!el) continue;
                var icon = el.querySelector('i');
                if (!icon) continue;
                icon.className = key === col ? (dir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down') : 'fas fa-sort';
            }
        }

        function paginaPedidosGoTo(page) {
            __paginaPedidosPage = Math.max(1, parseInt(page, 10) || 1);
            renderPaginaPedidos();
        }

        async function loadAndRenderRelatorioPrescritores() {
            const tbody = document.getElementById('relatorioPrescritoresBody');
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:24px;"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';
            const anoEl = document.getElementById('anoSelect');
            const mesEl = document.getElementById('mesSelect');
            const ano = anoEl ? anoEl.value : (new Date().getFullYear());
            const mes = mesEl && mesEl.value ? mesEl.value : '';
            const params = { visitador: currentVisitadorName || '', ano };
            if (mes) params.mes = mes;
            let list = [];
            try {
                list = await apiGet('all_prescritores', params) || [];
            } catch (e) {
                console.error('Erro ao carregar prescritores para relatório', e);
            }
            const normalized = list.map(p => ({
                ...p,
                total_aprovado: p.valor_aprovado ?? p.total_aprovado ?? 0,
                total_recusado: p.valor_recusado ?? p.total_recusado ?? 0,
                qtd_recusados: p.qtd_recusados ?? 0,
                qtd_aprovados: p.total_pedidos ?? p.qtd_aprovados ?? 0
            }));
            relatorioPrescritoresFiltered = normalized;
            renderRelatorioPrescritoresTable(relatorioPrescritoresFiltered);
        }

        function filterRelatorioPrescritores() {
            const q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
            const term = q.toLowerCase().trim();
            relatorioCurrentPage = 1;
            if (!term) {
                renderRelatorioPrescritoresTable(relatorioPrescritoresFiltered);
                return;
            }
            const filtered = relatorioPrescritoresFiltered.filter(p => (p.prescritor || '').toLowerCase().includes(term));
            renderRelatorioPrescritoresTable(filtered);
        }

        function relatorioChangePage(delta) {
            relatorioCurrentPage += delta;
            const q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
            const term = q.toLowerCase().trim();
            const data = term ? relatorioPrescritoresFiltered.filter(p => (p.prescritor || '').toLowerCase().includes(term)) : relatorioPrescritoresFiltered;
            renderRelatorioPrescritoresTable(data);
        }

        function relatorioDiasBadge(days, emptyText) {
            if (days === null || isNaN(days) || days === 999999) {
                return '<div class="days-badge danger"><div class="circle"><i class="fas fa-exclamation"></i></div> ' + emptyText + '</div>';
            }
            let type = 'success';
            let icon = '<i class="fas fa-check"></i>';
            let label = ' ' + days + 'd';
            if (days > 15) {
                type = 'danger';
                icon = '<i class="fas fa-exclamation"></i>';
            } else if (days > 7) {
                type = 'warning';
                icon = '<i class="fas fa-exclamation-triangle"></i>';
            }
            return '<div class="days-badge ' + type + '"><div class="circle">' + icon + '</div>' + label + '</div>';
        }

        function renderRelatorioPrescritoresTable(data) {
            const tbody = document.getElementById('relatorioPrescritoresBody');
            const pagInfo = document.getElementById('relatorioPaginationInfo');
            const prevBtn = document.getElementById('relatorioPrevPage');
            const nextBtn = document.getElementById('relatorioNextPage');
            const subtitle = document.getElementById('relatorioPrescritorSubtitle');
            if (!tbody) return;
            if (subtitle) subtitle.textContent = currentVisitadorName ? 'Carteira: ' + currentVisitadorName + ' — ' + data.length + ' prescritor(es)' : data.length + ' prescritor(es)';
            if (!Array.isArray(data) || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:40px; color:var(--text-secondary);"><i class="fas fa-search" style="font-size:1.5rem; margin-bottom:8px; display:block; opacity:0.3;"></i>Nenhum prescritor encontrado</td></tr>';
                if (pagInfo) pagInfo.textContent = 'Mostrando 0-0 de 0';
                if (prevBtn) prevBtn.disabled = true;
                if (nextBtn) nextBtn.disabled = true;
                return;
            }
            const totalPages = Math.ceil(data.length / relatorioItemsPerPage);
            if (relatorioCurrentPage < 1) relatorioCurrentPage = 1;
            if (relatorioCurrentPage > totalPages) relatorioCurrentPage = totalPages;
            const startIdx = (relatorioCurrentPage - 1) * relatorioItemsPerPage;
            const endIdx = Math.min(startIdx + relatorioItemsPerPage, data.length);
            const pageData = data.slice(startIdx, endIdx);

            if (pagInfo) pagInfo.textContent = 'Mostrando ' + (startIdx + 1) + '-' + endIdx + ' de ' + data.length;
            if (prevBtn) { prevBtn.disabled = relatorioCurrentPage === 1; prevBtn.style.opacity = relatorioCurrentPage === 1 ? '0.5' : '1'; }
            if (nextBtn) { nextBtn.disabled = relatorioCurrentPage >= totalPages; nextBtn.style.opacity = relatorioCurrentPage >= totalPages ? '0.5' : '1'; }

            tbody.innerHTML = pageData.map((p, i) => {
                const globalIndex = startIdx + i + 1;
                const diasCompra = p.dias_sem_compra !== null ? parseInt(p.dias_sem_compra) : null;
                const diasCompraHTML = relatorioDiasBadge(diasCompra, 'Sem compras');
                let diasVisitaHTML = relatorioDiasBadge(null, 'Sem visita');
                if (p.ultima_visita) {
                    const dv = new Date(p.ultima_visita);
                    if (!isNaN(dv.getTime())) {
                        const diasVisita = Math.floor((new Date() - dv) / (1000 * 60 * 60 * 24));
                        diasVisitaHTML = relatorioDiasBadge(diasVisita, 'Sem visita');
                    }
                }
                const valorAprovado = typeof formatMoney === 'function' ? formatMoney(p.total_aprovado || 0) : (p.total_aprovado || 0);
                const valorRecusado = typeof formatMoney === 'function' ? formatMoney(p.total_recusado ?? p.valor_recusado ?? 0) : (p.total_recusado ?? p.valor_recusado ?? 0);
                const qtdRec = p.qtd_recusados ?? 0;
                return '<tr style="border-bottom:1px solid var(--border); transition:background 0.15s;" onmouseover="this.style.background=\'rgba(37,99,235,0.03)\'" onmouseout="this.style.background=\'transparent\'">' +
                    '<td style="padding:10px 16px; color:var(--text-secondary); font-weight:600;">' + globalIndex + '</td>' +
                    '<td style="padding:10px 16px; font-weight:500; color:var(--text-primary);">' + (p.prescritor || '').trim() + '</td>' +
                    '<td class="val-approved" style="padding:10px 16px; text-align:right;">' + valorAprovado + '<br><small style="opacity:0.9;">' + (p.qtd_aprovados || 0) + ' ped.</small></td>' +
                    '<td class="val-refused" style="padding:10px 16px; text-align:right; color:var(--danger, #EF4444);">' + valorRecusado + '<br><small style="opacity:0.9;">' + qtdRec + ' ped.</small></td>' +
                    '<td style="padding:10px 16px; text-align:center;">' + diasCompraHTML + '</td>' +
                    '<td style="padding:10px 16px; text-align:center;">' + diasVisitaHTML + '</td>' +
                    '<td style="padding:10px 16px; text-align:center;">' + renderVisitaActionCell(p.prescritor) + '</td>' +
                    '</tr>';
            }).join('');
        }

        // ========== ROTA DO DIA (Iniciar / Pausar / Finalizar + GPS) ==========
        let rotaState = 'idle'; // idle | em_andamento | pausada
        let rotaWatchId = null;
        const ROTA_PONTO_INTERVAL_MS = 15000;

        function updateRotaButton() {
            const btn = document.getElementById('btnIniciarRota');
            const label = document.getElementById('labelIniciarRota');
            const icon = document.getElementById('iconIniciarRota');
            const btnFinalizar = document.getElementById('btnFinalizarRota');
            if (!btn || !label) return;
            if (!canManageRota) {
                btn.disabled = true;
                btn.style.opacity = '0.65';
                btn.style.cursor = 'not-allowed';
                btn.title = 'Somente perfil visitador pode iniciar/pausar/finalizar rota.';
                label.textContent = 'Rota bloqueada';
                if (icon) { icon.className = 'fas fa-ban'; }
                if (btnFinalizar) btnFinalizar.style.display = 'none';
                return;
            }
            btn.disabled = false;
            btn.style.opacity = '';
            btn.style.cursor = 'pointer';
            if (rotaState === 'em_andamento') {
                btn.title = 'Pausar a rota (ex.: almoço). O GPS deixa de registrar até retomar.';
                label.textContent = 'Pausar Rota';
                if (icon) { icon.className = 'fas fa-pause'; }
                btn.style.background = '#B45309';
                if (btnFinalizar) btnFinalizar.style.display = 'inline-flex';
            } else if (rotaState === 'pausada') {
                btn.title = 'Retomar a rota para continuar registrando o percurso.';
                label.textContent = 'Retomar Rota';
                if (icon) { icon.className = 'fas fa-play'; }
                btn.style.background = '#059669';
                if (btnFinalizar) { btnFinalizar.style.display = 'inline-flex'; }
            } else {
                btn.title = 'Iniciar rota do dia: ativa GPS e registra o percurso. Pause para almoço e finalize ao fechar o dia.';
                label.textContent = 'Iniciar Rota';
                if (icon) { icon.className = 'fas fa-route'; }
                btn.style.background = '#DC2626';
                if (btnFinalizar) btnFinalizar.style.display = 'none';
            }
        }

        function stopRotaWatch() {
            if (rotaWatchId != null && navigator.geolocation) {
                navigator.geolocation.clearWatch(rotaWatchId);
                rotaWatchId = null;
            }
        }

        function sendRotaPonto(lat, lng) {
            if (!canManageRota) return;
            if (!currentVisitadorName) return;
            if (typeof apiPost !== 'function') return;
            apiPost('save_rota_ponto', { visitador_nome: currentVisitadorName, lat, lng }).catch(() => {});
        }

        function startRotaWatch() {
            if (!canManageRota) return;
            if (!navigator.geolocation || rotaState !== 'em_andamento') return;
            let lastSent = 0;
            rotaWatchId = navigator.geolocation.watchPosition(
                function (pos) {
                    if (rotaState !== 'em_andamento') return;
                    const now = Date.now();
                    if (now - lastSent >= ROTA_PONTO_INTERVAL_MS) {
                        lastSent = now;
                        sendRotaPonto(pos.coords.latitude, pos.coords.longitude);
                    }
                },
                function () { },
                { enableHighAccuracy: true, maximumAge: 10000 }
            );
        }

        async function toggleRota() {
            if (!canManageRota) {
                alert('Somente perfil visitador pode gerenciar rota.');
                return;
            }
            if (!currentVisitadorName) return;
            try {
                if (rotaState === 'idle') {
                    var res = await apiPost('start_rota', { visitador_nome: currentVisitadorName });
                    // Se já existe rota finalizada hoje, perguntar se quer reabrir
                    if (res && res.rota_existente) {
                        if (confirm('Você já finalizou uma rota hoje. Deseja reabrir a rota existente ao invés de criar uma nova?')) {
                            res = await apiPost('start_rota', { visitador_nome: currentVisitadorName, reabrir: true });
                        } else {
                            return;
                        }
                    }
                    if (res && res.success) {
                        rotaState = 'em_andamento';
                        updateRotaButton();
                        startRotaWatch();
                        renderModalPrescritores(filteredPrescritores);
                        if (typeof loadVisitadorDashboard === 'function') {
                            var anoEl = document.getElementById('anoSelect');
                            var mesEl = document.getElementById('mesSelect');
                            await loadVisitadorDashboard(currentVisitadorName, anoEl ? anoEl.value : null, mesEl && mesEl.value ? mesEl.value : null);
                        }
                        var relEl = document.getElementById('paginaRelatorioVisitas');
                        if (relEl && relEl.style.display !== 'none') {
                            if (typeof copyDashboardToRelatorio === 'function') copyDashboardToRelatorio();
                            var q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
                            var term = q.toLowerCase().trim();
                            var data = term ? relatorioPrescritoresFiltered.filter(function(p) { return (p.prescritor || '').toLowerCase().includes(term); }) : relatorioPrescritoresFiltered;
                            renderRelatorioPrescritoresTable(data);
                        }
                    } else {
                        alert(res && res.error ? res.error : 'Não foi possível iniciar a rota.');
                    }
                } else if (rotaState === 'em_andamento') {
                    const res = await apiPost('pause_rota', { visitador_nome: currentVisitadorName });
                    if (res && res.success) {
                        stopRotaWatch();
                        rotaState = 'pausada';
                        updateRotaButton();
                        renderModalPrescritores(filteredPrescritores);
                        var relEl = document.getElementById('paginaRelatorioVisitas');
                        if (relEl && relEl.style.display !== 'none') {
                            var q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
                            var term = q.toLowerCase().trim();
                            var data = term ? relatorioPrescritoresFiltered.filter(function(p) { return (p.prescritor || '').toLowerCase().includes(term); }) : relatorioPrescritoresFiltered;
                            renderRelatorioPrescritoresTable(data);
                        }
                    }
                } else if (rotaState === 'pausada') {
                    const res = await apiPost('resume_rota', { visitador_nome: currentVisitadorName });
                    if (res && res.success) {
                        rotaState = 'em_andamento';
                        updateRotaButton();
                        startRotaWatch();
                        renderModalPrescritores(filteredPrescritores);
                        if (typeof loadVisitadorDashboard === 'function') {
                            var anoEl = document.getElementById('anoSelect');
                            var mesEl = document.getElementById('mesSelect');
                            await loadVisitadorDashboard(currentVisitadorName, anoEl ? anoEl.value : null, mesEl && mesEl.value ? mesEl.value : null);
                        }
                        var relEl = document.getElementById('paginaRelatorioVisitas');
                        if (relEl && relEl.style.display !== 'none') {
                            if (typeof copyDashboardToRelatorio === 'function') copyDashboardToRelatorio();
                            var q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
                            var term = q.toLowerCase().trim();
                            var data = term ? relatorioPrescritoresFiltered.filter(function(p) { return (p.prescritor || '').toLowerCase().includes(term); }) : relatorioPrescritoresFiltered;
                            renderRelatorioPrescritoresTable(data);
                        }
                    }
                }
            } catch (e) {
                console.error('Rota', e);
                alert('Erro de conexão.');
            }
        }

        async function finishRotaFromUI() {
            if (!canManageRota) {
                alert('Somente perfil visitador pode finalizar rota.');
                return;
            }
            if (!currentVisitadorName || (rotaState !== 'em_andamento' && rotaState !== 'pausada')) return;
            try {
                const av = await apiGet('visita_ativa', { visitador_nome: currentVisitadorName });
                if (av && av.active) {
                    alert('Não é possível finalizar a rota com uma visita em andamento. Encerre a visita ao prescritor "' + (av.active.prescritor || '') + '" antes de finalizar a rota.');
                    return;
                }
            } catch (e) { /* ignora erro de rede, backend também valida */ }
            if (!confirm('Finalizar a rota do dia? O percurso registrado será mantido.')) return;
            try {
                const res = await apiPost('finish_rota', { visitador_nome: currentVisitadorName });
                if (res && res.success) {
                    stopRotaWatch();
                    rotaState = 'idle';
                    updateRotaButton();
                    renderModalPrescritores(filteredPrescritores);
                    var relEl = document.getElementById('paginaRelatorioVisitas');
                    if (relEl && relEl.style.display !== 'none') {
                        var q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
                        var term = q.toLowerCase().trim();
                        var data = term ? relatorioPrescritoresFiltered.filter(function(p) { return (p.prescritor || '').toLowerCase().includes(term); }) : relatorioPrescritoresFiltered;
                        renderRelatorioPrescritoresTable(data);
                    }
                } else if (res && res.error) {
                    alert(res.error);
                }
            } catch (e) {
                console.error('Finalizar rota', e);
            }
        }

        async function syncRotaState() {
            if (!canManageRota) {
                stopRotaWatch();
                rotaState = 'idle';
                updateRotaButton();
                return;
            }
            try {
                const r = await apiGet('rota_ativa', { visitador_nome: currentVisitadorName });
                const active = r && r.active ? r.active : null;
                if (active) {
                    rotaState = (active.status === 'pausada') ? 'pausada' : 'em_andamento';
                    if (rotaState === 'em_andamento') startRotaWatch();
                } else {
                    rotaState = 'idle';
                    stopRotaWatch();
                }
                updateRotaButton();
                renderModalPrescritores(filteredPrescritores);
                var relEl = document.getElementById('paginaRelatorioVisitas');
                if (relEl && relEl.style.display !== 'none') {
                    var q = (document.getElementById('searchRelatorioPrescritor') || {}).value || '';
                    var term = q.toLowerCase().trim();
                    var data = term ? relatorioPrescritoresFiltered.filter(function(p) { return (p.prescritor || '').toLowerCase().includes(term); }) : relatorioPrescritoresFiltered;
                    renderRelatorioPrescritoresTable(data);
                }
            } catch (e) {
                rotaState = 'idle';
                updateRotaButton();
            }
        }

        async function doLogout() {
            localStorage.clear();
            window.location.href = 'index.html';
        }

        document.addEventListener('DOMContentLoaded', async () => {
            if (!localStorage.getItem('loggedIn')) {
                window.location.href = 'index.html';
                return;
            }
            // Garantir token CSRF antes de qualquer POST (evita 403 em save_rota_ponto, start_rota, etc.)
            if (typeof fetchCsrfToken === 'function') await fetchCsrfToken();

            const userName = localStorage.getItem('userName');
            const userType = localStorage.getItem('userType') || '';
            const userSetor = (localStorage.getItem('userSetor') || '').toLowerCase();
            const params = new URLSearchParams(window.location.search);
            const viewVisitador = params.get('visitador');

            let visitadorToLoad = userName;
            if (viewVisitador && viewVisitador.trim() !== '') {
                if (userType !== 'admin') {
                    window.location.href = 'index.html';
                    return;
                }
                visitadorToLoad = viewVisitador.trim();
                document.getElementById('visName').textContent = visitadorToLoad;
                document.getElementById('visSubtitle').textContent = 'Visualizando como admin';
                document.getElementById('visSubtitle').style.color = 'var(--primary)';
            } else {
                document.getElementById('visName').textContent = userName;
                document.getElementById('visSubtitle').textContent = 'Minha Carteira';
            }

            var btnVoltarInicioAdmin = document.getElementById('btnVoltarInicioAdmin');
            if (btnVoltarInicioAdmin) {
                btnVoltarInicioAdmin.style.display = (viewVisitador && userType === 'admin') ? 'inline-flex' : 'none';
            }

            applyAvatarInHeader((visitadorToLoad || 'V').charAt(0).toUpperCase(), localStorage.getItem('foto_perfil') || null);
            document.getElementById('greeting').textContent = visitadorToLoad ? `Painel: ${visitadorToLoad.split(' ')[0]}!` : `Olá, ${(userName || '').split(' ')[0]}!`;

            currentVisitadorName = visitadorToLoad || '';
            // Visitador logado pode gerenciar suas visitas; admin também pode ao visualizar outro visitador
            canManageVisits = (userSetor === 'visitador') || (userType === 'admin');
            // Rota e GPS são exclusivos do visitador (nunca para admin visualizando carteira)
            canManageRota = (userSetor === 'visitador') && (userType !== 'admin') && !viewVisitador;
            if (!viewVisitador && typeof apiGet === 'function') {
                try {
                    var session = await apiGet('check_session');
                    if (session && session.foto_perfil) {
                        localStorage.setItem('foto_perfil', session.foto_perfil);
                        applyAvatarInHeader((visitadorToLoad || 'V').charAt(0).toUpperCase(), session.foto_perfil);
                    }
                } catch (e) {}
            }
            setupAvatarUpload(!viewVisitador);
            loadVisitadorDashboard(visitadorToLoad);
            await syncRotaState();
        });

        function applyAvatarInHeader(initial, fotoUrl) {
            var av = document.getElementById('userAvatar');
            var img = document.getElementById('userAvatarImg');
            if (!av || !img) return;
            if (fotoUrl) {
                var url = (typeof API_URL !== 'undefined' ? API_URL : 'api.php') + '?action=get_foto_perfil&t=' + Date.now();
                img.src = url;
                img.alt = 'Foto de perfil';
                img.setAttribute('width', '42');
                img.setAttribute('height', '42');
                img.style.display = 'block';
                img.style.width = '42px';
                img.style.height = '42px';
                img.style.maxWidth = '42px';
                img.style.maxHeight = '42px';
                av.style.display = 'none';
                img.onerror = function () {
                    img.style.display = 'none';
                    img.removeAttribute('width');
                    img.removeAttribute('height');
                    img.style.width = img.style.height = img.style.maxWidth = img.style.maxHeight = '';
                    av.style.display = 'flex';
                    av.textContent = (initial || 'V').charAt(0).toUpperCase();
                    img.src = '';
                    localStorage.removeItem('foto_perfil');
                };
            } else {
                img.src = '';
                img.onerror = null;
                img.style.display = 'none';
                img.removeAttribute('width');
                img.removeAttribute('height');
                img.style.width = img.style.height = img.style.maxWidth = img.style.maxHeight = '';
                av.style.display = 'flex';
                av.textContent = (initial || 'V').charAt(0).toUpperCase();
            }
        }
        function setupAvatarUpload(enable) {
            var wrap = document.getElementById('avatarWrap');
            var input = document.getElementById('inputFotoPerfil');
            var profileArea = document.querySelector('.user-profile');
            if (!wrap || !input) return;
            wrap.style.cursor = enable ? 'pointer' : 'default';
            wrap.onclick = enable ? openMeuPerfilModal : null;
            if (profileArea) profileArea.style.cursor = enable ? 'pointer' : 'default';
            if (profileArea) profileArea.onclick = enable ? openMeuPerfilModal : null;
            input.onchange = function () { };
        }

        async function openMeuPerfilModal() {
            var modal = document.getElementById('modalMeuPerfil');
            if (!modal) return;
            modal.style.display = 'flex';
            document.getElementById('perfilSenhaAtual').value = '';
            document.getElementById('perfilSenhaNova').value = '';
            document.getElementById('perfilSenhaConfirma').value = '';
            try {
                var r = await apiGet('get_meu_perfil');
                if (r && r.success) {
                    document.getElementById('perfilNome').value = r.nome || '';
                    document.getElementById('perfilUsuario').value = r.usuario || '';
                    document.getElementById('perfilWhatsapp').value = r.whatsapp || '';
                    var initial = (r.nome || 'V').charAt(0).toUpperCase();
                    var img = document.getElementById('perfilFotoImg');
                    var av = document.getElementById('perfilAvatarInicial');
                    if (r.foto_perfil) {
                        img.onerror = function () {
                            img.style.display = 'none';
                            av.style.display = '';
                            av.textContent = initial;
                        };
                        img.src = (typeof API_URL !== 'undefined' ? API_URL : 'api.php') + '?action=get_foto_perfil&t=' + Date.now();
                        img.style.display = '';
                        av.style.display = 'none';
                    } else {
                        img.onerror = null;
                        img.src = '';
                        img.style.display = 'none';
                        av.style.display = '';
                        av.textContent = initial;
                    }
                }
            } catch (e) {
                console.error('Perfil', e);
            }
            document.getElementById('inputFotoPerfilModal').onchange = function () {
                var file = this.files && this.files[0];
                if (!file || !file.type.match(/^image\//)) return;
                if (file.size > 3 * 1024 * 1024) {
                    alert('Escolha uma foto de até 3 MB.');
                    document.getElementById('inputFotoPerfilModal').value = '';
                    return;
                }
                var apiUrl = (typeof API_URL !== 'undefined' ? API_URL : 'api.php');
                var formData = new FormData();
                formData.append('foto', file);
                (async function () {
                    try {
                        var response = await fetch(apiUrl + '?action=upload_foto_perfil', {
                            method: 'POST',
                            body: formData,
                            credentials: 'include'
                        });
                        var text = await response.text();
                        var res = null;
                        try { res = text ? JSON.parse(text) : null; } catch (_) {}
                        if (res && res.success && res.foto_perfil) {
                            localStorage.setItem('foto_perfil', res.foto_perfil);
                            var nome = document.getElementById('perfilNome').value || 'V';
                            applyAvatarInHeader(nome.charAt(0), res.foto_perfil);
                            var img = document.getElementById('perfilFotoImg');
                            var av = document.getElementById('perfilAvatarInicial');
                            img.src = apiUrl + '?action=get_foto_perfil&t=' + Date.now();
                            img.style.display = 'block';
                            if (av) av.style.display = 'none';
                        } else {
                            var msg = (res && res.error) ? res.error : (!response.ok ? 'Erro do servidor (' + response.status + '). Verifique a pasta uploads/avatars no servidor.' : 'Não foi possível alterar a foto.');
                            alert(msg);
                        }
                    } catch (err) {
                        alert('Erro ao enviar a foto. Verifique a conexão.');
                    }
                    document.getElementById('inputFotoPerfilModal').value = '';
                })();
            };
        }

        function closeMeuPerfilModal() {
            var modal = document.getElementById('modalMeuPerfil');
            if (modal) modal.style.display = 'none';
        }

        document.getElementById('modalMeuPerfil').addEventListener('click', function (e) {
            if (e.target === this) closeMeuPerfilModal();
        });

        function isStrongPasswordClient(password) {
            if (!password || password.length < 8) return false;
            if (!/[A-Z]/.test(password)) return false;
            if (!/[^a-zA-Z0-9]/.test(password)) return false;
            return true;
        }

        async function salvarMeuPerfil() {
            var nome = (document.getElementById('perfilNome').value || '').trim();
            var whatsapp = (document.getElementById('perfilWhatsapp').value || '').trim();
            var senhaAtual = document.getElementById('perfilSenhaAtual').value;
            var senhaNova = document.getElementById('perfilSenhaNova').value;
            var senhaConfirma = document.getElementById('perfilSenhaConfirma').value;
            if (!nome) {
                alert('Nome é obrigatório.');
                return;
            }
            if (senhaNova !== '' && senhaNova !== senhaConfirma) {
                alert('A nova senha e a confirmação não conferem.');
                return;
            }
            if (senhaNova !== '' && !isStrongPasswordClient(senhaNova)) {
                alert('A nova senha deve ter no mínimo 8 caracteres, 1 letra maiúscula e 1 caractere especial.');
                return;
            }
            var btn = document.getElementById('btnSalvarPerfil');
            btn.disabled = true;
            btn.textContent = 'Salvando...';
            try {
                var payload = { nome: nome, whatsapp: whatsapp };
                if (senhaNova !== '') payload.senha_atual = senhaAtual, payload.senha_nova = senhaNova;
                var res = await apiPost('update_meu_perfil', payload);
                if (res && res.success) {
                    if (res.nome) {
                        document.getElementById('visName').textContent = res.nome;
                        localStorage.setItem('userName', res.nome);
                    }
                    closeMeuPerfilModal();
                    alert('Perfil atualizado.');
                } else {
                    alert(res && res.error ? res.error : 'Não foi possível salvar.');
                }
            } catch (e) {
                alert('Erro ao salvar. Tente novamente.');
            }
            btn.disabled = false;
            btn.textContent = 'Salvar';
        }