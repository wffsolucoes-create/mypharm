<?php
/**
 * Server-Sent Events — ranking TV
 *
 * Mantém uma conexão HTTP aberta com o navegador.
 * Quando o webhook invalida o cache, escreve sse_trigger.txt.
 * Este script detecta a mudança e envia "event: update" ao cliente.
 * O React invalida a query → busca dados frescos → animações disparam.
 *
 * Apache: precisa de mod_headers e output_buffering = Off no php.ini
 * ou o script já chama ob_end_clean() abaixo.
 */

// Zera qualquer buffer de saída para streaming funcionar
while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');   // nginx
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';

$triggerFile = CACHE_DIR . '/sse_trigger.txt';
$lastMtime   = 0;
$start       = time();
$maxAge      = 50; // reconectar a cada ~50s para manter conexão saudável

// Confirma conexão ao cliente
echo "event: connected\n";
echo "data: {\"ok\":true}\n\n";
flush();

while (time() - $start < $maxAge) {
    // Detecta se o cliente desconectou
    if (connection_aborted()) break;

    clearstatcache(true, $triggerFile);

    if (file_exists($triggerFile)) {
        $mtime = (int) filemtime($triggerFile);
        if ($mtime > $lastMtime) {
            $lastMtime = $mtime;
            echo "event: update\n";
            echo "data: {\"ts\":{$mtime}}\n\n";
            flush();
        }
    }

    // Heartbeat a cada 20s para evitar timeout de proxy/firewall
    $elapsed = time() - $start;
    if ($elapsed > 0 && $elapsed % 20 === 0) {
        echo ": heartbeat\n\n";
        flush();
    }

    sleep(1);
}

// Instrui o EventSource a reconectar após 1s
echo "event: reconnect\n";
echo "data: {}\n\n";
flush();
