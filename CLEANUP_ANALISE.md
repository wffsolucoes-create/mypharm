# Limpeza Segura - Analise e Whitelist

Data: 2026-04-02

## Whitelist de preservacao

Itens preservados por regra para evitar risco operacional:

- Configuracao e seguranca: `.env`, `.env.example`, `.htaccess`, `config.php`, `api.php`, `api/`, `api/modules/`
- Frontend ativo: `index.html`, `visitador.html`, `prescritores.html`, `vendedor.html`, `vendedor-pedidos.html`, `gestao-comercial.html`, `controle-erros.html`, `rejeitados-clientes.html`, `rejeitados-prescritores.html`, `pedido-detalhe.html`, `tv-vendedores.html`
- Recursos e scripts de negocio: `js/`, `css/`, `scripts/`, `imagens/`, `manifest.json`, `robots.txt`
- Dados e operacao: `Dados/`, `Prescritores_Cadastrados.csv`, `Prescritores_Cadastrados.txt`, `Controle Erros/`, `Metas/`
- Midia e uploads: `uploads/`

## Itens avaliados como baixo risco (sem referencia funcional)

- `debug-6ce141.log` (log de debug gerado em runtime)
- `api_debug.log` (log de debug gerado em runtime)
- `.tmp_cookie.txt` (cookie temporario local)
- `storage_tv_cache/gc_tv_rd_v3_587c95ce6a9c27c096897ec00f45371a.json` (cache runtime)
- `fix_dashboard_patch.php` (script patch temporario one-shot, sem uso no fluxo atual)

## Resultado da execucao

Itens removidos:

- `debug-6ce141.log`
- `api_debug.log`
- `.tmp_cookie.txt`
- `storage_tv_cache/gc_tv_rd_v3_587c95ce6a9c27c096897ec00f45371a.json`
- `fix_dashboard_patch.php`

Validacoes de consistencia:

- Nao restaram arquivos `*.log` na raiz.
- Nao restaram arquivos `*.json` em `storage_tv_cache/` (cache regeneravel).
- `fix_dashboard_patch.php` removido.
- Estrutura principal do sistema preservada pela whitelist.

## Itens duvidosos (nao removidos)

- `rd-debug-dump.html`
- `js/rd-debug-dump.js`

Motivo: sao ferramentas de diagnostico local e podem ser necessarias em suporte.
