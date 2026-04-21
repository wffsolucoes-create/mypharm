(function () {
    const API_URL = 'api_gestao.php';
    let viewType = 'clientes';
    let lastPayload = null;

    function formatMoney(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function formatInt(v) {
        return Number(v || 0).toLocaleString('pt-BR');
    }

    function fmtDateBr(iso) {
        const s = String(iso || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return s || '-';
        const p = s.split('-');
        return `${p[2]}/${p[1]}/${p[0]}`;
    }

    function escapeHtml(v) {
        const d = document.createElement('div');
        d.textContent = String(v == null ? '' : v);
        return d.innerHTML;
    }

    async function apiGet(action, params) {
        const query = new URLSearchParams(Object.assign({ action: action }, params || {}));
        const res = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'include' });
        const text = await res.text();
        try { return text ? JSON.parse(text) : {}; } catch (_) { return { success: false, error: `Erro ${res.status}` }; }
    }

    function currentRange() {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        return { data_de: `${yyyy}-${mm}-01`, data_ate: `${yyyy}-${mm}-${dd}` };
    }

    function setDefaultDates() {
        const r = currentRange();
        const de = document.getElementById('rejDataDe');
        const ate = document.getElementById('rejDataAte');
        if (de && !de.value) de.value = r.data_de;
        if (ate && !ate.value) ate.value = r.data_ate;
    }

    function setSummary(data) {
        const resumo = data && data.resumo ? data.resumo : {};
        const periodo = data && data.periodo ? data.periodo : {};
        const topMotivo = Array.isArray(resumo.top_motivos) && resumo.top_motivos.length ? resumo.top_motivos[0].motivo : '—';
        const periodTxt = periodo.data_de ? `${fmtDateBr(periodo.data_de)} a ${fmtDateBr(periodo.data_ate)}` : '—';

        const map = {
            rejTotalRej: formatInt(resumo.total_rejeicoes || 0),
            rejTotalValor: formatMoney(resumo.total_valor_rejeitado || 0),
            rejTotalContato: formatInt(resumo.total_com_contato || 0),
            rejTopMotivo: topMotivo,
            rejPeriodo: periodTxt,
            rejUpdatedAt: data && data.updated_at ? data.updated_at : '—'
        };
        Object.keys(map).forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.textContent = map[id];
        });
    }

    function filteredRows(rows) {
        const q = String((document.getElementById('rejSearch') || {}).value || '').trim().toLowerCase();
        if (!q) return rows;
        return rows.filter(function (r) {
            return Object.keys(r || {}).some(function (k) {
                return String(r[k] == null ? '' : r[k]).toLowerCase().indexOf(q) >= 0;
            });
        });
    }

    function renderTable(data) {
        const body = document.getElementById('rejTbody');
        if (!body) return;
        const rowsSrc = viewType === 'prescritores'
            ? (Array.isArray(data.por_prescritor) ? data.por_prescritor : [])
            : (Array.isArray(data.por_cliente) ? data.por_cliente : []);
        const rows = filteredRows(rowsSrc);
        if (!rows.length) {
            body.innerHTML = `<tr><td colspan="${viewType === 'prescritores' ? 8 : 8}" class="rej-empty">Sem registros no período.</td></tr>`;
            return;
        }

        if (viewType === 'prescritores') {
            body.innerHTML = rows.map(function (r) {
                return `<tr>
                    <td>${escapeHtml(r.prescritor || '-')}</td>
                    <td>${escapeHtml(r.contato || 'Sem contato')}</td>
                    <td>${formatInt(r.qtd_rejeicoes || 0)}</td>
                    <td>${formatMoney(r.valor_rejeitado || 0)}</td>
                    <td>${formatInt(r.clientes_unicos || 0)}</td>
                    <td>${escapeHtml(r.motivo_principal || '-')}</td>
                    <td>${escapeHtml(fmtDateBr(r.ultima_perda || ''))}</td>
                    <td>${escapeHtml(r.atendentes || '-')}</td>
                </tr>`;
            }).join('');
            return;
        }

        body.innerHTML = rows.map(function (r) {
            return `<tr>
                <td>${escapeHtml(r.cliente || '-')}</td>
                <td>${escapeHtml(r.prescritor || '-')}</td>
                <td>${escapeHtml(r.contato || 'Sem contato')}</td>
                <td>${formatInt(r.qtd_rejeicoes || 0)}</td>
                <td>${formatMoney(r.valor_rejeitado || 0)}</td>
                <td>${escapeHtml(r.motivo_principal || '-')}</td>
                <td>${escapeHtml(fmtDateBr(r.ultima_perda || ''))}</td>
                <td>${escapeHtml(r.atendente || '-')}</td>
            </tr>`;
        }).join('');
    }

    async function loadData() {
        const de = (document.getElementById('rejDataDe') || {}).value || '';
        const ate = (document.getElementById('rejDataAte') || {}).value || '';
        const btn = document.getElementById('rejApplyBtn');
        if (btn) btn.disabled = true;
        const data = await apiGet('gestao_rejeitados_rd', { data_de: de, data_ate: ate });
        if (btn) btn.disabled = false;
        if (!data || data.success === false) {
            const body = document.getElementById('rejTbody');
            if (body) body.innerHTML = `<tr><td colspan="8" class="rej-empty">${escapeHtml((data && data.error) || 'Erro ao carregar dados')}</td></tr>`;
            return;
        }
        lastPayload = data;
        setSummary(data);
        renderTable(data);
    }

    async function enforceAdmin() {
        const s = await apiGet('check_session', {});
        if (!s || !s.logged_in) {
            window.location.href = 'index.html';
            return false;
        }
        if (String(s.tipo || '').toLowerCase() !== 'admin') {
            window.location.href = 'index.html';
            return false;
        }
        const user = document.getElementById('rejUser');
        if (user) user.textContent = String(s.nome || 'Administrador');
        return true;
    }

    function bind() {
        const btn = document.getElementById('rejApplyBtn');
        if (btn) btn.addEventListener('click', loadData);
        const search = document.getElementById('rejSearch');
        if (search) search.addEventListener('input', function () {
            if (lastPayload) renderTable(lastPayload);
        });
    }

    document.addEventListener('DOMContentLoaded', async function () {
        viewType = String(document.body.getAttribute('data-view') || 'clientes').toLowerCase();
        setDefaultDates();
        bind();
        const ok = await enforceAdmin();
        if (!ok) return;
        loadData().catch(function () {});
    });
})();
