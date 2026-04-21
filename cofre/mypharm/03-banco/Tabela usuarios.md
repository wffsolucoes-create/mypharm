---
tags: [banco, tabela, usuarios]
---

# Tabela: `usuarios`

## Schema

```sql
CREATE TABLE usuarios (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    usuario                   VARCHAR(100) NOT NULL UNIQUE,  -- login
    senha                     VARCHAR(255) NOT NULL,          -- bcrypt
    nome                      VARCHAR(150) NOT NULL,
    email                     VARCHAR(150),
    tipo                      ENUM('admin','gestor','gerente','vendedor','visitador'),
    setor                     VARCHAR(50),
    whatsapp                  VARCHAR(20),
    ativo                     TINYINT(1) DEFAULT 1,
    preferencia_menu_retraido TINYINT(1) DEFAULT 0,
    criado_em                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ultimo_acesso             TIMESTAMP NULL,
    meta_mensal               DECIMAL(10,2) DEFAULT 0,
    meta_anual                DECIMAL(10,2) DEFAULT 0,
    comissao_percentual       DECIMAL(5,2) DEFAULT 0,
    meta_visitas_semana       INT DEFAULT 0,
    meta_visitas_mes          INT DEFAULT 0,
    premio_visitas            DECIMAL(10,2) DEFAULT 0,
    foto_perfil               VARCHAR(255)   -- ex: 'avatars/9.jpg'
);
```

## Campos Importantes

| Campo | Descrição |
|---|---|
| `usuario` | Login único (não é o email) |
| `senha` | Hash bcrypt via `password_hash()` |
| `tipo` | Controla permissões de acesso |
| `setor` | Agrupamento para relatórios |
| `meta_mensal` | Meta de vendas individual (R$) |
| `comissao_percentual` | % de comissão sobre vendas |
| `meta_visitas_semana` | Para visitadores: meta de visitas/semana |
| `foto_perfil` | Caminho relativo dentro de `uploads/` |

## Fotos de Perfil

Arquivos em `/mypharm/uploads/avatars/`.
URL para exibir: `/mypharm/uploads/avatars/{foto_perfil}`

Usuários com foto real cadastrada (Abril 2026):
- `id=1` (Admin) → `avatars/4.png`
- `id=9` (Felipe) → `avatars/9.jpg`
- `id=13` (teste) → `avatars/13.png`

## Integração com TV Ranking

O campo `meta_mensal` pode ser usado como fonte da meta no TV Ranking,
mapeando pelo email do RD Station → usuário no banco.

Ver: [[Modulo TV Ranking#Configurar Vendedoras]]

## Links Relacionados
- [[Banco de Dados — Visao Geral]]
- [[Perfis de Acesso]]
- [[Fluxo de Autenticacao]]
