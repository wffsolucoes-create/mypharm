<?php
/**
 * De/para de profissao em prescritor_dados.
 *
 * Uso:
 *   php scripts/depara_profissoes_prescritores.php
 *     -> apenas simulacao (nao grava)
 *
 *   php scripts/depara_profissoes_prescritores.php --apply
 *     -> aplica alteracoes no banco
 */

require __DIR__ . '/../config.php';

$pdo = getConnection();

$apply = in_array('--apply', $argv ?? [], true);

function deparaNormalize(string $value): string
{
    $v = trim($value);
    $v = mb_strtolower($v, 'UTF-8');

    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    $v = strtr($v, $map);
    $v = preg_replace('/[^a-z0-9\s]/', ' ', $v) ?? $v;
    $v = preg_replace('/\s+/', ' ', $v) ?? $v;
    return trim($v);
}

function deparaTarget(string $raw): string
{
    $n = deparaNormalize($raw);
    if ($n === '') {
        return '';
    }

    $exact = [
        'medico' => 'Medico',
        'medica' => 'Medico',
        'dr' => 'Medico',
        'dra' => 'Medico',
        'nutricionista' => 'Nutricionista',
        'nutri' => 'Nutricionista',
        'fisioterapeuta' => 'Fisioterapeuta',
        'fisio' => 'Fisioterapeuta',
        'enfermeiro' => 'Enfermeiro',
        'enfermeira' => 'Enfermeiro',
        'farmaceutico' => 'Farmaceutico',
        'farmaceutica' => 'Farmaceutico',
        'dentista' => 'Dentista',
        'odontologo' => 'Dentista',
        'odontologa' => 'Dentista',
        'biomedico' => 'Biomedico',
        'biomedica' => 'Biomedico',
        'psicologo' => 'Psicologo',
        'psicologa' => 'Psicologo',
        'fonoaudiologo' => 'Fonoaudiologo',
        'fonoaudiologa' => 'Fonoaudiologo',
        'terapeuta ocupacional' => 'Terapeuta Ocupacional',
        'educador fisico' => 'Educador Fisico',
        'educadora fisica' => 'Educador Fisico',
        'veterinario' => 'Veterinario',
        'veterinaria' => 'Veterinario',
        'esteticista' => 'Esteticista',
        'outros' => 'Outros',
        'outro' => 'Outros',
    ];
    if (isset($exact[$n])) {
        return $exact[$n];
    }

    if (strpos($n, 'medic') !== false || strpos($n, 'clinico') !== false) return 'Medico';
    if (strpos($n, 'nutri') !== false) return 'Nutricionista';
    if (strpos($n, 'fisio') !== false) return 'Fisioterapeuta';
    if (strpos($n, 'enferm') !== false) return 'Enfermeiro';
    if (strpos($n, 'farmac') !== false) return 'Farmaceutico';
    if (strpos($n, 'odont') !== false || strpos($n, 'denti') !== false) return 'Dentista';
    if (strpos($n, 'biomed') !== false) return 'Biomedico';
    if (strpos($n, 'psico') !== false) return 'Psicologo';
    if (strpos($n, 'fono') !== false) return 'Fonoaudiologo';
    if (strpos($n, 'terapeuta') !== false) return 'Terapeuta Ocupacional';
    if (strpos($n, 'educador') !== false && strpos($n, 'fisic') !== false) return 'Educador Fisico';
    if (strpos($n, 'veterin') !== false) return 'Veterinario';
    if (strpos($n, 'estetic') !== false) return 'Esteticista';

    $words = array_filter(explode(' ', $n), static fn($w) => $w !== '');
    $words = array_map(static fn($w) => mb_convert_case($w, MB_CASE_TITLE, 'UTF-8'), $words);
    return implode(' ', $words);
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profissoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(255) NOT NULL,
            UNIQUE KEY uk_profissoes_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    // segue; no pior caso apenas nao sincroniza a tabela de dominio
}

$stmt = $pdo->query("
    SELECT TRIM(profissao) AS profissao, COUNT(*) AS qtd
    FROM prescritor_dados
    WHERE profissao IS NOT NULL AND TRIM(profissao) <> ''
    GROUP BY TRIM(profissao)
    ORDER BY qtd DESC, profissao ASC
");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$changes = [];
$unchanged = 0;
$totalRowsAffectedPreview = 0;

foreach ($rows as $row) {
    $orig = trim((string)($row['profissao'] ?? ''));
    if ($orig === '') continue;
    $target = deparaTarget($orig);
    $count = (int)($row['qtd'] ?? 0);
    if ($target === '' || $target === $orig) {
        $unchanged += $count;
        continue;
    }
    $changes[] = ['from' => $orig, 'to' => $target, 'qtd' => $count];
    $totalRowsAffectedPreview += $count;
}

echo "=== DE/PARA PROFISSOES (PRESCRITOR_DADOS) ===\n";
echo $apply ? "Modo: APPLY (gravando)\n" : "Modo: DRY-RUN (sem gravar)\n";
echo "Variacoes encontradas: " . count($rows) . "\n";
echo "Variacoes com ajuste: " . count($changes) . "\n";
echo "Linhas estimadas para ajuste: " . $totalRowsAffectedPreview . "\n";
echo "Linhas ja padronizadas: " . $unchanged . "\n\n";

if (empty($changes)) {
    echo "Nenhum ajuste necessario.\n";
    exit(0);
}

foreach ($changes as $c) {
    echo "- {$c['from']}  =>  {$c['to']}  ({$c['qtd']} registro(s))\n";
}
echo "\n";

if (!$apply) {
    echo "Para aplicar, execute:\n";
    echo "php scripts/depara_profissoes_prescritores.php --apply\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE prescritor_dados SET profissao = :to WHERE TRIM(profissao) = :from");
    $insProf = $pdo->prepare("INSERT IGNORE INTO profissoes (nome) VALUES (:nome)");

    $updatedRows = 0;
    foreach ($changes as $c) {
        $upd->execute(['to' => $c['to'], 'from' => $c['from']]);
        $updatedRows += (int)$upd->rowCount();
        $insProf->execute(['nome' => $c['to']]);
    }

    $pdo->commit();
    echo "Aplicacao concluida com sucesso.\n";
    echo "Linhas atualizadas: {$updatedRows}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Erro ao aplicar de/para: " . $e->getMessage() . "\n");
    exit(1);
}

