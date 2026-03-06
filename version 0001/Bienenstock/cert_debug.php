<?php
/**
 * BIENENSTOCK — Diagnóstico de certificados (remover após uso)
 * Acesse: /cert_debug.php?secret=debug2024
 */
if (($_GET['secret'] ?? '') !== 'debug2024') { http_response_code(403); exit('Forbidden'); }
define('BASE', __DIR__);
require_once __DIR__ . '/config.php';
$db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function db() { global $db; return $db; }

echo '<pre style="font-family:monospace;font-size:12px;background:#111;color:#0f0;padding:20px;white-space:pre-wrap;word-break:break-all">';
echo "=== DIAGNÓSTICO CERTIFICADOS ===\n\n";

// 1. GD
echo "GD: " . (extension_loaded('gd') ? "SIM" : "NÃO") . "\n";
if (extension_loaded('gd')) {
    $info = gd_info();
    echo "  FreeType: " . ($info['FreeType Support'] ? "SIM" : "NÃO") . "\n";
    echo "  JPEG: "     . ($info['JPEG Support']     ? "SIM" : "NÃO") . "\n";
}

// 2. HTML completo salvo no banco
echo "\n=== HTML COMPLETO DO POSITIONS.MAIN.TEXT ===\n";
try {
    $rows = $db->query("SELECT cert_type, positions FROM certificates")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $pos  = json_decode($r['positions'] ?? '{}', true);
        $text = $pos['main']['text'] ?? '(vazio)';
        echo "\n--- cert_type: {$r['cert_type']} | x={$pos['main']['x']} y={$pos['main']['y']} ---\n";
        echo htmlspecialchars($text) . "\n";
    }
} catch (Exception $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

// 3. Linhas parseadas com alinhamento
echo "\n\n=== LINHAS PARSEADAS (alinhamento) ===\n";
try {
    require_once __DIR__ . '/cert_pdf.php';
    $rows = $db->query("SELECT * FROM certificates")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $pos  = json_decode($row['positions'] ?? '{}', true) ?: [];
        $raw  = $pos['main']['text'] ?? $row['template_html'] ?? '';
        $vars = ['nome'=>'Carlos Eduardo','nome_evento'=>'Evento Teste',
                 'data_inicio'=>'13/03/2026','data_fim'=>'14/03/2026',
                 'palestrante'=>'Carlos','atividade'=>'Palestra','horario'=>'10h'];
        foreach ($vars as $k => $v) $raw = str_replace('{'.$k.'}', $v, $raw);
        $lines = cpdf_parse_content($raw, cpdf_defaults());
        echo "\n--- {$row['cert_type']} ---\n";
        foreach ($lines as $i => $l) {
            printf("  [%02d] align=%-6s size=%4.1f  \"%s\"\n",
                $i, $l['align'], $l['size'], mb_substr($l['text'],0,60));
        }
    }
} catch (Exception $e) { echo "EXCEÇÃO: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
echo '</pre>';
