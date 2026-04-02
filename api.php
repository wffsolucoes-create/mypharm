<?php
// Evitar que erros/avisos PHP (Notice, Warning) sejam enviados como HTML e quebrem respostas JSON
ini_set('display_errors', '0');
if (function_exists('ini_set')) {
    @ini_set('log_errors', '1');
}
require_once 'config.php';
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/modules/prescritores.php';
require_once __DIR__ . '/api/modules/dashboard.php';
require_once __DIR__ . '/api/modules/notificacoes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// CORS - permitir origens confiáveis (local + produção)
$allowedOrigins = [
    'http://localhost',
    'https://localhost',
    'http://127.0.0.1',
    'https://127.0.0.1',
    // Adicione seu domínio da Hostinger abaixo:
    // 'https://rede-mypharm.com.br',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
// Se não há header Origin, é requisição same-origin — CORS não se aplica
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Preflight OPTIONS: responder 200 para o navegador enviar o POST com X-CSRF-Token
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Headers de segurança adicionais
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header("Referrer-Policy: strict-origin-when-cross-origin");

// CSRF: validar em requisições POST (exceto login que usa rate limiting próprio)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $action = $_GET['action'] ?? '';
    $csrfExempt = ['login', 'save_rota_ponto', 'upload_foto_perfil'];
    if (!in_array($action, $csrfExempt) && !validateCsrfToken($csrfHeader)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF inválido. Recarregue a página.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// #region agent log
function _debugLogRota($payload) {
    $line = json_encode(array_merge(['timestamp' => round(microtime(true) * 1000), 'location' => 'api.php:rota_ativa'], $payload)) . "\n";
    @file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'debug-6ce141.log', $line, FILE_APPEND | LOCK_EX);
}
// #endregion

$pdo = getConnection();
runUsuarioIdMigrationIfNeeded($pdo);
$action = $_GET['action'] ?? '';

function isStrongPassword(string $senha): bool {
    if (strlen($senha) < 8) {
        return false;
    }
    if (!preg_match('/[A-Z]/', $senha)) {
        return false;
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $senha)) {
        return false;
    }
    return true;
}

function strongPasswordErrorMessage(): string {
    return 'A senha deve ter no mínimo 8 caracteres, pelo menos 1 letra maiúscula e 1 caractere especial.';
}

try {
    // Ações públicas (não precisam de sessão)
    $publicActions = ['login', 'csrf_token'];
    if (!in_array($action, $publicActions) && !isset($_SESSION['user_id']) && !isset($_GET['skip_auth'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Controle de sessão única: 12h máx, inatividade 30 min, um aparelho por usuário
    if (!in_array($action, $publicActions) && isset($_SESSION['user_id']) && !isset($_GET['skip_auth'])) {
        $sessionCheck = validateAndRefreshUserSession($pdo);
        if (!($sessionCheck['valid'] ?? true)) {
            $reason = $sessionCheck['reason'] ?? 'session_invalid';
            $invalidUserId = (int)($_SESSION['user_id'] ?? 0);
            $invalidUserName = $_SESSION['user_nome'] ?? null;
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            logAuthEvent($pdo, 'session_invalid', false, [
                'user_id' => $invalidUserId > 0 ? $invalidUserId : null,
                'nome_usuario' => $invalidUserName,
                'detalhes' => ['reason' => $reason]
            ]);
            http_response_code(401);
            $msg = 'Sessão encerrada. Faça login novamente.';
            if ($reason === 'other_device') {
                $msg = 'Sua conta foi acessada em outro aparelho. Faça login novamente.';
            } elseif ($reason === 'expired_inactivity') {
                $msg = 'Sessão encerrada por inatividade. Faça login novamente.';
            } elseif ($reason === 'expired_max') {
                $msg = 'Sessão expirou (máximo 12 horas). Faça login novamente.';
            }
            echo json_encode(['error' => $msg, 'session_invalid' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Visitador só pode acessar ações permitidas (evita bypass pela API)
    // Ações públicas (login, csrf_token) não entram nessa restrição
    $visitadorAllowed = [
        'logout',
        'check_session',
        'visitador_dashboard',
        'list_pedidos_visitador',
        'list_componentes_prescritor',
        'evolucao_prescritor',
        'analise_prescritor',
        'get_pedido_detalhe',
        'get_pedido_componentes',
        'all_prescritores',
        'get_recusados_prescritores',
        'get_prescritor_contatos',
        'save_prescritor_whatsapp',
        'get_prescritor_dados',
        'update_prescritor_dados',
        'add_prescritor',
        'list_profissoes',
        'list_especialidades',
        'visita_ativa',
        'iniciar_visita',
        'encerrar_visita',
        'rota_ativa',
        'start_rota',
        'pause_rota',
        'resume_rota',
        'finish_rota',
        'save_rota_ponto',
        'config',
        'upload_foto_perfil',
        'get_meu_perfil',
        'update_meu_perfil',
        'update_my_password',
        'get_foto_perfil',
        'get_visitas_agendadas_mes',
        'criar_agendamento',
        'update_agendamento',
        'excluir_agendamento',
        'get_detalhe_visita',
        'update_detalhe_visita',
        'get_visitas_prescritor',
        'get_visitas_mapa_periodo',
        'get_relatorio_rota_completo',
        'list_notificacoes',
        'enviar_mensagem_visitador',
        'enviar_mensagem_usuario',
        'list_usuarios_para_mensagem',
        'marcar_notificacao_lida',
        'marcar_mensagem_usuario_lida',
        'ocultar_mensagem_usuario',
        'apagar_notificacao'
    ];
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));
    // Aceita "visitador", "visitador comercial", etc. (evita 403 em rota/visita)
    $isVisitadorSetor = ($userSetor === 'visitador' || strpos($userSetor, 'visitador') !== false);

    // Servir foto de perfil como imagem (não JSON)
    if ($action === 'get_foto_perfil') {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {}
        $stmt = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $path = null;
        if (!empty($row['foto_perfil'])) {
            $f = basename($row['foto_perfil']);
            $path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $f;
        }
        if (!$path || !is_file($path)) {
            // Retorna 1x1 pixel transparente para evitar 404 no <img src="...?action=get_foto_perfil">
            header('Content-Type: image/gif');
            header('Cache-Control: private, max-age=3600');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'image/jpeg'));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }
    if ($isVisitadorSetor && !in_array($action, $publicActions) && !in_array($action, $visitadorAllowed)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso restrito. Usuários do setor Visitador só podem acessar o painel do visitador.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // #region agent log
    if ($action === 'rota_ativa') {
        _debugLogRota(['hypothesisId' => 'H3,H4', 'message' => 'before switch, action=rota_ativa', 'data' => []]);
    }
    // #endregion

    // Fechar automaticamente rotas após 19h (definido antes do switch para estar sempre disponível em rota_ativa, start_rota, etc.)
    // Encerra visitas em aberto antes de fechar a rota (para os visitadores afetados).
    $encerrarVisitaAbertaAutomatico = function (PDO $pdo, $visitadorNome, $motivo = 'Encerrada automaticamente (após 19h)') {
        $visitadorNome = trim((string)$visitadorNome);
        if ($visitadorNome === '') return;
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_em_andamento (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    inicio DATETIME NOT NULL,
                    fim DATETIME NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'iniciada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_visitador_status (visitador, status),
                    INDEX idx_inicio (inicio)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) { /* ignora */ }
        $stmt = $pdo->prepare("
            SELECT id, prescritor, inicio FROM visitas_em_andamento
            WHERE LOWER(TRIM(visitador)) = LOWER(TRIM(:v)) AND status = 'iniciada' AND fim IS NULL LIMIT 1
        ");
        $stmt->execute(['v' => $visitadorNome]);
        $active = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$active) return;
        $pdo->prepare("UPDATE visitas_em_andamento SET fim = NOW(), status = 'encerrada' WHERE id = :id AND LOWER(TRIM(visitador)) = LOWER(TRIM(:v))")
            ->execute(['id' => $active['id'], 'v' => $visitadorNome]);
        try {
            $pdo->exec("ALTER TABLE historico_visitas ADD COLUMN inicio_visita DATETIME NULL");
        } catch (Exception $e) { /* coluna já existe */ }
        $stmtH = $pdo->prepare("
            INSERT INTO historico_visitas (visitador, prescritor, data_visita, horario, inicio_visita, status_visita, resumo_visita, ano_referencia)
            VALUES (:visitador, :prescritor, CURDATE(), DATE_FORMAT(NOW(), '%H:%i'), :inicio_visita, 'Remarcada', :resumo, YEAR(CURDATE()))
        ");
        $stmtH->execute([
            'visitador' => $visitadorNome,
            'prescritor' => $active['prescritor'] ?? '',
            'inicio_visita' => $active['inicio'] ?? null,
            'resumo' => $motivo
        ]);
    };

    $fecharRotasApos19h = function (PDO $pdo) use ($encerrarVisitaAbertaAutomatico) {
        $stmt = $pdo->query("
            SELECT visitador_nome FROM rotas_diarias
            WHERE status IN ('em_andamento', 'pausada')
            AND (DATE(data_inicio) < CURDATE() OR (DATE(data_inicio) = CURDATE() AND CURTIME() >= '19:00:00'))
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $encerrarVisitaAbertaAutomatico($pdo, $row['visitador_nome'] ?? '', 'Encerrada automaticamente (após 19h)');
        }
        $pdo->exec("
            UPDATE rotas_diarias
            SET data_fim = NOW(), pausado_em = NULL, status = 'finalizada'
            WHERE status IN ('em_andamento', 'pausada')
            AND (DATE(data_inicio) < CURDATE() OR (DATE(data_inicio) = CURDATE() AND CURTIME() >= '19:00:00'))
        ");
    };

    switch ($action) {

        // ============================================
        // CSRF TOKEN (público)
        // ============================================
        case 'csrf_token':
            echo json_encode(['token' => getCsrfToken()]);
            break;

        // ============================================
        // CONFIG (comissão, etc.)
        // ============================================
        case 'config':
            $comissao = floatval($_ENV['COMISSAO_PERCENT'] ?? 3);
            echo json_encode(['comissao_percent' => $comissao], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // KPIs PRINCIPAIS
        // ============================================
        case 'kpis':
        case 'faturamento_mensal':
        case 'faturamento_anual':
        case 'top_formas':
        case 'top_prescritores':
        case 'top_clientes':
        case 'top_atendentes':
        case 'canais':
        case 'visitadores':
        case 'profissoes_carteira':
        case 'visitadores_carteira':
        case 'visitador_dashboard':
        case 'list_pedidos_visitador':
        case 'list_componentes_prescritor':
        case 'evolucao_prescritor':
        case 'analise_prescritor':
        case 'get_exemplo_prescritor':
        case 'get_pedido_detalhe':
        case 'get_pedido_componentes':
        case 'get_visitas_mapa_periodo':
        case 'get_relatorio_rota_completo':
            handleDashboardModuleAction($action, $pdo);
                break;
        case 'list_notificacoes':
            listNotificacoes($pdo);
            break;
        case 'enviar_mensagem_visitador':
            enviarMensagemVisitador($pdo);
            break;
        case 'enviar_mensagem_usuario':
            enviarMensagemUsuario($pdo);
            break;
        case 'list_usuarios_para_mensagem':
            listUsuariosParaMensagem($pdo);
            break;
        case 'marcar_mensagem_usuario_lida':
            marcarMensagemUsuarioLida($pdo);
            break;
        case 'ocultar_mensagem_usuario':
            ocultarMensagemUsuario($pdo);
            break;
        case 'marcar_notificacao_lida':
            marcarNotificacaoLida($pdo);
            break;
        case 'apagar_notificacao':
            apagarNotificacao($pdo);
            break;
        case 'admin_visitas':
            if ($isVisitadorSetor) {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.']); exit;
            }
            $dataDeV = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
            $dataAteV = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
            if ($dataDeV === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDeV)) $dataDeV = null;
            if ($dataAteV === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAteV)) $dataAteV = null;
            if ($dataDeV !== null && $dataAteV !== null && $dataDeV > $dataAteV) $dataAteV = $dataDeV;

            $anoV = $_GET['ano'] ?? date('Y');
            $mesV = $_GET['mes'] ?? '';
            $visitadorFiltro = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : null;
            if ($visitadorFiltro === '') $visitadorFiltro = null;

            $whereHV = "WHERE hv.data_visita IS NOT NULL";
            $paramsHV = [];
            if ($dataDeV !== null && $dataAteV !== null) {
                $whereHV .= " AND DATE(hv.data_visita) BETWEEN :data_de AND :data_ate";
                $paramsHV['data_de'] = $dataDeV;
                $paramsHV['data_ate'] = $dataAteV;
            } else {
                $whereHV .= " AND YEAR(hv.data_visita) = :ano";
                $paramsHV['ano'] = (int)$anoV;
                if ($mesV !== '') {
                    $whereHV .= " AND MONTH(hv.data_visita) = :mes";
                    $paramsHV['mes'] = (int)$mesV;
                }
            }
            if ($visitadorFiltro !== null) {
                $whereHV .= " AND TRIM(COALESCE(hv.visitador, '')) = TRIM(:visitador)";
                $paramsHV['visitador'] = $visitadorFiltro;
            }

            $stmt = $pdo->prepare("
                SELECT hv.id, hv.visitador, hv.prescritor, hv.data_visita, hv.horario, hv.inicio_visita,
                    hv.status_visita, hv.local_visita, hv.resumo_visita, hv.reagendado_para,
                    TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) as duracao_minutos
                FROM historico_visitas hv
                $whereHV
                ORDER BY hv.data_visita DESC, hv.horario DESC
                LIMIT 2000
            ");
            $stmt->execute($paramsHV);
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($lista, JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN: RELATÓRIO VISITAS (totais, por visitador, rotas, km, mapa)
        // ============================================
        case 'admin_visitas_relatorio':
            if ($isVisitadorSetor) {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.']); exit;
            }
            $dataDeR = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
            $dataAteR = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
            if ($dataDeR === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDeR)) $dataDeR = null;
            if ($dataAteR === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAteR)) $dataAteR = null;
            if ($dataDeR !== null && $dataAteR !== null && $dataDeR > $dataAteR) $dataAteR = $dataDeR;

            $anoR = (int)($_GET['ano'] ?? date('Y'));
            $mesR = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int)$_GET['mes'] : null;
            $visitadorFiltroR = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : null;
            if ($visitadorFiltroR === '') $visitadorFiltroR = null;

            $whereH = "WHERE data_visita IS NOT NULL";
            $paramsH = [];
            if ($dataDeR !== null && $dataAteR !== null) {
                $whereH .= " AND DATE(data_visita) BETWEEN :data_de AND :data_ate";
                $paramsH['data_de'] = $dataDeR;
                $paramsH['data_ate'] = $dataAteR;
            } else {
                $whereH .= " AND YEAR(data_visita) = :ano";
                $paramsH['ano'] = $anoR;
                if ($mesR !== null) {
                    $whereH .= " AND MONTH(data_visita) = :mes";
                    $paramsH['mes'] = $mesR;
                }
            }
            if ($visitadorFiltroR !== null) {
                $whereH .= " AND TRIM(COALESCE(visitador, '')) = TRIM(:visitador)";
                $paramsH['visitador'] = $visitadorFiltroR;
            }

            // Mesmas condições de período/visitador com alias hv (agregações por prescritor)
            $whereHV = "WHERE hv.data_visita IS NOT NULL";
            $paramsHV = [];
            if ($dataDeR !== null && $dataAteR !== null) {
                $whereHV .= " AND DATE(hv.data_visita) BETWEEN :hv_data_de AND :hv_data_ate";
                $paramsHV['hv_data_de'] = $dataDeR;
                $paramsHV['hv_data_ate'] = $dataAteR;
            } else {
                $whereHV .= " AND YEAR(hv.data_visita) = :hv_ano";
                $paramsHV['hv_ano'] = $anoR;
                if ($mesR !== null) {
                    $whereHV .= " AND MONTH(hv.data_visita) = :hv_mes";
                    $paramsHV['hv_mes'] = $mesR;
                }
            }
            if ($visitadorFiltroR !== null) {
                $whereHV .= " AND TRIM(COALESCE(hv.visitador, '')) = TRIM(:hv_visitador)";
                $paramsHV['hv_visitador'] = $visitadorFiltroR;
            }

            // Totais: período e semana atual
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas $whereH");
            $stmt->execute($paramsH);
            $total_visitas_periodo = (int)$stmt->fetchColumn();

            if ($visitadorFiltroR !== null) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM historico_visitas
                    WHERE data_visita IS NOT NULL AND YEARWEEK(data_visita, 1) = YEARWEEK(CURDATE(), 1)
                    AND TRIM(COALESCE(visitador, '')) = TRIM(:visitador)
                ");
                $stmt->execute(['visitador' => $visitadorFiltroR]);
            } else {
                $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM historico_visitas 
                    WHERE data_visita IS NOT NULL AND YEARWEEK(data_visita, 1) = YEARWEEK(CURDATE(), 1)
                ");
                $stmt->execute([]);
            }
            $total_visitas_semana_atual = (int)$stmt->fetchColumn();

            $prescritores_distintos = 0;
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT TRIM(hv.prescritor))
                    FROM historico_visitas hv
                    $whereHV
                    AND TRIM(COALESCE(hv.prescritor, '')) != ''
                    AND UPPER(TRIM(hv.prescritor)) NOT IN ('MY PHARM', '')
                ");
                $stmt->execute($paramsHV);
                $prescritores_distintos = (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                $prescritores_distintos = 0;
            }

            $totais = [
                'total_visitas_periodo' => $total_visitas_periodo,
                'total_visitas_semana_atual' => $total_visitas_semana_atual,
                'prescritores_distintos_visitados' => $prescritores_distintos,
            ];

            // Por visitador: total visitas + km (rotas)
            $stmt = $pdo->prepare("
                SELECT visitador as visitador_nome, COUNT(*) as total_visitas
                FROM historico_visitas
                $whereH
                GROUP BY visitador
                ORDER BY total_visitas DESC
            ");
            $stmt->execute($paramsH);
            $por_visitador_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Rotas no período (rotas_diarias: filtrar por data_inicio)
            $whereR = "WHERE 1=1";
            $paramsR = [];
            if ($dataDeR !== null && $dataAteR !== null) {
                $whereR .= " AND DATE(rd.data_inicio) BETWEEN :data_de AND :data_ate";
                $paramsR['data_de'] = $dataDeR;
                $paramsR['data_ate'] = $dataAteR;
            } else {
                $whereR .= " AND YEAR(rd.data_inicio) = :ano";
                $paramsR['ano'] = $anoR;
                if ($mesR !== null) {
                    $whereR .= " AND MONTH(rd.data_inicio) = :mes";
                    $paramsR['mes'] = $mesR;
                }
            }
            if ($visitadorFiltroR !== null) {
                $whereR .= " AND TRIM(COALESCE(rd.visitador_nome, '')) = TRIM(:visitador)";
                $paramsR['visitador'] = $visitadorFiltroR;
            }
            $stmt = $pdo->prepare("
                SELECT rd.id, rd.visitador_nome, rd.data_inicio, rd.data_fim, rd.status
                FROM rotas_diarias rd
                $whereR
                ORDER BY rd.data_inicio DESC
                LIMIT 1500
            ");
            $stmt->execute($paramsR);
            $rotas_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Haversine em PHP (km)
            $haversineKm = function ($lat1, $lon1, $lat2, $lon2) {
                $lat1 = (float)$lat1; $lon1 = (float)$lon1; $lat2 = (float)$lat2; $lon2 = (float)$lon2;
                $R = 6371;
                $dLat = deg2rad($lat2 - $lat1);
                $dLon = deg2rad($lon2 - $lon1);
                $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2);
                $c = 2*atan2(sqrt($a), sqrt(1-$a));
                return $R*$c;
            };

            $km_por_visitador = [];
            $pontos_rotas = [];
            $rotas_com_km = [];
            // Mesma regra do painel do visitador / get_relatorio_rota_completo: trechos ≥50 km são ignorados (salto de GPS).
            $kmSegmentoMaxValido = 50.0;
            $geoRotasUsed = 0;
            $geoRotasBudget = 80;

            foreach ($rotas_list as $rota) {
                $rid = (int)$rota['id'];
                $dataFimRota = $rota['data_fim'] ?? null;
                if ($dataFimRota !== null && $dataFimRota !== '') {
                    $stmtP = $pdo->prepare("SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid AND criado_em <= :data_fim ORDER BY criado_em ASC");
                    $stmtP->execute(['rid' => $rid, 'data_fim' => $dataFimRota]);
                } else {
                    $stmtP = $pdo->prepare("SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC");
                    $stmtP->execute(['rid' => $rid]);
                }
                $pontos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
                $qtd_pontos = count($pontos);
                $primeiroPv = $qtd_pontos > 0 ? $pontos[0] : null;
                $ultimoPv = $qtd_pontos > 0 ? $pontos[$qtd_pontos - 1] : null;
                $isRotaFinalizada = strcasecmp(trim((string)($rota['status'] ?? '')), 'finalizada') === 0;
                $statusNorm = strtolower(trim((string)($rota['status'] ?? '')));
                $rotaEmDeslocamento = !$isRotaFinalizada && $qtd_pontos > 0
                    && ($statusNorm === 'em_andamento' || $statusNorm === 'pausada');
                $posicaoAtual = null;
                if ($rotaEmDeslocamento && $ultimoPv !== null) {
                    $posicaoAtual = [
                        'lat' => (float)$ultimoPv['lat'],
                        'lng' => (float)$ultimoPv['lng'],
                        'atualizado_em' => $ultimoPv['criado_em'] ?? null,
                        'status_rota' => (string)($rota['status'] ?? ''),
                    ];
                }
                $addrInicio = '—';
                if ($primeiroPv !== null) {
                    $la0 = (float)$primeiroPv['lat'];
                    $ln0 = (float)$primeiroPv['lng'];
                    if ($geoRotasUsed < $geoRotasBudget) {
                        $addrInicio = reverseGeocode($pdo, $la0, $ln0);
                        $geoRotasUsed++;
                    } else {
                        $addrInicio = number_format($la0, 5, '.', '') . ', ' . number_format($ln0, 5, '.', '');
                    }
                }
                $addrFim = '';
                if ($isRotaFinalizada && $ultimoPv !== null) {
                    $la1 = (float)$ultimoPv['lat'];
                    $ln1 = (float)$ultimoPv['lng'];
                    if ($geoRotasUsed < $geoRotasBudget) {
                        $addrFim = reverseGeocode($pdo, $la1, $ln1);
                        $geoRotasUsed++;
                    } else {
                        $addrFim = number_format($la1, 5, '.', '') . ', ' . number_format($ln1, 5, '.', '');
                    }
                }
                $km_rota = 0;
                for ($i = 1; $i < $qtd_pontos; $i++) {
                    $segKm = $haversineKm(
                        $pontos[$i-1]['lat'], $pontos[$i-1]['lng'],
                        $pontos[$i]['lat'], $pontos[$i]['lng']
                    );
                    if ($segKm < $kmSegmentoMaxValido) {
                        $km_rota += $segKm;
                    }
                }
                $km_linha_reta = 0.0;
                if ($qtd_pontos >= 2) {
                    $km_linha_reta = $haversineKm(
                        $pontos[0]['lat'], $pontos[0]['lng'],
                        $pontos[$qtd_pontos - 1]['lat'], $pontos[$qtd_pontos - 1]['lng']
                    );
                }
                $vn = $rota['visitador_nome'];
                if (!isset($km_por_visitador[$vn])) $km_por_visitador[$vn] = 0;
                $km_por_visitador[$vn] += $km_rota;

                $rotas_com_km[] = [
                    'id' => $rid,
                    'visitador_nome' => $vn,
                    'data_inicio' => $rota['data_inicio'],
                    'data_fim' => $rota['data_fim'],
                    'status' => $rota['status'],
                    'km' => round($km_rota, 2),
                    'km_linha_reta' => round($km_linha_reta, 2),
                    'qtd_pontos' => $qtd_pontos,
                    'local_inicio_endereco' => $addrInicio,
                    'local_fim_endereco' => $addrFim,
                ];

                $pontos_rotas[] = [
                    'visitador_nome' => $vn,
                    'rota_id' => $rid,
                    'data_inicio' => $rota['data_inicio'],
                    'data_fim' => $rota['data_fim'],
                    'status' => $rota['status'],
                    'local_inicio_endereco' => $addrInicio,
                    'local_fim_endereco' => $addrFim,
                    'posicao_atual' => $posicaoAtual,
                    'pontos' => array_map(function ($p) { return ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng']]; }, $pontos)
                ];
            }

            $por_visitador = [];
            foreach ($por_visitador_raw as $row) {
                $nome = $row['visitador_nome'] ?? '';
                $por_visitador[] = [
                    'visitador_nome' => $nome,
                    'total_visitas' => (int)$row['total_visitas'],
                    'total_km_rotas' => round($km_por_visitador[$nome] ?? 0, 2)
                ];
            }
            // Incluir visitadores que têm rotas mas zero visitas no período
            foreach (array_keys($km_por_visitador) as $vn) {
                if (!$vn) continue;
                $existe = false;
                foreach ($por_visitador as $pv) { if ($pv['visitador_nome'] === $vn) { $existe = true; break; } }
                if (!$existe) {
                    $por_visitador[] = ['visitador_nome' => $vn, 'total_visitas' => 0, 'total_km_rotas' => round($km_por_visitador[$vn], 2)];
                }
            }

            // Pontos de atendimento (visitas com GPS) para marcar no mapa
            $pontos_atendimento = [];
            try {
                // Período/visitador em COALESCE(hv, vg): inclui linhas órfãs em visitas_geolocalizacao
                // e evita perder pinos quando historico_id não casa com historico_visitas.
                $whereGeo = "WHERE COALESCE(hv.data_visita, vg.data_visita) IS NOT NULL";
                $paramsGeo = [];
                if ($dataDeR !== null && $dataAteR !== null) {
                    $whereGeo .= " AND DATE(COALESCE(hv.data_visita, vg.data_visita)) BETWEEN :data_de AND :data_ate";
                    $paramsGeo['data_de'] = $dataDeR;
                    $paramsGeo['data_ate'] = $dataAteR;
                } else {
                    $whereGeo .= " AND YEAR(COALESCE(hv.data_visita, vg.data_visita)) = :ano";
                    $paramsGeo['ano'] = $anoR;
                    if ($mesR !== null) {
                        $whereGeo .= " AND MONTH(COALESCE(hv.data_visita, vg.data_visita)) = :mes";
                        $paramsGeo['mes'] = $mesR;
                    }
                }
                if ($visitadorFiltroR !== null) {
                    $whereGeo .= " AND TRIM(COALESCE(hv.visitador, vg.visitador, '')) = TRIM(:visitador)";
                    $paramsGeo['visitador'] = $visitadorFiltroR;
                }
            $stmt = $pdo->prepare("
                SELECT 
                        vg.lat, vg.lng,
                        COALESCE(NULLIF(TRIM(hv.prescritor), ''), TRIM(vg.prescritor)) AS prescritor,
                        COALESCE(NULLIF(TRIM(hv.visitador), ''), TRIM(vg.visitador)) AS visitador_nome,
                        COALESCE(hv.data_visita, vg.data_visita) AS data_visita,
                        DATE_FORMAT(COALESCE(hv.horario, vg.horario), '%H:%i') AS horario,
                        COALESCE(hv.local_visita, '') AS local_visita,
                        COALESCE(hv.status_visita, '') AS status_visita
                FROM visitas_geolocalizacao vg
                    LEFT JOIN historico_visitas hv ON hv.id = vg.historico_id
                    LEFT JOIN (
                        SELECT historico_id, MAX(id) AS max_id
                        FROM visitas_geolocalizacao
                        WHERE historico_id IS NOT NULL
                        GROUP BY historico_id
                    ) vg_last ON vg.historico_id = vg_last.historico_id AND vg.id = vg_last.max_id
                $whereGeo
                      AND vg.lat IS NOT NULL AND vg.lng IS NOT NULL
                    AND (vg.historico_id IS NULL OR vg_last.max_id IS NOT NULL)
                    ORDER BY COALESCE(hv.data_visita, vg.data_visita) DESC, COALESCE(hv.horario, vg.horario) DESC
                    LIMIT 2500
                ");
                $stmt->execute($paramsGeo);
                $pontos_atendimento = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Tabela visitas_geolocalizacao pode não existir ainda
            }

            // Prescritores mais visitados no período (gestão / cobertura)
            $prescritores_mais_visitados = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT TRIM(hv.prescritor) AS prescritor,
                           COUNT(*) AS total_visitas,
                           MAX(TRIM(hv.visitador)) AS visitador_referencia
                    FROM historico_visitas hv
                    $whereHV
                    AND TRIM(COALESCE(hv.prescritor, '')) != ''
                    AND UPPER(TRIM(hv.prescritor)) NOT IN ('MY PHARM', '')
                    GROUP BY TRIM(hv.prescritor)
                    ORDER BY total_visitas DESC, prescritor ASC
                    LIMIT 25
                ");
                $stmt->execute($paramsHV);
                $prescritores_mais_visitados = array_map(static function ($row) {
                    return [
                        'prescritor' => (string)($row['prescritor'] ?? ''),
                        'total_visitas' => (int)($row['total_visitas'] ?? 0),
                        'visitador_referencia' => (string)($row['visitador_referencia'] ?? ''),
                    ];
                }, $stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e) {
                $prescritores_mais_visitados = [];
            }

            // Carteira (prescritores_cadastro) sem visita no período — o NOT EXISTS deve considerar o mesmo visitador do filtro (hv2.visitador), senão qualquer visita de outro visitador "limpa" o prescritor da lista.
            $prescritores_sem_visita_periodo = [];
            $totais['prescritores_sem_visita_count'] = 0;
            $totais['prescritores_carteira_total'] = 0;
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS prescritores_cadastro (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(200) NOT NULL, visitador VARCHAR(150), usuario_id INT NULL, INDEX idx_nome (nome(120)), INDEX idx_visitador (visitador(80))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $wherePC = "WHERE TRIM(COALESCE(pc.nome, '')) != '' AND UPPER(TRIM(pc.nome)) NOT IN ('MY PHARM', '')";
                $paramsPC = [];
                if ($visitadorFiltroR !== null) {
                    if (strcasecmp(trim($visitadorFiltroR), 'My Pharm') === 0) {
                        $wherePC .= " AND (pc.visitador IS NULL OR TRIM(pc.visitador) = '' OR pc.visitador = 'My Pharm' OR UPPER(TRIM(pc.visitador)) = 'MY PHARM')";
                    } else {
                        $wherePC .= " AND TRIM(COALESCE(pc.visitador, '')) = TRIM(:pc_visitador)";
                        $paramsPC['pc_visitador'] = $visitadorFiltroR;
                    }
                }
                $stmtCntCarteira = $pdo->prepare("SELECT COUNT(*) FROM prescritores_cadastro pc $wherePC");
                $stmtCntCarteira->execute($paramsPC);
                $totais['prescritores_carteira_total'] = (int)$stmtCntCarteira->fetchColumn();
                // Placeholders próprios (hv2_*) e sem mesclar $paramsHV: com PDO native prepares
                // (ATTR_EMULATE_PREPARES false), parâmetros extras como :hv_visitador quebram o execute (HY093)
                // e o catch deixa lista/contagem zeradas — parecia “não há prescritores sem visita”.
                $notExistsSql = '';
                $paramsSemPeriodoHv2 = [];
                if ($dataDeR !== null && $dataAteR !== null) {
                    $notExistsSql = "hv2.data_visita IS NOT NULL AND DATE(hv2.data_visita) BETWEEN :hv2_data_de AND :hv2_data_ate";
                    $paramsSemPeriodoHv2['hv2_data_de'] = $dataDeR;
                    $paramsSemPeriodoHv2['hv2_data_ate'] = $dataAteR;
                } else {
                    $notExistsSql = "hv2.data_visita IS NOT NULL AND YEAR(hv2.data_visita) = :hv2_ano";
                    $paramsSemPeriodoHv2['hv2_ano'] = $anoR;
                    if ($mesR !== null) {
                        $notExistsSql .= " AND MONTH(hv2.data_visita) = :hv2_mes";
                        $paramsSemPeriodoHv2['hv2_mes'] = $mesR;
                    }
                }
                $hv2VisitadorSql = '';
                $paramsHv2Sem = [];
                if ($visitadorFiltroR !== null) {
                    if (strcasecmp(trim($visitadorFiltroR), 'My Pharm') === 0) {
                        $hv2VisitadorSql = " AND (hv2.visitador IS NULL OR TRIM(hv2.visitador) = '' OR hv2.visitador = 'My Pharm' OR UPPER(TRIM(hv2.visitador)) = 'MY PHARM')";
                    } else {
                        $hv2VisitadorSql = " AND TRIM(COALESCE(hv2.visitador, '')) = TRIM(:hv2_sem_visitador)";
                        $paramsHv2Sem['hv2_sem_visitador'] = $visitadorFiltroR;
                    }
                }
                $paramsSemVisita = array_merge($paramsPC, $paramsSemPeriodoHv2, $paramsHv2Sem);
                $sqlSemBase = "
                    FROM prescritores_cadastro pc
                    $wherePC
                    AND NOT EXISTS (
                        SELECT 1 FROM historico_visitas hv2
                        WHERE UPPER(TRIM(COALESCE(hv2.prescritor, ''))) = UPPER(TRIM(pc.nome))
                        AND $notExistsSql
                        $hv2VisitadorSql
                    )
                ";
                $stmtCntSem = $pdo->prepare("SELECT COUNT(*) $sqlSemBase");
                $stmtCntSem->execute($paramsSemVisita);
                $totais['prescritores_sem_visita_count'] = (int)$stmtCntSem->fetchColumn();

                $sqlSem = "
                    SELECT pc.nome AS prescritor,
                           COALESCE(NULLIF(TRIM(pc.visitador), ''), 'My Pharm') AS visitador_carteira
                    $sqlSemBase
                    ORDER BY visitador_carteira ASC, pc.nome ASC
                    LIMIT 250
                ";
                $stmtSem = $pdo->prepare($sqlSem);
                $stmtSem->execute($paramsSemVisita);
                $prescritores_sem_visita_periodo = array_map(static function ($row) {
                    return [
                        'prescritor' => (string)($row['prescritor'] ?? ''),
                        'visitador_carteira' => (string)($row['visitador_carteira'] ?? ''),
                    ];
                }, $stmtSem->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e) {
                $prescritores_sem_visita_periodo = [];
            }

            echo json_encode([
                'totais' => $totais,
                'por_visitador' => $por_visitador,
                'rotas' => $rotas_com_km,
                'pontos_rotas' => $pontos_rotas,
                'pontos_atendimento' => $pontos_atendimento,
                'prescritores_mais_visitados' => $prescritores_mais_visitados,
                'prescritores_sem_visita_periodo' => $prescritores_sem_visita_periodo,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ANOS DISPONÍVEIS
        // ============================================
        case 'anos':
            $stmt = $pdo->query("
                SELECT DISTINCT ano_referencia as ano 
                FROM gestao_pedidos 
                ORDER BY ano_referencia DESC
            ");
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // CORTESIA VS VENDA
        // ============================================
        case 'cortesia':
            $ano = $_GET['ano'] ?? null;
            $whereAno = $ano ? "WHERE ano_referencia = :ano" : "";

            $stmt = $pdo->prepare("
                SELECT 
                    cortesia,
                    COUNT(*) as total,
                    SUM(preco_liquido) as faturamento
                FROM gestao_pedidos 
                $whereAno
                GROUP BY cortesia
            ");
            if ($ano)
                $stmt->bindParam(':ano', $ano, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ITENS STATUS (Aprovado vs Recusado)
        // ============================================
        case 'itens_status':
            $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
            $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
            if ($dataDe === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe ?? '')) $dataDe = null;
            if ($dataAte === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte ?? '')) $dataAte = null;
            if ($dataDe !== null && $dataAte !== null && $dataDe > $dataAte) $dataAte = $dataDe;

            $ano = $_GET['ano'] ?? null;
            $mes = $_GET['mes'] ?? null;
            $dia = $_GET['dia'] ?? null;
            $where = "WHERE 1=1";
            $params = [];

            if ($dataDe !== null && $dataAte !== null) {
                $where .= " AND DATE(data) BETWEEN :data_de AND :data_ate";
                $params['data_de'] = $dataDe;
                $params['data_ate'] = $dataAte;
            } else {
                if ($ano) {
                    $where .= " AND ano_referencia = :ano";
                    $params['ano'] = (int)$ano;
                }
                if ($mes) {
                    $where .= " AND MONTH(data) = :mes";
                    $params['mes'] = (int)$mes;
                }
                if ($dia && $ano && $mes) {
                    $dataFiltro = sprintf('%04d-%02d-%02d', (int)$ano, (int)$mes, (int)$dia);
                    $where .= " AND DATE(data) = :data_filtro";
                    $params['data_filtro'] = $dataFiltro;
                }
            }

            $stmt = $pdo->prepare("
                SELECT 
                    status,
                    COUNT(*) as total,
                    SUM(valor_liquido) as valor_total,
                    SUM(valor_bruto) as valor_bruto_total
                FROM itens_orcamentos_pedidos 
                $where
                GROUP BY status
                ORDER BY total DESC
            ");
            foreach ($params as $k => $v) {
                $stmt->bindValue(":$k", $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // FATURAMENTO DIÁRIO (últimos 30 dias)
        // ============================================
        case 'faturamento_diario':
            $dias = safeLimit($_GET['dias'] ?? 30, 1, 365);
            $stmt = $pdo->prepare("
                SELECT 
                    DATE(data_aprovacao) as dia,
                    SUM(preco_liquido) as faturamento,
                    COUNT(*) as pedidos
                FROM gestao_pedidos 
                WHERE data_aprovacao >= DATE_SUB(CURDATE(), INTERVAL $dias DAY)
                  AND data_aprovacao IS NOT NULL
                GROUP BY DATE(data_aprovacao)
                ORDER BY dia
            ");
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // PROFISSÕES DOS PRESCRITORES (por período: data_de/data_ate ou ano)
        // ============================================
        case 'profissoes':
            $dataDeP = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
            $dataAteP = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
            if ($dataDeP === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDeP ?? '')) $dataDeP = null;
            if ($dataAteP === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAteP ?? '')) $dataAteP = null;
            if ($dataDeP !== null && $dataAteP !== null && $dataDeP > $dataAteP) $dataAteP = $dataDeP;

            if ($dataDeP !== null && $dataAteP !== null) {
                // Fonte principal de profissão: prescritor_dados.
                // O período continua vindo de gestao_pedidos (data_aprovacao/data_orcamento),
                // mas a classificação de profissão passa a ser do cadastro.
                $rows = [];
                $sql = "
                    SELECT
                        COALESCE(NULLIF(TRIM(pd.profissao), ''), 'Não informada') AS profissao,
                        COUNT(DISTINCT base.prescritor_norm) AS total,
                        SUM(base.total_aprovados) AS total_aprovados,
                        COALESCE(SUM(base.valor_total), 0) AS valor_total
                    FROM (
                        SELECT
                            UPPER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm'))) AS prescritor_norm,
                            SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN 1 ELSE 0 END) AS total_aprovados,
                            COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) AS valor_total
                        FROM gestao_pedidos gp
                        WHERE (gp.data_aprovacao IS NOT NULL OR gp.data_orcamento IS NOT NULL)
                          AND DATE(COALESCE(gp.data_aprovacao, gp.data_orcamento)) BETWEEN :data_de AND :data_ate
                        GROUP BY UPPER(TRIM(COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')))
                    ) base
                    LEFT JOIN prescritor_dados pd
                        ON UPPER(TRIM(pd.nome_prescritor)) = base.prescritor_norm
                    GROUP BY COALESCE(NULLIF(TRIM(pd.profissao), ''), 'Não informada')
                    ORDER BY valor_total DESC
                ";
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindValue(':data_de', $dataDeP);
                    $stmt->bindValue(':data_ate', $dataAteP);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $rows = [];
                }
                // Com data_de/data_ate explícitos, não há fallback anual: vazio = sem pedidos no período.
            } else {
                $ano = $_GET['ano'] ?? date('Y');
                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(NULLIF(TRIM(profissao), ''), 'Não informada') AS profissao,
                        COUNT(*) AS total,
                        SUM(aprovados) AS total_aprovados,
                        SUM(valor_aprovado) AS valor_total
                    FROM prescritor_resumido
                    WHERE ano_referencia = :ano
                    GROUP BY COALESCE(NULLIF(TRIM(profissao), ''), 'Não informada')
                    HAVING COALESCE(NULLIF(TRIM(profissao), ''), 'Não informada') != ''
                    ORDER BY valor_total DESC
                ");
                $stmt->bindValue(':ano', (int)$ano, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($rows ?? [], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // COMPARATIVO ANUAL
        // ============================================
        case 'comparativo_anual':
            $dataDeComp = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
            $dataAteComp = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
            if ($dataDeComp === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDeComp)) $dataDeComp = null;
            if ($dataAteComp === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAteComp)) $dataAteComp = null;
            if ($dataDeComp !== null && $dataAteComp !== null && $dataDeComp > $dataAteComp) $dataAteComp = $dataDeComp;

            $whereComp = '';
            $paramsComp = [];
            if ($dataDeComp !== null && $dataAteComp !== null) {
                $whereComp = ' WHERE (data_aprovacao IS NOT NULL OR data_orcamento IS NOT NULL) AND DATE(COALESCE(data_aprovacao, data_orcamento)) BETWEEN :data_de AND :data_ate';
                $paramsComp = ['data_de' => $dataDeComp, 'data_ate' => $dataAteComp];
            }
            $stmt = $pdo->prepare("
                SELECT 
                    ano_referencia as ano,
                    SUM(preco_liquido) as faturamento,
                    SUM(preco_custo) as custo,
                    SUM(preco_liquido) - SUM(preco_custo) as lucro_bruto,
                    COUNT(*) as total_pedidos,
                    COUNT(DISTINCT cliente) as total_clientes,
                    COUNT(DISTINCT COALESCE(NULLIF(prescritor, ''), 'My Pharm')) as total_prescritores,
                    AVG(preco_liquido) as ticket_medio,
                    AVG(preco_bruto) as ticket_bruto,
                    CASE WHEN SUM(preco_liquido) > 0 
                        THEN ROUND(((SUM(preco_liquido) - SUM(preco_custo)) / SUM(preco_liquido)) * 100, 2)
                        ELSE 0 END as margem_pct
                FROM gestao_pedidos
                $whereComp
                GROUP BY ano_referencia
                ORDER BY ano_referencia
            ");
            $stmt->execute($paramsComp);
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // TODOS OS PRESCRITORES
        // ============================================
        case 'all_prescritores':
            try {
            $ano = !empty($_GET['ano']) ? $_GET['ano'] : null;
            $mes = !empty($_GET['mes']) ? $_GET['mes'] : null;
            $dia = !empty($_GET['dia']) ? $_GET['dia'] : null;
            $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
            $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
            if ($dataDe === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)) $dataDe = null;
            if ($dataAte === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) $dataAte = null;
            $useRange = $dataDe !== null && $dataAte !== null && $dataDe <= $dataAte;
            $visitadorFilter = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : null;
            if ($visitadorFilter === '') $visitadorFilter = null;
            $searchTerm = isset($_GET['q']) ? trim((string)$_GET['q']) : null;
            if ($searchTerm === '') $searchTerm = null;
            $anoUsar = $ano ?: ($dataDe ? substr($dataDe, 0, 4) : date('Y'));
            $useLimit = isset($_GET['limit']) && (int)$_GET['limit'] > 0;
            $limit = $useLimit ? min((int)$_GET['limit'], 2000) : 0;
            $offset = ($useLimit && isset($_GET['offset'])) ? max(0, (int)$_GET['offset']) : 0;

            $filtroMesGp = !$useRange && $mes ? "AND MONTH(gp.data_aprovacao) = :mes" : "";
            $filtroDiaGp = !$useRange && ($dia && $mes && $anoUsar) ? "AND DATE(gp.data_aprovacao) = :data_filtro" : "";
            $filtroDataGp = $useRange ? "AND DATE(gp.data_aprovacao) BETWEEN :data_de AND :data_ate" : "";
            $dataFiltro = !$useRange && ($dia && $mes && $anoUsar) ? sprintf('%04d-%02d-%02d', (int)$anoUsar, (int)$mes, (int)$dia) : null;
            $filtroSearch = $searchTerm !== null ? "AND (pc.nome LIKE :search)" : "";

            // Garantir que tabelas existam (evita 500 se importação web foi usada sem CLI)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS prescritores_cadastro (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(200) NOT NULL, visitador VARCHAR(150), usuario_id INT NULL, INDEX idx_nome (nome(120)), INDEX idx_visitador (visitador(80))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_dados (nome_prescritor VARCHAR(255) PRIMARY KEY, profissao VARCHAR(255) NULL, usuario_id INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_contatos (nome_prescritor VARCHAR(255) PRIMARY KEY, whatsapp VARCHAR(30) NULL, atualizado_em DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("CREATE TABLE IF NOT EXISTS historico_visitas (id INT AUTO_INCREMENT PRIMARY KEY, visitador VARCHAR(255), prescritor VARCHAR(255), data_visita DATE NULL, ano_referencia INT NULL, INDEX idx_prescritor (prescritor(100)), INDEX idx_data (data_visita)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $pdo->exec("CREATE TABLE IF NOT EXISTS gestao_pedidos (id INT AUTO_INCREMENT PRIMARY KEY, data_aprovacao DATETIME NULL, data_orcamento DATETIME NULL, canal_atendimento VARCHAR(100), numero_pedido INT DEFAULT 0, serie_pedido INT DEFAULT 0, forma_farmaceutica VARCHAR(100), produto VARCHAR(255), quantidade INT DEFAULT 1, preco_bruto DECIMAL(14,2) DEFAULT 0, valor_subsidio DECIMAL(14,2) DEFAULT 0, preco_custo DECIMAL(14,2) DEFAULT 0, desconto DECIMAL(14,2) DEFAULT 0, acrescimo DECIMAL(14,2) DEFAULT 0, preco_liquido DECIMAL(14,2) DEFAULT 0, cliente VARCHAR(255), paciente VARCHAR(255), prescritor VARCHAR(255), atendente VARCHAR(100), venda_pdv VARCHAR(20), cortesia VARCHAR(20), aprovador VARCHAR(100), orcamentista VARCHAR(100), status_financeiro VARCHAR(100), origem_acrescimo_desconto VARCHAR(100), convenio VARCHAR(255), ano_referencia INT NOT NULL, INDEX idx_ano (ano_referencia), INDEX idx_numero_serie (numero_pedido, serie_pedido)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Throwable $e) { /* ignora se já existirem com estrutura diferente */ }

            $dataFallback = null; // se preenchido, pula query principal e usa esta lista
            // Com filtro de visitador: listar TODA a carteira (prescritores_cadastro), com indicadores do ano/período
            if ($visitadorFilter) {
                $visWhere = ($visitadorFilter === 'My Pharm')
                    ? "(pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')"
                    : "pc.visitador = :vis";

                if ($useLimit) {
                    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM prescritores_cadastro pc WHERE $visWhere $filtroSearch");
                    $countParams = [];
                    if ($visitadorFilter !== 'My Pharm') $countParams['vis'] = $visitadorFilter;
                    if ($searchTerm !== null) $countParams['search'] = '%' . $searchTerm . '%';
                    $countStmt->execute($countParams);
                    $totalRows = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                }

                if ($useRange) {
                    // Período: uma query para lista (aprovados por data); recusados do período em segunda query e merge em PHP
                    $sql = "
                    SELECT 
                        pc.nome as prescritor,
                        pc.visitador as visitador,
                        pc.usuario_id as usuario_id,
                        MAX(pd.profissao) as profissao,
                        COALESCE(SUM(CASE WHEN gp.data_aprovacao IS NOT NULL
                            AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND gp.status_financeiro NOT LIKE '%carrinho%'))
                            THEN gp.preco_liquido ELSE 0 END), 0) as valor_aprovado,
                        COALESCE(SUM(CASE WHEN (gp.data_aprovacao IS NOT NULL OR gp.data_orcamento IS NOT NULL)
                            AND (gp.status_financeiro IN ('Recusado', 'Cancelado', 'Orçamento') OR gp.status_financeiro LIKE '%carrinho%')
                            THEN gp.preco_liquido ELSE 0 END), 0) as valor_recusado,
                        SUM(CASE WHEN (gp.data_aprovacao IS NOT NULL OR gp.data_orcamento IS NOT NULL)
                            AND (gp.status_financeiro IN ('Recusado', 'Cancelado', 'Orçamento') OR gp.status_financeiro LIKE '%carrinho%')
                            THEN 1 ELSE 0 END) as qtd_recusados,
                        SUM(CASE WHEN gp.data_aprovacao IS NOT NULL
                            AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND gp.status_financeiro NOT LIKE '%carrinho%'))
                            THEN 1 ELSE 0 END) as total_pedidos,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(CURDATE(), MAX(gp.data_aprovacao)) as dias_sem_compra,
                        MAX(hv.ultima_visita) as ultima_visita,
                        NULLIF(TRIM(MAX(pd.whatsapp)), '') as whatsapp
                    FROM prescritores_cadastro pc
                    LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pc.nome
                    LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome 
                        AND gp.ano_referencia = :ano_gp
                        $filtroDataGp
                    LEFT JOIN (
                        SELECT prescritor, MAX(data_visita) as ultima_visita 
                        FROM historico_visitas GROUP BY prescritor
                    ) hv ON pc.nome = hv.prescritor
                    WHERE $visWhere $filtroSearch
                    GROUP BY pc.nome, pc.visitador, pc.usuario_id
                    ORDER BY valor_aprovado DESC
                    ";
                } else {
                    $sql = "
                    SELECT 
                        pc.nome as prescritor,
                        pc.visitador as visitador,
                        pc.usuario_id as usuario_id,
                        MAX(pd.profissao) as profissao,
                        COALESCE(SUM(CASE WHEN gp.data_aprovacao IS NOT NULL
                            AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND gp.status_financeiro NOT LIKE '%carrinho%'))
                            THEN gp.preco_liquido ELSE 0 END), 0) as valor_aprovado,
                        COALESCE(SUM(CASE WHEN (gp.data_aprovacao IS NOT NULL OR gp.data_orcamento IS NOT NULL)
                            AND (gp.status_financeiro IN ('Recusado', 'Cancelado', 'Orçamento') OR gp.status_financeiro LIKE '%carrinho%')
                            THEN gp.preco_liquido ELSE 0 END), 0) as valor_recusado,
                        SUM(CASE WHEN (gp.data_aprovacao IS NOT NULL OR gp.data_orcamento IS NOT NULL)
                            AND (gp.status_financeiro IN ('Recusado', 'Cancelado', 'Orçamento') OR gp.status_financeiro LIKE '%carrinho%')
                            THEN 1 ELSE 0 END) as qtd_recusados,
                        SUM(CASE WHEN gp.data_aprovacao IS NOT NULL
                            AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND gp.status_financeiro NOT LIKE '%carrinho%'))
                            THEN 1 ELSE 0 END) as total_pedidos,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(CURDATE(), MAX(gp.data_aprovacao)) as dias_sem_compra,
                        MAX(hv.ultima_visita) as ultima_visita,
                        NULLIF(TRIM(MAX(pd.whatsapp)), '') as whatsapp
                    FROM prescritores_cadastro pc
                    LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pc.nome
                    LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome 
                        AND gp.ano_referencia = :ano_gp
                        $filtroMesGp
                        $filtroDiaGp
                    LEFT JOIN (
                        SELECT prescritor, MAX(data_visita) as ultima_visita 
                        FROM historico_visitas GROUP BY prescritor
                    ) hv ON pc.nome = hv.prescritor
                    WHERE $visWhere $filtroSearch
                    GROUP BY pc.nome, pc.visitador, pc.usuario_id
                    ORDER BY valor_aprovado DESC
                    ";
                }
                if ($useLimit) $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                $paramsVis = ['ano_gp' => $anoUsar];
                if ($visitadorFilter !== 'My Pharm')
                    $paramsVis['vis'] = $visitadorFilter;
                if ($searchTerm !== null)
                    $paramsVis['search'] = '%' . $searchTerm . '%';
                if ($useRange) {
                    $paramsVis['data_de'] = $dataDe;
                    $paramsVis['data_ate'] = $dataAte;
                } else {
                    if ($mes) $paramsVis['mes'] = (int)$mes;
                    if ($dataFiltro) $paramsVis['data_filtro'] = $dataFiltro;
                }
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($paramsVis);
                } catch (Throwable $e) {
                    // Fallback: query mínima só com prescritores_cadastro + prescritor_dados (sem gestao_pedidos/historico)
                    try {
                        $sqlFallback = "SELECT pc.nome as prescritor, pc.visitador as visitador, pc.usuario_id as usuario_id, MAX(pd.profissao) as profissao,
                            0 as valor_aprovado, 0 as valor_recusado, 0 as qtd_recusados, 0 as total_pedidos,
                            NULL as ultima_compra, NULL as dias_sem_compra, NULL as ultima_visita,
                            NULLIF(TRIM(MAX(pd.whatsapp)), '') as whatsapp
                            FROM prescritores_cadastro pc
                            LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pc.nome
                            WHERE $visWhere " . ($searchTerm !== null ? "AND (pc.nome LIKE :search)" : "") . "
                            GROUP BY pc.nome, pc.visitador, pc.usuario_id ORDER BY pc.nome";
                        $stmtFallback = $pdo->prepare($sqlFallback);
                        $paramsFallback = [];
                        if ($visitadorFilter !== 'My Pharm') $paramsFallback['vis'] = $visitadorFilter;
                        if ($searchTerm !== null) $paramsFallback['search'] = '%' . $searchTerm . '%';
                        $stmtFallback->execute($paramsFallback);
                        $dataFallback = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e2) {
                        http_response_code(500);
                        echo json_encode(['error' => 'Erro ao listar prescritores: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
                if ($dataFallback === null && isset($stmt)) {
                    // Recusados já vêm da query principal (gestao_pedidos), mesma metodologia dos aprovados
                } // if ($dataFallback === null && isset($stmt))
            }
            else {
                // Sem filtro de visitador: listar TODOS os prescritores (prescritores_cadastro)
                // com indicadores do ano/mês, para que apareçam mesmo sem pedidos no período (ex.: Renata Cortes).
                $filtroMesGp = !$useRange && $mes ? "AND MONTH(gp.data_aprovacao) = :mes" : "";
                $filtroDiaGp = !$useRange && ($dia && $mes && $anoUsar) ? "AND DATE(gp.data_aprovacao) = :data_filtro" : "";
                $filtroDataGp = $useRange ? "AND DATE(gp.data_aprovacao) BETWEEN :data_de AND :data_ate" : "";
                $dataFiltro = !$useRange && ($dia && $mes && $anoUsar) ? sprintf('%04d-%02d-%02d', (int)$anoUsar, (int)$mes, (int)$dia) : null;

                if ($useLimit) {
                    $countSql = "SELECT COUNT(*) as total FROM prescritores_cadastro pc WHERE 1=1 $filtroSearch";
                    $countStmt = $pdo->prepare($countSql);
                    $countParams = [];
                    if ($searchTerm !== null) $countParams['search'] = '%' . $searchTerm . '%';
                    $countStmt->execute($countParams);
                    $totalRows = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                }

                $sql = "
                    SELECT 
                        pc.nome as prescritor,
                        pc.visitador as visitador,
                        pc.usuario_id as usuario_id,
                        MAX(pd.profissao) as profissao,
                        COALESCE(SUM(CASE WHEN gp.data_aprovacao IS NOT NULL
                            AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND gp.status_financeiro NOT LIKE '%carrinho%'))
                            THEN gp.preco_liquido ELSE 0 END), 0) as valor_aprovado,
                        COALESCE(SUM(CASE WHEN (gp.data_aprovacao IS NOT NULL OR gp.data_orcamento IS NOT NULL)
                            AND (gp.status_financeiro IN ('Recusado', 'Cancelado', 'Orçamento') OR gp.status_financeiro LIKE '%carrinho%')
                            THEN gp.preco_liquido ELSE 0 END), 0) as valor_recusado,
                        SUM(CASE WHEN (gp.data_aprovacao IS NOT NULL OR gp.data_orcamento IS NOT NULL)
                            AND (gp.status_financeiro IN ('Recusado', 'Cancelado', 'Orçamento') OR gp.status_financeiro LIKE '%carrinho%')
                            THEN 1 ELSE 0 END) as qtd_recusados,
                        SUM(CASE WHEN gp.data_aprovacao IS NOT NULL
                            AND (gp.status_financeiro IS NULL OR (gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                            AND gp.status_financeiro NOT LIKE '%carrinho%'))
                            THEN 1 ELSE 0 END) as total_pedidos,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(CURDATE(), MAX(gp.data_aprovacao)) as dias_sem_compra,
                        MAX(hv.ultima_visita) as ultima_visita,
                        NULLIF(TRIM(MAX(pd.whatsapp)), '') as whatsapp
                    FROM prescritores_cadastro pc
                    LEFT JOIN prescritor_dados pd ON pd.nome_prescritor = pc.nome
                    LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome 
                        AND gp.ano_referencia = :ano_gp
                        $filtroDataGp
                        $filtroMesGp
                        $filtroDiaGp
                    LEFT JOIN (
                        SELECT prescritor, MAX(data_visita) as ultima_visita 
                        FROM historico_visitas GROUP BY prescritor
                    ) hv ON pc.nome = hv.prescritor
                    WHERE 1=1 $filtroSearch
                    GROUP BY pc.nome, pc.visitador, pc.usuario_id
                    ORDER BY valor_aprovado DESC
                ";
                if ($useLimit) $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                $stmt = $pdo->prepare($sql);
                $paramsTodos = ['ano_gp' => $anoUsar];
                if ($searchTerm !== null) $paramsTodos['search'] = '%' . $searchTerm . '%';
                if ($useRange) {
                    $paramsTodos['data_de'] = $dataDe;
                    $paramsTodos['data_ate'] = $dataAte;
                } else {
                    if ($mes) $paramsTodos['mes'] = (int)$mes;
                    if ($dataFiltro) $paramsTodos['data_filtro'] = $dataFiltro;
                }
                $stmt->execute($paramsTodos);
            }
            $data = ($dataFallback !== null) ? $dataFallback : $stmt->fetchAll();

            // Recusados: gestao_pedidos não traz Recusado/No carrinho — preencher a partir de itens_orcamentos_pedidos (e fallback prescritor_resumido)
            $recMap = [];
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS itens_orcamentos_pedidos (
                    id INT AUTO_INCREMENT PRIMARY KEY, filial VARCHAR(100), numero INT NOT NULL DEFAULT 0, serie INT NOT NULL DEFAULT 0,
                    data DATE NULL, canal VARCHAR(50), forma_farmaceutica VARCHAR(100), descricao VARCHAR(255),
                    quantidade INT NOT NULL DEFAULT 1, unidade VARCHAR(20),
                    valor_bruto DECIMAL(14,2) DEFAULT 0, valor_liquido DECIMAL(14,2) DEFAULT 0, preco_custo DECIMAL(14,2) DEFAULT 0, fator DECIMAL(10,2) DEFAULT 0,
                    status VARCHAR(50), usuario_inclusao VARCHAR(100), usuario_aprovador VARCHAR(100),
                    paciente VARCHAR(255), prescritor VARCHAR(255), status_financeiro VARCHAR(50), ano_referencia INT NOT NULL,
                    INDEX idx_ano (ano_referencia), INDEX idx_prescritor (prescritor(100)), INDEX idx_status (status),
                    INDEX idx_numero_serie (numero, serie)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Throwable $e) { /* ignora */ }
            $visWhereRec = $visitadorFilter ? (($visitadorFilter === 'My Pharm') ? "(pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')" : "pc.visitador = :vis_rec") : '1=1';
            $paramsRec = ['ano_ref' => (int)$anoUsar];
            if ($visitadorFilter && $visitadorFilter !== 'My Pharm') $paramsRec['vis_rec'] = $visitadorFilter;
            if ($useRange) {
                $filtroPeriodoRec = "AND DATE(i.data) BETWEEN :data_de_rec AND :data_ate_rec";
                $paramsRec['data_de_rec'] = $dataDe;
                $paramsRec['data_ate_rec'] = $dataAte;
            } elseif ($mes) {
                $filtroPeriodoRec = "AND YEAR(i.data) = :ano_ref AND MONTH(i.data) = :mes_rec";
                $paramsRec['mes_rec'] = (int)$mes;
            } else {
                $filtroPeriodoRec = "AND YEAR(i.data) = :ano_ref";
            }
            $sqlRec = "SELECT pc.nome as prescritor, COALESCE(SUM(i.valor_liquido), 0) as valor_recusado, COUNT(*) as qtd_recusados
                FROM prescritores_cadastro pc
                INNER JOIN itens_orcamentos_pedidos i
                    ON UPPER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'))) = UPPER(TRIM(pc.nome))
                    AND i.ano_referencia = :ano_ref AND (i.status = 'Recusado' OR i.status = 'No carrinho') $filtroPeriodoRec
                WHERE $visWhereRec
                GROUP BY pc.nome";
            try {
                $stmtRec = $pdo->prepare($sqlRec);
                $stmtRec->execute($paramsRec);
                foreach ($stmtRec->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $recMap[$r['prescritor']] = [(float)($r['valor_recusado'] ?? 0), (int)($r['qtd_recusados'] ?? 0)];
                }
            } catch (Throwable $e) { /* ignora */ }
            if (empty($recMap) && !$mes && !$useRange) {
                try {
                    $visWherePr = $visitadorFilter ? (($visitadorFilter === 'My Pharm') ? "(pr.visitador IS NULL OR pr.visitador = '' OR pr.visitador = 'My Pharm' OR UPPER(pr.visitador) = 'MY PHARM')" : "pr.visitador = :vis_pr") : '1=1';
                    $paramsPr = ['ano_ref' => (int)$anoUsar];
                    if ($visitadorFilter && $visitadorFilter !== 'My Pharm') $paramsPr['vis_pr'] = $visitadorFilter;
                    $sqlPr = "SELECT pc.nome as prescritor,
                        (COALESCE(pr.valor_recusado, 0) + COALESCE(pr.valor_no_carrinho, 0)) as valor_recusado,
                        (COALESCE(pr.recusados, 0) + COALESCE(pr.no_carrinho, 0)) as qtd_recusados
                        FROM prescritor_resumido pr
                        INNER JOIN prescritores_cadastro pc ON UPPER(TRIM(pr.nome)) = UPPER(TRIM(pc.nome))
                        WHERE pr.ano_referencia = :ano_ref AND $visWherePr
                        AND (COALESCE(pr.valor_recusado, 0) + COALESCE(pr.valor_no_carrinho, 0) > 0 OR COALESCE(pr.recusados, 0) + COALESCE(pr.no_carrinho, 0) > 0)";
                    $stmtPr = $pdo->prepare($sqlPr);
                    $stmtPr->execute($paramsPr);
                    foreach ($stmtPr->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $recMap[$r['prescritor']] = [(float)($r['valor_recusado'] ?? 0), (int)($r['qtd_recusados'] ?? 0)];
                    }
                } catch (Throwable $e) { /* ignora */ }
            }
            // Normalizar chave do mapa para comparação (trim)
            $recMapNormalized = [];
            foreach ($recMap as $nome => $pair) {
                $k = trim((string)$nome);
                if ($k !== '') $recMapNormalized[$k] = $pair;
            }

            $results = [];
            foreach ($data as $row) {
                $row['dias_sem_visita'] = null;
                if (!empty($row['ultima_visita'])) {
                    try {
                        $d1 = new DateTime($row['ultima_visita']);
                        $d2 = new DateTime();
                        $row['dias_sem_visita'] = $d1->diff($d2)->days;
                    }
                    catch (Exception $e) {
                        $row['dias_sem_visita'] = null;
                    }
                }
                $nomePrescritor = isset($row['prescritor']) ? trim((string)$row['prescritor']) : '';
                if ($nomePrescritor !== '' && (isset($recMap[$row['prescritor']]) || isset($recMapNormalized[$nomePrescritor]))) {
                    $pair = $recMap[$row['prescritor']] ?? $recMapNormalized[$nomePrescritor];
                    $row['valor_recusado'] = $pair[0];
                    $row['qtd_recusados'] = $pair[1];
                }
                $results[] = $row;
            }

            // Importante: a lista de prescritores deve permanecer completa mesmo com filtro por período.
            // O período afeta apenas os valores/contadores (aprovado, recusado, pedidos etc.).

            if (ob_get_length())
                ob_clean();
            if ($useLimit) {
                $out = ['total' => isset($totalRows) ? $totalRows : count($results), 'data' => $results];
                echo json_encode($out, JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode($results, JSON_UNESCAPED_UNICODE);
            }
            } catch (Throwable $e) {
                if (ob_get_length()) ob_end_clean();
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode(['error' => 'all_prescritores: ' . $e->getMessage(), 'line' => $e->getLine()], JSON_UNESCAPED_UNICODE);
                exit;
            }
            break;

        // Recusados por prescritor: fonte itens_orcamentos_pedidos (gestao_pedidos não traz Recusado/No carrinho); fallback prescritor_resumido (ano)
        case 'get_recusados_prescritores':
            try {
                $anoRec = !empty($_GET['ano']) ? $_GET['ano'] : date('Y');
                $mesRec = !empty($_GET['mes']) ? $_GET['mes'] : null;
                $dataDeRec = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
                $dataAteRec = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
                if ($dataDeRec === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDeRec)) $dataDeRec = null;
                if ($dataAteRec === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAteRec)) $dataAteRec = null;
                $useRangeRec = $dataDeRec !== null && $dataAteRec !== null && $dataDeRec <= $dataAteRec;
                $visitadorRec = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : null;
                if ($visitadorRec === '') $visitadorRec = null;

                $visWhereRec = '1=1';
                $paramsRec = ['ano_ref' => (int)$anoRec];
                if ($visitadorRec !== null) {
                    $visWhereRec = ($visitadorRec === 'My Pharm')
                        ? "(pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')"
                        : "pc.visitador = :vis";
                    if ($visitadorRec !== 'My Pharm') $paramsRec['vis'] = $visitadorRec;
                }

                // Garantir que a tabela existe (evita erro se importação de itens não foi feita)
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS itens_orcamentos_pedidos (
                        id INT AUTO_INCREMENT PRIMARY KEY, filial VARCHAR(100), numero INT NOT NULL DEFAULT 0, serie INT NOT NULL DEFAULT 0,
                        data DATE NULL, canal VARCHAR(50), forma_farmaceutica VARCHAR(100), descricao VARCHAR(255),
                        quantidade INT NOT NULL DEFAULT 1, unidade VARCHAR(20),
                        valor_bruto DECIMAL(14,2) DEFAULT 0, valor_liquido DECIMAL(14,2) DEFAULT 0, preco_custo DECIMAL(14,2) DEFAULT 0, fator DECIMAL(10,2) DEFAULT 0,
                        status VARCHAR(50), usuario_inclusao VARCHAR(100), usuario_aprovador VARCHAR(100),
                        paciente VARCHAR(255), prescritor VARCHAR(255), status_financeiro VARCHAR(50), ano_referencia INT NOT NULL,
                        INDEX idx_ano (ano_referencia), INDEX idx_prescritor (prescritor(100)), INDEX idx_status (status),
                        INDEX idx_numero_serie (numero, serie)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                } catch (Throwable $e) { /* ignora */ }

                // Período pela coluna data de itens_orcamentos_pedidos
                if ($useRangeRec) {
                    $filtroPeriodoRec = "AND DATE(i.data) BETWEEN :data_de AND :data_ate";
                    $paramsRec['data_de'] = $dataDeRec;
                    $paramsRec['data_ate'] = $dataAteRec;
                } elseif ($mesRec) {
                    $filtroPeriodoRec = "AND YEAR(i.data) = :ano_ref AND MONTH(i.data) = :mes";
                    $paramsRec['mes'] = (int)$mesRec;
                } else {
                    $filtroPeriodoRec = "AND YEAR(i.data) = :ano_ref";
                }

                // Join case-insensitive para bater nome do prescritor (itens vs cadastro)
                $sqlRec = "
                    SELECT
                        pc.nome as prescritor,
                        COALESCE(SUM(i.valor_liquido), 0) as valor_recusado,
                        COUNT(*) as qtd_recusados
                    FROM prescritores_cadastro pc
                    INNER JOIN itens_orcamentos_pedidos i
                        ON UPPER(TRIM(COALESCE(NULLIF(TRIM(i.prescritor), ''), 'My Pharm'))) = UPPER(TRIM(pc.nome))
                        AND i.ano_referencia = :ano_ref
                        AND (i.status = 'Recusado' OR i.status = 'No carrinho')
                        $filtroPeriodoRec
                    WHERE $visWhereRec
                    GROUP BY pc.nome
                ";
                $stmtRec = $pdo->prepare($sqlRec);
                $stmtRec->execute($paramsRec);
                $recusados = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

                // Fallback: quando não há filtro de mês/faixa e itens retornou vazio, usar prescritor_resumido (ano)
                $visWherePr = '1=1';
                $paramsPr = ['ano_ref' => (int)$anoRec];
                if ($visitadorRec !== null) {
                    $visWherePr = ($visitadorRec === 'My Pharm')
                        ? "(pr.visitador IS NULL OR pr.visitador = '' OR pr.visitador = 'My Pharm' OR UPPER(pr.visitador) = 'MY PHARM')"
                        : "pr.visitador = :vis";
                    if ($visitadorRec !== 'My Pharm') $paramsPr['vis'] = $visitadorRec;
                }
                if (count($recusados) === 0 && !$mesRec && !$useRangeRec) {
                    try {
                        $sqlResumido = "
                            SELECT pc.nome as prescritor,
                                (COALESCE(pr.valor_recusado, 0) + COALESCE(pr.valor_no_carrinho, 0)) as valor_recusado,
                                (COALESCE(pr.recusados, 0) + COALESCE(pr.no_carrinho, 0)) as qtd_recusados
                            FROM prescritor_resumido pr
                            INNER JOIN prescritores_cadastro pc ON UPPER(TRIM(pr.nome)) = UPPER(TRIM(pc.nome))
                            WHERE pr.ano_referencia = :ano_ref AND $visWherePr
                                AND (COALESCE(pr.valor_recusado, 0) + COALESCE(pr.valor_no_carrinho, 0) > 0
                                   OR COALESCE(pr.recusados, 0) + COALESCE(pr.no_carrinho, 0) > 0)
                        ";
                        $stmtPr = $pdo->prepare($sqlResumido);
                        $stmtPr->execute($paramsPr);
                        $recusados = $stmtPr->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) { /* mantém recusados vazio */ }
                }

                if (ob_get_length()) ob_clean();
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($recusados, JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                if (ob_get_length()) ob_end_clean();
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
                echo json_encode([], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'list_visitadores':
            $stmt = $pdo->query("SELECT id, nome as visitador FROM usuarios WHERE LOWER(TRIM(COALESCE(setor,''))) = 'visitador' ORDER BY nome ASC");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $myPharm = null;
            $data = array_values(array_filter($data, function ($r) use (&$myPharm) {
                if (strcasecmp(trim($r['visitador'] ?? ''), 'My Pharm') === 0) {
                    $myPharm = $r;
                    return false;
                }
                return true;
            }));
            array_unshift($data, ['id' => $myPharm['id'] ?? null, 'visitador' => 'My Pharm']);
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // LOGIN (Bcrypt com migração automática de MD5)
        // ============================================
        case 'login':
            $input = json_decode(file_get_contents('php://input'), true);
            $usuario = trim($input['usuario'] ?? '');
            $senha = $input['senha'] ?? '';

            if (empty($usuario) || empty($senha)) {
                echo json_encode(['success' => false, 'error' => 'Preencha usuário e senha']);
                break;
            }

            $clientIp = getClientIp();
            $rateCheck = checkLoginRateLimit($pdo, $clientIp);
            if ($rateCheck['blocked']) {
                $minutos = ceil($rateCheck['remaining'] / 60);
                logAuthEvent($pdo, 'login_blocked', false, [
                    'usuario' => $usuario,
                    'detalhes' => [
                        'remaining_seconds' => $rateCheck['remaining'],
                        'attempts' => (int)($rateCheck['attempts'] ?? 0)
                    ]
                ]);
                echo json_encode([
                    'success' => false,
                    'error' => "Muitas tentativas. Tente novamente em {$minutos} minuto(s).",
                    'locked' => true,
                    'remaining_seconds' => $rateCheck['remaining']
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = :usuario AND ativo = 1");
            $stmt->execute(['usuario' => $usuario]);
            $user = $stmt->fetch();

            $authenticated = false;

            if ($user) {
                $storedHash = $user['senha'];

                if (password_verify($senha, $storedHash)) {
                    $authenticated = true;

                    if (password_needs_rehash($storedHash, PASSWORD_BCRYPT)) {
                        $newHash = password_hash($senha, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id")
                            ->execute(['senha' => $newHash, 'id' => $user['id']]);
                    }
                }
            }

            if ($authenticated && $user) {
                clearLoginAttempts($pdo, $clientIp);
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nome'] = $user['nome'];
                $_SESSION['user_tipo'] = $user['tipo'];
                $_SESSION['user_setor'] = $user['setor'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                registerUserSession($pdo, (int) $user['id']);

                $pdo->prepare("UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id")
                    ->execute(['id' => $user['id']]);

                $requirePasswordChange = !isStrongPassword($senha);
                logAuthEvent($pdo, 'login_success', true, [
                    'user_id' => (int)$user['id'],
                    'usuario' => $user['usuario'] ?? $usuario,
                    'nome_usuario' => $user['nome'] ?? null,
                    'detalhes' => ['require_password_change' => $requirePasswordChange]
                ]);

                $fotoPerfil = null;
                if (!empty($user['foto_perfil'])) {
                    $fotoPerfil = 'uploads/avatars/' . basename($user['foto_perfil']);
                }
                echo json_encode([
                    'success' => true,
                    'nome' => $user['nome'],
                    'tipo' => $user['tipo'],
                    'setor' => $user['setor'],
                    'foto_perfil' => $fotoPerfil,
                    'require_password_change' => $requirePasswordChange,
                    'csrf_token' => getCsrfToken()
                ]);
            } else {
                recordLoginAttempt($pdo, $clientIp);
                logAuthEvent($pdo, 'login_failed', false, [
                    'usuario' => $usuario,
                    'detalhes' => [
                        'reason' => 'invalid_credentials',
                        'attempts_after' => ((int)($rateCheck['attempts'] ?? 0)) + 1
                    ]
                ]);
                usleep(500000);
                $attemptsLeft = MAX_LOGIN_ATTEMPTS - $rateCheck['attempts'] - 1;
                $msg = 'Usuário ou senha inválidos';
                if ($attemptsLeft <= 2 && $attemptsLeft > 0) {
                    $msg .= ". {$attemptsLeft} tentativa(s) restante(s).";
                }
                echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'check_session': {
            $fotoPerfil = null;
            if (isset($_SESSION['user_id'])) {
                try {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL DEFAULT NULL");
                } catch (Throwable $e) {
                    // coluna já existe
                }
                $stmt = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $_SESSION['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!empty($row['foto_perfil'])) {
                    $fotoPerfil = 'uploads/avatars/' . basename($row['foto_perfil']);
                }
            }
            echo json_encode([
                'logged_in' => isset($_SESSION['user_id']),
                'nome' => $_SESSION['user_nome'] ?? null,
                'tipo' => $_SESSION['user_tipo'] ?? null,
                'setor' => $_SESSION['user_setor'] ?? null,
                'foto_perfil' => $fotoPerfil,
                'csrf_token' => getCsrfToken()
            ]);
            break;
        }

        case 'upload_foto_perfil': {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            try {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL DEFAULT NULL");
            } catch (Throwable $e) {}
            $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
            $dir = $baseDir . DIRECTORY_SEPARATOR . 'avatars';
            if (!is_dir($baseDir)) {
                if (!@mkdir($baseDir, 0755, true)) {
                    echo json_encode(['success' => false, 'error' => 'Servidor: não foi possível criar a pasta uploads. Verifique permissões (755).'], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    echo json_encode(['success' => false, 'error' => 'Servidor: não foi possível criar a pasta uploads/avatars. Verifique permissões (755).'], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            if (!is_writable($dir)) {
                echo json_encode(['success' => false, 'error' => 'Servidor: pasta uploads/avatars sem permissão de escrita. Ajuste para 755 ou 775.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $maxSize = 3 * 1024 * 1024;
            $bin = null;
            $ext = 'jpg';

            // Upload multipart (evita 403 do WAF/ModSecurity na Hostinger)
            if (!empty($_FILES['foto']['tmp_name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['foto']['tmp_name']);
                finfo_close($finfo);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) {
                    echo json_encode(['success' => false, 'error' => 'Imagem inválida. Use JPEG, PNG, GIF ou WebP.'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $ext = $allowed[$mime];
                if ($_FILES['foto']['size'] > $maxSize) {
                    echo json_encode(['success' => false, 'error' => 'Arquivo muito grande (máx. 3 MB).'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $bin = file_get_contents($_FILES['foto']['tmp_name']);
            }

            // Fallback: JSON com base64 (localhost etc.)
            if ($bin === null) {
                $rawInput = file_get_contents('php://input');
                if ($rawInput === false || $rawInput === '') {
                    echo json_encode(['success' => false, 'error' => 'Dados não recebidos. Envie a foto pelo botão Alterar foto.'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $input = json_decode($rawInput, true) ?: [];
                $dataUrl = $input['image'] ?? '';
                if (!preg_match('/^data:image\/(jpeg|png|gif|webp);base64,(.+)$/s', $dataUrl, $m)) {
                    echo json_encode(['success' => false, 'error' => 'Imagem inválida. Use JPEG, PNG, GIF ou WebP.'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $bin = base64_decode($m[2], true);
                if ($bin === false || strlen($bin) > $maxSize) {
                    echo json_encode(['success' => false, 'error' => 'Arquivo inválido ou muito grande (máx. 3 MB).'], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }

            $filename = $userId . '.' . $ext;
            $path = $dir . DIRECTORY_SEPARATOR . $filename;
            if (file_put_contents($path, $bin) === false) {
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar a foto no servidor. Verifique permissões da pasta uploads/avatars.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $relative = 'avatars/' . $filename;
            $stmt = $pdo->prepare("UPDATE usuarios SET foto_perfil = :foto WHERE id = :id");
            $stmt->execute(['foto' => $relative, 'id' => $userId]);
            echo json_encode([
                'success' => true,
                'foto_perfil' => 'uploads/avatars/' . $filename
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'get_meu_perfil': {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            try {
                $pdo->exec("ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL DEFAULT NULL");
            } catch (Throwable $e) {}
            $stmt = $pdo->prepare("SELECT nome, usuario, setor, tipo, whatsapp, foto_perfil FROM usuarios WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Usuário não encontrado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $foto = !empty($row['foto_perfil']) ? 'uploads/avatars/' . basename($row['foto_perfil']) : null;
            echo json_encode([
                'success' => true,
                'nome' => $row['nome'],
                'usuario' => $row['usuario'],
                'setor' => $row['setor'],
                'tipo' => $row['tipo'],
                'whatsapp' => $row['whatsapp'] ?? '',
                'foto_perfil' => $foto
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'update_meu_perfil': {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $nome = trim($input['nome'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? '');
            $senhaAtual = $input['senha_atual'] ?? '';
            $senhaNova = $input['senha_nova'] ?? '';
            if ($nome === '') {
                echo json_encode(['success' => false, 'error' => 'Nome é obrigatório.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $updates = ['nome' => $nome, 'whatsapp' => $whatsapp];
            if ($senhaNova !== '') {
                if (!isStrongPassword($senhaNova)) {
                    echo json_encode(['success' => false, 'error' => strongPasswordErrorMessage()], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user || !password_verify($senhaAtual, $user['senha'])) {
                    echo json_encode(['success' => false, 'error' => 'Senha atual incorreta.'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $updates['senha'] = password_hash($senhaNova, PASSWORD_BCRYPT);
            }
            $set = implode(', ', array_map(function ($k) { return "`$k` = :$k"; }, array_keys($updates)));
            $stmt = $pdo->prepare("UPDATE usuarios SET $set WHERE id = :id");
            $stmt->execute(array_merge($updates, ['id' => $userId]));
            $_SESSION['user_nome'] = $nome;
            echo json_encode(['success' => true, 'nome' => $nome], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'update_my_password': {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $senhaAtual = (string)($input['senha_atual'] ?? '');
            $senhaNova = (string)($input['senha_nova'] ?? '');
            if ($senhaAtual === '' || $senhaNova === '') {
                echo json_encode(['success' => false, 'error' => 'Informe a senha atual e a nova senha.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (!isStrongPassword($senhaNova)) {
                echo json_encode(['success' => false, 'error' => strongPasswordErrorMessage()], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (hash_equals($senhaAtual, $senhaNova)) {
                echo json_encode(['success' => false, 'error' => 'A nova senha deve ser diferente da senha atual.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$userRow || !password_verify($senhaAtual, $userRow['senha'])) {
                echo json_encode(['success' => false, 'error' => 'Senha atual incorreta.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $newHash = password_hash($senhaNova, PASSWORD_BCRYPT);
            $upd = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
            $upd->execute(['senha' => $newHash, 'id' => $userId]);
            logAuthEvent($pdo, 'password_changed', true, [
                'user_id' => $userId,
                'nome_usuario' => $_SESSION['user_nome'] ?? null,
                'detalhes' => ['source' => 'update_my_password']
            ]);
            echo json_encode(['success' => true, 'message' => 'Senha atualizada com sucesso.'], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'logout': {
            $logoutUserId = (int)($_SESSION['user_id'] ?? 0);
            $logoutNome = $_SESSION['user_nome'] ?? null;
            $logoutUsuario = null;
            if ($logoutUserId > 0) {
                try {
                    $stUser = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = :id LIMIT 1");
                    $stUser->execute(['id' => $logoutUserId]);
                    $logoutUsuario = $stUser->fetchColumn() ?: null;
                } catch (Throwable $e) {}
            }
            removeUserSession($pdo, $logoutUserId);
            logAuthEvent($pdo, 'logout', true, [
                'user_id' => $logoutUserId > 0 ? $logoutUserId : null,
                'usuario' => $logoutUsuario,
                'nome_usuario' => $logoutNome
            ]);
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            echo json_encode(['success' => true]);
            break;
        }

        // ============================================
        // ADMIN - LISTAR USUÁRIOS
        // ============================================
        case 'list_users':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                break;
            }
            $stmt = $pdo->query("SELECT id, nome, usuario, tipo, setor, whatsapp, ativo, 
                meta_mensal, meta_anual, comissao_percentual, meta_visitas_semana, meta_visitas_mes, premio_visitas,
                DATE_FORMAT(criado_em, '%d/%m/%Y %H:%i') as criado_em,
                DATE_FORMAT(ultimo_acesso, '%d/%m/%Y %H:%i') as ultimo_acesso
                FROM usuarios ORDER BY id");
            echo json_encode(['success' => true, 'users' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN - LOGS DE AUTENTICAÇÃO
        // ============================================
        case 'list_auth_logs':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            initAuthAuditTable($pdo);
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $evento = trim((string)($_GET['evento'] ?? ''));
            $usuarioFilter = trim((string)($_GET['usuario'] ?? ''));
            $ipFilter = trim((string)($_GET['ip'] ?? ''));
            $from = trim((string)($_GET['from'] ?? ''));
            $to = trim((string)($_GET['to'] ?? ''));

            $where = ["1=1"];
            $params = [];
            if ($evento !== '') {
                $where[] = "evento = :evento";
                $params['evento'] = $evento;
            }
            if ($usuarioFilter !== '') {
                $where[] = "(usuario LIKE :usuario OR nome_usuario LIKE :usuario)";
                $params['usuario'] = '%' . $usuarioFilter . '%';
            }
            if ($ipFilter !== '') {
                $where[] = "ip_address LIKE :ip";
                $params['ip'] = '%' . $ipFilter . '%';
            }
            if ($from !== '') {
                $where[] = "criado_em >= :from";
                $params['from'] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = "criado_em <= :to";
                $params['to'] = $to . ' 23:59:59';
            }
            $sqlWhere = implode(' AND ', $where);
            $sqlCount = "SELECT COUNT(*) FROM auth_audit_logs WHERE $sqlWhere";
            $stmtCount = $pdo->prepare($sqlCount);
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $sql = "SELECT id, evento, sucesso, user_id, usuario, nome_usuario, ip_address, ip_forwarded, host_reverse, session_id, request_method, request_path, origin, referer, user_agent, accept_language, mac_info, detalhes_json, criado_em
                    FROM auth_audit_logs
                    WHERE $sqlWhere
                    ORDER BY id DESC
                    LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                if (!empty($r['detalhes_json'])) {
                    $decoded = json_decode($r['detalhes_json'], true);
                    $r['detalhes'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $r['detalhes_json'];
                } else {
                    $r['detalhes'] = null;
                }
                unset($r['detalhes_json']);
            }
            unset($r);

            echo json_encode([
                'success' => true,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'data' => $rows
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN - Carteira inativa → My Pharm (40 dias sem visita desde 02/03/2026)
        // ============================================
        case 'run_carteira_inativa_mypharm':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                break;
            }
            require_once __DIR__ . '/scripts/carteira_inativa_mypharm.php';
            $dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] !== '0' && $_GET['dry_run'] !== 'false';
            $result = runCarteiraInativaMyPharm($pdo, $dryRun);
            echo json_encode([
                'success'  => true,
                'count'    => $result['count'],
                'moved'    => $result['moved'],
                'dry_run'  => $dryRun,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN - EDITAR METAS
        // ============================================
        case 'edit_user_metas':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            $meta_mensal = floatval($input['meta_mensal'] ?? 0);
            $meta_anual = floatval($input['meta_anual'] ?? 0);
            $comissao = floatval($input['comissao_percentual'] ?? 0);
            $meta_visitas_semana = intval($input['meta_visitas_semana'] ?? 0);
            $meta_visitas_mes = intval($input['meta_visitas_mes'] ?? 0);
            $premio_visitas = floatval($input['premio_visitas'] ?? 0);

            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido']);
                break;
            }

            $stmt = $pdo->prepare("UPDATE usuarios SET 
                meta_mensal = :mm, 
                meta_anual = :ma, 
                comissao_percentual = :cp,
                meta_visitas_semana = :mvs,
                meta_visitas_mes = :mvm,
                premio_visitas = :pv
                WHERE id = :id");
            $stmt->execute([
                'mm' => $meta_mensal,
                'ma' => $meta_anual,
                'cp' => $comissao,
                'mvs' => $meta_visitas_semana,
                'mvm' => $meta_visitas_mes,
                'pv' => $premio_visitas,
                'id' => $id
            ]);

            echo json_encode(['success' => true, 'message' => 'Metas atualizadas com sucesso!']);
            break;

        // ============================================
        // ADMIN - ADICIONAR USUÁRIO
        // ============================================
        case 'add_user':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $nome = trim($input['nome'] ?? '');
            $usuario = trim($input['usuario'] ?? '');
            $senha = trim($input['senha'] ?? '');
            $tipo = trim($input['tipo'] ?? 'usuario');
            $setor = trim($input['setor'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? '');

            if (empty($nome) || empty($usuario) || empty($senha)) {
                echo json_encode(['success' => false, 'error' => 'Preencha todos os campos obrigatórios']);
                break;
            }
            if (!isStrongPassword($senha)) {
                echo json_encode(['success' => false, 'error' => strongPasswordErrorMessage()], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Check if username already exists
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = :usuario");
            $check->execute(['usuario' => $usuario]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Este nome de usuário já existe']);
                break;
            }

            // Usar bcrypt para hash seguro da senha
            $senhaHash = password_hash($senha, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, usuario, senha, tipo, setor, whatsapp, ativo, criado_em) 
                VALUES (:nome, :usuario, :senha, :tipo, :setor, :whatsapp, 1, NOW())");
            $stmt->execute([
                'nome' => $nome,
                'usuario' => $usuario,
                'senha' => $senhaHash,
                'tipo' => $tipo,
                'setor' => $setor ?: null,
                'whatsapp' => $whatsapp ?: null
            ]);
            echo json_encode(['success' => true, 'message' => 'Usuário criado com sucesso!', 'id' => $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN - EDITAR USUÁRIO
        // ============================================
        case 'edit_user':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);
            $nome = trim($input['nome'] ?? '');
            $usuario = trim($input['usuario'] ?? '');
            $tipo = trim($input['tipo'] ?? 'usuario');
            $setor = trim($input['setor'] ?? '');
            $senha = trim($input['senha'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? '');

            if ($id <= 0 || empty($nome) || empty($usuario)) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
                break;
            }

            // Check duplicate username (excluding current user)
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = :usuario AND id != :id");
            $check->execute(['usuario' => $usuario, 'id' => $id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Este nome de usuário já está em uso']);
                break;
            }

            if (!empty($senha)) {
                if (!isStrongPassword($senha)) {
                    echo json_encode(['success' => false, 'error' => strongPasswordErrorMessage()], JSON_UNESCAPED_UNICODE);
                    break;
                }
                // Usar bcrypt para hash seguro da senha
                $senhaHash = password_hash($senha, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, usuario = :usuario, senha = :senha, tipo = :tipo, setor = :setor, whatsapp = :whatsapp WHERE id = :id");
                $stmt->execute(['nome' => $nome, 'usuario' => $usuario, 'senha' => $senhaHash, 'tipo' => $tipo, 'setor' => $setor ?: null, 'whatsapp' => $whatsapp ?: null, 'id' => $id]);
            }
            else {
                $stmt = $pdo->prepare("UPDATE usuarios SET nome = :nome, usuario = :usuario, tipo = :tipo, setor = :setor, whatsapp = :whatsapp WHERE id = :id");
                $stmt->execute(['nome' => $nome, 'usuario' => $usuario, 'tipo' => $tipo, 'setor' => $setor ?: null, 'whatsapp' => $whatsapp ?: null, 'id' => $id]);
            }
            echo json_encode(['success' => true, 'message' => 'Usuário atualizado com sucesso!'], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN - ATIVAR/DESATIVAR USUÁRIO
        // ============================================
        case 'toggle_user':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);

            // Prevent disabling own account
            if ($id === ($_SESSION['user_id'] ?? 0)) {
                echo json_encode(['success' => false, 'error' => 'Você não pode desativar sua própria conta']);
                break;
            }

            $stmt = $pdo->prepare("UPDATE usuarios SET ativo = NOT ativo WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Status alterado com sucesso!'], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN - EXCLUIR USUÁRIO
        // ============================================
        case 'delete_user':
            if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);

            // Prevent deleting own account
            if ($id === ($_SESSION['user_id'] ?? 0)) {
                echo json_encode(['success' => false, 'error' => 'Você não pode excluir sua própria conta']);
                break;
            }

            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute(['id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Usuário excluído com sucesso!'], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // PRESCRITORES - DELEGADO AO MÓDULO
        // ============================================
        case 'save_prescritor_whatsapp':
        case 'get_prescritor_contatos':
        case 'get_prescritor_dados':
        case 'update_prescritor_dados':
        case 'add_prescritor':
        case 'list_profissoes':
        case 'list_especialidades':
            handlePrescritoresModuleAction($action, $pdo);
            break;

        // ============================================
        // VISITAS - FLUXO (iniciar/encerrar)
        // ============================================
        case 'visita_ativa':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_em_andamento (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    inicio DATETIME NOT NULL,
                    fim DATETIME NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'iniciada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_visitador_status (visitador, status),
                    INDEX idx_inicio (inicio)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // Admin pode consultar visita ativa de outro visitador via ?visitador_nome=
            $visitadorNome = trim($_GET['visitador_nome'] ?? $_SESSION['user_nome'] ?? '');
            $stmt = $pdo->prepare("
                SELECT id, visitador, prescritor, inicio
                FROM visitas_em_andamento
                WHERE LOWER(TRIM(visitador)) = LOWER(TRIM(:v)) AND status = 'iniciada' AND fim IS NULL
                ORDER BY inicio DESC
                LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['active' => $row ?: null], JSON_UNESCAPED_UNICODE);
            break;

        case 'iniciar_visita':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            if ((int)date('G') >= 19) {
                echo json_encode(['success' => false, 'error' => 'Não é permitido iniciar visita após as 19h.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_em_andamento (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    inicio DATETIME NOT NULL,
                    fim DATETIME NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'iniciada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_visitador_status (visitador, status),
                    INDEX idx_inicio (inicio)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $input = json_decode(file_get_contents('php://input'), true);
            $prescritor = trim($input['prescritor'] ?? '');
            if ($prescritor === '') {
                echo json_encode(['success' => false, 'error' => 'Informe o prescritor.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            // Admin pode operar em nome de outro visitador
            $visitadorNome = trim($input['visitador_nome'] ?? $_SESSION['user_nome'] ?? '');

            // Garantir somente 1 visita ativa por vez por visitador
            $stmt = $pdo->prepare("
                SELECT id, prescritor, inicio
                FROM visitas_em_andamento
                WHERE LOWER(TRIM(visitador)) = LOWER(TRIM(:v)) AND status = 'iniciada' AND fim IS NULL
                ORDER BY inicio DESC
                LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            $active = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($active) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Já existe uma visita em andamento. Encerre antes de iniciar outra.',
                    'active' => $active
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $stmt = $pdo->prepare("
                INSERT INTO visitas_em_andamento (visitador, prescritor, inicio, status)
                VALUES (:v, :p, NOW(), 'iniciada')
            ");
            $stmt->execute(['v' => $visitadorNome, 'p' => $prescritor]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
            break;

        case 'encerrar_visita':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_em_andamento (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    inicio DATETIME NOT NULL,
                    fim DATETIME NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'iniciada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_visitador_status (visitador, status),
                    INDEX idx_inicio (inicio)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $input = json_decode(file_get_contents('php://input'), true);
            // Admin pode operar em nome de outro visitador
            $visitadorNome = trim($input['visitador_nome'] ?? $_SESSION['user_nome'] ?? '');

            $id = (int)($input['id'] ?? 0);
            $status_visita = trim($input['status_visita'] ?? 'Realizada');
            $local_visita = trim($input['local_visita'] ?? '');
            $resumo_visita = trim($input['resumo_visita'] ?? '');
            $amostra = trim($input['amostra'] ?? '');
            $brinde = trim($input['brinde'] ?? '');
            $artigo = trim($input['artigo'] ?? '');
            $reagendado_para = trim($input['reagendado_para'] ?? '');

            // Confirmação de local (GPS) vinda do tablet (opcional)
            $geo_lat_in = $input['geo_lat'] ?? null;
            $geo_lng_in = $input['geo_lng'] ?? null;
            $geo_acc_in = $input['geo_accuracy'] ?? null;
            $geo_lat = (is_numeric($geo_lat_in) ? (float)$geo_lat_in : null);
            $geo_lng = (is_numeric($geo_lng_in) ? (float)$geo_lng_in : null);
            $geo_acc = (is_numeric($geo_acc_in) ? (float)$geo_acc_in : null);
            if ($geo_lat !== null && ($geo_lat < -90 || $geo_lat > 90)) $geo_lat = null;
            if ($geo_lng !== null && ($geo_lng < -180 || $geo_lng > 180)) $geo_lng = null;
            if ($geo_acc !== null && ($geo_acc < 0 || $geo_acc > 100000)) $geo_acc = null;

            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Visita ativa inválida.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Buscar visita ativa
            $stmt = $pdo->prepare("
                SELECT id, prescritor, inicio
                FROM visitas_em_andamento
                WHERE id = :id AND LOWER(TRIM(visitador)) = LOWER(TRIM(:v)) AND status = 'iniciada' AND fim IS NULL
                LIMIT 1
            ");
            $stmt->execute(['id' => $id, 'v' => $visitadorNome]);
            $active = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$active) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma visita em andamento encontrada para encerrar.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Encerrar a visita em andamento
            $stmt = $pdo->prepare("UPDATE visitas_em_andamento SET fim = NOW(), status = 'encerrada' WHERE id = :id AND LOWER(TRIM(visitador)) = LOWER(TRIM(:v))");
            $stmt->execute(['id' => $id, 'v' => $visitadorNome]);

            // Resolver profissão/UF/registro do prescritor para persistir no histórico.
            $profissaoVisita = '';
            $ufVisita = '';
            $registroVisita = '';
            $nomePrescritor = trim((string)($active['prescritor'] ?? ''));
            if ($nomePrescritor !== '') {
                try {
                    $stmtDados = $pdo->prepare("
                        SELECT
                            COALESCE(
                                (SELECT NULLIF(TRIM(pd.profissao), '') FROM prescritor_dados pd WHERE TRIM(pd.nome_prescritor) = TRIM(:nome1) LIMIT 1),
                                (SELECT NULLIF(TRIM(pr.profissao), '') FROM prescritor_resumido pr WHERE TRIM(pr.nome) = TRIM(:nome2) ORDER BY pr.ano_referencia DESC, pr.id DESC LIMIT 1),
                                (SELECT NULLIF(TRIM(hv.profissao), '') FROM historico_visitas hv WHERE TRIM(hv.prescritor) = TRIM(:nome3) ORDER BY hv.data_visita DESC, hv.id DESC LIMIT 1),
                                ''
                            ) AS profissao,
                            COALESCE(
                                (SELECT NULLIF(TRIM(pd.uf_registro), '') FROM prescritor_dados pd WHERE TRIM(pd.nome_prescritor) = TRIM(:nome4) LIMIT 1),
                                (SELECT NULLIF(TRIM(pr.uf), '') FROM prescritor_resumido pr WHERE TRIM(pr.nome) = TRIM(:nome5) ORDER BY pr.ano_referencia DESC, pr.id DESC LIMIT 1),
                                (SELECT NULLIF(TRIM(hv.uf), '') FROM historico_visitas hv WHERE TRIM(hv.prescritor) = TRIM(:nome6) ORDER BY hv.data_visita DESC, hv.id DESC LIMIT 1),
                                ''
                            ) AS uf,
                            COALESCE(
                                (SELECT NULLIF(TRIM(pd.registro), '') FROM prescritor_dados pd WHERE TRIM(pd.nome_prescritor) = TRIM(:nome7) LIMIT 1),
                                (SELECT NULLIF(TRIM(pr.numero), '') FROM prescritor_resumido pr WHERE TRIM(pr.nome) = TRIM(:nome8) ORDER BY pr.ano_referencia DESC, pr.id DESC LIMIT 1),
                                (SELECT NULLIF(TRIM(hv.registro), '') FROM historico_visitas hv WHERE TRIM(hv.prescritor) = TRIM(:nome9) ORDER BY hv.data_visita DESC, hv.id DESC LIMIT 1),
                                ''
                            ) AS registro
                    ");
                    $stmtDados->execute([
                        'nome1' => $nomePrescritor,
                        'nome2' => $nomePrescritor,
                        'nome3' => $nomePrescritor,
                        'nome4' => $nomePrescritor,
                        'nome5' => $nomePrescritor,
                        'nome6' => $nomePrescritor,
                        'nome7' => $nomePrescritor,
                        'nome8' => $nomePrescritor,
                        'nome9' => $nomePrescritor
                    ]);
                    $dadosPrescritor = $stmtDados->fetch(PDO::FETCH_ASSOC) ?: [];
                    $profissaoVisita = trim((string)($dadosPrescritor['profissao'] ?? ''));
                    $ufVisita = trim((string)($dadosPrescritor['uf'] ?? ''));
                    $registroVisita = trim((string)($dadosPrescritor['registro'] ?? ''));
                } catch (Exception $e) {
                    // Fallback para bases antigas sem colunas completas em prescritor_dados.
                    try {
                        $stmtDadosFallback = $pdo->prepare("
                            SELECT
                                COALESCE(
                                    (SELECT NULLIF(TRIM(pr.profissao), '') FROM prescritor_resumido pr WHERE TRIM(pr.nome) = TRIM(:nome1) ORDER BY pr.ano_referencia DESC, pr.id DESC LIMIT 1),
                                    (SELECT NULLIF(TRIM(hv.profissao), '') FROM historico_visitas hv WHERE TRIM(hv.prescritor) = TRIM(:nome2) ORDER BY hv.data_visita DESC, hv.id DESC LIMIT 1),
                                    ''
                                ) AS profissao,
                                COALESCE(
                                    (SELECT NULLIF(TRIM(pr.uf), '') FROM prescritor_resumido pr WHERE TRIM(pr.nome) = TRIM(:nome3) ORDER BY pr.ano_referencia DESC, pr.id DESC LIMIT 1),
                                    (SELECT NULLIF(TRIM(hv.uf), '') FROM historico_visitas hv WHERE TRIM(hv.prescritor) = TRIM(:nome4) ORDER BY hv.data_visita DESC, hv.id DESC LIMIT 1),
                                    ''
                                ) AS uf,
                                COALESCE(
                                    (SELECT NULLIF(TRIM(pr.numero), '') FROM prescritor_resumido pr WHERE TRIM(pr.nome) = TRIM(:nome5) ORDER BY pr.ano_referencia DESC, pr.id DESC LIMIT 1),
                                    (SELECT NULLIF(TRIM(hv.registro), '') FROM historico_visitas hv WHERE TRIM(hv.prescritor) = TRIM(:nome6) ORDER BY hv.data_visita DESC, hv.id DESC LIMIT 1),
                                    ''
                                ) AS registro
                        ");
                        $stmtDadosFallback->execute([
                            'nome1' => $nomePrescritor,
                            'nome2' => $nomePrescritor,
                            'nome3' => $nomePrescritor,
                            'nome4' => $nomePrescritor,
                            'nome5' => $nomePrescritor,
                            'nome6' => $nomePrescritor
                        ]);
                        $dadosPrescritorFallback = $stmtDadosFallback->fetch(PDO::FETCH_ASSOC) ?: [];
                        $profissaoVisita = trim((string)($dadosPrescritorFallback['profissao'] ?? ''));
                        $ufVisita = trim((string)($dadosPrescritorFallback['uf'] ?? ''));
                        $registroVisita = trim((string)($dadosPrescritorFallback['registro'] ?? ''));
                    } catch (Exception $e2) {
                        // Mantém vazio se não for possível resolver dados de origem.
                    }
                }
            }

            // Garantir coluna inicio_visita no histórico (para exibir duração no relatório)
            try {
                $pdo->exec("ALTER TABLE historico_visitas ADD COLUMN inicio_visita DATETIME NULL");
            } catch (Exception $e) {
                // Coluna já existe
            }

            // Registrar no histórico de visitas (com início para cálculo de duração)
            $stmt = $pdo->prepare("
                INSERT INTO historico_visitas
                    (visitador, prescritor, profissao, uf, registro, data_visita, horario, inicio_visita, status_visita, local_visita, amostra, brinde, artigo, resumo_visita, reagendado_para, ano_referencia)
                VALUES
                    (:visitador, :prescritor, :profissao, :uf, :registro, CURDATE(), DATE_FORMAT(NOW(), '%H:%i'), :inicio_visita, :status_visita, :local_visita, :amostra, :brinde, :artigo, :resumo_visita, :reagendado_para, YEAR(CURDATE()))
            ");
            $stmt->execute([
                'visitador' => $visitadorNome,
                'prescritor' => $active['prescritor'],
                'profissao' => $profissaoVisita,
                'uf' => $ufVisita,
                'registro' => $registroVisita,
                'inicio_visita' => $active['inicio'] ?? null,
                'status_visita' => $status_visita,
                'local_visita' => $local_visita,
                'amostra' => $amostra,
                'brinde' => $brinde,
                'artigo' => $artigo,
                'resumo_visita' => $resumo_visita,
                'reagendado_para' => $reagendado_para
            ]);
            $historicoId = (int)$pdo->lastInsertId();

            // Salvar confirmação de local (GPS), se capturado
            if ($geo_lat !== null && $geo_lng !== null) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS visitas_geolocalizacao (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        historico_id INT NULL,
                        visitador VARCHAR(255) NOT NULL,
                        prescritor VARCHAR(255) NOT NULL,
                        data_visita DATE NOT NULL,
                        horario TIME NULL,
                        lat DECIMAL(10,7) NOT NULL,
                        lng DECIMAL(10,7) NOT NULL,
                        accuracy_m DECIMAL(10,2) NULL,
                        provider VARCHAR(50) NOT NULL DEFAULT 'browser_geolocation',
                        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_hist (historico_id),
                        INDEX idx_visitador_data (visitador, data_visita),
                        INDEX idx_prescritor_data (prescritor, data_visita)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $stmtGeo = $pdo->prepare("
                    INSERT INTO visitas_geolocalizacao
                        (historico_id, visitador, prescritor, data_visita, horario, lat, lng, accuracy_m, provider)
                    VALUES
                        (:hid, :v, :p, CURDATE(), DATE_FORMAT(NOW(), '%H:%i:%s'), :lat, :lng, :acc, 'browser_geolocation')
                ");
                $stmtGeo->execute([
                    'hid' => $historicoId > 0 ? $historicoId : null,
                    'v' => $visitadorNome,
                    'p' => $active['prescritor'],
                    'lat' => $geo_lat,
                    'lng' => $geo_lng,
                    'acc' => $geo_acc
                ]);
            }

            // Salvar agendamento da próxima visita (se informado)
            if (!empty($reagendado_para)) {
                // criar tabela de agendamentos (se não existir)
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS visitas_agendadas (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        visitador VARCHAR(255) NOT NULL,
                        prescritor VARCHAR(255) NOT NULL,
                        data_agendada DATE NOT NULL,
                        hora TIME NULL,
                        observacao TEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'agendada',
                        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_visita (visitador, prescritor, data_agendada),
                        INDEX idx_visitador_data (visitador, data_agendada),
                        INDEX idx_status (status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                $datePart = null;
                $timePart = null;
                // formatos esperados do novo modal: "YYYY-MM-DD" ou "YYYY-MM-DD HH:MM"
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $reagendado_para)) {
                    $datePart = substr($reagendado_para, 0, 10);
                    if (strlen($reagendado_para) >= 16) {
                        $timePart = substr($reagendado_para, 11, 5);
                    }
                }

                if ($datePart) {
                    // Bloquear choque de horários (mesmo visitador + mesma data + mesma hora)
                    if (!empty($timePart)) {
                        // 1) Checar na tabela nova de agendamentos
                        $stmtChk = $pdo->prepare("
                            SELECT prescritor, DATE_FORMAT(hora, '%H:%i') as hora
                            FROM visitas_agendadas
                            WHERE TRIM(COALESCE(visitador, '')) = TRIM(:v)
                              AND data_agendada = :d
                              AND hora = :h
                              AND status = 'agendada'
                            LIMIT 1
                        ");
                        $stmtChk->execute([
                            'v' => $visitadorNome,
                            'd' => $datePart,
                            'h' => $timePart
                        ]);
                        $conf = $stmtChk->fetch(PDO::FETCH_ASSOC);
                        if ($conf) {
                            $confPresc = trim($conf['prescritor'] ?? '');
                            // permitir se for o mesmo prescritor (reagendamento do próprio registro)
                            if (strcasecmp($confPresc, trim($active['prescritor'] ?? '')) !== 0) {
                                echo json_encode([
                                    'success' => false,
                                    'error' => "Conflito de horário: já existe visita agendada para {$datePart} {$timePart} com {$confPresc}. Escolha outro horário."
                                ], JSON_UNESCAPED_UNICODE);
                                break;
                            }
                        }

                        // 2) Checar reagendamentos do sistema antigo (historico_visitas.reagendado_para)
                        $isMyPharmEnc = (strcasecmp(trim($visitadorNome), 'My Pharm') === 0);
                        $visitadorWhereOld = $isMyPharmEnc
                            ? "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')"
                            : "TRIM(COALESCE(visitador, '')) = TRIM(:nome)";
                        $exprOld = "COALESCE(
                            STR_TO_DATE(reagendado_para, '%Y-%m-%d %H:%i'),
                            STR_TO_DATE(reagendado_para, '%d/%m/%Y %H:%i')
                        )";
                        $sqlOld = "
                            SELECT prescritor, reagendado_para
                            FROM historico_visitas
                            WHERE data_visita IS NOT NULL
                              AND $visitadorWhereOld
                              AND reagendado_para IS NOT NULL AND TRIM(reagendado_para) != ''
                              AND $exprOld IS NOT NULL
                              AND DATE($exprOld) = :d
                              AND DATE_FORMAT($exprOld, '%H:%i') = :h
                            LIMIT 1
                        ";
                        $stmtOld = $pdo->prepare($sqlOld);
                        $qOld = ['d' => $datePart, 'h' => $timePart];
                        if (!$isMyPharmEnc) $qOld['nome'] = $visitadorNome;
                        $stmtOld->execute($qOld);
                        $confOld = $stmtOld->fetch(PDO::FETCH_ASSOC);
                        if ($confOld) {
                            $confPrescOld = trim($confOld['prescritor'] ?? '');
                            if (strcasecmp($confPrescOld, trim($active['prescritor'] ?? '')) !== 0) {
                                echo json_encode([
                                    'success' => false,
                                    'error' => "Conflito de horário: já existe reagendamento (sistema antigo) para {$datePart} {$timePart} com {$confPrescOld}. Escolha outro horário."
                                ], JSON_UNESCAPED_UNICODE);
                                break;
                            }
                        }
                    }

                    $stmtAg = $pdo->prepare("
                        INSERT INTO visitas_agendadas (visitador, prescritor, data_agendada, hora, observacao, status)
                        VALUES (:v, :p, :d, :h, :o, 'agendada')
                        ON DUPLICATE KEY UPDATE 
                            hora = VALUES(hora),
                            observacao = VALUES(observacao),
                            status = 'agendada'
                    ");
                    $stmtAg->execute([
                        'v' => $visitadorNome,
                        'p' => $active['prescritor'],
                        'd' => $datePart,
                        'h' => $timePart ?: null,
                        'o' => null
                    ]);
                }
            }

            echo json_encode(['success' => true, 'historico_id' => $historicoId], JSON_UNESCAPED_UNICODE);
            break;

        // ========== AGENDA – listar por mês e criar agendamento ==========
        case 'get_visitas_agendadas_mes': {
            $visitadorNome = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($_GET['visitador'] ?? '');
            $ano = (int)($_GET['ano'] ?? date('Y'));
            $mes = (int)($_GET['mes'] ?? date('n'));
            $isMyPharm = (strcasecmp($visitadorNome, 'My Pharm') === 0 || $visitadorNome === '');
            $visitadorWhere = $isMyPharm
                ? "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')"
                : "TRIM(COALESCE(visitador, '')) = TRIM(:v)";
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_agendadas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    data_agendada DATE NOT NULL,
                    hora TIME NULL,
                    observacao TEXT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'agendada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_visita (visitador, prescritor, data_agendada),
                    INDEX idx_visitador_data (visitador, data_agendada)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            // 1) Visitas REALIZADAS no mês (historico_visitas) – mesmo sem agendamento
            $stmtReal = $pdo->prepare("
                SELECT hv.id as historico_id, hv.prescritor, DATE(hv.data_visita) as data_agendada, DATE_FORMAT(COALESCE(hv.horario, hv.data_visita), '%H:%i') as hora
                FROM historico_visitas hv
                WHERE hv.data_visita IS NOT NULL
                  AND YEAR(hv.data_visita) = :ano
                  AND MONTH(hv.data_visita) = :mes
                  AND " . str_replace('visitador', 'hv.visitador', $visitadorWhere) . "
                ORDER BY hv.data_visita ASC, hv.horario ASC
            ");
            $paramsReal = ['ano' => $ano, 'mes' => $mes];
            if (!$isMyPharm) $paramsReal['v'] = $visitadorNome;
            $stmtReal->execute($paramsReal);
            $realizadas = $stmtReal->fetchAll(PDO::FETCH_ASSOC);
            $realizadasSet = [];
            foreach ($realizadas as $r) {
                $key = (trim($r['prescritor'] ?? '') . '|' . ($r['data_agendada'] ?? ''));
                $realizadasSet[$key] = true;
            }
            // 2) Agendamentos do mês (com historico_id se já realizou nessa data)
            $stmt = $pdo->prepare("
                SELECT va.id, va.prescritor, va.data_agendada, DATE_FORMAT(va.hora, '%H:%i') as hora, va.status, va.observacao,
                       hv.id as historico_id
                FROM visitas_agendadas va
                LEFT JOIN historico_visitas hv ON TRIM(COALESCE(hv.visitador, '')) = TRIM(COALESCE(va.visitador, ''))
                  AND hv.prescritor = va.prescritor
                  AND DATE(hv.data_visita) = va.data_agendada
                  AND hv.data_visita IS NOT NULL
                WHERE " . str_replace('visitador', 'va.visitador', $visitadorWhere) . "
                  AND va.status = 'agendada'
                  AND YEAR(va.data_agendada) = :ano
                  AND MONTH(va.data_agendada) = :mes
                ORDER BY va.data_agendada ASC, va.hora ASC
            ");
            $params = ['ano' => $ano, 'mes' => $mes];
            if (!$isMyPharm) $params['v'] = $visitadorNome;
            $stmt->execute($params);
            $agendadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // 3) Lista unificada: todas realizadas (tipo=realizada) + agendadas que ainda não têm realizada no mesmo dia (tipo=agendada)
            $lista = [];
            foreach ($realizadas as $r) {
                $lista[] = [
                    'tipo' => 'realizada',
                    'id' => null,
                    'prescritor' => $r['prescritor'] ?? '',
                    'data_agendada' => $r['data_agendada'] ?? '',
                    'hora' => $r['hora'] ?? '',
                    'historico_id' => isset($r['historico_id']) ? (int)$r['historico_id'] : null,
                    'status' => 'realizada'
                ];
            }
            foreach ($agendadas as $a) {
                $key = (trim($a['prescritor'] ?? '') . '|' . ($a['data_agendada'] ?? ''));
                if (isset($realizadasSet[$key])) continue; // já está como realizada
                $lista[] = [
                    'tipo' => 'agendada',
                    'id' => isset($a['id']) ? (int)$a['id'] : null,
                    'prescritor' => $a['prescritor'] ?? '',
                    'data_agendada' => $a['data_agendada'] ?? '',
                    'hora' => $a['hora'] ?? '',
                    'historico_id' => isset($a['historico_id']) && $a['historico_id'] ? (int)$a['historico_id'] : null,
                    'status' => 'agendada'
                ];
            }
            usort($lista, function ($x, $y) {
                $d = strcmp($x['data_agendada'] ?? '', $y['data_agendada'] ?? '');
                if ($d !== 0) return $d;
                return strcmp($x['hora'] ?? '', $y['hora'] ?? '');
            });
            echo json_encode(['success' => true, 'visitas' => $lista], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'get_detalhe_visita': {
            $historicoId = (int)($_GET['historico_id'] ?? 0);
            if ($historicoId <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $visitadorNome = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($_GET['visitador'] ?? '');
            $isMyPharm = (strcasecmp($visitadorNome, 'My Pharm') === 0 || $visitadorNome === '');
            $visitadorWhere = $isMyPharm
                ? "(hv.visitador IS NULL OR TRIM(hv.visitador) = '' OR LOWER(TRIM(hv.visitador)) = 'my pharm')"
                : "TRIM(COALESCE(hv.visitador, '')) = TRIM(:v)";
            $stmt = $pdo->prepare("
                SELECT hv.id, hv.visitador, hv.prescritor, hv.data_visita, hv.horario, hv.inicio_visita,
                    hv.status_visita, hv.local_visita, hv.resumo_visita, hv.reagendado_para,
                    hv.amostra, hv.brinde, hv.artigo,
                    TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) as duracao_minutos,
                    vg.lat as geo_lat, vg.lng as geo_lng, vg.accuracy_m as geo_accuracy
                FROM historico_visitas hv
                LEFT JOIN visitas_geolocalizacao vg ON vg.historico_id = hv.id
                WHERE hv.id = :id AND hv.data_visita IS NOT NULL AND $visitadorWhere
                LIMIT 1
            ");
            $params = ['id' => $historicoId];
            if (!$isMyPharm) $params['v'] = $visitadorNome;
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Visita não encontrada'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['success' => true, 'visita' => $row], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'get_visitas_prescritor': {
            $prescritor = trim($_GET['prescritor'] ?? '');
            if ($prescritor === '') {
                echo json_encode(['success' => false, 'error' => 'Prescritor não informado.', 'visitas' => []], JSON_UNESCAPED_UNICODE);
                break;
            }
            $visitadorNome = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($_GET['visitador'] ?? '');
            $isMyPharm = (strcasecmp($visitadorNome, 'My Pharm') === 0 || $visitadorNome === '');
            $visitadorWhere = $isMyPharm
                ? "(hv.visitador IS NULL OR TRIM(hv.visitador) = '' OR LOWER(TRIM(hv.visitador)) = 'my pharm')"
                : "TRIM(COALESCE(hv.visitador, '')) = TRIM(:v)";
            $stmt = $pdo->prepare("
                SELECT hv.id, hv.prescritor, hv.data_visita, hv.horario, hv.status_visita, hv.local_visita, hv.resumo_visita,
                    hv.amostra, hv.brinde, hv.artigo,
                    TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) as duracao_minutos
                FROM historico_visitas hv
                WHERE hv.data_visita IS NOT NULL AND hv.prescritor = :p AND $visitadorWhere
                ORDER BY hv.data_visita DESC, hv.horario DESC
                LIMIT 100
            ");
            $params = ['p' => $prescritor];
            if (!$isMyPharm) $params['v'] = $visitadorNome;
            $stmt->execute($params);
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'visitas' => $lista], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'update_detalhe_visita': {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $historicoId = (int)($input['historico_id'] ?? 0);
            if ($historicoId <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID da visita inválido.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $visitadorNome = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($input['visitador'] ?? '');
            $isMyPharm = (strcasecmp($visitadorNome, 'My Pharm') === 0 || $visitadorNome === '');
            $visitadorWhere = $isMyPharm
                ? "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')"
                : "TRIM(COALESCE(visitador, '')) = TRIM(:v)";
            $stmtGet = $pdo->prepare("SELECT id, data_visita FROM historico_visitas WHERE id = :id AND data_visita IS NOT NULL AND $visitadorWhere LIMIT 1");
            $stmtGet->execute($isMyPharm ? ['id' => $historicoId] : ['id' => $historicoId, 'v' => $visitadorNome]);
            $rowVisita = $stmtGet->fetch(PDO::FETCH_ASSOC);
            if (!$rowVisita) {
                echo json_encode(['success' => false, 'error' => 'Visita não encontrada ou sem permissão.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $agendaDate = isset($rowVisita['data_visita']) ? (is_object($rowVisita['data_visita']) ? $rowVisita['data_visita']->format('Y-m-d') : substr((string)$rowVisita['data_visita'], 0, 10)) : '';
            $dt = new DateTime();
            $dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'));
            $segundaSemana = $dt->format('Y-m-d');
            $domingoSemana = (clone $dt)->modify('+6 days')->format('Y-m-d');
            if ($agendaDate === '' || $agendaDate < $segundaSemana || $agendaDate > $domingoSemana) {
                echo json_encode(['success' => false, 'error' => 'Só é possível editar visitas da semana atual (segunda a domingo).'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $data_visita = isset($input['data_visita']) ? trim($input['data_visita']) : null;
            $datePart = null;
            if ($data_visita !== null && $data_visita !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $data_visita)) $datePart = substr($data_visita, 0, 10);
                elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $data_visita, $m)) $datePart = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
            $horario = isset($input['horario']) && $input['horario'] !== '' ? preg_replace('/[^\d:]/', '', trim($input['horario'])) : null;
            if ($horario !== null && strlen($horario) === 4) $horario = substr($horario, 0, 2) . ':' . substr($horario, 2, 2);
            $inicio_visita = isset($input['inicio_visita']) && $input['inicio_visita'] !== '' ? trim($input['inicio_visita']) : null;
            if ($inicio_visita !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $inicio_visita)) {
                $t = str_replace('T', ' ', $inicio_visita);
                $t = preg_match('/\d{1,2}:\d{2}/', $t) ? $t : $t . ' 00:00:00';
                $inicio_visita = substr($t, 0, 19);
            }
            $local_visita = isset($input['local_visita']) ? trim($input['local_visita']) : null;
            $resumo_visita = isset($input['resumo_visita']) ? trim($input['resumo_visita']) : null;
            $amostra = isset($input['amostra']) ? trim($input['amostra']) : null;
            $brinde = isset($input['brinde']) ? trim($input['brinde']) : null;
            $artigo = isset($input['artigo']) ? trim($input['artigo']) : null;
            $updates = [];
            $params = ['id' => $historicoId];
            if ($datePart !== null) { $updates[] = 'data_visita = :data_visita'; $params['data_visita'] = $datePart; }
            if ($horario !== null) { $updates[] = 'horario = :horario'; $params['horario'] = $horario; }
            if ($inicio_visita !== null) { $updates[] = 'inicio_visita = :inicio_visita'; $params['inicio_visita'] = $inicio_visita; }
            if ($local_visita !== null) { $updates[] = 'local_visita = :local_visita'; $params['local_visita'] = $local_visita; }
            if ($resumo_visita !== null) { $updates[] = 'resumo_visita = :resumo_visita'; $params['resumo_visita'] = $resumo_visita; }
            if ($amostra !== null) { $updates[] = 'amostra = :amostra'; $params['amostra'] = $amostra; }
            if ($brinde !== null) { $updates[] = 'brinde = :brinde'; $params['brinde'] = $brinde; }
            if ($artigo !== null) { $updates[] = 'artigo = :artigo'; $params['artigo'] = $artigo; }
            if (count($updates) === 0) {
                echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (!$isMyPharm) $params['v'] = $visitadorNome;
            $sql = "UPDATE historico_visitas SET " . implode(', ', $updates) . " WHERE id = :id AND $visitadorWhere";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'criar_agendamento': {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $visitadorNome = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($input['visitador'] ?? '');
            $prescritor = trim($input['prescritor'] ?? '');
            $data_agendada = trim($input['data_agendada'] ?? '');
            $hora = isset($input['hora']) && $input['hora'] !== '' ? trim($input['hora']) : null;
            if ($prescritor === '' || $data_agendada === '') {
                echo json_encode(['success' => false, 'error' => 'Prescritor e data são obrigatórios.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $datePart = null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $data_agendada)) {
                $datePart = substr($data_agendada, 0, 10);
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $data_agendada, $m)) {
                $datePart = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
            if (!$datePart) {
                echo json_encode(['success' => false, 'error' => 'Data inválida. Use AAAA-MM-DD ou DD/MM/AAAA.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $timePart = null;
            if ($hora && preg_match('/^\d{1,2}:?\d{2}$/', preg_replace('/\s/', '', $hora))) {
                $timePart = preg_replace('/[^\d:]/', '', $hora);
                if (strlen($timePart) === 4) $timePart = substr($timePart, 0, 2) . ':' . substr($timePart, 2, 2);
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_agendadas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    data_agendada DATE NOT NULL,
                    hora TIME NULL,
                    observacao TEXT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'agendada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_visita (visitador, prescritor, data_agendada),
                    INDEX idx_visitador_data (visitador, data_agendada)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $stmt = $pdo->prepare("
                INSERT INTO visitas_agendadas (visitador, prescritor, data_agendada, hora, observacao, status)
                VALUES (:v, :p, :d, :h, NULL, 'agendada')
                ON DUPLICATE KEY UPDATE hora = VALUES(hora), status = 'agendada'
            ");
            $stmt->execute([
                'v' => $visitadorNome,
                'p' => $prescritor,
                'd' => $datePart,
                'h' => $timePart
            ]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'update_agendamento': {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID do agendamento não informado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $visitadorNome = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($input['visitador'] ?? '');
            $isMyPharm = (strcasecmp($visitadorNome, 'My Pharm') === 0 || $visitadorNome === '');
            $vw = $isMyPharm ? "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')" : "TRIM(COALESCE(visitador, '')) = TRIM(:v)";
            $stmtGet = $pdo->prepare("SELECT data_agendada, hora FROM visitas_agendadas WHERE id = :id AND " . str_replace('visitador', 'visitador', $vw));
            $stmtGet->execute($isMyPharm ? ['id' => $id] : ['id' => $id, 'v' => $visitadorNome]);
            $row = $stmtGet->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Agendamento não encontrado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $agendaDate = isset($row['data_agendada']) ? (is_object($row['data_agendada']) ? $row['data_agendada']->format('Y-m-d') : substr((string)$row['data_agendada'], 0, 10)) : '';
            $dt = new DateTime();
            $dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'));
            $segundaSemana = $dt->format('Y-m-d');
            $domingoSemana = (clone $dt)->modify('+6 days')->format('Y-m-d');
            if ($agendaDate === '' || $agendaDate < $segundaSemana || $agendaDate > $domingoSemana) {
                echo json_encode(['success' => false, 'error' => 'Só é possível editar visitas da semana atual (segunda a domingo).'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $prescritor = trim($input['prescritor'] ?? '');
            $data_agendada = trim($input['data_agendada'] ?? '');
            $hora = isset($input['hora']) && $input['hora'] !== '' ? trim($input['hora']) : null;
            if ($prescritor === '' || $data_agendada === '') {
                echo json_encode(['success' => false, 'error' => 'Prescritor e data são obrigatórios.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $datePart = null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $data_agendada)) $datePart = substr($data_agendada, 0, 10);
            elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $data_agendada, $m)) $datePart = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            if (!$datePart) {
                echo json_encode(['success' => false, 'error' => 'Data inválida.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $timePart = null;
            if ($hora && preg_match('/^\d{1,2}:?\d{2}$/', preg_replace('/\s/', '', $hora))) {
                $timePart = preg_replace('/[^\d:]/', '', $hora);
                if (strlen($timePart) === 4) $timePart = substr($timePart, 0, 2) . ':' . substr($timePart, 2, 2);
            }
            $stmt = $pdo->prepare("
                UPDATE visitas_agendadas SET prescritor = :p, data_agendada = :d, hora = :h
                WHERE id = :id AND TRIM(COALESCE(visitador, '')) = TRIM(:v)
            ");
            $stmt->execute(['id' => $id, 'v' => $visitadorNome, 'p' => $prescritor, 'd' => $datePart, 'h' => $timePart]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'Agendamento não encontrado ou sem permissão.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'excluir_agendamento': {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID do agendamento não informado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $visitadorNome = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($input['visitador'] ?? '');
            $isMyPharm = (strcasecmp($visitadorNome, 'My Pharm') === 0 || $visitadorNome === '');
            $vw = $isMyPharm ? "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')" : "TRIM(COALESCE(visitador, '')) = TRIM(:v)";
            $stmtGet = $pdo->prepare("SELECT data_agendada, hora FROM visitas_agendadas WHERE id = :id AND " . str_replace('visitador', 'visitador', $vw));
            $stmtGet->execute($isMyPharm ? ['id' => $id] : ['id' => $id, 'v' => $visitadorNome]);
            $row = $stmtGet->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Agendamento não encontrado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $agendaDate = isset($row['data_agendada']) ? (is_object($row['data_agendada']) ? $row['data_agendada']->format('Y-m-d') : substr((string)$row['data_agendada'], 0, 10)) : '';
            $dt = new DateTime();
            $dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'));
            $segundaSemana = $dt->format('Y-m-d');
            $domingoSemana = (clone $dt)->modify('+6 days')->format('Y-m-d');
            if ($agendaDate === '' || $agendaDate < $segundaSemana || $agendaDate > $domingoSemana) {
                echo json_encode(['success' => false, 'error' => 'Só é possível excluir visitas da semana atual (segunda a domingo).'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("DELETE FROM visitas_agendadas WHERE id = :id AND TRIM(COALESCE(visitador, '')) = TRIM(:v)");
            $stmt->execute(['id' => $id, 'v' => $visitadorNome]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'error' => 'Agendamento não encontrado ou já excluído.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ========== ROTA DO DIA (GPS percurso) ==========
        case 'rota_ativa':
            // #region agent log
            _debugLogRota(['hypothesisId' => 'H1,H3', 'message' => 'rota_ativa case entered', 'data' => ['action' => $action, 'closure_isset' => isset($fecharRotasApos19h)]]);
            // #endregion
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            try {
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
                // #region agent log
                _debugLogRota(['hypothesisId' => 'H1', 'message' => 'before fecharRotasApos19h', 'data' => ['closure_isset' => isset($fecharRotasApos19h)]]);
                // #endregion
                $fecharRotasApos19h($pdo);
                $visitadorNome = trim($_GET['visitador_nome'] ?? $_SESSION['user_nome'] ?? '');
                if ($visitadorNome === '') {
                    echo json_encode(['active' => null], JSON_UNESCAPED_UNICODE);
                    break;
                }
                // Match insensível a maiúsculas e espaços (evita rota “em aberto” no BD e painel em idle)
                $stmt = $pdo->prepare("
                    SELECT id, visitador_nome, data_inicio, data_fim, pausado_em, status
                    FROM rotas_diarias
                    WHERE LOWER(TRIM(visitador_nome)) = LOWER(TRIM(:v)) AND status IN ('em_andamento', 'pausada')
                    ORDER BY data_inicio DESC
                    LIMIT 1
                ");
                $stmt->execute(['v' => $visitadorNome]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['active' => $row ?: null], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                // #region agent log
                _debugLogRota(['hypothesisId' => 'H2', 'message' => 'catch rota_ativa', 'data' => ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]]);
                // #endregion
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao consultar rota ativa.', 'active' => null], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'start_rota':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
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
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS rotas_pontos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    rota_id INT NOT NULL,
                    lat DECIMAL(10,7) NOT NULL,
                    lng DECIMAL(10,7) NOT NULL,
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_rota (rota_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $visitadorNome = trim($input['visitador_nome'] ?? $_SESSION['user_nome'] ?? '');
            if ($visitadorNome === '') {
                echo json_encode(['success' => false, 'error' => 'Visitador não informado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("
                SELECT id FROM rotas_diarias
                WHERE LOWER(TRIM(visitador_nome)) = LOWER(TRIM(:v)) AND status IN ('em_andamento', 'pausada')
                LIMIT 1
            ");
            $fecharRotasApos19h($pdo);
            $stmt->execute(['v' => $visitadorNome]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Já existe uma rota ativa. Pause ou finalize antes de iniciar outra.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            // Verificar se já existe rota finalizada hoje (evitar múltiplas rotas no dia)
            $reabrir = filter_var($input['reabrir'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $stmt = $pdo->prepare("
                SELECT id, data_inicio, data_fim FROM rotas_diarias
                WHERE LOWER(TRIM(visitador_nome)) = LOWER(TRIM(:v)) AND DATE(data_inicio) = CURDATE() AND status = 'finalizada'
                ORDER BY data_inicio DESC LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            $rotaHoje = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rotaHoje && !$reabrir) {
                echo json_encode([
                    'success' => false,
                    'rota_existente' => true,
                    'rota_id' => (int)$rotaHoje['id'],
                    'data_inicio' => $rotaHoje['data_inicio'],
                    'data_fim' => $rotaHoje['data_fim'],
                    'error' => 'Você já finalizou uma rota hoje. Deseja reabrir a rota existente?'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            if ($rotaHoje && $reabrir) {
                $stmt = $pdo->prepare("
                    UPDATE rotas_diarias SET status = 'em_andamento', data_fim = NULL, pausado_em = NULL
                    WHERE id = :id
                ");
                $stmt->execute(['id' => (int)$rotaHoje['id']]);
                echo json_encode(['success' => true, 'rota_id' => (int)$rotaHoje['id'], 'reaberta' => true], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("
                INSERT INTO rotas_diarias (visitador_nome, data_inicio, status)
                VALUES (:v, NOW(), 'em_andamento')
            ");
            $stmt->execute(['v' => $visitadorNome]);
            echo json_encode(['success' => true, 'rota_id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
            break;

        case 'pause_rota':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $sessionNome = trim($_SESSION['user_nome'] ?? '');
            // Mesmo critério do start_rota: prioriza nome enviado pelo app (localStorage)
            $visitadorNome = trim($input['visitador_nome'] ?? '') !== '' ? trim($input['visitador_nome']) : $sessionNome;
            // Obrigatório encerrar visita ao prescritor antes de pausar a rota
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_em_andamento (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    inicio DATETIME NOT NULL,
                    fim DATETIME NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'iniciada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_visitador_status (visitador, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $stmtV = $pdo->prepare("
                SELECT id, prescritor FROM visitas_em_andamento
                WHERE LOWER(TRIM(visitador)) = LOWER(TRIM(:v)) AND status = 'iniciada' AND fim IS NULL LIMIT 1
            ");
            $stmtV->execute(['v' => $visitadorNome]);
            $visitaAbertaPause = $stmtV->fetch(PDO::FETCH_ASSOC);
            if ($visitaAbertaPause) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Finalize a visita ao prescritor antes de pausar a rota.',
                    'prescritor' => $visitaAbertaPause['prescritor'] ?? null,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("
                UPDATE rotas_diarias
                SET pausado_em = NOW(), status = 'pausada'
                WHERE LOWER(TRIM(visitador_nome)) = LOWER(TRIM(:v)) AND status = 'em_andamento'
            ");
            $stmt->execute(['v' => $visitadorNome]);
            echo json_encode(['success' => true, 'paused' => $stmt->rowCount() > 0], JSON_UNESCAPED_UNICODE);
            break;

        case 'resume_rota':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $sessionNome = trim($_SESSION['user_nome'] ?? '');
            $visitadorNome = trim($input['visitador_nome'] ?? '') !== '' ? trim($input['visitador_nome']) : $sessionNome;
            $stmt = $pdo->prepare("
                UPDATE rotas_diarias
                SET pausado_em = NULL, status = 'em_andamento'
                WHERE LOWER(TRIM(visitador_nome)) = LOWER(TRIM(:v)) AND status = 'pausada'
            ");
            $stmt->execute(['v' => $visitadorNome]);
            echo json_encode(['success' => true, 'resumed' => $stmt->rowCount() > 0], JSON_UNESCAPED_UNICODE);
            break;

        case 'finish_rota':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $sessionNome = trim($_SESSION['user_nome'] ?? '');
            $visitadorNome = trim($input['visitador_nome'] ?? '') !== '' ? trim($input['visitador_nome']) : $sessionNome;
            // Não permitir finalizar rota com visita em andamento
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS visitas_em_andamento (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    visitador VARCHAR(255) NOT NULL,
                    prescritor VARCHAR(255) NOT NULL,
                    inicio DATETIME NOT NULL,
                    fim DATETIME NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'iniciada',
                    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_visitador_status (visitador, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $stmt = $pdo->prepare("
                SELECT id, prescritor FROM visitas_em_andamento
                WHERE LOWER(TRIM(visitador)) = LOWER(TRIM(:v)) AND status = 'iniciada' AND fim IS NULL LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            $visitaAberta = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($visitaAberta) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Encerre a visita ao prescritor (fluxo completo) antes de finalizar a rota.',
                    'prescritor' => $visitaAberta['prescritor'] ?? null,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("
                UPDATE rotas_diarias
                SET data_fim = NOW(), pausado_em = NULL, status = 'finalizada'
                WHERE LOWER(TRIM(visitador_nome)) = LOWER(TRIM(:v)) AND status IN ('em_andamento', 'pausada')
            ");
            $stmt->execute(['v' => $visitadorNome]);
            echo json_encode(['success' => true, 'finished' => $stmt->rowCount() > 0], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_rota_ponto':
            if (!$isVisitadorSetor && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $visitadorNome = trim($input['visitador_nome'] ?? $_SESSION['user_nome'] ?? '');
            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;
            if ($visitadorNome === '' || $lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                echo json_encode(['success' => false, 'error' => 'Dados inválidos.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $fecharRotasApos19h($pdo);
            $stmt = $pdo->prepare("
                SELECT id FROM rotas_diarias
                WHERE LOWER(TRIM(visitador_nome)) = LOWER(TRIM(:v)) AND status = 'em_andamento'
                LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            $rota = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rota) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma rota em andamento.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("
                INSERT INTO rotas_pontos (rota_id, lat, lng) VALUES (:rid, :lat, :lng)
            ");
            $stmt->execute(['rid' => $rota['id'], 'lat' => $lat, 'lng' => $lng]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            break;

        case 'db_info':
            // Get last data update (max data_aprovacao)
            $stmt = $pdo->query("SELECT MAX(data_aprovacao) as last_update FROM gestao_pedidos");
            $last_update = $stmt->fetchColumn();

            // Get last month with data in 2026
            $stmt = $pdo->query("SELECT MAX(MONTH(data_aprovacao)) as last_mes FROM gestao_pedidos WHERE ano_referencia = 2026");
            $last_mes = $stmt->fetchColumn();

            echo json_encode([
                'last_update' => $last_update,
                'last_mes' => $last_mes
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'transfer_prescritor':
        case 'add_prescritor':
            handlePrescritoresModuleAction($action, $pdo);
            break;

        default:
            echo json_encode(['error' => 'Ação não reconhecida']);
    }


}
catch (Exception $e) {
    error_log('MyPharm API Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);

    if (IS_PRODUCTION) {
        echo json_encode(['error' => 'Erro interno do servidor.'], JSON_UNESCAPED_UNICODE);
    }
    else {
        // Debug detalhado apenas em localhost
        echo json_encode([
            'error' => 'Erro: ' . $e->getMessage(),
            'file' => basename($e->getFile()) . ':' . $e->getLine()
        ], JSON_UNESCAPED_UNICODE);
    }
}
