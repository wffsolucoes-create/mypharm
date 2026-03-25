<?php
/**
 * API exclusiva da Gestão Comercial.
 * Tudo que corresponda à gestão comercial usa esta API (sessão, logout, dashboard, lista vendedores).
 *
 * TV Corrida de Vendas:
 *   Se RDSTATION_CRM_TOKEN estiver configurado no .env, os dados vêm diretamente
 *   da API do RD Station CRM (deals won). Caso contrário, usa o banco local (legado).
 */
ini_set('display_errors', '0');
if (function_exists('ini_set')) {
    @ini_set('log_errors', '1');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/modules/gestao_comercial.php';
require_once __DIR__ . '/api/rdstation_tv.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$allowedOrigins = [
    'http://localhost',
    'https://localhost',
    'http://127.0.0.1',
    'https://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';

$allowedActions = [
    'check_session',
    'logout',
    'debug_rd_metricas_public',
    'debug_rd_tv_public',
    'debug_rd_rejeitados_public',
    'gestao_comercial_dashboard',
    'gestao_comercial_dashboard_rd',
    'gestao_comercial_lista_vendedores',
    'gestao_rd_metricas',
    'gestao_rejeitados_rd',
    'vendedor_dashboard_rd',
    'tv_corrida_vendedores',
];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Compatibilidade: se a sessão PHP existe mas a tabela user_sessions perdeu o registro
 * (limpeza/manual/restore), recria o vínculo e evita 401 falso no painel.
 */
function gcEnsureSessionIsValidOrRepair(PDO $pdo): array
{
    $sessionCheck = validateAndRefreshUserSession($pdo);
    if (($sessionCheck['valid'] ?? false) === true) {
        return ['valid' => true];
    }
    $reason = (string)($sessionCheck['reason'] ?? '');
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && in_array($reason, ['session_not_found', 'session_gone'], true)) {
        try {
            registerUserSession($pdo, $userId);
            $sessionCheck2 = validateAndRefreshUserSession($pdo);
            if (($sessionCheck2['valid'] ?? false) === true) {
                return ['valid' => true, 'repaired' => true];
            }
            return $sessionCheck2;
        } catch (Throwable $e) {
            return ['valid' => false, 'reason' => 'repair_failed'];
        }
    }
    return $sessionCheck;
}

function gcDateRangeFromQuery(): array
{
    $today = new DateTimeImmutable('today');
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : '';
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : '';
    $isDate = static function ($v) {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v);
    };
    if (!$isDate($dataDe) || !$isDate($dataAte)) {
        $dataDe = $today->modify('first day of this month')->format('Y-m-d');
        $dataAte = $today->format('Y-m-d');
    } elseif ($dataDe > $dataAte) {
        $dataAte = $dataDe;
    }
    return [$dataDe, $dataAte];
}

function gcIsLocalDebugRequest(): bool
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        return true;
    }
    // IPv4 mapeado em IPv6 (comum no Windows / Apache)
    if ($remote !== '' && strpos($remote, '127.0.0.1') !== false) {
        return true;
    }
    if ($host !== '' && (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false)) {
        return true;
    }
    // Opcional: acessar pelo IP da máquina na rede (ex.: http://192.168.x.x/mypharm/)
    if (getenv('RD_DEBUG_ALLOW_LAN') === '1' && $remote !== '') {
        if (preg_match('/^(127\.0\.0\.1|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3})$/', $remote)) {
            return true;
        }
    }
    return false;
}

function gcNormalizeNome(string $v): string
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
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}

function gcResolveMetaPorNome(string $nome, array $metasByKey): float
{
    $key = gcNormalizeNome($nome);
    if ($key !== '' && isset($metasByKey[$key])) {
        return (float)$metasByKey[$key];
    }
    foreach ($metasByKey as $metaKey => $metaVal) {
        if ($metaKey === '') continue;
        if (str_contains($key, (string)$metaKey) || str_contains((string)$metaKey, $key)) {
            return (float)$metaVal;
        }
    }
    return 0.0;
}

function gcResolveComissaoPorNome(string $nome, array $comissaoByKey): float
{
    $key = gcNormalizeNome($nome);
    if ($key !== '' && array_key_exists($key, $comissaoByKey)) {
        return (float)$comissaoByKey[$key];
    }
    foreach ($comissaoByKey as $cKey => $cVal) {
        if ($cKey === '') continue;
        if (str_contains($key, (string)$cKey) || str_contains((string)$cKey, $key)) {
            return (float)$cVal;
        }
    }
    return 1.0;
}

function handleVendedorDashboardRd(PDO $pdo): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', '300');
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    $isAdmin = ($tipo === 'admin');
    $isVendedor = (strpos($setor, 'vendedor') !== false);
    if (!$isAdmin && !$isVendedor) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao setor vendedor ou administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode([
            'success' => false,
            'fonte'   => null,
            'error'   => 'RDSTATION_CRM_TOKEN não configurado no .env. Configure para usar dados externos do RD Station CRM.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $maxPagesVend = (int)(getenv('RD_VENDEDOR_MAX_PAGES') ?: 12);
    if ($maxPagesVend <= 0) {
        $maxPagesVend = 12;
    }
    $cacheKey = 'gc_vendedor_rd_v2_' . md5($dataDe . '|' . $dataAte . '|' . $maxPagesVend);
    $cacheFile = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $metricas = null;
    try {
        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            $cachedArr = is_string($cached) ? json_decode($cached, true) : null;
            if (is_array($cachedArr) && isset($cachedArr['por_vendedor'])) {
                $metricas = $cachedArr;
            }
        }
    } catch (Throwable $e) {
        // cache opcional
    }
    try {
        // Para a página do vendedor, limita páginas para reduzir 429/timeout.
        // O objetivo aqui é estabilidade do painel individual.
        $metricasFresh = rdFetchTodasMetricas($rdToken, $dataDe, $dataAte, $maxPagesVend, false);
        if (is_array($metricasFresh) && isset($metricasFresh['por_vendedor'])) {
            $metricas = $metricasFresh;
            @file_put_contents($cacheFile, json_encode($metricasFresh, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (!is_array($metricas)) {
            // fallback final: evita 500 no painel do vendedor quando o RD limita/oscila.
            $metricas = [
                'fonte' => 'rdstation_crm',
                'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
                'receita_total' => 0.0,
                'total_ganhos' => 0,
                'total_perdidos' => 0,
                'conversao_geral_pct' => null,
                'oportunidades_abertas' => 0,
                'funil_estagios' => [],
                'kanban_snapshot' => [
                    'total_negociacoes' => 0,
                    'ganhos' => 0,
                    'perdidos' => 0,
                    'abertos' => 0,
                    'valor_ganho' => 0.0,
                    'etapas' => [],
                ],
                'por_vendedor' => [],
                'motivos_perda_geral' => [],
                'origens_geral' => [],
                'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'warning' => (string)$e->getMessage(),
            ];
        }
        // fallback: usa cache stale se disponível; caso contrário usa payload vazio acima
    }

    $metasByKey = [];
    $comissaoByKey = [];
    $nomesByKey = [];
    $userComissaoPct = 1.0;
    try {
        $stMe = $pdo->prepare("
            SELECT COALESCE(comissao_percentual, 1) AS comissao_percentual
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ");
        $stMe->execute(['id' => (int)($_SESSION['user_id'] ?? 0)]);
        $rowMe = $stMe->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rowMe !== null && array_key_exists('comissao_percentual', $rowMe)) {
            $userComissaoPct = (float)$rowMe['comissao_percentual'];
        }
    } catch (Throwable $e) {
        // usa fallback 1%
    }
    try {
        $stVend = $pdo->query("
            SELECT
                TRIM(nome) AS nome,
                COALESCE(meta_mensal, 0) AS meta_mensal,
                COALESCE(comissao_percentual, 1) AS comissao_percentual
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
              AND COALESCE(ativo, 1) = 1
              AND TRIM(COALESCE(nome, '')) <> ''
        ");
        foreach ($stVend->fetchAll(PDO::FETCH_ASSOC) ?: [] as $v) {
            $nome = trim((string)($v['nome'] ?? ''));
            if ($nome === '') continue;
            $k = gcNormalizeNome($nome);
            $metasByKey[$k] = (float)($v['meta_mensal'] ?? 0);
            $comissaoByKey[$k] = (float)($v['comissao_percentual'] ?? 1);
            $nomesByKey[$k] = $nome;
        }
    } catch (Throwable $e) {
        // Metas locais são opcionais; mantém fallback.
    }

    $ranking = [];
    $seen = [];
    foreach (($metricas['por_vendedor'] ?? []) as $row) {
        $nome = trim((string)($row['vendedor'] ?? ''));
        if ($nome === '') continue;
        $key = gcNormalizeNome($nome);
        $seen[$key] = true;
        $meta = gcResolveMetaPorNome($nome, $metasByKey);
        if ($meta <= 0) $meta = 60000.0;
        $comissaoPct = gcResolveComissaoPorNome($nome, $comissaoByKey);
        $receita = round((float)($row['receita'] ?? 0), 2);
        $comissaoEstimada = round(($receita * $comissaoPct) / 100, 2);

        $motivos = [];
        foreach (($row['motivos_perda'] ?? []) as $m) {
            $motivos[] = [
                'nome' => (string)($m['motivo'] ?? ''),
                'quantidade' => (int)($m['quantidade'] ?? 0),
            ];
        }

        $origens = [];
        foreach (($row['origem_deals'] ?? []) as $o) {
            $origens[] = [
                'nome' => (string)($o['origem'] ?? ''),
                'receita' => round((float)($o['receita'] ?? 0), 2),
                'quantidade' => (int)($o['quantidade'] ?? 0),
            ];
        }

        $ranking[] = [
            'vendedor' => $nome,
            'receita' => $receita,
            'meta_mensal' => $meta,
            'comissao_percentual' => $comissaoPct,
            'comissao_estimada_valor' => $comissaoEstimada,
            'duracao_media_min' => $row['tempo_medio_fechamento_min'] ?? null,
            'tempo_medio_espera_min' => $row['tempo_medio_espera_min'] ?? null,
            'total_deals_ganhos' => (int)($row['total_ganhos'] ?? 0),
            'total_deals_perdidos' => (int)($row['total_perdidos'] ?? 0),
            'taxa_perda_pct' => (float)($row['taxa_perda_pct'] ?? 0),
            'top_motivos_perda' => array_slice($motivos, 0, 10),
            'origem_deals' => $origens,
            'meta_mensal_utilizada' => $meta,
            'percentual_meta' => $meta > 0 ? round(($receita / $meta) * 100, 2) : 0.0,
            'percentual_lider' => 0.0,
            'posicao' => 0,
        ];
    }

    foreach ($metasByKey as $k => $meta) {
        if (isset($seen[$k])) continue;
        $metaUtilizada = (float)$meta;
        if ($metaUtilizada <= 0) $metaUtilizada = 60000.0;
        $comissaoPct = array_key_exists($k, $comissaoByKey) ? (float)$comissaoByKey[$k] : 1.0;
        $ranking[] = [
            'vendedor' => (string)($nomesByKey[$k] ?? $k),
            'receita' => 0.0,
            'meta_mensal' => $metaUtilizada,
            'comissao_percentual' => $comissaoPct,
            'comissao_estimada_valor' => 0.0,
            'duracao_media_min' => null,
            'tempo_medio_espera_min' => null,
            'total_deals_ganhos' => 0,
            'total_deals_perdidos' => 0,
            'taxa_perda_pct' => 0.0,
            'top_motivos_perda' => [],
            'origem_deals' => [],
            'meta_mensal_utilizada' => $metaUtilizada,
            'percentual_meta' => 0.0,
            'percentual_lider' => 0.0,
            'posicao' => 0,
        ];
    }

    usort($ranking, static function (array $a, array $b): int {
        $ra = (float)($a['receita'] ?? 0);
        $rb = (float)($b['receita'] ?? 0);
        if (($rb <=> $ra) !== 0) return $rb <=> $ra;
        return strcmp((string)($a['vendedor'] ?? ''), (string)($b['vendedor'] ?? ''));
    });

    $maxReceita = 0.0;
    foreach ($ranking as $it) {
        $val = (float)($it['receita'] ?? 0);
        if ($val > $maxReceita) $maxReceita = $val;
    }

    foreach ($ranking as $i => &$it) {
        $it['posicao'] = $i + 1;
        $it['percentual_lider'] = $maxReceita > 0
            ? round((((float)$it['receita']) / $maxReceita) * 100, 2)
            : 0.0;
    }
    unset($it);

    $me = null;
    $meKey = gcNormalizeNome((string)($_SESSION['user_nome'] ?? ''));
    if ($meKey !== '') {
        foreach ($ranking as $it) {
            $rk = gcNormalizeNome((string)($it['vendedor'] ?? ''));
            if ($rk === $meKey || str_contains($rk, $meKey) || str_contains($meKey, $rk)) {
                $me = $it;
                break;
            }
        }
    }
    if (is_array($me)) {
        $me['comissao_percentual'] = $userComissaoPct;
        $me['comissao_estimada_valor'] = round((((float)($me['receita'] ?? 0)) * $userComissaoPct) / 100, 2);
    }

    echo json_encode([
        'success' => true,
        'fonte' => 'rdstation_crm',
        'periodo' => [
            'data_de' => $dataDe,
            'data_ate' => $dataAte,
        ],
        'ranking' => $ranking,
        'me' => $me,
        'funil_estagios' => $metricas['funil_estagios'] ?? [],
        'motivos_perda_geral' => $metricas['motivos_perda_geral'] ?? [],
        'origens_geral' => $metricas['origens_geral'] ?? [],
        'comissao_percentual_usuario' => $userComissaoPct,
        'rd_metricas' => $metricas,
        'updated_at' => (string)($metricas['updated_at'] ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
    ], JSON_UNESCAPED_UNICODE);
}

function handleGestaoRejeitadosRd(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    if ($tipo !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode([
            'success' => false,
            'fonte'   => null,
            'error'   => 'RDSTATION_CRM_TOKEN não configurado no .env.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $detalhe = rdFetchRejeitadosDetalhados($rdToken, $dataDe, $dataAte, 12);
    $registros = $detalhe['registros'] ?? [];
    if (!is_array($registros)) $registros = [];

    $porClienteMap = [];
    $porPrescMap = [];
    $motivosGeral = [];
    $totalValor = 0.0;
    $totalComContato = 0;

    foreach ($registros as $row) {
        if (!is_array($row)) continue;
        $cliente = trim((string)($row['cliente'] ?? '(Sem cliente)'));
        $prescritor = trim((string)($row['prescritor'] ?? 'Não informado'));
        $contato = trim((string)($row['contato'] ?? ''));
        $atendente = trim((string)($row['atendente'] ?? 'Não informado'));
        $motivo = trim((string)($row['motivo'] ?? 'Não informado'));
        $valor = (float)($row['valor_rejeitado'] ?? 0);
        $dataPerda = trim((string)($row['data_perda'] ?? ''));

        $totalValor += $valor;
        if ($contato !== '') $totalComContato++;
        if (!isset($motivosGeral[$motivo])) $motivosGeral[$motivo] = 0;
        $motivosGeral[$motivo]++;

        $kCli = gcNormalizeNome($cliente . '|' . $prescritor . '|' . $contato);
        if (!isset($porClienteMap[$kCli])) {
            $porClienteMap[$kCli] = [
                'cliente' => $cliente,
                'prescritor' => $prescritor,
                'contato' => $contato,
                'qtd_rejeicoes' => 0,
                'valor_rejeitado' => 0.0,
                'motivos' => [],
                'ultima_perda' => '',
                'atendentes' => [],
            ];
        }
        $porClienteMap[$kCli]['qtd_rejeicoes']++;
        $porClienteMap[$kCli]['valor_rejeitado'] += $valor;
        if (!isset($porClienteMap[$kCli]['motivos'][$motivo])) $porClienteMap[$kCli]['motivos'][$motivo] = 0;
        $porClienteMap[$kCli]['motivos'][$motivo]++;
        $porClienteMap[$kCli]['atendentes'][$atendente] = true;
        if ($dataPerda !== '' && ($porClienteMap[$kCli]['ultima_perda'] === '' || $dataPerda > $porClienteMap[$kCli]['ultima_perda'])) {
            $porClienteMap[$kCli]['ultima_perda'] = $dataPerda;
        }

        $kPre = gcNormalizeNome($prescritor . '|' . $contato);
        if (!isset($porPrescMap[$kPre])) {
            $porPrescMap[$kPre] = [
                'prescritor' => $prescritor,
                'contato' => $contato,
                'qtd_rejeicoes' => 0,
                'valor_rejeitado' => 0.0,
                'clientes' => [],
                'motivos' => [],
                'ultima_perda' => '',
                'atendentes' => [],
            ];
        }
        $porPrescMap[$kPre]['qtd_rejeicoes']++;
        $porPrescMap[$kPre]['valor_rejeitado'] += $valor;
        $porPrescMap[$kPre]['clientes'][gcNormalizeNome($cliente)] = $cliente;
        if (!isset($porPrescMap[$kPre]['motivos'][$motivo])) $porPrescMap[$kPre]['motivos'][$motivo] = 0;
        $porPrescMap[$kPre]['motivos'][$motivo]++;
        $porPrescMap[$kPre]['atendentes'][$atendente] = true;
        if ($dataPerda !== '' && ($porPrescMap[$kPre]['ultima_perda'] === '' || $dataPerda > $porPrescMap[$kPre]['ultima_perda'])) {
            $porPrescMap[$kPre]['ultima_perda'] = $dataPerda;
        }
    }

    $porCliente = [];
    foreach ($porClienteMap as $it) {
        $motivos = $it['motivos'];
        arsort($motivos, SORT_NUMERIC);
        $motivoPrincipal = '';
        foreach ($motivos as $m => $q) { $motivoPrincipal = (string)$m; break; }
        $atendentes = array_keys($it['atendentes']);
        sort($atendentes);
        $porCliente[] = [
            'cliente' => $it['cliente'],
            'prescritor' => $it['prescritor'],
            'contato' => $it['contato'],
            'qtd_rejeicoes' => (int)$it['qtd_rejeicoes'],
            'valor_rejeitado' => round((float)$it['valor_rejeitado'], 2),
            'motivo_principal' => $motivoPrincipal !== '' ? $motivoPrincipal : 'Não informado',
            'ultima_perda' => $it['ultima_perda'],
            'atendente' => implode(', ', $atendentes),
        ];
    }
    usort($porCliente, static function (array $a, array $b): int {
        if (($b['qtd_rejeicoes'] ?? 0) !== ($a['qtd_rejeicoes'] ?? 0)) return ($b['qtd_rejeicoes'] ?? 0) <=> ($a['qtd_rejeicoes'] ?? 0);
        return ($b['valor_rejeitado'] ?? 0) <=> ($a['valor_rejeitado'] ?? 0);
    });

    $porPrescritor = [];
    foreach ($porPrescMap as $it) {
        $motivos = $it['motivos'];
        arsort($motivos, SORT_NUMERIC);
        $motivoPrincipal = '';
        foreach ($motivos as $m => $q) { $motivoPrincipal = (string)$m; break; }
        $atendentes = array_keys($it['atendentes']);
        sort($atendentes);
        $porPrescritor[] = [
            'prescritor' => $it['prescritor'],
            'contato' => $it['contato'],
            'qtd_rejeicoes' => (int)$it['qtd_rejeicoes'],
            'valor_rejeitado' => round((float)$it['valor_rejeitado'], 2),
            'clientes_unicos' => count($it['clientes']),
            'motivo_principal' => $motivoPrincipal !== '' ? $motivoPrincipal : 'Não informado',
            'ultima_perda' => $it['ultima_perda'],
            'atendentes' => implode(', ', $atendentes),
        ];
    }
    usort($porPrescritor, static function (array $a, array $b): int {
        if (($b['qtd_rejeicoes'] ?? 0) !== ($a['qtd_rejeicoes'] ?? 0)) return ($b['qtd_rejeicoes'] ?? 0) <=> ($a['qtd_rejeicoes'] ?? 0);
        return ($b['valor_rejeitado'] ?? 0) <=> ($a['valor_rejeitado'] ?? 0);
    });

    arsort($motivosGeral, SORT_NUMERIC);
    $topMotivos = [];
    foreach ($motivosGeral as $mot => $qty) {
        $topMotivos[] = ['motivo' => (string)$mot, 'quantidade' => (int)$qty];
        if (count($topMotivos) >= 8) break;
    }

    echo json_encode([
        'success' => true,
        'fonte' => 'rdstation_crm',
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'resumo' => [
            'total_rejeicoes' => count($registros),
            'total_valor_rejeitado' => round($totalValor, 2),
            'total_com_contato' => $totalComContato,
            'top_motivos' => $topMotivos,
        ],
        'por_cliente' => $porCliente,
        'por_prescritor' => $porPrescritor,
        'updated_at' => (string)($detalhe['updated_at'] ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
    ], JSON_UNESCAPED_UNICODE);
}

function handleDebugRdMetricasPublic(PDO $pdo): void
{
    if (!gcIsLocalDebugRequest()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Endpoint temporário disponível apenas em localhost.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode(['success' => false, 'fonte' => null, 'error' => 'RDSTATION_CRM_TOKEN não configurado no .env.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    [$dataDe, $dataAte] = gcDateRangeFromQuery();

    $usuarios = [];
    try {
        $st = $pdo->query("
            SELECT id, TRIM(nome) AS nome, TRIM(COALESCE(setor, '')) AS setor, COALESCE(meta_mensal, 0) AS meta_mensal, COALESCE(comissao_percentual, 1) AS comissao_percentual
            FROM usuarios
            WHERE TRIM(COALESCE(nome, '')) <> ''
            ORDER BY TRIM(nome) ASC
        ");
        $usuarios = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $usuarios = [];
    }

    $rdFull = rdFetchTodasMetricas($rdToken, $dataDe, $dataAte, null, true);
    $rdSemFunil = [
        'receita_total' => $rdFull['receita_total'] ?? 0,
        'total_ganhos' => $rdFull['total_ganhos'] ?? 0,
        'total_perdidos' => $rdFull['total_perdidos'] ?? 0,
        'conversao_geral_pct' => $rdFull['conversao_geral_pct'] ?? null,
        'kanban_snapshot' => $rdFull['kanban_snapshot'] ?? [],
        'por_vendedor' => $rdFull['por_vendedor'] ?? [],
        'motivos_perda_geral' => $rdFull['motivos_perda_geral'] ?? [],
        'origens_geral' => $rdFull['origens_geral'] ?? [],
        'updated_at' => $rdFull['updated_at'] ?? null,
    ];
    echo json_encode([
        'success' => true,
        'fonte' => 'rdstation_crm',
        'temporary_debug' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'summary' => [
            'rd_receita_total' => $rdFull['receita_total'] ?? 0,
            'rd_total_ganhos' => $rdFull['total_ganhos'] ?? 0,
            'rd_total_perdidos' => $rdFull['total_perdidos'] ?? 0,
            'kanban_total_negociacoes' => $rdFull['kanban_snapshot']['total_negociacoes'] ?? 0,
            'kanban_ganhos' => $rdFull['kanban_snapshot']['ganhos'] ?? 0,
            'kanban_perdidos' => $rdFull['kanban_snapshot']['perdidos'] ?? 0,
            'kanban_abertos' => $rdFull['kanban_snapshot']['abertos'] ?? 0,
        ],
        'usuarios_vendedores' => array_values(array_filter($usuarios, static function (array $u): bool {
            return strpos(strtolower(trim((string)($u['setor'] ?? ''))), 'vendedor') !== false;
        })),
        'rd_metricas_full' => $rdFull,
        'rd_metricas_sem_funil' => $rdSemFunil,
        'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

function handleDebugRdTvPublic(PDO $pdo): void
{
    if (!gcIsLocalDebugRequest()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Endpoint temporário disponível apenas em localhost.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode(['success' => false, 'fonte' => null, 'error' => 'RDSTATION_CRM_TOKEN não configurado no .env.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $metas = [];
    try {
        $st = $pdo->query("
            SELECT TRIM(nome) AS nome, COALESCE(meta_mensal, 0) AS meta_mensal
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
              AND COALESCE(ativo, 1) = 1
              AND TRIM(COALESCE(nome, '')) <> ''
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $v) {
            $nome = trim((string)($v['nome'] ?? ''));
            if ($nome === '') continue;
            $metas[gcNormalizeNome($nome)] = (float)($v['meta_mensal'] ?? 0);
        }
    } catch (Throwable $e) {
        $metas = [];
    }
    $tvRanking = rdtvBuildRanking($rdToken, $dataDe, $dataAte, $metas);
    echo json_encode([
        'success' => true,
        'fonte' => 'rdstation_crm',
        'temporary_debug' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'tv_ranking' => $tvRanking,
        'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

function handleDebugRdRejeitadosPublic(PDO $pdo): void
{
    if (!gcIsLocalDebugRequest()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Endpoint temporário disponível apenas em localhost.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode(['success' => false, 'fonte' => null, 'error' => 'RDSTATION_CRM_TOKEN não configurado no .env.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $rejeitados = rdFetchRejeitadosDetalhados($rdToken, $dataDe, $dataAte, 8);
    echo json_encode([
        'success' => true,
        'fonte' => 'rdstation_crm',
        'temporary_debug' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'rejeitados_detalhados' => $rejeitados,
        'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

function handleTvCorridaVendedores(PDO $pdo): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }
    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', '180');
    }

    $today = new DateTimeImmutable('today');
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : '';
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : '';
    $isDate = static function ($v) {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v);
    };
    if (!$isDate($dataDe) || !$isDate($dataAte)) {
        $dataDe = $today->modify('first day of this month')->format('Y-m-d');
        $dataAte = $today->format('Y-m-d');
    } elseif ($dataDe > $dataAte) {
        $dataAte = $dataDe;
    }

    $refreshRd = isset($_GET['refresh_rd']) ? strtolower(trim((string)$_GET['refresh_rd'])) : '';
    $forceRefresh = in_array($refreshRd, ['1', 'true', 'yes', 'on'], true);

    $cacheTtlSec = (int)(getenv('RD_TV_CACHE_TTL') ?: 90);
    if ($cacheTtlSec < 30) {
        $cacheTtlSec = 30;
    }
    $cacheKey = 'gc_tv_rd_v2_' . md5($dataDe . '|' . $dataAte);
    $cacheFile = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $cachedTv = null;
    try {
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $arr = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($arr) && isset($arr['ranking']) && is_array($arr['ranking'])) {
                $cachedTv = $arr;
            }
        }
    } catch (Throwable $e) {
        $cachedTv = null;
    }

    // Se houver cache recente e não for refresh forçado, responde rápido para a TV.
    try {
        if (!$forceRefresh && is_file($cacheFile) && (time() - @filemtime($cacheFile) <= $cacheTtlSec) && is_array($cachedTv)) {
            $cachedTv['cache'] = ['hit' => true, 'stale' => false];
            echo json_encode($cachedTv, JSON_UNESCAPED_UNICODE);
            return;
        }
    } catch (Throwable $e) {
        // segue fluxo normal
    }

    // ===== Tenta usar RD Station CRM =====
    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken !== '') {
        try {
            // Busca metas do banco para cruzar com os dados do CRM
            $metas = [];
            try {
                $stVend = $pdo->query("
                    SELECT TRIM(nome) AS nome, COALESCE(meta_mensal, 0) AS meta_mensal
                    FROM usuarios
                    WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
                      AND COALESCE(ativo, 1) = 1
                      AND TRIM(COALESCE(nome, '')) <> ''
                ");
                foreach ($stVend->fetchAll(PDO::FETCH_ASSOC) as $v) {
                    $k = function_exists('mb_strtolower')
                        ? mb_strtolower(trim((string)$v['nome']), 'UTF-8')
                        : strtolower(trim((string)$v['nome']));
                    $metas[$k] = (float)$v['meta_mensal'];
                }
            } catch (Throwable $e) { /* sem metas: usa default */ }

            $result = rdtvBuildRanking($rdToken, $dataDe, $dataAte, $metas);
            try {
                @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
            } catch (Throwable $e) { /* cache opcional */ }
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            return;
        } catch (Throwable $e) {
            // Log do erro e fallback para o banco
            if (function_exists('error_log')) {
                error_log('RD Station TV proxy error: ' . $e->getMessage());
            }
            // Prioriza cache stale real do RD antes de cair no legado
            if (is_array($cachedTv)) {
                $cachedTv['cache'] = ['hit' => true, 'stale' => true];
                echo json_encode($cachedTv, JSON_UNESCAPED_UNICODE);
                return;
            }
            // Continua para o fallback abaixo
        }
    }

    // ===== Fallback: banco local (legado — CSV importado) =====
    $approvedCase = "(
        gp.status_financeiro IS NULL OR
        (
            gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
            AND gp.status_financeiro NOT LIKE '%carrinho%'
        )
    )";

    $ranking = [];
    try {
        $st = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS vendedor,
                COALESCE(SUM(CASE WHEN {$approvedCase} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
            GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
            ORDER BY COALESCE(SUM(CASE WHEN {$approvedCase} THEN gp.preco_liquido ELSE 0 END), 0) DESC
        ");
        $st->execute(['de' => $dataDe, 'ate' => $dataAte]);
        $ranking = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $ranking = [];
    }

    $duracaoPorVendedor = [];
    try {
        $stDur = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS vendedor,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, gp.data_orcamento, gp.data_aprovacao)), 0) AS duracao_media_min
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
              AND gp.data_orcamento IS NOT NULL AND gp.data_aprovacao IS NOT NULL
              AND {$approvedCase}
            GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
        ");
        $stDur->execute(['de' => $dataDe, 'ate' => $dataAte]);
        foreach ($stDur->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $duracaoPorVendedor[trim((string)($row['vendedor'] ?? ''))] = (int)$row['duracao_media_min'];
        }
    } catch (Throwable $e) {
        $duracaoPorVendedor = [];
    }

    $vendedoresCad = [];
    try {
        $stVend = $pdo->query("
            SELECT
                TRIM(nome) AS nome,
                COALESCE(meta_mensal, 0) AS meta_mensal
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
              AND COALESCE(ativo, 1) = 1
              AND TRIM(COALESCE(nome, '')) <> ''
            ORDER BY TRIM(nome) ASC
        ");
        $vendedoresCad = $stVend->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $vendedoresCad = [];
    }

    $map = [];
    foreach ($ranking as $r) {
        $nome = trim((string)($r['vendedor'] ?? ''));
        if ($nome === '') continue;
        if (function_exists('gcIsAllowedVendedora') && !gcIsAllowedVendedora($nome)) continue;
        $k = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
        $map[$k] = [
            'vendedor'    => $nome,
            'receita'     => (float)($r['receita'] ?? 0),
            'meta_mensal' => 0.0,
            'duracao_media_min' => $duracaoPorVendedor[$nome] ?? null,
            'tempo_medio_espera_min' => null,
        ];
    }
    foreach ($vendedoresCad as $v) {
        $nome = trim((string)($v['nome'] ?? ''));
        if ($nome === '') continue;
        if (function_exists('gcIsAllowedVendedora') && !gcIsAllowedVendedora($nome)) continue;
        $k = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
        if (!isset($map[$k])) {
            $map[$k] = ['vendedor' => $nome, 'receita' => 0.0, 'meta_mensal' => 0.0, 'duracao_media_min' => null, 'tempo_medio_espera_min' => null];
        }
        $map[$k]['meta_mensal'] = (float)($v['meta_mensal'] ?? 0);
        if (isset($duracaoPorVendedor[$nome])) {
            $map[$k]['duracao_media_min'] = $duracaoPorVendedor[$nome];
        }
    }

    $lista = array_values($map);
    usort($lista, static function (array $a, array $b): int {
        $ra = (float)($a['receita'] ?? 0);
        $rb = (float)($b['receita'] ?? 0);
        if ($rb <=> $ra) return $rb <=> $ra;
        $pa = ((float)($a['meta_mensal'] ?? 0) > 0)
            ? (((float)($a['receita'] ?? 0) / (float)$a['meta_mensal']) * 100)
            : 0.0;
        $pb = ((float)($b['meta_mensal'] ?? 0) > 0)
            ? (((float)($b['receita'] ?? 0) / (float)$b['meta_mensal']) * 100)
            : 0.0;
        if ($pb <=> $pa) return $pb <=> $pa;
        return strcmp((string)($a['vendedor'] ?? ''), (string)($b['vendedor'] ?? ''));
    });

    $maxReceita = 0.0;
    foreach ($lista as $it) {
        $val = (float)($it['receita'] ?? 0);
        if ($val > $maxReceita) $maxReceita = $val;
    }
    foreach ($lista as $i => &$it) {
        $it['posicao'] = $i + 1;
        $it['percentual_lider'] = $maxReceita > 0 ? round(((float)$it['receita'] / $maxReceita) * 100, 2) : 0.0;
        $metaMensal = (float)($it['meta_mensal'] ?? 0);
        if ($metaMensal <= 0) {
            $metaMensal = 60000.0;
        }
        $it['meta_mensal_utilizada'] = $metaMensal;
        $it['percentual_meta'] = $metaMensal > 0
            ? round((((float)$it['receita']) / $metaMensal) * 100, 2)
            : 0.0;
        if (!array_key_exists('duracao_media_min', $it)) $it['duracao_media_min'] = null;
        if (!array_key_exists('tempo_medio_espera_min', $it)) $it['tempo_medio_espera_min'] = null;
        if (!array_key_exists('total_deals_ganhos', $it)) $it['total_deals_ganhos'] = null;
        if (!array_key_exists('total_deals_perdidos', $it)) $it['total_deals_perdidos'] = null;
        if (!array_key_exists('taxa_perda_pct', $it)) $it['taxa_perda_pct'] = null;
        if (!array_key_exists('top_motivos_perda', $it)) $it['top_motivos_perda'] = [];
        if (!array_key_exists('origem_deals', $it)) $it['origem_deals'] = [];
    }
    unset($it);

    echo json_encode([
        'success'         => true,
        'fonte'           => 'banco_local',
        'periodo'         => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'max_receita'     => round($maxReceita, 2),
        'ranking'         => $lista,
        'funil_estagios'  => [],
        'updated_at'      => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

// check_session: não exige login, só retorna estado da sessão
if ($action === 'check_session') {
    try {
        $pdo = getConnection();
        $fotoPerfil = null;
        if (isset($_SESSION['user_id'])) {
            try {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL DEFAULT NULL");
            } catch (Throwable $e) {}
            $stmt = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['foto_perfil'])) {
                $fotoPerfil = 'uploads/avatars/' . basename($row['foto_perfil']);
            }
        }
        echo json_encode([
            'logged_in' => isset($_SESSION['user_id']),
            'nome' => $_SESSION['user_nome'] ?? null,
            'tipo' => $_SESSION['user_tipo'] ?? null,
            'setor' => $_SESSION['user_setor'] ?? null,
            'foto_perfil' => $fotoPerfil,
            'csrf_token' => getCsrfToken()
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao verificar sessão.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'debug_rd_metricas_public') {
    try {
        $pdo = getConnection();
        handleDebugRdMetricasPublic($pdo);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'fonte' => null, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'debug_rd_tv_public') {
    try {
        $pdo = getConnection();
        handleDebugRdTvPublic($pdo);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'fonte' => null, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'debug_rd_rejeitados_public') {
    try {
        $pdo = getConnection();
        handleDebugRdRejeitadosPublic($pdo);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'fonte' => null, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// logout: exige sessão, encerra e retorna
if ($action === 'logout') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdo = getConnection();
        $logoutUserId = (int)$_SESSION['user_id'];
        $logoutNome = $_SESSION['user_nome'] ?? null;
        $logoutUsuario = null;
        try {
            $stUser = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = :id LIMIT 1");
            $stUser->execute(['id' => $logoutUserId]);
            $logoutUsuario = $stUser->fetchColumn() ?: null;
        } catch (Throwable $e) {}
        removeUserSession($pdo, $logoutUserId);
        logAuthEvent($pdo, 'logout', true, [
            'user_id' => $logoutUserId,
            'usuario' => $logoutUsuario,
            'nome_usuario' => $logoutNome
        ]);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $_SESSION = [];
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Métricas completas do RD Station CRM (exige admin)
if ($action === 'gestao_rd_metricas') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdo = getConnection();
        $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
        if (!($sessionCheck['valid'] ?? true)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Sessão encerrada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
        if ($tipo !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
        if ($rdToken === '') {
            echo json_encode([
                'success' => false,
                'fonte'   => null,
                'error'   => 'RDSTATION_CRM_TOKEN não configurado no .env. Configure para usar métricas do RD Station CRM.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $today = new DateTimeImmutable('today');
        $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : '';
        $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : '';
        $isDate = static function ($v) {
            return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v);
        };
        if (!$isDate($dataDe) || !$isDate($dataAte)) {
            $dataDe = $today->modify('first day of this month')->format('Y-m-d');
            $dataAte = $today->format('Y-m-d');
        } elseif ($dataDe > $dataAte) {
            $dataAte = $dataDe;
        }
        $metricas = rdFetchTodasMetricas($rdToken, $dataDe, $dataAte);
        echo json_encode(array_merge(['success' => true], $metricas), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('gestao_rd_metricas: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'fonte'   => null,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao buscar métricas do RD Station.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'gestao_rejeitados_rd') {
    try {
        $pdo = getConnection();
        handleGestaoRejeitadosRd($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('gestao_rejeitados_rd: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'fonte'   => null,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao buscar rejeitados no RD Station.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_dashboard_rd') {
    try {
        $pdo = getConnection();
        handleVendedorDashboardRd($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_dashboard_rd: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'fonte'   => null,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao buscar dados do vendedor no RD Station.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// TV corrida: leitura pública para monitor/tela (não expira sessão/senha)
if ($action === 'tv_corrida_vendedores') {
    try {
        $pdo = getConnection();
        handleTvCorridaVendedores($pdo);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao carregar corrida da TV.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Ações de gestão: exigem sessão e admin (validado dentro do módulo)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getConnection();
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['error' => 'Sessão encerrada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    handleGestaoComercialModuleAction($action, $pdo);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('api_gestao.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $msg = (defined('IS_PRODUCTION') && IS_PRODUCTION)
        ? 'Erro interno na gestão comercial.'
        : $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}
