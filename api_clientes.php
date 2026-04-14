<?php
/**
 * api_clientes.php — API da página de análise de clientes (base importada).
 * Usa config.php/.env igual ao resto do sistema. Sem credenciais hardcoded.
 */
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$allowedOrigins = ['http://localhost','https://localhost','http://127.0.0.1','https://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Mesma sessão que api.php: user_id + user_setor (não existe $_SESSION['loggedIn'] / ['setor'] no PHP)
$userIdCli = (int)($_SESSION['user_id'] ?? 0);
$userSetorCli = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
$tipoCli = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
$cliSetorOk = ($userSetorCli === 'administrador' || $userSetorCli === 'gestor'
    || strpos($userSetorCli, 'administrador') === 0
    || strpos($userSetorCli, 'gestor') === 0);
if ($userIdCli <= 0 || (!$cliSetorOk && $tipoCli !== 'admin')) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo    = getConnection();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function cliTblExists(PDO $pdo): bool {
    static $r = null;
    if ($r === null) $r = (bool)$pdo->query("SHOW TABLES LIKE 'clientes'")->fetch();
    return $r;
}
function cliOut(mixed $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Garante colunas de geocódigo na tabela clientes (idempotente). */
function cliEnsureGeoColumns(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!cliTblExists($pdo)) {
        return;
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'latitude'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec('ALTER TABLE clientes ADD COLUMN latitude DECIMAL(10,7) NULL DEFAULT NULL, ADD COLUMN longitude DECIMAL(10,7) NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // colunas já existem ou sem permissão ALTER
    }
}

// ── resumo ────────────────────────────────────────────────────────────────────
if ($action === 'resumo') {
    if (!cliTblExists($pdo)) {
        cliOut(['total'=>0,'com_cpf'=>0,'sem_cpf'=>0,'completo'=>0,'simples'=>0,'com_celular'=>0,'com_email'=>0,'com_nasc'=>0]);
    }
    $r = $pdo->query("
        SELECT
            COUNT(*)                               AS total,
            SUM(cpf IS NOT NULL)                   AS com_cpf,
            SUM(cpf IS NULL)                       AS sem_cpf,
            SUM(tipo_cadastro = 'Completo')        AS completo,
            SUM(tipo_cadastro = 'Simples')         AS simples,
            SUM(
                IFNULL(TRIM(telefone), '') <> ''
                AND (
                    REGEXP_REPLACE(TRIM(telefone), '[^0-9]', '') REGEXP '^[1-9][1-9]9[0-9]{8}\$'
                    OR REGEXP_REPLACE(TRIM(telefone), '[^0-9]', '') REGEXP '^55[1-9][1-9]9[0-9]{8}\$'
                )
            )                                      AS com_celular,
            SUM(email IS NOT NULL AND email != '') AS com_email,
            SUM(data_nasc IS NOT NULL)             AS com_nasc
        FROM clientes
    ")->fetch(PDO::FETCH_ASSOC);
    cliOut($r);
}

// ── por_mes ───────────────────────────────────────────────────────────────────
if ($action === 'por_mes') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT
            YEAR(data_cadastro)  AS ano,
            MONTH(data_cadastro) AS mes,
            COUNT(*)             AS total,
            SUM(tipo_cadastro = 'Completo') AS completo,
            SUM(tipo_cadastro = 'Simples')  AS simples
        FROM clientes
        WHERE data_cadastro IS NOT NULL
        GROUP BY ano, mes
        ORDER BY ano, mes
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_fonte_ano ─────────────────────────────────────────────────────────────
if ($action === 'por_fonte_ano') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT
            COALESCE(fonte_ano, 0) AS ano,
            COUNT(*) AS total,
            SUM(tipo_cadastro = 'Completo') AS completo,
            SUM(cpf IS NOT NULL)            AS com_cpf
        FROM clientes
        GROUP BY fonte_ano
        ORDER BY fonte_ano
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_uf ────────────────────────────────────────────────────────────────────
if ($action === 'por_uf') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(uf),''),'N/D') AS uf, COUNT(*) AS total
        FROM clientes GROUP BY uf ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_municipio ─────────────────────────────────────────────────────────────
if ($action === 'por_municipio') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT
            COALESCE(NULLIF(TRIM(municipio),''),'N/D') AS municipio,
            COALESCE(NULLIF(TRIM(uf),''),'')           AS uf,
            COUNT(*) AS total
        FROM clientes GROUP BY municipio, uf ORDER BY total DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_sexo ──────────────────────────────────────────────────────────────────
if ($action === 'por_sexo') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(sexo),''),'Não informado') AS sexo, COUNT(*) AS total
        FROM clientes GROUP BY sexo ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── lista_ufs ─────────────────────────────────────────────────────────────────
if ($action === 'lista_ufs') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("SELECT DISTINCT uf FROM clientes WHERE uf IS NOT NULL AND uf != '' ORDER BY uf")->fetchAll(PDO::FETCH_COLUMN);
    cliOut($rows);
}

// ── lista_municipios ─────────────────────────────────────────────────────────
if ($action === 'lista_municipios') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $uf)) {
        cliOut([]);
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT TRIM(municipio) AS municipio
        FROM clientes
        WHERE uf = :uf AND municipio IS NOT NULL AND TRIM(municipio) <> ''
        ORDER BY municipio COLLATE utf8mb4_unicode_ci
    ");
    $stmt->execute([':uf' => $uf]);
    cliOut($stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── lista_bairros ───────────────────────────────────────────────────────────
if ($action === 'lista_bairros') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    $mun = trim((string)($_GET['municipio'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $uf) || $mun === '') {
        cliOut([]);
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT TRIM(bairro) AS bairro
        FROM clientes
        WHERE uf = :uf AND TRIM(municipio) = :mun
          AND bairro IS NOT NULL AND TRIM(bairro) <> ''
        ORDER BY bairro COLLATE utf8mb4_unicode_ci
    ");
    $stmt->execute([':uf' => $uf, ':mun' => $mun]);
    cliOut($stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── por_municipio_por_uf ─────────────────────────────────────────────────────
if ($action === 'por_municipio_por_uf') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $uf)) {
        cliOut([]);
    }
    $stmt = $pdo->prepare("
        SELECT TRIM(municipio) AS municipio, COUNT(*) AS total
        FROM clientes
        WHERE uf = :uf AND municipio IS NOT NULL AND TRIM(municipio) <> ''
        GROUP BY TRIM(municipio)
        ORDER BY total DESC
    ");
    $stmt->execute([':uf' => $uf]);
    cliOut($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── por_bairro ───────────────────────────────────────────────────────────────
if ($action === 'por_bairro') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    $mun = trim((string)($_GET['municipio'] ?? ''));
    $bairro = trim((string)($_GET['bairro'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $uf) || $mun === '') {
        cliOut([]);
    }
    if ($bairro !== '') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(bairro), ''), 'N/D') AS bairro, COUNT(*) AS total
            FROM clientes
            WHERE uf = :uf AND TRIM(municipio) = :mun AND TRIM(bairro) = :bairro
            GROUP BY TRIM(bairro)
            ORDER BY total DESC
            LIMIT 60
        ");
        $stmt->execute([':uf' => $uf, ':mun' => $mun, ':bairro' => $bairro]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(bairro), ''), 'N/D') AS bairro, COUNT(*) AS total
            FROM clientes
            WHERE uf = :uf AND TRIM(municipio) = :mun
            GROUP BY TRIM(bairro)
            ORDER BY total DESC
            LIMIT 60
        ");
        $stmt->execute([':uf' => $uf, ':mun' => $mun]);
    }
    cliOut($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── pontos_municipio_mapa (cadastros com endereço para pins no mapa) ─────────
if ($action === 'pontos_municipio_mapa') {
    cliEnsureGeoColumns($pdo);
    if (!cliTblExists($pdo)) {
        cliOut([]);
    }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    $mun = trim((string)($_GET['municipio'] ?? ''));
    $bairro = trim((string)($_GET['bairro'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $uf) || $mun === '') {
        cliOut([]);
    }
    $sql = "
        SELECT id, nome,
               COALESCE(NULLIF(TRIM(logradouro), ''), '') AS logradouro,
               COALESCE(NULLIF(TRIM(numero), ''), '') AS numero,
               COALESCE(NULLIF(TRIM(bairro), ''), '') AS bairro,
               COALESCE(NULLIF(TRIM(cep), ''), '') AS cep,
               TRIM(municipio) AS municipio, uf,
               latitude, longitude
        FROM clientes
        WHERE uf = :uf AND TRIM(municipio) = :mun
    ";
    $bind = [':uf' => $uf, ':mun' => $mun];
    if ($bairro !== '') {
        $sql .= ' AND TRIM(bairro) = :bairro';
        $bind[':bairro'] = $bairro;
    }
    $sql .= ' ORDER BY id ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    cliOut($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── salvar_cliente_geo (persistir lat/lng após geocódigo no navegador) ────────
if ($action === 'salvar_cliente_geo') {
    if (strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST') {
        http_response_code(405);
        cliOut(['error' => 'Use POST']);
    }
    cliEnsureGeoColumns($pdo);
    if (!cliTblExists($pdo)) {
        cliOut(['ok' => false, 'error' => 'Sem tabela']);
    }
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        cliOut(['ok' => false, 'error' => 'JSON inválido']);
    }
    $id = (int)($j['id'] ?? 0);
    $lat = $j['lat'] ?? null;
    $lng = $j['lng'] ?? null;
    if ($id <= 0 || !is_numeric($lat) || !is_numeric($lng)) {
        cliOut(['ok' => false, 'error' => 'Parâmetros']);
    }
    $latF = (float)$lat;
    $lngF = (float)$lng;
    if ($latF < -35.0 || $latF > 10.0 || $lngF < -81.0 || $lngF > -28.0) {
        cliOut(['ok' => false, 'error' => 'Coordenadas fora do Brasil']);
    }
    $stmt = $pdo->prepare('UPDATE clientes SET latitude = :la, longitude = :ln WHERE id = :id LIMIT 1');
    $stmt->execute([':la' => $latF, ':ln' => $lngF, ':id' => $id]);
    cliOut(['ok' => $stmt->rowCount() > 0]);
}

// ── buscar ────────────────────────────────────────────────────────────────────
if ($action === 'buscar') {
    if (!cliTblExists($pdo)) { cliOut(['rows'=>[],'total'=>0,'paginas'=>0,'pagina'=>1]); }

    $q    = trim((string)($_GET['q']    ?? ''));
    $uf   = trim((string)($_GET['uf']   ?? ''));
    $tipo = trim((string)($_GET['tipo'] ?? ''));
    $ano  = (int)($_GET['ano'] ?? 0);
    $pg   = max(1, (int)($_GET['pg'] ?? 1));
    $per  = 50;
    $off  = ($pg - 1) * $per;

    $where = ['1=1']; $p = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(nome LIKE :q1 OR telefone LIKE :q2 OR cpf LIKE :q3 OR email LIKE :q4 OR municipio LIKE :q5)';
        $p[':q1'] = $like; $p[':q2'] = $like; $p[':q3'] = $like; $p[':q4'] = $like; $p[':q5'] = $like;
    }
    if ($uf   !== '') { $where[] = 'uf = :uf';              $p[':uf']   = $uf; }
    if ($tipo !== '') { $where[] = 'tipo_cadastro = :tipo'; $p[':tipo'] = $tipo; }
    if ($ano  >  0  ) { $where[] = 'fonte_ano = :ano';      $p[':ano']  = $ano; }

    $wSql  = implode(' AND ', $where);
    $cnt   = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE $wSql");
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();
    $pags  = $total > 0 ? (int)ceil($total / $per) : 0;

    $stmt  = $pdo->prepare("
        SELECT id, nome, nome_valido, data_cadastro, data_nasc, tipo_cadastro,
               sexo, email, telefone, cpf, uf, municipio, bairro, logradouro, numero, cep, fonte_ano
        FROM clientes WHERE $wSql
        ORDER BY data_cadastro DESC, id DESC
        LIMIT $per OFFSET $off
    ");
    $stmt->execute($p);
    cliOut(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'paginas' => $pags, 'pagina' => $pg]);
}

http_response_code(400);
echo json_encode(['error' => 'Ação inválida'], JSON_UNESCAPED_UNICODE);
