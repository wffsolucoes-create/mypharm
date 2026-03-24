(function () {
    function resolveApiGestaoUrl() {
        try {
            if (window.location.protocol === 'file:') {
                return '';
            }
            return new URL('api_gestao.php', window.location.href).href;
        } catch (e) {
            return 'api_gestao.php';
        }
    }
    const API_URL = resolveApiGestaoUrl();
    let lastPayload = {
        metricas: null,
        tv: null,
        rejeitados: null
    };

    function money(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function int(v) {
        return Number(v || 0).toLocaleString('pt-BR');
    }

    function currentMonthRange() {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        return { data_de: `${yyyy}-${mm}-01`, data_ate: `${yyyy}-${mm}-${dd}` };
    }

    function setDefaultDates() {
        const r = currentMonthRange();
        const de = document.getElementById('dbgDataDe');
        const ate = document.getElementById('dbgDataAte');
        if (de && !de.value) de.value = r.data_de;
        if (ate && !ate.value) ate.value = r.data_ate;
    }

    async function apiGet(action, params, options) {
        const query = new URLSearchParams(Object.assign({ action: action }, params || {}));
        const url = API_URL ? `${API_URL}?${query.toString()}` : '';
        if (!url) {
            return { success: false, error: 'Abra esta página pelo Apache (http://localhost/.../rd-debug-dump.html), não por file://.' };
        }
        const ctrl = new AbortController();
        const ms = (options && options.timeoutMs) || 300000;
        const t = window.setTimeout(function () { try { ctrl.abort(); } catch (e) {} }, ms);
        try {
            const res = await fetch(url, { credentials: 'omit', signal: ctrl.signal });
            const text = await res.text();
            let data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (_) {
                return { success: false, error: 'Resposta não é JSON (HTTP ' + res.status + ').', raw: String(text).slice(0, 800) };
            }
            if (!res.ok && (data.success !== true)) {
                return Object.assign({ success: false }, data, { error: data.error || ('HTTP ' + res.status) });
            }
            return data;
        } catch (e) {
            if (e && e.name === 'AbortError') {
                return { success: false, error: 'Tempo esgotado (' + Math.round(ms / 1000) + 's). O RD pode estar lento; tente um período menor ou tente de novo.' };
            }
            return { success: false, error: (e && e.message) ? String(e.message) : 'Falha de rede ao chamar a API.' };
        } finally {
            window.clearTimeout(t);
        }
    }

    function filterText(text) {
        const q = String((document.getElementById('dbgSearch') || {}).value || '').trim().toLowerCase();
        if (!q) return text;
        return text.toLowerCase().indexOf(q) >= 0 ? text : `Filtro "${q}" não encontrado neste bloco.`;
    }

    function setPre(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        const text = JSON.stringify(value, null, 2);
        el.textContent = filterText(text);
    }

    function setSummary(metricasPayload) {
        const payload = metricasPayload || {};
        const sum = payload.summary || {};
        const status = document.getElementById('dbgStatus');
        if (status) {
            status.className = 'status';
            status.textContent = `Atualizado em ${payload.generated_at || '-'} · período ${payload.periodo?.data_de || '-'} a ${payload.periodo?.data_ate || '-'}`;
        }
        const set = function (id, txt) {
            const el = document.getElementById(id);
            if (el) el.textContent = txt;
        };
        set('dbgReceita', money(sum.rd_receita_total || 0));
        set('dbgGanhos', `${int(sum.rd_total_ganhos || 0)} / ${int(sum.rd_total_perdidos || 0)}`);
        set('dbgKanban', `${int(sum.kanban_total_negociacoes || 0)} total · ${int(sum.kanban_abertos || 0)} abertos`);
        set('dbgRejeitados', 'carregando em bloco separado');

        setPre('dbgSummary', payload.summary || {});
        setPre('dbgUsuarios', payload.usuarios_vendedores || []);
        setPre('dbgFull', payload.rd_metricas_full || {});
        setPre('dbgNoFunil', payload.rd_metricas_sem_funil || {});
    }

    function setTv(tvPayload) {
        const data = tvPayload || {};
        setPre('dbgTv', data.tv_ranking || {});
    }

    function setRejeitados(rejPayload) {
        const data = rejPayload || {};
        const registros = data.rejeitados_detalhados && Array.isArray(data.rejeitados_detalhados.registros)
            ? data.rejeitados_detalhados.registros
            : [];
        const el = document.getElementById('dbgRejeitados');
        if (el) el.textContent = int(registros.length);
        setPre('dbgRej', data.rejeitados_detalhados || {});
    }

    async function loadMetricas() {
        const btn = document.getElementById('dbgApplyBtn');
        const status = document.getElementById('dbgStatus');
        if (btn) btn.disabled = true;
        if (status) {
            status.className = 'status';
            status.textContent = API_URL
                ? 'Carregando métricas principais do RD (pode levar 1–2 minutos)...'
                : 'Defina a URL: abra via http://localhost/.../rd-debug-dump.html';
        }

        if (!API_URL) {
            if (btn) btn.disabled = false;
            setPre('dbgSummary', { success: false, error: 'Use http://localhost/mypharm/rd-debug-dump.html (não abra o arquivo direto do disco).' });
            return;
        }

        let payload;
        try {
            payload = await apiGet('debug_rd_metricas_public', {
                data_de: (document.getElementById('dbgDataDe') || {}).value || '',
                data_ate: (document.getElementById('dbgDataAte') || {}).value || ''
            }, { timeoutMs: 300000 });
        } catch (e) {
            payload = { success: false, error: (e && e.message) ? String(e.message) : String(e) };
        } finally {
            if (btn) btn.disabled = false;
        }

        if (!payload || payload.success !== true) {
            if (status) {
                status.className = 'status error';
                status.textContent = (payload && payload.error) || 'Falha ao carregar métricas.';
            }
            setPre('dbgSummary', payload || { success: false, error: 'Resposta vazia.' });
            setPre('dbgUsuarios', {});
            setPre('dbgFull', {});
            setPre('dbgNoFunil', {});
            return;
        }
        lastPayload.metricas = payload;
        setSummary(payload);
        loadTv();
        loadRejeitados();
    }

    async function loadTv() {
        const payload = await apiGet('debug_rd_tv_public', {
            data_de: (document.getElementById('dbgDataDe') || {}).value || '',
            data_ate: (document.getElementById('dbgDataAte') || {}).value || ''
        });
        lastPayload.tv = payload;
        setTv(payload);
    }

    async function loadRejeitados() {
        const payload = await apiGet('debug_rd_rejeitados_public', {
            data_de: (document.getElementById('dbgDataDe') || {}).value || '',
            data_ate: (document.getElementById('dbgDataAte') || {}).value || ''
        });
        lastPayload.rejeitados = payload;
        setRejeitados(payload);
    }

    document.addEventListener('DOMContentLoaded', function () {
        setDefaultDates();
        const btn = document.getElementById('dbgApplyBtn');
        if (btn) btn.addEventListener('click', loadMetricas);
        const search = document.getElementById('dbgSearch');
        if (search) {
            search.addEventListener('input', function () {
                if (lastPayload.metricas) setSummary(lastPayload.metricas);
                if (lastPayload.tv) setTv(lastPayload.tv);
                if (lastPayload.rejeitados) setRejeitados(lastPayload.rejeitados);
            });
        }
        loadMetricas().catch(function (err) {
            const status = document.getElementById('dbgStatus');
            const btn = document.getElementById('dbgApplyBtn');
            if (btn) btn.disabled = false;
            if (status) {
                status.className = 'status error';
                status.textContent = 'Erro: ' + (err && err.message ? err.message : String(err));
            }
        });
    });
})();
