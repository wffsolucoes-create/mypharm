<?php
/**
 * Importa o CSV "Relatórios de Orçamentos e Pedidos por Prescritor Detalhado com Componentes".
 * Relatório usado: Dados/Relatórios de Orçamentos e Pedidos por Prescritor Detalhado com Componentes 2026.csv
 * (por padrão só 2026; 2025 já está no banco e não muda mais.)
 *
 * Não apaga a tabela: só remove os dados do(s) ano(s) informado(s) e reinsere.
 *
 * Uso (na pasta mypharm):
 *   php scripts/importar_detalhado_componentes_cli.php        → importa só 2026 (padrão)
 *   php scripts/importar_detalhado_componentes_cli.php 2025   → importa só 2025 (substitui 2025)
 *   php scripts/importar_detalhado_componentes_cli.php 2025 2026
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php importar_detalhado_componentes_cli.php [ano1] [ano2] ...');
}

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
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
    echo "Erro DB: " . $e->getMessage() . "\n";
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '1G');

$baseDir = dirname(__DIR__);
$anos = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^20\d{2}$/', $arg)) $anos[] = (int)$arg;
}
if (empty($anos)) $anos = [2026];

/** Quantidade com vírgula decimal (ex: 0,09000) */
function parseBRDecimal($s) {
    $s = trim($s);
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    return (float) $s;
}

function readCsvHeader($path, $delim = ';') {
    $h = @fopen($path, 'r');
    if (!$h) return [null, null];
    if (fread($h, 3) !== "\xEF\xBB\xBF") rewind($h);
    $header = fgetcsv($h, 0, $delim);
    if ($header) $header = array_map(function ($x) { return trim($x, "\xEF\xBB\xBF \t\n\r"); }, $header);
    return [$h, $header];
}

const BULK_CHUNK = 3000;

// Criar tabela só se não existir (não apaga dados de outros anos)
$tableExists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'pedidos_detalhado_componentes'")->fetchColumn();
if (!$tableExists) {
    $pdo->exec("CREATE TABLE pedidos_detalhado_componentes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero INT NOT NULL DEFAULT 0,
        serie INT NOT NULL DEFAULT 0,
        ano_referencia INT NOT NULL,
        componente VARCHAR(255),
        quantidade_componente DECIMAL(18,6) NULL,
        unidade_componente VARCHAR(20),
        INDEX idx_ano (ano_referencia),
        INDEX idx_numero_serie (numero, serie)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Tabela pedidos_detalhado_componentes criada.\n";
}

$totalInseridos = 0;
foreach ($anos as $ano) {
    $file = $baseDir . "/Dados/Relatórios de Orçamentos e Pedidos por Prescritor Detalhado com Componentes {$ano}.csv";
    echo "Ano {$ano}: ";
    if (!file_exists($file)) {
        echo "arquivo não encontrado.\n";
        continue;
    }
    // Substitui dados do ano: remove o que já existe para esse ano e reinsere do CSV
    $stmtDel = $pdo->prepare("DELETE FROM pedidos_detalhado_componentes WHERE ano_referencia = ?");
    $stmtDel->execute([$ano]);
    $deleted = $stmtDel->rowCount();
    if ($deleted > 0) echo "(substituiu " . number_format($deleted, 0, '', '.') . " linhas) ";
    list($handle, $header) = readCsvHeader($file);
    if (!$handle || !$header) {
        echo "erro ao ler CSV.\n";
        continue;
    }

    $keyMap = array_flip($header);
    $get = function ($row, $col) use ($keyMap, $header) {
        $i = $keyMap[$col] ?? null;
        if ($i === null) return '';
        $key = $header[$i] ?? null;
        return $key !== null ? trim($row[$key] ?? '') : '';
    };

    $batch = [];
    $count = 0;
    $linha = 0;
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        if (count($data) !== count($header)) continue;
        $row = array_combine($header, $data);
        $batch[] = [
            (int)($get($row, 'Número') ?: 0),
            (int)($get($row, 'Série') ?: 0),
            $ano,
            $get($row, 'Componente'),
            parseBRDecimal($get($row, 'Quantidade Componente')),
            $get($row, 'Unidade Componente'),
        ];
        $linha++;

        if (count($batch) >= BULK_CHUNK) {
            $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?)'));
            $flat = [];
            foreach ($batch as $r) { foreach ($r as $v) { $flat[] = $v; } }
            $sql = "INSERT INTO pedidos_detalhado_componentes (numero, serie, ano_referencia, componente, quantidade_componente, unidade_componente) VALUES {$placeholders}";
            $pdo->prepare($sql)->execute($flat);
            $count += count($batch);
            $batch = [];
            echo " " . number_format($count, 0, '', '.') . "…";
        }
    }
    fclose($handle);

    if (!empty($batch)) {
        $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?)'));
        $flat = [];
        foreach ($batch as $r) { foreach ($r as $v) { $flat[] = $v; } }
        $sql = "INSERT INTO pedidos_detalhado_componentes (numero, serie, ano_referencia, componente, quantidade_componente, unidade_componente) VALUES {$placeholders}";
        $pdo->prepare($sql)->execute($flat);
        $count += count($batch);
    }

    $totalInseridos += $count;
    echo " " . number_format($count, 0, '', '.') . " linhas.\n";
}

echo "Total: {$totalInseridos} linhas importadas.\n";
