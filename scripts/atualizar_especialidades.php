<?php
/**
 * Atualiza a tabela especialidades: insere as que já existem em prescritor_dados
 * e adiciona especialidades que costumam faltar.
 * Executar: php scripts/atualizar_especialidades.php
 */
require __DIR__ . '/../config.php';
$pdo = getConnection();

$listaFixa = [
    'Cardiologia', 'Pediatria', 'Clínico Geral', 'Dermatologia', 'Ginecologia', 'Ortopedia',
    'Neurologia', 'Oftalmologia', 'Psiquiatria', 'Urologia', 'Endocrinologia', 'Gastroenterologia',
    'Reumatologia', 'Pneumologia', 'Oncologia', 'Anestesiologia', 'Angiologia', 'Cancerologia',
    'Cirurgia Geral', 'Coloproctologia', 'Geriatria', 'Hematologia', 'Infectologia', 'Mastologia',
    'Medicina do Trabalho', 'Nefrologia', 'Neurocirurgia', 'Otorrinolaringologia', 'Patologia',
    'Medicina Estética', 'Fisioterapia', 'Nutrição Clínica', 'Psicologia Clínica', 'Outros'
];

$stmt = $pdo->prepare("INSERT IGNORE INTO especialidades (nome) VALUES (?)");
$inseridas = 0;
foreach ($listaFixa as $nome) {
    $n = trim($nome);
    if ($n === '') continue;
    $stmt->execute([$n]);
    if ($stmt->rowCount() > 0) $inseridas++;
}

try {
    $stmtDistinct = $pdo->query("
        SELECT DISTINCT TRIM(especialidade) as nome
        FROM prescritor_dados
        WHERE especialidade IS NOT NULL AND TRIM(especialidade) != ''
        ORDER BY nome
    ");
    $usadas = $stmtDistinct->fetchAll(PDO::FETCH_COLUMN);
    foreach ($usadas as $nome) {
        $nome = trim($nome);
        if ($nome === '') continue;
        $stmt->execute([$nome]);
        if ($stmt->rowCount() > 0) $inseridas++;
    }
} catch (Throwable $e) {}

$total = (int) $pdo->query("SELECT COUNT(*) FROM especialidades")->fetchColumn();
echo "Especialidades na tabela: {$total} (inseridas nesta execução: {$inseridas})\n";
