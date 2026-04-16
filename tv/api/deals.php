<?php
/**
 * API de Deals Detalhados - RD Station CRM
 * 
 * Busca deals ganhos (vendas) do RD Station CRM API v1
 * e retorna dados detalhados de cada venda individual.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';

// ============================================================
// FUNÇÕES
// ============================================================

function fetchDealsPage(string $token, int $page, int $limit, string $startDate, string $endDate, string $win = 'true'): array {
    $params = [
        'token'      => $token,
        'page'       => $page,
        'limit'      => $limit,
        'win'        => $win,
        'start_date' => $startDate,
        'end_date'   => $endDate,
    ];

    $url = RD_API_BASE . '/deals?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException("cURL error: {$error}");
    }
    if ($status === 401) {
        throw new RuntimeException("Token inválido (401).");
    }
    if ($status !== 200) {
        throw new RuntimeException("RD Station API HTTP {$status}.");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Resposta inválida da API.");
    }

    return $data;
}

function getPeriodDatesForDeals(): array {
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

    switch (PERIODO_RANKING) {
        case 'semanal':
            $start = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
            break;
        case 'anual':
            $start = (clone $now)->modify('first day of January')->setTime(0, 0, 0);
            break;
        case 'mensal':
        default:
            $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
            break;
    }

    return [
        'start' => $start->format('Y-m-d'),
        'end'   => $now->format('Y-m-d'),
    ];
}

// Ler cache de deals
function readDealsCache(): ?array {
    $file = CACHE_DIR . '/deals.json';
    if (!file_exists($file)) return null;
    $age = time() - filemtime($file);
    return [
        'age'  => $age,
        'data' => json_decode(file_get_contents($file), true),
    ];
}

// Salvar cache de deals
function writeDealsCache(array $data): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    file_put_contents(CACHE_DIR . '/deals.json', json_encode($data, JSON_UNESCAPED_UNICODE));
}

// ============================================================
// LÓGICA PRINCIPAL
// ============================================================

try {
    // Parâmetro opcional: vendedora específica
    $filterSeller = isset($_GET['seller']) ? trim($_GET['seller']) : '';

    // Verificar cache válido
    $cache = readDealsCache();
    if ($cache && $cache['age'] < CACHE_TTL && !empty($cache['data'])) {
        $result = $cache['data'];
        
        // Aplicar filtro de vendedora se necessário
        if ($filterSeller !== '') {
            $result['deals'] = array_values(array_filter($result['deals'], function($d) use ($filterSeller) {
                return stripos($d['vendedora'], $filterSeller) !== false || 
                       stripos($d['vendedora_crm'], $filterSeller) !== false;
            }));
            $result['total'] = count($result['deals']);
            $result['valor_total'] = array_sum(array_column($result['deals'], 'valor'));
        }
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Calcular período
    $period = getPeriodDatesForDeals();

    // Buscar TODOS os deals GANHOS no período (paginado)
    $allDeals = [];
    $page     = 1;
    $limit    = 200;
    $maxPages = 15;
    $valorTotal = 0.0;

    global $SELLER_CONFIG;

    do {
        $data  = fetchDealsPage(RD_API_TOKEN, $page, $limit, $period['start'], $period['end'], 'true');
        $deals = $data['deals'] ?? [];

        foreach ($deals as $deal) {
            if (empty($deal['win'])) continue;

            // Validar data dentro do período
            $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
            $date    = $dateStr ? substr((string)$dateStr, 0, 10) : '';
            if ($date !== '' && $date < $period['start']) continue;
            if ($date === '' || $date > $period['end']) continue;

            // Extrair dados
            $nomeCrm  = trim((string)($deal['user']['name'] ?? ''));
            if ($nomeCrm === '') continue;

            // Calcular valor
            $amount = (float)($deal['amount_total'] ?? 0);
            if ($amount <= 0) {
                $amount  = (float)($deal['amount_montly'] ?? 0);
                $amount += (float)($deal['amount_unique'] ?? 0);
            }

            // Nome de exibição da vendedora
            $config = $SELLER_CONFIG[$nomeCrm] ?? [];
            $nomeExibicao = $config['nome_exibicao'] ?? $nomeCrm;

            // Nome do deal / titulo
            $dealName = trim((string)($deal['name'] ?? 'Sem título'));

            // Contato / Cliente
            $contactName = '';
            if (!empty($deal['deal_source'])) {
                $contactName = trim((string)($deal['deal_source']));
            }
            if (empty($contactName) && !empty($deal['contacts']) && is_array($deal['contacts'])) {
                $contact = $deal['contacts'][0];
                $contactName = trim(($contact['name'] ?? '') . ' ' . ($contact['title'] ?? ''));
            }
            if (empty($contactName)) {
                $contactName = 'Cliente não identificado';
            }

            // Organização
            $orgName = '';
            if (!empty($deal['organization']) && is_array($deal['organization'])) {
                $orgName = trim((string)($deal['organization']['name'] ?? ''));
            }

            // Data formatada
            $closedAt = $deal['closed_at'] ?? $deal['created_at'] ?? '';
            $dataFormatada = '';
            if ($closedAt) {
                try {
                    $dt = new DateTime($closedAt, new DateTimeZone('America/Sao_Paulo'));
                    $dataFormatada = $dt->format('d/m/Y H:i');
                } catch (Exception $e) {
                    $dataFormatada = substr($closedAt, 0, 10);
                }
            }

            // Rating / Stars
            $rating = (int)($deal['rating'] ?? 0);

            // Stage / Pipeline
            $dealStage = trim((string)($deal['deal_stage']['name'] ?? 'Vendida'));

            $valorTotal += $amount;

            $allDeals[] = [
                'id'             => $deal['_id'] ?? $deal['id'] ?? uniqid(),
                'titulo'         => $dealName,
                'cliente'        => $contactName,
                'organizacao'    => $orgName,
                'vendedora'      => $nomeExibicao,
                'vendedora_crm'  => $nomeCrm,
                'vendedora_foto' => $config['foto'] ?? '',
                'valor'          => round($amount, 2),
                'data'           => $dataFormatada,
                'data_iso'       => $date,
                'rating'         => $rating,
                'status'         => 'Vendida',
                'stage'          => $dealStage,
            ];
        }

        $fetched = count($deals);
        $page++;
    } while ($fetched >= $limit && $page <= $maxPages);

    // Ordenar por data mais recente primeiro
    usort($allDeals, function ($a, $b) {
        return strcmp($b['data_iso'], $a['data_iso']);
    });

    $result = [
        'deals'       => $allDeals,
        'total'       => count($allDeals),
        'valor_total' => round($valorTotal, 2),
        'periodo'     => [
            'inicio' => $period['start'],
            'fim'    => $period['end'],
        ],
        'atualizado_em' => (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i:s'),
    ];

    // Salvar cache
    writeDealsCache($result);

    // Aplicar filtro de vendedora se necessário
    if ($filterSeller !== '') {
        $result['deals'] = array_values(array_filter($result['deals'], function($d) use ($filterSeller) {
            return stripos($d['vendedora'], $filterSeller) !== false || 
                   stripos($d['vendedora_crm'], $filterSeller) !== false;
        }));
        $result['total'] = count($result['deals']);
        $result['valor_total'] = array_sum(array_column($result['deals'], 'valor'));
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(502);
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
