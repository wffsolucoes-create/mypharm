<?php
/**
 * Carteira inativa → My Pharm
 *
 * A partir de 02/03/2026, só contam para a regra de "40 dias sem visita" as visitas
 * registradas a partir dessa data. Prescritores que passarem 40+ dias sem visita
 * (considerando apenas visitas a partir de 02/03/2026) são movidos automaticamente
 * para a carteira "My Pharm". O histórico de visitas anterior permanece intacto.
 *
 * Uso: require este arquivo e chamar runCarteiraInativaMyPharm($pdo).
 * Pode ser executado via cron (scripts/mover_carteira_inativa_mypharm.php) ou pela API (admin).
 */

if (!defined('CARTEIRA_INATIVA_DATA_INICIO')) {
    define('CARTEIRA_INATIVA_DATA_INICIO', '2026-03-02');
}
if (!defined('CARTEIRA_INATIVA_DIAS')) {
    define('CARTEIRA_INATIVA_DIAS', 40);
}
if (!defined('CARTEIRA_MY_PHARM')) {
    define('CARTEIRA_MY_PHARM', 'My Pharm');
}

/**
 * Identifica prescritores que estão há mais de 40 dias sem visita (a partir de 02/03/2026)
 * e move para a carteira My Pharm em prescritores_cadastro e prescritor_resumido.
 *
 * @param PDO $pdo
 * @param bool $dryRun Se true, apenas lista quem seria movido sem alterar o banco (para testar).
 * @return array{ moved: array<array{nome: string, visitador_anterior: string}>, count: int, dry_run?: bool }
 */
function runCarteiraInativaMyPharm(PDO $pdo, bool $dryRun = false): array
{
    $dataInicio = CARTEIRA_INATIVA_DATA_INICIO;
    $diasLimite = (int) CARTEIRA_INATIVA_DIAS;
    $myPharm = CARTEIRA_MY_PHARM;

    // Prescritores da carteira de visitadores (excluindo já My Pharm) com última visita desde data_inicio
    $sql = "
        SELECT pc.nome, pc.visitador AS visitador_anterior
        FROM prescritores_cadastro pc
        LEFT JOIN (
            SELECT prescritor, MAX(data_visita) AS ultima_visita
            FROM historico_visitas
            WHERE data_visita >= :data_inicio
            GROUP BY prescritor
        ) hv ON TRIM(pc.nome) = TRIM(hv.prescritor)
        WHERE pc.visitador IS NOT NULL
          AND TRIM(pc.visitador) != ''
          AND LOWER(TRIM(pc.visitador)) != 'my pharm'
          AND DATEDIFF(CURDATE(), COALESCE(hv.ultima_visita, :data_inicio)) > :dias
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'data_inicio' => $dataInicio,
        'dias'        => $diasLimite,
    ]);
    $toMove = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $moved = [];
    foreach ($toMove as $row) {
        $nome = trim($row['nome']);
        $visitadorAnterior = trim($row['visitador_anterior'] ?? '');
        if ($nome === '') {
            continue;
        }
        if ($dryRun) {
            $moved[] = ['nome' => $nome, 'visitador_anterior' => $visitadorAnterior];
            continue;
        }
        try {
            $pdo->prepare("UPDATE prescritores_cadastro SET visitador = :mypharm WHERE nome = :nome")
                ->execute(['mypharm' => $myPharm, 'nome' => $nome]);
            $pdo->prepare("UPDATE prescritor_resumido SET visitador = :mypharm WHERE nome = :nome")
                ->execute(['mypharm' => $myPharm, 'nome' => $nome]);
            $moved[] = ['nome' => $nome, 'visitador_anterior' => $visitadorAnterior];
        } catch (Throwable $e) {
            // log and skip this prescriber
            continue;
        }
    }

    $result = ['moved' => $moved, 'count' => count($moved)];
    if ($dryRun) {
        $result['dry_run'] = true;
    }
    return $result;
}
