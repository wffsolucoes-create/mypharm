(function () {
    const API_URL = 'api_gestao.php';
    let ceRows = [];
    let ceCharts = { consultora: null, gravidade: null };
    let ceInteractiveFilter = { consultora: '', gravidade: '' };

    function fmtInt(v) {
        return Number(v || 0).toLocaleString('pt-BR');
    }

    function apiGet(action, params = {}) {
        const query = new URLSearchParams({ action, ...params });
        return fetch(`${API_URL}?${query.toString()}`, { credentials: 'include' })
            .then(function (r) { return r.text(); })
            .then(function (t) { try { return t ? JSON.parse(t) : {}; } catch (_) { return { success: false }; } });
    }

    function apiPost(action, data = {}) {
        return fetch(`${API_URL}?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (r) { return r.text(); })
            .then(function (t) { try { return t ? JSON.parse(t) : {}; } catch (_) { return { success: false }; } });
    }

    function esc(s) {
        if (s == null) return '';
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function getThemeStorageKey() {
        const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
        return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
    }

    function loadSavedTheme() {
        const saved = localStorage.getItem(getThemeStorageKey()) || localStorage.getItem('mypharm_theme');
        if (saved) document.documentElement.setAttribute('data-theme', saved);
    }

    function toggleTheme() {
        const html = document.documentElement;
        const current = html.getAttribute('data-theme');
        const next = current === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', next);
        localStorage.setItem(getThemeStorageKey(), next);
        localStorage.setItem('mypharm_theme', next);
    }

    function monthRange() {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        return { de: `${y}-${m}-01`, ate: `${y}-${m}-${d}` };
    }

    function normalizeClassificacao(v) {
        const s = String(v || '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        if (s === 'grave') return { cls: 'ce-tag-grave', txt: 'Grave' };
        if (s === 'medio') return { cls: 'ce-tag-medio', txt: 'Médio' };
        if (s === 'leve') return { cls: 'ce-tag-leve', txt: 'Leve' };
        return { cls: 'ce-tag-leve', txt: 'Leve' };
    }

    function formatDateBr(v) {
        const s = String(v || '');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return s || '-';
        return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
    }

    function normalizeName(v) {
        var s = String(v || '').trim().toLowerCase();
        try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (_) {}
        return s.replace(/\s+/g, ' ');
    }

    function userColorStyle(name) {
        const map = {
            'ananda': { bg: '#fde68a', text: '#7c2d12', border: '#f59e0b' },
            'ananda reis': { bg: '#fde68a', text: '#7c2d12', border: '#f59e0b' },
            'carla': { bg: '#fecaca', text: '#7f1d1d', border: '#ef4444' },
            'clara': { bg: '#bfdbfe', text: '#1e3a8a', border: '#3b82f6' },
            'clara leticia': { bg: '#bfdbfe', text: '#1e3a8a', border: '#3b82f6' },
            'giovanna': { bg: '#ddd6fe', text: '#4c1d95', border: '#8b5cf6' },
            'jessica vitoria': { bg: '#bae6fd', text: '#0c4a6e', border: '#0ea5e9' },
            'mariana': { bg: '#fecdd3', text: '#881337', border: '#f43f5e' },
            'nailena': { bg: '#bbf7d0', text: '#14532d', border: '#22c55e' },
            'nereida': { bg: '#fef3c7', text: '#78350f', border: '#f59e0b' },
            'vitoria': { bg: '#bae6fd', text: '#0c4a6e', border: '#0ea5e9' }
        };
        const key = normalizeName(name);
        let c = map[key];
        if (!c) {
            // Fallback determinístico para manter cores distintas por nome.
            let hash = 0;
            for (let i = 0; i < key.length; i++) hash = ((hash << 5) - hash) + key.charCodeAt(i);
            const hue = Math.abs(hash) % 360;
            c = {
                bg: `hsl(${hue} 85% 92%)`,
                text: `hsl(${hue} 55% 26%)`,
                border: `hsl(${hue} 70% 58%)`
            };
        }
        return `background:${c.bg};color:${c.text};border:1px solid ${c.border};`;
    }

    function renderTable(rows) {
        const body = document.getElementById('ceTbody');
        if (!body) return;
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="8">Sem erros registrados no período selecionado.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            const c = normalizeClassificacao(r.classificacao_erro);
            const userName = String(r.vendedor_nome || '-');
            return `<tr>
                <td>${esc(formatDateBr(r.data_erro))}</td>
                <td>${esc(r.pedido_ref || '-')}</td>
                <td><span class="ce-user-badge" style="${userColorStyle(userName)}">${esc(userName)}</span></td>
                <td>${esc(r.tipo_erro || '-')}</td>
                <td>${esc(r.descricao || '-')}</td>
                <td><span class="ce-tag ${c.cls}">${esc(c.txt)}</span></td>
                <td>${Number(r.pontos_descontados || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</td>
                <td><button type="button" class="ce-delete-btn" data-id="${Number(r.id || 0)}"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        }).join('');
    }

    function renderResumo(rows) {
        const total = rows.length;
        const pontos = rows.reduce(function (acc, r) { return acc + Number(r.pontos_descontados || 0); }, 0);
        const totalEl = document.getElementById('ceResumoTotal');
        const pontosEl = document.getElementById('ceResumoPontos');
        if (totalEl) totalEl.textContent = fmtInt(total);
        if (pontosEl) pontosEl.textContent = pontos.toLocaleString('pt-BR', { maximumFractionDigits: 2 });
    }

    function getRowsFilteredByCharts(rows) {
        return rows.filter(function (r) {
            if (ceInteractiveFilter.consultora) {
                const vn = String(r.vendedor_nome || '');
                if (vn !== ceInteractiveFilter.consultora && normalizeName(vn) !== normalizeName(ceInteractiveFilter.consultora)) {
                    return false;
                }
            }
            if (ceInteractiveFilter.gravidade) {
                const c = normalizeClassificacao(r.classificacao_erro).txt;
                if (c !== ceInteractiveFilter.gravidade) return false;
            }
            return true;
        });
    }

    function renderFilterHint(filteredRows, totalRows) {
        const el = document.getElementById('ceListFilterHint');
        if (!el) return;
        const hasConsultora = !!ceInteractiveFilter.consultora;
        const hasGravidade = !!ceInteractiveFilter.gravidade;
        if (!hasConsultora && !hasGravidade) {
            el.textContent = '';
            return;
        }
        const parts = [];
        if (hasConsultora) parts.push('Consultora: ' + ceInteractiveFilter.consultora);
        if (hasGravidade) parts.push('Gravidade: ' + ceInteractiveFilter.gravidade);
        el.textContent = 'Filtro por gráfico ativo (' + parts.join(' | ') + ') — ' + fmtInt(filteredRows.length) + ' de ' + fmtInt(totalRows) + ' registros.';
    }

    function renderDerivedFromRows() {
        const rows = getRowsFilteredByCharts(ceRows);
        renderResumo(rows);
        renderTable(rows);
        renderFilterHint(rows, ceRows.length);
    }

    function destroyChart(key) {
        if (ceCharts[key] && typeof ceCharts[key].destroy === 'function') {
            try { ceCharts[key].destroy(); } catch (_) {}
        }
        ceCharts[key] = null;
    }

    function renderCharts(rows) {
        if (typeof Chart === 'undefined') return;
        const byConsultora = {};
        const byGrav = { Leve: 0, 'Médio': 0, Grave: 0 };
        rows.forEach(function (r) {
            const nome = String(r.vendedor_nome || 'Sem nome');
            byConsultora[nome] = (byConsultora[nome] || 0) + 1;
            const c = normalizeClassificacao(r.classificacao_erro).txt;
            byGrav[c] = (byGrav[c] || 0) + 1;
        });

        const c1 = document.getElementById('ceChartConsultora');
        const c2 = document.getElementById('ceChartGravidade');
        if (!c1 || !c2) return;

        destroyChart('consultora');
        destroyChart('gravidade');

        const pairs = Object.keys(byConsultora).length
            ? Object.entries(byConsultora).sort(function (a, b) { return b[1] - a[1]; })
            : [];
        const pieLabels = pairs.length ? pairs.map(function (p) { return p[0]; }) : ['Sem dados'];
        const pieData = pairs.length ? pairs.map(function (p) { return p[1]; }) : [1];
        const palette = ['#2563eb', '#e63946', '#06d6a0', '#f59e0b', '#8b5cf6', '#118ab2', '#22c55e', '#ef4444', '#0ea5e9', '#a855f7', '#14b8a6', '#f97316'];
        const pieColors = pieLabels.map(function (_, i) { return palette[i % palette.length]; });

        ceCharts.consultora = new Chart(c1.getContext('2d'), {
            type: 'pie',
            data: {
                labels: pieLabels,
                datasets: [{
                    data: pieData,
                    backgroundColor: pieColors
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right' } },
                onClick: function (_, elements) {
                    if (!elements || !elements.length) return;
                    const idx = Number(elements[0].index || 0);
                    const label = this.data && this.data.labels ? String(this.data.labels[idx] || '') : '';
                    if (!label || label === 'Sem dados') return;
                    ceInteractiveFilter.consultora = (ceInteractiveFilter.consultora === label) ? '' : label;
                    renderDerivedFromRows();
                }
            }
        });

        ceCharts.gravidade = new Chart(c2.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Leve', 'Médio', 'Grave'],
                datasets: [{ data: [byGrav.Leve, byGrav['Médio'], byGrav.Grave], backgroundColor: ['#2563eb', '#eab308', '#dc2626'] }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                onClick: function (_, elements) {
                    if (!elements || !elements.length) return;
                    const idx = Number(elements[0].index || 0);
                    const label = this.data && this.data.labels ? String(this.data.labels[idx] || '') : '';
                    if (!label) return;
                    ceInteractiveFilter.gravidade = (ceInteractiveFilter.gravidade === label) ? '' : label;
                    renderDerivedFromRows();
                }
            }
        });
    }

    async function loadVendedores() {
        const data = await apiGet('gestao_comercial_lista_vendedores');
        const nomes = Array.isArray(data && data.nomes) ? data.nomes : [];
        const opts = nomes.map(function (n) { return `<option value="${esc(n)}">${esc(n)}</option>`; }).join('');
        const s1 = document.getElementById('ceVendedor');
        const s2 = document.getElementById('ceFiltroVendedor');
        if (s1) s1.innerHTML = opts;
        if (s2) s2.innerHTML = '<option value="">Todas</option>' + opts;
    }

    async function loadErros() {
        const dataDe = (document.getElementById('ceDataDe') || {}).value || '';
        const dataAte = (document.getElementById('ceDataAte') || {}).value || '';
        const vendedor = (document.getElementById('ceFiltroVendedor') || {}).value || '';
        const q = (document.getElementById('ceBusca') || {}).value || '';
        const data = await apiGet('gestao_comercial_erros_lista', { data_de: dataDe, data_ate: dataAte, vendedor: vendedor, q: q, limit: '500' });
        ceRows = Array.isArray(data && data.rows) ? data.rows : [];
        ceInteractiveFilter = { consultora: '', gravidade: '' };
        renderDerivedFromRows();
        renderCharts(ceRows);
    }

    async function saveErro() {
        const payload = {
            vendedor_nome: (document.getElementById('ceVendedor') || {}).value || '',
            data_erro: (document.getElementById('ceDataErro') || {}).value || '',
            pedido_ref: (document.getElementById('cePedidoRef') || {}).value || '',
            classificacao_erro: (document.getElementById('ceClassificacao') || {}).value || 'leve',
            pontos_descontados: (document.getElementById('cePontos') || {}).value || '',
            tipo_erro: (document.getElementById('ceTipoErro') || {}).value || '',
            descricao: (document.getElementById('ceDescricao') || {}).value || ''
        };
        if (!payload.vendedor_nome || !String(payload.tipo_erro).trim()) {
            alert('Preencha consultora e tipo de erro.');
            return;
        }
        const resp = await apiPost('gestao_comercial_erros_salvar', payload);
        if (!resp || resp.success === false) {
            alert((resp && resp.error) ? resp.error : 'Não foi possível salvar.');
            return;
        }
        const tipo = document.getElementById('ceTipoErro');
        const pedido = document.getElementById('cePedidoRef');
        const desc = document.getElementById('ceDescricao');
        if (tipo) tipo.value = '';
        if (pedido) pedido.value = '';
        if (desc) desc.value = '';
        await loadErros();
    }

    async function deleteErro(id) {
        if (!id) return;
        if (!window.confirm('Deseja excluir este lançamento?')) return;
        const resp = await apiPost('gestao_comercial_erros_excluir', { id: id });
        if (!resp || resp.success === false) {
            alert((resp && resp.error) ? resp.error : 'Não foi possível excluir.');
            return;
        }
        await loadErros();
    }

    async function enforceAdminAccess() {
        const session = await apiGet('check_session');
        if (!session || !session.logged_in || String(session.tipo || '').toLowerCase() !== 'admin') {
            localStorage.clear();
            window.location.href = 'index.html';
            return null;
        }
        return session;
    }

    function bindUi(session) {
        const nome = (session && session.nome) || localStorage.getItem('userName') || 'Administrador';
        const userName = document.getElementById('ceUserName');
        const avatar = document.getElementById('ceAvatar');
        if (userName) userName.textContent = nome;
        if (avatar) avatar.textContent = String(nome).charAt(0).toUpperCase();

        const r = monthRange();
        const de = document.getElementById('ceDataDe');
        const ate = document.getElementById('ceDataAte');
        const dataErro = document.getElementById('ceDataErro');
        if (de && !de.value) de.value = r.de;
        if (ate && !ate.value) ate.value = r.ate;
        if (dataErro && !dataErro.value) dataErro.value = r.ate;

        const classif = document.getElementById('ceClassificacao');
        const pontos = document.getElementById('cePontos');
        if (classif && pontos) {
            classif.addEventListener('change', function () {
                if ((pontos.value || '').trim() !== '') return;
                pontos.value = classif.value === 'grave' ? '10' : (classif.value === 'medio' ? '5' : '2');
            });
        }

        const salvar = document.getElementById('ceSalvarBtn');
        if (salvar) salvar.addEventListener('click', function () { saveErro().catch(function () {}); });
        const atualizar = document.getElementById('ceBtnAtualizar');
        if (atualizar) atualizar.addEventListener('click', function () { loadErros().catch(function () {}); });
        const busca = document.getElementById('ceBusca');
        if (busca) busca.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                loadErros().catch(function () {});
            }
        });
        const filtroVend = document.getElementById('ceFiltroVendedor');
        if (filtroVend) filtroVend.addEventListener('change', function () { loadErros().catch(function () {}); });
        if (de) de.addEventListener('change', function () { loadErros().catch(function () {}); });
        if (ate) ate.addEventListener('change', function () { loadErros().catch(function () {}); });

        const logout = document.getElementById('ceLogoutBtn');
        if (logout) logout.addEventListener('click', async function () {
            try { await apiPost('logout', {}); } catch (_) {}
            localStorage.clear();
            window.location.href = 'index.html';
        });

        const themeBtn = document.getElementById('ceThemeBtn');
        if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

        const tbody = document.getElementById('ceTbody');
        if (tbody) {
            tbody.addEventListener('click', function (ev) {
                const btn = ev.target.closest('.ce-delete-btn');
                if (!btn) return;
                const id = Number(btn.getAttribute('data-id') || 0);
                deleteErro(id).catch(function () {});
            });
        }

        const toggle = document.getElementById('ceSidebarToggle');
        const sidebar = document.getElementById('ceSidebar');
        if (toggle && sidebar) {
            toggle.addEventListener('click', function () {
                if (window.innerWidth <= 768) return;
                sidebar.classList.toggle('collapsed');
                const icon = toggle.querySelector('i');
                if (icon) icon.className = sidebar.classList.contains('collapsed') ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', async function () {
        loadSavedTheme();
        const session = await enforceAdminAccess();
        if (!session) return;
        const app = document.getElementById('ceApp');
        if (app) app.style.display = 'flex';
        bindUi(session);
        await loadVendedores();
        await loadErros();
    });
})();
