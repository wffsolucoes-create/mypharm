(function () {
    const API_URL = 'api_gestao.php';
    const REFRESH_MS = 15000;
    const CAR_THEMES = [
        { body: '#3b82f6', dark: '#1d4ed8', glow: 'rgba(59,130,246,.45)' },
        { body: '#22c55e', dark: '#15803d', glow: 'rgba(34,197,94,.45)' },
        { body: '#f59e0b', dark: '#b45309', glow: 'rgba(245,158,11,.45)' },
        { body: '#ef4444', dark: '#b91c1c', glow: 'rgba(239,68,68,.45)' },
        { body: '#8b5cf6', dark: '#6d28d9', glow: 'rgba(139,92,246,.45)' },
        { body: '#06b6d4', dark: '#0e7490', glow: 'rgba(6,182,212,.45)' },
        { body: '#ec4899', dark: '#be185d', glow: 'rgba(236,72,153,.45)' },
        { body: '#84cc16', dark: '#4d7c0f', glow: 'rgba(132,204,22,.45)' },
    ];
    const lastPosByVendor = {};

    function fmtMoney(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function fmtDateBr(isoDate) {
        const s = String(isoDate || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return s || '-';
        const p = s.split('-');
        return `${p[2]}/${p[1]}/${p[0]}`;
    }

    function fmtDateTimeBr(isoDateTime) {
        const s = String(isoDateTime || '').trim();
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2})/);
        if (!m) return s || '--';
        return `${m[3]}/${m[2]}/${m[1]} ${m[4]}`;
    }

    function currentMonthRange() {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        return { data_de: `${yyyy}-${mm}-01`, data_ate: `${yyyy}-${mm}-${dd}` };
    }

    async function apiGet(action, params) {
        const query = new URLSearchParams({ action, ...(params || {}) });
        const res = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'include' });
        const text = await res.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch (e) {
            return { success: false, error: `Erro ${res.status}` };
        }
    }

    function renderPodium(ranking) {
        const wrap = document.getElementById('tvPodium');
        if (!wrap) return;
        const top3 = (ranking || []).slice(0, 3);
        if (!top3.length) {
            wrap.innerHTML = '<div class="podium-item">Sem dados no período.</div>';
            return;
        }
        wrap.innerHTML = top3.map(function (r, i) {
            const place = i === 0 ? '🥇 1º lugar' : (i === 1 ? '🥈 2º lugar' : '🥉 3º lugar');
            return `<div class="podium-item">
                <div class="place">${place}</div>
                <div class="name">${r.vendedor || '-'}</div>
                <div class="value">${fmtMoney(r.receita || 0)}</div>
            </div>`;
        }).join('');
    }

    function safeKey(name) {
        return String(name || '').trim().toLowerCase();
    }

    function pickTheme(seed) {
        let hash = 0;
        const s = String(seed || '');
        for (let i = 0; i < s.length; i++) hash = ((hash << 5) - hash + s.charCodeAt(i)) | 0;
        const idx = Math.abs(hash) % CAR_THEMES.length;
        return CAR_THEMES[idx];
    }

    function laneTemplate(r, targetPct) {
        const key = safeKey(r.vendedor);
        const theme = pickTheme(key || r.posicao);
        const leaderCls = r.posicao === 1 ? ' leader' : '';
        const moodCls = r.posicao <= 2 ? ' happy' : ' chasing';
        const moodFace = r.posicao === 1 ? '😄' : (r.posicao === 2 ? '😁' : '😤');
        const prev = Number(lastPosByVendor[key] || 0);
        const startLeft = `${Math.max(0, Math.min(100, prev)).toFixed(2)}%`;
        const pct = Math.max(0, Math.min(100, Number(targetPct || 0)));
        const targetLeft = `${pct.toFixed(2)}%`;
        const pctMetaText = `${Math.max(0, Number(r.percentual_meta || 0)).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% da meta`;
        return `<div class="lane">
            <div class="lane-label">${r.posicao}º ${r.vendedor || '-'}</div>
            <div class="track">
                <div class="lane-rank">${r.posicao}º</div>
                <div class="cart${leaderCls}${moodCls}" data-vendor="${key}" data-target-left="${targetLeft}" data-money="${fmtMoney(r.receita || 0)} • ${pctMetaText}"
                     style="--car:${theme.body}; --car-dark:${theme.dark}; --car-glow:${theme.glow}; left:${startLeft};">
                    <span class="cart-body">
                        <span class="cart-roof"></span>
                        <span class="cart-number">#${r.posicao}</span>
                        <span class="cart-face">${moodFace}</span>
                        <span class="wheel wheel-1"></span>
                        <span class="wheel wheel-2"></span>
                    </span>
                </div>
                <div class="finish-line"></div>
                <div class="finish-flag"><i class="fas fa-flag-checkered"></i></div>
            </div>
        </div>`;
    }

    function buildTargetPositions(ranking) {
        const minGap = 1.6; // evita sobreposição quando % muito próximas
        const out = [];
        if (!Array.isArray(ranking) || !ranking.length) return out;

        let prev = null;
        for (let i = 0; i < ranking.length; i++) {
            const rawMetaPct = Number(ranking[i].percentual_meta || 0);
            let p = Math.max(0, Math.min(100, rawMetaPct));
            if (prev !== null && p > (prev - minGap)) {
                p = Math.max(0, prev - minGap);
            }
            out.push(p);
            prev = p;
        }
        return out;
    }

    function renderRace(ranking, maxReceita) {
        const wrap = document.getElementById('tvRace');
        if (!wrap) return;
        if (!Array.isArray(ranking) || !ranking.length) {
            wrap.innerHTML = '<div class="race-empty">Sem vendas para animar no período.</div>';
            return;
        }
        const positions = buildTargetPositions(ranking);
        wrap.innerHTML = ranking.map(function (r, i) { return laneTemplate(r, positions[i] || 0); }).join('');
        requestAnimationFrame(function () {
            wrap.querySelectorAll('.cart').forEach(function (cart) {
                const target = cart.getAttribute('data-target-left');
                if (target) cart.style.left = target;
            });
        });
        ranking.forEach(function (r, i) {
            const key = safeKey(r.vendedor);
            lastPosByVendor[key] = Number(positions[i] || 0);
        });
    }

    async function loadRace() {
        const range = currentMonthRange();
        const data = await apiGet('tv_corrida_vendedores', range);
        if (!data || data.success === false) return;

        const ranking = data.ranking || [];
        const max = Number(data.max_receita || 0);
        renderPodium(ranking);
        renderRace(ranking, max);

        const p = data.periodo || {};
        const updated = data.updated_at || '--';
        const periodoEl = document.getElementById('tvPeriodo');
        const updEl = document.getElementById('tvUpdatedAt');
        if (periodoEl) periodoEl.textContent = `Período: ${fmtDateBr(p.data_de)} até ${fmtDateBr(p.data_ate)}`;
        if (updEl) updEl.textContent = `Atualizado: ${fmtDateTimeBr(updated)}`;
    }

    function bindUi() {
        const fsBtn = document.getElementById('tvFullscreenBtn');
        if (fsBtn) {
            fsBtn.addEventListener('click', async function () {
                if (!document.fullscreenElement) {
                    try { await document.documentElement.requestFullscreen(); } catch (_) {}
                } else {
                    try { await document.exitFullscreen(); } catch (_) {}
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindUi();
        loadRace().catch(function () {});
        setInterval(function () { loadRace().catch(function () {}); }, REFRESH_MS);
    });
})();
