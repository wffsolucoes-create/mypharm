<?php
/**
 * Importa o CSV "Relatório de Itens de Orçamentos e Pedidos".
 * Relatório usado: Dados/Relatório de Itens de Orçamentos e Pedidos 2026.csv
 * (por padrão só 2026; 2025 já está no banco e não muda mais.)
 * Atualiza prescritores_cadastro + prescritor_resumido.
 *
 * Uso (na pasta mypharm):
 *   php scripts/importar_itens_cli.php        → importa só 2026 (padrão)
 *   php scripts/importar_itens_cli.php 2025   → importa só 2025 (se precisar repor)
 *   php scripts/importar_itens_cli.php 2025 2026
 *
 * REGRA: Prescritores e visitadores são gerenciados pelo SISTEMA. Na importação
 * só adicionamos prescritores que ainda NÃO estão no banco (ex.: nome novo no relatório).
 * Prescritores já cadastrados não têm visitador/nome alterados.
 *
 * REGRA - Nome do prescritor = finalidade de venda (não normalizar):
 * "REP - Nome" ≠ "Nome" ≠ "Nome."  (prefixo e ponto no final = outra finalidade).
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php importar_itens_cli.php');
}

// Carregar .env
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

function parseBRDate($s) {
    $s = trim($s);
    if ($s === '') return null;
    $dt = DateTime::createFromFormat('d/m/Y H:i:s', $s);
    if ($dt) return $dt->format('Y-m-d H:i:s');
    $dt = DateTime::createFromFormat('d/m/Y', $s);
    if ($dt) return $dt->format('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    return $dt ? $dt->format('Y-m-d') : null;
}

function parseBRMoney($s) {
    $s = trim($s);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' ', '.'], ['', '', ''], $s);
    $s = str_replace(',', '.', $s);
    return (float) $s;
}

function readCsvHeader($path, $delim = ';') {
    $h = fopen($path, 'r');
    if (!$h) return [null, null];
    if (fread($h, 3) !== "\xEF\xBB\xBF") rewind($h);
    $header = fgetcsv($h, 0, $delim);
    if ($header) $header = array_map(function ($x) { return trim($x, "\xEF\xBB\xBF \t\n\r"); }, $header);
    return [$h, $header];
}

/** INSERT em massa: várias linhas por query (muito mais rápido que 1 execute por linha). */
const BULK_INSERT_CHUNK = 500;

function importBatch($pdo, $sql, $rows) {
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
    $chunks = array_chunk($rows, BULK_INSERT_CHUNK);
    foreach ($chunks as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, $numPlaceholders, '?')) . ')'));
        $multiSql = "INSERT INTO {$table} ({$cols}) VALUES {$placeholders}";
        $flat = [];
        foreach ($chunk as $row) {
            foreach ($row as $v) $flat[] = $v;
        }
        $pdo->prepare($multiSql)->execute($flat);
    }
}

// Garantir tabelas
$pdo->exec("CREATE TABLE IF NOT EXISTS prescritores_cadastro (
    id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(200) NOT NULL UNIQUE, visitador VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nome (nome), INDEX idx_visitador (visitador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS itens_orcamentos_pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY, filial VARCHAR(100), numero INT NOT NULL DEFAULT 0, serie INT NOT NULL DEFAULT 0,
    data DATE NULL, canal VARCHAR(50), forma_farmaceutica VARCHAR(100), descricao VARCHAR(255),
    quantidade INT NOT NULL DEFAULT 1, unidade VARCHAR(20),
    valor_bruto DECIMAL(14,2) DEFAULT 0, valor_liquido DECIMAL(14,2) DEFAULT 0, preco_custo DECIMAL(14,2) DEFAULT 0, fator DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(50), usuario_inclusao VARCHAR(100), usuario_aprovador VARCHAR(100),
    paciente VARCHAR(255), prescritor VARCHAR(255), status_financeiro VARCHAR(50), ano_referencia INT NOT NULL,
    INDEX idx_ano (ano_referencia), INDEX idx_prescritor (prescritor(100)), INDEX idx_status (status_financeiro),
    INDEX idx_numero_serie (numero, serie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $pdo->exec("ALTER TABLE itens_orcamentos_pedidos ADD INDEX idx_numero_serie (numero, serie)");
} catch (Throwable $e) { /* já existe */ }

$pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_resumido (
    id INT AUTO_INCREMENT PRIMARY KEY, visitador VARCHAR(150), nome VARCHAR(255), profissao VARCHAR(100), sigla VARCHAR(20), uf VARCHAR(10),
    numero VARCHAR(50), aprovados INT DEFAULT 0, valor_aprovado DECIMAL(14,2) DEFAULT 0,
    recusados INT DEFAULT 0, valor_recusado DECIMAL(14,2) DEFAULT 0, no_carrinho INT DEFAULT 0, valor_no_carrinho DECIMAL(14,2) DEFAULT 0,
    considerar_desconto VARCHAR(50), percentual_desconto VARCHAR(50), ano_referencia INT NOT NULL,
    INDEX idx_ano (ano_referencia), INDEX idx_nome (nome(100)), INDEX idx_visitador (visitador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Cadastro = fonte da verdade. Só inserir prescritores que ainda não existem.
$prescritorMap = [];
foreach ($pdo->query("SELECT nome, visitador FROM prescritores_cadastro") as $r) {
    $prescritorMap[strtoupper(trim($r['nome'] ?? ''))] = $r['visitador'] ?? '';
}
$stmtNew = $pdo->prepare("INSERT IGNORE INTO prescritores_cadastro (nome, visitador) VALUES (:nome, :visitador)");

$sql = "INSERT INTO itens_orcamentos_pedidos (filial, numero, serie, data, canal, forma_farmaceutica, descricao, quantidade, unidade, valor_bruto, valor_liquido, preco_custo, fator, status, usuario_inclusao, usuario_aprovador, paciente, prescritor, status_financeiro, ano_referencia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

// Sem argumentos: importa só 2026 (2025 fixo no banco). Com anos: php importar_itens_cli.php 2026 ou 2025 2026
$anos = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^20\d{2}$/', $arg)) $anos[] = (int)$arg;
}
if (empty($anos)) $anos = [2026];
echo "Anos a importar: " . implode(', ', $anos) . "\n";

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

$total = 0;
foreach ($anos as $ano) {
    $file = dirname(__DIR__) . "/Dados/Relatório de Itens de Orçamentos e Pedidos {$ano}.csv";
    if (!file_exists($file)) {
        echo "Arquivo não encontrado: {$ano}\n";
        continue;
    }
    echo "Limpando itens {$ano}...";
    $del = deleteInBatches($pdo, 'itens_orcamentos_pedidos', 'ano_referencia', $ano);
    echo " {$del} removidos.\n";
    list($handle, $header) = readCsvHeader($file);
    if (!$handle || !$header) {
        echo "Erro ao ler CSV {$ano}\n";
        continue;
    }
    $count = 0;
    $batch = [];
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        if (count($data) !== count($header)) continue;
        $row = array_combine($header, $data);
        $nomePrescritor = trim($row['Prescritor'] ?? '');
        $nomeUpper = strtoupper($nomePrescritor);
        if ($nomePrescritor === '') {
            if (!isset($prescritorMap[''])) {
                $stmtNew->execute(['nome' => '', 'visitador' => 'My Pharm']);
                $prescritorMap[''] = 'My Pharm';
            }
        } else {
            if (!isset($prescritorMap[$nomeUpper])) {
                $stmtNew->execute(['nome' => $nomePrescritor, 'visitador' => 'My Pharm']);
                $prescritorMap[$nomeUpper] = 'My Pharm';
            }
        }
        $batch[] = [
            trim($row['Filial'] ?? ''),
            (int)($row['Número'] ?? 0),
            (int)($row['Série'] ?? 0),
            parseBRDate($row['Data'] ?? ''),
            trim($row['Canal'] ?? ''),
            trim($row['Forma Farmacêutica'] ?? ''),
            trim($row['Descriçao'] ?? $row['Descrição'] ?? ''),
            (int)($row['Quantidade'] ?? 1),
            trim($row['Unidade'] ?? ''),
            parseBRMoney($row['Valor Bruto'] ?? '0'),
            parseBRMoney($row['Valor Líquido'] ?? '0'),
            parseBRMoney($row['Preço Custo'] ?? '0'),
            (float)str_replace(',', '.', $row['Fator'] ?? '0'),
            trim($row['Status'] ?? ''),
            trim($row['Usuário inclusão'] ?? ''),
            trim($row['Usuário aprovador'] ?? ''),
            trim($row['Paciente'] ?? ''),
            trim($row['Prescritor'] ?? ''),
            trim($row['Status Financeiro'] ?? ''),
            $ano
        ];
        $count++;
        if (count($batch) >= 2000) {
            importBatch($pdo, $sql, $batch);
            $batch = [];
        }
    }
    if (!empty($batch)) importBatch($pdo, $sql, $batch);
    fclose($handle);
    $total += $count;
    echo "{$ano}: {$count} itens\n";
}

// Atualizar prescritor_resumido a partir dos itens (só os anos que foram importados)
foreach ($anos as $anoRef) {
    deleteInBatches($pdo, 'prescritor_resumido', 'ano_referencia', $anoRef);
    $pdo->exec("
        INSERT INTO prescritor_resumido (visitador, nome, aprovados, valor_aprovado, recusados, valor_recusado, no_carrinho, valor_no_carrinho, ano_referencia)
        SELECT COALESCE(pc.visitador, 'My Pharm'), COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'),
            SUM(CASE WHEN i.status = 'Aprovado' THEN 1 ELSE 0 END), SUM(CASE WHEN i.status = 'Aprovado' THEN i.valor_liquido ELSE 0 END),
            SUM(CASE WHEN i.status = 'Recusado' THEN 1 ELSE 0 END), SUM(CASE WHEN i.status = 'Recusado' THEN i.valor_liquido ELSE 0 END),
            SUM(CASE WHEN i.status = 'No carrinho' THEN 1 ELSE 0 END), SUM(CASE WHEN i.status = 'No carrinho' THEN i.valor_liquido ELSE 0 END),
            " . (int)$anoRef . "
        FROM itens_orcamentos_pedidos i
        LEFT JOIN prescritores_cadastro pc ON UPPER(TRIM(pc.nome)) = UPPER(TRIM(COALESCE(i.prescritor, '')))
        WHERE i.ano_referencia = " . (int)$anoRef . "
        GROUP BY COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'), COALESCE(pc.visitador, 'My Pharm')
    ");
}
echo "prescritor_resumido atualizado (" . implode(', ', $anos) . ").\n";
echo "Total de itens importados: {$total}\n";
echo "Concluído.\n";
