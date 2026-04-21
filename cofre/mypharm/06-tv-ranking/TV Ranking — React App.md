---
tags: [tv, react, typescript, vite]
---

# TV Ranking — React App

## Stack

- React 19 + TypeScript
- Vite 8 (build)
- Tailwind CSS 3.4
- TanStack Query (polling)
- Framer Motion (animações)
- Howler.js (sons)
- Canvas Confetti

## Estrutura

```
tv/src/
├── types/ranking.ts          # Interfaces TypeScript
├── services/api.ts           # fetch functions
├── hooks/
│   ├── useRankingData.ts     # polling 15s
│   ├── useDealsData.ts       # polling 30s
│   └── useAudio.ts           # efeitos sonoros
├── components/
│   ├── Ranking/
│   │   ├── RankingBoard.tsx  # componente principal
│   │   ├── Podium.tsx        # top 3
│   │   ├── RankingCard.tsx   # card individual
│   │   └── SellerDetailsModal.tsx
│   ├── Deals/DealsPage.tsx
│   ├── Import/ImportPage.tsx
│   └── Shared/
│       ├── AnimatedCounter.tsx
│       └── ProgressBar.tsx
└── App.tsx                   # roteamento
```

## Tipos Principais

```typescript
interface SellerRecord {
  id: number;
  nome: string;
  foto: string;             // URL ou '' para iniciais
  equipe: string;
  vendas_qtd: number;
  vendas_valor: number;
  pontuacao: number;        // qtd × 10
  meta_valor: number;
  percentual_meta: number;  // 0-100+
  posicao_atual: number;
  posicao_anterior: number; // para animação de subida/descida
  ultima_atualizacao: string;
}
```

## URL da API

Configurada em `src/services/api.ts`:
```typescript
const API_URL = import.meta.env.VITE_API_URL
             || `${import.meta.env.BASE_URL}api/`;
```

`BASE_URL` é definido pelo Vite conforme o `base` em `vite.config.ts`.
Atualmente: `/mypharm/tv/` → API em `/mypharm/tv/api/`.

## Variáveis de Ambiente (frontend)

Arquivo: `tv/.env` (opcional)
```env
VITE_API_URL=https://seudominio.com/api/   # override da URL
VITE_USE_MOCKS=false                        # true = dados mockados
```

## Efeitos Visuais

| Evento | Efeito |
|---|---|
| Vendedora subiu de posição | Som "levelup" |
| Vendedora atingiu 100% da meta | Som "goal" + confetti |
| Modal aberto | Detalhes com AnimatedCounter |

## Modo TV (fullscreen)

Rota `/mypharm/tv/tv` — sem header, indicador "AO VIVO" pulsante.
Ideal para projetar na TV da empresa.

## Build

```bash
cd /c/xampp/htdocs/mypharm/tv
npm run build
```

Gera `dist/` com assets hasheados. O `.htaccess` serve de lá.

> Sempre rebuildar após mudar código-fonte ou `vite.config.ts`.

## Links Relacionados
- [[Modulo TV Ranking]]
- [[API RD Station]]
