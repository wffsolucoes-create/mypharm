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
 * Busca deals do RD Station CRM (com win=true).
 */
function rdtvFetchDeals(string $token, int $page, int $limit, string $startDate = '', string $endDate = ''): array
{
    $params = [
        'token' => $token,
        'page'  => $page,
        'limit' => $limit,
        'win'   => 'true',
    ];
    if ($startDate) $params['start_date'] = $startDate;
    if ($endDate)   $params['end_date']   = $endDate;

    $url = RDSTATION_API_BASE . '/deals?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
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
 * Busca todas as negociações ganhas no período e agrupa por nome de exibição.
 * Filtra somente as 8 vendedoras do mapeamento.
 */
function rdtvFetchWonByVendedora(string $token, string $startDate, string $endDate): array
{
    $receitas  = [];
    $page      = 1;
    $limit     = 200;
    $maxPages  = 10; // Proteção máxima: 2000 deals
    $earlyStop = false;

    do {
        $data  = rdtvFetchDeals($token, $page, $limit, $startDate, $endDate);
        $deals = $data['deals'] ?? [];

        foreach ($deals as $deal) {
            if (empty($deal['win'])) continue;

            // Early-stop: deals ordenados do mais recente ao mais antigo
            // Quando closed_at < startDate, todos os próximos serão ainda mais antigos
            $dateStr = $deal['closed_at'] ?? $deal['created_at'] ?? '';
            $date    = $dateStr ? substr((string)$dateStr, 0, 10) : '';
            if ($date !== '' && $date < $startDate) {
                $earlyStop = true;
                break;
            }
            // Pula deals fora do período (futuro ou sem data)
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

            if (!isset($receitas[$nomeExibicao])) $receitas[$nomeExibicao] = 0.0;
            $receitas[$nomeExibicao] += $amount;
        }

        $fetched = count($deals);
        $page++;
    } while (!$earlyStop && $fetched >= $limit && $page <= $maxPages);

    // Garante que todas as 8 apareçam mesmo sem venda
    foreach (array_unique(array_values(rdtvGetMapeamento())) as $nome) {
        if (!isset($receitas[$nome])) $receitas[$nome] = 0.0;
    }

    return $receitas;
}

/**
 * Monta o ranking final no formato esperado pela TV.
 */
function rdtvBuildRanking(string $token, string $dataDe, string $dataAte, array $metas, array $nomesExt = []): array
{
    $receitaMap = rdtvFetchWonByVendedora($token, $dataDe, $dataAte);

    $lista = [];
    foreach ($receitaMap as $nome => $receita) {
        $normNome   = rdtvNormalize($nome);
        $metaMensal = 0.0;

        // Busca meta pelo nome normalizado
        foreach ($metas as $normBanco => $meta) {
            if ($normNome === $normBanco ||
                str_contains($normNome, $normBanco) ||
                str_contains($normBanco, $normNome)) {
                $metaMensal = $meta;
                break;
            }
        }

        if ($metaMensal <= 0) $metaMensal = 60000.0;

        $lista[] = [
            'vendedor'    => $nome,
            'receita'     => round($receita, 2),
            'meta_mensal' => $metaMensal,
        ];
    }

    usort($lista, static fn(array $a, array $b) => $b['receita'] <=> $a['receita']);

    $maxReceita = 0.0;
    foreach ($lista as $it) {
        if ($it['receita'] > $maxReceita) $maxReceita = $it['receita'];
    }

    foreach ($lista as $i => &$it) {
        $it['posicao']               = $i + 1;
        $it['percentual_lider']      = $maxReceita > 0
            ? round(($it['receita'] / $maxReceita) * 100, 2) : 0.0;
        $it['percentual_meta']       = $it['meta_mensal'] > 0
            ? round(($it['receita'] / $it['meta_mensal']) * 100, 2) : 0.0;
        $it['meta_mensal_utilizada'] = $it['meta_mensal'];
    }
    unset($it);

    $now = new DateTimeImmutable('now');
    return [
        'success'     => true,
        'fonte'       => 'rdstation_crm',
        'periodo'     => [
            'data_de'  => (new DateTimeImmutable($dataDe))->format('d/m/Y'),
            'data_ate' => (new DateTimeImmutable($dataAte))->format('d/m/Y'),
        ],
        'max_receita' => round($maxReceita, 2),
        'ranking'     => $lista,
        'updated_at'  => $now->format('d/m/Y H:i'),
    ];
}
