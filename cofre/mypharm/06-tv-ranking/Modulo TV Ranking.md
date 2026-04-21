---
tags: [tv, ranking, react, rdstation]
---

# Módulo TV Ranking

Painel gamificado em tempo real para motivar a equipe de vendas.
Roda como SPA React separada em `/mypharm/tv/`.

## Como Funciona

```
1. React faz polling a cada 15s → GET /mypharm/tv/api/
2. PHP busca deals GANHOS no RD Station CRM (API v1)
3. Agrupa por vendedora → calcula ranking
4. Cache de 60s (evita excesso de chamadas ao RD)
5. Frontend detecta mudanças de posição → toca som
6. Se vendedora atingiu 100% da meta → confetti + som
```

## Rotas do Frontend

| URL | O que mostra |
|---|---|
| `/mypharm/tv/` | Ranking principal (pódio + lista) |
| `/mypharm/tv/tv` | Modo TV fullscreen (sem header) |
| `/mypharm/tv/vendas` | Lista detalhada de deals |
| `/mypharm/tv/import` | Upload CSV Phusion |

## Endpoints da API PHP

| Endpoint | Retorna |
|---|---|
| `GET /mypharm/tv/api/` | `SellerRecord[]` — ranking ordenado |
| `GET /mypharm/tv/api/deals.php` | `DealsResponse` — todos os deals |
| `GET /mypharm/tv/api/deals.php?seller=nome` | Deals filtrados por vendedora |
| `GET /mypharm/tv/api/divergencias.php` | Auditoria RD vs Phusion |
| `POST /mypharm/tv/api/upload_csv.php` | Importa CSV Phusion |

## Configurar Vendedoras

Arquivo: `tv/api/config.php`

```php
$SELLER_CONFIG = [
    // CHAVE = nome EXATO como aparece no RD Station CRM
    'Vitória Carvalho' => [
        'nome_exibicao' => 'Jessica Vitória',  // nome no painel
        'foto'   => '',                          // '' = iniciais | URL = foto real
        'equipe' => 'Equipe Capital',
        'meta'   => 25000                        // meta individual em R$
    ],
    // ...
];
```

> ⚠️ A chave do array deve ser o nome **exatamente** como está no RD Station.
> Se o nome no CRM mudar, a vendedora some do ranking.

## Adicionar Foto Real

1. Colocar a foto em `/mypharm/uploads/avatars/nome.jpg`
2. Editar `config.php`:
   ```php
   'foto' => '/mypharm/uploads/avatars/nome.jpg',
   ```
3. Limpar cache: deletar `tv/api/cache/ranking.json`

## Metas

- `META_GLOBAL` em `config.php` = meta padrão para quem não tem individual
- Meta individual: campo `meta` no `$SELLER_CONFIG` de cada vendedora
- Progresso exibido como barra + percentual colorido:
  - 🟠 abaixo de 100%
  - 🔵 acima de 100% (atingiu meta)

## Período do Ranking

Definido em `config.php`:
```php
define('PERIODO_RANKING', 'mensal'); // 'mensal' | 'semanal' | 'anual'
```
- **mensal** = do dia 1 até hoje (reinicia todo mês)
- **semanal** = segunda até hoje
- **anual** = 1º de janeiro até hoje

## Cache

- Arquivo: `tv/api/cache/ranking.json`
- TTL: 60 segundos (configurável em `CACHE_TTL`)
- Limpar manualmente: deletar o arquivo JSON
- O cache também preserva `posicao_anterior` para animar subidas

## Fonte dos Dados

Todos os dados vêm do **RD Station CRM API v1**:
- Deals com `win=true` no período configurado
- Token em `.env` → `RDSTATION_CRM_TOKEN`
- Endpoint: `https://crm.rdstation.com/api/v1/deals`

A tabela `phusion_pedidos` (MySQL) é usada apenas para auditoria via `divergencias.php`.

## Build e Deploy

```bash
cd /mypharm/tv
npm run build     # gera dist/
```

O `.htaccess` em `tv/` serve `dist/index.html` como padrão e
redireciona `assets/` → `dist/assets/`.

Ver: [[Deploy e Infraestrutura#TV Ranking]]

## Links Relacionados
- [[API RD Station]]
- [[TV Ranking — React App]]
- [[Deploy e Infraestrutura]]
- [[Variaveis de Ambiente]]
