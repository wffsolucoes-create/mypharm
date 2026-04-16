<?php
/**
 * Configuração da integração com RD Station CRM (API v1)
 * 
 * A API v1 usa o token diretamente como query parameter.
 * Base: https://crm.rdstation.com/api/v1
 */

// ============================================================
// TOKEN DE ACESSO - RD Station CRM API v1
// ============================================================
define('RD_API_TOKEN', '69b244e342d1f9001391ae62');

// URL base da API v1 do RD Station CRM
define('RD_API_BASE', 'https://crm.rdstation.com/api/v1');

// ============================================================
// CONFIGURAÇÃO DE METAS E RANKING
// ============================================================

// Meta global de vendas (em R$) - Usada quando o vendedor não possui meta individual
define('META_GLOBAL', 25000);

// Período do ranking: 'mensal' (reseta dia 1), 'semanal' (reseta segunda), 'anual'
define('PERIODO_RANKING', 'mensal');

// Tempo de cache em segundos (60 = 1 min)
define('CACHE_TTL', 60);

// Diretório do cache
define('CACHE_DIR', __DIR__ . '/cache');

// ============================================================
// CONFIGURAÇÃO INDIVIDUAL DE VENDEDORAS
// A chave é o NOME EXATO como aparece no RD Station CRM.
// ============================================================
$SELLER_CONFIG = [
    'Vitória Carvalho' => [
        'nome_exibicao' => 'Jessica Vitória',
        'foto'   => 'https://i.pravatar.cc/256?u=jessica_vitoria',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Giovanna' => [
        'nome_exibicao' => 'Giovanna',
        'foto'   => 'https://i.pravatar.cc/256?u=giovanna_vendas',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Carla Pires - Consultora' => [
        'nome_exibicao' => 'Carla Pires',
        'foto'   => 'https://i.pravatar.cc/256?u=carla_pires',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Nailena' => [
        'nome_exibicao' => 'Nailena',
        'foto'   => 'https://i.pravatar.cc/256?u=nailena_rv',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Clara Letícia' => [
        'nome_exibicao' => 'Clara Letícia',
        'foto'   => 'https://i.pravatar.cc/256?u=clara_leticia',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Ananda' => [
        'nome_exibicao' => 'Ananda Reis',
        'foto'   => 'https://i.pravatar.cc/256?u=ananda_reis',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Micaela Nicolle' => [
        'nome_exibicao' => 'Micaela Nicolle',
        'foto'   => 'https://i.pravatar.cc/256?u=micaela_nicolle',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Nereida' => [
        'nome_exibicao' => 'Nereida',
        'foto'   => 'https://i.pravatar.cc/256?u=nereida_rv',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Mariana' => [
        'nome_exibicao' => 'Mariana',
        'foto'   => 'https://i.pravatar.cc/256?u=mariana_rv',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
];
