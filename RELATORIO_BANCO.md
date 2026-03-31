# Relatório geral do banco – MyPharm

**Data:** 30/03/2026 14:31:34
**Banco:** u936212550_my_pharm @ srv1845.hstgr.io

---

## 1. Tabelas e contagem de registros

| Tabela | Registros |
|--------|-----------|
| auth_audit_logs | 665 |
| especialidades | 34 |
| geocache | 255 |
| gestao_pedidos | 86.671 |
| historico_visitas | 905 |
| itens_orcamentos_pedidos | 139.314 |
| login_attempts | 0 |
| mensagens_usuario | 0 |
| mensagens_usuario_ocultas | 0 |
| mensagens_visitador | 0 |
| notificacoes | 0 |
| orcamentos_pedidos | 29.999 |
| pedidos_detalhado_componentes | 197.358 |
| prescritor_contatos | 4 |
| prescritor_dados | 144 |
| prescritor_resumido | 3.409 |
| prescritores_cadastro | 2.000 |
| prescritores_visitadores | 144 |
| profissoes | 16 |
| rotas_diarias | 32 |
| rotas_pontos | 2.858 |
| user_sessions | 9 |
| usuarios | 16 |
| vendedor_perdas_acoes | 0 |
| vendedor_perdas_interacoes | 0 |
| visitas_agendadas | 26 |
| visitas_em_andamento | 134 |
| visitas_geolocalizacao | 133 |
| **Total** | **464.126** |

---

## 2. Mapeamento por domínio

| Tabela | Fonte / Uso |
|--------|-------------|
| **usuarios** | Login, perfis, visitadores, metas |
| **prescritores_cadastro** | Cadastro único prescritor ↔ visitador (carteira) |
| **prescritor_resumido** | Resumo por prescritor/ano (aprovados, recusados, no carrinho) |
| **prescritor_dados** | Dados extras do prescritor (profissão, registro, endereço, etc.) |
| **prescritor_contatos** | WhatsApp por prescritor |
| **gestao_pedidos** | CSV “Relatório de Gestão de Pedidos” – itens por pedido (numero_pedido, serie_pedido) |
| **itens_orcamentos_pedidos** | CSV “Relatório de Itens de Orçamentos e Pedidos” – itens por numero/serie |
| **pedidos_detalhado_componentes** | CSV “Relatórios… Detalhado com Componentes” – componente + quantidade por numero/serie |
| **historico_visitas** | XLSX “Relatório de Histórico de Visitas” |
| **visitas_agendadas** | Agenda do visitador |
| **visitas_em_andamento** | Visita em curso |
| **visitas_geolocalizacao** | Pontos GPS das visitas |
| **rotas_diarias** / **rotas_pontos** | Rotas e trajetos |
| **login_attempts** | Bloqueio por tentativas de login |

---

## 3. Chaves de ligação (pedidos)

- **gestao_pedidos:** `numero_pedido` + `serie_pedido` + `ano_referencia`
- **itens_orcamentos_pedidos:** `numero` + `serie` + `ano_referencia`
- **pedidos_detalhado_componentes:** `numero` + `serie` (opcional: `ano_referencia`)

---

## 4. Diagnóstico – Componentes no modal “Detalhe do Pedido”

- Tabela existe. Total de linhas: **197.358**
- **Pedidos distintos (numero+série) com componentes:** 48.575 — só esses pedidos exibem a seção Componentes no modal.
- Colunas esperadas pela API: `numero`, `serie`, `componente`, `quantidade_componente`, `unidade_componente`
- Colunas encontradas: `id`, `numero`, `serie`, `ano_referencia`, `componente`, `quantidade_componente`, `unidade_componente`
- Todas as colunas necessárias estão presentes.
- **Pedido 58399 série 1:** 4 componente(s) encontrado(s).

Amostra (58399/1):
```
{
    "id": 2459678,
    "numero": 58399,
    "serie": 1,
    "ano_referencia": 2026,
    "componente": "TACROLIMO DILUIDO 1\/10",
    "quantidade_componente": "0.090000",
    "unidade_componente": "G"
}
{
    "id": 2459679,
    "numero": 58399,
    "serie": 1,
    "ano_referencia": 2026,
    "componente": "METRONIDAZOL BASE",
    "quantidade_componente": "0.225000",
    "unidade_componente": "G"
}
{
    "id": 2459680,
    "numero": 58399,
    "serie": 1,
    "ano_referencia": 2026,
    "componente": "DESONIDE",
    "quantidade_componente": "0.007500",
    "unidade_componente": "G"
}
{
    "id": 2459681,
    "numero": 58399,
    "serie": 1,
    "ano_referencia": 2026,
    "componente": "VITAMINA E [TOPICA]",
    "quantidade_componente": "0.900000",
    "unidade_componente": "G"
}
```

---

*Relatório gerado por `scripts/relatorio_banco_geral.php`*