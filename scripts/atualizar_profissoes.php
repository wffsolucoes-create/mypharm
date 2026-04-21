<?php
/**
 * Atualiza a tabela profissoes: insere as que já existem em prescritor_dados
 * e adiciona as profissões que costumam faltar (Esteticista, Fisioterapeuta, Nutricionista, etc.).
 * Executar: php scripts/atualizar_profissoes.php
 */
require __DIR__ . '/../config.php';
$pdo = getConnection();

// Profissões que devem existir (lista ampliada)
$listaFixa = [
    'Médico', 'Enfermeiro', 'Dentista', 'Farmacêutico', 'Veterinário',
    'Esteticista', 'Fisioterapeuta', 'Nutricionista', 'Psicólogo', 'Biomédico',
    'Educador Físico', 'Terapeuta Ocupacional', 'Fonoaudiólogo', 'Outros'
];

$stmt = $pdo->prepare("INSERT IGNORE INTO profissoes (nome) VALUES (?)");
$inseridas = 0;
foreach ($listaFixa as $nome) {
    $n = trim($nome);
    if ($n === '') continue;
    $stmt->execute([$n]);
    if ($stmt->rowCount() > 0) $inseridas++;
}

// Buscar profissões já usadas em prescritor_dados que não estão em profissoes
try {
    $stmtDistinct = $pdo->query("
        SELECT DISTINCT TRIM(profissao) as nome
        FROM prescritor_dados
        WHERE profissao IS NOT NULL AND TRIM(profissao) != ''
        ORDER BY nome
    ");
    $usadas = $stmtDistinct->fetchAll(PDO::FETCH_COLUMN);
    foreach ($usadas as $nome) {
        $nome = trim($nome);
        if ($nome === '') continue;
        $stmt->execute([$nome]);
        if ($stmt->rowCount() > 0) $inseridas++;
    }
} catch (Throwable $e) {
    // Tabela prescritor_dados pode não existir
}

$total = (int) $pdo->query("SELECT COUNT(*) FROM profissoes")->fetchColumn();
echo "Profissões na tabela: {$total} (inseridas nesta execução: {$inseridas})\n";
