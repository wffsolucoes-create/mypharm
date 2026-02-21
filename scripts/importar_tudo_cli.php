<?php
/**
 * Executa TODAS as importações em sequência (CLI, sem login).
 * Por padrão importa só 2026. Use --todos para importar todos os anos (2022-2026).
 *
 * 1. Prescritor Resumido (CSV)
 * 2. Gestão de Pedidos (CSV)
 * 3. Itens de Orçamentos e Pedidos (CSV)
 * 4. Histórico de Visitas (XLSX)
 *
 * Uso (na pasta mypharm):
 *   php scripts/importar_tudo_cli.php           → só 2026 (padrão, uso diário)
 *   php scripts/importar_tudo_cli.php --todos   → todos os anos (2022-2026, primeira vez)
 *   php scripts/importar_tudo_cli.php 2025      → só ano 2025
 *   php scripts/importar_tudo_cli.php --only=visitas → só Histórico de Visitas (2025 e 2026)
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php scripts/importar_tudo_cli.php [ano]');
}
$argvList = $argv ?? [];
$onlyVisitas = in_array('--only=visitas', $argvList, true);
$modoTodos = in_array('--todos', $argvList, true);

$baseDir = dirname(__DIR__);

// Carregar .env
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
    echo "Erro DB: " . $e->getMessage() . "\n";
    exit(1);
}

set_time_limit(0);
ini_set('memory_limit', '1G');

// Padrão: só 2026. --todos = todos os anos (2022-2026). Ano no argumento = só esse ano.
$anoUnico = null;
if ($modoTodos) {
    $anoUnico = null; // todos
} else {
    foreach ($argvList as $arg) {
        if (preg_match('/^20\d{2}$/', $arg)) { $anoUnico = (int)$arg; break; }
    }
    if ($anoUnico === null) {
        $anoUnico = 2026; // padrão: só 2026
    }
}
$anosItens = $anoUnico !== null ? [$anoUnico] : range(2022, 2026);
$anosPrescritor = $anoUnico !== null ? [$anoUnico] : range(2022, 2026);
$anosGestao = $anoUnico !== null ? [$anoUnico] : range(2022, 2026);
$anosVisitas = $anoUnico !== null ? [$anoUnico] : [2025, 2026];

echo "=== Importação MyPharm ===\n";
if ($onlyVisitas) {
    echo "Modo: só Histórico de Visitas (2025 e 2026).\n";
} elseif ($modoTodos) {
    echo "Modo: todos os anos (Prescritor + Gestão + Itens 2022-2026, Visitas 2025-2026).\n";
} else {
    echo "Modo: só ano {$anoUnico}\n";
}

// --- Funções ---
function parseBRDate($s) {
    $s = trim($s);
    if ($s === '') return null;
    $dt = DateTime::createFromFormat('d/m/Y H:i:s', $s);
    if ($dt) return $dt->format('Y-m-d H:i:s');
    $dt = DateTime::createFromFormat('d/m/Y', $s);
    if ($dt) return $dt->format('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    if ($dt) return $dt->format('Y-m-d H:i:s');
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    return $dt ? $dt->format('Y-m-d') : null;
}

function parseBRMoney($s) {
    $s = trim($s);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' ', '.'], ['', '', ''], $s);
    $s = str_replace(',', '.', $s);
    return (float)$s;
}

function readCsvHeader($path, $delim = ';') {
    $h = fopen($path, 'r');
    if (!$h) return [null, null];
    if (fread($h, 3) !== "\xEF\xBB\xBF") rewind($h);
    $header = fgetcsv($h, 0, $delim);
    if ($header) $header = array_map(function ($x) { return trim($x, "\xEF\xBB\xBF \t\n\r"); }, $header);
    return [$h, $header];
}

function deleteInBatches($pdo, $table, $column, $value, $batchSize = 500) {
    $deleted = 0;
    do {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$column} = ? LIMIT {$batchSize}");
        $stmt->execute([$value]);
        $rows = $stmt->rowCount();
        $deleted += $rows;
    } while ($rows > 0);
    return $deleted;
}

function importBatch($pdo, $sql, $rows, $chunkSize = 500) {
    if (empty($rows)) return;
    if (!preg_match('/^INSERT\s+INTO\s+(\S+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql, $m)) {
        $pdo->beginTransaction();
        $st = $pdo->prepare($sql);
        foreach ($rows as $r) $st->execute($r);
        $pdo->commit();
        return;
    }
    $table = $m[1];
    $cols = $m[2];
    $numPlaceholders = count(explode(',', trim($m[3])));
    $chunks = array_chunk($rows, $chunkSize);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, $numPlaceholders, '?')) . ')'));
        $multiSql = "INSERT INTO {$table} ({$cols}) VALUES {$placeholders}";
        $flat = [];
        foreach ($chunk as $row) { foreach ($row as $v) $flat[] = $v; }
        $pdo->prepare($multiSql)->execute($flat);
    }
}

function readXlsx($filePath) {
    $rows = [];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return $rows;
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        $ss->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($ss->si as $si) {
            $text = '';
            if ($si->t) $text = (string)$si->t;
            elseif ($si->r) { foreach ($si->r as $r) $text .= (string)$r->t; }
            $sharedStrings[] = $text;
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) { $zip->close(); return $rows; }
    $sheet = new SimpleXMLElement($sheetXml);
    $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $header = [];
    $isFirst = true;
    foreach ($sheet->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $value = '';
            $type = (string)$cell['t'];
            $v = (string)$cell->v;
            if ($type === 's') $value = $sharedStrings[intval($v)] ?? '';
            elseif ($type === 'inlineStr') $value = (string)$cell->is->t;
            else $value = $v;
            $rowData[] = $value;
        }
        if ($isFirst) { $header = $rowData; $isFirst = false; }
        else {
            if (count($rowData) < count($header)) $rowData = array_pad($rowData, count($header), '');
            if (count($rowData) >= count($header)) $rows[] = array_combine($header, array_slice($rowData, 0, count($header)));
        }
    }
    $zip->close();
    return $rows;
}

// ========== 1. Vínculo Prescritor → Visitador (só Nome + Visitador dos CSVs Prescritor Resumido) ==========
// Esses CSVs servem apenas para ligar prescritor ao visitador em prescritores_cadastro. Não preenche prescritor_resumido.
if (!$onlyVisitas) {
$pdo->exec("CREATE TABLE IF NOT EXISTS prescritores_cadastro (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(200) NOT NULL UNIQUE, visitador VARCHAR(150), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_nome (nome), INDEX idx_visitador (visitador)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$totalLinks = 0;
foreach ($anosPrescritor as $anoPrescritor) {
    $filePrescritor = $baseDir . "/Dados/Relatórios de Orçamentos e Pedidos por Prescritor Resumido {$anoPrescritor}.csv";
    echo "\n[1/4] Vínculo prescritor→visitador ({$anoPrescritor})... ";
    if (!file_exists($filePrescritor)) {
        echo "arquivo não encontrado.\n";
        continue;
    }
    list($handle, $header) = readCsvHeader($filePrescritor);
    if (!$handle || !$header) { echo "erro ao ler CSV.\n"; continue; }
    $batch = [];
    $count = 0;
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        if (count($data) !== count($header)) continue;
        $row = array_combine($header, $data);
        $nome = trim($row['Nome'] ?? '');
        $visitador = trim($row['Visitador'] ?? '');
        if ($nome === '' && $visitador === '') continue;
        $batch[] = [$nome, $visitador];
        $count++;
        if (count($batch) >= 500) {
            $placeholders = implode(',', array_fill(0, count($batch), '(?,?)'));
            $sql = "INSERT INTO prescritores_cadastro (nome, visitador) VALUES {$placeholders} ON DUPLICATE KEY UPDATE visitador = VALUES(visitador)";
            $flat = [];
            foreach ($batch as $r) { $flat[] = $r[0]; $flat[] = $r[1]; }
            $pdo->prepare($sql)->execute($flat);
            $totalLinks += count($batch);
            $batch = [];
        }
    }
    if (!empty($batch)) {
        $placeholders = implode(',', array_fill(0, count($batch), '(?,?)'));
        $sql = "INSERT INTO prescritores_cadastro (nome, visitador) VALUES {$placeholders} ON DUPLICATE KEY UPDATE visitador = VALUES(visitador)";
        $flat = [];
        foreach ($batch as $r) { $flat[] = $r[0]; $flat[] = $r[1]; }
        $pdo->prepare($sql)->execute($flat);
        $totalLinks += count($batch);
    }
    fclose($handle);
    echo "{$count} vínculos.\n";
}
if ($totalLinks > 0) echo "  Total: {$totalLinks} vínculos (prescritor→visitador) atualizados.\n";
}

// ========== 2. Gestão de Pedidos (CSV) — anos 2022-2026 ==========
if (!$onlyVisitas) {
$pdo->exec("CREATE TABLE IF NOT EXISTS gestao_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data_aprovacao DATETIME NULL, data_orcamento DATETIME NULL, canal_atendimento VARCHAR(100), numero_pedido INT DEFAULT 0, serie_pedido INT DEFAULT 0,
    forma_farmaceutica VARCHAR(100), produto VARCHAR(255), quantidade INT DEFAULT 1,
    preco_bruto DECIMAL(14,2) DEFAULT 0, valor_subsidio DECIMAL(14,2) DEFAULT 0, preco_custo DECIMAL(14,2) DEFAULT 0,
    desconto DECIMAL(14,2) DEFAULT 0, acrescimo DECIMAL(14,2) DEFAULT 0, preco_liquido DECIMAL(14,2) DEFAULT 0,
    cliente VARCHAR(255), paciente VARCHAR(255), prescritor VARCHAR(255), atendente VARCHAR(100),
    venda_pdv VARCHAR(20), cortesia VARCHAR(20), aprovador VARCHAR(100), orcamentista VARCHAR(100),
    status_financeiro VARCHAR(100), origem_acrescimo_desconto VARCHAR(100), convenio VARCHAR(255),
    ano_referencia INT NOT NULL,
    INDEX idx_ano (ano_referencia), INDEX idx_prescritor (prescritor(100)), INDEX idx_data_aprovacao (data_aprovacao), INDEX idx_status (status_financeiro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$sqlGp = "INSERT INTO gestao_pedidos (data_aprovacao, data_orcamento, canal_atendimento, numero_pedido, serie_pedido, forma_farmaceutica, produto, quantidade, preco_bruto, valor_subsidio, preco_custo, desconto, acrescimo, preco_liquido, cliente, paciente, prescritor, atendente, venda_pdv, cortesia, aprovador, orcamentista, status_financeiro, origem_acrescimo_desconto, convenio, ano_referencia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
foreach ($anosGestao as $anoGestao) {
    $fileGp = $baseDir . "/Dados/Relatório de Gestão de Pedidos {$anoGestao}.csv";
    echo "\n[2/4] Gestão de Pedidos {$anoGestao}... ";
    if (!file_exists($fileGp)) {
        echo "arquivo não encontrado.\n";
        continue;
    }
    deleteInBatches($pdo, 'gestao_pedidos', 'ano_referencia', $anoGestao);
    list($handle, $header) = readCsvHeader($fileGp);
    if (!$handle || !$header) { echo "erro ao ler CSV.\n"; continue; }
    $batch = [];
    $count = 0;
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        if (count($data) !== count($header)) continue;
        $row = array_combine($header, $data);
        $batch[] = [
            parseBRDate($row['Data da Aprovação'] ?? ''),
            parseBRDate($row['Data de Orçamento'] ?? ''),
            trim($row['Canal de Atendimento'] ?? ''),
            (int)($row['Numero do Pedido'] ?? 0),
            (int)($row['Serie do Pedido'] ?? 0),
            trim($row['Forma Farmaceutica'] ?? ''),
            trim($row['Produto'] ?? ''),
            (int)($row['Quantidade'] ?? 1),
            parseBRMoney($row['Preço Bruto'] ?? '0'),
            parseBRMoney($row['Valor Subsídio'] ?? '0'),
            parseBRMoney($row['Preço Custo'] ?? '0'),
            parseBRMoney($row['Desconto'] ?? '0'),
            parseBRMoney($row['Acréscimo'] ?? '0'),
            parseBRMoney($row['Preço Líquido'] ?? '0'),
            trim($row['Cliente'] ?? ''),
            trim($row['Paciente'] ?? ''),
            trim($row['Prescritor'] ?? ''),
            trim($row['Atendente'] ?? ''),
            trim($row['Venda PDV'] ?? ''),
            trim($row['Cortesia'] ?? ''),
            trim($row['Aprovador'] ?? ''),
            trim($row['Orçamentista'] ?? ''),
            trim($row['Status Financeiro'] ?? ''),
            trim($row['Origem Acrescimo Desconto'] ?? ''),
            trim($row['Convênio'] ?? ''),
            $anoGestao
        ];
        $count++;
        if (count($batch) >= 2000) { importBatch($pdo, $sqlGp, $batch); $batch = []; }
    }
    if (!empty($batch)) importBatch($pdo, $sqlGp, $batch);
    fclose($handle);
    echo "{$count} registros.\n";
}
}

// ========== 3. Itens (subprocess: importar_itens_cli.php) ==========
if (!$onlyVisitas) {
echo "\n[3/4] Itens de Orçamentos e Pedidos...\n";
$php = (PHP_BINARY ?: 'php');
$cmd = $anoUnico !== null
    ? "{$php} " . escapeshellarg($baseDir . '/scripts/importar_itens_cli.php') . ' ' . $anoUnico
    : "{$php} " . escapeshellarg($baseDir . '/scripts/importar_itens_cli.php');
passthru($cmd);
echo "\n";
}

// ========== 4. Histórico de Visitas (XLSX) ==========
echo "[4/4] Histórico de Visitas (XLSX)...\n";
// Reconectar (a conexão pode ter expirado após o subprocess dos itens)
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=$charset",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo "Erro ao reconectar DB: " . $e->getMessage() . "\n";
    exit(1);
}
$pdo->exec("CREATE TABLE IF NOT EXISTS historico_visitas (
    id INT AUTO_INCREMENT PRIMARY KEY, visitador VARCHAR(255), prescritor VARCHAR(255), profissao VARCHAR(100), uf VARCHAR(10), registro VARCHAR(50),
    data_visita DATE, horario VARCHAR(50), status_visita VARCHAR(100), local_visita TEXT, amostra TEXT, brinde TEXT, artigo TEXT, resumo_visita TEXT, reagendado_para VARCHAR(100), ano_referencia INT,
    INDEX idx_visitador (visitador), INDEX idx_prescritor (prescritor), INDEX idx_data (data_visita), INDEX idx_ano (ano_referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

foreach ($anosVisitas as $anoV) {
    deleteInBatches($pdo, 'historico_visitas', 'ano_referencia', $anoV);
}
$sqlVisitas = "INSERT INTO historico_visitas (visitador, prescritor, profissao, uf, registro, data_visita, horario, status_visita, local_visita, amostra, brinde, artigo, resumo_visita, reagendado_para, ano_referencia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

foreach ($anosVisitas as $anoV) {
    echo "  ";
    $fileV = $baseDir . "/Dados/Relatório de Histórico de Visitas {$anoV}.xlsx";
    if (!file_exists($fileV)) {
        echo "{$anoV}: arquivo não encontrado.\n";
        continue;
    }
    $rowsV = readXlsx($fileV);
    if (empty($rowsV)) { echo "  {$anoV}: vazio ou não legível.\n"; continue; }
    $batch = [];
    $count = 0;
    foreach ($rowsV as $row) {
        $rawDate = $row['Data da Visita'] ?? '';
        $dataVisita = null;
        if (is_numeric($rawDate) && (int)$rawDate > 40000) $dataVisita = date('Y-m-d', ((int)$rawDate - 25569) * 86400);
        else $dataVisita = parseBRDate($rawDate);
        $batch[] = [
            trim($row['Visitadores'] ?? $row['Visitador'] ?? ''), trim($row['Prescritores'] ?? $row['Prescritor'] ?? ''),
            trim($row['Profissao'] ?? $row['Profissão'] ?? ''), trim($row['UF'] ?? ''), trim($row['Registro'] ?? ''),
            $dataVisita, trim($row['Horario'] ?? $row['Horário'] ?? ''), trim($row['Status da Visita'] ?? $row['Status da visita'] ?? ''),
            trim($row['Local'] ?? ''), trim($row['Amostra'] ?? ''), trim($row['Brinde'] ?? ''), trim($row['Artigo'] ?? ''),
            trim($row['Resumo da Visita'] ?? $row['Resumo da visita'] ?? ''), trim($row['Reagendado para'] ?? ''), $anoV
        ];
        $count++;
        if (count($batch) >= 1000) { importBatch($pdo, $sqlVisitas, $batch); $batch = []; }
    }
    if (!empty($batch)) importBatch($pdo, $sqlVisitas, $batch);
    echo "  {$anoV}: {$count} visitas.\n";
}

echo "\n=== Todas as importações concluídas. ===\n";
