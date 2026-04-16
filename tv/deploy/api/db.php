<?php
/**
 * Conexão com o Banco de Dados MySQL via PDO.
 * Cria o banco e as tabelas automaticamente na primeira execução, se não existirem.
 */

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ranking_crm');

function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            // Conecta sem selecionar o banco primeiro, para podermos criar se não existir
            $tempPdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS);
            $tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Cria o banco de dados se não existir
            $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Reconecta agora usando o banco específico
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Recria a tabela para remover a constraint antiga de unique
            setupTables($pdo);

        } catch (PDOException $e) {
            die(json_encode(["error" => "Erro de Conexão com o Banco de Dados: " . $e->getMessage()]));
        }
    }

    return $pdo;
}

function setupTables($pdo) {
    $sql = "
    CREATE TABLE IF NOT EXISTS phusion_pedidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id VARCHAR(50) NOT NULL,
        data_aprovacao DATE NULL,
        cliente VARCHAR(255) NOT NULL,
        vendedora  VARCHAR(255) DEFAULT NULL,
        valor DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $pdo->exec($sql);
}
