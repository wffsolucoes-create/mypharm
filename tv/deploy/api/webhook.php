<?php
/**
 * Webhook RD Station CRM — Invalidação de Cache + Trigger SSE
 *
 * Configurar no RD Station CRM:
 *   URL: https://seusite.com/tv/api/webhook.php
 *   Evento: deal_won (negócio ganho)
 *   Método: POST
 *
 * Quando um deal é marcado como ganho:
 *   1. Apaga o cache do ranking (próxima req busca dados frescos)
 *   2. Toca o arquivo sse_trigger.txt (SSE notifica todos os clientes)
 *   → React invalida a query → animações + sons disparam em <2s
 *
 * Segurança: defina RDSTATION_WEBHOOK_SECRET no .env
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

// ── Verifica secret ─────────────────────────────────────────────
$secret = $_ENV['RDSTATION_WEBHOOK_SECRET'] ?? '';
if ($secret !== '') {
    $received = $_SERVER['HTTP_X_RDSTATION_SECRET']
             ?? $_SERVER['HTTP_X_WEBHOOK_SECRET']
             ?? ($_GET['secret'] ?? '');

    if (!hash_equals($secret, $received)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

// ── Aceita apenas POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// ── Garante que o diretório de cache existe ─────────────────────
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// ── 1. Invalida o cache do ranking ──────────────────────────────
$cacheFile = CACHE_DIR . '/ranking.json';
if (file_exists($cacheFile)) {
    unlink($cacheFile);
}

// ── 2. Dispara o trigger do SSE (atualiza mtime) ─────────────────
$triggerFile = CACHE_DIR . '/sse_trigger.txt';
file_put_contents($triggerFile, (string) time());

echo json_encode(['ok' => true, 'message' => 'Cache invalidado e SSE notificado']);
