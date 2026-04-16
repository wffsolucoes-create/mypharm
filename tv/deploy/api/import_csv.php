<?php
/**
 * Script para importar os dados do CSV do Phusion para o Banco de Dados MySQL
 */
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';

try {
    $pdo = getDB();
    
    $csv_file = __DIR__ . '/../Relatório de Gestão de Pedidos.csv';
    $csv_path = file_exists($csv_file) ? $csv_file : glob(__DIR__ . '/../*Pedidos.csv')[0];

    if (!$csv_path || !file_exists($csv_path)) {
        echo json_encode(["status" => "error", "message" => "Arquivo CSV do Phusion não encontrado."]);
        exit;
    }

    $handle = fopen($csv_path, "r");
    if ($handle === FALSE) {
        echo json_encode(["status" => "error", "message" => "Não foi possível abrir o arquivo CSV."]);
        exit;
    }

    // Pula o cabeçalho (1ª linha)
    fgetcsv($handle, 10000, ";");

    // Limpa a tabela antes de importar os dados novos
    $pdo->exec("TRUNCATE TABLE phusion_pedidos");

    $imported = 0;
    
    // Preparar o statement de insert
    // Múltiplos produtos do mesmo pedido vão entrar como linhas separadas, então vamos somá-los no divergencias.php
    $stmt = $pdo->prepare("
        INSERT INTO phusion_pedidos (pedido_id, data_aprovacao, cliente, vendedora, valor)
        VALUES (:pedido_id, :data_aprovacao, :cliente, :vendedora, :valor)
    ");

    $pdo->beginTransaction();

    while (($data = fgetcsv($handle, 10000, ";")) !== FALSE) {
        if (count($data) < 15) continue;
        
        $pedido_id = trim($data[3]); // Numero do Pedido
        
        if (empty($pedido_id)) continue;

        // Formatar Data (01/04/2026 08:15:54 -> 2026-04-01)
        $dataStr = trim($data[0]); 
        $data_aprovacao = null;
        if (!empty($dataStr)) {
            $parts = explode(' ', $dataStr);
            $dateParts = explode('/', $parts[0]);
            if (count($dateParts) == 3) {
                // YYYY-MM-DD
                $data_aprovacao = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
            }
        }

        $cliente = trim(preg_replace('/\s+/', ' ', $data[14]));
        $vendedora = trim($data[17]);
        
        // Formatar valor bruto R$ 180,00 -> 180.00
        $valorStr = str_replace(['R$', '.', ' '], '', $data[13]);
        $valorStr = str_replace(',', '.', $valorStr);
        $valor = floatval($valorStr);

        $stmt->execute([
            ':pedido_id' => $pedido_id,
            ':data_aprovacao' => $data_aprovacao,
            ':cliente' => $cliente,
            ':vendedora' => $vendedora,
            ':valor' => $valor
        ]);
        
        if ($stmt->rowCount() > 0) {
            $imported++;
        }
    }

    $pdo->commit();
    fclose($handle);

    echo json_encode([
        "status" => "success",
        "message" => "Importação concluída com sucesso.",
        "novos" => $imported
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
