<?php
/**
 * api_clientes.php — API da página de análise de clientes (base importada).
 * Usa config.php/.env igual ao resto do sistema. Sem credenciais hardcoded.
 */
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$allowedOrigins = ['http://localhost','https://localhost','http://127.0.0.1','https://127.0.0.1'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Mesma sessão que api.php: user_id + user_setor (não existe $_SESSION['loggedIn'] / ['setor'] no PHP)
$userIdCli = (int)($_SESSION['user_id'] ?? 0);
$userSetorCli = strtolower(trim((string)($_SESSION['user_setor'] ?? '')));
$tipoCli = strtolower(trim((string)($_SESSION['user_tipo'] ?? '')));
$cliSetorOk = ($userSetorCli === 'administrador' || $userSetorCli === 'gestor'
    || strpos($userSetorCli, 'administrador') === 0
    || strpos($userSetorCli, 'gestor') === 0);
if ($userIdCli <= 0 || (!$cliSetorOk && $tipoCli !== 'admin')) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo    = getConnection();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function cliTblExists(PDO $pdo): bool {
    static $r = null;
    if ($r === null) $r = (bool)$pdo->query("SHOW TABLES LIKE 'clientes'")->fetch();
    return $r;
}
function cliOut(mixed $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Garante colunas de geocódigo na tabela clientes (idempotente). */
function cliEnsureGeoColumns(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!cliTblExists($pdo)) {
        return;
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'latitude'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec('ALTER TABLE clientes ADD COLUMN latitude DECIMAL(10,7) NULL DEFAULT NULL, ADD COLUMN longitude DECIMAL(10,7) NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // colunas já existem ou sem permissão ALTER
    }
}

function cliTableExists(PDO $pdo, string $table): bool
{
    $table = preg_replace('/[^a-z0-9_]/i', '', $table);
    if ($table === '') {
        return false;
    }
    try {
        $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));

        return $st && (bool) $st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/** Normaliza nome para comparação (igual ideia a gestao_comercial gcNormName). */
function cliNormNomePedido(string $v): string
{
    $v = trim($v);
    $v = strtr($v, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ç' => 'C', 'ç' => 'c',
    ]);
    if (function_exists('mb_strtolower')) {
        $v = mb_strtolower($v, 'UTF-8');
    } else {
        $v = strtolower($v);
    }
    $v = preg_replace('/\s+/', ' ', $v) ?? '';

    return $v;
}

/** Critério de linha aprovada em gestao_pedidos (alinhado a gcApprovedCase). */
function cliSqlApprovedGestao(string $alias = 'gp'): string
{
    return "(
        {$alias}.status_financeiro IS NULL OR
        (
            {$alias}.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
            AND {$alias}.status_financeiro NOT LIKE '%carrinho%'
        )
    )";
}

/**
 * Cruzamento: nomes em pedidos (gestão aprovados + itens recusado/carrinho) × cadastro clientes.
 *
 * @return array<string, mixed>
 */
function cliCruzamentoComprasPayload(PDO $pdo, int $ano): array
{
    if (!cliTblExists($pdo)) {
        return [
            'ok' => false,
            'error' => 'Tabela clientes inexistente.',
        ];
    }

    $hasGp = cliTableExists($pdo, 'gestao_pedidos');
    $hasIo = cliTableExists($pdo, 'itens_orcamentos_pedidos');
    $hasOp = cliTableExists($pdo, 'orcamentos_pedidos');

    $cadastroNorm = []; // norm => true
    $cadastroExemplo = []; // norm => nome cru (primeiro)
    $stmtC = $pdo->query('SELECT nome FROM clientes');
    if ($stmtC) {
        while ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $raw = trim((string) ($row['nome'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $n = cliNormNomePedido($raw);
            if ($n === '') {
                continue;
            }
            $cadastroNorm[$n] = true;
            if (!isset($cadastroExemplo[$n])) {
                $cadastroExemplo[$n] = $raw;
            }
        }
    }

    $aprovNorm = [];
    $recNorm = [];

    $anoSqlGp = '';
    $anoSqlIo = '';
    $bindGp = [];
    $bindIo = [];
    if ($ano > 0) {
        $anoSqlGp = ' AND gp.ano_referencia = :ano_gp ';
        $bindGp[':ano_gp'] = $ano;
        $anoSqlIo = ' AND i.ano_referencia = :ano_io ';
        $bindIo[':ano_io'] = $ano;
    }

    if ($hasGp) {
        $ap = cliSqlApprovedGestao('gp');
        $sql = "
            SELECT DISTINCT TRIM(cliente) AS nm FROM gestao_pedidos gp
            WHERE {$ap} AND TRIM(COALESCE(cliente, '')) <> '' {$anoSqlGp}
            UNION
            SELECT DISTINCT TRIM(paciente) AS nm FROM gestao_pedidos gp
            WHERE {$ap} AND TRIM(COALESCE(paciente, '')) <> '' {$anoSqlGp}
        ";
        $st = $pdo->prepare($sql);
        $st->execute($bindGp);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $raw = trim((string) ($row['nm'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $n = cliNormNomePedido($raw);
            if ($n !== '') {
                $aprovNorm[$n] = true;
            }
        }
    }

    if ($hasIo) {
        $sql = "
            SELECT DISTINCT TRIM(paciente) AS nm
            FROM itens_orcamentos_pedidos i
            WHERE LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
              AND TRIM(COALESCE(paciente, '')) <> ''
              {$anoSqlIo}
        ";
        $st = $pdo->prepare($sql);
        $st->execute($bindIo);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $raw = trim((string) ($row['nm'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $n = cliNormNomePedido($raw);
            if ($n !== '') {
                $recNorm[$n] = true;
            }
        }
    }

    $orcUsado = false;
    if ($hasOp) {
        try {
            $cols = [];
            $cst = $pdo->query('SHOW COLUMNS FROM orcamentos_pedidos');
            if ($cst) {
                while ($c = $cst->fetch(PDO::FETCH_ASSOC)) {
                    $cols[strtolower((string) ($c['Field'] ?? ''))] = true;
                }
            }
            $campo = null;
            foreach (['paciente', 'cliente', 'nome_cliente'] as $cand) {
                if (isset($cols[$cand])) {
                    $campo = $cand;
                    break;
                }
            }
            if ($campo !== null) {
                $orcUsado = true;
                $sql = "SELECT DISTINCT TRIM(`{$campo}`) AS nm FROM orcamentos_pedidos WHERE TRIM(COALESCE(`{$campo}`, '')) <> ''";
                if ($ano > 0 && isset($cols['ano_referencia'])) {
                    $sql .= ' AND ano_referencia = ' . (int) $ano;
                }
                foreach ($pdo->query($sql) as $row) {
                    $raw = trim((string) ($row['nm'] ?? ''));
                    if ($raw === '') {
                        continue;
                    }
                    $n = cliNormNomePedido($raw);
                    if ($n !== '') {
                        $recNorm[$n] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignora orcamentos_pedidos problemático
        }
    }

    $qualquerPedido = array_merge(array_keys($aprovNorm), array_keys($recNorm));
    $qualquerPedido = array_fill_keys($qualquerPedido, true);

    $cadComCompra = 0;
    foreach (array_keys($cadastroNorm) as $cn) {
        if (isset($qualquerPedido[$cn])) {
            $cadComCompra++;
        }
    }

    $pedComMatch = 0;
    foreach (array_keys($qualquerPedido) as $pn) {
        if (isset($cadastroNorm[$pn])) {
            $pedComMatch++;
        }
    }

    $cadSemCompra = count($cadastroNorm) - $cadComCompra;
    $pedSemCadastro = count($qualquerPedido) - $pedComMatch;

    $amostraCadSem = [];
    foreach (array_keys($cadastroNorm) as $cn) {
        if (!isset($qualquerPedido[$cn])) {
            $amostraCadSem[] = $cadastroExemplo[$cn] ?? $cn;
            if (count($amostraCadSem) >= 40) {
                break;
            }
        }
    }

    $amostraPedSem = [];
    foreach (array_keys($qualquerPedido) as $pn) {
        if (!isset($cadastroNorm[$pn])) {
            $amostraPedSem[] = $pn;
            if (count($amostraPedSem) >= 40) {
                break;
            }
        }
    }

    return [
        'ok' => true,
        'ano_filtrado' => $ano > 0 ? $ano : null,
        'fontes' => [
            'gestao_pedidos_aprovados' => $hasGp,
            'itens_orcamentos_recusado_carrinho' => $hasIo,
            'orcamentos_pedidos_nomes' => $orcUsado,
        ],
        'totais' => [
            'cadastro_nomes_distintos' => count($cadastroNorm),
            'cadastro_com_match_pedido' => $cadComCompra,
            'cadastro_sem_match_pedido' => $cadSemCompra,
            'pedido_nomes_distintos_total' => count($qualquerPedido),
            'pedido_nomes_distintos_somente_aprovados' => count($aprovNorm),
            'pedido_nomes_distintos_recusado_ou_carrinho_ou_orc' => count($recNorm),
            'pedido_com_match_cadastro' => $pedComMatch,
            'pedido_sem_match_cadastro' => $pedSemCadastro,
        ],
        'amostras' => [
            'cadastro_sem_compra_nome' => $amostraCadSem,
            'pedido_sem_cadastro_norm' => $amostraPedSem,
        ],
        'metodologia' => 'Comparação por nome normalizado (minúsculas, acentos básicos, espaços). '
            . 'Aprovados: gestao_pedidos com status_financeiro compatível com venda aprovada (exclui Recusado, Cancelado, Orçamento e “carrinho”). '
            . 'Recusado / no carrinho: itens_orcamentos_pedidos com status recusado ou no carrinho (nome em paciente). '
            . 'União cliente + paciente nas linhas aprovadas de gestao_pedidos. '
            . 'Se existir tabela orcamentos_pedidos com coluna paciente/cliente/nome_cliente, nomes são incluídos no grupo “sem aprovação” (recNorm). '
            . ($ano > 0 ? "Filtro de ano_referencia = {$ano} aplicado onde a coluna existe." : 'Sem filtro de ano (todos os anos nas tabelas).'),
    ];
}

/**
 * Análise cadastro × compras (gestão aprovada + pipeline itens) com filtro opcional UF/município.
 *
 * @return array<string, mixed>
 */
function cliAnaliseComprasClientesPayload(PDO $pdo, string $uf, string $municipio): array
{
    if (!cliTblExists($pdo)) {
        return ['ok' => false, 'error' => 'Tabela clientes inexistente.'];
    }

    $uf = strtoupper(trim($uf));
    $municipio = trim($municipio);
    if ($uf !== '' && !preg_match('/^[A-Z]{2}$/', $uf)) {
        $uf = '';
    }

    $wc = ['1=1'];
    $bind = [];
    if ($uf !== '') {
        $wc[] = 'c.uf = :uf_c';
        $bind[':uf_c'] = $uf;
    }
    if ($municipio !== '') {
        $wc[] = 'TRIM(c.municipio) = :mun_c';
        $bind[':mun_c'] = $municipio;
    }
    $whereCli = implode(' AND ', $wc);

    $cadTotal = 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE {$whereCli}");
    $st->execute($bind);
    $cadTotal = (int) $st->fetchColumn();

    $hasGp = cliTableExists($pdo, 'gestao_pedidos');
    $hasIo = cliTableExists($pdo, 'itens_orcamentos_pedidos');

    $out = [
        'ok' => true,
        'filtro' => [
            'uf' => $uf !== '' ? $uf : null,
            'municipio' => $municipio !== '' ? $municipio : null,
        ],
        'cadastro_total' => $cadTotal,
        'fontes' => [
            'gestao_pedidos' => $hasGp,
            'itens_orcamentos_pedidos' => $hasIo,
        ],
        'totais' => [
            'clientes_sem_compra_aprovada' => 0,
            'clientes_uma_compra' => 0,
            'clientes_recorrentes' => 0,
            'pct_recorrentes_sobre_base' => null,
            'pct_recorrentes_sobre_compradores' => null,
            'receita_aprovada_total' => 0.0,
            'ticket_medio_compradores' => null,
            'pedidos_aprovados_distintos' => 0,
            'valor_pipeline_rec_carrinho' => 0.0,
            'clientes_somente_pipeline' => 0,
        ],
        'compras_por_ano' => [],
        'top_recorrentes' => [],
        'metodologia' => 'Clientes filtrados pela base `clientes` (UF/município). '
            . 'Compras aprovadas: `gestao_pedidos` com status_financeiro compatível (exclui recusado, cancelado, orçamento e carrinho), '
            . 'vínculo por nome igual em cliente ou paciente (TRIM). '
            . 'Recorrente = 2 ou mais pedidos distintos (número+série). '
            . 'Pipeline = soma de valor_líquido em `itens_orcamentos_pedidos` com status recusado ou no carrinho (paciente). '
            . 'Indicadores são em todo o período disponível nas tabelas (use filtro geográfico para focar, ex.: Ariquemes/RO).',
    ];

    if (!$hasGp) {
        if ($hasIo) {
            $sqlP = "
                SELECT COALESCE(SUM(i.valor_liquido), 0) AS v
                FROM clientes c
                INNER JOIN itens_orcamentos_pedidos i ON (
                    TRIM(COALESCE(i.paciente, '')) = TRIM(c.nome)
                    AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
                )
                WHERE {$whereCli}
            ";
            $st = $pdo->prepare($sqlP);
            $st->execute($bind);
            $out['totais']['valor_pipeline_rec_carrinho'] = round((float) $st->fetchColumn(), 2);
        }

        return $out;
    }

    $ap = cliSqlApprovedGestao('gp');

    $sqlPer = "
        SELECT
            SUM(CASE WHEN COALESCE(ped_cnt, 0) = 0 THEN 1 ELSE 0 END) AS sem_compra,
            SUM(CASE WHEN COALESCE(ped_cnt, 0) = 1 THEN 1 ELSE 0 END) AS uma,
            SUM(CASE WHEN COALESCE(ped_cnt, 0) >= 2 THEN 1 ELSE 0 END) AS recorrentes,
            COALESCE(SUM(receita), 0) AS receita_total
        FROM (
            SELECT
                c.id,
                COUNT(DISTINCT CONCAT(gp.numero_pedido, '-', gp.serie_pedido)) AS ped_cnt,
                COALESCE(SUM(gp.preco_liquido), 0) AS receita
            FROM clientes c
            LEFT JOIN gestao_pedidos gp ON (
                {$ap}
                AND (
                    TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome)
                    OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome)
                )
            )
            WHERE {$whereCli}
            GROUP BY c.id
        ) t
    ";
    $st = $pdo->prepare($sqlPer);
    $st->execute($bind);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $sem = (int) ($row['sem_compra'] ?? 0);
    $uma = (int) ($row['uma'] ?? 0);
    $rec = (int) ($row['recorrentes'] ?? 0);
    $receita = (float) ($row['receita_total'] ?? 0);
    $compradores = $uma + $rec;

    $out['totais']['clientes_sem_compra_aprovada'] = $sem;
    $out['totais']['clientes_uma_compra'] = $uma;
    $out['totais']['clientes_recorrentes'] = $rec;
    $out['totais']['pct_recorrentes_sobre_base'] = $cadTotal > 0 ? round($rec / $cadTotal * 100, 2) : null;
    $out['totais']['pct_recorrentes_sobre_compradores'] = $compradores > 0 ? round($rec / $compradores * 100, 2) : null;
    $out['totais']['receita_aprovada_total'] = round($receita, 2);
    $out['totais']['ticket_medio_compradores'] = $compradores > 0 ? round($receita / $compradores, 2) : null;

    $sqlPed = "
        SELECT COUNT(*) FROM (
            SELECT DISTINCT CONCAT(gp.numero_pedido, '-', gp.serie_pedido) AS pk
            FROM gestao_pedidos gp
            INNER JOIN clientes c ON (
                (TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome) OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome))
                AND {$whereCli}
            )
            WHERE {$ap}
        ) x
    ";
    $st = $pdo->prepare($sqlPed);
    $st->execute($bind);
    $out['totais']['pedidos_aprovados_distintos'] = (int) $st->fetchColumn();

    $sqlAno = "
        SELECT
            gp.ano_referencia AS ano,
            COALESCE(SUM(gp.preco_liquido), 0) AS receita,
            COUNT(DISTINCT CONCAT(gp.numero_pedido, '-', gp.serie_pedido)) AS pedidos_distintos,
            COUNT(DISTINCT c.id) AS clientes_distintos
        FROM gestao_pedidos gp
        INNER JOIN clientes c ON (
            (TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome) OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome))
            AND {$whereCli}
        )
        WHERE {$ap}
        GROUP BY gp.ano_referencia
        ORDER BY gp.ano_referencia ASC
    ";
    $st = $pdo->prepare($sqlAno);
    $st->execute($bind);
    $porAno = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $porAno[] = [
            'ano' => (int) ($r['ano'] ?? 0),
            'receita' => round((float) ($r['receita'] ?? 0), 2),
            'pedidos_distintos' => (int) ($r['pedidos_distintos'] ?? 0),
            'clientes_distintos' => (int) ($r['clientes_distintos'] ?? 0),
        ];
    }
    $out['compras_por_ano'] = $porAno;

    $sqlTop = "
        SELECT
            c.nome,
            c.municipio,
            c.uf,
            COUNT(DISTINCT CONCAT(gp.numero_pedido, '-', gp.serie_pedido)) AS pedidos_aprov,
            COALESCE(SUM(gp.preco_liquido), 0) AS receita,
            COUNT(DISTINCT gp.ano_referencia) AS anos_com_compra
        FROM clientes c
        INNER JOIN gestao_pedidos gp ON (
            {$ap}
            AND (
                TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome)
                OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome)
            )
        )
        WHERE {$whereCli}
        GROUP BY c.id, c.nome, c.municipio, c.uf
        HAVING pedidos_aprov >= 2
        ORDER BY receita DESC
        LIMIT 40
    ";
    $st = $pdo->prepare($sqlTop);
    $st->execute($bind);
    $top = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $top[] = [
            'nome' => (string) ($r['nome'] ?? ''),
            'municipio' => (string) ($r['municipio'] ?? ''),
            'uf' => (string) ($r['uf'] ?? ''),
            'pedidos_aprov' => (int) ($r['pedidos_aprov'] ?? 0),
            'receita' => round((float) ($r['receita'] ?? 0), 2),
            'anos_com_compra' => (int) ($r['anos_com_compra'] ?? 0),
        ];
    }
    $out['top_recorrentes'] = $top;

    if ($hasIo) {
        $sqlPl = "
            SELECT COALESCE(SUM(i.valor_liquido), 0) AS v
            FROM clientes c
            INNER JOIN itens_orcamentos_pedidos i ON (
                TRIM(COALESCE(i.paciente, '')) = TRIM(c.nome)
                AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
            )
            WHERE {$whereCli}
        ";
        $st = $pdo->prepare($sqlPl);
        $st->execute($bind);
        $out['totais']['valor_pipeline_rec_carrinho'] = round((float) $st->fetchColumn(), 2);

        $sqlSoPipe = "
            SELECT COUNT(*) FROM clientes c
            WHERE {$whereCli}
            AND NOT EXISTS (
                SELECT 1 FROM gestao_pedidos gp
                WHERE {$ap}
                AND (
                    TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome)
                    OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome)
                )
                LIMIT 1
            )
            AND EXISTS (
                SELECT 1 FROM itens_orcamentos_pedidos i
                WHERE TRIM(COALESCE(i.paciente, '')) = TRIM(c.nome)
                AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
                LIMIT 1
            )
        ";
        $st = $pdo->prepare($sqlSoPipe);
        $st->execute($bind);
        $out['totais']['clientes_somente_pipeline'] = (int) $st->fetchColumn();
    }

    return $out;
}

// ── cruzamento_compras (cadastro clientes × pedidos) ─────────────────────────
if ($action === 'cruzamento_compras') {
    $ano = (int) ($_GET['ano'] ?? 0);
    cliOut(cliCruzamentoComprasPayload($pdo, $ano));
}

// ── analise_compras_clientes (KPIs, recorrência, pipeline, série por ano) ────
if ($action === 'analise_compras_clientes') {
    $ufCli = strtoupper(trim((string) ($_GET['uf'] ?? '')));
    $munCli = trim((string) ($_GET['municipio'] ?? ''));
    cliOut(cliAnaliseComprasClientesPayload($pdo, $ufCli, $munCli));
}

// ── resumo ────────────────────────────────────────────────────────────────────
if ($action === 'resumo') {
    if (!cliTblExists($pdo)) {
        cliOut(['total'=>0,'com_cpf'=>0,'sem_cpf'=>0,'completo'=>0,'simples'=>0,'com_celular'=>0,'com_email'=>0,'com_nasc'=>0]);
    }
    $r = $pdo->query("
        SELECT
            COUNT(*)                               AS total,
            SUM(cpf IS NOT NULL)                   AS com_cpf,
            SUM(cpf IS NULL)                       AS sem_cpf,
            SUM(tipo_cadastro = 'Completo')        AS completo,
            SUM(tipo_cadastro = 'Simples')         AS simples,
            SUM(
                IFNULL(TRIM(telefone), '') <> ''
                AND (
                    REGEXP_REPLACE(TRIM(telefone), '[^0-9]', '') REGEXP '^[1-9][1-9]9[0-9]{8}\$'
                    OR REGEXP_REPLACE(TRIM(telefone), '[^0-9]', '') REGEXP '^55[1-9][1-9]9[0-9]{8}\$'
                )
            )                                      AS com_celular,
            SUM(email IS NOT NULL AND email != '') AS com_email,
            SUM(data_nasc IS NOT NULL)             AS com_nasc
        FROM clientes
    ")->fetch(PDO::FETCH_ASSOC);
    cliOut($r);
}

// ── por_mes ───────────────────────────────────────────────────────────────────
if ($action === 'por_mes') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT
            YEAR(data_cadastro)  AS ano,
            MONTH(data_cadastro) AS mes,
            COUNT(*)             AS total,
            SUM(tipo_cadastro = 'Completo') AS completo,
            SUM(tipo_cadastro = 'Simples')  AS simples
        FROM clientes
        WHERE data_cadastro IS NOT NULL
        GROUP BY ano, mes
        ORDER BY ano, mes
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_fonte_ano ─────────────────────────────────────────────────────────────
if ($action === 'por_fonte_ano') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT
            COALESCE(fonte_ano, 0) AS ano,
            COUNT(*) AS total,
            SUM(tipo_cadastro = 'Completo') AS completo,
            SUM(cpf IS NOT NULL)            AS com_cpf
        FROM clientes
        GROUP BY fonte_ano
        ORDER BY fonte_ano
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_uf ────────────────────────────────────────────────────────────────────
if ($action === 'por_uf') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(uf),''),'N/D') AS uf, COUNT(*) AS total
        FROM clientes GROUP BY uf ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_municipio ─────────────────────────────────────────────────────────────
if ($action === 'por_municipio') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT
            COALESCE(NULLIF(TRIM(municipio),''),'N/D') AS municipio,
            COALESCE(NULLIF(TRIM(uf),''),'')           AS uf,
            COUNT(*) AS total
        FROM clientes GROUP BY municipio, uf ORDER BY total DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── por_sexo ──────────────────────────────────────────────────────────────────
if ($action === 'por_sexo') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(sexo),''),'Não informado') AS sexo, COUNT(*) AS total
        FROM clientes GROUP BY sexo ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    cliOut($rows);
}

// ── lista_ufs ─────────────────────────────────────────────────────────────────
if ($action === 'lista_ufs') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $rows = $pdo->query("SELECT DISTINCT uf FROM clientes WHERE uf IS NOT NULL AND uf != '' ORDER BY uf")->fetchAll(PDO::FETCH_COLUMN);
    cliOut($rows);
}

// ── lista_municipios ─────────────────────────────────────────────────────────
if ($action === 'lista_municipios') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $uf)) {
        cliOut([]);
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT TRIM(municipio) AS municipio
        FROM clientes
        WHERE uf = :uf AND municipio IS NOT NULL AND TRIM(municipio) <> ''
        ORDER BY municipio COLLATE utf8mb4_unicode_ci
    ");
    $stmt->execute([':uf' => $uf]);
    cliOut($stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── lista_bairros ───────────────────────────────────────────────────────────
if ($action === 'lista_bairros') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    $mun = trim((string)($_GET['municipio'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $uf) || $mun === '') {
        cliOut([]);
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT TRIM(bairro) AS bairro
        FROM clientes
        WHERE uf = :uf AND TRIM(municipio) = :mun
          AND bairro IS NOT NULL AND TRIM(bairro) <> ''
        ORDER BY bairro COLLATE utf8mb4_unicode_ci
    ");
    $stmt->execute([':uf' => $uf, ':mun' => $mun]);
    cliOut($stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ── por_municipio_por_uf ─────────────────────────────────────────────────────
if ($action === 'por_municipio_por_uf') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $uf)) {
        cliOut([]);
    }
    $stmt = $pdo->prepare("
        SELECT TRIM(municipio) AS municipio, COUNT(*) AS total
        FROM clientes
        WHERE uf = :uf AND municipio IS NOT NULL AND TRIM(municipio) <> ''
        GROUP BY TRIM(municipio)
        ORDER BY total DESC
    ");
    $stmt->execute([':uf' => $uf]);
    cliOut($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── por_bairro ───────────────────────────────────────────────────────────────
if ($action === 'por_bairro') {
    if (!cliTblExists($pdo)) { cliOut([]); }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    $mun = trim((string)($_GET['municipio'] ?? ''));
    $bairro = trim((string)($_GET['bairro'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $uf) || $mun === '') {
        cliOut([]);
    }
    if ($bairro !== '') {
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(bairro), ''), 'N/D') AS bairro, COUNT(*) AS total
            FROM clientes
            WHERE uf = :uf AND TRIM(municipio) = :mun AND TRIM(bairro) = :bairro
            GROUP BY TRIM(bairro)
            ORDER BY total DESC
            LIMIT 60
        ");
        $stmt->execute([':uf' => $uf, ':mun' => $mun, ':bairro' => $bairro]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(bairro), ''), 'N/D') AS bairro, COUNT(*) AS total
            FROM clientes
            WHERE uf = :uf AND TRIM(municipio) = :mun
            GROUP BY TRIM(bairro)
            ORDER BY total DESC
            LIMIT 60
        ");
        $stmt->execute([':uf' => $uf, ':mun' => $mun]);
    }
    cliOut($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── pontos_municipio_mapa (cadastros com endereço para pins no mapa) ─────────
if ($action === 'pontos_municipio_mapa') {
    cliEnsureGeoColumns($pdo);
    if (!cliTblExists($pdo)) {
        cliOut([]);
    }
    $uf = strtoupper(trim((string)($_GET['uf'] ?? '')));
    $mun = trim((string)($_GET['municipio'] ?? ''));
    $bairro = trim((string)($_GET['bairro'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $uf) || $mun === '') {
        cliOut([]);
    }
    $sql = "
        SELECT id, nome,
               COALESCE(NULLIF(TRIM(logradouro), ''), '') AS logradouro,
               COALESCE(NULLIF(TRIM(numero), ''), '') AS numero,
               COALESCE(NULLIF(TRIM(bairro), ''), '') AS bairro,
               COALESCE(NULLIF(TRIM(cep), ''), '') AS cep,
               TRIM(municipio) AS municipio, uf,
               latitude, longitude
        FROM clientes
        WHERE uf = :uf AND TRIM(municipio) = :mun
    ";
    $bind = [':uf' => $uf, ':mun' => $mun];
    if ($bairro !== '') {
        $sql .= ' AND TRIM(bairro) = :bairro';
        $bind[':bairro'] = $bairro;
    }
    $sql .= ' ORDER BY id ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    cliOut($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ── salvar_cliente_geo (persistir lat/lng após geocódigo no navegador) ────────
if ($action === 'salvar_cliente_geo') {
    if (strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST') {
        http_response_code(405);
        cliOut(['error' => 'Use POST']);
    }
    cliEnsureGeoColumns($pdo);
    if (!cliTblExists($pdo)) {
        cliOut(['ok' => false, 'error' => 'Sem tabela']);
    }
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        cliOut(['ok' => false, 'error' => 'JSON inválido']);
    }
    $id = (int)($j['id'] ?? 0);
    $lat = $j['lat'] ?? null;
    $lng = $j['lng'] ?? null;
    if ($id <= 0 || !is_numeric($lat) || !is_numeric($lng)) {
        cliOut(['ok' => false, 'error' => 'Parâmetros']);
    }
    $latF = (float)$lat;
    $lngF = (float)$lng;
    if ($latF < -35.0 || $latF > 10.0 || $lngF < -81.0 || $lngF > -28.0) {
        cliOut(['ok' => false, 'error' => 'Coordenadas fora do Brasil']);
    }
    $stmt = $pdo->prepare('UPDATE clientes SET latitude = :la, longitude = :ln WHERE id = :id LIMIT 1');
    $stmt->execute([':la' => $latF, ':ln' => $lngF, ':id' => $id]);
    cliOut(['ok' => $stmt->rowCount() > 0]);
}

/**
 * Filtros da busca de clientes (GET). Retorna [ WHERE sql, bind params, ORDER BY expressão (sem ORDER BY) ].
 * Usa alias de tabela `c` em ORDER BY quando necessário (agregados).
 *
 * @return array{0: string, 1: array<string, mixed>, 2: string}
 */
function cliBuscaBuildWhereAndOrder(PDO $pdo): array
{
    $q    = trim((string)($_GET['q']    ?? ''));
    $uf   = strtoupper(trim((string)($_GET['uf']   ?? '')));
    $mun  = trim((string)($_GET['municipio'] ?? ''));
    $tipo = trim((string)($_GET['tipo'] ?? ''));
    $ano  = (int)($_GET['ano'] ?? 0);

    $sortReq = trim((string)($_GET['sort'] ?? 'data_cadastro'));
    $dirReq  = strtoupper(trim((string)($_GET['dir'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
    $sortCols = ['nome', 'tipo_cadastro', 'telefone', 'cpf', 'uf', 'municipio', 'data_cadastro', 'fonte_ano'];

    if ($sortReq === 'receita_aprovada_gestao' && cliTableExists($pdo, 'gestao_pedidos')) {
        $ap = cliSqlApprovedGestao('gp');
        $orderSql = "(SELECT COALESCE(SUM(gp.preco_liquido), 0) FROM gestao_pedidos gp WHERE {$ap} AND (TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome) OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome))) {$dirReq}, c.id {$dirReq}";
    } elseif ($sortReq === 'ultima_compra_aprovada' && cliTableExists($pdo, 'gestao_pedidos')) {
        $ap = cliSqlApprovedGestao('gp');
        $sub = "(SELECT MAX(COALESCE(gp.data_aprovacao, gp.data_orcamento)) FROM gestao_pedidos gp WHERE {$ap} AND (TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome) OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome)))";
        // Sem compra: ordena por último (DESC: datas reais primeiro; ASC: mais antigas primeiro)
        if ($dirReq === 'DESC') {
            $orderSql = "COALESCE({$sub}, '1970-01-01 00:00:00') DESC, c.id DESC";
        } else {
            $orderSql = "COALESCE({$sub}, '9999-12-31 23:59:59') ASC, c.id ASC";
        }
    } elseif ($sortReq === 'valor_itens_recusados' && cliTableExists($pdo, 'itens_orcamentos_pedidos')) {
        $orderSql = "(SELECT COALESCE(SUM(i.valor_liquido), 0) FROM itens_orcamentos_pedidos i WHERE TRIM(COALESCE(i.paciente, '')) = TRIM(c.nome) AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')) {$dirReq}, c.id {$dirReq}";
    } elseif ($sortReq === 'prescritor_cliente') {
        $pex = cliBuscaSqlOrderExprPrescritorCliente($pdo);
        $orderSql = $pex !== ''
            ? "{$pex} {$dirReq}, c.id {$dirReq}"
            : "c.data_cadastro {$dirReq}, c.id {$dirReq}";
    } elseif ($sortReq === 'visitador_prescritor') {
        $vex = cliBuscaSqlOrderExprVisitadorPrescritor($pdo);
        $orderSql = $vex !== ''
            ? "COALESCE({$vex}, '') {$dirReq}, c.id {$dirReq}"
            : "c.data_cadastro {$dirReq}, c.id {$dirReq}";
    } elseif (in_array($sortReq, $sortCols, true)) {
        $orderSql = "c.{$sortReq} {$dirReq}, c.id {$dirReq}";
    } else {
        $orderSql = "c.data_cadastro {$dirReq}, c.id {$dirReq}";
    }

    $where = ['1=1'];
    $p = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(nome LIKE :q1 OR telefone LIKE :q2 OR cpf LIKE :q3 OR email LIKE :q4 OR municipio LIKE :q5)';
        $p[':q1'] = $like;
        $p[':q2'] = $like;
        $p[':q3'] = $like;
        $p[':q4'] = $like;
        $p[':q5'] = $like;
    }
    if ($uf !== '' && preg_match('/^[A-Z]{2}$/', $uf)) {
        $where[] = 'uf = :uf';
        $p[':uf'] = $uf;
    }
    if ($mun !== '') {
        $where[] = 'TRIM(municipio) = :mun';
        $p[':mun'] = $mun;
    }
    if ($tipo !== '') {
        $where[] = 'tipo_cadastro = :tipo';
        $p[':tipo'] = $tipo;
    }
    if ($ano > 0) {
        $where[] = 'fonte_ano = :ano';
        $p[':ano'] = $ano;
    }

    return [implode(' AND ', $where), $p, $orderSql];
}

/**
 * Expressão SQL (correlacionada a `c`) para ordenar pela mesma prioridade de prescritor_cliente na busca.
 *
 * @return string vazio se não houver gestão/itens/orçamento utilizáveis
 */
function cliBuscaSqlOrderExprPrescritorCliente(PDO $pdo): string
{
    $hasGp = cliTableExists($pdo, 'gestao_pedidos');
    $hasIoPresc = cliTableExists($pdo, 'itens_orcamentos_pedidos') && cliItensOrcamentoTemColuna($pdo, 'prescritor');
    $orcMeta = cliBuscaOrcamentosHeadMeta($pdo);
    if (!$hasGp && !$hasIoPresc && $orcMeta === null) {
        return '';
    }
    $chunks = [];
    if ($hasGp) {
        $ap2 = cliSqlApprovedGestao('gp2');
        $chunks[] = "(SELECT COALESCE(NULLIF(TRIM(gp2.prescritor), ''), 'My Pharm')
               FROM gestao_pedidos gp2
               WHERE {$ap2}
               AND (TRIM(COALESCE(gp2.cliente, '')) = TRIM(c.nome) OR TRIM(COALESCE(gp2.paciente, '')) = TRIM(c.nome))
               ORDER BY COALESCE(gp2.data_aprovacao, gp2.data_orcamento) DESC, gp2.id DESC
               LIMIT 1)";
    }
    if ($hasIoPresc) {
        $ordIt = cliItensOrcamentoTemColuna($pdo, 'data') ? "COALESCE(i2.data, '1970-01-01') DESC, i2.id DESC" : 'i2.id DESC';
        $chunks[] = "NULLIF((SELECT COALESCE(NULLIF(TRIM(i2.prescritor), ''), '')
               FROM itens_orcamentos_pedidos i2
               WHERE TRIM(COALESCE(i2.paciente, '')) = TRIM(c.nome)
               ORDER BY {$ordIt}
               LIMIT 1), '')";
    }
    if ($orcMeta !== null) {
        $nf = $orcMeta['nome_field'];
        $of = $orcMeta['order_field'];
        $chunks[] = "NULLIF((SELECT COALESCE(NULLIF(TRIM(o.prescritor), ''), '')
               FROM orcamentos_pedidos o
               WHERE TRIM(COALESCE(o.`{$nf}`, '')) = TRIM(c.nome)
               ORDER BY o.`{$of}` DESC, o.id DESC
               LIMIT 1), '')";
    }

    return $chunks === [] ? '' : 'COALESCE(' . implode(', ', $chunks) . ')';
}

/**
 * Expressão SQL (correlacionada a `c`) para ordenar por visitador em prescritores_cadastro,
 * usando o mesmo nome de prescritor que cliBuscaSqlOrderExprPrescritorCliente.
 *
 * @return string vazio se não houver carteira ou fonte de prescritor
 */
function cliBuscaSqlOrderExprVisitadorPrescritor(PDO $pdo): string
{
    if (!cliTableExists($pdo, 'prescritores_cadastro')) {
        return '';
    }
    $pex = cliBuscaSqlOrderExprPrescritorCliente($pdo);
    if ($pex === '') {
        return '';
    }

    return "(SELECT COALESCE(NULLIF(TRIM(pc.visitador), ''), '')
            FROM prescritores_cadastro pc
            WHERE LOWER(TRIM(pc.nome)) = LOWER(TRIM(COALESCE(({$pex}), '')))
            LIMIT 1)";
}

/**
 * Cabeçalho orcamentos_pedidos: coluna de nome do paciente/cliente + coluna para ORDER BY (último registro).
 *
 * @return array{nome_field: string, order_field: string}|null
 */
function cliBuscaOrcamentosHeadMeta(PDO $pdo): ?array
{
    if (!cliTableExists($pdo, 'orcamentos_pedidos')) {
        return null;
    }
    try {
        $cols = [];
        $cst = $pdo->query('SHOW COLUMNS FROM orcamentos_pedidos');
        if (!$cst) {
            return null;
        }
        while ($c = $cst->fetch(PDO::FETCH_ASSOC)) {
            $cols[strtolower((string) ($c['Field'] ?? ''))] = true;
        }
        $nomeField = null;
        foreach (['paciente', 'cliente', 'nome_cliente'] as $cand) {
            if (isset($cols[$cand])) {
                $nomeField = $cand;
                break;
            }
        }
        if ($nomeField === null || !isset($cols['prescritor'])) {
            return null;
        }
        $orderField = 'id';
        foreach (['data_aprovacao', 'data_orcamento', 'data', 'criado_em'] as $dc) {
            if (isset($cols[$dc])) {
                $orderField = $dc;
                break;
            }
        }
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $nomeField) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $orderField)) {
            return null;
        }

        return ['nome_field' => $nomeField, 'order_field' => $orderField];
    } catch (Throwable $e) {
        return null;
    }
}

function cliItensOrcamentoTemColuna(PDO $pdo, string $col): bool
{
    if (!cliTableExists($pdo, 'itens_orcamentos_pedidos')) {
        return false;
    }
    $col = preg_replace('/[^a-z0-9_]/i', '', $col);
    if ($col === '') {
        return false;
    }
    try {
        $st = $pdo->query('SHOW COLUMNS FROM itens_orcamentos_pedidos');
        if (!$st) {
            return false;
        }
        while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
            if (strcasecmp((string) ($c['Field'] ?? ''), $col) === 0) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

/**
 * Anexa, por id de clientes, totais de gestao_pedidos (aprovados) e itens_orcamentos_pedidos
 * (soma recusado + no carrinho na coluna valor_itens_recusados).
 * Match por TRIM(nome) = TRIM(cliente|paciente) na gestão; só paciente nos itens.
 * Prescritor: último da gestão aprovada; senão último em itens_orcamentos_pedidos; senão último em orcamentos_pedidos.
 * Visitador: coluna visitador em prescritores_cadastro pelo nome do prescritor (LOWER(TRIM)).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function cliBuscaMapVisitadorPorPrescritores(PDO $pdo, array $nomesPrescritor): array
{
    if (!cliTableExists($pdo, 'prescritores_cadastro')) {
        return [];
    }
    $uniq = [];
    foreach ($nomesPrescritor as $n) {
        $t = trim((string) $n);
        if ($t === '') {
            continue;
        }
        $uniq[mb_strtolower($t, 'UTF-8')] = true;
    }
    $keys = array_keys($uniq);
    if ($keys === []) {
        return [];
    }
    $out = [];
    $chunkSize = 300;
    for ($i = 0, $n = count($keys); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($keys, $i, $chunkSize);
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT nome, visitador FROM prescritores_cadastro WHERE LOWER(TRIM(nome)) IN ($ph)";
        $st = $pdo->prepare($sql);
        $st->execute($chunk);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $lk = mb_strtolower(trim((string) ($row['nome'] ?? '')), 'UTF-8');
            if ($lk === '') {
                continue;
            }
            $vis = $row['visitador'] ?? null;
            $out[$lk] = ($vis !== null && trim((string) $vis) !== '') ? trim((string) $vis) : null;
        }
    }

    return $out;
}

function cliBuscaAnexarResumoPedidos(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }
    $hasGp = cliTableExists($pdo, 'gestao_pedidos');
    $hasIo = cliTableExists($pdo, 'itens_orcamentos_pedidos');
    $orcMeta = cliBuscaOrcamentosHeadMeta($pdo);
    if (!$hasGp && !$hasIo && $orcMeta === null) {
        foreach ($rows as &$r) {
            $r['receita_aprovada_gestao'] = 0.0;
            $r['valor_itens_recusados'] = 0.0;
            $r['ultima_compra_aprovada'] = null;
            $r['prescritor_cliente'] = null;
            $r['visitador_prescritor'] = null;
        }
        unset($r);

        return $rows;
    }
    $ids = [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $idList = array_keys($ids);
    if ($idList === []) {
        foreach ($rows as &$r) {
            $r['receita_aprovada_gestao'] = 0.0;
            $r['valor_itens_recusados'] = 0.0;
            $r['ultima_compra_aprovada'] = null;
            $r['prescritor_cliente'] = null;
            $r['visitador_prescritor'] = null;
        }
        unset($r);

        return $rows;
    }
    $in = implode(',', array_map('intval', $idList));

    $mapG = [];
    if ($hasGp) {
        $ap = cliSqlApprovedGestao('gp');
        $sql = "
            SELECT c.id AS cid,
                   COALESCE(SUM(gp.preco_liquido), 0) AS receita_aprovada,
                   MAX(COALESCE(gp.data_aprovacao, gp.data_orcamento)) AS ultima_compra_em
            FROM clientes c
            LEFT JOIN gestao_pedidos gp ON (
                {$ap}
                AND (
                    TRIM(COALESCE(gp.cliente, '')) = TRIM(c.nome)
                    OR TRIM(COALESCE(gp.paciente, '')) = TRIM(c.nome)
                )
            )
            WHERE c.id IN ({$in})
            GROUP BY c.id
        ";
        $st = $pdo->query($sql);
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) $row['cid'];
                $mapG[$cid] = [
                    'receita' => (float) ($row['receita_aprovada'] ?? 0),
                    'ultima' => $row['ultima_compra_em'] ?? null,
                ];
            }
        }
    }

    $mapI = [];
    if ($hasIo) {
        $sql = "
            SELECT c.id AS cid,
                   COALESCE(SUM(i.valor_liquido), 0) AS valor_rec_carr
            FROM clientes c
            LEFT JOIN itens_orcamentos_pedidos i ON (
                TRIM(COALESCE(i.paciente, '')) = TRIM(c.nome)
                AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
            )
            WHERE c.id IN ({$in})
            GROUP BY c.id
        ";
        $st = $pdo->query($sql);
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $mapI[(int) $row['cid']] = (float) ($row['valor_rec_carr'] ?? 0);
            }
        }
    }

    $mapPrescG = [];
    if ($hasGp) {
        $ap2 = cliSqlApprovedGestao('gp2');
        $sqlPg = "
            SELECT c.id AS cid,
              (SELECT COALESCE(NULLIF(TRIM(gp2.prescritor), ''), 'My Pharm')
               FROM gestao_pedidos gp2
               WHERE {$ap2}
               AND (TRIM(COALESCE(gp2.cliente, '')) = TRIM(c.nome) OR TRIM(COALESCE(gp2.paciente, '')) = TRIM(c.nome))
               ORDER BY COALESCE(gp2.data_aprovacao, gp2.data_orcamento) DESC, gp2.id DESC
               LIMIT 1) AS presc
            FROM clientes c
            WHERE c.id IN ({$in})
        ";
        $st = $pdo->query($sqlPg);
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $p = $row['presc'] ?? null;
                $mapPrescG[(int) $row['cid']] = ($p !== null && trim((string) $p) !== '') ? trim((string) $p) : null;
            }
        }
    }

    $mapPrescI = [];
    if ($hasIo && cliItensOrcamentoTemColuna($pdo, 'prescritor')) {
        $ordIt = cliItensOrcamentoTemColuna($pdo, 'data') ? 'COALESCE(i2.data, \'1970-01-01\') DESC, i2.id DESC' : 'i2.id DESC';
        $sqlPi = "
            SELECT c.id AS cid,
              (SELECT COALESCE(NULLIF(TRIM(i2.prescritor), ''), '')
               FROM itens_orcamentos_pedidos i2
               WHERE TRIM(COALESCE(i2.paciente, '')) = TRIM(c.nome)
               ORDER BY {$ordIt}
               LIMIT 1) AS presc
            FROM clientes c
            WHERE c.id IN ({$in})
        ";
        $st = $pdo->query($sqlPi);
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $p = $row['presc'] ?? null;
                $mapPrescI[(int) $row['cid']] = ($p !== null && trim((string) $p) !== '') ? trim((string) $p) : null;
            }
        }
    }

    $mapPrescO = [];
    if ($orcMeta !== null) {
        $nf = $orcMeta['nome_field'];
        $of = $orcMeta['order_field'];
        $sqlPo = "
            SELECT c.id AS cid,
              (SELECT COALESCE(NULLIF(TRIM(o.prescritor), ''), '')
               FROM orcamentos_pedidos o
               WHERE TRIM(COALESCE(o.`{$nf}`, '')) = TRIM(c.nome)
               ORDER BY o.`{$of}` DESC, o.id DESC
               LIMIT 1) AS presc
            FROM clientes c
            WHERE c.id IN ({$in})
        ";
        $st = $pdo->query($sqlPo);
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $p = $row['presc'] ?? null;
                $mapPrescO[(int) $row['cid']] = ($p !== null && trim((string) $p) !== '') ? trim((string) $p) : null;
            }
        }
    }

    foreach ($rows as &$r) {
        $id = (int) ($r['id'] ?? 0);
        $g = $mapG[$id] ?? null;
        $i = $mapI[$id] ?? null;
        $r['receita_aprovada_gestao'] = $g !== null ? round($g['receita'], 2) : 0.0;
        if ($g !== null && !empty($g['ultima'])) {
            $u = $g['ultima'];
            $r['ultima_compra_aprovada'] = is_string($u) ? $u : (string) $u;
        } else {
            $r['ultima_compra_aprovada'] = null;
        }
        $r['valor_itens_recusados'] = $i !== null ? round($i, 2) : 0.0;

        $pg = $mapPrescG[$id] ?? null;
        $pi = $mapPrescI[$id] ?? null;
        $po = $mapPrescO[$id] ?? null;
        $r['prescritor_cliente'] = $pg ?? $pi ?? $po;
        if ($r['prescritor_cliente'] !== null && trim((string) $r['prescritor_cliente']) === '') {
            $r['prescritor_cliente'] = null;
        }
    }
    unset($r);

    $nomesParaCarteira = [];
    foreach ($rows as $r) {
        $p = $r['prescritor_cliente'] ?? null;
        if ($p !== null && trim((string) $p) !== '') {
            $nomesParaCarteira[] = (string) $p;
        }
    }
    $visitMap = cliBuscaMapVisitadorPorPrescritores($pdo, $nomesParaCarteira);
    foreach ($rows as &$r) {
        $p = $r['prescritor_cliente'] ?? null;
        if ($p === null || trim((string) $p) === '') {
            $r['visitador_prescritor'] = null;
        } else {
            $lk = mb_strtolower(trim((string) $p), 'UTF-8');
            $r['visitador_prescritor'] = array_key_exists($lk, $visitMap) ? $visitMap[$lk] : null;
        }
    }
    unset($r);

    return $rows;
}

// ── exportar_busca_csv (mesmos filtros/ordem da busca; até 80k linhas) ─────────
if ($action === 'exportar_busca_csv') {
    if (!cliTblExists($pdo)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Sem tabela clientes';
        exit;
    }
    [$wSql, $p, $orderSql] = cliBuscaBuildWhereAndOrder($pdo);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $wSql");
    $cnt->execute($p);
    $total = (int) $cnt->fetchColumn();
    $maxExport = 80000;
    if ($total > $maxExport) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'resultado_grande',
            'message' => "Muitos registros ($total). Refine a busca (máx. $maxExport para exportar).",
            'total' => $total,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clientes_busca_' . date('Y-m-d_His') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");

    $headers = [
        'id', 'nome', 'nome_valido', 'tipo_cadastro', 'data_cadastro', 'data_nasc', 'sexo',
        'email', 'tipo_telefone', 'telefone', 'cpf', 'rg', 'cep', 'logradouro', 'numero',
        'bairro', 'complemento', 'uf', 'municipio', 'fonte_ano',
        'receita_aprovada_gestao', 'valor_itens_recusados', 'ultima_compra_aprovada', 'prescritor_cliente', 'visitador_prescritor',
    ];
    fputcsv($out, $headers, ';');

    $sql = "
        SELECT id, nome, nome_valido, tipo_cadastro, data_cadastro, data_nasc, sexo,
               email, tipo_telefone, telefone, cpf, rg, cep, logradouro, numero,
               bairro, complemento, uf, municipio, fonte_ano
        FROM clientes c WHERE $wSql
        ORDER BY $orderSql
        LIMIT $maxExport
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $batch = [];
    $batchSize = 400;
    $flushBatch = static function () use (&$batch, $pdo, $out, $headers): void {
        if ($batch === []) {
            return;
        }
        $batch = cliBuscaAnexarResumoPedidos($pdo, $batch);
        foreach ($batch as $row) {
            $line = [];
            foreach ($headers as $h) {
                $v = $row[$h] ?? '';
                if ($v !== null && !is_scalar($v)) {
                    $v = '';
                }
                $line[] = $v;
            }
            fputcsv($out, $line, ';');
        }
        $batch = [];
    };
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $batch[] = $row;
        if (count($batch) >= $batchSize) {
            $flushBatch();
        }
    }
    $flushBatch();
    fclose($out);
    exit;
}

// ── buscar ────────────────────────────────────────────────────────────────────
if ($action === 'buscar') {
    if (!cliTblExists($pdo)) { cliOut(['rows'=>[],'total'=>0,'paginas'=>0,'pagina'=>1]); }

    [$wSql, $p, $orderSql] = cliBuscaBuildWhereAndOrder($pdo);
    $pg   = max(1, (int)($_GET['pg'] ?? 1));
    $per  = 50;
    $off  = ($pg - 1) * $per;

    $cnt   = $pdo->prepare("SELECT COUNT(*) FROM clientes c WHERE $wSql");
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();
    $pags  = $total > 0 ? (int)ceil($total / $per) : 0;

    $stmt  = $pdo->prepare("
        SELECT id, nome, nome_valido, data_cadastro, data_nasc, tipo_cadastro,
               sexo, email, telefone, cpf, uf, municipio, bairro, logradouro, numero, cep, fonte_ano
        FROM clientes c WHERE $wSql
        ORDER BY $orderSql
        LIMIT $per OFFSET $off
    ");
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = cliBuscaAnexarResumoPedidos($pdo, $rows);
    cliOut(['rows' => $rows, 'total' => $total, 'paginas' => $pags, 'pagina' => $pg]);
}

http_response_code(400);
echo json_encode(['error' => 'Ação inválida'], JSON_UNESCAPED_UNICODE);
