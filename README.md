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
