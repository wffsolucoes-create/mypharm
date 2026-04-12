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
        const a = per.data_de || '';
        const b = per.data_ate || '';
        if (a && b) {
            el.textContent = a.slice(8, 10) + '/' + a.slice(5, 7) + '/' + a.slice(0, 4) + ' — ' + b.slice(8, 10) + '/' + b.slice(5, 7) + '/' + b.slice(0, 4);
        } else {
            el.textContent = '—';
        }
    }

    async function loadLista(session) {
        const tbody = document.getElementById('vdRevTbody');
        const msgEl = document.getElementById('vdRevMsg');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="vdct-table__empty">Carregando…</td></tr>';
        setMsg(msgEl, '', '');
        const vendParam = adminVendedorApiParam(session);
        const q = periodoQueryFromForm();
        const params = Object.assign({}, q, vendParam ? { vendedor: vendParam } : {});
        const data = await apiGet('vendedor_revenda_lista', params);
        if (!data || data.success !== true) {
            tbody.innerHTML =
                '<tr><td colspan="6" class="vdct-table__empty">' +
                escapeHtml((data && data.error) || 'Não foi possível carregar.') +
                '</td></tr>';
            return;
        }
        atualizarBadgePeriodo(data.periodo);
        const rows = Array.isArray(data.rows) ? data.rows : [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="vdct-table__empty">Nenhum lançamento no período.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map(function (r) {
                const ativo = Number(r.ativo) === 1;
                const st = ativo
                    ? '<span class="vdct-status vdct-status--pendente">Ativo</span>'
                    : '<span class="vdct-status vdct-status--cancelada">Cancelado</span>';
                const btn =
                    '<button type="button" class="vdct-btn vdct-btn--secondary vd-rev-cancel" data-id="' +
                    escapeHtml(String(r.id)) +
                    '"' +
                    (ativo ? '' : ' disabled') +
                    '>Cancelar</button>';
                return (
                    '<tr><td class="vdct-td-id">' +
                    escapeHtml(String(r.id)) +
                    '</td><td>' +
                    escapeHtml(String(r.data_venda || '').slice(0, 10)) +
                    '</td><td class="vdct-td-valor">' +
                    escapeHtml(formatMoney(r.valor_liquido)) +
                    '</td><td class="vdct-td-motivo">' +
                    escapeHtml(r.descricao) +
                    '</td><td>' +
                    st +
                    '</td><td class="vdct-td-acoes">' +
                    btn +
                    '</td></tr>'
                );
            })
            .join('');
        tbody.querySelectorAll('.vd-rev-cancel').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const id = parseInt(btn.getAttribute('data-id') || '0', 10);
                if (!id || !session) return;
                if (!window.confirm('Cancelar este lançamento? Ele deixa de entrar na sua receita.')) return;
                const body = { id: id };
                if (adminVendedorApiParam(session)) body.vendedor = adminVendedorApiParam(session);
                const resp = await apiPost('vendedor_revenda_cancelar', body);
                if (!resp || resp.success !== true) {
                    setMsg(msgEl, 'err', (resp && resp.error) || 'Não foi possível cancelar.');
                    return;
                }
                setMsg(msgEl, 'ok', 'Cancelamento registrado.');
                await loadLista(session);
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
            await loadLista(sess);
        }

        const btnEnv = document.getElementById('vdRevEnviar');
        if (btnEnv) {
            btnEnv.addEventListener('click', async function () {
                const msgEl = document.getElementById('vdRevMsg');
                if (!sessionRef) return;
                const dvenda = (document.getElementById('vdRevDataVenda') || {}).value || '';
                const valor = parseValor((document.getElementById('vdRevValor') || {}).value || '');
                const desc = (document.getElementById('vdRevDesc') || {}).value || '';
                if (!/^\d{4}-\d{2}-\d{2}$/.test(dvenda)) {
                    setMsg(msgEl, 'err', 'Informe a data da venda.');
                    return;
                }
                if (!Number.isFinite(valor) || valor <= 0) {
                    setMsg(msgEl, 'err', 'Informe um valor válido.');
                    return;
                }
                const body = { data_venda: dvenda, valor_liquido: valor, descricao: desc };
                const vp = adminVendedorApiParam(sessionRef);
                if (vp) body.vendedor = vp;
                setMsg(msgEl, '', '');
                const resp = await apiPost('vendedor_revenda_lancar', body);
                if (!resp || resp.success !== true) {
                    setMsg(msgEl, 'err', (resp && resp.error) || 'Não foi possível salvar.');
                    return;
                }
                setMsg(msgEl, 'ok', 'Lançamento registrado.');
                const vInp = document.getElementById('vdRevValor');
                if (vInp) vInp.value = '';
                const dInp = document.getElementById('vdRevDesc');
                if (dInp) dInp.value = '';
                await loadLista(sessionRef);
            });
        }

        const btnAtu = document.getElementById('vdRevAtualizar');
        if (btnAtu) {
            btnAtu.addEventListener('click', function () {
                refresh().catch(function () {});
            });
        }

        [de, ate].forEach(function (el) {
            if (!el) return;
            el.addEventListener('change', function () {
                if (sessionRef) loadLista(sessionRef).catch(function () {});
            });
        });

        refresh().catch(function () {});
    });
})();
