<?php
/**
 * Gera relatório geral do banco MyPharm: tabelas, contagens, diagnóstico de componentes.
 * Saída: RELATORIO_BANCO.md na raiz do projeto (e resumo no terminal).
 *
 * Uso: php scripts/relatorio_banco_geral.php
 */
if (PHP_SAPI !== 'cli') {
    die('Execute pelo terminal: php scripts/relatorio_banco_geral.php');
}

$baseDir = dirname(__DIR__);
$envPath = $baseDir . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    echo "Arquivo .env não encontrado.\n";
    exit(1);
}
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $p = explode('=', $line, 2);
    if (count($p) === 2) putenv(trim($p[0]) . '=' . trim(trim($p[1]), '"\''));
}

$host = getenv('DB_HOST') ?: 'localhost';
$name = getenv('DB_NAME') ?: 'mypharm_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=$charset",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo "Erro de conexão: " . $e->getMessage() . "\n";
    exit(1);
}

$db = $name;
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$byTable = [];
foreach ($tables as $table) {
    try {
        $byTable[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "`")->fetchColumn();
    } catch (Exception $e) {
        $byTable[$table] = -1;
    }
}

// Diagnóstico: pedidos_detalhado_componentes
$diagComponentes = [
    'existe' => in_array('pedidos_detalhado_componentes', $tables),
    'total_linhas' => $byTable['pedidos_detalhado_componentes'] ?? -1,
    'pedidos_distintos' => 0,
    'colunas_esperadas' => ['numero', 'serie', 'componente', 'quantidade_componente', 'unidade_componente'],
    'colunas_reais' => [],
    'pedido_58399_1' => [],
    'erro' => null,
];
if ($diagComponentes['existe']) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM pedidos_detalhado_componentes")->fetchAll(PDO::FETCH_COLUMN);
        $diagComponentes['colunas_reais'] = $cols;
        $diagComponentes['pedidos_distintos'] = (int) $pdo->query("SELECT COUNT(DISTINCT CONCAT(numero, '-', serie)) FROM pedidos_detalhado_componentes")->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM pedidos_detalhado_componentes WHERE numero = 58399 AND serie = 1 ORDER BY id ASC LIMIT 10");
        $stmt->execute();
        $diagComponentes['pedido_58399_1'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $diagComponentes['erro'] = $e->getMessage();
    }
}

$out = [];
$out[] = '# Relatório geral do banco – MyPharm';
$out[] = '';
$out[] = '**Data:** ' . date('d/m/Y H:i:s');
$out[] = '**Banco:** ' . $db . ' @ ' . $host;
$out[] = '';
$out[] = '---';
$out[] = '';
$out[] = '## 1. Tabelas e contagem de registros';
$out[] = '';
$out[] = '| Tabela | Registros |';
$out[] = '|--------|-----------|';
$total = 0;
foreach ($tables as $t) {
    $n = $byTable[$t] >= 0 ? number_format($byTable[$t], 0, ',', '.') : 'erro';
    if ($byTable[$t] >= 0) $total += $byTable[$t];
    $out[] = '| ' . $t . ' | ' . $n . ' |';
}
$out[] = '| **Total** | **' . number_format($total, 0, ',', '.') . '** |';
$out[] = '';
$out[] = '---';
$out[] = '';
$out[] = '## 2. Mapeamento por domínio';
$out[] = '';
$out[] = '| Tabela | Fonte / Uso |';
$out[] = '|--------|-------------|';
$out[] = '| **usuarios** | Login, perfis, visitadores, metas |';
$out[] = '| **prescritores_cadastro** | Cadastro único prescritor ↔ visitador (carteira) |';
$out[] = '| **prescritor_resumido** | Resumo por prescritor/ano (aprovados, recusados, no carrinho) |';
$out[] = '| **prescritor_dados** | Dados extras do prescritor (profissão, registro, endereço, etc.) |';
$out[] = '| **prescritor_contatos** | WhatsApp por prescritor |';
$out[] = '| **gestao_pedidos** | CSV “Relatório de Gestão de Pedidos” – itens por pedido (numero_pedido, serie_pedido) |';
$out[] = '| **itens_orcamentos_pedidos** | CSV “Relatório de Itens de Orçamentos e Pedidos” – itens por numero/serie |';
$out[] = '| **pedidos_detalhado_componentes** | CSV “Relatórios… Detalhado com Componentes” – componente + quantidade por numero/serie |';
$out[] = '| **historico_visitas** | XLSX “Relatório de Histórico de Visitas” |';
$out[] = '| **visitas_agendadas** | Agenda do visitador |';
$out[] = '| **visitas_em_andamento** | Visita em curso |';
$out[] = '| **visitas_geolocalizacao** | Pontos GPS das visitas |';
$out[] = '| **rotas_diarias** / **rotas_pontos** | Rotas e trajetos |';
$out[] = '| **login_attempts** | Bloqueio por tentativas de login |';
$out[] = '';
$out[] = '---';
$out[] = '';
$out[] = '## 3. Chaves de ligação (pedidos)';
$out[] = '';
$out[] = '- **gestao_pedidos:** `numero_pedido` + `serie_pedido` + `ano_referencia`';
$out[] = '- **itens_orcamentos_pedidos:** `numero` + `serie` + `ano_referencia`';
$out[] = '- **pedidos_detalhado_componentes:** `numero` + `serie` (opcional: `ano_referencia`)';
$out[] = '';
$out[] = '---';
$out[] = '';
$out[] = '## 4. Diagnóstico – Componentes no modal “Detalhe do Pedido”';
$out[] = '';

if (!$diagComponentes['existe']) {
    $out[] = '- **Tabela `pedidos_detalhado_componentes` não existe.**';
    $out[] = '- Crie e importe com: `php scripts/importar_detalhado_componentes_cli.php 2026`';
} else {
    $out[] = '- Tabela existe. Total de linhas: **' . ($diagComponentes['total_linhas'] >= 0 ? number_format($diagComponentes['total_linhas'], 0, ',', '.') : '?') . '**';
    $out[] = '- **Pedidos distintos (numero+série) com componentes:** ' . number_format($diagComponentes['pedidos_distintos'], 0, ',', '.') . ' — só esses pedidos exibem a seção Componentes no modal.';
    $out[] = '- Colunas esperadas pela API: `numero`, `serie`, `componente`, `quantidade_componente`, `unidade_componente`';
    $out[] = '- Colunas encontradas: `' . implode('`, `', $diagComponentes['colunas_reais']) . '`';
    $faltam = array_diff($diagComponentes['colunas_esperadas'], $diagComponentes['colunas_reais']);
    if (!empty($faltam)) {
        $out[] = '- **Atenção:** faltam colunas: `' . implode('`, `', $faltam) . '` – a API pode falhar ou retornar vazio.';
    } else {
        $out[] = '- Todas as colunas necessárias estão presentes.';
    }
    if ($diagComponentes['erro']) {
        $out[] = '- **Erro ao consultar pedido 58399/1:** ' . $diagComponentes['erro'];
    } elseif (empty($diagComponentes['pedido_58399_1'])) {
        $out[] = '- **Pedido 58399 série 1:** nenhuma linha encontrada. Para este pedido o modal exibirá “Nenhum componente disponível” até haver dados importados para esse numero/serie.';
    } else {
        $out[] = '- **Pedido 58399 série 1:** ' . count($diagComponentes['pedido_58399_1']) . ' componente(s) encontrado(s).';
    }
    if ($diagComponentes['pedidos_distintos'] > 0 && $diagComponentes['pedidos_distintos'] < 50) {
        $out[] = '';
        $out[] = '**Dica:** Poucos pedidos com componentes. Para aumentar, importe o CSV completo: `php scripts/importar_detalhado_componentes_cli.php 2026` (e 2025 se tiver). A API também tenta série 0 quando não acha para a série informada.';
    }
    if (!empty($diagComponentes['pedido_58399_1'])) {
        $out[] = '';
        $out[] = 'Amostra (58399/1):';
        $out[] = '```';
        foreach (array_slice($diagComponentes['pedido_58399_1'], 0, 5) as $r) {
            $out[] = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        $out[] = '```';
    }
}

$out[] = '';
$out[] = '---';
$out[] = '';
$out[] = '*Relatório gerado por `scripts/relatorio_banco_geral.php`*';

$md = implode("\n", $out);
$path = $baseDir . DIRECTORY_SEPARATOR . 'RELATORIO_BANCO.md';
file_put_contents($path, $md);
echo "Relatório gravado em: " . $path . "\n";
echo "\n--- Resumo ---\n";
echo "Tabelas: " . count($tables) . " | Total registros: " . number_format($total, 0, ',', '.') . "\n";
echo "pedidos_detalhado_componentes: " . ($diagComponentes['existe'] ? $diagComponentes['total_linhas'] . " linhas, " . $diagComponentes['pedidos_distintos'] . " pedidos distintos" : "não existe") . "\n";
echo "Pedido 58399/1: " . (count($diagComponentes['pedido_58399_1']) ? count($diagComponentes['pedido_58399_1']) . " componentes" : "0 componentes") . "\n";
echo "\n";
