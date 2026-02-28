<?php
/**
 * Adiciona a coluna especialidade na tabela prescritor_dados (se não existir).
 * Executar: php scripts/add_coluna_especialidade.php
 */
require __DIR__ . '/../config.php';
$pdo = getConnection();

$table = 'prescritor_dados';
$column = 'especialidade';

try {
    $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
    if ($stmt->rowCount() === 0) {
        echo "Tabela {$table} não existe. Nada a fazer.\n";
        exit(0);
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    if ($stmt->rowCount() > 0) {
        echo "Coluna {$column} já existe em {$table}.\n";
        exit(0);
    }

    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} VARCHAR(255) NULL");
    echo "Coluna {$column} criada com sucesso em {$table}.\n";
} catch (Throwable $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
