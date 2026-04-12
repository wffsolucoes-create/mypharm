<?php
/**
 * Corrige créditos fechados em que a reserva administrativa (ex.: 20%) foi aplicada indevidamente
 * na referência de vendas 03/2026 com competência 04/2026: alinha o modal de histórico
 * (Valor bruto = líquido consolidado, Desconto 0%, pct_desconto_admin_aplicado = 0).
 * Recalcula `percentual` (comissão sobre vendas) só quando o líquido > 0; caso contrário mantém o valor anterior.
 *
 * Uso:
 *   php scripts/fix_bonus_ref202603_reserva_zero.php
 *   php scripts/fix_bonus_ref202603_reserva_zero.php --apply
 *   php scripts/fix_bonus_ref202603_reserva_zero.php --apply --prescritor="Nome exato"
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execute pelo terminal: php scripts/fix_bonus_ref202603_reserva_zero.php [--apply] [--prescritor=\"Nome\"]\n");
    exit(1);
}

$baseDir = dirname(__DIR__);
require_once $baseDir . DIRECTORY_SEPARATOR . 'config.php';

$apply = in_array('--apply', $argv, true);
$prescritorFiltro = null;
foreach ($argv as $arg) {
    if (is_string($arg) && strncmp($arg, '--prescritor=', 13) === 0) {
        $v = trim(substr($arg, 13));
        $prescritorFiltro = $v === '' ? null : $v;
    }
}

$pdo = getConnection();

$refAno = 2026;
$refMes = 3;
$compAno = 2026;
$compMes = 4;

$sql = 'SELECT id, prescritor, visitador, valor_base, percentual, valor_bruto_snapshot, valor_credito,
            pct_desconto_admin_aplicado, valor_liquido_consolidado, credito_status
     FROM prescritor_bonus_creditos_mensais
     WHERE referencia_ano = :ra AND referencia_mes = :rm
       AND competencia_ano = :ca AND competencia_mes = :cm
       AND LOWER(TRIM(COALESCE(credito_status, \'\'))) = \'fechado\'
       AND (
            COALESCE(pct_desconto_admin_aplicado, -1) BETWEEN 19.5 AND 20.5
            OR (
                COALESCE(valor_bruto_snapshot, 0) > 0
                AND COALESCE(valor_liquido_consolidado, valor_credito, 0) > 0
                AND ABS(COALESCE(valor_bruto_snapshot, 0) * 0.8 - COALESCE(valor_liquido_consolidado, valor_credito, 0)) < 0.05
            )
       )';
$params = [
    'ra' => $refAno,
    'rm' => $refMes,
    'ca' => $compAno,
    'cm' => $compMes,
];
if ($prescritorFiltro !== null) {
    $sql .= ' AND prescritor = :prescritor';
    $params['prescritor'] = $prescritorFiltro;
}

$sel = $pdo->prepare($sql);
$sel->execute($params);
$rows = $sel->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nenhuma linha encontrada para ref {$refMes}/{$refAno} + competência {$compMes}/{$compAno} (fechado, critério 20%).\n";
    exit(0);
}

echo 'Encontradas ' . count($rows) . " linha(s) (ref. {$refMes}/{$refAno}, competência {$compMes}/{$compAno}).\n";
foreach ($rows as $r) {
    $liq = round((float)($r['valor_liquido_consolidado'] ?? $r['valor_credito'] ?? 0), 2);
    echo sprintf(
        "  id=%s | %s | bruto=%s | pct_adm=%s | liq=%s\n",
        $r['id'],
        mb_substr((string)$r['prescritor'], 0, 60),
        $r['valor_bruto_snapshot'],
        $r['pct_desconto_admin_aplicado'],
        $r['valor_liquido_consolidado'] ?? $r['valor_credito']
    );
}

if (!$apply) {
    echo "\nModo simulação. Para gravar, execute com --apply\n";
    exit(0);
}

$upd = $pdo->prepare(
    'UPDATE prescritor_bonus_creditos_mensais
     SET valor_bruto_snapshot = :bruto,
         pct_desconto_admin_aplicado = 0,
         valor_credito = :liq,
         valor_liquido_consolidado = :liq2,
         percentual = CASE
             WHEN valor_base > 0.01 AND :liq3 > 0.005 THEN LEAST(1, GREATEST(0, ROUND(:liq3 / valor_base, 4)))
             ELSE percentual
         END,
         atualizado_em = NOW()
     WHERE id = :id'
);

foreach ($rows as $r) {
    $liq = round((float)($r['valor_liquido_consolidado'] ?? $r['valor_credito'] ?? 0), 2);
    $upd->execute([
        'bruto' => $liq,
        'liq' => $liq,
        'liq2' => $liq,
        'liq3' => $liq,
        'id' => (int)$r['id'],
    ]);
}

echo "\nAtualizado(s) " . count($rows) . " registro(s).\n";
