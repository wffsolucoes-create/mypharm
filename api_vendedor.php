<?php
/**
 * API exclusiva da página do Vendedor.
 * Retorna apenas dados do usuário logado (metas e progresso).
 */
ini_set('display_errors', '0');
if (function_exists('ini_set')) {
    @ini_set('log_errors', '1');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/modules/gestao_comercial.php';

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
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';
$allowedActions = ['check_session', 'logout', 'vendedor_metas'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function isVendedorSession(): bool
{
    $setor = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
    $tipo = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
    return strpos($setor, 'vendedor') !== false || $tipo === 'admin';
}

function getMetaIndividualRules(): array
{
    return [
        ['min' => 0.0, 'max' => 40709.00, 'comissao_percentual' => 0.5, 'label' => 'Até R$ 40.709,00 (69%)'],
        ['min' => 40710.00, 'max' => 52510.00, 'comissao_percentual' => 1.0, 'label' => 'R$ 40.710,00 a R$ 52.510,00 (70%-89%)'],
        ['min' => 52511.00, 'max' => 58409.00, 'comissao_percentual' => 1.3, 'label' => 'R$ 52.511,00 a R$ 58.409,00 (90%-99%)'],
        ['min' => 59000.00, 'max' => 64309.00, 'comissao_percentual' => 1.8, 'label' => 'R$ 59.000,00 a R$ 64.309,00 (100%-109%)'],
        ['min' => 70000.00, 'max' => null, 'comissao_percentual' => 2.0, 'label' => 'Acima de R$ 70.000,00 (110%+)'],
    ];
}

function getMetaGrupoRules(): array
{
    return [
        ['min' => 320000.00, 'max' => 349999.99, 'percentual' => 1.5, 'label' => '320k a 349k'],
        ['min' => 350000.00, 'max' => 369999.99, 'percentual' => 2.0, 'label' => '350k a 369k'],
        ['min' => 370000.00, 'max' => null, 'percentual' => 2.5, 'label' => '370k+'],
    ];
}

function detectFaixaByValue(array $rules, float $value): ?array
{
    foreach ($rules as $rule) {
        $min = (float)($rule['min'] ?? 0);
        $max = $rule['max'];
        if ($value >= $min && ($max === null || $value <= (float)$max)) {
            return $rule;
        }
    }
    return null;
}

function allowedVendedoras(): array
{
    return [
        'Nailena',
        'Mariana',
        'Jessica Vitoria',
        'Carla',
        'Nereida',
        'Giovanna',
        'Clara Leticia',
        'Ananda Reis',
    ];
}

function normalizeSimple(string $v): string
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
    if (function_exists('mb_strtolower')) return mb_strtolower($v, 'UTF-8');
    return strtolower($v);
}

function isAllowedVendedoraName(string $nome): bool
{
    $k = normalizeSimple($nome);
    if ($k === '') return false;
    foreach (allowedVendedoras() as $n) {
        if (normalizeSimple($n) === $k) return true;
    }
    return false;
}

if ($action === 'check_session') {
    echo json_encode([
        'logged_in' => isset($_SESSION['user_id']),
        'nome' => $_SESSION['user_nome'] ?? null,
        'tipo' => $_SESSION['user_tipo'] ?? null,
        'setor' => $_SESSION['user_setor'] ?? null,
        'csrf_token' => getCsrfToken(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'logout') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdo = getConnection();
        $logoutUserId = (int)($_SESSION['user_id'] ?? 0);
        $logoutNome = $_SESSION['user_nome'] ?? null;
        $logoutUsuario = null;
        if ($logoutUserId > 0) {
            try {
                $stUser = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = :id LIMIT 1");
                $stUser->execute(['id' => $logoutUserId]);
                $logoutUsuario = $stUser->fetchColumn() ?: null;
            } catch (Throwable $e) {}
            removeUserSession($pdo, $logoutUserId);
        }
        logAuthEvent($pdo, 'logout', true, [
            'user_id' => $logoutUserId > 0 ? $logoutUserId : null,
            'usuario' => $logoutUsuario,
            'nome_usuario' => $logoutNome
        ]);
    } catch (Throwable $e) {}

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getConnection();
    $sessionCheck = validateAndRefreshUserSession($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isVendedorSession()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso permitido somente ao perfil de vendedor.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'vendedor_metas') {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $userNome = trim((string)($_SESSION['user_nome'] ?? ''));

        $stmt = $pdo->prepare("
            SELECT
                id,
                nome,
                COALESCE(meta_mensal, 0) AS meta_mensal,
                COALESCE(meta_anual, 0) AS meta_anual,
                COALESCE(comissao_percentual, 0) AS comissao_percentual,
                COALESCE(meta_visitas_semana, 0) AS meta_visitas_semana,
                COALESCE(meta_visitas_mes, 0) AS meta_visitas_mes,
                COALESCE(premio_visitas, 0) AS premio_visitas
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
        $metaRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $hoje = new DateTimeImmutable('today');
        $inicioMes = $hoje->modify('first day of this month')->format('Y-m-d');
        $fimMes = $hoje->modify('last day of this month')->format('Y-m-d');
        $inicioAno = $hoje->format('Y-01-01');
        $fimAno = $hoje->format('Y-12-31');
        $inicioSemana = $hoje->modify('monday this week')->format('Y-m-d');
        $fimSemana = $hoje->modify('sunday this week')->format('Y-m-d');

        $progresso = [
            'faturamento_mes' => 0.0,
            'faturamento_ano' => 0.0,
            'visitas_semana' => 0,
            'visitas_mes' => 0,
        ];

        if ($userNome !== '') {
            try {
                $stFatMes = $pdo->prepare("
                    SELECT COALESCE(SUM(preco_liquido), 0) AS total
                    FROM gestao_pedidos
                    WHERE DATE(data_aprovacao) BETWEEN :ini AND :fim
                      AND TRIM(COALESCE(atendente, '')) = :nome
                      AND (
                        status_financeiro IS NULL OR
                        (
                            status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND status_financeiro NOT LIKE '%carrinho%'
                        )
                      )
                ");
                $stFatMes->execute(['ini' => $inicioMes, 'fim' => $fimMes, 'nome' => $userNome]);
                $progresso['faturamento_mes'] = (float)($stFatMes->fetchColumn() ?: 0);

                $stFatAno = $pdo->prepare("
                    SELECT COALESCE(SUM(preco_liquido), 0) AS total
                    FROM gestao_pedidos
                    WHERE DATE(data_aprovacao) BETWEEN :ini AND :fim
                      AND TRIM(COALESCE(atendente, '')) = :nome
                      AND (
                        status_financeiro IS NULL OR
                        (
                            status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND status_financeiro NOT LIKE '%carrinho%'
                        )
                      )
                ");
                $stFatAno->execute(['ini' => $inicioAno, 'fim' => $fimAno, 'nome' => $userNome]);
                $progresso['faturamento_ano'] = (float)($stFatAno->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                // Sem regressão se tabela não existir.
            }

            $revMes = 0.0;
            $revAno = 0.0;
            if (function_exists('gcRevendaAggRows')) {
                $me = normalizeSimple($userNome);
                if ($me !== '') {
                    foreach (gcRevendaAggRows($pdo, $inicioMes, $fimMes) as $rr) {
                        if (normalizeSimple((string)($rr['nome'] ?? '')) === $me) {
                            $revMes = (float)($rr['total'] ?? 0);
                            break;
                        }
                    }
                    foreach (gcRevendaAggRows($pdo, $inicioAno, $fimAno) as $rr) {
                        if (normalizeSimple((string)($rr['nome'] ?? '')) === $me) {
                            $revAno = (float)($rr['total'] ?? 0);
                            break;
                        }
                    }
                }
            }
            $progresso['faturamento_mes'] = round((float)$progresso['faturamento_mes'] + $revMes, 2);
            $progresso['faturamento_ano'] = round((float)$progresso['faturamento_ano'] + $revAno, 2);

            try {
                $stVisSemana = $pdo->prepare("
                    SELECT COALESCE(COUNT(*), 0)
                    FROM historico_visitas
                    WHERE DATE(data_visita) BETWEEN :ini AND :fim
                      AND TRIM(COALESCE(visitador, '')) = :nome
                ");
                $stVisSemana->execute(['ini' => $inicioSemana, 'fim' => $fimSemana, 'nome' => $userNome]);
                $progresso['visitas_semana'] = (int)($stVisSemana->fetchColumn() ?: 0);

                $stVisMes = $pdo->prepare("
                    SELECT COALESCE(COUNT(*), 0)
                    FROM historico_visitas
                    WHERE DATE(data_visita) BETWEEN :ini AND :fim
                      AND TRIM(COALESCE(visitador, '')) = :nome
                ");
                $stVisMes->execute(['ini' => $inicioMes, 'fim' => $fimMes, 'nome' => $userNome]);
                $progresso['visitas_mes'] = (int)($stVisMes->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                // Sem regressão se tabela não existir.
            }
        }

        $faturamentoGrupoMes = 0.0;
        try {
            $allowed = allowedVendedoras();
            $holders = [];
            $params = ['ini' => $inicioMes, 'fim' => $fimMes];
            foreach ($allowed as $idx => $nome) {
                $key = 'n' . $idx;
                $holders[] = ':' . $key;
                $params[$key] = $nome;
            }
            $inNames = implode(',', $holders);
            $stGrupoMes = $pdo->prepare("
                SELECT COALESCE(SUM(gp.preco_liquido), 0) AS total
                FROM gestao_pedidos gp
                INNER JOIN usuarios u
                    ON LOWER(TRIM(COALESCE(u.nome, ''))) = LOWER(TRIM(COALESCE(gp.atendente, '')))
                WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
                  AND LOWER(TRIM(COALESCE(u.setor, ''))) LIKE '%vendedor%'
                  AND TRIM(COALESCE(u.nome, '')) IN ({$inNames})
                  AND (
                    gp.status_financeiro IS NULL OR
                    (
                        gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                        AND gp.status_financeiro NOT LIKE '%carrinho%'
                    )
                  )
            ");
            $stGrupoMes->execute($params);
            $faturamentoGrupoMes = (float)($stGrupoMes->fetchColumn() ?: 0);
            if (function_exists('gcRevendaAggRows')) {
                $allowedNorm = [];
                foreach ($allowed as $nomeLista) {
                    $kn = normalizeSimple((string)$nomeLista);
                    if ($kn !== '') {
                        $allowedNorm[$kn] = true;
                    }
                }
                foreach (gcRevendaAggRows($pdo, $inicioMes, $fimMes) as $rr) {
                    $kn = normalizeSimple((string)($rr['nome'] ?? ''));
                    if ($kn !== '' && isset($allowedNorm[$kn])) {
                        $faturamentoGrupoMes += (float)($rr['total'] ?? 0);
                    }
                }
                $faturamentoGrupoMes = round($faturamentoGrupoMes, 2);
            }
        } catch (Throwable $e) {
            // Sem regressão se tabela não existir.
        }

        $metaIndividualRules = getMetaIndividualRules();
        $metaGrupoRules = getMetaGrupoRules();
        $faixaIndividual = detectFaixaByValue($metaIndividualRules, (float)$progresso['faturamento_mes']);
        $faixaGrupo = detectFaixaByValue($metaGrupoRules, $faturamentoGrupoMes);

        $comissaoIndividualPct = (float)($faixaIndividual['comissao_percentual'] ?? 0);
        // Regra de transição: entre 64.309 e 70.000 mantém comissão de 1,8%.
        if ($comissaoIndividualPct <= 0 && (float)$progresso['faturamento_mes'] >= 64310.00 && (float)$progresso['faturamento_mes'] < 70000.00) {
            $comissaoIndividualPct = 1.8;
        }
        $comissaoGrupoPct = (float)($faixaGrupo['percentual'] ?? 0);
        $comissaoTotalPct = $comissaoIndividualPct + $comissaoGrupoPct;
        $comissaoEstimadaValor = round(((float)$progresso['faturamento_mes']) * ($comissaoTotalPct / 100), 2);

        $metaMensal = (float)($metaRow['meta_mensal'] ?? 0);
        $percentMetaMensal = $metaMensal > 0 ? round((((float)$progresso['faturamento_mes']) / $metaMensal) * 100, 2) : 0.0;

        $score = [
            'faturamento' => max(0, min(50, (float)($_GET['score_faturamento'] ?? 0))),
            'conversao' => max(0, min(20, (float)($_GET['score_conversao'] ?? 0))),
            'erros' => max(0, min(20, (float)($_GET['score_erros'] ?? 0))),
            'organizacao_crm' => max(0, min(10, (float)($_GET['score_organizacao_crm'] ?? 0))),
        ];
        $scoreTotal = (float)$score['faturamento'] + (float)$score['conversao'] + (float)$score['erros'] + (float)$score['organizacao_crm'];
        $bateuMeta = $percentMetaMensal >= 100;
        $ultrapassouMeta = $percentMetaMensal >= 110;
        $premioEstimado = 0.0;
        if ($ultrapassouMeta && $scoreTotal > 95) {
            $premioEstimado = 400.0;
        } elseif ($bateuMeta && $scoreTotal > 85) {
            $premioEstimado = 200.0;
        }

        echo json_encode([
            'success' => true,
            'vendedor' => [
                'id' => (int)($metaRow['id'] ?? $userId),
                'nome' => (string)($metaRow['nome'] ?? $userNome),
            ],
            'metas' => [
                'meta_mensal' => (float)($metaRow['meta_mensal'] ?? 0),
                'meta_anual' => (float)($metaRow['meta_anual'] ?? 0),
                'comissao_percentual' => (float)($metaRow['comissao_percentual'] ?? 0),
                'meta_visitas_semana' => (int)($metaRow['meta_visitas_semana'] ?? 0),
                'meta_visitas_mes' => (int)($metaRow['meta_visitas_mes'] ?? 0),
                'premio_visitas' => (float)($metaRow['premio_visitas'] ?? 0),
            ],
            'progresso' => $progresso,
            'regras' => [
                'meta_individual' => $metaIndividualRules,
                'meta_grupo' => $metaGrupoRules,
                'score' => [
                    ['item' => 'Faturamento', 'max_pontos' => 50],
                    ['item' => 'Conversão', 'max_pontos' => 20],
                    ['item' => 'Erros', 'max_pontos' => 20],
                    ['item' => 'Organização / CRM', 'max_pontos' => 10],
                ],
                'premio_performance' => [
                    ['regra' => 'Bate meta + score acima de 85', 'premio' => 200],
                    ['regra' => 'Ultrapassa meta + score acima de 95', 'premio' => 400],
                ],
            ],
            'calculos' => [
                'percentual_meta_mensal' => $percentMetaMensal,
                'faturamento_grupo_mes' => round($faturamentoGrupoMes, 2),
                'faixa_individual' => $faixaIndividual['label'] ?? 'Sem faixa definida',
                'faixa_grupo' => $faixaGrupo['label'] ?? 'Sem faixa ativa',
                'comissao_individual_percentual' => $comissaoIndividualPct,
                'comissao_grupo_percentual' => $comissaoGrupoPct,
                'comissao_total_percentual' => $comissaoTotalPct,
                'comissao_estimada_valor' => $comissaoEstimadaValor,
                'score' => $score,
                'score_total' => $scoreTotal,
                'premio_estimado' => $premioEstimado,
                'bateu_meta' => $bateuMeta,
                'ultrapassou_meta' => $ultrapassouMeta,
            ],
            'periodo' => [
                'mes_inicio' => $inicioMes,
                'mes_fim' => $fimMes,
                'ano_inicio' => $inicioAno,
                'ano_fim' => $fimAno,
                'semana_inicio' => $inicioSemana,
                'semana_fim' => $fimSemana,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('api_vendedor.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    http_response_code(500);
    $msg = (defined('IS_PRODUCTION') && IS_PRODUCTION)
        ? 'Erro interno do módulo de vendedor.'
        : $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE);
