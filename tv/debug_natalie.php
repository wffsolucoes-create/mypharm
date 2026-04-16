<?php
require_once __DIR__ . '/api/db.php';
$pdo = getDB();
$stmt = $pdo->query("SELECT * FROM phusion_pedidos WHERE cliente LIKE '%Natalie%' OR pedido_id = '61732'");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
