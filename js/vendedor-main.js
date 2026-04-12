(function () {
    const API_URL = 'api_gestao.php';
    const REFRESH_MS = 45000; // Painel alimentado por gestao_pedidos (importação)

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

    window.vdGoSection = function (sectionId, ev) {
        if (ev && typeof ev.preventDefault === 'function') ev.preventDefault();
        const target = document.getElementById(sectionId);
        if (!target) return false;
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return false;
    };

    function getThemeStorageKey() {
        const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
        return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
    }

    function loadSavedTheme() {
        const userKey = getThemeStorageKey();
        const saved = localStorage.getItem(userKey) || localStorage.getItem('mypharm_theme');
        const theme = saved === 'dark' || saved === 'light' ? saved : 'light';
        document.documentElement.setAttribute('data-theme', theme);
    }

    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme') || 'light';
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
            clearLocalStoragePreservingMyPharmTheme();
            window.location.href = 'index.html';
            return null;
        }
        const setor = String(session.setor || '').trim().toLowerCase();
        if (setor.indexOf('vendedor') === -1 && String(session.tipo || '').toLowerCase() !== 'admin') {
            clearLocalStoragePreservingMyPharmTheme();
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
    let forcedVendedoraView = '';
    let perdasSearchDebounce = null;
    const vendedorPedidosState = {
        list: [],
        page: 1,
        pageSize: 20,
        sort: { column: 'data_aprovacao', direction: 'desc' },
        expanded: {},
        lastGroupedFiltered: [],
        dataDe: '',
        dataAte: ''
    };

    function getForcedVendedoraFromUrl() {
        try {
            var qs = new URLSearchParams(window.location.search || '');
            var nome = String(qs.get('vendedora') || qs.get('vendedor') || '').trim();
            return nome;
        } catch (_) {
            return '';
        }
    }

    function getDashboardVendedoraName(session) {
        var sessionTipo = String((session && session.tipo) || '').trim().toLowerCase();
        if (sessionTipo === 'admin' && forcedVendedoraView) {
            return forcedVendedoraView;
        }
        return (session && session.nome) ? String(session.nome).trim() : (localStorage.getItem('userName') || 'Vendedor').trim();
    }

    function getAdminVendedoraApiParam(session) {
        var sessionTipo = String((session && session.tipo) || '').trim().toLowerCase();
        return (sessionTipo === 'admin' && forcedVendedoraView) ? forcedVendedoraView : '';
    }

    function sortVendedorPedidosList(list, col, dir) {
        const d = dir === 'asc' ? 1 : -1;
        return list.slice().sort(function (a, b) {
            let va = a[col], vb = b[col];
            if (col === 'data_aprovacao' || col === 'data_orcamento') {
                va = String(va || '').replace(/-/g, '');
                vb = String(vb || '').replace(/-/g, '');
                return (va === vb ? 0 : (va < vb ? -1 : 1)) * d;
            }
            if (col === 'valor') {
                va = Number(va || 0);
                vb = Number(vb || 0);
                return (va - vb) * d;
            }
            if (col === 'numero_pedido' || col === 'serie_pedido') {
                va = Number(va || 0);
                vb = Number(vb || 0);
                return (va - vb) * d;
            }
            va = String(va || '').toLowerCase();
            vb = String(vb || '').toLowerCase();
            return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
        });
    }

    function updateVendedorPedidosSortIcons() {
        const col = vendedorPedidosState.sort.column;
        const dir = vendedorPedidosState.sort.direction;
        const map = {
            numero_pedido: 'vdPedidosThNumero',
            serie_pedido: 'vdPedidosThSerie',
            data_aprovacao: 'vdPedidosThDataAprovacao',
            data_orcamento: 'vdPedidosThDataOrcamento',
            prescritor: 'vdPedidosThPrescritor',
            cliente: 'vdPedidosThCliente',
            valor: 'vdPedidosThValor',
            tipo: 'vdPedidosThStatus'
        };
        Object.keys(map).forEach(function (k) {
            const el = document.getElementById(map[k]);
            if (!el) return;
            const icon = el.querySelector('i');
            if (!icon) return;
            icon.className = k === col ? (dir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down') : 'fas fa-sort';
        });
    }

    function renderVendedorPedidos() {
        const tbody = document.getElementById('vdPedidosTbody');
        const pagEl = document.getElementById('vdPedidosPagination');
        if (!tbody) return;

        const qRaw = ((document.getElementById('vdSearchPedidos') || {}).value || '').trim().toLowerCase();
        const status = ((document.getElementById('vdPedidosFiltroStatus') || {}).value || '').trim();
        let list = Array.isArray(vendedorPedidosState.list) ? vendedorPedidosState.list.slice() : [];

        if (status) {
            list = list.filter(function (p) { return String(p.tipo || '') === status; });
        }
        if (qRaw) {
            list = list.filter(function (p) {
                return String(p.prescritor || '').toLowerCase().indexOf(qRaw) !== -1
                    || String(p.cliente || '').toLowerCase().indexOf(qRaw) !== -1
                    || String(p.numero_pedido || '').indexOf(qRaw) !== -1;
            });
        }
        list = sortVendedorPedidosList(list, vendedorPedidosState.sort.column, vendedorPedidosState.sort.direction);

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
            groupsMap[numero].valor_total += Number(p.valor || 0);
        });
        const groupedList = groupsOrder.map(function (numero) {
            const g = groupsMap[numero];
            const rows = g.rows || [];
            let dataAprov = '';
            let dataOrc = '';
            const tipos = {};
            const prescritores = {};
            const clientes = {};
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
            const tipoResumo = tiposKeys.length > 1 ? 'Misto' : (tiposKeys[0] || '—');
            const prescKeys = Object.keys(prescritores);
            const cliKeys = Object.keys(clientes);
            const prescResumo = prescKeys.length <= 1 ? (prescKeys[0] || '—') : (prescKeys[0] + ' +' + (prescKeys.length - 1));
            const cliResumo = cliKeys.length <= 1 ? (cliKeys[0] || '—') : (cliKeys[0] + ' +' + (cliKeys.length - 1));
            return {
                numero_pedido: numero,
                serie_pedido: rows.length,
                data_aprovacao: dataAprov,
                data_orcamento: dataOrc,
                prescritor: prescResumo,
                cliente: cliResumo,
                valor: g.valor_total,
                tipo: tipoResumo,
                rows: rows
            };
        });

        vendedorPedidosState.lastGroupedFiltered = groupedList.slice();
        const totalValor = groupedList.reduce(function (acc, g) { return acc + Number(g.valor || 0); }, 0);
        setText('vdPedidosCardValor', formatMoney(totalValor));
        setText('vdPedidosCardQuantidade', formatInt(groupedList.length) + ' pedido' + (groupedList.length !== 1 ? 's' : ''));

        const fmtMoneyLocal = function (v) { return formatMoney(v || 0); };

        if (!groupedList.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:48px 24px; color:var(--text-secondary); font-size:0.9rem;"><i class="fas fa-inbox" style="margin-right:8px; opacity:0.6;"></i>Nenhum pedido encontrado.</td></tr>';
            if (pagEl) pagEl.innerHTML = '';
            updateVendedorPedidosSortIcons();
            return;
        }

        const totalPages = Math.max(1, Math.ceil(groupedList.length / vendedorPedidosState.pageSize));
        vendedorPedidosState.page = Math.min(Math.max(1, vendedorPedidosState.page), totalPages);
        const start = (vendedorPedidosState.page - 1) * vendedorPedidosState.pageSize;
        const pageList = groupedList.slice(start, start + vendedorPedidosState.pageSize);
        let html = '';

        pageList.forEach(function (group) {
            const isAprovado = group.tipo === 'Aprovado';
            const isRecusado = group.tipo === 'Recusado';
            const corValor = isAprovado ? 'var(--success)' : (isRecusado ? 'var(--danger)' : 'var(--text-primary)');
            const bgGrupo = isAprovado ? 'rgba(5,150,105,0.04)' : isRecusado ? 'rgba(220,38,38,0.04)' : 'rgba(234,179,8,0.04)';
            const key = String(group.numero_pedido || '');
            const isOpen = !!vendedorPedidosState.expanded[key];
            const rows = Array.isArray(group.rows) ? group.rows : [];
            const serieGrupo = rows.length ? ((rows[0].serie_pedido === null || rows[0].serie_pedido === undefined || rows[0].serie_pedido === '') ? '0' : String(rows[0].serie_pedido)) : '0';
            const nEsc = escapeHtml(String(group.numero_pedido || '').replace(/'/g, "\\'"));
            const sEsc = escapeHtml(String(serieGrupo).replace(/'/g, "\\'"));

            html += '<tr role="button" tabindex="0" style="cursor:pointer; background:' + bgGrupo + ';" title="Ver detalhes do pedido" onclick="openVendedorPedidoDetalhe(\'' + nEsc + '\', \'' + sEsc + '\')">';
            html += '<td onclick="event.stopPropagation()"><button type="button" onclick="toggleVendedorPedidoSeries(\'' + key.replace(/'/g, "\\'") + '\')" style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border:1px solid var(--border); border-radius:6px; background:var(--bg-body); color:var(--text-primary); cursor:pointer; margin-right:8px; font-weight:700; line-height:1;">' + (isOpen ? '−' : '+') + '</button><span style="font-weight:600;">' + escapeHtml(group.numero_pedido) + '</span></td>';
            html += '<td>' + escapeHtml(rows.length + ' série' + (rows.length !== 1 ? 's' : '')) + '</td>';
            html += '<td>' + escapeHtml(group.tipo === 'Recusado' ? '—' : fmtDateBr(group.data_aprovacao)) + '</td>';
            html += '<td>' + escapeHtml(fmtDateBr(group.data_orcamento || (group.tipo === 'Aprovado' ? group.data_aprovacao : ''))) + '</td>';
            html += '<td>' + escapeHtml(group.prescritor) + '</td>';
            html += '<td>' + escapeHtml(group.cliente) + '</td>';
            html += '<td style="text-align:right; font-weight:700; color:' + corValor + ';">' + escapeHtml(fmtMoneyLocal(group.valor)) + '</td>';
            html += '<td>' + escapeHtml(group.tipo) + '</td></tr>';

            if (isOpen) {
                rows.forEach(function (p) {
                    const rowAprov = p.tipo === 'Aprovado';
                    const rowRec = p.tipo === 'Recusado';
                    const serie = (p.serie_pedido === null || p.serie_pedido === undefined || p.serie_pedido === '') ? '0' : String(p.serie_pedido);
                    const bgSub = rowAprov ? 'rgba(5,150,105,0.02)' : rowRec ? 'rgba(220,38,38,0.03)' : 'rgba(234,179,8,0.03)';
                    const borderSub = rowAprov ? '2px solid rgba(5,150,105,0.15)' : rowRec ? '2px solid rgba(220,38,38,0.15)' : '2px solid rgba(234,179,8,0.2)';
                    const pnEsc = escapeHtml(String(p.numero_pedido || '').replace(/'/g, "\\'"));
                    const psEsc = escapeHtml(String(serie).replace(/'/g, "\\'"));
                    html += '<tr role="button" tabindex="0" onclick="openVendedorPedidoDetalhe(\'' + pnEsc + '\', \'' + psEsc + '\')" style="cursor:pointer; background:' + bgSub + '; border-left:' + borderSub + ';" title="Ver detalhes da série">';
                    html += '<td style="padding-left:36px; color:var(--text-secondary);">↳ <span style="font-weight:600;">' + escapeHtml(p.numero_pedido) + '</span></td>';
                    html += '<td>' + escapeHtml(serie) + '</td>';
                    html += '<td>' + escapeHtml(p.tipo === 'Recusado' ? '—' : fmtDateBr(p.data_aprovacao)) + '</td>';
                    html += '<td>' + escapeHtml(fmtDateBr(p.data_orcamento || (p.tipo === 'Aprovado' ? p.data_aprovacao : ''))) + '</td>';
                    html += '<td>' + escapeHtml(p.prescritor) + '</td>';
                    html += '<td>' + escapeHtml(p.cliente) + '</td>';
                    html += '<td style="text-align:right; font-weight:600; color:' + (rowAprov ? 'var(--success)' : 'var(--danger)') + ';">' + escapeHtml(fmtMoneyLocal(p.valor)) + '</td>';
                    html += '<td>' + escapeHtml(p.tipo) + '</td></tr>';
                });
            }
        });
        tbody.innerHTML = html;

        if (pagEl) {
            const from = start + 1;
            const to = Math.min(start + vendedorPedidosState.pageSize, groupedList.length);
            let pagHtml = '<span style="font-weight:500;">Mostrando ' + from + ' – ' + to + ' de ' + groupedList.length + ' pedidos</span>';
            pagHtml += '<span class="vdped-pag-btns">';
            pagHtml += '<button type="button" onclick="goToVendedorPedidosPage(1)" ' + (vendedorPedidosState.page <= 1 ? 'disabled' : '') + ' title="Primeira"><i class="fas fa-angle-double-left"></i></button>';
            pagHtml += '<button type="button" onclick="goToVendedorPedidosPage(' + (vendedorPedidosState.page - 1) + ')" ' + (vendedorPedidosState.page <= 1 ? 'disabled' : '') + ' title="Anterior"><i class="fas fa-angle-left"></i></button>';
            pagHtml += '<span style="padding:0 10px; font-weight:500;">Página ' + vendedorPedidosState.page + ' de ' + totalPages + '</span>';
            pagHtml += '<button type="button" onclick="goToVendedorPedidosPage(' + (vendedorPedidosState.page + 1) + ')" ' + (vendedorPedidosState.page >= totalPages ? 'disabled' : '') + ' title="Próxima"><i class="fas fa-angle-right"></i></button>';
            pagHtml += '<button type="button" onclick="goToVendedorPedidosPage(' + totalPages + ')" ' + (vendedorPedidosState.page >= totalPages ? 'disabled' : '') + ' title="Última"><i class="fas fa-angle-double-right"></i></button>';
            pagHtml += '</span>';
            pagEl.innerHTML = pagHtml;
        }

        updateVendedorPedidosSortIcons();
    }

    async function loadVendedorPedidos() {
        const tbody = document.getElementById('vdPedidosTbody');
        if (!tbody) return;
        const range = currentMonthRange();
        const dataDeEl = document.getElementById('vdPedidosDataDe');
        const dataAteEl = document.getElementById('vdPedidosDataAte');
        if (!vendedorPedidosState.dataDe) vendedorPedidosState.dataDe = range.data_de;
        if (!vendedorPedidosState.dataAte) vendedorPedidosState.dataAte = range.data_ate;
        if (dataDeEl && !dataDeEl.value) dataDeEl.value = vendedorPedidosState.dataDe;
        if (dataAteEl && !dataAteEl.value) dataAteEl.value = vendedorPedidosState.dataAte;

        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:48px 24px; color:var(--text-secondary); font-size:0.9rem;"><i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i>Carregando...</td></tr>';
        const apiVendedora = getAdminVendedoraApiParam(currentSession);
        const resp = await apiGet('vendedor_pedidos_lista', {
            data_de: (dataDeEl && dataDeEl.value) ? dataDeEl.value : vendedorPedidosState.dataDe,
            data_ate: (dataAteEl && dataAteEl.value) ? dataAteEl.value : vendedorPedidosState.dataAte,
            vendedor: apiVendedora
        });
        if (!resp || resp.success === false) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:48px 24px; color:var(--danger); font-size:0.9rem;"><i class="fas fa-exclamation-circle" style="margin-right:8px;"></i>Erro ao carregar pedidos.</td></tr>';
            return;
        }
        const aprov = Array.isArray(resp.aprovados) ? resp.aprovados : [];
        const rec = Array.isArray(resp.recusados_carrinho) ? resp.recusados_carrinho : [];
        vendedorPedidosState.list = aprov.map(function (p) { return { ...p, tipo: 'Aprovado' }; }).concat(
            rec.map(function (p) {
                const st = String(p.status_origem || '').toLowerCase();
                const tipo =
                    st.indexOf('carrinho') !== -1
                        ? 'No carrinho'
                        : st.indexOf('recusad') !== -1 ||
                            st.indexOf('cancelad') !== -1 ||
                            st.indexOf('orçamento') !== -1 ||
                            st.indexOf('orcamento') !== -1
                          ? 'Recusado'
                          : 'No carrinho';
                return { ...p, tipo: tipo };
            })
        );
        vendedorPedidosState.page = 1;
        vendedorPedidosState.expanded = {};
        renderVendedorPedidos();
    }

    window.sortVendedorPedidosBy = function (col) {
        if (vendedorPedidosState.sort.column === col) vendedorPedidosState.sort.direction = vendedorPedidosState.sort.direction === 'asc' ? 'desc' : 'asc';
        else { vendedorPedidosState.sort.column = col; vendedorPedidosState.sort.direction = 'desc'; }
        vendedorPedidosState.page = 1;
        renderVendedorPedidos();
    };

    window.goToVendedorPedidosPage = function (page) {
        vendedorPedidosState.page = Math.max(1, parseInt(page, 10) || 1);
        renderVendedorPedidos();
    };

    window.toggleVendedorPedidoSeries = function (numeroPedido) {
        const key = String(numeroPedido || '');
        if (!key) return;
        vendedorPedidosState.expanded[key] = !vendedorPedidosState.expanded[key];
        renderVendedorPedidos();
    };

    window.openVendedorPedidoDetalhe = function (numeroPedido, seriePedido) {
        const numero = String(numeroPedido || '').trim();
        const serie = String(seriePedido || '').trim() || '0';
        if (!numero) return false;
        openModalDetalhePedido(numero, serie);
        return false;
    };

    async function openModalDetalhePedido(numero, serie, ano) {
        var modal = document.getElementById('modalDetalhePedido');
        var loading = document.getElementById('modalDetalhePedidoLoading');
        var errEl = document.getElementById('modalDetalhePedidoError');
        var content = document.getElementById('modalDetalhePedidoContent');
        if (!modal || !loading) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
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
            var qsDet = new URLSearchParams(params).toString();
            var resDetRaw = await fetch('api.php?action=get_pedido_detalhe&' + qsDet, { credentials: 'include' });
            var resDet = await resDetRaw.json();
            if (resDet.error && !resDet.pedido) {
                loading.style.display = 'none';
                if (errEl) { errEl.textContent = resDet.error || 'Pedido não encontrado.'; errEl.style.display = 'block'; }
                return;
            }
            var p = resDet.pedido || resDet.resumo || {};
            var itensGestao = Array.isArray(resDet.itens_gestao) ? resDet.itens_gestao : [];
            var itensOrcamento = Array.isArray(resDet.itens_orcamento) ? resDet.itens_orcamento : [];
            var qsComp = new URLSearchParams(params).toString();
            var resCompRaw = await fetch('api.php?action=get_pedido_componentes&' + qsComp, { credentials: 'include' });
            var resComp = await resCompRaw.json();
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

    window.closeModalDetalhePedido = function () {
        var modal = document.getElementById('modalDetalhePedido');
        if (modal) { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); }
    };

    window.printVendedorPedidosFiltrados = function () {
        const groupedList = Array.isArray(vendedorPedidosState.lastGroupedFiltered) ? vendedorPedidosState.lastGroupedFiltered : [];
        if (!groupedList.length) {
            alert('Não há pedidos filtrados para imprimir.');
            return;
        }
        const dataDe = (document.getElementById('vdPedidosDataDe') || {}).value || '';
        const dataAte = (document.getElementById('vdPedidosDataAte') || {}).value || '';
        const status = (document.getElementById('vdPedidosFiltroStatus') || {}).value || 'Todos';
        const busca = ((document.getElementById('vdSearchPedidos') || {}).value || '').trim();
        const totalValor = groupedList.reduce(function (acc, g) { return acc + (Number(g.valor) || 0); }, 0);
        const totalSeries = groupedList.reduce(function (acc, g) { return acc + ((g.rows && g.rows.length) || 0); }, 0);

        let linhas = '';
        groupedList.forEach(function (g) {
            const cor = g.tipo === 'Aprovado' ? '#059669' : (g.tipo === 'Recusado' ? '#dc2626' : '#111827');
            linhas += '<tr style="background:#f8fafc;">' +
                '<td style="padding:8px; border:1px solid #e5e7eb; font-weight:700;">' + escapeHtml(g.numero_pedido) + '</td>' +
                '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml((g.rows || []).length + ' série(s)') + '</td>' +
                '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(fmtDateBr(g.data_aprovacao)) + '</td>' +
                '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(fmtDateBr(g.data_orcamento)) + '</td>' +
                '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(g.prescritor || '—') + '</td>' +
                '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(g.cliente || '—') + '</td>' +
                '<td style="padding:8px; border:1px solid #e5e7eb; text-align:right; color:' + cor + '; font-weight:700;">' + escapeHtml(formatMoney(g.valor)) + '</td>' +
                '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(g.tipo || '—') + '</td>' +
            '</tr>';
            (g.rows || []).forEach(function (r) {
                const corSerie = r.tipo === 'Aprovado' ? '#059669' : '#dc2626';
                linhas += '<tr>' +
                    '<td style="padding:8px 8px 8px 24px; border:1px solid #e5e7eb; color:#6b7280;">↳ ' + escapeHtml(r.numero_pedido) + '</td>' +
                    '<td style="padding:8px; border:1px solid #e5e7eb;">Série ' + escapeHtml((r.serie_pedido === null || r.serie_pedido === undefined || r.serie_pedido === '' ? '0' : r.serie_pedido)) + '</td>' +
                    '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(r.tipo === 'Recusado' ? '—' : fmtDateBr(r.data_aprovacao)) + '</td>' +
                    '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(fmtDateBr(r.data_orcamento)) + '</td>' +
                    '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(r.prescritor || '—') + '</td>' +
                    '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(r.cliente || '—') + '</td>' +
                    '<td style="padding:8px; border:1px solid #e5e7eb; text-align:right; color:' + corSerie + '; font-weight:600;">' + escapeHtml(formatMoney(r.valor)) + '</td>' +
                    '<td style="padding:8px; border:1px solid #e5e7eb;">' + escapeHtml(r.tipo || '—') + '</td>' +
                '</tr>';
            });
        });
        const agora = new Date();
        const dataHoraImp = agora.toLocaleDateString('pt-BR') + ' ' + agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
        const html = '<!doctype html><html><head><meta charset="utf-8"><title>Pedidos filtrados</title></head><body style="font-family:Segoe UI, Arial, sans-serif; margin:22px; color:#111827;">' +
            '<div style="display:flex; align-items:center; justify-content:space-between; border-bottom:2px solid #e5e7eb; padding-bottom:10px; margin-bottom:16px;">' +
                '<div style="display:flex; align-items:center; gap:12px;">' +
                    '<div style="background:#ffffff; border:1px solid #e5e7eb; border-radius:8px; padding:6px 10px; line-height:0;">' +
                        '<img src="imagens/logoMypharm1.png?v=20260402" alt="MyPharm" style="height:34px; width:auto; display:block;" onerror="this.style.display=\'none\'">' +
                    '</div>' +
                    '<div>' +
                        '<div style="font-size:18px; font-weight:800; line-height:1.15;">Relatório de Pedidos Filtrados</div>' +
                        '<div style="font-size:11px; color:#4b5563;">MyPharm • Portal do Vendedor</div>' +
                    '</div>' +
                '</div>' +
                '<div style="font-size:11px; color:#4b5563; text-align:right;">Impresso em<br><strong style="color:#111827;">' + escapeHtml(dataHoraImp) + '</strong></div>' +
            '</div>' +
            '<div style="font-size:12px; color:#374151; margin-bottom:12px;">Período: ' + escapeHtml(fmtDateBr(dataDe)) + ' até ' + escapeHtml(fmtDateBr(dataAte)) + ' · Status: ' + escapeHtml(status) + (busca ? (' · Busca: ' + escapeHtml(busca)) : '') + '</div>' +
            '<div style="font-size:13px; margin-bottom:12px;"><strong>Total de pedidos:</strong> ' + escapeHtml(groupedList.length) + ' · <strong>Total de séries:</strong> ' + escapeHtml(totalSeries) + ' · <strong>Valor total:</strong> ' + escapeHtml(formatMoney(totalValor)) + '</div>' +
            '<table style="width:100%; border-collapse:collapse; font-size:12px;">' +
                '<thead><tr style="background:#111827; color:#fff;">' +
                    '<th style="padding:8px; text-align:left; border:1px solid #111827;">Nº Pedido</th>' +
                    '<th style="padding:8px; text-align:left; border:1px solid #111827;">Séries</th>' +
                    '<th style="padding:8px; text-align:left; border:1px solid #111827;">Data aprovação</th>' +
                    '<th style="padding:8px; text-align:left; border:1px solid #111827;">Data orçamento</th>' +
                    '<th style="padding:8px; text-align:left; border:1px solid #111827;">Prescritor</th>' +
                    '<th style="padding:8px; text-align:left; border:1px solid #111827;">Cliente</th>' +
                    '<th style="padding:8px; text-align:right; border:1px solid #111827;">Valor</th>' +
                    '<th style="padding:8px; text-align:left; border:1px solid #111827;">Status</th>' +
                '</tr></thead>' +
                '<tbody>' + linhas + '</tbody>' +
            '</table>' +
        '</body></html>';
        const w = window.open('', '_blank', 'width=1200,height=800');
        if (!w) {
            alert('Não foi possível abrir a janela de impressão. Verifique se o navegador bloqueou pop-up.');
            return;
        }
        w.document.open();
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(function () { w.print(); }, 250);
    };

    function fmtQtdCalculada(qtd, unidade) {
        if (qtd === null || qtd === undefined || qtd === '') return '-';
        const n = parseFloat(qtd);
        if (isNaN(n)) return String(qtd).trim() + (unidade ? ' ' + String(unidade).trim() : '');
        const s = Number.isInteger(n) ? String(Math.round(n)) : String(n).replace('.', ',');
        const u = (unidade && String(unidade).trim()) ? ' ' + String(unidade).trim() : '';
        return s + u;
    }

    function fmtDateTimeBr(value) {
        if (!value) return '-';
        const s = String(value).trim();
        if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(s)) {
            return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4) + ' ' + s.slice(11, 16);
        }
        return fmtDateBr(s);
    }

    function buildRecuperacaoMensagem(row) {
        const pedido = String(row && row.pedido_ref ? row.pedido_ref : '-');
        const paciente = String(row && row.cliente ? row.cliente : 'paciente');
        const valor = formatMoney(row && row.valor_rejeitado ? row.valor_rejeitado : 0);
        return 'Olá, ' + paciente + '! Aqui é da MyPharm. Identificamos seu pedido ' + pedido +
            ' (valor: ' + valor + ') e queremos te ajudar a concluir hoje com a melhor condição. Posso te enviar a atualização agora?';
    }

    function getContatoDigits(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function getStatusTentativaLabel(status) {
        const s = String(status || '').trim().toLowerCase();
        if (s === 'sem_resposta') return 'Sem resposta';
        if (s === 'retorno_pendente') return 'Retorno pendente';
        if (s === 'negociando') return 'Negociando';
        if (s === 'recuperado') return 'Recuperado';
        if (s === 'perdido') return 'Perdido';
        return 'Tentativa';
    }

    function getTipoContatoLabel(tipo) {
        const t = String(tipo || '').trim().toLowerCase();
        if (t === 'whatsapp') return 'WhatsApp';
        if (t === 'telefone') return 'Telefone';
        if (t === 'email') return 'E-mail';
        return 'Outro';
    }

    async function carregarHistoricoTentativas(row) {
        const wrap = document.getElementById('vdTentativasHistorico');
        if (!wrap || !row) return;
        wrap.innerHTML = '<div class="vd-mini-muted">Carregando tentativas...</div>';
        try {
            const res = await apiGet('vendedor_perdas_interacoes_lista', {
                ano_referencia: row.ano_referencia || '',
                numero_pedido: row.numero_pedido || ''
            });
            const rows = Array.isArray(res && res.rows) ? res.rows : [];
            if (!rows.length) {
                wrap.innerHTML = '<div class="vd-mini-muted">Sem tentativas registradas.</div>';
                return;
            }
            wrap.innerHTML = rows.map(function (it) {
                const status = getStatusTentativaLabel(it.status_tentativa);
                const tipo = getTipoContatoLabel(it.tipo_contato);
                const msg = String(it.mensagem || '').trim();
                const passo = String(it.proximo_passo || '').trim();
                return (
                    '<div style="border:1px solid var(--border); border-radius:8px; padding:8px 10px; margin-bottom:8px; background:var(--bg-card);">' +
                        '<div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; font-size:0.75rem; color:var(--text-secondary); margin-bottom:4px;">' +
                            '<span><strong style="color:var(--text-primary);">' + escapeHtml(tipo) + '</strong> · ' + escapeHtml(status) + '</span>' +
                            '<span>' + escapeHtml(fmtDateTimeBr(it.data_contato || it.created_at || '')) + '</span>' +
                        '</div>' +
                        (msg ? ('<div style="font-size:0.82rem; color:var(--text-primary); line-height:1.35; margin-bottom:' + (passo ? '4px' : '0') + ';">' + escapeHtml(msg) + '</div>') : '') +
                        (passo ? ('<div style="font-size:0.76rem; color:var(--text-secondary);"><strong>Próximo passo:</strong> ' + escapeHtml(passo) + '</div>') : '') +
                    '</div>'
                );
            }).join('');
        } catch (_) {
            wrap.innerHTML = '<div class="vd-mini-muted">Não foi possível carregar o histórico.</div>';
        }
    }

    window.abrirWhatsappRecuperacao = function (rowKey) {
        const row = perdasState.rowMap[String(rowKey)];
        if (!row) return;
        const contatoDigits = getContatoDigits(row.contato);
        if (!contatoDigits) {
            alert('Este pedido não possui telefone/WhatsApp cadastrado.');
            return;
        }
        const msg = buildRecuperacaoMensagem(row);
        const url = 'https://wa.me/' + contatoDigits + '?text=' + encodeURIComponent(msg);
        window.open(url, '_blank');
    };

    window.ligarContatoRecuperacao = function (rowKey) {
        const row = perdasState.rowMap[String(rowKey)];
        if (!row) return;
        const contatoDigits = getContatoDigits(row.contato);
        if (!contatoDigits) {
            alert('Este pedido não possui telefone cadastrado.');
            return;
        }
        window.location.href = 'tel:' + contatoDigits;
    };

    window.copiarMensagemRecuperacao = async function (rowKey) {
        const row = perdasState.rowMap[String(rowKey)];
        if (!row) return;
        const texto = buildRecuperacaoMensagem(row);
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(texto);
            } else {
                const ta = document.createElement('textarea');
                ta.value = texto;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            alert('Mensagem de recuperação copiada.');
        } catch (_) {
            alert('Não foi possível copiar a mensagem.');
        }
    };

    window.recuperarPedidoRapido = function (rowKey) {
        const row = perdasState.rowMap[String(rowKey)];
        if (!row) return;
        window.abrirModalPerdaAcao(String(rowKey));
        const motivo = document.getElementById('vdAcaoMotivoPerda');
        const obs = document.getElementById('vdAcaoObservacoes');
        if (motivo && !String(motivo.value || '').trim()) {
            motivo.value = 'Contato ativo para recuperação do pedido ' + (row.pedido_ref || '');
        }
        if (obs && !String(obs.value || '').trim()) {
            obs.value = 'Plano rápido: 1) enviar atualização de proposta; 2) confirmar prazo e valor; 3) retorno em até 24h.';
        }
    };

    async function loadPedidosPerdidos() {
        const tbody = document.getElementById('vdPedidosPerdidosBody');
        if (!tbody) return;
        const range = (perdasState.dataDe && perdasState.dataAte)
            ? { data_de: perdasState.dataDe, data_ate: perdasState.dataAte }
            : currentMonthRange();
        const apiVendedora = getAdminVendedoraApiParam(currentSession);
        const data = await apiGet('vendedor_pedidos_lista', {
            data_de: range.data_de,
            data_ate: range.data_ate,
            vendedor: apiVendedora
        });
        if (!data || data.success === false) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Erro ao carregar pedidos perdidos.</td></tr>';
            return;
        }

        const qRaw = String(perdasState.q || '').trim().toLowerCase();
        const sortBy = String(perdasState.sortBy || 'data_perda').toLowerCase();
        const sortDir = String(perdasState.sortDir || 'desc').toLowerCase() === 'asc' ? 'asc' : 'desc';
        const d = sortDir === 'asc' ? 1 : -1;
        const recusados = Array.isArray(data.recusados_carrinho) ? data.recusados_carrinho : [];
        let list = recusados.slice();
        if (qRaw) {
            list = list.filter(function (p) {
                return String(p.numero_pedido || '').toLowerCase().indexOf(qRaw) !== -1
                    || String(p.prescritor || '').toLowerCase().indexOf(qRaw) !== -1
                    || String(p.cliente || '').toLowerCase().indexOf(qRaw) !== -1
                    || String(p.status_origem || '').toLowerCase().indexOf(qRaw) !== -1
                    || String(p.contato || '').toLowerCase().indexOf(qRaw) !== -1;
            });
        }

        const groupsMap = {};
        const groupsOrder = [];
        list.forEach(function (p) {
            const numero = String(p.numero_pedido || '').trim();
            if (!numero) return;
            if (!groupsMap[numero]) {
                groupsMap[numero] = { numero_pedido: numero, rows: [], valor_total: 0, ano_referencia: Number(p.ano_referencia || 0) };
                groupsOrder.push(numero);
            }
            groupsMap[numero].rows.push(p);
            groupsMap[numero].valor_total += Number(p.valor || 0);
            if (!groupsMap[numero].ano_referencia && Number(p.ano_referencia || 0) > 0) groupsMap[numero].ano_referencia = Number(p.ano_referencia || 0);
        });

        let groupedList = groupsOrder.map(function (numero) {
            const g = groupsMap[numero];
            const rows = g.rows || [];
            let dataAprov = '';
            let dataOrc = '';
            const prescritores = {};
            const clientes = {};
            const contatos = {};
            const statusOrigem = {};
            rows.forEach(function (r) {
                const presc = String(r.prescritor || '').trim();
                const cli = String(r.cliente || '').trim();
                const contato = String(r.contato || '').trim();
                const statusRaw = String(r.status_origem || '').trim().toLowerCase();
                const dA = String(r.data_aprovacao || '').trim();
                const dO = String(r.data_orcamento || '').trim();
                if (presc) prescritores[presc] = true;
                if (cli) clientes[cli] = true;
                if (contato) contatos[contato] = true;
                if (statusRaw) statusOrigem[statusRaw] = true;
                if (dA && (!dataAprov || dA > dataAprov)) dataAprov = dA;
                if (dO && (!dataOrc || dO > dataOrc)) dataOrc = dO;
            });
            const prescKeys = Object.keys(prescritores);
            const cliKeys = Object.keys(clientes);
            const contatoKeys = Object.keys(contatos);
            const statusKeys = Object.keys(statusOrigem);
            const statusResumo = statusKeys.some(function (s) { return s === 'recusado'; }) ? 'Recusado' : 'No carrinho';
            return {
                numero_pedido: numero,
                pedido_ref: numero + '/' + (g.ano_referencia || new Date().getFullYear()),
                ano_referencia: g.ano_referencia || 0,
                serie_pedido: rows.length,
                data_aprovacao: dataAprov,
                data_orcamento: dataOrc,
                prescritor: prescKeys.length <= 1 ? (prescKeys[0] || '—') : (prescKeys[0] + ' +' + (prescKeys.length - 1)),
                cliente: cliKeys.length <= 1 ? (cliKeys[0] || '—') : (cliKeys[0] + ' +' + (cliKeys.length - 1)),
                contato: contatoKeys[0] || '',
                valor: g.valor_total,
                status_principal: statusResumo,
                qtd_itens: rows.length,
                rows: rows
            };
        });

        groupedList = groupedList.sort(function (a, b) {
            let va; let vb;
            if (sortBy === 'valor_rejeitado' || sortBy === 'valor') {
                va = Number(a.valor || 0); vb = Number(b.valor || 0);
                return (va - vb) * d;
            }
            if (sortBy === 'cliente') {
                va = String(a.cliente || '').toLowerCase(); vb = String(b.cliente || '').toLowerCase();
                return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
            }
            if (sortBy === 'prescritor') {
                va = String(a.cliente || '').toLowerCase(); vb = String(b.cliente || '').toLowerCase();
                return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
            }
            if (sortBy === 'status') {
                va = String(a.status_principal || '').toLowerCase(); vb = String(b.status_principal || '').toLowerCase();
                return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
            }
            if (sortBy === 'pedido') {
                va = Number(a.numero_pedido || 0); vb = Number(b.numero_pedido || 0);
                return (va - vb) * d;
            }
            va = String(a.data_orcamento || '').replace(/-/g, '');
            vb = String(b.data_orcamento || '').replace(/-/g, '');
            return (va < vb ? -1 : (va > vb ? 1 : 0)) * d;
        });

        perdasState.rowMap = {};
        if (!groupedList.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Nenhum pedido perdido encontrado no período.</td></tr>';
        } else {
            const totalPages = Math.max(1, Math.ceil(groupedList.length / perdasState.perPage));
            perdasState.totalPages = totalPages;
            perdasState.page = Math.max(1, Math.min(perdasState.page, totalPages));
            const start = (perdasState.page - 1) * perdasState.perPage;
            const pageList = groupedList.slice(start, start + perdasState.perPage);
            tbody.innerHTML = pageList.map(function (g, idx) {
                const key = 'g' + String(start + idx);
                perdasState.rowMap[key] = g;
                const acaoTxt = (g.motivo_perda && String(g.motivo_perda).trim() !== '')
                    ? ('Motivo: ' + escapeHtml(g.motivo_perda))
                    : 'Sem ação registrada';
                const ultimaAcao = (g.acao_atualizada_em && String(g.acao_atualizada_em).trim() !== '')
                    ? ('Últ. ação: ' + fmtDateTimeBr(g.acao_atualizada_em))
                    : 'Ainda sem histórico de recuperação';
                return (
                    '<tr>' +
                    '<td><button type="button" class="btn-action-primary" style="padding:6px 9px; font-size:0.75rem;" onclick="abrirModalPedidoGestao(\'' + key + '\')"><i class="fas fa-file-lines"></i> ' + escapeHtml(g.numero_pedido || '-') + '</button></td>' +
                    '<td>' + escapeHtml(String(g.serie_pedido || 0) + ' série' + ((Number(g.serie_pedido || 0) !== 1) ? 's' : '')) + '</td>' +
                    '<td>' + escapeHtml(fmtDateBr(g.data_orcamento || '')) + '</td>' +
                    '<td>' + escapeHtml(g.prescritor || '—') + '</td>' +
                    '<td><div style="display:flex; flex-direction:column; gap:2px;"><strong>' + escapeHtml(g.cliente || 'Paciente não informado') + '</strong><span class="vd-mini-muted">' + escapeHtml(g.contato || 'Sem contato') + '</span></div></td>' +
                    '<td style="text-align:right; font-weight:700; color:var(--danger);">' + formatMoney(g.valor || 0) + '</td>' +
                    '<td>' + escapeHtml(g.status_principal || 'Recusado') + '</td>' +
                    '<td><div style="display:flex; flex-direction:column; gap:6px;">' +
                    '<span style="font-size:0.75rem; color:var(--text-secondary);">' + acaoTxt + '</span>' +
                    '<span style="font-size:0.73rem; color:var(--text-muted);">' + escapeHtml(ultimaAcao) + '</span>' +
                    '<div class="perdas-actions">' +
                    '<button type="button" class="perdas-btn perdas-btn-detalhe" title="Ver detalhe do pedido" onclick="abrirModalPedidoGestao(\'' + key + '\')"><i class="fas fa-file-lines"></i></button>' +
                    '<button type="button" class="perdas-btn perdas-btn-whats" title="Abrir WhatsApp" onclick="abrirWhatsappRecuperacao(\'' + key + '\')" ' + (String(g.contato || '').trim() ? '' : 'disabled') + '><i class="fab fa-whatsapp"></i></button>' +
                    '<button type="button" class="perdas-btn perdas-btn-ligar" title="Ligar contato" onclick="ligarContatoRecuperacao(\'' + key + '\')" ' + (String(g.contato || '').trim() ? '' : 'disabled') + '><i class="fas fa-phone"></i></button>' +
                    '<button type="button" class="perdas-btn perdas-btn-rec" title="Recuperação rápida" onclick="recuperarPedidoRapido(\'' + key + '\')"><i class="fas fa-bolt"></i></button>' +
                    '<button type="button" class="perdas-btn perdas-btn-copiar" title="Copiar mensagem de recuperação" onclick="copiarMensagemRecuperacao(\'' + key + '\')"><i class="fas fa-copy"></i></button>' +
                    '<button type="button" class="perdas-btn perdas-btn-acao" title="Registrar ação e conversa" onclick="abrirModalPerdaAcao(\'' + key + '\')"><i class="fas fa-comments"></i></button>' +
                    '</div>' +
                    '</div></td>' +
                    '</tr>'
                );
            }).join('');
        }

        const total = groupedList.length;
        const pages = Math.max(1, Math.ceil(total / perdasState.perPage));
        perdasState.totalPages = pages;
        perdasState.page = Math.max(1, Math.min(perdasState.page, pages));
        const pageInfo = document.getElementById('vdPerdasPageInfo');
        if (pageInfo) pageInfo.textContent = 'Página ' + perdasState.page + ' de ' + pages + ' · ' + formatInt(total) + ' pedido(s)';

        const qtdRecusado = groupedList.filter(function (g) { return String(g.status_principal || '').toLowerCase() === 'recusado'; }).length;
        const qtdCarrinho = groupedList.filter(function (g) { return String(g.status_principal || '').toLowerCase() !== 'recusado'; }).length;
        const totalValor = groupedList.reduce(function (acc, g) { return acc + Number(g.valor || 0); }, 0);
        const ticketMedio = total > 0 ? (totalValor / total) : 0;
        setText('vdPerdasTotalQtd', formatInt(total) + ' pedidos');
        setText('vdPerdasTotalValor', formatMoney(totalValor));
        setText('vdPerdasTotalRecusado', formatInt(qtdRecusado) + ' pedidos');
        setText('vdPerdasTotalCarrinho', formatInt(qtdCarrinho) + ' pedidos');
        setText('vdPerdasComAcao', '0 pedidos');
        setText('vdPerdasSemAcao', formatInt(total) + ' pedidos');
        setText('vdPerdasTicketMedio', formatMoney(ticketMedio));

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
        setText('vdPerdaContatoInfo', 'Contato: ' + (String(row.contato || '').trim() || 'sem telefone informado'));
        const motivo = document.getElementById('vdAcaoMotivoPerda');
        const classif = document.getElementById('vdAcaoClassificacaoErro');
        const pontos = document.getElementById('vdAcaoPontosDescontados');
        const obs = document.getElementById('vdAcaoObservacoes');
        const tipoContato = document.getElementById('vdAcaoTipoContato');
        const statusTentativa = document.getElementById('vdAcaoStatusTentativa');
        const msgTentativa = document.getElementById('vdAcaoMensagemTentativa');
        const proxPasso = document.getElementById('vdAcaoProximoPasso');
        const btnLigar = document.getElementById('vdBtnContatoLigar');
        const btnWhatsapp = document.getElementById('vdBtnContatoWhatsapp');
        if (motivo) motivo.value = row.motivo_perda || '';
        if (classif) classif.value = row.classificacao_erro || 'nenhum';
        if (pontos) pontos.value = Number(row.pontos_descontados || 0) > 0 ? String(row.pontos_descontados) : '';
        if (obs) obs.value = row.observacoes || '';
        if (tipoContato) tipoContato.value = 'whatsapp';
        if (statusTentativa) statusTentativa.value = 'retorno_pendente';
        if (msgTentativa) msgTentativa.value = '';
        if (proxPasso) proxPasso.value = '';
        if (btnLigar) btnLigar.onclick = function () { window.ligarContatoRecuperacao(String(rowKey)); };
        if (btnWhatsapp) btnWhatsapp.onclick = function () { window.abrirWhatsappRecuperacao(String(rowKey)); };
        const hasContato = String(row.contato || '').trim() !== '';
        if (btnLigar) btnLigar.disabled = !hasContato;
        if (btnWhatsapp) btnWhatsapp.disabled = !hasContato;
        const modal = document.getElementById('modalPerdaAcao');
        if (modal) {
            modal.style.display = 'flex';
            setTimeout(function () { modal.classList.add('active'); }, 10);
            modal.setAttribute('aria-hidden', 'false');
        }
        carregarHistoricoTentativas(row).catch(function () {});
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
        const detalheRow = (row.rows && row.rows.length) ? row.rows[0] : row;
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
                numero: detalheRow.numero_pedido || row.numero_pedido || '',
                serie: detalheRow.serie_pedido || '',
                ano: String(detalheRow.ano_referencia || row.ano_referencia || '')
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
                numero: detalheRow.numero_pedido || row.numero_pedido || '',
                serie: detalheRow.serie_pedido || '',
                ano: String(detalheRow.ano_referencia || row.ano_referencia || '')
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
        const tipoContatoEl = document.getElementById('vdAcaoTipoContato');
        const statusTentativaEl = document.getElementById('vdAcaoStatusTentativa');
        const msgTentativaEl = document.getElementById('vdAcaoMensagemTentativa');
        const proxPassoEl = document.getElementById('vdAcaoProximoPasso');
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
        const mensagemTentativa = String((msgTentativaEl && msgTentativaEl.value) || '').trim();
        const proximoPasso = String((proxPassoEl && proxPassoEl.value) || '').trim();
        const statusTentativa = String((statusTentativaEl && statusTentativaEl.value) || 'retorno_pendente').trim();
        const tipoContato = String((tipoContatoEl && tipoContatoEl.value) || 'whatsapp').trim();
        const deveSalvarTentativa = !!(mensagemTentativa || proximoPasso || statusTentativa === 'recuperado' || statusTentativa === 'negociando' || statusTentativa === 'perdido');
        if (deveSalvarTentativa) {
            const respTent = await apiPost('vendedor_perdas_interacoes_salvar', {
                ano_referencia: perdasState.currentPedido.ano_referencia,
                numero_pedido: perdasState.currentPedido.numero_pedido,
                serie_pedido: perdasState.currentPedido.serie_pedido || '',
                tipo_contato: tipoContato,
                status_tentativa: statusTentativa,
                mensagem: mensagemTentativa,
                proximo_passo: proximoPasso
            });
            if (!respTent || respTent.success === false) {
                alert((respTent && respTent.error) ? respTent.error : 'Ação salva, mas não foi possível salvar a conversa.');
            } else {
                if (msgTentativaEl) msgTentativaEl.value = '';
                if (proxPassoEl) proxPassoEl.value = '';
                carregarHistoricoTentativas(perdasState.currentPedido).catch(function () {});
            }
        }
        await loadPedidosPerdidos();
        if (currentSession) {
            loadMetas(currentSession).catch(function () {});
        }
        alert('Ação de recuperação salva com sucesso.');
    }

    async function loadMetas(session) {
        hideError();
        const nome = getDashboardVendedoraName(session);
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
        var baseConversaoPedidos = qtdAprovados + qtdReprovados;
        var taxaConversaoPedidos = baseConversaoPedidos > 0
            ? (qtdAprovados / baseConversaoPedidos) * 100
            : null;
        var valorAprovado = faturamento_mes;
        var valorReprovado = me ? Number(me.valor_rejeitado || 0) : 0;
        var ticketMedioAprovado = qtdAprovados > 0 ? (valorAprovado / qtdAprovados) : 0;
        var ticketMedioReprovado = qtdReprovados > 0 ? (valorReprovado / qtdReprovados) : 0;
        // Regra de exibição alinhada com o subtítulo do card: aprovados x recus./carrinho.
        setText('vdTaxaConversao',
            taxaConversaoPedidos != null && !isNaN(taxaConversaoPedidos)
                ? formatPercent(taxaConversaoPedidos)
                : (
                    taxaConvLinhas != null && !isNaN(taxaConvLinhas) && me && ((me.linhas_aprovadas || 0) > 0 || (me.linhas_orcamento || 0) > 0 || (me.linhas_perdidas || 0) > 0)
                        ? formatPercent(taxaConvLinhas)
                        : formatPercent(pctMeta)
                )
        );
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
                clearLocalStoragePreservingMyPharmTheme();
                window.location.href = 'index.html';
            });
        }

        const vdPedidosSearch = document.getElementById('vdSearchPedidos');
        const vdPedidosDataDe = document.getElementById('vdPedidosDataDe');
        const vdPedidosDataAte = document.getElementById('vdPedidosDataAte');
        const vdPedidosStatus = document.getElementById('vdPedidosFiltroStatus');
        const rangePedidos = currentMonthRange();
        vendedorPedidosState.dataDe = rangePedidos.data_de;
        vendedorPedidosState.dataAte = rangePedidos.data_ate;
        if (vdPedidosDataDe) vdPedidosDataDe.value = rangePedidos.data_de;
        if (vdPedidosDataAte) vdPedidosDataAte.value = rangePedidos.data_ate;
        if (vdPedidosStatus) vdPedidosStatus.value = 'Aprovado';
        if (vdPedidosSearch) {
            vdPedidosSearch.addEventListener('input', function () {
                vendedorPedidosState.page = 1;
                renderVendedorPedidos();
            });
        }
        if (vdPedidosStatus) {
            vdPedidosStatus.addEventListener('change', function () {
                vendedorPedidosState.page = 1;
                renderVendedorPedidos();
            });
        }
        if (vdPedidosDataDe) {
            vdPedidosDataDe.addEventListener('change', function () {
                vendedorPedidosState.dataDe = vdPedidosDataDe.value || rangePedidos.data_de;
                if (vdPedidosDataAte && vdPedidosDataAte.value && vdPedidosDataAte.value < vendedorPedidosState.dataDe) {
                    vdPedidosDataAte.value = vendedorPedidosState.dataDe;
                    vendedorPedidosState.dataAte = vendedorPedidosState.dataDe;
                }
                loadVendedorPedidos().catch(function () {});
            });
        }
        if (vdPedidosDataAte) {
            vdPedidosDataAte.addEventListener('change', function () {
                vendedorPedidosState.dataAte = vdPedidosDataAte.value || rangePedidos.data_ate;
                if (vdPedidosDataDe && vdPedidosDataDe.value && vendedorPedidosState.dataAte < vdPedidosDataDe.value) {
                    vendedorPedidosState.dataAte = vdPedidosDataDe.value;
                    vdPedidosDataAte.value = vendedorPedidosState.dataAte;
                }
                loadVendedorPedidos().catch(function () {});
            });
        }
    }

    document.addEventListener('DOMContentLoaded', async function () {
        loadSavedTheme();
        forcedVendedoraView = getForcedVendedoraFromUrl();
        const session = await enforceVendedorAccess();
        if (!session) {
            var loader = document.getElementById('vdLoadingOverlay');
            if (loader) loader.style.display = 'none';
            return;
        }
        currentSession = session;
        window.vdSession = session;
        const app = document.getElementById('vdApp');
        if (app) app.style.display = 'block';
        var loader = document.getElementById('vdLoadingOverlay');
        if (loader) loader.style.display = 'none';
        // Preenche nome e avatar em todas as páginas do vendedor
        const nomeHeader = getDashboardVendedoraName(session);
        setText('vdUserName', nomeHeader);
        setText('vdAvatar', nomeHeader ? nomeHeader.charAt(0).toUpperCase() : 'V');
        bindUi();
        // Só carrega dados pesados do dashboard na página principal (vendedor.html)
        const isDashboard = !!document.getElementById('vdPctMetaDiaria');
        const isPedidosPage = !!document.getElementById('vdPedidosTbody') && !isDashboard;
        if (isDashboard) {
            await loadMetas(session);
            await loadPedidosPerdidos();
            await loadVendedorPedidos();
            setInterval(function () {
                loadMetas(session).catch(function () {});
                loadPedidosPerdidos().catch(function () {});
                loadVendedorPedidos().catch(function () {});
            }, REFRESH_MS);
        } else if (isPedidosPage) {
            await loadVendedorPedidos();
        }
    });
})();
