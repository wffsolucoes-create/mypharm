<?php
/**
 * Lacunas na amostragem GPS (rotas_pontos): sem novas colunas no BD.
 * Intervalos: início da rota → 1º ponto; entre pontos; último ponto → fim (ou até agora se em andamento).
 */
function mypharm_rotas_pontos_lacunas_resumo(
    array $pontos,
    ?string $dataInicio,
    ?string $dataFim,
    bool $finalizada,
    int $limiteSegSignificativo = 300
): array {
    $n = count($pontos);
    $tsInicio = ($dataInicio !== null && $dataInicio !== '') ? strtotime((string)$dataInicio) : false;
    $tsFim = ($finalizada && $dataFim !== null && $dataFim !== '') ? strtotime((string)$dataFim) : false;
    $agora = time();

    if ($n === 0) {
        return [
            'maior_lacuna_seg' => 0,
            'maior_lacuna_de' => null,
            'maior_lacuna_ate' => null,
            'maior_lacuna_ate_agora' => false,
            'qtd_lacunas_ge_5min' => 0,
            'lacuna_inicio_seg' => null,
            'apos_ultimo_ponto_seg' => null,
        ];
    }

    $times = [];
    foreach ($pontos as $p) {
        $t = strtotime((string)($p['criado_em'] ?? ''));
        $times[] = ($t !== false && $t > 0) ? $t : 0;
    }

    $maxSeg = 0;
    $maxDe = null;
    $maxAte = null;
    $maxAteAgora = false;
    $qtd = 0;

    $consider = function (int $seg, $de, $ate, bool $ateEhAgora) use (&$maxSeg, &$maxDe, &$maxAte, &$maxAteAgora, &$qtd, $limiteSegSignificativo) {
        $seg = max(0, $seg);
        if ($seg >= $limiteSegSignificativo) {
            $qtd++;
        }
        if ($seg > $maxSeg) {
            $maxSeg = $seg;
            $maxDe = $de;
            $maxAte = $ate;
            $maxAteAgora = $ateEhAgora;
        }
    };

    $lacunaInicio = null;
    if ($tsInicio !== false && $times[0] > 0) {
        $g = max(0, $times[0] - $tsInicio);
        $lacunaInicio = $g;
        $consider($g, $dataInicio, $pontos[0]['criado_em'] ?? null, false);
    }

    for ($i = 1; $i < $n; $i++) {
        if ($times[$i] <= 0 || $times[$i - 1] <= 0) {
            continue;
        }
        $g = max(0, $times[$i] - $times[$i - 1]);
        $consider($g, $pontos[$i - 1]['criado_em'] ?? null, $pontos[$i]['criado_em'] ?? null, false);
    }

    $aposUltimo = null;
    $lastTs = $times[$n - 1];
    if ($lastTs > 0) {
        if ($tsFim !== false && $tsFim > $lastTs) {
            $aposUltimo = $tsFim - $lastTs;
            $consider($aposUltimo, $pontos[$n - 1]['criado_em'] ?? null, $dataFim, false);
        } elseif (!$finalizada) {
            $aposUltimo = max(0, $agora - $lastTs);
            $consider($aposUltimo, $pontos[$n - 1]['criado_em'] ?? null, null, true);
        }
    }

    return [
        'maior_lacuna_seg' => (int)$maxSeg,
        'maior_lacuna_de' => $maxDe,
        'maior_lacuna_ate' => $maxAte,
        'maior_lacuna_ate_agora' => (bool)$maxAteAgora,
        'qtd_lacunas_ge_5min' => (int)$qtd,
        'lacuna_inicio_seg' => $lacunaInicio !== null ? (int)$lacunaInicio : null,
        'apos_ultimo_ponto_seg' => $aposUltimo !== null ? (int)$aposUltimo : null,
    ];
}
