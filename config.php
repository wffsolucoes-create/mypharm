<?php
// Configuração do Banco de Dados - MyPharm
// Credenciais carregadas do arquivo .env (protegido via .htaccess)

// Detectar ambiente: produção (Hostinger) ou desenvolvimento (localhost)
define('IS_PRODUCTION', !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1']));

/**
 * Carrega variáveis de ambiente a partir do arquivo .env
 */
function loadEnv($path)
{
    if (!file_exists($path)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        die(json_encode(['error' => 'Arquivo .env não encontrado.']));
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignorar comentários
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;

        // Separar chave=valor
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        // Remover aspas se existirem
        $value = trim($value, '"\'');

        // Definir como variável de ambiente
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// Carregar credenciais do .env
loadEnv(__DIR__ . DIRECTORY_SEPARATOR . '.env');

// Definir constantes a partir do .env
define('DB_HOST', $_ENV['DB_HOST'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? '');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// Conexão PDO
function getConnection()
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET time_zone = '-04:00'"
        ]);
        return $pdo;
    } catch (PDOException $e) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        // Em produção: mensagem genérica. Local: mostrar detalhes para debug
        $msg = IS_PRODUCTION
            ? 'Erro de conexão com o banco de dados.'
            : 'Erro DB: ' . $e->getMessage();
        die(json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE));
    }
}

/** Migração única: colunas usuario_id em prescritores_cadastro e prescritor_dados. Roda uma vez por instalação. */
function runUsuarioIdMigrationIfNeeded(PDO $pdo): void
{
    $marker = __DIR__ . DIRECTORY_SEPARATOR . '.migrated_usuario_id';
    if (file_exists($marker)) {
        return;
    }
    $run = function (string $sql) use ($pdo): bool {
        try {
            $pdo->exec(trim($sql));
            return true;
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (strpos($m, 'Duplicate column') !== false || strpos($m, '1060') !== false ||
                strpos($m, 'Duplicate key') !== false || strpos($m, '1061') !== false) {
                return true;
            }
            return false;
        }
    };
    $run("ALTER TABLE prescritores_cadastro ADD COLUMN usuario_id INT NULL");
    $run("ALTER TABLE prescritores_cadastro ADD INDEX idx_usuario_id (usuario_id)");
    $run("UPDATE prescritores_cadastro pc INNER JOIN usuarios u ON TRIM(u.nome) = TRIM(pc.visitador) AND LOWER(TRIM(COALESCE(u.setor,''))) = 'visitador' SET pc.usuario_id = u.id");
    $run("UPDATE prescritores_cadastro SET usuario_id = (SELECT id FROM usuarios WHERE TRIM(COALESCE(nome,'')) = 'My Pharm' AND LOWER(TRIM(COALESCE(setor,''))) = 'visitador' LIMIT 1) WHERE (visitador IS NULL OR TRIM(visitador) = '' OR TRIM(visitador) = 'My Pharm' OR UPPER(TRIM(visitador)) = 'MY PHARM')");
    $run("ALTER TABLE prescritor_dados ADD COLUMN usuario_id INT NULL");
    try {
        $pdo->exec("UPDATE prescritor_dados pd INNER JOIN prescritores_cadastro pc ON pc.nome = pd.nome_prescritor SET pd.usuario_id = pc.usuario_id");
    } catch (PDOException $e) {
        // tabela prescritor_dados pode não existir ainda
    }
    @file_put_contents($marker, date('c'));
}

/** Cria tabelas de notificações e mensagens entre visitadores (se não existirem). */
function runNotificacoesTablesIfNeeded(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        tipo VARCHAR(50) NOT NULL,
        titulo VARCHAR(255) DEFAULT NULL,
        mensagem TEXT,
        prescritor_nome VARCHAR(255) DEFAULT NULL,
        dias_sem_compra INT NULL,
        lida TINYINT(1) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario_lida (usuario_id, lida),
        INDEX idx_usuario_criado (usuario_id, criado_em),
        INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens_visitador (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        prescritor_nome VARCHAR(255) NOT NULL,
        mensagem TEXT NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prescritor (prescritor_nome(100)),
        INDEX idx_usuario (usuario_id),
        INDEX idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens_usuario (
        id INT AUTO_INCREMENT PRIMARY KEY,
        de_usuario_id INT NOT NULL,
        para_usuario_id INT NOT NULL,
        mensagem TEXT NOT NULL,
        lida TINYINT(1) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_para (para_usuario_id, lida),
        INDEX idx_de (de_usuario_id),
        INDEX idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens_usuario_ocultas (
        usuario_id INT NOT NULL,
        mensagem_id INT NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_id, mensagem_id),
        INDEX idx_usuario (usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Sessão segura
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => IS_PRODUCTION,    // true em HTTPS (Hostinger), false em HTTP (localhost)
        'httponly' => true,
        'samesite' => 'Lax'           // Lax funciona melhor que Strict para navegação entre páginas
    ]);
    session_start();
}

// Timezone
date_default_timezone_set('America/Porto_Velho');

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function getCsrfToken(): string
{
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Rate Limiting (login)
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 300);

function checkLoginRateLimit(PDO $pdo, string $ip): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_time (ip_address, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)")
        ->execute([LOGIN_LOCKOUT_SECONDS]);

    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM login_attempts WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= MAX_LOGIN_ATTEMPTS) {
        $stmt2 = $pdo->prepare("SELECT MAX(attempted_at) as last_attempt FROM login_attempts WHERE ip_address = ?");
        $stmt2->execute([$ip]);
        $lastAttempt = $stmt2->fetchColumn();
        $unlockAt = strtotime($lastAttempt) + LOGIN_LOCKOUT_SECONDS;
        $remaining = $unlockAt - time();
        return ['blocked' => true, 'remaining' => max(0, $remaining), 'attempts' => $count];
    }

    return ['blocked' => false, 'remaining' => 0, 'attempts' => $count];
}

function recordLoginAttempt(PDO $pdo, string $ip): void
{
    $pdo->prepare("INSERT INTO login_attempts (ip_address) VALUES (?)")->execute([$ip]);
}

function clearLoginAttempts(PDO $pdo, string $ip): void
{
    $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
}

function getClientIp(): string
{
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function initAuthAuditTable(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_audit_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        evento VARCHAR(50) NOT NULL,
        sucesso TINYINT(1) NOT NULL DEFAULT 0,
        user_id INT NULL,
        usuario VARCHAR(120) NULL,
        nome_usuario VARCHAR(255) NULL,
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        ip_forwarded VARCHAR(255) NULL,
        host_reverse VARCHAR(255) NULL,
        session_id VARCHAR(255) NULL,
        request_method VARCHAR(10) NULL,
        request_path VARCHAR(255) NULL,
        origin VARCHAR(255) NULL,
        referer VARCHAR(500) NULL,
        user_agent VARCHAR(500) NULL,
        accept_language VARCHAR(120) NULL,
        mac_info VARCHAR(120) NULL,
        detalhes_json TEXT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_evento_data (evento, criado_em),
        INDEX idx_user_data (user_id, criado_em),
        INDEX idx_ip_data (ip_address, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function getReverseHostFromIp(string $ip): string
{
    if ($ip === '' || $ip === '0.0.0.0' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
    }
    $host = @gethostbyaddr($ip);
    if (!$host || $host === $ip) {
        return '';
    }
    return $host;
}

function getRequestPathForAudit(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ($uri === '') return '';
    $parts = explode('?', $uri, 2);
    return trim($parts[0]);
}

function logAuthEvent(PDO $pdo, string $evento, bool $sucesso, array $ctx = []): void
{
    try {
        initAuthAuditTable($pdo);
        $ip = getClientIp();
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $hostReverse = getReverseHostFromIp($ip);
        $sid = session_id();
        if ($sid === '') $sid = null;
        $detalhes = $ctx['detalhes'] ?? null;
        $detalhesJson = null;
        if (is_array($detalhes)) {
            $detalhesJson = json_encode($detalhes, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($detalhes) && $detalhes !== '') {
            $detalhesJson = $detalhes;
        }
        // Navegadores não expõem MAC Address por segurança.
        $macInfo = 'indisponivel_via_web';
        $stmt = $pdo->prepare("INSERT INTO auth_audit_logs
            (evento, sucesso, user_id, usuario, nome_usuario, ip_address, ip_forwarded, host_reverse, session_id, request_method, request_path, origin, referer, user_agent, accept_language, mac_info, detalhes_json, criado_em)
            VALUES
            (:evento, :sucesso, :user_id, :usuario, :nome_usuario, :ip_address, :ip_forwarded, :host_reverse, :session_id, :request_method, :request_path, :origin, :referer, :user_agent, :accept_language, :mac_info, :detalhes_json, NOW())");
        $stmt->execute([
            'evento' => $evento,
            'sucesso' => $sucesso ? 1 : 0,
            'user_id' => $ctx['user_id'] ?? null,
            'usuario' => $ctx['usuario'] ?? null,
            'nome_usuario' => $ctx['nome_usuario'] ?? null,
            'ip_address' => $ip,
            'ip_forwarded' => $forwarded !== '' ? $forwarded : null,
            'host_reverse' => $hostReverse !== '' ? $hostReverse : null,
            'session_id' => $sid,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'request_path' => getRequestPathForAudit(),
            'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
            'mac_info' => $macInfo,
            'detalhes_json' => $detalhesJson
        ]);
    } catch (Throwable $e) {
        // Nunca interromper fluxo principal por falha de auditoria.
    }
}

// ========== Controle de sessão única (um aparelho por usuário, 12h máx, inatividade) ==========
define('SESSION_MAX_HOURS', 12);
define('SESSION_INACTIVITY_MINUTES', 300); // 5 horas sem atividade

function initUserSessionsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        user_id INT NOT NULL PRIMARY KEY,
        session_id VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_session (session_id),
        INDEX idx_last_activity (last_activity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Registra a sessão atual como a única ativa para o usuário (encerra qualquer outra). */
function registerUserSession(PDO $pdo, int $userId): void
{
    initUserSessionsTable($pdo);
    $sid = session_id();
    if ($sid === '') {
        return;
    }
    $pdo->prepare("INSERT INTO user_sessions (user_id, session_id, created_at, last_activity) VALUES (?, ?, NOW(), NOW())
                   ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), created_at = NOW(), last_activity = NOW()")
        ->execute([$userId, $sid]);
}

/**
 * Valida a sessão: mesmo session_id, criada há menos de 12h, última atividade há menos de 30 min.
 * Atualiza last_activity se válida. Se inválida, destrói a sessão e retorna false.
 * Retorna ['valid' => true] ou ['valid' => false, 'reason' => '...'].
 */
function validateAndRefreshUserSession(PDO $pdo): array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['valid' => true];
    }
    initUserSessionsTable($pdo);
    $sid = session_id();
    if ($sid === '') {
        return ['valid' => false, 'reason' => 'session_gone'];
    }

    $stmt = $pdo->prepare("SELECT session_id, created_at, last_activity FROM user_sessions WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['valid' => false, 'reason' => 'session_not_found'];
    }
    if ($row['session_id'] !== $sid) {
        return ['valid' => false, 'reason' => 'other_device'];
    }

    $created = strtotime($row['created_at']);
    $lastActivity = strtotime($row['last_activity']);
    $now = time();
    $maxAge = SESSION_MAX_HOURS * 3600;
    $inactivityLimit = SESSION_INACTIVITY_MINUTES * 60;

    if ($now - $created > $maxAge) {
        $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]);
        return ['valid' => false, 'reason' => 'expired_max'];
    }
    if ($now - $lastActivity > $inactivityLimit) {
        $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]);
        return ['valid' => false, 'reason' => 'expired_inactivity'];
    }

    $pdo->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE user_id = ?")->execute([$userId]);
    return ['valid' => true];
}

/** Remove o registro de sessão do usuário (ex.: no logout). */
function removeUserSession(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]);
    } catch (Throwable $e) {
        // ignora se a tabela não existir
    }
}

function initGeocacheTable(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS geocache (
        lat_key DECIMAL(7,5) NOT NULL,
        lng_key DECIMAL(7,5) NOT NULL,
        endereco VARCHAR(500) NOT NULL DEFAULT '',
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (lat_key, lng_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * Reverse geocoding via Nominatim com cache no banco.
 * Retorna endereço legível ou coordenadas formatadas como fallback.
 */
function reverseGeocode(PDO $pdo, ?float $lat, ?float $lng): string
{
    if ($lat === null || $lng === null) return '—';

    $latKey = round($lat, 5);
    $lngKey = round($lng, 5);

    initGeocacheTable($pdo);

    $stmt = $pdo->prepare("SELECT endereco FROM geocache WHERE lat_key = :lat AND lng_key = :lng LIMIT 1");
    $stmt->execute(['lat' => $latKey, 'lng' => $lngKey]);
    $cached = $stmt->fetchColumn();
    if ($cached !== false && $cached !== '') {
        return $cached;
    }

    $url = 'https://nominatim.openstreetmap.org/reverse?format=json'
         . '&lat=' . $lat . '&lon=' . $lng
         . '&zoom=18&addressdetails=1&accept-language=pt-BR';

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header' => "User-Agent: MyPharm/1.0\r\nAccept-Language: pt-BR\r\n",
        ],
    ]);

    $addr = '';
    try {
        $json = @file_get_contents($url, false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['address'])) {
                $a = $data['address'];
                $partes = [];
                if (!empty($a['road'])) $partes[] = $a['road'] . (!empty($a['house_number']) ? ', ' . $a['house_number'] : '');
                if (!empty($a['suburb'] ?? $a['neighbourhood'] ?? '')) $partes[] = $a['suburb'] ?? $a['neighbourhood'];
                if (!empty($a['city'] ?? $a['town'] ?? $a['village'] ?? '')) $partes[] = $a['city'] ?? $a['town'] ?? $a['village'];
                $addr = implode(' - ', $partes);
            }
            if (!$addr && !empty($data['display_name'])) {
                $addr = implode(', ', array_slice(explode(', ', $data['display_name']), 0, 3));
            }
        }
    } catch (Throwable $e) {
        // fallback silencioso
    }

    if (!$addr) {
        $addr = number_format($lat, 5, '.', '') . ', ' . number_format($lng, 5, '.', '');
    }

    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO geocache (lat_key, lng_key, endereco) VALUES (:lat, :lng, :end)");
        $ins->execute(['lat' => $latKey, 'lng' => $lngKey, 'end' => $addr]);
    } catch (Throwable $e) {
        // ignora erro de insert
    }

    return $addr;
}
