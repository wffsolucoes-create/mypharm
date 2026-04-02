# MyPharm - Sistema de Gestao Comercial e Visitas

Sistema web para gestao de farmacia de manipulacao com foco em:
- indicadores de faturamento e performance,
- carteira de prescritores,
- operacao de visitadores em campo,
- rotas com GPS e historico de visitas,
- importacao de dados via CSV/CLI.

---

## Sumario

1. [Visao geral](#visao-geral)
2. [Funcionalidades principais](#funcionalidades-principais)
3. [Arquitetura e stack](#arquitetura-e-stack)
4. [Estrutura do projeto](#estrutura-do-projeto)
5. [Instalacao local](#instalacao-local)
6. [Configuracao de ambiente](#configuracao-de-ambiente)
7. [Banco de dados](#banco-de-dados)
8. [Fluxo de visitas e rotas](#fluxo-de-visitas-e-rotas)
9. [Importacao de dados](#importacao-de-dados)
10. [API (resumo por dominios)](#api-resumo-por-dominios)
11. [Seguranca](#seguranca)
12. [Deploy em producao (Hostinger)](#deploy-em-producao-hostinger)
13. [Operacao e troubleshooting](#operacao-e-troubleshooting)
14. [Roadmap curto](#roadmap-curto)

---

## Visao geral

O MyPharm centraliza informacoes de vendas, prescritores e visitas comerciais em uma unica aplicacao.

O sistema possui dois contextos principais:

- **Painel Admin (`index.html`)**
  - dashboards de faturamento, prescritores, visitadores e visitas;
  - filtros por ano/mes;
  - relatorio de rotas e mapa consolidado com pontos de atendimento;
  - gerenciamento de usuarios e metas.

- **Painel Visitador (`visitador.html`)**
  - controle da rota do dia (iniciar, pausar, retomar, finalizar);
  - iniciar/encerrar visita com coleta de GPS;
  - agenda e relatorio semanal com detalhes da visita;
  - modal detalhado com inicio, fim, duracao e proximo agendamento.

---

## Funcionalidades principais

### 1) Dashboards administrativos
- KPIs por periodo (visitas, faturamento, produtividade).
- Graficos com Chart.js e filtros por ano/mes.
- Tabelas de detalhamento por visitador, prescritor e rotas.

### 2) Gestao de visitas
- Abertura e encerramento de visita por prescritor.
- Registro de status, local, resumo, itens entregues e reagendamento.
- Captura de geolocalizacao no encerramento.

### 3) Rota do dia (visitador)
- Estado da rota: `idle`, `em_andamento`, `pausada`.
- Gravacao periodica de pontos GPS.
- Calculo de km percorrido por rota.

### 4) Mapa de rotas e atendimentos
- Exibicao de trilhas por visitador.
- Marcacao de pontos de atendimento com popup do prescritor.
- Filtro por visitador no mapa.

### 5) Relatorio de visitas (admin)
- totais do periodo e semana atual;
- visitas por visitador;
- detalhes das rotas (inicio/fim/status/km/pontos);
- visitas realizadas com inicio, fim, duracao e proximo agendamento.

### 6) Importacao de base historica
- suporte a importacao por CSV e scripts CLI;
- carga por ano (incluindo 2026) e reprocessamento quando necessario.

---

## Arquitetura e stack

### Backend
- PHP 8+
- API unica em `api.php`
- PDO com MySQL

### Frontend
- HTML + CSS + JavaScript (vanilla)
- Chart.js (graficos)
- Leaflet + OpenStreetMap (mapas)
- Font Awesome (icones)

### Persistencia
- MySQL (Hostinger/XAMPP)
- Sessao PHP + dados auxiliares em `localStorage` (ex.: tema por usuario)

---

## Estrutura do projeto

```text
mypharm/
├── api.php
├── config.php
├── index.html
├── visitador.html
├── prescritores.html
├── .htaccess
├── .env
├── css/
│   ├── styles.css
│   ├── visitador.css
│   └── login.css
├── js/
│   └── app.js
├── scripts/
│   ├── importar_dados.php
│   ├── importar_itens_cli.php
│   ├── importar_tudo_cli.php
│   └── analisar_banco.php
├── Dados/
├── imagens/
├── manifest.json
└── robots.txt
```

---

## Instalacao local

### Requisitos
- PHP 8.0+
- MySQL 8+ (ou compativel)
- Apache (XAMPP recomendado local)

### Passos
1. Copie o projeto para:
   - `C:\xampp\htdocs\mypharm`
2. Crie/ajuste o arquivo `.env` com as credenciais locais.
3. Garanta que Apache e MySQL estejam ativos no XAMPP.
4. Acesse:
   - `http://localhost/mypharm/index.html`

---

## Configuracao de ambiente

Exemplo de `.env`:

```ini
DB_HOST=127.0.0.1
DB_NAME=mypharm
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
APP_ENV=local
```

Notas:
- `config.php` ja aplica configuracoes de conexao e seguranca de sessao.
- Timezone operacional de dados foi padronizado para Porto Velho (`-04:00` / `America/Porto_Velho` no frontend).

### Integracao RD Station CRM (opcional)

Para usar dados do **RD Station CRM** na TV Corrida de Vendas e em partes do visitador (deals ganhos/perdidos, funil):

1. No `.env`, adicione o token privado do RD Station:
   ```ini
   RDSTATION_CRM_TOKEN=seu_token_privado_aqui
   ```
2. O token e obtido em: RD Station CRM → Configuracoes → API → Chave de integracao (token privado).
3. Endpoint utilizado: `https://crm.rdstation.com/api/v1/deals` (GET, parametros: `token`, `win`, `start_date`, `end_date`, `page`, `limit`).
4. Onde e usado: `api_gestao.php` (TV Corrida), `api/rdstation_tv.php` (proxy e mapeamento de vendedoras). Sem o token, o sistema usa o banco local (gestao_pedidos).
5. **Métricas completas:** o endpoint `api_gestao.php?action=gestao_rd_metricas` (GET, admin) retorna todas as métricas agregadas do RD Station no período (`data_de`, `data_ate`): receita total, total ganhos/perdidos, conversão geral, oportunidades abertas, funil por estágio, por vendedor (receita, conversão, tempo médio, motivos de perda, origem dos deals), motivos de perda gerais e origens gerais. O dashboard `gestao_comercial_dashboard` pode incluir `rd_metricas` no mesmo JSON **ou** receber `skip_rd=1` para não bloquear a resposta na API externa; nesse caso o front da Gestão Comercial chama `gestao_rd_metricas` em segundo plano quando `rd_metricas_deferred` vier `true` (token RD configurado).
6. **Totais vs TV Corrida:** na Gestão Comercial (`rdFetchTodasMetricas`), ganhos/perdas e receita somam **todos** os responsáveis retornados pela API no intervalo (para aproximar o funil/Kanban). Na **TV Corrida**, o ranking continua limitado ao **mapeamento** em `api/rdstation_tv.php` (`rdtvGetMapeamento`). Pequenas diferenças vs o Kanban ainda podem ocorrer por regra de data (fechamento vs criação) ou por colunas que somam negociações ainda abertas no RD.

---

## Banco de dados

Tabelas centrais utilizadas pelo sistema:

- `historico_visitas`
- `visitas_geolocalizacao`
- `rotas_diarias`
- `rotas_pontos`
- `prescritores_cadastro`
- `prescritor_resumido`
- `itens_orcamentos_pedidos`
- `gestao_pedidos`
- `usuarios`
- `metas_visitadores`

Observacoes:
- algumas estruturas sao criadas sob demanda na API (ex.: tabelas de GPS/agendamento).
- campos de visita incluem suporte a `inicio_visita`, `horario` (fim), `status_visita`, `reagendado_para` e dados de localizacao.

---

## Fluxo de visitas e rotas

### Visita (visitador)
1. Inicia visita para um prescritor.
2. Finaliza visita preenchendo:
   - status,
   - local,
   - resumo,
   - itens (amostra/brinde/artigo),
   - proximo agendamento (opcional).
3. Sistema grava horario de inicio/fim, duracao e ponto GPS.

### Rota do dia
1. Iniciar rota.
2. Capturar pontos GPS periodicos.
3. Pausar/retomar quando necessario.
4. Finalizar rota ao encerrar o dia.

### Consolidacao no admin
- mapa mostra trilhas da rota e pontos de atendimento;
- popup do ponto de atendimento exibe dados do prescritor.

---

## Importacao de dados

### Via painel (web)
- `scripts/importar_dados.php`
- uso para cargas administradas por usuario logado.

### Via CLI
- `scripts/importar_itens_cli.php`
- `scripts/importar_tudo_cli.php`

Exemplo CLI:

```bash
cd C:\xampp\htdocs\mypharm
php scripts/importar_tudo_cli.php
```

### Ferramenta de apoio
- `scripts/analisar_banco.php` para diagnostico/analise de base.

### Carteira inativa → My Pharm (40 dias sem visita)
A partir de **02/03/2026** a contagem de visitas para a regra de inatividade e feita em separado (historico anterior preservado). Prescritores que ficarem **mais de 40 dias sem visita** (considerando apenas visitas a partir de 02/03/2026) sao movidos automaticamente para a carteira **My Pharm**.

- **Script CLI (cron):** `scripts/mover_carteira_inativa_mypharm.php`
  - Execucao diaria recomendada, ex.: `0 6 * * * cd /caminho/mypharm && php scripts/mover_carteira_inativa_mypharm.php`
- **API (admin):** `GET/POST api.php?action=run_carteira_inativa_mypharm` — executa a rotina e retorna quantidade e lista de prescritores movidos.

Constantes em `scripts/carteira_inativa_mypharm.php`: `CARTEIRA_INATIVA_DATA_INICIO` (2026-03-02), `CARTEIRA_INATIVA_DIAS` (40).

---

## API (resumo por dominios)

### Autenticacao e sessao
- `login`
- `logout`
- `csrf_token`

### Dashboard/admin
- `dashboard`
- `anos`
- `admin_visitas`
- `admin_visitas_relatorio` — totais, por visitador, rotas/km, mapa; também `prescritores_mais_visitados`, `prescritores_sem_visita_periodo` (carteira `prescritores_cadastro` sem visita no intervalo, alinhada ao filtro de visitador quando aplicável) e KPIs `prescritores_distintos_visitados`, `prescritores_carteira_total`, `prescritores_sem_visita_count` (os percentuais visitados/não visitados sobre a carteira são calculados no front a partir destes dois últimos).
- `run_carteira_inativa_mypharm` — move para My Pharm prescritores com 40+ dias sem visita (desde 02/03/2026); apenas admin.

### Visitador
- `visitador_dashboard`
- `visita_ativa`
- `iniciar_visita`
- `encerrar_visita`
- `rota_ativa`
- `start_rota`
- `pause_rota`
- `resume_rota`
- `stop_rota`
- `save_rota_ponto`

### Usuarios
- `list_users`
- `add_user`
- `edit_user`
- `toggle_user`
- `delete_user`
- `edit_user_metas`

---

## Seguranca

Controles aplicados:
- CSRF token em requisicoes POST;
- sessao com flags de seguranca;
- prepared statements em toda camada SQL;
- CORS com validacao;
- bloqueios via `.htaccess` para arquivos sensiveis (`.env`, `config.php`, `Dados/`, etc.);
- validacao de perfil para acoes administrativas.

---

## Deploy em producao (Hostinger)

Voce pode publicar de duas formas:

### Opcao A - Git
1. Commit/push para o repositorio remoto.
2. No painel da Hostinger, execute pull/deploy do branch alvo.

### Opcao B - FTP/File Manager
1. Envie os arquivos para `public_html/mypharm/`.
2. Configure `.env` de producao.
3. Verifique `.htaccess` ativo.

Exemplo de `.env` de producao:

```ini
DB_HOST=srv1845.hstgr.io
DB_NAME=uXXXXXXXXX_my_pharm
DB_USER=uXXXXXXXXX_my_pharm
DB_PASS=SUA_SENHA
DB_CHARSET=utf8mb4
APP_ENV=production
```

Checklist rapido:
- [ ] arquivos atualizados no servidor
- [ ] `.env` correto
- [ ] HTTPS funcionando
- [ ] login e filtros testados
- [ ] fluxo visita/rota testado
- [ ] mapa de visitas carregando

---

## Operacao e troubleshooting

### Tema claro/escuro
- o tema fica salvo por usuario no `localStorage`:
  - `mypharm_theme_<usuario>`

### Mapa sem pontos
- confirme se houve encerramento de visita com GPS;
- valide permissao de geolocalizacao no navegador;
- confira periodo/visitador selecionados.

### API lenta
- revisar indices das tabelas de visitas e pedidos;
- conferir latencia entre PHP e MySQL;
- habilitar opcache em producao.

### Erro 403
- validar regras de CORS/CSRF;
- revisar permissao por tipo/setor do usuario.

---

## Roadmap curto

- clusterizacao de marcadores no mapa para bases grandes;
- filtros adicionais no relatorio de visitas;
- exportacao CSV/PDF dos relatorios;
- limpeza de codigo legado no painel do visitador.

---

## Licenca e uso

Projeto interno MyPharm (uso privado da operacao).

# MyPharm - Sistema de Gestao e Performance

Sistema web para gestao de farmacia de manipulacao, com dashboards de performance, controle de visitas, prescritores e equipe comercial.

---

## Indice

1. [Visao Geral](#visao-geral)
2. [Estrutura do Projeto](#estrutura-do-projeto)
3. [Requisitos](#requisitos)
4. [Instalacao](#instalacao)
5. [Configuracao](#configuracao)
6. [Paginas da Aplicacao](#paginas-da-aplicacao)
7. [API - Endpoints](#api---endpoints)
8. [Banco de Dados](#banco-de-dados)
9. [Seguranca](#seguranca)
10. [Scripts de Importacao](#scripts-de-importacao)
11. [Deploy (Hostinger)](#deploy-hostinger)

---

## Visao Geral

O MyPharm e um sistema de Business Intelligence para farmacias de manipulacao. Ele oferece:

- **Dashboard Administrativo** com KPIs de faturamento, prescritores, produtos e equipe
- **Painel do Visitador** com metas, controle de visitas (GPS), agendamentos e alertas inteligentes
- **Gestao de Prescritores** com ranking, transferencia de carteira e historico
- **Importacao de Dados** a partir de CSVs do sistema da farmacia
- **Controle de Usuarios** com permissoes por tipo (admin/usuario) e setor (visitador)

### Stack Tecnologica

| Camada     | Tecnologia                                       |
|------------|--------------------------------------------------|
| Backend    | PHP 8+ (PDO/MySQL)                               |
| Banco      | MySQL 8 (Hostinger ou XAMPP local)               |
| Frontend   | HTML5, CSS3, JavaScript (vanilla)                |
| Graficos   | Chart.js 4.x + chartjs-plugin-datalabels        |
| Mapas      | Leaflet.js + OpenStreetMap                       |
| Icones     | Font Awesome 6.x                                |
| Servidor   | Apache (XAMPP local / Hostinger producao)        |

---

## Estrutura do Projeto

```
mypharm/
├── css/                          # Estilos
│   ├── login.css                 #   Pagina de login
│   ├── styles.css                #   Dashboard admin
│   └── visitador.css             #   Painel do visitador
├── Dados/                        # CSVs para importacao (bloqueado via .htaccess)
├── imagens/                      # Logo e icones
│   ├── IconeMyPharm.png
│   └── logoMypharm.png
├── js/
│   └── app.js                    # Logica principal do frontend
├── scripts/                      # Scripts utilitarios
│   ├── importar_dados.php        #   Importacao via web (requer sessao)
│   └── importar_itens_cli.php    #   Importacao via terminal (CLI only)
├── .env                          # Credenciais do banco (bloqueado)
├── .htaccess                     # Regras de seguranca Apache
├── api.php                       # API REST principal
├── config.php                    # Configuracao e conexao DB (bloqueado)
├── index.html                    # Login + Dashboard admin
├── prescritores.html             # Ranking de prescritores
├── visitador.html                # Painel do visitador
├── manifest.json                 # PWA manifest
├── robots.txt                    # Anti-indexacao por buscadores
└── README.md                     # Esta documentacao
```

---

## Requisitos

- PHP 8.0 ou superior
- MySQL 8.0 ou superior
- Apache com `mod_rewrite` e `mod_headers` habilitados
- Extensoes PHP: `pdo`, `pdo_mysql`, `json`, `mbstring`, `zip` (para XLSX)

### Desenvolvimento Local

- XAMPP 8.x (inclui Apache + PHP + MySQL)

---

## Instalacao

### Local (XAMPP)

1. Clone ou copie o projeto para `C:\xampp\htdocs\mypharm\`
2. Copie `.env.example` para `.env` e preencha as credenciais
3. Inicie o Apache e MySQL no XAMPP
4. Acesse `http://localhost/mypharm/`

### Producao (Hostinger)

1. Suba os arquivos via FTP ou File Manager para `public_html/mypharm/`
2. Configure o `.env` com as credenciais do MySQL da Hostinger
3. Verifique que `.htaccess` esta ativo (Apache)

---

## Configuracao

### Arquivo `.env`

```ini
DB_HOST=localhost
DB_NAME=mypharm_db
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
```

### Variaveis Opcionais

| Variavel           | Descricao                     | Padrao   |
|--------------------|-------------------------------|----------|
| `DB_HOST`          | Host do MySQL                 | localhost|
| `DB_NAME`          | Nome do banco                 | -        |
| `DB_USER`          | Usuario do banco              | -        |
| `DB_PASS`          | Senha do banco                | -        |
| `DB_CHARSET`       | Charset                       | utf8mb4  |
| `COMISSAO_PERCENT` | Percentual de comissao padrao | 3        |

---

## Paginas da Aplicacao

### `index.html` - Login + Dashboard Admin

**Acesso:** Todos os usuarios (admin ve tudo, visitador e redirecionado)

| Secao              | Descricao                                                   |
|--------------------|-------------------------------------------------------------|
| Login              | Formulario com usuario/senha, efeito de particulas          |
| Dashboard          | 6 KPIs: faturamento, receita, custo, descontos, pedidos, clientes |
| Faturamento        | Graficos de faturamento mensal, comparativo anual, margens  |
| Prescritores       | Ranking top prescritores + link para pagina completa        |
| Visitadores        | Ranking de visitadores com barras clicaveis (admin)         |
| Clientes           | Top clientes por faturamento                                |
| Produtos           | Top formas farmaceuticas                                    |
| Equipe             | Performance dos atendentes                                  |
| Insights           | Status financeiro, canais, cortesia, profissoes             |
| Importar Dados     | Botao para executar importacao de CSVs                      |
| Administracao      | CRUD de usuarios, metas e permissoes (admin only)           |

### `visitador.html` - Painel do Visitador

**Acesso:** Visitadores (proprio painel) e Admins (qualquer visitador via `?visitador=Nome`)

| Secao                    | Descricao                                                |
|--------------------------|----------------------------------------------------------|
| Metas                    | 6 cards: visitas semanais/mensais, premio, metas, comissao |
| KPIs                     | Vendas aprovadas, recusados + carrinho, prescritores ativos |
| Graficos                 | Evolucao mensal, meta mensal (gauge), meta anual (gauge) |
| Distribuicao por Produtos| Grafico doughnut das formas farmaceuticas                |
| Atencao Especial         | Alertas inteligentes de prescritores inativos com potencial |
| Relatorio Semanal        | Cards de visitas realizadas na semana                    |
| Agenda de Visitas        | Visitas agendadas para os proximos dias                  |
| Mapa de Visitas          | Mapa interativo com GPS das visitas realizadas           |
| Modal Prescritores       | Tabela completa da carteira com busca e ordenacao        |
| Gestao de Visitas        | Iniciar/Encerrar visita com GPS automatico               |

### `prescritores.html` - Ranking Completo de Prescritores

**Acesso:** Admin

| Secao              | Descricao                                                   |
|--------------------|-------------------------------------------------------------|
| Filtros            | Busca, visitador, ano, mes                                  |
| KPIs               | Total prescritores, valor aprovado/recusado, pedidos, ganhos|
| Tabela             | Paginacao server-side (200/pagina), ordenacao por coluna    |
| Badges             | Dias sem compra/visita com cores (verde/amarelo/vermelho)   |
| Transferencia      | Modal para transferir prescritor entre visitadores (admin)  |

---

## API - Endpoints

Base URL: `api.php?action=NOME`

### Publicos (sem autenticacao)

| Acao         | Metodo | Descricao                      |
|--------------|--------|--------------------------------|
| `csrf_token` | GET    | Retorna token CSRF da sessao   |
| `login`      | POST   | Autentica usuario              |

### Sessao e Config

| Acao            | Metodo | Descricao                           |
|-----------------|--------|-------------------------------------|
| `check_session` | GET    | Verifica sessao ativa + token CSRF  |
| `logout`        | GET    | Encerra sessao                      |
| `config`        | GET    | Retorna configuracoes (comissao)    |

### Dashboard e KPIs

| Acao                | Metodo | Params                  | Descricao                              |
|---------------------|--------|-------------------------|----------------------------------------|
| `kpis`              | GET    | ano, mes, dia           | KPIs principais do dashboard           |
| `faturamento_mensal`| GET    | ano, mes, dia           | Faturamento por mes                    |
| `faturamento_anual` | GET    | -                       | Faturamento por ano                    |
| `faturamento_diario`| GET    | dias (max 365)          | Faturamento por dia                    |
| `comparativo_anual` | GET    | -                       | Comparativo completo entre anos        |

### Rankings

| Acao              | Metodo | Params                  | Descricao                          |
|-------------------|--------|-------------------------|------------------------------------|
| `top_formas`      | GET    | ano, mes, dia, limit    | Top formas farmaceuticas           |
| `top_prescritores`| GET    | ano, mes, dia, limit    | Top prescritores por faturamento   |
| `top_clientes`    | GET    | ano, mes, dia, limit    | Top clientes por faturamento       |
| `top_atendentes`  | GET    | ano, mes, dia           | Top atendentes por desempenho      |

### Prescritores

| Acao                      | Metodo | Params                              | Descricao                                |
|---------------------------|--------|--------------------------------------|------------------------------------------|
| `all_prescritores`        | GET    | ano, mes, visitador, limit, offset   | Lista paginada de prescritores           |
| `list_visitadores`        | GET    | -                                    | Lista de visitadores unicos              |
| `get_prescritor_contatos` | GET    | -                                    | Mapa nome -> whatsapp                    |
| `save_prescritor_whatsapp`| POST   | nome_prescritor, whatsapp            | Salva contato WhatsApp                   |
| `transfer_prescritor`     | POST   | nome_prescritor, novo_visitador      | Transfere prescritor (admin)             |

### Analytics

| Acao          | Metodo | Params    | Descricao                           |
|---------------|--------|-----------|-------------------------------------|
| `canais`      | GET    | ano,mes   | Distribuicao por canal de atendimento|
| `itens_status`| GET    | ano       | Status dos itens de pedidos         |
| `profissoes`  | GET    | ano       | Distribuicao por profissao          |
| `cortesia`    | GET    | ano       | Pedidos de cortesia                 |

### Visitadores

| Acao                  | Metodo | Params               | Descricao                              |
|-----------------------|--------|----------------------|----------------------------------------|
| `visitadores`         | GET    | ano                  | Lista todos visitadores com metricas   |
| `visitador_dashboard` | GET    | nome, ano, mes, dia  | Dashboard completo do visitador        |

### Gestao de Visitas

| Acao              | Metodo | Params                                           | Descricao                        |
|-------------------|--------|--------------------------------------------------|----------------------------------|
| `visita_ativa`    | GET    | visitador_nome                                   | Verifica se ha visita em andamento|
| `iniciar_visita`  | POST   | prescritor, visitador_nome                       | Inicia nova visita               |
| `encerrar_visita` | POST   | id, status_visita, local_visita, resumo_visita, amostra, brinde, artigo, reagendado_para, geo_lat, geo_lng, geo_accuracy | Encerra visita com GPS |

### Administracao de Usuarios (admin only)

| Acao              | Metodo | Params                                        | Descricao                    |
|-------------------|--------|-----------------------------------------------|------------------------------|
| `list_users`      | GET    | -                                             | Lista todos os usuarios      |
| `add_user`        | POST   | nome, usuario, senha, tipo, setor, whatsapp   | Cria novo usuario            |
| `edit_user`       | POST   | id, nome, usuario, senha?, tipo, setor, whatsapp | Edita usuario            |
| `edit_user_metas` | POST   | user_id, meta_mensal, meta_anual, comissao_percentual, meta_visitas_semana, meta_visitas_mes, premio_visitas | Edita metas |
| `toggle_user`     | POST   | id                                            | Ativa/desativa usuario       |
| `delete_user`     | POST   | id                                            | Remove usuario               |

### Utilitarios

| Acao      | Metodo | Descricao                                  |
|-----------|--------|--------------------------------------------|
| `anos`    | GET    | Anos disponiveis nos dados                 |
| `db_info` | GET    | Ultima atualizacao do banco                |

---

## Banco de Dados

### Diagrama de Tabelas

```
┌─────────────────────┐       ┌──────────────────────┐
│  prescritores_      │       │  prescritor_         │
│  cadastro           │◄──────│  resumido            │
│─────────────────────│       │──────────────────────│
│ id (PK)             │       │ id (PK)              │
│ nome (UNIQUE)       │       │ nome                 │
│ visitador           │       │ visitador            │
│ created_at          │       │ profissao            │
│ updated_at          │       │ aprovados            │
└─────────┬───────────┘       │ valor_aprovado       │
          │                   │ recusados            │
          │                   │ valor_recusado       │
          │                   │ ano_referencia       │
          │                   └──────────────────────┘
          │
          │       ┌──────────────────────┐
          ├──────►│  gestao_pedidos      │
          │       │──────────────────────│
          │       │ id (PK)              │
          │       │ prescritor           │
          │       │ data_aprovacao       │
          │       │ preco_liquido        │
          │       │ produto              │
          │       │ status_financeiro    │
          │       │ cortesia             │
          │       │ ano_referencia       │
          │       └──────────────────────┘
          │
          │       ┌──────────────────────┐     ┌──────────────────────┐
          ├──────►│  historico_visitas   │────►│  visitas_            │
          │       │──────────────────────│     │  geolocalizacao      │
          │       │ id (PK)              │     │──────────────────────│
          │       │ visitador            │     │ id (PK)              │
          │       │ prescritor           │     │ historico_id (FK)    │
          │       │ data_visita          │     │ visitador            │
          │       │ status_visita        │     │ prescritor           │
          │       │ local_visita         │     │ lat                  │
          │       │ resumo_visita        │     │ lng                  │
          │       │ reagendado_para      │     │ accuracy_m           │
          │       │ amostra/brinde/artigo│     │ provider             │
          │       │ ano_referencia       │     └──────────────────────┘
          │       └──────────────────────┘
          │
          │       ┌──────────────────────┐
          ├──────►│  visitas_em_andamento│
          │       │──────────────────────│
          │       │ id (PK)              │
          │       │ visitador            │
          │       │ prescritor           │
          │       │ inicio               │
          │       │ status               │
          │       └──────────────────────┘
          │
          │       ┌──────────────────────┐
          ├──────►│  visitas_agendadas   │
          │       │──────────────────────│
          │       │ id (PK)              │
          │       │ visitador            │
          │       │ prescritor           │
          │       │ data_agendada        │
          │       │ hora                 │
          │       │ status               │
          │       └──────────────────────┘
          │
          │       ┌──────────────────────┐
          └──────►│  prescritor_contatos │
                  │──────────────────────│
                  │ nome_prescritor (UK) │
                  │ whatsapp             │
                  │ atualizado_em        │
                  └──────────────────────┘

┌──────────────────────┐     ┌──────────────────────┐
│  usuarios            │     │  itens_orcamentos_   │
│──────────────────────│     │  pedidos             │
│ id (PK)              │     │──────────────────────│
│ nome                 │     │ id (PK)              │
│ usuario (UNIQUE)     │     │ prescritor           │
│ senha (bcrypt)       │     │ descricao            │
│ tipo (admin/usuario) │     │ forma_farmaceutica   │
│ setor (visitador...) │     │ valor_bruto          │
│ meta_mensal          │     │ valor_liquido        │
│ meta_anual           │     │ status               │
│ meta_visitas_semana  │     │ ano_referencia       │
│ comissao_percentual  │     └──────────────────────┘
│ premio_visitas       │
│ ativo                │     ┌──────────────────────┐
│ ultimo_acesso        │     │  login_attempts      │
└──────────────────────┘     │──────────────────────│
                             │ id (PK)              │
                             │ ip_address           │
                             │ attempted_at         │
                             └──────────────────────┘
```

---

## Seguranca

### Protecoes Implementadas

| Protecao                  | Onde                 | Descricao                                            |
|---------------------------|----------------------|------------------------------------------------------|
| **CSRF Token**            | config.php, api.php  | Token por sessao, validado em todo POST via header `X-CSRF-Token` |
| **Rate Limiting**         | config.php, api.php  | 5 tentativas de login por IP, bloqueio de 5 minutos  |
| **Bcrypt**                | api.php              | Senhas com `password_hash(PASSWORD_BCRYPT)`           |
| **Prepared Statements**   | api.php              | PDO com `EMULATE_PREPARES = false`                    |
| **Session Segura**        | config.php           | httpOnly, sameSite=Lax, secure em producao (HTTPS)    |
| **Session Regeneration**  | api.php              | `session_regenerate_id(true)` apos login              |
| **CORS Restritivo**       | api.php              | Whitelist de origens permitidas                       |
| **CSP Header**            | .htaccess            | Content-Security-Policy para paginas HTML             |
| **HSTS**                  | .htaccess            | Strict-Transport-Security em HTTPS                    |
| **X-Frame-Options**       | api.php, .htaccess   | DENY (previne clickjacking)                           |
| **Referrer-Policy**       | api.php, .htaccess   | strict-origin-when-cross-origin                       |
| **Permissions-Policy**    | .htaccess            | Apenas geolocation permitido (para visitas)           |
| **.htaccess**             | .htaccess            | Bloqueia .env, config.php, scripts CLI, Dados/, arquivos ocultos |
| **robots.txt**            | robots.txt           | Impede indexacao da API, scripts e dados              |
| **Role-Based Access**     | api.php              | Visitador tem whitelist de acoes, admin acessa tudo   |
| **Metodos HTTP**          | .htaccess            | Apenas GET, POST, HEAD permitidos                     |

### Arquivos Protegidos via .htaccess

| Arquivo/Pasta     | Protecao                       |
|-------------------|--------------------------------|
| `.env`            | Acesso direto bloqueado        |
| `config.php`      | Acesso direto bloqueado        |
| `Dados/`          | Rewrite 403                    |
| `scripts/*.cli.*` | Rewrite 403                    |
| `.*` (ocultos)    | Rewrite 403                    |
| `*.bak,*.log,*.sql` | Acesso direto bloqueado     |

---

## Scripts de Importacao

### `scripts/importar_dados.php` (Web)

**Acesso:** Via dashboard admin (requer sessao autenticada)

Importa 3 tipos de CSV da pasta `Dados/`:

1. **Prescritor Resumido** (`Relatorios de Orcamentos e Pedidos por Prescritor Resumido XXXX.csv`)
   - Atualiza tabela `prescritor_resumido`
   - Registra novos prescritores em `prescritores_cadastro`

2. **Itens de Orcamentos** (`Relatorio de Itens de Orcamentos e Pedidos XXXX.csv`)
   - Atualiza tabela `itens_orcamentos_pedidos`
   - Recalcula `prescritor_resumido` a partir dos itens

3. **Historico de Visitas** (`Relatorio de Historico de Visitas XXXX.xlsx`)
   - Atualiza tabela `historico_visitas`
   - Leitura de XLSX via ZipArchive + SimpleXML

### `scripts/importar_itens_cli.php` (Terminal)

**Acesso:** Apenas via terminal (`php scripts/importar_itens_cli.php`)

Importa apenas os CSVs de Itens de Orcamentos (2022-2026). Util para carga inicial ou reprocessamento completo.

```bash
cd C:\xampp\htdocs\mypharm
php scripts/importar_itens_cli.php
```

---

## Deploy (Hostinger)

### Configuracao do `.env` para producao

```ini
DB_HOST=srv1845.hstgr.io
DB_NAME=u936212550_my_pharm
DB_USER=u936212550_my_pharm
DB_PASS=SUA_SENHA_AQUI
DB_CHARSET=utf8mb4
```

### Checklist de Deploy

- [ ] Subir todos os arquivos para `public_html/mypharm/`
- [ ] Configurar `.env` com credenciais de producao
- [ ] Verificar que `mod_rewrite` esta ativo no Apache
- [ ] Verificar que `.htaccess` esta sendo interpretado
- [ ] Testar acesso direto a `.env` (deve retornar 403)
- [ ] Testar login e navegacao
- [ ] Verificar que HTTPS esta ativo (para cookies seguros)
- [ ] Rodar importacao de dados inicial

### Diagnostico

Se a aplicacao estiver lenta:
1. Verifique a latencia entre o servidor PHP e o MySQL
2. Confirme que ambos (PHP e DB) estao no mesmo datacenter
3. Habilite cache de opcodes (`opcache`) no PHP
4. Verifique os indices das tabelas com muitos registros

---

## Tipos de Usuario

| Tipo    | Setor      | Acesso                                                |
|---------|------------|-------------------------------------------------------|
| admin   | (qualquer) | Dashboard completo, CRUD usuarios, ver painel de qualquer visitador |
| usuario | (qualquer) | Dashboard com restricoes por setor                    |
| usuario | visitador  | Apenas painel do visitador (proprio), gestao de visitas|

---

## Funcionalidades por Perfil

### Admin
- Visualizar dashboard completo com todos os KPIs
- Acessar painel de qualquer visitador (`visitador.html?visitador=Nome`)
- Gerenciar usuarios (criar, editar, desativar, excluir)
- Definir metas individuais por visitador
- Transferir prescritores entre visitadores
- Importar dados (CSVs)

### Visitador
- Visualizar seu proprio painel com metas e KPIs
- Iniciar e encerrar visitas (com captura GPS automatica)
- Agendar proximas visitas
- Registrar WhatsApp de prescritores
- Visualizar mapa de visitas realizadas
- Receber alertas de prescritores inativos com potencial
