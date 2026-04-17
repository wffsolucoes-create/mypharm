<?php
/**
 * Entrada pública para exibição do ranking em TV sem autenticação.
 * Mantém o mesmo frontend do index.html para facilitar deploy e operação.
 */

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$indexPath = __DIR__ . DIRECTORY_SEPARATOR . 'index.html';
if (!file_exists($indexPath)) {
    http_response_code(500);
    echo 'Arquivo tv/index.html não encontrado.';
    exit;
}

readfile($indexPath);
