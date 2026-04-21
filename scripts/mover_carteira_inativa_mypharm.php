<?php
/**
 * Move para a carteira "My Pharm" os prescritores que passaram mais de 40 dias
 * sem visita, considerando apenas visitas a partir de 02/03/2026 (contagem oculta).
 *
 * Não altera o histórico de visitas; apenas atualiza prescritores_cadastro e
 * prescritor_resumido para que o prescritor passe a constar na carteira My Pharm.
 *
 * Uso (na pasta mypharm):
 *   php scripts/mover_carteira_inativa_mypharm.php           # executa de verdade
 *   php scripts/mover_carteira_inativa_mypharm.php --dry-run  # só lista quem seria movido (teste)
 *
 * Recomendado: executar diariamente via cron, ex. 6h da manhã:
 *   0 6 * * * cd /caminho/mypharm && php scripts/mover_carteira_inativa_mypharm.php
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php scripts/mover_carteira_inativa_mypharm.php');
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

$baseDir = dirname(__DIR__);
$envPath = $baseDir . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    echo "Arquivo .env não encontrado.\n";
    exit(1);
}

foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode('=', $line, 2);
    if (count($p) === 2) putenv(trim($p[0]) . '=' . trim(trim($p[1]), '"\''));
}

$host   = getenv('DB_HOST') ?: 'localhost';
$name   = getenv('DB_NAME') ?: 'mypharm_db';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=$charset",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "Erro de conexão: " . $e->getMessage() . "\n";
    exit(1);
}

require_once __DIR__ . '/carteira_inativa_mypharm.php';

$result = runCarteiraInativaMyPharm($pdo, $dryRun);
$count  = $result['count'];
$moved  = $result['moved'];

if ($count === 0) {
    if ($dryRun) {
        echo "[DRY-RUN] Nenhum prescritor seria movido (todos com visita nos últimos " . CARTEIRA_INATIVA_DIAS . " dias ou já em My Pharm).\n";
    } else {
        echo "Nenhum prescritor movido para My Pharm (todos com visita nos últimos " . CARTEIRA_INATIVA_DIAS . " dias ou já em My Pharm).\n";
    }
    exit(0);
}

if ($dryRun) {
    echo "[DRY-RUN] Seriam movidos para My Pharm: $count prescritor(es). Nenhum dado alterado.\n";
} else {
    echo "Movidos para My Pharm: $count prescritor(es).\n";
}
foreach ($moved as $m) {
    echo "  - " . $m['nome'] . " (antes: " . ($m['visitador_anterior'] ?: '—') . ")\n";
}
exit(0);
