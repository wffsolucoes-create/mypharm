<?php
/**
 * Proxy API - Ranking Comercial
 * 
 * Busca dados de deals (ganhos) do RD Station CRM API v1,
 * agrega por vendedor, calcula ranking e retorna JSON para o frontend React.
 * 
 * Usa cache local para performance e para preservar posicao_anterior.
 * 
 * Agregação alinhada a api/rdstation_tv.php (rdtvAccumulateWonLostPage + rdtvIsInPeriod + valor do deal).
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
require_once __DIR__ . '/../../api/rdstation_tv.php';

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
 * Valor do deal igual ao painel / rdstation_tv (amount_total &gt; 0 senão monthly+unique).
 */
function tvDealValorRd(array $deal): float {
    $amount = (float)($deal['amount_total'] ?? 0);
    if ($amount <= 0) {
        return rdtvGetAmountFromDeal($deal);
    }
    return $amount;
}

/**
 * Nome agregado no RD (rdtvResolveNome) → chave em $SELLER_CONFIG na TV.
 */
function tvRdtvNameToConfigKey(string $rdtvNome): string {
    static $map = [
        'Jessica Vitoria'           => 'Vitória Carvalho',
        'Carla'                     => 'Carla Pires - Consultora',
        'Clara Leticia'             => 'Clara Letícia',
        'Ananda Reis'               => 'Ananda',
    ];
    return $map[$rdtvNome] ?? $rdtvNome;
}

/**
 * Nome para bucket de agregação: prioriza mapeamento RD (igual Gestão/TV corrida); senão canonical local.
 */
function tvResolveSellerAggregationKey(string $nomeCrmRaw): string {
    $nomeCrmRaw = trim($nomeCrmRaw);
    if ($nomeCrmRaw === '') {
        return '';
    }
    $rd = rdtvResolveNome($nomeCrmRaw);
    if ($rd !== null) {
        return tvRdtvNameToConfigKey($rd);
    }
    return canonicalSellerName($nomeCrmRaw);
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
    $now = new DateTime('now', new DateTimeZone(TV_TIMEZONE));

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
            // Igual rdtvAccumulateWonLostPage: só negócios com win explícito na API.
            if (empty($deal['win'])) {
                continue;
            }
            if (!rdtvIsInPeriod($deal, $period['start'], $period['end'])) {
                continue;
            }

            // Extrair nome e email do vendedor
            $nomeCrmRaw = trim((string)($deal['user']['name'] ?? $deal['user_name'] ?? ''));
            $emailVendedor = trim((string)($deal['user']['email'] ?? ''));
            if ($nomeCrmRaw === '' && $emailVendedor !== '') {
                // Fallback: se o nome não vier no payload, usa o prefixo do email.
                $nomeCrmRaw = preg_replace('/@.*/', '', $emailVendedor) ?: '';
            }
            if ($nomeCrmRaw === '') {
                continue;
            }
            $nomeCrm = tvResolveSellerAggregationKey($nomeCrmRaw);
            if ($nomeCrm === '') {
                continue;
            }

            $amount = tvDealValorRd($deal);

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
    $now = (new DateTime('now', new DateTimeZone(TV_TIMEZONE)))->format('Y-m-d H:i:s');
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
