<?php
require __DIR__ . '/../config.php';
$pdo = getConnection();

$indexes = [
    "ALTER TABLE gestao_pedidos ADD INDEX idx_numero_serie (numero_pedido, serie_pedido)",
    "ALTER TABLE gestao_pedidos ADD INDEX idx_ano_status_data (ano_referencia, status_financeiro, data_aprovacao)",
    "ALTER TABLE gestao_pedidos ADD INDEX idx_prescritor (prescritor(100))",
    "ALTER TABLE prescritores_cadastro ADD INDEX idx_visitador (visitador(80))",
    "ALTER TABLE prescritores_cadastro ADD INDEX idx_nome (nome(120))",
    "ALTER TABLE prescritor_resumido ADD INDEX idx_ano_nome (ano_referencia, nome(120))",
    "ALTER TABLE prescritor_resumido ADD INDEX idx_visitador (visitador(80))",
    "ALTER TABLE historico_visitas ADD INDEX idx_prescritor_data (prescritor(100), data_visita)",
    "ALTER TABLE historico_visitas ADD INDEX idx_visitador_data (visitador(80), data_visita)",
    "ALTER TABLE itens_orcamentos_pedidos ADD INDEX idx_ano_status (ano_referencia, status)",
    "ALTER TABLE itens_orcamentos_pedidos ADD INDEX idx_prescritor (prescritor(100))",
];

$ok = 0;
$skip = 0;
foreach ($indexes as $sql) {
    try {
        $pdo->exec($sql);
        echo "[OK] $sql\n";
        $ok++;
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1061') !== false) {
            echo "[SKIP] Já existe: $sql\n";
            $skip++;
        } else {
            echo "[ERRO] $sql => " . $e->getMessage() . "\n";
        }
    }
}
echo "\nCriados: $ok | Já existiam: $skip\n";
