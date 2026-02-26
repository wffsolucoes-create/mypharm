<?php
/**
 * Aplica no banco todas as mudanças de usuario_id (prescritores_cadastro e prescritor_dados).
 * Uso: php scripts/migrate_usuario_id.php
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php scripts/migrate_usuario_id.php');
}

$baseDir = dirname(__DIR__);
$envPath = $baseDir . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    echo "Arquivo .env não encontrado em {$envPath}\n";
    exit(1);
}
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode('=', $line, 2);
    if (count($p) === 2) putenv(trim($p[0]) . '=' . trim(trim($p[1]), '"\''));
}

$host = getenv('DB_HOST') ?: 'localhost';
$name = getenv('DB_NAME') ?: 'mypharm_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=$charset",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo "Erro de conexão: " . $e->getMessage() . "\n";
    exit(1);
}

$steps = [
    'prescritores_cadastro: adicionar coluna usuario_id' => "ALTER TABLE prescritores_cadastro ADD COLUMN usuario_id INT NULL",
    'prescritores_cadastro: índice usuario_id' => "ALTER TABLE prescritores_cadastro ADD INDEX idx_usuario_id (usuario_id)",
    'prescritores_cadastro: preencher usuario_id por nome (visitadores)' => "
        UPDATE prescritores_cadastro pc
        INNER JOIN usuarios u ON TRIM(u.nome) = TRIM(pc.visitador) AND LOWER(TRIM(COALESCE(u.setor,''))) = 'visitador'
        SET pc.usuario_id = u.id
    ",
    'prescritores_cadastro: My Pharm / vazio' => "
        UPDATE prescritores_cadastro SET usuario_id = (
            SELECT id FROM usuarios WHERE TRIM(COALESCE(nome,'')) = 'My Pharm' AND LOWER(TRIM(COALESCE(setor,''))) = 'visitador' LIMIT 1
        )
        WHERE (visitador IS NULL OR TRIM(visitador) = '' OR TRIM(visitador) = 'My Pharm' OR UPPER(TRIM(visitador)) = 'MY PHARM')
    ",
    'prescritor_dados: adicionar coluna usuario_id' => "ALTER TABLE prescritor_dados ADD COLUMN usuario_id INT NULL",
    'prescritor_dados: sincronizar usuario_id de prescritores_cadastro' => "
        UPDATE prescritor_dados pd
        INNER JOIN prescritores_cadastro pc ON pc.nome = pd.nome_prescritor
        SET pd.usuario_id = pc.usuario_id
    ",
];

foreach ($steps as $label => $sql) {
    $sql = trim($sql);
    try {
        $pdo->exec($sql);
        echo "[OK] {$label}\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false || strpos($msg, '1060') !== false) {
            echo "[--] {$label} (coluna já existe)\n";
        } elseif (strpos($msg, 'Duplicate key') !== false || strpos($msg, '1061') !== false) {
            echo "[--] {$label} (índice já existe)\n";
        } else {
            echo "[ERRO] {$label}: " . $msg . "\n";
        }
    }
}

echo "\nMigração concluída.\n";
