---
tags: [api, rdstation, integracao]
---

# API RD Station CRM

## Versão e Auth

- **Versão:** v1 (token como query parameter)
- **Base URL:** `https://crm.rdstation.com/api/v1`
- **Autenticação:** `?token=SEU_TOKEN` em todos os endpoints
- **Token:** armazenado em `.env` → `RDSTATION_CRM_TOKEN`

> ⚠️ API v1 usa token direto na URL. Não é OAuth.

## Endpoints Usados

### GET /deals

Busca oportunidades (deals).

```
GET /deals?token=...&page=1&limit=200&win=true&start_date=2026-04-01&end_date=2026-04-15
```

| Parâmetro | Descrição |
|---|---|
| `win=true` | Apenas deals ganhos |
| `start_date` | Início do período (Y-m-d) |
| `end_date` | Fim do período (Y-m-d) |
| `page` | Paginação (começa em 1) |
| `limit` | Máximo 200 por página |

**Campos do deal retornado:**
```json
{
  "_id": "...",
  "name": "Nome do deal",
  "win": true,
  "closed_at": "2026-04-10T14:00:00.000-03:00",
  "amount_total": 1500.00,
  "amount_montly": 0,
  "amount_unique": 0,
  "rating": 3,
  "user": {
    "_id": "...",
    "id": "...",
    "name": "Nereida",
    "nickname": "NE",
    "email": "nereida@mypharm.com.br"
  },
  "contacts": [...],
  "organization": { "name": "..." },
  "deal_stage": { "name": "..." }
}
```

> ℹ️ **Sem foto de usuário** — a API v1 não retorna avatar/foto nos deals.

### GET /users

Lista todos os usuários do CRM.

```
GET /users?token=...
```

Retorna array de usuários com `id`, `name`, `nickname`, `email`, `active`.

**Não tem endpoint `/users/{id}`** — retorna 0 ao tentar.

## Lógica de Valor

O campo `amount_total` nem sempre está preenchido.
Fallback usado no código:

```php
function getAmountFromDeal(array $deal): float {
    $amount = (float)($deal['amount_total'] ?? 0);
    if ($amount <= 0) {
        $amount  = (float)($deal['amount_montly'] ?? 0);
        $amount += (float)($deal['amount_unique'] ?? 0);
    }
    return $amount;
}
```

## Cache Local

Para evitar excesso de chamadas ao RD Station:
- `tv/api/cache/ranking.json` — TTL 60s
- `tv/api/cache/deals.json` — TTL 60s

## Rate Limit

A API v1 não documenta rate limit explícito, mas o sistema
pagina até 15 páginas × 200 deals = 3.000 deals por requisição.

## Usuários Cadastrados (Abril 2026)

| Nome no CRM | Email |
|---|---|
| Vitória Carvalho | vitoria@mypharm.com.br |
| Nereida | nereida@mypharm.com.br |
| Nailena | nailena@mypharm.com.br |
| Ananda | anandareis@mypharm.com.br |
| Clara Letícia | clara@mypharm.com.br |
| Carla Pires - Consultora | carla@mypharm.com.br |
| Giovanna | giovanna@mypharm.com.br |
| Micaela Nicolle | micaela@mypharm.com.br |
| Mariana | mariana@mypharm.com.br |
| Renata Kichileski | renata@mypharm.com.br |

## Links Relacionados
- [[Modulo TV Ranking]]
- [[Variaveis de Ambiente]]
