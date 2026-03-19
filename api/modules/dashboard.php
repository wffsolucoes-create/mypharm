<?php
/**
 * Módulo Dashboard: KPIs, faturamento, tops e canais.
 * Usa buildDateFilter() e safeLimit() de api/bootstrap.php.
 */
require_once __DIR__ . '/dashboard_visitador.php';

function handleDashboardModuleAction(string $action, PDO $pdo): void
{
    switch ($action) {
        case 'kpis':
            dashboardKpis($pdo);
            return;
        case 'faturamento_mensal':
            dashboardFaturamentoMensal($pdo);
            return;
        case 'faturamento_anual':
            dashboardFaturamentoAnual($pdo);
            return;
        case 'top_formas':
            dashboardTopFormas($pdo);
            return;
        case 'top_prescritores':
            dashboardTopPrescritores($pdo);
            return;
        case 'top_clientes':
            dashboardTopClientes($pdo);
            return;
        case 'top_atendentes':
            dashboardTopAtendentes($pdo);
            return;
        case 'canais':
            dashboardCanais($pdo);
            return;
        case 'visitadores':
            dashboardVisitadoresStub($pdo);
            return;
        case 'visitador_dashboard':
            dashboardVisitadorDashboardReal($pdo);
            return;
        case 'list_pedidos_visitador':
            dashboardListPedidosVisitador($pdo);
            return;
        case 'get_pedido_detalhe':
            dashboardGetPedidoDetalhe($pdo);
            return;
        case 'get_pedido_componentes':
            dashboardGetPedidoComponentes($pdo);
            return;
        case 'list_componentes_prescritor':
            dashboardListComponentesPrescritor($pdo);
            return;
        case 'evolucao_prescritor':
            dashboardEvolucaoPrescritor($pdo);
            return;
        case 'analise_prescritor':
            dashboardAnalisePrescritor($pdo);
            return;
        case 'get_exemplo_prescritor':
            dashboardGetExemploPrescritor($pdo);
            return;
        case 'get_visitas_mapa_periodo':
            getVisitasMapaPeriodo($pdo);
            return;
        case 'get_relatorio_rota_completo':
            getRelatorioRotaCompleto($pdo);
            return;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Ação de dashboard desconhecida'], JSON_UNESCAPED_UNICODE);
            return;
    }
}

// ---------------------------------------------------------------------------
// KPIs
// Chave de ligação com itens e outras tabelas: (numero_pedido, serie_pedido).
// Vendas Aprovadas = soma de preco_liquido onde status NÃO é Recusado/Cancelado/Orçamento.
// ---------------------------------------------------------------------------
function dashboardKpis(PDO $pdo): void
{
    list($whereCond, $paramsKpis) = buildDateFilter();
    $whereKpis = $whereCond === '1=1' ? '' : "WHERE $whereCond";

    $sql = "SELECT 
        COALESCE(SUM(preco_liquido), 0) as faturamento_total,
        COALESCE(SUM(CASE WHEN status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN preco_liquido ELSE 0 END), 0) as vendas_aprovadas,
        COALESCE(SUM(preco_bruto), 0) as receita_bruta,
        COALESCE(SUM(preco_custo), 0) as custo_total,
        COALESCE(SUM(desconto), 0) as total_descontos,
        COUNT(*) as total_pedidos,
        COUNT(DISTINCT CONCAT(numero_pedido, '-', serie_pedido)) as total_pedidos_unicos,
        COUNT(DISTINCT cliente) as total_clientes,
        COUNT(DISTINCT COALESCE(NULLIF(prescritor, ''), 'My Pharm')) as total_prescritores,
        COALESCE(AVG(preco_liquido), 0) as ticket_medio
    FROM gestao_pedidos $whereKpis";

    $stmt = $pdo->prepare($sql);
    foreach ($paramsKpis as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    $kpis = $stmt->fetch(PDO::FETCH_ASSOC);

    $kpis['margem_lucro'] = isset($kpis['faturamento_total']) && (float)$kpis['faturamento_total'] > 0
        ? round((((float)$kpis['faturamento_total'] - (float)$kpis['custo_total']) / (float)$kpis['faturamento_total']) * 100, 2)
        : 0;

    $sql2 = "SELECT status_financeiro, COUNT(*) as total 
             FROM gestao_pedidos $whereKpis 
             GROUP BY status_financeiro ORDER BY total DESC";
    $stmt2 = $pdo->prepare($sql2);
    foreach ($paramsKpis as $k => $v) {
        $stmt2->bindValue(":$k", $v);
    }
    $stmt2->execute();
    $kpis['status_financeiro'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($kpis, JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Faturamento mensal
// ---------------------------------------------------------------------------
function dashboardFaturamentoMensal(PDO $pdo): void
{
    list($whereCond, $paramsFat) = buildDateFilter();
    if ($whereCond === '1=1') {
        $anoDefault = $_GET['ano'] ?? date('Y');
        $whereFat = "WHERE ano_referencia = :ano AND data_aprovacao IS NOT NULL";
        $paramsFat = ['ano' => $anoDefault];
    } else {
        $whereFat = "WHERE $whereCond AND data_aprovacao IS NOT NULL";
    }
    $stmt = $pdo->prepare("
        SELECT 
            MONTH(data_aprovacao) as mes,
            SUM(preco_liquido) as faturamento,
            SUM(preco_bruto) as receita_bruta,
            SUM(preco_custo) as custo,
            COUNT(*) as qtd_pedidos,
            COUNT(DISTINCT cliente) as clientes_unicos
        FROM gestao_pedidos 
        $whereFat
        GROUP BY MONTH(data_aprovacao)
        ORDER BY mes
    ");
    foreach ($paramsFat as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Faturamento anual
// ---------------------------------------------------------------------------
function dashboardFaturamentoAnual(PDO $pdo): void
{
    $stmt = $pdo->query("
        SELECT 
            ano_referencia as ano,
            SUM(preco_liquido) as faturamento,
            SUM(preco_bruto) as receita_bruta,
            SUM(preco_custo) as custo,
            COUNT(*) as qtd_pedidos,
            COUNT(DISTINCT cliente) as clientes_unicos
        FROM gestao_pedidos 
        GROUP BY ano_referencia
        ORDER BY ano_referencia
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Top formas farmacêuticas
// ---------------------------------------------------------------------------
function dashboardTopFormas(PDO $pdo): void
{
    list($whereCond, $paramsFormas) = buildDateFilter();
    $whereFormas = $whereCond === '1=1' ? '' : "WHERE $whereCond";
    $limit = safeLimit($_GET['limit'] ?? 10);

    $stmt = $pdo->prepare("
        SELECT 
            forma_farmaceutica,
            COUNT(*) as quantidade,
            SUM(preco_liquido) as faturamento,
            SUM(preco_custo) as custo,
            AVG(preco_liquido) as ticket_medio
        FROM gestao_pedidos 
        $whereFormas
        GROUP BY forma_farmaceutica
        ORDER BY faturamento DESC
        LIMIT $limit
    ");
    foreach ($paramsFormas as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Período para itens_orcamentos_pedidos.data (alinhado a data_de/data_ate ou ano/mês)
// ---------------------------------------------------------------------------
/**
 * @return array{0: string, 1: array<string, mixed>} SQL extra (com AND inicial) e params
 */
function dashboardItensDataPeriodSql(): array
{
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : '';
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : '';
    if ($dataDe !== '' && $dataAte !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) {
        if ($dataDe > $dataAte) {
            $dataAte = $dataDe;
        }
        return [' AND DATE(i.data) BETWEEN :dash_data_de AND :dash_data_ate', ['dash_data_de' => $dataDe, 'dash_data_ate' => $dataAte]];
    }
    $ano = isset($_GET['ano']) ? $_GET['ano'] : null;
    $mes = isset($_GET['mes']) ? $_GET['mes'] : null;
    $dia = isset($_GET['dia']) ? $_GET['dia'] : null;
    if ($ano && $mes && $dia) {
        $df = sprintf('%04d-%02d-%02d', (int)$ano, (int)$mes, (int)$dia);
        return [' AND DATE(i.data) = :dash_data_filtro', ['dash_data_filtro' => $df]];
    }
    if ($ano && $mes) {
        return [' AND YEAR(i.data) = :dash_ano AND MONTH(i.data) = :dash_mes', ['dash_ano' => (int)$ano, 'dash_mes' => (int)$mes]];
    }
    if ($ano) {
        return [' AND YEAR(i.data) = :dash_ano_only', ['dash_ano_only' => (int)$ano]];
    }
    return ['', []];
}

/**
 * Mapa prescritor normalizado (UPPER TRIM) => [ valor_recusado+carrinho, qtd ]
 * Fonte: itens_orcamentos_pedidos (igual all_prescritores / get_recusados_prescritores).
 * gestao_pedidos em muitas bases não traz linhas Recusado/No carrinho.
 */
function dashboardRecusadosPorPrescritorFromItens(PDO $pdo): array
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS itens_orcamentos_pedidos (
            id INT AUTO_INCREMENT PRIMARY KEY, filial VARCHAR(100), numero INT NOT NULL DEFAULT 0, serie INT NOT NULL DEFAULT 0,
            data DATE NULL, canal VARCHAR(50), forma_farmaceutica VARCHAR(100), descricao VARCHAR(255),
            quantidade INT NOT NULL DEFAULT 1, unidade VARCHAR(20),
            valor_bruto DECIMAL(14,2) DEFAULT 0, valor_liquido DECIMAL(14,2) DEFAULT 0, preco_custo DECIMAL(14,2) DEFAULT 0, fator DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(50), usuario_inclusao VARCHAR(100), usuario_aprovador VARCHAR(100),
            paciente VARCHAR(255), prescritor VARCHAR(255), status_financeiro VARCHAR(50), ano_referencia INT NOT NULL,
            INDEX idx_ano (ano_referencia), INDEX idx_prescritor (prescritor(100)), INDEX idx_status (status),
            INDEX idx_numero_serie (numero, serie)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ignora */ }

    list($periodSql, $periodParams) = dashboardItensDataPeriodSql();
    $sql = "
        SELECT
            UPPER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'))) AS k,
            COALESCE(SUM(i.valor_liquido), 0) AS valor_recusado,
            COUNT(*) AS qtd_recusados
        FROM itens_orcamentos_pedidos i
        WHERE i.data IS NOT NULL
          AND (i.status = 'Recusado' OR i.status = 'No carrinho' OR LOWER(TRIM(i.status)) = 'no carrinho')
          $periodSql
        GROUP BY k
    ";
    $map = [];
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($periodParams as $k => $v) {
            $stmt->bindValue(':' . $k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (string)($r['k'] ?? '');
            if ($key !== '') {
                $map[$key] = [(float)($r['valor_recusado'] ?? 0), (int)($r['qtd_recusados'] ?? 0)];
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $map;
}

function dashboardNormPrescritorKey(string $nome): string
{
    $n = trim($nome);
    if ($n === '') {
        $n = 'My Pharm';
    }
    return mb_strtoupper($n, 'UTF-8');
}

function dashboardNormVisitadorKey(string $nome): string
{
    $n = trim($nome);
    if ($n === '') {
        $n = 'My Pharm';
    }
    return mb_strtoupper($n, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Top prescritores
// ---------------------------------------------------------------------------
function dashboardTopPrescritores(PDO $pdo): void
{
    list($whereCond, $paramsPresc) = buildDateFilter();
    $wherePresc = $whereCond === '1=1' ? '' : "WHERE $whereCond";
    $limit = safeLimit($_GET['limit'] ?? 10);

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') as prescritor,
            SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN 1 ELSE 0 END) as total_pedidos,
            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) as faturamento,
            COUNT(DISTINCT gp.cliente) as clientes_atendidos,
            AVG(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido END) as ticket_medio
        FROM gestao_pedidos gp
        $wherePresc
        GROUP BY COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
        ORDER BY faturamento DESC
        LIMIT $limit
    ");
    foreach ($paramsPresc as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Regra do dashboard: recusados vêm da mesma fonte do CSV de itens
    // (Dados/Relatório de Itens de Orçamentos e Pedidos 2026.csv -> itens_orcamentos_pedidos).
    $recMap = dashboardRecusadosPorPrescritorFromItens($pdo);

    foreach ($rows as &$row) {
        $k = dashboardNormPrescritorKey((string)($row['prescritor'] ?? ''));
        $pair = $recMap[$k] ?? [0.0, 0];
        $row['valor_recusado'] = $pair[0];
        $row['qtd_recusados'] = $pair[1];
    }
    unset($row);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Top clientes
// ---------------------------------------------------------------------------
function dashboardTopClientes(PDO $pdo): void
{
    list($whereCond, $paramsCli) = buildDateFilter();
    $whereCli = $whereCond === '1=1' ? '' : "WHERE $whereCond";
    $limit = safeLimit($_GET['limit'] ?? 10);

    $stmt = $pdo->prepare("
        SELECT 
            cliente,
            COUNT(*) as total_pedidos,
            SUM(preco_liquido) as faturamento,
            AVG(preco_liquido) as ticket_medio,
            MAX(data_aprovacao) as ultima_compra
        FROM gestao_pedidos 
        $whereCli
        GROUP BY cliente
        HAVING cliente != '' AND cliente IS NOT NULL
        ORDER BY faturamento DESC
        LIMIT $limit
    ");
    foreach ($paramsCli as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Top atendentes
// ---------------------------------------------------------------------------
function dashboardTopAtendentes(PDO $pdo): void
{
    list($whereCond, $paramsAtend) = buildDateFilter();
    $whereAtend = $whereCond === '1=1' ? '' : "WHERE $whereCond";
    $limit = safeLimit($_GET['limit'] ?? 10, 1, 100);

    $stmt = $pdo->prepare("
        SELECT 
            atendente,
            COUNT(*) as total_pedidos,
            SUM(preco_liquido) as faturamento,
            COUNT(DISTINCT cliente) as clientes_atendidos,
            AVG(preco_liquido) as ticket_medio
        FROM gestao_pedidos 
        $whereAtend
        GROUP BY atendente
        HAVING atendente != '' AND atendente IS NOT NULL
        ORDER BY faturamento DESC
        LIMIT $limit
    ");
    foreach ($paramsAtend as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Canais de atendimento
// ---------------------------------------------------------------------------
function dashboardCanais(PDO $pdo): void
{
    list($whereCond, $paramsCan) = buildDateFilter();
    $whereCan = $whereCond === '1=1' ? '' : "WHERE $whereCond";

    $stmt = $pdo->prepare("
        SELECT 
            canal_atendimento,
            COUNT(*) as total,
            SUM(preco_liquido) as faturamento
        FROM gestao_pedidos 
        $whereCan
        GROUP BY canal_atendimento
        ORDER BY faturamento DESC
    ");
    foreach ($paramsCan as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Visitadores: aprovados (gestao_pedidos) + recusado/carrinho (itens_orcamentos_pedidos)
// ---------------------------------------------------------------------------
function dashboardVisitadoresStub(PDO $pdo): void
{
    list($whereCond, $params) = buildDateFilter();
    $whereSql = $whereCond === '1=1' ? '' : "AND $whereCond";

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(pr.visitador), ''), 'My Pharm') AS visitador,
            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) AS total_valor_aprovado,
            COUNT(DISTINCT COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')) AS total_prescritores,
            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN 1 ELSE 0 END), 0) AS total_aprovados
        FROM gestao_pedidos gp
        LEFT JOIN prescritor_resumido pr
            ON pr.nome = COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')
            AND pr.ano_referencia = YEAR(gp.data_aprovacao)
        WHERE gp.data_aprovacao IS NOT NULL $whereSql
        GROUP BY COALESCE(NULLIF(TRIM(pr.visitador), ''), 'My Pharm')
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    $aprovRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    list($periodSql, $periodParams) = dashboardItensDataPeriodSql();
    $recSql = "
        SELECT
            UPPER(TRIM(COALESCE(NULLIF(TRIM(pc.visitador), ''), 'My Pharm'))) AS k,
            MIN(pc.visitador) AS visitador_label,
            COALESCE(SUM(CASE WHEN i.status = 'Recusado' THEN i.valor_liquido ELSE 0 END), 0) AS total_valor_recusado,
            COALESCE(SUM(CASE WHEN i.status = 'No carrinho' OR LOWER(TRIM(i.status)) = 'no carrinho' THEN i.valor_liquido ELSE 0 END), 0) AS total_valor_carrinho
        FROM itens_orcamentos_pedidos i
        INNER JOIN prescritores_cadastro pc
            ON UPPER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'))) = UPPER(TRIM(pc.nome))
        WHERE i.data IS NOT NULL
          AND (i.status = 'Recusado' OR i.status = 'No carrinho' OR LOWER(TRIM(i.status)) = 'no carrinho')
          $periodSql
        GROUP BY k
    ";
    $recByKey = [];
    try {
        $stmtR = $pdo->prepare($recSql);
        foreach ($periodParams as $pk => $pv) {
            $stmtR->bindValue(':' . $pk, $pv, is_int($pv) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmtR->execute();
        foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (string)($r['k'] ?? '');
            if ($key === '') {
                continue;
            }
            $recByKey[$key] = [
                'total_valor_recusado' => (float)($r['total_valor_recusado'] ?? 0),
                'total_valor_carrinho' => (float)($r['total_valor_carrinho'] ?? 0),
                'visitador_label' => trim((string)($r['visitador_label'] ?? '')) ?: 'My Pharm',
            ];
        }
    } catch (Throwable $e) {
        $recByKey = [];
    }

    $merged = [];
    foreach ($aprovRows as $row) {
        $key = dashboardNormVisitadorKey((string)($row['visitador'] ?? ''));
        $merged[$key] = [
            'visitador' => $row['visitador'],
            'total_valor_aprovado' => (float)($row['total_valor_aprovado'] ?? 0),
            'total_valor_recusado' => 0.0,
            'total_valor_carrinho' => 0.0,
            'total_prescritores' => (int)($row['total_prescritores'] ?? 0),
            'total_aprovados' => (int)($row['total_aprovados'] ?? 0),
        ];
    }
    foreach ($recByKey as $key => $rec) {
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'visitador' => $rec['visitador_label'],
                'total_valor_aprovado' => 0.0,
                'total_valor_recusado' => 0.0,
                'total_valor_carrinho' => 0.0,
                'total_prescritores' => 0,
                'total_aprovados' => 0,
            ];
        }
        $merged[$key]['total_valor_recusado'] = $rec['total_valor_recusado'];
        $merged[$key]['total_valor_carrinho'] = $rec['total_valor_carrinho'];
    }

    $out = array_values($merged);
    usort($out, function ($a, $b) {
        return ($b['total_valor_aprovado'] <=> $a['total_valor_aprovado']);
    });

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Visitador dashboard (stub: retorna estrutura mínima para o front não quebrar)
// ---------------------------------------------------------------------------
function dashboardVisitadorDashboardStub(PDO $pdo): void
{
    $nome = trim($_GET['nome'] ?? '');
    if ($nome === '') {
        echo json_encode(['error' => 'Nome do visitador não fornecido'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $mes = (int)($_GET['mes'] ?? 0);
    $ultimoMes = $mes > 0 ? $mes : (int)date('n');

    $out = [
        'kpis' => [
            'total_aprovado_anual' => 0,
            'total_aprovado_mensal' => 0,
            'total_prescritores' => 0,
            'total_recusados' => 0,
            'total_no_carrinho' => 0,
            'meta_mensal' => 50000,
            'meta_anual' => 600000,
            'comissao' => 1,
            'ultimo_mes_com_dados' => $ultimoMes,
            'ultima_atualizacao' => null,
        ],
        'top_prescritores' => [],
        'kpis_visitas' => [
            'semanal' => ['atual' => 0, 'meta' => 30, 'pct' => 0],
            'mes' => ['atual' => 0, 'meta' => 100, 'pct' => 0],
            'visitas_por_semana' => [],
            'premio' => [
                'valor' => 0,
                'conquistado' => false,
                'batouVendas' => false,
                'batouVisitasMes' => false,
                'batouVisitasSemana' => false,
            ],
        ],
        'relatorio_visitas_semana' => [],
        'visitas_agendadas' => [],
        'visitas_mapa' => [],
        'visitas_mapa_resumo' => [
            'total_visitas_periodo' => 0,
            'total_visitas_realizadas' => 0,
            'total_pontos_gps' => 0,
            'km_rotas_periodo' => 0,
        ],
        'evolucao' => [],
        'top_produtos' => [],
        'top_especialidades' => [],
        'alertas' => [],
        'meta' => 50000,
    ];

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
