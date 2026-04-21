<?php
$base = dirname(__DIR__);
$path = $base . '/visitador.html';
$html = file_get_contents($path);
$replacement = '    <script src="js/visitador/visitador-main.js"></script>';
// Replace the block that starts with <script> after prescritores-feature.js and ends before </body>
$pattern = '@(\s*<script src="js/visitador/prescritores-feature\.js"></script>\s*)<script>\s.*?\s</script>(\s*</body>)@s';
$newHtml = preg_replace($pattern, '$1' . $replacement . '$2', $html, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "Replace count: $count (expected 1)\n");
    exit(1);
}
file_put_contents($path, $newHtml);
echo "Replaced inline script with script src. New size: " . strlen($newHtml) . " bytes\n";
