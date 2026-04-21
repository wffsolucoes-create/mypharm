---
tags: [arquitetura, diagrama]
---

# Arquitetura Geral

## Diagrama de Camadas

```
┌─────────────────────────────────────────────────────┐
│                   NAVEGADOR                         │
│  HTML + JS Vanilla          React (TV Ranking)      │
│  index.html, vendedor.html  /mypharm/tv/            │
└──────────────┬──────────────────────┬───────────────┘
               │ fetch/XHR            │ fetch (polling)
               ▼                      ▼
┌──────────────────────┐  ┌──────────────────────────┐
│   API Principal      │  │   TV API (PHP)           │
│   api.php            │  │   tv/api/index.php       │
│   api_gestao.php     │  │   tv/api/deals.php       │
│   api_vendedor.php   │  │   tv/api/divergencias.php│
│   api_bonus.php      │  └───────────┬──────────────┘
│   api_comparacao.php │              │ cURL
└──────────┬───────────┘              ▼
           │ PDO               ┌─────────────────┐
           ▼                   │ RD Station CRM  │
┌──────────────────────┐       │ API v1          │
│     MySQL            │       │ /deals          │
│  u936212550_my_pharm │       │ /users          │
│                      │       └─────────────────┘
│  usuarios            │
│  pedidos/vendas      │
│  comissoes           │
│  prescritores        │
│  bonificacoes        │
│  phusion_pedidos     │
└──────────────────────┘
```

## Estrutura de Pastas

```
/mypharm/
├── index.html                  # Dashboard principal
├── gestao-comercial.html       # Painel executivo
├── vendedor.html               # Painel do vendedor
├── visitador.html              # Painel do visitador
├── clientes.html               # Módulo clientes
├── bonificacoes.html           # Bonificações
├── tv-vendedores.html          # Painel TV (legado)
│
├── api.php                     # API principal (router)
├── api_gestao.php              # API gestão comercial
├── api_vendedor.php            # API vendedor
├── api_bonus.php               # API bonificações
├── api_comparacao.php          # API comparação períodos
│
├── api/
│   ├── bootstrap.php           # Inicialização comum
│   └── modules/
│       ├── dashboard.php
│       ├── gestao_comercial.php
│       ├── prescritores.php
│       ├── notificacoes.php
│       └── rotas_gps_lacunas.php
│
├── js/
│   ├── app.js                  # Dashboard + auth
│   ├── gestao-comercial.js
│   ├── vendedor-main.js
│   ├── visitador/
│   ├── vendedores/
│   └── shared/
│
├── tv/                         # TV Ranking (React/Vite)
│   ├── src/                    # Código fonte React
│   ├── dist/                   # Build produção
│   └── api/                    # PHP backend do TV
│
├── uploads/avatars/            # Fotos de perfil
├── .env                        # Credenciais (nunca commitar)
└── .htaccess                   # Segurança + CSP + rewrites
```

## Padrão de Módulo API

Cada módulo da API segue o padrão:

```php
// 1. Verificar autenticação
requireAuth();

// 2. Verificar permissão
requireRole(['admin', 'gestor']);

// 3. Roteamento por action
switch ($action) {
    case 'listar': // ...
    case 'salvar': // ...
}

// 4. Retornar JSON
echo json_encode(['success' => true, 'data' => $result]);
```

## Links Relacionados
- [[Estrutura de Pastas]]
- [[API Principal]]
- [[TV Ranking — React App]]
- [[Fluxo de Autenticacao]]
