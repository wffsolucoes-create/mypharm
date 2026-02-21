<?php
/**
 * Análise do banco de dados MyPharm.
 * Conecta usando .env e gera relatório: tabelas, colunas, contagem de linhas, índices.
 *
 * Uso: php scripts/analisar_banco.php   (na pasta mypharm)
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php scripts/analisar_banco.php');
}

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

$db = $name;
echo "\n";
echo "=============================================\n";
echo "  ANÁLISE DO BANCO: {$db}\n";
echo "  Host: {$host}\n";
echo "  " . date('d/m/Y H:i:s') . "\n";
echo "=============================================\n\n";

// Listar tabelas
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
if (empty($tables)) {
    echo "Nenhuma tabela encontrada.\n";
    exit(0);
}

$totalRows = 0;
$byTable = [];

foreach ($tables as $table) {
    $count = 0;
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "`")->fetchColumn();
    } catch (Exception $e) {
        $count = -1;
    }
    $totalRows += $count >= 0 ? $count : 0;
    $byTable[$table] = ['rows' => $count, 'columns' => [], 'indexes' => []];
}

// Colunas e índices (INFORMATION_SCHEMA)
$stmtCol = $pdo->prepare("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ORDER BY ORDINAL_POSITION
");
$stmtIdx = $pdo->prepare("
    SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ORDER BY INDEX_NAME, SEQ_IN_INDEX
");

foreach ($tables as $table) {
    $stmtCol->execute([$db, $table]);
    $byTable[$table]['columns'] = $stmtCol->fetchAll(PDO::FETCH_ASSOC);
    $stmtIdx->execute([$db, $table]);
    $byTable[$table]['indexes'] = $stmtIdx->fetchAll(PDO::FETCH_ASSOC);
}

// Relacionamentos (FK)
$fkStmt = $pdo->prepare("
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME
");
$fkStmt->execute([$db]);
$fks = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

// Tamanho aproximado por tabela
$sizeStmt = $pdo->prepare("
    SELECT TABLE_NAME, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb, TABLE_ROWS
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = ?
    ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
");
$sizeStmt->execute([$db]);
$sizes = $sizeStmt->fetchAll(PDO::FETCH_ASSOC);

// ----- Resumo -----
echo "--- RESUMO ---\n";
echo "Tabelas: " . count($tables) . "\n";
echo "Total de registros (soma): " . number_format($totalRows, 0, ',', '.') . "\n";
if (!empty($sizes)) {
    $totalMb = array_sum(array_column($sizes, 'size_mb'));
    echo "Tamanho total (dados + índices): " . number_format($totalMb, 2, ',', '.') . " MB\n";
}
echo "\n";

// Tabelas por tamanho
if (!empty($sizes)) {
    echo "--- TABELAS POR TAMANHO (MB) ---\n";
    foreach ($sizes as $s) {
        $rows = $s['TABLE_ROWS'] ?? $byTable[$s['TABLE_NAME']]['rows'] ?? '?';
        echo sprintf("  %-35s %8s MB  ~%s linhas\n", $s['TABLE_NAME'], number_format($s['size_mb'], 2, ',', '.'), is_numeric($rows) ? number_format($rows, 0, ',', '.') : $rows);
    }
    echo "\n";
}

// Detalhes por tabela
echo "--- ESTRUTURA POR TABELA ---\n\n";
foreach ($tables as $table) {
    $info = $byTable[$table];
    $rows = $info['rows'] >= 0 ? number_format($info['rows'], 0, ',', '.') : 'erro';
    echo "■ {$table}  ({$rows} registros)\n";
    echo "  Colunas:\n";
    foreach ($info['columns'] as $col) {
        $key = $col['COLUMN_KEY'] ? " [{$col['COLUMN_KEY']}]" : '';
        $null = $col['IS_NULLABLE'] === 'YES' ? ' NULL' : '';
        echo "    - {$col['COLUMN_NAME']}  {$col['COLUMN_TYPE']}{$null}{$key}\n";
    }
    $idxGroups = [];
    foreach ($info['indexes'] as $idx) {
        $idxGroups[$idx['INDEX_NAME']][] = $idx['COLUMN_NAME'];
    }
    if (!empty($idxGroups)) {
        echo "  Índices:\n";
        foreach ($idxGroups as $idxName => $cols) {
            $unique = $info['indexes'][array_search($idxName, array_column($info['indexes'], 'INDEX_NAME'))]['NON_UNIQUE'] == 0 ? ' UNIQUE' : '';
            echo "    - {$idxName}{$unique}: (" . implode(', ', $cols) . ")\n";
        }
    }
    echo "\n";
}

// Foreign keys
if (!empty($fks)) {
    echo "--- RELACIONAMENTOS (FOREIGN KEYS) ---\n";
    $byTbl = [];
    foreach ($fks as $fk) {
        $byTbl[$fk['TABLE_NAME']][] = "{$fk['COLUMN_NAME']} → {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}";
    }
    foreach ($byTbl as $t => $refs) {
        echo "  {$t}:\n";
        foreach ($refs as $r) echo "    {$r}\n";
    }
    echo "\n";
}

echo "=============================================\n";
echo "  Fim da análise.\n";
echo "=============================================\n\n";
