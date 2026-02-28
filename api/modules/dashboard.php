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
// Top prescritores
// ---------------------------------------------------------------------------
function dashboardTopPrescritores(PDO $pdo): void
{
    list($whereCond, $paramsPresc) = buildDateFilter();
    $wherePresc = $whereCond === '1=1' ? '' : "WHERE $whereCond";
    $limit = safeLimit($_GET['limit'] ?? 10);
    $anoPr = isset($paramsPresc['ano']) ? $paramsPresc['ano'] : date('Y');

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') as prescritor,
            SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN 1 ELSE 0 END) as total_pedidos,
            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) as faturamento,
            COUNT(DISTINCT gp.cliente) as clientes_atendidos,
            AVG(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido END) as ticket_medio,
            (COALESCE(MAX(pr.valor_recusado), 0) + COALESCE(MAX(pr.valor_no_carrinho), 0)) as valor_recusado,
            (COALESCE(MAX(pr.recusados), 0) + COALESCE(MAX(pr.no_carrinho), 0)) as qtd_recusados
        FROM gestao_pedidos gp
        LEFT JOIN prescritor_resumido pr ON pr.nome = COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') AND pr.ano_referencia = :ano_pr
        $wherePresc
        GROUP BY COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
        ORDER BY faturamento DESC
        LIMIT $limit
    ");
    foreach ($paramsPresc as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->bindValue(':ano_pr', $anoPr, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
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
// Visitadores: faturamento e pedidos por visitador no período (carteira via prescritor_resumido)
// ---------------------------------------------------------------------------
function dashboardVisitadoresStub(PDO $pdo): void
{
    list($whereCond, $params) = buildDateFilter();
    $whereSql = $whereCond === '1=1' ? '' : "AND $whereCond";

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(pr.visitador), ''), 'My Pharm') AS visitador,
            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) AS total_valor_aprovado,
            COALESCE(SUM(CASE WHEN gp.status_financeiro = 'Recusado' THEN gp.preco_liquido ELSE 0 END), 0) AS total_valor_recusado,
            COALESCE(SUM(CASE WHEN gp.status_financeiro IN ('Orçamento', 'No Carrinho') THEN gp.preco_liquido ELSE 0 END), 0) AS total_valor_carrinho,
            COUNT(DISTINCT COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')) AS total_prescritores,
            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN 1 ELSE 0 END), 0) AS total_aprovados
        FROM gestao_pedidos gp
        LEFT JOIN prescritor_resumido pr
            ON pr.nome = COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')
            AND pr.ano_referencia = YEAR(gp.data_aprovacao)
        WHERE gp.data_aprovacao IS NOT NULL $whereSql
        GROUP BY COALESCE(NULLIF(TRIM(pr.visitador), ''), 'My Pharm')
        ORDER BY total_valor_aprovado DESC
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
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
