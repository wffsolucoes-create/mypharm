# Relatório geral do banco – MyPharm

**Data:** 26/02/2026 06:28:01
**Banco:** u936212550_my_pharm @ srv1845.hstgr.io

---

## 1. Tabelas e contagem de registros

| Tabela | Registros |
|--------|-----------|
| gestao_pedidos | 82.880 |
| historico_visitas | 696 |
| itens_orcamentos_pedidos | 81.852 |
| login_attempts | 0 |
| orcamentos_pedidos | 29.999 |
| pedidos_detalhado_componentes | 4.838 |
| prescritor_contatos | 0 |
| prescritor_dados | 124 |
| prescritor_resumido | 2.854 |
| prescritores_cadastro | 1.909 |
| prescritores_visitadores | 144 |
| rotas_diarias | 0 |
| rotas_pontos | 0 |
| usuarios | 5 |
| visitas_agendadas | 0 |
| visitas_em_andamento | 0 |
| visitas_geolocalizacao | 0 |
| **Total** | **205.301** |

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

- Tabela existe. Total de linhas: **4.838**
- Colunas esperadas pela API: `numero`, `serie`, `componente`, `quantidade_componente`, `unidade_componente`
- Colunas encontradas: `id`, `numero`, `serie`, `ano_referencia`, `componente`, `quantidade_componente`, `unidade_componente`
- Todas as colunas necessárias estão presentes.
- **Pedido 58399 série 1:** 4 componente(s) encontrado(s).

Amostra:
```
{
    "id": 2586,
    "numero": 58399,
    "serie": 1,
    "ano_referencia": 2026,
    "componente": "TACROLIMO DILUIDO 1\/10",
    "quantidade_componente": "0.090000",
    "unidade_componente": "G"
}
{
    "id": 2587,
    "numero": 58399,
    "serie": 1,
    "ano_referencia": 2026,
    "componente": "METRONIDAZOL BASE",
    "quantidade_componente": "0.225000",
    "unidade_componente": "G"
}
{
    "id": 2588,
    "numero": 58399,
    "serie": 1,
    "ano_referencia": 2026,
    "componente": "DESONIDE",
    "quantidade_componente": "0.007500",
    "unidade_componente": "G"
}
{
    "id": 2589,
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