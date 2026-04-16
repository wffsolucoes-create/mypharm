<?php
ob_start();
require 'api/divergencias.php';
$out = ob_get_clean();
file_put_contents('debug_div.json', $out);
