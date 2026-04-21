<?php
/**
 * Uso único (CLI ou browser): copia WhatsApp de prescritor_contatos para prescritor_dados
 * quando prescritor_dados.whatsapp estiver vazio — alinha a tabela padrão com dados legados.
 *
 *   php scripts/sync_whatsapp_contatos_para_prescritor_dados.php
 */
require_once dirname(__DIR__) . '/config.php';

$pdo = getConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "PDO indisponível.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/api/modules/prescritores.php';
if (function_exists('prescritores_ensure_prescritor_dados_schema')) {
    prescritores_ensure_prescritor_dados_schema($pdo);
}

$n = 0;
try {
    $upd = $pdo->exec("
        UPDATE prescritor_dados pd
        INNER JOIN prescritor_contatos c ON c.nome_prescritor = pd.nome_prescritor
        SET pd.whatsapp = c.whatsapp, pd.atualizado_em = NOW()
        WHERE (pd.whatsapp IS NULL OR TRIM(pd.whatsapp) = '')
          AND c.whatsapp IS NOT NULL AND TRIM(c.whatsapp) != ''
    ");
    $n = (int)$upd;
} catch (Throwable $e) {
    fwrite(STDERR, 'UPDATE: ' . $e->getMessage() . "\n");
    exit(1);
}

$ins = 0;
try {
    $stmt = $pdo->exec("
        INSERT INTO prescritor_dados (nome_prescritor, whatsapp, atualizado_em)
        SELECT c.nome_prescritor, c.whatsapp, NOW()
        FROM prescritor_contatos c
        WHERE c.whatsapp IS NOT NULL AND TRIM(c.whatsapp) != ''
          AND NOT EXISTS (SELECT 1 FROM prescritor_dados d WHERE d.nome_prescritor = c.nome_prescritor)
    ");
    $ins = (int)$stmt;
} catch (Throwable $e) {
    fwrite(STDERR, 'INSERT: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Atualizados (whatsapp preenchido em prescritor_dados): {$n}\n";
echo "Inseridos (novo registro só com whatsapp): {$ins}\n";
