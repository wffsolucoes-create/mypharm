<?php
$base = dirname(__DIR__);
$oldApi = $base . '/_old_api_full.php';
$out = $base . '/api/modules/dashboard_visitador.php';
$lines = file($oldApi, FILE_IGNORE_NEW_LINES);
// Line numbers from grep: case 'visitador_dashboard' at 494, case 'admin_visitas' at 1333
$startLine = 495;  // first line of body (after "case 'visitador_dashboard':")
$endLine = 1327;   // last line: echo json_encode(...); (exclude the break; that followed)
$body = [];
for ($i = $startLine; $i <= $endLine; $i++) {
    $body[] = '    ' . str_replace("\0", '', $lines[$i - 1]);
}
$fix = implode("\n", $body);
$fix = str_replace('            break;', '            return;', $fix);
$fix = preg_replace("/'Or[^\']*amento'/", "'Orçamento'", $fix);
$fix = str_replace("'Or├ºamento'", "'Orçamento'", $fix);
$fix = str_replace('Or├ºamento', 'Orçamento', $fix);
$fix = str_replace('n├úo', 'não', $fix);
$fix = str_replace('├║nico', 'único', $fix);
$fix = str_replace('├║ltimo', 'último', $fix);
$php = "<?php\n/** Visitador dashboard: dados reais do painel (extraído de api.php) */\nfunction dashboardVisitadorDashboardReal(PDO \$pdo): void\n{\n" . $fix . "\n}\n";
$php = str_replace("\0", '', $php);
file_put_contents($out, $php);
echo "Written $out\n";
