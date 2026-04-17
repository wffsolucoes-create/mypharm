MyPharm — Sons do ranking (TV)
================================

Coloque aqui os arquivos MP3 com estes nomes EXATOS:

  ranking-update.mp3   — atualização geral do painel (discreto, curto)
  rank-up.mp3          — consultora subiu de posição
  top3-enter.mp3       — consultora entrou no Top 3
  meta-hit.mp3         — meta ≥ 100%
  warning.mp3          — falha de atualização / alerta crítico
  click-soft.mp3       — clique em botões principais

Ativação dos sons (manifesto)
-----------------------------
O frontend agora usa um catálogo para evitar erros 404 quando os MP3 ainda
não existem. Edite este arquivo após subir os sons:

  assets/sounds/manifest.json

Exemplo:
{
  "available": [
    "ranking-update.mp3",
    "rank-up.mp3",
    "top3-enter.mp3",
    "meta-hit.mp3",
    "warning.mp3",
    "click-soft.mp3"
  ]
}

Onde obter (licenças próprias — verifique termos de cada site):
  • https://mixkit.co/free-sound-effects/  (Interface, Technology)
  • https://pixabay.com/sound-effects/     (ui notification)
  • https://www.zapsplat.com               (UI)
  • https://freesound.org                (interface, notification)

Sugestão de busca por arquivo — ver especificação do projeto (ranking TV).

Enquanto os arquivos não existirem, o SoundManager ignora o play silenciosamente
(sem quebrar a página).

Caminho no código
-----------------
A partir de tv/index.html o script usa baseUrl: ../assets/sounds/
Arquivos de código:
  js/sound-manager.js   — classe MyPharmSoundManager
  tv/index.html         — inclusão do script, init e gatilhos (ranking / botões)

Substituir um som: mantenha o nome do arquivo ou altere SOUND_FILES em sound-manager.js.
