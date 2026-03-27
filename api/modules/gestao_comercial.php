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
            case 'gestao_comercial_dashboard_rd':
                gestaoComercialDashboardRdOnly($pdo);
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

/** Meses (Y-m) do 1º ao último mês cobertos pelo intervalo [start, end]. */
function gcMonthKeysBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $first = $start->modify('first day of this month');
    $lastMonth = $end->modify('first day of this month');
    $out = [];
    $cur = $first;
    if ($cur > $lastMonth) {
        return [$start->format('Y-m')];
    }
    while ($cur <= $lastMonth) {
        $out[] = $cur->format('Y-m');
        $cur = $cur->modify('+1 month');
    }
    return $out;
}

/**
 * Receita aprovada em gestao_pedidos por mês e por vendedora (lista permitida).
 *
 * @param array<int, array<string, mixed>> $rowsComAtendente Linhas com chave atendente (ex.: equipe).
 * @return array{meses: string[], series: array<int, array{atendente: string, valores: float[]}>}
 */
function gcBuildEvolucaoMensalVendedores(PDO $pdo, DateTimeImmutable $startObj, DateTimeImmutable $endObj, string $approvedGp, array $rowsComAtendente): array
{
    $meses = gcMonthKeysBetween($startObj, $endObj);
    if ($meses === []) {
        return ['meses' => [], 'series' => []];
    }
    $ini = $startObj->format('Y-m-d');
    $fim = $endObj->format('Y-m-d');
    $sql = "
        SELECT
            DATE_FORMAT(gp.data_aprovacao, '%Y-%m') AS ym,
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY ym, COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
    ";
    $raw = gcTryFetchAll($pdo, $sql, ['ini' => $ini, 'fim' => $fim]);
    $matrix = [];
    $nomeByNorm = [];
    foreach ($raw as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if (!gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        if (!isset($nomeByNorm[$nk])) {
            $nomeByNorm[$nk] = $nome;
        }
        $ym = (string)($row['ym'] ?? '');
        $matrix[$nk][$ym] = round((float)($row['receita'] ?? 0), 2);
    }
    $normsOrdered = [];
    foreach ($rowsComAtendente as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        $normsOrdered[$nk] = $nome;
    }
    foreach ($matrix as $nk => $_) {
        if (!isset($normsOrdered[$nk]) && isset($nomeByNorm[$nk])) {
            $normsOrdered[$nk] = $nomeByNorm[$nk];
        }
    }
    uasort($normsOrdered, static function (string $a, string $b): int {
        return strcmp($a, $b);
    });
    $series = [];
    foreach ($normsOrdered as $nk => $nome) {
        $vals = [];
        foreach ($meses as $ym) {
            $vals[] = round((float)($matrix[$nk][$ym] ?? 0), 2);
        }
        $series[] = [
            'atendente' => $nome,
            'valores' => $vals,
        ];
    }
    return [
        'meses' => $meses,
        'series' => $series,
    ];
}

/**
 * Contagem de linhas (gestao_pedidos) por mês e vendedora: aprovadas ou rejeitadas (mesmo critério do painel).
 *
 * @param 'aprovados'|'rejeitados' $tipo
 * @return array{meses: string[], series: array<int, array{atendente: string, valores: float[]}>}
 */
function gcBuildEvolucaoMensalVendedoresContagem(
    PDO $pdo,
    DateTimeImmutable $startObj,
    DateTimeImmutable $endObj,
    string $approvedGp,
    array $rowsComAtendente,
    string $tipo
): array {
    $meses = gcMonthKeysBetween($startObj, $endObj);
    if ($meses === []) {
        return ['meses' => [], 'series' => []];
    }
    $ini = $startObj->format('Y-m-d');
    $fim = $endObj->format('Y-m-d');
    $filtroLinha = $tipo === 'aprovados'
        ? "AND {$approvedGp}"
        : "AND NOT {$approvedGp}";
    $sql = "
        SELECT
            DATE_FORMAT(gp.data_aprovacao, '%Y-%m') AS ym,
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COUNT(*) AS qtd
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        {$filtroLinha}
        GROUP BY ym, COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
    ";
    $raw = gcTryFetchAll($pdo, $sql, ['ini' => $ini, 'fim' => $fim]);
    $matrix = [];
    $nomeByNorm = [];
    foreach ($raw as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if (!gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        if (!isset($nomeByNorm[$nk])) {
            $nomeByNorm[$nk] = $nome;
        }
        $ym = (string)($row['ym'] ?? '');
        $matrix[$nk][$ym] = (int)($row['qtd'] ?? 0);
    }

    // Rejeitados: mesma lógica do painel da equipe — somar linhas em itens_orcamentos_pedidos (Recusado / No carrinho),
    // pois gestao_pedidos muitas vezes não reflete esses desfechos sozinho.
    if ($tipo === 'rejeitados') {
        $rawItens = gcTryFetchAll($pdo, "
            SELECT
                DATE_FORMAT(i.data, '%Y-%m') AS ym,
                COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS atendente,
                COUNT(*) AS qtd
            FROM itens_orcamentos_pedidos i
            LEFT JOIN gestao_pedidos gpLink
              ON gpLink.numero_pedido = i.numero
             AND gpLink.serie_pedido = i.serie
             AND gpLink.ano_referencia = i.ano_referencia
            WHERE DATE(i.data) BETWEEN :ini AND :fim
              AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
            GROUP BY ym, COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
        ", ['ini' => $ini, 'fim' => $fim]);
        foreach ($rawItens as $row) {
            $nome = trim((string)($row['atendente'] ?? ''));
            if ($nome === '' || $nome === '(Sem atendente)' || !gcIsAllowedVendedora($nome)) {
                continue;
            }
            $nk = gcNormName($nome);
            if ($nk === '') {
                continue;
            }
            if (!isset($nomeByNorm[$nk])) {
                $nomeByNorm[$nk] = $nome;
            }
            $ym = (string)($row['ym'] ?? '');
            $q = (int)($row['qtd'] ?? 0);
            $matrix[$nk][$ym] = ($matrix[$nk][$ym] ?? 0) + $q;
        }
    }
    $normsOrdered = [];
    foreach ($rowsComAtendente as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        $normsOrdered[$nk] = $nome;
    }
    foreach ($matrix as $nk => $_) {
        if (!isset($normsOrdered[$nk]) && isset($nomeByNorm[$nk])) {
            $normsOrdered[$nk] = $nomeByNorm[$nk];
        }
    }
    uasort($normsOrdered, static function (string $a, string $b): int {
        return strcmp($a, $b);
    });
    $series = [];
    foreach ($normsOrdered as $nk => $nome) {
        $vals = [];
        foreach ($meses as $ym) {
            $vals[] = (float)($matrix[$nk][$ym] ?? 0);
        }
        $series[] = [
            'atendente' => $nome,
            'valores' => $vals,
        ];
    }
    return [
        'meses' => $meses,
        'series' => $series,
    ];
}

/**
 * Aprovados + rejeitados mensais (ano fixo 2026), mesma lista de consultoras da receita.
 *
 * @return array{aprovados: array, rejeitados: array}
 */
function gcEvolucaoMensalAprovRejPayload(PDO $pdo, string $approvedGp, array $rowsComAtendente): array
{
    $ano = 2026;
    $ini = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
    $fim = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
    $ap = gcBuildEvolucaoMensalVendedoresContagem($pdo, $ini, $fim, $approvedGp, $rowsComAtendente, 'aprovados');
    $rj = gcBuildEvolucaoMensalVendedoresContagem($pdo, $ini, $fim, $approvedGp, $rowsComAtendente, 'rejeitados');
    $meta = [
        'ano_fixo' => $ano,
        'usa_filtro_painel' => false,
    ];
    return [
        'aprovados' => array_merge($ap, $meta),
        'rejeitados' => array_merge($rj, $meta),
    ];
}

/**
 * Evolução mensal por vendedor: sempre calendário completo do ano fixo (ignora data_de/data_ate/ano/mês do painel).
 */
function gcEvolucaoMensalVendedoresPayload(PDO $pdo, string $approvedGp, array $rowsComAtendente): array
{
    $ano = 2026;
    $ini = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
    $fim = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
    $out = gcBuildEvolucaoMensalVendedores($pdo, $ini, $fim, $approvedGp, $rowsComAtendente);
    $out['ano_fixo'] = $ano;
    $out['usa_filtro_painel'] = false;
    return $out;
}

/** Alinhado a api_gestao handleVendedorDashboardGestao — comissão individual por % da meta. */
function gcCalcComissaoIndividualPct(float $percentualMeta): float
{
    if ($percentualMeta >= 110.0) {
        return 2.0;
    }
    if ($percentualMeta >= 100.0) {
        return 1.8;
    }
    if ($percentualMeta >= 90.0) {
        return 1.3;
    }
    if ($percentualMeta >= 70.0) {
        return 1.0;
    }
    return 0.5;
}

/** Faixa de comissão extra por faturamento do grupo (receita aprovada no período, soma da equipe). */
function gcCalcComissaoGrupoPct(float $receitaGrupo): float
{
    if ($receitaGrupo >= 370000.0) {
        return 2.5;
    }
    if ($receitaGrupo >= 350000.0) {
        return 2.0;
    }
    if ($receitaGrupo >= 320000.0) {
        return 1.5;
    }
    return 0.0;
}

/**
 * Painel Gestão Comercial com métricas exclusivamente da API RD Station CRM (crm.rdstation.com).
 * Mantém o mesmo formato JSON do dashboard MySQL para o frontend não duplicar renderização.
 */
function gestaoComercialDashboardRdOnly(PDO $pdo): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', '300');
    }

    if (strtolower((string)($_SESSION['user_tipo'] ?? '')) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $rdToken = trim((string)(function_exists('getenv') ? getenv('RDSTATION_CRM_TOKEN') : '') ?: '');
    if ($rdToken === '') {
        echo json_encode([
            'success' => false,
            'error'   => 'RDSTATION_CRM_TOKEN não configurado no .env. Sem token não é possível carregar o painel só com o CRM.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!function_exists('rdFetchTodasMetricas')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Integração RD Station indisponível no servidor.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$startObj, $endObj, $periodType] = gcDateRangeFromInput();
    $start = $startObj->format('Y-m-d');
    $end = $endObj->format('Y-m-d');
    $prevStart = $startObj->modify('-1 month')->format('Y-m-d');
    $prevEnd = $endObj->modify('-1 month')->format('Y-m-d');
    $lyStart = $startObj->modify('-1 year')->format('Y-m-d');
    $lyEnd = $endObj->modify('-1 year')->format('Y-m-d');

    $refreshRd = isset($_GET['refresh_rd']) ? strtolower(trim((string)$_GET['refresh_rd'])) : '';
    $forceRefresh = in_array($refreshRd, ['1', 'true', 'yes', 'on'], true);

    $rdNow = null;
    $cacheKey = 'gc_rd_dashboard_v5_' . md5($start . '|' . $end);
    $cacheFile = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    try {
        if (is_file($cacheFile) && !$forceRefresh) {
            $cached = @file_get_contents($cacheFile);
            $cachedArr = is_string($cached) ? json_decode($cached, true) : null;
            if (is_array($cachedArr) && isset($cachedArr['receita_total'])) {
                $rdNow = $cachedArr;
            }
        }
    } catch (Throwable $e) {
        // cache é opcional
    }
    try {
        if (!is_array($rdNow)) {
            // Traz o período completo para manter aderência aos números do Kanban RD.
            // O cache de 30 min preserva performance nas próximas consultas.
            $rdNow = rdFetchTodasMetricas($rdToken, $start, $end, null, false);
            @file_put_contents($cacheFile, json_encode($rdNow, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        // Se houver cache antigo, serve versão stale em caso de falha no RD.
        try {
            if (is_file($cacheFile)) {
                $cached = @file_get_contents($cacheFile);
                $cachedArr = is_string($cached) ? json_decode($cached, true) : null;
                if (is_array($cachedArr) && isset($cachedArr['receita_total'])) {
                    $rdNow = $cachedArr;
                }
            }
        } catch (Throwable $ignored) {}
        if (is_array($rdNow)) {
            // segue com cache stale
        } else {
            http_response_code(500);
            $msg = (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao consultar o RD Station CRM.'
                : $e->getMessage();
            echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    $receitaMes = (float)($rdNow['receita_total'] ?? 0);
    $totalGanhos = (int)($rdNow['total_ganhos'] ?? 0);
    $totalPerdidos = (int)($rdNow['total_perdidos'] ?? 0);
    $oportunidadesAbertas = (int)($rdNow['oportunidades_abertas'] ?? 0);
    $ticketMedio = $totalGanhos > 0 ? round($receitaMes / $totalGanhos, 2) : 0.0;

    $volumeMacro = $totalGanhos + $totalPerdidos + $oportunidadesAbertas;

    $porLista = $rdNow['por_vendedor'] ?? [];
    $tempoFechHoras = null;
    $sumMinWeighted = 0.0;
    $cntDeals = 0;
    foreach ($porLista as $row) {
        $c = (int)($row['total_ganhos'] ?? 0);
        $tMin = $row['tempo_medio_fechamento_min'] ?? null;
        if ($c > 0 && $tMin !== null && $tMin !== '') {
            $sumMinWeighted += (float)$tMin * $c;
            $cntDeals += $c;
        }
    }
    if ($cntDeals > 0) {
        $tempoFechHoras = round($sumMinWeighted / $cntDeals / 60, 2);
    }

    $fechadosNoPeriodo = $totalGanhos + $totalPerdidos;
    $taxaPerdaCliente = gcPercent((float)$totalPerdidos, (float)max($fechadosNoPeriodo, 0));

    $funilEstagios = $rdNow['funil_estagios'] ?? [];
    $funilMap = [];
    foreach ($funilEstagios as $e) {
        $pipe = trim((string)($e['pipeline_name'] ?? 'Geral'));
        $st = trim((string)($e['stage_name'] ?? ''));
        $label = $pipe . ' — ' . ($st !== '' ? $st : 'Etapa');
        $funilMap[$label] = ($funilMap[$label] ?? 0) + (int)($e['quantidade'] ?? 0);
    }
    $gargalo = null;

    $equipe = [];
    foreach ($porLista as $row) {
        $nome = trim((string)($row['vendedor'] ?? ''));
        if ($nome === '') {
            continue;
        }
        $ganhos = (int)($row['total_ganhos'] ?? 0);
        $perd = (int)($row['total_perdidos'] ?? 0);
        $totalPedidos = $ganhos + $perd;
        $equipe[] = [
            'atendente'                   => $nome,
            'total_pedidos'               => $totalPedidos,
            'vendas_aprovadas'            => $ganhos,
            'vendas_rejeitadas'           => $perd,
            'receita'                     => (float)($row['receita'] ?? 0),
            'ticket_medio'                => $ganhos > 0 ? round((float)($row['receita'] ?? 0) / $ganhos, 2) : 0.0,
            'tempo_medio_espera_min'      => (float)($row['tempo_medio_espera_min'] ?? 0),
            'clientes_atendidos'          => $ganhos,
            'conversao_individual'        => (float)($row['conversao_pct'] ?? 0),
            'follow_ups_realizados'       => null,
            'motivos_perda'               => null,
            'tempo_medio_espera_resposta' => round((float)($row['tempo_medio_espera_min'] ?? 0), 1),
            'taxa_perda'                  => (float)($row['taxa_perda_pct'] ?? 0),
        ];
    }

    $motivosPerda = [];
    foreach ($porLista as $row) {
        $nome = trim((string)($row['vendedor'] ?? ''));
        foreach ($row['motivos_perda'] ?? [] as $m) {
            $motivosPerda[] = [
                'atendente'   => $nome,
                'motivo'      => (string)($m['motivo'] ?? ''),
                'quantidade'  => (int)($m['quantidade'] ?? 0),
                'valor_total' => 0.0,
            ];
        }
    }
    usort($motivosPerda, static function (array $a, array $b): int {
        return ($b['quantidade'] ?? 0) <=> ($a['quantidade'] ?? 0);
    });
    $motivosPerda = array_slice($motivosPerda, 0, 50);

    $clientesRejeitadosComContato = [];
    try {
        $detalhesRej = rdFetchRejeitadosDetalhados($rdToken, $start, $end, 3);
        $registrosRej = $detalhesRej['registros'] ?? [];
        if (is_array($registrosRej) && $registrosRej) {
            $rejMap = [];
            foreach ($registrosRej as $rj) {
                if (!is_array($rj)) continue;
                $cliente = trim((string)($rj['cliente'] ?? ''));
                $prescritor = trim((string)($rj['prescritor'] ?? 'Não informado'));
                $contato = trim((string)($rj['contato'] ?? ''));
                if ($cliente === '' || $contato === '') continue;
                $atendente = trim((string)($rj['atendente'] ?? 'Não informado'));
                $valor = (float)($rj['valor_rejeitado'] ?? 0);
                $kRaw = trim((string)($cliente . '|' . $prescritor . '|' . $contato . '|' . $atendente));
                $k = function_exists('mb_strtolower') ? mb_strtolower($kRaw, 'UTF-8') : strtolower($kRaw);
                if (!isset($rejMap[$k])) {
                    $rejMap[$k] = [
                        'cliente' => $cliente,
                        'prescritor' => $prescritor,
                        'contato' => $contato,
                        'qtd_rejeicoes' => 0,
                        'valor_rejeitado' => 0.0,
                        'atendente' => $atendente,
                    ];
                }
                $rejMap[$k]['qtd_rejeicoes']++;
                $rejMap[$k]['valor_rejeitado'] += $valor;
            }
            $clientesRejeitadosComContato = array_values($rejMap);
            usort($clientesRejeitadosComContato, static function (array $a, array $b): int {
                if (($b['qtd_rejeicoes'] ?? 0) !== ($a['qtd_rejeicoes'] ?? 0)) return ($b['qtd_rejeicoes'] ?? 0) <=> ($a['qtd_rejeicoes'] ?? 0);
                return ($b['valor_rejeitado'] ?? 0) <=> ($a['valor_rejeitado'] ?? 0);
            });
            $clientesRejeitadosComContato = array_slice($clientesRejeitadosComContato, 0, 80);
            foreach ($clientesRejeitadosComContato as &$itRej) {
                $itRej['valor_rejeitado'] = round((float)($itRej['valor_rejeitado'] ?? 0), 2);
            }
            unset($itRej);
        }
    } catch (Throwable $e) {
        // bloco opcional
    }

    $vendedoresCadastrados = [];
    foreach ($equipe as $ev) {
        $vendedoresCadastrados[] = [
            'nome'  => (string)($ev['atendente'] ?? ''),
            'ativo' => 1,
        ];
    }

    $consultorasAtivas = count($equipe);
    $receitaEquipe = 0.0;
    $conversaoMedia = 0.0;
    $tempoMedioEquipe = 0.0;
    $clientesAtendidosEquipe = 0;
    $taxaPerdaMedia = 0.0;
    if ($consultorasAtivas > 0) {
        foreach ($equipe as $v) {
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

    $canais = [];
    foreach ($rdNow['origens_geral'] ?? [] as $o) {
        $q = (int)($o['quantidade'] ?? 0);
        $canais[] = [
            'canal'              => (string)($o['origem'] ?? ''),
            'total_pedidos'      => $q,
            'vendas_aprovadas'   => $q,
            'receita'            => (float)($o['receita'] ?? 0),
            'conversao'          => null,
            'margem_percentual'  => null,
            'cac'                => null,
        ];
    }

    // No modo RD-only, não há produto/prescritor/especialidade nativos no mesmo formato legado.
    // Então montamos estes blocos a partir dos detalhamentos disponíveis no CRM.
    $topFormulas = [];
    foreach (($rdNow['origens_geral'] ?? []) as $o) {
        $topFormulas[] = [
            'produto'    => (string)($o['origem'] ?? 'Não informada'),
            'quantidade' => (int)($o['quantidade'] ?? 0),
        ];
    }
    usort($topFormulas, static function (array $a, array $b): int {
        return ($b['quantidade'] ?? 0) <=> ($a['quantidade'] ?? 0);
    });
    $topFormulas = array_slice($topFormulas, 0, 15);

    $topPrescritores = [];
    foreach ($porLista as $row) {
        $topPrescritores[] = [
            'prescritor' => (string)($row['vendedor'] ?? 'Não informado'),
            'receita'    => round((float)($row['receita'] ?? 0), 2),
        ];
    }
    usort($topPrescritores, static function (array $a, array $b): int {
        return ($b['receita'] ?? 0) <=> ($a['receita'] ?? 0);
    });
    $topPrescritores = array_slice($topPrescritores, 0, 15);

    $formulasMargem = [];
    $baseReceita = max((float)$receitaMes, 0.0);
    foreach (($rdNow['origens_geral'] ?? []) as $o) {
        $receitaOrigem = (float)($o['receita'] ?? 0);
        $formulasMargem[] = [
            'produto'           => (string)($o['origem'] ?? 'Não informada'),
            'margem_percentual' => $baseReceita > 0 ? round(($receitaOrigem / $baseReceita) * 100, 2) : 0.0,
        ];
    }
    usort($formulasMargem, static function (array $a, array $b): int {
        return ($b['margem_percentual'] ?? 0) <=> ($a['margem_percentual'] ?? 0);
    });
    $formulasMargem = array_slice($formulasMargem, 0, 15);

    $ticketEspecialidade = [];
    foreach ($porLista as $row) {
        $ganhos = (int)($row['total_ganhos'] ?? 0);
        $receitaVendedor = (float)($row['receita'] ?? 0);
        $ticketEspecialidade[] = [
            'especialidade' => (string)($row['vendedor'] ?? 'Não informado'),
            'ticket_medio'  => $ganhos > 0 ? round($receitaVendedor / $ganhos, 2) : 0.0,
        ];
    }
    usort($ticketEspecialidade, static function (array $a, array $b): int {
        return ($b['ticket_medio'] ?? 0) <=> ($a['ticket_medio'] ?? 0);
    });
    $ticketEspecialidade = array_slice($ticketEspecialidade, 0, 15);

    $approvedGpRd = gcApprovedCase('gp');
    $evolucaoMensalVendedores = gcEvolucaoMensalVendedoresPayload($pdo, $approvedGpRd, $equipe);
    $evolucaoMensalAprovRej = gcEvolucaoMensalAprovRejPayload($pdo, $approvedGpRd, $equipe);

    $crescimento = [
        'receita_mes'                     => round($receitaMes, 2),
        'crescimento_vs_mes_anterior'     => null,
        'crescimento_vs_mes_ano_passado'  => null,
        'ticket_medio'                    => $ticketMedio,
        'numero_vendas'                   => $totalGanhos,
        'numero_clientes_ativos_6_meses'  => null,
    ];

    $eficiencia = [
        'leads_recebidos'             => $volumeMacro,
        'conversao_geral'             => $rdNow['conversao_geral_pct'] ?? null,
        'tempo_medio_fechamento_horas' => $tempoFechHoras,
        'taxa_perda_cliente'          => $taxaPerdaCliente,
        'ltv'                         => null,
        'cac'                         => null,
        'ltv_cac'                     => null,
        'notas'                       => [
            'cac'     => 'CAC não disponível na API do CRM.',
            'ltv_cac' => '—',
            'ltv'     => 'LTV não calculado a partir do CRM neste modo.',
            'leads'   => '“Leads / volume” = negociações fechadas no período (ganhos + perdidos) + oportunidades abertas no funil (RD).',
        ],
    ];

    $rentabilidade = [
        'margem_bruta'            => null,
        'margem_contribuicao'     => null,
        'cmv_sobre_faturamento'   => null,
        'ponto_equilibrio'        => null,
        'lucro_operacional'       => round($receitaMes, 2),
        'margem_contribuicao_por' => [
            'atendente'          => [],
            'forma_farmaceutica' => [],
            'prescritor'         => [],
            'produto'            => [],
        ],
        'notas' => [
            'ponto_equilibrio' => 'Custos e margens de produto não vêm do RD Station CRM.',
            'modo'             => 'Receita e “lucro” exibidos refletem o valor dos deals ganhos no CRM; não há CMV do ERP.',
        ],
    ];

    $fidelizacao = [
        'taxa_recompra'            => null,
        'frequencia_media_compra'  => null,
        'base_ativa_90_dias'       => null,
        'nps'                      => null,
        'csat'                     => null,
        'notas'                    => [
            'nps_csat' => 'Fidelização e NPS não estão neste modo (somente CRM).',
        ],
    ];

    $conversoes = [
        'atendimento_para_orcamento' => gcPercent((float)$oportunidadesAbertas, (float)max($volumeMacro, 0)),
        'orcamento_para_venda'       => gcPercent((float)$totalGanhos, (float)max($oportunidadesAbertas + $totalGanhos + $totalPerdidos, 0)),
        'venda_para_recompra'        => null,
    ];

    $payload = [
        'success'        => true,
        'fonte_dados'    => 'rdstation_crm',
        'generated_at'   => date('c'),
        'periodo'        => [
            'tipo'         => $periodType,
            'data_de'      => $start,
            'data_ate'     => $end,
            'comparativos' => [
                'mes_anterior'              => ['data_de' => $prevStart, 'data_ate' => $prevEnd],
                'mesmo_periodo_ano_passado' => ['data_de' => $lyStart, 'data_ate' => $lyEnd],
            ],
        ],
        'crescimento'            => $crescimento,
        'eficiencia_comercial'   => $eficiencia,
        'rentabilidade'          => $rentabilidade,
        'fidelizacao'            => $fidelizacao,
        'funil_comercial'        => [
            'volume_atendimentos' => $volumeMacro,
            'oportunidades_abertas' => $oportunidadesAbertas,
            'orcamentos_enviados' => $oportunidadesAbertas,
            'vendas_fechadas'     => $totalGanhos,
            'conversao_por_etapa' => $conversoes,
            'gargalo_funil'       => $gargalo,
            'etapas_raw'          => $funilMap,
        ],
        'performance_vendedor'   => $equipe,
        'vendedor_gestao'        => [
            'resumo'                         => [
                'consultoras_ativas'       => $consultorasAtivas,
                'receita_equipe'           => round($receitaEquipe, 2),
                'conversao_media'          => $conversaoMedia,
                'tempo_medio_espera_min'   => $tempoMedioEquipe,
                'clientes_atendidos'       => $clientesAtendidosEquipe,
                'taxa_perda_media'         => $taxaPerdaMedia,
            ],
            'vendedores_cadastrados'         => $vendedoresCadastrados,
            'equipe'                         => $equipe,
            'motivos_perda'                  => $motivosPerda,
            'clientes_rejeitados_com_contato' => $clientesRejeitadosComContato,
            'evolucao_mensal_vendedores'     => $evolucaoMensalVendedores,
            'evolucao_mensal_aprovados'      => $evolucaoMensalAprovRej['aprovados'],
            'evolucao_mensal_rejeitados'     => $evolucaoMensalAprovRej['rejeitados'],
        ],
        'performance_canal'      => $canais,
        'inteligencia_comercial' => [
            'top_formulas_mais_vendidas'     => $topFormulas,
            'formulas_maior_margem'          => $formulasMargem,
            'prescritores_maior_receita'     => $topPrescritores,
            'ticket_medio_por_especialidade' => $ticketEspecialidade,
        ],
        'financeiro_aplicado'    => [
            'receita' => [
                'faturamento_bruto'  => round($receitaMes, 2),
                'faturamento_liquido' => round($receitaMes, 2),
            ],
            'custos' => [
                'cmv'                    => 0.0,
                'custo_fixo_mensal'      => null,
                'custo_variavel_por_venda' => null,
                'custo_por_atendimento'    => null,
                'cac'                      => null,
            ],
            'rentabilidade' => [
                'margem_bruta'        => null,
                'margem_contribuicao' => null,
                'lucro_operacional'   => round($receitaMes, 2),
                'ponto_equilibrio'    => null,
                'roi_campanhas'       => null,
            ],
            'caixa' => [
                'prazo_medio_recebimento' => null,
                'indice_inadimplencia'    => null,
                'taxa_antecipacao'        => null,
                'fluxo_projetado_3_meses' => null,
            ],
            'notas' => [
                'campos_nulos' => 'Painel alimentado só pelo RD Station CRM: sem CMV, inadimplência ou fluxo de caixa do ERP.',
            ],
        ],
        'rd_metricas'            => $rdNow,
        'rd_metricas_deferred'   => false,
        'rd_metricas_error'      => null,
        'lista_vendedores_nomes' => array_values(array_map(static function ($r) {
            return (string)($r['atendente'] ?? '');
        }, $equipe)),
    ];

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao montar JSON do painel (dados inválidos para serialização).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $json;
    exit;
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

    // LTV no período filtrado (antes: full scan em gestao_pedidos — muito lento com base grande).
    $ltvRow = gcFetchRow($pdo, "
        SELECT
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita_total,
            COALESCE(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.cliente END), 0) AS clientes_total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

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
            COALESCE(SUM(gp.quantidade), 0) AS quantidade_somada,
            SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS vendas_aprovadas,
            SUM(CASE WHEN NOT ({$approvedGp}) THEN 1 ELSE 0 END) AS vendas_rejeitadas,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            ROUND(
                COALESCE(
                    SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END)
                    / NULLIF(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.numero_pedido END), 0),
                    0
                ),
                2
            ) AS ticket_medio,
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

    // Rejeitados (Recusado / No carrinho) vivem em itens_orcamentos_pedidos; gestao_pedidos muitas vezes não traz essas linhas.
    $rejeitadosPorItensRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS atendente,
            COUNT(*) AS linhas_rejeitadas
        FROM itens_orcamentos_pedidos i
        LEFT JOIN gestao_pedidos gpLink
          ON gpLink.numero_pedido = i.numero
         AND gpLink.serie_pedido = i.serie
         AND gpLink.ano_referencia = i.ano_referencia
        WHERE DATE(i.data) BETWEEN :ini AND :fim
          AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
        GROUP BY COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
    ", ['ini' => $start, 'fim' => $end]);
    $rejeitadosLinesByNorm = [];
    $rejeitadosNomeByNorm = [];
    foreach ($rejeitadosPorItensRaw as $rrow) {
        $nomeRej = trim((string)($rrow['atendente'] ?? ''));
        if ($nomeRej === '' || $nomeRej === '(Sem atendente)') {
            continue;
        }
        if (!gcIsAllowedVendedora($nomeRej)) {
            continue;
        }
        $nkRej = gcNormName($nomeRej);
        if ($nkRej === '') {
            continue;
        }
        $rejeitadosLinesByNorm[$nkRej] = (float)($rrow['linhas_rejeitadas'] ?? 0);
        $rejeitadosNomeByNorm[$nkRej] = $nomeRej;
    }

    foreach ($vendedores as &$vnd) {
        $vnd['follow_ups_realizados'] = null;
        $vnd['motivos_perda'] = null; // detalhado em vendedor_gestao.motivos_perda
        $vnd['tempo_medio_espera_resposta'] = round((float)($vnd['tempo_medio_espera_min'] ?? 0), 1);
    }
    unset($vnd);

    $usuariosSetor = gcTryFetchAll($pdo, "
        SELECT
            TRIM(u.nome) AS nome,
            COALESCE(u.setor, '') AS setor,
            COALESCE(u.ativo, 1) AS ativo
        FROM usuarios u
        WHERE LOWER(TRIM(COALESCE(u.setor, ''))) LIKE '%vendedor%'
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
                'quantidade_somada' => 0,
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

    $presentNormsVend = [];
    foreach ($vendedores as $v) {
        $pk = gcNormName((string)($v['atendente'] ?? ''));
        if ($pk !== '') {
            $presentNormsVend[$pk] = true;
        }
    }
    foreach ($rejeitadosLinesByNorm as $nkOnly => $rejLinhas) {
        if ($rejLinhas <= 0 || isset($presentNormsVend[$nkOnly])) {
            continue;
        }
        $nomeOnly = trim((string)($rejeitadosNomeByNorm[$nkOnly] ?? ''));
        if ($nomeOnly === '' || !gcIsAllowedVendedora($nomeOnly)) {
            continue;
        }
        $vendedores[] = [
            'atendente' => $nomeOnly,
            'total_pedidos' => 0,
            'quantidade_somada' => 0,
            'vendas_aprovadas' => 0,
            'vendas_rejeitadas' => 0,
            'receita' => 0,
            'ticket_medio' => 0,
            'tempo_medio_espera_min' => 0,
            'clientes_atendidos' => 0,
            'conversao_individual' => null,
            'follow_ups_realizados' => null,
            'motivos_perda' => null,
            'tempo_medio_espera_resposta' => 0,
            'taxa_perda' => null,
        ];
        $presentNormsVend[$nkOnly] = true;
    }

    foreach ($vendedores as &$vnd) {
        $totalPedidos = (float)($vnd['total_pedidos'] ?? 0);
        $vendasAprovadas = (float)($vnd['vendas_aprovadas'] ?? 0);
        $fromGestaoRej = (float)($vnd['vendas_rejeitadas'] ?? 0);
        $normV = gcNormName((string)($vnd['atendente'] ?? ''));
        $fromItensRej = (float)($rejeitadosLinesByNorm[$normV] ?? 0);
        $vendasRejeitadas = $fromGestaoRej + $fromItensRej;
        $vnd['vendas_rejeitadas'] = $vendasRejeitadas;

        $denLinhas = $vendasAprovadas + $vendasRejeitadas;
        $vnd['conversao_individual'] = $denLinhas > 0
            ? gcPercent($vendasAprovadas, $denLinhas)
            : gcPercent($vendasAprovadas, $totalPedidos);
        $vnd['taxa_perda'] = $denLinhas > 0
            ? gcPercent($vendasRejeitadas, $denLinhas)
            : gcPercent($vendasRejeitadas, $totalPedidos);
    }
    unset($vnd);

    $metaSistemaVendedor = (float)(getenv('VENDEDOR_META_SISTEMA') ?: 60000);
    if ($metaSistemaVendedor <= 0) {
        $metaSistemaVendedor = 60000.0;
    }
    $metaPorNorm = [];
    try {
        $stMetaV = $pdo->query("
            SELECT TRIM(nome) AS nome, COALESCE(meta_mensal, 0) AS meta_mensal
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
              AND COALESCE(ativo, 1) = 1
        ");
        foreach ($stMetaV->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mr) {
            $nm = trim((string)($mr['nome'] ?? ''));
            if ($nm === '') {
                continue;
            }
            $metaPorNorm[gcNormName($nm)] = (float)($mr['meta_mensal'] ?? 0);
        }
    } catch (Throwable $e) {
        $metaPorNorm = [];
    }

    $receitaGrupoVend = 0.0;
    foreach ($vendedores as $vv) {
        $receitaGrupoVend += (float)($vv['receita'] ?? 0);
    }
    $comissaoGrupoPctVend = gcCalcComissaoGrupoPct($receitaGrupoVend);

    foreach ($vendedores as &$vnd) {
        $norm = gcNormName((string)($vnd['atendente'] ?? ''));
        $meta = (float)($metaPorNorm[$norm] ?? 0);
        if ($meta <= 0) {
            $meta = $metaSistemaVendedor;
        }
        $receita = (float)($vnd['receita'] ?? 0);
        $pctMeta = $meta > 0 ? round(($receita / $meta) * 100, 2) : 0.0;
        $ci = gcCalcComissaoIndividualPct($pctMeta);
        $ct = round($ci + $comissaoGrupoPctVend, 2);
        $vnd['meta_mensal'] = round($meta, 2);
        $vnd['percentual_meta'] = $pctMeta;
        $vnd['comissao_pct_individual'] = $ci;
        $vnd['comissao_pct_grupo'] = $comissaoGrupoPctVend;
        $vnd['comissao_pct_total'] = $ct;
        $vnd['previsao_ganho'] = round($receita * $ct / 100, 2);
    }
    unset($vnd);

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
    $metaMensalEquipeSoma = 0.0;
    $previsaoGanhoEquipeSoma = 0.0;
    if (!empty($vendedores)) {
        $consultorasAtivas = count($vendedores);
        foreach ($vendedores as $v) {
            $receitaEquipe += (float)($v['receita'] ?? 0);
            $conversaoMedia += (float)($v['conversao_individual'] ?? 0);
            $tempoMedioEquipe += (float)($v['tempo_medio_espera_resposta'] ?? 0);
            $clientesAtendidosEquipe += (int)($v['clientes_atendidos'] ?? 0);
            $taxaPerdaMedia += (float)($v['taxa_perda'] ?? 0);
            $metaMensalEquipeSoma += (float)($v['meta_mensal'] ?? 0);
            $previsaoGanhoEquipeSoma += (float)($v['previsao_ganho'] ?? 0);
        }
        $conversaoMedia = round($conversaoMedia / $consultorasAtivas, 2);
        $tempoMedioEquipe = round($tempoMedioEquipe / $consultorasAtivas, 1);
        $taxaPerdaMedia = round($taxaPerdaMedia / $consultorasAtivas, 2);
    }
    $percentualMetaEquipe = $metaMensalEquipeSoma > 0
        ? round(($receitaEquipe / $metaMensalEquipeSoma) * 100, 2)
        : null;

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
            'ltv_cac' => 'Depende do CAC para cálculo.',
            'ltv' => 'LTV = receita aprovada no período filtrado ÷ clientes distintos com compra nesse período (agilidade do painel; não é LTV vitalício).',
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

    $listaVendedoresNomes = array_values(array_filter(array_map(static function ($row) {
        return isset($row['nome']) ? trim((string)$row['nome']) : '';
    }, $vendedoresCadastrados), static function ($n) {
        return $n !== '';
    }));

    $evolucaoMensalVendedores = gcEvolucaoMensalVendedoresPayload($pdo, $approvedGp, $vendedores);
    $evolucaoMensalAprovRej = gcEvolucaoMensalAprovRejPayload($pdo, $approvedGp, $vendedores);

    $payload = [
        'success' => true,
        'generated_at' => date('c'),
        'lista_vendedores_nomes' => $listaVendedoresNomes,
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
                'meta_mensal_equipe' => round($metaMensalEquipeSoma, 2),
                'percentual_meta_equipe' => $percentualMetaEquipe,
                'previsao_ganho_equipe' => round($previsaoGanhoEquipeSoma, 2),
                'comissao_pct_grupo_equipe' => round($comissaoGrupoPctVend, 2),
            ],
            'vendedores_cadastrados' => $vendedoresCadastrados,
            'equipe' => $vendedores,
            'motivos_perda' => $motivosPerda,
            'clientes_rejeitados_com_contato' => $clientesRejeitadosComContato,
            'evolucao_mensal_vendedores' => $evolucaoMensalVendedores,
            'evolucao_mensal_aprovados' => $evolucaoMensalAprovRej['aprovados'],
            'evolucao_mensal_rejeitados' => $evolucaoMensalAprovRej['rejeitados'],
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

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao montar JSON do painel (dados inválidos para serialização).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $json;
    exit;
}

