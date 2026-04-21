<?php
ini_set('display_errors', '0');
if (function_exists('ini_set')) {
    @ini_set('log_errors', '1');
}
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$allowedOrigins = [
    'http://localhost',
    'https://localhost',
    'http://127.0.0.1',
    'https://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function bonusNormalize(string $value): string
{
    $v = trim($value);
    $map = [
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'Ç'=>'C','ç'=>'c',
    ];
    $v = strtr($v, $map);
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}

function bonusIsEligibleVisitador(?string $visitador): bool
{
    $v = bonusNormalize((string)$visitador);
    if ($v === '') return false;
    return strpos($v, 'aulis') !== false || strpos($v, 'priscila') !== false;
}

function bonusCanDebit(string $setor, string $tipo): bool
{
    $s = bonusNormalize($setor);
    $t = bonusNormalize($tipo);
    if ($t === 'admin') return true;
    return strpos($s, 'visitador') !== false
        || strpos($s, 'gerente') !== false
        || strpos($s, 'vendedor') !== false;
}

/** Normaliza espaços (incl. NBSP) para comparar nomes de prescritor entre tabelas e a lista. */
function bonusPrescritorNormalizeSpaces(string $s): string
{
    $t = str_replace("\xc2\xa0", ' ', trim($s));

    return preg_replace('/\s+/u', ' ', $t) ?? $t;
}

/** Mesmo prescritor para casar override (lista = pc.nome vs chave gravada em prescritor_bonus_admin_desconto). */
function bonusPrescritorSameForMap(string $a, string $b): bool
{
    $a = bonusPrescritorNormalizeSpaces($a);
    $b = bonusPrescritorNormalizeSpaces($b);
    if ($a === $b) {
        return true;
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
    }

    return strtolower($a) === strtolower($b);
}

/** Nome canônico em prescritores_cadastro (mesmo texto da lista), ou o valor informado. */
function bonusResolvePrescritorNomeCadastro(PDO $pdo, string $prescritorInformado): string
{
    $p = bonusPrescritorNormalizeSpaces($prescritorInformado);
    if ($p === '') {
        return '';
    }
    try {
        $st = $pdo->prepare('SELECT nome FROM prescritores_cadastro WHERE nome = :a OR LOWER(TRIM(nome)) = LOWER(TRIM(:b)) LIMIT 1');
        $st->execute(['a' => $p, 'b' => $p]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $nome = trim((string)($row['nome'] ?? ''));

        return $nome !== '' ? $nome : $p;
    } catch (Throwable $e) {
        return $p;
    }
}

/** Percentual (0–100) por prescritor para fechamento, coluna “%” na lista e “A bonificar” (projeção aberta); fallback = desconto universal. */
function bonusGetAdminDescontoMap(PDO $pdo, array $prescritores): array
{
    $clean = [];
    foreach ($prescritores as $p) {
        $t = bonusPrescritorNormalizeSpaces((string)$p);
        if ($t !== '') {
            $clean[] = $t;
        }
    }
    $clean = array_values(array_unique($clean));
    $globalPct = bonusGetGlobalDefaultPct($pdo);
    $out = [];
    foreach ($clean as $p) {
        $out[$p] = $globalPct;
    }
    if ($clean === []) {
        return $out;
    }
    foreach (array_chunk($clean, 300) as $chunk) {
        $ph = [];
        $params = [];
        foreach ($chunk as $i => $name) {
            $k = ':d' . $i;
            $ph[] = $k;
            $params[$k] = $name;
        }
        $sql = 'SELECT prescritor, desconto_percent FROM prescritor_bonus_admin_desconto WHERE prescritor IN (' . implode(',', $ph) . ')';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pDb = bonusPrescritorNormalizeSpaces((string)($r['prescritor'] ?? ''));
            if ($pDb === '') {
                continue;
            }
            $pct = max(0.0, min(100.0, (float)($r['desconto_percent'] ?? $globalPct)));
            foreach ($clean as $pList) {
                if (bonusPrescritorSameForMap($pList, $pDb)) {
                    $out[$pList] = $pct;
                }
            }
        }
    }
    return $out;
}

function bonusGetStatusMap(PDO $pdo, array $prescritores): array
{
    $names = [];
    foreach ($prescritores as $p) {
        $v = trim((string)$p);
        if ($v !== '') $names[] = $v;
    }
    $names = array_values(array_unique($names));
    if (!$names) return [];
    $chunks = array_chunk($names, 300);
    $out = [];
    foreach ($chunks as $chunk) {
        $ph = [];
        $params = [];
        foreach ($chunk as $i => $name) {
            $k = ':p' . $i;
            $ph[] = $k;
            $params[$k] = $name;
        }
        $sql = "SELECT prescritor, apto, observacao, atualizado_em, atualizado_por_nome
                FROM prescritor_bonus_status
                WHERE prescritor IN (" . implode(',', $ph) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['prescritor']] = $r;
        }
    }
    return $out;
}

function bonusInitTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_creditos_mensais (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        prescritor VARCHAR(255) NOT NULL,
        visitador VARCHAR(150) NULL,
        competencia_ano INT NOT NULL,
        competencia_mes TINYINT NOT NULL,
        referencia_ano INT NOT NULL,
        referencia_mes TINYINT NOT NULL,
        valor_base DECIMAL(14,2) NOT NULL DEFAULT 0,
        percentual DECIMAL(6,4) NOT NULL DEFAULT 0.2000,
        valor_credito DECIMAL(14,2) NOT NULL DEFAULT 0,
        gerado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL,
        INDEX idx_prescritor (prescritor(120)),
        INDEX idx_competencia (competencia_ano, competencia_mes),
        UNIQUE KEY uq_presc_comp (prescritor(120), competencia_ano, competencia_mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_debitos (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        prescritor VARCHAR(255) NOT NULL,
        visitador VARCHAR(150) NULL,
        numero_pedido INT NOT NULL DEFAULT 0,
        serie_pedido INT NOT NULL DEFAULT 0,
        valor_debito DECIMAL(14,2) NOT NULL,
        data_debito DATE NOT NULL,
        usuario_id INT NOT NULL,
        usuario_nome VARCHAR(255) NOT NULL,
        observacao VARCHAR(500) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prescritor (prescritor(120)),
        INDEX idx_data (data_debito),
        INDEX idx_usuario (usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_meta (
        meta_key VARCHAR(120) PRIMARY KEY,
        meta_value VARCHAR(255) NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_status (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        prescritor VARCHAR(255) NOT NULL,
        apto TINYINT(1) NOT NULL DEFAULT 1,
        observacao VARCHAR(500) NULL,
        atualizado_por_id INT NULL,
        atualizado_por_nome VARCHAR(255) NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_prescritor (prescritor(120)),
        INDEX idx_apto (apto)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS prescritor_bonus_admin_desconto (
        prescritor VARCHAR(255) NOT NULL,
        desconto_percent DECIMAL(6,2) NOT NULL DEFAULT 20.00,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        atualizado_por_id INT NULL,
        atualizado_por_nome VARCHAR(255) NULL,
        PRIMARY KEY (prescritor)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function bonusColumnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $st->execute(['t' => $table, 'c' => $column]);
        return ((int)$st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Percentual padrão do sistema (fechamentos futuros e fallback por prescritor). */
function bonusGetGlobalDefaultPct(PDO $pdo): float
{
    try {
        $st = $pdo->query('SELECT percentual_desconto_admin FROM config_bonificacao ORDER BY id ASC LIMIT 1');
        $v = (float)($st ? $st->fetchColumn() : 20);
        return max(0.0, min(100.0, $v));
    } catch (Throwable $e) {
        return 20.0;
    }
}

/** Percentual efetivo para fechamento / prévia: override por prescritor, senão config global. */
function bonusGetEffectivePctForPrescritor(PDO $pdo, string $prescritor, array $descontoMap): float
{
    $p = bonusPrescritorNormalizeSpaces($prescritor);
    if ($p === '') {
        return bonusGetGlobalDefaultPct($pdo);
    }
    if (array_key_exists($p, $descontoMap)) {
        return max(0.0, min(100.0, (float)$descontoMap[$p]));
    }
    foreach ($descontoMap as $k => $v) {
        if (bonusPrescritorSameForMap((string)$k, $p)) {
            return max(0.0, min(100.0, (float)$v));
        }
    }

    return bonusGetGlobalDefaultPct($pdo);
}

function bonusEnsureExtendedSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS config_bonificacao (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        percentual_desconto_admin DECIMAL(6,2) NOT NULL DEFAULT 20.00,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        $pdo->exec("INSERT INTO config_bonificacao (id, percentual_desconto_admin) SELECT 1, 20.00 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM config_bonificacao WHERE id = 1 LIMIT 1)");
    } catch (Throwable $e) {
        // MySQL sem DUAL: tentativa alternativa
        try {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM config_bonificacao')->fetchColumn();
            if ($n === 0) {
                $pdo->exec("INSERT INTO config_bonificacao (id, percentual_desconto_admin) VALUES (1, 20.00)");
            }
        } catch (Throwable $e2) {
        }
    }

    $t = 'prescritor_bonus_creditos_mensais';
    $adds = [
        ['credito_status', "VARCHAR(16) NULL DEFAULT NULL COMMENT 'aberto|fechado'"],
        ['valor_bruto_snapshot', 'DECIMAL(14,2) NULL DEFAULT NULL'],
        ['pct_desconto_admin_aplicado', 'DECIMAL(6,2) NULL DEFAULT NULL'],
        ['valor_liquido_consolidado', 'DECIMAL(14,2) NULL DEFAULT NULL'],
        ['fechado_em', 'DATETIME NULL DEFAULT NULL'],
    ];
    foreach ($adds as [$col, $ddl]) {
        if (!bonusColumnExists($pdo, $t, $col)) {
            $pdo->exec("ALTER TABLE `{$t}` ADD COLUMN `{$col}` {$ddl}");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS bonificacao_utilizada (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        prescritor VARCHAR(255) NOT NULL,
        valor_utilizado DECIMAL(14,2) NOT NULL DEFAULT 0,
        descricao VARCHAR(500) NULL,
        data_utilizacao DATE NOT NULL,
        usuario_id INT NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_prescritor (prescritor(120)),
        INDEX idx_data (data_utilizacao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT 'Reserva para evolução; utilizações atuais em prescritor_bonus_debitos'");
}

function bonusCompetenciaAberta(DateTime $now): array
{
    $next = (clone $now)->modify('first day of next month');
    return [(int)$next->format('Y'), (int)$next->format('n')];
}

function bonusCreditRowIsFechado(?string $status): bool
{
    return strtolower(trim((string)$status)) === 'fechado';
}

/**
 * Só permite aprovar/fechar referência ou competência após o último dia civil do mês (ex.: ref. 04/2026 a partir de 01/05/2026 no fuso informado).
 */
function bonusMesCalendarioJaEncerrou(int $ano, int $mes, DateTimeZone $tz): bool
{
    if ($ano < 2000 || $mes < 1 || $mes > 12) {
        return false;
    }
    $hoje = (new DateTime('now', $tz))->format('Y-m-d');
    try {
        $ultimo = (new DateTime(sprintf('%04d-%02d-01', $ano, $mes), $tz))->modify('last day of this month')->format('Y-m-d');
    } catch (Throwable $e) {
        return false;
    }

    return $hoje > $ultimo;
}

function bonusMsgMesCalendarioNaoEncerrou(int $ano, int $mes, DateTimeZone $tz): string
{
    try {
        $dtUlt = new DateTime(sprintf('%04d-%02d-01', $ano, $mes), $tz);
        $dtUlt->modify('last day of this month');
        $ultimoBr = $dtUlt->format('d/m/Y');
        $dtLib = (clone $dtUlt)->modify('+1 day');
        $libBr = $dtLib->format('d/m/Y');
    } catch (Throwable $e) {
        return 'O mês civil selecionado ainda não encerrou.';
    }
    $ref = sprintf('%02d/%04d', $mes, $ano);

    return "Não é possível aprovar o período {$ref} enquanto o mês civil correspondente não tiver terminado (último dia {$ultimoBr}). Liberado a partir de {$libBr}.";
}

/**
 * Aplica fechamento (congela bruto, líquido) numa linha de crédito em aberto.
 * O líquido consolidado é sempre o valor atual da linha (valor_credito = o que está em “A bonificar”),
 * sem reaplicar o desconto universal no fechamento. O % gravado é só informativo (derivado de bruto vs líquido).
 *
 * @param array<string,mixed> $rLinha Linha com valor_credito, valor_bruto_snapshot, credito_status
 * @param array<string,float> $descontoMap ignorado (assinatura mantida para chamadas existentes)
 * @return int 1 se atualizou, 0 se já fechada ou sem efeito
 */
function bonusAplicarFechamentoPrescritorCompetencia(
    PDO $pdo,
    string $prescritor,
    int $compAno,
    int $compMes,
    array $rLinha,
    array $descontoMap
): int {
    $p = trim($prescritor);
    if ($p === '') {
        return 0;
    }
    if (bonusCreditRowIsFechado($rLinha['credito_status'] ?? null)) {
        return 0;
    }
    $netDb = round((float)($rLinha['valor_credito'] ?? 0), 2);
    $brutoSnap = round((float)($rLinha['valor_bruto_snapshot'] ?? 0), 2);
    if ($brutoSnap <= 0 && $netDb > 0) {
        $brutoSnap = $netDb;
    }
    $liq = $netDb;
    if ($liq < 0) {
        $liq = 0.0;
    }
    $pct = null;
    if ($brutoSnap > 0.0001) {
        $derived = round(100.0 - (100.0 * $liq / $brutoSnap), 2);
        if (is_finite($derived)) {
            $pct = max(0.0, min(100.0, $derived));
        }
    }
    $upd = $pdo->prepare("
        UPDATE prescritor_bonus_creditos_mensais
        SET
            valor_bruto_snapshot = :bruto,
            pct_desconto_admin_aplicado = :pct,
            valor_liquido_consolidado = :liq,
            credito_status = 'fechado',
            fechado_em = NOW()
        WHERE prescritor = :p AND competencia_ano = :y AND competencia_mes = :m
          AND (credito_status IS NULL OR credito_status = '' OR credito_status = 'aberto')
    ");
    $upd->execute([
        'bruto' => $brutoSnap,
        'pct' => $pct,
        'liq' => $liq,
        'p' => $p,
        'y' => $compAno,
        'm' => $compMes,
    ]);

    return $upd->rowCount() > 0 ? 1 : 0;
}

/**
 * Valor exibido em "A bonificar": na projeção aberta, comissão (valor_bruto_snapshot) × (1 − %),
 * com % = reserva administrativa efetiva do prescritor (override ou desconto universal); consolidado se já fechado.
 *
 * @param array<string,mixed>|null $projLinha Linha da competência de projeção (próximo mês) ou null
 * @param array<string,float>      $descontoMap Mapa prescritor => % (bonusGetAdminDescontoMap)
 */
function bonusCalcAbonificarExibir(PDO $pdo, string $prescritor, ?array $projLinha, array $descontoMap): float
{
    if ($projLinha === null || $prescritor === '') {
        return 0.0;
    }
    if (bonusCreditRowIsFechado($projLinha['credito_status'] ?? null)) {
        $liq = $projLinha['valor_liquido_consolidado'] ?? null;
        if ($liq !== null && $liq !== '') {
            return round((float)$liq, 2);
        }

        return round((float)($projLinha['valor_credito'] ?? 0), 2);
    }
    $bruto = round((float)($projLinha['valor_bruto_snapshot'] ?? 0), 2);
    $credAberto = round((float)($projLinha['valor_credito'] ?? 0), 2);
    if ($bruto <= 0 && $credAberto > 0) {
        $bruto = $credAberto;
    }
    if ($bruto > 0) {
        $pct = bonusGetEffectivePctForPrescritor($pdo, $prescritor, $descontoMap);
        $liq = round($bruto * (1.0 - ($pct / 100.0)), 2);

        return $liq < 0 ? 0.0 : $liq;
    }

    return $credAberto;
}

/**
 * Atualiza valor_credito da linha de projeção (próxima competência) em aberto conforme a reserva efetiva do prescritor.
 */
function bonusRefreshValorCreditoLinhaAbertaProjecao(PDO $pdo, string $prescritor): void
{
    $tz = new DateTimeZone('America/Porto_Velho');
    $now = new DateTime('now', $tz);
    $next = (clone $now)->modify('first day of next month');
    $na = (int)$next->format('Y');
    $nm = (int)$next->format('n');
    $p = bonusPrescritorNormalizeSpaces($prescritor);
    if ($p === '') {
        return;
    }
    $st = $pdo->prepare('
        SELECT valor_credito, valor_bruto_snapshot, credito_status, valor_liquido_consolidado, valor_base, percentual
        FROM prescritor_bonus_creditos_mensais
        WHERE prescritor = :p AND competencia_ano = :a AND competencia_mes = :m
        LIMIT 1
    ');
    $st->execute(['p' => $p, 'a' => $na, 'm' => $nm]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    if (bonusCreditRowIsFechado($row['credito_status'] ?? null)) {
        return;
    }
    $proj = [
        'valor_credito' => $row['valor_credito'],
        'valor_bruto_snapshot' => $row['valor_bruto_snapshot'],
        'credito_status' => $row['credito_status'],
        'valor_liquido_consolidado' => $row['valor_liquido_consolidado'],
        'valor_base' => $row['valor_base'],
        'percentual_credito' => $row['percentual'],
    ];
    $dMap = bonusGetAdminDescontoMap($pdo, [$p]);
    $novo = round(bonusCalcAbonificarExibir($pdo, $p, $proj, $dMap), 2);
    $upd = $pdo->prepare('
        UPDATE prescritor_bonus_creditos_mensais
        SET valor_credito = :vc, atualizado_em = NOW()
        WHERE prescritor = :p AND competencia_ano = :a AND competencia_mes = :m
          AND (credito_status IS NULL OR credito_status = \'\' OR LOWER(TRIM(credito_status)) <> \'fechado\')
    ');
    $upd->execute(['vc' => $novo, 'p' => $p, 'a' => $na, 'm' => $nm]);
}

/**
 * Valor da comissão (ex.: 20% sobre vendas) na linha de projeção — é o que está em valor_bruto_snapshot.
 *
 * @param array<string,mixed>|null $projLinha Mesma forma usada em bonusCalcAbonificarExibir (+ valor_base, percentual_credito)
 */
function bonusAdminReservaCommissionBruto(?array $projLinha): float
{
    if ($projLinha === null) {
        return 0.0;
    }
    $bruto = round((float)($projLinha['valor_bruto_snapshot'] ?? 0), 2);
    $credAberto = round((float)($projLinha['valor_credito'] ?? 0), 2);
    if ($bruto <= 0 && $credAberto > 0) {
        $bruto = $credAberto;
    }

    return $bruto < 0 ? 0.0 : $bruto;
}

/** Faturamento (base pedidos) que gerou a comissão — valor_base; estima a partir da comissão se vier zerado. */
function bonusAdminReservaValorVendas(?array $projLinha): float
{
    if ($projLinha === null) {
        return 0.0;
    }
    $vb = round((float)($projLinha['valor_base'] ?? 0), 2);
    if ($vb > 0) {
        return $vb;
    }
    $com = bonusAdminReservaCommissionBruto($projLinha);
    $pctLine = (float)($projLinha['percentual_credito'] ?? 0);
    if ($pctLine > 0 && $pctLine <= 1 && $com > 0) {
        return round($com / $pctLine, 2);
    }
    if ($com > 0) {
        return round($com / 0.20, 2);
    }

    return 0.0;
}

/** Percentual da comissão sobre vendas (ex.: 0,2 → 20) para exibição. */
function bonusAdminReservaPctComissaoVendasDisplay(?array $projLinha): float
{
    if ($projLinha === null) {
        return 20.0;
    }
    $p = (float)($projLinha['percentual_credito'] ?? 0);
    if ($p > 0 && $p <= 1) {
        return round($p * 100, 2);
    }
    if ($p > 1) {
        return round($p, 2);
    }

    return 20.0;
}

/** Líquido após aplicar reserva administrativa % sobre a comissão (prévia). */
function bonusAdminReservaLiquidoSobreComissao(float $valorComissao, float $pct): float
{
    if ($valorComissao <= 0) {
        return 0.0;
    }
    $p = max(0.0, min(100.0, $pct));
    $liq = round($valorComissao * (1.0 - ($p / 100.0)), 2);

    return $liq < 0 ? 0.0 : $liq;
}

/**
 * Fecha uma competência (mês de crédito): congela bruto, grava % e líquido, não altera mais após fechamento.
 * @return array{ok:bool, fechados:int, message:string}
 */
function bonusFecharCompetenciaInterno(PDO $pdo, int $compAno, int $compMes, ?int $uid, ?string $unome, bool $silent): array
{
    $tz = new DateTimeZone('America/Porto_Velho');
    $now = new DateTime('now', $tz);
    if (!bonusMesCalendarioJaEncerrou($compAno, $compMes, $tz)) {
        return [
            'ok' => false,
            'fechados' => 0,
            'message' => bonusMsgMesCalendarioNaoEncerrou($compAno, $compMes, $tz),
            'ja_aprovado' => false,
        ];
    }
    [$nextAno, $nextMes] = bonusCompetenciaAberta($now);
    // Não fechar a linha de projeção (próxima competência)
    if ($compAno === $nextAno && $compMes === $nextMes) {
        return ['ok' => false, 'fechados' => 0, 'message' => 'Não é permitido fechar a competência em aberto (mês atual a bonificar).', 'ja_aprovado' => false];
    }

    $stList = $pdo->prepare("SELECT prescritor, visitador, valor_credito, valor_bruto_snapshot, credito_status FROM prescritor_bonus_creditos_mensais WHERE competencia_ano = :y AND competencia_mes = :m");
    $stList->execute(['y' => $compAno, 'm' => $compMes]);
    $lista = $stList->fetchAll(PDO::FETCH_ASSOC);
    if (!$lista) {
        return ['ok' => false, 'fechados' => 0, 'message' => 'Nenhum crédito encontrado para esta competência.', 'ja_aprovado' => false];
    }
    $hadOpen = false;
    foreach ($lista as $r) {
        if (!bonusCreditRowIsFechado($r['credito_status'] ?? null)) {
            $hadOpen = true;
            break;
        }
    }
    if (!$hadOpen) {
        return [
            'ok' => true,
            'fechados' => 0,
            'message' => 'Este mês de competência já está aprovado: todas as linhas estão consolidadas.',
            'ja_aprovado' => true,
        ];
    }
    $names = array_values(array_unique(array_map(static fn($r) => trim((string)($r['prescritor'] ?? '')), $lista)));
    $descontoMap = bonusGetAdminDescontoMap($pdo, $names);
    $n = 0;
    foreach ($lista as $r) {
        $p = trim((string)($r['prescritor'] ?? ''));
        if ($p === '') {
            continue;
        }
        $n += bonusAplicarFechamentoPrescritorCompetencia($pdo, $p, $compAno, $compMes, $r, $descontoMap);
    }
    if ($n === 0) {
        return [
            'ok' => true,
            'fechados' => 0,
            'message' => 'Este mês de competência já está aprovado: não há linhas em aberto para consolidar.',
            'ja_aprovado' => true,
        ];
    }
    $msg = "Competência {$compMes}/{$compAno} aprovada: {$n} prescritor(es).";

    return ['ok' => true, 'fechados' => $n, 'message' => $msg, 'ja_aprovado' => false];
}

/**
 * Aprova (fecha) todas as linhas de crédito cujo mês de referência de vendas coincide com o informado.
 * Evita confundir competência contábil (ex.: 04/2026) com o mês de vendas (ex.: 03/2026) exibido na tabela.
 *
 * @return array{ok:bool, fechados:int, message:string, ja_aprovado:bool}
 */
function bonusFecharPorReferenciaInterno(PDO $pdo, int $refAno, int $refMes, ?int $uid, ?string $unome, bool $silent): array
{
    $tz = new DateTimeZone('America/Porto_Velho');
    $now = new DateTime('now', $tz);
    if (!bonusMesCalendarioJaEncerrou($refAno, $refMes, $tz)) {
        return [
            'ok' => false,
            'fechados' => 0,
            'message' => bonusMsgMesCalendarioNaoEncerrou($refAno, $refMes, $tz),
            'ja_aprovado' => false,
        ];
    }
    [$nextAno, $nextMes] = bonusCompetenciaAberta($now);
    $stList = $pdo->prepare("
        SELECT prescritor, visitador, valor_credito, valor_bruto_snapshot, credito_status, competencia_ano, competencia_mes
        FROM prescritor_bonus_creditos_mensais
        WHERE referencia_ano = :ry AND referencia_mes = :rm
    ");
    $stList->execute(['ry' => $refAno, 'rm' => $refMes]);
    $lista = $stList->fetchAll(PDO::FETCH_ASSOC);
    if (!$lista) {
        return [
            'ok' => false,
            'fechados' => 0,
            'message' => 'Não há créditos com referência de vendas em ' . sprintf('%02d/%04d', $refMes, $refAno) . '.',
            'ja_aprovado' => false,
        ];
    }
    $hadCloseableOpen = false;
    foreach ($lista as $r) {
        $cy = (int)($r['competencia_ano'] ?? 0);
        $cm = (int)($r['competencia_mes'] ?? 0);
        if ($cy === $nextAno && $cm === $nextMes) {
            continue;
        }
        if (!bonusCreditRowIsFechado($r['credito_status'] ?? null)) {
            $hadCloseableOpen = true;
            break;
        }
    }
    if (!$hadCloseableOpen) {
        $allFechado = true;
        foreach ($lista as $r) {
            if (!bonusCreditRowIsFechado($r['credito_status'] ?? null)) {
                $allFechado = false;
                break;
            }
        }
        if ($allFechado) {
            return [
                'ok' => true,
                'fechados' => 0,
                'message' => 'Este mês de referência (vendas) já está aprovado: não há linhas em aberto.',
                'ja_aprovado' => true,
            ];
        }

        return [
            'ok' => false,
            'fechados' => 0,
            'message' => 'Não é possível aprovar apenas a competência em projeção (mês a bonificar). Escolha um período de referência já consolidável.',
            'ja_aprovado' => false,
        ];
    }
    $names = array_values(array_unique(array_map(static fn($r) => trim((string)($r['prescritor'] ?? '')), $lista)));
    $descontoMap = bonusGetAdminDescontoMap($pdo, $names);
    $n = 0;
    foreach ($lista as $r) {
        $cy = (int)($r['competencia_ano'] ?? 0);
        $cm = (int)($r['competencia_mes'] ?? 0);
        if ($cy === $nextAno && $cm === $nextMes) {
            continue;
        }
        $p = trim((string)($r['prescritor'] ?? ''));
        if ($p === '') {
            continue;
        }
        $n += bonusAplicarFechamentoPrescritorCompetencia($pdo, $p, $cy, $cm, $r, $descontoMap);
    }
    if ($n === 0) {
        return [
            'ok' => true,
            'fechados' => 0,
            'message' => 'Este mês de referência já está aprovado: não há linhas em aberto para consolidar.',
            'ja_aprovado' => true,
        ];
    }
    $msg = 'Referência de vendas ' . sprintf('%02d/%04d', $refMes, $refAno) . " aprovada: {$n} linha(s) de crédito.";

    return ['ok' => true, 'fechados' => $n, 'message' => $msg, 'ja_aprovado' => false];
}

function bonusTryAutoFecharMes(PDO $pdo, DateTime $now): void
{
    if ((int)$now->format('j') !== 1) {
        return;
    }
    $ym = $now->format('Y-m');
    try {
        $chk = $pdo->prepare("SELECT meta_value FROM prescritor_bonus_meta WHERE meta_key = 'bonus_auto_fechar_ym' LIMIT 1");
        $chk->execute();
        $cur = trim((string)($chk->fetchColumn() ?: ''));
        if ($cur === $ym) {
            return;
        }
        // Dia 1: tenta fechar a competência do mês civil anterior (o mês atual acabou de começar).
        $prev = (clone $now)->modify('first day of previous month');
        $ano = (int)$prev->format('Y');
        $mes = (int)$prev->format('n');
        bonusFecharCompetenciaInterno($pdo, $ano, $mes, null, null, true);
        $ins = $pdo->prepare("INSERT INTO prescritor_bonus_meta (meta_key, meta_value, atualizado_em) VALUES ('bonus_auto_fechar_ym', :v, NOW()) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), atualizado_em = NOW()");
        $ins->execute(['v' => $ym]);
    } catch (Throwable $e) {
    }
}

function bonusShouldRefreshCredits(PDO $pdo, DateTime $now, int $ttlMinutes = 120): bool
{
    $st = $pdo->prepare("SELECT meta_value FROM prescritor_bonus_meta WHERE meta_key = 'last_generation_run' LIMIT 1");
    $st->execute();
    $last = trim((string)($st->fetchColumn() ?: ''));
    if ($last === '') {
        return true;
    }
    try {
        $lastDt = new DateTime($last, new DateTimeZone('America/Porto_Velho'));
    } catch (Throwable $e) {
        return true;
    }
    $diff = $now->getTimestamp() - $lastDt->getTimestamp();
    return $diff >= ($ttlMinutes * 60);
}

function bonusMarkCreditsRefreshed(PDO $pdo, DateTime $now): void
{
    $st = $pdo->prepare("
        INSERT INTO prescritor_bonus_meta (meta_key, meta_value, atualizado_em)
        VALUES ('last_generation_run', :v, NOW())
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), atualizado_em = NOW()
    ");
    $st->execute(['v' => $now->format('Y-m-d H:i:s')]);
}

function bonusUpsertCreditsByReference(
    PDO $pdo,
    int $competenciaAno,
    int $competenciaMes,
    int $refAno,
    int $refMes,
    string $refIni,
    string $refFim
): void {
    $fimMaisUm = $refFim;
    try {
        $fimMaisUm = (new DateTime($refFim, new DateTimeZone('America/Porto_Velho')))
            ->modify('+1 day')
            ->format('Y-m-d');
    } catch (Throwable $e) {
        $fimMaisUm = $refFim;
    }

    $sql = "
        SELECT
            pc.nome AS prescritor,
            pc.visitador AS visitador,
            COALESCE(SUM(gp.preco_liquido), 0) AS valor_base
        FROM prescritores_cadastro pc
        LEFT JOIN gestao_pedidos gp
            ON COALESCE(NULLIF(gp.prescritor, ''), 'My Pharm') = pc.nome
            AND gp.data_aprovacao IS NOT NULL
            AND gp.data_aprovacao >= :d1
            AND gp.data_aprovacao < :d2_next
            AND (
                gp.status_financeiro IS NULL
                OR (
                    gp.status_financeiro NOT IN ('Recusado', 'Cancelado', 'Orçamento')
                    AND gp.status_financeiro NOT LIKE '%carrinho%'
                )
            )
        GROUP BY pc.nome, pc.visitador
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['d1' => $refIni, 'd2_next' => $fimMaisUm]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $prescsForMap = [];
    foreach ($rows as $rr) {
        $pv = trim((string)($rr['prescritor'] ?? ''));
        if ($pv !== '' && bonusIsEligibleVisitador((string)($rr['visitador'] ?? ''))) {
            $prescsForMap[] = $pv;
        }
    }
    $dMapUpsert = bonusGetAdminDescontoMap($pdo, array_values(array_unique($prescsForMap)));

    $ins = $pdo->prepare("
        INSERT INTO prescritor_bonus_creditos_mensais
            (prescritor, visitador, competencia_ano, competencia_mes, referencia_ano, referencia_mes, valor_base, percentual, valor_bruto_snapshot, valor_credito, gerado_em, atualizado_em, credito_status)
        VALUES
            (:prescritor, :visitador, :comp_ano, :comp_mes, :ref_ano, :ref_mes, :valor_base, 0.2000, :valor_bruto_snapshot, :valor_credito, NOW(), NOW(), 'aberto')
        ON DUPLICATE KEY UPDATE
            visitador = IF(COALESCE(credito_status, '') = 'fechado', visitador, VALUES(visitador)),
            referencia_ano = IF(COALESCE(credito_status, '') = 'fechado', referencia_ano, VALUES(referencia_ano)),
            referencia_mes = IF(COALESCE(credito_status, '') = 'fechado', referencia_mes, VALUES(referencia_mes)),
            valor_base = IF(COALESCE(credito_status, '') = 'fechado', valor_base, VALUES(valor_base)),
            valor_bruto_snapshot = IF(COALESCE(credito_status, '') = 'fechado', valor_bruto_snapshot, VALUES(valor_bruto_snapshot)),
            valor_credito = IF(COALESCE(credito_status, '') = 'fechado', valor_credito, VALUES(valor_credito)),
            atualizado_em = IF(COALESCE(credito_status, '') = 'fechado', atualizado_em, NOW())
    ");

    foreach ($rows as $r) {
        $visitador = (string)($r['visitador'] ?? '');
        if (!bonusIsEligibleVisitador($visitador)) {
            continue;
        }
        $presc = (string)($r['prescritor'] ?? '');
        $valorBase = (float)($r['valor_base'] ?? 0);
        $valorBruto = round($valorBase * 0.20, 2);
        $pctEff = bonusGetEffectivePctForPrescritor($pdo, $presc, $dMapUpsert);
        $valorLiquido = round($valorBruto * (1.0 - ($pctEff / 100.0)), 2);
        if ($valorLiquido < 0) {
            $valorLiquido = 0.0;
        }
        $ins->execute([
            'prescritor' => $presc,
            'visitador' => $visitador,
            'comp_ano' => $competenciaAno,
            'comp_mes' => $competenciaMes,
            'ref_ano' => $refAno,
            'ref_mes' => $refMes,
            'valor_base' => $valorBase,
            'valor_bruto_snapshot' => $valorBruto,
            'valor_credito' => $valorLiquido,
        ]);
    }
}

function bonusGenerateMonthlyCredits(PDO $pdo): void
{
    $now = new DateTime('now', new DateTimeZone('America/Porto_Velho'));
    $competenciaAtualAno = (int)$now->format('Y');
    $competenciaAtualMes = (int)$now->format('n');
    $competenciaProx = (clone $now)->modify('first day of next month');
    $competenciaProxAno = (int)$competenciaProx->format('Y');
    $competenciaProxMes = (int)$competenciaProx->format('n');

    $refAnterior = (clone $now)->modify('first day of last month');
    $refAnteriorAno = (int)$refAnterior->format('Y');
    $refAnteriorMes = (int)$refAnterior->format('n');
    $refAnteriorIni = $refAnterior->format('Y-m-01');
    $refAnteriorFim = $refAnterior->format('Y-m-t');

    $refAtualAno = (int)$now->format('Y');
    $refAtualMes = (int)$now->format('n');
    $refAtualIni = $now->format('Y-m-01');
    $refAtualFim = $now->format('Y-m-d');

    // Crédito disponível no mês atual (base do mês anterior).
    bonusUpsertCreditsByReference(
        $pdo,
        $competenciaAtualAno,
        $competenciaAtualMes,
        $refAnteriorAno,
        $refAnteriorMes,
        $refAnteriorIni,
        $refAnteriorFim
    );

    // Crédito a bonificar para o próximo mês (base do mês atual acumulado até hoje).
    bonusUpsertCreditsByReference(
        $pdo,
        $competenciaProxAno,
        $competenciaProxMes,
        $refAtualAno,
        $refAtualMes,
        $refAtualIni,
        $refAtualFim
    );
}

function bonusGetResumoForNames(PDO $pdo, array $prescritores): array
{
    $result = [];
    $clean = [];
    $normalizedToOriginals = [];
    foreach ($prescritores as $p) {
        $v = trim((string)$p);
        if ($v === '') {
            continue;
        }
        $clean[] = $v;
        $norm = bonusNormalize($v);
        if ($norm !== '') {
            if (!isset($normalizedToOriginals[$norm])) {
                $normalizedToOriginals[$norm] = [];
            }
            $normalizedToOriginals[$norm][] = $v;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === []) return $result;
    $statusMap = bonusGetStatusMap($pdo, $clean);

    $tzRef = new DateTimeZone('America/Porto_Velho');
    $nowRef = new DateTime('now', $tzRef);
    $nextRef = (clone $nowRef)->modify('first day of next month');
    $na = (int)$nextRef->format('Y');
    $nm = (int)$nextRef->format('n');
    $curAnoRef = (int)$nowRef->format('Y');
    $curMesRef = (int)$nowRef->format('n');

    $chunkSize = 200;
    foreach (array_chunk($clean, $chunkSize) as $chunk) {
        $chunkNorm = array_map(static function ($name) {
            return bonusNormalize((string)$name);
        }, $chunk);
        $in = implode(',', array_fill(0, count($chunkNorm), '?'));
        $sql = "
            SELECT
                q.prescritor,
                q.visitador,
                q.a_utilizar AS total_credito,
                q.total_debito,
                (q.a_utilizar - q.total_debito) AS saldo,
                q.bonificacao_mes_anterior
            FROM (
                SELECT
                    pc.nome AS prescritor,
                    pc.visitador AS visitador,
                    COALESCE(c.a_utilizar, 0) AS a_utilizar,
                    COALESCE(d.total_debito, 0) AS total_debito,
                    COALESCE(ccur.valor_credito, 0) AS bonificacao_mes_anterior
                FROM prescritores_cadastro pc
                LEFT JOIN (
                    SELECT prescritor,
                        SUM(
                            CASE WHEN competencia_ano = {$na} AND competencia_mes = {$nm} THEN 0
                            WHEN LOWER(TRIM(COALESCE(credito_status, ''))) = 'fechado'
                                THEN COALESCE(valor_liquido_consolidado, valor_credito, 0)
                            ELSE 0 END
                        ) AS a_utilizar
                    FROM prescritor_bonus_creditos_mensais
                    GROUP BY prescritor
                ) c ON c.prescritor = pc.nome
                LEFT JOIN (
                    SELECT prescritor, valor_credito
                    FROM prescritor_bonus_creditos_mensais
                    WHERE competencia_ano = {$curAnoRef} AND competencia_mes = {$curMesRef}
                ) ccur ON ccur.prescritor = pc.nome
                LEFT JOIN (
                    SELECT prescritor, SUM(valor_debito) AS total_debito
                    FROM prescritor_bonus_debitos
                    GROUP BY prescritor
                ) d ON d.prescritor = pc.nome
                WHERE LOWER(TRIM(pc.nome)) IN ($in)
            ) q
        ";
        $st = $pdo->prepare($sql);
        $st->execute($chunkNorm);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = trim((string)$r['prescritor']);
            $baseElegivel = bonusIsEligibleVisitador((string)($r['visitador'] ?? ''));
            $status = $statusMap[$key] ?? null;
            $elegivel = $baseElegivel;
            if (is_array($status) && array_key_exists('apto', $status)) {
                $elegivel = ((int)$status['apto'] === 1);
            }
            $aUtil = (float)($r['total_credito'] ?? 0);
            $deb = (float)($r['total_debito'] ?? 0);
            $mesAnt = round((float)($r['bonificacao_mes_anterior'] ?? 0), 2);
            $payload = [
                'prescritor' => $key,
                'visitador' => (string)($r['visitador'] ?? ''),
                'bonificacao_eligivel' => $elegivel,
                'saldo_bonus' => (float)($r['saldo'] ?? 0),
                'bonificacao_a_utilizar' => $aUtil,
                'bonificacao_mes_anterior' => $mesAnt,
                'total_credito' => $aUtil,
                'total_debito' => $deb,
                'bonificacao_utilizada' => $deb,
                'status_apto_manual' => is_array($status) ? ((int)($status['apto'] ?? 1)) : null,
                'status_observacao' => is_array($status) ? (string)($status['observacao'] ?? '') : '',
            ];
            $result[$key] = $payload;

            $keyNorm = bonusNormalize($key);
            if ($keyNorm !== '' && isset($normalizedToOriginals[$keyNorm])) {
                foreach ($normalizedToOriginals[$keyNorm] as $originalName) {
                    $result[$originalName] = $payload;
                }
            }
        }
    }
    return $result;
}

function bonusGetExtrato(PDO $pdo, string $prescritor): array
{
    $out = [
        'resumo' => [
            'prescritor' => $prescritor,
            'visitador' => '',
            'bonificacao_eligivel' => false,
            'saldo_bonus' => 0.0,
            'total_credito' => 0.0,
            'total_debito' => 0.0,
        ],
        'creditos' => [],
        'debitos' => [],
    ];
    $map = bonusGetResumoForNames($pdo, [$prescritor]);
    if (isset($map[$prescritor])) {
        $out['resumo'] = $map[$prescritor];
    }
    $stC = $pdo->prepare("
        SELECT competencia_ano, competencia_mes, referencia_ano, referencia_mes, valor_base, percentual, valor_credito, gerado_em,
            credito_status, valor_bruto_snapshot, pct_desconto_admin_aplicado, valor_liquido_consolidado, fechado_em
        FROM prescritor_bonus_creditos_mensais
        WHERE prescritor = :p
        ORDER BY competencia_ano DESC, competencia_mes DESC
        LIMIT 36
    ");
    $stC->execute(['p' => $prescritor]);
    $out['creditos'] = $stC->fetchAll(PDO::FETCH_ASSOC);

    $stD = $pdo->prepare("
        SELECT id, numero_pedido, serie_pedido, valor_debito, data_debito, usuario_nome, criado_em, observacao
        FROM prescritor_bonus_debitos
        WHERE prescritor = :p
        ORDER BY data_debito DESC, id DESC
        LIMIT 50
    ");
    $stD->execute(['p' => $prescritor]);
    $out['debitos'] = $stD->fetchAll(PDO::FETCH_ASSOC);

    $tz = new DateTimeZone('America/Porto_Velho');
    $now = new DateTime('now', $tz);
    $prev = (clone $now)->modify('first day of last month');
    $next = (clone $now)->modify('first day of next month');
    $curAno = (int)$now->format('Y');
    $curMes = (int)$now->format('n');
    $nextAno = (int)$next->format('Y');
    $nextMes = (int)$next->format('n');

    $stCard = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN competencia_ano = :cur_ano AND competencia_mes = :cur_mes THEN valor_credito ELSE 0 END), 0) AS bonificacao_mes_anterior,
            COALESCE(SUM(
                CASE WHEN competencia_ano = :na2 AND competencia_mes = :nm2 THEN 0
                WHEN LOWER(TRIM(COALESCE(credito_status, ''))) = 'fechado'
                    THEN COALESCE(valor_liquido_consolidado, valor_credito, 0)
                ELSE 0 END
            ), 0) AS bonificacao_a_utilizar
        FROM prescritor_bonus_creditos_mensais
        WHERE prescritor = :prescritor
    ");
    $stCard->execute([
        'cur_ano' => $curAno,
        'cur_mes' => $curMes,
        'na2' => $nextAno,
        'nm2' => $nextMes,
        'prescritor' => $prescritor,
    ]);
    $rowCard = $stCard->fetch(PDO::FETCH_ASSOC) ?: [];
    $stProj = $pdo->prepare("
        SELECT valor_credito, valor_bruto_snapshot, credito_status, valor_liquido_consolidado
        FROM prescritor_bonus_creditos_mensais
        WHERE prescritor = :p AND competencia_ano = :na AND competencia_mes = :nm
        LIMIT 1
    ");
    $stProj->execute(['p' => $prescritor, 'na' => $nextAno, 'nm' => $nextMes]);
    $projLinha = $stProj->fetch(PDO::FETCH_ASSOC) ?: null;
    $dMapOne = bonusGetAdminDescontoMap($pdo, [$prescritor]);
    $aBonificarNet = bonusCalcAbonificarExibir($pdo, $prescritor, $projLinha, $dMapOne);
    $aUtilizar = round((float)($rowCard['bonificacao_a_utilizar'] ?? 0), 2);
    $deb = (float)($out['resumo']['total_debito'] ?? 0);

    $out['resumo']['bonificacao_mes_anterior'] = round((float)($rowCard['bonificacao_mes_anterior'] ?? 0), 2);
    $out['resumo']['bonificacao_mes_atual_a_bonificar'] = $aBonificarNet;
    $out['resumo']['bonificacao_a_utilizar'] = $aUtilizar;
    $out['resumo']['bonificacao_utilizada'] = $deb;
    $out['resumo']['bonificacoes_utilizadas'] = $deb;
    $out['resumo']['saldo_bonificacao'] = round($aUtilizar - $deb, 2);
    $out['resumo']['saldo_bonus'] = $out['resumo']['saldo_bonificacao'];
    $out['resumo']['total_credito'] = $aUtilizar;
    $out['resumo']['referencia_mes_anterior_label'] = $prev->format('m/Y');
    $out['resumo']['competencia_mes_atual_label'] = $now->format('m/Y');
    foreach (['admin_desconto_proximo_percent', 'liquido_previsto_a_bonificar'] as $__rk) {
        unset($out['resumo'][$__rk]);
    }

    return $out;
}

function bonusGetControleLista(PDO $pdo): array
{
    $tz = new DateTimeZone('America/Porto_Velho');
    $now = new DateTime('now', $tz);
    $prev = (clone $now)->modify('first day of last month');
    $next = (clone $now)->modify('first day of next month');
    $curAno = (int)$now->format('Y');
    $curMes = (int)$now->format('n');
    $nextAno = (int)$next->format('Y');
    $nextMes = (int)$next->format('n');
    $compMesLabel = $now->format('m/Y');
    $refMesLabel = $prev->format('m/Y');

    $sql = "
        SELECT
            pc.nome AS prescritor,
            pc.visitador AS visitador,
            COALESCE(ccur.valor_credito, 0) AS bonificacao_mes_anterior,
            cnext.valor_credito AS cnext_vc,
            cnext.valor_bruto_snapshot AS cnext_bruto,
            cnext.valor_base AS cnext_vb,
            cnext.percentual AS cnext_pct,
            cnext.credito_status AS cnext_st,
            cnext.valor_liquido_consolidado AS cnext_liq,
            COALESCE(cart.bonificacao_a_utilizar, 0) AS bonificacao_a_utilizar,
            COALESCE(d.total_debito, 0) AS bonificacoes_utilizadas
        FROM prescritores_cadastro pc
        LEFT JOIN (
            SELECT prescritor,
                SUM(
                    CASE WHEN competencia_ano = :next_ano AND competencia_mes = :next_mes THEN 0
                    WHEN LOWER(TRIM(COALESCE(credito_status, ''))) = 'fechado'
                        THEN COALESCE(valor_liquido_consolidado, valor_credito, 0)
                    ELSE 0 END
                ) AS bonificacao_a_utilizar
            FROM prescritor_bonus_creditos_mensais
            GROUP BY prescritor
        ) cart ON cart.prescritor = pc.nome
        LEFT JOIN (
            SELECT prescritor, SUM(valor_credito) AS valor_credito
            FROM prescritor_bonus_creditos_mensais
            WHERE competencia_ano = :cur_ano AND competencia_mes = :cur_mes
            GROUP BY prescritor
        ) ccur ON ccur.prescritor = pc.nome
        LEFT JOIN prescritor_bonus_creditos_mensais cnext
            ON cnext.prescritor = pc.nome
            AND cnext.competencia_ano = :next_ano2
            AND cnext.competencia_mes = :next_mes2
        LEFT JOIN (
            SELECT prescritor, SUM(valor_debito) AS total_debito
            FROM prescritor_bonus_debitos
            GROUP BY prescritor
        ) d ON d.prescritor = pc.nome
        WHERE (
            LOWER(TRIM(COALESCE(pc.visitador, ''))) LIKE '%aulis%'
            OR LOWER(TRIM(COALESCE(pc.visitador, ''))) LIKE '%priscila%'
        )
          AND (
              COALESCE(ccur.valor_credito, 0) > 0
              OR COALESCE(cnext.valor_credito, 0) > 0
              OR COALESCE(cnext.valor_bruto_snapshot, 0) > 0
              OR COALESCE(cart.bonificacao_a_utilizar, 0) > 0
              OR COALESCE(d.total_debito, 0) > 0
          )
        GROUP BY pc.nome, pc.visitador, ccur.valor_credito, cnext.valor_credito, cnext.valor_bruto_snapshot, cnext.valor_base, cnext.percentual, cnext.credito_status, cnext.valor_liquido_consolidado, cart.bonificacao_a_utilizar, d.total_debito
        ORDER BY pc.nome ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        'cur_ano' => $curAno,
        'cur_mes' => $curMes,
        'next_ano' => $nextAno,
        'next_mes' => $nextMes,
        'next_ano2' => $nextAno,
        'next_mes2' => $nextMes,
    ]);
    $fetched = $st->fetchAll(PDO::FETCH_ASSOC);
    $statusMap = bonusGetStatusMap($pdo, array_map(static fn($x) => (string)($x['prescritor'] ?? ''), $fetched));
    $namesPct = [];
    foreach ($fetched as $rf) {
        $pn = trim((string)($rf['prescritor'] ?? ''));
        if ($pn !== '') {
            $namesPct[] = $pn;
        }
    }
    $descontoListaMap = bonusGetAdminDescontoMap($pdo, array_values(array_unique($namesPct)));
    $rows = [];
    foreach ($fetched as $r) {
        $visitador = (string)($r['visitador'] ?? '');
        $prescritor = (string)($r['prescritor'] ?? '');
        $baseElegivel = bonusIsEligibleVisitador($visitador);
        $status = $statusMap[$prescritor] ?? null;
        $elegivel = $baseElegivel;
        if (is_array($status) && array_key_exists('apto', $status)) {
            $elegivel = ((int)$status['apto'] === 1);
        }
        $creditoMesAnterior = round((float)($r['bonificacao_mes_anterior'] ?? 0), 2);
        $projLinha = null;
        if (($r['cnext_vc'] ?? null) !== null || ($r['cnext_bruto'] ?? null) !== null || ($r['cnext_st'] ?? null) !== null || ($r['cnext_liq'] ?? null) !== null) {
            $projLinha = [
                'valor_credito' => $r['cnext_vc'],
                'valor_bruto_snapshot' => $r['cnext_bruto'],
                'valor_base' => $r['cnext_vb'],
                'percentual_credito' => $r['cnext_pct'],
                'credito_status' => $r['cnext_st'],
                'valor_liquido_consolidado' => $r['cnext_liq'],
            ];
        }
        $aBonificarMesAtual = bonusCalcAbonificarExibir($pdo, $prescritor, $projLinha, $descontoListaMap);
        $aUtilizar = round((float)($r['bonificacao_a_utilizar'] ?? 0), 2);
        $totalDebito = (float)($r['bonificacoes_utilizadas'] ?? 0);
        $saldoReal = round($aUtilizar - $totalDebito, 2);
        if ($creditoMesAnterior <= 0 && $aBonificarMesAtual <= 0 && $aUtilizar <= 0 && $totalDebito <= 0 && $saldoReal <= 0) {
            continue;
        }
        $vendasReserva = bonusAdminReservaValorVendas($projLinha);
        $comissaoReserva = bonusAdminReservaCommissionBruto($projLinha);
        $pctComissaoDisp = bonusAdminReservaPctComissaoVendasDisplay($projLinha);
        $adminPctRow = round(bonusGetEffectivePctForPrescritor($pdo, $prescritor, $descontoListaMap), 2);
        $projFechadaReserva = $projLinha !== null && bonusCreditRowIsFechado($projLinha['credito_status'] ?? null);
        $rows[] = [
            'prescritor' => $prescritor,
            'visitador' => $visitador,
            'bonificacao_mes_anterior' => $creditoMesAnterior,
            'bonificacao_mes_atual_a_bonificar' => $aBonificarMesAtual,
            'bonificacao_a_utilizar' => $aUtilizar,
            'bonificacoes_utilizadas' => round($totalDebito, 2),
            'bonificacao_utilizada' => round($totalDebito, 2),
            'saldo_bonificacao' => $saldoReal,
            'saldo_bonificacao_contabil' => $saldoReal,
            'bonificacao_eligivel' => $elegivel,
            'status_apto_manual' => is_array($status) ? ((int)($status['apto'] ?? 1)) : null,
            'status_observacao' => is_array($status) ? (string)($status['observacao'] ?? '') : '',
            'referencia_mes_anterior_label' => $refMesLabel,
            'competencia_mes_atual_label' => $compMesLabel,
            'admin_desconto_proximo_percent' => $adminPctRow,
            'admin_reserva_valor_vendas' => round($vendasReserva, 2),
            'admin_reserva_comissao_bruto' => round($comissaoReserva, 2),
            'admin_reserva_pct_comissao_vendas' => $pctComissaoDisp,
            'admin_reserva_projecao_fechada' => $projFechadaReserva,
        ];
    }
    $globalPctMeta = round(bonusGetGlobalDefaultPct($pdo), 2);
    return [
        'meta' => [
            'referencia_mes_anterior_label' => $refMesLabel,
            'competencia_mes_atual_label' => $compMesLabel,
            'percentual_desconto_global' => $globalPctMeta,
            'competencia_projecao_ano' => $nextAno,
            'competencia_projecao_mes' => $nextMes,
            'referencia_fechamento_sugerida' => [
                'ano' => (int)$prev->format('Y'),
                'mes' => (int)$prev->format('n'),
                'label' => sprintf('%02d/%04d', (int)$prev->format('n'), (int)$prev->format('Y')),
            ],
            'competencia_fechamento_sugerida' => [
                'ano' => (int)$prev->format('Y'),
                'mes' => (int)$prev->format('n'),
                'label' => sprintf('%02d/%04d', (int)$prev->format('n'), (int)$prev->format('Y')),
            ],
        ],
        'data' => $rows,
    ];
}

try {
    $pdo = getConnection();
    $sessionCheck = validateAndRefreshUserSession($pdo);
    if (!($sessionCheck['valid'] ?? true)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Sessão encerrada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    bonusInitTables($pdo);
    bonusEnsureExtendedSchema($pdo);
    $nowPv = new DateTime('now', new DateTimeZone('America/Porto_Velho'));
    if (bonusShouldRefreshCredits($pdo, $nowPv, 120)) {
        bonusGenerateMonthlyCredits($pdo);
        bonusMarkCreditsRefreshed($pdo, $nowPv);
    }
    bonusTryAutoFecharMes($pdo, $nowPv);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falha ao inicializar API de bônus.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action === 'bonus_resumo_lote') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    $items = (is_array($payload) && isset($payload['prescritores']) && is_array($payload['prescritores']))
        ? $payload['prescritores']
        : [];
    $names = [];
    $fallbackVisitadorByName = [];
    foreach ($items as $it) {
        if (is_array($it) && isset($it['prescritor'])) {
            $prescritor = trim((string)$it['prescritor']);
            if ($prescritor === '') {
                continue;
            }
            $names[] = $prescritor;
            $fallbackVisitadorByName[$prescritor] = trim((string)($it['visitador'] ?? ''));
        }
    }
    $resumo = bonusGetResumoForNames($pdo, $names);
    foreach ($names as $name) {
        if (isset($resumo[$name])) {
            continue;
        }
        $fallbackVisitador = (string)($fallbackVisitadorByName[$name] ?? '');
        $resumo[$name] = [
            'prescritor' => $name,
            'visitador' => $fallbackVisitador,
            'bonificacao_eligivel' => bonusIsEligibleVisitador($fallbackVisitador),
            'saldo_bonus' => 0.0,
            'bonificacao_a_utilizar' => 0.0,
            'bonificacao_mes_anterior' => 0.0,
            'total_credito' => 0.0,
            'total_debito' => 0.0,
            'bonificacao_utilizada' => 0.0,
        ];
    }
    echo json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_extrato') {
    $prescritor = trim((string)($_GET['prescritor'] ?? ''));
    if ($prescritor === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Prescritor é obrigatório.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = bonusGetExtrato($pdo, $prescritor);
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_controle_lista') {
    $out = bonusGetControleLista($pdo);
    echo json_encode(['success' => true] + $out, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_debito') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para lançar débito de bônus.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $prescritor = trim((string)($payload['prescritor'] ?? ''));
    $visitador = trim((string)($payload['visitador'] ?? ''));
    $numeroPedido = (int)($payload['numero_pedido'] ?? 0);
    $seriePedido = (int)($payload['serie_pedido'] ?? 0);
    $valorDebito = (float)($payload['valor_debito'] ?? 0);
    $dataDebito = trim((string)($payload['data_debito'] ?? ''));
    $observacao = trim((string)($payload['observacao'] ?? ''));

    if ($prescritor === '' || $numeroPedido <= 0 || $seriePedido <= 0 || $valorDebito <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDebito)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados obrigatórios inválidos para débito.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resumo = bonusGetResumoForNames($pdo, [$prescritor]);
    $saldoAtual = isset($resumo[$prescritor]) ? (float)$resumo[$prescritor]['saldo_bonus'] : 0.0;
    $eligivel = isset($resumo[$prescritor]) ? (bool)$resumo[$prescritor]['bonificacao_eligivel'] : false;
    if (!$eligivel) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Prescritor sem elegibilidade para bônus (somente carteiras Aulis/Priscila).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($valorDebito > $saldoAtual) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Débito maior que o saldo de bônus disponível.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $userNome = trim((string)($_SESSION['user_nome'] ?? 'Usuário'));

    $ins = $pdo->prepare("
        INSERT INTO prescritor_bonus_debitos
            (prescritor, visitador, numero_pedido, serie_pedido, valor_debito, data_debito, usuario_id, usuario_nome, observacao, criado_em)
        VALUES
            (:prescritor, :visitador, :numero_pedido, :serie_pedido, :valor_debito, :data_debito, :usuario_id, :usuario_nome, :observacao, NOW())
    ");
    $ins->execute([
        'prescritor' => $prescritor,
        'visitador' => $visitador !== '' ? $visitador : ($resumo[$prescritor]['visitador'] ?? null),
        'numero_pedido' => $numeroPedido,
        'serie_pedido' => $seriePedido,
        'valor_debito' => round($valorDebito, 2),
        'data_debito' => $dataDebito,
        'usuario_id' => $userId,
        'usuario_nome' => $userNome,
        'observacao' => $observacao !== '' ? $observacao : null,
    ]);

    $novoResumo = bonusGetResumoForNames($pdo, [$prescritor]);
    $novoSaldo = isset($novoResumo[$prescritor]) ? (float)$novoResumo[$prescritor]['saldo_bonus'] : 0.0;
    echo json_encode([
        'success' => true,
        'message' => 'Débito de bônus registrado com sucesso.',
        'saldo_atualizado' => round($novoSaldo, 2),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_set_apto') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para alterar aptidão de bônus.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $prescritor = trim((string)($payload['prescritor'] ?? ''));
    $apto = (int)($payload['apto'] ?? 1) === 1 ? 1 : 0;
    $observacao = trim((string)($payload['observacao'] ?? ''));
    if ($prescritor === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Prescritor é obrigatório.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare("
        INSERT INTO prescritor_bonus_status (prescritor, apto, observacao, atualizado_por_id, atualizado_por_nome, atualizado_em)
        VALUES (:prescritor, :apto, :observacao, :uid, :unome, NOW())
        ON DUPLICATE KEY UPDATE
            apto = VALUES(apto),
            observacao = VALUES(observacao),
            atualizado_por_id = VALUES(atualizado_por_id),
            atualizado_por_nome = VALUES(atualizado_por_nome),
            atualizado_em = NOW()
    ");
    $st->execute([
        'prescritor' => $prescritor,
        'apto' => $apto,
        'observacao' => ($observacao !== '' ? $observacao : null),
        'uid' => (int)($_SESSION['user_id'] ?? 0),
        'unome' => trim((string)($_SESSION['user_nome'] ?? 'Usuário')),
    ]);
    echo json_encode([
        'success' => true,
        'message' => $apto === 1 ? 'Prescritor marcado como apto para bonificação.' : 'Prescritor bloqueado para novas bonificações.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_debito_update') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para editar lançamento.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int)($payload['id'] ?? 0);
    $prescritor = trim((string)($payload['prescritor'] ?? ''));
    $numeroPedido = (int)($payload['numero_pedido'] ?? 0);
    $seriePedido = (int)($payload['serie_pedido'] ?? 0);
    $valorDebito = round((float)($payload['valor_debito'] ?? 0), 2);
    $dataDebito = trim((string)($payload['data_debito'] ?? ''));
    $observacao = trim((string)($payload['observacao'] ?? ''));
    if ($id <= 0 || $prescritor === '' || $valorDebito <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDebito)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos para edição do lançamento.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $curSt = $pdo->prepare("SELECT valor_debito FROM prescritor_bonus_debitos WHERE id = :id AND prescritor = :p LIMIT 1");
    $curSt->execute(['id' => $id, 'p' => $prescritor]);
    $cur = $curSt->fetch(PDO::FETCH_ASSOC);
    if (!$cur) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Lançamento não encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $currentValor = (float)($cur['valor_debito'] ?? 0);
    $resumo = bonusGetResumoForNames($pdo, [$prescritor]);
    $totalCredito = isset($resumo[$prescritor]) ? (float)$resumo[$prescritor]['total_credito'] : 0.0;
    $totalDebito = isset($resumo[$prescritor]) ? (float)$resumo[$prescritor]['total_debito'] : 0.0;
    $novoTotalDebito = $totalDebito - $currentValor + $valorDebito;
    if ($novoTotalDebito - $totalCredito > 0.0001) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valor do lançamento excede saldo disponível.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $upd = $pdo->prepare("
        UPDATE prescritor_bonus_debitos
        SET numero_pedido = :numero_pedido,
            serie_pedido = :serie_pedido,
            valor_debito = :valor_debito,
            data_debito = :data_debito,
            observacao = :observacao
        WHERE id = :id AND prescritor = :prescritor
    ");
    $upd->execute([
        'numero_pedido' => $numeroPedido,
        'serie_pedido' => $seriePedido,
        'valor_debito' => $valorDebito,
        'data_debito' => $dataDebito,
        'observacao' => ($observacao !== '' ? $observacao : null),
        'id' => $id,
        'prescritor' => $prescritor,
    ]);
    echo json_encode(['success' => true, 'message' => 'Lançamento atualizado com sucesso.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_debito_delete') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para excluir lançamento.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int)($payload['id'] ?? 0);
    $prescritor = trim((string)($payload['prescritor'] ?? ''));
    if ($id <= 0 || $prescritor === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Dados inválidos para exclusão.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM prescritor_bonus_debitos WHERE id = :id AND prescritor = :prescritor");
    $del->execute(['id' => $id, 'prescritor' => $prescritor]);
    echo json_encode(['success' => true, 'message' => 'Lançamento excluído com sucesso.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_admin_desconto_set') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para alterar reserva administrativa.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $prescritorRaw = bonusPrescritorNormalizeSpaces((string)($payload['prescritor'] ?? ''));
    $prescritor = bonusResolvePrescritorNomeCadastro($pdo, $prescritorRaw);
    if ($prescritor === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Prescritor é obrigatório.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pct = (float)($payload['desconto_percent'] ?? 20);
    if ($pct < 0 || $pct > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Percentual deve estar entre 0 e 100.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pct = round($pct, 2);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $unome = trim((string)($_SESSION['user_nome'] ?? 'Usuário'));
    $st = $pdo->prepare("
        INSERT INTO prescritor_bonus_admin_desconto (prescritor, desconto_percent, atualizado_por_id, atualizado_por_nome, atualizado_em)
        VALUES (:prescritor, :pct, :uid, :unome, NOW())
        ON DUPLICATE KEY UPDATE
            desconto_percent = VALUES(desconto_percent),
            atualizado_por_id = VALUES(atualizado_por_id),
            atualizado_por_nome = VALUES(atualizado_por_nome),
            atualizado_em = NOW()
    ");
    $st->execute([
        'prescritor' => $prescritor,
        'pct' => $pct,
        'uid' => $uid,
        'unome' => $unome,
    ]);
    try {
        bonusRefreshValorCreditoLinhaAbertaProjecao($pdo, $prescritor);
    } catch (Throwable $e) {
    }
    echo json_encode([
        'success' => true,
        'message' => 'Percentual de reserva administrativa atualizado.',
        'desconto_percent' => $pct,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_config_get') {
    $pct = bonusGetGlobalDefaultPct($pdo);
    echo json_encode([
        'success' => true,
        'percentual_desconto_admin' => $pct,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_config_set') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para alterar configuração de bonificação.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pct = (float)($payload['percentual_desconto_admin'] ?? 20);
    if ($pct < 0 || $pct > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Percentual deve estar entre 0 e 100.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pct = round($pct, 2);
    $pdo->prepare('INSERT INTO config_bonificacao (id, percentual_desconto_admin) VALUES (1, :p) ON DUPLICATE KEY UPDATE percentual_desconto_admin = VALUES(percentual_desconto_admin), atualizado_em = NOW()')->execute(['p' => $pct]);
    try {
        bonusGenerateMonthlyCredits($pdo);
        bonusMarkCreditsRefreshed($pdo, new DateTime('now', new DateTimeZone('America/Porto_Velho')));
    } catch (Throwable $e) {
    }
    echo json_encode([
        'success' => true,
        'message' => 'Percentual salvo. As linhas em aberto (a bonificar) foram recalculadas com o novo desconto.',
        'percentual_desconto_admin' => $pct,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bonus_fechar_mes') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $setor = (string)($_SESSION['user_setor'] ?? '');
    $tipo = (string)($_SESSION['user_tipo'] ?? '');
    if (!bonusCanDebit($setor, $tipo)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Perfil sem permissão para executar fechamento de bonificação.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $csrfToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '')));
    if (!validateCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $refAno = (int)($payload['referencia_ano'] ?? 0);
    $refMes = (int)($payload['referencia_mes'] ?? 0);
    $compAno = (int)($payload['competencia_ano'] ?? 0);
    $compMes = (int)($payload['competencia_mes'] ?? 0);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $unome = trim((string)($_SESSION['user_nome'] ?? 'Usuário'));
    $r = null;
    if ($refAno >= 2000 && $refMes >= 1 && $refMes <= 12) {
        $r = bonusFecharPorReferenciaInterno($pdo, $refAno, $refMes, $uid, $unome, false);
    } elseif ($compAno >= 2000 && $compMes >= 1 && $compMes <= 12) {
        $r = bonusFecharCompetenciaInterno($pdo, $compAno, $compMes, $uid, $unome, false);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Informe referencia_ano e referencia_mes (mês das vendas, 1–12) ou competencia_ano/mes para fechamento legado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$r['ok']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $r['message']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => $r['message'],
        'fechados' => $r['fechados'],
        'ja_aprovado' => !empty($r['ja_aprovado']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.'], JSON_UNESCAPED_UNICODE);
exit;
