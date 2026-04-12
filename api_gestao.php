<?php
/**
 * API exclusiva da GestÃ£o Comercial.
 * Tudo que corresponda Ã  gestÃ£o comercial usa esta API (sessÃ£o, logout, dashboard, lista vendedores).
 *
 * TV Corrida de Vendas:
 *   Se RDSTATION_CRM_TOKEN estiver configurado no .env, os dados vÃªm diretamente
 *   da API do RD Station CRM (deals won). Caso contrÃ¡rio, usa o banco local (legado).
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
    'gestao_comercial_erros_lista',
    'gestao_comercial_erros_salvar',
    'gestao_comercial_erros_excluir',
    'gestao_comercial_erros_tipos_distintos',
    'gestao_comercial_comissao_regras_get',
    'gestao_comercial_comissao_regras_salvar',
    'gestao_comercial_comissao_transfer_lista',
    'gestao_comercial_comissao_transfer_decidir',
    'gestao_comercial_comissao_transfer_excluir',
    'gestao_comercial_revenda_lista',
    'gestao_comercial_revenda_salvar',
    'gestao_comercial_revenda_cancelar',
    'gestao_comercial_revenda_decidir',
    'gestao_comercial_vendas_relatorio',
    'gestao_comercial_pedidos_visitador_style',
    'gestao_rd_metricas',
    'gestao_rejeitados_rd',
    'vendedor_dashboard_rd',
    'vendedor_dashboard_gestao',
    'vendedor_pedidos_lista',
    'vendedor_perdas_lista',
    'vendedor_perdas_salvar_acao',
    'vendedor_perdas_interacoes_lista',
    'vendedor_perdas_interacoes_salvar',
    'vendedor_comissao_transfer_lista',
    'vendedor_comissao_transfer_opcoes',
    'vendedor_comissao_transfer_solicitar',
    'vendedor_comissao_transfer_cancelar',
    'vendedor_comissao_transfer_editar',
    'vendedor_comissao_transfer_buscar_pedido',
    'vendedor_revenda_lista',
    'vendedor_revenda_lancar',
    'vendedor_revenda_cancelar',
    'tv_corrida_vendedores',
];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Ação não reconhecida."], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Compatibilidade: se a sessÃ£o PHP existe mas a tabela user_sessions perdeu o registro
 * (limpeza/manual/restore), recria o vÃ­nculo e evita 401 falso no painel.
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
    // Opcional: acessar pelo IP da mÃ¡quina na rede (ex.: http://192.168.x.x/mypharm/)
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
        'Ã'=>'A','Ã€'=>'A','Ã‚'=>'A','Ãƒ'=>'A','Ã„'=>'A',
        'Ã¡'=>'a','Ã '=>'a','Ã¢'=>'a','Ã£'=>'a','Ã¤'=>'a',
        'Ã‰'=>'E','Ãˆ'=>'E','ÃŠ'=>'E','Ã‹'=>'E',
        'Ã©'=>'e','Ã¨'=>'e','Ãª'=>'e','Ã«'=>'e',
        'Ã'=>'I','ÃŒ'=>'I','ÃŽ'=>'I','Ã'=>'I',
        'Ã­'=>'i','Ã¬'=>'i','Ã®'=>'i','Ã¯'=>'i',
        'Ã“'=>'O','Ã’'=>'O','Ã”'=>'O','Ã•'=>'O','Ã–'=>'O',
        'Ã³'=>'o','Ã²'=>'o','Ã´'=>'o','Ãµ'=>'o','Ã¶'=>'o',
        'Ãš'=>'U','Ã™'=>'U','Ã›'=>'U','Ãœ'=>'U',
        'Ãº'=>'u','Ã¹'=>'u','Ã»'=>'u','Ã¼'=>'u',
        'Ã‡'=>'C','Ã§'=>'c',
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
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
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
            'error'   => 'RDSTATION_CRM_TOKEN nÃ£o configurado no .env. Configure para usar dados externos do RD Station CRM.',
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
        // Para a pÃ¡gina do vendedor, limita pÃ¡ginas para reduzir 429/timeout.
        // O objetivo aqui Ã© estabilidade do painel individual.
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
        // fallback: usa cache stale se disponÃ­vel; caso contrÃ¡rio usa payload vazio acima
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
        // Metas locais sÃ£o opcionais; mantÃ©m fallback.
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

/**
 * Painel do vendedor (vendedor.html) com mÃ©tricas da tabela gestao_pedidos (importaÃ§Ã£o),
 * alinhado ao critÃ©rio de aprovaÃ§Ã£o da TV / gestÃ£o comercial.
 */
function handleVendedorDashboardGestao(PDO $pdo): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', '120');
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
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

    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $approvedCase = function_exists('gcApprovedCase') ? gcApprovedCase('gp') : "(
        gp.status_financeiro IS NULL OR
        (
            gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'OrÃ§amento')
            AND gp.status_financeiro NOT LIKE '%carrinho%'
        )
    )";
    $calcComissaoIndividualPct = static function (float $percentualMeta): float {
        if ($percentualMeta >= 110.0) return 2.0;
        if ($percentualMeta >= 100.0) return 1.8;
        if ($percentualMeta >= 90.0) return 1.3;
        if ($percentualMeta >= 70.0) return 1.0;
        return 0.5;
    };
    $calcComissaoGrupoPct = static function (float $receitaGrupo): float {
        if ($receitaGrupo >= 370000.0) return 2.5;
        if ($receitaGrupo >= 350000.0) return 2.0;
        if ($receitaGrupo >= 320000.0) return 1.5;
        return 0.0;
    };
    $calcScore = static function (array $item, float $penalidadeErros = 0.0): array {
        $pctMeta = (float)($item['percentual_meta'] ?? 0);
        $conv = (float)($item['taxa_conversao_linhas_pct'] ?? 0);
        $perda = (float)($item['taxa_perda_pct'] ?? 0);
        $atividade = ((int)($item['linhas_aprovadas'] ?? 0) + (int)($item['linhas_orcamento'] ?? 0) + (int)($item['linhas_perdidas'] ?? 0)) > 0;

        $pFaturamento = max(0.0, min(50.0, round($pctMeta * 0.5, 2)));   // 100% meta = 50 pts
        $pConversao = max(0.0, min(20.0, round($conv * 0.2, 2)));         // 100% conv = 20 pts
        $pErrosBase = max(0.0, min(20.0, round(20.0 - ($perda * 0.2), 2))); // 0% perda = 20 pts
        $penalidade = max(0.0, round($penalidadeErros, 2));
        $pErros = max(0.0, round($pErrosBase - $penalidade, 2));
        $pOrgCrm = $atividade ? 10.0 : 0.0;                                // sem mÃ©trica dedicada no BD

        $total = round($pFaturamento + $pConversao + $pErros + $pOrgCrm, 2);
        return [
            'faturamento' => $pFaturamento,
            'conversao' => $pConversao,
            'erros' => $pErros,
            'erros_base' => $pErrosBase,
            'penalidade_manual_erros' => $penalidade,
            'organizacao_crm' => $pOrgCrm,
            'total' => $total,
        ];
    };
    $metaSistemaVendedor = (float)(getenv('VENDEDOR_META_SISTEMA') ?: 60000);
    if ($metaSistemaVendedor <= 0) {
        $metaSistemaVendedor = 60000.0;
    }

    $rowsAgg = [];
    try {
        $sqlAgg = "
            SELECT
                COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS vendedor,
                COALESCE(SUM(CASE WHEN {$approvedCase} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COUNT(CASE WHEN {$approvedCase} THEN 1 END) AS linhas_aprovadas,
                COUNT(CASE WHEN gp.status_financeiro = 'OrÃ§amento' THEN 1 END) AS linhas_orcamento,
                COUNT(DISTINCT CASE WHEN {$approvedCase} THEN CONCAT(gp.ano_referencia, '-', gp.numero_pedido) END) AS pedidos_aprovados_distintos,
                COUNT(DISTINCT CASE WHEN gp.status_financeiro = 'OrÃ§amento' THEN CONCAT(gp.ano_referencia, '-', gp.numero_pedido) END) AS pedidos_orcamento_distintos,
                COUNT(DISTINCT gp.cliente) AS clientes_distintos
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
            GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
        ";
        $st = $pdo->prepare($sqlAgg);
        $st->execute(['de' => $dataDe, 'ate' => $dataAte]);
        $rowsAgg = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rowsAgg = [];
    }

    $revByKey = [];
    if (function_exists('gcRevendaAggRows')) {
        foreach (gcRevendaAggRows($pdo, $dataDe, $dataAte) as $rr) {
            $nm = trim((string)($rr['nome'] ?? ''));
            if ($nm === '') {
                continue;
            }
            $kRev = gcNormalizeNome($nm);
            if ($kRev === '') {
                continue;
            }
            $revByKey[$kRev] = round(($revByKey[$kRev] ?? 0) + (float)($rr['total'] ?? 0), 2);
        }
    }

    // Rejeitados vÃªm de itens_orcamentos_pedidos; vÃ­nculo por numero/serie/ano para recuperar atendente.
    $rejeitadosByVendedor = [];
    try {
        $sqlRej = "
            SELECT
                COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS vendedor,
                COUNT(*) AS linhas_rejeitadas,
                COUNT(DISTINCT CONCAT(i.ano_referencia, '-', i.numero)) AS pedidos_rejeitados,
                COALESCE(SUM(i.valor_liquido), 0) AS valor_rejeitado
            FROM itens_orcamentos_pedidos i
            LEFT JOIN gestao_pedidos gpLink
              ON gpLink.numero_pedido = i.numero
             AND gpLink.serie_pedido = i.serie
             AND gpLink.ano_referencia = i.ano_referencia
            WHERE DATE(i.data) BETWEEN :de AND :ate
              AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
            GROUP BY COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
        ";
        $stRej = $pdo->prepare($sqlRej);
        $stRej->execute(['de' => $dataDe, 'ate' => $dataAte]);
        foreach ($stRej->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) {
            $nomeVend = trim((string)($rr['vendedor'] ?? ''));
            if ($nomeVend === '') continue;
            $kRej = gcNormalizeNome($nomeVend);
            if ($kRej === '') continue;
            $rejeitadosByVendedor[$kRej] = [
                'vendedor' => $nomeVend,
                'linhas_rejeitadas' => (int)($rr['linhas_rejeitadas'] ?? 0),
                'pedidos_rejeitados' => (int)($rr['pedidos_rejeitados'] ?? 0),
                'valor_rejeitado' => round((float)($rr['valor_rejeitado'] ?? 0), 2),
            ];
        }
    } catch (Throwable $e) {
        $rejeitadosByVendedor = [];
    }

    $duracaoPorVendedor = [];
    try {
        $stDur = $pdo->prepare("
            SELECT
                base.vendedor,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, base.data_orcamento_ref, base.data_aprovacao_ref)), 0) AS duracao_media_min
            FROM (
                SELECT
                    COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS vendedor,
                    gp.ano_referencia,
                    gp.numero_pedido,
                    MIN(gp.data_orcamento) AS data_orcamento_ref,
                    MIN(gp.data_aprovacao) AS data_aprovacao_ref
                FROM gestao_pedidos gp
                WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
                  AND gp.data_orcamento IS NOT NULL
                  AND gp.data_aprovacao IS NOT NULL
                  AND gp.data_aprovacao >= gp.data_orcamento
                  AND {$approvedCase}
                GROUP BY
                    COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)'),
                    gp.ano_referencia,
                    gp.numero_pedido
            ) base
            GROUP BY base.vendedor
        ");
        $stDur->execute(['de' => $dataDe, 'ate' => $dataAte]);
        foreach ($stDur->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $duracaoPorVendedor[trim((string)($row['vendedor'] ?? ''))] = (int)$row['duracao_media_min'];
        }
    } catch (Throwable $e) {
        $duracaoPorVendedor = [];
    }

    $metasByKey = [];
    $nomesByKey = [];
    try {
        $stVend = $pdo->query("
            SELECT
                TRIM(nome) AS nome
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
              AND COALESCE(ativo, 1) = 1
              AND TRIM(COALESCE(nome, '')) <> ''
        ");
        foreach ($stVend->fetchAll(PDO::FETCH_ASSOC) ?: [] as $v) {
            $nome = trim((string)($v['nome'] ?? ''));
            if ($nome === '') continue;
            $k = gcNormalizeNome($nome);
            $metasByKey[$k] = $metaSistemaVendedor;
            $nomesByKey[$k] = $nome;
        }
    } catch (Throwable $e) {
        // opcional
    }

    $ranking = [];
    $seen = [];
    foreach ($rowsAgg as $row) {
        $nome = trim((string)($row['vendedor'] ?? ''));
        if ($nome === '') continue;
        $key = gcNormalizeNome($nome);
        $seen[$key] = true;
        $meta = $metaSistemaVendedor;
        $receitaPed = round((float)($row['receita'] ?? 0), 2);
        $extraRev = round((float)($revByKey[$key] ?? 0), 2);
        unset($revByKey[$key]);
        $receita = round($receitaPed + $extraRev, 2);
        $la = (int)($row['linhas_aprovadas'] ?? 0);
        $lo = (int)($row['linhas_orcamento'] ?? 0);
        $pedidoOrcDist = (int)($row['pedidos_orcamento_distintos'] ?? 0);
        $rej = $rejeitadosByVendedor[$key] ?? null;
        $lp = (int)($rej['linhas_rejeitadas'] ?? 0);
        $denConv = $la + $lo + $lp;
        $taxaConvLinhas = $denConv > 0 ? round(($la / $denConv) * 100, 2) : 0.0;
        $totalG = (int)($row['pedidos_aprovados_distintos'] ?? 0);
        $totalP = (int)($rej['pedidos_rejeitados'] ?? 0);
        $taxaPerda = ($totalG + $totalP) > 0 ? round(($totalP / ($totalG + $totalP)) * 100, 2) : 0.0;
        $percentualMeta = $meta > 0 ? round(($receita / $meta) * 100, 2) : 0.0;
        $comissaoIndividualPct = $calcComissaoIndividualPct($percentualMeta);

        $ranking[] = [
            'vendedor' => $nome,
            'receita' => $receita,
            'receita_pedidos' => $receitaPed,
            'receita_revenda' => $extraRev,
            'meta_mensal' => $meta,
            'comissao_percentual' => $comissaoIndividualPct,
            'comissao_percentual_individual' => $comissaoIndividualPct,
            'comissao_percentual_grupo' => 0.0,
            'comissao_estimada_valor' => round(($receita * $comissaoIndividualPct) / 100, 2),
            'duracao_media_min' => $duracaoPorVendedor[$nome] ?? null,
            'tempo_medio_espera_min' => null,
            'total_deals_ganhos' => $totalG,
            'total_deals_perdidos' => $totalP,
            'valor_rejeitado' => round((float)($rej['valor_rejeitado'] ?? 0), 2),
            'taxa_perda_pct' => $taxaPerda,
            'top_motivos_perda' => [],
            'origem_deals' => [],
            'meta_mensal_utilizada' => $meta,
            'percentual_meta' => $percentualMeta,
            'percentual_lider' => 0.0,
            'posicao' => 0,
            'volume_clientes' => (int)($row['clientes_distintos'] ?? 0),
            'linhas_aprovadas' => $la,
            'linhas_orcamento' => $lo,
            'linhas_perdidas' => $lp,
            'pedidos_orcamento_distintos' => $pedidoOrcDist,
            'taxa_conversao_linhas_pct' => $taxaConvLinhas,
        ];
    }

    foreach ($metasByKey as $k => $meta) {
        if (isset($seen[$k])) continue;
        $nome = (string)($nomesByKey[$k] ?? $k);
        $metaUtilizada = (float)$meta;
        if ($metaUtilizada <= 0) $metaUtilizada = $metaSistemaVendedor;
        $rej = $rejeitadosByVendedor[$k] ?? null;
        $lp = (int)($rej['linhas_rejeitadas'] ?? 0);
        $totalP = (int)($rej['pedidos_rejeitados'] ?? 0);
        $extraRev = round((float)($revByKey[$k] ?? 0), 2);
        unset($revByKey[$k]);
        $pctMetaNz = $metaUtilizada > 0 ? round(($extraRev / $metaUtilizada) * 100, 2) : 0.0;
        $comissaoIndividualPct = $calcComissaoIndividualPct($pctMetaNz);
        $ranking[] = [
            'vendedor' => $nome,
            'receita' => $extraRev,
            'receita_pedidos' => 0.0,
            'receita_revenda' => $extraRev,
            'meta_mensal' => $metaUtilizada,
            'comissao_percentual' => $comissaoIndividualPct,
            'comissao_percentual_individual' => $comissaoIndividualPct,
            'comissao_percentual_grupo' => 0.0,
            'comissao_estimada_valor' => round(($extraRev * $comissaoIndividualPct) / 100, 2),
            'duracao_media_min' => null,
            'tempo_medio_espera_min' => null,
            'total_deals_ganhos' => 0,
            'total_deals_perdidos' => $totalP,
            'valor_rejeitado' => round((float)($rej['valor_rejeitado'] ?? 0), 2),
            'taxa_perda_pct' => $totalP > 0 ? 100.0 : 0.0,
            'top_motivos_perda' => [],
            'origem_deals' => [],
            'meta_mensal_utilizada' => $metaUtilizada,
            'percentual_meta' => $pctMetaNz,
            'percentual_lider' => 0.0,
            'posicao' => 0,
            'volume_clientes' => 0,
            'linhas_aprovadas' => 0,
            'linhas_orcamento' => 0,
            'linhas_perdidas' => $lp,
            'pedidos_orcamento_distintos' => 0,
            'taxa_conversao_linhas_pct' => 0.0,
        ];
        $seen[$k] = true;
    }

    foreach ($rejeitadosByVendedor as $k => $rej) {
        if (isset($seen[$k])) continue;
        $nome = (string)($rej['vendedor'] ?? $k);
        $lp = (int)($rej['linhas_rejeitadas'] ?? 0);
        $totalP = (int)($rej['pedidos_rejeitados'] ?? 0);
        $extraRev = round((float)($revByKey[$k] ?? 0), 2);
        unset($revByKey[$k]);
        $pctRej = $metaSistemaVendedor > 0 ? round(($extraRev / $metaSistemaVendedor) * 100, 2) : 0.0;
        $comissaoIndividualPct = $calcComissaoIndividualPct($pctRej);
        $ranking[] = [
            'vendedor' => $nome,
            'receita' => $extraRev,
            'receita_pedidos' => 0.0,
            'receita_revenda' => $extraRev,
            'meta_mensal' => $metaSistemaVendedor,
            'comissao_percentual' => $comissaoIndividualPct,
            'comissao_percentual_individual' => $comissaoIndividualPct,
            'comissao_percentual_grupo' => 0.0,
            'comissao_estimada_valor' => round(($extraRev * $comissaoIndividualPct) / 100, 2),
            'duracao_media_min' => null,
            'tempo_medio_espera_min' => null,
            'total_deals_ganhos' => 0,
            'total_deals_perdidos' => $totalP,
            'valor_rejeitado' => round((float)($rej['valor_rejeitado'] ?? 0), 2),
            'taxa_perda_pct' => $totalP > 0 ? 100.0 : 0.0,
            'top_motivos_perda' => [],
            'origem_deals' => [],
            'meta_mensal_utilizada' => $metaSistemaVendedor,
            'percentual_meta' => $pctRej,
            'percentual_lider' => 0.0,
            'posicao' => 0,
            'volume_clientes' => 0,
            'linhas_aprovadas' => 0,
            'linhas_orcamento' => 0,
            'linhas_perdidas' => $lp,
            'pedidos_orcamento_distintos' => 0,
            'taxa_conversao_linhas_pct' => 0.0,
        ];
        $seen[$k] = true;
    }

    foreach ($revByKey as $kLeft => $totLeft) {
        if ($totLeft <= 0 || isset($seen[$kLeft])) {
            continue;
        }
        $nomeLeft = (string)($nomesByKey[$kLeft] ?? '');
        if ($nomeLeft === '' || !function_exists('gcIsAllowedVendedora') || !gcIsAllowedVendedora($nomeLeft)) {
            continue;
        }
        $metaU = (float)($metasByKey[$kLeft] ?? $metaSistemaVendedor);
        if ($metaU <= 0) {
            $metaU = $metaSistemaVendedor;
        }
        $tot = round((float) $totLeft, 2);
        $pctL = $metaU > 0 ? round(($tot / $metaU) * 100, 2) : 0.0;
        $ciL = $calcComissaoIndividualPct($pctL);
        $ranking[] = [
            'vendedor' => $nomeLeft,
            'receita' => $tot,
            'receita_pedidos' => 0.0,
            'receita_revenda' => $tot,
            'meta_mensal' => $metaU,
            'comissao_percentual' => $ciL,
            'comissao_percentual_individual' => $ciL,
            'comissao_percentual_grupo' => 0.0,
            'comissao_estimada_valor' => round(($tot * $ciL) / 100, 2),
            'duracao_media_min' => null,
            'tempo_medio_espera_min' => null,
            'total_deals_ganhos' => 0,
            'total_deals_perdidos' => 0,
            'valor_rejeitado' => 0.0,
            'taxa_perda_pct' => 0.0,
            'top_motivos_perda' => [],
            'origem_deals' => [],
            'meta_mensal_utilizada' => $metaU,
            'percentual_meta' => $pctL,
            'percentual_lider' => 0.0,
            'posicao' => 0,
            'volume_clientes' => 0,
            'linhas_aprovadas' => 0,
            'linhas_orcamento' => 0,
            'linhas_perdidas' => 0,
            'pedidos_orcamento_distintos' => 0,
            'taxa_conversao_linhas_pct' => 0.0,
        ];
        $seen[$kLeft] = true;
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

    $receitaGrupo = 0.0;
    foreach ($ranking as $it) {
        $receitaGrupo += (float)($it['receita'] ?? 0);
    }
    $comissaoGrupoPct = $calcComissaoGrupoPct($receitaGrupo);

    $penalidadeErrosByVendedor = [];
    try {
        gcEnsureVendedorPerdasAcoesTable($pdo);
        $stPen = $pdo->prepare("
            SELECT vendedor_nome, COALESCE(SUM(pontos_descontados), 0) AS pontos
            FROM vendedor_perdas_acoes
            WHERE (data_perda IS NULL OR DATE(data_perda) BETWEEN :de AND :ate)
            GROUP BY vendedor_nome
        ");
        $stPen->execute(['de' => $dataDe, 'ate' => $dataAte]);
        foreach ($stPen->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rp) {
            $kPen = gcNormalizeNome((string)($rp['vendedor_nome'] ?? ''));
            if ($kPen === '') continue;
            $penalidadeErrosByVendedor[$kPen] = round((float)($rp['pontos'] ?? 0), 2);
        }
    } catch (Throwable $e) {
        $penalidadeErrosByVendedor = [];
    }

    foreach ($ranking as &$it) {
        $receita = (float)($it['receita'] ?? 0);
        $comissaoIndPct = (float)($it['comissao_percentual_individual'] ?? 0);
        $comissaoTotalPct = round($comissaoIndPct + $comissaoGrupoPct, 2);
        $it['comissao_percentual_grupo'] = $comissaoGrupoPct;
        $it['comissao_percentual'] = $comissaoTotalPct;
        $it['comissao_estimada_valor'] = round(($receita * $comissaoTotalPct) / 100, 2);

        $kVendScore = gcNormalizeNome((string)($it['vendedor'] ?? ''));
        $penalidadeErros = (float)($penalidadeErrosByVendedor[$kVendScore] ?? 0);
        $score = $calcScore($it, $penalidadeErros);
        $scoreTotal = (float)($score['total'] ?? 0);
        $pctMeta = (float)($it['percentual_meta'] ?? 0);
        $premio = 0.0;
        if ($pctMeta > 100.0 && $scoreTotal > 95.0) {
            $premio = 400.0;
        } elseif ($pctMeta >= 100.0 && $scoreTotal > 85.0) {
            $premio = 200.0;
        }
        $it['score_performance'] = $score;
        $it['premio_performance_valor'] = $premio;
        $it['total_estimado_com_premio'] = round((float)$it['comissao_estimada_valor'] + $premio, 2);
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
        $vNomeMe = trim((string)($me['vendedor'] ?? ''));

        if ($vNomeMe !== '') {
            try {
                $stMot = $pdo->prepare("
                    SELECT
                        TRIM(COALESCE(i.status, '')) AS motivo,
                        COUNT(*) AS quantidade
                    FROM itens_orcamentos_pedidos i
                    LEFT JOIN gestao_pedidos gpLink
                      ON gpLink.numero_pedido = i.numero
                     AND gpLink.serie_pedido = i.serie
                     AND gpLink.ano_referencia = i.ano_referencia
                    WHERE DATE(i.data) BETWEEN :de AND :ate
                      AND COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') = :vend
                      AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
                    GROUP BY TRIM(COALESCE(i.status, ''))
                    HAVING motivo <> ''
                    ORDER BY quantidade DESC
                    LIMIT 10
                ");
                $stMot->execute(['de' => $dataDe, 'ate' => $dataAte, 'vend' => $vNomeMe]);
                $motivos = [];
                foreach ($stMot->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
                    $motivos[] = [
                        'nome' => (string)($m['motivo'] ?? ''),
                        'quantidade' => (int)($m['quantidade'] ?? 0),
                    ];
                }
                $me['top_motivos_perda'] = $motivos;
            } catch (Throwable $e) {
                // mantÃ©m vazio
            }

            try {
                $stCanal = $pdo->prepare("
                    SELECT
                        COALESCE(NULLIF(TRIM(gp.canal_atendimento), ''), '(Sem canal)') AS origem,
                        COUNT(*) AS quantidade,
                        COALESCE(SUM(CASE WHEN {$approvedCase} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
                    FROM gestao_pedidos gp
                    WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
                      AND COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') = :vend
                    GROUP BY COALESCE(NULLIF(TRIM(gp.canal_atendimento), ''), '(Sem canal)')
                    ORDER BY receita DESC
                    LIMIT 12
                ");
                $stCanal->execute(['de' => $dataDe, 'ate' => $dataAte, 'vend' => $vNomeMe]);
                $origens = [];
                foreach ($stCanal->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) {
                    $origens[] = [
                        'nome' => (string)($o['origem'] ?? ''),
                        'receita' => round((float)($o['receita'] ?? 0), 2),
                        'quantidade' => (int)($o['quantidade'] ?? 0),
                    ];
                }
                $me['origem_deals'] = $origens;
            } catch (Throwable $e) {
                // mantÃ©m vazio
            }
        }
    }

    $funilEstagios = [];
    try {
        $stFun = $pdo->prepare("
            SELECT
                TRIM(COALESCE(gp.status_financeiro, '(sem status)')) AS stage_name,
                COUNT(*) AS quantidade
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
            GROUP BY TRIM(COALESCE(gp.status_financeiro, '(sem status)'))
            ORDER BY quantidade DESC
            LIMIT 20
        ");
        $stFun->execute(['de' => $dataDe, 'ate' => $dataAte]);
        foreach ($stFun->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) {
            $funilEstagios[] = [
                'stage_name' => (string)($f['stage_name'] ?? ''),
                'quantidade' => (int)($f['quantidade'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        $funilEstagios = [];
    }

    $updatedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    echo json_encode([
        'success' => true,
        'fonte' => 'gestao_pedidos',
        'periodo' => [
            'data_de' => $dataDe,
            'data_ate' => $dataAte,
        ],
        'ranking' => $ranking,
        'me' => $me,
        'funil_estagios' => $funilEstagios,
        'motivos_perda_geral' => [],
        'origens_geral' => [],
        'comissao_percentual_usuario' => is_array($me)
            ? (float)($me['comissao_percentual'] ?? 0)
            : 0.0,
        'meta_sistema_vendedor' => $metaSistemaVendedor,
        'regras_comissao' => [
            'individual_percentual_meta' => [
                ['max' => 69.99, 'comissao_pct' => 0.5],
                ['min' => 70.0, 'max' => 89.99, 'comissao_pct' => 1.0],
                ['min' => 90.0, 'max' => 99.99, 'comissao_pct' => 1.3],
                ['min' => 100.0, 'max' => 109.99, 'comissao_pct' => 1.8],
                ['min' => 110.0, 'comissao_pct' => 2.0],
            ],
            'grupo_receita' => [
                ['min' => 320000.0, 'max' => 349999.99, 'comissao_pct' => 1.5],
                ['min' => 350000.0, 'max' => 369999.99, 'comissao_pct' => 2.0],
                ['min' => 370000.0, 'comissao_pct' => 2.5],
            ],
            'score' => [
                'faturamento_max' => 50,
                'conversao_max' => 20,
                'erros_max' => 20,
                'organizacao_crm_max' => 10,
                'total_max' => 100,
            ],
            'premio_performance' => [
                ['condicao' => 'bate_meta_e_score_maior_85', 'valor' => 200],
                ['condicao' => 'ultrapassa_meta_e_score_maior_95', 'valor' => 400],
            ],
        ],
        'receita_grupo_total' => round($receitaGrupo, 2),
        'comissao_grupo_percentual_aplicada' => $comissaoGrupoPct,
        'ganho_total_usuario' => is_array($me)
            ? (float)($me['total_estimado_com_premio'] ?? 0)
            : 0.0,
        'premio_performance_usuario' => is_array($me)
            ? (float)($me['premio_performance_valor'] ?? 0)
            : 0.0,
        'updated_at' => $updatedAt,
    ], JSON_UNESCAPED_UNICODE);
}

function handleGestaoRejeitadosRd(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
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
            'error'   => 'RDSTATION_CRM_TOKEN nÃ£o configurado no .env.',
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
        $prescritor = trim((string)($row['prescritor'] ?? 'NÃ£o informado'));
        $contato = trim((string)($row['contato'] ?? ''));
        $atendente = trim((string)($row['atendente'] ?? 'NÃ£o informado'));
        $motivo = trim((string)($row['motivo'] ?? 'NÃ£o informado'));
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
            'motivo_principal' => $motivoPrincipal !== '' ? $motivoPrincipal : 'NÃ£o informado',
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
            'motivo_principal' => $motivoPrincipal !== '' ? $motivoPrincipal : 'NÃ£o informado',
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
        echo json_encode(['success' => false, 'error' => 'Endpoint temporÃ¡rio disponÃ­vel apenas em localhost.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode(['success' => false, 'fonte' => null, 'error' => 'RDSTATION_CRM_TOKEN nÃ£o configurado no .env.'], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['success' => false, 'error' => 'Endpoint temporÃ¡rio disponÃ­vel apenas em localhost.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode(['success' => false, 'fonte' => null, 'error' => 'RDSTATION_CRM_TOKEN nÃ£o configurado no .env.'], JSON_UNESCAPED_UNICODE);
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
        echo json_encode(['success' => false, 'error' => 'Endpoint temporÃ¡rio disponÃ­vel apenas em localhost.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    if ($rdToken === '') {
        echo json_encode(['success' => false, 'fonte' => null, 'error' => 'RDSTATION_CRM_TOKEN nÃ£o configurado no .env.'], JSON_UNESCAPED_UNICODE);
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

/**
 * DiretÃ³rio gravÃ¡vel para cache JSON da TV (evita /tmp partilhado na Hostinger).
 * Opcional: RD_TV_CACHE_DIR=/caminho/absoluto no .env
 */
function gcTvCacheDir(): string
{
    $env = trim((string)(getenv('RD_TV_CACHE_DIR') ?: ''));
    if ($env !== '' && is_dir($env) && is_writable($env)) {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $env), DIRECTORY_SEPARATOR);
    }
    $base = __DIR__ . DIRECTORY_SEPARATOR . 'storage_tv_cache';
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    if (is_dir($base) && is_writable($base)) {
        return $base;
    }

    return rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR);
}

/**
 * Idade mÃ¡xima (segundos) do ficheiro de cache RD ao servir como stale apÃ³s falha da API.
 * Acima disto passa a usar o ranking do MySQL (importaÃ§Ã£o), para nÃ£o â€œcongelarâ€ nÃºmeros velhos.
 */
function gcTvStaleMaxSec(): int
{
    $n = (int)(getenv('RD_TV_STALE_MAX_SEC') ?: 900);
    if ($n < 120) {
        return 120;
    }
    if ($n > 86400) {
        return 86400;
    }

    return $n;
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
    // DecisÃ£o operacional: TV usa somente base interna (importaÃ§Ã£o), sem consultar RD.
    $forceDbSource = true;

    $rdToken = trim((string)(getenv('RDSTATION_CRM_TOKEN') ?: ''));
    $tokenFp = $rdToken !== '' ? substr(hash('sha256', $rdToken), 0, 24) : 'sem_token';
    $cacheKey = 'gc_tv_rd_v3_' . md5($dataDe . '|' . $dataAte . '|' . $tokenFp);
    $cacheFile = gcTvCacheDir() . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    $rdFalhouAntesMysql = false;

    $cacheTtlSec = (int)(getenv('RD_TV_CACHE_TTL') ?: 90);
    if ($cacheTtlSec < 30) {
        $cacheTtlSec = 30;
    }
    $cachedTv = null;
    if (!$forceDbSource) {
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

        // Se houver cache recente e nÃ£o for refresh forÃ§ado, responde rÃ¡pido para a TV.
        try {
            if (!$forceRefresh && is_file($cacheFile) && (time() - @filemtime($cacheFile) <= $cacheTtlSec) && is_array($cachedTv)) {
                $cachedTv['cache'] = ['hit' => true, 'stale' => false];
                echo json_encode($cachedTv, JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (Throwable $e) {
            // segue fluxo normal
        }
    }

    // ===== Tenta usar RD Station CRM =====
    if (!$forceDbSource && $rdToken !== '') {
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
            if (function_exists('error_log')) {
                error_log('RD Station TV proxy error: ' . $e->getMessage());
            }
            // Snapshot RD recente; cache muito velho nÃ£o serve (Hostinger mostrava nÃºmeros irreais vs localhost)
            if (is_array($cachedTv) && is_file($cacheFile)) {
                $fm = @filemtime($cacheFile);
                $age = is_int($fm) && $fm > 0 ? (time() - $fm) : PHP_INT_MAX;
                $maxStale = gcTvStaleMaxSec();
                if ($age >= 0 && $age <= $maxStale) {
                    $cachedTv['cache'] = ['hit' => true, 'stale' => true, 'idade_segundos' => $age];
                    $cachedTv['fonte'] = 'rdstation_crm_cache_stale';
                    $cachedTv['aviso_rd'] = 'RD indisponÃ­vel; Ãºltimo snapshot do CRM (' . $age . 's).';
                    echo json_encode($cachedTv, JSON_UNESCAPED_UNICODE);
                    return;
                }
                @unlink($cacheFile);
            }
            $rdFalhouAntesMysql = true;
        }
    }

    // ===== Fallback: banco local (legado â€” CSV importado) =====
    $approvedCase = "(
        gp.status_financeiro IS NULL OR
        (
            gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'OrÃ§amento')
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
                base.vendedor,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, base.data_orcamento_ref, base.data_aprovacao_ref)), 0) AS duracao_media_min
            FROM (
                SELECT
                    COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS vendedor,
                    gp.ano_referencia,
                    gp.numero_pedido,
                    MIN(gp.data_orcamento) AS data_orcamento_ref,
                    MIN(gp.data_aprovacao) AS data_aprovacao_ref
                FROM gestao_pedidos gp
                WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
                  AND gp.data_orcamento IS NOT NULL
                  AND gp.data_aprovacao IS NOT NULL
                  AND gp.data_aprovacao >= gp.data_orcamento
                  AND {$approvedCase}
                GROUP BY
                    COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)'),
                    gp.ano_referencia,
                    gp.numero_pedido
            ) base
            GROUP BY base.vendedor
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

    if (function_exists('gcRevendaAggRows')) {
        foreach (gcRevendaAggRows($pdo, $dataDe, $dataAte) as $rRev) {
            $nomeR = trim((string)($rRev['nome'] ?? ''));
            if ($nomeR === '') {
                continue;
            }
            if (function_exists('gcIsAllowedVendedora') && !gcIsAllowedVendedora($nomeR)) {
                continue;
            }
            $kR = function_exists('mb_strtolower') ? mb_strtolower($nomeR, 'UTF-8') : strtolower($nomeR);
            if (!isset($map[$kR])) {
                $map[$kR] = [
                    'vendedor' => $nomeR,
                    'receita' => 0.0,
                    'meta_mensal' => 0.0,
                    'duracao_media_min' => null,
                    'tempo_medio_espera_min' => null,
                ];
            }
            $map[$kR]['receita'] = round((float)($map[$kR]['receita'] ?? 0) + (float)($rRev['total'] ?? 0), 2);
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
        if (!array_key_exists('pedidos_orcamento_distintos', $it)) $it['pedidos_orcamento_distintos'] = null;
        if (!array_key_exists('taxa_perda_pct', $it)) $it['taxa_perda_pct'] = null;
        if (!array_key_exists('top_motivos_perda', $it)) $it['top_motivos_perda'] = [];
        if (!array_key_exists('origem_deals', $it)) $it['origem_deals'] = [];
    }
    unset($it);

    // Assinatura da base no perÃ­odo para detectar mudanÃ§as reais apÃ³s importaÃ§Ã£o/script.
    $dbVersion = '';
    try {
        $stSig = $pdo->prepare("
            SELECT
                COUNT(*) AS registros,
                COALESCE(SUM(CASE WHEN {$approvedCase} THEN gp.preco_liquido ELSE 0 END), 0) AS soma_aprovado,
                COALESCE(MAX(gp.data_aprovacao), '') AS max_data_aprovacao,
                COALESCE(MAX(gp.data_orcamento), '') AS max_data_orcamento
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
        ");
        $stSig->execute(['de' => $dataDe, 'ate' => $dataAte]);
        $sig = $stSig->fetch(PDO::FETCH_ASSOC) ?: [];
        $reg = (int)($sig['registros'] ?? 0);
        $sum = number_format((float)($sig['soma_aprovado'] ?? 0), 2, '.', '');
        $maxAprov = trim((string)($sig['max_data_aprovacao'] ?? ''));
        $maxOrc = trim((string)($sig['max_data_orcamento'] ?? ''));
        $dbVersion = md5($dataDe . '|' . $dataAte . '|' . $reg . '|' . $sum . '|' . $maxAprov . '|' . $maxOrc);
    } catch (Throwable $e) {
        $dbVersion = '';
    }

    $payloadTvMysql = [
        'success'         => true,
        'fonte'           => 'banco_local',
        'periodo'         => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'max_receita'     => round($maxReceita, 2),
        'ranking'         => $lista,
        'funil_estagios'  => [],
        'db_version'      => $dbVersion,
        'updated_at'      => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ];
    if (!empty($rdFalhouAntesMysql)) {
        $payloadTvMysql['aviso_rd'] = 'RD Station falhou; ranking pelo banco interno (importaÃ§Ã£o) â€” pode divergir do CRM.';
    }
    if (!empty($forceDbSource)) {
        $payloadTvMysql['aviso_rd'] = 'Fonte definida para banco interno (importaÃ§Ã£o), sem consulta ao RD Station.';
    }
    echo json_encode($payloadTvMysql, JSON_UNESCAPED_UNICODE);
}

function gcEnsureVendedorPerdasAcoesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendedor_perdas_acoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ano_referencia INT NOT NULL,
            numero_pedido VARCHAR(40) NOT NULL,
            serie_pedido VARCHAR(20) NOT NULL DEFAULT '',
            vendedor_nome VARCHAR(190) NOT NULL,
            data_perda DATE NULL,
            motivo_perda VARCHAR(255) NULL,
            classificacao_erro VARCHAR(20) NULL,
            pontos_descontados DECIMAL(10,2) NOT NULL DEFAULT 0,
            observacoes TEXT NULL,
            updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pedido_vendedor (ano_referencia, numero_pedido, serie_pedido, vendedor_nome)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
}

function gcEnsureVendedorPerdasInteracoesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendedor_perdas_interacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ano_referencia INT NOT NULL,
            numero_pedido VARCHAR(40) NOT NULL,
            serie_pedido VARCHAR(20) NOT NULL DEFAULT '',
            vendedor_nome VARCHAR(190) NOT NULL,
            tipo_contato VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
            status_tentativa VARCHAR(30) NOT NULL DEFAULT 'retorno_pendente',
            mensagem TEXT NULL,
            proximo_passo VARCHAR(255) NULL,
            data_contato DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_perda_interacao_ref (ano_referencia, numero_pedido, serie_pedido, vendedor_nome),
            KEY idx_perda_interacao_data (data_contato)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
}

function handleVendedorPedidosLista(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao setor vendedor ou administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $vendedor = gcResolveVendedorTargetFromSession($_SESSION, $_GET);
    if ($vendedor === '') {
        echo json_encode(['success' => true, 'aprovados' => [], 'recusados_carrinho' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    $approvedCase = function_exists('gcApprovedCase') ? gcApprovedCase('gp') : "(
        gp.status_financeiro IS NULL OR
        (
            gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'OrÃ§amento')
            AND gp.status_financeiro NOT LIKE '%carrinho%'
        )
    )";

    $sqlAprovados = "
        SELECT
            i.ano_referencia AS ano_referencia,
            CAST(gp.numero_pedido AS CHAR) AS numero_pedido,
            CAST(COALESCE(gp.serie_pedido, 0) AS CHAR) AS serie_pedido,
            DATE(gp.data_aprovacao) AS data_aprovacao,
            DATE(gp.data_orcamento) AS data_orcamento,
            COALESCE(NULLIF(TRIM(gp.prescritor), ''), '(Sem prescritor)') AS prescritor,
            COALESCE(NULLIF(TRIM(gp.cliente), ''), '(Sem cliente)') AS cliente,
            '' AS contato,
            ROUND(COALESCE(gp.preco_liquido, 0), 2) AS valor,
            LOWER(TRIM(COALESCE(gp.status_financeiro, ''))) AS status_origem
        FROM gestao_pedidos gp
        LEFT JOIN itens_orcamentos_pedidos i
          ON i.numero = gp.numero_pedido
         AND i.serie = gp.serie_pedido
         AND i.ano_referencia = gp.ano_referencia
        WHERE DATE(gp.data_aprovacao) BETWEEN :de AND :ate
          AND COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') = :vend
          AND {$approvedCase}
        ORDER BY gp.data_aprovacao DESC, gp.numero_pedido DESC, gp.serie_pedido DESC
        LIMIT 5000
    ";
    $stAprov = $pdo->prepare($sqlAprovados);
    $stAprov->execute(['de' => $dataDe, 'ate' => $dataAte, 'vend' => $vendedor]);
    $aprovados = $stAprov->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sqlRecusados = "
        SELECT
            i.ano_referencia AS ano_referencia,
            CAST(i.numero AS CHAR) AS numero_pedido,
            CAST(COALESCE(i.serie, 0) AS CHAR) AS serie_pedido,
            NULL AS data_aprovacao,
            DATE(COALESCE(gpLink.data_orcamento, i.data)) AS data_orcamento,
            COALESCE(NULLIF(TRIM(i.prescritor), ''), NULLIF(TRIM(gpLink.prescritor), ''), '(Sem prescritor)') AS prescritor,
            COALESCE(NULLIF(TRIM(i.paciente), ''), NULLIF(TRIM(gpLink.cliente), ''), '(Sem cliente)') AS cliente,
            '' AS contato,
            ROUND(COALESCE(i.valor_liquido, 0), 2) AS valor,
            LOWER(TRIM(COALESCE(i.status, ''))) AS status_origem
        FROM itens_orcamentos_pedidos i
        LEFT JOIN gestao_pedidos gpLink
          ON gpLink.numero_pedido = i.numero
         AND gpLink.serie_pedido = i.serie
         AND gpLink.ano_referencia = i.ano_referencia
        WHERE DATE(i.data) BETWEEN :de AND :ate
          AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
          AND COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') = :vend
        ORDER BY i.data DESC, i.numero DESC, i.serie DESC
        LIMIT 5000
    ";
    $stRec = $pdo->prepare($sqlRecusados);
    $stRec->execute(['de' => $dataDe, 'ate' => $dataAte, 'vend' => $vendedor]);
    $recusados = $stRec->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Mesma linha da gestão comercial: reprovados que existem só em gestao_pedidos (sem linha em itens com status recusado).
    $recMap = [];
    foreach ($recusados as $r) {
        $k = (int) ($r['numero_pedido'] ?? 0) . '_' . (int) ($r['serie_pedido'] ?? 0);
        $recMap[$k] = $r;
    }
    $sqlRecGestao = "
        SELECT
            MAX(gp.ano_referencia) AS ano_referencia,
            CAST(gp.numero_pedido AS CHAR) AS numero_pedido,
            CAST(COALESCE(gp.serie_pedido, 0) AS CHAR) AS serie_pedido,
            NULL AS data_aprovacao,
            DATE(MAX(COALESCE(gp.data_orcamento, gp.data_aprovacao))) AS data_orcamento,
            COALESCE(NULLIF(TRIM(MAX(gp.prescritor)), ''), '(Sem prescritor)') AS prescritor,
            COALESCE(NULLIF(TRIM(MAX(gp.cliente)), ''), '(Sem cliente)') AS cliente,
            '' AS contato,
            ROUND(COALESCE(SUM(gp.preco_liquido), 0), 2) AS valor,
            LOWER(TRIM(MAX(COALESCE(gp.status_financeiro, '')))) AS status_origem
        FROM gestao_pedidos gp
        WHERE (
                gp.status_financeiro IN ('Recusado', 'Cancelado', CONCAT('Or', CHAR(231), 'amento'))
                OR gp.status_financeiro LIKE '%carrinho%'
            )
          AND DATE(COALESCE(gp.data_aprovacao, gp.data_orcamento)) BETWEEN :de AND :ate
          AND COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') = :vend
        GROUP BY gp.numero_pedido, gp.serie_pedido
        ORDER BY MAX(gp.data_orcamento) DESC, gp.numero_pedido DESC, gp.serie_pedido DESC
        LIMIT 5000
    ";
    try {
        $stRg = $pdo->prepare($sqlRecGestao);
        $stRg->execute(['de' => $dataDe, 'ate' => $dataAte, 'vend' => $vendedor]);
        foreach ($stRg->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $k = (int) ($row['numero_pedido'] ?? 0) . '_' . (int) ($row['serie_pedido'] ?? 0);
            if (isset($recMap[$k])) {
                continue;
            }
            $recMap[$k] = $row;
        }
    } catch (Throwable $e) {
        /* mantém só itens */
    }
    $recusados = array_values($recMap);

    echo json_encode([
        'success' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'vendedor' => $vendedor,
        'aprovados' => $aprovados,
        'recusados_carrinho' => $recusados
    ], JSON_UNESCAPED_UNICODE);
}

function gcResolveVendedorTargetFromSession(array $session, array $query): string
{
    $tipo = strtolower(trim((string)($session['user_tipo'] ?? '')));
    $nomeSessao = trim((string)($session['user_nome'] ?? ''));
    $vendedorParam = trim((string)($query['vendedor'] ?? ''));
    if ($tipo === 'admin' && $vendedorParam !== '') {
        return $vendedorParam;
    }
    return $nomeSessao;
}

function handleVendedorComissaoTransferOpcoes(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string) ($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string) ($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $nomes = [];
    try {
        $st = $pdo->query(
            "SELECT TRIM(nome) AS nome FROM usuarios
             WHERE LOWER(TRIM(COALESCE(setor,''))) = 'vendedor' AND COALESCE(ativo,1) = 1
               AND TRIM(COALESCE(nome,'')) != ''
             ORDER BY TRIM(nome) ASC"
        );
        if ($st) {
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $n = trim((string) ($r['nome'] ?? ''));
                if ($n !== '') {
                    $nomes[] = $n;
                }
            }
        }
    } catch (Throwable $e) {
        $nomes = [];
    }
    echo json_encode(['success' => true, 'nomes' => $nomes], JSON_UNESCAPED_UNICODE);
}

function handleVendedorComissaoTransferLista(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    gcEnsureComissaoTransferenciasTable($pdo);
    $vend = gcResolveVendedorTargetFromSession($_SESSION, $_GET);
    if ($vend === '') {
        echo json_encode(['success' => true, 'rows' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    $st = $pdo->prepare(
        'SELECT id, solicitante_nome, contraparte_nome, valor, ref_mes, ref_ano, numero_pedido, serie_pedido, motivo, status,
                created_at, updated_at, decidido_por_nome, decidido_em, observacao_gestao
         FROM gc_comissao_transferencias
         WHERE solicitante_nome = :v1 OR (contraparte_nome = :v2 AND status = \'pendente\')
         ORDER BY id DESC
         LIMIT 200'
    );
    $st->execute(['v1' => $vend, 'v2' => $vend]);
    echo json_encode(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
}

function handleVendedorComissaoTransferSolicitar(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $solicitante = gcResolveVendedorTargetFromSession($_SESSION, $payload);
    if ($solicitante === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Consultora solicitante não identificada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $contraparte = trim((string)($payload['contraparte_nome'] ?? ''));
    $motivo = trim((string)($payload['motivo'] ?? ''));
    $valor = (float) str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string) ($payload['valor'] ?? '0')));
    $refMes = isset($payload['ref_mes']) ? (int) $payload['ref_mes'] : 0;
    $refAno = isset($payload['ref_ano']) ? (int) $payload['ref_ano'] : 0;
    $numeroPedido = (int) ($payload['numero_pedido'] ?? 0);
    if ($numeroPedido <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Informe o número do pedido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($numeroPedido > 999999999) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Número do pedido inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $serieRaw = trim((string) ($payload['serie_pedido'] ?? ''));
    $seriePedido = $serieRaw === '' ? 0 : (int) preg_replace('/\D+/', '', $serieRaw);
    if ($seriePedido < 0 || $seriePedido > 999999) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Série do pedido inválida.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($contraparte === '' || mb_strtoupper($contraparte) === mb_strtoupper($solicitante)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Informe a consultora de origem (diferente de você).'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($valor <= 0 || $valor > 99999999.99) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Valor inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (mb_strlen($motivo) < 10) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Descreva o motivo (mínimo 10 caracteres).'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (mb_strlen($motivo) > 4000) {
        $motivo = mb_substr($motivo, 0, 4000);
    }
    gcEnsureComissaoTransferenciasTable($pdo);
    $ins = $pdo->prepare(
        'INSERT INTO gc_comissao_transferencias
        (solicitante_nome, contraparte_nome, valor, ref_mes, ref_ano, numero_pedido, serie_pedido, motivo, status, created_by)
        VALUES (:sol, :con, :val, :rm, :ra, :np, :sp, :mot, \'pendente\', :uid)'
    );
    $ins->execute([
        'sol' => $solicitante,
        'con' => $contraparte,
        'val' => round($valor, 2),
        'rm' => $refMes > 0 && $refMes <= 12 ? $refMes : null,
        'ra' => $refAno >= 2000 && $refAno <= 2100 ? $refAno : null,
        'np' => $numeroPedido,
        'sp' => $seriePedido,
        'mot' => $motivo,
        'uid' => (int) ($_SESSION['user_id'] ?? 0),
    ]);
    echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
}

function handleVendedorComissaoTransferCancelar(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $id = (int) ($payload['id'] ?? 0);
    $vend = gcResolveVendedorTargetFromSession($_SESSION, $payload);
    if ($id <= 0 || $vend === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Solicitação inválida.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    gcEnsureComissaoTransferenciasTable($pdo);
    $st = $pdo->prepare(
        'UPDATE gc_comissao_transferencias SET status = \'cancelada\', updated_at = NOW()
         WHERE id = :id AND status = \'pendente\' AND solicitante_nome = :vend'
    );
    $st->execute(['id' => $id, 'vend' => $vend]);
    echo json_encode(['success' => true, 'updated' => $st->rowCount() > 0], JSON_UNESCAPED_UNICODE);
}

function handleVendedorComissaoTransferEditar(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) { $payload = []; }
    $id = (int) ($payload['id'] ?? 0);
    $vend = gcResolveVendedorTargetFromSession($_SESSION, $payload);
    if ($id <= 0 || $vend === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Solicitação inválida.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $numStr = preg_replace('/\D/', '', (string)($payload['numero_pedido'] ?? ''));
    $numeroPedido = (int) $numStr;
    if ($numeroPedido <= 0 || $numeroPedido > 999999999) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Número do pedido inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $serStr = preg_replace('/\D/', '', (string)($payload['serie_pedido'] ?? '0'));
    $seriePedido = $serStr === '' ? 0 : (int) $serStr;
    if ($seriePedido < 0 || $seriePedido > 999999) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Série inválida.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $valorRaw = str_replace(['.', ','], ['', '.'], trim((string)($payload['valor'] ?? '')));
    $valor = (float) $valorRaw;
    if ($valor < 0) { $valor = 0; }
    $motivo = trim((string)($payload['motivo'] ?? ''));
    if (mb_strlen($motivo) > 0 && mb_strlen($motivo) < 10) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Motivo muito curto (mínimo 10 caracteres).'], JSON_UNESCAPED_UNICODE);
        return;
    }
    gcEnsureComissaoTransferenciasTable($pdo);
    $st = $pdo->prepare(
        'UPDATE gc_comissao_transferencias
         SET numero_pedido = :np, serie_pedido = :sp, valor = :val, motivo = :mot, updated_at = NOW()
         WHERE id = :id AND status = \'pendente\' AND solicitante_nome = :vend'
    );
    $st->execute(['np' => $numeroPedido, 'sp' => $seriePedido, 'val' => round($valor, 2), 'mot' => $motivo, 'id' => $id, 'vend' => $vend]);
    echo json_encode(['success' => true, 'updated' => $st->rowCount() > 0], JSON_UNESCAPED_UNICODE);
}

function handleVendedorComissaoTransferBuscarPedido(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Não autenticado.'],JSON_UNESCAPED_UNICODE); return; }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Sessão encerrada.'],JSON_UNESCAPED_UNICODE); return; }
    $tipo  = strtolower(trim((string)($_SESSION['user_tipo']  ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'Acesso restrito.'],JSON_UNESCAPED_UNICODE); return; }
    $np  = (int) preg_replace('/\D/', '', (string)($_GET['numero'] ?? ''));
    $sp  = (int) preg_replace('/\D/', '', (string)($_GET['serie']  ?? '0'));
    if ($np <= 0) { http_response_code(422); echo json_encode(['success'=>false,'error'=>'Informe o número do pedido.'],JSON_UNESCAPED_UNICODE); return; }
    // Busca em gestao_pedidos (dados importados) — inclui orcamentista e aprovador
    $st = $pdo->prepare(
        'SELECT numero_pedido, serie_pedido, cliente, prescritor, atendente,
                orcamentista, aprovador,
                SUM(preco_liquido) AS valor_total, status_financeiro,
                MAX(data_aprovacao) AS data_aprovacao
         FROM gestao_pedidos
         WHERE numero_pedido = :np AND (serie_pedido = :sp OR :sp2 = 0)
         GROUP BY numero_pedido, serie_pedido, cliente, prescritor, atendente, orcamentista, aprovador, status_financeiro
         ORDER BY data_aprovacao DESC
         LIMIT 5'
    );
    $st->execute(['np' => $np, 'sp' => $sp, 'sp2' => $sp]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        // Tenta em itens_orcamentos_pedidos — usuario_inclusao=orçamentista, usuario_aprovador=aprovador
        $st2 = $pdo->prepare(
            'SELECT i.numero AS numero_pedido, i.serie AS serie_pedido,
                    i.cliente, i.prescritor, i.atendente,
                    i.usuario_inclusao AS orcamentista, i.usuario_aprovador AS aprovador,
                    SUM(i.valor_liquido) AS valor_total, i.status AS status_financeiro,
                    MAX(i.data) AS data_aprovacao
             FROM itens_orcamentos_pedidos i
             WHERE i.numero = :np AND (i.serie = :sp OR :sp2 = 0)
             GROUP BY i.numero, i.serie, i.cliente, i.prescritor, i.atendente, i.usuario_inclusao, i.usuario_aprovador, i.status
             ORDER BY data_aprovacao DESC
             LIMIT 5'
        );
        $st2->execute(['np' => $np, 'sp' => $sp, 'sp2' => $sp]);
        $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows) { echo json_encode(['success'=>false,'error'=>'Pedido não encontrado na base.'],JSON_UNESCAPED_UNICODE); return; }
    $out = array_map(function($r) {
        return [
            'numero_pedido'    => (int)   $r['numero_pedido'],
            'serie_pedido'     => (int)   ($r['serie_pedido'] ?? 0),
            'cliente'          => (string)($r['cliente']       ?? ''),
            'prescritor'       => (string)($r['prescritor']    ?? ''),
            'atendente'        => (string)($r['atendente']     ?? ''),
            'orcamentista'     => (string)($r['orcamentista']  ?? ''),
            'aprovador'        => (string)($r['aprovador']     ?? ''),
            'valor_total'      => round((float)($r['valor_total'] ?? 0), 2),
            'status_financeiro'=> (string)($r['status_financeiro'] ?? ''),
            'data_aprovacao'   => $r['data_aprovacao'] ? substr((string)$r['data_aprovacao'], 0, 10) : null,
        ];
    }, $rows);
    echo json_encode(['success'=>true,'rows'=>$out],JSON_UNESCAPED_UNICODE);
}

function handleVendedorRevendaLista(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $vend = gcResolveVendedorTargetFromSession($_SESSION, $_GET);
    if ($vend === '') {
        echo json_encode(['success' => true, 'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte], 'rows' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!function_exists('gcEnsureRevendaVendasTable')) {
        echo json_encode(['success' => true, 'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte], 'rows' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    gcEnsureRevendaVendasTable($pdo);
    $st = $pdo->prepare(
        'SELECT id, vendedor_nome, data_venda, valor_liquido, descricao, ativo, created_at
         FROM gc_revenda_vendas
         WHERE LOWER(TRIM(vendedor_nome)) = LOWER(TRIM(:v))
           AND data_venda BETWEEN :a AND :b
         ORDER BY id DESC
         LIMIT 200'
    );
    $st->execute(['v' => $vend, 'a' => $dataDe, 'b' => $dataAte]);
    echo json_encode([
        'success' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ], JSON_UNESCAPED_UNICODE);
}

function handleVendedorRevendaLancar(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $vend = gcResolveVendedorTargetFromSession($_SESSION, $payload);
    if ($vend === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Consultora não identificada na sessão.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $dv = trim((string)($payload['data_venda'] ?? ''));
    $valor = (float) str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string)($payload['valor_liquido'] ?? '0')));
    $desc = trim((string)($payload['descricao'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dv)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Data inválida (AAAA-MM-DD).'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($valor <= 0 || $valor > 99999999.99) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Valor inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (mb_strlen($desc) > 500) {
        $desc = mb_substr($desc, 0, 500);
    }
    gcEnsureRevendaVendasTable($pdo);
    // Vendedor lança como pendente — aguarda aprovação do gestor para entrar na comissão
    $ins = $pdo->prepare(
        "INSERT INTO gc_revenda_vendas (vendedor_nome, data_venda, valor_liquido, descricao, ativo, status, created_by)
         VALUES (:n, :d, :v, :desc, 0, 'pendente', :uid)"
    );
    $ins->execute([
        'n' => $vend,
        'd' => $dv,
        'v' => round($valor, 2),
        'desc' => $desc !== '' ? $desc : null,
        'uid' => (int)($_SESSION['user_id'] ?? 0),
    ]);
    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
}

function handleVendedorRevendaCancelar(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $id = (int)($payload['id'] ?? 0);
    $vend = gcResolveVendedorTargetFromSession($_SESSION, $payload);
    if ($id <= 0 || $vend === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    gcEnsureRevendaVendasTable($pdo);
    $st = $pdo->prepare(
        "UPDATE gc_revenda_vendas SET ativo = 0, status = 'cancelada'
         WHERE id = :id AND status = 'pendente' AND LOWER(TRIM(vendedor_nome)) = LOWER(TRIM(:v))"
    );
    $st->execute(['id' => $id, 'v' => $vend]);
    echo json_encode(['success' => true, 'updated' => $st->rowCount() > 0], JSON_UNESCAPED_UNICODE);
}

function handleVendedorPerdasLista(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao setor vendedor ou administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    gcEnsureVendedorPerdasAcoesTable($pdo);

    [$dataDe, $dataAte] = gcDateRangeFromQuery();
    $vendedor = gcResolveVendedorTargetFromSession($_SESSION, $_GET);
    if ($vendedor === '') {
        echo json_encode(['success' => true, 'rows' => [], 'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 0, 'pages' => 0]], JSON_UNESCAPED_UNICODE);
        return;
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(5, min(50, (int)($_GET['per_page'] ?? 10)));
    $offset = ($page - 1) * $perPage;

    $sortByRaw = strtolower(trim((string)($_GET['sort_by'] ?? 'data_perda')));
    $sortDir = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
    $q = trim((string)($_GET['q'] ?? ''));
    $qLike = $q !== '' ? ('%' . str_replace(' ', '%', $q) . '%') : '';
    $sortMap = [
        'data' => 'g.data_perda',
        'data_perda' => 'g.data_perda',
        'valor' => 'g.valor_rejeitado',
        'valor_rejeitado' => 'g.valor_rejeitado',
        'cliente' => 'g.cliente',
        'prescritor' => 'g.prescritor',
        'status' => 'g.status_principal',
        'pedido' => 'g.pedido_ref',
    ];
    $orderExpr = $sortMap[$sortByRaw] ?? 'g.data_perda';
    $outerFilterSql = $qLike !== ''
        ? " WHERE (g.pedido_ref LIKE :q OR g.cliente LIKE :q OR g.prescritor LIKE :q OR g.status_principal LIKE :q) "
        : '';

    $baseAggSql = "
        FROM itens_orcamentos_pedidos i
        LEFT JOIN gestao_pedidos gpLink
          ON gpLink.numero_pedido = i.numero
         AND gpLink.serie_pedido = i.serie
         AND gpLink.ano_referencia = i.ano_referencia
        WHERE DATE(i.data) BETWEEN :de AND :ate
          AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
          AND COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') = :vend
    ";

    $total = 0;
    $totalValorRejeitado = 0.0;
    $qtdRecusado = 0;
    $qtdNoCarrinho = 0;
    $qtdComAcao = 0;
    $qtdSemAcao = 0;
    $ticketMedioRejeitado = 0.0;
    try {
        $stCount = $pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(g.valor_rejeitado), 0) AS total_valor_rejeitado
            FROM (
                SELECT
                    i.ano_referencia,
                    i.numero,
                    '' AS serie_pedido,
                    CONCAT(CAST(i.numero AS CHAR), '/', i.ano_referencia) AS pedido_ref,
                    COALESCE(NULLIF(TRIM(MAX(gpLink.cliente)), ''), '(Sem cliente)') AS cliente,
                    COALESCE(NULLIF(TRIM(MAX(gpLink.prescritor)), ''), '(Sem prescritor)') AS prescritor,
                    CASE
                        WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(i.status, ''))) = 'recusado' THEN 1 ELSE 0 END) > 0 THEN 'Recusado'
                        WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(i.status, ''))) = 'no carrinho' THEN 1 ELSE 0 END) > 0 THEN 'No carrinho'
                        ELSE 'Outros'
                    END AS status_principal,
                    ROUND(COALESCE(SUM(i.valor_liquido), 0), 2) AS valor_rejeitado
                {$baseAggSql}
                GROUP BY i.ano_referencia, i.numero
            ) g
            {$outerFilterSql}
        ");
        $countBind = ['de' => $dataDe, 'ate' => $dataAte, 'vend' => $vendedor];
        if ($qLike !== '') $countBind['q'] = $qLike;
        $stCount->execute($countBind);
        $rowCount = $stCount->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($rowCount['total'] ?? 0);
        $totalValorRejeitado = round((float)($rowCount['total_valor_rejeitado'] ?? 0), 2);
    } catch (Throwable $e) {
        $total = 0;
        $totalValorRejeitado = 0.0;
    }

    if ($total > 0) {
        try {
            $stStats = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN g.status_principal = 'Recusado' THEN 1 ELSE 0 END), 0) AS qtd_recusado,
                    COALESCE(SUM(CASE WHEN g.status_principal = 'No carrinho' THEN 1 ELSE 0 END), 0) AS qtd_no_carrinho,
                    COALESCE(SUM(
                        CASE
                            WHEN COALESCE(NULLIF(TRIM(a.motivo_perda), ''), NULLIF(TRIM(a.observacoes), '')) IS NULL THEN 1
                            ELSE 0
                        END
                    ), 0) AS qtd_sem_acao,
                    COALESCE(SUM(
                        CASE
                            WHEN COALESCE(NULLIF(TRIM(a.motivo_perda), ''), NULLIF(TRIM(a.observacoes), '')) IS NOT NULL THEN 1
                            ELSE 0
                        END
                    ), 0) AS qtd_com_acao,
                    COALESCE(AVG(g.valor_rejeitado), 0) AS ticket_medio_rejeitado
                FROM (
                    SELECT
                        i.ano_referencia,
                        CAST(i.numero AS CHAR) AS numero_pedido,
                        '' AS serie_pedido,
                        CONCAT(CAST(i.numero AS CHAR), '/', i.ano_referencia) AS pedido_ref,
                        COALESCE(NULLIF(TRIM(MAX(gpLink.cliente)), ''), '(Sem cliente)') AS cliente,
                        COALESCE(NULLIF(TRIM(MAX(gpLink.prescritor)), ''), '(Sem prescritor)') AS prescritor,
                        CASE
                            WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(i.status, ''))) = 'recusado' THEN 1 ELSE 0 END) > 0 THEN 'Recusado'
                            WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(i.status, ''))) = 'no carrinho' THEN 1 ELSE 0 END) > 0 THEN 'No carrinho'
                            ELSE 'Outros'
                        END AS status_principal,
                        ROUND(COALESCE(SUM(i.valor_liquido), 0), 2) AS valor_rejeitado
                    {$baseAggSql}
                    GROUP BY i.ano_referencia, i.numero
                ) g
                LEFT JOIN vendedor_perdas_acoes a
                  ON a.ano_referencia = g.ano_referencia
                 AND a.numero_pedido = g.numero_pedido
                 AND COALESCE(a.serie_pedido, '') = ''
                 AND a.vendedor_nome = :vend_join_stats
                {$outerFilterSql}
            ");
            $statsBind = [
                'de' => $dataDe,
                'ate' => $dataAte,
                'vend' => $vendedor,
                'vend_join_stats' => $vendedor,
            ];
            if ($qLike !== '') $statsBind['q'] = $qLike;
            $stStats->execute($statsBind);
            $statsRow = $stStats->fetch(PDO::FETCH_ASSOC) ?: [];
            $qtdRecusado = (int)($statsRow['qtd_recusado'] ?? 0);
            $qtdNoCarrinho = (int)($statsRow['qtd_no_carrinho'] ?? 0);
            $qtdSemAcao = (int)($statsRow['qtd_sem_acao'] ?? 0);
            $qtdComAcao = (int)($statsRow['qtd_com_acao'] ?? 0);
            $ticketMedioRejeitado = round((float)($statsRow['ticket_medio_rejeitado'] ?? 0), 2);
        } catch (Throwable $e) {
            $qtdRecusado = 0;
            $qtdNoCarrinho = 0;
            $qtdSemAcao = 0;
            $qtdComAcao = 0;
            $ticketMedioRejeitado = 0.0;
        }
    }

    $rows = [];
    if ($total > 0) {
        $sqlRows = "
            SELECT
                g.ano_referencia,
                g.numero_pedido,
                g.serie_pedido,
                g.pedido_ref,
                g.cliente,
                g.prescritor,
                g.contato,
                g.status_principal,
                g.qtd_itens,
                g.valor_rejeitado,
                g.data_perda,
                COALESCE(a.motivo_perda, '') AS motivo_perda,
                COALESCE(a.classificacao_erro, 'nenhum') AS classificacao_erro,
                COALESCE(a.pontos_descontados, 0) AS pontos_descontados,
                COALESCE(a.observacoes, '') AS observacoes,
                COALESCE(a.updated_at, '') AS acao_atualizada_em
            FROM (
                SELECT
                    i.ano_referencia,
                    CAST(i.numero AS CHAR) AS numero_pedido,
                    '' AS serie_pedido,
                    CONCAT(CAST(i.numero AS CHAR), '/', i.ano_referencia) AS pedido_ref,
                    COALESCE(NULLIF(TRIM(MAX(gpLink.cliente)), ''), '(Sem cliente)') AS cliente,
                    COALESCE(NULLIF(TRIM(MAX(gpLink.prescritor)), ''), '(Sem prescritor)') AS prescritor,
                    '' AS contato,
                    CASE
                        WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(i.status, ''))) = 'recusado' THEN 1 ELSE 0 END) > 0 THEN 'Recusado'
                        WHEN SUM(CASE WHEN LOWER(TRIM(COALESCE(i.status, ''))) = 'no carrinho' THEN 1 ELSE 0 END) > 0 THEN 'No carrinho'
                        ELSE 'Outros'
                    END AS status_principal,
                    COUNT(*) AS qtd_itens,
                    ROUND(COALESCE(SUM(i.valor_liquido), 0), 2) AS valor_rejeitado,
                    DATE(MAX(i.data)) AS data_perda
                    {$baseAggSql}
                GROUP BY i.ano_referencia, i.numero
            ) g
            LEFT JOIN vendedor_perdas_acoes a
              ON a.ano_referencia = g.ano_referencia
             AND a.numero_pedido = g.numero_pedido
             AND COALESCE(a.serie_pedido, '') = ''
             AND a.vendedor_nome = :vend_join
            {$outerFilterSql}
            ORDER BY {$orderExpr} {$sortDir}, g.numero_pedido DESC
            LIMIT :lim OFFSET :off
        ";
        $stRows = $pdo->prepare($sqlRows);
        $stRows->bindValue(':de', $dataDe);
        $stRows->bindValue(':ate', $dataAte);
        $stRows->bindValue(':vend', $vendedor);
        $stRows->bindValue(':vend_join', $vendedor);
        if ($qLike !== '') $stRows->bindValue(':q', $qLike);
        $stRows->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stRows->bindValue(':off', $offset, PDO::PARAM_INT);
        $stRows->execute();
        $rows = $stRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $pages = $perPage > 0 ? (int)ceil($total / $perPage) : 0;
    echo json_encode([
        'success' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'vendedor' => $vendedor,
        'sort' => ['by' => $sortByRaw, 'dir' => strtolower($sortDir)],
        'resumo' => [
            'total_pedidos' => $total,
            'total_valor_rejeitado' => $totalValorRejeitado,
            'qtd_recusado' => $qtdRecusado,
            'qtd_no_carrinho' => $qtdNoCarrinho,
            'qtd_com_acao' => $qtdComAcao,
            'qtd_sem_acao' => $qtdSemAcao,
            'ticket_medio_rejeitado' => $ticketMedioRejeitado,
        ],
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages],
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
}

function handleVendedorPerdasSalvarAcao(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao setor vendedor ou administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) $payload = [];

    $anoRef = (int)($payload['ano_referencia'] ?? 0);
    $numeroPedido = trim((string)($payload['numero_pedido'] ?? ''));
    // AÃ§Ã£o de perdas Ã© sempre por pedido unificado (nÃºmero/ano), independente de sÃ©rie.
    $seriePedido = '';
    $dataPerda = trim((string)($payload['data_perda'] ?? ''));
    $motivoPerda = trim((string)($payload['motivo_perda'] ?? ''));
    $classificacao = strtolower(trim((string)($payload['classificacao_erro'] ?? 'nenhum')));
    $observacoes = trim((string)($payload['observacoes'] ?? ''));
    $pontosRaw = $payload['pontos_descontados'] ?? null;

    if ($anoRef <= 0 || $numeroPedido === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Pedido invÃ¡lido para salvar aÃ§Ã£o.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!in_array($classificacao, ['nenhum', 'leve', 'grave'], true)) {
        $classificacao = 'nenhum';
    }

    $pontos = is_numeric($pontosRaw) ? (float)$pontosRaw : null;
    if ($pontos === null) {
        if ($classificacao === 'leve') $pontos = 1.0;
        elseif ($classificacao === 'grave') $pontos = 2.0;
        else $pontos = 0.0;
    }
    $pontos = max(0.0, min(20.0, round($pontos, 2)));

    $isDate = static function ($v) {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v);
    };
    if (!$isDate($dataPerda)) {
        $dataPerda = null;
    }

    if (mb_strlen($motivoPerda) > 255) $motivoPerda = mb_substr($motivoPerda, 0, 255);
    if (mb_strlen($observacoes) > 3000) $observacoes = mb_substr($observacoes, 0, 3000);

    $vendedorNome = gcResolveVendedorTargetFromSession($_SESSION, $payload);
    if ($vendedorNome === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Vendedor nÃ£o identificado para salvar aÃ§Ã£o.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    gcEnsureVendedorPerdasAcoesTable($pdo);

    $sql = "
        INSERT INTO vendedor_perdas_acoes (
            ano_referencia, numero_pedido, serie_pedido, vendedor_nome, data_perda,
            motivo_perda, classificacao_erro, pontos_descontados, observacoes, updated_by
        ) VALUES (
            :ano, :num, :serie, :vend, :data_perda,
            :motivo, :classif, :pontos, :obs, :user_id
        )
        ON DUPLICATE KEY UPDATE
            data_perda = VALUES(data_perda),
            motivo_perda = VALUES(motivo_perda),
            classificacao_erro = VALUES(classificacao_erro),
            pontos_descontados = VALUES(pontos_descontados),
            observacoes = VALUES(observacoes),
            updated_by = VALUES(updated_by)
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        'ano' => $anoRef,
        'num' => $numeroPedido,
        'serie' => $seriePedido,
        'vend' => $vendedorNome,
        'data_perda' => $dataPerda,
        'motivo' => $motivoPerda !== '' ? $motivoPerda : null,
        'classif' => $classificacao,
        'pontos' => $pontos,
        'obs' => $observacoes !== '' ? $observacoes : null,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
    ]);

    echo json_encode([
        'success' => true,
        'saved' => [
            'ano_referencia' => $anoRef,
            'numero_pedido' => $numeroPedido,
            'serie_pedido' => $seriePedido,
            'vendedor_nome' => $vendedorNome,
            'data_perda' => $dataPerda,
            'motivo_perda' => $motivoPerda,
            'classificacao_erro' => $classificacao,
            'pontos_descontados' => $pontos,
            'observacoes' => $observacoes,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function handleVendedorPerdasInteracoesLista(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao setor vendedor ou administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $anoRef = (int)($_GET['ano_referencia'] ?? 0);
    $numeroPedido = trim((string)($_GET['numero_pedido'] ?? ''));
    $seriePedido = '';
    if ($anoRef <= 0 || $numeroPedido === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Pedido invÃ¡lido para listar tentativas.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $vendedorNome = gcResolveVendedorTargetFromSession($_SESSION, $_GET);
    if ($vendedorNome === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Vendedor nÃ£o identificado.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    gcEnsureVendedorPerdasInteracoesTable($pdo);
    $st = $pdo->prepare("
        SELECT
            id, ano_referencia, numero_pedido, serie_pedido, vendedor_nome,
            tipo_contato, status_tentativa, mensagem, proximo_passo,
            DATE_FORMAT(data_contato, '%Y-%m-%d %H:%i:%s') AS data_contato,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
        FROM vendedor_perdas_interacoes
        WHERE ano_referencia = :ano
          AND numero_pedido = :num
          AND COALESCE(serie_pedido, '') = :serie
          AND vendedor_nome = :vend
        ORDER BY data_contato DESC, id DESC
        LIMIT 200
    ");
    $st->execute([
        'ano' => $anoRef,
        'num' => $numeroPedido,
        'serie' => $seriePedido,
        'vend' => $vendedorNome,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'total' => count($rows),
    ], JSON_UNESCAPED_UNICODE);
}

function handleVendedorPerdasInteracoesSalvar(PDO $pdo): void
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    if ($tipo !== 'admin' && strpos($setor, 'vendedor') === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao setor vendedor ou administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) $payload = [];
    $anoRef = (int)($payload['ano_referencia'] ?? 0);
    $numeroPedido = trim((string)($payload['numero_pedido'] ?? ''));
    $seriePedido = '';
    $tipoContato = strtolower(trim((string)($payload['tipo_contato'] ?? 'whatsapp')));
    $statusTentativa = strtolower(trim((string)($payload['status_tentativa'] ?? 'retorno_pendente')));
    $mensagem = trim((string)($payload['mensagem'] ?? ''));
    $proximoPasso = trim((string)($payload['proximo_passo'] ?? ''));
    $dataContato = trim((string)($payload['data_contato'] ?? ''));

    if ($anoRef <= 0 || $numeroPedido === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Pedido invÃ¡lido para salvar tentativa.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $tiposValidos = ['whatsapp', 'telefone', 'email', 'outro'];
    if (!in_array($tipoContato, $tiposValidos, true)) $tipoContato = 'whatsapp';
    $statusValidos = ['sem_resposta', 'retorno_pendente', 'negociando', 'recuperado', 'perdido'];
    if (!in_array($statusTentativa, $statusValidos, true)) $statusTentativa = 'retorno_pendente';
    if (mb_strlen($mensagem) > 3000) $mensagem = mb_substr($mensagem, 0, 3000);
    if (mb_strlen($proximoPasso) > 255) $proximoPasso = mb_substr($proximoPasso, 0, 255);
    $isDateTime = static function ($v) {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}(?::\d{2})?)?$/', (string)$v);
    };
    if (!$isDateTime($dataContato)) $dataContato = date('Y-m-d H:i:s');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataContato)) $dataContato .= ' 00:00:00';

    $vendedorNome = gcResolveVendedorTargetFromSession($_SESSION, $payload);
    if ($vendedorNome === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Vendedor nÃ£o identificado.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    gcEnsureVendedorPerdasInteracoesTable($pdo);
    $st = $pdo->prepare("
        INSERT INTO vendedor_perdas_interacoes (
            ano_referencia, numero_pedido, serie_pedido, vendedor_nome,
            tipo_contato, status_tentativa, mensagem, proximo_passo, data_contato, created_by
        ) VALUES (
            :ano, :num, :serie, :vend,
            :tipo, :status, :msg, :passo, :data_contato, :user_id
        )
    ");
    $st->execute([
        'ano' => $anoRef,
        'num' => $numeroPedido,
        'serie' => $seriePedido,
        'vend' => $vendedorNome,
        'tipo' => $tipoContato,
        'status' => $statusTentativa,
        'msg' => $mensagem !== '' ? $mensagem : null,
        'passo' => $proximoPasso !== '' ? $proximoPasso : null,
        'data_contato' => $dataContato,
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
    ]);

    echo json_encode([
        'success' => true,
        'saved' => [
            'id' => (int)$pdo->lastInsertId(),
            'ano_referencia' => $anoRef,
            'numero_pedido' => $numeroPedido,
            'serie_pedido' => $seriePedido,
            'tipo_contato' => $tipoContato,
            'status_tentativa' => $statusTentativa,
            'mensagem' => $mensagem,
            'proximo_passo' => $proximoPasso,
            'data_contato' => $dataContato,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

// check_session: nÃ£o exige login, sÃ³ retorna estado da sessÃ£o
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
        echo json_encode(['success' => false, 'error' => 'Erro ao verificar sessÃ£o.'], JSON_UNESCAPED_UNICODE);
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

// logout: exige sessÃ£o, encerra e retorna
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

// MÃ©tricas completas do RD Station CRM (exige admin)
if ($action === 'gestao_rd_metricas') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdo = getConnection();
        $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
        if (!($sessionCheck['valid'] ?? true)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
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
                'error'   => 'RDSTATION_CRM_TOKEN nÃ£o configurado no .env. Configure para usar mÃ©tricas do RD Station CRM.',
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
                ? 'Erro ao buscar mÃ©tricas do RD Station.'
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

if ($action === 'vendedor_perdas_lista') {
    try {
        $pdo = getConnection();
        handleVendedorPerdasLista($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_perdas_lista: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao carregar pedidos perdidos.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_pedidos_lista') {
    try {
        $pdo = getConnection();
        handleVendedorPedidosLista($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_pedidos_lista: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao carregar pedidos do vendedor.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_perdas_salvar_acao') {
    try {
        $pdo = getConnection();
        handleVendedorPerdasSalvarAcao($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_perdas_salvar_acao: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao salvar aÃ§Ã£o da perda.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_perdas_interacoes_lista') {
    try {
        $pdo = getConnection();
        handleVendedorPerdasInteracoesLista($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_perdas_interacoes_lista: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao carregar tentativas de recuperaÃ§Ã£o.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_perdas_interacoes_salvar') {
    try {
        $pdo = getConnection();
        handleVendedorPerdasInteracoesSalvar($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_perdas_interacoes_salvar: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao salvar tentativa de recuperaÃ§Ã£o.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_dashboard_gestao') {
    try {
        $pdo = getConnection();
        handleVendedorDashboardGestao($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_dashboard_gestao: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'fonte'   => null,
            'error'   => (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao carregar dados da gestÃ£o de pedidos.'
                : $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_comissao_transfer_lista') {
    try {
        $pdo = getConnection();
        handleVendedorComissaoTransferLista($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_comissao_transfer_lista: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao listar solicitações.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_comissao_transfer_opcoes') {
    try {
        $pdo = getConnection();
        handleVendedorComissaoTransferOpcoes($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_comissao_transfer_opcoes: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao carregar consultoras.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_comissao_transfer_solicitar') {
    try {
        $pdo = getConnection();
        handleVendedorComissaoTransferSolicitar($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_comissao_transfer_solicitar: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao registrar solicitação.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_comissao_transfer_cancelar') {
    try {
        $pdo = getConnection();
        handleVendedorComissaoTransferCancelar($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_comissao_transfer_cancelar: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao cancelar.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_comissao_transfer_editar') {
    try {
        $pdo = getConnection();
        handleVendedorComissaoTransferEditar($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_comissao_transfer_editar: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao editar.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_comissao_transfer_buscar_pedido') {
    try {
        $pdo = getConnection();
        handleVendedorComissaoTransferBuscarPedido($pdo);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao buscar pedido.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_revenda_lista') {
    try {
        $pdo = getConnection();
        handleVendedorRevendaLista($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_revenda_lista: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao listar revenda.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_revenda_lancar') {
    try {
        $pdo = getConnection();
        handleVendedorRevendaLancar($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_revenda_lancar: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao registrar revenda.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'vendedor_revenda_cancelar') {
    try {
        $pdo = getConnection();
        handleVendedorRevendaCancelar($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('vendedor_revenda_cancelar: ' . $e->getMessage());
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao cancelar revenda.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// TV corrida: leitura pÃºblica para monitor/tela (nÃ£o expira sessÃ£o/senha)
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

// AÃ§Ãµes de gestÃ£o: exigem sessÃ£o e admin (validado dentro do mÃ³dulo)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'NÃ£o autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getConnection();
    $sessionCheck = gcEnsureSessionIsValidOrRepair($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['error' => 'SessÃ£o encerrada. FaÃ§a login novamente.'], JSON_UNESCAPED_UNICODE);
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
        ? 'Erro interno na gestÃ£o comercial.'
        : $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}

