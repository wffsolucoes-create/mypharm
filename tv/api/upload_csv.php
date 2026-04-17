<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

require_once dirname(__DIR__, 2) . '/config.php';

if (!isset($_SESSION['user_id']) || (($_SESSION['user_tipo'] ?? '') !== 'admin')) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Acesso negado."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Apenas requisições POST são permitidas."]);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(["status" => "error", "message" => "Nenhum arquivo enviado ou ocorreu um erro no upload."]);
    exit;
}

$fileTmpPath = $_FILES['file']['tmp_name'];
$fileName = $_FILES['file']['name'];
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($fileExtension !== 'csv') {
    echo json_encode(["status" => "error", "message" => "Apenas arquivos CSV são permitidos."]);
    exit;
}

// Destino do arquivo
$destPath = __DIR__ . '/../Relatório de Gestão de Pedidos.csv';

if(move_uploaded_file($fileTmpPath, $destPath)) {
    // Em vez de importar tudo e imprimir 2 JSONs, eu incluo o import_csv e deixo ele cuidar da resposta!
    // Ele não sabe que foi via upload, só vai ler o arquivo salvo e atualizar o banco.
    require_once __DIR__ . '/import_csv.php';
} else {
    echo json_encode(["status" => "error", "message" => "Erro ao mover o arquivo enviado para o servidor."]);
}
