<?php
/**
 * Configuração da integração com RD Station CRM (API v1)
 * Lê o token e credenciais do .env da raiz do projeto (via db.php, mesmo parser do MyPharm).
 */

require_once __DIR__ . '/db.php';

// ============================================================
// TOKEN DE ACESSO - RD Station CRM API v1
// ============================================================
define('RD_API_TOKEN', $_ENV['RDSTATION_CRM_TOKEN'] ?? '');

// URL base da API v1 do RD Station CRM
define('RD_API_BASE', 'https://crm.rdstation.com/api/v1');

// ============================================================
// CONFIGURAÇÃO DE METAS E RANKING
// ============================================================

// Meta global de vendas (em R$) - Usada quando o vendedor não possui meta individual
define('META_GLOBAL', 25000);

// Período do ranking: 'mensal' (reseta dia 1), 'semanal' (reseta segunda), 'anual'
define('PERIODO_RANKING', 'mensal');

// Fuso para cálculo do período e campo ultima_atualizacao (alinhado ao restante do MyPharm em Rondônia)
define('TV_TIMEZONE', 'America/Porto_Velho');

// Tempo de cache em segundos (60 = 1 min)
define('CACHE_TTL', 60);

// Diretório do cache
define('CACHE_DIR', __DIR__ . '/cache');

/**
 * Incrementar quando mudar regra de agregação, fuso ou formato do ranking — invalida ficheiro de cache antigo.
 */
define('TV_RANKING_CACHE_VERSION', '6');

/**
 * Caminho do JSON cacheado do ranking (não usar ranking.json fixo: deploy antigo mantinha dados obsoletos).
 */
function tv_ranking_cache_path(): string
{
    return CACHE_DIR . '/ranking_v' . TV_RANKING_CACHE_VERSION . '.json';
}

/**
 * Segmento de URL do projeto (ex.: "mypharm" em XAMPP/htdocs/mypharm).
 * Vazio = ficheiros na raiz do domínio (ex.: Hostinger public_html = site).
 * Sobrescrever com .env: MYPHARM_WEB_PATH=mypharm  ou  MYPHARM_WEB_PATH=
 */
function mypharm_web_public_prefix(): string
{
    if (array_key_exists('MYPHARM_WEB_PATH', $_ENV)) {
        return trim((string) $_ENV['MYPHARM_WEB_PATH'], '/');
    }

    $projectRoot = realpath(dirname(__DIR__, 2));
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($projectRoot && $docRoot && strpos($projectRoot, $docRoot) === 0) {
        $rest = trim(str_replace('\\', '/', substr($projectRoot, strlen($docRoot))), '/');
        if ($rest === '' || $rest === '.') {
            return '';
        }
        $parts = explode('/', $rest);
        return $parts[0] !== '' ? $parts[0] : '';
    }

    return 'mypharm';
}

/**
 * URL pública para um ficheiro em uploads/ (coluna foto_perfil = caminho relativo a uploads/, ex. avatars/x.jpg).
 */
function foto_perfil_public_url(string $relative): string
{
    $relative = trim(str_replace('\\', '/', $relative));
    if ($relative === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    // Caminho absoluto web já pronto
    if ($relative[0] === '/') {
        return $relative;
    }

    $relative = ltrim($relative, '/');
    // Coluna pode ser "avatars/x.jpg" ou "uploads/avatars/x.jpg" (como em api.php)
    if (stripos($relative, 'uploads/') === 0) {
        $path = '/' . $relative;
    } else {
        $path = '/uploads/' . $relative;
    }

    $prefix = mypharm_web_public_prefix();
    if ($prefix !== '') {
        return '/' . $prefix . $path;
    }
    return $path;
}

// Função para buscar foto do usuário no banco
function getFotoFromDB($nomeCrm, $email = '') {
    try {
        $pdo = getDB();

        // Tenta buscar por email primeiro
        if (!empty($email)) {
            $stmt = $pdo->prepare('
                SELECT foto_perfil FROM usuarios
                WHERE email = ? AND ativo = 1 AND foto_perfil IS NOT NULL AND foto_perfil != ""
                LIMIT 1
            ');
            $stmt->execute([$email]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && !empty($result['foto_perfil'])) {
                return foto_perfil_public_url($result['foto_perfil']);
            }
        }

        // Fallback: tenta por nome
        $nomes = explode(' ', trim($nomeCrm));
        $primeiroNome = $nomes[0] ?? '';

        if (!empty($primeiroNome)) {
            $stmt = $pdo->prepare('
                SELECT foto_perfil FROM usuarios
                WHERE nome LIKE ? AND ativo = 1 AND foto_perfil IS NOT NULL AND foto_perfil != ""
                LIMIT 1
            ');
            $stmt->execute(['%' . $primeiroNome . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && !empty($result['foto_perfil'])) {
                return foto_perfil_public_url($result['foto_perfil']);
            }
        }

        return null;
    } catch (Exception $e) {
        error_log('Erro ao buscar foto do BD: ' . $e->getMessage());
        return null;
    }
}

// Função para buscar meta do usuário no banco
function getMetaFromDB($nomeCrm, $email = '') {
    global $NAME_ALIASES;

    try {
        $pdo = getDB();

        // 0. Aplica alias se existir
        $nomeParaBuscar = $NAME_ALIASES[$nomeCrm] ?? $nomeCrm;

        // 1. Tenta buscar por email primeiro (mais confiável)
        if (!empty($email)) {
            $stmt = $pdo->prepare('
                SELECT meta_mensal FROM usuarios
                WHERE email = ? AND ativo = 1
                LIMIT 1
            ');
            $stmt->execute([$email]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['meta_mensal'] > 0) {
                return (float)$result['meta_mensal'];
            }
        }

        // 2. Extrai primeiro nome e tenta match
        $nomes = explode(' ', trim($nomeParaBuscar));
        $primeiroNome = $nomes[0] ?? '';

        if (!empty($primeiroNome)) {
            $stmt = $pdo->prepare('
                SELECT meta_mensal FROM usuarios
                WHERE nome LIKE ? AND ativo = 1
                LIMIT 1
            ');
            $stmt->execute(['%' . $primeiroNome . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['meta_mensal'] > 0) {
                return (float)$result['meta_mensal'];
            }
        }

        // 3. Fallback: tenta nome completo
        $stmt = $pdo->prepare('
            SELECT meta_mensal FROM usuarios
            WHERE nome LIKE ? AND ativo = 1
            LIMIT 1
        ');
        $stmt->execute(['%' . $nomeParaBuscar . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['meta_mensal'] > 0) {
            return (float)$result['meta_mensal'];
        }

        return null;
    } catch (Exception $e) {
        error_log('Erro ao buscar meta do BD: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// CONFIGURAÇÃO INDIVIDUAL DE VENDEDORAS
// A chave é o NOME EXATO como aparece no RD Station CRM.
// ============================================================
// ============================================================
// MAPEAMENTO DE NOMES / ALIASES
// Associa nomes do RD Station a nomes no banco (apelidos, variações)
// ============================================================
$NAME_ALIASES = [
    'Vitória Carvalho' => 'Vitória',        // RD Station → banco
    'Vitória Carvalho' => 'Jessica Vitória',
    'Jéssica' => 'Jessica',
    'Jhennyffer' => 'Jhennyffer',
    // adicionar mais conforme necessário
];

// foto: deixar vazio ('') exibe as iniciais do nome no frontend.
// URL completa ou caminho a partir de uploads/ (ex.: avatars/arquivo.png). Prefixo /mypharm vem de MYPHARM_WEB_PATH ou deteção automática.
$SELLER_CONFIG = [
    'Vitória Carvalho' => [
        'nome_exibicao' => 'Jessica Vitória',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Giovanna' => [
        'nome_exibicao' => 'Giovanna',
        'foto'   => '',
        'equipe' => 'Equipe Comercial',
        'meta'   => 25000
    ],
    'Carla Pires - Consultora' => [
        'nome_exibicao' => 'Carla Pires',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Nailena' => [
        'nome_exibicao' => 'Nailena',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Clara Letícia' => [
        'nome_exibicao' => 'Clara Letícia',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Ananda' => [
        'nome_exibicao' => 'Ananda Reis',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Micaela Nicolle' => [
        'nome_exibicao' => 'Micaela Nicolle',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Nereida' => [
        'nome_exibicao' => 'Nereida',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
    'Mariana' => [
        'nome_exibicao' => 'Mariana',
        'foto'   => '',
        'equipe' => 'Equipe Capital',
        'meta'   => 25000
    ],
];
