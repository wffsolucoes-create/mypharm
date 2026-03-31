<?php
/**
 * MÃ³dulo GestÃ£o Comercial
 * Endpoint principal: action=gestao_comercial_dashboard
 */

function handleGestaoComercialModuleAction(string $action, PDO $pdo): void
{
    try {
        switch ($action) {
            case 'gestao_comercial_dashboard':
                gestaoComercialDashboard($pdo);
                return;
            case 'gestao_comercial_dashboard_rd':
                gestaoComercialDashboardRdOnly($pdo);
                return;
            case 'gestao_comercial_lista_vendedores':
                gestaoComercialListaVendedores($pdo);
                return;
            case 'gestao_comercial_erros_lista':
                gestaoComercialErrosLista($pdo);
                return;
            case 'gestao_comercial_erros_salvar':
                gestaoComercialErrosSalvar($pdo);
                return;
            case 'gestao_comercial_erros_excluir':
                gestaoComercialErrosExcluir($pdo);
                return;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'AÃ§Ã£o de gestÃ£o comercial desconhecida'], JSON_UNESCAPED_UNICODE);
                return;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $msg = (defined('IS_PRODUCTION') && IS_PRODUCTION)
            ? 'Erro interno na gestÃ£o comercial.'
            : 'Erro: ' . $e->getMessage() . ' em ' . basename($e->getFile()) . ':' . $e->getLine();
        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Retorna apenas a lista de nomes de usuÃ¡rios com setor = vendedor (para abas).
 * Acesso: admin.
 */
function gestaoComercialListaVendedores(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $nomes = [];
    try {
        $tipo = isset($_SESSION['user_tipo']) ? strtolower(trim((string)$_SESSION['user_tipo'])) : '';
        if ($tipo !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->query("
            SELECT TRIM(nome) AS nome
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) = 'vendedor'
              AND COALESCE(ativo, 1) = 1
              AND TRIM(COALESCE(nome, '')) != ''
            ORDER BY TRIM(nome) ASC
        ");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $n = trim((string)($row['nome'] ?? ''));
                if ($n !== '' && gcIsAllowedVendedora($n)) {
                    $nomes[] = $n;
                }
            }
        }
    } catch (Throwable $e) {
        $nomes = [];
    }
    echo json_encode(['success' => true, 'nomes' => $nomes], JSON_UNESCAPED_UNICODE);
    exit;
}

function gcPercent(float $num, float $den): ?float
{
    if ($den <= 0) return null;
    return round(($num / $den) * 100, 2);
}

function gcGrowth(float $current, float $base): ?float
{
    if ($base <= 0) return null;
    return round((($current - $base) / $base) * 100, 2);
}

function gcDateRangeFromInput(): array
{
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : '';
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : '';
    $ano = isset($_GET['ano']) ? (int)$_GET['ano'] : 0;
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;

    $isDate = static function (string $v): bool {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    };

    if ($isDate($dataDe) && $isDate($dataAte)) {
        if ($dataDe > $dataAte) {
            $dataAte = $dataDe;
        }
        $start = new DateTimeImmutable($dataDe);
        $end = new DateTimeImmutable($dataAte);
        return [$start, $end, 'custom'];
    }

    if ($ano > 0 && $mes >= 1 && $mes <= 12) {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $end = $start->modify('last day of this month');
        return [$start, $end, 'ano_mes'];
    }

    if ($ano > 0) {
        $start = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
        $end = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
        return [$start, $end, 'ano'];
    }

    $today = new DateTimeImmutable('today');
    $start = $today->modify('first day of this month');
    $end = $today->modify('last day of this month');
    return [$start, $end, 'mes_atual'];
}

function gcFetchRow(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function gcFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function gcTryFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        return gcFetchAll($pdo, $sql, $params);
    } catch (Throwable $e) {
        return [];
    }
}

function gcEnsureControleErrosTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendedor_erros_manuais (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendedor_nome VARCHAR(120) NOT NULL,
            data_erro DATE NOT NULL,
            tipo_erro VARCHAR(120) NOT NULL,
            classificacao_erro VARCHAR(20) NOT NULL DEFAULT 'leve',
            pontos_descontados DECIMAL(10,2) NOT NULL DEFAULT 0,
            pedido_ref VARCHAR(80) NULL,
            descricao TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_vendedor_data (vendedor_nome, data_erro),
            KEY idx_data (data_erro)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function gcAssertAdminSession(): void
{
    $tipo = isset($_SESSION['user_tipo']) ? strtolower(trim((string)$_SESSION['user_tipo'])) : '';
    if ($tipo !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function gestaoComercialErrosLista(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    gcAssertAdminSession();
    gcEnsureControleErrosTable($pdo);

    [$startObj, $endObj] = gcDateRangeFromInput();
    $dataDe = $startObj->format('Y-m-d');
    $dataAte = $endObj->format('Y-m-d');
    $vendedor = trim((string)($_GET['vendedor'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = max(20, min(500, (int)($_GET['limit'] ?? 200)));

    $where = ["data_erro BETWEEN :de AND :ate"];
    $bind = ['de' => $dataDe, 'ate' => $dataAte];
    if ($vendedor !== '') {
        $where[] = "vendedor_nome = :vendedor";
        $bind['vendedor'] = $vendedor;
    }
    if ($q !== '') {
        $where[] = "(tipo_erro LIKE :q OR COALESCE(descricao, '') LIKE :q OR COALESCE(pedido_ref, '') LIKE :q)";
        $bind['q'] = '%' . str_replace(' ', '%', $q) . '%';
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    $rows = gcTryFetchAll($pdo, "
        SELECT
            id,
            vendedor_nome,
            DATE_FORMAT(data_erro, '%Y-%m-%d') AS data_erro,
            tipo_erro,
            classificacao_erro,
            pontos_descontados,
            COALESCE(pedido_ref, '') AS pedido_ref,
            COALESCE(descricao, '') AS descricao,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
        FROM vendedor_erros_manuais
        {$sqlWhere}
        ORDER BY data_erro DESC, id DESC
        LIMIT {$limit}
    ", $bind);

    foreach ($rows as &$row) {
        $vn = trim((string)($row['vendedor_nome'] ?? ''));
        $canon = gcCanonicalVendedoraNome($vn);
        $row['vendedor_nome'] = gcDisplayConsultoraNamePhp($canon !== '' ? $canon : $vn);
        $row['classificacao_erro'] = gcNormalizeClassificacaoErro((string)($row['classificacao_erro'] ?? 'leve'));
    }
    unset($row);

    $resumo = gcFetchRow($pdo, "
        SELECT
            COUNT(*) AS total_erros,
            COALESCE(SUM(pontos_descontados), 0) AS total_pontos
        FROM vendedor_erros_manuais
        {$sqlWhere}
    ", $bind);

    echo json_encode([
        'success' => true,
        'periodo' => ['data_de' => $dataDe, 'data_ate' => $dataAte],
        'resumo' => [
            'total_erros' => (int)($resumo['total_erros'] ?? 0),
            'total_pontos' => round((float)($resumo['total_pontos'] ?? 0), 2),
        ],
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function gestaoComercialErrosSalvar(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    gcAssertAdminSession();
    gcEnsureControleErrosTable($pdo);

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) $payload = [];

    $vendedor = gcCanonicalVendedoraNome(trim((string)($payload['vendedor_nome'] ?? '')));
    $dataErro = trim((string)($payload['data_erro'] ?? ''));
    $tipoErro = trim((string)($payload['tipo_erro'] ?? ''));
    $classificacao = gcNormalizeClassificacaoErro((string)($payload['classificacao_erro'] ?? 'leve'));
    $pedidoRef = trim((string)($payload['pedido_ref'] ?? ''));
    $descricao = trim((string)($payload['descricao'] ?? ''));

    if ($vendedor === '' || $tipoErro === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Vendedora e tipo do erro sÃ£o obrigatÃ³rios.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataErro)) {
        $dataErro = date('Y-m-d');
    }
    // Regra fixa: pontuação é sempre definida pela gravidade (leve=1, medio=2, grave=3).
    $pontos = gcPontosPadraoPorClassificacao($classificacao);
    $pontos = max(0.0, min(20.0, round($pontos, 2)));
    if (mb_strlen($tipoErro) > 120) $tipoErro = mb_substr($tipoErro, 0, 120);
    if (mb_strlen($pedidoRef) > 80) $pedidoRef = mb_substr($pedidoRef, 0, 80);
    if (mb_strlen($descricao) > 3000) $descricao = mb_substr($descricao, 0, 3000);

    $st = $pdo->prepare("
        INSERT INTO vendedor_erros_manuais (
            vendedor_nome, data_erro, tipo_erro, classificacao_erro,
            pontos_descontados, pedido_ref, descricao, created_by
        ) VALUES (
            :vendedor, :data_erro, :tipo_erro, :classificacao,
            :pontos, :pedido_ref, :descricao, :created_by
        )
    ");
    $st->execute([
        'vendedor' => $vendedor,
        'data_erro' => $dataErro,
        'tipo_erro' => $tipoErro,
        'classificacao' => $classificacao,
        'pontos' => $pontos,
        'pedido_ref' => $pedidoRef !== '' ? $pedidoRef : null,
        'descricao' => $descricao !== '' ? $descricao : null,
        'created_by' => (int)($_SESSION['user_id'] ?? 0),
    ]);

    echo json_encode([
        'success' => true,
        'id' => (int)$pdo->lastInsertId(),
        'saved' => [
            'vendedor_nome' => $vendedor,
            'data_erro' => $dataErro,
            'tipo_erro' => $tipoErro,
            'classificacao_erro' => $classificacao,
            'pontos_descontados' => $pontos,
            'pedido_ref' => $pedidoRef,
            'descricao' => $descricao,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function gestaoComercialErrosExcluir(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    gcAssertAdminSession();
    gcEnsureControleErrosTable($pdo);

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) $payload = [];
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Registro invÃ¡lido para exclusÃ£o.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $st = $pdo->prepare("DELETE FROM vendedor_erros_manuais WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    echo json_encode(['success' => true, 'deleted' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Converte data DD/MM/AAAA ou AAAA-MM-DD para SQL; vazio = null. */
function gcParseDataBrParaSql(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/u', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

/**
 * Importa linhas de planilha (CSV) para vendedor_erros_manuais.
 * Colunas esperadas (cabeÃ§alho): Data, RequisiÃ§Ã£o, Consultor, Tipo de Erro, ObservaÃ§Ã£o, Gravidade â€” ou ordem fixa se nÃ£o houver cabeÃ§alho reconhecido.
 * Delimitador: ; ou ,
 */
function gestaoComercialErrosImportarCsv(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    gcAssertAdminSession();
    gcEnsureControleErrosTable($pdo);

    $csv = '';
    if (!empty($_FILES['arquivo']['tmp_name']) && is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
        $csv = (string)file_get_contents($_FILES['arquivo']['tmp_name']);
    } else {
        $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (is_array($payload)) {
            $csv = (string)($payload['csv'] ?? '');
        }
    }
    $csv = trim($csv);
    if ($csv === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Envie um arquivo CSV (campo arquivo) ou o texto em JSON (chave csv).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $delim = (substr_count($csv, ';') >= substr_count($csv, ',')) ? ';' : ',';
    $lines = preg_split("/\r\n|\n|\r/", $csv);
    if (isset($lines[0]) && strncmp($lines[0], "\xEF\xBB\xBF", 3) === 0) {
        $lines[0] = substr($lines[0], 3);
    }
    $lines = array_values(array_filter($lines, static function ($l) {
        return trim((string)$l) !== '';
    }));
    if (count($lines) < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'CSV vazio.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $firstCells = str_getcsv($lines[0], $delim);
    $looksLikeDataRow = (bool)preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', trim((string)($firstCells[0] ?? '')))
        || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)($firstCells[0] ?? '')));

    $headerRow = null;
    $dataStart = 0;
    if ($looksLikeDataRow) {
        $headerRow = null;
        $dataStart = 0;
    } else {
        $headerRow = $firstCells;
        $dataStart = 1;
    }

    $col = ['data' => 0, 'req' => 1, 'consultor' => 2, 'tipo' => 3, 'obs' => 4, 'grav' => 5];
    if (is_array($headerRow)) {
        $map = [];
        foreach ($headerRow as $i => $h) {
            $k = mb_strtolower(trim((string)$h), 'UTF-8');
            $k = strtr($k, [
                'Ã¡' => 'a', 'Ã ' => 'a', 'Ã¢' => 'a', 'Ã£' => 'a',
                'Ã©' => 'e', 'Ãª' => 'e', 'Ã­' => 'i', 'Ã³' => 'o', 'Ã´' => 'o', 'Ãµ' => 'o', 'Ãº' => 'u', 'Ã§' => 'c',
            ]);
            $k = preg_replace('/[^a-z0-9]+/u', '_', $k);
            $k = trim($k, '_');
            $map[$k] = $i;
        }
        $pick = static function (array $map, array $names) {
            foreach ($names as $n) {
                if (isset($map[$n])) {
                    return $map[$n];
                }
            }
            return null;
        };
        $d = $pick($map, ['data', 'data_do_erro']);
        $r = $pick($map, ['requisicao', 'pedido', 'req']);
        $c = $pick($map, ['consultor', 'consultora', 'vendedor', 'vendedora']);
        $t = $pick($map, ['tipo_de_erro', 'tipo_erro', 'tipo']);
        $o = $pick($map, ['observacao', 'obs', 'descricao']);
        $g = $pick($map, ['gravidade_leve_medio_grave', 'gravidade', 'classificacao', 'severidade']);
        if ($d !== null) {
            $col['data'] = $d;
        }
        if ($r !== null) {
            $col['req'] = $r;
        }
        if ($c !== null) {
            $col['consultor'] = $c;
        }
        if ($t !== null) {
            $col['tipo'] = $t;
        }
        if ($o !== null) {
            $col['obs'] = $o;
        }
        if ($g !== null) {
            $col['grav'] = $g;
        }
    }

    $fallbackData = date('Y-m-01');
    $stIns = $pdo->prepare("
        INSERT INTO vendedor_erros_manuais (
            vendedor_nome, data_erro, tipo_erro, classificacao_erro,
            pontos_descontados, pedido_ref, descricao, created_by
        ) VALUES (
            :vendedor, :data_erro, :tipo_erro, :classificacao,
            :pontos, :pedido_ref, :descricao, :created_by
        )
    ");
    $stDup = $pdo->prepare("
        SELECT id FROM vendedor_erros_manuais
        WHERE vendedor_nome = :v
          AND data_erro = :d
          AND tipo_erro = :t
          AND COALESCE(pedido_ref, '') = :p
        LIMIT 1
    ");

    $inserted = 0;
    $skipped = 0;
    $errors = [];
    $uid = (int)($_SESSION['user_id'] ?? 0);

    for ($li = $dataStart; $li < count($lines); $li++) {
        $cells = str_getcsv($lines[$li], $delim);
        $get = static function ($idx) use ($cells) {
            return isset($cells[$idx]) ? trim((string)$cells[$idx]) : '';
        };
        $dataRaw = $get($col['data']);
        $req = $get($col['req']);
        $consultor = $get($col['consultor']);
        $tipo = $get($col['tipo']);
        $obs = $get($col['obs']);
        $gravRaw = $get($col['grav']);

        $vendedor = gcCanonicalVendedoraNome($consultor);
        if ($vendedor === '' || $tipo === '') {
            $skipped++;
            continue;
        }
        if (!gcIsAllowedVendedora($vendedor)) {
            $errors[] = 'Linha ' . ($li + 1) . ': consultor nÃ£o reconhecido na equipe comercial (' . $consultor . ').';
            $skipped++;
            continue;
        }

        $dataSql = gcParseDataBrParaSql($dataRaw);
        if ($dataSql === null) {
            $dataSql = $fallbackData;
        }
        $classificacao = gcNormalizeClassificacaoErro($gravRaw !== '' ? $gravRaw : 'leve');
        $pontos = gcPontosPadraoPorClassificacao($classificacao);
        $pontos = max(0.0, min(20.0, round($pontos, 2)));
        if (mb_strlen($tipo) > 120) {
            $tipo = mb_substr($tipo, 0, 120);
        }
        if (mb_strlen($req) > 80) {
            $req = mb_substr($req, 0, 80);
        }
        if (mb_strlen($obs) > 3000) {
            $obs = mb_substr($obs, 0, 3000);
        }

        $stDup->execute([
            'v' => $vendedor,
            'd' => $dataSql,
            't' => $tipo,
            'p' => $req,
        ]);
        if ($stDup->fetch(PDO::FETCH_ASSOC)) {
            $skipped++;
            continue;
        }

        $stIns->execute([
            'vendedor' => $vendedor,
            'data_erro' => $dataSql,
            'tipo_erro' => $tipo,
            'classificacao' => $classificacao,
            'pontos' => $pontos,
            'pedido_ref' => $req !== '' ? $req : null,
            'descricao' => $obs !== '' ? $obs : null,
            'created_by' => $uid,
        ]);
        $inserted++;
    }

    echo json_encode([
        'success' => true,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'hints' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function gcNormName(string $v): string
{
    $v = trim($v);
    $v = strtr($v, [
        'Ã'=>'A','Ã€'=>'A','Ã‚'=>'A','Ãƒ'=>'A','Ã„'=>'A',
        'Ã¡'=>'a','Ã '=>'a','Ã¢'=>'a','Ã£'=>'a','Ã¤'=>'a',
        'Ã‰'=>'E','Ãˆ'=>'E','ÃŠ'=>'E','Ã‹'=>'E',
        'Ã©'=>'e','Ã¨'=>'e','Ãª'=>'e','Ã«'=>'e',
        'Ã'=>'I','ÃŒ'=>'I','ÃŽ'=>'I','Ã'=>'I',
        'Ã­'=>'i','Ã¬'=>'i','Ã®'=>'i','Ã¯'=>'i',
        'Ã“'=>'O','Ã’'=>'O','Ã”'=>'O','Ã•'=>'O','Ã–'=>'O',
        'Ã³'=>'o','Ã²'=>'o','Ã´'=>'o','Ãµ'=>'o','Ã¶'=>'o',
        'Ãš'=>'U','Ã™'=>'U','Ã›'=>'U','Ãœ'=>'U',
        'Ãº'=>'u','Ã¹'=>'u','Ã»'=>'u','Ã¼'=>'u',
        'Ã‡'=>'C','Ã§'=>'c',
    ]);
    if (function_exists('mb_strtolower')) {
        $v = mb_strtolower($v, 'UTF-8');
    } else {
        $v = strtolower($v);
    }
    $v = preg_replace('/\s+/', ' ', $v);
    return $v ?? '';
}

function gcAllowedVendedorasMap(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $allow = [
        'Nailena',
        'Mariana',
        'Jessica Vitoria',
        'Carla',
        'Nereida',
        'Giovanna',
        'Clara Leticia',
        'Ananda Reis',
        'Micaela',
    ];
    $map = [];
    foreach ($allow as $n) {
        $map[gcNormName($n)] = true;
    }
    return $map;
}

/**
 * Unifica grafias (ex.: "Clara", "Clara Leticia") para o nome canÃ´nico da equipe,
 * evitando que o mesmo consultor apareÃ§a vÃ¡rias vezes nos grÃ¡ficos e KPIs.
 */
function gcCanonicalVendedoraNome(string $nome): string
{
    $nome = trim($nome);
    if ($nome === '') {
        return '';
    }
    $canonList = [
        'Clara Leticia', 'Ananda Reis', 'Nailena', 'Mariana', 'Jessica Vitoria',
        'Carla', 'Nereida', 'Giovanna', 'Micaela',
    ];
    $nk = gcNormName($nome);
    foreach ($canonList as $c) {
        if (gcNormName($c) === $nk) {
            return $c;
        }
    }
    $parts = preg_split('/\s+/', $nk, 2);
    $first = $parts[0] ?? '';
    $firstToFull = [
        'clara' => 'Clara Leticia',
        'ananda' => 'Ananda Reis',
        'jessica' => 'Jessica Vitoria',
        'nailena' => 'Nailena',
        'mariana' => 'Mariana',
        'carla' => 'Carla',
        'nereida' => 'Nereida',
        'giovanna' => 'Giovanna',
        'micaela' => 'Micaela',
        'vitoria' => 'Jessica Vitoria',
    ];
    if ($first !== '' && isset($firstToFull[$first])) {
        return $firstToFull[$first];
    }
    return $nome;
}

/** Normaliza gravidade para leve|medio|grave (aceita acentos e variaÃ§Ãµes). */
function gcNormalizeClassificacaoErro(string $raw): string
{
    $s = trim($raw);
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    $s = strtr($s, [
        'Ã¡' => 'a', 'Ã ' => 'a', 'Ã¢' => 'a', 'Ã£' => 'a', 'Ã¤' => 'a',
        'Ã©' => 'e', 'Ãª' => 'e', 'Ã­' => 'i', 'Ã³' => 'o', 'Ã´' => 'o', 'Ãµ' => 'o', 'Ãº' => 'u', 'Ã§' => 'c',
    ]);
    if ($s === 'grave') {
        return 'grave';
    }
    if ($s === 'medio') {
        return 'medio';
    }
    return 'leve';
}

/** Pontos padrÃ£o descontados do score por gravidade (ajustÃ¡veis em um sÃ³ lugar). */
function gcPontosPadraoPorClassificacao(string $classificacao): float
{
    return match ($classificacao) {
        'grave' => 3.0,
        'medio' => 2.0,
        default => 1.0,
    };
}

/**
 * Faixa de atÃ© 20 pontos no bloco "erros" do score, conforme total de registros no perÃ­odo
 * (planilha: 0â†’20, 1â†’18, 2â†’15, 3â†’12, 4 ou maisâ†’5).
 */
function gcPontosErrosScorePorTotal(int $totalErros): float
{
    return match (true) {
        $totalErros <= 0 => 20.0,
        $totalErros === 1 => 18.0,
        $totalErros === 2 => 15.0,
        $totalErros === 3 => 12.0,
        default => 5.0,
    };
}

function gcIsAllowedVendedora(string $nome): bool
{
    $k = gcNormName($nome);
    if ($k === '') return false;
    $allow = gcAllowedVendedorasMap();
    return isset($allow[$k]);
}

function gcApprovedCase(string $alias = 'gp'): string
{
    return "(
        {$alias}.status_financeiro IS NULL OR
        (
            {$alias}.status_financeiro NOT IN ('Recusado', 'Cancelado', 'OrÃ§amento')
            AND {$alias}.status_financeiro NOT LIKE '%carrinho%'
        )
    )";
}

/** Meses (Y-m) do 1Âº ao Ãºltimo mÃªs cobertos pelo intervalo [start, end]. */
function gcMonthKeysBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $first = $start->modify('first day of this month');
    $lastMonth = $end->modify('first day of this month');
    $out = [];
    $cur = $first;
    if ($cur > $lastMonth) {
        return [$start->format('Y-m')];
    }
    while ($cur <= $lastMonth) {
        $out[] = $cur->format('Y-m');
        $cur = $cur->modify('+1 month');
    }
    return $out;
}

/**
 * Receita aprovada em gestao_pedidos por mÃªs e por vendedora (lista permitida).
 *
 * @param array<int, array<string, mixed>> $rowsComAtendente Linhas com chave atendente (ex.: equipe).
 * @return array{meses: string[], series: array<int, array{atendente: string, valores: float[]}>}
 */
function gcBuildEvolucaoMensalVendedores(PDO $pdo, DateTimeImmutable $startObj, DateTimeImmutable $endObj, string $approvedGp, array $rowsComAtendente): array
{
    $meses = gcMonthKeysBetween($startObj, $endObj);
    if ($meses === []) {
        return ['meses' => [], 'series' => []];
    }
    $ini = $startObj->format('Y-m-d');
    $fim = $endObj->format('Y-m-d');
    $sql = "
        SELECT
            DATE_FORMAT(gp.data_aprovacao, '%Y-%m') AS ym,
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY ym, COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
    ";
    $raw = gcTryFetchAll($pdo, $sql, ['ini' => $ini, 'fim' => $fim]);
    $matrix = [];
    $nomeByNorm = [];
    foreach ($raw as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if (!gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        if (!isset($nomeByNorm[$nk])) {
            $nomeByNorm[$nk] = $nome;
        }
        $ym = (string)($row['ym'] ?? '');
        $matrix[$nk][$ym] = round((float)($row['receita'] ?? 0), 2);
    }
    $normsOrdered = [];
    foreach ($rowsComAtendente as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        $normsOrdered[$nk] = $nome;
    }
    foreach ($matrix as $nk => $_) {
        if (!isset($normsOrdered[$nk]) && isset($nomeByNorm[$nk])) {
            $normsOrdered[$nk] = $nomeByNorm[$nk];
        }
    }
    uasort($normsOrdered, static function (string $a, string $b): int {
        return strcmp($a, $b);
    });
    $series = [];
    foreach ($normsOrdered as $nk => $nome) {
        $vals = [];
        foreach ($meses as $ym) {
            $vals[] = round((float)($matrix[$nk][$ym] ?? 0), 2);
        }
        $series[] = [
            'atendente' => $nome,
            'valores' => $vals,
        ];
    }
    return [
        'meses' => $meses,
        'series' => $series,
    ];
}

/**
 * Contagem de linhas (gestao_pedidos) por mÃªs e vendedora: aprovadas ou rejeitadas (mesmo critÃ©rio do painel).
 *
 * @param 'aprovados'|'rejeitados' $tipo
 * @return array{meses: string[], series: array<int, array{atendente: string, valores: float[]}>}
 */
function gcBuildEvolucaoMensalVendedoresContagem(
    PDO $pdo,
    DateTimeImmutable $startObj,
    DateTimeImmutable $endObj,
    string $approvedGp,
    array $rowsComAtendente,
    string $tipo
): array {
    $meses = gcMonthKeysBetween($startObj, $endObj);
    if ($meses === []) {
        return ['meses' => [], 'series' => []];
    }
    $ini = $startObj->format('Y-m-d');
    $fim = $endObj->format('Y-m-d');
    $filtroLinha = $tipo === 'aprovados'
        ? "AND {$approvedGp}"
        : "AND NOT {$approvedGp}";
    $sql = "
        SELECT
            DATE_FORMAT(gp.data_aprovacao, '%Y-%m') AS ym,
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COUNT(*) AS qtd
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        {$filtroLinha}
        GROUP BY ym, COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
    ";
    $raw = gcTryFetchAll($pdo, $sql, ['ini' => $ini, 'fim' => $fim]);
    $matrix = [];
    $nomeByNorm = [];
    foreach ($raw as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if (!gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        if (!isset($nomeByNorm[$nk])) {
            $nomeByNorm[$nk] = $nome;
        }
        $ym = (string)($row['ym'] ?? '');
        $matrix[$nk][$ym] = (int)($row['qtd'] ?? 0);
    }

    // Rejeitados: mesma lÃ³gica do painel da equipe â€” somar linhas em itens_orcamentos_pedidos (Recusado / No carrinho),
    // pois gestao_pedidos muitas vezes nÃ£o reflete esses desfechos sozinho.
    if ($tipo === 'rejeitados') {
        $rawItens = gcTryFetchAll($pdo, "
            SELECT
                DATE_FORMAT(i.data, '%Y-%m') AS ym,
                COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS atendente,
                COUNT(*) AS qtd
            FROM itens_orcamentos_pedidos i
            LEFT JOIN gestao_pedidos gpLink
              ON gpLink.numero_pedido = i.numero
             AND gpLink.serie_pedido = i.serie
             AND gpLink.ano_referencia = i.ano_referencia
            WHERE DATE(i.data) BETWEEN :ini AND :fim
              AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
            GROUP BY ym, COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
        ", ['ini' => $ini, 'fim' => $fim]);
        foreach ($rawItens as $row) {
            $nome = trim((string)($row['atendente'] ?? ''));
            if ($nome === '' || $nome === '(Sem atendente)' || !gcIsAllowedVendedora($nome)) {
                continue;
            }
            $nk = gcNormName($nome);
            if ($nk === '') {
                continue;
            }
            if (!isset($nomeByNorm[$nk])) {
                $nomeByNorm[$nk] = $nome;
            }
            $ym = (string)($row['ym'] ?? '');
            $q = (int)($row['qtd'] ?? 0);
            $matrix[$nk][$ym] = ($matrix[$nk][$ym] ?? 0) + $q;
        }
    }
    $normsOrdered = [];
    foreach ($rowsComAtendente as $row) {
        $nome = trim((string)($row['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if ($nk === '') {
            continue;
        }
        $normsOrdered[$nk] = $nome;
    }
    foreach ($matrix as $nk => $_) {
        if (!isset($normsOrdered[$nk]) && isset($nomeByNorm[$nk])) {
            $normsOrdered[$nk] = $nomeByNorm[$nk];
        }
    }
    uasort($normsOrdered, static function (string $a, string $b): int {
        return strcmp($a, $b);
    });
    $series = [];
    foreach ($normsOrdered as $nk => $nome) {
        $vals = [];
        foreach ($meses as $ym) {
            $vals[] = (float)($matrix[$nk][$ym] ?? 0);
        }
        $series[] = [
            'atendente' => $nome,
            'valores' => $vals,
        ];
    }
    return [
        'meses' => $meses,
        'series' => $series,
    ];
}

/**
 * Aprovados + rejeitados mensais (ano fixo 2026), mesma lista de consultoras da receita.
 *
 * @return array{aprovados: array, rejeitados: array}
 */
function gcEvolucaoMensalAprovRejPayload(PDO $pdo, string $approvedGp, array $rowsComAtendente): array
{
    $ano = 2026;
    $ini = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
    $fim = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
    $ap = gcBuildEvolucaoMensalVendedoresContagem($pdo, $ini, $fim, $approvedGp, $rowsComAtendente, 'aprovados');
    $rj = gcBuildEvolucaoMensalVendedoresContagem($pdo, $ini, $fim, $approvedGp, $rowsComAtendente, 'rejeitados');
    $meta = [
        'ano_fixo' => $ano,
        'usa_filtro_painel' => false,
    ];
    return [
        'aprovados' => array_merge($ap, $meta),
        'rejeitados' => array_merge($rj, $meta),
    ];
}

/**
 * EvoluÃ§Ã£o mensal por vendedor: sempre calendÃ¡rio completo do ano fixo (ignora data_de/data_ate/ano/mÃªs do painel).
 */
function gcEvolucaoMensalVendedoresPayload(PDO $pdo, string $approvedGp, array $rowsComAtendente): array
{
    $ano = 2026;
    $ini = new DateTimeImmutable(sprintf('%04d-01-01', $ano));
    $fim = new DateTimeImmutable(sprintf('%04d-12-31', $ano));
    $out = gcBuildEvolucaoMensalVendedores($pdo, $ini, $fim, $approvedGp, $rowsComAtendente);
    $out['ano_fixo'] = $ano;
    $out['usa_filtro_painel'] = false;
    return $out;
}

/** Alinhado a api_gestao handleVendedorDashboardGestao â€” comissÃ£o individual por % da meta. */
function gcCalcComissaoIndividualPct(float $percentualMeta): float
{
    if ($percentualMeta >= 110.0) {
        return 2.0;
    }
    if ($percentualMeta >= 100.0) {
        return 1.8;
    }
    if ($percentualMeta >= 90.0) {
        return 1.3;
    }
    if ($percentualMeta >= 70.0) {
        return 1.0;
    }
    return 0.5;
}

/** Faixa de comissÃ£o extra por faturamento do grupo (receita aprovada no perÃ­odo, soma da equipe). */
function gcCalcComissaoGrupoPct(float $receitaGrupo): float
{
    if ($receitaGrupo >= 370000.0) {
        return 2.5;
    }
    if ($receitaGrupo >= 350000.0) {
        return 2.0;
    }
    if ($receitaGrupo >= 320000.0) {
        return 1.5;
    }
    return 0.0;
}

/** Regras estÃ¡ticas de comissÃ£o individual (painel comercial). */
function gcRelatorioFaixasComissaoIndividual(): array
{
    return [
        ['meta_pct_faixa' => 'AtÃ© 69%', 'intervalo' => 'AtÃ© R$ 40.709,00', 'comissao_percentual' => 0.5],
        ['meta_pct_faixa' => '70% a 89%', 'intervalo' => 'R$ 40.710,00 a R$ 52.510,00', 'comissao_percentual' => 1.0],
        ['meta_pct_faixa' => '90% a 99%', 'intervalo' => 'R$ 52.511,00 a R$ 58.409,00', 'comissao_percentual' => 1.3],
        ['meta_pct_faixa' => '100% a 109%', 'intervalo' => 'R$ 59.000,00 a R$ 64.309,00', 'comissao_percentual' => 1.8],
        ['meta_pct_faixa' => '110%+', 'intervalo' => 'Acima de R$ 70.000,00', 'comissao_percentual' => 2.0],
    ];
}

/** Regras estÃ¡ticas de comissÃ£o grupo (painel comercial). */
function gcRelatorioFaixasComissaoGrupo(): array
{
    return [
        ['faixa_receita' => 'R$ 320.000 a R$ 349.999', 'percentual' => 1.5],
        ['faixa_receita' => 'R$ 350.000 a R$ 369.999', 'percentual' => 2.0],
        ['faixa_receita' => 'Acima de R$ 370.000', 'percentual' => 2.5],
    ];
}

/**
 * Bloco "RelatÃ³rios" (Semanal, Mensal e ComissÃ£o) para aba dedicada.
 * Usa exclusivamente dados do banco no perÃ­odo filtrado.
 */
function gcBuildRelatoriosComerciais(PDO $pdo, string $start, string $end, string $approvedGp, array $vendedores): array
{
    $vendByNorm = [];
    foreach ($vendedores as $v) {
        $nome = trim((string)($v['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) continue;
        $vendByNorm[gcNormName($nome)] = $v;
    }

    $semanalRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) BETWEEN 1 AND 7 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_1,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) BETWEEN 8 AND 14 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_2,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) BETWEEN 15 AND 21 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_3,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) >= 22 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_4,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS total_vendido
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
    ", ['ini' => $start, 'fim' => $end]);
    $semanalMap = [];
    foreach ($semanalRaw as $r) {
        $nome = trim((string)($r['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) continue;
        $semanalMap[gcNormName($nome)] = $r;
    }

    $orcRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS atendente,
            COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))) AS total_orcamentos
        FROM itens_orcamentos_pedidos i
        LEFT JOIN gestao_pedidos gpLink
          ON gpLink.numero_pedido = i.numero
         AND gpLink.serie_pedido = i.serie
         AND gpLink.ano_referencia = i.ano_referencia
        WHERE DATE(i.data) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
    ", ['ini' => $start, 'fim' => $end]);
    $orcMap = [];
    foreach ($orcRaw as $r) {
        $nome = trim((string)($r['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) continue;
        $orcMap[gcNormName($nome)] = (int)($r['total_orcamentos'] ?? 0);
    }

    $rejeitadosItensMap = [];
    $statusItensRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS atendente,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(i.status, ''))) IN ('no carrinho', 'recusado') THEN 1 ELSE 0 END) AS linhas_rejeitados_funnel
        FROM itens_orcamentos_pedidos i
        LEFT JOIN gestao_pedidos gpLink
          ON gpLink.numero_pedido = i.numero
         AND gpLink.serie_pedido = i.serie
         AND gpLink.ano_referencia = i.ano_referencia
        WHERE DATE(i.data) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($statusItensRaw as $r) {
        $nome = trim((string)($r['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $k = gcNormName($nome);
        $rejeitadosItensMap[$k] = (int)($r['linhas_rejeitados_funnel'] ?? 0);
    }

    $errosCountMap = [];
    gcEnsureControleErrosTable($pdo);
    gcEnsureVendedorPerdasAcoesTableGc($pdo);
    // Score "Pts erros": tabela por quantidade total de registros (manuais + perdas), nÃ£o soma de pontos_descontados.
    $cntPerdas = gcTryFetchAll($pdo, "
        SELECT TRIM(vpa.vendedor_nome) AS atendente, COUNT(*) AS qtd
        FROM vendedor_perdas_acoes vpa
        WHERE DATE(COALESCE(vpa.data_perda, vpa.created_at)) BETWEEN :ini AND :fim
        GROUP BY TRIM(vpa.vendedor_nome)
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($cntPerdas as $r) {
        $nome = gcCanonicalVendedoraNome(trim((string)($r['atendente'] ?? '')));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        $errosCountMap[$nk] = (int)($errosCountMap[$nk] ?? 0) + (int)($r['qtd'] ?? 0);
    }
    $cntManuais = gcTryFetchAll($pdo, "
        SELECT TRIM(vendedor_nome) AS atendente, COUNT(*) AS qtd
        FROM vendedor_erros_manuais
        WHERE data_erro BETWEEN :ini AND :fim
        GROUP BY TRIM(vendedor_nome)
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($cntManuais as $r) {
        $nome = gcCanonicalVendedoraNome(trim((string)($r['atendente'] ?? '')));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        $errosCountMap[$nk] = (int)($errosCountMap[$nk] ?? 0) + (int)($r['qtd'] ?? 0);
    }

    $bonusMetaScore85 = 200.0;
    $bonusUltrapassaScore95 = 400.0;
    $rowsSemanal = [];
    $rowsMensal = [];
    $totSem1 = 0.0; $totSem2 = 0.0; $totSem3 = 0.0; $totSem4 = 0.0; $totReceita = 0.0;
    foreach ($vendByNorm as $nk => $v) {
        $nome = trim((string)($v['atendente'] ?? ''));
        $meta = (float)($v['meta_mensal'] ?? 0);
        $receita = (float)($v['receita'] ?? 0);
        $aprov = (int)($v['vendas_aprovadas'] ?? 0);
        $rej = (int)($v['vendas_rejeitadas'] ?? 0);
        $orc = (int)($orcMap[$nk] ?? max($aprov + $rej, 0));
        $rjIt = (int)($rejeitadosItensMap[$nk] ?? 0);
        // Denominador: orÃ§amentos + pedidos + (no carrinho + recusado, linhas de item); taxa = pedidos / total.
        $denConv = $orc + $aprov + $rjIt;
        $convRatio = $denConv > 0 ? ($aprov / $denConv) : 0.0;
        $conv = round($convRatio * 100, 2);
        $sem = $semanalMap[$nk] ?? null;
        $s1 = (float)($sem['semana_1'] ?? 0);
        $s2 = (float)($sem['semana_2'] ?? 0);
        $s3 = (float)($sem['semana_3'] ?? 0);
        $s4 = (float)($sem['semana_4'] ?? 0);
        $totalSemanas = $s1 + $s2 + $s3 + $s4;
        // Semanal: usa exatamente o total exibido na linha para evitar divergÃªncia no % da meta.
        $receitaBaseSemanal = abs($receita - $totalSemanas) > 0.01 ? $totalSemanas : $receita;
        $pctMetaSemanal = $meta > 0 ? round(($receitaBaseSemanal / $meta) * 100, 2) : 0.0;
        $pctMetaMensal = $meta > 0 ? round(($receita / $meta) * 100, 2) : 0.0;
        $ratioMetaMensal = $meta > 0 ? ($receita / $meta) : 0.0;
        $falta = $meta > 0 ? max(0.0, $meta - $receitaBaseSemanal) : 0.0;
        $crm = 5.0;
        // Sheets: =SE(D2>=1;50;D2*50), onde D2 é a razão da meta (ex.: 0,47 = 47%).
        $pFatRaw = $ratioMetaMensal >= 1.0 ? 50.0 : ($ratioMetaMensal * 50.0);
        $pFat = round(min(50.0, max(0.0, $pFatRaw)), 0);
        // Sheets: =SE(G2>=0,4;20;(G2/0,4)*20), onde G2 é a razão de conversão (0..1).
        $pConvRaw = $convRatio >= 0.4 ? 20.0 : (($convRatio / 0.4) * 20.0);
        $pConv = round(min(20.0, max(0.0, $pConvRaw)), 2);
        $totalErrosScore = (int)($errosCountMap[$nk] ?? 0);
        $pErros = gcPontosErrosScorePorTotal($totalErrosScore);
        $score = round($pFat + $pConv + $pErros + $crm, 2);
        $status = $score >= 80 ? 'Muito Bom' : ($score >= 65 ? 'Bom' : ($score >= 50 ? 'Plano de Ação' : 'Crítico'));
        $ci = (float)gcCalcComissaoIndividualPct($pctMetaSemanal);
        $cg = (float)($v['comissao_pct_grupo'] ?? 0);
        $ct = round($ci + $cg, 2);
        $comissao = round($receitaBaseSemanal * ($ct / 100), 2);
        $bonus = 0.0;
        if ($pctMetaSemanal > 100 && $score > 95) {
            $bonus = $bonusUltrapassaScore95;
        } elseif ($pctMetaSemanal >= 100 && $score > 85) {
            $bonus = $bonusMetaScore85;
        }
        $comissaoComBonus = round($comissao + $bonus, 2);
        $totSem1 += $s1; $totSem2 += $s2; $totSem3 += $s3; $totSem4 += $s4; $totReceita += $receita;

        $rowsSemanal[] = [
            'vendedora' => $nome,
            'meta_individual' => round($meta, 2),
            'venda_semana_1' => round($s1, 2),
            'venda_semana_2' => round($s2, 2),
            'venda_semana_3' => round($s3, 2),
            'venda_semana_4' => round($s4, 2),
            'total_vendido' => round($receitaBaseSemanal, 2),
            'percentual_atingido' => $pctMetaSemanal,
            'falta_meta' => round($falta, 2),
            'comissao_valor' => $comissao,
            'bonus_valor' => round($bonus, 2),
            'comissao_total' => $comissaoComBonus,
        ];

        $rowsMensal[] = [
            'vendedora' => $nome,
            'meta_individual' => round($meta, 2),
            'venda_total_mes' => round($receita, 2),
            'percentual_meta' => $pctMetaMensal,
            'orcamentos' => $orc,
            'pedidos' => $aprov,
            'conversao' => $conv,
            'rejeitados_itens' => $rjIt,
            'erros' => (int)($errosCountMap[$nk] ?? 0),
            'crm' => $crm,
            'pontos_faturamento' => $pFat,
            'pontos_conversao' => $pConv,
            'pontos_erros' => $pErros,
            'score_final' => $score,
            'status' => $status,
        ];
    }

    usort($rowsSemanal, static function (array $a, array $b): int {
        return strcmp((string)$a['vendedora'], (string)$b['vendedora']);
    });
    usort($rowsMensal, static function (array $a, array $b): int {
        return strcmp((string)$a['vendedora'], (string)$b['vendedora']);
    });

    return [
        'periodo' => ['data_de' => $start, 'data_ate' => $end],
        'semanal' => [
            'linhas' => $rowsSemanal,
            'totais' => [
                'semana_1' => round($totSem1, 2),
                'semana_2' => round($totSem2, 2),
                'semana_3' => round($totSem3, 2),
                'semana_4' => round($totSem4, 2),
                'receita_total' => round($totReceita, 2),
            ],
        ],
        'mensal' => ['linhas' => $rowsMensal],
        'comissao' => [
            'faixas_individuais' => gcRelatorioFaixasComissaoIndividual(),
            'faixas_grupo' => gcRelatorioFaixasComissaoGrupo(),
            'premios_score' => [
                ['regra' => 'Bate meta + score acima de 85', 'premio' => 200],
                ['regra' => 'Ultrapassa meta + score acima de 95', 'premio' => 400],
            ],
        ],
    ];
}

function gcEnsureVendedorPerdasAcoesTableGc(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vendedor_perdas_acoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ano_referencia INT NOT NULL,
            numero_pedido VARCHAR(40) NOT NULL,
            serie_pedido VARCHAR(20) NOT NULL DEFAULT '',
            vendedor_nome VARCHAR(190) NOT NULL,
            data_perda DATE NULL,
            motivo_perda VARCHAR(255) NULL,
            classificacao_erro VARCHAR(20) NULL,
            pontos_descontados DECIMAL(10,2) NOT NULL DEFAULT 0,
            observacoes TEXT NULL,
            updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pedido_vendedor (ano_referencia, numero_pedido, serie_pedido, vendedor_nome)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    ");
}

function gcDisplayConsultoraNamePhp(string $nome): string
{
    $map = [
        'clara leticia' => 'Clara LetÃ­cia',
        'jessica vitoria' => 'JÃ©ssica VitÃ³ria',
        'ananda reis' => 'Ananda Reis',
        'Micaela',
    ];
    $k = gcNormName($nome);
    return $map[$k] ?? $nome;
}

/**
 * KPIs globais, tabela de erros (manuais + perdas) e bullets de conclusÃ£o para a pÃ¡gina Resumo de erros.
 */
function gcBuildResumoErrosPayload(PDO $pdo, string $ini, string $fim): array
{
    gcEnsureControleErrosTable($pdo);
    gcEnsureVendedorPerdasAcoesTableGc($pdo);

    $approvedGp = gcApprovedCase('gp');

    $metaPorNome = [];
    try {
        $st = $pdo->query("
            SELECT TRIM(nome) AS nome, COALESCE(meta_mensal, 0) AS meta_mensal
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
              AND COALESCE(ativo, 1) = 1
        ");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mr) {
            $nm = trim((string)($mr['nome'] ?? ''));
            if ($nm === '' || !gcIsAllowedVendedora($nm)) {
                continue;
            }
            $metaPorNome[gcNormName($nm)] = (float)($mr['meta_mensal'] ?? 0);
        }
    } catch (Throwable $e) {
        $metaPorNome = [];
    }

    $metaSistema = (float)(getenv('VENDEDOR_META_SISTEMA') ?: 60000);
    if ($metaSistema <= 0) {
        $metaSistema = 60000.0;
    }

    $vendRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS vendas_aprovadas
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
    ", ['ini' => $ini, 'fim' => $fim]);

    $orcRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS atendente,
            COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))) AS total_orcamentos
        FROM itens_orcamentos_pedidos i
        LEFT JOIN gestao_pedidos gpLink
          ON gpLink.numero_pedido = i.numero
         AND gpLink.serie_pedido = i.serie
         AND gpLink.ano_referencia = i.ano_referencia
        WHERE DATE(i.data) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
    ", ['ini' => $ini, 'fim' => $fim]);

    $semRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) BETWEEN 1 AND 7 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_1,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) BETWEEN 8 AND 14 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_2,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) BETWEEN 15 AND 21 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_3,
            COALESCE(SUM(CASE WHEN {$approvedGp} AND DAY(gp.data_aprovacao) >= 22 THEN gp.preco_liquido ELSE 0 END), 0) AS semana_4
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
    ", ['ini' => $ini, 'fim' => $fim]);

    $receitaMap = [];
    $aprovMap = [];
    foreach ($vendRaw as $r) {
        $nome = trim((string)($r['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        $receitaMap[$nk] = (float)($r['receita'] ?? 0);
        $aprovMap[$nk] = (int)($r['vendas_aprovadas'] ?? 0);
    }

    $orcMap = [];
    foreach ($orcRaw as $r) {
        $nome = trim((string)($r['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $orcMap[gcNormName($nome)] = (int)($r['total_orcamentos'] ?? 0);
    }

    $semMap = [];
    foreach ($semRaw as $r) {
        $nome = trim((string)($r['atendente'] ?? ''));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $semMap[gcNormName($nome)] = $r;
    }

    $norms = array_keys(gcAllowedVendedorasMap());
    $equipe = [];
    foreach ($norms as $nk) {
        $nomeCanon = '';
        foreach (['Clara Leticia', 'Ananda Reis', 'Nailena', 'Mariana', 'Jessica Vitoria', 'Carla', 'Nereida', 'Giovanna', 'Micaela'] as $cand) {
            if (gcNormName($cand) === $nk) {
                $nomeCanon = $cand;
                break;
            }
        }
        if ($nomeCanon === '') {
            continue;
        }
        $meta = (float)($metaPorNome[$nk] ?? 0);
        if ($meta <= 0) {
            $meta = $metaSistema;
        }
        $receita = (float)($receitaMap[$nk] ?? 0);
        $orc = (int)($orcMap[$nk] ?? 0);
        $aprov = (int)($aprovMap[$nk] ?? 0);
        if ($orc <= 0 && $aprov > 0) {
            $orc = max($orc, $aprov);
        }
        $conv = $orc > 0 ? round(($aprov / $orc) * 100, 2) : 0.0;
        $pctMeta = $meta > 0 ? round(($receita / $meta) * 100, 2) : 0.0;
        $equipe[] = [
            'norm' => $nk,
            'vendedora' => gcDisplayConsultoraNamePhp($nomeCanon),
            'meta' => round($meta, 2),
            'receita' => round($receita, 2),
            'percentual_meta' => $pctMeta,
            'orcamentos' => $orc,
            'conversao' => $conv,
        ];
    }

    usort($equipe, static function ($a, $b) {
        return strcmp((string)$a['vendedora'], (string)$b['vendedora']);
    });

    $metaGlobal = 0.0;
    $fatGlobal = 0.0;
    foreach ($equipe as $row) {
        $metaGlobal += (float)$row['meta'];
        $fatGlobal += (float)$row['receita'];
    }
    $pctGlobal = $metaGlobal > 0 ? round(($fatGlobal / $metaGlobal) * 100, 2) : 0.0;

    $manualRows = gcTryFetchAll($pdo, "
        SELECT
            TRIM(vendedor_nome) AS nome,
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(TRIM(classificacao_erro)) = 'leve' THEN 1 ELSE 0 END) AS leves,
            SUM(CASE WHEN LOWER(TRIM(classificacao_erro)) IN ('medio','mÃ©dio') THEN 1 ELSE 0 END) AS medios,
            SUM(CASE WHEN LOWER(TRIM(classificacao_erro)) = 'grave' THEN 1 ELSE 0 END) AS graves,
            COALESCE(SUM(pontos_descontados), 0) AS pts
        FROM vendedor_erros_manuais
        WHERE data_erro BETWEEN :ini AND :fim
        GROUP BY TRIM(vendedor_nome)
    ", ['ini' => $ini, 'fim' => $fim]);

    $perdasRows = gcTryFetchAll($pdo, "
        SELECT
            TRIM(vendedor_nome) AS nome,
            COUNT(*) AS total,
            SUM(CASE
                WHEN LOWER(TRIM(COALESCE(classificacao_erro, ''))) IN ('medio', 'mÃ©dio') THEN 0
                WHEN LOWER(TRIM(COALESCE(classificacao_erro, ''))) = 'grave' THEN 0
                ELSE 1
            END) AS leves,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(classificacao_erro, ''))) IN ('medio', 'mÃ©dio') THEN 1 ELSE 0 END) AS medios,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(classificacao_erro, ''))) = 'grave' THEN 1 ELSE 0 END) AS graves,
            COALESCE(SUM(pontos_descontados), 0) AS pts
        FROM vendedor_perdas_acoes
        WHERE DATE(COALESCE(data_perda, created_at)) BETWEEN :ini AND :fim
        GROUP BY TRIM(vendedor_nome)
    ", ['ini' => $ini, 'fim' => $fim]);

    $errAgg = [];
    foreach ($manualRows as $r) {
        $nome = gcCanonicalVendedoraNome(trim((string)($r['nome'] ?? '')));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if (!isset($errAgg[$nk])) {
            $errAgg[$nk] = ['nome' => $nome, 'total' => 0, 'leves' => 0, 'medios' => 0, 'graves' => 0, 'pts' => 0.0];
        }
        $errAgg[$nk]['total'] += (int)($r['total'] ?? 0);
        $errAgg[$nk]['leves'] += (int)($r['leves'] ?? 0);
        $errAgg[$nk]['medios'] += (int)($r['medios'] ?? 0);
        $errAgg[$nk]['graves'] += (int)($r['graves'] ?? 0);
        $errAgg[$nk]['pts'] += (float)($r['pts'] ?? 0);
    }
    foreach ($perdasRows as $r) {
        $nome = gcCanonicalVendedoraNome(trim((string)($r['nome'] ?? '')));
        if ($nome === '' || !gcIsAllowedVendedora($nome)) {
            continue;
        }
        $nk = gcNormName($nome);
        if (!isset($errAgg[$nk])) {
            $errAgg[$nk] = ['nome' => $nome, 'total' => 0, 'leves' => 0, 'medios' => 0, 'graves' => 0, 'pts' => 0.0];
        }
        $errAgg[$nk]['total'] += (int)($r['total'] ?? 0);
        $errAgg[$nk]['leves'] += (int)($r['leves'] ?? 0);
        $errAgg[$nk]['medios'] += (int)($r['medios'] ?? 0);
        $errAgg[$nk]['graves'] += (int)($r['graves'] ?? 0);
        $errAgg[$nk]['pts'] += (float)($r['pts'] ?? 0);
    }

    $linhasErros = [];
    foreach ($equipe as $ev) {
        $nk = $ev['norm'];
        $agg = $errAgg[$nk] ?? null;
        $total = $agg ? (int)$agg['total'] : 0;
        $leves = $agg ? (int)$agg['leves'] : 0;
        $medios = $agg ? (int)$agg['medios'] : 0;
        $graves = $agg ? (int)$agg['graves'] : 0;
        $pScore = gcPontosErrosScorePorTotal($total);
        $linhasErros[] = [
            'vendedora' => $ev['vendedora'],
            'total_erros' => $total,
            'erros_leves' => $leves,
            'erros_medios' => $medios,
            'erros_graves' => $graves,
            'pontos_no_score' => $pScore,
        ];
    }

    $acimaMeta = [];
    $abaixo70 = [];
    $topReceita = null;
    $maxRec = -1.0;
    foreach ($equipe as $ev) {
        if ($ev['percentual_meta'] > 100) {
            $acimaMeta[] = $ev['vendedora'] . ' (' . number_format($ev['percentual_meta'], 2, ',', '.') . '%)';
        }
        if ($ev['percentual_meta'] < 70 && $ev['percentual_meta'] > 0) {
            $abaixo70[] = $ev['vendedora'] . ' (' . number_format($ev['percentual_meta'], 2, ',', '.') . '%)';
        }
        if ($ev['receita'] > $maxRec) {
            $maxRec = $ev['receita'];
            $topReceita = $ev;
        }
    }

    $maxConv = null;
    $maxConvVal = -1.0;
    $maxOrc = null;
    $maxOrcVal = -1;
    foreach ($equipe as $ev) {
        if ($ev['conversao'] > $maxConvVal) {
            $maxConvVal = $ev['conversao'];
            $maxConv = $ev;
        }
        if ($ev['orcamentos'] > $maxOrcVal) {
            $maxOrcVal = $ev['orcamentos'];
            $maxOrc = $ev;
        }
    }

    $bestS4 = null;
    $bestS4Share = -1.0;
    foreach ($equipe as $ev) {
        $nk = $ev['norm'];
        $sr = $semMap[$nk] ?? null;
        if (!$sr) {
            continue;
        }
        $s1 = (float)($sr['semana_1'] ?? 0);
        $s2 = (float)($sr['semana_2'] ?? 0);
        $s3 = (float)($sr['semana_3'] ?? 0);
        $s4 = (float)($sr['semana_4'] ?? 0);
        $t = $s1 + $s2 + $s3 + $s4;
        if ($t <= 0) {
            continue;
        }
        $share = $s4 / $t;
        if ($share > $bestS4Share) {
            $bestS4Share = $share;
            $bestS4 = ['nome' => $ev['vendedora'], 'share' => round($share * 100, 1), 's4' => $s4, 'total' => $t];
        }
    }

    usort($linhasErros, static function ($a, $b) {
        return ($b['total_erros'] <=> $a['total_erros']) ?: strcmp((string)$a['vendedora'], (string)$b['vendedora']);
    });
    $topErr = array_slice($linhasErros, 0, 2);

    $convMed = count($equipe) > 0
        ? round(array_sum(array_column($equipe, 'conversao')) / count($equipe), 2)
        : 0.0;

    $c1 = '<strong>Desempenho individual e metas:</strong> ';
    if ($acimaMeta !== []) {
        $c1 .= 'Vendedoras acima de 100% da meta: ' . implode(', ', $acimaMeta) . '. ';
    } else {
        $c1 .= 'Nenhuma vendedora acima de 100% da meta no perÃ­odo. ';
    }
    if ($topReceita) {
        $c1 .= 'Maior faturamento: <strong>' . $topReceita['vendedora'] . '</strong> ('
            . 'R$ ' . number_format($topReceita['receita'], 2, ',', '.') . '). ';
    }
    if ($abaixo70 !== []) {
        $c1 .= 'AtenÃ§Ã£o (abaixo de 70% da meta): ' . implode(', ', $abaixo70) . '.';
    } else {
        $c1 .= 'Nenhuma vendedora abaixo de 70% da meta.';
    }

    $c2 = '<strong>EficiÃªncia de conversÃ£o:</strong> ';
    $c2 .= 'MÃ©dia de conversÃ£o da equipe: <strong>' . number_format($convMed, 2, ',', '.') . '%</strong>. ';
    if ($maxConv) {
        $c2 .= 'Maior taxa: <strong>' . $maxConv['vendedora'] . '</strong> (' . number_format($maxConv['conversao'], 2, ',', '.') . '%). ';
    }
    if ($maxOrc && $maxOrcVal > 0) {
        $c2 .= 'Maior volume de orÃ§amentos: <strong>' . $maxOrc['vendedora'] . '</strong> (' . number_format($maxOrcVal, 0, ',', '.') . ').';
    }

    $c3 = '<strong>DinÃ¢mica semanal:</strong> ';
    if ($bestS4 && $bestS4Share > 0.2) {
        $c3 .= 'A <strong>semana 4</strong> concentrou a maior fatia do faturamento individual em <strong>' . $bestS4['nome']
            . '</strong> (~' . number_format($bestS4['share'], 1, ',', '.') . '% do total dela no perÃ­odo).';
    } else {
        $c3 .= 'DistribuiÃ§Ã£o entre semanas variando por consultora; acompanhe o relatÃ³rio semanal na GestÃ£o comercial para detalhes.';
    }

    $c4 = '<strong>Erros e pontos no score (atÃ© 20):</strong> ';
    if (count($topErr) >= 2) {
        $c4 .= 'Maiores volumes de registros: <strong>' . $topErr[0]['vendedora'] . '</strong> (' . (int)$topErr[0]['total_erros'] . ') e <strong>'
            . $topErr[1]['vendedora'] . '</strong> (' . (int)$topErr[1]['total_erros'] . '). ';
    } elseif (count($topErr) === 1) {
        $c4 .= 'Maior volume: <strong>' . $topErr[0]['vendedora'] . '</strong> (' . (int)$topErr[0]['total_erros'] . '). ';
    }
    $c4 .= 'A coluna â€œPontos no scoreâ€ reflete 20 menos a soma dos pontos descontados (perdas + erros manuais), limitado entre 0 e 20.';

    return [
        'periodo' => ['data_de' => $ini, 'data_ate' => $fim],
        'kpis' => [
            'meta_global' => round($metaGlobal, 2),
            'faturamento_total' => round($fatGlobal, 2),
            'percentual_global' => $pctGlobal,
        ],
        'conclusoes' => [$c1, $c2, $c3, $c4],
        'linhas' => $linhasErros,
    ];
}

function gestaoComercialResumoErros(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    gcAssertAdminSession();
    [$startObj, $endObj] = gcDateRangeFromInput();
    $ini = $startObj->format('Y-m-d');
    $fim = $endObj->format('Y-m-d');
    $payload = gcBuildResumoErrosPayload($pdo, $ini, $fim);
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Painel GestÃ£o Comercial com mÃ©tricas exclusivamente da API RD Station CRM (crm.rdstation.com).
 * MantÃ©m o mesmo formato JSON do dashboard MySQL para o frontend nÃ£o duplicar renderizaÃ§Ã£o.
 */
function gestaoComercialDashboardRdOnly(PDO $pdo): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    if (function_exists('ini_set')) {
        @ini_set('max_execution_time', '300');
    }

    if (strtolower((string)($_SESSION['user_tipo'] ?? '')) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $rdToken = trim((string)(function_exists('getenv') ? getenv('RDSTATION_CRM_TOKEN') : '') ?: '');
    if ($rdToken === '') {
        echo json_encode([
            'success' => false,
            'error'   => 'RDSTATION_CRM_TOKEN nÃ£o configurado no .env. Sem token nÃ£o Ã© possÃ­vel carregar o painel sÃ³ com o CRM.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!function_exists('rdFetchTodasMetricas')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'IntegraÃ§Ã£o RD Station indisponÃ­vel no servidor.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$startObj, $endObj, $periodType] = gcDateRangeFromInput();
    $start = $startObj->format('Y-m-d');
    $end = $endObj->format('Y-m-d');
    $prevStart = $startObj->modify('-1 month')->format('Y-m-d');
    $prevEnd = $endObj->modify('-1 month')->format('Y-m-d');
    $lyStart = $startObj->modify('-1 year')->format('Y-m-d');
    $lyEnd = $endObj->modify('-1 year')->format('Y-m-d');

    $refreshRd = isset($_GET['refresh_rd']) ? strtolower(trim((string)$_GET['refresh_rd'])) : '';
    $forceRefresh = in_array($refreshRd, ['1', 'true', 'yes', 'on'], true);

    $rdNow = null;
    $cacheKey = 'gc_rd_dashboard_v5_' . md5($start . '|' . $end);
    $cacheFile = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    try {
        if (is_file($cacheFile) && !$forceRefresh) {
            $cached = @file_get_contents($cacheFile);
            $cachedArr = is_string($cached) ? json_decode($cached, true) : null;
            if (is_array($cachedArr) && isset($cachedArr['receita_total'])) {
                $rdNow = $cachedArr;
            }
        }
    } catch (Throwable $e) {
        // cache Ã© opcional
    }
    try {
        if (!is_array($rdNow)) {
            // Traz o perÃ­odo completo para manter aderÃªncia aos nÃºmeros do Kanban RD.
            // O cache de 30 min preserva performance nas prÃ³ximas consultas.
            $rdNow = rdFetchTodasMetricas($rdToken, $start, $end, null, false);
            @file_put_contents($cacheFile, json_encode($rdNow, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        // Se houver cache antigo, serve versÃ£o stale em caso de falha no RD.
        try {
            if (is_file($cacheFile)) {
                $cached = @file_get_contents($cacheFile);
                $cachedArr = is_string($cached) ? json_decode($cached, true) : null;
                if (is_array($cachedArr) && isset($cachedArr['receita_total'])) {
                    $rdNow = $cachedArr;
                }
            }
        } catch (Throwable $ignored) {}
        if (is_array($rdNow)) {
            // segue com cache stale
        } else {
            http_response_code(500);
            $msg = (defined('IS_PRODUCTION') && IS_PRODUCTION)
                ? 'Erro ao consultar o RD Station CRM.'
                : $e->getMessage();
            echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    $receitaMes = (float)($rdNow['receita_total'] ?? 0);
    $totalGanhos = (int)($rdNow['total_ganhos'] ?? 0);
    $totalPerdidos = (int)($rdNow['total_perdidos'] ?? 0);
    $oportunidadesAbertas = (int)($rdNow['oportunidades_abertas'] ?? 0);
    $ticketMedio = $totalGanhos > 0 ? round($receitaMes / $totalGanhos, 2) : 0.0;

    $volumeMacro = $totalGanhos + $totalPerdidos + $oportunidadesAbertas;

    $porLista = $rdNow['por_vendedor'] ?? [];
    $tempoFechHoras = null;
    $sumMinWeighted = 0.0;
    $cntDeals = 0;
    foreach ($porLista as $row) {
        $c = (int)($row['total_ganhos'] ?? 0);
        $tMin = $row['tempo_medio_fechamento_min'] ?? null;
        if ($c > 0 && $tMin !== null && $tMin !== '') {
            $sumMinWeighted += (float)$tMin * $c;
            $cntDeals += $c;
        }
    }
    if ($cntDeals > 0) {
        $tempoFechHoras = round($sumMinWeighted / $cntDeals / 60, 2);
    }

    $fechadosNoPeriodo = $totalGanhos + $totalPerdidos;
    $taxaPerdaCliente = gcPercent((float)$totalPerdidos, (float)max($fechadosNoPeriodo, 0));

    $funilEstagios = $rdNow['funil_estagios'] ?? [];
    $funilMap = [];
    foreach ($funilEstagios as $e) {
        $pipe = trim((string)($e['pipeline_name'] ?? 'Geral'));
        $st = trim((string)($e['stage_name'] ?? ''));
        $label = $pipe . ' â€” ' . ($st !== '' ? $st : 'Etapa');
        $funilMap[$label] = ($funilMap[$label] ?? 0) + (int)($e['quantidade'] ?? 0);
    }
    $gargalo = null;

    $equipe = [];
    foreach ($porLista as $row) {
        $nome = trim((string)($row['vendedor'] ?? ''));
        if ($nome === '') {
            continue;
        }
        $ganhos = (int)($row['total_ganhos'] ?? 0);
        $perd = (int)($row['total_perdidos'] ?? 0);
        $totalPedidos = $ganhos + $perd;
        $equipe[] = [
            'atendente'                   => $nome,
            'total_pedidos'               => $totalPedidos,
            'vendas_aprovadas'            => $ganhos,
            'vendas_rejeitadas'           => $perd,
            'receita'                     => (float)($row['receita'] ?? 0),
            'ticket_medio'                => $ganhos > 0 ? round((float)($row['receita'] ?? 0) / $ganhos, 2) : 0.0,
            'tempo_medio_espera_min'      => (float)($row['tempo_medio_espera_min'] ?? 0),
            'clientes_atendidos'          => $ganhos,
            'conversao_individual'        => (float)($row['conversao_pct'] ?? 0),
            'follow_ups_realizados'       => null,
            'motivos_perda'               => null,
            'tempo_medio_espera_resposta' => round((float)($row['tempo_medio_espera_min'] ?? 0), 1),
            'taxa_perda'                  => (float)($row['taxa_perda_pct'] ?? 0),
        ];
    }

    $motivosPerda = [];
    foreach ($porLista as $row) {
        $nome = trim((string)($row['vendedor'] ?? ''));
        foreach ($row['motivos_perda'] ?? [] as $m) {
            $motivosPerda[] = [
                'atendente'   => $nome,
                'motivo'      => (string)($m['motivo'] ?? ''),
                'quantidade'  => (int)($m['quantidade'] ?? 0),
                'valor_total' => 0.0,
            ];
        }
    }
    usort($motivosPerda, static function (array $a, array $b): int {
        return ($b['quantidade'] ?? 0) <=> ($a['quantidade'] ?? 0);
    });
    $motivosPerda = array_slice($motivosPerda, 0, 50);

    $clientesRejeitadosComContato = [];
    try {
        $detalhesRej = rdFetchRejeitadosDetalhados($rdToken, $start, $end, 3);
        $registrosRej = $detalhesRej['registros'] ?? [];
        if (is_array($registrosRej) && $registrosRej) {
            $rejMap = [];
            foreach ($registrosRej as $rj) {
                if (!is_array($rj)) continue;
                $cliente = trim((string)($rj['cliente'] ?? ''));
                $prescritor = trim((string)($rj['prescritor'] ?? 'NÃ£o informado'));
                $contato = trim((string)($rj['contato'] ?? ''));
                if ($cliente === '' || $contato === '') continue;
                $atendente = trim((string)($rj['atendente'] ?? 'NÃ£o informado'));
                $valor = (float)($rj['valor_rejeitado'] ?? 0);
                $kRaw = trim((string)($cliente . '|' . $prescritor . '|' . $contato . '|' . $atendente));
                $k = function_exists('mb_strtolower') ? mb_strtolower($kRaw, 'UTF-8') : strtolower($kRaw);
                if (!isset($rejMap[$k])) {
                    $rejMap[$k] = [
                        'cliente' => $cliente,
                        'prescritor' => $prescritor,
                        'contato' => $contato,
                        'qtd_rejeicoes' => 0,
                        'valor_rejeitado' => 0.0,
                        'atendente' => $atendente,
                    ];
                }
                $rejMap[$k]['qtd_rejeicoes']++;
                $rejMap[$k]['valor_rejeitado'] += $valor;
            }
            $clientesRejeitadosComContato = array_values($rejMap);
            usort($clientesRejeitadosComContato, static function (array $a, array $b): int {
                if (($b['qtd_rejeicoes'] ?? 0) !== ($a['qtd_rejeicoes'] ?? 0)) return ($b['qtd_rejeicoes'] ?? 0) <=> ($a['qtd_rejeicoes'] ?? 0);
                return ($b['valor_rejeitado'] ?? 0) <=> ($a['valor_rejeitado'] ?? 0);
            });
            $clientesRejeitadosComContato = array_slice($clientesRejeitadosComContato, 0, 80);
            foreach ($clientesRejeitadosComContato as &$itRej) {
                $itRej['valor_rejeitado'] = round((float)($itRej['valor_rejeitado'] ?? 0), 2);
            }
            unset($itRej);
        }
    } catch (Throwable $e) {
        // bloco opcional
    }

    $vendedoresCadastrados = [];
    foreach ($equipe as $ev) {
        $vendedoresCadastrados[] = [
            'nome'  => (string)($ev['atendente'] ?? ''),
            'ativo' => 1,
        ];
    }

    $consultorasAtivas = count($equipe);
    $receitaEquipe = 0.0;
    $conversaoMedia = 0.0;
    $tempoMedioEquipe = 0.0;
    $clientesAtendidosEquipe = 0;
    $taxaPerdaMedia = 0.0;
    if ($consultorasAtivas > 0) {
        foreach ($equipe as $v) {
            $receitaEquipe += (float)($v['receita'] ?? 0);
            $conversaoMedia += (float)($v['conversao_individual'] ?? 0);
            $tempoMedioEquipe += (float)($v['tempo_medio_espera_resposta'] ?? 0);
            $clientesAtendidosEquipe += (int)($v['clientes_atendidos'] ?? 0);
            $taxaPerdaMedia += (float)($v['taxa_perda'] ?? 0);
        }
        $conversaoMedia = round($conversaoMedia / $consultorasAtivas, 2);
        $tempoMedioEquipe = round($tempoMedioEquipe / $consultorasAtivas, 1);
        $taxaPerdaMedia = round($taxaPerdaMedia / $consultorasAtivas, 2);
    }

    $canais = [];
    foreach ($rdNow['origens_geral'] ?? [] as $o) {
        $q = (int)($o['quantidade'] ?? 0);
        $canais[] = [
            'canal'              => (string)($o['origem'] ?? ''),
            'total_pedidos'      => $q,
            'vendas_aprovadas'   => $q,
            'receita'            => (float)($o['receita'] ?? 0),
            'conversao'          => null,
            'margem_percentual'  => null,
            'cac'                => null,
        ];
    }

    // No modo RD-only, nÃ£o hÃ¡ produto/prescritor/especialidade nativos no mesmo formato legado.
    // EntÃ£o montamos estes blocos a partir dos detalhamentos disponÃ­veis no CRM.
    $topFormulas = [];
    foreach (($rdNow['origens_geral'] ?? []) as $o) {
        $topFormulas[] = [
            'produto'    => (string)($o['origem'] ?? 'NÃ£o informada'),
            'quantidade' => (int)($o['quantidade'] ?? 0),
        ];
    }
    usort($topFormulas, static function (array $a, array $b): int {
        return ($b['quantidade'] ?? 0) <=> ($a['quantidade'] ?? 0);
    });
    $topFormulas = array_slice($topFormulas, 0, 15);

    $topPrescritores = [];
    foreach ($porLista as $row) {
        $topPrescritores[] = [
            'prescritor' => (string)($row['vendedor'] ?? 'NÃ£o informado'),
            'receita'    => round((float)($row['receita'] ?? 0), 2),
        ];
    }
    usort($topPrescritores, static function (array $a, array $b): int {
        return ($b['receita'] ?? 0) <=> ($a['receita'] ?? 0);
    });
    $topPrescritores = array_slice($topPrescritores, 0, 15);

    $formulasMargem = [];
    $baseReceita = max((float)$receitaMes, 0.0);
    foreach (($rdNow['origens_geral'] ?? []) as $o) {
        $receitaOrigem = (float)($o['receita'] ?? 0);
        $formulasMargem[] = [
            'produto'           => (string)($o['origem'] ?? 'NÃ£o informada'),
            'margem_percentual' => $baseReceita > 0 ? round(($receitaOrigem / $baseReceita) * 100, 2) : 0.0,
        ];
    }
    usort($formulasMargem, static function (array $a, array $b): int {
        return ($b['margem_percentual'] ?? 0) <=> ($a['margem_percentual'] ?? 0);
    });
    $formulasMargem = array_slice($formulasMargem, 0, 15);

    $ticketEspecialidade = [];
    foreach ($porLista as $row) {
        $ganhos = (int)($row['total_ganhos'] ?? 0);
        $receitaVendedor = (float)($row['receita'] ?? 0);
        $ticketEspecialidade[] = [
            'especialidade' => (string)($row['vendedor'] ?? 'NÃ£o informado'),
            'ticket_medio'  => $ganhos > 0 ? round($receitaVendedor / $ganhos, 2) : 0.0,
        ];
    }
    usort($ticketEspecialidade, static function (array $a, array $b): int {
        return ($b['ticket_medio'] ?? 0) <=> ($a['ticket_medio'] ?? 0);
    });
    $ticketEspecialidade = array_slice($ticketEspecialidade, 0, 15);

    $approvedGpRd = gcApprovedCase('gp');
    $evolucaoMensalVendedores = gcEvolucaoMensalVendedoresPayload($pdo, $approvedGpRd, $equipe);
    $evolucaoMensalAprovRej = gcEvolucaoMensalAprovRejPayload($pdo, $approvedGpRd, $equipe);
    $relatoriosComerciais = gcBuildRelatoriosComerciais($pdo, $start, $end, $approvedGpRd, $equipe);

    $crescimento = [
        'receita_mes'                     => round($receitaMes, 2),
        'crescimento_vs_mes_anterior'     => null,
        'crescimento_vs_mes_ano_passado'  => null,
        'ticket_medio'                    => $ticketMedio,
        'numero_vendas'                   => $totalGanhos,
        'numero_clientes_ativos_6_meses'  => null,
    ];

    $eficiencia = [
        'leads_recebidos'             => $volumeMacro,
        'conversao_geral'             => $rdNow['conversao_geral_pct'] ?? null,
        'tempo_medio_fechamento_horas' => $tempoFechHoras,
        'taxa_perda_cliente'          => $taxaPerdaCliente,
        'ltv'                         => null,
        'cac'                         => null,
        'ltv_cac'                     => null,
        'notas'                       => [
            'cac'     => 'CAC nÃ£o disponÃ­vel na API do CRM.',
            'ltv_cac' => 'â€”',
            'ltv'     => 'LTV nÃ£o calculado a partir do CRM neste modo.',
            'leads'   => 'â€œLeads / volumeâ€ = negociaÃ§Ãµes fechadas no perÃ­odo (ganhos + perdidos) + oportunidades abertas no funil (RD).',
        ],
    ];

    $rentabilidade = [
        'margem_bruta'            => null,
        'margem_contribuicao'     => null,
        'cmv_sobre_faturamento'   => null,
        'ponto_equilibrio'        => null,
        'lucro_operacional'       => round($receitaMes, 2),
        'margem_contribuicao_por' => [
            'atendente'          => [],
            'forma_farmaceutica' => [],
            'prescritor'         => [],
            'produto'            => [],
        ],
        'notas' => [
            'ponto_equilibrio' => 'Custos e margens de produto nÃ£o vÃªm do RD Station CRM.',
            'modo'             => 'Receita e â€œlucroâ€ exibidos refletem o valor dos deals ganhos no CRM; nÃ£o hÃ¡ CMV do ERP.',
        ],
    ];

    $fidelizacao = [
        'taxa_recompra'            => null,
        'frequencia_media_compra'  => null,
        'base_ativa_90_dias'       => null,
        'nps'                      => null,
        'csat'                     => null,
        'notas'                    => [
            'nps_csat' => 'FidelizaÃ§Ã£o e NPS nÃ£o estÃ£o neste modo (somente CRM).',
        ],
    ];

    $conversoes = [
        'atendimento_para_orcamento' => gcPercent((float)$oportunidadesAbertas, (float)max($volumeMacro, 0)),
        'orcamento_para_venda'       => gcPercent((float)$totalGanhos, (float)max($oportunidadesAbertas + $totalGanhos + $totalPerdidos, 0)),
        'venda_para_recompra'        => null,
    ];

    $payload = [
        'success'        => true,
        'fonte_dados'    => 'rdstation_crm',
        'generated_at'   => date('c'),
        'periodo'        => [
            'tipo'         => $periodType,
            'data_de'      => $start,
            'data_ate'     => $end,
            'comparativos' => [
                'mes_anterior'              => ['data_de' => $prevStart, 'data_ate' => $prevEnd],
                'mesmo_periodo_ano_passado' => ['data_de' => $lyStart, 'data_ate' => $lyEnd],
            ],
        ],
        'crescimento'            => $crescimento,
        'eficiencia_comercial'   => $eficiencia,
        'rentabilidade'          => $rentabilidade,
        'fidelizacao'            => $fidelizacao,
        'funil_comercial'        => [
            'volume_atendimentos' => $volumeMacro,
            'oportunidades_abertas' => $oportunidadesAbertas,
            'orcamentos_enviados' => $oportunidadesAbertas,
            'vendas_fechadas'     => $totalGanhos,
            'conversao_por_etapa' => $conversoes,
            'gargalo_funil'       => $gargalo,
            'etapas_raw'          => $funilMap,
        ],
        'performance_vendedor'   => $equipe,
        'vendedor_gestao'        => [
            'resumo'                         => [
                'consultoras_ativas'       => $consultorasAtivas,
                'receita_equipe'           => round($receitaEquipe, 2),
                'conversao_media'          => $conversaoMedia,
                'tempo_medio_espera_min'   => $tempoMedioEquipe,
                'clientes_atendidos'       => $clientesAtendidosEquipe,
                'taxa_perda_media'         => $taxaPerdaMedia,
            ],
            'vendedores_cadastrados'         => $vendedoresCadastrados,
            'equipe'                         => $equipe,
            'motivos_perda'                  => $motivosPerda,
            'clientes_rejeitados_com_contato' => $clientesRejeitadosComContato,
            'evolucao_mensal_vendedores'     => $evolucaoMensalVendedores,
            'evolucao_mensal_aprovados'      => $evolucaoMensalAprovRej['aprovados'],
            'evolucao_mensal_rejeitados'     => $evolucaoMensalAprovRej['rejeitados'],
        ],
        'performance_canal'      => $canais,
        'inteligencia_comercial' => [
            'top_formulas_mais_vendidas'     => $topFormulas,
            'formulas_maior_margem'          => $formulasMargem,
            'prescritores_maior_receita'     => $topPrescritores,
            'ticket_medio_por_especialidade' => $ticketEspecialidade,
        ],
        'financeiro_aplicado'    => [
            'receita' => [
                'faturamento_bruto'  => round($receitaMes, 2),
                'faturamento_liquido' => round($receitaMes, 2),
            ],
            'custos' => [
                'cmv'                    => 0.0,
                'custo_fixo_mensal'      => null,
                'custo_variavel_por_venda' => null,
                'custo_por_atendimento'    => null,
                'cac'                      => null,
            ],
            'rentabilidade' => [
                'margem_bruta'        => null,
                'margem_contribuicao' => null,
                'lucro_operacional'   => round($receitaMes, 2),
                'ponto_equilibrio'    => null,
                'roi_campanhas'       => null,
            ],
            'caixa' => [
                'prazo_medio_recebimento' => null,
                'indice_inadimplencia'    => null,
                'taxa_antecipacao'        => null,
                'fluxo_projetado_3_meses' => null,
            ],
            'notas' => [
                'campos_nulos' => 'Painel alimentado sÃ³ pelo RD Station CRM: sem CMV, inadimplÃªncia ou fluxo de caixa do ERP.',
            ],
        ],
        'relatorios_comerciais'  => $relatoriosComerciais,
        'rd_metricas'            => $rdNow,
        'rd_metricas_deferred'   => false,
        'rd_metricas_error'      => null,
        'lista_vendedores_nomes' => array_values(array_map(static function ($r) {
            return (string)($r['atendente'] ?? '');
        }, $equipe)),
    ];

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao montar JSON do painel (dados invÃ¡lidos para serializaÃ§Ã£o).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $json;
    exit;
}

function gestaoComercialDashboard(PDO $pdo): void
{
    if (strtolower((string)($_SESSION['user_tipo'] ?? '')) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso restrito ao administrador.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    [$startObj, $endObj, $periodType] = gcDateRangeFromInput();
    $start = $startObj->format('Y-m-d');
    $end = $endObj->format('Y-m-d');
    $prevStart = $startObj->modify('-1 month')->format('Y-m-d');
    $prevEnd = $endObj->modify('-1 month')->format('Y-m-d');
    $lyStart = $startObj->modify('-1 year')->format('Y-m-d');
    $lyEnd = $endObj->modify('-1 year')->format('Y-m-d');
    $sixMonthsStart = $endObj->modify('-6 months')->format('Y-m-d');
    $ninetyDaysStart = $endObj->modify('-90 days')->format('Y-m-d');
    $limit = safeLimit($_GET['limit'] ?? 20, 5, 50);

    $approvedGp = gcApprovedCase('gp');

    $rowCurrent = gcFetchRow($pdo, "
        SELECT
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo,
            COALESCE(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,'')) END), 0) AS vendas,
            COALESCE(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.cliente END), 0) AS clientes
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

    $rowPrev = gcFetchRow($pdo, "
        SELECT COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $prevStart, 'fim' => $prevEnd]);

    $rowLy = gcFetchRow($pdo, "
        SELECT COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $lyStart, 'fim' => $lyEnd]);

    $receitaMes = (float)($rowCurrent['receita'] ?? 0);
    $custoMes = (float)($rowCurrent['custo'] ?? 0);
    $vendasMes = (int)($rowCurrent['vendas'] ?? 0);
    $clientesMes = (int)($rowCurrent['clientes'] ?? 0);
    $ticketMedio = $vendasMes > 0 ? round($receitaMes / $vendasMes, 2) : 0.0;

    $clientesAtivos6mRow = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT gp.cliente), 0) AS total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND {$approvedGp}
    ", ['ini' => $sixMonthsStart, 'fim' => $end]);

    $leadsRow = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))), 0) AS total
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

    $vendasFunilRow = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))), 0) AS total
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
          AND UPPER(TRIM(COALESCE(i.status, ''))) = 'APROVADO'
    ", ['ini' => $start, 'fim' => $end]);

    $tempoFechamentoRow = gcFetchRow($pdo, "
        SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, gp.data_orcamento, gp.data_aprovacao)), 0) AS media_horas
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND gp.data_orcamento IS NOT NULL
          AND gp.data_aprovacao IS NOT NULL
          AND {$approvedGp}
    ", ['ini' => $start, 'fim' => $end]);

    $clientesPerdaBase = gcFetchRow($pdo, "
        SELECT
            COALESCE(COUNT(DISTINCT gp.cliente), 0) AS clientes_mov
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

    $clientesPerdidos = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(*), 0) AS clientes_perdidos
        FROM (
            SELECT
                gp.cliente,
                SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS qtd_aprov,
                SUM(CASE WHEN NOT ({$approvedGp}) THEN 1 ELSE 0 END) AS qtd_nao_aprov
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
              AND gp.cliente IS NOT NULL
              AND TRIM(gp.cliente) <> ''
            GROUP BY gp.cliente
        ) x
        WHERE x.qtd_aprov = 0 AND x.qtd_nao_aprov > 0
    ", ['ini' => $start, 'fim' => $end]);

    // LTV no perÃ­odo filtrado (antes: full scan em gestao_pedidos â€” muito lento com base grande).
    $ltvRow = gcFetchRow($pdo, "
        SELECT
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita_total,
            COALESCE(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.cliente END), 0) AS clientes_total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
    ", ['ini' => $start, 'fim' => $end]);

    $freqRecompraRow = gcFetchRow($pdo, "
        SELECT
            COALESCE(COUNT(*), 0) AS clientes_recompra
        FROM (
            SELECT gp.cliente
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
              AND {$approvedGp}
              AND gp.cliente IS NOT NULL
              AND TRIM(gp.cliente) <> ''
            GROUP BY gp.cliente
            HAVING COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) >= 2
        ) t
    ", ['ini' => $start, 'fim' => $end]);

    $base90Row = gcFetchRow($pdo, "
        SELECT COALESCE(COUNT(DISTINCT gp.cliente), 0) AS total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND {$approvedGp}
    ", ['ini' => $ninetyDaysStart, 'fim' => $end]);

    $funilRows = gcFetchAll($pdo, "
        SELECT
            UPPER(TRIM(COALESCE(i.status, '(SEM_STATUS)'))) AS etapa,
            COUNT(DISTINCT CONCAT(COALESCE(i.numero,''), '-', COALESCE(i.serie,''))) AS total
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
        GROUP BY UPPER(TRIM(COALESCE(i.status, '(SEM_STATUS)')))
    ", ['ini' => $start, 'fim' => $end]);
    $funilMap = [];
    foreach ($funilRows as $fr) {
        $funilMap[$fr['etapa']] = (int)$fr['total'];
    }
    $atendimentos = array_sum($funilMap);
    $orcamentos = ($funilMap['NO CARRINHO'] ?? 0) + ($funilMap['APROVADO'] ?? 0) + ($funilMap['RECUSADO'] ?? 0);
    $vendasFechadas = (int)($vendasFunilRow['total'] ?? 0);
    $clientesAprovados = $clientesMes;
    $clientesRecompra = (int)($freqRecompraRow['clientes_recompra'] ?? 0);

    $convAtendimentoOrc = gcPercent((float)$orcamentos, (float)max($atendimentos, 0));
    $convOrcVenda = gcPercent((float)$vendasFechadas, (float)max($orcamentos, 0));
    $convVendaRecompra = gcPercent((float)$clientesRecompra, (float)max($clientesAprovados, 0));

    $conversoes = [
        'atendimento_para_orcamento' => $convAtendimentoOrc,
        'orcamento_para_venda' => $convOrcVenda,
        'venda_para_recompra' => $convVendaRecompra,
    ];
    $gargalo = null;
    $validConversoes = array_filter($conversoes, function ($v) { return $v !== null; });
    if (!empty($validConversoes)) {
        asort($validConversoes);
        reset($validConversoes);
        $gargalo = key($validConversoes);
    }

    $vendedores = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) AS total_pedidos,
            COALESCE(SUM(gp.quantidade), 0) AS quantidade_somada,
            SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS vendas_aprovadas,
            SUM(CASE WHEN NOT ({$approvedGp}) THEN 1 ELSE 0 END) AS vendas_rejeitadas,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            ROUND(
                COALESCE(
                    SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END)
                    / NULLIF(COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.numero_pedido END), 0),
                    0
                ),
                2
            ) AS ticket_medio,
            COALESCE(AVG(TIMESTAMPDIFF(MINUTE, gp.data_orcamento, gp.data_aprovacao)), 0) AS tempo_medio_espera_min,
            COUNT(DISTINCT CASE WHEN {$approvedGp} THEN gp.cliente END) AS clientes_atendidos
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    $vendedores = array_values(array_filter($vendedores, static function (array $row): bool {
        return gcIsAllowedVendedora((string)($row['atendente'] ?? ''));
    }));

    // Rejeitados (Recusado / No carrinho) vivem em itens_orcamentos_pedidos; gestao_pedidos muitas vezes nÃ£o traz essas linhas.
    $rejeitadosPorItensRaw = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)') AS atendente,
            COUNT(*) AS linhas_rejeitadas
        FROM itens_orcamentos_pedidos i
        LEFT JOIN gestao_pedidos gpLink
          ON gpLink.numero_pedido = i.numero
         AND gpLink.serie_pedido = i.serie
         AND gpLink.ano_referencia = i.ano_referencia
        WHERE DATE(i.data) BETWEEN :ini AND :fim
          AND LOWER(TRIM(COALESCE(i.status, ''))) IN ('recusado', 'no carrinho')
        GROUP BY COALESCE(NULLIF(TRIM(gpLink.atendente), ''), NULLIF(TRIM(i.usuario_inclusao), ''), '(Sem atendente)')
    ", ['ini' => $start, 'fim' => $end]);
    $rejeitadosLinesByNorm = [];
    $rejeitadosNomeByNorm = [];
    foreach ($rejeitadosPorItensRaw as $rrow) {
        $nomeRej = trim((string)($rrow['atendente'] ?? ''));
        if ($nomeRej === '' || $nomeRej === '(Sem atendente)') {
            continue;
        }
        if (!gcIsAllowedVendedora($nomeRej)) {
            continue;
        }
        $nkRej = gcNormName($nomeRej);
        if ($nkRej === '') {
            continue;
        }
        $rejeitadosLinesByNorm[$nkRej] = (float)($rrow['linhas_rejeitadas'] ?? 0);
        $rejeitadosNomeByNorm[$nkRej] = $nomeRej;
    }

    foreach ($vendedores as &$vnd) {
        $vnd['follow_ups_realizados'] = null;
        $vnd['motivos_perda'] = null; // detalhado em vendedor_gestao.motivos_perda
        $vnd['tempo_medio_espera_resposta'] = round((float)($vnd['tempo_medio_espera_min'] ?? 0), 1);
    }
    unset($vnd);

    $usuariosSetor = gcTryFetchAll($pdo, "
        SELECT
            TRIM(u.nome) AS nome,
            COALESCE(u.setor, '') AS setor,
            COALESCE(u.ativo, 1) AS ativo
        FROM usuarios u
        WHERE LOWER(TRIM(COALESCE(u.setor, ''))) LIKE '%vendedor%'
        ORDER BY TRIM(u.nome) ASC
    ");
    $vendedoresCadastrados = [];
    foreach ($usuariosSetor as $u) {
        $nomeUsr = trim((string)($u['nome'] ?? ''));
        if ($nomeUsr === '') continue;
        $setorNorm = gcNormName((string)($u['setor'] ?? ''));
        if (strpos($setorNorm, 'vendedor') !== false && gcIsAllowedVendedora($nomeUsr)) {
            $vendedoresCadastrados[] = [
                'nome' => $nomeUsr,
                'ativo' => (int)($u['ativo'] ?? 1),
            ];
        }
    }

    $vendedoresMap = [];
    foreach ($vendedores as $v) {
        $k = gcNormName((string)($v['atendente'] ?? ''));
        if ($k !== '') $vendedoresMap[$k] = true;
    }

    foreach ($vendedoresCadastrados as $u) {
        $nomeCad = trim((string)($u['nome'] ?? ''));
        if ($nomeCad === '') continue;
        $k = gcNormName($nomeCad);
        if (!isset($vendedoresMap[$k])) {
            $vendedores[] = [
                'atendente' => $nomeCad,
                'total_pedidos' => 0,
                'quantidade_somada' => 0,
                'vendas_aprovadas' => 0,
                'vendas_rejeitadas' => 0,
                'receita' => 0,
                'ticket_medio' => 0,
                'tempo_medio_espera_min' => 0,
                'clientes_atendidos' => 0,
                'conversao_individual' => 0,
                'follow_ups_realizados' => null,
                'motivos_perda' => null,
                'tempo_medio_espera_resposta' => 0,
                'taxa_perda' => 0
            ];
            $vendedoresMap[$k] = true;
        }
    }

    $presentNormsVend = [];
    foreach ($vendedores as $v) {
        $pk = gcNormName((string)($v['atendente'] ?? ''));
        if ($pk !== '') {
            $presentNormsVend[$pk] = true;
        }
    }
    foreach ($rejeitadosLinesByNorm as $nkOnly => $rejLinhas) {
        if ($rejLinhas <= 0 || isset($presentNormsVend[$nkOnly])) {
            continue;
        }
        $nomeOnly = trim((string)($rejeitadosNomeByNorm[$nkOnly] ?? ''));
        if ($nomeOnly === '' || !gcIsAllowedVendedora($nomeOnly)) {
            continue;
        }
        $vendedores[] = [
            'atendente' => $nomeOnly,
            'total_pedidos' => 0,
            'quantidade_somada' => 0,
            'vendas_aprovadas' => 0,
            'vendas_rejeitadas' => 0,
            'receita' => 0,
            'ticket_medio' => 0,
            'tempo_medio_espera_min' => 0,
            'clientes_atendidos' => 0,
            'conversao_individual' => null,
            'follow_ups_realizados' => null,
            'motivos_perda' => null,
            'tempo_medio_espera_resposta' => 0,
            'taxa_perda' => null,
        ];
        $presentNormsVend[$nkOnly] = true;
    }

    foreach ($vendedores as &$vnd) {
        $totalPedidos = (float)($vnd['total_pedidos'] ?? 0);
        $vendasAprovadas = (float)($vnd['vendas_aprovadas'] ?? 0);
        $fromGestaoRej = (float)($vnd['vendas_rejeitadas'] ?? 0);
        $normV = gcNormName((string)($vnd['atendente'] ?? ''));
        $fromItensRej = (float)($rejeitadosLinesByNorm[$normV] ?? 0);
        $vendasRejeitadas = $fromGestaoRej + $fromItensRej;
        $vnd['vendas_rejeitadas'] = $vendasRejeitadas;

        $denLinhas = $vendasAprovadas + $vendasRejeitadas;
        $vnd['conversao_individual'] = $denLinhas > 0
            ? gcPercent($vendasAprovadas, $denLinhas)
            : gcPercent($vendasAprovadas, $totalPedidos);
        $vnd['taxa_perda'] = $denLinhas > 0
            ? gcPercent($vendasRejeitadas, $denLinhas)
            : gcPercent($vendasRejeitadas, $totalPedidos);
    }
    unset($vnd);

    $metaSistemaVendedor = (float)(getenv('VENDEDOR_META_SISTEMA') ?: 60000);
    if ($metaSistemaVendedor <= 0) {
        $metaSistemaVendedor = 60000.0;
    }
    $metaPorNorm = [];
    try {
        $stMetaV = $pdo->query("
            SELECT TRIM(nome) AS nome, COALESCE(meta_mensal, 0) AS meta_mensal
            FROM usuarios
            WHERE LOWER(TRIM(COALESCE(setor, ''))) LIKE '%vendedor%'
              AND COALESCE(ativo, 1) = 1
        ");
        foreach ($stMetaV->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mr) {
            $nm = trim((string)($mr['nome'] ?? ''));
            if ($nm === '') {
                continue;
            }
            $metaPorNorm[gcNormName($nm)] = (float)($mr['meta_mensal'] ?? 0);
        }
    } catch (Throwable $e) {
        $metaPorNorm = [];
    }

    $receitaGrupoVend = 0.0;
    foreach ($vendedores as $vv) {
        $receitaGrupoVend += (float)($vv['receita'] ?? 0);
    }
    $comissaoGrupoPctVend = gcCalcComissaoGrupoPct($receitaGrupoVend);

    foreach ($vendedores as &$vnd) {
        $norm = gcNormName((string)($vnd['atendente'] ?? ''));
        $meta = (float)($metaPorNorm[$norm] ?? 0);
        if ($meta <= 0) {
            $meta = $metaSistemaVendedor;
        }
        $receita = (float)($vnd['receita'] ?? 0);
        $pctMeta = $meta > 0 ? round(($receita / $meta) * 100, 2) : 0.0;
        $ci = gcCalcComissaoIndividualPct($pctMeta);
        $ct = round($ci + $comissaoGrupoPctVend, 2);
        $vnd['meta_mensal'] = round($meta, 2);
        $vnd['percentual_meta'] = $pctMeta;
        $vnd['comissao_pct_individual'] = $ci;
        $vnd['comissao_pct_grupo'] = $comissaoGrupoPctVend;
        $vnd['comissao_pct_total'] = $ct;
        $vnd['previsao_ganho'] = round($receita * $ct / 100, 2);
    }
    unset($vnd);

    usort($vendedores, static function (array $a, array $b): int {
        return strcmp((string)($a['atendente'] ?? ''), (string)($b['atendente'] ?? ''));
    });

    $motivosPerda = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(NULLIF(TRIM(gp.status_financeiro), ''), 'Sem status') AS motivo,
            COUNT(*) AS quantidade,
            COALESCE(SUM(gp.preco_liquido), 0) AS valor_total
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND NOT ({$approvedGp})
        GROUP BY
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)'),
            COALESCE(NULLIF(TRIM(gp.status_financeiro), ''), 'Sem status')
        ORDER BY COUNT(*) DESC, COALESCE(SUM(gp.preco_liquido), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    $motivosPerda = array_values(array_filter($motivosPerda, static function (array $row): bool {
        return gcIsAllowedVendedora((string)($row['atendente'] ?? ''));
    }));

    $clientesRejeitadosComContato = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS atendente,
            COALESCE(NULLIF(TRIM(gp.cliente), ''), '(Sem cliente)') AS cliente,
            COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') AS prescritor,
            COALESCE(NULLIF(TRIM(pc.whatsapp), ''), NULLIF(TRIM(pd.whatsapp), ''), '') AS contato,
            COUNT(*) AS qtd_rejeicoes,
            COALESCE(SUM(gp.preco_liquido), 0) AS valor_rejeitado
        FROM gestao_pedidos gp
        LEFT JOIN prescritor_contatos pc
            ON LOWER(TRIM(pc.nome_prescritor)) = LOWER(TRIM(COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')))
        LEFT JOIN prescritor_dados pd
            ON LOWER(TRIM(pd.nome_prescritor)) = LOWER(TRIM(COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm')))
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
          AND NOT ({$approvedGp})
        GROUP BY
            COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)'),
            COALESCE(NULLIF(TRIM(gp.cliente), ''), '(Sem cliente)'),
            COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm'),
            COALESCE(NULLIF(TRIM(pc.whatsapp), ''), NULLIF(TRIM(pd.whatsapp), ''), '')
        ORDER BY COALESCE(SUM(gp.preco_liquido), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    $clientesRejeitadosComContato = array_values(array_filter($clientesRejeitadosComContato, static function (array $row): bool {
        return gcIsAllowedVendedora((string)($row['atendente'] ?? ''));
    }));

    $consultorasAtivas = 0;
    $receitaEquipe = 0.0;
    $conversaoMedia = 0.0;
    $tempoMedioEquipe = 0.0;
    $clientesAtendidosEquipe = 0;
    $taxaPerdaMedia = 0.0;
    $metaMensalEquipeSoma = 0.0;
    $previsaoGanhoEquipeSoma = 0.0;
    if (!empty($vendedores)) {
        $consultorasAtivas = count($vendedores);
        foreach ($vendedores as $v) {
            $receitaEquipe += (float)($v['receita'] ?? 0);
            $conversaoMedia += (float)($v['conversao_individual'] ?? 0);
            $tempoMedioEquipe += (float)($v['tempo_medio_espera_resposta'] ?? 0);
            $clientesAtendidosEquipe += (int)($v['clientes_atendidos'] ?? 0);
            $taxaPerdaMedia += (float)($v['taxa_perda'] ?? 0);
            $metaMensalEquipeSoma += (float)($v['meta_mensal'] ?? 0);
            $previsaoGanhoEquipeSoma += (float)($v['previsao_ganho'] ?? 0);
        }
        $conversaoMedia = round($conversaoMedia / $consultorasAtivas, 2);
        $tempoMedioEquipe = round($tempoMedioEquipe / $consultorasAtivas, 1);
        $taxaPerdaMedia = round($taxaPerdaMedia / $consultorasAtivas, 2);
    }
    $percentualMetaEquipe = $metaMensalEquipeSoma > 0
        ? round(($receitaEquipe / $metaMensalEquipeSoma) * 100, 2)
        : null;

    $canais = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.canal_atendimento), ''), '(Sem canal)') AS canal,
            COUNT(DISTINCT CONCAT(COALESCE(gp.numero_pedido,''), '-', COALESCE(gp.serie_pedido,''))) AS total_pedidos,
            SUM(CASE WHEN {$approvedGp} THEN 1 ELSE 0 END) AS vendas_aprovadas,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.canal_atendimento), ''), '(Sem canal)')
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($canais as &$can) {
        $receitaCanal = (float)($can['receita'] ?? 0);
        $custoCanal = (float)($can['custo'] ?? 0);
        $can['conversao'] = gcPercent((float)($can['vendas_aprovadas'] ?? 0), (float)max((int)($can['total_pedidos'] ?? 0), 0));
        $can['margem_percentual'] = gcPercent($receitaCanal - $custoCanal, $receitaCanal);
        $can['cac'] = null;
    }
    unset($can);

    $approvedItens = gcApprovedCase('i');

    $topFormulas = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(i.descricao), ''), '(Sem componente)') AS produto,
            COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.quantidade ELSE 0 END), 0) AS quantidade,
            COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.valor_liquido ELSE 0 END), 0) AS receita
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(i.descricao), ''), '(Sem componente)')
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.quantidade ELSE 0 END), 0) DESC, COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.valor_liquido ELSE 0 END), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);

    $formulasMargem = gcTryFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(i.descricao), ''), '(Sem componente)') AS produto,
            COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.valor_liquido ELSE 0 END), 0) AS receita,
            COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.preco_custo ELSE 0 END), 0) AS custo
        FROM itens_orcamentos_pedidos i
        WHERE DATE(i.data) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(i.descricao), ''), '(Sem componente)')
        HAVING COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.valor_liquido ELSE 0 END), 0) > 0
        ORDER BY ((COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.valor_liquido ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.preco_custo ELSE 0 END), 0)) / COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.valor_liquido ELSE 0 END), 0)) DESC, COALESCE(SUM(CASE WHEN {$approvedItens} THEN i.valor_liquido ELSE 0 END), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);
    foreach ($formulasMargem as &$fm) {
        $fm['margem_percentual'] = gcPercent(((float)$fm['receita'] - (float)$fm['custo']), (float)$fm['receita']);
    }
    unset($fm);

    $topPrescritores = gcFetchAll($pdo, "
        SELECT
            COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') AS prescritor,
            COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita
        FROM gestao_pedidos gp
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')
        ORDER BY COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);

    $ticketEspecialidade = gcFetchAll($pdo, "
        SELECT
            COALESCE(
                NULLIF(TRIM(pd.profissao), ''),
                NULLIF(TRIM(pr.profissao), ''),
                '(Sem profissao)'
            ) AS especialidade,
            COALESCE(AVG(CASE WHEN {$approvedGp} THEN gp.preco_liquido END), 0) AS ticket_medio,
            COUNT(*) AS total
        FROM gestao_pedidos gp
        LEFT JOIN prescritor_dados pd
            ON LOWER(TRIM(pd.nome_prescritor)) = LOWER(TRIM(COALESCE(NULLIF(gp.prescritor,''), 'My Pharm')))
        LEFT JOIN (
            SELECT
                LOWER(TRIM(nome)) AS nome_norm,
                ano_referencia,
                MAX(NULLIF(TRIM(profissao), '')) AS profissao
            FROM prescritor_resumido
            GROUP BY LOWER(TRIM(nome)), ano_referencia
        ) pr
            ON pr.nome_norm = LOWER(TRIM(COALESCE(NULLIF(gp.prescritor,''), 'My Pharm')))
           AND pr.ano_referencia = gp.ano_referencia
        WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
        GROUP BY COALESCE(
            NULLIF(TRIM(pd.profissao), ''),
            NULLIF(TRIM(pr.profissao), ''),
            '(Sem profissao)'
        )
        ORDER BY COALESCE(AVG(CASE WHEN {$approvedGp} THEN gp.preco_liquido END), 0) DESC
        LIMIT {$limit}
    ", ['ini' => $start, 'fim' => $end]);

    $havingReceita = "COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) > 0";
    $orderReceitaDesc = "COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) DESC";
    $segmentosMargem = [
        'atendente' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.atendente), ''), '(Sem atendente)')
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
        'forma_farmaceutica' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.forma_farmaceutica), ''), '(Sem forma)') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.forma_farmaceutica), ''), '(Sem forma)')
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
        'prescritor' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.prescritor), ''), 'My Pharm')
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
        'produto' => gcFetchAll($pdo, "
            SELECT
                COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)') AS nome,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_liquido ELSE 0 END), 0) AS receita,
                COALESCE(SUM(CASE WHEN {$approvedGp} THEN gp.preco_custo ELSE 0 END), 0) AS custo
            FROM gestao_pedidos gp
            WHERE DATE(gp.data_aprovacao) BETWEEN :ini AND :fim
            GROUP BY COALESCE(NULLIF(TRIM(gp.produto), ''), '(Sem produto)')
            HAVING {$havingReceita}
            ORDER BY {$orderReceitaDesc}
            LIMIT 10
        ", ['ini' => $start, 'fim' => $end]),
    ];
    foreach ($segmentosMargem as &$arr) {
        foreach ($arr as &$item) {
            $item['margem_percentual'] = gcPercent(((float)$item['receita'] - (float)$item['custo']), (float)$item['receita']);
        }
        unset($item);
    }
    unset($arr);

    $crescimento = [
        'receita_mes' => round($receitaMes, 2),
        'crescimento_vs_mes_anterior' => gcGrowth($receitaMes, (float)($rowPrev['receita'] ?? 0)),
        'crescimento_vs_mes_ano_passado' => gcGrowth($receitaMes, (float)($rowLy['receita'] ?? 0)),
        'ticket_medio' => $ticketMedio,
        'numero_vendas' => $vendasMes,
        'numero_clientes_ativos_6_meses' => (int)($clientesAtivos6mRow['total'] ?? 0),
    ];

    $leads = (int)($leadsRow['total'] ?? 0);
    $vendasFunil = (int)($vendasFunilRow['total'] ?? 0);
    $clientesMov = (int)($clientesPerdaBase['clientes_mov'] ?? 0);
    $clientesPerdidosQtd = (int)($clientesPerdidos['clientes_perdidos'] ?? 0);
    $receitaTotal = (float)($ltvRow['receita_total'] ?? 0);
    $clientesTotal = (int)($ltvRow['clientes_total'] ?? 0);
    $ltv = $clientesTotal > 0 ? round($receitaTotal / $clientesTotal, 2) : null;

    $eficiencia = [
        'leads_recebidos' => $leads,
        'conversao_geral' => gcPercent((float)$vendasFunil, (float)max($leads, 0)),
        'tempo_medio_fechamento_horas' => round((float)($tempoFechamentoRow['media_horas'] ?? 0), 2),
        'taxa_perda_cliente' => gcPercent((float)$clientesPerdidosQtd, (float)max($clientesMov, 0)),
        'ltv' => $ltv,
        'cac' => null,
        'ltv_cac' => null,
        'notas' => [
            'cac' => 'NÃ£o hÃ¡ base de custo de marketing/campanhas no banco atual.',
            'ltv_cac' => 'Depende do CAC para cÃ¡lculo.',
            'ltv' => 'LTV = receita aprovada no perÃ­odo filtrado Ã· clientes distintos com compra nesse perÃ­odo (agilidade do painel; nÃ£o Ã© LTV vitalÃ­cio).',
        ],
    ];

    $lucroOperacional = $receitaMes - $custoMes;
    $margemBruta = gcPercent($lucroOperacional, $receitaMes);
    $cmv = gcPercent($custoMes, $receitaMes);

    $rentabilidade = [
        'margem_bruta' => $margemBruta,
        'margem_contribuicao' => $margemBruta,
        'cmv_sobre_faturamento' => $cmv,
        'ponto_equilibrio' => null,
        'lucro_operacional' => round($lucroOperacional, 2),
        'margem_contribuicao_por' => $segmentosMargem,
        'notas' => [
            'ponto_equilibrio' => 'Depende de custo fixo mensal consolidado.',
        ],
    ];

    $fidelizacao = [
        'taxa_recompra' => gcPercent((float)$clientesRecompra, (float)max($clientesAprovados, 0)),
        'frequencia_media_compra' => $clientesAprovados > 0 ? round($vendasMes / $clientesAprovados, 2) : 0,
        'base_ativa_90_dias' => (int)($base90Row['total'] ?? 0),
        'nps' => null,
        'csat' => null,
        'notas' => [
            'nps_csat' => 'NÃ£o hÃ¡ tabela de pesquisas de satisfaÃ§Ã£o (NPS/CSAT) na base atual.',
        ],
    ];

    $listaVendedoresNomes = array_values(array_filter(array_map(static function ($row) {
        return isset($row['nome']) ? trim((string)$row['nome']) : '';
    }, $vendedoresCadastrados), static function ($n) {
        return $n !== '';
    }));

    $evolucaoMensalVendedores = gcEvolucaoMensalVendedoresPayload($pdo, $approvedGp, $vendedores);
    $evolucaoMensalAprovRej = gcEvolucaoMensalAprovRejPayload($pdo, $approvedGp, $vendedores);
    $relatoriosComerciais = gcBuildRelatoriosComerciais($pdo, $start, $end, $approvedGp, $vendedores);

    $payload = [
        'success' => true,
        'generated_at' => date('c'),
        'lista_vendedores_nomes' => $listaVendedoresNomes,
        'periodo' => [
            'tipo' => $periodType,
            'data_de' => $start,
            'data_ate' => $end,
            'comparativos' => [
                'mes_anterior' => ['data_de' => $prevStart, 'data_ate' => $prevEnd],
                'mesmo_periodo_ano_passado' => ['data_de' => $lyStart, 'data_ate' => $lyEnd],
            ],
        ],
        'crescimento' => $crescimento,
        'eficiencia_comercial' => $eficiencia,
        'rentabilidade' => $rentabilidade,
        'fidelizacao' => $fidelizacao,
        'funil_comercial' => [
            'volume_atendimentos' => $atendimentos,
            'oportunidades_abertas' => $orcamentos,
            'orcamentos_enviados' => $orcamentos,
            'vendas_fechadas' => $vendasFechadas,
            'conversao_por_etapa' => $conversoes,
            'gargalo_funil' => $gargalo,
            'etapas_raw' => $funilMap,
        ],
        'performance_vendedor' => $vendedores,
        'vendedor_gestao' => [
            'resumo' => [
                'consultoras_ativas' => $consultorasAtivas,
                'receita_equipe' => round($receitaEquipe, 2),
                'conversao_media' => $conversaoMedia,
                'tempo_medio_espera_min' => $tempoMedioEquipe,
                'clientes_atendidos' => $clientesAtendidosEquipe,
                'taxa_perda_media' => $taxaPerdaMedia,
                'meta_mensal_equipe' => round($metaMensalEquipeSoma, 2),
                'percentual_meta_equipe' => $percentualMetaEquipe,
                'previsao_ganho_equipe' => round($previsaoGanhoEquipeSoma, 2),
                'comissao_pct_grupo_equipe' => round($comissaoGrupoPctVend, 2),
            ],
            'vendedores_cadastrados' => $vendedoresCadastrados,
            'equipe' => $vendedores,
            'motivos_perda' => $motivosPerda,
            'clientes_rejeitados_com_contato' => $clientesRejeitadosComContato,
            'evolucao_mensal_vendedores' => $evolucaoMensalVendedores,
            'evolucao_mensal_aprovados' => $evolucaoMensalAprovRej['aprovados'],
            'evolucao_mensal_rejeitados' => $evolucaoMensalAprovRej['rejeitados'],
        ],
        'performance_canal' => $canais,
        'inteligencia_comercial' => [
            'top_formulas_mais_vendidas' => $topFormulas,
            'formulas_maior_margem' => $formulasMargem,
            'prescritores_maior_receita' => $topPrescritores,
            'ticket_medio_por_especialidade' => $ticketEspecialidade,
        ],
        'financeiro_aplicado' => [
            'receita' => [
                'faturamento_bruto' => round($receitaMes, 2),
                'faturamento_liquido' => round($receitaMes, 2),
            ],
            'custos' => [
                'cmv' => round($custoMes, 2),
                'custo_fixo_mensal' => null,
                'custo_variavel_por_venda' => $vendasMes > 0 ? round($custoMes / $vendasMes, 2) : 0,
                'custo_por_atendimento' => $atendimentos > 0 ? round($custoMes / $atendimentos, 2) : 0,
                'cac' => null,
            ],
            'rentabilidade' => [
                'margem_bruta' => $margemBruta,
                'margem_contribuicao' => $margemBruta,
                'lucro_operacional' => round($lucroOperacional, 2),
                'ponto_equilibrio' => null,
                'roi_campanhas' => null,
            ],
            'caixa' => [
                'prazo_medio_recebimento' => null,
                'indice_inadimplencia' => null,
                'taxa_antecipacao' => null,
                'fluxo_projetado_3_meses' => null,
            ],
            'notas' => [
                'campos_nulos' => 'MÃ©tricas dependentes de marketing/financeiro nÃ£o existem hoje na base transacional.'
            ]
        ],
        'relatorios_comerciais' => $relatoriosComerciais,
    ];

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao montar JSON do painel (dados invÃ¡lidos para serializaÃ§Ã£o).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $json;
    exit;
}



