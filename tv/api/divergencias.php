<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

// Banco: mesmo .env da raiz do MyPharm (DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET)
require_once __DIR__ . '/db.php';

$csv_file = __DIR__ . '/../Relatório de Gestão de Pedidos.csv';
$json_file = __DIR__ . '/cache/deals.json';

// 1. Ler RD Station (deals.json)
$rd_data = file_exists($json_file) ? json_decode(file_get_contents($json_file), true) : null;
$rd_deals = $rd_data['deals'] ?? [];

$rd_values_by_customer = [];
foreach ($rd_deals as $deal) {
    if (!isset($deal['cliente']) || !isset($deal['valor'])) continue;
    $raw_name = trim($deal['cliente']);
    
    // Evitar agrupar clientes genéricos ("Cliente não identificado", "Array") porque são de pessoas diferentes
    $is_generic = stripos($raw_name, 'cliente n') !== false || stripos($raw_name, 'array') !== false;
    $name = $is_generic && isset($deal['id']) ? $raw_name . '_' . $deal['id'] : $raw_name;
    
    $seller = $deal['vendedora'] ?? 'Desconhecido';
    $name_key = $name . '|||' . normalizeSeller($seller);
    
    if (!isset($rd_values_by_customer[$name_key])) {
        $rd_values_by_customer[$name_key] = [
            'id' => $deal['id'] ?? '',
            'titulo' => $deal['titulo'] ?? '',
            'value' => 0,
            'seller' => $seller,
            'raw_name' => $raw_name
        ];
    }
    $rd_values_by_customer[$name_key]['value'] += floatval($deal['valor']);
}

// 2. Ler Phusion (Banco de Dados — credenciais do .env na raiz do projeto)
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT cliente, vendedora, SUM(valor) as total_valor, GROUP_CONCAT(pedido_id SEPARATOR ', ') as ids FROM phusion_pedidos GROUP BY cliente, vendedora");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Falha ao consultar Phusion no banco: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$phusion_values_by_customer = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cliente = $row['cliente'];
    $vendedora = $row['vendedora'];
    $key = $cliente . '|||' . normalizeSeller($vendedora);
    
    $phusion_values_by_customer[$key] = [
        'value' => floatval($row['total_valor']),
        'seller' => $vendedora,
        'raw_name' => $cliente,
        'ids' => array_filter(explode(', ', $row['ids']))
    ];
}

$total_phusion = 0;
foreach ($phusion_values_by_customer as $ph_data) {
    $total_phusion += $ph_data['value'];
}
$total_rd = 0;
foreach ($rd_values_by_customer as $rd_data) {
    $total_rd += $rd_data['value'];
}

// 3. Match e Comparar
function removeAcentos($str) {
    if ($str === null) return '';
    return strtolower(preg_replace(array("/(á|à|ã|â|ä)/","/(é|è|ê|ë)/","/(í|ì|î|ï)/","/(ó|ò|õ|ô|ö)/","/(ú|ù|û|ü)/"),explode(" ","a e i o u"),$str));
}

function normalizeSeller($s) {
    $s = removeAcentos($s);
    if (stripos($s, 'vitoria carvalho') !== false || stripos($s, 'jessica vitoria') !== false) return 'jessica';
    if (stripos($s, 'carla pires') !== false || stripos($s, 'carla') !== false) return 'carla';
    if (stripos($s, 'clara leticia') !== false || stripos($s, 'clara') !== false) return 'clara';
    if (stripos($s, 'nailena') !== false) return 'nailena';
    if (stripos($s, 'giovanna') !== false) return 'giovanna';
    if (stripos($s, 'nereida') !== false) return 'nereida';
    if (stripos($s, 'micaela') !== false) return 'micaela';
    if (stripos($s, 'ananda') !== false) return 'ananda';
    if (stripos($s, 'mariana') !== false) return 'mariana';
    return $s;
}

function sellersMatch($s1, $s2) {
    if (!$s1 || !$s2) return true;
    return normalizeSeller($s1) === normalizeSeller($s2);
}

$divergences = [];
$matched_rd_names = [];
$unmatched_phusion = [];

foreach ($phusion_values_by_customer as $ph_name => $ph_data) {
    $ph_clean = removeAcentos($ph_name);
    $best_match = null;
    
    foreach ($rd_values_by_customer as $rd_key => $rd_data) {
        if (in_array($rd_key, $matched_rd_names)) continue;

        $rd_name = $rd_data['raw_name'] ?? '';

        $rd_clean = removeAcentos($rd_name);
        $rd_titulo = removeAcentos($rd_data['titulo'] ?? '');
        
        $match = false;
        
        // Priorizar match se vendedora for a mesma
        $same_seller = sellersMatch($ph_data['seller'] ?? '', $rd_data['seller'] ?? '');
        
        // 1. Exact Name Match
        if ($ph_clean === $rd_clean && $same_seller) {
            $match = true;
        }
        
        // 2. Contains Relationship (Name) - usa palavras inteiras para evitar "Maria" vs "Mariana"
        if (!$match && $same_seller) {
            $ph_words = array_filter(explode(' ', $ph_clean));
            $rd_words = array_filter(explode(' ', $rd_clean));
            
            if (count($ph_words) > 0 && count($rd_words) > 0) {
                $ph_str_words = implode(' ', array_values($ph_words));
                $rd_str_words = implode(' ', array_values($rd_words));

                // Se um dos nomes for apenas 1 palavra, checa se é igual ao início do outro
                if (count($ph_words) === 1 || count($rd_words) === 1) {
                    if (strpos($ph_str_words . ' ', $rd_str_words . ' ') === 0 || 
                        strpos($rd_str_words . ' ', $ph_str_words . ' ') === 0) {
                        $match = true;
                    }
                } else {
                    // Senão, exige match das primeiras 2 palavras para evitar falsos positivos como "Maria"
                    $min_words = 2;
                    $ph_prefix = implode(' ', array_slice(array_values($ph_words), 0, $min_words));
                    $rd_prefix = implode(' ', array_slice(array_values($rd_words), 0, $min_words));
                    
                    if ($ph_prefix === $rd_prefix) {
                        $match = true;
                    }
                }
            }
        }
        
        // 3. Title contains Phusion name - AGORA COM TRAVA DE VENDEDORA
        if (!$match && $same_seller && stripos($rd_titulo, $ph_clean) !== false) {
            $match = true;
        }
        
        // 4. Name inside RD Parentheses - AGORA COM TRAVA DE VENDEDORA
        if (!$match && $same_seller && preg_match('/\((.*?)\)/', $rd_name, $m)) {
            $inside = removeAcentos($m[1]);
            if ($inside === $ph_clean || stripos($ph_clean, $inside) !== false) {
                $match = true;
            }
        }

        // 5. ID Match - procura qualquer número do título do RD nos IDs do Phusion
        if (!$match && $same_seller) {
            $ph_ids_list = $ph_data['ids'] ?? [];
            $titulo = $rd_data['titulo'] ?? '';
            // Extrai todos os números de 4+ dígitos do título (IDs de pedidos)
            preg_match_all('/\b(\d{4,})\b/', $titulo, $m_ids);
            foreach (($m_ids[1] ?? []) as $num) {
                if (in_array($num, $ph_ids_list)) {
                    $match = true;
                    break;
                }
            }
        }

        // 7. Lenient Name Match (First 2 words match) - STRICTER: must match seller too
        if (!$match) {
            $ph_parts = explode(' ', $ph_clean);
            $rd_parts = explode(' ', $rd_clean);
            if (count($ph_parts) >= 2 && count($rd_parts) >= 2) {
                if ($ph_parts[0] === $rd_parts[0] && $ph_parts[1] === $rd_parts[1]) {
                    // Only match if seller is same or compatible
                    if (sellersMatch($ph_data['seller'], $rd_data['seller'])) {
                        $match = true;
                    }
                }
            }
        }
        
        if ($match) {
            $best_match = $rd_data;
            $matched_rd_names[] = $rd_key;
            break;
        }
    }
    
    // 5. Fallback: Erro de Nomenclatura (Mesma vendedora e valor EXATO)
    // 5. Fallback: Erro de Nomenclatura (Mesma vendedora e valor EXATO o mais próximo possível)
    if (!$best_match) {
        foreach ($rd_values_by_customer as $rd_key => $rd_data) {
            if (in_array($rd_key, $matched_rd_names)) continue;
            
            if (sellersMatch($rd_data['seller'], $ph_data['seller']) && abs($rd_data['value'] - $ph_data['value']) < 0.01) {
                $best_match = $rd_data;
                $matched_rd_names[] = $rd_key;
                $best_match['is_naming_error'] = true;
                break;
            }
        }
    }
    
    if ($best_match) {
        $val_phusion = round($ph_data['value'], 2);
        $val_rd = round($best_match['value'], 2);
        $diff = abs($val_rd - $val_phusion);
        $is_naming_error = !empty($best_match['is_naming_error']);
        
        $raw_rd_name = $best_match['raw_name'] ?? '';
        if (strpos($raw_rd_name, '_') !== false && (stripos($raw_rd_name, 'cliente n') !== false || stripos($raw_rd_name, 'array') !== false)) {
            $raw_rd_name = explode('_', $raw_rd_name)[0];
        }
        
        if ($diff >= 0.01) { // Só mostra se a diferença de VALOR for real
            $divergences[] = [
                'deal_id' => $best_match['id'],
                'cliente' => ucwords(strtolower($ph_name)),
                'rd_cliente' => ucwords(strtolower($raw_rd_name)),
                'vendedora' => $ph_data['seller'] ?? '',
                'phusion_val' => $val_phusion,
                'rd_val' => $val_rd,
                'diff' => $diff,
                'is_naming_error' => $is_naming_error,
                'phusion_ids' => $ph_data['ids'] ?? []
            ];
        }
    } else {
        $unmatched_phusion[] = [
            'cliente' => ucwords(strtolower($ph_name)),
            'vendedora' => $ph_data['seller'] ?? '',
            'phusion_val' => round($ph_data['value'], 2),
            'ids' => $ph_data['ids'] ?? []
        ];
    }
}

// Check for unmatched RD deals
$unmatched_rd = [];
foreach ($rd_values_by_customer as $rd_key => $rd_data) {
    if (!in_array($rd_key, $matched_rd_names)) {
        $unmatched_rd[] = [
            'deal_id' => $rd_data['id'],
            'cliente' => ucwords(strtolower($rd_data['raw_name'] ?? '')),
            'vendedora' => $rd_data['seller'] ?? '',
            'rd_val' => round($rd_data['value'], 2),
            'titulo' => $rd_data['titulo'] ?? ''
        ];
    }
}

// Calcular totais reais para exibir no frontend
$total_phusion = 0;
foreach ($phusion_values_by_customer as $ph_data) {
    $total_phusion += $ph_data['value'];
}

$total_rd = 0;
foreach ($rd_values_by_customer as $rd_data) {
    $total_rd += $rd_data['value'];
}

// Ordenar pela maior diferença
usort($divergences, function($a, $b) {
    return $b['diff'] <=> $a['diff']; // Descendente
});

// 4. Buscar dados brutos para as abas detalhadas (Somando por pedido_id no Phusion)
try {
    $stmt_raw = $pdo->query("SELECT pedido_id, data_aprovacao, cliente, vendedora, SUM(valor) as valor FROM phusion_pedidos GROUP BY pedido_id ORDER BY data_aprovacao DESC, pedido_id DESC");
    $phusion_raw = $stmt_raw->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Falha ao listar pedidos Phusion: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// RD Raw já está em $rd_deals
$rd_raw = $rd_deals;

echo json_encode([
    'divergencias' => $divergences,
    'unmatched_phusion' => $unmatched_phusion,
    'unmatched_rd' => $unmatched_rd,
    'total_phusion' => round($total_phusion, 2),
    'total_rd' => round($total_rd, 2),
    'phusion_raw' => $phusion_raw,
    'rd_raw' => $rd_raw,
    'debug' => [
        'parsed_phusion' => count($phusion_values_by_customer),
        'parsed_rd' => count($rd_values_by_customer)
    ]
]);
?>
