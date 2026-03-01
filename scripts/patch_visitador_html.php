<?php
/**
 * Remove blocos inline de visitador.html e insere link/script para os arquivos extraídos.
 * Executar após extract_visitador_assets.php
 */
$base = dirname(__DIR__);
$path = $base . '/visitador.html';
$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Could not read visitador.html\n");
    exit(1);
}
$lines = preg_split('/\r\n|\r|\n/', $content, -1);
$n = count($lines);
echo "Lines: $n\n";

$out = [];
// Linhas 1-18 (índices 0-17): head até antes do primeiro <style>
for ($i = 0; $i <= 17 && $i < $n; $i++) $out[] = $lines[$i];
// Inserir link para o CSS extraído
$out[] = '    <link rel="stylesheet" href="css/visitador-inline.css">';
// Pular linhas 19-958 (bloco style 1), usar linhas 959 em diante
for ($i = 958; $i < $n; $i++) {
    // Pular segundo bloco style: linhas 2125-2157 (índices 2124-2156)
    if ($i >= 2124 && $i <= 2156) continue;
    // Pular bloco script grande: linhas 2456-6045 (índices 2455-6044) incluindo </script>
    if ($i >= 2455 && $i <= 6044) {
        if ($i === 2455) {
            $out[] = '    <script src="js/visitador/visitador-main.js"></script>';
        }
        continue;
    }
    // Pular style após </html>: linhas 6049-6064 (índices 6048-6063)
    if ($i >= 6048 && $i <= 6063) continue;
    $out[] = $lines[$i];
}

$newContent = implode("\n", $out);
if (file_put_contents($path, $newContent) === false) {
    fwrite(STDERR, "Could not write visitador.html\n");
    exit(1);
}
echo "Written visitador.html (" . count($out) . " lines). Done.\n";
