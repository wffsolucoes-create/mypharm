<?php
ob_start();
require 'api/divergencias.php';
$json = ob_get_clean();
$data = json_decode($json, true);

echo "--- DIVERGENCIAS ---\n";
foreach($data['divergencias'] as $d) {
    if (stripos($d['cliente'], 'Georgia') !== false) {
        print_r($d);
    }
}
echo "--- UNMATCHED PHUSION ---\n";
foreach($data['unmatched_phusion'] as $d) {
    if (stripos($d['cliente'], 'Georgia') !== false) {
        print_r($d);
    }
}
echo "--- UNMATCHED RD ---\n";
foreach($data['unmatched_rd'] as $d) {
    if (stripos($d['titulo'], 'Georgia') !== false || stripos($d['cliente'], 'Georgia') !== false) {
        print_r($d);
    }
}
