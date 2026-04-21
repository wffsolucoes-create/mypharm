#!/usr/bin/env python3
"""
Generate simple audio files for TV Ranking sound effects
Creates WAV files with synthesized tones
"""

import wave
import struct
import math
import os

def create_audio_file(filename, frequency, duration, output_dir='../public/audio'):
    """Create a simple sine wave audio file"""
    sample_rate = 44100
    num_samples = int(sample_rate * duration)

    # Ensure output directory exists
    os.makedirs(output_dir, exist_ok=True)
    filepath = os.path.join(output_dir, filename)

    with wave.open(filepath, 'w') as wav_file:
        wav_file.setnchannels(1)  # Mono
        wav_file.setsampwidth(2)  # 16-bit
        wav_file.setframerate(sample_rate)

        # Generate sine wave
        for i in range(num_samples):
            # Envelope (fade in/out)
            if i < sample_rate * 0.05:  # 50ms attack
                envelope = i / (sample_rate * 0.05)
            elif i > num_samples - sample_rate * 0.1:  # 100ms release
                envelope = (num_samples - i) / (sample_rate * 0.1)
            else:
                envelope = 1.0

            # Generate sine wave
            value = 32767 * 0.3 * envelope * math.sin(2 * math.pi * frequency * i / sample_rate)
            wav_file.writeframes(struct.pack('<h', int(value)))

    print(f'[OK] {filename}')

# Create audio files with different frequencies and durations
print('Gerando arquivos de áudio...\n')

sounds = {
    'levelup.wav': (440, 0.3),      # A4, ascending tone
    'goal.wav': (523, 0.5),         # C5, success tone
    'champion.wav': (659, 0.7),     # E5, victory tone
    'overtake.wav': (392, 0.2),     # G4, quick alert
    'alert.wav': (330, 0.15),       # E4, simple beep
    'ambient.wav': (261, 2.0),      # C4, background drone (longer)
}

os.chdir(os.path.dirname(os.path.abspath(__file__)))

for filename, (frequency, duration) in sounds.items():
    try:
        create_audio_file(filename, frequency, duration)
    except Exception as e:
        print(f'[ERROR] Erro ao criar {filename}: {e}')

print('\n[OK] Arquivos de áudio criados com sucesso!')
print('[DIR] Salvos em: public/audio/')
print('\n[INFO] Nota: Estes sao audios simples de teste.')
print('Para producao, substitua com audios reais de qualidade.')
