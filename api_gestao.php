<?php
/**
 * API exclusiva da Gestão Comercial.
 * Tudo que corresponda à gestão comercial usa esta API (sessão, logout, dashboard, lista vendedores).
 */
ini_set('display_errors', '0');
if (function_exists('ini_set')) {
    @ini_set('log_errors', '1');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/modules/gestao_comercial.php';

header('Content-Type: application/json; charset=utf-8');

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
    'gestao_comercial_dashboard',
    'gestao_comercial_lista_vendedores',
    'tv_corrida_vendedores',
];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function handleTvCorridaVendedores(PDO $pdo): void
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
            'vendedor' => $nome,
            'receita' => (float)($r['receita'] ?? 0),
            'meta_mensal' => 0.0,
        ];
    }
    foreach ($vendedoresCad as $v) {
        $nome = trim((string)($v['nome'] ?? ''));
        if ($nome === '') continue;
        if (function_exists('gcIsAllowedVendedora') && !gcIsAllowedVendedora($nome)) continue;
        $k = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
        if (!isset($map[$k])) {
            $map[$k] = ['vendedor' => $nome, 'receita' => 0.0, 'meta_mensal' => 0.0];
        }
        $map[$k]['meta_mensal'] = (float)($v['meta_mensal'] ?? 0);
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
    }
    unset($it);

    echo json_encode([
        'success' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'max_receita' => round($maxReceita, 2),
        'ranking' => $lista,
        'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
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
    $sessionCheck = validateAndRefreshUserSession($pdo);
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
