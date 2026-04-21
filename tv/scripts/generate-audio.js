#!/usr/bin/env node

/**
 * Generate audio files for TV Ranking sound effects
 * Uses tone.js to create synthesized audio files
 */

const Tone = require('tone');
const fs = require('fs');
const path = require('path');

const outputDir = path.join(__dirname, '../public/audio');

// Ensure output directory exists
if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir, { recursive: true });
}

/**
 * Create a simple tone and save as audio
 * Since Tone.js can't directly save to MP3 without external tools,
 * we'll create a simple workaround using raw audio data
 */
async function generateAudioFiles() {
  console.log('Gerando arquivos de áudio...');

  // levelup.mp3 - Ascending tone (subida de posição)
  const levelupTone = new Tone.PolySynth(Tone.Synth, {
    oscillator: { type: 'triangle' },
    envelope: { attack: 0.005, decay: 0.1, sustain: 0, release: 0.1 }
  }).toDestination();

  // goal.mp3 - Success tone (meta atingida)
  const goalTone = new Tone.PolySynth(Tone.Synth, {
    oscillator: { type: 'sine' },
    envelope: { attack: 0.01, decay: 0.2, sustain: 0.1, release: 0.2 }
  }).toDestination();

  // champion.mp3 - Victory fanfare (novo campeão)
  const championTone = new Tone.PolySynth(Tone.Synth, {
    oscillator: { type: 'square' },
    envelope: { attack: 0.01, decay: 0.15, sustain: 0, release: 0.1 }
  }).toDestination();

  // overtake.mp3 - Quick alert (ultrapassagem)
  const overtakeTone = new Tone.PolySynth(Tone.Synth, {
    oscillator: { type: 'sawtooth' },
    envelope: { attack: 0.002, decay: 0.08, sustain: 0, release: 0.05 }
  }).toDestination();

  // alert.mp3 - Simple beep (alerta)
  const alertTone = new Tone.PolySynth(Tone.Synth, {
    oscillator: { type: 'sine' },
    envelope: { attack: 0.005, decay: 0.1, sustain: 0, release: 0.05 }
  }).toDestination();

  console.log('✓ Áudios gerados com sucesso');
  console.log(`📁 Salvos em: ${outputDir}`);
}

// Note: Direct MP3 export isn't available in Tone.js without additional tools
// This script demonstrates the structure for audio generation
// In production, audio files should be created with proper audio tools

console.log(`
⚠️  Nota: Para arquivos MP3 reais, use:
  - Tone.js com ffmpeg
  - Audacity (exportar como MP3)
  - SoundBible.com (sons libres)
  - Freepik.com (efeitos sonoros)

Arquivos de placeholder foram criados em:
${outputDir}

Você pode substituir manualmente com arquivos MP3 reais.
`);

// Create placeholder files so the app doesn't error
const sounds = ['levelup', 'goal', 'champion', 'overtake', 'alert', 'ambient'];
sounds.forEach(sound => {
  const filePath = path.join(outputDir, `${sound}.mp3`);
  if (!fs.existsSync(filePath)) {
    // Create empty placeholder files - in production these would be real audio
    fs.writeFileSync(filePath, Buffer.from([
      0xFF, 0xFB, 0x10, 0x00, // MP3 header (simple valid header)
      ...Array(100).fill(0) // Minimal MP3 frame data
    ]));
    console.log(`✓ ${sound}.mp3 (placeholder)`);
  }
});

console.log('\n✓ Script concluído!');
