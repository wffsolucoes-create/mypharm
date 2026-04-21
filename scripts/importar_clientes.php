<?php
/**
 * importar_clientes.php — Importação dos CSVs de pacientes/clientes
 * Usa batch inserts em transações para importar ~20k registros rapidamente.
 * Idempotente: pode ser re-executado sem duplicar CPFs.
 * ACESSO: apenas localhost.
 */

set_time_limit(0);
ini_set('display_errors', '1');
ini_set('memory_limit', '256M');

/** Raiz do repositório MyPharm (caminho absoluto no disco). */
$MYPHARM_ROOT = realpath(dirname(__DIR__));
if ($MYPHARM_ROOT === false) {
    http_response_code(500);
    exit('Erro: não foi possível resolver a pasta raiz do projeto (acima de scripts/).');
}

require_once $MYPHARM_ROOT . DIRECTORY_SEPARATOR . 'config.php';

// Web: só localhost. CLI (php.exe): permitido para importação manual.
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (PHP_SAPI !== 'cli' && !in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Acesso negado: execute apenas localmente (ou use: php scripts/importar_clientes.php).');
}

$pdo = getConnection();

// ── 1. Criar tabela ───────────────────────────────────────────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    filial        VARCHAR(50)  NOT NULL DEFAULT '1 - MY PHARM',
    nome          VARCHAR(255) NOT NULL DEFAULT '',
    nome_valido   TINYINT(1)   NOT NULL DEFAULT 1,
    data_cadastro DATE         NULL,
    data_nasc     DATE         NULL,
    tipo_cliente  VARCHAR(50)  NOT NULL DEFAULT 'Pessoa Fisica',
    tipo_cadastro VARCHAR(20)  NOT NULL DEFAULT 'Simples',
    sexo          VARCHAR(20)  NULL,
    email         VARCHAR(255) NULL,
    tipo_telefone VARCHAR(30)  NULL,
    telefone      VARCHAR(20)  NULL,
    cpf           VARCHAR(11)  NULL,
    rg            VARCHAR(30)  NULL,
    tipo_endereco VARCHAR(30)  NULL,
    cep           VARCHAR(8)   NULL,
    logradouro    VARCHAR(255) NULL,
    numero        VARCHAR(20)  NULL,
    bairro        VARCHAR(100) NULL,
    complemento   VARCHAR(100) NULL,
    uf            CHAR(2)      NULL,
    municipio     VARCHAR(100) NULL,
    latitude      DECIMAL(10,7) NULL,
    longitude     DECIMAL(10,7) NULL,
    fonte_ano     SMALLINT     NULL,
    criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE  KEY uk_cpf        (cpf),
    INDEX   idx_nome          (nome(60)),
    INDEX   idx_telefone      (telefone),
    INDEX   idx_data_cadastro (data_cadastro),
    INDEX   idx_municipio     (municipio(60))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── 2. Helpers ────────────────────────────────────────────────────────────────

function parseDateBr(?string $v): ?string
{
    if (!$v || !preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', trim($v), $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return null;
    return "{$m[3]}-{$m[2]}-{$m[1]}";
}

function onlyDigits(?string $v): ?string
{
    if ($v === null) return null;
    $r = preg_replace('/\D/', '', $v);
    return ($r !== '' && $r !== '0') ? $r : null;
}

function normCpf(?string $v): ?string
{
    $d = onlyDigits($v);
    if ($d === null || strlen($d) !== 11) return null;
    if (preg_match('/^(\d)\1{10}$/', $d)) return null;
    return $d;
}

function normCep(?string $v): ?string
{
    $d = onlyDigits($v);
    return ($d !== null && strlen($d) === 8) ? $d : null;
}

/** Telefone só dígitos; cabe em VARCHAR(20). Aceita 8–20 dígitos (DDD+celular, erros de digitação, exterior). */
function normTel(?string $v): ?string
{
    $d = onlyDigits($v);
    if ($d === null) {
        return null;
    }
    $len = strlen($d);
    if ($len < 8) {
        return null;
    }
    if ($len > 20) {
        $d = substr($d, 0, 20);
    }
    return $d;
}

function isNomeInvalido(string $nome): bool
{
    if ($nome === '') return true;
    if (mb_strlen($nome) <= 2) return true;
    if (preg_match('/^[\d\s\-\(\)\+\.]+$/', $nome)) return true;
    return false;
}

function ns(?string $v): ?string
{
    if ($v === null) return null;
    $r = preg_replace('/\s+/', ' ', trim($v));
    return ($r !== '') ? $r : null;
}

/** True se a linha do CSV tem algum dado além da filial (coluna 0). Evita perder linhas só com e-mail/endereço. */
function csvLinhaTemDadosCliente(array $row): bool
{
    for ($i = 1; $i <= 19; $i++) {
        if (!isset($row[$i])) {
            continue;
        }
        if (trim((string) $row[$i]) !== '') {
            return true;
        }
    }
    return false;
}

// ── 3. Limpar tabela para reimportar do zero ──────────────────────────────────
// Garante que uma re-execução não duplique registros sem CPF
$pdo->exec("TRUNCATE TABLE clientes");

// ── 4. Processar CSVs ─────────────────────────────────────────────────────────

$dadosDir = $MYPHARM_ROOT . DIRECTORY_SEPARATOR . 'dados';
$csvFiles = [
    2023 => $dadosDir . DIRECTORY_SEPARATOR . 'Relatório de Clientes 2023.csv',
    2024 => $dadosDir . DIRECTORY_SEPARATOR . 'Relatório de Clientes 2024.csv',
    2025 => $dadosDir . DIRECTORY_SEPARATOR . 'Relatório de Clientes 2025.csv',
    2026 => $dadosDir . DIRECTORY_SEPARATOR . 'Relatório de Clientes 2026.csv',
];

// Colunas para o INSERT
$cols = 'filial,nome,nome_valido,data_cadastro,data_nasc,tipo_cliente,tipo_cadastro,
         sexo,email,tipo_telefone,telefone,cpf,rg,
         tipo_endereco,cep,logradouro,numero,bairro,complemento,uf,municipio,fonte_ano';

// ON DUPLICATE KEY UPDATE: atualiza para o registro mais recente
$onDup = '
    nome          = IF(VALUES(data_cadastro) >= data_cadastro, VALUES(nome),          nome),
    nome_valido   = IF(VALUES(data_cadastro) >= data_cadastro, VALUES(nome_valido),   nome_valido),
    data_cadastro = IF(VALUES(data_cadastro) >= data_cadastro, VALUES(data_cadastro), data_cadastro),
    data_nasc     = IF(VALUES(data_nasc)     IS NOT NULL,      VALUES(data_nasc),     data_nasc),
    tipo_cadastro = IF(VALUES(data_cadastro) >= data_cadastro, VALUES(tipo_cadastro), tipo_cadastro),
    sexo          = IF(VALUES(sexo)          IS NOT NULL,      VALUES(sexo),          sexo),
    email         = IF(VALUES(email)         IS NOT NULL,      VALUES(email),         email),
    telefone      = IF(VALUES(telefone)      IS NOT NULL,      VALUES(telefone),      telefone),
    rg            = IF(VALUES(rg)            IS NOT NULL,      VALUES(rg),            rg),
    cep           = IF(VALUES(cep)           IS NOT NULL,      VALUES(cep),           cep),
    logradouro    = IF(VALUES(logradouro)    IS NOT NULL,      VALUES(logradouro),    logradouro),
    numero        = IF(VALUES(numero)        IS NOT NULL,      VALUES(numero),        numero),
    bairro        = IF(VALUES(bairro)        IS NOT NULL,      VALUES(bairro),        bairro),
    complemento   = IF(VALUES(complemento)   IS NOT NULL,      VALUES(complemento),   complemento),
    uf            = IF(VALUES(uf)            IS NOT NULL,      VALUES(uf),            uf),
    municipio     = IF(VALUES(municipio)     IS NOT NULL,      VALUES(municipio),     municipio),
    fonte_ano     = IF(VALUES(data_cadastro) >= data_cadastro, VALUES(fonte_ano),     fonte_ano)
';

$BATCH = 300; // registros por INSERT batch

$totalInserted = 0;
$totalUpdated  = 0;
$totalSkipped  = 0;
$totalInvalid  = 0;
$fileStats     = [];
$errors        = [];

foreach ($csvFiles as $ano => $path) {
    if (!file_exists($path)) { $errors[] = "Não encontrado: $path"; continue; }
    $fh = fopen($path, 'r');
    if (!$fh) { $errors[] = "Não foi possível abrir: $path"; continue; }

    // Descartar BOM
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $lineNum   = 0;
    $fInserted = 0;
    $fUpdated  = 0;
    $fSkipped  = 0;
    $fInvalid  = 0;
    $batch     = [];   // array de arrays de valores
    $numCols   = 22;   // quantidade de colunas no INSERT

    $flushBatch = function () use ($pdo, $cols, $onDup, $numCols, &$batch, &$fInserted, &$fUpdated, &$fSkipped, &$errors, $ano) {
        if (empty($batch)) return;

        $placeholders = implode(',', array_fill(0, count($batch), '(' . implode(',', array_fill(0, $numCols, '?')) . ')'));
        $sql = "INSERT INTO clientes ($cols) VALUES $placeholders ON DUPLICATE KEY UPDATE $onDup";
        $flat = array_merge(...$batch);

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($flat);
            $affected = $stmt->rowCount();
            $pdo->commit();

            // rowCount em batch ON DUPLICATE KEY: 1 por INSERT, 2 por UPDATE
            $rows    = count($batch);
            $updates = (int)floor(($affected - $rows) / 1);  // cada update adiciona 1 ao count
            // Forma precisa: affected = inserts*1 + updates*2
            // inserts + updates = rows  →  inserts = rows - updates
            // affected = inserts + 2*updates  →  updates = affected - rows
            $upd  = max(0, $affected - $rows);
            $ins  = $rows - $upd;
            $skip = max(0, $rows - $ins - $upd);

            $fInserted += $ins;
            $fUpdated  += $upd;
            $fSkipped  += $skip;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = "Erro batch ano $ano: " . $e->getMessage();
            $fSkipped += count($batch);
        }
        $batch = [];
    };

    while (($row = fgetcsv($fh, 0, ';')) !== false) {
        $lineNum++;
        if ($lineNum === 1) continue;

        while (count($row) < 20) $row[] = '';

        $nome        = ns($row[1])  ?? '';
        $dataCad     = parseDateBr($row[2]);
        $dataNasc    = parseDateBr($row[3]);
        $tipoCliente = ns($row[4])  ?? 'Pessoa Física';
        $tipoCad     = ns($row[5])  ?? 'Simples';
        $sexo        = ns($row[6]);
        $email       = ns($row[7]);
        $tipoTel     = ns($row[8]);
        $telefone    = normTel($row[9]);
        $cpf         = normCpf($row[10]);
        $rg          = ns($row[11]);
        $tipoEnd     = ns($row[12]);
        $cep         = normCep($row[13]);
        $logradouro  = ns($row[14]);
        $numero      = ns($row[15]);
        $bairro      = ns($row[16]);
        $complemento = ns($row[17]);
        $uf          = ns($row[18]);
        $municipio   = ns($row[19]);

        $nomeValido = isNomeInvalido($nome) ? 0 : 1;
        if ($nomeValido === 0) $fInvalid++;

        if (!csvLinhaTemDadosCliente($row)) {
            $fSkipped++;
            continue;
        }

        $batch[] = [
            '1 - MY PHARM', $nome, $nomeValido, $dataCad, $dataNasc,
            $tipoCliente, $tipoCad, $sexo, $email, $tipoTel,
            $telefone, $cpf, $rg, $tipoEnd, $cep,
            $logradouro, $numero, $bairro, $complemento, $uf,
            $municipio, $ano,
        ];

        if (count($batch) >= $BATCH) $flushBatch();
    }
    $flushBatch(); // resto
    fclose($fh);

    $fileStats[$ano] = [
        'linhas'      => $lineNum - 1,
        'inseridos'   => $fInserted,
        'atualizados' => $fUpdated,
        'ignorados'   => $fSkipped,
        'invalidos'   => $fInvalid,
    ];
    $totalInserted += $fInserted;
    $totalUpdated  += $fUpdated;
    $totalSkipped  += $fSkipped;
    $totalInvalid  += $fInvalid;
}

// ── 4. Totais ─────────────────────────────────────────────────────────────────
$totalNaTabela = (int)$pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$totalComCpf   = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE cpf IS NOT NULL")->fetchColumn();
$totalSemCpf   = $totalNaTabela - $totalComCpf;
$totalNomeInv  = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE nome_valido = 0")->fetchColumn();
$totalCompleto = (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE tipo_cadastro = 'Completo'")->fetchColumn();

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Importação Clientes — MyPharm</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: system-ui, sans-serif; background: #f4f4f6; color: #222; padding: 2rem; margin: 0; }
  h1 { color: #E63946; margin-bottom: 0.25rem; }
  h2 { color: #333; font-size: 1.1rem; margin: 0 0 1rem; }
  .box { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 1.5rem; max-width: 840px; margin-bottom: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; font-size: 0.88rem; }
  th { background: #E63946; color: #fff; }
  tr:nth-child(even) td { background: #fafafa; }
  .ok   { color: #2a9d8f; font-weight: 700; }
  .warn { color: #e9a320; font-weight: 700; }
  .err  { color: #E63946; font-weight: 700; }
  .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem; }
  .card { background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 1rem; text-align: center; }
  .card strong { display: block; font-size: 1.8rem; color: #E63946; line-height: 1.2; }
  .card span { font-size: 0.78rem; color: #666; }
  ul.erros { color: #E63946; margin: 0; padding-left: 1.2rem; }
  footer { color: #aaa; font-size: 0.8rem; max-width: 840px; margin-top: 1rem; }
</style>
</head>
<body>

<h1>Importação de Clientes — MyPharm</h1>
<p style="color:#666; margin-bottom:1.5rem;">Executado em <?= date('d/m/Y \à\s H:i:s') ?></p>

<?php if (!empty($errors)): ?>
<div class="box">
  <h2 class="err">Erros (<?= count($errors) ?>)</h2>
  <ul class="erros"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="box">
  <h2>Resumo geral</h2>
  <div class="grid">
    <div class="card"><strong><?= number_format($totalNaTabela, 0, ',', '.') ?></strong><span>Total na tabela</span></div>
    <div class="card"><strong><?= number_format($totalComCpf,   0, ',', '.') ?></strong><span>Com CPF válido</span></div>
    <div class="card"><strong><?= number_format($totalSemCpf,   0, ',', '.') ?></strong><span>Sem CPF</span></div>
    <div class="card"><strong class="ok"><?= number_format($totalInserted, 0, ',', '.') ?></strong><span>Novos inseridos</span></div>
    <div class="card"><strong class="warn"><?= number_format($totalUpdated, 0, ',', '.') ?></strong><span>Atualizados (CPF dup.)</span></div>
    <div class="card"><strong><?= number_format($totalCompleto, 0, ',', '.') ?></strong><span>Cadastro Completo</span></div>
  </div>
  <p style="margin:0; font-size:0.88rem; color:#666;">
    Nomes inválidos (telefone/vazio): <strong><?= number_format($totalNomeInv, 0, ',', '.') ?></strong> — marcados <code>nome_valido=0</code>.
  </p>
</div>

<div class="box">
  <h2>Por arquivo CSV</h2>
  <table>
    <thead><tr><th>Ano</th><th>Linhas lidas</th><th>Inseridos</th><th>Atualizados</th><th>Ignorados</th><th>Nomes inválidos</th></tr></thead>
    <tbody>
    <?php foreach ($fileStats as $ano => $s): ?>
      <tr>
        <td><strong><?= $ano ?></strong></td>
        <td><?= number_format($s['linhas'],      0, ',', '.') ?></td>
        <td class="ok"><?=   number_format($s['inseridos'],   0, ',', '.') ?></td>
        <td class="warn"><?= number_format($s['atualizados'], 0, ',', '.') ?></td>
        <td><?= number_format($s['ignorados'],   0, ',', '.') ?></td>
        <td><?= number_format($s['invalidos'],   0, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="box">
  <h2>Prévia — primeiros 25 registros</h2>
  <div style="overflow-x:auto;">
  <table>
    <thead>
      <tr><th>ID</th><th>Nome</th><th>V</th><th>Data Cad.</th><th>Tipo</th><th>Telefone</th><th>CPF</th><th>UF</th><th>Município</th><th>Ano</th></tr>
    </thead>
    <tbody>
    <?php
    $prev = $pdo->query("SELECT id,nome,nome_valido,data_cadastro,tipo_cadastro,telefone,cpf,uf,municipio,fonte_ano FROM clientes ORDER BY id LIMIT 25");
    foreach ($prev->fetchAll(PDO::FETCH_ASSOC) as $r):
        $cpfFmt = $r['cpf'] ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $r['cpf']) : '—';
        $telFmt = $r['telefone'] ? preg_replace('/^(\d{2})(\d{4,5})(\d{4})$/', '($1) $2-$3', $r['telefone']) : '—';
    ?>
      <tr>
        <td><?= $r['id'] ?></td>
        <td><?= htmlspecialchars($r['nome']) ?></td>
        <td><?= $r['nome_valido'] ? '<span class="ok">✓</span>' : '<span class="err">✗</span>' ?></td>
        <td><?= $r['data_cadastro'] ? date('d/m/Y', strtotime($r['data_cadastro'])) : '—' ?></td>
        <td><?= htmlspecialchars($r['tipo_cadastro']) ?></td>
        <td><?= htmlspecialchars($telFmt) ?></td>
        <td><?= htmlspecialchars($cpfFmt) ?></td>
        <td><?= htmlspecialchars((string)$r['uf']) ?></td>
        <td><?= htmlspecialchars((string)$r['municipio']) ?></td>
        <td><?= $r['fonte_ano'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<footer>MyPharm — importar_clientes.php | Este arquivo pode ser removido após a importação.</footer>
</body>
</html>
