<?php
/**
 * API — Premiação (Awards)
 *
 * GET  → retorna o JSON com as imagens configuradas
 * POST → faz upload das imagens de premiação (1º, 2º, 3º lugar)
 *
 * As imagens são salvas em /mypharm/tv/uploads/premiacao/
 * e os paths são persistidos em premiacao.json
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uploadDir  = __DIR__ . '/../uploads/premiacao/';
$configFile = __DIR__ . '/../uploads/premiacao/config.json';

// Garante que o diretório existe
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── GET: retorna configuração atual ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $defaults = [
        '1' => ['url' => '', 'label' => '1º Lugar'],
        '2' => ['url' => '', 'label' => '2º Lugar'],
        '3' => ['url' => '', 'label' => '3º Lugar'],
    ];

    if (file_exists($configFile)) {
        $saved = json_decode(file_get_contents($configFile), true);
        if (is_array($saved)) {
            $defaults = array_merge($defaults, $saved);
        }
    }

    echo json_encode(['success' => true, 'data' => $defaults]);
    exit;
}

// ── POST: upload de imagem ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $position = isset($_POST['position']) ? $_POST['position'] : null;

    if (!in_array($position, ['1', '2', '3'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Posição inválida. Use 1, 2 ou 3.']);
        exit;
    }

    // Ação de remover
    if (isset($_POST['action']) && $_POST['action'] === 'remove') {
        $config = [];
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true) ?: [];
        }

        // Apaga o arquivo se existir
        if (isset($config[$position]['url']) && $config[$position]['url']) {
            $oldFile = __DIR__ . '/../' . ltrim($config[$position]['url'], '/');
            if (file_exists($oldFile)) {
                @unlink($oldFile);
            }
        }

        $labels = ['1' => '1º Lugar', '2' => '2º Lugar', '3' => '3º Lugar'];
        $config[$position] = ['url' => '', 'label' => $labels[$position]];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        echo json_encode(['success' => true, 'message' => 'Premiação removida.']);
        exit;
    }

    // Upload de arquivo
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nenhuma imagem enviada ou erro no upload.']);
        exit;
    }

    $file = $_FILES['image'];
    $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não permitido. Use PNG, JPG, GIF, WEBP ou SVG.']);
        exit;
    }

    // Limite de 5 MB
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Arquivo muito grande. Máximo 5MB.']);
        exit;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png';
    $filename = 'premio_' . $position . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar o arquivo.']);
        exit;
    }

    // Atualiza config
    $config = [];
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true) ?: [];
    }

    // Remove arquivo antigo
    if (isset($config[$position]['url']) && $config[$position]['url']) {
        $oldFile = __DIR__ . '/../' . ltrim($config[$position]['url'], '/');
        if (file_exists($oldFile)) {
            @unlink($oldFile);
        }
    }

    $relativeUrl = 'uploads/premiacao/' . $filename;
    $labels = ['1' => '1º Lugar', '2' => '2º Lugar', '3' => '3º Lugar'];
    $label = isset($_POST['label']) && $_POST['label'] ? $_POST['label'] : $labels[$position];

    $config[$position] = [
        'url'   => $relativeUrl,
        'label' => $label,
    ];

    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode([
        'success' => true,
        'message' => 'Imagem salva com sucesso!',
        'data'    => $config[$position],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
