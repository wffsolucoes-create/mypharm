<?php
ini_set('display_errors', '0');
if (function_exists('ini_set')) {
    @ini_set('log_errors', '1');
}
require_once __DIR__ . '/config.php';

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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function bonusNormalize(string $value): string
{
    $v = trim($value);
    $map = [
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
    ];
    $v = strtr($v, $map);
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}

function bonusIsEligibleVisitador(?string $visitador): bool
{
    $v = bonusNormalize((string)$visitador);
    if ($v === '') return false;
    return strpos($v, 'aulis') !== false || strpos($v, 'priscila') !== false;
}

function bonusCanDebit(string $setor, string $tipo): bool
{
    $s = bonusNormalize($setor);
    $t = bonusNormalize($tipo);
    if ($t === 'admin') return true;
    return strpos($s, 'visitador') !== false
        || strpos($s, 'gerente') !== false
        || strpos($s, 'vendedor') !== false;
}

function bonusInitTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_creditos_mensais (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        prescritor VARCHAR(255) NOT NULL,
        visitador VARCHAR(150) NULL,
        competencia_ano INT NOT NULL,
        competencia_mes TINYINT NOT NULL,
        referencia_ano INT NOT NULL,
        referencia_mes TINYINT NOT NULL,
        valor_base DECIMAL(14,2) NOT NULL DEFAULT 0,
        percentual DECIMAL(6,4) NOT NULL DEFAULT 0.2000,
        valor_credito DECIMAL(14,2) NOT NULL DEFAULT 0,
        gerado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL,
        INDEX idx_prescritor (prescritor(120)),
        INDEX idx_competencia (competencia_ano, competencia_mes),
        UNIQUE KEY uq_presc_comp (prescritor(120), competencia_ano, competencia_mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_debitos (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        prescritor VARCHAR(255) NOT NULL,
        visitador VARCHAR(150) NULL,
        numero_pedido INT NOT NULL DEFAULT 0,
        serie_pedido INT NOT NULL DEFAULT 0,
        valor_debito DECIMAL(14,2) NOT NULL,
        data_debito DATE NOT NULL,
        usuario_id INT NOT NULL,
        usuario_nome VARCHAR(255) NOT NULL,
        observacao VARCHAR(500) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prescritor (prescritor(120)),
        INDEX idx_data (data_debito),
        INDEX idx_usuario (usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_meta (
        meta_key VARCHAR(120) PRIMARY KEY,
        meta_value VARCHAR(255) NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function bonusShouldRefreshCredits(PDO $pdo, DateTime $now, int $ttlMinutes = 120): bool
{
    $st = $pdo->prepare("SELECT meta_value FROM prescritor_bonus_meta WHERE meta_key = 'last_generation_run' LIMIT 1");
    $st->execute();
    $last = trim((string)($st->fetchColumn() ?: ''));
    if ($last === '') {
        return true;
    }
    try {
        $lastDt = new DateTime($last, new DateTimeZone('America/Porto_Velho'));
    } catch (Throwable $e) {
        return true;
    }
    $diff = $now->getTimestamp() - $lastDt->getTimestamp();
    return $diff >= ($ttlMinutes * 60);
}

function bonusMarkCreditsRefreshed(PDO $pdo, DateTime $now): void
{
    $st = $pdo->prepare("
        INSERT INTO prescritor_bonus_meta (meta_key, meta_value, atualizado_em)
        VALUES ('last_generation_run', :v, NOW())
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), atualizado_em = NOW()
    ");
    $st->execute(['v' => $now->format('Y-m-d H:i:s')]);
}

function bonusUpsertCreditsByReference(
    PDO $pdo,
    int $competenciaAno,
    int $competenciaMes,
    int $refAno,
    int $refMes,
    string $refIni,
    string $refFim
): void {
    $fimMaisUm = $refFim;
    try {
        $fimMaisUm = (new DateTime($refFim, new DateTimeZone('America/Porto_Velho')))
            ->modify('+1 day')
            ->format('Y-m-d');
    } catch (Throwable $e) {
        $fimMaisUm = $refFim;
    }

    $sql = "
        SELECT
            pc.nome AS prescritor,
            pc.visitador AS visitador,
            COALESCE(SUM(gp.preco_liquido), 0) AS valor_base
        FROM prescritores_cadastro pc
        LEFT JOIN gestao_pedidos gp
            ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome
            AND gp.data_aprovacao IS NOT NULL
            AND gp.data_aprovacao >= :d1
            AND gp.data_aprovacao < :d2_next
            AND (
                gp.status_financeiro IS NULL
                OR (
                    gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                    AND gp.status_financeiro NOT LIKE '%carrinho%'
                )
            )
        GROUP BY pc.nome, pc.visitador
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['d1' => $refIni, 'd2_next' => $fimMaisUm]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare("
        INSERT INTO prescritor_bonus_creditos_mensais
            (prescritor, visitador, competencia_ano, competencia_mes, referencia_ano, referencia_mes, valor_base, percentual, valor_credito, gerado_em, atualizado_em)
        VALUES
            (:prescritor, :visitador, :comp_ano, :comp_mes, :ref_ano, :ref_mes, :valor_base, 0.2000, :valor_credito, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            visitador = VALUES(visitador),
            referencia_ano = VALUES(referencia_ano),
            referencia_mes = VALUES(referencia_mes),
            valor_base = VALUES(valor_base),
            valor_credito = VALUES(valor_credito),
            atualizado_em = NOW()
    ");

    foreach ($rows as $r) {
        $visitador = (string)($r['visitador'] ?? '');
        if (!bonusIsEligibleVisitador($visitador)) {
            continue;
        }
        $valorBase = (float)($r['valor_base'] ?? 0);
        $valorCredito = round($valorBase * 0.20, 2);
        $ins->execute([
            'prescritor' => (string)($r['prescritor'] ?? ''),
            'visitador' => $visitador,
            'comp_ano' => $competenciaAno,
            'comp_mes' => $competenciaMes,
            'ref_ano' => $refAno,
            'ref_mes' => $refMes,
            'valor_base' => $valorBase,
            'valor_credito' => $valorCredito,
        ]);
    }
}

function bonusGenerateMonthlyCredits(PDO $pdo): void
{
    $now = new DateTime('now', new DateTimeZone('America/Porto_Velho'));
    $competenciaAtualAno = (int)$now->format('Y');
    $competenciaAtualMes = (int)$now->format('n');
    $competenciaProx = (clone $now)->modify('first day of next month');
    $competenciaProxAno = (int)$competenciaProx->format('Y');
    $competenciaProxMes = (int)$competenciaProx->format('n');

    $refAnterior = (clone $now)->modify('first day of last month');
    $refAnteriorAno = (int)$refAnterior->format('Y');
    $refAnteriorMes = (int)$refAnterior->format('n');
    $refAnteriorIni = $refAnterior->format('Y-m-01');
    $refAnteriorFim = $refAnterior->format('Y-m-t');

    $refAtualAno = (int)$now->format('Y');
    $refAtualMes = (int)$now->format('n');
    $refAtualIni = $now->format('Y-m-01');
    $refAtualFim = $now->format('Y-m-d');

    // Crédito disponível no mês atual (base do mês anterior).
    bonusUpsertCreditsByReference(
        $pdo,
        $competenciaAtualAno,
        $competenciaAtualMes,
        $refAnteriorAno,
        $refAnteriorMes,
        $refAnteriorIni,
        $refAnteriorFim
    );

    // Crédito a bonificar para o próximo mês (base do mês atual acumulado até hoje).
    bonusUpsertCreditsByReference(
        $pdo,
        $competenciaProxAno,
        $competenciaProxMes,
        $refAtualAno,
        $refAtualMes,
        $refAtualIni,
        $refAtualFim
    );
}

function bonusGetResumoForNames(PDO $pdo, array $prescritores): array
{
    $result = [];
    $clean = [];
    $normalizedToOriginals = [];
    foreach ($prescritores as $p) {
        $v = trim((string)$p);
        if ($v === '') {
            continue;
        }
        $clean[] = $v;
        $norm = bonusNormalize($v);
        if ($norm !== '') {
            if (!isset($normalizedToOriginals[$norm])) {
                $normalizedToOriginals[$norm] = [];
            }
            $normalizedToOriginals[$norm][] = $v;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === []) return $result;

    $chunkSize = 200;
    foreach (array_chunk($clean, $chunkSize) as $chunk) {
        $chunkNorm = array_map(static function ($name) {
            return bonusNormalize((string)$name);
        }, $chunk);
        $in = implode(',', array_fill(0, count($chunkNorm), '?'));
        $sql = "
            SELECT
                q.prescritor,
                q.visitador,
                q.total_credito,
                q.total_debito,
                (q.total_credito - q.total_debito) AS saldo
            FROM (
                SELECT
                    pc.nome AS prescritor,
                    pc.visitador AS visitador,
                    COALESCE(c.total_credito, 0) AS total_credito,
                    COALESCE(d.total_debito, 0) AS total_debito
                FROM prescritores_cadastro pc
                LEFT JOIN (
                    SELECT prescritor, SUM(valor_credito) AS total_credito
                    FROM prescritor_bonus_creditos_mensais
                    GROUP BY prescritor
                ) c ON c.prescritor = pc.nome
                LEFT JOIN (
                    SELECT prescritor, SUM(valor_debito) AS total_debito
                    FROM prescritor_bonus_debitos
                    GROUP BY prescritor
                ) d ON d.prescritor = pc.nome
                WHERE LOWER(TRIM(pc.nome)) IN ($in)
            ) q
        ";
        $st = $pdo->prepare($sql);
        $st->execute($chunkNorm);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = trim((string)$r['prescritor']);
            $payload = [
                'prescritor' => $key,
                'visitador' => (string)($r['visitador'] ?? ''),
                'bonificacao_eligivel' => bonusIsEligibleVisitador((string)($r['visitador'] ?? '')),
                'saldo_bonus' => (float)($r['saldo'] ?? 0),
                'total_credito' => (float)($r['total_credito'] ?? 0),
                'total_debito' => (float)($r['total_debito'] ?? 0),
            ];
            $result[$key] = $payload;

            $keyNorm = bonusNormalize($key);
            if ($keyNorm !== '' && isset($normalizedToOriginals[$keyNorm])) {
                foreach ($normalizedToOriginals[$keyNorm] as $originalName) {
                    $result[$originalName] = $payload;
                }
            }
        }
    }
    return $result;
}

function bonusGetExtrato(PDO $pdo, string $prescritor): array
{
    $out = [
        'resumo' => [
            'prescritor' => $prescritor,
            'visitador' => '',
            'bonificacao_eligivel' => false,
            'saldo_bonus' => 0.0,
            'total_credito' => 0.0,
            'total_debito' => 0.0,
        ],
        'creditos' => [],
        'debitos' => [],
    ];
    $map = bonusGetResumoForNames($pdo, [$prescritor]);
    if (isset($map[$prescritor])) {
        $out['resumo'] = $map[$prescritor];
    }
    $stC = $pdo->prepare("
        SELECT competencia_ano, competencia_mes, referencia_ano, referencia_mes, valor_base, percentual, valor_credito, gerado_em
        FROM prescritor_bonus_creditos_mensais
        WHERE prescritor = :p
        ORDER BY competencia_ano DESC, competencia_mes DESC
        LIMIT 24
    ");
    $stC->execute(['p' => $prescritor]);
    $out['creditos'] = $stC->fetchAll(PDO::FETCH_ASSOC);

    $stD = $pdo->prepare("
        SELECT id, numero_pedido, serie_pedido, valor_debito, data_debito, usuario_nome, criado_em, observacao
        FROM prescritor_bonus_debitos
        WHERE prescritor = :p
        ORDER BY data_debito DESC, id DESC
        LIMIT 50
    ");
    $stD->execute(['p' => $prescritor]);
    $out['debitos'] = $stD->fetchAll(PDO::FETCH_ASSOC);

    $tz = new DateTimeZone('America/Porto_Velho');
    $now = new DateTime('now', $tz);
    $prev = (clone $now)->modify('first day of last month');
    $next = (clone $now)->modify('first day of next month');
    $curAno = (int)$now->format('Y');
    $curMes = (int)$now->format('n');
    $nextAno = (int)$next->format('Y');
    $nextMes = (int)$next->format('n');

    $stCard = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN competencia_ano = :cur_ano AND competencia_mes = :cur_mes THEN valor_credito ELSE 0 END), 0) AS bonificacao_mes_anterior,
            COALESCE(SUM(CASE WHEN competencia_ano = :next_ano AND competencia_mes = :next_mes THEN valor_credito ELSE 0 END), 0) AS bonificacao_mes_atual_a_bonificar
        FROM prescritor_bonus_creditos_mensais
        WHERE prescritor = :prescritor
    ");
    $stCard->execute([
        'cur_ano' => $curAno,
        'cur_mes' => $curMes,
        'next_ano' => $nextAno,
        'next_mes' => $nextMes,
        'prescritor' => $prescritor,
    ]);
    $rowCard = $stCard->fetch(PDO::FETCH_ASSOC) ?: [];
    $out['resumo']['bonificacao_mes_anterior'] = round((float)($rowCard['bonificacao_mes_anterior'] ?? 0), 2);
    $out['resumo']['bonificacao_mes_atual_a_bonificar'] = round((float)($rowCard['bonificacao_mes_atual_a_bonificar'] ?? 0), 2);
    $out['resumo']['bonificacoes_utilizadas'] = (float)$out['resumo']['total_debito'];
    $out['resumo']['saldo_bonificacao'] = (float)$out['resumo']['saldo_bonus'];
    $out['resumo']['referencia_mes_anterior_label'] = $prev->format('m/Y');
    $out['resumo']['competencia_mes_atual_label'] = $now->format('m/Y');

    return $out;
}

function bonusGetControleLista(PDO $pdo): array
{
    $tz = new DateTimeZone('America/Porto_Velho');
    $now = new DateTime('now', $tz);
    $prev = (clone $now)->modify('first day of last month');
    $next = (clone $now)->modify('first day of next month');
    $curAno = (int)$now->format('Y');
    $curMes = (int)$now->format('n');
    $nextAno = (int)$next->format('Y');
    $nextMes = (int)$next->format('n');
    $compMesLabel = $now->format('m/Y');
    $refMesLabel = $prev->format('m/Y');

    $sql = "
        SELECT
            pc.nome AS prescritor,
            pc.visitador AS visitador,
            COALESCE(ccur.valor_credito, 0) AS bonificacao_mes_anterior,
            COALESCE(cnext.valor_credito, 0) AS bonificacao_mes_atual_a_bonificar,
            COALESCE(c.total_credito, 0) AS total_credito,
            COALESCE(d.total_debito, 0) AS total_debito
        FROM prescritores_cadastro pc
        LEFT JOIN (
            SELECT prescritor, SUM(valor_credito) AS total_credito
            FROM prescritor_bonus_creditos_mensais
            GROUP BY prescritor
        ) c ON c.prescritor = pc.nome
        LEFT JOIN (
            SELECT prescritor, SUM(valor_credito) AS valor_credito
            FROM prescritor_bonus_creditos_mensais
            WHERE competencia_ano = :cur_ano AND competencia_mes = :cur_mes
            GROUP BY prescritor
        ) ccur ON ccur.prescritor = pc.nome
        LEFT JOIN (
            SELECT prescritor, SUM(valor_credito) AS valor_credito
            FROM prescritor_bonus_creditos_mensais
            WHERE competencia_ano = :next_ano AND competencia_mes = :next_mes
            GROUP BY prescritor
        ) cnext ON cnext.prescritor = pc.nome
        LEFT JOIN (
            SELECT prescritor, SUM(valor_debito) AS total_debito
            FROM prescritor_bonus_debitos
            GROUP BY prescritor
        ) d ON d.prescritor = pc.nome
        WHERE (
            LOWER(TRIM(COALESCE(pc.visitador, ''))) LIKE '%aulis%'
            OR LOWER(TRIM(COALESCE(pc.visitador, ''))) LIKE '%priscila%'
        )
          AND (
              COALESCE(ccur.valor_credito, 0) > 0
              OR COALESCE(cnext.valor_credito, 0) > 0
              OR COALESCE(c.total_credito, 0) > 0
              OR COALESCE(d.total_debito, 0) > 0
          )
        GROUP BY pc.nome, pc.visitador, ccur.valor_credito, cnext.valor_credito, c.total_credito, d.total_debito
        ORDER BY pc.nome ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        'cur_ano' => $curAno,
        'cur_mes' => $curMes,
        'next_ano' => $nextAno,
        'next_mes' => $nextMes,
    ]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $visitador = (string)($r['visitador'] ?? '');
        if (!bonusIsEligibleVisitador($visitador)) {
            continue;
        }
        $creditoMesAnterior = round((float)($r['bonificacao_mes_anterior'] ?? 0), 2);
        $aBonificarMesAtual = round((float)($r['bonificacao_mes_atual_a_bonificar'] ?? 0), 2);
        $totalCredito = (float)($r['total_credito'] ?? 0);
        $totalDebito = (float)($r['total_debito'] ?? 0);
        $saldo = round($totalCredito - $totalDebito, 2);
        // Exibe somente prescritores com bonificação/movimentação real.
        if ($creditoMesAnterior <= 0 && $aBonificarMesAtual <= 0 && $totalCredito <= 0 && $totalDebito <= 0 && $saldo <= 0) {
            continue;
        }
        $rows[] = [
            'prescritor' => (string)($r['prescritor'] ?? ''),
            'visitador' => $visitador,
            'bonificacao_mes_anterior' => $creditoMesAnterior,
            'bonificacao_mes_atual_a_bonificar' => $aBonificarMesAtual,
            'bonificacoes_utilizadas' => round($totalDebito, 2),
            'saldo_bonificacao' => $saldo,
            'referencia_mes_anterior_label' => $refMesLabel,
            'competencia_mes_atual_label' => $compMesLabel,
        ];
    }
    return [
        'meta' => [
            'referencia_mes_anterior_label' => $refMesLabel,
            'competencia_mes_atual_label' => $compMesLabel,
        ],
        'data' => $rows,
    ];
}

try {
    $pdo = getConnection();
    $sessionCheck = validateAndRefreshUserSession($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    bonusInitTables($pdo);
    $nowPv = new DateTime('now', new DateTimeZone('America/Porto_Velho'));
    if (bonusShouldRefreshCredits($pdo, $nowPv, 120)) {
        bonusGenerateMonthlyCredits($pdo);
        bonusMarkCreditsRefreshed($pdo, $nowPv);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falha ao inicializar API de bônus.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action === 'bonus_resumo_lote') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    $items = (is_array($payload) && isset($payload['prescritores']) && is_array($payload['prescritores']))
        ? $payload['prescritores']
        : [];
    $names = [];
    $fallbackVisitadorByName = [];
    foreach ($items as $it) {
        if (is_array($it) && isset($it['prescritor'])) {
            $prescritor = trim((string)$it['prescritor']);
            if ($prescritor === '') {
                continue;
            }
            $names[] = $prescritor;
            $fallbackVisitadorByName[$prescritor] = trim((string)($it['visitador'] ?? ''));
        }
    }
    $resumo = bonusGetResumoForNames($pdo, $names);
    foreach ($names as $name) {
        if (isset($resumo[$name])) {
            continue;
        }
        $fallbackVisitador = (string)($fallbackVisitadorByName[$name] ?? '');
        $resumo[$name] = [
            'prescritor' => $name,
            'visitador' => $fallbackVisitador,
            'bonificacao_eligivel' => bonusIsEligibleVisitador($fallbackVisitador),
            'saldo_bonus' => 0.0,
            'total_credito' => 0.0,
            'total_debito' => 0.0,
        ];
    }
    echo json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_extrato') {
    $prescritor = trim((string)($_GET['prescritor'] ?? ''));
    if ($prescritor === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Prescritor é obrigatório.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = bonusGetExtrato($pdo, $prescritor);
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_controle_lista') {
    $out = bonusGetControleLista($pdo);
    echo json_encode(['success' => true] + $out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_debito') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para lançar débito de bônus.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $prescritor = trim((string)($payload['prescritor'] ?? ''));
    $visitador = trim((string)($payload['visitador'] ?? ''));
    $numeroPedido = (int)($payload['numero_pedido'] ?? 0);
    $seriePedido = (int)($payload['serie_pedido'] ?? 0);
    $valorDebito = (float)($payload['valor_debito'] ?? 0);
    $dataDebito = trim((string)($payload['data_debito'] ?? ''));
    $observacao = trim((string)($payload['observacao'] ?? ''));

    if ($prescritor === '' || $numeroPedido <= 0 || $seriePedido <= 0 || $valorDebito <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDebito)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados obrigatórios inválidos para débito.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resumo = bonusGetResumoForNames($pdo, [$prescritor]);
    $saldoAtual = isset($resumo[$prescritor]) ? (float)$resumo[$prescritor]['saldo_bonus'] : 0.0;
    $eligivel = isset($resumo[$prescritor]) ? (bool)$resumo[$prescritor]['bonificacao_eligivel'] : false;
    if (!$eligivel) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Prescritor sem elegibilidade para bônus (somente carteiras Aulis/Priscila).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($valorDebito > $saldoAtual) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Débito maior que o saldo de bônus disponível.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $userNome = trim((string)($_SESSION['user_nome'] ?? 'Usuário'));

    $ins = $pdo->prepare("
        INSERT INTO prescritor_bonus_debitos
            (prescritor, visitador, numero_pedido, serie_pedido, valor_debito, data_debito, usuario_id, usuario_nome, observacao, criado_em)
        VALUES
            (:prescritor, :visitador, :numero_pedido, :serie_pedido, :valor_debito, :data_debito, :usuario_id, :usuario_nome, :observacao, NOW())
    ");
    $ins->execute([
        'prescritor' => $prescritor,
        'visitador' => $visitador !== '' ? $visitador : ($resumo[$prescritor]['visitador'] ?? null),
        'numero_pedido' => $numeroPedido,
        'serie_pedido' => $seriePedido,
        'valor_debito' => round($valorDebito, 2),
        'data_debito' => $dataDebito,
        'usuario_id' => $userId,
        'usuario_nome' => $userNome,
        'observacao' => $observacao !== '' ? $observacao : null,
    ]);

    $novoResumo = bonusGetResumoForNames($pdo, [$prescritor]);
    $novoSaldo = isset($novoResumo[$prescritor]) ? (float)$novoResumo[$prescritor]['saldo_bonus'] : 0.0;
    echo json_encode([
        'success' => true,
        'message' => 'Débito de bônus registrado com sucesso.',
        'saldo_atualizado' => round($novoSaldo, 2),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.'], JSON_UNESCAPED_UNICODE);
exit;
