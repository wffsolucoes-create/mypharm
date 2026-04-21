---
tags: [arquitetura, stack]
---

# Stack Tecnológica

## Backend

| Tecnologia | Uso |
|---|---|
| **PHP 8.2** | Toda a lógica de servidor, sem framework |
| **PDO + MySQL** | Acesso ao banco, queries preparadas |
| **cURL** | Integração com RD Station CRM API |
| **Apache (XAMPP)** | Servidor web local e produção (Hostinger) |

> Filosofia: sem ORM, sem framework. PHP puro com funções bem organizadas em módulos.

## Banco de Dados

- **MySQL** via Hostinger (produção) e XAMPP (local)
- Charset: `utf8mb4`
- Timezone: `America/Porto_Velho` (UTC-4)
- Banco produção: `u936212550_my_pharm`

## Frontend — Sistema Principal

| Tecnologia | Uso |
|---|---|
| **HTML5** | Estrutura das páginas |
| **JS Vanilla** | Toda interatividade (sem framework) |
| **Chart.js 4** | Gráficos de vendas, KPIs, funil |
| **Font Awesome** | Ícones |
| **CSS Custom** | Design system próprio (sem Bootstrap) |

## Frontend — TV Ranking

| Tecnologia | Uso |
|---|---|
| **React 19** | SPA do painel de ranking |
| **TypeScript** | Tipagem forte |
| **Vite 8** | Build tool |
| **Tailwind CSS 3.4** | Styling |
| **Framer Motion** | Animações do ranking |
| **TanStack Query** | Polling automático (15s) |
| **Howler.js** | Efeitos sonoros |
| **Canvas Confetti** | Celebração ao atingir meta |

## Integrações Externas

| Serviço | Uso | Auth |
|---|---|---|
| **RD Station CRM v1** | Deals de vendas, usuários | Token no `.env` |
| **Nominatim (OSM)** | Geocoding de endereços | Sem auth (rate limit) |
| **ViaCEP** | Busca de endereços por CEP | Sem auth |

## Links Relacionados
- [[Arquitetura Geral]]
- [[API RD Station]]
- [[TV Ranking — React App]]
