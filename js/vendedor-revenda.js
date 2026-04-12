(function () {
    const API_URL = 'api_gestao.php';

    function forcedVendedoraFromUrl() {
        try {
            const qs = new URLSearchParams(window.location.search || '');
            return String(qs.get('vendedora') || qs.get('vendedor') || '').trim();
        } catch (_) { return ''; }
    }

    function adminVendedorApiParam(session) {
        const tipo = String((session && session.tipo) || '').trim().toLowerCase();
        const forced = forcedVendedoraFromUrl();
        return tipo === 'admin' && forced ? forced : '';
    }

    function currentMonthRange() {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        return { data_de: `${yyyy}-${mm}-01`, data_ate: `${yyyy}-${mm}-${dd}` };
    }

    async function apiGet(action, params) {
        const query = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'include' });
        const text = await res.text();
        try { return text ? JSON.parse(text) : {}; }
        catch (_) { return { success: false, error: `Erro ${res.status}` }; }
    }

    async function apiPost(action, data) {
        const res = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data || {})
        });
        const text = await res.text();
        try { return text ? JSON.parse(text) : {}; }
        catch (_) { return { success: false, error: `Erro ${res.status}` }; }
    }

    function formatMoney(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function escapeHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function setMsg(el, type, text) {
        if (!el) return;
        el.textContent = text || '';
        el.style.display = text ? 'block' : 'none';
        el.className = 'vdct-msg' + (type === 'err' ? ' vdct-msg--error' : type === 'ok' ? ' vdct-msg--ok' : '');
    }

    function parseValor(raw) {
        const s = String(raw || '').trim();
        if (!s) return NaN;
        const norm = s.replace(/\s/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
        const n = parseFloat(norm);
        return Number.isFinite(n) ? n : NaN;
    }

    function periodoQueryFromForm() {
        const de = (document.getElementById('vdRevDataDe') || {}).value || '';
        const ate = (document.getElementById('vdRevDataAte') || {}).value || '';
        const p = {};
        if (/^\d{4}-\d{2}-\d{2}$/.test(de)) p.data_de = de;
        if (/^\d{4}-\d{2}-\d{2}$/.test(ate)) p.data_ate = ate;
        return p;
    }

    function atualizarBadgePeriodo(per) {
        const el = document.getElementById('vdRevPeriodoInfo');
        if (!el || !per) return;
        const a = per.data_de || '', b = per.data_ate || '';
        if (a && b) {
            el.textContent = a.slice(8,10)+'/'+a.slice(5,7)+'/'+a.slice(0,4)+' — '+b.slice(8,10)+'/'+b.slice(5,7)+'/'+b.slice(0,4);
        } else { el.textContent = '—'; }
    }

    // ── Modal de detalhes / edição ─────────────────────────────────────────
    function getOrCreateModal() {
        let m = document.getElementById('vdRevModal');
        if (m) return m;
        m = document.createElement('div');
        m.id = 'vdRevModal';
        m.className = 'gc-modal-det-ped';
        m.setAttribute('aria-hidden', 'true');
        m.innerHTML = `
            <div class="gc-modal-det-ped__backdrop" id="vdRevModalBackdrop"></div>
            <div class="gc-modal-det-ped__card" role="dialog" aria-modal="true" aria-labelledby="vdRevModalTitle">
                <div class="gc-modal-det-ped__head">
                    <h3 class="gc-modal-det-ped__title" id="vdRevModalTitle"><i class="fas fa-store"></i> <span id="vdRevModalTitleText">Detalhes do lançamento</span></h3>
                    <button type="button" class="gc-modal-det-ped__close" id="vdRevModalClose" aria-label="Fechar">&times;</button>
                </div>
                <div class="gc-modal-det-ped__body" id="vdRevModalBody"></div>
                <div class="gc-modal-det-ped__foot" id="vdRevModalFoot"></div>
            </div>`;
        document.body.appendChild(m);
        m.querySelector('#vdRevModalBackdrop').addEventListener('click', closeRevModal);
        m.querySelector('#vdRevModalClose').addEventListener('click', closeRevModal);
        return m;
    }

    function openRevModal() {
        const m = getOrCreateModal();
        m.classList.add('is-open');
        m.setAttribute('aria-hidden', 'false');
    }

    function closeRevModal() {
        const m = document.getElementById('vdRevModal');
        if (m) { m.classList.remove('is-open'); m.setAttribute('aria-hidden', 'true'); }
    }

    function fmtDate(d) {
        const s = String(d || '').slice(0, 10);
        if (!s) return '—';
        return s.slice(8,10)+'/'+s.slice(5,7)+'/'+s.slice(0,4);
    }

    function abrirModalVer(r) {
        getOrCreateModal();
        document.getElementById('vdRevModalTitleText').textContent = 'Detalhes do lançamento #' + r.id;
        const stLabels = { pendente:'Aguardando aprovação', aprovada:'Aprovada', recusada:'Recusada', cancelada:'Cancelada' };
        const stKey = String(r.status || 'pendente').toLowerCase();
        const body = document.getElementById('vdRevModalBody');
        body.innerHTML = `
            <div class="gc-modal-det-ped__fields-wrap">
                <div class="gc-modal-det-ped__fields">
                    <div><span style="font-size:0.7rem;color:var(--text-secondary);">ID</span><div style="font-weight:600;">${escapeHtml(String(r.id))}</div></div>
                    <div><span style="font-size:0.7rem;color:var(--text-secondary);">Data da venda</span><div style="font-weight:600;">${fmtDate(r.data_venda)}</div></div>
                    <div><span style="font-size:0.7rem;color:var(--text-secondary);">Nº Pedido</span><div style="font-weight:600;">${escapeHtml(r.numero_pedido || '—')}</div></div>
                    <div><span style="font-size:0.7rem;color:var(--text-secondary);">Série</span><div style="font-weight:600;">${escapeHtml(r.serie_pedido || '—')}</div></div>
                    <div><span style="font-size:0.7rem;color:var(--text-secondary);">Valor</span><div style="font-weight:600;color:var(--success);">${escapeHtml(formatMoney(r.valor_liquido))}</div></div>
                    <div><span style="font-size:0.7rem;color:var(--text-secondary);">Status</span><div style="font-weight:600;">${escapeHtml(stLabels[stKey] || stKey)}</div></div>
                    <div style="grid-column:1/-1;"><span style="font-size:0.7rem;color:var(--text-secondary);">Produto / Descrição</span><div style="font-weight:600;">${escapeHtml(r.produto || '—')}</div></div>
                    <div style="grid-column:1/-1;"><span style="font-size:0.7rem;color:var(--text-secondary);">Observações</span><div>${escapeHtml(r.descricao || '—')}</div></div>
                    ${r.observacao_gestao ? `<div style="grid-column:1/-1;"><span style="font-size:0.7rem;color:var(--text-secondary);">Observação da gestão</span><div style="color:var(--warning,#d97706);">${escapeHtml(r.observacao_gestao)}</div></div>` : ''}
                </div>
            </div>`;
        document.getElementById('vdRevModalFoot').innerHTML =
            '<button type="button" class="gc-filter-btn gc-filter-btn--secondary" id="vdRevModalFechar">Fechar</button>';
        document.getElementById('vdRevModalFechar').addEventListener('click', closeRevModal);
        openRevModal();
    }

    function abrirModalEditar(r, session, onSaved) {
        getOrCreateModal();
        document.getElementById('vdRevModalTitleText').textContent = 'Editar lançamento #' + r.id;
        const body = document.getElementById('vdRevModalBody');
        body.innerHTML = `
            <div class="gc-modal-det-ped__fields-wrap" style="padding-bottom:8px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <label class="vdct-field__label" style="display:flex;flex-direction:column;gap:6px;">Data da venda
                        <input class="vdct-field__input" type="date" id="vdRevEditData" value="${escapeHtml(String(r.data_venda||'').slice(0,10))}">
                    </label>
                    <label class="vdct-field__label" style="display:flex;flex-direction:column;gap:6px;">Valor (R$)
                        <input class="vdct-field__input" type="text" id="vdRevEditValor" value="${escapeHtml(String(Number(r.valor_liquido||0).toFixed(2)).replace('.',','))}" inputmode="decimal">
                    </label>
                    <label class="vdct-field__label" style="display:flex;flex-direction:column;gap:6px;">Nº Pedido
                        <input class="vdct-field__input" type="text" id="vdRevEditNped" value="${escapeHtml(r.numero_pedido||'')}" maxlength="40">
                    </label>
                    <label class="vdct-field__label" style="display:flex;flex-direction:column;gap:6px;">Série
                        <input class="vdct-field__input" type="text" id="vdRevEditSped" value="${escapeHtml(r.serie_pedido||'')}" maxlength="20">
                    </label>
                    <label class="vdct-field__label" style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;">Produto / Descrição *
                        <input class="vdct-field__input" type="text" id="vdRevEditProduto" value="${escapeHtml(r.produto||'')}" maxlength="500">
                    </label>
                    <label class="vdct-field__label" style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;">Observações
                        <input class="vdct-field__input" type="text" id="vdRevEditDesc" value="${escapeHtml(r.descricao||'')}" maxlength="500">
                    </label>
                </div>
                <div id="vdRevEditMsg" class="vdct-msg" style="display:none;margin-top:10px;"></div>
            </div>`;
        const foot = document.getElementById('vdRevModalFoot');
        foot.innerHTML =
            '<button type="button" class="gc-filter-btn gc-filter-btn--secondary" id="vdRevEditCancelar">Cancelar</button>' +
            '<button type="button" class="gc-filter-btn" style="background:var(--primary,#E63946);color:#fff;border-color:var(--primary,#E63946);" id="vdRevEditSalvar"><i class="fas fa-check"></i> Salvar</button>';
        document.getElementById('vdRevEditCancelar').addEventListener('click', closeRevModal);
        document.getElementById('vdRevEditSalvar').addEventListener('click', async function () {
            const msgEl = document.getElementById('vdRevEditMsg');
            const dvenda = (document.getElementById('vdRevEditData')||{}).value||'';
            const valor = parseValor((document.getElementById('vdRevEditValor')||{}).value||'');
            const produto = ((document.getElementById('vdRevEditProduto')||{}).value||'').trim();
            const desc = ((document.getElementById('vdRevEditDesc')||{}).value||'').trim();
            const nped = ((document.getElementById('vdRevEditNped')||{}).value||'').trim();
            const sped = ((document.getElementById('vdRevEditSped')||{}).value||'').trim();
            if (!/^\d{4}-\d{2}-\d{2}$/.test(dvenda)) { setMsg(msgEl,'err','Informe a data.'); return; }
            if (!Number.isFinite(valor)||valor<=0) { setMsg(msgEl,'err','Valor inválido.'); return; }
            if (!produto) { setMsg(msgEl,'err','Informe o produto.'); return; }
            const payload = { id: r.id, data_venda: dvenda, valor_liquido: valor, produto, descricao: desc, numero_pedido: nped, serie_pedido: sped };
            const vp = adminVendedorApiParam(session);
            if (vp) payload.vendedor = vp;
            setMsg(msgEl,'','');
            const resp = await apiPost('vendedor_revenda_editar', payload);
            if (!resp||resp.success!==true) { setMsg(msgEl,'err',(resp&&resp.error)||'Não foi possível salvar.'); return; }
            showSuccess('Lançamento atualizado.');
            closeRevModal();
            if (typeof onSaved === 'function') onSaved();
        });
        openRevModal();
    }

    // ── Renderização da lista ──────────────────────────────────────────────
    async function loadLista(session) {
        const tbody = document.getElementById('vdRevTbody');
        const msgEl = document.getElementById('vdRevMsg');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="9" class="vdct-table__empty">Carregando…</td></tr>';
        setMsg(msgEl, '', '');
        const vendParam = adminVendedorApiParam(session);
        const q = periodoQueryFromForm();
        const params = Object.assign({}, q, vendParam ? { vendedor: vendParam } : {});
        const data = await apiGet('vendedor_revenda_lista', params);
        if (!data || data.success !== true) {
            tbody.innerHTML = '<tr><td colspan="9" class="vdct-table__empty">' + escapeHtml((data&&data.error)||'Não foi possível carregar.') + '</td></tr>';
            return;
        }
        atualizarBadgePeriodo(data.periodo);
        const rows = Array.isArray(data.rows) ? data.rows : [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="vdct-table__empty">Nenhum lançamento no período.</td></tr>';
            return;
        }
        const statusMap = {
            pendente:  '<span class="vdct-status vdct-status--pendente">Aguardando</span>',
            aprovada:  '<span class="vdct-status vdct-status--aprovada">Aprovada</span>',
            recusada:  '<span class="vdct-status vdct-status--recusada">Recusada</span>',
            cancelada: '<span class="vdct-status vdct-status--cancelada">Cancelada</span>',
        };
        tbody.innerHTML = rows.map(function (r) {
            const stKey = String(r.status || (Number(r.ativo)===1?'aprovada':'cancelada')).toLowerCase();
            const stBadge = statusMap[stKey] || stKey;
            const canEdit = stKey === 'pendente';
            const id = escapeHtml(String(r.id));
            const btnVer    = '<button type="button" class="vdct-icon-btn vd-rev-ver" data-id="' + id + '" title="Ver detalhes"><i class="fas fa-eye"></i></button>';
            const btnEdit   = canEdit ? '<button type="button" class="vdct-icon-btn vdct-icon-btn--edit vd-rev-edit" data-id="' + id + '" title="Editar lançamento"><i class="fas fa-pen"></i></button>' : '';
            const btnCancel = canEdit ? '<button type="button" class="vdct-icon-btn vdct-icon-btn--cancel vd-rev-cancel" data-id="' + id + '" title="Cancelar lançamento"><i class="fas fa-times"></i></button>' : '';
            const obsCell = r.observacao_gestao
                ? '<span title="'+escapeHtml(r.observacao_gestao)+'" style="cursor:help;color:var(--warning,#d97706);font-size:0.8rem;"><i class="fas fa-comment-alt" style="margin-right:3px;"></i>'+escapeHtml(String(r.observacao_gestao).slice(0,30))+(r.observacao_gestao.length>30?'…':'')+'</span>'
                : escapeHtml(r.descricao || '—');
            return (
                '<tr>' +
                '<td class="vdct-td-id">' + id + '</td>' +
                '<td>' + escapeHtml(String(r.data_venda||'').slice(0,10)) + '</td>' +
                '<td>' + escapeHtml(r.numero_pedido||'—') + '</td>' +
                '<td>' + escapeHtml(r.serie_pedido||'—') + '</td>' +
                '<td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(r.produto||'—') + '</td>' +
                '<td class="vdct-td-valor">' + escapeHtml(formatMoney(r.valor_liquido)) + '</td>' +
                '<td class="vdct-td-motivo">' + obsCell + '</td>' +
                '<td>' + stBadge + '</td>' +
                '<td class="vdct-td-acoes"><div class="vdct-acoes-btns">' + btnVer + btnEdit + btnCancel + '</div></td>' +
                '</tr>'
            );
        }).join('');

        // Bind actions usando os dados originais
        tbody.querySelectorAll('[class*="vd-rev-"]').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const id = parseInt(btn.getAttribute('data-id')||'0', 10);
                const row = rows.find(function(r){ return Number(r.id)===id; });
                if (!row) return;
                if (btn.classList.contains('vd-rev-ver')) {
                    abrirModalVer(row);
                } else if (btn.classList.contains('vd-rev-edit')) {
                    abrirModalEditar(row, session, function(){ loadLista(session).catch(function(){}); });
                } else if (btn.classList.contains('vd-rev-cancel')) {
                    const ok = await showConfirm({
                        kind: 'danger', title: 'Cancelar lançamento',
                        message: 'Cancelar este lançamento pendente? Ele não poderá mais ser aprovado.',
                        confirmText: 'Cancelar lançamento', cancelText: 'Voltar', destructive: true
                    });
                    if (!ok) return;
                    const body = { id: id };
                    if (adminVendedorApiParam(session)) body.vendedor = adminVendedorApiParam(session);
                    const resp = await apiPost('vendedor_revenda_cancelar', body);
                    if (!resp||resp.success!==true) { showError((resp&&resp.error)||'Não foi possível cancelar.'); return; }
                    showSuccess('Lançamento cancelado.');
                    await loadLista(session);
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('vdRevRoot');
        if (!root) return;

        const de = document.getElementById('vdRevDataDe');
        const ate = document.getElementById('vdRevDataAte');
        const pad = currentMonthRange();
        if (de && !de.value) de.value = pad.data_de;
        if (ate && !ate.value) ate.value = pad.data_ate;

        const dv = document.getElementById('vdRevDataVenda');
        if (dv && !dv.value) dv.value = new Date().toISOString().slice(0, 10);

        let sessionRef = null;

        async function getSession() {
            for (let i = 0; i < 30; i++) {
                if (window.vdSession) return window.vdSession;
                await new Promise(function(r){ setTimeout(r, 100); });
            }
            return await apiGet('check_session');
        }

        async function refresh() {
            const sess = await getSession();
            if (!sess || !sess.logged_in) { window.location.href = 'index.html'; return; }
            const setor = String(sess.setor||'').trim().toLowerCase();
            const tipo  = String(sess.tipo||'').trim().toLowerCase();
            if (setor.indexOf('vendedor') === -1 && tipo !== 'admin') { window.location.href = 'index.html'; return; }
            sessionRef = sess;
            await loadLista(sess);
        }

        const btnEnv = document.getElementById('vdRevEnviar');
        if (btnEnv) {
            btnEnv.addEventListener('click', async function () {
                const msgEl = document.getElementById('vdRevMsg');
                if (!sessionRef) return;
                const dvenda  = (document.getElementById('vdRevDataVenda')||{}).value||'';
                const valor   = parseValor((document.getElementById('vdRevValor')||{}).value||'');
                const produto = ((document.getElementById('vdRevProduto')||{}).value||'').trim();
                const desc    = ((document.getElementById('vdRevDesc')||{}).value||'').trim();
                const nped    = ((document.getElementById('vdRevNumeroPedido')||{}).value||'').trim();
                const sped    = ((document.getElementById('vdRevSeriePedido')||{}).value||'').trim();
                if (!/^\d{4}-\d{2}-\d{2}$/.test(dvenda)) { setMsg(msgEl,'err','Informe a data da venda.'); return; }
                if (!Number.isFinite(valor)||valor<=0) { setMsg(msgEl,'err','Informe um valor válido.'); return; }
                if (!produto) { setMsg(msgEl,'err','Informe o produto / descrição.'); return; }
                const body = { data_venda: dvenda, valor_liquido: valor, produto, descricao: desc, numero_pedido: nped, serie_pedido: sped };
                const vp = adminVendedorApiParam(sessionRef);
                if (vp) body.vendedor = vp;
                setMsg(msgEl,'','');
                const resp = await apiPost('vendedor_revenda_lancar', body);
                if (!resp||resp.success!==true) { setMsg(msgEl,'err',(resp&&resp.error)||'Não foi possível salvar.'); return; }
                setMsg(msgEl,'ok','Lançamento registrado.');
                ['vdRevValor','vdRevProduto','vdRevDesc','vdRevNumeroPedido','vdRevSeriePedido'].forEach(function(id){
                    var el = document.getElementById(id); if (el) el.value = '';
                });
                await loadLista(sessionRef);
            });
        }

        const btnAtu = document.getElementById('vdRevAtualizar');
        if (btnAtu) { btnAtu.addEventListener('click', function(){ refresh().catch(function(){}); }); }

        [de, ate].forEach(function (el) {
            if (!el) return;
            el.addEventListener('change', function(){ if (sessionRef) loadLista(sessionRef).catch(function(){}); });
        });

        refresh().catch(function(){});
    });
})();
