<?php
/**
 * Página para executar a importação completa (2026) pelo navegador.
 * Apenas usuários com tipo "admin" podem usar.
 *
 * Uso: faça login como admin e acesse run_import.php (GET para ver o botão, depois clique para executar).
 */
require_once __DIR__ . '/config.php';
$pdo = getConnection();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}
if (($_SESSION['user_tipo'] ?? '') !== 'admin') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Acesso negado</title></head><body>';
    echo '<p><strong>Acesso negado.</strong> Apenas administradores podem executar a importação.</p>';
    echo '<p><a href="index.html">Voltar</a></p></body></html>';
    exit;
}

$run = isset($_GET['run']) && $_GET['run'] === '1';
$background = isset($_GET['background']) && $_GET['background'] === '1';
header('Content-Type: text/html; charset=utf-8');

if (!$run) {
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Executar importação – MyPharm</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 560px; margin: 40px auto; padding: 0 20px; }
        h1 { font-size: 1.25rem; }
        p { color: #444; }
        .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 12px; border: none; cursor: pointer; font-size: 1rem; }
        .btn:hover { background: #1d4ed8; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .out { margin-top: 24px; padding: 16px; background: #f1f5f9; border-radius: 8px; white-space: pre-wrap; font-size: 0.875rem; max-height: 70vh; overflow: auto; }
        .back { margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Executar importação (2026)</h1>
    <p>Isso roda todas as importações: vínculos, Gestão de Pedidos, Itens, Detalhado com Componentes, Prescritor Resumido e Histórico de Visitas. Pode levar 15–30 segundos.</p>
    <p><strong>Se aparecer erro do Apache (pipe finalizado)</strong> no XAMPP/Windows, use o terminal:</p>
    <pre style="background:#f1f5f9; padding:12px; border-radius:8px; font-size:0.85rem; overflow:auto;">c:\xampp\php\php.exe scripts/importar_tudo_cli.php</pre>
    <form method="get" action="run_import.php" style="margin-top:16px;">
        <input type="hidden" name="run" value="1">
        <button type="submit" class="btn">Executar agora (aguarda término)</button>
    </form>
    <form method="get" action="run_import.php" style="margin-top:8px;">
        <input type="hidden" name="run" value="1"><input type="hidden" name="background" value="1">
        <button type="submit" class="btn" style="background:#64748b;">Executar em segundo plano (retorna rápido)</button>
    </form>
    <p class="back"><a href="index.html">← Voltar</a></p>
</body>
</html>
    <?php
    exit;
}

// Executar importação
set_time_limit(0);
ignore_user_abort(true);

$php = 'php';
if (defined('PHP_BINARY') && PHP_BINARY !== '') {
    $php = PHP_BINARY;
} elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && file_exists('c:\\xampp\\php\\php.exe')) {
    $php = 'c:\\xampp\\php\\php.exe';
}
$script = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'importar_tudo_cli.php';

if ($background) {
    // Segundo plano: não espera o término; evita erro de pipe do Apache no Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $runCmd = 'start /B "" ' . $php . ' ' . escapeshellarg($script);
        @pclose(@popen($runCmd, 'r'));
    } else {
        $runCmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        @exec($runCmd);
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Importação em segundo plano</title></head><body>';
    echo '<h1>Importação iniciada em segundo plano</h1>';
    echo '<p>A importação está rodando. Em 1–2 minutos os dados devem estar atualizados. Não é necessário esperar nesta página.</p>';
    echo '<p><a href="run_import.php">← Executar de novo</a> &nbsp; <a href="index.html">Voltar ao sistema</a></p></body></html>';
    exit;
}

$cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
$cwd = __DIR__;

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
if (!is_resource($proc)) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body>';
    echo '<p>Não foi possível executar o script. Use <strong>Executar em segundo plano</strong> ou o terminal:</p>';
    echo '<pre>c:\\xampp\\php\\php.exe scripts/importar_tudo_cli.php</pre>';
    echo '<p><a href="run_import.php">← Voltar</a></p></body></html>';
    exit;
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

$output = $stdout . ($stderr ? "\n" . $stderr : '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Importação concluída – MyPharm</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 20px; }
        h1 { font-size: 1.25rem; }
        .out { margin-top: 16px; padding: 16px; background: #f1f5f9; border-radius: 8px; white-space: pre-wrap; font-size: 0.875rem; max-height: 70vh; overflow: auto; }
        .back { margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Importação concluída</h1>
    <div class="out"><?php echo htmlspecialchars($output ?: '(nenhuma saída)'); ?></div>
    <p class="back"><a href="run_import.php">← Executar de novo</a> &nbsp; <a href="index.html">Voltar ao sistema</a></p>
</body>
</html>
