<?php
ini_set('display_errors', '0');
if (function_exists('ini_set')) {
    @ini_set('log_errors', '1');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/rdstation_tv.php';

ob_start();
register_shutdown_function(static function () {
    $err = error_get_last();
    if (!$err) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($err['type'] ?? 0), $fatalTypes, true)) return;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo json_encode([
        'success' => false,
        'error' => (defined('IS_PRODUCTION') && IS_PRODUCTION)
            ? 'Falha fatal ao processar comparação do RD Station.'
            : ('Fatal: ' . (($err['message'] ?? 'erro desconhecido'))),
    ], JSON_UNESCAPED_UNICODE);
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$allowedOrigins = [
    'http://localhost',
    'https://localhost',
    'http://127.0.0.1',
    'https://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function cmpIsDate(string $v): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
}

try {
    $token = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($token === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'RDSTATION_CRM_TOKEN não configurado no .env.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $today = new DateTimeImmutable('today');
    $dataDe = trim((string)($_GET['data_de'] ?? ''));
    $dataAte = trim((string)($_GET['data_ate'] ?? ''));
    if (!cmpIsDate($dataDe) || !cmpIsDate($dataAte)) {
        $dataDe = $today->modify('first day of this month')->format('Y-m-d');
        $dataAte = $today->format('Y-m-d');
    } elseif ($dataDe > $dataAte) {
        $dataAte = $dataDe;
    }

    $limit = 100;
    $maxPages = 100;
    $page = 1;
    $rows = [];
    $totalReceita = 0.0;
    $totalDeals = 0;

    do {
        $resp = rdtvFetchDeals($token, $page, $limit, $dataDe, $dataAte, 'true');
        $deals = (isset($resp['deals']) && is_array($resp['deals'])) ? $resp['deals'] : [];
        foreach ($deals as $deal) {
            if (empty($deal['win'])) continue;
            $amount = rdtvGetAmountFromDeal($deal);
            $totalReceita += $amount;
            $totalDeals++;
            $responsavel = isset($deal['user']['name']) ? (string)$deal['user']['name'] : 'Sem responsável';
            $rows[] = [
                'id' => $deal['id'] ?? null,
                'nome' => $deal['name'] ?? '',
                'responsavel' => $responsavel,
                'valor' => round($amount, 2),
                'data_fechamento' => $deal['closed_at'] ?? ($deal['created_at'] ?? null),
                'pipeline' => rdtvGetPipelineNameFromDeal($deal),
                'etapa' => rdtvGetStageNameFromDeal($deal),
                'cliente' => rdtvExtractClienteFromDeal($deal),
                'contato' => rdtvExtractContatoFromDeal($deal),
                'prescritor' => rdtvExtractPrescritorFromDeal($deal),
            ];
        }

        $fetched = count($deals);
        $page++;
    } while ($fetched >= $limit && $page <= $maxPages);

    usort($rows, static function (array $a, array $b): int {
        $da = strtotime((string)($a['data_fechamento'] ?? '')) ?: 0;
        $db = strtotime((string)($b['data_fechamento'] ?? '')) ?: 0;
        return $db <=> $da;
    });

    echo json_encode([
        'success' => true,
        'fonte' => 'rdstation_crm',
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'total_deals' => $totalDeals,
        'total_receita' => round($totalReceita, 2),
        'data' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => (defined('IS_PRODUCTION') && IS_PRODUCTION)
            ? 'Erro ao buscar vendas do RD Station.'
            : $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

