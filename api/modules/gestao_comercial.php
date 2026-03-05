<?php
/**
 * Módulo Gestão Comercial
 * Endpoint principal: action=gestao_comercial_dashboard
 */

function handleGestaoComercialModuleAction(string $action, PDO $pdo): void
{
    switch ($action) {
        case 'gestao_comercial_dashboard':
            gestaoComercialDashboard($pdo);
            return;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação de gestão comercial desconhecida'], JSON_UNESCAPED_UNICODE);
            return;
    }
}

function gcPercent(float $num, float $den): ?float
{
    if ($den <= 0) return null;
    return round(($num / $den) * 100, 2);
}

function gcGrowth(float $current, float $base): ?float
{
    if ($base <= 0) return null;
    return round((($current - $base) / $base) * 100, 2);
}

function gcDateRangeFromInput(): array
{
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : '';
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : '';
    $ano = isset($_GET['ano']) ? (int)$_GET['ano'] : 0;
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;

    $isDate = static function (string $v): bool {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    };

    if ($isDate($dataDe) && $isDate($dataAte)) {
        if ($dataDe > $dataAte) {
            $dataAte = $dataDe;
        }
        $start = new DateTimeImmutable($dataDe);
        $end = new DateTimeImmutable($dataAte);
        return [$start, $end, 'custom'];
    }

    if ($ano > 0 && $mes >= 1 && $mes <= 12) {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $end = $start->modify('last day of this month');
        return [$start, $end, 'ano_mes'];
    }

    if ($ano > 0) {
        $start = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
        $end = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
        return [$start, $end, 'ano'];
    }

    $today = new DateTimeImmutable('today');
    $start = $today->modify('first day of this month');
    $end = $today->modify('last day of this month');
    return [$start, $end, 'mes_atual'];
}

function gcFetchRow(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function gcFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function gcApprovedCase(string $alias = 'gp'): string
{
    return "(
        {$alias}.status_financeiro IS NULL OR
        (
            {$alias}.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
            AND {$alias}.status_financeiro NOT LIKE '%carrinho%'
        )
    )";
}

function gestaoComercialDashboard(PDO $pdo): void
{
    if (strtolower((string)($_SESSION['user_tipo'] ?? '')) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$startObj, $endObj, $periodType] = gcDateRangeFromInput();
    $start = $startObj->format('Y-m-d');
    $end = $endObj->format('Y-m-d');
    $prevStart = $startObj->modify('-1 month')->format('Y-m-d');
    $prevEnd = $endObj->modify('-1 month')->format('Y-m-d');
    $lyStart = $startObj->modify('-1 year')->format('Y-m-d');
    $lyEnd = $endObj->modify('-1 year')->format('Y-m-d');
    $sixMonthsStart = $endObj->modify('-6 months')->format('Y-m-d');
    $ninetyDaysStart = $endObj->modify('-90 days')->format('Y-m-d');
    $limit = safeLimit($_GET['limit'] ?? 20, 5, 50);

    $approvedGp = gcApprovedCase('gp');

    $rowCurrent = gcFetchRow($pdo, "
        SELECT
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo,
            COALESCE(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,'')) END), 0) AS vendas,
            COALESCE(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.cliente END), 0) AS clientes
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

    $rowPrev = gcFetchRow($pdo, "
        SELECT COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $prevStart, 'fim' => $prevEnd]);

    $rowLy = gcFetchRow($pdo, "
        SELECT COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $lyStart, 'fim' => $lyEnd]);

    $receitaMes = (float)($rowCurrent['receita'] ?? 0);
    $custoMes = (float)($rowCurrent['custo'] ?? 0);
    $vendasMes = (int)($rowCurrent['vendas'] ?? 0);
    $clientesMes = (int)($rowCurrent['clientes'] ?? 0);
    $ticketMedio = $vendasMes > 0 ? round($receitaMes / $vendasMes, 2) : 0.0;

    $clientesAtivos6mRow = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT gp.cliente), 0) AS total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND {$approvedGp}
    ", ['ini' => $sixMonthsStart, 'fim' => $end]);

    $leadsRow = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))), 0) AS total
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

    $vendasFunilRow = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))), 0) AS total
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
          AND UPPER(TRIM(COALESCE(i.status, ''))) = 'APROVADO'
    ", ['ini' => $start, 'fim' => $end]);

    $tempoFechamentoRow = gcFetchRow($pdo, "
        SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, gp.data_orcamento, gp.data_aprovacao)), 0) AS media_horas
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND gp.data_orcamento IS NOT NULL
          AND gp.data_aprovacao IS NOT NULL
          AND {$approvedGp}
    ", ['ini' => $start, 'fim' => $end]);

    $clientesPerdaBase = gcFetchRow($pdo, "
        SELECT
            COALESCE(COUNT(DISTINCT gp.cliente), 0) AS clientes_mov
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

    $clientesPerdidos = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(*), 0) AS clientes_perdidos
        FROM (
            SELECT
                gp.cliente,
                SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS qtd_aprov,
                SUM(CASE WHEN NOT ({$approvedGp}) THEN 1 ELSE 0 END) AS qtd_nao_aprov
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
              AND gp.cliente IS NOT NULL
              AND TRIM(gp.cliente) <> ''
            GROUP BY gp.cliente
        ) x
        WHERE x.qtd_aprov = 0 AND x.qtd_nao_aprov > 0
    ", ['ini' => $start, 'fim' => $end]);

    $ltvRow = gcFetchRow($pdo, "
        SELECT
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita_total,
            COALESCE(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.cliente END), 0) AS clientes_total
        FROM gestao_pedidos gp
    ");

    $freqRecompraRow = gcFetchRow($pdo, "
        SELECT
            COALESCE(COUNT(*), 0) AS clientes_recompra
        FROM (
            SELECT gp.cliente, COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) AS qtd
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
              AND {$approvedGp}
              AND gp.cliente IS NOT NULL
              AND TRIM(gp.cliente) <> ''
            GROUP BY gp.cliente
            HAVING qtd >= 2
        ) t
    ", ['ini' => $start, 'fim' => $end]);

    $base90Row = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT gp.cliente), 0) AS total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND {$approvedGp}
    ", ['ini' => $ninetyDaysStart, 'fim' => $end]);

    $funilRows = gcFetchAll($pdo, "
        SELECT
            UPPER(TRIM(COALESCE(i.status, '(SEM_STATUS)'))) AS etapa,
            COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))) AS total
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
        GROUP BY UPPER(TRIM(COALESCE(i.status, '(SEM_STATUS)')))
    ", ['ini' => $start, 'fim' => $end]);
    $funilMap = [];
    foreach ($funilRows as $fr) {
        $funilMap[$fr['etapa']] = (int)$fr['total'];
    }
    $atendimentos = array_sum($funilMap);
    $orcamentos = ($funilMap['NO CARRINHO'] ?? 0) + ($funilMap['APROVADO'] ?? 0) + ($funilMap['RECUSADO'] ?? 0);
    $vendasFechadas = (int)($vendasFunilRow['total'] ?? 0);
    $clientesAprovados = $clientesMes;
    $clientesRecompra = (int)($freqRecompraRow['clientes_recompra'] ?? 0);

    $convAtendimentoOrc = gcPercent((float)$orcamentos, (float)max($atendimentos, 0));
    $convOrcVenda = gcPercent((float)$vendasFechadas, (float)max($orcamentos, 0));
    $convVendaRecompra = gcPercent((float)$clientesRecompra, (float)max($clientesAprovados, 0));

    $conversoes = [
        'atendimento_para_orcamento' => $convAtendimentoOrc,
        'orcamento_para_venda' => $convOrcVenda,
        'venda_para_recompra' => $convVendaRecompra,
    ];
    $gargalo = null;
    $validConversoes = array_filter($conversoes, static fn($v) => $v !== null);
    if (!empty($validConversoes)) {
        asort($validConversoes);
        $gargalo = array_key_first($validConversoes);
    }

    $vendedores = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) AS total_pedidos,
            SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS vendas_aprovadas,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            COALESCE(AVG(CASE WHEN {$approvedGp} THEN gp.preco_liquido END), 0) AS ticket_medio
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
        ORDER BY receita DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($vendedores as &$vnd) {
        $totalPedidos = (float)($vnd['total_pedidos'] ?? 0);
        $vendasAprovadas = (float)($vnd['vendas_aprovadas'] ?? 0);
        $vnd['conversao_individual'] = gcPercent($vendasAprovadas, $totalPedidos);
        $vnd['follow_ups_realizados'] = null;
        $vnd['motivos_perda'] = null;
        $vnd['tempo_medio_espera_resposta'] = null;
    }
    unset($vnd);

    $canais = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.canal_atendimento), ''), '(Sem canal)') AS canal,
            COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) AS total_pedidos,
            SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS vendas_aprovadas,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.canal_atendimento), ''), '(Sem canal)')
        ORDER BY receita DESC
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($canais as &$can) {
        $receitaCanal = (float)($can['receita'] ?? 0);
        $custoCanal = (float)($can['custo'] ?? 0);
        $can['conversao'] = gcPercent((float)($can['vendas_aprovadas'] ?? 0), (float)max((int)($can['total_pedidos'] ?? 0), 0));
        $can['margem_percentual'] = gcPercent($receitaCanal - $custoCanal, $receitaCanal);
        $can['cac'] = null;
    }
    unset($can);

    $topFormulas = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)') AS produto,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.quantidade ELSE 0 END), 0) AS quantidade,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)')
        ORDER BY quantidade DESC, receita DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);

    $formulasMargem = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)') AS produto,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)')
        HAVING receita > 0
        ORDER BY ((receita - custo) / receita) DESC, receita DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($formulasMargem as &$fm) {
        $fm['margem_percentual'] = gcPercent(((float)$fm['receita'] - (float)$fm['custo']), (float)$fm['receita']);
    }
    unset($fm);

    $topPrescritores = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') AS prescritor,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')
        ORDER BY receita DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);

    $ticketEspecialidade = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(pd.especialidade), ''), '(Sem especialidade)') AS especialidade,
            COALESCE(AVG(CASE WHEN {$approvedGp} THEN gp.preco_liquido END), 0) AS ticket_medio,
            COUNT(*) AS total
        FROM gestao_pedidos gp
        LEFT JOIN prescritor_dados pd
            ON LOWER(TRIM(pd.nome_prescritor)) = LOWER(TRIM(COALESCE(NULLIF(gp.prescritor,''), 'My Pharm')))
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(pd.especialidade), ''), '(Sem especialidade)')
        ORDER BY ticket_medio DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);

    $segmentosMargem = [
        'atendente' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
            HAVING receita > 0
            ORDER BY receita DESC
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
        'forma_farmaceutica' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.forma_farmaceutica), ''), '(Sem forma)') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.forma_farmaceutica), ''), '(Sem forma)')
            HAVING receita > 0
            ORDER BY receita DESC
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
        'prescritor' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')
            HAVING receita > 0
            ORDER BY receita DESC
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
        'produto' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)')
            HAVING receita > 0
            ORDER BY receita DESC
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
    ];
    foreach ($segmentosMargem as &$arr) {
        foreach ($arr as &$item) {
            $item['margem_percentual'] = gcPercent(((float)$item['receita'] - (float)$item['custo']), (float)$item['receita']);
        }
        unset($item);
    }
    unset($arr);

    $crescimento = [
        'receita_mes' => round($receitaMes, 2),
        'crescimento_vs_mes_anterior' => gcGrowth($receitaMes, (float)($rowPrev['receita'] ?? 0)),
        'crescimento_vs_mes_ano_passado' => gcGrowth($receitaMes, (float)($rowLy['receita'] ?? 0)),
        'ticket_medio' => $ticketMedio,
        'numero_vendas' => $vendasMes,
        'numero_clientes_ativos_6_meses' => (int)($clientesAtivos6mRow['total'] ?? 0),
    ];

    $leads = (int)($leadsRow['total'] ?? 0);
    $vendasFunil = (int)($vendasFunilRow['total'] ?? 0);
    $clientesMov = (int)($clientesPerdaBase['clientes_mov'] ?? 0);
    $clientesPerdidosQtd = (int)($clientesPerdidos['clientes_perdidos'] ?? 0);
    $receitaTotal = (float)($ltvRow['receita_total'] ?? 0);
    $clientesTotal = (int)($ltvRow['clientes_total'] ?? 0);
    $ltv = $clientesTotal > 0 ? round($receitaTotal / $clientesTotal, 2) : null;

    $eficiencia = [
        'leads_recebidos' => $leads,
        'conversao_geral' => gcPercent((float)$vendasFunil, (float)max($leads, 0)),
        'tempo_medio_fechamento_horas' => round((float)($tempoFechamentoRow['media_horas'] ?? 0), 2),
        'taxa_perda_cliente' => gcPercent((float)$clientesPerdidosQtd, (float)max($clientesMov, 0)),
        'ltv' => $ltv,
        'cac' => null,
        'ltv_cac' => null,
        'notas' => [
            'cac' => 'Não há base de custo de marketing/campanhas no banco atual.',
            'ltv_cac' => 'Depende do CAC para cálculo.'
        ],
    ];

    $lucroOperacional = $receitaMes - $custoMes;
    $margemBruta = gcPercent($lucroOperacional, $receitaMes);
    $cmv = gcPercent($custoMes, $receitaMes);

    $rentabilidade = [
        'margem_bruta' => $margemBruta,
        'margem_contribuicao' => $margemBruta,
        'cmv_sobre_faturamento' => $cmv,
        'ponto_equilibrio' => null,
        'lucro_operacional' => round($lucroOperacional, 2),
        'margem_contribuicao_por' => $segmentosMargem,
        'notas' => [
            'ponto_equilibrio' => 'Depende de custo fixo mensal consolidado.',
        ],
    ];

    $fidelizacao = [
        'taxa_recompra' => gcPercent((float)$clientesRecompra, (float)max($clientesAprovados, 0)),
        'frequencia_media_compra' => $clientesAprovados > 0 ? round($vendasMes / $clientesAprovados, 2) : 0,
        'base_ativa_90_dias' => (int)($base90Row['total'] ?? 0),
        'nps' => null,
        'csat' => null,
        'notas' => [
            'nps_csat' => 'Não há tabela de pesquisas de satisfação (NPS/CSAT) na base atual.',
        ],
    ];

    $payload = [
        'success' => true,
        'generated_at' => date('c'),
        'periodo' => [
            'tipo' => $periodType,
            'data_de' => $start,
            'data_ate' => $end,
            'comparativos' => [
                'mes_anterior' => ['data_de' => $prevStart, 'data_ate' => $prevEnd],
                'mesmo_periodo_ano_passado' => ['data_de' => $lyStart, 'data_ate' => $lyEnd],
            ],
        ],
        'crescimento' => $crescimento,
        'eficiencia_comercial' => $eficiencia,
        'rentabilidade' => $rentabilidade,
        'fidelizacao' => $fidelizacao,
        'funil_comercial' => [
            'volume_atendimentos' => $atendimentos,
            'oportunidades_abertas' => $orcamentos,
            'orcamentos_enviados' => $orcamentos,
            'vendas_fechadas' => $vendasFechadas,
            'conversao_por_etapa' => $conversoes,
            'gargalo_funil' => $gargalo,
            'etapas_raw' => $funilMap,
        ],
        'performance_vendedor' => $vendedores,
        'performance_canal' => $canais,
        'inteligencia_comercial' => [
            'top_formulas_mais_vendidas' => $topFormulas,
            'formulas_maior_margem' => $formulasMargem,
            'prescritores_maior_receita' => $topPrescritores,
            'ticket_medio_por_especialidade' => $ticketEspecialidade,
        ],
        'financeiro_aplicado' => [
            'receita' => [
                'faturamento_bruto' => round($receitaMes, 2),
                'faturamento_liquido' => round($receitaMes, 2),
            ],
            'custos' => [
                'cmv' => round($custoMes, 2),
                'custo_fixo_mensal' => null,
                'custo_variavel_por_venda' => $vendasMes > 0 ? round($custoMes / $vendasMes, 2) : 0,
                'custo_por_atendimento' => $atendimentos > 0 ? round($custoMes / $atendimentos, 2) : 0,
                'cac' => null,
            ],
            'rentabilidade' => [
                'margem_bruta' => $margemBruta,
                'margem_contribuicao' => $margemBruta,
                'lucro_operacional' => round($lucroOperacional, 2),
                'ponto_equilibrio' => null,
                'roi_campanhas' => null,
            ],
            'caixa' => [
                'prazo_medio_recebimento' => null,
                'indice_inadimplencia' => null,
                'taxa_antecipacao' => null,
                'fluxo_projetado_3_meses' => null,
            ],
            'notas' => [
                'campos_nulos' => 'Métricas dependentes de marketing/financeiro não existem hoje na base transacional.'
            ]
        ],
    ];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

