<?php
/**
 * Notificações (sistema + mensagens entre visitadores).
 * - Notificações do sistema: 30 dias sem visita, 15/20/25... dias sem compra.
 * - Mensagens enviadas por usuários sobre prescritores (visíveis a quem tem o prescritor na carteira).
 */

function listNotificacoes(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado', 'notificacoes' => [], 'mensagens' => []], JSON_UNESCAPED_UNICODE);
        return;
    }

    $limit = min(max((int)($_GET['limit'] ?? 100), 1), 200);

    // Notificações do sistema (para este usuário)
    $stmt = $pdo->prepare("
        SELECT id, tipo, titulo, mensagem, prescritor_nome, dias_sem_compra, lida, criado_em
        FROM notificacoes
        WHERE usuario_id = :uid
        ORDER BY criado_em DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notificacoes as &$n) {
        $n['lida'] = (bool)(int)$n['lida'];
        $n['origem'] = 'sistema';
    }
    unset($n);

    // Mensagens de outros visitadores sobre prescritores da minha carteira (exclui as minhas)
    $stmt = $pdo->prepare("
        SELECT m.id, m.usuario_id, m.prescritor_nome, m.mensagem, m.criado_em, u.nome as autor_nome
        FROM mensagens_visitador m
        INNER JOIN usuarios u ON u.id = m.usuario_id
        INNER JOIN prescritores_cadastro pc ON TRIM(pc.nome) = TRIM(m.prescritor_nome) AND pc.usuario_id = :uid
        WHERE m.usuario_id != :uid2
        ORDER BY m.criado_em DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mensagens as &$m) {
        $m['origem'] = 'usuario';
        $m['autor'] = $m['autor_nome'] ?? '';
    }
    unset($m);

    // Mensagens enviadas por outros usuários para mim (exclui as que o usuário ocultou)
    $stmt = $pdo->prepare("
        SELECT mu.id, mu.de_usuario_id, mu.mensagem, mu.lida, mu.criado_em, u.nome as autor_nome
        FROM mensagens_usuario mu
        INNER JOIN usuarios u ON u.id = mu.de_usuario_id
        LEFT JOIN mensagens_usuario_ocultas oc ON oc.usuario_id = :uid AND oc.mensagem_id = mu.id
        WHERE mu.para_usuario_id = :uid2 AND oc.mensagem_id IS NULL
        ORDER BY mu.criado_em DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $mensagens_recebidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mensagens_recebidas as &$mr) {
        $mr['lida'] = (bool)(int)$mr['lida'];
        $mr['origem'] = 'msg_usuario';
        $mr['autor'] = $mr['autor_nome'] ?? '';
    }
    unset($mr);

    echo json_encode([
        'success' => true,
        'notificacoes' => $notificacoes,
        'mensagens' => $mensagens,
        'mensagens_recebidas' => $mensagens_recebidas,
    ], JSON_UNESCAPED_UNICODE);
}

/** Lista usuários disponíveis para enviar mensagem (ativos, exceto o próprio). */
function listUsuariosParaMensagem(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado', 'usuarios' => []], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE ativo = 1 AND id != :uid ORDER BY nome");
    $stmt->execute(['uid' => $userId]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'usuarios' => $usuarios], JSON_UNESCAPED_UNICODE);
}

/** Envia mensagem para um usuário do sistema. */
function enviarMensagemUsuario(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $paraId = (int)($input['para_usuario_id'] ?? 0);
    $mensagem = trim((string)($input['mensagem'] ?? ''));
    if ($paraId <= 0 || $mensagem === '') {
        echo json_encode(['success' => false, 'error' => 'Informe o destinatário e a mensagem.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($paraId === $userId) {
        echo json_encode(['success' => false, 'error' => 'Não é possível enviar mensagem para si mesmo.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO mensagens_usuario (de_usuario_id, para_usuario_id, mensagem) VALUES (:de, :para, :msg)");
    $stmt->execute(['de' => $userId, 'para' => $paraId, 'msg' => $mensagem]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
}

/** Marca mensagem recebida (mensagens_usuario) como lida. */
function marcarMensagemUsuarioLida(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare("UPDATE mensagens_usuario SET lida = 1 WHERE id = :id AND para_usuario_id = :uid");
    $stmt->execute(['id' => $id, 'uid' => $userId]);
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()], JSON_UNESCAPED_UNICODE);
}

function enviarMensagemVisitador(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $prescritor = trim((string)($input['prescritor_nome'] ?? ''));
    $mensagem = trim((string)($input['mensagem'] ?? ''));
    if ($prescritor === '' || $mensagem === '') {
        echo json_encode(['success' => false, 'error' => 'Informe o prescritor e a mensagem.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO mensagens_visitador (usuario_id, prescritor_nome, mensagem) VALUES (:uid, :prescritor, :msg)");
    $stmt->execute(['uid' => $userId, 'prescritor' => $prescritor, 'msg' => $mensagem]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
}

function marcarNotificacaoLida(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE id = :id AND usuario_id = :uid");
    $stmt->execute(['id' => $id, 'uid' => $userId]);
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()], JSON_UNESCAPED_UNICODE);
}

function apagarNotificacao(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM notificacoes WHERE id = :id AND usuario_id = :uid");
    $stmt->execute(['id' => $id, 'uid' => $userId]);
    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()], JSON_UNESCAPED_UNICODE);
}

/** Oculta mensagem recebida da lista do usuário (não remove do banco). */
function ocultarMensagemUsuario(PDO $pdo): void
{
    runNotificacoesTablesIfNeeded($pdo);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM mensagens_usuario WHERE id = :id AND para_usuario_id = :uid LIMIT 1");
    $stmt->execute(['id' => $id, 'uid' => $userId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Mensagem não encontrada.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO mensagens_usuario_ocultas (usuario_id, mensagem_id) VALUES (:uid, :id)");
    $stmt->execute(['uid' => $userId, 'id' => $id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
}
