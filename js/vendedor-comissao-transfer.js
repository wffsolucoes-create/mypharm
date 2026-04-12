(function () {
    const API_URL = 'api_gestao.php';

    function forcedVendedoraFromUrl() {
        try {
            const qs = new URLSearchParams(window.location.search || '');
            return String(qs.get('vendedora') || qs.get('vendedor') || '').trim();
        } catch (_) {
            return '';
        }
    }

    function adminVendedorApiParam(session) {
        const tipo = String((session && session.tipo) || '').trim().toLowerCase();
        const forced = forcedVendedoraFromUrl();
        return tipo === 'admin' && forced ? forced : '';
    }

    function meuNomeVendedora(session) {
        const tipo = String((session && session.tipo) || '').trim().toLowerCase();
        const forced = forcedVendedoraFromUrl();
        if (tipo === 'admin' && forced) return forced;
        return String((session && session.nome) || localStorage.getItem('userName') || '').trim();
    }

    async function apiGet(action, params) {
        const query = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'include' });
        const text = await res.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch (_) {
            return { success: false, error: `Erro ${res.status}` };
        }
    }

    async function apiPost(action, data) {
        const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data || {})
        });
        const text = await res.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch (_) {
            return { success: false, error: `Erro ${res.status}` };
        }
    }

    function formatMoney(v) {
        const n = Number(v || 0);
        return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function escapeHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtDateTime(value) {
        if (!value) return '—';
        const str = String(value).trim();
        if (/^\d{4}-\d{2}-\d{2}/.test(str)) {
            const d = str.slice(8, 10) + '/' + str.slice(5, 7) + '/' + str.slice(0, 4);
            const t = str.length >= 16 ? str.slice(11, 16) : '';
            return t ? d + ' ' + t : d;
        }
        return str;
    }

    function formatPedidoSerieCell(r) {
        const np = r.numero_pedido != null && r.numero_pedido !== '' ? parseInt(r.numero_pedido, 10) : 0;
        if (!np || np <= 0) return '—';
        const spRaw = r.serie_pedido;
        const sp =
            spRaw != null && spRaw !== ''
                ? parseInt(spRaw, 10)
                : 0;
        const s = !isNaN(sp) ? sp : 0;
        return escapeHtml(String(np)) + ' / ' + escapeHtml(String(s));
    }

    function statusLabel(st) {
        const s = String(st || '').toLowerCase();
        const map = { pendente: 'Pendente', aprovada: 'Aprovada', recusada: 'Recusada', cancelada: 'Cancelada' };
        const label = map[s] || (st || '—');
        const cls = map[s] ? 'vdct-status vdct-status--' + s : '';
        return cls ? '<span class="' + cls + '">' + label + '</span>' : label;
    }

    function setMsg(el, type, text) {
        if (!el) return;
        el.textContent = text || '';
        el.style.display = text ? 'block' : 'none';
        el.className = 'vdct-msg' + (type === 'err' ? ' vdct-msg--error' : type === 'ok' ? ' vdct-msg--ok' : '');
    }

    function normName(a) {
        return String(a || '').trim().toLowerCase();
    }

    async function loadOpcoes(session) {
        const sel = document.getElementById('vdCtContraparte');
        if (!sel) return;
        const me = meuNomeVendedora(session);
        const vendParam = adminVendedorApiParam(session);
        const resp = await apiGet('vendedor_comissao_transfer_opcoes', vendParam ? { vendedor: vendParam } : {});
        sel.innerHTML = '<option value="">Selecione a consultora origem (débito)…</option>';
        if (!resp || resp.success === false) return;
        const nomes = Array.isArray(resp.nomes) ? resp.nomes : [];
        nomes.forEach(function (n) {
            if (!n || normName(n) === normName(me)) return;
            const opt = document.createElement('option');
            opt.value = n;
            opt.textContent = n;
            sel.appendChild(opt);
        });
    }

    async function loadLista(session) {
        const tbody = document.getElementById('vdCtTbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="10" class="vdct-table__empty"><i class="fas fa-spinner fa-spin"></i> Carregando…</td></tr>';
        const vendParam = adminVendedorApiParam(session);
        const resp = await apiGet('vendedor_comissao_transfer_lista', vendParam ? { vendedor: vendParam } : {});
        if (!resp || resp.success === false) {
            tbody.innerHTML = '<tr><td colspan="10" class="vdct-table__empty" style="color:var(--danger);">Não foi possível carregar as solicitações.</td></tr>';
            return;
        }
        const rows = Array.isArray(resp.rows) ? resp.rows : [];
        const me = meuNomeVendedora(session);
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="vdct-table__empty">Nenhuma solicitação registrada.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function (r) {
            const id = Number(r.id || 0);
            const sol = String(r.solicitante_nome || '');
            const con = String(r.contraparte_nome || '');
            const souSol = normName(sol) === normName(me);
            const papel = souSol
                ? '<span style="color:var(--success,#15803d); font-weight:600;">Destino</span>'
                : '<span style="color:var(--warning,#d97706); font-weight:600;">Origem</span>';
            const outra = souSol ? escapeHtml(con) : escapeHtml(sol);
            const st = String(r.status || '').toLowerCase();
            const ref = (r.ref_mes && r.ref_ano) ? (String(r.ref_mes).padStart(2, '0') + '/' + r.ref_ano) : '—';
            const btnVer = '<button type="button" class="vdct-icon-btn" data-id="' + id + '" data-action="ver" title="Visualizar detalhes" aria-label="Visualizar"><i class="fas fa-eye"></i></button>';
            const btnEdit = (souSol && st === 'pendente' && id)
                ? '<button type="button" class="vdct-icon-btn vdct-icon-btn--edit" data-id="' + id + '" data-action="edit" title="Editar solicitação" aria-label="Editar"><i class="fas fa-pen"></i></button>'
                : '';
            const btnCancel = (souSol && st === 'pendente' && id)
                ? '<button type="button" class="vdct-icon-btn vdct-icon-btn--cancel" data-id="' + id + '" data-action="cancel" title="Cancelar solicitação" aria-label="Cancelar"><i class="fas fa-times"></i></button>'
                : '';
            return (
                '<tr>' +
                '<td class="vdct-td-date">' + fmtDateTime(r.created_at) + '</td>' +
                '<td>' + papel + '</td>' +
                '<td>' + outra + '</td>' +
                '<td class="vdct-td-valor">' + escapeHtml(formatMoney(r.valor)) + '</td>' +
                '<td class="vdct-td-pedido">' + formatPedidoSerieCell(r) + '</td>' +
                '<td class="vdct-td-ref">' + escapeHtml(ref) + '</td>' +
                '<td>' + statusLabel(r.status) + '</td>' +
                '<td class="vdct-td-acoes"><div class="vdct-acoes-btns">' + btnVer + btnEdit + btnCancel + '</div></td>' +
                '</tr>'
            );
        }).join('');

        tbody.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const rid = Number(btn.getAttribute('data-id') || 0);
                const action = btn.getAttribute('data-action');
                const row = rows.find(function (r) { return Number(r.id) === rid; });
                if (action === 'cancel') {
                    cancelarSolicitacao(session, rid).catch(function () {});
                } else if (action === 'ver') {
                    abrirModalVer(row);
                } else if (action === 'edit') {
                    abrirModalEditar(session, row);
                }
            });
        });
    }

    async function cancelarSolicitacao(session, id) {
        if (!id) return;
        const ok = await showConfirm({
            kind: 'danger',
            title: 'Cancelar solicitação',
            message: 'Cancelar esta solicitação pendente? Ela não poderá mais ser processada pela gestão.',
            confirmText: 'Cancelar solicitação',
            cancelText: 'Voltar',
            destructive: true
        });
        if (!ok) return;
        const payload = { id: id };
        const vp = adminVendedorApiParam(session);
        if (vp) payload.vendedor = vp;
        const resp = await apiPost('vendedor_comissao_transfer_cancelar', payload);
        if (!resp || resp.success === false || !resp.updated) {
            showError((resp && resp.error) ? resp.error : 'Não foi possível cancelar.');
            return;
        }
        showSuccess('Solicitação cancelada.');
        await loadLista(session);
    }

    async function enviarSolicitacao(session) {
        const msgEl = document.getElementById('vdCtFormMsg');
        setMsg(msgEl, '', '');
        const sel = document.getElementById('vdCtContraparte');
        const numEl = document.getElementById('vdCtNumeroPedido');
        const serEl = document.getElementById('vdCtSeriePedido');
        const valEl = document.getElementById('vdCtValor');
        const mesEl = document.getElementById('vdCtRefMes');
        const anoEl = document.getElementById('vdCtRefAno');
        const motEl = document.getElementById('vdCtMotivo');
        const contraparte = sel ? String(sel.value || '').trim() : '';
        const valorRaw = valEl ? String(valEl.value || '').trim() : '';
        const motivo = motEl ? String(motEl.value || '').trim() : '';
        const refMes = mesEl ? parseInt(mesEl.value, 10) : 0;
        const refAno = anoEl ? parseInt(anoEl.value, 10) : 0;
        const numStr = numEl ? String(numEl.value || '').replace(/\D+/g, '') : '';
        const numeroPedido = parseInt(numStr, 10);
        const serStr = serEl ? String(serEl.value || '').trim() : '';
        const serieDigits = serStr === '' ? '0' : String(serStr).replace(/\D+/g, '');
        const seriePedido = parseInt(serieDigits, 10);
        if (!numStr || !numeroPedido || numeroPedido <= 0) {
            setMsg(msgEl, 'err', 'Informe o número do pedido.');
            return;
        }
        if (numeroPedido > 999999999) {
            setMsg(msgEl, 'err', 'Número do pedido inválido.');
            return;
        }
        if (isNaN(seriePedido) || seriePedido < 0 || seriePedido > 999999) {
            setMsg(msgEl, 'err', 'Série inválida (use 0 se não houver série).');
            return;
        }
        const payload = {
            contraparte_nome: contraparte,
            valor: valorRaw,
            ref_mes: refMes,
            ref_ano: refAno,
            numero_pedido: numeroPedido,
            serie_pedido: seriePedido,
            motivo: motivo
        };
        const vp = adminVendedorApiParam(session);
        if (vp) payload.vendedor = vp;
        const resp = await apiPost('vendedor_comissao_transfer_solicitar', payload);
        if (!resp || resp.success === false) {
            setMsg(msgEl, 'err', (resp && resp.error) ? resp.error : 'Não foi possível enviar.');
            return;
        }
        setMsg(msgEl, 'ok', 'Solicitação registrada. A gestão será notificada para análise.');
        if (motEl) motEl.value = '';
        if (valEl) valEl.value = '';
        if (sel) sel.value = '';
        if (numEl) numEl.value = '';
        if (serEl) serEl.value = '0';
        const resultEl = document.getElementById('vdCtLookupResult');
        if (resultEl) { resultEl.innerHTML = ''; resultEl.style.display = 'none'; }
        mostrarFormulario(false);
        await loadLista(session);
    }

    function abrirModal(body, footer) {
        const backdrop = document.getElementById('vdCtModalBackdrop');
        const bodyEl = document.getElementById('vdCtModalBody');
        const footerEl = document.getElementById('vdCtModalFooter');
        const closeBtn = document.getElementById('vdCtModalClose');
        if (!backdrop || !bodyEl || !footerEl) return;
        bodyEl.innerHTML = body;
        footerEl.innerHTML = footer;
        const close = function () {
            backdrop.classList.remove('vdct-modal-backdrop--open');
            backdrop.setAttribute('aria-hidden', 'true');
        };
        closeBtn.onclick = close;
        backdrop.onclick = function (e) { if (e.target === backdrop) close(); };
        const closeFooter = footerEl.querySelector('[data-modal-close]');
        if (closeFooter) closeFooter.onclick = close;
        backdrop.classList.add('vdct-modal-backdrop--open');
        backdrop.setAttribute('aria-hidden', 'false');
        return close;
    }

    function abrirModalVer(r) {
        if (!r) return;
        const ref = (r.ref_mes && r.ref_ano) ? String(r.ref_mes).padStart(2, '0') + '/' + r.ref_ano : '—';
        const np = r.numero_pedido ? parseInt(r.numero_pedido, 10) : 0;
        const pedido = np > 0 ? String(np) + ' / ' + (r.serie_pedido != null ? String(r.serie_pedido) : '0') : '—';
        const obs = String(r.observacao_gestao || '').trim();
        const body =
            '<dl class="vdct-modal-dl">' +
            '<div class="vdct-modal-dl__row"><dt>Destino (crédito)</dt><dd>' + escapeHtml(r.solicitante_nome) + '</dd></div>' +
            '<div class="vdct-modal-dl__row"><dt>Origem (débito)</dt><dd>' + escapeHtml(r.contraparte_nome) + '</dd></div>' +
            '<div class="vdct-modal-dl__row"><dt>Valor</dt><dd>' + escapeHtml(formatMoney(r.valor)) + '</dd></div>' +
            '<div class="vdct-modal-dl__row"><dt>Pedido / série</dt><dd>' + escapeHtml(pedido) + '</dd></div>' +
            '<div class="vdct-modal-dl__row"><dt>Referência</dt><dd>' + escapeHtml(ref) + '</dd></div>' +
            '<div class="vdct-modal-dl__row"><dt>Status</dt><dd>' + statusLabel(r.status) + '</dd></div>' +
            '<div class="vdct-modal-dl__row vdct-modal-dl__row--full"><dt>Motivo</dt><dd>' + escapeHtml(r.motivo || '—') + '</dd></div>' +
            (obs ? '<div class="vdct-modal-dl__row vdct-modal-dl__row--full"><dt>Observação da gestão</dt><dd>' + escapeHtml(obs) + '</dd></div>' : '') +
            '<div class="vdct-modal-dl__row"><dt>Criado em</dt><dd>' + escapeHtml(fmtDateTime(r.created_at)) + '</dd></div>' +
            '</dl>';
        abrirModal(body, '<button type="button" class="vdct-btn vdct-btn--secondary" data-modal-close>Fechar</button>');
    }

    function abrirModalEditar(session, r) {
        if (!r) return;
        const np = r.numero_pedido ? parseInt(r.numero_pedido, 10) : '';
        const sp = r.serie_pedido != null ? String(r.serie_pedido) : '0';
        const val = r.valor ? String(parseFloat(r.valor).toFixed(2)).replace('.', ',') : '';
        const ref = (r.ref_mes && r.ref_ano) ? String(r.ref_mes).padStart(2, '0') + '/' + r.ref_ano : '—';
        const body =
            '<p class="vdct-modal-hint">Altere os campos que deseja corrigir. Apenas solicitações <strong>pendentes</strong> podem ser editadas.</p>' +
            '<dl class="vdct-modal-dl vdct-modal-dl--form">' +
            '<div class="vdct-modal-dl__row"><dt><label for="vdCtEditNum">Nº do pedido</label></dt><dd><input class="vdct-field__input" id="vdCtEditNum" type="text" inputmode="numeric" value="' + escapeHtml(String(np)) + '" autocomplete="off"></dd></div>' +
            '<div class="vdct-modal-dl__row"><dt><label for="vdCtEditSer">Série</label></dt><dd><input class="vdct-field__input" id="vdCtEditSer" type="text" inputmode="numeric" value="' + escapeHtml(sp) + '" autocomplete="off"></dd></div>' +
            '<div class="vdct-modal-dl__row"><dt><label for="vdCtEditVal">Valor (R$)</label></dt><dd><input class="vdct-field__input" id="vdCtEditVal" type="text" inputmode="decimal" value="' + escapeHtml(val) + '" autocomplete="off"></dd></div>' +
            '<div class="vdct-modal-dl__row"><dt>Ref.</dt><dd>' + escapeHtml(ref) + '</dd></div>' +
            '<div class="vdct-modal-dl__row vdct-modal-dl__row--full"><dt><label for="vdCtEditMotivo">Motivo / detalhes</label></dt><dd><textarea class="vdct-field__input vdct-field__textarea" id="vdCtEditMotivo" rows="4">' + escapeHtml(r.motivo || '') + '</textarea></dd></div>' +
            '</dl>' +
            '<p class="vdct-modal-msg" id="vdCtEditMsg" style="display:none;"></p>';
        const footer =
            '<button type="button" class="vdct-btn vdct-btn--primary" id="vdCtEditSalvar"><i class="fas fa-floppy-disk"></i> Salvar</button>' +
            '<button type="button" class="vdct-btn vdct-btn--secondary" data-modal-close>Cancelar</button>';
        const close = abrirModal(body, footer);
        const btnSalvar = document.getElementById('vdCtEditSalvar');
        if (btnSalvar) {
            btnSalvar.addEventListener('click', async function () {
                const msgEl = document.getElementById('vdCtEditMsg');
                const numStr = String(document.getElementById('vdCtEditNum').value || '').replace(/\D+/g, '');
                const numeroPedido = parseInt(numStr, 10);
                const serStr = String(document.getElementById('vdCtEditSer').value || '').trim();
                const seriePedido = parseInt(serStr === '' ? '0' : serStr.replace(/\D+/g, ''), 10);
                const valorRaw = String(document.getElementById('vdCtEditVal').value || '').trim();
                const motivo = String(document.getElementById('vdCtEditMotivo').value || '').trim();
                if (!numStr || !numeroPedido || numeroPedido <= 0) {
                    msgEl.textContent = 'Informe o número do pedido.';
                    msgEl.className = 'vdct-modal-msg vdct-modal-msg--error';
                    msgEl.style.display = 'block';
                    return;
                }
                const payload = { id: Number(r.id), numero_pedido: numeroPedido, serie_pedido: isNaN(seriePedido) ? 0 : seriePedido, valor: valorRaw, motivo: motivo };
                const vp = adminVendedorApiParam(session);
                if (vp) payload.vendedor = vp;
                btnSalvar.disabled = true;
                const resp = await apiPost('vendedor_comissao_transfer_editar', payload);
                btnSalvar.disabled = false;
                if (!resp || resp.success === false) {
                    msgEl.textContent = (resp && resp.error) ? resp.error : 'Não foi possível salvar.';
                    msgEl.className = 'vdct-modal-msg vdct-modal-msg--error';
                    msgEl.style.display = 'block';
                    return;
                }
                if (close) close();
                await loadLista(session);
            });
        }
    }

    function preencherAnosMeses() {
        const mes = document.getElementById('vdCtRefMes');
        const ano = document.getElementById('vdCtRefAno');
        if (mes && !mes.options.length) {
            for (let m = 1; m <= 12; m++) {
                const opt = document.createElement('option');
                opt.value = String(m);
                opt.textContent = String(m).padStart(2, '0');
                mes.appendChild(opt);
            }
        }
        if (ano && !ano.options.length) {
            const y = new Date().getFullYear();
            for (let a = y + 1; a >= y - 3; a--) {
                const opt = document.createElement('option');
                opt.value = String(a);
                opt.textContent = String(a);
                ano.appendChild(opt);
            }
        }
        const now = new Date();
        if (mes) mes.value = String(now.getMonth() + 1);
        if (ano) ano.value = String(now.getFullYear());
    }

    function mostrarFormulario(show) {
        const grid = document.getElementById('vdCtFormGrid');
        const actions = document.getElementById('vdCtFormActions');
        if (grid) grid.style.display = show ? '' : 'none';
        if (actions) actions.style.display = show ? '' : 'none';
    }

    async function buscarPedido() {
        const numEl = document.getElementById('vdCtNumeroPedido');
        const serEl = document.getElementById('vdCtSeriePedido');
        const resultEl = document.getElementById('vdCtLookupResult');
        const btnBuscar = document.getElementById('vdCtBuscarPedido');
        if (!numEl || !resultEl) return;
        const np = String(numEl.value || '').replace(/\D+/g, '');
        const sp = String(serEl ? serEl.value : '0').replace(/\D+/g, '') || '0';
        if (!np) {
            resultEl.innerHTML = '<p class="vdct-lookup__msg vdct-lookup__msg--error"><i class="fas fa-circle-exclamation"></i> Informe o número do pedido.</p>';
            resultEl.style.display = 'block';
            mostrarFormulario(false);
            return;
        }
        resultEl.innerHTML = '<p class="vdct-lookup__msg"><i class="fas fa-spinner fa-spin"></i> Buscando pedido…</p>';
        resultEl.style.display = 'block';
        if (btnBuscar) btnBuscar.disabled = true;
        mostrarFormulario(false);
        const resp = await apiGet('vendedor_comissao_transfer_buscar_pedido', { numero: np, serie: sp });
        if (btnBuscar) btnBuscar.disabled = false;
        if (!resp || resp.success === false) {
            resultEl.innerHTML = '<p class="vdct-lookup__msg vdct-lookup__msg--error"><i class="fas fa-circle-exclamation"></i> ' + escapeHtml((resp && resp.error) || 'Pedido não encontrado.') + '</p>';
            return;
        }
        const rows = resp.rows || [];
        if (!rows.length) {
            resultEl.innerHTML = '<p class="vdct-lookup__msg vdct-lookup__msg--error"><i class="fas fa-circle-exclamation"></i> Pedido não encontrado na base.</p>';
            return;
        }
        // Usa a primeira linha como dados principais; se houver múltiplos mostra todos
        const r = rows[0];
        const fmtVal = function(v) { return 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR', {minimumFractionDigits:2,maximumFractionDigits:2}); };
        let html = '<div class="vdct-lookup__found"><div class="vdct-lookup__found-header"><i class="fas fa-circle-check"></i> Pedido encontrado</div><div class="vdct-lookup__found-grid">';
        html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Pedido / série</span><span class="vdct-lookup__found-value">' + escapeHtml(String(r.numero_pedido)) + ' / ' + escapeHtml(String(r.serie_pedido)) + '</span></div>';
        html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Cliente</span><span class="vdct-lookup__found-value">' + escapeHtml(r.cliente || '—') + '</span></div>';
        html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Prescritor</span><span class="vdct-lookup__found-value">' + escapeHtml(r.prescritor || '—') + '</span></div>';
        html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Orçamentista</span><span class="vdct-lookup__found-value">' + escapeHtml(r.orcamentista || '—') + '</span></div>';
        html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Aprovador</span><span class="vdct-lookup__found-value">' + escapeHtml(r.aprovador || '—') + '</span></div>';
        html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Valor total</span><span class="vdct-lookup__found-value vdct-lookup__found-value--bold">' + escapeHtml(fmtVal(r.valor_total)) + '</span></div>';
        html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Status</span><span class="vdct-lookup__found-value">' + escapeHtml(r.status_financeiro || '—') + '</span></div>';
        if (r.data_aprovacao) html += '<div class="vdct-lookup__found-item"><span class="vdct-lookup__found-label">Data</span><span class="vdct-lookup__found-value">' + escapeHtml(r.data_aprovacao) + '</span></div>';
        html += '</div></div>';
        resultEl.innerHTML = html;

        // Preenche campos ocultos e displays a partir dos dados do pedido
        const valEl = document.getElementById('vdCtValor');
        const valDisplay = document.getElementById('vdCtValorDisplay');
        const mesEl = document.getElementById('vdCtRefMes');
        const mesDisplay = document.getElementById('vdCtRefMesDisplay');
        const anoEl = document.getElementById('vdCtRefAno');
        const anoDisplay = document.getElementById('vdCtRefAnoDisplay');

        const valorFmt = parseFloat(r.valor_total || 0).toFixed(2).replace('.', ',');
        if (valEl) valEl.value = valorFmt;
        if (valDisplay) valDisplay.textContent = 'R$ ' + valorFmt;

        // Pré-seleciona o aprovador no dropdown de consultora de origem
        const sel = document.getElementById('vdCtContraparte');
        if (sel && r.aprovador) {
            const aprovNorm = String(r.aprovador).trim().toLowerCase();
            let matched = false;
            for (let i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value.trim().toLowerCase() === aprovNorm) {
                    sel.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            // Se não achou correspondência exata, tenta parcial
            if (!matched) {
                for (let i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value.trim().toLowerCase().includes(aprovNorm) ||
                        aprovNorm.includes(sel.options[i].value.trim().toLowerCase())) {
                        sel.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        // Mês e ano vêm da data_aprovacao do pedido (YYYY-MM-DD)
        let mes = 0, ano = 0;
        if (r.data_aprovacao) {
            const parts = String(r.data_aprovacao).split('-');
            ano = parseInt(parts[0], 10) || 0;
            mes = parseInt(parts[1], 10) || 0;
        }
        // Fallback: mês/ano atual
        if (!mes || !ano) { const now = new Date(); mes = now.getMonth() + 1; ano = now.getFullYear(); }
        if (mesEl) mesEl.value = String(mes);
        if (mesDisplay) mesDisplay.textContent = String(mes).padStart(2, '0');
        if (anoEl) anoEl.value = String(ano);
        if (anoDisplay) anoDisplay.textContent = String(ano);

        mostrarFormulario(true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('vdCtRoot');
        if (!root) return;

        let sessionRef = null;

        async function getSession() {
            // Espera até 3s pelo vendedor-main.js expor a sessão; evita segundo check_session
            for (let i = 0; i < 30; i++) {
                if (window.vdSession) return window.vdSession;
                await new Promise(function(r) { setTimeout(r, 100); });
            }
            return await apiGet('check_session');
        }

        async function refresh() {
            const sess = await getSession();
            if (!sess || !sess.logged_in) {
                window.location.href = 'index.html';
                return;
            }
            const setor = String(sess.setor || '').trim().toLowerCase();
            const tipo = String(sess.tipo || '').trim().toLowerCase();
            if (setor.indexOf('vendedor') === -1 && tipo !== 'admin') {
                window.location.href = 'index.html';
                return;
            }
            sessionRef = sess;
            // Carrega opções e lista em paralelo
            await Promise.all([loadOpcoes(sess), loadLista(sess)]);
        }

        const btnBuscar = document.getElementById('vdCtBuscarPedido');
        if (btnBuscar) {
            btnBuscar.addEventListener('click', function () { buscarPedido().catch(function(){}); });
        }
        // Busca também ao pressionar Enter no campo número
        const numEl = document.getElementById('vdCtNumeroPedido');
        if (numEl) {
            numEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); buscarPedido().catch(function(){}); }
            });
        }
        // Ao mudar número/série, oculta resultado anterior
        [numEl, document.getElementById('vdCtSeriePedido')].forEach(function(el) {
            if (!el) return;
            el.addEventListener('input', function() {
                const r = document.getElementById('vdCtLookupResult');
                if (r) r.style.display = 'none';
                mostrarFormulario(false);
            });
        });

        const btnLimpar = document.getElementById('vdCtLimparBusca');
        if (btnLimpar) {
            btnLimpar.addEventListener('click', function () {
                if (numEl) numEl.value = '';
                const serEl = document.getElementById('vdCtSeriePedido');
                if (serEl) serEl.value = '0';
                const resultEl = document.getElementById('vdCtLookupResult');
                if (resultEl) { resultEl.innerHTML = ''; resultEl.style.display = 'none'; }
                mostrarFormulario(false);
                const msgEl = document.getElementById('vdCtFormMsg');
                if (msgEl) { msgEl.style.display = 'none'; }
                if (numEl) numEl.focus();
            });
        }

        const btn = document.getElementById('vdCtEnviar');
        if (btn) {
            btn.addEventListener('click', function () {
                if (sessionRef) enviarSolicitacao(sessionRef).catch(function () {});
            });
        }
        const btnAtual = document.getElementById('vdCtAtualizar');
        if (btnAtual) {
            btnAtual.addEventListener('click', function () {
                refresh().catch(function () {});
            });
        }

        refresh().catch(function () {});
    });
})();
