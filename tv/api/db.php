<?php
/**
 * Conexão com o Banco de Dados MySQL via PDO.
 * Usa as mesmas credenciais do .env na raiz do projeto MyPharm (igual a config.php).
 * Tabela `usuarios` vem do MyPharm; não há mais import Phusion na TV.
 */

/**
 * Carrega variáveis do .env da raiz do repositório (mypharm/.env).
 * Mesmo comportamento essencial de config.php: aspas removidas, comentários ignorados.
 */
function tv_load_root_env(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, '"\'');
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

tv_load_root_env();

define('DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'mypharm_db');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

function getDB()
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            setupTvApiTables($pdo);
        } catch (PDOException $e) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
            }
            die(json_encode(['error' => 'Erro de conexão: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }

    return $pdo;
}

function setupTvApiTables(PDO $pdo): void
{
    /* Importação Phusion / divergências removidos — sem tabelas extra aqui. */
}
