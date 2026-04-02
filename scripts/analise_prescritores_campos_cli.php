<?php
/**
 * Diagnóstico somente leitura dos campos de prescritor:
 * - profissao
 * - crm/registro
 * - estado/uf
 * - data de cadastro
 *
 * Não executa INSERT/UPDATE/DELETE/ALTER.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts/analise_prescritores_campos_cli.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execute via CLI.\n");
    exit(1);
}

$baseDir = dirname(__DIR__);
$envPath = $baseDir . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    fwrite(STDERR, "Arquivo .env nao encontrado.\n");
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

function scalar(PDO $pdo, string $sql): int
{
    return (int)$pdo->query($sql)->fetchColumn();
}

function pct(int $part, int $total): string
{
    if ($total <= 0) return '0.00%';
    return number_format(($part / $total) * 100, 2, '.', '') . '%';
}

function existsColumn(PDO $pdo, string $db, string $table, string $column): bool
{
    $st = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tb AND COLUMN_NAME = :col
    ");
    $st->execute(['db' => $db, 'tb' => $table, 'col' => $column]);
    return (int)$st->fetchColumn() > 0;
}

$totalCarteira = scalar($pdo, "SELECT COUNT(*) FROM prescritores_cadastro");
$totalNomesCarteira = scalar($pdo, "SELECT COUNT(*) FROM prescritores_cadastro WHERE NULLIF(TRIM(nome), '') IS NOT NULL");
$totalDados = scalar($pdo, "SELECT COUNT(*) FROM prescritor_dados");
$totalDadosNome = scalar($pdo, "SELECT COUNT(*) FROM prescritor_dados WHERE NULLIF(TRIM(nome_prescritor), '') IS NOT NULL");

$joinExact = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN NULLIF(TRIM(pd.profissao), '') IS NOT NULL THEN 1 ELSE 0 END) AS profissao_ok,
      SUM(CASE WHEN NULLIF(TRIM(pd.registro), '') IS NOT NULL THEN 1 ELSE 0 END) AS crm_ok,
      SUM(CASE WHEN NULLIF(TRIM(pd.uf_registro), '') IS NOT NULL THEN 1 ELSE 0 END) AS estado_ok
    FROM prescritores_cadastro pc
    LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pc.nome
    WHERE NULLIF(TRIM(pc.nome), '') IS NOT NULL
")->fetch();

$joinNorm = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN NULLIF(TRIM(pdn.profissao), '') IS NOT NULL THEN 1 ELSE 0 END) AS profissao_ok,
      SUM(CASE WHEN NULLIF(TRIM(pdn.registro), '') IS NOT NULL THEN 1 ELSE 0 END) AS crm_ok,
      SUM(CASE WHEN NULLIF(TRIM(pdn.uf_registro), '') IS NOT NULL THEN 1 ELSE 0 END) AS estado_ok
    FROM prescritores_cadastro pc
    LEFT JOIN (
      SELECT
        LOWER(TRIM(nome_prescritor)) AS nome_key,
        MAX(NULLIF(TRIM(profissao), '')) AS profissao,
        MAX(NULLIF(TRIM(registro), '')) AS registro,
        MAX(NULLIF(TRIM(uf_registro), '')) AS uf_registro
      FROM prescritor_dados
      WHERE NULLIF(TRIM(nome_prescritor), '') IS NOT NULL
      GROUP BY LOWER(TRIM(nome_prescritor))
    ) pdn ON pdn.nome_key = LOWER(TRIM(pc.nome))
    WHERE NULLIF(TRIM(pc.nome), '') IS NOT NULL
")->fetch();

$semMatchExato = scalar($pdo, "
    SELECT COUNT(*)
    FROM prescritores_cadastro pc
    LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pc.nome
    WHERE NULLIF(TRIM(pc.nome), '') IS NOT NULL
      AND pd.nome_prescritor IS NULL
");

$semMatchNorm = scalar($pdo, "
    SELECT COUNT(*)
    FROM prescritores_cadastro pc
    LEFT JOIN (
      SELECT DISTINCT LOWER(TRIM(nome_prescritor)) AS nome_key
      FROM prescritor_dados
      WHERE NULLIF(TRIM(nome_prescritor), '') IS NOT NULL
    ) pdn ON pdn.nome_key = LOWER(TRIM(pc.nome))
    WHERE NULLIF(TRIM(pc.nome), '') IS NOT NULL
      AND pdn.nome_key IS NULL
");

$somenteDados = scalar($pdo, "
    SELECT COUNT(*)
    FROM prescritor_dados pd
    LEFT JOIN (
      SELECT DISTINCT LOWER(TRIM(nome)) AS nome_key
      FROM prescritores_cadastro
      WHERE NULLIF(TRIM(nome), '') IS NOT NULL
    ) pc ON pc.nome_key = LOWER(TRIM(pd.nome_prescritor))
    WHERE NULLIF(TRIM(pd.nome_prescritor), '') IS NOT NULL
      AND pc.nome_key IS NULL
");

$nomesComNumero = scalar($pdo, "
    SELECT COUNT(*)
    FROM prescritores_cadastro
    WHERE NULLIF(TRIM(nome), '') IS NOT NULL
      AND TRIM(nome) REGEXP '[0-9]'
");

$nomesDesativado = scalar($pdo, "
    SELECT COUNT(*)
    FROM prescritores_cadastro
    WHERE NULLIF(TRIM(nome), '') IS NOT NULL
      AND LOWER(TRIM(nome)) LIKE '%desativad%'
");

$dateCandidates = [
    ['table' => 'prescritores_cadastro', 'column' => 'data_cadastro'],
    ['table' => 'prescritores_cadastro', 'column' => 'criado_em'],
    ['table' => 'prescritores_cadastro', 'column' => 'created_at'],
    ['table' => 'prescritor_dados', 'column' => 'data_cadastro'],
    ['table' => 'prescritor_dados', 'column' => 'atualizado_em'],
    ['table' => 'prescritor_dados', 'column' => 'created_at'],
];

$dateStats = [];
foreach ($dateCandidates as $cand) {
    if (!existsColumn($pdo, $name, $cand['table'], $cand['column'])) continue;
    $sql = "
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN {$cand['column']} IS NOT NULL THEN 1 ELSE 0 END) AS preenchido
        FROM {$cand['table']}
    ";
    $row = $pdo->query($sql)->fetch();
    $dateStats[] = [
        'table' => $cand['table'],
        'column' => $cand['column'],
        'total' => (int)$row['total'],
        'preenchido' => (int)$row['preenchido'],
    ];
}

echo "=== ANALISE DE CAMPOS DOS PRESCRITORES (SOMENTE LEITURA) ===\n";
echo "Banco: {$name} @ {$host}\n\n";

echo "Base de referencia (carteira):\n";
echo "- prescritores_cadastro (linhas): {$totalCarteira}\n";
echo "- prescritores_cadastro (nomes validos): {$totalNomesCarteira}\n";
echo "- prescritor_dados (linhas): {$totalDados}\n";
echo "- prescritor_dados (nomes validos): {$totalDadosNome}\n\n";

echo "Cobertura dos campos na carteira (join EXATO por nome):\n";
echo "- profissao preenchida: {$joinExact['profissao_ok']} de {$joinExact['total']} (" . pct((int)$joinExact['profissao_ok'], (int)$joinExact['total']) . ")\n";
echo "- crm/registro preenchido: {$joinExact['crm_ok']} de {$joinExact['total']} (" . pct((int)$joinExact['crm_ok'], (int)$joinExact['total']) . ")\n";
echo "- estado/uf preenchido: {$joinExact['estado_ok']} de {$joinExact['total']} (" . pct((int)$joinExact['estado_ok'], (int)$joinExact['total']) . ")\n";
echo "- sem match em prescritor_dados: {$semMatchExato}\n\n";

echo "Cobertura dos campos na carteira (join NORMALIZADO lower+trim):\n";
echo "- profissao preenchida: {$joinNorm['profissao_ok']} de {$joinNorm['total']} (" . pct((int)$joinNorm['profissao_ok'], (int)$joinNorm['total']) . ")\n";
echo "- crm/registro preenchido: {$joinNorm['crm_ok']} de {$joinNorm['total']} (" . pct((int)$joinNorm['crm_ok'], (int)$joinNorm['total']) . ")\n";
echo "- estado/uf preenchido: {$joinNorm['estado_ok']} de {$joinNorm['total']} (" . pct((int)$joinNorm['estado_ok'], (int)$joinNorm['total']) . ")\n";
echo "- sem match em prescritor_dados (normalizado): {$semMatchNorm}\n\n";

echo "Registros em prescritor_dados sem equivalente na carteira (normalizado): {$somenteDados}\n\n";

echo "Qualidade de nome na carteira:\n";
echo "- nomes com numero: {$nomesComNumero}\n";
echo "- nomes com 'desativad*': {$nomesDesativado}\n\n";

$amostraSemMatch = $pdo->query("
    SELECT pc.nome
    FROM prescritores_cadastro pc
    LEFT JOIN (
      SELECT DISTINCT LOWER(TRIM(nome_prescritor)) AS nome_key
      FROM prescritor_dados
      WHERE NULLIF(TRIM(nome_prescritor), '') IS NOT NULL
    ) pdn ON pdn.nome_key = LOWER(TRIM(pc.nome))
    WHERE NULLIF(TRIM(pc.nome), '') IS NOT NULL
      AND pdn.nome_key IS NULL
    ORDER BY pc.nome
    LIMIT 15
")->fetchAll(PDO::FETCH_COLUMN);

$amostraCampoFaltando = $pdo->query("
    SELECT
      pc.nome,
      pdn.profissao,
      pdn.registro,
      pdn.uf_registro
    FROM prescritores_cadastro pc
    LEFT JOIN (
      SELECT
        LOWER(TRIM(nome_prescritor)) AS nome_key,
        MAX(NULLIF(TRIM(profissao), '')) AS profissao,
        MAX(NULLIF(TRIM(registro), '')) AS registro,
        MAX(NULLIF(TRIM(uf_registro), '')) AS uf_registro
      FROM prescritor_dados
      WHERE NULLIF(TRIM(nome_prescritor), '') IS NOT NULL
      GROUP BY LOWER(TRIM(nome_prescritor))
    ) pdn ON pdn.nome_key = LOWER(TRIM(pc.nome))
    WHERE NULLIF(TRIM(pc.nome), '') IS NOT NULL
      AND (
        NULLIF(TRIM(COALESCE(pdn.profissao, '')), '') IS NULL
        OR NULLIF(TRIM(COALESCE(pdn.registro, '')), '') IS NULL
        OR NULLIF(TRIM(COALESCE(pdn.uf_registro, '')), '') IS NULL
      )
    ORDER BY pc.nome
    LIMIT 15
")->fetchAll();

echo "Amostra (15) sem match em prescritor_dados:\n";
if (empty($amostraSemMatch)) {
    echo "- nenhuma\n";
} else {
    foreach ($amostraSemMatch as $nome) {
        echo "- {$nome}\n";
    }
}
echo "\n";

echo "Amostra (15) com algum campo faltando (profissao/registro/uf):\n";
if (empty($amostraCampoFaltando)) {
    echo "- nenhuma\n";
} else {
    foreach ($amostraCampoFaltando as $r) {
        $p = $r['profissao'] ?: 'NULL';
        $c = $r['registro'] ?: 'NULL';
        $u = $r['uf_registro'] ?: 'NULL';
        echo "- {$r['nome']} | profissao={$p} | crm={$c} | uf={$u}\n";
    }
}
echo "\n";

echo "Data de cadastro (candidatos encontrados):\n";
if (empty($dateStats)) {
    echo "- Nenhuma coluna candidata encontrada automaticamente.\n";
} else {
    foreach ($dateStats as $d) {
        echo "- {$d['table']}.{$d['column']}: {$d['preenchido']} de {$d['total']} (" . pct($d['preenchido'], $d['total']) . ")\n";
    }
}

echo "\nObservacao: este script NAO altera dados.\n";
