<?php
/**
 * Helpers compartilhados pelos módulos da API.
 * Incluído por api.php antes do switch de actions.
 */

function safeLimit($value, $min = 1, $max = 100)
{
    $limit = intval($value);
    return max($min, min($max, $limit));
}

function buildDateFilter()
{
    $dataDe = isset($_GET['data_de']) ? trim((string)$_GET['data_de']) : null;
    $dataAte = isset($_GET['data_ate']) ? trim((string)$_GET['data_ate']) : null;
    if ($dataDe === '') $dataDe = null;
    if ($dataAte === '') $dataAte = null;
    if ($dataDe !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe)) $dataDe = null;
    if ($dataAte !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte)) $dataAte = null;
    if ($dataDe !== null && $dataAte !== null && $dataDe > $dataAte) $dataAte = $dataDe;

    if ($dataDe !== null && $dataAte !== null) {
        // Período do topo: considerar movimento no dia (aprovação ou, se vazia, data do orçamento).
        return [
            '(data_aprovacao IS NOT NULL OR data_orcamento IS NOT NULL) AND DATE(COALESCE(data_aprovacao, data_orcamento)) BETWEEN :data_de AND :data_ate',
            ['data_de' => $dataDe, 'data_ate' => $dataAte],
        ];
    }

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
