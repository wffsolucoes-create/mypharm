(function () {
    const API_URL = 'api_gestao.php';
    const REFRESH_MS = 5000;
    let tvRequestInFlight = false;
    
    // Lista ampliada de cores neon vibrantes (garantindo variedade)
    const CAR_THEMES = [
        { body: '#fbbf24', glow: 'rgba(251,191,36,.8)' },   // Ouro/Amarelo
        { body: '#cbd5e1', glow: 'rgba(203,213,225,.8)' },   // Prata/Cinza
        { body: '#d97706', glow: 'rgba(217,119,6,.8)' },    // Bronze
        { body: '#3b82f6', glow: 'rgba(59,130,246,.8)' },   // Azul Neon
        { body: '#10b981', glow: 'rgba(16,185,129,.8)' },   // Verde Esmeralda
        { body: '#ec4899', glow: 'rgba(236,72,153,.8)' },   // Rosa Pink
        { body: '#8b5cf6', glow: 'rgba(139,92,246,.8)' },   // Roxo Violeta
        { body: '#f43f5e', glow: 'rgba(244,63,94,.8)' },    // Vermelho Rose
        { body: '#06b6d4', glow: 'rgba(6,182,212,.8)' },    // Ciano
        { body: '#84cc16', glow: 'rgba(132,204,22,.8)' },   // Verde Limão
        { body: '#f97316', glow: 'rgba(249,115,22,.8)' },   // Laranja Claro
        { body: '#eab308', glow: 'rgba(234,179,8,.8)' },    // Amarelo Sol
        { body: '#6366f1', glow: 'rgba(99,102,241,.8)' },   // Indigo
        { body: '#14b8a6', glow: 'rgba(20,184,166,.8)' },   // Teal
        { body: '#d946ef', glow: 'rgba(217,70,239,.8)' }    // Fuschia
    ];
    const REAL_CAR_MODELS = [
        { name: 'Ferrari', src: 'imagens/tv-real/ferrari.png', flipX: false },
        { name: 'Camaro', src: 'imagens/tv-real/camaro.png', flipX: true },
        { name: 'Mustang', src: 'imagens/tv-real/mustang.png', flipX: true },
        { name: 'Lamborghini', src: 'imagens/tv-real/lamborghini.png', flipX: false },
        { name: 'Porsche', src: 'imagens/tv-real/porsche.png', flipX: true },
        { name: 'BMW', src: 'imagens/tv-real/bmw.png', flipX: false },
        { name: 'Mercedes', src: 'imagens/tv-real/mercedes.png', flipX: true },
        { name: 'Pickup', src: 'imagens/tv-real/pickup.png', flipX: false }
    ];
    const lastPctByVendor = {};
    const lastRankByVendor = {};
    const lastReceitaByVendor = {};
    const lastRaceRevenueByVendor = {};
    const assignedThemeIndices = {};
    let nextThemeIdx = 0;
    let lastRaceFingerprint = '';
    let lastRaceUpdatedAt = '';

    // Sons nas ações (Web Audio API - sem arquivos externos)
    var tvAudioCtx = null;
    function getAudioCtx() {
        if (tvAudioCtx) return tvAudioCtx;
        try {
            tvAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) { return null; }
        return tvAudioCtx;
    }
    function playSoundNow(type, ctx) {
        var now = ctx.currentTime;
        var gain = ctx.createGain();
        gain.connect(ctx.destination);
        gain.gain.setValueAtTime(0.28, now);
        gain.gain.exponentialRampToValueAtTime(0.01, now + 0.5);

        if (type === 'overtake') {
            var osc = ctx.createOscillator();
            osc.connect(gain);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(400, now);
            osc.frequency.exponentialRampToValueAtTime(800, now + 0.08);
            osc.frequency.exponentialRampToValueAtTime(1200, now + 0.2);
            osc.start(now);
            osc.stop(now + 0.25);
        } else if (type === 'confetti' || type === 'celebration') {
            [523, 659, 784, 1047].forEach(function (freq, i) {
                var o = ctx.createOscillator();
                o.connect(gain);
                o.type = 'sine';
                o.frequency.setValueAtTime(freq, now + i * 0.05);
                o.start(now + i * 0.05);
                o.stop(now + 0.3 + i * 0.05);
            });
        } else if (type === 'tick') {
            var o = ctx.createOscillator();
            o.connect(gain);
            o.type = 'sine';
            o.frequency.setValueAtTime(600, now);
            o.start(now);
            o.stop(now + 0.05);
        }
    }
    function playSound(type) {
        var ctx = getAudioCtx();
        if (!ctx) return;
        try {
            if (ctx.state === 'suspended') {
                ctx.resume().then(function () { playSoundNow(type, ctx); }).catch(function () {});
                return;
            }
            playSoundNow(type, ctx);
        } catch (e) {}
    }
    // No primeiro clique/toque na página, ativa o áudio (política do navegador exige gesto do usuário)
    function resumeAudioOnFirstInteraction() {
        var done = false;
        function resume() {
            if (done) return;
            done = true;
            var ctx = getAudioCtx();
            if (ctx && ctx.state === 'suspended') ctx.resume().catch(function () {});
        }
        document.addEventListener('click', resume, { once: true, passive: true });
        document.addEventListener('touchstart', resume, { once: true, passive: true });
    }

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
        // Usar do dia 01 até o dia atual
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

    function getCarProfile(posicao) {
        if (posicao === 1) {
            return { cls: 'leader', podiumLabel: '1º Diamante' };
        }
        if (posicao === 2) {
            return { cls: 'rank-gold', podiumLabel: '2º Ouro' };
        }
        if (posicao === 3) {
            return { cls: 'rank-luxury', podiumLabel: '3º Prata' };
        }
        return { cls: '', podiumLabel: posicao + 'º' };
    }

    // Função para animar números (receita)
    function animateValue(obj, start, end, duration) {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            obj.innerHTML = fmtMoney(progress * (end - start) + start);
            if (progress < 1) {
                window.requestAnimationFrame(step);
            } else {
                obj.innerHTML = fmtMoney(end);
            }
        };
        window.requestAnimationFrame(step);
    }

    function triggerConfetti() {
        playSound('celebration');
        if(typeof confetti !== 'undefined'){
            var duration = 3000;
            var animationEnd = Date.now() + duration;
            var defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 100 };

            function randomInRange(min, max) { return Math.random() * (max - min) + min; }

            var interval = setInterval(function() {
                var timeLeft = animationEnd - Date.now();
                if (timeLeft <= 0) { return clearInterval(interval); }
                var particleCount = 50 * (timeLeft / duration);
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }));
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }));
            }, 250);
        }
    }

    let isFirstLoad = true;

    function renderPodium(ranking) {
        const wrap = document.getElementById('tvPodium');
        if (!wrap) return;
        const top3 = (ranking || []).slice(0, 3);
        if (!top3.length) {
            wrap.innerHTML = '<div class="podium-item glass-panel">Sem dados no período.</div>';
            return;
        }
        
        let shouldConfetti = false;
        if(isFirstLoad && top3.length > 0) shouldConfetti = true;

        wrap.innerHTML = top3.map(function (r, i) {
            const place = (i + 1) + 'º';
            
            // Check if rank changed (simplificando: baseia-se apenas no ID do vendedor, se disponível, ou nome)
            const key = safeKey(r.vendedor);
            let valueHtml = `<div class="value" data-vendor="${key}" data-val="${r.receita}">${fmtMoney(r.receita || 0)}</div>`;

            return `<div class="podium-item glass-panel" data-place="${place}">
                <div class="place">${i === 0 ? '👑 LÍDER' : '🏆 TOP ' + (i+1)}</div>
                <div class="name">${r.vendedor || '-'}</div>
                ${valueHtml}
            </div>`;
        }).join('');

        if(shouldConfetti) {
            triggerConfetti();
            isFirstLoad = false;
        }

        // Animate numbers
        wrap.querySelectorAll('.value').forEach(el => {
            const val = parseFloat(el.getAttribute('data-val'));
            const key = el.getAttribute('data-vendor');
            const oldVal = lastReceitaByVendor[key] || 0;
            if (val > oldVal) {
                animateValue(el, oldVal, val, 1500);
                lastReceitaByVendor[key] = val;
            } else {
                el.innerHTML = fmtMoney(val);
                lastReceitaByVendor[key] = val;
            }
        });
    }

    function safeKey(name) {
        return String(name || '').trim().toLowerCase();
    }

    function pickTheme(vendorKey, position) {
        // Se já associamos uma cor "única" a esse vendedor antes, continua com ela
        if (assignedThemeIndices[vendorKey] !== undefined) {
            return CAR_THEMES[assignedThemeIndices[vendorKey]];
        }
        
        // Pega a próxima cor sequencial garantindo que parem de se repetir à toa
        const idx = nextThemeIdx % CAR_THEMES.length;
        assignedThemeIndices[vendorKey] = idx;
        nextThemeIdx++;
        
        return CAR_THEMES[idx];
    }

    function modelIndexByName(name) {
        const n = String(name || '').toLowerCase();
        for (let i = 0; i < REAL_CAR_MODELS.length; i++) {
            if (String(REAL_CAR_MODELS[i].name || '').toLowerCase() === n) return i;
        }
        return -1;
    }

    function assignModelsForRace(ranking) {
        const used = new Set();
        const assigned = [];
        const preferredByPos = ['Ferrari', 'Lamborghini', 'Porsche'];

        for (let i = 0; i < ranking.length; i++) {
            const prefName = preferredByPos[i] || null;
            let idx = prefName ? modelIndexByName(prefName) : -1;

            if (idx >= 0 && !used.has(idx)) {
                used.add(idx);
                assigned.push(REAL_CAR_MODELS[idx]);
                continue;
            }

            idx = -1;
            for (let j = 0; j < REAL_CAR_MODELS.length; j++) {
                if (!used.has(j)) {
                    idx = j;
                    break;
                }
            }

            if (idx === -1) {
                // fallback raro quando ranking > quantidade de modelos
                idx = i % REAL_CAR_MODELS.length;
            }
            used.add(idx);
            assigned.push(REAL_CAR_MODELS[idx]);
        }
        return assigned;
    }

    function scaleForPosition(posicao) {
        const p = Number(posicao || 0);
        if (p <= 1) return 1.45;
        if (p === 2) return 1.30;
        if (p === 3) return 1.18;
        if (p === 4) return 1.06;
        if (p === 5) return 0.96;
        if (p === 6) return 0.88;
        if (p === 7) return 0.80;
        return 0.74;
    }

    function buildRaceFingerprint(ranking) {
        if (!Array.isArray(ranking) || !ranking.length) return '';
        return ranking.map(function (r, i) {
            const key = safeKey(r.vendedor);
            const receita = Number(r.receita || 0).toFixed(2);
            const meta = Number(r.percentual_meta || 0).toFixed(2);
            const lider = Number(r.percentual_lider || 0).toFixed(2);
            return `${i + 1}:${key}:${receita}:${meta}:${lider}`;
        }).join('|');
    }

    function laneTemplate(r, targetPct, carModel) {
        const key = safeKey(r.vendedor);
        const theme = pickTheme(key || r.posicao);
        const profile = getCarProfile(r.posicao);
        const prev = Number(lastPctByVendor[key] || 6);
        const startLeft = `${Math.max(4, Math.min(96, prev)).toFixed(2)}%`;
        const pct = Math.max(4, Math.min(96, Number(targetPct || 0)));
        const targetLeft = `${pct.toFixed(2)}%`;
        const pctMetaText = `${Math.max(0, Number(r.percentual_meta || 0)).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% meta`;
        const posLabel = r.posicao + 'º';
        const carScale = scaleForPosition(r.posicao);
        const posClass = 'pos-' + String(r.posicao || '');

        return `<div class="lane">
            <div class="lane-label"><span class="lane-pos">${posLabel}</span> ${r.vendedor || '-'}</div>
            <div class="track">
                <div class="cart cart-img-wrap ${profile.cls} ${posClass}" data-vendor="${key}" data-target-left="${targetLeft}" data-money="${fmtMoney(r.receita || 0)} | ${pctMetaText}"
                     style="color:${theme.body}; left:${startLeft}; --car-scale:${carScale};">
                    <span class="cart-img">
                        <img class="cart-photo ${carModel.flipX ? 'cart-photo--flip' : ''}" src="${carModel.src}" alt="${carModel.name}" data-fallback="imagens/tv-carro.svg" loading="eager" decoding="async" />
                    </span>
                </div>
                <div class="finish-line"></div>
                <div class="finish-flag"><i class="fas fa-flag-checkered"></i></div>
            </div>
        </div>`;
    }

    function buildTargetPositions(ranking) {
        const minGap = 4.5; // mantém uma separação visual estável entre posições
        const minPct = 8;
        const maxPct = 92;
        const out = [];
        if (!Array.isArray(ranking) || !ranking.length) return out;

        let prev = null;
        for (let i = 0; i < ranking.length; i++) {
            const rawLeaderPct = Number(ranking[i].percentual_lider || 0);
            const rawMetaPct = Number(ranking[i].percentual_meta || 0);
            const base = rawLeaderPct > 0 ? rawLeaderPct : Math.max(0, Math.min(100, rawMetaPct));
            let p = minPct + (Math.max(0, Math.min(100, base)) / 100) * (maxPct - minPct);
            
            if (prev !== null && p > (prev - minGap)) {
                p = Math.max(minPct, prev - minGap);
            }
            out.push(p);
            prev = p;
        }
        return out;
    }

    function renderRace(ranking, maxReceita, updatedAt) {
        const wrap = document.getElementById('tvRace');
        if (!wrap) return;
        if (!Array.isArray(ranking) || !ranking.length) {
            wrap.innerHTML = '<div class="race-empty">Sem vendas no período.</div>';
            return;
        }
        const currentFingerprint = buildRaceFingerprint(ranking);
        const hasUpdatedAt = String(updatedAt || '').trim() !== '';
        const hasDataChanged = hasUpdatedAt
            ? (lastRaceUpdatedAt === '' || String(updatedAt) !== lastRaceUpdatedAt)
            : (lastRaceFingerprint === '' || currentFingerprint !== lastRaceFingerprint);

        const positions = buildTargetPositions(ranking);
        const modelsByLane = assignModelsForRace(ranking);
        wrap.innerHTML = ranking.map(function (r, i) { return laneTemplate(r, positions[i] || 0, modelsByLane[i]); }).join('');
        
        // Verifica Ultrapassagem (Overtake Detection)
        let overtakeOccurred = "";
        
        ranking.forEach(function (r, i) {
            const key = safeKey(r.vendedor);
            const currentPosition = i + 1; // 1-indexed

            if (!isFirstLoad && hasDataChanged && lastRankByVendor[key] !== undefined) {
                // se ele tava numa posição maior (pior) e agora tá numa melhor (menor)
                if (currentPosition < lastRankByVendor[key]) {
                    const currentRevenue = Number(r.receita || 0);
                    const previousRevenue = Number(lastRaceRevenueByVendor[key] || 0);
                    const revenueMoved = Math.abs(currentRevenue - previousRevenue) > 0.009;
                    if (revenueMoved) {
                        // Ele subiu de posição com mudança real de receita
                        overtakeOccurred = r.vendedor;
                    }
                }
            }
        });

        if (overtakeOccurred && !isFirstLoad) {
            triggerOvertake(overtakeOccurred);
        }

        // Trigger animations
        setTimeout(function () {
            wrap.querySelectorAll('.cart').forEach(function (cart) {
                const target = cart.getAttribute('data-target-left');
                if (target) {
                    cart.style.left = target;
                    // Se estiver cruzando a linha de chegada (limite visual ~90%), pequeno efeito de brilho
                    if(parseFloat(target) > 90 && cart.classList.contains('leader')) {
                        setTimeout(() => triggerConfetti(), 2500); // Trigger again loosely for leader finishing
                    }
                }
            });
        }, 100);

        wrap.querySelectorAll('.cart-photo').forEach(function (img) {
            img.addEventListener('error', function () {
                const fb = img.getAttribute('data-fallback') || 'imagens/tv-carro.svg';
                if (img.getAttribute('src') !== fb) {
                    img.setAttribute('src', fb);
                    img.classList.add('cart-photo--fallback');
                }
            }, { once: true });
        });

        ranking.forEach(function (r, i) {
            const key = safeKey(r.vendedor);
            lastRankByVendor[key] = i + 1;
            lastPctByVendor[key] = Number(positions[i] || 0);
            lastRaceRevenueByVendor[key] = Number(r.receita || 0);
        });
        lastRaceFingerprint = currentFingerprint;
        lastRaceUpdatedAt = String(updatedAt || '').trim();
    }

    function triggerOvertake(nome) {
        playSound('overtake');
        const overlay = document.getElementById('overtakeOverlay');
        const textObj = document.getElementById('overtakeText');
        if(!overlay) return;

        textObj.innerHTML = `🔥 ULTRAPASSAGEM: <br> ${nome} 🔥`;
        overlay.classList.add('active');
        
        // Ativa o confetti "explosão no meio"
        if(typeof confetti !== 'undefined'){
            var duration = 4000;
            var animationEnd = Date.now() + duration;
            var defaults = { startVelocity: 40, spread: 360, ticks: 100, zIndex: 10000 };

            function randomInRange(min, max) { return Math.random() * (max - min) + min; }

            var interval = setInterval(function() {
                var timeLeft = animationEnd - Date.now();
                if (timeLeft <= 0) { return clearInterval(interval); }
                var particleCount = 80 * (timeLeft / duration);
                
                // Explode no centro da tela
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.4, 0.6), y: randomInRange(0.4, 0.6) } }));
            }, 250);
        }

        setTimeout(() => {
            overlay.classList.remove('active');
        }, 5000); // Overlay fica ativo por 5s
    }

    async function loadRace() {
        if (tvRequestInFlight) return;
        tvRequestInFlight = true;
        // Tentaremos pegar os dados do período padrão. O sistema de vcs aceita os mesmos params.
        const range = currentMonthRange();
        try {
            const data = await apiGet('tv_corrida_vendedores', Object.assign({}, range, { refresh_rd: 1 }));
            if (!data || data.success === false) return;

            const ranking = data.ranking || [];
            const max = Number(data.max_receita || 0);
            
            renderPodium(ranking);
            renderRace(ranking, max, data.updated_at || '');

            const p = data.periodo || {};
            const updated = data.updated_at || '--';
            const periodoEl = document.getElementById('tvPeriodo');
            const updEl = document.getElementById('tvUpdatedAt');
            if (periodoEl) {
                let inicio = p.data_de ? fmtDateBr(p.data_de) : fmtDateBr(range.data_de);
                let fim = p.data_ate ? fmtDateBr(p.data_ate) : fmtDateBr(range.data_ate);
                periodoEl.textContent = `Período: ${inicio} até ${fim}`;
            }
            if (updEl) {
                const stale = data.cache && data.cache.stale === true;
                updEl.textContent = stale
                    ? `Atualizado (cache): ${fmtDateTimeBr(updated)}`
                    : `Atualizado: ${fmtDateTimeBr(updated)}`;
            }
        } finally {
            tvRequestInFlight = false;
        }
    }

    function bindUi() {
        const fsBtn = document.getElementById('tvFullscreenBtn');
        if (fsBtn) {
            fsBtn.addEventListener('click', goFullscreen);
        }
    }

    async function goFullscreen() {
        if (document.fullscreenElement) {
            try { await document.exitFullscreen(); } catch (_) {}
            return;
        }
        try { await document.documentElement.requestFullscreen(); } catch (_) {}
    }

    function tryAutoFullscreen() {
        if (document.fullscreenElement) return;
        goFullscreen();
    }

    /** Navegadores de TV às vezes reportam viewport errado; visualViewport ajuda em alguns WebKit. */
    function applyTvLayoutVars() {
        var root = document.documentElement;
        var vv = window.visualViewport;
        var w = vv && vv.width ? vv.width : window.innerWidth;
        var h = vv && vv.height ? vv.height : window.innerHeight;
        if (!w || !h) return;
        root.style.setProperty('--app-vw', w + 'px');
        root.style.setProperty('--app-vh', h + 'px');
        root.style.setProperty('--app-vmin', Math.min(w, h) / 100 + 'px');
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindUi();
        resumeAudioOnFirstInteraction();
        applyTvLayoutVars();
        window.addEventListener('resize', applyTvLayoutVars);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', applyTvLayoutVars);
            window.visualViewport.addEventListener('scroll', applyTvLayoutVars);
        }
        loadRace().catch(function () {});
        setInterval(function () { loadRace().catch(function () {}); }, REFRESH_MS);
        // Forçar tela cheia ao abrir a página (tentativa imediata e após 300ms para quando o navegador exige primeiro frame)
        tryAutoFullscreen();
        setTimeout(tryAutoFullscreen, 300);
    });
})();
