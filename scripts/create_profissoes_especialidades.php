<?php
/**
 * Cria as tabelas profissoes e especialidades e insere valores iniciais.
 * Executar: php scripts/create_profissoes_especialidades.php
 */
require __DIR__ . '/../config.php';
$pdo = getConnection();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS profissoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL,
        UNIQUE KEY uk_profissoes_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS especialidades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL,
        UNIQUE KEY uk_especialidades_nome (nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$profissoes = [
    'Médico', 'Enfermeiro', 'Dentista', 'Farmacêutico', 'Veterinário',
    'Esteticista', 'Fisioterapeuta', 'Nutricionista', 'Psicólogo', 'Biomédico',
    'Educador Físico', 'Terapeuta Ocupacional', 'Fonoaudiólogo', 'Outros'
];
$especialidades = [
    'Cardiologia', 'Pediatria', 'Clínico Geral', 'Dermatologia', 'Ginecologia', 'Ortopedia',
    'Neurologia', 'Oftalmologia', 'Psiquiatria', 'Urologia', 'Endocrinologia', 'Gastroenterologia',
    'Reumatologia', 'Pneumologia', 'Oncologia', 'Anestesiologia', 'Angiologia', 'Cancerologia',
    'Cirurgia Geral', 'Coloproctologia', 'Geriatria', 'Hematologia', 'Infectologia', 'Mastologia',
    'Medicina do Trabalho', 'Nefrologia', 'Neurocirurgia', 'Otorrinolaringologia', 'Patologia',
    'Medicina Estética', 'Fisioterapia', 'Nutrição Clínica', 'Psicologia Clínica', 'Outros'
];

$stmtP = $pdo->prepare("INSERT IGNORE INTO profissoes (nome) VALUES (?)");
foreach ($profissoes as $nome) {
    $stmtP->execute([$nome]);
}

$stmtE = $pdo->prepare("INSERT IGNORE INTO especialidades (nome) VALUES (?)");
foreach ($especialidades as $nome) {
    $stmtE->execute([$nome]);
}

$countP = (int) $pdo->query("SELECT COUNT(*) FROM profissoes")->fetchColumn();
$countE = (int) $pdo->query("SELECT COUNT(*) FROM especialidades")->fetchColumn();
echo "Tabelas criadas. Profissões: {$countP} | Especialidades: {$countE}\n";
