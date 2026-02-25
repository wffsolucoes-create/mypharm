<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

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

// Sanitizar parâmetro LIMIT para queries SQL (range seguro)
function safeLimit($value, $min = 1, $max = 100)
{
    $limit = intval($value);
    return max($min, min($max, $limit));
}

// Retorna [clausula WHERE/AND, params] para filtro ano/mes/dia em gestao_pedidos
function buildDateFilter()
{
    $ano = $_GET['ano'] ?? null;
    $mes = $_GET['mes'] ?? null;
    $dia = $_GET['dia'] ?? null;
    $parts = [];
    $params = [];
    if ($ano) {
        $parts[] = 'ano_referencia = :ano';
        $params['ano'] = $ano;
    }
    if ($mes) {
        $parts[] = 'MONTH(data_aprovacao) = :mes';
        $params['mes'] = (int)$mes;
    }
    if ($dia && $ano && $mes) {
        $dataFiltro = sprintf('%04d-%02d-%02d', (int)$ano, (int)$mes, (int)$dia);
        $parts[] = 'DATE(data_aprovacao) = :data_filtro';
        $params['data_filtro'] = $dataFiltro;
    }
    if (empty($parts))
        return ['1=1', []];
    return ['(' . implode(' AND ', $parts) . ')', $params];
}

$pdo = getConnection();
$action = $_GET['action'] ?? '';

try {
    // Ações públicas (não precisam de sessão)
    $publicActions = ['login', 'csrf_token'];
    if (!in_array($action, $publicActions) && !isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Visitador só pode acessar ações permitidas (evita bypass pela API)
    // Ações públicas (login, csrf_token) não entram nessa restrição
    $visitadorAllowed = [
        'logout',
        'check_session',
        'visitador_dashboard',
        'all_prescritores',
        'get_prescritor_contatos',
        'save_prescritor_whatsapp',
        'get_prescritor_dados',
        'update_prescritor_dados',
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
        'get_foto_perfil',
        'get_visitas_agendadas_mes',
        'criar_agendamento',
        'update_agendamento',
        'excluir_agendamento',
        'get_detalhe_visita',
        'update_detalhe_visita',
        'get_visitas_prescritor'
    ];
    $userSetor = strtolower(trim($_SESSION['user_setor'] ?? ''));

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
    if ($userSetor === 'visitador' && !in_array($action, $publicActions) && !in_array($action, $visitadorAllowed)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acesso restrito. Usuários do setor Visitador só podem acessar o painel do visitador.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
            list($whereCond, $paramsKpis) = buildDateFilter();
            $whereKpis = $whereCond === '1=1' ? '' : "WHERE $whereCond";

            $sql = "SELECT 
                COALESCE(SUM(preco_liquido), 0) as faturamento_total,
                COALESCE(SUM(preco_bruto), 0) as receita_bruta,
                COALESCE(SUM(preco_custo), 0) as custo_total,
                COALESCE(SUM(desconto), 0) as total_descontos,
                COUNT(*) as total_pedidos,
                COUNT(DISTINCT cliente) as total_clientes,
                COUNT(DISTINCT COALESCE(NULLIF(prescritor, ''), 'My Pharm')) as total_prescritores,
                COALESCE(AVG(preco_liquido), 0) as ticket_medio
            FROM gestao_pedidos $whereKpis";

            $stmt = $pdo->prepare($sql);
            foreach ($paramsKpis as $k => $v)
                $stmt->bindValue(":$k", $v);
            $stmt->execute();
            $kpis = $stmt->fetch();

            $kpis['margem_lucro'] = $kpis['faturamento_total'] > 0
                ? round((($kpis['faturamento_total'] - $kpis['custo_total']) / $kpis['faturamento_total']) * 100, 2)
                : 0;

            $sql2 = "SELECT status_financeiro, COUNT(*) as total 
                     FROM gestao_pedidos $whereKpis 
                     GROUP BY status_financeiro ORDER BY total DESC";
            $stmt2 = $pdo->prepare($sql2);
            foreach ($paramsKpis as $k => $v)
                $stmt2->bindValue(":$k", $v);
            $stmt2->execute();
            $kpis['status_financeiro'] = $stmt2->fetchAll();

            echo json_encode($kpis, JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // FATURAMENTO MENSAL
        // ============================================
        case 'faturamento_mensal':
            list($whereCond, $paramsFat) = buildDateFilter();
            if ($whereCond === '1=1') {
                $anoDefault = $_GET['ano'] ?? date('Y');
                $whereFat = "WHERE ano_referencia = :ano AND data_aprovacao IS NOT NULL";
                $paramsFat = ['ano' => $anoDefault];
            }
            else {
                $whereFat = "WHERE $whereCond AND data_aprovacao IS NOT NULL";
            }
            $stmt = $pdo->prepare("
                SELECT 
                    MONTH(data_aprovacao) as mes,
                    SUM(preco_liquido) as faturamento,
                    SUM(preco_bruto) as receita_bruta,
                    SUM(preco_custo) as custo,
                    COUNT(*) as qtd_pedidos,
                    COUNT(DISTINCT cliente) as clientes_unicos
                FROM gestao_pedidos 
                $whereFat
                GROUP BY MONTH(data_aprovacao)
                ORDER BY mes
            ");
            foreach ($paramsFat as $k => $v)
                $stmt->bindValue(":$k", $v);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // FATURAMENTO POR ANO
        // ============================================
        case 'faturamento_anual':
            $stmt = $pdo->query("
                SELECT 
                    ano_referencia as ano,
                    SUM(preco_liquido) as faturamento,
                    SUM(preco_bruto) as receita_bruta,
                    SUM(preco_custo) as custo,
                    COUNT(*) as qtd_pedidos,
                    COUNT(DISTINCT cliente) as clientes_unicos
                FROM gestao_pedidos 
                GROUP BY ano_referencia
                ORDER BY ano_referencia
            ");
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // TOP FORMAS FARMACÊUTICAS
        // ============================================
        case 'top_formas':
            list($whereCond, $paramsFormas) = buildDateFilter();
            $whereFormas = $whereCond === '1=1' ? '' : "WHERE $whereCond";
            $limit = safeLimit($_GET['limit'] ?? 10);

            $stmt = $pdo->prepare("
                SELECT 
                    forma_farmaceutica,
                    COUNT(*) as quantidade,
                    SUM(preco_liquido) as faturamento,
                    SUM(preco_custo) as custo,
                    AVG(preco_liquido) as ticket_medio
                FROM gestao_pedidos 
                $whereFormas
                GROUP BY forma_farmaceutica
                ORDER BY faturamento DESC
                LIMIT $limit
            ");
            foreach ($paramsFormas as $k => $v)
                $stmt->bindValue(":$k", $v);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // TOP PRESCRITORES
        // ============================================
        case 'top_prescritores':
            list($whereCond, $paramsPresc) = buildDateFilter();
            $wherePresc = $whereCond === '1=1' ? '' : "WHERE $whereCond";
            $limit = safeLimit($_GET['limit'] ?? 10);

            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(NULLIF(prescritor, ''), 'My Pharm') as prescritor,
                    COUNT(*) as total_pedidos,
                    SUM(preco_liquido) as faturamento,
                    COUNT(DISTINCT cliente) as clientes_atendidos,
                    AVG(preco_liquido) as ticket_medio
                FROM gestao_pedidos 
                $wherePresc
                GROUP BY COALESCE(NULLIF(prescritor, ''), 'My Pharm')
                ORDER BY faturamento DESC
                LIMIT $limit
            ");
            foreach ($paramsPresc as $k => $v)
                $stmt->bindValue(":$k", $v);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // TOP CLIENTES
        // ============================================
        case 'top_clientes':
            list($whereCond, $paramsCli) = buildDateFilter();
            $whereCli = $whereCond === '1=1' ? '' : "WHERE $whereCond";
            $limit = safeLimit($_GET['limit'] ?? 10);

            $stmt = $pdo->prepare("
                SELECT 
                    cliente,
                    COUNT(*) as total_pedidos,
                    SUM(preco_liquido) as faturamento,
                    AVG(preco_liquido) as ticket_medio,
                    MAX(data_aprovacao) as ultima_compra
                FROM gestao_pedidos 
                $whereCli
                GROUP BY cliente
                HAVING cliente != '' AND cliente IS NOT NULL
                ORDER BY faturamento DESC
                LIMIT $limit
            ");
            foreach ($paramsCli as $k => $v)
                $stmt->bindValue(":$k", $v);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // TOP ATENDENTES
        // ============================================
        case 'top_atendentes':
            list($whereCond, $paramsAtend) = buildDateFilter();
            $whereAtend = $whereCond === '1=1' ? '' : "WHERE $whereCond";

            $stmt = $pdo->prepare("
                SELECT 
                    atendente,
                    COUNT(*) as total_pedidos,
                    SUM(preco_liquido) as faturamento,
                    COUNT(DISTINCT cliente) as clientes_atendidos,
                    AVG(preco_liquido) as ticket_medio
                FROM gestao_pedidos 
                $whereAtend
                GROUP BY atendente
                HAVING atendente != '' AND atendente IS NOT NULL
                ORDER BY faturamento DESC
                LIMIT 10
            ");
            foreach ($paramsAtend as $k => $v)
                $stmt->bindValue(":$k", $v);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // CANAL DE ATENDIMENTO
        // ============================================
        case 'canais':
            list($whereCond, $paramsCan) = buildDateFilter();
            $whereCan = $whereCond === '1=1' ? '' : "WHERE $whereCond";

            $stmt = $pdo->prepare("
                SELECT 
                    canal_atendimento,
                    COUNT(*) as total,
                    SUM(preco_liquido) as faturamento
                FROM gestao_pedidos 
                $whereCan
                GROUP BY canal_atendimento
                ORDER BY faturamento DESC
            ");
            foreach ($paramsCan as $k => $v)
                $stmt->bindValue(":$k", $v);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // VISITADORES - PERFORMANCE
        // Quando mes é informado: usa gestao_pedidos (filtro por mês). Senão: prescritor_resumido (ano).
        // ============================================
        case 'visitadores':
            $ano = $_GET['ano'] ?? null;
            $mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int)$_GET['mes'] : null;

            if ($mes !== null) {
                // Filtro por mês: dados de gestao_pedidos (data_aprovacao) + vínculo prescritor→visitador
                $anoVis = $ano ? (int)$ano : (int)date('Y');
                $sqlVis = "
                    SELECT 
                        COALESCE(NULLIF(TRIM(pc.visitador), ''), 'My Pharm') AS visitador,
                        SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END) AS total_valor_aprovado,
                        SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN 1 ELSE 0 END) AS total_aprovados,
                        SUM(CASE WHEN gp.status_financeiro = 'Recusado' THEN gp.preco_liquido ELSE 0 END) AS total_valor_recusado,
                        SUM(CASE WHEN gp.status_financeiro = 'Recusado' THEN 1 ELSE 0 END) AS total_recusados,
                        0 AS total_no_carrinho,
                        0 AS total_valor_carrinho,
                        COUNT(DISTINCT TRIM(COALESCE(gp.prescritor, ''))) AS total_prescritores_ano
                    FROM gestao_pedidos gp
                    LEFT JOIN prescritores_cadastro pc ON UPPER(TRIM(COALESCE(gp.prescritor, ''))) = UPPER(TRIM(pc.nome))
                    WHERE gp.ano_referencia = :anoVis AND MONTH(gp.data_aprovacao) = :mesVis
                    GROUP BY visitador
                    HAVING visitador IS NOT NULL AND visitador != ''
                    ORDER BY total_valor_aprovado DESC
                ";
                $stmt = $pdo->prepare($sqlVis);
                $stmt->execute(['anoVis' => $anoVis, 'mesVis' => $mes]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $whereAno = $ano ? "WHERE ano_referencia = :ano" : "";
                $stmt = $pdo->prepare("
                    SELECT 
                        visitador,
                        SUM(aprovados) as total_aprovados,
                        SUM(valor_aprovado) as total_valor_aprovado,
                        SUM(recusados) as total_recusados,
                        SUM(valor_recusado) as total_valor_recusado,
                        SUM(no_carrinho) as total_no_carrinho,
                        SUM(valor_no_carrinho) as total_valor_carrinho,
                        COUNT(DISTINCT nome) as total_prescritores_ano
                    FROM prescritor_resumido 
                    $whereAno
                    GROUP BY visitador
                    HAVING visitador != '' AND visitador IS NOT NULL
                    ORDER BY total_valor_aprovado DESC
                ");
                if ($ano)
                    $stmt->bindParam(':ano', $ano, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Prescritores na carteira = cadastro único (prescritores_cadastro), não por ano
            $stmtCarteira = $pdo->query("
                SELECT 
                    CASE 
                        WHEN (visitador IS NULL OR visitador = '' OR UPPER(TRIM(visitador)) = 'MY PHARM') THEN 'My Pharm'
                        ELSE visitador
                    END AS visitador,
                    COUNT(*) AS total
                FROM prescritores_cadastro
                GROUP BY CASE 
                    WHEN (visitador IS NULL OR visitador = '' OR UPPER(TRIM(visitador)) = 'MY PHARM') THEN 'My Pharm'
                    ELSE visitador
                END
            ");
            $carteira = [];
            while ($r = $stmtCarteira->fetch(PDO::FETCH_ASSOC)) {
                $carteira[$r['visitador']] = (int) $r['total'];
            }

            foreach ($rows as &$row) {
                $v = $row['visitador'];
                $row['total_prescritores'] = $carteira[$v] ?? 0;
            }
            unset($row);
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // VISITADOR DASHBOARD (DADOS PESSOAIS)
        // ============================================
        case 'visitador_dashboard':
            $nome = $_GET['nome'] ?? '';
            $ano = $_GET['ano'] ?? date('Y');
            $mes = $_GET['mes'] ?? '';
            $dia = $_GET['dia'] ?? '';

            if (!$nome) {
                echo json_encode(['error' => 'Nome do visitador não fornecido']);
                break;
            }

            // Filtro de mês e dia para queries (quando selecionado)
            $filtroMes = $mes ? "AND MONTH(gp.data_aprovacao) = :mes" : "";
            $filtroDia = ($dia && $mes && $ano) ? "AND DATE(gp.data_aprovacao) = :data_filtro" : "";
            $dataFiltroVis = ($dia && $mes && $ano) ? sprintf('%04d-%02d-%02d', (int)$ano, (int)$mes, (int)$dia) : null;
            $filtroMesPresc = $mes ? "AND MONTH(gp.data_aprovacao) = :mes" : "";

            // Lógica para My Pharm (visitador vazio ou NULL ou literal 'My Pharm')
            $nomeLimpo = trim($nome);
            $isMyPharm = (strcasecmp($nomeLimpo, 'My Pharm') === 0);

            $visitadorWhere = "TRIM(COALESCE(visitador, '')) = TRIM(:nome)";
            $visitadorWherePr = "TRIM(COALESCE(pr.visitador, '')) = TRIM(:nome)";
            $visitadorWhereP = "TRIM(COALESCE(p.visitador, '')) = TRIM(:nome)";
            $visitadorWhereVis = "TRIM(COALESCE(visitador, '')) = TRIM(:nome)";

            if ($isMyPharm) {
                $visitadorWhere = "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')";
                $visitadorWherePr = "(pr.visitador IS NULL OR TRIM(pr.visitador) = '' OR LOWER(TRIM(pr.visitador)) = 'my pharm')";
                $visitadorWhereP = "(p.visitador IS NULL OR TRIM(p.visitador) = '' OR LOWER(TRIM(p.visitador)) = 'my pharm')";
                $visitadorWhereVis = "(visitador IS NULL OR TRIM(visitador) = '' OR LOWER(TRIM(visitador)) = 'my pharm')";
            }

            // Total prescritores: 1 por pessoa (mesmo que tenha orçamento em vários anos).
            // Fonte: prescritores_cadastro (cadastro único), filtrado por visitador.
            $sqlCarteira = "SELECT COUNT(*) as total FROM prescritores_cadastro pc WHERE 1=1";
            if ($isMyPharm) {
                $sqlCarteira .= " AND (pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')";
            } else {
                $sqlCarteira .= " AND pc.visitador = :nome";
            }
            $stmtCarteira = $pdo->prepare($sqlCarteira);
            $stmtCarteira->execute($isMyPharm ? [] : ['nome' => $nome]);
            $total_prescritores = (int)$stmtCarteira->fetchColumn();

            // Recusados e no carrinho: soma de prescritor_resumido
            $sqlKpi = "
                SELECT 
                    COALESCE(SUM(pr.recusados), 0) as total_recusados,
                    COALESCE(SUM(pr.no_carrinho), 0) as total_no_carrinho
                FROM prescritor_resumido pr
                WHERE pr.ano_referencia = :ano
            ";
            if (!$isMyPharm)
                $sqlKpi .= " AND $visitadorWherePr";

            $stmt = $pdo->prepare($sqlKpi);
            $qKpi = ['ano' => $ano];
            if (!$isMyPharm)
                $qKpi['nome'] = $nome;
            $stmt->execute($qKpi);
            $kpiRow = $stmt->fetch();
            $total_recusados = $kpiRow['total_recusados'] ?? 0;
            $total_no_carrinho = $kpiRow['total_no_carrinho'] ?? 0;

            // === QUERY 2: Vendas consolidadas (anual SEM filtro de mês; mensal por CASE; último mês) ===
            // Meta Anual e total_anual devem ser sempre do ano inteiro, sem interferência do filtro mensal
            $sqlVendas = "
                SELECT 
                    SUM(gp.preco_liquido) as total_anual,
                    SUM(CASE WHEN MONTH(gp.data_aprovacao) = :mesRef THEN gp.preco_liquido ELSE 0 END) as total_mensal,
                    MAX(MONTH(gp.data_aprovacao)) as ultimo_mes,
                    MAX(gp.data_aprovacao) as ultima_atualizacao
                FROM gestao_pedidos gp
                LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                WHERE gp.ano_referencia = :ano2
                  AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
            ";
            if ($isMyPharm) {
                $sqlVendas .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
            }
            else {
                $sqlVendas .= " AND $visitadorWherePr";
            }
            $stmt = $pdo->prepare($sqlVendas);

            $mesRef = $mes ?: date('m');
            $qVendas = ['ano1' => $ano, 'ano2' => $ano, 'mesRef' => (int)$mesRef];
            if (!$isMyPharm)
                $qVendas['nome'] = $nome;
            $stmt->execute($qVendas);
            $vendasRow = $stmt->fetch();
            $total_aprovado_anual = $vendasRow['total_anual'] ?: 0;
            $total_aprovado_mensal = $vendasRow['total_mensal'] ?: 0;
            $ultimo_mes = $vendasRow['ultimo_mes'] ?: date('m');
            $ultima_atualizacao = $vendasRow['ultima_atualizacao'];

            // Total com filtro de mês (para KPI)
            if ($mes) {
                $total_aprovado = $total_aprovado_mensal;
            }
            else {
                $total_aprovado = $total_aprovado_anual;
            }

            // Buscar Metas do Usuário
            $stmtUser = $pdo->prepare("SELECT meta_mensal, meta_anual, comissao_percentual, meta_visitas_semana, meta_visitas_mes, premio_visitas FROM usuarios WHERE nome = :nome LIMIT 1");
            $stmtUser->execute(['nome' => $nome]);
            $userData = $stmtUser->fetch();

            $meta_mensal = $userData ? ($userData['meta_mensal'] ?? 50000) : 50000;
            $meta_anual = $userData ? ($userData['meta_anual'] ?? 600000) : 600000;
            $comissao = $userData ? ($userData['comissao_percentual'] ?? 1) : 1;
            $metaVisitaSemanaActual = $userData ? ($userData['meta_visitas_semana'] ?? 30) : 30;
            $metaVisitasMensalActual = $userData ? ($userData['meta_visitas_mes'] ?? 100) : 100;
            $valorPremioActual = $userData ? ($userData['premio_visitas'] ?? 600.00) : 600.00;

            $kpis = [
                'total_aprovado_anual' => $total_aprovado_anual,
                'total_aprovado_mensal' => $total_aprovado_mensal,
                'total_prescritores' => $total_prescritores,
                'total_recusados' => $total_recusados,
                'total_no_carrinho' => $total_no_carrinho,
                'meta_mensal' => $meta_mensal,
                'meta_anual' => $meta_anual,
                'comissao' => $comissao,
                'ultimo_mes_com_dados' => intval($ultimo_mes),
                'ultima_atualizacao' => $ultima_atualizacao
            ];

            // === QUERY 3: Última visita por prescritor (pré-carregada) ===
            $stmtVisitas = $pdo->prepare("
                SELECT prescritor, MAX(data_visita) as ultima_visita
                FROM historico_visitas
                WHERE $visitadorWhere
                GROUP BY prescritor
            ");
            $qVis = [];
            if (!$isMyPharm)
                $qVis['nome'] = $nome;
            $stmtVisitas->execute($qVis);
            $visitasMap = [];
            while ($vr = $stmtVisitas->fetch()) {
                $visitasMap[$vr['prescritor']] = $vr['ultima_visita'];
            }

            // Listar TODOS os prescritores da carteira (prescritor_resumido),
            // não só os que têm pedidos — para o modal mostrar 74 e não apenas 45
            $sqlPresc = "
                SELECT 
                    pr.nome as prescritor,
                    COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) as total_aprovado,
                    COUNT(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN 1 END) as qtd_aprovados,
                    (COALESCE(pr.valor_recusado, 0) + COALESCE(pr.valor_no_carrinho, 0)) as total_recusado,
                    (COALESCE(pr.recusados, 0) + COALESCE(pr.no_carrinho, 0)) as qtd_recusados,
                    MAX(gp.data_aprovacao) as ultima_compra,
                    DATEDIFF(NOW(), MAX(gp.data_aprovacao)) as dias_sem_compra
                FROM prescritor_resumido pr
                LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome 
                    AND gp.ano_referencia = :ano2
                    AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                    $filtroMesPresc
                    $filtroDia
                WHERE pr.ano_referencia = :ano1
            ";
            if ($isMyPharm) {
                $sqlPresc .= " AND ($visitadorWherePr)";
            }
            else {
                $sqlPresc .= " AND $visitadorWherePr";
            }
            $sqlPresc .= " GROUP BY pr.nome, pr.valor_recusado, pr.valor_no_carrinho, pr.recusados, pr.no_carrinho
                          ORDER BY total_aprovado DESC";

            $stmt = $pdo->prepare($sqlPresc);
            $paramsPresc = ['ano1' => $ano, 'ano2' => $ano];
            if (!$isMyPharm)
                $paramsPresc['nome'] = $nome;
            if ($mes)
                $paramsPresc['mes'] = (int)$mes;
            if ($dataFiltroVis)
                $paramsPresc['data_filtro'] = $dataFiltroVis;
            $stmt->execute($paramsPresc);
            $top_prescritores_raw = $stmt->fetchAll();

            // Adicionar ultima_visita do mapa pré-carregado
            $top_prescritores = [];
            foreach ($top_prescritores_raw as $tp) {
                $tp['ultima_visita'] = $visitasMap[$tp['prescritor']] ?? null;
                $top_prescritores[] = $tp;
            }

            // === QUERY 5: Evolução Mensal + Top Produtos + Alertas (usando subquery única para nomes) ===
            // Evolução Mensal
            $sqlEv = "
                SELECT 
                    MONTH(gp.data_aprovacao) as mes,
                    SUM(gp.preco_liquido) as total
                FROM gestao_pedidos gp
                LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                WHERE gp.ano_referencia = :ano2 
                AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                AND gp.data_aprovacao IS NOT NULL
                $filtroMes
                $filtroDia
            ";
            if ($isMyPharm) {
                $sqlEv .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
            }
            else {
                $sqlEv .= " AND $visitadorWherePr";
            }
            $sqlEv .= " GROUP BY mes ORDER BY mes";

            $stmt = $pdo->prepare($sqlEv);
            $qEv = ['ano1' => $ano, 'ano2' => $ano];
            if (!$isMyPharm)
                $qEv['nome'] = $nome;
            if ($mes)
                $qEv['mes'] = (int)$mes;
            if ($dataFiltroVis)
                $qEv['data_filtro'] = $dataFiltroVis;
            $stmt->execute($qEv);
            $evolucao = $stmt->fetchAll();

            // Top Famílias de Produtos
            $sqlProd = "
                SELECT 
                    gp.produto as familia,
                    SUM(gp.preco_liquido) as total
                FROM gestao_pedidos gp
                LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                WHERE gp.ano_referencia = :ano2 
                AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                $filtroMesPresc
                $filtroDia
            ";
            if ($isMyPharm) {
                $sqlProd .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
            }
            else {
                $sqlProd .= " AND $visitadorWherePr";
            }
            $sqlProd .= " GROUP BY gp.produto ORDER BY total DESC LIMIT 5";

            $stmt = $pdo->prepare($sqlProd);
            $paramsProd = ['ano1' => $ano, 'ano2' => $ano];
            if (!$isMyPharm)
                $paramsProd['nome'] = $nome;
            if ($mes)
                $paramsProd['mes'] = (int)$mes;
            if ($dataFiltroVis)
                $paramsProd['data_filtro'] = $dataFiltroVis;
            $stmt->execute($paramsProd);
            $top_produtos = $stmt->fetchAll();

            // Especialidades dos prescritores da carteira (profissão) — para o gráfico no painel do visitador
            $sqlEsp = "
                SELECT 
                    COALESCE(NULLIF(TRIM(pr.profissao), ''), 'Não informada') as familia,
                    COUNT(*) as total
                FROM prescritor_resumido pr
                WHERE pr.ano_referencia = :ano
            ";
            if ($isMyPharm) {
                $sqlEsp .= " AND (pr.visitador IS NULL OR TRIM(pr.visitador) = '' OR LOWER(TRIM(pr.visitador)) = 'my pharm')";
            } else {
                $sqlEsp .= " AND TRIM(COALESCE(pr.visitador, '')) = TRIM(:nome)";
            }
            $sqlEsp .= " GROUP BY COALESCE(NULLIF(TRIM(pr.profissao), ''), 'Não informada') ORDER BY total DESC LIMIT 10";
            $stmtEsp = $pdo->prepare($sqlEsp);
            $stmtEsp->execute($isMyPharm ? ['ano' => $ano] : ['ano' => $ano, 'nome' => $nome]);
            $top_especialidades = $stmtEsp->fetchAll(PDO::FETCH_ASSOC);

            // Alertas inteligentes: prescritores com potencial (valor aprovado) mas inativos OU abaixo da média do ano anterior
            // Média ano anterior = total comprado no ano anterior / 12. "Abaixo" = YTD atual < média * meses decorridos
            $ano_anterior = (int)$ano - 1;
            $mes_ytd = $mes ? (int)$mes : (int)date('n');
            $sqlAlerts = "
                SELECT 
                    sub.prescritor,
                    sub.valor_total_aprovado,
                    sub.ultima_compra,
                    sub.dias_sem_compra,
                    sub.ultima_visita,
                    sub.dias_sem_visita,
                    sub.total_pedidos,
                    sub.total_ano_anterior,
                    sub.total_ano_atual_ytd,
                    ROUND(
                        (LEAST(sub.valor_total_aprovado, 100000) / 100000) * 40
                        + LEAST(sub.dias_sem_compra, 365) / 365 * 35
                        + LEAST(COALESCE(sub.dias_sem_visita, 365), 365) / 365 * 25
                    , 1) as score
                FROM (
                    SELECT 
                        COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') as prescritor,
                        SUM(gp.preco_liquido) as valor_total_aprovado,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(NOW(), MAX(gp.data_aprovacao)) as dias_sem_compra,
                        COUNT(*) as total_pedidos,
                        (SELECT MAX(hv.data_visita) 
                         FROM historico_visitas hv 
                         WHERE hv.prescritor = COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
                           AND hv.data_visita IS NOT NULL
                        ) as ultima_visita,
                        DATEDIFF(NOW(), (
                            SELECT MAX(hv2.data_visita) 
                            FROM historico_visitas hv2 
                            WHERE hv2.prescritor = COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
                              AND hv2.data_visita IS NOT NULL
                        )) as dias_sem_visita,
                        SUM(CASE WHEN gp.ano_referencia = :ano_ant AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') AND gp.preco_liquido > 0 THEN gp.preco_liquido ELSE 0 END) as total_ano_anterior,
                        SUM(CASE WHEN gp.ano_referencia = :ano_ytd AND MONTH(gp.data_aprovacao) <= :mes_ytd AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') AND gp.preco_liquido > 0 THEN gp.preco_liquido ELSE 0 END) as total_ano_atual_ytd
                    FROM gestao_pedidos gp
                    LEFT JOIN prescritor_resumido pr 
                        ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome 
                        AND pr.ano_referencia = :ano1
                    WHERE gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                      AND gp.preco_liquido > 0
            ";
            if ($isMyPharm) {
                $sqlAlerts .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
            } else {
                $sqlAlerts .= " AND $visitadorWherePr";
            }
            $sqlAlerts .= "
                    GROUP BY COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
                    HAVING (
                        (
                            (dias_sem_compra >= 15 OR dias_sem_visita >= 15 OR dias_sem_visita IS NULL)
                            AND dias_sem_compra >= 7
                        )
                        OR (total_ano_anterior >= 1000 AND total_ano_atual_ytd * 12 < total_ano_anterior * :mes_ytd2)
                    )
                ) sub
                WHERE sub.valor_total_aprovado >= 100
                ORDER BY ROUND(
                    (LEAST(sub.valor_total_aprovado, 100000) / 100000) * 40
                    + LEAST(sub.dias_sem_compra, 365) / 365 * 35
                    + LEAST(COALESCE(sub.dias_sem_visita, 365), 365) / 365 * 25
                , 1) DESC
                LIMIT 12
            ";
            $stmt = $pdo->prepare($sqlAlerts);
            $qAlerts = ['ano1' => $ano, 'ano_ant' => $ano_anterior, 'ano_ytd' => $ano, 'mes_ytd' => $mes_ytd, 'mes_ytd2' => $mes_ytd];
            if (!$isMyPharm) $qAlerts['nome'] = $nome;
            $stmt->execute($qAlerts);
            $alertasRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Enriquecer cada alerta com média mensal ano anterior, média 2026 (YTD) e flag "abaixo_da_media"
            $alertas = [];
            foreach ($alertasRaw as $a) {
                $totAnt = (float)($a['total_ano_anterior'] ?? 0);
                $totYtd = (float)($a['total_ano_atual_ytd'] ?? 0);
                $mediaMensalAnoAnt = $totAnt > 0 ? $totAnt / 12 : 0;
                $esperadoYtd = $mediaMensalAnoAnt * $mes_ytd;
                $a['media_mensal_ano_anterior'] = round($mediaMensalAnoAnt, 2);
                $a['media_mensal_ano_atual'] = $mes_ytd > 0 ? round($totYtd / $mes_ytd, 2) : 0;
                $a['abaixo_da_media'] = $totAnt >= 1000 && $totYtd < $esperadoYtd;
                $a['ano_anterior_ref'] = $ano_anterior;
                $a['ano_atual_ref'] = (int)$ano;
                $alertas[] = $a;
            }

            // Metas de Visitas e Premiação (Usando valores reais do banco)
            $metaVisitaSemana = $metaVisitaSemanaActual;
            $metaVisitasMensal = $metaVisitasMensalActual;
            $valorPremio = $valorPremioActual;

            // Visitas Mês Selecionado (usa mês atual quando não filtrado)
            $mesRefVisitas = $mes ? (int)$mes : (int)date('m');
            $anoRefVisitas = $mes ? (int)$ano : (int)date('Y');
            $stmtVisitasMes = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas WHERE data_visita IS NOT NULL AND $visitadorWhereVis AND YEAR(data_visita) = :y AND MONTH(data_visita) = :m");
            $qVMes = ['y' => $anoRefVisitas, 'm' => $mesRefVisitas];
            if (!$isMyPharm)
                $qVMes['nome'] = $nome;
            $stmtVisitasMes->execute($qVMes);
            $visitasMes = (int)$stmtVisitasMes->fetchColumn();

            // Fallback mês: prescritores distintos com vendas aprovadas no mês
            $sqlFallbackMes = "
                SELECT COUNT(DISTINCT COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm'))
                FROM gestao_pedidos gp
                LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                WHERE gp.ano_referencia = :ano2
                  AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                  AND gp.data_aprovacao IS NOT NULL
                  AND MONTH(gp.data_aprovacao) = :m AND YEAR(gp.data_aprovacao) = :y
            ";
            if ($isMyPharm) {
                $sqlFallbackMes .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
            }
            else {
                $sqlFallbackMes .= " AND $visitadorWherePr";
            }
            $stmtFallbackMes = $pdo->prepare($sqlFallbackMes);
            $qFallbackMes = ['ano1' => $anoRefVisitas, 'ano2' => $anoRefVisitas, 'm' => $mesRefVisitas, 'y' => $anoRefVisitas];
            if (!$isMyPharm)
                $qFallbackMes['nome'] = $nome;
            $stmtFallbackMes->execute($qFallbackMes);
            $fallbackMes = (int)$stmtFallbackMes->fetchColumn();
            $visitasMes = max($visitasMes, $fallbackMes);

            // Visitas na semana atual = quantidade de registros em historico_visitas (semana ISO: seg–dom)
            $stmtVisitasSemana = $pdo->prepare("
                SELECT COUNT(*) FROM historico_visitas 
                WHERE data_visita IS NOT NULL 
                  AND $visitadorWhereVis 
                  AND YEARWEEK(data_visita, 1) = YEARWEEK(CURDATE(), 1)
            ");
            $qVSem = [];
            if (!$isMyPharm)
                $qVSem['nome'] = $nome;
            $stmtVisitasSemana->execute($qVSem);
            $visitasSemana = (int)$stmtVisitasSemana->fetchColumn();

            // Fallback: se historico_visitas vazio, usar prescritores distintos com vendas aprovadas na semana atual
            $sqlFallbackSemana = "
                SELECT COUNT(DISTINCT COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm'))
                FROM gestao_pedidos gp
                LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome AND pr.ano_referencia = :ano1
                WHERE gp.ano_referencia = :ano2
                  AND gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                  AND gp.data_aprovacao IS NOT NULL
                  AND YEARWEEK(gp.data_aprovacao, 1) = YEARWEEK(CURDATE(), 1)
            ";
            if ($isMyPharm) {
                $sqlFallbackSemana .= " AND ($visitadorWherePr OR pr.nome IS NULL)";
            }
            else {
                $sqlFallbackSemana .= " AND $visitadorWherePr";
            }
            $stmtFallback = $pdo->prepare($sqlFallbackSemana);
            $stmtFallback->execute($isMyPharm ? ['ano1' => $ano, 'ano2' => $ano] : ['ano1' => $ano, 'ano2' => $ano, 'nome' => $nome]);
            $fallbackSemana = (int)$stmtFallback->fetchColumn();
            $visitasSemana = max($visitasSemana, $fallbackSemana);

            // Métrica analítica: visitas por semana
            // - Se houver filtro de mês, listar apenas semanas que COMEÇAM dentro do mês selecionado
            // - Sem filtro de mês, mostrar as últimas 4 semanas (contínuo)
            $stmtVps = $pdo->prepare("
                SELECT COUNT(*) FROM historico_visitas 
                WHERE data_visita IS NOT NULL AND $visitadorWhereVis
                  AND YEARWEEK(data_visita, 1) = :yw
            ");
            $visitasPorSemana = [];

            if ($mes) {
                // No filtro mensal: sempre 4 blocos (mesmo se o mês tiver 5 segundas/semanas)
                // 01–07, 08–14, 15–21, 22–último dia do mês
                $ini = new DateTime(sprintf('%04d-%02d-01', (int)$anoRefVisitas, (int)$mesRefVisitas));
                $fim = (clone $ini)->modify('last day of this month');
                $ultimoDia = (int)$fim->format('d');

                $ranges = [
                    [1, 7],
                    [8, 14],
                    [15, 21],
                    [22, $ultimoDia],
                ];

                $stmtVpsRange = $pdo->prepare("
                    SELECT COUNT(*) FROM historico_visitas
                    WHERE data_visita IS NOT NULL AND $visitadorWhereVis
                      AND data_visita >= :ini AND data_visita <= :fim
                ");

                foreach ($ranges as $rg) {
                    [$dIni, $dFim] = $rg;
                    $dtIni = new DateTime(sprintf('%04d-%02d-%02d', (int)$anoRefVisitas, (int)$mesRefVisitas, $dIni));
                    $dtFim = new DateTime(sprintf('%04d-%02d-%02d', (int)$anoRefVisitas, (int)$mesRefVisitas, $dFim));

                    $qVps = ['ini' => $dtIni->format('Y-m-d'), 'fim' => $dtFim->format('Y-m-d')];
                    if (!$isMyPharm) $qVps['nome'] = $nome;
                    $stmtVpsRange->execute($qVps);
                    $total = (int)$stmtVpsRange->fetchColumn();

                    // status do bloco para UI: future (ainda não começou), current (em andamento), past (já terminou)
                    $hoje = new DateTime(date('Y-m-d'));
                    $status = 'past';
                    if ($hoje < $dtIni) $status = 'future';
                    else if ($hoje <= $dtFim) $status = 'current';

                    $visitasPorSemana[] = [
                        'semana' => $dtIni->format('Y-m-d'),
                        'fim' => $dtFim->format('Y-m-d'),
                        'total' => $total,
                        'label' => sprintf('%02d-%02d', $dIni, $dFim),
                        'status' => $status,
                    ];
                }
            } else {
                for ($i = 0; $i < 4; $i++) {
                    $ts = strtotime("-$i weeks");
                    $yw = (int)date('o', $ts) * 100 + (int)date('W', $ts);
                    $qVps = ['yw' => $yw];
                    if (!$isMyPharm) $qVps['nome'] = $nome;
                    $stmtVps->execute($qVps);
                    $total = (int)$stmtVps->fetchColumn();
                    $segunda = strtotime('monday this week', $ts);
                    $visitasPorSemana[] = [
                        'semana' => date('Y-m-d', $segunda),
                        'total' => $total,
                        'label' => date('d/m', $segunda)
                    ];
                }
            }

            $metaSemanaMin = max(1, (int)$metaVisitaSemana);

            // Se estiver filtrando por mês: a "meta semanal" vira meta por 4 blocos do mês (quantas semanas bateram a meta)
            if ($mes) {
                $semanasBatidas = 0;
                foreach ($visitasPorSemana as &$s) {
                    $s['bateu'] = ((int)$s['total'] >= $metaSemanaMin);
                    if ($s['bateu'])
                        $semanasBatidas++;
                }
                unset($s);

                $visitasSemanaExibir = $semanasBatidas; // ex.: 1
                $metaSemanaExibir = 4; // sempre 4 blocos no mês
                $pctSemanaExibir = min(100, round(($semanasBatidas / 4) * 100)); // ex.: 25%
                $batouVisitasSemana = ($semanasBatidas >= 4); // só bate se bater nas 4
            } else {
                $visitasSemanaExibir = (int)$visitasSemana; // visitas na semana atual
                $metaSemanaExibir = $metaSemanaMin;         // meta semanal do usuário (ex.: 30)
                $pctSemanaExibir = min(100, round(($visitasSemanaExibir / max(1, $metaSemanaExibir)) * 100));
                $batouVisitasSemana = ($visitasSemanaExibir >= (int)$metaVisitaSemana);
            }

            $batouVendas = ($total_aprovado_mensal >= $meta_mensal);
            $batouVisitasMes = ($visitasMes >= $metaVisitasMensal);
            $conquistouPremio = ($batouVendas && $batouVisitasMes && $batouVisitasSemana);

            $kpis_visitas = [
                'semanal' => [
                    'atual' => (int)$visitasSemanaExibir,
                    'meta' => (int)$metaSemanaExibir,
                    'pct' => (int)$pctSemanaExibir
                ],
                'mes' => [
                    'atual' => (int)$visitasMes,
                    'meta' => max(1, (int)$metaVisitasMensal),
                    'pct' => min(100, round(($visitasMes / max(1, $metaVisitasMensal)) * 100))
                ],
                'visitas_por_semana' => $visitasPorSemana,
                'premio' => [
                    'valor' => $valorPremio,
                    'conquistado' => $conquistouPremio,
                    'batouVendas' => $batouVendas,
                    'batouVisitasMes' => $batouVisitasMes,
                    'batouVisitasSemana' => $batouVisitasSemana
                ]
            ];

            // =========================
            // RELATÓRIOS (Semanal + Agendadas)
            // =========================
            // Garantir coluna inicio_visita para duração (se ainda não existir)
            try {
                $pdo->exec("ALTER TABLE historico_visitas ADD COLUMN inicio_visita DATETIME NULL");
            } catch (Exception $e) {
                // Coluna já existe
            }
            // Visitas realizadas na semana atual (seg–dom) + duração quando houver inicio_visita
            $stmt = $pdo->prepare("
                SELECT 
                    hv.id,
                    hv.prescritor,
                    hv.data_visita,
                    hv.horario,
                    hv.inicio_visita,
                    TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) as duracao_minutos,
                    hv.status_visita,
                    hv.local_visita,
                    hv.resumo_visita,
                    hv.amostra,
                    hv.brinde,
                    hv.artigo,
                    hv.reagendado_para,
                    vg.lat  as geo_lat,
                    vg.lng  as geo_lng,
                    vg.accuracy_m as geo_accuracy
                FROM historico_visitas hv
                LEFT JOIN visitas_geolocalizacao vg ON vg.historico_id = hv.id
                WHERE hv.data_visita IS NOT NULL
                  AND " . str_replace('visitador', 'hv.visitador', $visitadorWhereVis) . "
                  AND YEARWEEK(hv.data_visita, 1) = YEARWEEK(CURDATE(), 1)
                ORDER BY hv.data_visita DESC, hv.horario DESC
                LIMIT 50
            ");
            $qSem = [];
            if (!$isMyPharm) $qSem['nome'] = $nome;
            $stmt->execute($qSem);
            $relatorio_visitas_semana = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Visitas agendadas (próximas)
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
            $stmt = $pdo->prepare("
                SELECT 
                    prescritor,
                    data_agendada,
                    DATE_FORMAT(hora, '%H:%i') as hora,
                    status,
                    observacao
                FROM visitas_agendadas
                WHERE $visitadorWhereVis
                  AND status = 'agendada'
                  AND data_agendada >= CURDATE()
                ORDER BY data_agendada ASC, hora ASC
                LIMIT 50
            ");
            $qAg = [];
            if (!$isMyPharm) $qAg['nome'] = $nome;
            $stmt->execute($qAg);
            $visitas_agendadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Também considerar reagendamentos do sistema antigo (historico_visitas.reagendado_para)
            // Aceita formatos comuns: YYYY-MM-DD, YYYY-MM-DD HH:MM, DD/MM/YYYY, DD/MM/YYYY HH:MM
            $expr = "COALESCE(
                STR_TO_DATE(reagendado_para, '%Y-%m-%d %H:%i'),
                STR_TO_DATE(reagendado_para, '%Y-%m-%d'),
                STR_TO_DATE(reagendado_para, '%d/%m/%Y %H:%i'),
                STR_TO_DATE(reagendado_para, '%d/%m/%Y')
            )";
            $stmt = $pdo->prepare("
                SELECT 
                    prescritor,
                    DATE($expr) as data_agendada,
                    DATE_FORMAT(
                        COALESCE(
                            STR_TO_DATE(reagendado_para, '%Y-%m-%d %H:%i'),
                            STR_TO_DATE(reagendado_para, '%d/%m/%Y %H:%i')
                        ), '%H:%i'
                    ) as hora
                FROM historico_visitas
                WHERE data_visita IS NOT NULL
                  AND $visitadorWhereVis
                  AND reagendado_para IS NOT NULL AND TRIM(reagendado_para) != ''
                  AND $expr IS NOT NULL
                  AND DATE($expr) >= CURDATE()
                ORDER BY DATE($expr) ASC
                LIMIT 50
            ");
            $stmt->execute($qAg);
            $agOld = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Deduplicar por prescritor+data+hora
            $map = [];
            foreach ($visitas_agendadas as $r) {
                $k = ($r['prescritor'] ?? '') . '|' . ($r['data_agendada'] ?? '') . '|' . ($r['hora'] ?? '');
                $map[$k] = true;
            }
            foreach ($agOld as $r) {
                if (empty($r['data_agendada'])) continue;
                $row = [
                    'prescritor' => $r['prescritor'] ?? '',
                    'data_agendada' => $r['data_agendada'],
                    'hora' => $r['hora'] ?? null,
                    'status' => 'agendada',
                    'observacao' => null
                ];
                $k = ($row['prescritor'] ?? '') . '|' . ($row['data_agendada'] ?? '') . '|' . ($row['hora'] ?? '');
                if (!isset($map[$k])) {
                    $visitas_agendadas[] = $row;
                    $map[$k] = true;
                }
            }

            // Ordenar resultado final
            usort($visitas_agendadas, function ($a, $b) {
                $da = ($a['data_agendada'] ?? '') . ' ' . ($a['hora'] ?? '');
                $db = ($b['data_agendada'] ?? '') . ' ' . ($b['hora'] ?? '');
                return strcmp($da, $db);
            });

            // ============================
            // Mapa de Visitas (GPS) no período
            // ============================
            // Garantir tabela (para ambientes onde ainda não houve gravação de GPS)
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

            // Contadores do período (todas / realizadas)
            $whereHV = "WHERE data_visita IS NOT NULL AND YEAR(data_visita) = :ano AND $visitadorWhereVis";
            $whereHVReal = $whereHV . " AND status_visita = 'Realizada'";
            if ($mes) {
                $whereHV .= " AND MONTH(data_visita) = :mes";
                $whereHVReal .= " AND MONTH(data_visita) = :mes";
            }
            $qHv = ['ano' => (int)$ano];
            if (!$isMyPharm) $qHv['nome'] = $nome;
            if ($mes) $qHv['mes'] = (int)$mes;

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas $whereHV");
            $stmt->execute($qHv);
            $totalVisitasPeriodo = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM historico_visitas $whereHVReal");
            $stmt->execute($qHv);
            $totalVisitasRealizadas = (int)$stmt->fetchColumn();

            // Visitas com GPS (pontos) no período (por padrão: somente visitas realizadas)
            $visitadorWhereGeo = $isMyPharm
                ? "(vg.visitador IS NULL OR TRIM(vg.visitador) = '' OR LOWER(TRIM(vg.visitador)) = 'my pharm')"
                : "TRIM(COALESCE(vg.visitador, '')) = TRIM(:nome)";
            $whereGeo = "WHERE YEAR(vg.data_visita) = :ano AND $visitadorWhereGeo";
            if ($mes) $whereGeo .= " AND MONTH(vg.data_visita) = :mes";

            $stmt = $pdo->prepare("
                SELECT 
                    vg.lat,
                    vg.lng,
                    vg.accuracy_m,
                    vg.data_visita,
                    DATE_FORMAT(vg.horario, '%H:%i') as horario,
                    vg.prescritor,
                    hv.status_visita,
                    hv.local_visita,
                    hv.resumo_visita
                FROM visitas_geolocalizacao vg
                LEFT JOIN historico_visitas hv ON hv.id = vg.historico_id
                $whereGeo
                  AND (hv.status_visita IS NULL OR hv.status_visita = 'Realizada')
                ORDER BY vg.data_visita ASC, vg.horario ASC, vg.id ASC
                LIMIT 800
            ");
            $stmt->execute($qHv);
            $visitas_mapa = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Distância padrão do período: rotas do dia (GPS contínuo em rotas_pontos)
            $km_rotas_periodo = 0.0;
            try {
                $whereRotaVis = "WHERE YEAR(rd.data_inicio) = :ano_r AND TRIM(COALESCE(rd.visitador_nome, '')) = TRIM(:nome_r)";
                $paramsRotaVis = ['ano_r' => (int)$ano, 'nome_r' => $nome];
                if ($mes) {
                    $whereRotaVis .= " AND MONTH(rd.data_inicio) = :mes_r";
                    $paramsRotaVis['mes_r'] = (int)$mes;
                }

                $stmtRotas = $pdo->prepare("
                    SELECT rd.id
                    FROM rotas_diarias rd
                    $whereRotaVis
                    ORDER BY rd.data_inicio DESC
                    LIMIT 800
                ");
                $stmtRotas->execute($paramsRotaVis);
                $rotasIds = $stmtRotas->fetchAll(PDO::FETCH_COLUMN);

                $haversine = function ($lat1, $lon1, $lat2, $lon2) {
                    $lat1 = (float)$lat1; $lon1 = (float)$lon1; $lat2 = (float)$lat2; $lon2 = (float)$lon2;
                    $R = 6371;
                    $dLat = deg2rad($lat2 - $lat1);
                    $dLon = deg2rad($lon2 - $lon1);
                    $a = sin($dLat / 2) * sin($dLat / 2)
                        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
                    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                    return $R * $c;
                };

                foreach ($rotasIds as $ridRaw) {
                    $rid = (int)$ridRaw;
                    if ($rid <= 0) continue;
                    $stmtP = $pdo->prepare("SELECT lat, lng FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC");
                    $stmtP->execute(['rid' => $rid]);
                    $pts = $stmtP->fetchAll(PDO::FETCH_ASSOC);
                    $n = count($pts);
                    for ($i = 1; $i < $n; $i++) {
                        $km_rotas_periodo += $haversine(
                            $pts[$i - 1]['lat'], $pts[$i - 1]['lng'],
                            $pts[$i]['lat'], $pts[$i]['lng']
                        );
                    }
                }
            } catch (Exception $e) {
                // fallback silencioso: se não conseguir calcular por rota, mantém 0 e frontend usa GPS de visitas
            }

            $visitas_mapa_resumo = [
                'total_visitas_periodo' => $totalVisitasPeriodo,
                'total_visitas_realizadas' => $totalVisitasRealizadas,
                'total_pontos_gps' => is_array($visitas_mapa) ? count($visitas_mapa) : 0,
                'km_rotas_periodo' => round($km_rotas_periodo, 2)
            ];

            $meta = 50000;

            echo json_encode([
                'kpis' => $kpis,
                'kpis_visitas' => $kpis_visitas,
                'relatorio_visitas_semana' => $relatorio_visitas_semana,
                'visitas_agendadas' => $visitas_agendadas,
                'visitas_mapa' => $visitas_mapa,
                'visitas_mapa_resumo' => $visitas_mapa_resumo,
                'top_prescritores' => $top_prescritores,
                'evolucao' => $evolucao,
                'top_produtos' => $top_produtos,
                'top_especialidades' => $top_especialidades,
                'alertas' => $alertas,
                'meta' => $meta
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN: LISTAGEM DE VISITAS (painel principal)
        // ============================================
        case 'admin_visitas':
            if ($userSetor === 'visitador') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.']); exit;
            }
            $anoV = $_GET['ano'] ?? date('Y');
            $mesV = $_GET['mes'] ?? '';
            $visitadorFiltro = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : null;
            if ($visitadorFiltro === '') $visitadorFiltro = null;

            $whereHV = "WHERE hv.data_visita IS NOT NULL AND YEAR(hv.data_visita) = :ano";
            $paramsHV = ['ano' => (int)$anoV];
            if ($mesV !== '') {
                $whereHV .= " AND MONTH(hv.data_visita) = :mes";
                $paramsHV['mes'] = (int)$mesV;
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
                LIMIT 300
            ");
            $stmt->execute($paramsHV);
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($lista, JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // ADMIN: RELATÓRIO VISITAS (totais, por visitador, rotas, km, mapa)
        // ============================================
        case 'admin_visitas_relatorio':
            if ($userSetor === 'visitador') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.']); exit;
            }
            $anoR = (int)($_GET['ano'] ?? date('Y'));
            $mesR = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int)$_GET['mes'] : null;
            $visitadorFiltroR = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : null;
            if ($visitadorFiltroR === '') $visitadorFiltroR = null;

            $whereH = "WHERE data_visita IS NOT NULL AND YEAR(data_visita) = :ano";
            $paramsH = ['ano' => $anoR];
            if ($mesR !== null) {
                $whereH .= " AND MONTH(data_visita) = :mes";
                $paramsH['mes'] = $mesR;
            }
            if ($visitadorFiltroR !== null) {
                $whereH .= " AND TRIM(COALESCE(visitador, '')) = TRIM(:visitador)";
                $paramsH['visitador'] = $visitadorFiltroR;
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

            $totais = [
                'total_visitas_periodo' => $total_visitas_periodo,
                'total_visitas_semana_atual' => $total_visitas_semana_atual
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

            // Rotas no período (rotas_diarias: filtrar por data_inicio no ano/mês)
            $whereR = "WHERE YEAR(rd.data_inicio) = :ano";
            $paramsR = ['ano' => $anoR];
            if ($mesR !== null) {
                $whereR .= " AND MONTH(rd.data_inicio) = :mes";
                $paramsR['mes'] = $mesR;
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
                LIMIT 500
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

            foreach ($rotas_list as $rota) {
                $rid = (int)$rota['id'];
                $stmtP = $pdo->prepare("SELECT lat, lng FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC");
                $stmtP->execute(['rid' => $rid]);
                $pontos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
                $qtd_pontos = count($pontos);
                $km_rota = 0;
                for ($i = 1; $i < $qtd_pontos; $i++) {
                    $km_rota += $haversineKm(
                        $pontos[$i-1]['lat'], $pontos[$i-1]['lng'],
                        $pontos[$i]['lat'], $pontos[$i]['lng']
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
                    'qtd_pontos' => $qtd_pontos
                ];

                $pontos_rotas[] = [
                    'visitador_nome' => $vn,
                    'rota_id' => $rid,
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
                $whereGeo = "WHERE hv.data_visita IS NOT NULL AND YEAR(hv.data_visita) = :ano";
                $paramsGeo = ['ano' => $anoR];
                if ($mesR !== null) {
                    $whereGeo .= " AND MONTH(hv.data_visita) = :mes";
                    $paramsGeo['mes'] = $mesR;
                }
                if ($visitadorFiltroR !== null) {
                    $whereGeo .= " AND TRIM(COALESCE(hv.visitador, '')) = TRIM(:visitador)";
                    $paramsGeo['visitador'] = $visitadorFiltroR;
                }
                $stmt = $pdo->prepare("
                    SELECT 
                        vg.lat, vg.lng,
                        hv.prescritor, hv.visitador as visitador_nome, hv.data_visita,
                        DATE_FORMAT(hv.horario, '%H:%i') as horario,
                        hv.local_visita, hv.status_visita
                    FROM visitas_geolocalizacao vg
                    INNER JOIN historico_visitas hv ON hv.id = vg.historico_id
                    $whereGeo
                      AND vg.lat IS NOT NULL AND vg.lng IS NOT NULL
                    ORDER BY hv.data_visita DESC, hv.horario DESC
                    LIMIT 500
                ");
                $stmt->execute($paramsGeo);
                $pontos_atendimento = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Tabela visitas_geolocalizacao pode não existir ainda
            }

            echo json_encode([
                'totais' => $totais,
                'por_visitador' => $por_visitador,
                'rotas' => $rotas_com_km,
                'pontos_rotas' => $pontos_rotas,
                'pontos_atendimento' => $pontos_atendimento
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
            $ano = $_GET['ano'] ?? null;
            $whereAno = $ano ? "WHERE ano_referencia = :ano" : "";

            $stmt = $pdo->prepare("
                SELECT 
                    status,
                    COUNT(*) as total,
                    SUM(valor_liquido) as valor_total,
                    SUM(valor_bruto) as valor_bruto_total
                FROM itens_orcamentos_pedidos 
                $whereAno
                GROUP BY status
                ORDER BY total DESC
            ");
            if ($ano)
                $stmt->bindParam(':ano', $ano, PDO::PARAM_INT);
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
        // PROFISSÕES DOS PRESCRITORES
        // ============================================
        case 'profissoes':
            $ano = $_GET['ano'] ?? null;
            $whereAno = $ano ? "WHERE ano_referencia = :ano" : "";

            $stmt = $pdo->prepare("
                SELECT 
                    profissao,
                    COUNT(*) as total,
                    SUM(aprovados) as total_aprovados,
                    SUM(valor_aprovado) as valor_total
                FROM prescritor_resumido 
                $whereAno
                GROUP BY profissao
                HAVING profissao != '' AND profissao IS NOT NULL
                ORDER BY valor_total DESC
            ");
            if ($ano)
                $stmt->bindParam(':ano', $ano, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // COMPARATIVO ANUAL
        // ============================================
        case 'comparativo_anual':
            $stmt = $pdo->query("
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
                GROUP BY ano_referencia
                ORDER BY ano_referencia
            ");
            echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // TODOS OS PRESCRITORES
        // ============================================
        case 'all_prescritores':
            $ano = !empty($_GET['ano']) ? $_GET['ano'] : null;
            $mes = !empty($_GET['mes']) ? $_GET['mes'] : null;
            $dia = !empty($_GET['dia']) ? $_GET['dia'] : null;
            $visitadorFilter = isset($_GET['visitador']) ? trim((string)$_GET['visitador']) : null;
            if ($visitadorFilter === '') $visitadorFilter = null;
            $anoUsar = $ano ?: date('Y');
            $useLimit = isset($_GET['limit']) && (int)$_GET['limit'] > 0;
            $limit = $useLimit ? min((int)$_GET['limit'], 2000) : 0;
            $offset = ($useLimit && isset($_GET['offset'])) ? max(0, (int)$_GET['offset']) : 0;

            $filtroMesGp = $mes ? "AND MONTH(gp.data_aprovacao) = :mes" : "";
            $filtroDiaGp = ($dia && $mes && $anoUsar) ? "AND DATE(gp.data_aprovacao) = :data_filtro" : "";
            $dataFiltro = ($dia && $mes && $anoUsar) ? sprintf('%04d-%02d-%02d', (int)$anoUsar, (int)$mes, (int)$dia) : null;

            // Com filtro de visitador: listar TODA a carteira (prescritores_cadastro), com indicadores do ano
            if ($visitadorFilter) {
                $visWhere = ($visitadorFilter === 'My Pharm')
                    ? "(pc.visitador IS NULL OR pc.visitador = '' OR pc.visitador = 'My Pharm' OR UPPER(pc.visitador) = 'MY PHARM')"
                    : "pc.visitador = :vis";

                if ($useLimit) {
                    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM prescritores_cadastro pc WHERE $visWhere");
                    $countParams = [];
                    if ($visitadorFilter !== 'My Pharm') $countParams['vis'] = $visitadorFilter;
                    $countStmt->execute($countParams);
                    $totalRows = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                }

                $sql = "
                    SELECT 
                        pc.nome as prescritor,
                        pc.visitador as visitador,
                        COALESCE(SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END), 0) as valor_aprovado,
                        (COALESCE(MAX(pr.valor_recusado), 0) + COALESCE(MAX(pr.valor_no_carrinho), 0)) as valor_recusado,
                        COUNT(gp.id) as total_pedidos,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(CURDATE(), MAX(gp.data_aprovacao)) as dias_sem_compra,
                        MAX(hv.ultima_visita) as ultima_visita
                    FROM prescritores_cadastro pc
                    LEFT JOIN prescritor_resumido pr ON pr.nome = pc.nome AND pr.ano_referencia = :ano_pr
                    LEFT JOIN gestao_pedidos gp ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome 
                        AND gp.ano_referencia = :ano_gp
                        $filtroMesGp
                        $filtroDiaGp
                    LEFT JOIN (
                        SELECT prescritor, MAX(data_visita) as ultima_visita 
                        FROM historico_visitas GROUP BY prescritor
                    ) hv ON pc.nome = hv.prescritor
                    WHERE $visWhere
                    GROUP BY pc.nome, pc.visitador
                    ORDER BY valor_aprovado DESC
                ";
                if ($useLimit) $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                $stmt = $pdo->prepare($sql);
                $paramsVis = ['ano_gp' => $anoUsar, 'ano_pr' => $anoUsar];
                if ($visitadorFilter !== 'My Pharm')
                    $paramsVis['vis'] = $visitadorFilter;
                if ($mes)
                    $paramsVis['mes'] = (int)$mes;
                if ($dataFiltro)
                    $paramsVis['data_filtro'] = $dataFiltro;
                $stmt->execute($paramsVis);
            }
            else {
                // Sem filtro de visitador: manter lógica original (gestao_pedidos)
                $whereParts = [];
                $params = [];
                if ($ano) {
                    $whereParts[] = "gp.ano_referencia = :ano_ref";
                    $params['ano_ref'] = $ano;
                }
                if ($mes) {
                    $whereParts[] = "MONTH(gp.data_aprovacao) = :mes";
                    $params['mes'] = (int)$mes;
                }
                if ($dia && $mes && $ano) {
                    $params['data_filtro'] = sprintf('%04d-%02d-%02d', (int)$ano, (int)$mes, (int)$dia);
                    $whereParts[] = "DATE(gp.data_aprovacao) = :data_filtro";
                }
                if ($visitadorFilter) {
                    $anoVisCond = $ano ? " AND ano_referencia = :ano_vis" : "";
                    if ($visitadorFilter === 'My Pharm') {
                        $whereParts[] = "COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') IN (
                            SELECT nome FROM prescritor_resumido 
                            WHERE (visitador IS NULL OR visitador = '' OR visitador = 'My Pharm') $anoVisCond
                        )";
                    }
                    else {
                        $whereParts[] = "COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') IN (
                            SELECT nome FROM prescritor_resumido 
                            WHERE visitador = :vis $anoVisCond
                        )";
                        $params['vis'] = $visitadorFilter;
                    }
                    if ($ano)
                        $params['ano_vis'] = $ano;
                }
                $whereSql = count($whereParts) > 0 ? "WHERE " . implode(" AND ", $whereParts) : "";
                $joinAno = $ano ? "AND pr.ano_referencia = :ano_join" : "";
                if ($ano)
                    $params['ano_join'] = $ano;

                if ($useLimit) {
                    $countSql = "SELECT COUNT(*) as total FROM (
                        SELECT COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') as p
                        FROM gestao_pedidos gp
                        LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome $joinAno
                        $whereSql
                        GROUP BY COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
                    ) _cnt";
                    $countStmt = $pdo->prepare($countSql);
                    $countStmt->execute($params);
                    $totalRows = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                }

                $sql = "
                    SELECT 
                        COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') as prescritor,
                        MAX(pr.visitador) as visitador,
                        SUM(CASE WHEN gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento') THEN gp.preco_liquido ELSE 0 END) as valor_aprovado,
                        (COALESCE(MAX(pr.valor_recusado), 0) + COALESCE(MAX(pr.valor_no_carrinho), 0)) as valor_recusado,
                        COUNT(*) as total_pedidos,
                        MAX(gp.data_aprovacao) as ultima_compra,
                        DATEDIFF(CURDATE(), MAX(gp.data_aprovacao)) as dias_sem_compra,
                        MAX(hv.ultima_visita) as ultima_visita
                    FROM gestao_pedidos gp
                    LEFT JOIN prescritor_resumido pr ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pr.nome $joinAno
                    LEFT JOIN (
                        SELECT prescritor, MAX(data_visita) as ultima_visita 
                        FROM historico_visitas GROUP BY prescritor
                    ) hv ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = hv.prescritor
                    $whereSql
                    GROUP BY COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')
                    ORDER BY valor_aprovado DESC
                ";
                if ($useLimit) $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            $data = $stmt->fetchAll();

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
                $results[] = $row;
            }

            if (ob_get_length())
                ob_clean();
            if ($useLimit) {
                $out = ['total' => isset($totalRows) ? $totalRows : count($results), 'data' => $results];
                echo json_encode($out, JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode($results, JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'list_visitadores':
            $stmt = $pdo->query("SELECT DISTINCT visitador FROM prescritor_resumido WHERE visitador IS NOT NULL AND visitador != '' ORDER BY visitador ASC");
            $data = $stmt->fetchAll();
            // Remover "My Pharm" da lista (pode vir do banco) e colocar uma vez no início
            $data = array_values(array_filter($data, function ($r) {
                return strcasecmp(trim($r['visitador'] ?? ''), 'My Pharm') !== 0;
            }));
            array_unshift($data, ['visitador' => 'My Pharm']);
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

                $pdo->prepare("UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id")
                    ->execute(['id' => $user['id']]);

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
                    'csrf_token' => getCsrfToken()
                ]);
            } else {
                recordLoginAttempt($pdo, $clientIp);
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
                if (strlen($senhaNova) < 6) {
                    echo json_encode(['success' => false, 'error' => 'A nova senha deve ter no mínimo 6 caracteres.'], JSON_UNESCAPED_UNICODE);
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

        case 'logout':
            // Limpar todas as variáveis de sessão
            $_SESSION = [];
            // Destruir o cookie de sessão
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
        // PRESCRITOR - SALVAR WHATSAPP
        // ============================================
        case 'save_prescritor_whatsapp':
            $input = json_decode(file_get_contents('php://input'), true);
            $nome = trim($input['nome_prescritor'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? '');

            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome do prescritor não fornecido']);
                break;
            }

            $stmt = $pdo->prepare("
                INSERT INTO prescritor_contatos (nome_prescritor, whatsapp) 
                VALUES (:nome, :whatsapp)
                ON DUPLICATE KEY UPDATE whatsapp = :whatsapp2, atualizado_em = NOW()
            ");
            $stmt->execute(['nome' => $nome, 'whatsapp' => $whatsapp, 'whatsapp2' => $whatsapp]);
            echo json_encode(['success' => true, 'message' => 'WhatsApp salvo com sucesso!'], JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // PRESCRITOR - BUSCAR CONTATOS
        // ============================================
        case 'get_prescritor_contatos':
            $stmt = $pdo->query("SELECT nome_prescritor, whatsapp FROM prescritor_contatos WHERE whatsapp IS NOT NULL AND whatsapp != ''");
            $contatos = [];
            foreach ($stmt->fetchAll() as $row) {
                $contatos[$row['nome_prescritor']] = $row['whatsapp'];
            }
            echo json_encode($contatos, JSON_UNESCAPED_UNICODE);
            break;

        // ============================================
        // PRESCRITOR - DADOS COMPLETOS (edição visitador)
        // ============================================
        case 'get_prescritor_dados': {
            $nome = trim($_GET['nome_prescritor'] ?? '');
            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome não informado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS prescritor_dados (
                    nome_prescritor VARCHAR(255) PRIMARY KEY,
                    profissao VARCHAR(255) NULL,
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
                    atualizado_em DATETIME NULL
                )
            ");
            $stmt = $pdo->prepare("SELECT * FROM prescritor_dados WHERE nome_prescritor = :nome LIMIT 1");
            $stmt->execute(['nome' => $nome]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $dados = $row ?: [
                'nome_prescritor' => $nome,
                'profissao' => '',
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
                'whatsapp' => '',
                'email' => ''
            ];
            if ($row) {
                $dados['profissao'] = $row['profissao'] ?? '';
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
                $dados['whatsapp'] = $row['whatsapp'] ?? '';
                $dados['email'] = $row['email'] ?? '';
            }
            $visitador = (strtolower($_SESSION['user_setor'] ?? '') === 'visitador') ? trim($_SESSION['user_nome'] ?? '') : trim($_GET['visitador'] ?? '');
            $ano = (int)($_GET['ano'] ?? date('Y'));
            $mes = isset($_GET['mes']) && $_GET['mes'] !== '' ? (int)$_GET['mes'] : null;
            $whereVis = $visitador !== '' ? " AND pc.visitador = :vis" : "";
            if ($mes !== null) {
                // Mês selecionado: aprovados e recusados direto de gestao_pedidos (respeita ano + mês)
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
                if ($visitador !== '') $paramsKpi['vis'] = $visitador;
                $stmtKpi->execute($paramsKpi);
            } else {
                // Sem mês: ano inteiro (aprovados de gp, recusados de prescritor_resumido)
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
                if ($visitador !== '') $paramsKpi['vis'] = $visitador;
                $stmtKpi->execute($paramsKpi);
            }
            $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);
            $dados['aprovados'] = $kpi ? number_format((float)($kpi['valor_aprovado'] ?? 0), 2, ',', '.') : '0,00';
            $dados['recusados'] = $kpi ? number_format((float)($kpi['valor_recusado'] ?? 0), 2, ',', '.') : '0,00';
            echo json_encode(['success' => true, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
            break;
        }
        case 'update_prescritor_dados': {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $nome = trim($input['nome_prescritor'] ?? '');
            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome não informado'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS prescritor_dados (
                    nome_prescritor VARCHAR(255) PRIMARY KEY,
                    profissao VARCHAR(255) NULL,
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
                    atualizado_em DATETIME NULL
                )
            ");
            $stmt = $pdo->prepare("
                INSERT INTO prescritor_dados (nome_prescritor, profissao, registro, uf_registro, data_nascimento, endereco_rua, endereco_numero, endereco_bairro, endereco_cep, endereco_cidade, endereco_uf, local_atendimento, whatsapp, email, atualizado_em)
                VALUES (:nome, :profissao, :registro, :uf_registro, :data_nascimento, :endereco_rua, :endereco_numero, :endereco_bairro, :endereco_cep, :endereco_cidade, :endereco_uf, :local_atendimento, :whatsapp, :email, NOW())
                ON DUPLICATE KEY UPDATE
                    profissao = VALUES(profissao), registro = VALUES(registro), uf_registro = VALUES(uf_registro), data_nascimento = VALUES(data_nascimento),
                    endereco_rua = VALUES(endereco_rua), endereco_numero = VALUES(endereco_numero), endereco_bairro = VALUES(endereco_bairro), endereco_cep = VALUES(endereco_cep),
                    endereco_cidade = VALUES(endereco_cidade), endereco_uf = VALUES(endereco_uf), local_atendimento = VALUES(local_atendimento), whatsapp = VALUES(whatsapp), email = VALUES(email), atualizado_em = NOW()
            ");
            $stmt->execute([
                'nome' => $nome,
                'profissao' => trim($input['profissao'] ?? ''),
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
                'email' => trim($input['email'] ?? '')
            ]);
            $pdo->prepare("INSERT INTO prescritor_contatos (nome_prescritor, whatsapp) VALUES (:n, :w) ON DUPLICATE KEY UPDATE whatsapp = :w2, atualizado_em = NOW()")->execute(['n' => $nome, 'w' => trim($input['whatsapp'] ?? ''), 'w2' => trim($input['whatsapp'] ?? '')]);
            echo json_encode(['success' => true, 'message' => 'Dados salvos com sucesso!'], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ============================================
        // VISITAS - FLUXO (iniciar/encerrar)
        // ============================================
        case 'visita_ativa':
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
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
                WHERE visitador = :v AND status = 'iniciada' AND fim IS NULL
                ORDER BY inicio DESC
                LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['active' => $row ?: null], JSON_UNESCAPED_UNICODE);
            break;

        case 'iniciar_visita':
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
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
                WHERE visitador = :v AND status = 'iniciada' AND fim IS NULL
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
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
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
                WHERE id = :id AND visitador = :v AND status = 'iniciada' AND fim IS NULL
                LIMIT 1
            ");
            $stmt->execute(['id' => $id, 'v' => $visitadorNome]);
            $active = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$active) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma visita em andamento encontrada para encerrar.'], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Encerrar a visita em andamento
            $stmt = $pdo->prepare("UPDATE visitas_em_andamento SET fim = NOW(), status = 'encerrada' WHERE id = :id AND visitador = :v");
            $stmt->execute(['id' => $id, 'v' => $visitadorNome]);

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
                    (:visitador, :prescritor, '', '', '', CURDATE(), DATE_FORMAT(NOW(), '%H:%i'), :inicio_visita, :status_visita, :local_visita, :amostra, :brinde, :artigo, :resumo_visita, :reagendado_para, YEAR(CURDATE()))
            ");
            $stmt->execute([
                'visitador' => $visitadorNome,
                'prescritor' => $active['prescritor'],
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
            $stmtGet = $pdo->prepare("SELECT id FROM historico_visitas WHERE id = :id AND data_visita IS NOT NULL AND $visitadorWhere LIMIT 1");
            $stmtGet->execute($isMyPharm ? ['id' => $historicoId] : ['id' => $historicoId, 'v' => $visitadorNome]);
            if (!$stmtGet->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode(['success' => false, 'error' => 'Visita não encontrada ou sem permissão.'], JSON_UNESCAPED_UNICODE);
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
            $hoje = date('Y-m-d');
            $agendaDate = isset($row['data_agendada']) ? (is_object($row['data_agendada']) ? $row['data_agendada']->format('Y-m-d') : substr((string)$row['data_agendada'], 0, 10)) : '';
            if ($agendaDate < $hoje) {
                echo json_encode(['success' => false, 'error' => 'Só é possível editar visitas futuras.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            if ($agendaDate === $hoje && !empty($row['hora'])) {
                $horaRow = is_object($row['hora']) ? $row['hora']->format('H:i') : substr((string)$row['hora'], 0, 5);
                if ($horaRow && $horaRow < date('H:i')) {
                    echo json_encode(['success' => false, 'error' => 'Só é possível editar visitas futuras.'], JSON_UNESCAPED_UNICODE);
                    break;
                }
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
            $hoje = date('Y-m-d');
            $agendaDate = isset($row['data_agendada']) ? (is_object($row['data_agendada']) ? $row['data_agendada']->format('Y-m-d') : substr((string)$row['data_agendada'], 0, 10)) : '';
            if ($agendaDate < $hoje) {
                echo json_encode(['success' => false, 'error' => 'Só é possível excluir visitas futuras.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            if ($agendaDate === $hoje && !empty($row['hora'])) {
                $horaRow = is_object($row['hora']) ? $row['hora']->format('H:i') : substr((string)$row['hora'], 0, 5);
                if ($horaRow && $horaRow < date('H:i')) {
                    echo json_encode(['success' => false, 'error' => 'Só é possível excluir visitas futuras.'], JSON_UNESCAPED_UNICODE);
                    break;
                }
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
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
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
            $visitadorNome = trim($_GET['visitador_nome'] ?? $_SESSION['user_nome'] ?? '');
            if ($visitadorNome === '') {
                echo json_encode(['active' => null], JSON_UNESCAPED_UNICODE);
                break;
            }
            $stmt = $pdo->prepare("
                SELECT id, visitador_nome, data_inicio, data_fim, pausado_em, status
                FROM rotas_diarias
                WHERE visitador_nome = :v AND status IN ('em_andamento', 'pausada')
                ORDER BY data_inicio DESC
                LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['active' => $row ?: null], JSON_UNESCAPED_UNICODE);
            break;

        case 'start_rota':
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
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
                WHERE visitador_nome = :v AND status IN ('em_andamento', 'pausada')
                LIMIT 1
            ");
            $stmt->execute(['v' => $visitadorNome]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Já existe uma rota ativa. Pause ou finalize antes de iniciar outra.'], JSON_UNESCAPED_UNICODE);
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
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $sessionNome = trim($_SESSION['user_nome'] ?? '');
            $visitadorNome = ($userSetor === 'visitador') ? $sessionNome : trim($input['visitador_nome'] ?? $sessionNome);
            $stmt = $pdo->prepare("
                UPDATE rotas_diarias
                SET pausado_em = NOW(), status = 'pausada'
                WHERE visitador_nome = :v AND status = 'em_andamento'
            ");
            $stmt->execute(['v' => $visitadorNome]);
            echo json_encode(['success' => true, 'paused' => $stmt->rowCount() > 0], JSON_UNESCAPED_UNICODE);
            break;

        case 'resume_rota':
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $sessionNome = trim($_SESSION['user_nome'] ?? '');
            $visitadorNome = ($userSetor === 'visitador') ? $sessionNome : trim($input['visitador_nome'] ?? $sessionNome);
            $stmt = $pdo->prepare("
                UPDATE rotas_diarias
                SET pausado_em = NULL, status = 'em_andamento'
                WHERE visitador_nome = :v AND status = 'pausada'
            ");
            $stmt->execute(['v' => $visitadorNome]);
            echo json_encode(['success' => true, 'resumed' => $stmt->rowCount() > 0], JSON_UNESCAPED_UNICODE);
            break;

        case 'finish_rota':
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $sessionNome = trim($_SESSION['user_nome'] ?? '');
            // Visitador: sempre pela rota do usuário logado (permite finalizar em qualquer dispositivo)
            $visitadorNome = ($userSetor === 'visitador') ? $sessionNome : trim($input['visitador_nome'] ?? $sessionNome);
            $stmt = $pdo->prepare("
                UPDATE rotas_diarias
                SET data_fim = NOW(), pausado_em = NULL, status = 'finalizada'
                WHERE visitador_nome = :v AND status IN ('em_andamento', 'pausada')
            ");
            $stmt->execute(['v' => $visitadorNome]);
            echo json_encode(['success' => true, 'finished' => $stmt->rowCount() > 0], JSON_UNESCAPED_UNICODE);
            break;

        case 'save_rota_ponto':
            if ($userSetor !== 'visitador' && ($_SESSION['user_tipo'] ?? '') !== 'admin') {
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
            $stmt = $pdo->prepare("
                SELECT id FROM rotas_diarias
                WHERE visitador_nome = :v AND status = 'em_andamento'
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
            $input = json_decode(file_get_contents('php://input'), true);
            $nome = trim($input['nome_prescritor'] ?? '');
            $novo_visitador = trim($input['novo_visitador'] ?? '');

            if (empty($nome)) {
                echo json_encode(['success' => false, 'error' => 'Nome do prescritor não fornecido']);
                break;
            }

            // Se for My Pharm, salvamos como vazio ou null
            if ($novo_visitador === 'My Pharm') {
                $novo_visitador = '';
            }

            $stmt = $pdo->prepare("UPDATE prescritor_resumido SET visitador = :vis WHERE nome = :nome");
            $stmt->execute(['vis' => $novo_visitador, 'nome' => $nome]);

            // Persistir na tabela mestre (prescritores_cadastro)
            $stmtCadastro = $pdo->prepare("INSERT INTO prescritores_cadastro (nome, visitador) VALUES (:nome, :vis) ON DUPLICATE KEY UPDATE visitador = :vis2");
            $stmtCadastro->execute(['nome' => $nome, 'vis' => $novo_visitador, 'vis2' => $novo_visitador]);

            echo json_encode(['success' => true, 'message' => 'Prescritor transferido com sucesso!'], JSON_UNESCAPED_UNICODE);
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
