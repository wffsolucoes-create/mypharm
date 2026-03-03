<?php
/**
 * Executa TODAS as importações em sequência (CLI, sem login).
 *
 * Relatórios importados (somente 2026 — não há outros):
 *   1. Dados/Relatórios de Orçamentos e Pedidos por Prescritor Resumido 2026.csv
 *   2. Dados/Relatório de Gestão de Pedidos 2026.csv
 *   3. Dados/Relatório de Itens de Orçamentos e Pedidos 2026.csv
 *   4. Dados/Relatórios de Orçamentos e Pedidos por Prescritor Detalhado com Componentes 2026.csv
 * (+ Histórico de Visitas XLSX, quando aplicável)
 *
 * Por padrão importa só 2026. Use --todos para importar todos os anos (2022-2026) na primeira carga.
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
// Histórico de Visitas: sempre vincular 2025 e 2026 na mesma tabela (conforme os XLSX em Dados/)
$anosVisitas = [2025, 2026];

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

// ========== 1. Prescritor Resumido: só INSERIR novos prescritores; NUNCA alterar visitador dos existentes ==========
// O vínculo prescritor→visitador é definitivo; só pode ser alterado pela tela (Ranking / transferência).
if (!$onlyVisitas) {
$pdo->exec("CREATE TABLE IF NOT EXISTS prescritores_cadastro (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(200) NOT NULL UNIQUE, visitador VARCHAR(150), usuario_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_nome (nome), INDEX idx_visitador (visitador), INDEX idx_usuario_id (usuario_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $pdo->exec("ALTER TABLE prescritores_cadastro ADD COLUMN usuario_id INT NULL");
    $pdo->exec("ALTER TABLE prescritores_cadastro ADD INDEX idx_usuario_id (usuario_id)");
} catch (Throwable $e) { /* coluna ou índice já existe */ }

$totalLinks = 0;
foreach ($anosPrescritor as $anoPrescritor) {
    $filePrescritor = $baseDir . "/Dados/Relatórios de Orçamentos e Pedidos por Prescritor Resumido {$anoPrescritor}.csv";
    echo "\n[1/6] Prescritor Resumido ({$anoPrescritor}) – só novos; visitador não é alterado... ";
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
            $sql = "INSERT INTO prescritores_cadastro (nome, visitador) VALUES {$placeholders} ON DUPLICATE KEY UPDATE visitador = visitador";
            $flat = [];
            foreach ($batch as $r) { $flat[] = $r[0]; $flat[] = $r[1]; }
            $pdo->prepare($sql)->execute($flat);
            $totalLinks += count($batch);
            $batch = [];
        }
    }
    if (!empty($batch)) {
        $placeholders = implode(',', array_fill(0, count($batch), '(?,?)'));
        $sql = "INSERT INTO prescritores_cadastro (nome, visitador) VALUES {$placeholders} ON DUPLICATE KEY UPDATE visitador = visitador";
        $flat = [];
        foreach ($batch as $r) { $flat[] = $r[0]; $flat[] = $r[1]; }
        $pdo->prepare($sql)->execute($flat);
        $totalLinks += count($batch);
    }
    fclose($handle);
    echo "{$count} linhas.\n";
}
// Sincronizar usuario_id apenas (não altera visitador)
$pdo->exec("
    UPDATE prescritores_cadastro pc
    INNER JOIN usuarios u ON TRIM(u.nome) = TRIM(pc.visitador) AND LOWER(TRIM(COALESCE(u.setor,''))) = 'visitador'
    SET pc.usuario_id = u.id
");
$pdo->exec("
    UPDATE prescritores_cadastro SET usuario_id = (SELECT id FROM usuarios WHERE TRIM(COALESCE(nome,'')) = 'My Pharm' AND LOWER(TRIM(COALESCE(setor,''))) = 'visitador' LIMIT 1)
    WHERE (visitador IS NULL OR TRIM(visitador) = '' OR TRIM(visitador) = 'My Pharm' OR UPPER(TRIM(visitador)) = 'MY PHARM')
");
if ($totalLinks > 0) echo "  Total: {$totalLinks} linhas; visitador dos existentes preservado.\n";
}

// ========== 2. Gestão de Pedidos (CSV) — padrão 2026; --todos = 2022-2026 ==========
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
    INDEX idx_ano (ano_referencia), INDEX idx_prescritor (prescritor(100)), INDEX idx_data_aprovacao (data_aprovacao), INDEX idx_status (status_financeiro),
    INDEX idx_numero_serie (numero_pedido, serie_pedido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $pdo->exec("ALTER TABLE gestao_pedidos ADD INDEX idx_numero_serie (numero_pedido, serie_pedido)");
} catch (Throwable $e) { /* índice já existe */ }
$sqlGp = "INSERT INTO gestao_pedidos (data_aprovacao, data_orcamento, canal_atendimento, numero_pedido, serie_pedido, forma_farmaceutica, produto, quantidade, preco_bruto, valor_subsidio, preco_custo, desconto, acrescimo, preco_liquido, cliente, paciente, prescritor, atendente, venda_pdv, cortesia, aprovador, orcamentista, status_financeiro, origem_acrescimo_desconto, convenio, ano_referencia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
foreach ($anosGestao as $anoGestao) {
    $fileGp = $baseDir . "/Dados/Relatório de Gestão de Pedidos {$anoGestao}.csv";
    echo "\n[2/6] Gestão de Pedidos {$anoGestao}... ";
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
echo "\n[3/6] Itens de Orçamentos e Pedidos...\n";
$php = (PHP_BINARY ?: 'php');
$cmd = $anoUnico !== null
    ? "{$php} " . escapeshellarg($baseDir . '/scripts/importar_itens_cli.php') . ' ' . $anoUnico
    : "{$php} " . escapeshellarg($baseDir . '/scripts/importar_itens_cli.php');
passthru($cmd);
echo "\n";
}

// ========== 4. Detalhado com Componentes (subprocess) — somente 2026 no uso diário ==========
if (!$onlyVisitas) {
$anoComponentes = $anoUnico ?? 2026;
echo "\n[4/6] Detalhado com Componentes ({$anoComponentes})...\n";
$php = (PHP_BINARY ?: 'php');
$cmd = "{$php} " . escapeshellarg($baseDir . '/scripts/importar_detalhado_componentes_cli.php') . ' ' . $anoComponentes;
passthru($cmd);
echo "\n";
}

// ========== 5. Prescritor Resumido (CSV) — profissão, totais por prescritor/ano ==========
// Preenche prescritor_resumido a partir do mesmo CSV "Relatórios de Orçamentos e Pedidos por Prescritor Resumido",
// incluindo Profissão (especialidade) para o gráfico "Distribuição por Especialidade". Sobrescreve o que o passo 3 gerou.
if (!$onlyVisitas) {
$pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_resumido (
    id INT AUTO_INCREMENT PRIMARY KEY, visitador VARCHAR(150), nome VARCHAR(255), profissao VARCHAR(100), sigla VARCHAR(20), uf VARCHAR(10),
    numero VARCHAR(50), aprovados INT DEFAULT 0, valor_aprovado DECIMAL(14,2) DEFAULT 0,
    recusados INT DEFAULT 0, valor_recusado DECIMAL(14,2) DEFAULT 0, no_carrinho INT DEFAULT 0, valor_no_carrinho DECIMAL(14,2) DEFAULT 0,
    considerar_desconto VARCHAR(50), percentual_desconto VARCHAR(50), ano_referencia INT NOT NULL,
    INDEX idx_ano (ano_referencia), INDEX idx_nome (nome(100)), INDEX idx_visitador (visitador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $pdo->exec("ALTER TABLE prescritor_resumido ADD COLUMN profissao VARCHAR(100) NULL");
} catch (Throwable $e) { /* coluna já existe */ }
try {
    $pdo->exec("ALTER TABLE prescritor_resumido ADD COLUMN sigla VARCHAR(20) NULL");
} catch (Throwable $e) { /* coluna já existe */ }
try {
    $pdo->exec("ALTER TABLE prescritor_resumido ADD COLUMN numero VARCHAR(50) NULL");
} catch (Throwable $e) { /* coluna já existe */ }
try {
    $pdo->exec("ALTER TABLE prescritor_resumido ADD COLUMN considerar_desconto VARCHAR(50) NULL");
} catch (Throwable $e) { /* coluna já existe */ }
try {
    $pdo->exec("ALTER TABLE prescritor_resumido ADD COLUMN percentual_desconto VARCHAR(50) NULL");
} catch (Throwable $e) { /* coluna já existe */ }

// Tabela prescritor_dados: um registro por prescritor (nome_prescritor PK), sem repetir; profissão e dados do CSV
$pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_dados (
    nome_prescritor VARCHAR(255) PRIMARY KEY,
    profissao VARCHAR(255) NULL,
    registro VARCHAR(100) NULL,
    uf_registro VARCHAR(10) NULL,
    data_nascimento DATE NULL,
    endereco_rua VARCHAR(255) NULL,
    endereco_numero VARCHAR(20) NULL,
    endereco_bairro VARCHAR(120) NULL,
    endereco_cep VARCHAR(20) NULL,
    endereco_cidade VARCHAR(120) NULL,
    endereco_uf VARCHAR(5) NULL,
    local_atendimento VARCHAR(50) NULL,
    whatsapp VARCHAR(30) NULL,
    email VARCHAR(255) NULL,
    usuario_id INT NULL,
    atualizado_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $pdo->exec("ALTER TABLE prescritor_dados ADD COLUMN usuario_id INT NULL");
} catch (Throwable $e) { /* coluna já existe */ }

echo "\n[5/6] Prescritor Resumido (CSV — profissão e totais) + prescritor_dados (todos, sem repetir)...\n";
$totalPrescResumido = 0;
$totalPrescDados = 0;
$batchDados = [];
foreach ($anosPrescritor as $anoPresc) {
    $filePrescritor = $baseDir . "/Dados/Relatórios de Orçamentos e Pedidos por Prescritor Resumido {$anoPresc}.csv";
    if (!file_exists($filePrescritor)) {
        echo "  {$anoPresc}: arquivo não encontrado.\n";
        continue;
    }
    deleteInBatches($pdo, 'prescritor_resumido', 'ano_referencia', $anoPresc);
    list($handle, $header) = readCsvHeader($filePrescritor);
    if (!$handle || !$header) {
        echo "  {$anoPresc}: erro ao ler CSV.\n";
        continue;
    }
    $batch = [];
    $count = 0;
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        $row = array_combine($header, array_pad($data, count($header), ''));
        // Fallback por índice (ordem do CSV): Visitador(0), Nome(1), Profissão(2), Sigla(3), UF(4), Número(5), Aprovados(6), Valor Aprovado(7), Recusados(8), Valor Recusado(9), No Carrinho(10), Valor No Carrinho(11), Considerar desconto(12), %Desconto(13)
        $visitador = trim($row['Visitador'] ?? $data[0] ?? '');
        $nome = trim($row['Nome'] ?? $data[1] ?? '');
        if ($nome === '' && $visitador === '') continue;
        $profissao = trim($row['Profissão'] ?? $row['Profissao'] ?? $data[2] ?? '');
        $sigla = trim($row['Sigla'] ?? $data[3] ?? '');
        $uf = trim($row['UF'] ?? $data[4] ?? '');
        $numero = trim($row['Número'] ?? $row['Numero'] ?? $data[5] ?? '');
        // prescritor_dados: um registro por prescritor (sem repetir); ON DUPLICATE KEY atualiza profissao/registro/uf
        if ($nome !== '') {
            $batchDados[] = [$nome, $profissao, $numero, $uf];
            if (count($batchDados) >= 500) {
                try {
                    $ph = implode(',', array_fill(0, count($batchDados), '(?,?,?,?,NOW())'));
                    $sqlDados = "INSERT INTO prescritor_dados (nome_prescritor, profissao, registro, uf_registro, atualizado_em) VALUES {$ph} ON DUPLICATE KEY UPDATE profissao = VALUES(profissao), registro = VALUES(registro), uf_registro = VALUES(uf_registro), atualizado_em = NOW()";
                    $flatDados = [];
                    foreach ($batchDados as $d) { $flatDados[] = $d[0]; $flatDados[] = $d[1]; $flatDados[] = $d[2]; $flatDados[] = $d[3]; }
                    $pdo->prepare($sqlDados)->execute($flatDados);
                    $totalPrescDados += count($batchDados);
                } catch (Throwable $e) {
                    echo "  ERRO prescritor_dados: " . $e->getMessage() . "\n";
                }
                $batchDados = [];
            }
        }
        $batch[] = [
            $visitador,
            $nome,
            $profissao,
            $sigla,
            $uf,
            $numero,
            (int)($row['Aprovados'] ?? $data[6] ?? 0),
            parseBRMoney($row['Valor Aprovado'] ?? $data[7] ?? '0'),
            (int)($row['Recusados'] ?? $data[8] ?? 0),
            parseBRMoney($row['Valor Recusado'] ?? $data[9] ?? '0'),
            (int)($row['No Carrinho'] ?? $data[10] ?? 0),
            parseBRMoney($row['Valor No Carrinho'] ?? $data[11] ?? '0'),
            trim($row['Considerar desconto'] ?? $data[12] ?? ''),
            trim($row['%Desconto ate'] ?? $row['%Desconto até'] ?? $data[13] ?? ''),
            $anoPresc
        ];
        $count++;
        if (count($batch) >= 500) {
            try {
                $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
                $sql = "INSERT INTO prescritor_resumido (visitador, nome, profissao, sigla, uf, numero, aprovados, valor_aprovado, recusados, valor_recusado, no_carrinho, valor_no_carrinho, considerar_desconto, percentual_desconto, ano_referencia) VALUES {$placeholders}";
                $flat = [];
                foreach ($batch as $r) { foreach ($r as $v) $flat[] = $v; }
                $pdo->prepare($sql)->execute($flat);
                $totalPrescResumido += count($batch);
            } catch (Throwable $e) {
                echo "  ERRO ao inserir lote {$anoPresc}: " . $e->getMessage() . "\n";
            }
            $batch = [];
        }
    }
    if (!empty($batch)) {
        try {
            $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $sql = "INSERT INTO prescritor_resumido (visitador, nome, profissao, sigla, uf, numero, aprovados, valor_aprovado, recusados, valor_recusado, no_carrinho, valor_no_carrinho, considerar_desconto, percentual_desconto, ano_referencia) VALUES {$placeholders}";
            $flat = [];
            foreach ($batch as $r) { foreach ($r as $v) $flat[] = $v; }
            $pdo->prepare($sql)->execute($flat);
            $totalPrescResumido += count($batch);
        } catch (Throwable $e) {
            echo "  ERRO ao inserir lote final {$anoPresc}: " . $e->getMessage() . "\n";
        }
    }
    fclose($handle);
    echo "  {$anoPresc}: {$count} prescritores (com profissão e totais).\n";
}
// Flush restante de prescritor_dados
if (!empty($batchDados)) {
    try {
        $ph = implode(',', array_fill(0, count($batchDados), '(?,?,?,?,NOW())'));
        $sqlDados = "INSERT INTO prescritor_dados (nome_prescritor, profissao, registro, uf_registro, atualizado_em) VALUES {$ph} ON DUPLICATE KEY UPDATE profissao = VALUES(profissao), registro = VALUES(registro), uf_registro = VALUES(uf_registro), atualizado_em = NOW()";
        $flatDados = [];
        foreach ($batchDados as $d) { $flatDados[] = $d[0]; $flatDados[] = $d[1]; $flatDados[] = $d[2]; $flatDados[] = $d[3]; }
        $pdo->prepare($sqlDados)->execute($flatDados);
        $totalPrescDados += count($batchDados);
    } catch (Throwable $e) {
        echo "  ERRO prescritor_dados (lote final): " . $e->getMessage() . "\n";
    }
}
if ($totalPrescResumido > 0) echo "  Total prescritor_resumido: {$totalPrescResumido} linhas.\n";
if ($totalPrescDados > 0) echo "  Total prescritor_dados: {$totalPrescDados} upserts (prescritores únicos com profissão/registro/UF).\n";
// Sincronizar usuario_id (visitador responsável) de prescritores_cadastro para prescritor_dados
$pdo->exec("
    UPDATE prescritor_dados pd
    INNER JOIN prescritores_cadastro pc ON pc.nome = pd.nome_prescritor
    SET pd.usuario_id = pc.usuario_id
");
}

// ========== 5. Histórico de Visitas (XLSX) ==========
// A partir de março tudo será feito pelo sistema; esta importação é APENAS para não perder o histórico legado (XLSX).
// Para 2026: NÃO apagamos antes de inserir — assim as visitas já registradas pelo sistema (a partir de março) são preservadas.
echo "\n[6/6] Histórico de Visitas (XLSX — legado; a partir de março o sistema registra as visitas)...\n";
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

// Só apagar anos anteriores a 2026 (legado). 2026 não é apagado para não perder visitas já cadastradas pelo sistema (a partir de março).
foreach ($anosVisitas as $anoV) {
    if ($anoV < 2026) {
        deleteInBatches($pdo, 'historico_visitas', 'ano_referencia', $anoV);
    }
}
$sqlVisitas = "INSERT INTO historico_visitas (visitador, prescritor, profissao, uf, registro, data_visita, horario, status_visita, local_visita, amostra, brinde, artigo, resumo_visita, reagendado_para, ano_referencia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

// Mapeamento coluna XLSX → valor: várias chaves possíveis (conforme cabeçalho do Relatório de Histórico de Visitas)
$col = function (array $row, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row)) {
            $v = $row[$k];
            return $v !== null && $v !== '' ? trim((string)$v) : '';
        }
    }
    $kLower = array_map('mb_strtolower', $keys);
    foreach ($row as $header => $val) {
        $h = trim((string)$header);
        if (in_array(mb_strtolower($h), $kLower, true)) {
            return $val !== null && $val !== '' ? trim((string)$val) : '';
        }
    }
    return '';
};
$hoje = new DateTime('now');
$limiteVisitas2026 = new DateTime(date('Y') . '-02-28');

foreach ($anosVisitas as $anoV) {
    echo "  ";
    // 2026: importar do XLSX só até 28/02; após isso os dados vêm do sistema
    if ($anoV === 2026 && $hoje > $limiteVisitas2026) {
        echo "2026: após 28/02 — importação do XLSX desativada (dados pelo sistema).\n";
        continue;
    }
    if ($anoV >= 2026) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM historico_visitas WHERE ano_referencia = {$anoV}")->fetchColumn();
        if ($n > 0) {
            echo "{$anoV}: já existem {$n} registros (sistema/legado). Pulando importação XLSX.\n";
            continue;
        }
    }
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
        $rawDate = $col($row, ['Data da Visita', 'Data da visita', 'Data Visita']);
        $dataVisita = null;
        if (is_numeric($rawDate) && (int)$rawDate > 40000) {
            $dataVisita = date('Y-m-d', ((int)$rawDate - 25569) * 86400);
        } else {
            $dataVisita = parseBRDate($rawDate);
        }
        $batch[] = [
            $col($row, ['Visitadores', 'Visitador']),
            $col($row, ['Prescritores', 'Prescritor']),
            $col($row, ['Profissao', 'Profissão']),
            $col($row, ['UF']),
            $col($row, ['Registro']),
            $dataVisita,
            $col($row, ['Horario', 'Horário']),
            $col($row, ['Status da Visita', 'Status da visita', 'Status']),
            $col($row, ['Local', 'Local da visita', 'Local da Visita']),
            $col($row, ['Amostra']),
            $col($row, ['Brinde']),
            $col($row, ['Artigo']),
            $col($row, ['Resumo da Visita', 'Resumo da visita', 'Resumo']),
            $col($row, ['Reagendado para', 'Reagendado para']),
            $anoV
        ];
        $count++;
        if (count($batch) >= 1000) { importBatch($pdo, $sqlVisitas, $batch); $batch = []; }
    }
    if (!empty($batch)) importBatch($pdo, $sqlVisitas, $batch);
    echo "  {$anoV}: {$count} visitas.\n";
}

// ========== Vínculo prescritor→visitador NÃO é mais alterado pela importação ==========
// O vínculo é definitivo; só pode ser modificado pela tela (Ranking de Prescritores / transferência).

echo "\n=== Todas as importações concluídas. ===\n";
