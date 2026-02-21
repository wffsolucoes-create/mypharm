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
