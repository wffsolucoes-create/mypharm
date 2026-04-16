<?php
/**
 * Proxy API - Ranking Comercial
 * 
 * Busca dados de deals (ganhos) do RD Station CRM API v1,
 * agrega por vendedor, calcula ranking e retorna JSON para o frontend React.
 * 
 * Usa cache local para performance e para preservar posicao_anterior.
 * 
 * Baseado no padrão comprovado de rdstation_tv.php do projeto mypharm.
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
// FUNÇÕES AUXILIARES
// ============================================================

/**
 * Busca deals do RD Station CRM API v1
 */
function fetchDeals(string $token, int $page, int $limit, string $startDate, string $endDate, string $win = 'true'): array {
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
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
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
        throw new RuntimeException("Token inválido (401). Verifique RD_API_TOKEN no config.php.");
    }
    if ($status !== 200) {
        throw new RuntimeException("RD Station API HTTP {$status}.");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Resposta inválida da API RD Station.");
    }

    return $data;
}

/**
 * Extrai o valor monetário de um deal.
 * O RD Station pode popular amount_total, amount_montly e/ou amount_unique.
 * Usa a soma dos componentes quando disponíveis; senão, usa amount_total.
 */
function getAmountFromDeal(array $deal): float {
    $monthly = (float)($deal['amount_montly'] ?? 0);
    $unique  = (float)($deal['amount_unique'] ?? 0);
    $total   = (float)($deal['amount_total'] ?? 0);

    $sum = $monthly + $unique;
    if ($sum > 0) {
        return $sum;
    }
    return $total;
}

/**
 * Normaliza string (minúsculo, sem acentos) para comparação.
 */
function normalizeForCompare(string $text): string {
    if (function_exists('mb_strtolower')) {
        $text = trim(mb_strtolower($text, 'UTF-8'));
    } else {
        $text = trim(strtolower($text));
    }
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    return strtr($text, $map);
}

/**
 * Consolida variações de nome da consultora para chave canônica.
 */
function canonicalSellerName(string $nomeCrm): string {
    $n = normalizeForCompare($nomeCrm);

    if (strpos($n, 'vitoria') !== false || strpos($n, 'jessica') !== false) return 'Vitória Carvalho';
    if (strpos($n, 'carla') !== false) return 'Carla Pires - Consultora';
    if (strpos($n, 'clara') !== false) return 'Clara Letícia';
    if (strpos($n, 'nailena') !== false) return 'Nailena';
    if (strpos($n, 'ananda') !== false) return 'Ananda';
    if (strpos($n, 'micaela') !== false) return 'Micaela Nicolle';
    if (strpos($n, 'nereida') !== false) return 'Nereida';
    if (strpos($n, 'giovanna') !== false) return 'Giovanna';
    if (strpos($n, 'mariana') !== false) return 'Mariana';

    return trim($nomeCrm);
}

/**
 * Calcula intervalo de datas do período
 */
function getPeriodDates(): array {
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

    switch (PERIODO_RANKING) {
        case 'semanal':
            $start = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
            $end = clone $now;
            break;
        case 'anual':
            $start = (clone $now)->modify('first day of January')->setTime(0, 0, 0);
            $end = clone $now;
            break;
        case 'mensal':
        default:
            $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
            // Para bater com o RD (filtro de mês cheio), usa o último dia do mês atual.
            $end = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
            break;
    }

    return [
        'start' => $start->format('Y-m-d'),
        'end'   => $end->format('Y-m-d'),
    ];
}

/**
 * Verifica se a data de fechamento do deal está dentro do período.
 * Usa closed_at (Data de fechamento) como referência — mesmo campo
 * usado no filtro do painel do RD Station.
 * Compara só YYYY-MM-DD sem converter timezone para não cortar deals válidos.
 */
function dealInPeriod(array $deal, array $period): bool {
    $dateStr = $deal['closed_at'] ?? '';
    if ($dateStr === '') {
        return true;
    }
    $date = substr((string)$dateStr, 0, 10);
    if (strlen($date) !== 10) {
        return true;
    }
    return $date >= $period['start'] && $date <= $period['end'];
}

/**
 * Lê o cache anterior
 */
function readCache(): ?array {
    $file = CACHE_DIR . '/ranking.json';
    if (!file_exists($file)) return null;
    $age = time() - filemtime($file);
    return [
        'age'  => $age,
        'data' => json_decode(file_get_contents($file), true),
    ];
}

/**
 * Salva o cache
 */
function writeCache(array $data): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    file_put_contents(CACHE_DIR . '/ranking.json', json_encode($data, JSON_UNESCAPED_UNICODE));
}

// ============================================================
// LÓGICA PRINCIPAL
// ============================================================

try {
    // 1. Verificar cache válido
    $cache = readCache();
    if ($cache && $cache['age'] < CACHE_TTL && !empty($cache['data'])) {
        echo json_encode($cache['data'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Calcular período
    $period = getPeriodDates();

    // 3. Buscar TODOS os deals GANHOS no período (paginado)
    $porVendedor = [];
    $page    = 1;
    $limit   = 200;
    // Limite de segurança para evitar timeout do PHP/Apache.
    $maxPages = 40;
    $totalPages = null;

    while (true) {
        $data  = fetchDeals(RD_API_TOKEN, $page, $limit, $period['start'], $period['end'], 'true');
        $deals = is_array($data['deals'] ?? null) ? $data['deals'] : [];
        $fetched = count($deals);

        if ($totalPages === null) {
            if (isset($data['meta']['total_pages']) && is_numeric($data['meta']['total_pages'])) {
                $totalPages = (int)$data['meta']['total_pages'];
            } elseif (isset($data['total_pages']) && is_numeric($data['total_pages'])) {
                $totalPages = (int)$data['total_pages'];
            }
        }

        foreach ($deals as $deal) {
            if (empty($deal['win'])) continue;
            if (!dealInPeriod($deal, $period)) continue;

            // Extrair nome e email do vendedor
            $nomeCrmRaw = trim((string)($deal['user']['name'] ?? ''));
            $emailVendedor = trim((string)($deal['user']['email'] ?? ''));
            if ($nomeCrmRaw === '') continue;
            $nomeCrm = canonicalSellerName($nomeCrmRaw);

            // Extrair valor
            $amount = getAmountFromDeal($deal);

            // Agrupar (preserva email da primeira ocorrência)
            if (!isset($porVendedor[$nomeCrm])) {
                $porVendedor[$nomeCrm] = [
                    'receita' => 0.0,
                    'count'   => 0,
                    'email'   => $emailVendedor
                ];
            }
            $porVendedor[$nomeCrm]['receita'] += $amount;
            $porVendedor[$nomeCrm]['count']++;
        }

        if ($fetched === 0) break;
        if ($fetched < $limit) break;
        if ($totalPages !== null && $page >= $totalPages) break;
        if ($page >= $maxPages) break;
        $page++;
    }

    // 4. Montar ranking no formato SellerRecord
    global $SELLER_CONFIG;
    $ranking = [];
    $now = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    $id = 1;

    foreach ($porVendedor as $nomeCrm => $dados) {
        $config     = $SELLER_CONFIG[$nomeCrm] ?? [];

        // Busca foto do banco
        $fotoURL = getFotoFromDB($nomeCrm, $dados['email'] ?? '');

        // Busca meta do banco usando email ou nome como fallback
        $metaValor = getMetaFromDB($nomeCrm, $dados['email'] ?? '');
        if ($metaValor === null) {
            $metaValor = $config['meta'] ?? META_GLOBAL;  // fallback: config ou global
        }

        $percentual = $metaValor > 0 ? round(($dados['receita'] / $metaValor) * 100, 1) : 0;

        $ranking[] = [
            'id'                 => $id,
            'nome'               => $config['nome_exibicao'] ?? $nomeCrm,
            'foto'               => $fotoURL ?? '',  // Foto do banco, ou vazio (mostra iniciais)
            'equipe'             => $config['equipe'] ?? 'Equipe Comercial',
            'vendas_qtd'         => $dados['count'],
            'vendas_valor'       => round($dados['receita'], 2),
            'pontuacao'          => $dados['count'] * 10,
            'meta_valor'         => $metaValor,
            'percentual_meta'    => $percentual,
            'posicao_atual'      => 0,
            'posicao_anterior'   => 0,
            'ultima_atualizacao' => $now,
        ];
        $id++;
    }

    // 5. Ordenar por vendas_valor desc e atribuir posições
    usort($ranking, function ($a, $b) {
        return $b['vendas_valor'] <=> $a['vendas_valor'];
    });

    foreach ($ranking as $i => &$seller) {
        $seller['posicao_atual'] = $i + 1;
    }
    unset($seller);

    // 6. Mapear posição anterior a partir do cache
    if ($cache && !empty($cache['data'])) {
        // Indexar cache anterior por nome
        $prevPositions = [];
        foreach ($cache['data'] as $prev) {
            $prevPositions[$prev['nome']] = $prev['posicao_atual'];
        }
        foreach ($ranking as &$seller) {
            $seller['posicao_anterior'] = $prevPositions[$seller['nome']] ?? $seller['posicao_atual'];
        }
        unset($seller);
    } else {
        foreach ($ranking as &$seller) {
            $seller['posicao_anterior'] = $seller['posicao_atual'];
        }
        unset($seller);
    }

    // 7. Salvar cache e retornar
    writeCache($ranking);
    echo json_encode($ranking, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Fallback resiliente: se RD estiver indisponível, devolve último cache conhecido.
    $fallback = readCache();
    if ($fallback && !empty($fallback['data']) && is_array($fallback['data'])) {
        echo json_encode($fallback['data'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(502);
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
