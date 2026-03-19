<?php
/**
 * Módulo Gestão Comercial
 * Endpoint principal: action=gestao_comercial_dashboard
 */

function handleGestaoComercialModuleAction(string $action, PDO $pdo): void
{
    try {
        switch ($action) {
            case 'gestao_comercial_dashboard':
                gestaoComercialDashboard($pdo);
                return;
            case 'gestao_comercial_lista_vendedores':
                gestaoComercialListaVendedores($pdo);
                return;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ação de gestão comercial desconhecida'], JSON_UNESCAPED_UNICODE);
                return;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $msg = (defined('IS_PRODUCTION') && IS_PRODUCTION)
            ? 'Erro interno na gestão comercial.'
            : 'Erro: ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Retorna apenas a lista de nomes de usuários com setor = vendedor (para abas).
 * Acesso: admin.
 */
function gestaoComercialListaVendedores(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $nomes = [];
    try {
        $tipo = isset($_SESSION['user_tipo']) ? strtolower(trim((string)$_SESSION['user_tipo'])) : '';
        if ($tipo !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->query("
            SELECT TRIM(nome) AS nome
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) = 'vendedor'
              AND COALESCE(ativo, 1) = 1
              AND TRIM(COALESCE(nome, '')) != ''
            ORDER BY TRIM(nome) ASC
        ");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $n = trim((string)($row['nome'] ?? ''));
                if ($n !== '' && gcIsAllowedVendedora($n)) {
                    $nomes[] = $n;
                }
            }
        }
    } catch (Throwable $e) {
        $nomes = [];
    }
    echo json_encode(['success' => true, 'nomes' => $nomes], JSON_UNESCAPED_UNICODE);
    exit;
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

function gcTryFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        return gcFetchAll($pdo, $sql, $params);
    } catch (Throwable $e) {
        return [];
    }
}

function gcNormName(string $v): string
{
    $v = trim($v);
    $v = strtr($v, [
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'Ç'=>'C','ç'=>'c',
    ]);
    if (function_exists('mb_strtolower')) {
        $v = mb_strtolower($v, 'UTF-8');
    } else {
        $v = strtolower($v);
    }
    $v = preg_replace('/\s+/', ' ', $v);
    return $v ?? '';
}

function gcAllowedVendedorasMap(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $allow = [
        'Nailena',
        'Mariana',
        'Jessica Vitoria',
        'Carla',
        'Nereida',
        'Giovanna',
        'Clara Leticia',
        'Ananda Reis',
    ];
    $map = [];
    foreach ($allow as $n) {
        $map[gcNormName($n)] = true;
    }
    return $map;
}

function gcIsAllowedVendedora(string $nome): bool
{
    $k = gcNormName($nome);
    if ($k === '') return false;
    $allow = gcAllowedVendedorasMap();
    return isset($allow[$k]);
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
            SELECT gp.cliente
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
              AND {$approvedGp}
              AND gp.cliente IS NOT NULL
              AND TRIM(gp.cliente) <> ''
            GROUP BY gp.cliente
            HAVING COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) >= 2
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
    $validConversoes = array_filter($conversoes, function ($v) { return $v !== null; });
    if (!empty($validConversoes)) {
        asort($validConversoes);
        reset($validConversoes);
        $gargalo = key($validConversoes);
    }

    $vendedores = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) AS total_pedidos,
            SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS vendas_aprovadas,
            SUM(CASE WHEN NOT ({$approvedGp}) THEN 1 ELSE 0 END) AS vendas_rejeitadas,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            COALESCE(AVG(CASE WHEN {$approvedGp} THEN gp.preco_liquido END), 0) AS ticket_medio,
            COALESCE(AVG(TIMESTAMPDIFF(MINUTE, gp.data_orcamento, gp.data_aprovacao)), 0) AS tempo_medio_espera_min,
            COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.cliente END) AS clientes_atendidos
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    $vendedores = array_values(array_filter($vendedores, static function (array $row): bool {
        return gcIsAllowedVendedora((string)($row['atendente'] ?? ''));
    }));
    foreach ($vendedores as &$vnd) {
        $totalPedidos = (float)($vnd['total_pedidos'] ?? 0);
        $vendasAprovadas = (float)($vnd['vendas_aprovadas'] ?? 0);
        $vendasRejeitadas = (float)($vnd['vendas_rejeitadas'] ?? 0);
        $vnd['conversao_individual'] = gcPercent($vendasAprovadas, $totalPedidos);
        $vnd['follow_ups_realizados'] = null;
        $vnd['motivos_perda'] = null; // detalhado em vendedor_gestao.motivos_perda
        $vnd['tempo_medio_espera_resposta'] = round((float)($vnd['tempo_medio_espera_min'] ?? 0), 1);
        $vnd['taxa_perda'] = gcPercent($vendasRejeitadas, $totalPedidos);
    }
    unset($vnd);

    $usuariosSetor = gcTryFetchAll($pdo, "
        SELECT
            TRIM(u.nome) AS nome,
            COALESCE(u.setor, '') AS setor,
            COALESCE(u.ativo, 1) AS ativo
        FROM usuarios u
        ORDER BY TRIM(u.nome) ASC
    ");
    $vendedoresCadastrados = [];
    foreach ($usuariosSetor as $u) {
        $nomeUsr = trim((string)($u['nome'] ?? ''));
        if ($nomeUsr === '') continue;
        $setorNorm = gcNormName((string)($u['setor'] ?? ''));
        if (strpos($setorNorm, 'vendedor') !== false && gcIsAllowedVendedora($nomeUsr)) {
            $vendedoresCadastrados[] = [
                'nome' => $nomeUsr,
                'ativo' => (int)($u['ativo'] ?? 1),
            ];
        }
    }

    $vendedoresMap = [];
    foreach ($vendedores as $v) {
        $k = gcNormName((string)($v['atendente'] ?? ''));
        if ($k !== '') $vendedoresMap[$k] = true;
    }

    foreach ($vendedoresCadastrados as $u) {
        $nomeCad = trim((string)($u['nome'] ?? ''));
        if ($nomeCad === '') continue;
        $k = gcNormName($nomeCad);
        if (!isset($vendedoresMap[$k])) {
            $vendedores[] = [
                'atendente' => $nomeCad,
                'total_pedidos' => 0,
                'vendas_aprovadas' => 0,
                'vendas_rejeitadas' => 0,
                'receita' => 0,
                'ticket_medio' => 0,
                'tempo_medio_espera_min' => 0,
                'clientes_atendidos' => 0,
                'conversao_individual' => 0,
                'follow_ups_realizados' => null,
                'motivos_perda' => null,
                'tempo_medio_espera_resposta' => 0,
                'taxa_perda' => 0
            ];
            $vendedoresMap[$k] = true;
        }
    }

    usort($vendedores, static function (array $a, array $b): int {
        return strcmp((string)($a['atendente'] ?? ''), (string)($b['atendente'] ?? ''));
    });

    $motivosPerda = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(NULLIF(TRIM(gp.status_financeiro), ''), 'Sem status') AS motivo,
            COUNT(*) AS quantidade,
            COALESCE(SUM(gp.preco_liquido), 0) AS valor_total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND NOT ({$approvedGp})
        GROUP BY
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)'),
            COALESCE(NULLIF(TRIM(gp.status_financeiro), ''), 'Sem status')
        ORDER BY COUNT(*) DESC, COALESCE(SUM(gp.preco_liquido), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    $motivosPerda = array_values(array_filter($motivosPerda, static function (array $row): bool {
        return gcIsAllowedVendedora((string)($row['atendente'] ?? ''));
    }));

    $clientesRejeitadosComContato = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(NULLIF(TRIM(gp.cliente), ''), '(Sem cliente)') AS cliente,
            COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') AS prescritor,
            COALESCE(NULLIF(TRIM(pc.whatsapp), ''), NULLIF(TRIM(pd.whatsapp), ''), '') AS contato,
            COUNT(*) AS qtd_rejeicoes,
            COALESCE(SUM(gp.preco_liquido), 0) AS valor_rejeitado
        FROM gestao_pedidos gp
        LEFT JOIN prescritor_contatos pc
            ON LOWER(TRIM(pc.nome_prescritor)) = LOWER(TRIM(COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')))
        LEFT JOIN prescritor_dados pd
            ON LOWER(TRIM(pd.nome_prescritor)) = LOWER(TRIM(COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')))
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND NOT ({$approvedGp})
        GROUP BY
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)'),
            COALESCE(NULLIF(TRIM(gp.cliente), ''), '(Sem cliente)'),
            COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm'),
            COALESCE(NULLIF(TRIM(pc.whatsapp), ''), NULLIF(TRIM(pd.whatsapp), ''), '')
        ORDER BY COALESCE(SUM(gp.preco_liquido), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    $clientesRejeitadosComContato = array_values(array_filter($clientesRejeitadosComContato, static function (array $row): bool {
        return gcIsAllowedVendedora((string)($row['atendente'] ?? ''));
    }));

    $consultorasAtivas = 0;
    $receitaEquipe = 0.0;
    $conversaoMedia = 0.0;
    $tempoMedioEquipe = 0.0;
    $clientesAtendidosEquipe = 0;
    $taxaPerdaMedia = 0.0;
    if (!empty($vendedores)) {
        $consultorasAtivas = count($vendedores);
        foreach ($vendedores as $v) {
            $receitaEquipe += (float)($v['receita'] ?? 0);
            $conversaoMedia += (float)($v['conversao_individual'] ?? 0);
            $tempoMedioEquipe += (float)($v['tempo_medio_espera_resposta'] ?? 0);
            $clientesAtendidosEquipe += (int)($v['clientes_atendidos'] ?? 0);
            $taxaPerdaMedia += (float)($v['taxa_perda'] ?? 0);
        }
        $conversaoMedia = round($conversaoMedia / $consultorasAtivas, 2);
        $tempoMedioEquipe = round($tempoMedioEquipe / $consultorasAtivas, 1);
        $taxaPerdaMedia = round($taxaPerdaMedia / $consultorasAtivas, 2);
    }

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
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
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
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.quantidade ELSE 0 END), 0) DESC, COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
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
        HAVING COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) > 0
        ORDER BY ((COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0)) / COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0)) DESC, COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
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
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
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
        ORDER BY COALESCE(AVG(CASE WHEN {$approvedGp} THEN gp.preco_liquido END), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);

    $havingReceita = "COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) > 0";
    $orderReceitaDesc = "COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC";
    $segmentosMargem = [
        'atendente' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
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
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
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
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
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
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
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
        'vendedor_gestao' => [
            'resumo' => [
                'consultoras_ativas' => $consultorasAtivas,
                'receita_equipe' => round($receitaEquipe, 2),
                'conversao_media' => $conversaoMedia,
                'tempo_medio_espera_min' => $tempoMedioEquipe,
                'clientes_atendidos' => $clientesAtendidosEquipe,
                'taxa_perda_media' => $taxaPerdaMedia,
            ],
            'vendedores_cadastrados' => $vendedoresCadastrados,
            'equipe' => $vendedores,
            'motivos_perda' => $motivosPerda,
            'clientes_rejeitados_com_contato' => $clientesRejeitadosComContato
        ],
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

    // Métricas do RD Station CRM (quando token configurado): enriquece o payload com todas as métricas possíveis da API
    $rdToken = trim((string)(function_exists('getenv') ? getenv('RDSTATION_CRM_TOKEN') : '') ?: '');
    if ($rdToken !== '' && function_exists('rdFetchTodasMetricas')) {
        try {
            $payload['rd_metricas'] = rdFetchTodasMetricas($rdToken, $start, $end);
        } catch (Throwable $e) {
            $payload['rd_metricas'] = null;
            $payload['rd_metricas_error'] = (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao carregar métricas do RD Station.'
                : $e->getMessage();
        }
    } else {
        $payload['rd_metricas'] = null;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

