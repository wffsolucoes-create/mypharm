# 🧠 MyPharm — Cérebro do Sistema

> Sistema web para gestão comercial de farmácia de manipulação.
> Roda em **XAMPP local** e **Hostinger produção**.

---

## 🗺️ Mapa do Conhecimento

### Fundamentos
- [[Visao Geral]] — o que é, para que serve, quem usa
- [[Stack Tecnologica]] — PHP, MySQL, JS vanilla, React
- [[Perfis de Acesso]] — admin, vendedor, visitador, gerente

### Arquitetura
- [[Arquitetura Geral]] — diagrama completo do sistema
- [[Fluxo de Autenticacao]] — sessão, CSRF, rate limit
- [[Estrutura de Pastas]] — onde fica cada coisa

### Banco de Dados
- [[Banco de Dados — Visao Geral]] — tabelas principais
- [[Tabela usuarios]] — perfis e permissões
- [[Tabela pedidos e vendas]] — core do negócio
- [[Tabela comissoes]] — cálculo e transferências

### Módulos do Sistema
- [[Modulo Dashboard]] — KPIs e gráficos principais
- [[Modulo Vendedor]] — painel, pedidos, comissão
- [[Modulo Visitador]] — rotas, prescritores, GPS
- [[Modulo Gestao Comercial]] — painel executivo
- [[Modulo Clientes]] — cadastro e análise
- [[Modulo Bonificacoes]] — visitadores médicos
- [[Modulo TV Ranking]] — painel gamificado ao vivo
- [[Modulo Revenda]] — fluxo de aprovação

### APIs
- [[API Principal]] — api.php + módulos
- [[API Gestao Comercial]] — api_gestao.php
- [[API Vendedor]] — api_vendedor.php
- [[API RD Station]] — integração CRM

### Frontend
- [[Padrao Visual e CSS]] — design system, cores, componentes
- [[JS Vanilla — Padroes]] — como o frontend funciona
- [[TV Ranking — React App]] — Vite + React + TypeScript

### Operação
- [[Deploy e Infraestrutura]] — XAMPP local vs Hostinger
- [[Variaveis de Ambiente]] — .env e segredos
- [[Seguranca]] — CSP, CSRF, sessões

### Decisões Técnicas
- [[Decisoes de Arquitetura]] — por que cada escolha foi feita
- [[Divida Tecnica]] — o que ainda precisa melhorar

---

## ⚡ Acesso Rápido

| O que preciso | Onde está |
|---|---|
| Adicionar vendedora ao TV | [[Modulo TV Ranking#Configurar Vendedoras]] |
| Entender o cálculo de comissão | [[Tabela comissoes]] |
| Ver endpoints da API | [[API Principal#Endpoints]] |
| Configurar meta individual | [[Modulo TV Ranking#Metas]] |
| Entender sessão/auth | [[Fluxo de Autenticacao]] |
| Adicionar módulo novo | [[Arquitetura Geral#Padrão de Módulo]] |

---

## 📅 Histórico Recente

- `2026-04` — Módulo TV Ranking integrado ao sistema principal
- `2026-04` — Gestão Comercial + Módulo Clientes adicionados
- `2026-03` — Revenda: fluxo de aprovação do gestor
- `2026-03` — Rotas GPS com detecção de lacunas

---

*Última atualização: Abril 2026*
