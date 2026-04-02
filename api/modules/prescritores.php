<?php

/** Resolve nome do visitador para usuario_id (usuarios com setor = visitador). Retorna null se My Pharm / vazio. */
function prescritores_resolve_usuario_id(PDO $pdo, string $visitador): ?int
{
    $v = trim($visitador);
    if ($v === '' || strcasecmp($v, 'My Pharm') === 0) {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(TRIM(COALESCE(setor,''))) = 'visitador' AND TRIM(COALESCE(nome,'')) = 'My Pharm' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE LOWER(TRIM(COALESCE(setor,''))) = 'visitador' AND TRIM(nome) = :nome LIMIT 1");
    $stmt->execute(['nome' => $v]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

/** Garante tabela prescritor_dados com colunas de cadastro completo (fonte padrão dos dados do prescritor). */
function prescritores_ensure_prescritor_dados_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prescritor_dados (
            nome_prescritor VARCHAR(255) PRIMARY KEY,
            profissao VARCHAR(255) NULL,
            especialidade VARCHAR(255) NULL,
            registro VARCHAR(100) NULL,
            uf_registro VARCHAR(10) NULL,
            data_nascimento DATE NULL,
            endereco_rua VARCHAR(255) NULL,
            endereco_numero VARCHAR(20) NULL,
            endereco_bairro VARCHAR(120) NULL,
            endereco_cep VARCHAR(20) NULL,
            endereco_cidade VARCHAR(120) NULL,
            endereco_uf VARCHAR(5) NULL,
            local_atendimento VARCHAR(50) NULL,
            whatsapp VARCHAR(30) NULL,
            email VARCHAR(255) NULL,
            usuario_id INT NULL,
            atualizado_em DATETIME NULL
        )
    ");
    try {
        $pdo->exec("ALTER TABLE prescritor_dados ADD COLUMN usuario_id INT NULL");
    } catch (Throwable $e) { /* já existe */
    }
    try {
        $pdo->exec("ALTER TABLE prescritor_dados ADD COLUMN especialidade VARCHAR(255) NULL");
    } catch (Throwable $e) { /* já existe */
    }
}

function handlePrescritoresModuleAction(string $action, PDO $pdo): bool
{
    try {
        $pdo->exec("ALTER TABLE prescritores_cadastro ADD COLUMN usuario_id INT NULL");
    } catch (Throwable $e) { /* coluna já existe */ }

    switch ($action) {
        case 'save_prescritor_whatsapp':
            $input = json_decode(file_get_contents('php://input'), true);
            $nome = trim($input['nome_prescritor'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? '');

            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome do prescritor não fornecido']);
                return true;
            }

            // Fonte padrão: prescritor_dados.
            prescritores_ensure_prescritor_dados_schema($pdo);
            $stmtPd = $pdo->prepare("
                INSERT INTO prescritor_dados (nome_prescritor, whatsapp, atualizado_em)
                VALUES (:nome, :whatsapp, NOW())
                ON DUPLICATE KEY UPDATE whatsapp = VALUES(whatsapp), atualizado_em = NOW()
            ");
            $stmtPd->execute(['nome' => $nome, 'whatsapp' => $whatsapp]);
            // Compatibilidade com telas legadas: tenta espelhar em prescritor_contatos sem quebrar fluxo.
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO prescritor_contatos (nome_prescritor, whatsapp) 
                    VALUES (:nome, :whatsapp)
                    ON DUPLICATE KEY UPDATE whatsapp = :whatsapp2, atualizado_em = NOW()
                ");
                $stmt->execute(['nome' => $nome, 'whatsapp' => $whatsapp, 'whatsapp2' => $whatsapp]);
            } catch (Throwable $e) { /* ignora legado */
            }
            echo json_encode(['success' => true, 'message' => 'WhatsApp salvo com sucesso!'], JSON_UNESCAPED_UNICODE);
            return true;

        case 'get_prescritor_contatos':
            // Prioridade: prescritor_dados; fallback: prescritor_contatos (dados legados).
            $contatos = [];
            try {
                prescritores_ensure_prescritor_dados_schema($pdo);
                $st = $pdo->query("SELECT nome_prescritor, whatsapp FROM prescritor_dados WHERE whatsapp IS NOT NULL AND TRIM(whatsapp) != ''");
                if ($st) {
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $contatos[trim($row['nome_prescritor'])] = $row['whatsapp'];
                    }
                }
            } catch (Throwable $e) { /* ignora */
            }
            try {
                $stmt = $pdo->query("SELECT nome_prescritor, whatsapp FROM prescritor_contatos WHERE whatsapp IS NOT NULL AND whatsapp != ''");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $k = trim($row['nome_prescritor']);
                    if ($k !== '' && !isset($contatos[$k])) {
                        $contatos[$k] = $row['whatsapp'];
                    }
                }
            } catch (Throwable $e) { /* ignora */
            }
            echo json_encode($contatos, JSON_UNESCAPED_UNICODE);
            return true;

        case 'get_prescritor_dados':
            $nome = trim($_GET['nome_prescritor'] ?? ($_GET['nome'] ?? ''));
            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome não informado'], JSON_UNESCAPED_UNICODE);
                return true;
            }
            prescritores_ensure_prescritor_dados_schema($pdo);
            $stmt = $pdo->prepare("
                SELECT pd.*, COALESCE(pd.usuario_id, pc.usuario_id) as usuario_id
                FROM prescritor_dados pd
                LEFT JOIN prescritores_cadastro pc ON LOWER(TRIM(pc.nome)) = LOWER(TRIM(pd.nome_prescritor))
                WHERE LOWER(TRIM(pd.nome_prescritor)) = LOWER(TRIM(:nome))
                ORDER BY CASE WHEN pd.nome_prescritor = :nome_exato THEN 0 ELSE 1 END, pd.nome_prescritor
                LIMIT 1
            ");
            $stmt->execute(['nome' => $nome, 'nome_exato' => $nome]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $usuarioIdFromCadastro = null;
            $whatsappFallbackContatos = '';
            if (!$row) {
                $st = $pdo->prepare("SELECT usuario_id FROM prescritores_cadastro WHERE LOWER(TRIM(nome)) = LOWER(TRIM(:nome)) ORDER BY CASE WHEN nome = :nome_exato THEN 0 ELSE 1 END LIMIT 1");
                $st->execute(['nome' => $nome, 'nome_exato' => $nome]);
                $cad = $st->fetch(PDO::FETCH_ASSOC);
                $usuarioIdFromCadastro = $cad && isset($cad['usuario_id']) ? (int)$cad['usuario_id'] : null;
                try {
                    $stW = $pdo->prepare("SELECT whatsapp FROM prescritor_contatos WHERE LOWER(TRIM(nome_prescritor)) = LOWER(TRIM(:n)) ORDER BY CASE WHEN nome_prescritor = :n_exato THEN 0 ELSE 1 END LIMIT 1");
                    $stW->execute(['n' => $nome, 'n_exato' => $nome]);
                    $wRow = $stW->fetch(PDO::FETCH_ASSOC);
                    if ($wRow && trim((string)($wRow['whatsapp'] ?? '')) !== '') {
                        $whatsappFallbackContatos = trim((string)$wRow['whatsapp']);
                    }
                } catch (Throwable $e) { /* ignora */
                }
            }
            $dados = $row ?: [
                'nome_prescritor' => $nome,
                'profissao' => '',
                'especialidade' => '',
                'registro' => '',
                'uf_registro' => '',
                'data_nascimento' => null,
                'endereco_rua' => '',
                'endereco_numero' => '',
                'endereco_bairro' => '',
                'endereco_cep' => '',
                'endereco_cidade' => '',
                'endereco_uf' => '',
                'local_atendimento' => '',
                'whatsapp' => $whatsappFallbackContatos,
                'email' => '',
                'usuario_id' => $usuarioIdFromCadastro
            ];
            if ($row) {
                $dados['profissao'] = $row['profissao'] ?? '';
                $dados['especialidade'] = $row['especialidade'] ?? '';
                $dados['registro'] = $row['registro'] ?? '';
                $dados['uf_registro'] = $row['uf_registro'] ?? '';
                $dados['data_nascimento'] = $row['data_nascimento'] ?? null;
                $dados['endereco_rua'] = $row['endereco_rua'] ?? '';
                $dados['endereco_numero'] = $row['endereco_numero'] ?? '';
                $dados['endereco_bairro'] = $row['endereco_bairro'] ?? '';
                $dados['endereco_cep'] = $row['endereco_cep'] ?? '';
                $dados['endereco_cidade'] = $row['endereco_cidade'] ?? '';
                $dados['endereco_uf'] = $row['endereco_uf'] ?? '';
                $dados['local_atendimento'] = $row['local_atendimento'] ?? '';
                $wa = trim((string)($row['whatsapp'] ?? ''));
                $dados['whatsapp'] = $wa;
                $dados['email'] = $row['email'] ?? '';
                $dados['usuario_id'] = isset($row['usuario_id']) ? (int)$row['usuario_id'] : null;
            }
            $includeKpi = isset($_GET['include_kpi']) ? !in_array(strtolower((string)$_GET['include_kpi']), ['0', 'false', 'no', 'off'], true) : true;
            if ($includeKpi) {
                $visitador = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($_GET['visitador'] ?? '');
                $ano = (int)($_GET['ano'] ?? date('Y'));
                $mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int)$_GET['mes'] : null;
                $whereVis = $visitador !== '' ? " AND pc.visitador = :vis" : "";
                if ($mes !== null) {
                    $stmtKpi = $pdo->prepare("
                        SELECT
                            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) as valor_aprovado,
                            COALESCE(SUM(CASE WHEN gp.status_financeiro = 'Recusado' THEN gp.preco_liquido ELSE 0 END), 0) as valor_recusado
                        FROM prescritores_cadastro pc
                        LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome
                            AND gp.ano_referencia = :ano AND MONTH(gp.data_aprovacao) = :mes
                        WHERE pc.nome = :nome $whereVis
                        GROUP BY pc.nome
                    ");
                    $paramsKpi = ['nome' => $nome, 'ano' => $ano, 'mes' => $mes];
                    if ($visitador !== '') {
                        $paramsKpi['vis'] = $visitador;
                    }
                    $stmtKpi->execute($paramsKpi);
                } else {
                    $stmtKpi = $pdo->prepare("
                        SELECT
                            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) as valor_aprovado,
                            COALESCE(SUM(pr.valor_recusado), 0) + COALESCE(SUM(pr.valor_no_carrinho), 0) as valor_recusado
                        FROM prescritores_cadastro pc
                        LEFT JOIN prescritor_resumido pr ON pr.nome = pc.nome AND pr.ano_referencia = :ano
                        LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome AND gp.ano_referencia = :ano2
                            AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                        WHERE pc.nome = :nome $whereVis
                        GROUP BY pc.nome
                    ");
                    $paramsKpi = ['nome' => $nome, 'ano' => $ano, 'ano2' => $ano];
                    if ($visitador !== '') {
                        $paramsKpi['vis'] = $visitador;
                    }
                    $stmtKpi->execute($paramsKpi);
                }
                $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);
                $dados['aprovados'] = $kpi ? number_format((float)($kpi['valor_aprovado'] ?? 0), 2, ',', '.') : '0,00';
                $dados['recusados'] = $kpi ? number_format((float)($kpi['valor_recusado'] ?? 0), 2, ',', '.') : '0,00';
            } else {
                $dados['aprovados'] = '0,00';
                $dados['recusados'] = '0,00';
            }
            echo json_encode(['success' => true, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
            return true;

        case 'update_prescritor_dados':
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $nome = trim($input['nome_prescritor'] ?? '');
            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome não informado'], JSON_UNESCAPED_UNICODE);
                return true;
            }
            prescritores_ensure_prescritor_dados_schema($pdo);
            $usuarioId = null;
            try {
                $stUid = $pdo->prepare("
                    SELECT usuario_id
                    FROM prescritores_cadastro
                    WHERE LOWER(TRIM(nome)) = LOWER(TRIM(:nome))
                    ORDER BY CASE WHEN nome = :nome_exato THEN 0 ELSE 1 END
                    LIMIT 1
                ");
                $stUid->execute(['nome' => $nome, 'nome_exato' => $nome]);
                $rowUid = $stUid->fetch(PDO::FETCH_ASSOC);
                if ($rowUid && isset($rowUid['usuario_id']) && $rowUid['usuario_id'] !== null && $rowUid['usuario_id'] !== '') {
                    $usuarioId = (int)$rowUid['usuario_id'];
                }
            } catch (Throwable $e) { /* mantém null */
            }
            $stmt = $pdo->prepare("
                INSERT INTO prescritor_dados (nome_prescritor, profissao, especialidade, registro, uf_registro, data_nascimento, endereco_rua, endereco_numero, endereco_bairro, endereco_cep, endereco_cidade, endereco_uf, local_atendimento, whatsapp, email, usuario_id, atualizado_em)
                VALUES (:nome, :profissao, :especialidade, :registro, :uf_registro, :data_nascimento, :endereco_rua, :endereco_numero, :endereco_bairro, :endereco_cep, :endereco_cidade, :endereco_uf, :local_atendimento, :whatsapp, :email, :usuario_id, NOW())
                ON DUPLICATE KEY UPDATE
                    profissao = VALUES(profissao), especialidade = VALUES(especialidade), registro = VALUES(registro), uf_registro = VALUES(uf_registro), data_nascimento = VALUES(data_nascimento),
                    endereco_rua = VALUES(endereco_rua), endereco_numero = VALUES(endereco_numero), endereco_bairro = VALUES(endereco_bairro), endereco_cep = VALUES(endereco_cep),
                    endereco_cidade = VALUES(endereco_cidade), endereco_uf = VALUES(endereco_uf), local_atendimento = VALUES(local_atendimento), whatsapp = VALUES(whatsapp), email = VALUES(email),
                    usuario_id = COALESCE(VALUES(usuario_id), usuario_id), atualizado_em = NOW()
            ");
            $stmt->execute([
                'nome' => $nome,
                'profissao' => trim($input['profissao'] ?? ''),
                'especialidade' => trim($input['especialidade'] ?? ''),
                'registro' => trim($input['registro'] ?? ''),
                'uf_registro' => trim($input['uf_registro'] ?? ''),
                'data_nascimento' => !empty($input['data_nascimento']) ? $input['data_nascimento'] : null,
                'endereco_rua' => trim($input['endereco_rua'] ?? ''),
                'endereco_numero' => trim($input['endereco_numero'] ?? ''),
                'endereco_bairro' => trim($input['endereco_bairro'] ?? ''),
                'endereco_cep' => trim($input['endereco_cep'] ?? ''),
                'endereco_cidade' => trim($input['endereco_cidade'] ?? ''),
                'endereco_uf' => trim($input['endereco_uf'] ?? ''),
                'local_atendimento' => trim($input['local_atendimento'] ?? ''),
                'whatsapp' => trim($input['whatsapp'] ?? ''),
                'email' => trim($input['email'] ?? ''),
                'usuario_id' => $usuarioId
            ]);
            try {
                $pdo->prepare("INSERT INTO prescritor_contatos (nome_prescritor, whatsapp) VALUES (:n, :w) ON DUPLICATE KEY UPDATE whatsapp = :w2, atualizado_em = NOW()")->execute(['n' => $nome, 'w' => trim($input['whatsapp'] ?? ''), 'w2' => trim($input['whatsapp'] ?? '')]);
            } catch (Throwable $e) { /* ignora legado */
            }
            echo json_encode(['success' => true, 'message' => 'Dados salvos com sucesso!'], JSON_UNESCAPED_UNICODE);
            return true;

        case 'list_profissoes':
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS profissoes (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(255) NOT NULL, UNIQUE KEY uk_profissoes_nome (nome)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Throwable $e) { /* tabela já existe */ }
            $stmt = $pdo->query("SELECT id, nome FROM profissoes ORDER BY nome ASC");
            $raw = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            // Remover duplicatas: mesmo nome em maiúsculas e em título (ex.: MÉDICO e Médico) — manter só a versão em título/minúsculas
            $byKey = [];
            foreach ($raw as $row) {
                $nome = trim($row['nome'] ?? '');
                if ($nome === '') continue;
                $key = mb_strtolower($nome);
                $isAllUpper = ($nome === mb_strtoupper($nome));
                if (!isset($byKey[$key])) {
                    $byKey[$key] = ['id' => $row['id'], 'nome' => $nome];
                } else {
                    if ($isAllUpper && $byKey[$key]['nome'] !== mb_strtoupper($byKey[$key]['nome'])) {
                        continue; // já temos versão em título, descarta a maiúscula
                    }
                    if (!$isAllUpper && $byKey[$key]['nome'] === mb_strtoupper($byKey[$key]['nome'])) {
                        $byKey[$key] = ['id' => $row['id'], 'nome' => $nome]; // preferir título
                    }
                }
            }
            $items = array_values($byKey);
            usort($items, function ($a, $b) { return strcasecmp($a['nome'], $b['nome']); });
            echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
            return true;

        case 'list_especialidades':
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS especialidades (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(255) NOT NULL, UNIQUE KEY uk_especialidades_nome (nome)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Throwable $e) { /* tabela já existe */ }
            $stmt = $pdo->query("SELECT id, nome FROM especialidades ORDER BY nome ASC");
            $items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
            return true;

        case 'transfer_prescritor':
            $input = json_decode(file_get_contents('php://input'), true);
            $nome = trim($input['nome_prescritor'] ?? '');
            $novo_visitador = trim($input['novo_visitador'] ?? '');

            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome do prescritor não fornecido']);
                return true;
            }

            if ($novo_visitador === 'My Pharm') {
                $novo_visitador = '';
            }

            $stmt = $pdo->prepare("UPDATE prescritor_resumido SET visitador = :vis WHERE nome = :nome");
            $stmt->execute(['vis' => $novo_visitador, 'nome' => $nome]);

            $usuarioId = prescritores_resolve_usuario_id($pdo, $novo_visitador);
            $stmtCadastro = $pdo->prepare("INSERT INTO prescritores_cadastro (nome, visitador, usuario_id) VALUES (:nome, :vis, :uid) ON DUPLICATE KEY UPDATE visitador = :vis2, usuario_id = :uid2");
            $stmtCadastro->execute(['nome' => $nome, 'vis' => $novo_visitador, 'uid' => $usuarioId, 'vis2' => $novo_visitador, 'uid2' => $usuarioId]);

            echo json_encode(['success' => true, 'message' => 'Prescritor transferido com sucesso!'], JSON_UNESCAPED_UNICODE);
            return true;

        case 'add_prescritor':
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $nome = trim($input['nome_prescritor'] ?? '');
            $visitador = trim($input['visitador'] ?? '');

            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome do prescritor não informado.'], JSON_UNESCAPED_UNICODE);
                return true;
            }

            if ($visitador === 'My Pharm') {
                $visitador = '';
            }

            try {
                $stmtCheck = $pdo->prepare("SELECT 1 FROM prescritores_cadastro WHERE nome = :nome LIMIT 1");
                $stmtCheck->execute(['nome' => $nome]);
                if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                    echo json_encode(['success' => false, 'error' => 'Já existe um prescritor cadastrado com este nome.'], JSON_UNESCAPED_UNICODE);
                    return true;
                }
                $usuarioId = prescritores_resolve_usuario_id($pdo, $visitador);
                $stmtCadastro = $pdo->prepare("INSERT INTO prescritores_cadastro (nome, visitador, usuario_id) VALUES (:nome, :vis, :uid)");
                $stmtCadastro->execute(['nome' => $nome, 'vis' => $visitador, 'uid' => $usuarioId]);
                echo json_encode(['success' => true, 'message' => 'Prescritor cadastrado com sucesso!'], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => 'Erro ao cadastrar: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            return true;
    }

    return false;
}
