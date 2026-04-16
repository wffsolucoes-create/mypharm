<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(["status" => "error", "message" => "Apenas requisições POST são permitidas."]);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(["status" => "error", "message" => "Nenhum arquivo enviado ou ocorreu um erro no upload."]);
    exit;
}

$fileTmpPath = $_FILES['file']['tmp_name'];
$fileName = $_FILES['file']['name'];
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if ($fileExtension !== 'csv') {
    header('Content-Type: application/json; charset=UTF-8');
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
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(["status" => "error", "message" => "Erro ao mover o arquivo enviado para o servidor."]);
}
