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
