---
tags: [divida-tecnica, melhorias, backlog]
---

# Dívida Técnica

Coisas que funcionam mas poderiam ser melhores.
Organizado por prioridade.

---

## 🔴 Alta Prioridade

### Fotos reais das vendedoras no TV Ranking
**Situação:** Atualmente exibe iniciais (sem foto). Pravatar foi removido por mostrar fotos de estranhos.
**Solução ideal:** Upload de fotos reais em `uploads/avatars/` e mapeamento no `config.php`.
**Alternativa:** Gravatar com fallback para iniciais (usa email do RD Station).

### Token RD Station exposto em texto plano
**Situação:** Token fica no `.env` e é enviado em query string para API externa (API v1).
**Risco:** Se alguém interceptar o tráfego servidor→RD Station, vê o token.
**Solução:** Migrar para API v2 com OAuth. Complexidade maior.

---

## 🟡 Média Prioridade

### Sem testes automatizados
**Situação:** Zero testes no backend PHP e no frontend JS.
**Impacto:** Mudanças arriscam quebrar funcionalidades existentes.
**Solução:** Ao menos testes de integração nas APIs principais.

### config.php do TV com dados hardcoded
**Situação:** Nomes das vendedoras, metas e equipes estão hardcoded no PHP.
**Impacto:** Toda mudança de vendedora exige editar arquivo PHP.
**Solução:** Painel de admin para gerenciar o `$SELLER_CONFIG` via banco de dados.

### Duplicação de lógica de período
**Situação:** A lógica de calcular `start_date`/`end_date` existe em `index.php` e `deals.php`.
**Solução:** Extrair para função compartilhada em `tv/api/utils.php`.

---

## 🟢 Baixa Prioridade

### `tv-vendedores.html` legado
**Situação:** Existe uma versão antiga do TV em HTML/JS vanilla (`tv-vendedores.html`).
**Ação:** Decidir se mantém como fallback ou deprecia.

### Geocaching de endereços sem TTL
**Situação:** Cache de geocoding no banco não expira.
**Impacto:** Endereços que mudam continuam com coordenadas antigas.

### Múltiplos `api_*.php` na raiz
**Situação:** `api_gestao.php`, `api_vendedor.php`, `api_bonus.php` são routers separados.
**Solução futura:** Unificar em um único router com namespace de módulos.

---

## ✅ Resolvido Recentemente

- ~~Token e DB do TV Ranking hardcoded~~ → agora usa `.env` da raiz
- ~~Banco separado `u936212550_ranking`~~ → consolidado no banco principal
- ~~Pravatar mostrando fotos de estranhos~~ → removido, usa iniciais

## Links Relacionados
- [[Decisoes de Arquitetura]]
- [[Modulo TV Ranking]]
