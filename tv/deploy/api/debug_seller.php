<?php
/**
 * DEBUG — Mostra todos os deals de uma vendedora com pipeline/stage info
 * Uso: /tv/api/debug_seller.php?seller=Nereida
 *
 * Remove este arquivo após diagnóstico!
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';

$filterSeller = strtolower(trim($_GET['seller'] ?? ''));

function getPeriod(): array {
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
    return ['start' => $start->format('Y-m-d'), 'end' => $now->format('Y-m-d')];
}

$period = getPeriod();
$page   = 1;
$limit  = 200;
$deals_found = [];
$funnels_seen = [];

do {
    $params = http_build_query([
        'token'      => RD_API_TOKEN,
        'page'       => $page,
        'limit'      => $limit,
        'win'        => 'true',
        'start_date' => $period['start'],
        'end_date'   => $period['end'],
    ]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => RD_API_BASE . '/deals?' . $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data  = json_decode($body, true);
    $deals = $data['deals'] ?? [];

    foreach ($deals as $deal) {
        if (empty($deal['win'])) continue;

        $nomeCrm = strtolower(trim((string)($deal['user']['name'] ?? '')));
        if ($filterSeller !== '' && strpos($nomeCrm, $filterSeller) === false) continue;

        // Data
        $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
        $date    = $dateStr ? substr((string)$dateStr, 0, 10) : '';
        if ($date !== '' && $date < $period['start']) continue;
        if ($date === '' || $date > $period['end']) continue;

        // Valores
        $amount_total   = (float)($deal['amount_total']  ?? 0);
        $amount_unique  = (float)($deal['amount_unique']  ?? 0);
        $amount_montly  = (float)($deal['amount_montly']  ?? 0);
        $used_amount    = $amount_total;
        if ($used_amount <= 0) $used_amount = $amount_unique + $amount_montly;

        // Funil / Stage
        $funnel = $deal['funnel']['name'] ?? ($deal['deal_stage']['funnel_name'] ?? 'N/A');
        $stage  = $deal['deal_stage']['name'] ?? 'N/A';
        $funnelKey = $funnel . ' > ' . $stage;
        $funnels_seen[$funnelKey] = ($funnels_seen[$funnelKey] ?? 0) + 1;

        $deals_found[] = [
            'id'             => $deal['_id'] ?? $deal['id'] ?? '?',
            'nome'           => $deal['name'] ?? '?',
            'vendedor'       => $deal['user']['name'] ?? '?',
            'data'           => $date,
            'funnel'         => $funnel,
            'stage'          => $stage,
            'amount_total'   => $amount_total,
            'amount_unique'  => $amount_unique,
            'amount_montly'  => $amount_montly,
            'valor_usado'    => $used_amount,
        ];
    }

    $fetched = count($deals);
    $page++;
} while ($fetched >= $limit && $page <= 15);

$total_valor = array_sum(array_column($deals_found, 'valor_usado'));

echo json_encode([
    'filtro'          => $filterSeller,
    'periodo'         => $period,
    'total_deals'     => count($deals_found),
    'total_valor'     => round($total_valor, 2),
    'funnels_agrupados' => $funnels_seen,
    'deals'           => $deals_found,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
