<?php
/**
 * BIENENSTOCK — Gerador de PDF para certificados
 *
 * Estratégia: renderizar o GD no mesmo tamanho do editor visual (800×600 px),
 * depois escalar para A4 paisagem no PDF. Uma escala única, sem conversões de DPI.
 *
 * Editor visual:  800 × 600 px  (canvas exato)
 * GD canvas:      800 × 600 px  (1:1 com o editor — o que você vê é o que sai)
 * PDF A4 paisagem: 841.89 × 595.28 pt  (proporcional ao canvas 800×600)
 *
 * Fonte: imagettftext recebe pt. Para que Npx no editor = N pt no GD (mesmo tamanho visual):
 *   pt = px × (72/96) = px × 0.75
 * Line-height: igual ao editor → lh_px = font_px × 1.7
 *
 * GPL v3 — Carlos Eduardo (cadunico)
 */

// ─── Constantes do canvas (espelham exatamente o editor visual) ───────────────
if (!defined('CPDF_CW'))      define('CPDF_CW',      800);    // canvas width  — igual ao editor
if (!defined('CPDF_CH'))      define('CPDF_CH',      600);    // canvas height — igual ao editor
if (!defined('CPDF_BLOCK_W')) define('CPDF_BLOCK_W', 680);    // width do bloco de texto (px)
if (!defined('CPDF_PAD_X'))   define('CPDF_PAD_X',   16);     // padding-left/right do texto (px)
if (!defined('CPDF_PAD_Y'))   define('CPDF_PAD_Y',   14);     // padding-top/bottom do texto (px)
// pt = px × 0.75  (converte px @96dpi para pt tipográfico para imagettftext)
if (!defined('CPDF_PX2PT'))   define('CPDF_PX2PT',   0.75);
// line-height do editor visual
if (!defined('CPDF_LH'))      define('CPDF_LH',      1.7);

// ─── Ponto de entrada ─────────────────────────────────────────────────────────
function generate_cert_pdf_php($cert_row, $vars, $dest_path) {
    if (!$cert_row) return '';

    $pos_data = json_decode($cert_row['positions'] ?? '{}', true) ?: [];
    $bg       = $cert_row['bg_image'] ?? '';

    // Texto do editor visual (positions.main.text), fallback template_html
    $raw = isset($pos_data['main']['text'])
        ? $pos_data['main']['text']
        : ($cert_row['template_html'] ?? '');

    // Substituir variáveis
    foreach ($vars as $k => $v) {
        $raw = str_replace('{' . $k . '}', (string)($v ?? ''), $raw);
    }

    // Posição do bloco — em px do canvas (direto, sem escala)
    $bx = (float)($pos_data['main']['x'] ?? 50);
    $by = (float)($pos_data['main']['y'] ?? 150);

    // Defaults de cor/tamanho/alinhamento
    $defaults = cpdf_defaults();

    // Parsear conteúdo em linhas
    $lines = cpdf_parse_content($raw, $defaults);

    if (extension_loaded('gd') && function_exists('imagecreatetruecolor')) {
        $ok = cpdf_render_gd($bg, $lines, $bx, $by, $dest_path);
        if ($ok) return $dest_path;
    }

    // Fallback texto puro
    $plain = implode("\n", array_map(fn($l) => $l['text'], $lines));
    return cpdf_text_only($plain, $dest_path, $defaults['color']) ? $dest_path : '';
}

// ─── Defaults que espelham o editor visual ────────────────────────────────────
function cpdf_defaults() {
    return [
        'color' => '222222',  // cor padrão do body (preto)
        'size'  => 18.0,      // font-size:18px do editor
        'align' => 'left',    // padrão do contenteditable
        'bold'  => false,
    ];
}

// ─── Parsear conteúdo ─────────────────────────────────────────────────────────
function cpdf_parse_content($content, $defaults) {
    if (strpos($content, '<') !== false) {
        return cpdf_parse_html($content, $defaults);
    }
    return cpdf_parse_plain($content, $defaults);
}

// ─── Texto plano → linhas ─────────────────────────────────────────────────────
function cpdf_parse_plain($text, $def) {
    $text  = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = [];
    foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
        $lines[] = ['text' => $line, 'align' => $def['align'],
                    'size' => $def['size'], 'color' => $def['color'],
                    'bold' => $def['bold'], 'italic' => false];
    }
    return $lines;
}

// ─── HTML do contenteditable → linhas ────────────────────────────────────────
function cpdf_parse_html($html, $def) {
    $lines = [];

    // Usar DOMDocument se disponível — parse confiável do HTML do browser
    if (class_exists('DOMDocument')) {
        return cpdf_parse_html_dom($html, $def);
    }

    // Fallback regex
    $html = preg_replace('/<br\s*\/?>/i', "\x00", $html);

    preg_match_all('/(<(?:div|p)[^>]*>)(.*?)(?=<\/?(?:div|p)[^>]*>|$)/is',
        $html, $blocks, PREG_SET_ORDER);

    if (empty($blocks)) {
        return cpdf_parse_plain(strip_tags(str_replace("\x00", "\n", $html)), $def);
    }

    foreach ($blocks as $b) {
        $tag   = $b[1];
        $inner = $b[2];

        $stripped = trim(strip_tags(str_replace("\x00", '', $inner)));
        $has_br   = strpos($inner, "\x00") !== false;

        if ($stripped === '' && !$has_br) {
            $lines[] = ['text' => '', 'align' => $def['align'],
                        'size' => $def['size'], 'color' => $def['color'],
                        'bold' => $def['bold'], 'italic' => false];
            continue;
        }

        $align = cpdf_detect_align($tag . $inner, $def['align']);
        $color  = cpdf_extract_color($inner) ?? $def['color'];
        $size   = cpdf_extract_size($inner)  ?? $def['size'];
        $bold   = $def['bold']
            || (bool)preg_match('/<(?:b|strong)(?:\s[^>]*)?>/', $inner)
            || (bool)preg_match('/font-weight\s*:\s*(bold|[6-9]\d\d)/i', $inner);
        $italic = (bool)preg_match('/<(?:i|em)(?:\s[^>]*)?>/', $inner);

        $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (preg_split('/\x00/', $text) as $chunk) {
            $lines[] = ['text' => $chunk, 'align' => $align, 'size' => $size,
                        'color' => $color, 'bold' => $bold, 'italic' => $italic];
        }
    }
    return $lines ?: cpdf_parse_plain('', $def);
}

// ─── Parse via DOMDocument (confiável) ───────────────────────────────────────
function cpdf_parse_html_dom($html, $def) {
    $lines = [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Forçar UTF-8 e envolver em div para evitar problemas de root
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $root = $dom->getElementById('__root__');
    if (!$root) {
        return cpdf_parse_plain(strip_tags($html), $def);
    }

    // Percorrer todos os nós filhos diretos (cada div/p é uma linha)
    foreach ($root->childNodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $t = trim($node->textContent);
            if ($t !== '') {
                $lines[] = ['text' => $t, 'align' => $def['align'],
                            'size' => $def['size'], 'color' => $def['color'],
                            'bold' => $def['bold'], 'italic' => false];
            }
            continue;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) continue;

        // Serializar o nó para extrair o HTML interno e atributos
        $node_html = $dom->saveHTML($node);

        // Detectar alinhamento no nó e em todos os seus descendentes
        $align = cpdf_detect_align($node_html, $def['align']);

        // Extrair texto, cor, tamanho, bold, italic
        $inner_html = '';
        foreach ($node->childNodes as $child) {
            $inner_html .= $dom->saveHTML($child);
        }

        $text   = $node->textContent;
        $color  = cpdf_extract_color($inner_html) ?? $def['color'];
        $size   = cpdf_extract_size($inner_html)  ?? $def['size'];
        $bold   = $def['bold']
            || (bool)preg_match('/<(?:b|strong)(?:\s[^>]*)?>/', $inner_html)
            || (bool)preg_match('/font-weight\s*:\s*(bold|[6-9]\d\d)/i', $inner_html);
        $italic = (bool)preg_match('/<(?:i|em)(?:\s[^>]*)?>/', $inner_html);

        // Tratar <br> como quebra de linha dentro do bloco
        $text_decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (trim($text_decoded) === '' &&
            strpos($inner_html, '<br') === false &&
            strpos($inner_html, '<BR') === false) {
            $lines[] = ['text' => '', 'align' => $align,
                        'size' => $size, 'color' => $color,
                        'bold' => $bold, 'italic' => $italic];
            continue;
        }

        // Dividir por <br>
        $parts = preg_split('/<br\s*\/?>/i', $inner_html);
        foreach ($parts as $part) {
            $t = html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines[] = ['text' => $t, 'align' => $align,
                        'size' => $size, 'color' => $color,
                        'bold' => $bold, 'italic' => $italic];
        }
    }

    return $lines ?: cpdf_parse_plain('', $def);
}

// ─── Detectar alinhamento em qualquer trecho de HTML ─────────────────────────
function cpdf_detect_align($html, $default = 'left') {
    // style="text-align: center/left/right"
    if (preg_match('/text-align\s*:\s*(left|center|right)/i', $html, $m))
        return strtolower($m[1]);
    // atributo align="center" (HTML legado)
    if (preg_match('/\balign\s*=\s*["\']?(left|center|right)["\']?/i', $html, $m))
        return strtolower($m[1]);
    return $default;
}

// ─── Extrair cor de HTML interno ──────────────────────────────────────────────
function cpdf_extract_color($html) {
    $d = preg_replace('/(?:background|border|outline)-color\s*:[^;}"\']+[;}"\']/i', '', $html);
    if (preg_match('/\bcolor\s*=\s*["\']?\s*#([0-9a-fA-F]{3,6})/i', $d, $m))
        return strlen($m[1])===3
            ? $m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2]
            : strtolower($m[1]);
    if (preg_match('/(?<![a-z-])color\s*:\s*#([0-9a-fA-F]{3,6})\b/i', $d, $m))
        return strlen($m[1])===3
            ? $m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2]
            : strtolower($m[1]);
    if (preg_match('/(?<![a-z-])color\s*:\s*rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i', $d, $m))
        return sprintf('%02x%02x%02x', (int)$m[1], (int)$m[2], (int)$m[3]);
    return null;
}

// ─── Extrair tamanho de fonte de HTML interno ─────────────────────────────────
function cpdf_extract_size($html) {
    if (preg_match('/font-size\s*:\s*(\d+(?:\.\d+)?)px/i', $html, $m))
        return (float)$m[1];
    return null;
}

// ─── Renderizar GD 800×600 → JPEG → PDF ──────────────────────────────────────
// O canvas GD tem o mesmo tamanho do editor visual: 800×600 px.
// Fontes: Npx no editor = N×0.75 pt em imagettftext (px@96dpi → pt@72dpi).
// Line-height: font_px × 1.7  (igual ao CSS do editor).
function cpdf_render_gd($bg_rel, $lines, $bx, $by, $dest_path) {
    $W = CPDF_CW;  // 800
    $H = CPDF_CH;  // 600

    $img = @imagecreatetruecolor($W, $H);
    if (!$img) return false;
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));

    // Fundo
    if ($bg_rel) {
        $bg_path = defined('BASE') ? rtrim(BASE,'/').'/'.ltrim($bg_rel,'/') : $bg_rel;
        if (file_exists($bg_path)) {
            $ext = strtolower(pathinfo($bg_path, PATHINFO_EXTENSION));
            $bgi = null;
            if ($ext==='jpg'||$ext==='jpeg') $bgi = @imagecreatefromjpeg($bg_path);
            elseif ($ext==='png')  $bgi = @imagecreatefrompng($bg_path);
            elseif ($ext==='gif')  $bgi = @imagecreatefromgif($bg_path);
            elseif ($ext==='webp' && function_exists('imagecreatefromwebp'))
                $bgi = @imagecreatefromwebp($bg_path);
            if ($bgi) {
                $bw=$W; $bh=$H;
                // cover: escalar para cobrir 800×600
                $sw=imagesx($bgi); $sh=imagesy($bgi);
                $r=max($W/$sw,$H/$sh);
                $nw=(int)round($sw*$r); $nh=(int)round($sh*$r);
                $ox=(int)round(($W-$nw)/2); $oy=(int)round(($H-$nh)/2);
                imagecopyresampled($img,$bgi,$ox,$oy,0,0,$nw,$nh,$sw,$sh);
                imagedestroy($bgi);
            }
        }
    }

    $font_r = cpdf_font();
    $font_b = cpdf_font_bold();
    $shadow = imagecolorallocatealpha($img, 0, 0, 0, 100);

    // Largura do bloco de texto em px (igual ao editor: 680px, com padding interno 16px)
    $block_w = CPDF_BLOCK_W;          // 680 px — largura total do bloco
    $text_w  = $block_w - CPDF_PAD_X * 2;  // 648 px — área útil de texto
    $text_x  = (int)round($bx + CPDF_PAD_X);
    $cur_y   = (int)round($by + CPDF_PAD_Y);

    foreach ($lines as $line) {
        $text  = $line['text'];
        $align = $line['align'];
        $bold  = $line['bold'];
        $px    = (float)($line['size'] ?? 18.0);   // tamanho em px (igual ao editor)
        $color = $line['color'] ?? '222222';

        // px → pt para imagettftext:  pt = px × 0.75  (96dpi → 72dpi)
        $pt  = max(1.0, $px * CPDF_PX2PT);

        // line-height em px (igual ao CSS: font-size × 1.7)
        $lh  = (int)round($px * CPDF_LH);

        if (trim($text) === '') {
            $cur_y += (int)round($lh * 0.5);
            continue;
        }

        $col = cpdf_gd_color($img, $color);
        $fnt = ($bold && $font_b) ? $font_b : $font_r;

        // Word wrap na largura útil do bloco
        $wrapped = cpdf_wrap($text, $text_w, $pt, $fnt);

        foreach ($wrapped as $wl) {
            if (trim($wl) === '') { $cur_y += (int)round($lh * 0.5); continue; }

            if ($fnt && function_exists('imagettftext')) {
                $bb = @imagettfbbox($pt, 0, $fnt, $wl) ?: [0,0,0,0,0,0,0,0];
                $tw = abs($bb[4]-$bb[0]);
                $th = abs($bb[5]-$bb[1]);

                $lx = $text_x;
                if ($align==='center') $lx = (int)round($text_x + ($text_w-$tw)/2);
                if ($align==='right')  $lx = $text_x + $text_w - $tw;

                $ty = $cur_y + $th;
                imagettftext($img,$pt,0,$lx+1,$ty+1,$shadow,$fnt,$wl);
                imagettftext($img,$pt,0,$lx,$ty,$col,$fnt,$wl);
            } else {
                $gsz = max(1,min(5,(int)round($px/8)));
                $tw  = imagefontwidth($gsz)*mb_strlen($wl);
                $lx  = $text_x;
                if ($align==='center') $lx = (int)round($text_x+($text_w-$tw)/2);
                if ($align==='right')  $lx = $text_x+$text_w-$tw;
                imagestring($img,$gsz,$lx,$cur_y,$wl,$col);
            }
            $cur_y += $lh;
        }
    }

    // Salvar JPEG temporário
    $tmp = cpdf_tmp().'/cpdf_'.uniqid().'.jpg';
    $ok  = imagejpeg($img, $tmp, 95);
    imagedestroy($img);
    if (!$ok) return false;

    $ret = cpdf_jpeg_to_pdf($tmp, $dest_path);
    @unlink($tmp);
    return $ret;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function cpdf_gd_color($img, $hex) {
    $hex = ltrim($hex ?? '222222', '#');
    if (strlen($hex)===3) $hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex)!==6) $hex='222222';
    return imagecolorallocate($img,
        hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)));
}

function cpdf_wrap($text, $max_px, $pt, $font) {
    $words = preg_split('/(\s+)/', trim($text), -1, PREG_SPLIT_DELIM_CAPTURE);
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $test = $cur.$w;
        if ($cur!=='' && cpdf_text_w($test,$pt,$font) > $max_px) {
            $lines[] = rtrim($cur); $cur = ltrim($w);
        } else { $cur = $test; }
    }
    if (rtrim($cur)!=='') $lines[] = rtrim($cur);
    return $lines ?: [''];
}

function cpdf_text_w($text, $pt, $font) {
    if ($font && function_exists('imagettfbbox')) {
        $b = @imagettfbbox($pt, 0, $font, $text);
        if ($b) return abs($b[4]-$b[0]);
    }
    return (int)(mb_strlen($text)*$pt*0.6);
}

function cpdf_font() {
    static $f=false; if ($f!==false) return $f;
    foreach ([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        '/usr/share/fonts/truetype/ubuntu/Ubuntu-R.ttf',
        '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/usr/share/fonts/bitstream-vera/Vera.ttf',
    ] as $p) { if (file_exists($p)&&is_readable($p)) return ($f=$p); }
    return ($f=null);
}

function cpdf_font_bold() {
    static $f=false; if ($f!==false) return $f;
    foreach ([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        '/usr/share/fonts/truetype/ubuntu/Ubuntu-B.ttf',
        '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    ] as $p) { if (file_exists($p)&&is_readable($p)) return ($f=$p); }
    return ($f=null);
}

function cpdf_tmp() {
    $d = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
    if (is_writable($d)) return rtrim($d,'/');
    if (defined('BASE')) {
        $d2=BASE.'/uploads/certs';
        if (!is_dir($d2)) @mkdir($d2,0775,true);
        if (is_writable($d2)) return $d2;
    }
    return rtrim(sys_get_temp_dir(),'/');
}

// ─── JPEG 800×600 → PDF A4 paisagem ──────────────────────────────────────────
// O PDF estica a imagem 800×600 para preencher 841.89×595.28 pt (A4 paisagem).
// A proporção 800:600 = 4:3, enquanto A4 paisagem é ~841:595 ≈ 1.414:1.
// Para preservar proporção sem distorção, centralizar com letterbox.
function cpdf_jpeg_to_pdf($jpg, $dest) {
    $data = @file_get_contents($jpg);
    if (!$data) return false;
    $sz = @getimagesize($jpg);
    $iw = $sz ? $sz[0] : CPDF_CW;
    $ih = $sz ? $sz[1] : CPDF_CH;

    // A4 paisagem em pt
    $pw = 841.89; $ph = 595.28;

    // Calcular posição para preencher sem distorcer (cover)
    $scale = max($pw/$iw, $ph/$ih);
    $dw    = round($iw*$scale, 4);
    $dh    = round($ih*$scale, 4);
    $dx    = round(($pw-$dw)/2, 4);
    $dy    = round(($ph-$dh)/2, 4);

    $jl = strlen($data);
    $b  = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
    $o  = [];
    $o[1]=strlen($b); $b.="1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
    $o[2]=strlen($b); $b.="2 0 obj\n<</Type/Pages/Kids[4 0 R]/Count 1>>\nendobj\n";
    $o[3]=strlen($b); $b.="3 0 obj\n<</Type/XObject/Subtype/Image/Width $iw/Height $ih"
        ."/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter/DCTDecode/Length $jl>>\nstream\n"
        .$data."\nendstream\nendobj\n";
    $o[4]=strlen($b); $b.="4 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 $pw $ph]"
        ."/Resources<</XObject<</Img 3 0 R>>>>/Contents 5 0 R>>\nendobj\n";
    // Posicionar imagem: cm = [dw 0 0 dh dx dy]
    $s  = "q\n$dw 0 0 $dh $dx $dy cm\n/Img Do\nQ\n";
    $sl = strlen($s);
    $o[5]=strlen($b); $b.="5 0 obj\n<</Length $sl>>\nstream\n{$s}\nendstream\nendobj\n";
    $xr=strlen($b);
    $b.="xref\n0 6\n0000000000 65535 f \n";
    for($i=1;$i<=5;$i++) $b.=sprintf("%010d 00000 n \n",$o[$i]);
    $b.="trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n$xr\n%%EOF\n";
    return file_put_contents($dest,$b)!==false;
}

// ─── Fallback PDF texto puro ──────────────────────────────────────────────────
function cpdf_text_only($text, $dest, $color='222222') {
    $r=hexdec(substr($color,0,2))/255;
    $g=hexdec(substr($color,2,2))/255;
    $bl=hexdec(substr($color,4,2))/255;
    $pw=841.89; $ph=595.28; $fs=12; $lh=$fs*1.7; $x=50; $y=$ph-60;
    $lines=preg_split('/\r?\n/',$text);
    $op="BT\n/F1 $fs Tf\n".sprintf("%.3f %.3f %.3f rg\n",$r,$g,$bl);
    foreach($lines as $l){
        $l=trim($l); if($l===''){$y-=$lh*0.5;continue;}
        $op.=sprintf("1 0 0 1 %.2f %.2f Tm\n(%s) Tj\n",$x,$y,cpdf_win($l));
        $y-=$lh;
    }
    $op.="ET\n"; $sl=strlen($op);
    $b="%PDF-1.4\n%\xe2\xe3\xcf\xd3\n"; $o=[];
    $o[1]=strlen($b);$b.="1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n";
    $o[2]=strlen($b);$b.="2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n";
    $o[3]=strlen($b);$b.="3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 $pw $ph]"
        ."/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>\nendobj\n";
    $o[4]=strlen($b);$b.="4 0 obj\n<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>\nendobj\n";
    $o[5]=strlen($b);$b.="5 0 obj\n<</Length $sl>>\nstream\n$op\nendstream\nendobj\n";
    $xr=strlen($b);$b.="xref\n0 6\n0000000000 65535 f \n";
    for($i=1;$i<=5;$i++)$b.=sprintf("%010d 00000 n \n",$o[$i]);
    $b.="trailer\n<</Size 6/Root 1 0 R>>\nstartxref\n$xr\n%%EOF\n";
    return file_put_contents($dest,$b)!==false;
}

function cpdf_win($t) {
    static $m=null;
    if(!$m)$m=['ã'=>"\xe3",'â'=>"\xe2",'á'=>"\xe1",'à'=>"\xe0",'ä'=>"\xe4",
        'Ã'=>"\xc3",'Â'=>"\xc2",'Á'=>"\xc1",'ç'=>"\xe7",'Ç'=>"\xc7",
        'é'=>"\xe9",'ê'=>"\xea",'è'=>"\xe8",'É'=>"\xc9",'Ê'=>"\xca",
        'í'=>"\xed",'î'=>"\xee",'Í'=>"\xcd",'ó'=>"\xf3",'ô'=>"\xf4",'õ'=>"\xf5",
        'Ó'=>"\xd3",'Ô'=>"\xd4",'Õ'=>"\xd5",'ú'=>"\xfa",'û'=>"\xfb",'Ú'=>"\xda",
        '°'=>"\xb0",'ª'=>"\xaa",'º'=>"\xba"];
    $t=strtr($t,$m);
    $t=preg_replace('/[^\x20-\xff]/','', $t);
    return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$t);
}
