<?php
/**
 * Gera notificações do sistema no banco:
 * - 30 dias sem visita: avisa o visitador que o prescritor está 30+ dias sem visita (desde 02/03/2026).
 * - 15 dias sem compra: avisa que o prescritor está 15 dias sem compra; repete a cada 5 dias (15, 20, 25, 30...).
 *
 * Uso: php scripts/gerar_notificacoes_sistema.php
 * Cron sugerido: diário, ex. 0 7 * * * cd /caminho/mypharm && php scripts/gerar_notificacoes_sistema.php
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php scripts/gerar_notificacoes_sistema.php');
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';

$pdo = getConnection();
runNotificacoesTablesIfNeeded($pdo);

define('DATA_INICIO_VISITAS', '2026-03-02');
define('DIAS_SEM_VISITA_ALERTA', 30);
define('DIAS_SEM_COMPRA_INICIAL', 15);
define('DIAS_SEM_COMPRA_INTERVALO', 5);

$inseridas = 0;

// 1) 30 dias sem visita (desde DATA_INICIO_VISITAS)
$sqlVisita = "
    SELECT pc.nome AS prescritor_nome, pc.usuario_id
    FROM prescritores_cadastro pc
    LEFT JOIN (
        SELECT prescritor, MAX(data_visita) AS ultima_visita
        FROM historico_visitas
        WHERE data_visita >= :data_inicio
        GROUP BY prescritor
    ) hv ON TRIM(pc.nome) = TRIM(hv.prescritor)
    WHERE pc.usuario_id IS NOT NULL
      AND (pc.visitador IS NULL OR TRIM(pc.visitador) = '' OR LOWER(TRIM(pc.visitador)) != 'my pharm')
      AND DATEDIFF(CURDATE(), COALESCE(hv.ultima_visita, :data_inicio)) >= :dias
";
$stmt = $pdo->prepare($sqlVisita);
$stmt->execute([
    'data_inicio' => DATA_INICIO_VISITAS,
    'dias'        => DIAS_SEM_VISITA_ALERTA,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $prescritor = trim($r['prescritor_nome'] ?? '');
    $uid = (int)$r['usuario_id'];
    if ($prescritor === '' || $uid <= 0) continue;
    // Evitar duplicata no mesmo dia
    $existe = $pdo->prepare("SELECT 1 FROM notificacoes WHERE usuario_id = ? AND prescritor_nome = ? AND tipo = '30_dias_sem_visita' AND DATE(criado_em) = CURDATE() LIMIT 1");
    $existe->execute([$uid, $prescritor]);
    if ($existe->fetchColumn()) continue;
    $ins = $pdo->prepare("INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, prescritor_nome, lida) VALUES (?, '30_dias_sem_visita', ?, ?, ?, 0)");
    $titulo = 'Prescritor sem visita há 30 dias';
    $msg = $prescritor . ' está há 30 dias ou mais sem visita (desde ' . DATA_INICIO_VISITAS . ').';
    $ins->execute([$uid, $titulo, $msg, $prescritor]);
    $inseridas++;
}

// 2) 15 dias sem compra, repetir a cada 5 dias (15, 20, 25, 30...)
$sqlCompra = "
    SELECT pc.nome AS prescritor_nome, pc.usuario_id,
           DATEDIFF(CURDATE(), COALESCE(MAX(gp.data_aprovacao), '2000-01-01')) AS dias_sem_compra
    FROM prescritores_cadastro pc
    LEFT JOIN gestao_pedidos gp ON TRIM(COALESCE(NULLIF(gp.prescritor,''), 'My Pharm')) = TRIM(pc.nome) AND gp.data_aprovacao IS NOT NULL
    WHERE pc.usuario_id IS NOT NULL
      AND (pc.visitador IS NULL OR TRIM(pc.visitador) = '' OR LOWER(TRIM(pc.visitador)) != 'my pharm')
    GROUP BY pc.nome, pc.usuario_id
    HAVING dias_sem_compra >= :dias_min
";
$stmt = $pdo->prepare($sqlCompra);
$stmt->execute(['dias_min' => DIAS_SEM_COMPRA_INICIAL]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $prescritor = trim($r['prescritor_nome'] ?? '');
    $uid = (int)$r['usuario_id'];
    $dias = (int)$r['dias_sem_compra'];
    if ($prescritor === '' || $uid <= 0 || $dias < DIAS_SEM_COMPRA_INICIAL) continue;
    // Marco atual: 15, 20, 25, 30... (intervalos de 5 a partir de 15)
    $marco = DIAS_SEM_COMPRA_INICIAL + (int)floor(($dias - DIAS_SEM_COMPRA_INICIAL) / DIAS_SEM_COMPRA_INTERVALO) * DIAS_SEM_COMPRA_INTERVALO;
    if ($marco < DIAS_SEM_COMPRA_INICIAL) $marco = DIAS_SEM_COMPRA_INICIAL;
    $existe = $pdo->prepare("SELECT 1 FROM notificacoes WHERE usuario_id = ? AND prescritor_nome = ? AND tipo = 'dias_sem_compra' AND dias_sem_compra = ? LIMIT 1");
    $existe->execute([$uid, $prescritor, $marco]);
    if ($existe->fetchColumn()) continue;
    $ins = $pdo->prepare("INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, prescritor_nome, dias_sem_compra, lida) VALUES (?, 'dias_sem_compra', ?, ?, ?, ?, 0)");
    $titulo = $prescritor . ' – ' . $marco . ' dias sem compra';
    $msg = $prescritor . ' está há ' . $marco . ' dias sem compra aprovada.';
    $ins->execute([$uid, $titulo, $msg, $prescritor, $marco]);
    $inseridas++;
}

echo "Notificações inseridas: $inseridas\n";
exit(0);
