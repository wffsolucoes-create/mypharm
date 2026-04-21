---
tags: [api, php, endpoints]
---

# API Principal

## Arquivos de API

| Arquivo | Rota base | Uso |
|---|---|---|
| `api.php` | `/api.php` | Auth, dashboard, visitador, prescritores |
| `api_gestao.php` | `/api_gestao.php` | Gestão comercial |
| `api_vendedor.php` | `/api_vendedor.php` | Painel vendedor |
| `api_bonus.php` | `/api_bonus.php` | Bonificações |
| `api_comparacao.php` | `/api_comparacao.php` | Comparação de períodos |

## Bootstrap Comum

`api/bootstrap.php` — incluído por todos os endpoints:
- Inicia sessão
- Define funções `requireAuth()`, `requireRole()`
- Conecta ao banco via PDO
- Define `json_response()` helper

## Padrão de Chamada (Frontend)

```javascript
// js/shared/api.js (ou inline nos módulos)
async function apiPost(action, data = {}) {
    const response = await fetch('/mypharm/api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken()
        },
        body: JSON.stringify({ action, ...data })
    });
    return response.json();
}
```

## Endpoints — api.php

```
POST action=login          → Autenticação
POST action=logout         → Encerrar sessão
GET  action=dashboard      → KPIs do dashboard
GET  action=pedidos        → Lista de pedidos
GET  action=prescritores   → Lista prescritores
POST action=salvar_prescritor
GET  action=notificacoes
```

## Endpoints — api_gestao.php

```
GET  action=resumo_vendas    → KPIs executivos
GET  action=funil            → Dados do funil comercial
GET  action=ranking_equipe   → Ranking de vendedores
GET  action=comparativo      → Comparação de períodos
GET  action=clientes_kpis    → KPIs do módulo clientes
```

## Endpoints — api_vendedor.php

```
GET  action=meus_pedidos     → Pedidos do vendedor logado
GET  action=minhas_comissoes → Comissões calculadas
GET  action=transferencias   → Transferências de comissão
POST action=solicitar_transferencia
GET  action=revendas         → Gestão de revendas
POST action=aprovar_revenda
```

## Módulos em `api/modules/`

| Arquivo | Responsabilidade |
|---|---|
| `dashboard.php` | KPIs principais, gráficos |
| `gestao_comercial.php` | Relatórios executivos |
| `prescritores.php` | CRUD prescritores |
| `notificacoes.php` | Sistema de notificações |
| `rotas_gps_lacunas.php` | Detecção de lacunas GPS |

## Links Relacionados
- [[Arquitetura Geral]]
- [[Fluxo de Autenticacao]]
- [[API RD Station]]
