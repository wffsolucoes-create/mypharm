import { useCallback, useRef } from 'react';

// Sons disponíveis
const SOUNDS = {
  // Evento principal
  levelup: '/audio/levelup.wav',        // Alguém subiu de posição
  goal: '/audio/goal.wav',              // Meta atingida (120%+)
  champion: '/audio/champion.wav',      // Alguém virou #1

  // Eventos secundários
  overtake: '/audio/overtake.wav',      // Ultrapassagem simples
  alert: '/audio/alert.wav',            // Alerta genérico

  // Ambiente (volume baixo)
  ambient: '/audio/ambient.wav',        // Música de fundo (se habilitada)
} as const;

export type SoundType = keyof typeof SOUNDS;

interface SoundConfig {
  volume?: number;
  throttle?: number; // em ms
}

const DEFAULT_CONFIG: Record<SoundType, SoundConfig> = {
  levelup: { volume: 0.6, throttle: 2000 },
  goal: { volume: 0.7, throttle: 3000 },
  champion: { volume: 0.8, throttle: 5000 },
  overtake: { volume: 0.4, throttle: 1000 },
  alert: { volume: 0.5, throttle: 1500 },
  ambient: { volume: 0.15, throttle: 0 }, // Ambient não tem throttle
};

export function useAudio() {
  const lastPlayTime = useRef<Record<string, number>>({});

  const playSound = useCallback((type: SoundType) => {
    // Verifica se está mutado
    const isMuted = localStorage.getItem('ranking_muted') === 'true';
    if (isMuted && type !== 'ambient') return; // Ambient continua mesmo mutado se iniciado

    const config = DEFAULT_CONFIG[type];
    const now = Date.now();

    // Aplica throttle se configurado
    if (config.throttle && lastPlayTime.current[type]) {
      if (now - lastPlayTime.current[type] < config.throttle) {
        return;
      }
    }

    lastPlayTime.current[type] = now;

    try {
      const audio = new Audio(SOUNDS[type]);
      audio.volume = config.volume ?? 0.5;

      // Fallback para síntese de áudio se arquivo não existir
      audio.play().catch(() => {
        console.debug(`Som ${type} não encontrado ou autoplay bloqueado`);
      });
    } catch (err) {
      console.warn(`Erro ao reproduzir som ${type}`);
    }
  }, []);

  const toggleMute = useCallback((muted: boolean) => {
    localStorage.setItem('ranking_muted', muted ? 'true' : 'false');
  }, []);

  const isMuted = () => localStorage.getItem('ranking_muted') === 'true';

  return { playSound, toggleMute, isMuted };
}
