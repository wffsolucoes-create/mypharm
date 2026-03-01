<?php
/**
 * One-time script: extract inline CSS and JS from visitador.html (from git HEAD) into separate files.
 * Run from project root: php scripts/extract_visitador_assets.php
 */
$base = dirname(__DIR__);
// Use committed version so we have the full inline blocks
$html = @shell_exec('cd ' . escapeshellarg($base) . ' && git show HEAD:visitador.html 2>nul');
if (empty($html)) {
    $html = file_get_contents($base . '/visitador.html');
}
if (empty($html)) {
    fwrite(STDERR, "Could not read visitador.html\n");
    exit(1);
}

// Extract first large <style>...</style> block (only content between tags)
if (preg_match('/<style>\s*(.*?)\s*<\/style>/s', $html, $m)) {
    $cssContent = trim($m[1]);
} else {
    $cssContent = '';
}
// Append second style block (modal analise prescritor) if present
if (preg_match_all('/<style>\s*(.*?)\s*<\/style>/s', $html, $all)) {
    // First match is already in $cssContent; add other style blocks that look like modal/CSS (skip tiny ones like body{visibility})
    foreach ($all[1] as $i => $block) {
        $block = trim($block);
        if (strlen($block) > 200 && strpos($block, 'modal-analise-prescritor') !== false) {
            $cssContent .= "\n\n" . $block;
            break;
        }
    }
}
// Append trailing style block (after </html>) if present
if (preg_match('/<\/html>\s*<style>\s*(.*?)\s*<\/style>/s', $html, $m)) {
    $cssContent .= "\n\n" . trim($m[1]);
}

$cssFile = $base . '/css/visitador-inline.css';
if (file_put_contents($cssFile, $cssContent) === false) {
    fwrite(STDERR, "Could not write visitador-inline.css\n");
    exit(1);
}
echo "Written " . strlen($cssContent) . " bytes to css/visitador-inline.css\n";

// Extract the main <script> block (the big one, after app.js / prescritores-feature.js)
if (preg_match('/<script src="js\/visitador\/prescritores-feature\.js"><\/script>\s*<script>\s*(.*?)\s*<\/script>/s', $html, $m)) {
    $jsContent = trim($m[1]);
} elseif (preg_match('/<script>\s*(.*?)<\/script>\s*<\/body>/s', $html, $m)) {
    // Fallback: last script block before </body>
    $jsContent = trim($m[1]);
} else {
    $jsContent = '';
}
$jsDir = $base . '/js/visitador';
if (!is_dir($jsDir)) {
    mkdir($jsDir, 0755, true);
}
$jsFile = $jsDir . '/visitador-main.js';
if (file_put_contents($jsFile, $jsContent) === false) {
    fwrite(STDERR, "Could not write js/visitador/visitador-main.js\n");
    exit(1);
}
echo "Written " . strlen($jsContent) . " bytes to js/visitador/visitador-main.js\n";
echo "Done.\n";
