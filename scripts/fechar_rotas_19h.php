<?php
/**
 * Fecha automaticamente rotas em andamento ou pausadas que:
 * - são de dias anteriores (nunca deixar rota aberta de um dia para o outro), ou
 * - são do dia atual e já passou das 19h.
 *
 * Uso: php scripts/fechar_rotas_19h.php
 * Cron (todo dia às 19:00): 0 19 * * * cd /caminho/mypharm && php scripts/fechar_rotas_19h.php
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php scripts/fechar_rotas_19h.php');
}

$baseDir = dirname(__DIR__);
require_once $baseDir . DIRECTORY_SEPARATOR . 'config.php';

$pdo = getConnection();

// Garantir que as tabelas existem
$pdo->exec("
    CREATE TABLE IF NOT EXISTS rotas_diarias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        visitador_nome VARCHAR(255) NOT NULL,
        data_inicio DATETIME NOT NULL,
        data_fim DATETIME NULL,
        pausado_em DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'em_andamento',
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_visitador_status (visitador_nome, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$stmt = $pdo->prepare("
    UPDATE rotas_diarias
    SET data_fim = NOW(), pausado_em = NULL, status = 'finalizada'
    WHERE status IN ('em_andamento', 'pausada')
    AND (DATE(data_inicio) < CURDATE() OR (DATE(data_inicio) = CURDATE() AND CURTIME() >= '19:00:00'))
");
$stmt->execute();
$fechadas = $stmt->rowCount();

if ($fechadas > 0) {
    echo date('Y-m-d H:i:s') . " – Rotas finalizadas automaticamente: $fechadas\n";
} else {
    echo date('Y-m-d H:i:s') . " – Nenhuma rota aberta para fechar.\n";
}
