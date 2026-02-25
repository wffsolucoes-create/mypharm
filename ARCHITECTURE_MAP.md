# Mapeamento de Domínios (Estado Atual)

## Objetivo
Documentar as responsabilidades atuais para apoiar a refatoração incremental sem alterar comportamento.

## Back-end

Arquivo principal: `api.php`

### Domínios identificados por `action`
- **Infra/base**
  - `csrf_token`, `config`, `check_session`, `logout`, `db_info`
- **Dashboard/Admin Analytics**
  - `kpis`, `faturamento_mensal`, `faturamento_anual`, `faturamento_diario`
  - `top_formas`, `top_prescritores`, `top_clientes`, `top_atendentes`
  - `canais`, `anos`, `comparativo_anual`, `profissoes`, `cortesia`, `itens_status`
- **Visitadores**
  - `visitadores`, `visitador_dashboard`, `list_visitadores`
- **Prescritores**
  - `all_prescritores`, `transfer_prescritor`, `add_prescritor`
  - `get_prescritor_contatos`, `save_prescritor_whatsapp`
  - `get_prescritor_dados`, `update_prescritor_dados`
- **Visitas**
  - `visita_ativa`, `iniciar_visita`, `encerrar_visita`
  - `get_visitas_prescritor`, `get_detalhe_visita`, `update_detalhe_visita`
  - `admin_visitas`, `admin_visitas_relatorio`
- **Agenda**
  - `get_visitas_agendadas_mes`, `criar_agendamento`, `update_agendamento`, `excluir_agendamento`
- **Rota**
  - `rota_ativa`, `start_rota`, `pause_rota`, `resume_rota`, `finish_rota`, `save_rota_ponto`
- **Usuários/Perfil**
  - `login`, `list_users`, `add_user`, `edit_user`, `toggle_user`, `delete_user`, `edit_user_metas`
  - `upload_foto_perfil`, `get_meu_perfil`, `update_meu_perfil`

## Front-end

### `visitador.html`
- **Núcleo de tela e navegação**
  - troca de páginas internas, drawer, filtro, paginação
- **Prescritores (visitador)**
  - listagem, ordenação, busca, WhatsApp, editar, aprovados/recusados, relatório
- **Visita**
  - iniciar/encerrar, detalhe de visita, status, geolocalização
- **Agenda**
  - calendário mensal, CRUD de agendamentos
- **Rota**
  - iniciar/pausar/retomar/finalizar e envio de pontos
- **Perfil**
  - avatar/foto e edição de dados

### `prescritores.html`
- **Listagem admin de prescritores**
  - KPIs, filtros (visitador/ano/mês), ordenação, paginação
- **Ações por prescritor**
  - transferir, novo prescritor, editar dados, relatório de visitas, aprovados/recusados
- **Suporte**
  - tema, carregamento de anos, info de base, lista de visitadores

### `index.html`
- **Autenticação**
  - login e controle de sessão inicial

## Fronteiras sugeridas para módulos

### Back-end (PHP)
- `api/bootstrap.php` (sessão, conexão, helpers comuns)
- `api/router.php` (dispatch de actions)
- `api/modules/prescritores.php`
- `api/modules/visitas.php`
- `api/modules/agenda.php`
- `api/modules/rota.php`
- `api/modules/usuarios.php`
- `api/modules/dashboard.php`

### Front-end (JS)
- `assets/js/shared/api-client.js` (GET/POST + CSRF)
- `assets/js/shared/formatters.js`
- `assets/js/shared/dom-helpers.js`
- `assets/js/visitador/*.js` por feature
- `assets/js/prescritores/*.js` por feature

## Regras de migração incremental
- Manter todas as `action` atuais da API.
- Não alterar nomes de funções chamadas pelos botões atuais no HTML até o fim da migração.
- Extrair por domínio e validar regressão a cada passo.
