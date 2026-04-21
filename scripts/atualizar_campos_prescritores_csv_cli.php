<?php
/**
 * Atualiza campos de prescritores a partir de Prescritores_Cadastrados.csv
 * Foco exclusivo:
 * - profissao
 * - registro (crm)
 * - uf_registro (estado)
 * - data_cadastro
 *
 * Regras:
 * - Nao altera visitador
 * - Nao altera pedidos/valores
 * - Ignora nomes com "desativad*" e nomes com digitos
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts/atualizar_campos_prescritores_csv_cli.php --dry-run
 *   C:\xampp\php\php.exe scripts/atualizar_campos_prescritores_csv_cli.php --apply
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execute via CLI.\n");
    exit(1);
}

$mode = in_array('--apply', $argv, true) ? 'apply' : 'dry-run';
$baseDir = dirname(__DIR__);
$csvPath = $baseDir . DIRECTORY_SEPARATOR . 'Prescritores_Cadastrados.csv';
$envPath = $baseDir . DIRECTORY_SEPARATOR . '.env';

if (!file_exists($envPath)) {
    fwrite(STDERR, "Arquivo .env nao encontrado.\n");
    exit(1);
}
if (!file_exists($csvPath)) {
    fwrite(STDERR, "Arquivo CSV nao encontrado: {$csvPath}\n");
    exit(1);
}

foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $parts = explode('=', $line, 2);
    if (count($parts) !== 2) continue;
    putenv(trim($parts[0]) . '=' . trim(trim($parts[1]), "\"'"));
}

$host = getenv('DB_HOST') ?: 'localhost';
$name = getenv('DB_NAME') ?: 'mypharm_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Erro de conexao: " . $e->getMessage() . "\n");
    exit(1);
}

function shouldSkipNome(string $nome): bool
{
    $nome = trim($nome);
    if ($nome === '') return true;
    if (preg_match('/desativad[oa]/iu', $nome)) return true;
    if (preg_match('/\d/u', $nome)) return true;
    return false;
}

function normalizeProfissao(string $p): string
{
    $p = trim($p);
    if ($p === '') return '';
    $map = [
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C',
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    $p = strtr($p, $map);
    $p = preg_replace('/\s+/u', ' ', $p);
    return mb_strtoupper(trim($p), 'UTF-8');
}

function parseDateOrNull(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $fmts = ['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y'];
    foreach ($fmts as $f) {
        $dt = DateTime::createFromFormat($f, $raw);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

$pdo->exec("SET SESSION innodb_lock_wait_timeout = 15");
try {
    $pdo->exec("SET SESSION lock_wait_timeout = 15");
} catch (Throwable $e) {
    // alguns provedores podem nao permitir este ajuste
}

$hasDataCadastro = false;
$stCol = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = :db
      AND TABLE_NAME = 'prescritor_dados'
      AND COLUMN_NAME = 'data_cadastro'
");
$stCol->execute(['db' => $name]);
$hasDataCadastro = ((int)$stCol->fetchColumn() > 0);

$pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_csv_prescritores");
$pdo->exec("CREATE TEMPORARY TABLE tmp_csv_prescritores (
    nome_key VARCHAR(255) NOT NULL PRIMARY KEY,
    nome_original VARCHAR(255) NOT NULL,
    profissao VARCHAR(255) NULL,
    registro VARCHAR(100) NULL,
    uf_registro VARCHAR(10) NULL,
    data_cadastro DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$insertTmp = $pdo->prepare("
    INSERT INTO tmp_csv_prescritores (nome_key, nome_original, profissao, registro, uf_registro, data_cadastro)
    VALUES (:nome_key, :nome_original, :profissao, :registro, :uf_registro, :data_cadastro)
    ON DUPLICATE KEY UPDATE
        nome_original = VALUES(nome_original),
        profissao = VALUES(profissao),
        registro = VALUES(registro),
        uf_registro = VALUES(uf_registro),
        data_cadastro = VALUES(data_cadastro)
");

$fh = fopen($csvPath, 'r');
if (!$fh) {
    fwrite(STDERR, "Falha ao abrir CSV.\n");
    exit(1);
}
$header = fgetcsv($fh, 0, ',');
if (!$header) {
    fclose($fh);
    fwrite(STDERR, "CSV sem cabecalho.\n");
    exit(1);
}
$header = array_map(static function ($h) {
    return trim((string)$h, "\xEF\xBB\xBF \t\n\r");
}, $header);

$idxNome = array_search('Prescritor', $header, true);
$idxProf = array_search('Profissao', $header, true);
$idxCrm = array_search('Soma de Crm', $header, true);
$idxUf = array_search('Estado', $header, true);
$idxData = array_search('Data_cadastro', $header, true);
if ($idxNome === false || $idxProf === false || $idxCrm === false || $idxUf === false || $idxData === false) {
    fclose($fh);
    fwrite(STDERR, "Cabecalho inesperado no CSV.\n");
    exit(1);
}

$lidas = 0;
$ignoradas = 0;
$validas = 0;
while (($row = fgetcsv($fh, 0, ',')) !== false) {
    $lidas++;
    $nome = trim((string)($row[$idxNome] ?? ''));
    if (shouldSkipNome($nome)) {
        $ignoradas++;
        continue;
    }
    $prof = normalizeProfissao((string)($row[$idxProf] ?? ''));
    $crm = trim((string)($row[$idxCrm] ?? ''));
    $uf = strtoupper(trim((string)($row[$idxUf] ?? '')));
    if ($crm !== '') {
        $crm = preg_replace('/\s+/', '', $crm);
    }
    if (strlen($uf) > 2) {
        $uf = substr($uf, 0, 2);
    }
    $dataCadastro = parseDateOrNull((string)($row[$idxData] ?? ''));
    $nomeKey = mb_strtolower(trim($nome), 'UTF-8');

    $insertTmp->execute([
        'nome_key' => $nomeKey,
        'nome_original' => $nome,
        'profissao' => $prof !== '' ? $prof : null,
        'registro' => $crm !== '' ? $crm : null,
        'uf_registro' => $uf !== '' ? $uf : null,
        'data_cadastro' => $dataCadastro,
    ]);
    $validas++;
}
fclose($fh);

$dateDiffSql = $hasDataCadastro
    ? " OR COALESCE(pd.data_cadastro, '1900-01-01 00:00:00') <> COALESCE(t.data_cadastro, '1900-01-01 00:00:00') "
    : '';

$stats = $pdo->query("
    SELECT
      COUNT(*) AS candidatos_csv,
      SUM(CASE WHEN pdx.nome_key IS NULL THEN 1 ELSE 0 END) AS insert_novos,
      SUM(CASE WHEN pdx.nome_key IS NOT NULL THEN 1 ELSE 0 END) AS update_existentes,
      SUM(CASE WHEN pdx.nome_key IS NOT NULL AND (
            COALESCE(NULLIF(TRIM(pd.profissao), ''), '') <> COALESCE(NULLIF(TRIM(t.profissao), ''), '')
         OR COALESCE(NULLIF(TRIM(pd.registro), ''), '') <> COALESCE(NULLIF(TRIM(t.registro), ''), '')
         OR COALESCE(NULLIF(TRIM(pd.uf_registro), ''), '') <> COALESCE(NULLIF(TRIM(t.uf_registro), ''), '')
         {$dateDiffSql}
      ) THEN 1 ELSE 0 END) AS update_com_diferenca
    FROM tmp_csv_prescritores t
    INNER JOIN (
      SELECT LOWER(TRIM(nome)) AS nome_key, MIN(nome) AS nome
      FROM prescritores_cadastro
      WHERE NULLIF(TRIM(nome), '') IS NOT NULL
      GROUP BY LOWER(TRIM(nome))
    ) pc ON pc.nome_key = t.nome_key
    LEFT JOIN (
      SELECT LOWER(TRIM(nome_prescritor)) AS nome_key
      FROM prescritor_dados
      WHERE NULLIF(TRIM(nome_prescritor), '') IS NOT NULL
      GROUP BY LOWER(TRIM(nome_prescritor))
    ) pdx ON pdx.nome_key = pc.nome_key
    LEFT JOIN prescritor_dados pd ON LOWER(TRIM(pd.nome_prescritor)) = pc.nome_key
")->fetch();

echo "=== Atualizacao campos de prescritor ({$mode}) ===\n";
echo "CSV lidas: {$lidas}\n";
echo "CSV validas: {$validas}\n";
echo "CSV ignoradas (nome invalido): {$ignoradas}\n";
echo "Candidatos na carteira (join por nome normalizado): " . (int)$stats['candidatos_csv'] . "\n";
echo "Previsto inserir em prescritor_dados: " . (int)$stats['insert_novos'] . "\n";
echo "Previsto atualizar existentes: " . (int)$stats['update_existentes'] . "\n";
echo "Previsto atualizar com diferenca real: " . (int)$stats['update_com_diferenca'] . "\n";
echo "Coluna prescritor_dados.data_cadastro: " . ($hasDataCadastro ? "existe" : "nao existe") . "\n";

if ($mode !== 'apply') {
    echo "Dry-run finalizado. Nenhuma alteracao gravada.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    if (!$hasDataCadastro) {
        $pdo->exec("ALTER TABLE prescritor_dados ADD COLUMN data_cadastro DATETIME NULL");
        $hasDataCadastro = true;
    }

    $setDateSql = $hasDataCadastro
        ? "pd.data_cadastro = COALESCE(t.data_cadastro, pd.data_cadastro),"
        : '';
    $insertColsDate = $hasDataCadastro ? ", data_cadastro" : '';
    $insertValsDate = $hasDataCadastro ? "t.data_cadastro," : '';

    $sqlUpdate = "
        UPDATE prescritor_dados pd
        INNER JOIN (
          SELECT LOWER(TRIM(nome)) AS nome_key, MIN(nome) AS nome
          FROM prescritores_cadastro
          WHERE NULLIF(TRIM(nome), '') IS NOT NULL
          GROUP BY LOWER(TRIM(nome))
        ) pc ON pc.nome_key = LOWER(TRIM(pd.nome_prescritor))
        INNER JOIN tmp_csv_prescritores t ON t.nome_key = pc.nome_key
        SET
          pd.profissao = COALESCE(t.profissao, pd.profissao),
          pd.registro = COALESCE(t.registro, pd.registro),
          pd.uf_registro = COALESCE(t.uf_registro, pd.uf_registro),
          {$setDateSql}
          pd.atualizado_em = NOW()
    ";
    $updCount = $pdo->exec($sqlUpdate);

    $sqlInsert = "
        INSERT INTO prescritor_dados (nome_prescritor, profissao, registro, uf_registro {$insertColsDate}, atualizado_em)
        SELECT
          pc.nome,
          t.profissao,
          t.registro,
          t.uf_registro,
          {$insertValsDate}
          NOW()
        FROM (
          SELECT LOWER(TRIM(nome)) AS nome_key, MIN(nome) AS nome
          FROM prescritores_cadastro
          WHERE NULLIF(TRIM(nome), '') IS NOT NULL
          GROUP BY LOWER(TRIM(nome))
        ) pc
        INNER JOIN tmp_csv_prescritores t ON t.nome_key = pc.nome_key
        LEFT JOIN (
          SELECT LOWER(TRIM(nome_prescritor)) AS nome_key
          FROM prescritor_dados
          WHERE NULLIF(TRIM(nome_prescritor), '') IS NOT NULL
          GROUP BY LOWER(TRIM(nome_prescritor))
        ) pdx ON pdx.nome_key = pc.nome_key
        WHERE pdx.nome_key IS NULL
    ";
    $insCount = $pdo->exec($sqlInsert);

    $pdo->commit();
    echo "APLICADO com sucesso.\n";
    echo "Linhas atualizadas (SQL): {$updCount}\n";
    echo "Linhas inseridas (SQL): {$insCount}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Falha ao aplicar: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Concluido sem alterar visitador/pedidos.\n";
