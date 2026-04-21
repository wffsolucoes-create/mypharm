<?php
$f = dirname(__DIR__) . '/api/modules/dashboard_visitador.php';
$c = file_get_contents($f);
$c = preg_replace("/'Or[^']*amento'/", "'Orçamento'", $c);
file_put_contents($f, $c);
echo "Fixed Orçamento in dashboard_visitador.php\n";
