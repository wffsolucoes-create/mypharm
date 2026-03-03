<?php
/**
 * Importação via interface web (requer login).
 * Ao clicar em "Importar Dados" são executadas SOMENTE estas 4 importações (2026):
 *   1. Relatórios de Orçamentos e Pedidos por Prescritor Resumido 2026.csv
 *   2. Relatório de Gestão de Pedidos 2026.csv
 *   3. Relatório de Itens de Orçamentos e Pedidos 2026.csv
 *   4. Relatórios de Orçamentos e Pedidos por Prescritor Detalhado com Componentes 2026.csv
 * Nenhum outro relatório é importado por este script.
 */
require_once dirname(__DIR__) . '/config.php';

set_time_limit(0);
ini_set('memory_limit', '1G');

// Verificação de autenticação
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Acesso negado']));
}

header('Content-Type: application/json; charset=utf-8');

$pdo = getConnection();
$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);

function parseBRDate($dateStr)
{
    $dateStr = trim($dateStr);
    if (empty($dateStr))
        return null;
    $dateStr = trim($dateStr, "\t \n\r");
    $dt = DateTime::createFromFormat('d/m/Y H:i:s', $dateStr);
    if ($dt)
        return $dt->format('Y-m-d H:i:s');
    $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
    if ($dt)
        return $dt->format('Y-m-d');
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr);
    if ($dt)
        return $dt->format('Y-m-d H:i:s');
    $dt = DateTime::createFromFormat('Y-m-d', $dateStr);
    if ($dt)
        return $dt->format('Y-m-d');
    return null;
}

function parseBRMoney($value)
{
    $value = trim($value);
    if (empty($value))
        return 0;
    $value = str_replace(['R$', ' ', '.'], ['', '', ''], $value);
    $value = str_replace(',', '.', $value);
    return floatval($value);
}

function readCsvHeader($filePath, $delimiter = ';')
{
    $handle = fopen($filePath, 'r');
    if (!$handle)
        return [null, null];
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF")
        rewind($handle);
    $header = fgetcsv($handle, 0, $delimiter);
    if ($header) {
        $header = array_map(function ($h) {
            return trim($h, "\xEF\xBB\xBF \t\n\r");
        }, $header);
    }
    return [$handle, $header];
}

function importBatch($pdo, $sql, $rows)
{
    if (empty($rows))
        return;

    if (preg_match('/^INSERT\s+INTO\s+(\S+)\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql, $m)) {
        $table = $m[1];
        $cols = $m[2];
        $numPlaceholders = count(explode(',', $m[3]));

        $chunks = array_chunk($rows, 500);
        foreach ($chunks as $chunk) {
            $placeholderRow = '(' . implode(',', array_fill(0, $numPlaceholders, '?')) . ')';
            $allPlaceholders = implode(',', array_fill(0, count($chunk), $placeholderRow));
            $multiSql = "INSERT INTO {$table} ({$cols}) VALUES {$allPlaceholders}";
            $flat = [];
            foreach ($chunk as $row) {
                foreach ($row as $v) $flat[] = $v;
            }
            $pdo->prepare($multiSql)->execute($flat);
        }
        return;
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare($sql);
    foreach ($rows as $row) {
        $stmt->execute($row);
    }
    $pdo->commit();
}

/**
 * Lê um arquivo XLSX usando ZipArchive + SimpleXML (sem dependências externas)
 * Retorna array de arrays associativos (header => value)
 */
function readXlsx($filePath)
{
    $rows = [];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return $rows;
    }

    // Ler shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        $ss->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($ss->si as $si) {
            $text = '';
            if ($si->t) {
                $text = (string)$si->t;
            }
            elseif ($si->r) {
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
            }
            $sharedStrings[] = $text;
        }
    }

    // Ler sheet1
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        $zip->close();
        return $rows;
    }

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

            if ($type === 's') {
                // Shared string
                $value = $sharedStrings[intval($v)] ?? '';
            }
            elseif ($type === 'inlineStr') {
                $value = (string)$cell->is->t;
            }
            else {
                $value = $v;
            }
            $rowData[] = $value;
        }

        if ($isFirst) {
            $header = $rowData;
            $isFirst = false;
        }
        else {
            if (count($rowData) === count($header)) {
                $rows[] = array_combine($header, $rowData);
            }
            elseif (count($rowData) < count($header)) {
                // Preencher com vazio se tiver menos colunas
                $rowData = array_pad($rowData, count($header), '');
                $rows[] = array_combine($header, $rowData);
            }
        }
    }

    $zip->close();
    return $rows;
}

ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

$results = [];
$errors = [];
$ano = 2026;
$startTime = microtime(true);

// ============================================
// REGRA: O vínculo prescritor→visitador é DEFINITIVO e só é alterado pela tela (Ranking / transferência).
// Na importação: só inserimos prescritores NOVOS (que ainda não estão no banco); nunca alteramos visitador dos existentes.
// A importação traz apenas pedidos/dados; o cadastro de visitador permanece como está.
// ============================================

// ============================================
// 1. Prescritor Resumido 2026
// ============================================
try {
    $file = dirname(__DIR__) . "/Dados/Relatórios de Orçamentos e Pedidos por Prescritor Resumido {$ano}.csv";
    if (file_exists($file)) {
        // Apagar somente dados de 2026
        $pdo->exec("DELETE FROM prescritor_resumido WHERE ano_referencia = {$ano}");

        list($handle, $header) = readCsvHeader($file);
        if (!$handle || !$header)
            throw new Exception("Não foi possível ler o arquivo CSV");

        // ===========================================
        // CARREGAR MAPA DE PRESCRITORES (CACHE)
        // ===========================================
        $stmtMap = $pdo->query("SELECT nome, visitador FROM prescritores_cadastro");
        $prescritorMap = [];
        while ($rowMap = $stmtMap->fetch(PDO::FETCH_ASSOC)) {
            $prescritorMap[strtoupper(trim($rowMap['nome']))] = $rowMap['visitador'];
        }

        // Prepare insert for new prescribers
        $stmtNewPrescritor = $pdo->prepare("INSERT IGNORE INTO prescritores_cadastro (nome, visitador) VALUES (:nome, :visitador)");

        $count = 0;
        $batch = [];
        $sql = "INSERT INTO prescritor_resumido 
            (visitador, nome, profissao, sigla, uf, numero, aprovados, valor_aprovado,
             recusados, valor_recusado, no_carrinho, valor_no_carrinho, 
             considerar_desconto, percentual_desconto, ano_referencia)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            if (count($data) !== count($header))
                continue;
            $row = array_combine($header, $data);

            $nomePrescritor = trim($row['Nome'] ?? '');
            $nomeUpper = strtoupper($nomePrescritor);
            $visitadorCsv = trim($row['Visitador'] ?? '');
            $visitadorFinal = $visitadorCsv;

            if (!empty($nomePrescritor)) {
                if (isset($prescritorMap[$nomeUpper])) {
                    // Usar o visitador do banco de dados (preservar transferências)
                    $visitadorFinal = $prescritorMap[$nomeUpper];
                }
                else {
                    // Novo prescritor: cadastrar com o visitador do CSV
                    $stmtNewPrescritor->execute(['nome' => $nomePrescritor, 'visitador' => $visitadorCsv]);
                    $prescritorMap[$nomeUpper] = $visitadorCsv;
                }
            }

            $batch[] = [
                $visitadorFinal,
                trim($row['Nome'] ?? ''),
                trim($row['Profissão'] ?? $row['Profissao'] ?? ''),
                trim($row['Sigla'] ?? ''),
                trim($row['UF'] ?? ''),
                trim($row['Número'] ?? $row['Numero'] ?? ''),
                intval($row['Aprovados'] ?? 0),
                parseBRMoney($row['Valor Aprovado'] ?? '0'),
                intval($row['Recusados'] ?? 0),
                parseBRMoney($row['Valor Recusado'] ?? '0'),
                intval($row['No Carrinho'] ?? 0),
                parseBRMoney($row['Valor No Carrinho'] ?? '0'),
                trim($row['Considerar desconto'] ?? ''),
                trim($row['%Desconto ate'] ?? ''),
                $ano
            ];
            $count++;
            if (count($batch) >= 1000) {
                importBatch($pdo, $sql, $batch);
                $batch = [];
            }
        }
        if (!empty($batch))
            importBatch($pdo, $sql, $batch);
        fclose($handle);
        $results['prescritor_resumido'] = $count;
    }
    else {
        $errors[] = "Arquivo não encontrado: Prescritor Resumido {$ano}";
    }
}
catch (Exception $e) {
    $errors[] = "Prescritor Resumido: " . $e->getMessage();
}

// ============================================
// 2. Gestão de Pedidos 2026 (sempre)
// ============================================
try {
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
        INDEX idx_ano (ano_referencia), INDEX idx_numero_serie (numero_pedido, serie_pedido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $fileGp = dirname(__DIR__) . "/Dados/Relatório de Gestão de Pedidos 2026.csv";
    if (file_exists($fileGp)) {
        $pdo->exec("DELETE FROM gestao_pedidos WHERE ano_referencia = 2026");
        list($handle, $header) = readCsvHeader($fileGp);
        if ($handle && $header) {
            $sqlGp = "INSERT INTO gestao_pedidos (data_aprovacao, data_orcamento, canal_atendimento, numero_pedido, serie_pedido, forma_farmaceutica, produto, quantidade, preco_bruto, valor_subsidio, preco_custo, desconto, acrescimo, preco_liquido, cliente, paciente, prescritor, atendente, venda_pdv, cortesia, aprovador, orcamentista, status_financeiro, origem_acrescimo_desconto, convenio, ano_referencia) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $batch = [];
            $countGp = 0;
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
                    2026
                ];
                $countGp++;
                if (count($batch) >= 2000) { importBatch($pdo, $sqlGp, $batch); $batch = []; }
            }
            if (!empty($batch)) importBatch($pdo, $sqlGp, $batch);
            fclose($handle);
            $results['gestao_pedidos_2026'] = $countGp;
        }
    }
} catch (Exception $e) {
    $errors[] = "Gestão de Pedidos 2026: " . $e->getMessage();
}

// ============================================
// 3. Itens de Orçamentos e Pedidos — somente 2026
// ============================================
try {
    // Garantir que a tabela existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS itens_orcamentos_pedidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filial VARCHAR(100), numero INT NOT NULL DEFAULT 0, serie INT NOT NULL DEFAULT 0,
        data DATE NULL, canal VARCHAR(50), forma_farmaceutica VARCHAR(100), descricao VARCHAR(255),
        quantidade INT NOT NULL DEFAULT 1, unidade VARCHAR(20),
        valor_bruto DECIMAL(14,2) DEFAULT 0, valor_liquido DECIMAL(14,2) DEFAULT 0,
        preco_custo DECIMAL(14,2) DEFAULT 0, fator DECIMAL(10,2) DEFAULT 0,
        status VARCHAR(50), usuario_inclusao VARCHAR(100), usuario_aprovador VARCHAR(100),
        paciente VARCHAR(255), prescritor VARCHAR(255), status_financeiro VARCHAR(50),
        ano_referencia INT NOT NULL,
        INDEX idx_ano (ano_referencia), INDEX idx_prescritor (prescritor(100)), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Cadastro é a fonte da verdade: só inserir prescritores que ainda não existem.
    $stmtMap = $pdo->query("SELECT nome, visitador FROM prescritores_cadastro");
    $prescritorMap = [];
    while ($rowMap = $stmtMap->fetch(PDO::FETCH_ASSOC)) {
        $prescritorMap[strtoupper(trim($rowMap['nome'] ?? ''))] = $rowMap['visitador'] ?? '';
    }
    $stmtNewPrescritor = $pdo->prepare("INSERT IGNORE INTO prescritores_cadastro (nome, visitador) VALUES (:nome, :visitador)");

    foreach ([2026] as $anoItem) {
        $file = dirname(__DIR__) . "/Dados/Relatório de Itens de Orçamentos e Pedidos {$anoItem}.csv";

        if (!file_exists($file)) {
            continue;
        }

        $pdo->exec("DELETE FROM itens_orcamentos_pedidos WHERE ano_referencia = " . (int)$anoItem);

        list($handle, $header) = readCsvHeader($file);
        if (!$handle || !$header) {
            $errors[] = "Não foi possível ler o arquivo CSV de Itens {$anoItem}";
            continue;
        }

        $count = 0;
        $batch = [];
        $sql = "INSERT INTO itens_orcamentos_pedidos 
            (filial, numero, serie, data, canal, forma_farmaceutica, descricao,
             quantidade, unidade, valor_bruto, valor_liquido, preco_custo, fator,
             status, usuario_inclusao, usuario_aprovador, paciente, prescritor,
             status_financeiro, ano_referencia)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            if (count($data) !== count($header))
                continue;
            $row = array_combine($header, $data);

            // =========================================================
            // LÓGICA DE PRESCRITOR (Autocadastro a partir dos Itens)
            // REGRA: Cada variação do nome = finalidade de venda diferente.
            // Não normalizar: "REP - Nome" ≠ "Nome" ≠ "Nome." (prefixo e ponto no final mudam a finalidade).
            // =========================================================
            $nomePrescritor = trim($row['Prescritor'] ?? '');
            $nomeUpper = strtoupper($nomePrescritor);

            // Prescritor vazio = "My Pharm"
            // Mas no banco (itens), o campo prescritor fica vazio ou eu mudo para algo?
            // O usuário disse: "importe para o visitador My Pharm".
            // Isso refere-se à tabela de CADASTRO (quem visita).
            // Se o nome for vazio, cadastrar no mestre como Nome="" e Visitador="My Pharm"

            if (empty($nomePrescritor)) {
                // Prescritor Vazio -> Visitador My Pharm
                if (!isset($prescritorMap[''])) {
                    $stmtNewPrescritor->execute(['nome' => '', 'visitador' => 'My Pharm']);
                    $prescritorMap[''] = 'My Pharm';
                }
            }
            else {
                // Prescritor já existe no cadastro? Não alterar. Só inserir se for novo (vindo do relatório).
                if (!isset($prescritorMap[$nomeUpper])) {
                    $stmtNewPrescritor->execute(['nome' => $nomePrescritor, 'visitador' => 'My Pharm']);
                    $prescritorMap[$nomeUpper] = 'My Pharm';
                }
            }

            $batch[] = [
                trim($row['Filial'] ?? ''),
                intval($row['Número'] ?? 0),
                intval($row['Série'] ?? 0),
                parseBRDate($row['Data'] ?? ''),
                trim($row['Canal'] ?? ''),
                trim($row['Forma Farmacêutica'] ?? ''),
                trim($row['Descriçao'] ?? $row['Descrição'] ?? ''),
                intval($row['Quantidade'] ?? 1),
                trim($row['Unidade'] ?? ''),
                parseBRMoney($row['Valor Bruto'] ?? '0'),
                parseBRMoney($row['Valor Líquido'] ?? '0'),
                parseBRMoney($row['Preço Custo'] ?? '0'),
                floatval(str_replace(',', '.', $row['Fator'] ?? '0')),
                trim($row['Status'] ?? ''),
                trim($row['Usuário inclusão'] ?? ''),
                trim($row['Usuário aprovador'] ?? ''),
                trim($row['Paciente'] ?? ''),
                trim($row['Prescritor'] ?? ''),
                trim($row['Status Financeiro'] ?? ''),
                $anoItem
            ];
            $count++;
            if (count($batch) >= 2000) {
                importBatch($pdo, $sql, $batch);
                $batch = [];
            }
        }
        if (!empty($batch))
            importBatch($pdo, $sql, $batch);
        fclose($handle);
        $results["itens_orcamentos_pedidos_{$anoItem}"] = $count;
    }

    // Atualizar prescritor_resumido a partir dos itens (para o dashboard usar)
    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_resumido (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visitador VARCHAR(150), nome VARCHAR(255), profissao VARCHAR(100), sigla VARCHAR(20), uf VARCHAR(10),
        numero VARCHAR(50), aprovados INT DEFAULT 0, valor_aprovado DECIMAL(14,2) DEFAULT 0,
        recusados INT DEFAULT 0, valor_recusado DECIMAL(14,2) DEFAULT 0,
        no_carrinho INT DEFAULT 0, valor_no_carrinho DECIMAL(14,2) DEFAULT 0,
        considerar_desconto VARCHAR(50), percentual_desconto VARCHAR(50), ano_referencia INT NOT NULL,
        INDEX idx_ano (ano_referencia), INDEX idx_nome (nome(100)), INDEX idx_visitador (visitador)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Atualizar prescritor_resumido a partir dos itens apenas para 2026
    $pdo->exec("DELETE FROM prescritor_resumido WHERE ano_referencia = 2026");
    $pdo->exec("
        INSERT INTO prescritor_resumido
        (visitador, nome, aprovados, valor_aprovado, recusados, valor_recusado, no_carrinho, valor_no_carrinho, ano_referencia)
        SELECT
            COALESCE(pc.visitador, 'My Pharm'),
            COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'),
            SUM(CASE WHEN i.status = 'Aprovado' THEN 1 ELSE 0 END),
            SUM(CASE WHEN i.status = 'Aprovado' THEN i.valor_liquido ELSE 0 END),
            SUM(CASE WHEN i.status = 'Recusado' THEN 1 ELSE 0 END),
            SUM(CASE WHEN i.status = 'Recusado' THEN i.valor_liquido ELSE 0 END),
            SUM(CASE WHEN i.status = 'No carrinho' THEN 1 ELSE 0 END),
            SUM(CASE WHEN i.status = 'No carrinho' THEN i.valor_liquido ELSE 0 END),
            2026
        FROM itens_orcamentos_pedidos i
        LEFT JOIN prescritores_cadastro pc ON UPPER(TRIM(pc.nome)) = UPPER(TRIM(COALESCE(i.prescritor, '')))
        WHERE i.ano_referencia = 2026
        GROUP BY COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'), COALESCE(pc.visitador, 'My Pharm')
    ");

}
catch (Exception $e) {
    $errors[] = "Itens: " . $e->getMessage();
}

// ============================================
// 4. Detalhado com Componentes 2026 (sempre)
// ============================================
try {
    $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && file_exists('c:\\xampp\\php\\php.exe')) {
        $php = 'c:\\xampp\\php\\php.exe';
    }
    $scriptComponentes = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'importar_detalhado_componentes_cli.php';
    if (file_exists($scriptComponentes)) {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($scriptComponentes) . ' 2026';
        $out = [];
        @exec($cmd . ' 2>&1', $out);
        $countComp = (int) $pdo->query("SELECT COUNT(*) FROM pedidos_detalhado_componentes WHERE ano_referencia = 2026")->fetchColumn();
        $results['pedidos_detalhado_componentes_2026'] = $countComp;
    }
} catch (Exception $e) {
    $errors[] = "Detalhado Componentes 2026: " . $e->getMessage();
}

// Histórico de Visitas (XLSX) não é importado pelo botão "Importar Dados" — somente os 4 CSVs acima.

// ========== Vínculo prescritor→visitador NÃO é mais alterado pela importação ==========
// O vínculo é definitivo e só pode ser modificado pela tela (Ranking de Prescritores / transferência).
// Apenas pedidos e dados são importados; prescritores_cadastro.visitador permanece como está.

// ============================================
// Limpeza
// ============================================
if (file_exists(dirname(__DIR__) . '/read_xlsx_headers.php')) {
    @unlink(dirname(__DIR__) . '/read_xlsx_headers.php');
}

// ============================================
// Output
// ============================================
$elapsed = round(microtime(true) - $startTime, 1);

if (empty($errors)) {
    echo json_encode([
        'success' => true,
        'message' => "Importação concluída com sucesso em {$elapsed}s!",
        'registros_importados' => $results,
        'tempo_segundos' => $elapsed
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => count($results) > 0,
        'message' => "Importação concluída com alertas em {$elapsed}s",
        'registros_importados' => $results,
        'erros' => $errors,
        'tempo_segundos' => $elapsed
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
