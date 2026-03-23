<?php
/**
 * Proxy RD Station CRM → TV Corrida de Vendas
 *
 * Busca somente as 8 vendedoras ativas, mapeando nomes do CRM → nome do sistema.
 */

define('RDSTATION_API_BASE', 'https://crm.rdstation.com/api/v1');

/**
 * Mapeamento: nome exato no CRM → nome de exibição no sistema (banco).
 * Altere aqui se alguma vendedora mudar de nome no CRM.
 */
function rdtvGetMapeamento(): array
{
    return [
        'Vitória Carvalho'       => 'Jessica Vitoria',
        'Vitoria Carvalho'       => 'Jessica Vitoria',
        'Clara Letícia'          => 'Clara Leticia',
        'Clara Leticia'          => 'Clara Leticia',
        'Carla Pires - Consultora' => 'Carla',
        'Carla'                  => 'Carla',
        'Ananda'                 => 'Ananda Reis',
        'Ananda Reis'            => 'Ananda Reis',
        'Giovanna'               => 'Giovanna',
        'Mariana'                => 'Mariana',
        'Nailena'                => 'Nailena',
        'Nereida'                => 'Nereida',
    ];
}

/**
 * Resolve o nome de exibição a partir do nome do CRM.
 * Retorna null se a vendedora não for uma das 8 permitidas.
 */
function rdtvResolveNome(string $nomeCrm): ?string
{
    $map = rdtvGetMapeamento();
    $nomeCrm = trim($nomeCrm);

    // Busca exata
    if (isset($map[$nomeCrm])) {
        return $map[$nomeCrm];
    }

    // Busca sem acentos (normaliza e compara)
    $normCrm = rdtvNormalize($nomeCrm);
    foreach ($map as $chave => $valor) {
        if (rdtvNormalize($chave) === $normCrm) {
            return $valor;
        }
    }

    return null; // Não é uma das 8 — ignorar
}

/**
 * Normaliza nome para comparação (remove acentos, minúsculas, trim).
 */
function rdtvNormalize(string $v): string
{
    $v = trim($v);
    $v = strtr($v, [
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
    ]);
    return mb_strtolower($v, 'UTF-8');
}

/**
 * Busca deals do RD Station CRM.
 * @param string|null $win 'true' = ganhos, 'false' = perdidos, null = não filtra por win (todos/abertos)
 */
function rdtvFetchDeals(string $token, int $page, int $limit, string $startDate = '', string $endDate = '', ?string $win = 'true'): array
{
    $params = [
        'token' => $token,
        'page'  => $page,
        'limit' => $limit,
    ];
    if ($win !== null) {
        $params['win'] = $win;
    }
    if ($startDate) $params['start_date'] = $startDate;
    if ($endDate)   $params['end_date']   = $endDate;

    $url = RDSTATION_API_BASE . '/deals?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($error) throw new RuntimeException("cURL error: {$error}");
    if ($status === 401) throw new RuntimeException("Token inválido (401). Atualize RDSTATION_CRM_TOKEN no .env.");
    if ($status !== 200) throw new RuntimeException("RD Station API HTTP {$status}.");

    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException("Resposta inválida da API RD Station.");

    return $data;
}

/**
 * Executa duas requisições de deals em paralelo (ganhos + perdidos, mesma página).
 * Retorna ['won' => data, 'lost' => data]. Timeout 10s por requisição.
 */
function rdtvFetchDealsPair(string $token, int $page, int $limit, string $startDate, string $endDate): array
{
    $params = ['token' => $token, 'page' => $page, 'limit' => $limit];
    if ($startDate) $params['start_date'] = $startDate;
    if ($endDate) $params['end_date'] = $endDate;

    $urlWon  = RDSTATION_API_BASE . '/deals?' . http_build_query($params + ['win' => 'true']);
    $urlLost = RDSTATION_API_BASE . '/deals?' . http_build_query($params + ['win' => 'false']);

    $chWon = curl_init();
    curl_setopt_array($chWon, [
        CURLOPT_URL => $urlWon,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $chLost = curl_init();
    curl_setopt_array($chLost, [
        CURLOPT_URL => $urlLost,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $chWon);
    curl_multi_add_handle($mh, $chLost);

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running && $status === CURLM_OK);

    $bodyWon  = curl_multi_getcontent($chWon);
    $bodyLost = curl_multi_getcontent($chLost);
    $codeWon  = curl_getinfo($chWon, CURLINFO_HTTP_CODE);
    $codeLost = curl_getinfo($chLost, CURLINFO_HTTP_CODE);

    curl_multi_remove_handle($mh, $chWon);
    curl_multi_remove_handle($mh, $chLost);
    curl_multi_close($mh);
    curl_close($chWon);
    curl_close($chLost);

    if ($codeWon === 401 || $codeLost === 401) {
        throw new RuntimeException("Token inválido (401). Atualize RDSTATION_CRM_TOKEN no .env.");
    }
    if ($codeWon !== 200) throw new RuntimeException("RD Station API HTTP {$codeWon} (ganhos).");
    if ($codeLost !== 200) throw new RuntimeException("RD Station API HTTP {$codeLost} (perdidos).");

    $dataWon  = is_string($bodyWon) ? json_decode($bodyWon, true) : null;
    $dataLost = is_string($bodyLost) ? json_decode($bodyLost, true) : null;
    if (!is_array($dataWon)) $dataWon = ['deals' => []];
    if (!is_array($dataLost)) $dataLost = ['deals' => []];

    return ['won' => $dataWon, 'lost' => $dataLost];
}

/**
 * Resolve nome para agregação: TV usa só o mapeamento MyPharm; dashboard gestão usa todos do CRM.
 *
 * @param bool $somenteMapeadas true = ignora responsáveis fora de rdtvGetMapeamento() (TV corrida)
 */
function rdtvResolveNomeParaAgregacao(string $nomeCrm, bool $somenteMapeadas): ?string
{
    $nomeCrm = trim($nomeCrm);
    if ($nomeCrm === '') {
        return null;
    }
    $mapeado = rdtvResolveNome($nomeCrm);
    if ($mapeado !== null) {
        return $mapeado;
    }
    if ($somenteMapeadas) {
        return null;
    }
    return $nomeCrm;
}

/**
 * Busca ganhos e perdas em paralelo (uma rodada de requisições por página).
 * Retorna ['porVendedor' => ..., 'perdas' => ...] no mesmo formato das funções individuais.
 *
 * @param bool $somenteMapeadas Se true (padrão), só conta as vendedoras do mapeamento TV → MyPharm.
 *                             Se false, conta todos os responsáveis do CRM (alinha totais ao funil RD na Gestão Comercial).
 */
function rdtvFetchWonAndLostParallel(string $token, string $startDate, string $endDate, bool $somenteMapeadas = true): array
{
    $porVendedor = [];
    $perdas      = [];
    $page        = 1;
    $limit       = 200;
    $maxPages    = $somenteMapeadas ? 10 : 20;
    $earlyStopWon = false;
    $earlyStopLost = false;

    while ($page <= $maxPages) {
        $pair = rdtvFetchDealsPair($token, $page, $limit, $startDate, $endDate);
        $dealsWon  = $pair['won']['deals'] ?? [];
        $dealsLost = $pair['lost']['deals'] ?? [];

        foreach ($dealsWon as $deal) {
            if (empty($deal['win'])) continue;
            $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
            $date    = $dateStr ? substr((string)$dateStr, 0, 10) : '';
            if ($date !== '' && $date < $startDate) {
                $earlyStopWon = true;
                break;
            }
            if ($date === '' || $date > $endDate) continue;
            $nomeCrm = trim((string)($deal['user']['name'] ?? ''));
            if ($nomeCrm === '') {
                continue;
            }
            $nomeExibicao = rdtvResolveNomeParaAgregacao($nomeCrm, $somenteMapeadas);
            if ($nomeExibicao === null) {
                continue;
            }
            $amount = (float)($deal['amount_total'] ?? 0);
            if ($amount <= 0) {
                $amount  = (float)($deal['amount_montly'] ?? 0);
                $amount += (float)($deal['amount_unique'] ?? 0);
            }
            $createdTs = rdtvParseDateToTs($deal['created_at'] ?? null);
            $closedTs  = rdtvParseDateToTs($deal['closed_at'] ?? null);
            $updatedTs = rdtvParseDateToTs($deal['updated_at'] ?? null);
            $duracaoSeg = ($createdTs !== null && $closedTs !== null && $closedTs >= $createdTs) ? $closedTs - $createdTs : 0;
            $esperaSeg  = ($updatedTs !== null && $createdTs !== null && $updatedTs >= $createdTs) ? $updatedTs - $createdTs : 0;
            if (!isset($porVendedor[$nomeExibicao])) {
                $porVendedor[$nomeExibicao] = ['receita' => 0.0, 'total_duracao_seg' => 0, 'total_espera_seg' => 0, 'count' => 0, 'origem_deals' => []];
            }
            $porVendedor[$nomeExibicao]['receita'] += $amount;
            $porVendedor[$nomeExibicao]['total_duracao_seg'] += $duracaoSeg;
            $porVendedor[$nomeExibicao]['total_espera_seg'] += $esperaSeg;
            $porVendedor[$nomeExibicao]['count']++;
            $src = $deal['deal_source'] ?? null;
            $sourceName = is_array($src) ? trim((string)($src['name'] ?? $src['id'] ?? '')) : trim((string)$src);
            if ($sourceName === '' && is_array($src) && !empty($src['id'])) $sourceName = 'Origem ' . $src['id'];
            if ($sourceName === '') $sourceName = 'Não informada';
            if (!isset($porVendedor[$nomeExibicao]['origem_deals'][$sourceName])) {
                $porVendedor[$nomeExibicao]['origem_deals'][$sourceName] = ['receita' => 0.0, 'quantidade' => 0];
            }
            $porVendedor[$nomeExibicao]['origem_deals'][$sourceName]['receita'] += $amount;
            $porVendedor[$nomeExibicao]['origem_deals'][$sourceName]['quantidade']++;
        }

        foreach ($dealsLost as $deal) {
            if (!empty($deal['win'])) continue;
            $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
            $date    = $dateStr ? substr((string)$dateStr, 0, 10) : '';
            if ($date !== '' && $date < $startDate) {
                $earlyStopLost = true;
                break;
            }
            if ($date === '' || $date > $endDate) continue;
            $nomeCrm = trim((string)($deal['user']['name'] ?? ''));
            if ($nomeCrm === '') {
                continue;
            }
            $nomeExibicao = rdtvResolveNomeParaAgregacao($nomeCrm, $somenteMapeadas);
            if ($nomeExibicao === null) {
                continue;
            }
            $lostReason = $deal['deal_lost_reason'] ?? null;
            $motivoNome = is_array($lostReason) ? trim((string)($lostReason['name'] ?? $lostReason['id'] ?? '')) : trim((string)$lostReason);
            if ($motivoNome === '' && is_array($lostReason) && !empty($lostReason['id'])) $motivoNome = 'Motivo ' . $lostReason['id'];
            if ($motivoNome === '') $motivoNome = 'Não informado';
            if (!isset($perdas[$nomeExibicao])) {
                $perdas[$nomeExibicao] = ['total_perdidos' => 0, 'motivos' => []];
            }
            $perdas[$nomeExibicao]['total_perdidos']++;
            if (!isset($perdas[$nomeExibicao]['motivos'][$motivoNome])) $perdas[$nomeExibicao]['motivos'][$motivoNome] = 0;
            $perdas[$nomeExibicao]['motivos'][$motivoNome]++;
        }

        $needMore = (count($dealsWon) >= $limit && !$earlyStopWon) || (count($dealsLost) >= $limit && !$earlyStopLost);
        if (!$needMore) break;
        $page++;
    }

    if ($somenteMapeadas) {
        foreach (array_unique(array_values(rdtvGetMapeamento())) as $nome) {
            if (!isset($porVendedor[$nome])) {
                $porVendedor[$nome] = ['receita' => 0.0, 'total_duracao_seg' => 0, 'total_espera_seg' => 0, 'count' => 0, 'origem_deals' => []];
            }
            if (!isset($perdas[$nome])) {
                $perdas[$nome] = ['total_perdidos' => 0, 'motivos' => []];
            }
        }
    }

    return ['porVendedor' => $porVendedor, 'perdas' => $perdas];
}

/**
 * Verifica se o deal está dentro do período pelo closed_at ou created_at.
 */
function rdtvIsInPeriod(array $deal, string $startDate, string $endDate): bool
{
    $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
    if (!$dateStr) return false;
    $date = substr((string)$dateStr, 0, 10);
    return $date >= $startDate && $date <= $endDate;
}

/**
 * Converte data ISO (ex: 2023-09-18T15:04:34.515-03:00) em timestamp Unix.
 */
function rdtvParseDateToTs(?string $dateStr): ?int
{
    if ($dateStr === null || $dateStr === '') return null;
    $ts = strtotime($dateStr);
    return $ts ?: null;
}

/**
 * Busca todas as negociações ganhas no período e agrupa por nome de exibição.
 * Retorna por vendedor: receita, total_duracao_seg (closed - created), total_espera_seg (updated - created), count.
 * Filtra somente as 8 vendedoras do mapeamento.
 */
function rdtvFetchWonByVendedora(string $token, string $startDate, string $endDate): array
{
    $porVendedor = [];
    $page        = 1;
    $limit       = 200;
    $maxPages    = 10;
    $earlyStop   = false;

    do {
        $data  = rdtvFetchDeals($token, $page, $limit, $startDate, $endDate);
        $deals = $data['deals'] ?? [];

        foreach ($deals as $deal) {
            if (empty($deal['win'])) continue;

            $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
            $date    = $dateStr ? substr((string)$dateStr, 0, 10) : '';
            if ($date !== '' && $date < $startDate) {
                $earlyStop = true;
                break;
            }
            if ($date === '' || $date > $endDate) continue;

            $nomeCrm = trim((string)($deal['user']['name'] ?? ''));
            if ($nomeCrm === '') continue;

            $nomeExibicao = rdtvResolveNome($nomeCrm);
            if ($nomeExibicao === null) continue;

            $amount = (float)($deal['amount_total'] ?? 0);
            if ($amount <= 0) {
                $amount  = (float)($deal['amount_montly'] ?? 0);
                $amount += (float)($deal['amount_unique'] ?? 0);
            }

            $createdTs = rdtvParseDateToTs($deal['created_at'] ?? null);
            $closedTs  = rdtvParseDateToTs($deal['closed_at'] ?? null);
            $updatedTs = rdtvParseDateToTs($deal['updated_at'] ?? null);

            $duracaoSeg = 0;
            if ($createdTs !== null && $closedTs !== null && $closedTs >= $createdTs) {
                $duracaoSeg = $closedTs - $createdTs;
            }
            $esperaSeg = 0;
            if ($updatedTs !== null && $createdTs !== null && $updatedTs >= $createdTs) {
                $esperaSeg = $updatedTs - $createdTs;
            }

            if (!isset($porVendedor[$nomeExibicao])) {
                $porVendedor[$nomeExibicao] = [
                    'receita' => 0.0, 'total_duracao_seg' => 0, 'total_espera_seg' => 0, 'count' => 0,
                    'origem_deals' => [],
                ];
            }
            $porVendedor[$nomeExibicao]['receita'] += $amount;
            $porVendedor[$nomeExibicao]['total_duracao_seg'] += $duracaoSeg;
            $porVendedor[$nomeExibicao]['total_espera_seg'] += $esperaSeg;
            $porVendedor[$nomeExibicao]['count']++;
            $src = $deal['deal_source'] ?? null;
            $sourceName = is_array($src) ? trim((string)($src['name'] ?? $src['id'] ?? '')) : trim((string)$src);
            if ($sourceName === '' && is_array($src) && !empty($src['id'])) {
                $sourceName = 'Origem ' . $src['id'];
            }
            if ($sourceName === '') {
                $sourceName = 'Não informada';
            }
            if ($sourceName !== '') {
                if (!isset($porVendedor[$nomeExibicao]['origem_deals'][$sourceName])) {
                    $porVendedor[$nomeExibicao]['origem_deals'][$sourceName] = ['receita' => 0.0, 'quantidade' => 0];
                }
                $porVendedor[$nomeExibicao]['origem_deals'][$sourceName]['receita'] += $amount;
                $porVendedor[$nomeExibicao]['origem_deals'][$sourceName]['quantidade']++;
            }
        }

        $fetched = count($deals);
        $page++;
    } while (!$earlyStop && $fetched >= $limit && $page <= $maxPages);

    foreach (array_unique(array_values(rdtvGetMapeamento())) as $nome) {
        if (!isset($porVendedor[$nome])) {
            $porVendedor[$nome] = ['receita' => 0.0, 'total_duracao_seg' => 0, 'total_espera_seg' => 0, 'count' => 0];
        }
    }

    return $porVendedor;
}

/**
 * Busca negociações perdidas no período e agrupa por vendedor com motivos de perda.
 * Retorna por vendedor: total_perdidos, motivos_perda [{ nome, quantidade }].
 */
function rdtvFetchLostByVendedora(string $token, string $startDate, string $endDate): array
{
    $porVendedor = [];
    $page       = 1;
    $limit      = 200;
    $maxPages   = 10;
    $earlyStop  = false;

    do {
        $data  = rdtvFetchDeals($token, $page, $limit, $startDate, $endDate, 'false');
        $deals = $data['deals'] ?? [];

        foreach ($deals as $deal) {
            if (!empty($deal['win'])) continue;

            $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
            $date    = $dateStr ? substr((string)$dateStr, 0, 10) : '';
            if ($date !== '' && $date < $startDate) {
                $earlyStop = true;
                break;
            }
            if ($date === '' || $date > $endDate) continue;

            $nomeCrm = trim((string)($deal['user']['name'] ?? ''));
            if ($nomeCrm === '') continue;

            $nomeExibicao = rdtvResolveNome($nomeCrm);
            if ($nomeExibicao === null) continue;

            $lostReason = $deal['deal_lost_reason'] ?? null;
            $motivoNome = is_array($lostReason) ? trim((string)($lostReason['name'] ?? $lostReason['id'] ?? '')) : trim((string)$lostReason);
            if ($motivoNome === '' && is_array($lostReason) && !empty($lostReason['id'])) {
                $motivoNome = 'Motivo ' . $lostReason['id'];
            }
            if ($motivoNome === '') {
                $motivoNome = 'Não informado';
            }

            if (!isset($porVendedor[$nomeExibicao])) {
                $porVendedor[$nomeExibicao] = ['total_perdidos' => 0, 'motivos' => []];
            }
            $porVendedor[$nomeExibicao]['total_perdidos']++;
            if (!isset($porVendedor[$nomeExibicao]['motivos'][$motivoNome])) {
                $porVendedor[$nomeExibicao]['motivos'][$motivoNome] = 0;
            }
            $porVendedor[$nomeExibicao]['motivos'][$motivoNome]++;
        }

        $fetched = count($deals);
        $page++;
    } while (!$earlyStop && $fetched >= $limit && $page <= $maxPages);

    foreach (array_unique(array_values(rdtvGetMapeamento())) as $nome) {
        if (!isset($porVendedor[$nome])) {
            $porVendedor[$nome] = ['total_perdidos' => 0, 'motivos' => []];
        }
    }

    return $porVendedor;
}

/**
 * Busca deals em aberto (sem win) no período e agrupa por estágio do funil.
 * Retorna [ ['stage_name' => x, 'pipeline_name' => y, 'quantidade' => n], ... ].
 */
function rdtvFetchFunilEstagios(string $token, string $startDate, string $endDate): array
{
    $porEstagio = [];
    $page      = 1;
    $limit     = 200;
    $maxPages  = 3;

    do {
        $data  = rdtvFetchDeals($token, $page, $limit, $startDate, $endDate, null);
        $deals = $data['deals'] ?? [];

        foreach ($deals as $deal) {
            if (isset($deal['win']) && $deal['win'] !== null) continue;
            $closedAt = $deal['closed_at'] ?? null;
            if ($closedAt !== null && $closedAt !== '') continue;

            $stage   = $deal['deal_stage'] ?? null;
            $stageName = is_array($stage) ? trim((string)($stage['name'] ?? $stage['nickname'] ?? $stage['id'] ?? '')) : '';
            if ($stageName === '' && is_array($stage) && !empty($stage['id'])) {
                $stageName = 'Etapa ' . $stage['id'];
            }
            if ($stageName === '') $stageName = 'Sem etapa';

            $pipeline = $deal['deal_pipeline'] ?? null;
            $pipeName = is_array($pipeline) ? trim((string)($pipeline['name'] ?? $pipeline['id'] ?? '')) : 'Geral';
            if ($pipeName === '') $pipeName = 'Geral';

            $key = $pipeName . '|' . $stageName;
            if (!isset($porEstagio[$key])) {
                $porEstagio[$key] = ['stage_name' => $stageName, 'pipeline_name' => $pipeName, 'quantidade' => 0];
            }
            $porEstagio[$key]['quantidade']++;
        }

        $fetched = count($deals);
        $page++;
    } while ($fetched >= $limit && $page <= $maxPages);

    return array_values($porEstagio);
}

/**
 * Agrega todas as métricas possíveis da API RD Station CRM para o período.
 * Retorna: receita_total, total_ganhos, total_perdidos, conversao_geral, por_vendedor,
 * funil_estagios, oportunidades_abertas, motivos_perda_geral, origens_geral.
 * Uso: Gestão Comercial e dashboards que precisam de todas as métricas em uma chamada.
 */
function rdFetchTodasMetricas(string $token, string $startDate, string $endDate): array
{
    // false = todos os responsáveis do CRM (totais próximos ao funil / Kanban RD). TV continua com mapeamento fixo.
    $wonAndLost = rdtvFetchWonAndLostParallel($token, $startDate, $endDate, false);
    $porVendedor = $wonAndLost['porVendedor'];
    $perdas      = $wonAndLost['perdas'];

    $receitaTotal = 0.0;
    $totalGanhos  = 0;
    $totalPerdidos = 0;
    $motivosGeral = [];
    $origensGeral = [];

    foreach ($porVendedor as $nome => $dados) {
        $receitaTotal += (float)($dados['receita'] ?? 0);
        $totalGanhos  += (int)($dados['count'] ?? 0);
        foreach ($dados['origem_deals'] ?? [] as $origemNome => $vals) {
            if (!isset($origensGeral[$origemNome])) {
                $origensGeral[$origemNome] = ['receita' => 0.0, 'quantidade' => 0];
            }
            $origensGeral[$origemNome]['receita'] += (float)($vals['receita'] ?? 0);
            $origensGeral[$origemNome]['quantidade'] += (int)($vals['quantidade'] ?? 0);
        }
    }
    foreach ($perdas as $nome => $dados) {
        $totalPerdidos += (int)($dados['total_perdidos'] ?? 0);
        foreach ($dados['motivos'] ?? [] as $motivoNome => $qty) {
            if (!isset($motivosGeral[$motivoNome])) {
                $motivosGeral[$motivoNome] = 0;
            }
            $motivosGeral[$motivoNome] += (int)$qty;
        }
    }

    $totalDeals = $totalGanhos + $totalPerdidos;
    $conversaoGeral = $totalDeals > 0 ? round(($totalGanhos / $totalDeals) * 100, 2) : null;

    $funilEstagios = [];
    $oportunidadesAbertas = 0;
    try {
        $funilEstagios = rdtvFetchFunilEstagios($token, $startDate, $endDate);
        foreach ($funilEstagios as $e) {
            $oportunidadesAbertas += (int)($e['quantidade'] ?? 0);
        }
    } catch (Throwable $e) {
        // mantém arrays vazios
    }

    $motivosLista = [];
    arsort($motivosGeral, SORT_NUMERIC);
    foreach ($motivosGeral as $nome => $qty) {
        $motivosLista[] = ['motivo' => $nome, 'quantidade' => (int)$qty];
    }

    $origensLista = [];
    foreach ($origensGeral as $nome => $vals) {
        $origensLista[] = [
            'origem'     => $nome,
            'receita'    => round((float)($vals['receita'] ?? 0), 2),
            'quantidade' => (int)($vals['quantidade'] ?? 0),
        ];
    }
    usort($origensLista, static fn($a, $b) => $b['receita'] <=> $a['receita']);

    $porVendedorLista = [];
    foreach ($porVendedor as $nome => $dados) {
        $count    = (int)($dados['count'] ?? 0);
        $durSeg   = (int)($dados['total_duracao_seg'] ?? 0);
        $esperaSeg = (int)($dados['total_espera_seg'] ?? 0);
        $lostData = $perdas[$nome] ?? ['total_perdidos' => 0, 'motivos' => []];
        $lost     = (int)($lostData['total_perdidos'] ?? 0);
        $total    = $count + $lost;
        $taxaPerda = $total > 0 ? round(($lost / $total) * 100, 2) : 0.0;
        $duracaoMediaMin = $count > 0 && $durSeg > 0 ? round($durSeg / 60 / $count, 0) : null;
        $esperaMediaMin  = $count > 0 && $esperaSeg > 0 ? round($esperaSeg / 60 / $count, 0) : null;

        $motivosVendedor = [];
        foreach ($lostData['motivos'] ?? [] as $motivoNome => $qty) {
            $motivosVendedor[] = ['motivo' => $motivoNome, 'quantidade' => (int)$qty];
        }
        arsort($lostData['motivos'] ?? [], SORT_NUMERIC);
        usort($motivosVendedor, static fn($a, $b) => $b['quantidade'] <=> $a['quantidade']);

        $origemVendedor = [];
        foreach ($dados['origem_deals'] ?? [] as $origemNome => $vals) {
            $origemVendedor[] = [
                'origem'     => $origemNome,
                'receita'    => round((float)($vals['receita'] ?? 0), 2),
                'quantidade' => (int)($vals['quantidade'] ?? 0),
            ];
        }
        usort($origemVendedor, static fn($a, $b) => $b['receita'] <=> $a['receita']);

        $porVendedorLista[] = [
            'vendedor'                 => $nome,
            'receita'                  => round((float)($dados['receita'] ?? 0), 2),
            'total_ganhos'              => $count,
            'total_perdidos'           => $lost,
            'conversao_pct'            => $total > 0 ? round(($count / $total) * 100, 2) : 0.0,
            'taxa_perda_pct'           => $taxaPerda,
            'tempo_medio_fechamento_min' => $duracaoMediaMin,
            'tempo_medio_espera_min'   => $esperaMediaMin,
            'motivos_perda'            => array_slice($motivosVendedor, 0, 10),
            'origem_deals'             => $origemVendedor,
        ];
    }
    usort($porVendedorLista, static fn($a, $b) => $b['receita'] <=> $a['receita']);

    return [
        'fonte'                    => 'rdstation_crm',
        'periodo'                  => ['data_de' => $startDate, 'data_ate' => $endDate],
        'receita_total'            => round($receitaTotal, 2),
        'total_ganhos'              => $totalGanhos,
        'total_perdidos'           => $totalPerdidos,
        'conversao_geral_pct'       => $conversaoGeral,
        'oportunidades_abertas'     => $oportunidadesAbertas,
        'funil_estagios'           => $funilEstagios,
        'por_vendedor'             => $porVendedorLista,
        'motivos_perda_geral'      => $motivosLista,
        'origens_geral'            => $origensLista,
        'updated_at'               => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        'notas'                    => [
            'totais'              => 'Totais incluem todas as negociações ganhas/perdidas no período, por data de fechamento (ou criação, se fechamento vazio), alinhado ao filtro de datas da API — como a TV Corrida, mas sem limitar aos nomes mapeados no MyPharm.',
            'divergencia_funil'   => 'No Kanban RD, colunas podem somar valores de cards ainda abertos ou com regra de exibição diferente; aqui só entram negociações já marcadas como ganha (win) ou perdida. Use o mesmo intervalo de datas que no RD (ex.: data de fechamento).',
        ],
    ];
}

/**
 * Monta o ranking final no formato esperado pela TV.
 * Ganhos e perdas são buscados em paralelo para reduzir o tempo de resposta.
 */
function rdtvBuildRanking(string $token, string $dataDe, string $dataAte, array $metas, array $nomesExt = []): array
{
    $wonAndLost = rdtvFetchWonAndLostParallel($token, $dataDe, $dataAte);
    $porVendedor = $wonAndLost['porVendedor'];
    $perdas      = $wonAndLost['perdas'];
    $funilEstagios = [];
    try {
        $funilEstagios = rdtvFetchFunilEstagios($token, $dataDe, $dataAte);
    } catch (Throwable $e) {
        // Funil opcional; não quebra o ranking
    }

    $lista = [];
    foreach ($porVendedor as $nome => $dados) {
        $receita   = (float)($dados['receita'] ?? 0);
        $count     = (int)($dados['count'] ?? 0);
        $durSeg    = (int)($dados['total_duracao_seg'] ?? 0);
        $esperaSeg = (int)($dados['total_espera_seg'] ?? 0);

        $normNome   = rdtvNormalize($nome);
        $metaMensal = 0.0;
        foreach ($metas as $normBanco => $meta) {
            if ($normNome === $normBanco ||
                str_contains($normNome, $normBanco) ||
                str_contains($normBanco, $normNome)) {
                $metaMensal = $meta;
                break;
            }
        }
        if ($metaMensal <= 0) $metaMensal = 60000.0;

        $duracaoMediaMin = $count > 0 && $durSeg > 0 ? round($durSeg / 60 / $count, 0) : null;
        $esperaMediaMin  = $count > 0 && $esperaSeg > 0 ? round($esperaSeg / 60 / $count, 0) : null;

        $lostData    = $perdas[$nome] ?? ['total_perdidos' => 0, 'motivos' => []];
        $totalLost   = (int)($lostData['total_perdidos'] ?? 0);
        $totalWon    = $count;
        $totalDeals  = $totalWon + $totalLost;
        $taxaPerdaPct = $totalDeals > 0 ? round(($totalLost / $totalDeals) * 100, 2) : 0.0;

        $motivosArr = $lostData['motivos'] ?? [];
        arsort($motivosArr, SORT_NUMERIC);
        $topMotivos = [];
        $idx = 0;
        foreach ($motivosArr as $motivoNome => $qty) {
            if ($idx++ >= 5) break;
            $topMotivos[] = ['nome' => $motivoNome, 'quantidade' => (int)$qty];
        }

        $origemDeals = $dados['origem_deals'] ?? [];
        $origemLista = [];
        foreach ($origemDeals as $origemNome => $vals) {
            $origemLista[] = [
                'nome'       => $origemNome,
                'receita'    => round((float)($vals['receita'] ?? 0), 2),
                'quantidade' => (int)($vals['quantidade'] ?? 0),
            ];
        }
        usort($origemLista, static fn($a, $b) => $b['receita'] <=> $a['receita']);

        $lista[] = [
            'vendedor'               => $nome,
            'receita'                => round($receita, 2),
            'meta_mensal'            => $metaMensal,
            'duracao_media_min'      => $duracaoMediaMin,
            'tempo_medio_espera_min' => $esperaMediaMin,
            'total_deals_ganhos'     => $totalWon,
            'total_deals_perdidos'  => $totalLost,
            'taxa_perda_pct'        => $taxaPerdaPct,
            'top_motivos_perda'     => $topMotivos,
            'origem_deals'           => $origemLista,
        ];
    }

    usort($lista, static fn(array $a, array $b) => $b['receita'] <=> $a['receita']);

    $maxReceita = 0.0;
    foreach ($lista as $it) {
        if ($it['receita'] > $maxReceita) $maxReceita = $it['receita'];
    }

    foreach ($lista as $i => &$it) {
        $it['posicao']               = $i + 1;
        $it['percentual_lider']     = $maxReceita > 0
            ? round(($it['receita'] / $maxReceita) * 100, 2) : 0.0;
        $it['percentual_meta']       = $it['meta_mensal'] > 0
            ? round(($it['receita'] / $it['meta_mensal']) * 100, 2) : 0.0;
        $it['meta_mensal_utilizada'] = $it['meta_mensal'];
    }
    unset($it);

    $now = new DateTimeImmutable('now');
    return [
        'success'         => true,
        'fonte'           => 'rdstation_crm',
        'periodo'         => [
            'data_de'  => (new DateTimeImmutable($dataDe))->format('Y-m-d'),
            'data_ate' => (new DateTimeImmutable($dataAte))->format('Y-m-d'),
        ],
        'max_receita'     => round($maxReceita, 2),
        'ranking'         => $lista,
        'funil_estagios'  => $funilEstagios,
        'updated_at'      => $now->format('Y-m-d H:i:s'),
    ];
}
