(function () {
    const API_URL = 'api_gestao.php';
    const REFRESH_MS = 15000;
    
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
    const lastPosByVendor = {};
    const lastReceitaByVendor = {};
    const assignedThemeIndices = {};
    let nextThemeIdx = 0;

    // Sons nas ações (Web Audio API - sem arquivos externos)
    var tvAudioCtx = null;
    function getAudioCtx() {
        if (tvAudioCtx) return tvAudioCtx;
        try {
            tvAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) { return null; }
        return tvAudioCtx;
    }
    function playSound(type) {
        var ctx = getAudioCtx();
        if (!ctx) return;
        try {
            var now = ctx.currentTime;
            var gain = ctx.createGain();
            gain.connect(ctx.destination);
            gain.gain.setValueAtTime(0.25, now);
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
        } catch (e) {}
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

    // Futuristic Spaceship SVG
    var SVG_SHIP = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 40" fill="currentColor"><path d="M10 20 L40 5 L80 15 L95 20 L80 25 L40 35 Z" opacity="0.9"/><polygon points="0,15 20,20 0,25" fill="#fff" opacity="0.6"/><polygon points="30,15 60,18 60,22 30,25" fill="#0f172a" opacity="0.5"/><circle cx="75" cy="20" r="3" fill="#fff" opacity="0.8"/></svg>';

    function laneTemplate(r, targetPct) {
        const key = safeKey(r.vendedor);
        const theme = pickTheme(key || r.posicao);
        const profile = getCarProfile(r.posicao);
        const prev = Number(lastPosByVendor[key] || 0);
        const startLeft = `${Math.max(0, Math.min(100, prev)).toFixed(2)}%`;
        const pct = Math.max(0, Math.min(100, Number(targetPct || 0)));
        const targetLeft = `${pct.toFixed(2)}%`;
        const pctMetaText = `${Math.max(0, Number(r.percentual_meta || 0)).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% meta`;
        const posLabel = r.posicao + 'º';

        return `<div class="lane">
            <div class="lane-label"><span class="lane-pos">${posLabel}</span> ${r.vendedor || '-'}</div>
            <div class="track">
                <div class="cart cart-img-wrap ${profile.cls}" data-vendor="${key}" data-target-left="${targetLeft}" data-money="${fmtMoney(r.receita || 0)} | ${pctMetaText}"
                     style="color:${theme.body}; left:${startLeft};">
                    <span class="cart-img">${SVG_SHIP}</span>
                </div>
                <div class="finish-line"></div>
                <div class="finish-flag"><i class="fas fa-flag-checkered"></i></div>
            </div>
        </div>`;
    }

    function buildTargetPositions(ranking) {
        const minGap = 2; // evita sobreposição na chegada
        const out = [];
        if (!Array.isArray(ranking) || !ranking.length) return out;

        let prev = null;
        for (let i = 0; i < ranking.length; i++) {
            const rawMetaPct = Number(ranking[i].percentual_meta || 0);
            let p = Math.max(0, Math.min(100, rawMetaPct));
            // Espalha se estourar o limite
            if(p > 100) p = 100;
            
            // Corrida disputada, não permite sobrepor visualmente quem tá na frente
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
            wrap.innerHTML = '<div class="race-empty">Sem vendas no período.</div>';
            return;
        }
        const positions = buildTargetPositions(ranking);
        wrap.innerHTML = ranking.map(function (r, i) { return laneTemplate(r, positions[i] || 0); }).join('');
        
        // Verifica Ultrapassagem (Overtake Detection)
        let overtakeOccurred = "";
        
        ranking.forEach(function (r, i) {
            const key = safeKey(r.vendedor);
            const currentPosition = i + 1; // 1-indexed

            if (!isFirstLoad && lastPosByVendor[key] !== undefined) {
                // se ele tava numa posição maior (pior) e agora tá numa melhor (menor)
                if (currentPosition < lastPosByVendor[key]) {
                    // Ele subiu de posição, logo houve ultrapassagem
                    overtakeOccurred = r.vendedor; 
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

        ranking.forEach(function (r, i) {
            const key = safeKey(r.vendedor);
            lastPosByVendor[key] = i + 1; // Guarda a posição (ranking final) e não a %
        });
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
        // Tentaremos pegar os dados do período padrão. O sistema de vcs aceita os mesmos params.
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
        if (periodoEl) {
            let inicio = p.data_de ? fmtDateBr(p.data_de) : fmtDateBr(range.data_de);
            let fim = p.data_ate ? fmtDateBr(p.data_ate) : fmtDateBr(range.data_ate);
            periodoEl.textContent = `Período: ${inicio} até ${fim}`;
        }
        if (updEl) updEl.textContent = `Atualizado: ${fmtDateTimeBr(updated)}`;
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

    document.addEventListener('DOMContentLoaded', function () {
        bindUi();
        loadRace().catch(function () {});
        setInterval(function () { loadRace().catch(function () {}); }, REFRESH_MS);
        // Forçar tela cheia ao abrir a página (tentativa imediata e após 300ms para quando o navegador exige primeiro frame)
        tryAutoFullscreen();
        setTimeout(tryAutoFullscreen, 300);
    });
})();
