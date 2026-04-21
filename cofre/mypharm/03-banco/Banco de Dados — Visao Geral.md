---
tags: [banco, mysql, schema]
---

# Banco de Dados — Visão Geral

## Conexão

| Ambiente | Host | Banco |
|---|---|---|
| Produção | `srv1845.hstgr.io` | `u936212550_my_pharm` |
| Local | `localhost` | `mypharm_db` |

Credenciais sempre via `.env` — nunca hardcoded no código.

## Tabelas Principais

```
usuarios                — Usuários do sistema (todos os perfis)
auth_audit_logs         — Auditoria de logins
prescritores            — Médicos/prescritores cadastrados
visitas                 — Registro de visitas dos visitadores
rotas_gps               — Pontos GPS das rotas
comissoes               — Comissões calculadas por pedido
comissao_transferencias — Transferências entre pedidos
bonificacoes            — Bonificações dos visitadores
clientes                — Cadastro de clientes
phusion_pedidos         — Pedidos importados do CSV Phusion (TV)
```

## Convenções

- **Charset:** `utf8mb4` (suporta emojis e caracteres especiais)
- **Engine:** `InnoDB` (suporta transações e FK)
- **Timestamps:** `criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
- **Soft delete:** campo `ativo TINYINT(1)` em vez de deletar
- **Timezone:** queries usam `CONVERT_TZ()` ou aplicação já converte

## Notas Importantes

> ⚠️ A tabela `comissao_transferencias` tem colunas `numero_pedido` e `serie_pedido`:
> - Obrigatórias nas transferências **novas**
> - `NULL` permitido nas transferências **antigas** (legado)

## Links Relacionados
- [[Tabela usuarios]]
- [[Tabela pedidos e vendas]]
- [[Tabela comissoes]]
- [[Variaveis de Ambiente]]
