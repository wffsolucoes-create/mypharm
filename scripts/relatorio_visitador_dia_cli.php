<?php
/**
 * CLI: relatório textual do dia para um visitador (visitas + rotas + km).
 * Uso: php scripts/relatorio_visitador_dia_cli.php [YYYY-MM-DD] [trecho_nome]
 * Ex.: php scripts/relatorio_visitador_dia_cli.php 2026-04-01 Aulis
 * Sem data = hoje (timezone -04:00 já na conexão).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$data = $argv[1] ?? null;
if ($data === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    $data = date('Y-m-d');
}
$trecho = isset($argv[2]) ? trim((string)$argv[2]) : 'Aulis';
if ($trecho === '') {
    $trecho = 'Aulis';
}

$haversineKm = static function (float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
};

$kmSegmentoMaxValido = 50.0;

try {
    $pdo = getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Erro de conexão: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$like = '%' . $trecho . '%';

// Nome exato preferencial (primeiro registro do dia)
$stmt = $pdo->prepare("
    SELECT DISTINCT TRIM(visitador) AS nome
    FROM historico_visitas
    WHERE data_visita IS NOT NULL
      AND DATE(data_visita) = :d
      AND TRIM(COALESCE(visitador,'')) LIKE :like
    LIMIT 5
");
$stmt->execute(['d' => $data, 'like' => $like]);
$nomesVisitas = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$stmt = $pdo->prepare("
    SELECT DISTINCT TRIM(visitador_nome) AS nome
    FROM rotas_diarias
    WHERE DATE(data_inicio) = :d
      AND TRIM(COALESCE(visitador_nome,'')) LIKE :like
    LIMIT 5
");
$stmt->execute(['d' => $data, 'like' => $like]);
$nomesRotas = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$nomeCanon = null;
foreach (array_merge($nomesVisitas, $nomesRotas) as $n) {
    $n = trim((string)$n);
    if ($n !== '') {
        $nomeCanon = $n;
        break;
    }
}

$out = [];
$out[] = '# Relatório do dia — visitador';
$out[] = '';
$out[] = '- **Data:** ' . $data;
$out[] = '- **Filtro usado:** nome contém `' . $trecho . '`';
if ($nomeCanon) {
    $out[] = '- **Nome encontrado no sistema:** ' . $nomeCanon;
} else {
    $out[] = '- **Nome encontrado no sistema:** *(nenhum registro no dia — confira data ou grafia)*';
}
$out[] = '';
$out[] = '---';
$out[] = '';

if (!$nomeCanon) {
    echo implode(PHP_EOL, $out) . PHP_EOL;
    exit(0);
}

// Visitas do dia
$stmt = $pdo->prepare("
    SELECT hv.id, hv.visitador, hv.prescritor, hv.data_visita, hv.horario, hv.inicio_visita,
           hv.status_visita, hv.local_visita, hv.resumo_visita, hv.reagendado_para,
           TIMESTAMPDIFF(MINUTE, hv.inicio_visita, CONCAT(hv.data_visita, ' ', COALESCE(hv.horario, '00:00:00'))) AS duracao_minutos
    FROM historico_visitas hv
    WHERE hv.data_visita IS NOT NULL
      AND DATE(hv.data_visita) = :d
      AND TRIM(COALESCE(hv.visitador,'')) = :nome
    ORDER BY hv.data_visita ASC, hv.horario ASC
");
$stmt->execute(['d' => $data, 'nome' => $nomeCanon]);
$visitas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out[] = '## Visitas registradas (' . count($visitas) . ')';
$out[] = '';
if (count($visitas) === 0) {
    $out[] = 'Nenhuma visita em `historico_visitas` nesta data para este visitador.';
    $out[] = '';
} else {
    $out[] = '| # | Prescritor | Data | Horário | Status | Local (resumo) | Duração (min) |';
    $out[] = '|---|------------|------|---------|--------|----------------|---------------|';
    $i = 1;
    foreach ($visitas as $v) {
        $loc = mb_substr(trim((string)($v['local_visita'] ?? '')), 0, 60);
        if (mb_strlen(trim((string)($v['local_visita'] ?? ''))) > 60) {
            $loc .= '…';
        }
        $loc = str_replace('|', '/', $loc);
        $out[] = sprintf(
            '| %d | %s | %s | %s | %s | %s | %s |',
            $i,
            $v['prescritor'] ?? '—',
            $v['data_visita'] ?? '—',
            $v['horario'] ?? '—',
            $v['status_visita'] ?? '—',
            $loc !== '' ? $loc : '—',
            isset($v['duracao_minutos']) && $v['duracao_minutos'] !== '' ? (string)$v['duracao_minutos'] : '—'
        );
        $i++;
    }
    $out[] = '';
}

// Rotas do dia
$stmt = $pdo->prepare("
    SELECT id, visitador_nome, data_inicio, data_fim, status
    FROM rotas_diarias
    WHERE DATE(data_inicio) = :d
      AND TRIM(COALESCE(visitador_nome,'')) = :nome
    ORDER BY data_inicio ASC
");
$stmt->execute(['d' => $data, 'nome' => $nomeCanon]);
$rotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out[] = '## Rotas do dia (' . count($rotas) . ')';
$out[] = '';
$out[] = 'O **km (trajeto)** soma trechos em linha reta entre **cada par de pontos GPS consecutivos** (trechos ≥ 50 km são ignorados). O **km reta** é uma única linha reta do **primeiro ao último** ponto.';
$out[] = '';

$totalKmTrajeto = 0.0;
$totalKmRetaSoma = 0.0;

foreach ($rotas as $rota) {
    $rid = (int)$rota['id'];
    $dataFimRota = $rota['data_fim'] ?? null;
    if ($dataFimRota !== null && $dataFimRota !== '') {
        $stmtP = $pdo->prepare('SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid AND criado_em <= :data_fim ORDER BY criado_em ASC');
        $stmtP->execute(['rid' => $rid, 'data_fim' => $dataFimRota]);
    } else {
        $stmtP = $pdo->prepare('SELECT lat, lng, criado_em FROM rotas_pontos WHERE rota_id = :rid ORDER BY criado_em ASC');
        $stmtP->execute(['rid' => $rid]);
    }
    $pontos = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    $n = count($pontos);

    $kmRota = 0.0;
    $maxSeg = 0.0;
    $maxSegFrom = null;
    $maxSegTo = null;
    for ($i = 1; $i < $n; $i++) {
        $seg = $haversineKm(
            (float)$pontos[$i - 1]['lat'],
            (float)$pontos[$i - 1]['lng'],
            (float)$pontos[$i]['lat'],
            (float)$pontos[$i]['lng']
        );
        if ($seg < $kmSegmentoMaxValido) {
            $kmRota += $seg;
            if ($seg > $maxSeg) {
                $maxSeg = $seg;
                $maxSegFrom = $pontos[$i - 1]['criado_em'] ?? null;
                $maxSegTo = $pontos[$i]['criado_em'] ?? null;
            }
        }
    }
    $kmLinhaReta = 0.0;
    if ($n >= 2) {
        $kmLinhaReta = $haversineKm(
            (float)$pontos[0]['lat'],
            (float)$pontos[0]['lng'],
            (float)$pontos[$n - 1]['lat'],
            (float)$pontos[$n - 1]['lng']
        );
    }
    $totalKmTrajeto += $kmRota;
    $totalKmRetaSoma += $kmLinhaReta;

    $out[] = '### Rota #' . $rid;
    $out[] = '';
    $out[] = '- **Início:** ' . ($rota['data_inicio'] ?? '—');
    $out[] = '- **Fim:** ' . (($rota['data_fim'] ?? '') !== '' ? $rota['data_fim'] : '— *(em aberto)*');
    $out[] = '- **Status:** ' . ($rota['status'] ?? '—');
    $out[] = '- **Pontos GPS:** ' . $n;
    $out[] = '- **Km (trajeto):** ' . round($kmRota, 2) . ' km';
    $out[] = '- **Km reta (1º → último ponto):** ' . round($kmLinhaReta, 2) . ' km';
    if ($n >= 2 && $maxSeg > 0.05) {
        $out[] = '- **Maior trecho entre dois pings consecutivos:** ' . round($maxSeg, 2) . ' km (de `' . ($maxSegFrom ?? '?') . '` a `' . ($maxSegTo ?? '?') . '`) — *vale conferir no mapa se foi deslocamento real ou instabilidade de sinal*.';
    }
    if ($n >= 1) {
        $out[] = '- **Primeiro GPS:** `' . ($pontos[0]['criado_em'] ?? '') . '` — lat/lng ' . round((float)$pontos[0]['lat'], 5) . ', ' . round((float)$pontos[0]['lng'], 5);
        $out[] = '- **Último GPS:** `' . ($pontos[$n - 1]['criado_em'] ?? '') . '` — lat/lng ' . round((float)$pontos[$n - 1]['lat'], 5) . ', ' . round((float)$pontos[$n - 1]['lng'], 5);
    }
    $out[] = '';
}

if (count($rotas) > 0) {
    $out[] = '## Totais do dia (rotas)';
    $out[] = '';
    $out[] = '- **Soma km (trajeto)** de todas as rotas do dia: **' . round($totalKmTrajeto, 2) . ' km**';
    $out[] = '- **Soma km reta** (cada rota: 1º→último): **' . round($totalKmRetaSoma, 2) . ' km** *(não é “volta ao mundo”; é só para comparar com o trajeto polilinha)*';
    $out[] = '';
}

$out[] = '---';
$out[] = '';
$out[] = '## Texto para conversar com o visitador';
$out[] = '';
$out[] = 'Hoje (**' . $data . '**) o sistema registrou **' . count($visitas) . ' visita(s)** e **' . count($rotas) . ' rota(s)** em seu nome. ';
if (count($rotas) > 0) {
    $out[] = 'A quilometragem do relatório é calculada com **muitos pontos de GPS ao longo do percurso**: cada trecho liga um registro ao próximo, o que permite **reconstituir o caminho no mapa** com detalhe. ';
    $out[] = 'No dia, a soma do **km (trajeto)** das rotas foi **' . round($totalKmTrajeto, 2) . ' km**. ';
}
$out[] = 'Qualquer dúvida sobre um trecho específico pode ser esclarecida olhando a rota no mapa do painel, ponto a ponto.';
$out[] = '';

echo implode(PHP_EOL, $out) . PHP_EOL;
