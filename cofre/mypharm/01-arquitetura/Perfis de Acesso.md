---
tags: [seguranca, perfis, auth]
---

# Perfis de Acesso

## Tabela de Permissões

| Perfil | `tipo` no DB | Acesso |
|---|---|---|
| **admin** | `admin` | Tudo — configurações, relatórios, todos os usuários |
| **gestor** | `gestor` | Painel executivo, aprovações, metas, relatórios |
| **gerente** | `gerente` | Gestão da equipe, aprovação de revendas |
| **vendedor** | `vendedor` | Seus pedidos, comissões, ranking |
| **visitador** | `visitador` | Rotas, prescritores, bonificações |

## Setores vs Tipos

O sistema usa tanto `tipo` quanto `setor` na tabela `usuarios`:

- `tipo` — controla o nível de acesso (admin, gestor, vendedor…)
- `setor` — agrupa usuários para relatórios (Vendedor, Visitador, Gerente…)

## O que cada perfil vê

### Admin / Gestor
- Dashboard completo (todas as vendedoras)
- Gestão Comercial — painel executivo
- Clientes — cadastro e análise
- Controle de erros / rejeitados
- Configurações de usuários
- TV Ranking

### Vendedor
- Seu próprio dashboard (pedidos pessoais)
- Seus pedidos (vendedor-pedidos.html)
- Suas comissões e transferências
- Ranking (posição própria)
- Revendas (aprovação do gestor)

### Visitador
- Painel de visitação (visitador.html)
- Prescritores — cadastro e análise
- Rotas GPS — registro e histórico
- Bonificações

## Verificação no Backend

```php
// api/bootstrap.php
function requireAuth() {
    if (empty($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }
}

function requireRole(array $roles) {
    $tipo = $_SESSION['tipo'] ?? '';
    if (!in_array($tipo, $roles)) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão']);
        exit;
    }
}
```

## Links Relacionados
- [[Fluxo de Autenticacao]]
- [[Seguranca]]
- [[Tabela usuarios]]
