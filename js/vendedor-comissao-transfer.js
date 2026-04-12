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
        sel.innerHTML = '<option value="">Selecione a consultora de origem…</option>';
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
                ? '<span style="color:var(--success); font-weight:600;">Você recebe (beneficiária)</span>'
                : '<span style="color:var(--warning, #d97706); font-weight:600;">Você é a origem indicada</span>';
            const outra = souSol ? escapeHtml(con) : escapeHtml(sol);
            const st = String(r.status || '').toLowerCase();
            let acao = '—';
            if (souSol && st === 'pendente' && id) {
                acao = '<button type="button" class="vdct-btn vdct-btn--cancel" data-id="' + id + '"><i class="fas fa-times"></i> Cancelar</button>';
            }
            const ref = (r.ref_mes && r.ref_ano) ? (String(r.ref_mes).padStart(2, '0') + '/' + r.ref_ano) : '—';
            const obs = String(r.observacao_gestao || '').trim();
            return (
                '<tr>' +
                '<td class="vdct-td-date">' + fmtDateTime(r.created_at) + '</td>' +
                '<td>' + papel + '</td>' +
                '<td>' + outra + '</td>' +
                '<td class="vdct-td-valor">' + escapeHtml(formatMoney(r.valor)) + '</td>' +
                '<td class="vdct-td-pedido">' + formatPedidoSerieCell(r) + '</td>' +
                '<td class="vdct-td-ref">' + escapeHtml(ref) + '</td>' +
                '<td>' + statusLabel(r.status) + '</td>' +
                '<td class="vdct-td-motivo">' + escapeHtml(String(r.motivo || '').slice(0, 120)) + (String(r.motivo || '').length > 120 ? '…' : '') + '</td>' +
                '<td class="vdct-td-obs">' + escapeHtml(obs ? obs.slice(0, 80) + (obs.length > 80 ? '…' : '') : '—') + '</td>' +
                '<td class="vdct-td-acoes">' + acao + '</td>' +
                '</tr>'
            );
        }).join('');

        tbody.querySelectorAll('.vdct-btn--cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const rid = Number(btn.getAttribute('data-id') || 0);
                cancelarSolicitacao(session, rid).catch(function () {});
            });
        });
    }

    async function cancelarSolicitacao(session, id) {
        if (!id || !confirm('Cancelar esta solicitação pendente?')) return;
        const payload = { id: id };
        const vp = adminVendedorApiParam(session);
        if (vp) payload.vendedor = vp;
        const resp = await apiPost('vendedor_comissao_transfer_cancelar', payload);
        if (!resp || resp.success === false || !resp.updated) {
            alert((resp && resp.error) ? resp.error : 'Não foi possível cancelar (só solicitações pendentes criadas por você).');
            return;
        }
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
        await loadLista(session);
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

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('vdCtRoot');
        if (!root) return;

        preencherAnosMeses();

        let sessionRef = null;

        async function refresh() {
            const sess = await apiGet('check_session');
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
            await loadOpcoes(sess);
            await loadLista(sess);
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
