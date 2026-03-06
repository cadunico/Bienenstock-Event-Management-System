<?php
/**
 * BIENENSTOCK - Sistema de Gestão de Eventos
 * Versão: 0.001
 *
 * Desenvolvedor: Carlos Eduardo (cadunico)
 *
 * Licença: GPL v3
 *   Este programa é software livre: você pode redistribuí-lo e/ou modificá-lo
 *   sob os termos da Licença Pública Geral GNU v3, conforme publicada pela
 *   Free Software Foundation.
 *   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Conteúdo: CC BY-SA 3.0
 *   O conteúdo gerado por este sistema está licenciado sob Creative Commons
 *   Atribuição-CompartilhaIgual 3.0 (CC BY-SA 3.0).
 *   https://creativecommons.org/licenses/by-sa/3.0/
 */

// Em produção, desative as linhas abaixo ou configure via php.ini
// error_reporting(E_ALL);
// ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('display_errors', 0); // Erros não devem ser exibidos em produção
session_start();

// Config
define('BASE', __DIR__);
$config = BASE . '/config.php';

// Redirecionar para instalação se necessário
if (!file_exists($config)) {
    if (basename($_SERVER['SCRIPT_NAME']) !== 'install.php') {
        header('Location: install.php');
        exit;
    }
}

// Conectar banco
if (file_exists($config)) {
    require $config;
    try {
        $db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
    } catch (PDOException $e) {
        die('Erro DB: ' . $e->getMessage());
    }
}

// ==================== HELPERS ====================
function db() { global $db; return $db; }
function h($t) { return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8'); }

// Carrega o SMTP Mailer uma vez, no topo — fora de funções para evitar
// problemas de cwd (diretório de trabalho) com require_once dentro de funções.
if (file_exists(__DIR__ . '/smtp_mailer.php')) {
    require_once __DIR__ . '/smtp_mailer.php';
}
if (file_exists(__DIR__ . '/cert_pdf.php')) {
    require_once __DIR__ . '/cert_pdf.php';
}

// Garante que uploads/ existe, é gravável e tem .htaccess de segurança.
// Retorna o caminho absoluto da pasta ou '' em caso de falha.
function ensure_uploads_dir() {
    $dir = BASE . '/uploads/';
    if (!is_dir($dir)) {
        // Tenta criar com permissões progressivamente mais abertas
        @mkdir($dir, 0775, true) || @mkdir($dir, 0777, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
        if (!is_writable($dir)) { @chmod($dir, 0777); }
    }
    // Cria .htaccess de segurança se não existir
    $ht = $dir . '.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht,
            "<FilesMatch \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n" .
            "    Order Deny,Allow\n    Deny from all\n</FilesMatch>\n"
        );
    }
    return (is_dir($dir) && is_writable($dir)) ? $dir : '';
}
function flash($k, $v = null) {
    if ($v === null) {
        $r = $_SESSION['flash_' . $k] ?? null;
        unset($_SESSION['flash_' . $k]);
        return $r;
    }
    $_SESSION['flash_' . $k] = $v;
}
function logged() { return isset($_SESSION['user_id']); }
function go($url) { header("Location: $url"); exit; }
function qr_key() { return bin2hex(random_bytes(16)); }

// ── Busca configurações de email SEMPRE do banco (sem cache estático) ──────
function get_settings_email() {
    try {
        $rows = db()->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { $rows = array(); }
    $from = trim($rows['from_email'] ?? '');
    if (empty($from)) {
        try {
            $r = db()->query("SELECT email FROM users ORDER BY id ASC LIMIT 1")->fetch();
            $from = $r ? trim($r['email']) : '';
        } catch (Exception $e) { $from = ''; }
    }
    return array(
        'site_name'   => trim($rows['site_name']  ?? 'Bienenstock'),
        'from_email'  => $from,
        'admin_email' => $from,
        'smtp_host'   => trim($rows['smtp_host']  ?? ''),
        'smtp_port'   => trim($rows['smtp_port']  ?? '587'),
        'smtp_user'   => trim($rows['smtp_user']  ?? ''),
        'smtp_pass'   => trim($rows['smtp_pass']  ?? ''),
        'smtp_enc'    => trim($rows['smtp_enc']   ?? 'auto'),
    );
}

// ── Envia email via SMTP (quando configurado) ou mail() como fallback ──────
function send_email($to, $subject, $body) {
    $to = trim($to ?? '');
    if (empty($to)) return false;
    $s    = get_settings_email();
    $from = !empty($s['from_email']) ? $s['from_email'] : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // SMTP configurado → SmtpMailer
    if (!empty($s['smtp_host']) && !empty($s['smtp_user']) && !empty($s['smtp_pass'])) {
        $m             = new SmtpMailer();
        $m->host       = $s['smtp_host'];
        $m->port       = (int)($s['smtp_port'] ?: 587);
        $m->username   = $s['smtp_user'];
        $m->password   = $s['smtp_pass'];
        $m->encryption = $s['smtp_enc'] ?: 'auto';
        $m->from       = $from;
        $m->from_name  = $s['site_name'];
        return $m->send_mail($to, $subject, $body);
    }

    // Fallback: mail() nativo
    $enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $hdr = "MIME-Version: 1.0\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n"
         . "From: =?UTF-8?B?" . base64_encode($s['site_name']) . "?= <{$from}>\r\n"
         . "Reply-To: {$from}\r\n"
         . "X-Mailer: Bienenstock/0.001\r\n";
    $ok = @mail($to, $enc, $body, $hdr);
    if (!$ok) error_log("[Bienenstock] mail() falhou para: {$to} | {$subject}");
    return $ok;
}

// ── Envio com PDF em anexo (usa SmtpMailer diretamente) ───────────────────
function send_email_with_pdf($to, $subject, $html_body, $pdf_path, $pdf_filename) {
    $to = trim($to ?? '');
    if (empty($to)) return false;
    $s    = get_settings_email();
    $from = !empty($s['from_email']) ? $s['from_email'] : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    if (!empty($s['smtp_host']) && !empty($s['smtp_user']) && !empty($s['smtp_pass'])) {
        $m             = new SmtpMailer();
        $m->host       = $s['smtp_host'];
        $m->port       = (int)($s['smtp_port'] ?: 587);
        $m->username   = $s['smtp_user'];
        $m->password   = $s['smtp_pass'];
        $m->encryption = $s['smtp_enc'] ?: 'auto';
        $m->from       = $from;
        $m->from_name  = $s['site_name'];
        if ($pdf_path && file_exists($pdf_path)) {
            $m->attach_file($pdf_path, $pdf_filename);
        }
        return $m->send_mail($to, $subject, $html_body);
    }

    // Fallback mail() nativo — sem anexo, avisa por texto
    $html_body .= '<p style="color:#888;font-size:11px;margin-top:20px">'
                . '(O certificado em PDF requer configuração SMTP. Configure em Configurações → SMTP.)</p>';
    $enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
         . "From: =?UTF-8?B?" . base64_encode($s['site_name']) . "?= <{$from}>\r\n"
         . "Reply-To: {$from}\r\nX-Mailer: Bienenstock/0.001\r\n";
    return @mail($to, $enc, $html_body, $hdr);
}

// ── Notifica o administrador ───────────────────────────────────────────────
function notify_admin($subject, $body) {
    $s = get_settings_email();
    if (empty($s['admin_email'])) {
        error_log('[Bienenstock] notify_admin: admin_email não configurado — assunto: ' . $subject);
        return false;
    }
    $ok = send_email($s['admin_email'], $subject, $body);
    if (!$ok) {
        error_log('[Bienenstock] notify_admin FALHOU para: ' . $s['admin_email'] . ' | ' . $subject);
    }
    return $ok;
}

// ── QR Code via API pública qrserver.com ──────────────────────────────────
function qr_image_tag($data, $size = 200) {
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
         . '&format=png&qzone=2&data=' . rawurlencode($data);
    return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" '
         . 'width="' . $size . '" height="' . $size . '" alt="QR Code" '
         . 'style="display:block;margin:10px auto;border:4px solid #fff">';
}

// ── Template HTML de email ─────────────────────────────────────────────────
function email_wrap($titulo_hd, $conteudo, $site_name) {
    $hs = htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8');
    $ht = $titulo_hd; // título já é texto seguro em UTF-8
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
    . 'body{margin:0;padding:0;background:#f0f0f0;font-family:Arial,Helvetica,sans-serif}'
    . '.wrap{max-width:540px;margin:20px auto;background:#fff;border:1px solid #ddd}'
    . '.hd{background:#2c2c2c;padding:16px 22px}'
    . '.hd h1{color:#fff;font-size:15px;margin:0;font-weight:600}'
    . '.hd p{color:#888;font-size:11px;margin:3px 0 0}'
    . '.bd{padding:22px}'
    . '.bd h2{font-size:14px;color:#111;margin:0 0 12px}'
    . '.bd p{font-size:13px;color:#444;margin:0 0 12px;line-height:1.5}'
    . 'table.info{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px}'
    . 'table.info td{padding:7px 10px;border:1px solid #e0e0e0}'
    . 'table.info td:first-child{background:#f7f7f7;font-weight:600;width:38%}'
    . '.qrbox{text-align:center;padding:16px;background:#f7f7f7;border:1px solid #e0e0e0;margin-bottom:14px}'
    . '.qrbox .lbl{font-size:11px;font-weight:600;color:#333;text-transform:uppercase;letter-spacing:.5px;margin:0 0 4px}'
    . '.qrbox .sub{font-size:11px;color:#888;margin:8px 0 0}'
    . '.ft{font-size:10px;color:#bbb;text-align:center;padding:8px 0 14px}'
    . '</style></head><body><div class="wrap">'
    . '<div class="hd"><h1>' . $ht . '</h1><p>' . $hs . '  ·  v0.001</p></div>'
    . '<div class="bd">' . $conteudo . '</div>'
    . '<div class="ft">' . $hs . '  ·  v0.001  ·  GPL v3 / CC BY-SA 3.0</div>'
    . '</div></body></html>';
}

// ── Email confirmação PARTICIPANTE (com QR Code) ───────────────────────────
function email_participante_html($nome, $evento, $doc, $org, $phone, $qr_key, $site_name) {
    $h    = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $qr_url = get_base_url() . '/index.php?qr=' . rawurlencode($qr_key);
    $rows = '<tr><td>Evento</td><td>' . $h($evento) . '</td></tr>'
          . '<tr><td>Nome</td><td>'   . $h($nome)   . '</td></tr>'
          . ($doc   ? '<tr><td>Documento</td><td>'   . $h($doc)   . '</td></tr>' : '')
          . ($org   ? '<tr><td>Organização</td><td>' . $h($org) . '</td></tr>' : '')
          . ($phone ? '<tr><td>Telefone</td><td>'    . $h($phone) . '</td></tr>' : '')
          . '<tr><td>Data</td><td>' . date('d/m/Y H:i') . '</td></tr>';
    $corpo = '<h2>Inscrição Confirmada</h2>'
           . '<p>Olá <strong>' . $h($nome) . '</strong>, sua inscrição foi registrada com sucesso!</p>'
           . '<table class="info">' . $rows . '</table>'
           . '<div class="qrbox">'
           . '<p class="lbl">QR Code para Check-in</p>'
           . qr_image_tag($qr_url, 180)
           . '<p class="sub">Apresente este código na entrada do evento</p>'
           . '</div>';
    return email_wrap('Confirmação de Inscrição', $corpo, $site_name);
}

// ── Email notificação ADMIN — novo participante ────────────────────────────
function email_admin_participante_html($nome, $email, $evento, $doc, $org, $phone, $site_name) {
    $h    = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $rows = '<tr><td>Nome</td><td>'   . $h($nome)   . '</td></tr>'
          . '<tr><td>Email</td><td>'  . $h($email)  . '</td></tr>'
          . '<tr><td>Evento</td><td>' . $h($evento) . '</td></tr>'
          . ($doc   ? '<tr><td>Documento</td><td>'   . $h($doc)   . '</td></tr>' : '')
          . ($org   ? '<tr><td>Organização</td><td>' . $h($org) . '</td></tr>' : '')
          . ($phone ? '<tr><td>Telefone</td><td>'    . $h($phone) . '</td></tr>' : '')
          . '<tr><td>Recebido em</td><td>' . date('d/m/Y H:i') . '</td></tr>';
    $corpo = '<h2>Nova Inscrição Recebida</h2>'
           . '<table class="info">' . $rows . '</table>';
    return email_wrap('Nova Inscrição — ' . $site_name, $corpo, $site_name);
}

// ── Email confirmação PALESTRANTE ──────────────────────────────────────────
function email_palestrante_html($nome, $titulo, $local, $horario, $site_name, $summary = '', $bio = '', $tipo = '', $email_pal = '') {
    $h    = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $rows = '<tr><td>Atividade</td><td>'   . $h($titulo)   . '</td></tr>'
          . ($tipo    ? '<tr><td>Tipo</td><td>'        . $h($tipo)    . '</td></tr>' : '')
          . ($local   ? '<tr><td>Local</td><td>'       . $h($local)   . '</td></tr>' : '')
          . ($horario ? '<tr><td>Horário</td><td>'     . $h($horario) . '</td></tr>' : '')
          . ($email_pal ? '<tr><td>Email</td><td>'     . $h($email_pal) . '</td></tr>' : '')
          . ($summary ? '<tr><td>Resumo</td><td>'      . $h($summary) . '</td></tr>' : '')
          . ($bio     ? '<tr><td>Mini Bio</td><td>'    . $h($bio)     . '</td></tr>' : '');
    $corpo = '<h2>Confirmação de Atividade</h2>'
           . '<p>Olá <strong>' . $h($nome) . '</strong>, sua atividade foi registrada no sistema.</p>'
           . '<table class="info">' . $rows . '</table>';
    return email_wrap('Confirmação de Atividade', $corpo, $site_name);
}

// ── Email notificação ADMIN — nova atividade ──────────────────────────────
function email_admin_atividade_html($titulo, $palestrante, $email_palestrante, $local, $horario, $origem, $site_name, $summary = '', $bio = '', $tipo = '') {
    $h    = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $rows = '<tr><td>Atividade</td><td>'    . $h($titulo)            . '</td></tr>'
          . ($tipo    ? '<tr><td>Tipo</td><td>'       . $h($tipo)           . '</td></tr>' : '')
          . '<tr><td>Palestrante</td><td>'  . $h($palestrante)       . '</td></tr>'
          . '<tr><td>Email</td><td>'        . $h($email_palestrante) . '</td></tr>'
          . ($local   ? '<tr><td>Local</td><td>'      . $h($local)   . '</td></tr>' : '')
          . ($horario ? '<tr><td>Horário</td><td>'    . $h($horario) . '</td></tr>' : '')
          . ($summary ? '<tr><td>Resumo</td><td>'     . $h($summary) . '</td></tr>' : '')
          . ($bio     ? '<tr><td>Mini Bio</td><td>'   . $h($bio)     . '</td></tr>' : '')
          . '<tr><td>Origem</td><td>'       . $h($origem)            . '</td></tr>'
          . '<tr><td>Recebido em</td><td>'  . date('d/m/Y H:i')     . '</td></tr>';
    $corpo = '<h2>Nova Atividade — ' . $h($origem) . '</h2>'
           . '<table class="info">' . $rows . '</table>';
    return email_wrap('Nova Atividade — ' . $site_name, $corpo, $site_name);
}

function qr_page_url($qr_key) {
    return get_base_url() . '/index.php?qr=' . urlencode($qr_key);
}

function get_base_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    return $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
}

function get_meta($type, $id, $key, $default = '') {
    if (!$id) return $default;
    $table = $type . '_meta';
    $field = $type . '_id';
    $stmt = db()->prepare("SELECT meta_value FROM $table WHERE $field = ? AND meta_key = ?");
    $stmt->execute(array($id, $key));
    $r = $stmt->fetch();
    return $r ? $r['meta_value'] : $default;
}

function save_meta($type, $id, $key, $value) {
    $table = $type . '_meta';
    $field = $type . '_id';
    db()->prepare("DELETE FROM $table WHERE $field = ? AND meta_key = ?")->execute(array($id, $key));
    db()->prepare("INSERT INTO $table ($field, meta_key, meta_value) VALUES (?,?,?)")->execute(array($id, $key, $value));
}

// Router
$p = $_GET['p'] ?? 'dash';
$do = $_GET['do'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ==================== PÁGINA QR CODE ====================
if (isset($_GET['qr']) && !empty($_GET['qr'])) {
    $qr_key = preg_replace('/[^a-f0-9]/', '', $_GET['qr']);
    if (!$qr_key) { http_response_code(404); exit('QR inválido'); }

    // Verificar se o participante existe
    $stmt = db()->prepare("SELECT name FROM participants WHERE qr_key = ?");
    $stmt->execute(array($qr_key));
    $part = $stmt->fetch();

    $qr_url = get_base_url() . '/index.php?qr=' . $qr_key;
    $nome = $part ? h($part['name']) : '';
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QR Code</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;background:#f0f0f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .box{background:#fff;padding:36px 40px;border:1px solid #ddd;text-align:center;max-width:340px;width:100%}
    h1{color:#111;margin-bottom:6px;font-size:17px;font-weight:600}
    .nome{font-size:15px;font-weight:600;color:#222;margin:10px 0 20px}
    #qrcode{display:inline-block;padding:12px;border:1px solid #ddd;margin:10px 0 20px}
    #qrcode canvas,#qrcode img{display:block}
    small{color:#888;font-size:12px;line-height:1.6}
    </style>
    </head><body>
    <div class="box">
        <h1>Seu QR Code</h1>
        <?php if ($nome): ?><p class="nome"><?= $nome ?></p><?php endif; ?>
        <div id="qrcode"></div>
        <small>Use este código para fazer check-in no evento.<br>Guarde ou tire uma foto da tela.</small>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
    new QRCode(document.getElementById('qrcode'), {
        text: <?= json_encode($qr_url) ?>,
        width: 220,
        height: 220,
        colorDark: '#1f2937',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
    </script>
    </body></html>
    <?php
    exit;
}

// ==================== LOGIN ====================
if ($p === 'login') {
    if ($_POST) {
        $r = db()->prepare("SELECT * FROM users WHERE email = ?");
        $r->execute(array($_POST['email']));
        $u = $r->fetch();
        if ($u && password_verify($_POST['pass'], $u['password'])) {
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['user_name'] = $u['name'];
            
            // Redirecionar para check-in se vier do link público
            $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'dash';
            go('?p=' . $redirect);
        }
        $err = 'Login incorreto';
    }
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><title>Login - Bienenstock</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#f0f0f0;min-height:100vh;display:flex;align-items:center;justify-content:center}
    .box{background:#fff;padding:40px;border:1px solid #ddd;width:100%;max-width:360px}
    .box-logo{margin-bottom:10px}
    .box-sub{font-size:12px;color:#888;margin-bottom:24px;text-align:center}
    input{width:100%;padding:9px 11px;margin:4px 0 12px;border:1px solid #d0d0d0;font-size:14px;font-family:inherit;border-radius:3px}
    input:focus{outline:none;border-color:#555}
    button{width:100%;padding:10px;background:#2c2c2c;color:#fff;border:none;font-size:14px;font-weight:500;cursor:pointer;border-radius:3px;letter-spacing:.1px}
    button:hover{background:#111}
    .err{background:#fdf0ef;color:#7b2020;padding:10px 12px;margin-bottom:14px;font-size:13px;border-left:3px solid #c0392b}
    .ok{background:#edfaf3;color:#1a5c38;padding:10px 12px;margin-bottom:14px;font-size:13px;border-left:3px solid #27ae60}
    label{display:block;font-size:12px;font-weight:600;color:#555;margin-bottom:2px;text-transform:uppercase;letter-spacing:.4px}
    </style>
    </head><body>
    <div class="box">
        <div class="box-logo"><svg xmlns="http://www.w3.org/2000/svg" viewBox="-4 -4 432.92 107.83" style="display:block;width:200px;height:auto;margin:0 auto 10px"><g transform="translate(99.645971,-14.114862)"><path fill="#2c2c2c" d="m -68.040987,112.66672 c -3.825554,-2.03104 -12.467662,-7.15842 -12.8588,-7.62915 -0.275916,-0.33206 -0.377854,-2.06875 -0.446101,-7.60016 -0.04865,-3.94336 -0.0079,-8.04143 0.09049,-9.10683 l 0.178949,-1.93709 1.364721,-0.87015 c 2.078073,-1.32498 9.82205,-5.74819 12.259228,-7.00223 l 2.163257,-1.11309 1.673202,0.83192 c 2.645383,1.31531 12.70286,7.0711 13.579451,7.77138 l 0.79375,0.63411 0.09039,8.95487 0.09039,8.95487 -0.619557,0.55567 c -1.534556,1.37633 -14.749727,8.84513 -15.627117,8.83196 -0.18605,-0.003 -1.415563,-0.57703 -2.732251,-1.27608 z m 34.924917,-0.92329 c -6.403989,-3.56863 -11.156407,-6.4774 -11.332449,-6.93616 -0.09026,-0.23522 -0.164116,-4.46754 -0.164116,-9.40515 v -8.97748 l 1.457806,-0.93255 c 1.751411,-1.12036 10.090821,-5.83085 12.727361,-7.189 l 1.882042,-0.96949 3.8065,2.08081 c 6.49338,3.54958 11.456966,6.62009 11.806245,7.30343 0.239726,0.469 0.327869,2.97974 0.33073,9.42078 l 0.0039,8.78138 -2.315104,1.42657 c -6.303558,3.88428 -13.067531,7.59969 -13.83729,7.60075 -0.225327,3e-4 -2.189858,-0.99145 -4.365625,-2.20389 z m 301.43938,2.05336 c -0.10914,-0.11093 -0.19844,-0.65583 -0.19844,-1.21089 0,-0.88264 0.0912,-1.03087 0.7276,-1.18206 0.93587,-0.22235 1.56628,-0.77601 1.75932,-1.54513 0.13361,-0.53236 -0.87648,-3.71769 -3.38081,-10.66135 l -0.40555,-1.12448 h 1.65211 1.65212 l 1.01965,3.44601 c 0.56081,1.8953 1.04605,3.41335 1.07832,3.37343 0.0323,-0.0399 0.50928,-1.59062 1.06003,-3.44601 l 1.00135,-3.37343 h 1.54814 c 0.85147,0 1.54814,0.0979 1.54814,0.21755 0,0.38751 -4.4446,12.93478 -4.88038,13.77748 -0.23226,0.44916 -0.7192,1.01118 -1.08207,1.24895 -0.72941,0.47792 -2.78792,0.79666 -3.09953,0.47993 z m -90.81823,-0.49281 c -0.43657,-0.19724 -1.02215,-0.53305 -1.3013,-0.74626 -0.4984,-0.38067 -0.49726,-0.40432 0.0635,-1.31167 l 0.57107,-0.92402 1.33583,0.66109 c 1.18954,0.5887 1.44718,0.62774 2.35249,0.3565 1.25258,-0.37528 1.5894,-0.76299 1.79349,-2.06449 l 0.15928,-1.0158 -0.57306,0.50774 c -0.79383,0.70334 -3.12447,0.87789 -4.21208,0.31547 -2.3012,-1.19 -3.29976,-4.67005 -2.30305,-8.02632 0.43126,-1.45222 1.72616,-2.84277 2.92816,-3.14445 1.00976,-0.25343 2.98087,0.0418 3.5167,0.52669 0.58945,0.53344 0.82832,0.54247 0.82832,0.0313 0,-0.30038 0.32484,-0.39687 1.33606,-0.39687 h 1.33607 l -0.0793,6.41614 c -0.0777,6.2884 -0.0919,6.43388 -0.71458,7.30688 -0.88609,1.24237 -2.41991,1.87679 -4.52409,1.87123 -0.94589,-0.002 -2.07698,-0.16592 -2.51354,-0.36315 z m 4.30675,-6.64715 c 0.53561,-0.37516 0.58804,-0.63012 0.58804,-2.85985 0,-2.81031 -0.221,-3.28318 -1.64224,-3.51381 -0.84745,-0.13753 -1.08739,-0.0505 -1.78507,0.64714 -0.91658,0.91658 -1.16013,2.53328 -0.67663,4.49148 0.36837,1.49194 2.22034,2.14249 3.5159,1.23504 z m -167.510575,2.61674 c -0.09701,-0.097 -0.176389,-3.54983 -0.176389,-7.67292 v -7.49652 h 5.027083 5.027084 v 1.19062 1.19063 h -3.439584 -3.439583 v 1.98437 1.98438 h 3.042708 3.042709 v 1.32291 1.32292 h -3.042709 -3.042708 v 3.175 3.175 h -1.411111 c -0.776111,0 -1.490486,-0.0794 -1.5875,-0.17639 z m 11.388969,0.0119 c -0.103551,-0.10355 -0.188275,-2.66875 -0.188275,-5.70043 v -5.51215 h 1.455209 c 1.299462,0 1.455208,0.0592 1.455208,0.55268 0,0.53186 0.03067,0.52688 0.81405,-0.13229 0.573052,-0.48219 1.121359,-0.68498 1.852084,-0.68498 h 1.038033 v 1.45521 1.45521 h -1.161314 c -2.104261,0 -2.264218,0.32518 -2.342704,4.7625 l -0.06786,3.83646 -1.33308,0.078 c -0.733194,0.0429 -1.417803,-0.007 -1.521354,-0.11024 z m 10.251821,-0.33283 c -2.184694,-0.99237 -3.177675,-2.95502 -2.969203,-5.86869 0.148214,-2.07149 0.675543,-3.13399 2.144812,-4.32155 0.941381,-0.76089 1.168447,-0.82181 3.063304,-0.82181 1.814565,0 2.152475,0.0808 2.980916,0.71267 1.213787,0.9258 1.838611,2.28443 2.005235,4.36021 l 0.134372,1.674 h -3.608099 c -4.05644,0 -4.299987,0.14257 -2.802206,1.64035 0.731584,0.73158 0.927005,0.79425 2.119347,0.67969 0.744711,-0.0716 1.543404,-0.33431 1.8445,-0.6068 0.516621,-0.46754 0.552475,-0.46006 1.320034,0.27531 l 0.788989,0.7559 -0.595111,0.64625 c -0.327311,0.35544 -0.978239,0.8095 -1.446508,1.00902 -1.20867,0.51501 -3.698465,0.44775 -4.980382,-0.13455 z m 4.26168,-7.10944 c -0.387235,-1.63566 -2.287477,-2.18575 -3.46901,-1.00422 -0.357188,0.35719 -0.649432,0.89297 -0.649432,1.19063 0,0.50071 0.160497,0.54119 2.14535,0.54119 h 2.145349 z m 7.379987,7.10944 c -1.937554,-0.88011 -3.028811,-2.75046 -3.028811,-5.19121 0,-3.52399 2.157195,-5.95313 5.286673,-5.95313 3.200423,0 5.023443,2.03868 5.02765,5.6224 l 0.0015,1.25677 h -3.571875 c -4.001387,0 -4.258568,0.14776 -2.798478,1.60785 0.964087,0.96409 2.544096,1.05554 3.887894,0.22502 l 0.887293,-0.54837 0.640421,0.74453 c 0.803147,0.93371 0.583725,1.42329 -0.97639,2.17853 -1.465234,0.70931 -3.864335,0.73511 -5.355853,0.0576 z m 4.376571,-6.92303 c 0,-1.21206 -1.73677,-2.17229 -3.030854,-1.6757 -0.427649,0.1641 -1.202479,1.41363 -1.202479,1.93917 0,0.17076 0.815184,0.27772 2.116666,0.27772 1.955957,0 2.116667,-0.0411 2.116667,-0.54119 z m 10.31875,-0.24684 v -7.67863 h 5.159375 5.159375 v 1.19062 1.19063 h -3.571875 -3.571875 v 1.85208 1.85208 h 3.042709 3.042708 v 1.19063 1.19062 h -3.042708 -3.042709 v 2.11667 2.11667 h 3.586372 3.586372 l -0.08064,1.25677 -0.08064,1.25677 -5.09323,0.0719 -5.093229,0.0719 z m 14.42411,7.3504 c -0.06713,-0.18956 -0.95232,-2.76119 -1.967085,-5.71472 l -1.845029,-5.37007 1.619514,0.0784 1.619514,0.0784 0.902828,3.30729 c 0.496556,1.81901 0.991319,3.40024 1.099475,3.51384 0.108156,0.1136 0.460393,-0.77937 0.782751,-1.98437 0.322357,-1.20501 0.767687,-2.81601 0.989621,-3.57999 l 0.403517,-1.38906 h 1.551651 1.551651 l -1.901155,5.62239 -1.901154,5.6224 -1.392021,0.0801 c -0.963922,0.0555 -1.429559,-0.0259 -1.514078,-0.26458 z m 10.500624,-0.24628 c -1.488992,-0.73704 -2.378771,-1.94535 -2.755654,-3.74215 -0.365125,-1.74075 0.01504,-3.88728 0.933251,-5.26949 0.779792,-1.17384 2.600759,-2.06695 4.214313,-2.06695 3.121046,0 4.962523,2.02736 4.962523,5.46344 v 1.41573 h -3.589705 -3.589704 l 0.171503,0.59531 c 0.486871,1.69 2.807929,2.32823 4.566905,1.25578 0.47646,-0.29049 0.914837,-0.52817 0.974171,-0.52817 0.05933,0 0.365208,0.36138 0.679721,0.80307 0.487204,0.68422 0.519241,0.86664 0.216454,1.23256 -1.159956,1.40179 -4.751258,1.84694 -6.783778,0.84087 z m 4.444016,-6.85728 c 0,-0.80906 -1.166069,-1.84006 -2.08112,-1.84006 -0.548699,0 -0.98677,0.24305 -1.467235,0.81405 -1.149599,1.36622 -0.965998,1.5672 1.431689,1.5672 1.955957,0 2.116666,-0.0411 2.116666,-0.54119 z m 4.7625,1.73182 v -5.68854 h 1.455209 c 1.271398,0 1.455208,0.0658 1.455208,0.52119 0,0.4943 0.04968,0.48748 0.96296,-0.13229 1.37143,-0.93068 3.44299,-0.91935 4.56525,0.025 1.13232,0.95278 1.3478,2.04005 1.34948,6.80922 l 10e-4,4.18039 -1.52135,-0.0794 -1.52136,-0.0793 -0.13229,-4.30976 c -0.14123,-4.60104 -0.20658,-4.80551 -1.53875,-4.81432 -0.67753,-0.004 -1.66185,0.44455 -1.962927,0.89546 -0.106912,0.16011 -0.196209,2.10682 -0.198437,4.32601 l -0.0041,4.0349 h -1.455208 -1.455209 z m 14.102967,5.37793 c -1.09856,-0.54231 -1.40297,-1.7322 -1.40297,-5.48406 v -3.46575 h -0.79375 c -0.74741,0 -0.79375,-0.0608 -0.79375,-1.04076 0,-0.93869 0.0714,-1.04897 0.72761,-1.12448 0.67864,-0.0781 0.73298,-0.1772 0.80758,-1.47278 l 0.08,-1.38906 h 1.42755 1.42756 l 0.08,1.38906 0.08,1.38906 0.99219,0.0821 c 0.96473,0.0798 0.99219,0.11096 0.99219,1.12448 0,1.0397 -0.003,1.04236 -1.05834,1.04236 h -1.05833 v 2.7609 c 0,3.42823 0.22312,4.11827 1.33158,4.11827 0.7958,0 0.81355,0.0272 0.73416,1.12448 l -0.0814,1.12448 -1.45521,0.0543 c -0.80037,0.0299 -1.71684,-0.0748 -2.03662,-0.23267 z m 10.94426,0.13422 c -0.097,-0.097 -0.17639,-3.55464 -0.17639,-7.68362 v -7.50722 l 2.15518,0.0768 2.15518,0.0769 1.92481,5.42396 c 1.46406,4.12562 1.97542,5.29729 2.13627,4.89479 0.1163,-0.29104 0.98958,-2.73183 1.94062,-5.42396 l 1.72915,-4.89479 2.04918,-0.0773 2.04919,-0.0773 v 7.68404 7.68405 h -1.47833 -1.47833 l 0.0893,-5.53828 c 0.0491,-3.04605 0.004,-5.38527 -0.10056,-5.19826 -0.15348,0.27492 -1.55903,4.07722 -3.70497,10.02265 -0.2404,0.66603 -0.3751,0.73321 -1.32626,0.66146 l -1.05868,-0.0799 -1.94511,-5.39366 c -1.06981,-2.96652 -1.98442,-5.43297 -2.03246,-5.48101 -0.048,-0.048 -0.037,2.40887 0.0246,5.45981 l 0.11197,5.54715 h -1.44398 c -0.79419,0 -1.52336,-0.0794 -1.62037,-0.17639 z m 20.30724,-0.0657 c -0.30291,-0.1304 -0.86846,-0.61467 -1.25677,-1.07616 -0.54987,-0.65347 -0.70603,-1.11928 -0.70603,-2.10599 0,-2.27967 1.3445,-3.30574 4.74589,-3.62186 1.98032,-0.18405 2.16559,-0.36217 1.5645,-1.50415 -0.62313,-1.18385 -1.76279,-1.31311 -2.76257,-0.31333 -0.52754,0.52755 -0.92684,0.66146 -1.97234,0.66146 -1.12677,0 -1.3109,-0.073 -1.3109,-0.51996 0,-0.80811 1.45126,-2.26588 2.59768,-2.60936 2.42538,-0.72666 4.85412,-0.17396 5.98248,1.36141 0.48045,0.65376 0.56656,1.25688 0.69886,4.89479 0.083,2.28204 0.20206,4.32775 0.26459,4.54603 0.0888,0.30982 -0.18741,0.4143 -1.25897,0.47628 -1.1755,0.068 -1.41604,-0.002 -1.6747,-0.48497 -0.29032,-0.54246 -0.32217,-0.54617 -0.82007,-0.0956 -0.58581,0.53014 -3.19055,0.7793 -4.09165,0.39138 z m 4.05647,-2.56009 c 0.45302,-0.29302 0.59531,-0.63657 0.59531,-1.43729 v -1.05224 h -1.07572 c -1.68059,0 -2.61644,0.63151 -2.62439,1.77094 -0.009,1.28024 1.64474,1.66298 3.1048,0.71859 z m 5.88698,-2.8864 v -5.68854 h 1.45521 c 1.2714,0 1.45521,0.0658 1.45521,0.52119 0,0.4943 0.0497,0.48748 0.96296,-0.13229 1.37143,-0.93068 3.44298,-0.91935 4.56525,0.025 1.13179,0.95234 1.3478,2.04016 1.34948,6.79601 l 0.001,4.16719 h -1.43197 -1.43196 l -0.0894,-4.25502 c -0.0808,-3.84806 -0.13852,-4.29091 -0.60298,-4.63021 -0.73262,-0.53519 -1.81603,-0.46373 -2.62573,0.17318 l -0.69714,0.54837 v 4.08184 4.08184 h -1.45521 -1.45521 z m 13.27939,5.15259 c -0.4817,-0.29368 -1.04724,-0.94426 -1.25677,-1.44572 -1.14229,-2.73389 0.60978,-4.71344 4.49272,-5.07603 l 1.76854,-0.16515 -0.08,-0.81209 c -0.0538,-0.54696 -0.3019,-0.93605 -0.75975,-1.19176 -0.85707,-0.47868 -2.10379,-0.20231 -2.28941,0.5075 -0.11484,0.43918 -0.36983,0.51712 -1.69187,0.51712 -1.74844,0 -1.85769,-0.16443 -1.02102,-1.53674 1.08345,-1.77707 4.44095,-2.45452 6.81799,-1.37569 1.77253,0.80447 2.10329,1.84248 2.21372,6.94732 l 0.0901,4.16719 h -1.41392 c -1.17333,0 -1.4366,-0.0867 -1.54719,-0.50962 -0.13151,-0.50288 -0.14431,-0.50289 -0.96742,-9.9e-4 -1.13149,0.68994 -3.20235,0.6779 -4.3558,-0.0253 z m 4.51384,-2.31872 c 0.29093,-0.25281 0.46302,-0.78045 0.46302,-1.41967 v -1.01733 h -1.13175 c -1.54467,0 -2.30783,0.57649 -2.30783,1.74332 0,0.70827 0.13679,0.97305 0.59531,1.15233 0.7241,0.28313 1.75644,0.0843 2.38125,-0.45865 z m 20.96823,2.41503 c -2.09834,-1.10622 -3.03109,-2.76215 -3.03109,-5.38119 0,-2.08494 0.58506,-3.5086 1.92557,-4.68558 1.08574,-0.95329 2.25872,-1.25702 4.15194,-1.07511 2.46724,0.23707 3.91744,2.02734 4.1677,5.14502 l 0.13437,1.674 h -3.71562 c -3.59266,0 -3.71101,0.0176 -3.57642,0.5323 0.0766,0.29277 0.50649,0.84136 0.9554,1.21909 0.6958,0.58548 1.00375,0.66876 2.08766,0.56461 0.70763,-0.068 1.50697,-0.33529 1.80252,-0.60276 0.51661,-0.46752 0.55245,-0.46009 1.31821,0.27356 0.75475,0.7231 0.76818,0.77513 0.32617,1.26391 -1.31063,1.4493 -4.75956,2.01415 -6.54641,1.07215 z m 4.26164,-6.85804 c -0.0883,-0.23018 -0.1606,-0.52784 -0.1606,-0.66146 0,-0.53352 -1.09983,-1.30128 -1.86411,-1.30128 -0.9387,0 -2.10464,1.01937 -2.10464,1.84006 0,0.5007 0.1605,0.54119 2.14498,0.54119 1.84723,0 2.12268,-0.0581 1.98437,-0.41851 z m 4.77829,7.12129 c -0.097,-0.097 -0.17639,-2.65686 -0.17639,-5.68854 v -5.51215 h 1.45521 c 1.29479,0 1.45521,0.0603 1.45521,0.54718 0,0.54422 0.004,0.54399 0.82755,-0.0421 1.57333,-1.1203 4.61132,-0.91943 5.2906,0.34981 0.27488,0.51364 0.28164,0.51361 0.84978,-0.003 0.31498,-0.28654 0.83276,-0.65492 1.15061,-0.81862 0.85096,-0.43827 2.93562,-0.36203 3.78268,0.13835 1.36847,0.80837 1.59253,1.75336 1.59253,6.71673 v 4.48894 h -1.45521 -1.45521 v -4.10104 c 0,-3.74827 -0.0455,-4.14656 -0.52916,-4.63021 -0.68115,-0.68115 -1.85878,-0.67647 -2.59595,0.0103 -0.54745,0.51003 -0.57905,0.76271 -0.57905,4.6302 v 4.09074 h -1.43701 -1.437 l -0.0844,-4.25521 c -0.0763,-3.85166 -0.13306,-4.29077 -0.59794,-4.63021 -0.67421,-0.49227 -1.73744,-0.4764 -2.47025,0.0369 -0.56585,0.39634 -0.58804,0.57104 -0.58804,4.63021 v 4.21833 h -1.41111 c -0.77611,0 -1.49049,-0.0794 -1.5875,-0.17639 z m 21.38715,-0.26251 c -1.18057,-0.62129 -2.26768,-1.70152 -2.67842,-2.66146 -0.53135,-1.24186 -0.45711,-4.21467 0.13733,-5.4986 0.96091,-2.07547 2.51733,-3.04583 4.87342,-3.03835 2.81893,0.009 4.54991,1.83823 4.85763,5.13348 l 0.16261,1.74133 h -3.71766 c -3.5947,0 -3.71305,0.0176 -3.57846,0.5323 0.0766,0.29277 0.50649,0.84136 0.9554,1.21909 0.6958,0.58548 1.00375,0.66876 2.08767,0.56461 0.70763,-0.068 1.50697,-0.33529 1.80252,-0.60276 0.5166,-0.46752 0.55244,-0.46009 1.3182,0.27356 0.75589,0.72419 0.76885,0.77476 0.32617,1.27312 -1.23629,1.39181 -4.81726,1.97365 -6.54641,1.06368 z m 4.10105,-7.10173 c 0,-2.16637 -3.1815,-2.27688 -3.92767,-0.13642 l -0.27814,0.79788 h 2.1029 c 2.06671,0 2.10291,-0.0114 2.10291,-0.66146 z m 4.95077,7.37613 c -0.10355,-0.10355 -0.18827,-2.66875 -0.18827,-5.70043 v -5.51215 h 1.4552 c 1.29479,0 1.45521,0.0603 1.45521,0.54718 0,0.54309 0.006,0.54273 0.83565,-0.0479 1.08899,-0.77543 3.27278,-0.97892 4.37706,-0.40787 1.43104,0.74002 1.66646,1.69521 1.66646,6.76154 v 4.52408 h -1.45521 -1.45521 v -4.10104 c 0,-3.74827 -0.0455,-4.14656 -0.52916,-4.63021 -0.65266,-0.65266 -1.87235,-0.68351 -2.61512,-0.0661 -0.51648,0.4293 -0.56198,0.76175 -0.62462,4.56407 l -0.0676,4.10104 -1.33308,0.078 c -0.73319,0.0429 -1.4178,-0.007 -1.52135,-0.11024 z m 13.71707,-0.41893 c -0.92263,-0.7959 -1.19134,-2.0734 -1.19903,-5.70042 l -0.006,-2.97657 h -0.79375 c -0.74966,0 -0.79375,-0.0588 -0.79375,-1.05833 0,-0.99954 0.0441,-1.05833 0.79375,-1.05833 0.78573,0 0.79375,-0.0147 0.79375,-1.45521 v -1.45521 h 1.5875 1.5875 v 1.45521 1.45521 h 0.92604 c 0.90399,0 0.92604,0.0252 0.92604,1.05833 0,1.03313 -0.0221,1.05833 -0.92604,1.05833 h -0.92604 v 3.12209 c 0,3.39593 0.13371,3.75708 1.39104,3.75708 0.72584,0 0.7528,0.0449 0.67469,1.12448 l -0.0814,1.12448 -1.62508,0.078 c -1.38026,0.0663 -1.73111,-0.0134 -2.32895,-0.52917 z m 14.34264,0.31998 c -2.03491,-0.61272 -3.64173,-2.34892 -3.64173,-3.93497 0,-0.82662 0.004,-0.82866 1.43469,-0.82866 1.40639,0 1.44074,0.0183 1.74182,0.93064 0.36471,1.10506 1.38209,1.71519 2.86008,1.71519 1.23252,0 2.43007,-0.82797 2.43007,-1.68012 0,-1.06756 -0.72512,-1.65285 -3.18646,-2.57203 -3.77042,-1.40804 -5.32694,-3.13354 -4.89649,-5.42804 0.78563,-4.18778 8.67082,-4.86313 10.66264,-0.91323 0.95654,1.89687 0.85574,2.12773 -0.92604,2.12103 -1.45378,-0.005 -1.53898,-0.0437 -1.91823,-0.8599 -0.72936,-1.56976 -3.00642,-1.94879 -4.24809,-0.70712 -1.14889,1.14889 -0.33676,2.29317 2.23916,3.15491 2.8054,0.9385 4.36362,1.99469 4.97203,3.37011 1.43588,3.24605 -0.81486,5.92324 -4.93739,5.8729 -1.02419,-0.0125 -2.18792,-0.12083 -2.58606,-0.24071 z m 22.81674,-0.0338 c -1.17385,-0.46807 -2.64597,-2.20905 -2.64597,-3.12919 0,-0.16025 0.61556,-0.27773 1.4552,-0.27773 0.80037,0 1.45521,0.11907 1.45521,0.26459 0,0.14552 0.23813,0.5027 0.52917,0.79375 1.03267,1.03267 3.61781,0.45938 3.38478,-0.75063 -0.10141,-0.5266 -0.96467,-0.96946 -2.92058,-1.49829 -0.87714,-0.23715 -1.91239,-0.75538 -2.4474,-1.22512 -0.80147,-0.70371 -0.92722,-0.9753 -0.92722,-2.00263 0,-1.50269 0.7002,-2.52466 2.11861,-3.0922 2.53572,-1.01459 5.75827,-0.17252 6.69789,1.75021 0.26516,0.5426 0.40696,1.10815 0.31511,1.25677 -0.0918,0.14862 -0.7511,0.27022 -1.465,0.27022 -1.07678,0 -1.35142,-0.10145 -1.61149,-0.59531 -0.4187,-0.79508 -0.74308,-0.98332 -1.70272,-0.98814 -0.96313,-0.005 -1.44198,0.40866 -1.44198,1.24516 0,0.67943 0.14319,0.75632 3.17177,1.70324 1.6417,0.5133 2.08759,0.77809 2.60245,1.54547 1.11819,1.66659 0.40525,3.62619 -1.67914,4.6153 -0.97612,0.4632 -3.84571,0.53043 -4.88869,0.11453 z m 11.11757,-0.0364 c -1.12231,-0.57852 -1.32002,-1.36817 -1.32423,-5.28879 l -0.004,-3.63802 h -0.92604 c -0.90151,0 -0.92604,-0.0276 -0.92604,-1.04157 0,-0.98171 0.0494,-1.04633 0.8599,-1.12448 0.83855,-0.0808 0.86188,-0.11738 0.93986,-1.47197 l 0.08,-1.38906 h 1.44138 1.44138 v 1.45521 1.45521 h 0.92604 c 0.90399,0 0.92604,0.0252 0.92604,1.05833 0,1.03313 -0.022,1.05833 -0.92604,1.05833 h -0.92596 v 3.12209 c 0,3.39593 0.13371,3.75708 1.39105,3.75708 0.72584,0 0.75279,0.0449 0.67468,1.12448 l -0.0814,1.12448 -1.4552,0.0685 c -0.80248,0.0378 -1.74957,-0.0832 -2.11146,-0.26979 z m 7.64318,-0.28224 c -0.75489,-0.39869 -1.43786,-1.05463 -1.93675,-1.86012 -0.67482,-1.08955 -0.76907,-1.48823 -0.758,-3.20656 0.0238,-3.69026 1.71144,-5.7098 4.93293,-5.90303 3.17042,-0.19016 5.08319,1.66919 5.2499,5.10327 l 0.0771,1.5875 -3.63802,0.0736 c -2.00091,0.0405 -3.63802,0.12418 -3.63802,0.18599 0,0.0618 0.14224,0.42456 0.31609,0.80612 0.65335,1.43393 2.81879,1.87355 4.42007,0.89734 l 0.89971,-0.5485 0.68464,0.68464 c 0.85408,0.85408 0.61878,1.35405 -1.03552,2.20028 -1.61792,0.82761 -3.98467,0.81889 -5.5741,-0.0205 z m 4.52244,-6.84158 c 0,-0.83062 -1.01576,-1.80299 -1.88345,-1.80299 -0.8367,0 -1.96074,0.9067 -2.21404,1.78594 -0.16776,0.58234 -0.12498,0.59531 1.963,0.59531 2.01851,0 2.13449,-0.0314 2.13449,-0.57826 z m 4.95078,7.29293 c -0.10355,-0.10355 -0.18828,-2.66875 -0.18828,-5.70043 v -5.51215 h 1.45521 c 1.2714,0 1.45521,0.0658 1.45521,0.52119 0,0.4943 0.0497,0.48748 0.96296,-0.13229 1.44405,-0.97996 3.47198,-0.92492 4.66976,0.12674 l 0.88864,0.78024 0.71408,-0.71409 c 0.63767,-0.63767 0.90935,-0.71409 2.53884,-0.71409 1.56736,0 1.92112,0.0905 2.50793,0.64182 1.00464,0.9438 1.20843,2.07445 1.21006,6.71358 l 10e-4,4.18039 -1.52135,-0.0794 -1.52136,-0.0793 -0.0701,-4.23334 c -0.0596,-3.59954 -0.13652,-4.28284 -0.51359,-4.56406 -0.63183,-0.4712 -1.77728,-0.4088 -2.42827,0.13229 -0.51649,0.4293 -0.56199,0.76175 -0.62463,4.56407 l -0.0676,4.10104 h -1.4552 -1.45521 l -0.13229,-4.30976 c -0.14266,-4.64758 -0.19613,-4.80603 -1.62434,-4.81432 -0.38406,-0.002 -0.94897,0.20431 -1.25536,0.45897 -0.51649,0.4293 -0.56199,0.76175 -0.62462,4.56407 l -0.0676,4.10104 -1.33308,0.078 c -0.73319,0.0429 -1.4178,-0.007 -1.52135,-0.11024 z M 74.12751,84.96199 c -4.647397,-1.12668 -8.364276,-4.62851 -9.802582,-9.23542 -0.472284,-1.51273 -0.582511,-2.50986 -0.586174,-5.30265 -0.0052,-3.93271 0.300898,-5.47528 1.653606,-8.33438 1.114017,-2.3546 3.467718,-4.86353 5.554776,-5.92112 4.186021,-2.12122 10.178659,-1.97369 13.74267,0.33833 4.063475,2.63603 5.899644,6.69248 5.899644,13.03345 v 3.13268 h -9.392708 -9.392709 v 0.6095 c 0,0.98992 1.29614,3.29865 2.330595,4.15133 2.897542,2.3884 8.180166,2.0459 11.113454,-0.72055 l 0.960562,-0.90592 1.399279,1.54167 c 0.769604,0.84792 1.635606,1.85684 1.924448,2.24205 l 0.525168,0.70038 -1.573536,1.45681 c -2.777069,2.57108 -5.797096,3.63911 -10.187121,3.60268 -1.461015,-0.0121 -3.337232,-0.1871 -4.169372,-0.38884 z m 8.789023,-18.4588 c 0,-1.1932 -0.790838,-3.25352 -1.515134,-3.94728 -1.458969,-1.39745 -3.689548,-1.88048 -5.65528,-1.22463 -1.782474,0.5947 -3.311474,2.57446 -3.803989,4.92545 l -0.180142,0.8599 h 5.577273 5.577272 z m 53.388057,18.4588 c -5.19582,-1.25964 -8.99767,-5.27303 -10.1611,-10.7265 -0.51362,-2.40752 -0.35332,-7.32629 0.3096,-9.50011 1.93196,-6.33521 6.78748,-10.05417 13.12684,-10.05417 4.05719,0 6.85962,1.0479 9.29151,3.47433 2.70388,2.69781 3.88225,6.19333 3.8905,11.54077 l 0.005,2.97657 h -9.42733 -9.42733 l 0.15353,0.94611 c 0.36364,2.24084 2.50353,4.55249 4.78513,5.16921 0.59604,0.16111 1.97667,0.23978 3.06808,0.17483 2.24141,-0.1334 4.04023,-0.89769 5.49668,-2.33545 l 0.86647,-0.85535 1.51478,1.67382 c 2.75517,3.04443 2.67183,2.62887 0.86306,4.30347 -2.74694,2.54318 -5.84162,3.63736 -10.18564,3.60131 -1.46102,-0.0121 -3.33724,-0.1871 -4.16938,-0.38884 z m 8.78903,-18.22922 c 0,-1.23705 -0.66979,-3.19747 -1.36165,-3.98545 -1.61801,-1.84282 -4.95917,-2.24153 -7.07121,-0.84385 -1.07072,0.70857 -2.2711,2.69197 -2.55458,4.22097 l -0.18395,0.99219 h 5.58569 c 4.70445,0 5.5857,-0.0606 5.5857,-0.38386 z m 51.00443,18.2452 c -4.43887,-1.12441 -7.96143,-4.53462 -8.54265,-8.2702 l -0.17496,-1.12448 h 3.85971 3.85972 v 0.62233 c 0,0.85908 1.0179,2.28985 2.06355,2.90054 1.86761,1.09074 5.97403,0.82149 7.18859,-0.47136 0.78999,-0.8409 0.98448,-2.33677 0.41652,-3.20357 -0.56016,-0.85493 -2.59413,-1.80813 -5.03845,-2.36123 -6.37022,-1.44146 -9.92549,-3.68358 -10.99906,-6.93652 -0.17872,-0.54152 -0.25889,-1.74829 -0.18875,-2.84115 0.3328,-5.18582 5.0031,-8.6194 11.70642,-8.60653 4.60539,0.009 7.66639,1.09913 10.10849,3.60047 1.10096,1.12767 1.5035,1.78893 1.9304,3.17106 0.90707,2.93675 1.1372,2.74888 -3.36728,2.74888 h -3.90664 l -0.47462,-1.33632 c -0.5314,-1.4962 -1.44319,-2.22205 -3.15442,-2.51116 -2.83306,-0.47864 -5.22642,0.89131 -5.22642,2.99159 0,2.08024 0.96154,2.6586 6.66429,4.00852 4.16663,0.98631 6.49374,2.08475 8.02514,3.78804 1.49894,1.66718 2.08569,3.41857 1.91918,5.72858 -0.26261,3.64342 -2.50152,6.22971 -6.68707,7.72457 -2.08384,0.74425 -7.69665,0.95676 -9.98169,0.37794 z m 28.89974,0.14749 c -2.44314,-0.74587 -3.87475,-2.07079 -4.74195,-4.38859 -0.51752,-1.38318 -0.54972,-2.00345 -0.54972,-10.58746 v -9.1182 h -2.24896 -2.24896 v -2.89972 -2.89971 l 2.18281,-0.0769 2.18282,-0.0769 0.0737,-3.64505 0.0736,-3.64505 3.8951,0.0732 3.89509,0.0732 0.0736,3.63803 0.0736,3.63802 h 2.50608 2.50607 v 2.91041 2.91042 h -2.51354 -2.51354 v 8.18733 8.18733 l 0.63876,0.74236 c 0.59747,0.69437 0.76848,0.74165 2.64583,0.73149 l 2.00708,-0.0109 v 2.96551 2.9655 l -1.33594,0.28651 c -1.50368,0.32248 -5.59395,0.3467 -6.60156,0.0391 z m 19.84375,-0.23261 c -4.36692,-1.14144 -7.64063,-4.4843 -9.117,-9.30956 -0.66591,-2.17639 -0.82695,-7.32768 -0.30606,-9.78958 1.46842,-6.9402 6.59585,-11.1125 13.65639,-11.1125 6.95207,0 11.95486,3.87694 13.65671,10.58333 0.59351,2.33881 0.5839,7.48062 -0.0183,9.76843 -1.32857,5.04769 -4.84921,8.71079 -9.5484,9.93478 -2.06076,0.53676 -6.12316,0.5002 -8.32338,-0.0749 z m 6.74687,-6.37005 c 1.33252,-0.61499 2.0737,-1.41771 2.77692,-3.00744 1.18329,-2.67504 1.20329,-8.25638 0.039,-10.88844 -1.74928,-3.95453 -6.70015,-4.8313 -9.46964,-1.67702 -1.40306,1.598 -1.81364,3.26512 -1.80811,7.34173 0.005,3.77249 0.27376,4.85521 1.70264,6.86187 1.23871,1.73961 4.5176,2.40386 6.75918,1.3693 z m 25.03285,6.43189 c -4.57257,-1.22972 -7.77276,-4.60529 -9.03134,-9.52629 -0.65592,-2.56463 -0.71483,-7.86327 -0.11568,-10.4051 1.22864,-5.21242 4.7709,-8.89845 9.57943,-9.96825 2.41645,-0.5376 6.42797,-0.40043 8.3973,0.28715 4.19995,1.46639 7.0661,5.09312 7.47556,9.45932 l 0.14267,1.52136 h -3.7071 -3.7071 l -0.17823,-1.12448 c -0.82406,-5.1989 -8.19697,-5.59452 -9.98698,-0.53589 -0.24209,0.68415 -0.35346,2.42222 -0.35346,5.51596 0,4.49856 0.003,4.52339 0.79375,6.06505 1.00355,1.95715 2.37378,2.78231 4.60568,2.77355 2.59739,-0.0102 4.5421,-1.34925 4.95455,-3.41151 l 0.16321,-0.81602 h 3.70794 3.70795 l -0.1591,1.34334 c -0.4755,4.01489 -3.97013,7.67169 -8.47874,8.87219 -1.75522,0.46736 -5.98664,0.44007 -7.81031,-0.0504 z M 14.124866,64.84476 V 44.84582 l 9.458854,0.11659 c 10.153726,0.12515 10.889517,0.21293 13.966633,1.66619 3.076886,1.45316 4.966202,4.09591 5.40042,7.55403 0.537479,4.28051 -1.168434,7.91203 -4.534315,9.6526 l -0.875436,0.4527 1.185017,0.50328 c 4.111341,1.74609 6.006923,5.91676 5.030136,11.06734 -0.864654,4.55931 -4.046931,7.48649 -9.313384,8.56682 -1.628047,0.33396 -3.882661,0.41834 -11.178646,0.41834 h -9.139279 z m 18.117993,13.08925 c 1.350195,-0.37484 2.900481,-1.92699 3.318617,-3.32261 0.431367,-1.43977 0.13238,-3.77241 -0.628282,-4.90173 -0.3235,-0.48029 -1.119356,-1.16851 -1.768568,-1.52938 -1.107567,-0.61565 -1.470101,-0.66235 -5.876739,-0.75711 L 22.591533,67.3222 v 5.45346 5.45347 l 4.299479,-0.001 c 2.364714,-8.1e-4 4.773045,-0.13295 5.351847,-0.29364 z m 0.305504,-17.13243 c 1.540757,-0.88292 2.214003,-2.20002 2.214003,-4.33133 0,-2.15839 -0.651853,-3.23944 -2.513924,-4.16917 -1.208623,-0.60345 -1.681459,-0.66851 -5.489722,-0.75532 l -4.167187,-0.095 v 5.07711 5.07711 l 4.431771,-0.0884 c 4.088789,-0.0816 4.516382,-0.13693 5.525059,-0.71495 z M 50.1082,70.02704 V 55.21038 h 3.96875 3.96875 V 70.02704 84.84371 H 54.07695 50.1082 Z m 44.979167,0 V 55.21038 h 3.691028 3.691025 l 0.18152,1.60442 0.18152,1.60442 0.82381,-0.77937 c 2.06917,-1.95755 4.67556,-2.95864 7.70297,-2.95864 4.47446,0 7.18345,1.79623 8.66727,5.74693 0.55099,1.46702 0.578,1.99378 0.66524,12.97234 l 0.0909,11.44323 h -3.98412 -3.98412 l -0.006,-10.2526 c -0.005,-9.49956 -0.0427,-10.33391 -0.50837,-11.35954 -0.68985,-1.51943 -1.76217,-2.05434 -4.11603,-2.05323 -2.13211,0.001 -3.69729,0.65937 -4.60792,1.93824 -0.53656,0.75352 -0.55145,1.05744 -0.55145,11.25079 v 10.47634 h -3.968753 -3.96875 z m 62.177083,0 V 55.21038 h 3.66999 3.66999 l 0.18054,1.59571 0.18053,1.59571 1.375,-1.20388 c 2.10568,-1.84364 3.69261,-2.36783 7.19583,-2.37689 2.48679,-0.006 3.10297,0.081 4.23333,0.60084 2.64655,1.21707 4.30564,3.66529 4.87709,7.19684 0.16,0.98876 0.27914,6.07787 0.28029,11.9724 l 0.002,10.2526 h -3.95736 -3.95735 l -0.0775,-10.51719 c -0.0698,-9.46486 -0.12432,-10.60278 -0.54505,-11.3726 -1.28446,-2.35027 -5.84268,-2.54284 -8.15365,-0.34446 l -1.03614,0.98565 v 10.6243 10.6243 h -3.96875 -3.96875 z M 297.7582,63.80933 V 42.77496 h 3.96875 3.96875 l 0.008,11.57552 c 0.008,10.38099 0.0496,11.52091 0.40787,11.04635 0.2197,-0.29104 2.24939,-2.70205 4.51042,-5.35781 l 4.11098,-4.82864 h 4.79107 4.79108 l -0.66523,0.7276 c -0.85533,0.93554 -9.24253,10.64045 -9.70963,11.23512 -0.31198,0.39718 0.32369,1.45513 5.35782,8.91706 3.14148,4.65653 5.71179,8.53102 5.71179,8.60998 0,0.079 -2.01347,0.14357 -4.47438,0.14357 h -4.47437 l -3.79386,-5.94212 -3.79385,-5.94213 -1.38906,1.36903 -1.38907,1.36902 v 4.5731 4.5731 h -3.96875 -3.96875 z M -88.259252,79.94412 c -7.510559,-4.18802 -10.905769,-6.30528 -11.152739,-6.95486 -0.12869,-0.33848 -0.23398,-4.53101 -0.23398,-9.31673 v -8.70132 l 1.12448,-0.76466 c 1.3917,-0.94639 6.916086,-4.14083 11.282459,-6.52401 2.657041,-1.45023 3.437791,-1.76379 4.117164,-1.65355 1.091718,0.17716 13.978735,7.5471 15.22175,8.70514 0.513812,0.47869 0.533317,0.81502 0.533317,9.19616 0,4.78462 -0.07385,8.89176 -0.164116,9.12698 -0.30852,0.804 -14.32707,8.88245 -15.710885,9.05369 -0.780152,0.0966 -1.570604,-0.24482 -5.01745,-2.16684 z m 38.320814,0.97024 c -1.255702,-0.67652 -4.623388,-2.59513 -7.483747,-4.2636 -4.071048,-2.37467 -5.241741,-3.17923 -5.389822,-3.70417 -0.247137,-0.87608 -0.246442,-16.87163 7.72e-4,-17.74842 0.146076,-0.51809 1.093177,-1.19859 4.101042,-2.94662 6.124495,-3.55928 11.265083,-6.30159 11.812606,-6.30159 0.719472,0 13.235724,6.92993 15.18339,8.40666 l 0.859896,0.65198 v 9.08326 9.08325 l -0.859896,0.64465 c -0.933659,0.69994 -8.04528,4.80611 -12.137435,7.00801 -1.400459,0.75356 -2.829209,1.35807 -3.175,1.34336 -0.345791,-0.0147 -1.656104,-0.58026 -2.911806,-1.25677 z m 36.678929,0.006 c -4.276301,-2.27566 -11.892512,-6.76484 -12.501563,-7.36874 -0.593721,-0.5887 -0.595312,-0.61392 -0.595312,-9.43653 0,-8.03951 0.04223,-8.89632 0.46302,-9.39519 0.851922,-1.00998 14.641687,-8.77011 15.584502,-8.77011 0.286767,0 2.8953411,1.3197 5.7968321,2.93267 7.0705614,3.93059 9.5895508,5.47253 9.9059347,6.0637 0.1607395,0.30035 0.2642946,3.92642 0.2642946,9.25451 0,8.7331 -0.00187,8.76254 -0.5953125,9.35351 -1.1573654,1.15254 -14.5209878,8.64092 -15.4119799,8.63618 -0.291041,-0.002 -1.600729,-0.57305 -2.910416,-1.27 z M 52.224866,51.20951 c -1.876567,-0.74609 -2.899498,-2.80593 -2.369992,-4.77236 1.027829,-3.81707 6.93608,-4.15534 8.456721,-0.48419 0.751959,1.81539 -0.190681,4.28668 -1.954317,5.12358 -0.999014,0.47407 -3.104119,0.5418 -4.132412,0.13297 z m -121.576042,-2.552 c -4.594664,-2.56039 -10.363883,-5.97824 -11.244792,-6.66173 l -0.661459,-0.51323 -0.08742,-8.99316 -0.08742,-8.99316 1.275931,-0.92595 c 1.492212,-1.08289 7.350617,-4.45107 11.82132,-6.79643 l 3.132822,-1.643501 2.688012,1.399524 c 3.872457,2.016207 11.492104,6.427647 12.523173,7.250357 l 0.880463,0.70254 -0.02617,7.72778 c -0.01439,4.25028 -0.103688,8.37865 -0.198437,9.17416 -0.156837,1.3168 -0.26709,1.50936 -1.230605,2.14933 -2.632548,1.74852 -13.624219,7.84171 -14.536666,8.05834 -0.355572,0.0844 -1.830212,-0.58712 -4.24875,-1.93487 z m 37.703125,0.56944 c -6.143841,-3.26708 -12.698956,-7.28162 -12.840708,-7.86402 -0.07664,-0.31488 -0.106101,-4.56111 -0.06547,-9.43606 l 0.07388,-8.86354 3.571875,-2.14432 c 1.964531,-1.17938 5.516143,-3.19278 7.89247,-4.47423 l 4.320596,-2.329918 1.235654,0.596364 c 2.012191,0.971144 10.594766,5.812674 12.811176,7.226944 l 2.050521,1.30841 v 9.05652 c 0,10.48406 0.359204,9.24387 -3.307292,11.41884 -3.822541,2.26754 -12.397005,6.99043 -12.683771,6.98634 -0.154446,-0.002 -1.530968,-0.66881 -3.058937,-1.48133 z"/></g></svg></div>
        <div class="box-sub">Sistema de Gestão de Eventos  ·  v0.001</div>
        <?php if (isset($err)) echo "<div class='err'>$err</div>"; ?>
        <?php if ($m = flash('ok')) echo "<div class='ok'>$m</div>"; ?>
        <form method="post">
            <label>Email</label>
            <input name="email" type="email" required autofocus>
            <label>Senha</label>
            <input name="pass" type="password" required>
            <button>Entrar</button>
        </form>
    </div>
    </body></html>
    <?php exit;
}

if ($p === 'logout') {
    session_destroy();
    go('?p=login');
}

// Páginas públicas (sem login)
$public_pages = array('public');
if (!in_array($p, $public_pages) && !logged()) {
    go('?p=login&redirect=' . urlencode($p));
}

// ==================== LAYOUT ====================
function html_start($title = '') {
    global $p;
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $title ?> - Bienenstock</title>
    <meta name="application-name" content="Bienenstock">
    <meta name="version" content="0.001">
    <meta name="author" content="Carlos Eduardo (cadunico)">
    <meta name="license" content="GPL v3 / CC BY-SA 3.0">
    <meta name="description" content="Sistema de Gestão de Eventos - Bienenstock v0.001">
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#f0f0f0;display:flex;min-height:100vh;font-size:14px;color:#222}

    /* ── SIDEBAR ── */
    .nav{width:160px;min-height:100vh;background:#2c2c2c;display:flex;flex-direction:column;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:200}
    .nav-logo{padding:14px 14px 12px;border-bottom:1px solid #3e3e3e}
    .nav-logo img,.nav-logo svg{width:100%;height:auto;display:block}
    .nav-logo .version{color:#555;font-size:10px;margin-top:5px;display:block;letter-spacing:.3px;text-align:center}
    .nav-links{flex:1;padding:10px 0;overflow-y:auto}
    .nav a{display:block;color:#aaa;text-decoration:none;padding:10px 18px;font-size:13px;font-weight:400;border-left:3px solid transparent;transition:all .15s;white-space:nowrap}
    .nav a:hover{color:#fff;background:#363636}
    .nav a.active{color:#fff;background:#383838;border-left-color:#fff;font-weight:500}
    .nav-footer{padding:14px 18px;border-top:1px solid #3e3e3e}
    .nav-footer span{display:block;color:#666;font-size:11px;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .nav-footer a{color:#aaa;text-decoration:none;font-size:12px}
    .nav-footer a:hover{color:#fff}

    /* ── HAMBURGER (só mobile) ── */
    .nav-toggle{display:none;position:fixed;top:12px;left:12px;z-index:300;background:#2c2c2c;border:none;cursor:pointer;padding:8px;border-radius:4px;width:38px;height:38px;flex-direction:column;justify-content:center;align-items:center;gap:5px}
    .nav-toggle span{display:block;width:20px;height:2px;background:#fff;border-radius:2px;transition:all .2s}
    .nav-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:150}

    /* ── CONTEÚDO ── */
    .main{margin-left:160px;flex:1;padding:28px 32px;min-height:100vh;min-width:0}
    .container{max-width:1200px}

    /* ── TIPOGRAFIA ── */
    h1{font-size:20px;font-weight:600;color:#111;margin-bottom:22px;letter-spacing:-.2px}
    h2{font-size:16px;font-weight:600;color:#222;margin-bottom:14px}
    h3{font-size:14px;font-weight:600;color:#333;margin-bottom:10px}

    /* ── TABELAS ── */
    .table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
    table{width:100%;background:#fff;border-collapse:collapse;border:1px solid #e0e0e0;min-width:480px}
    th,td{padding:11px 14px;text-align:left;border-bottom:1px solid #ebebeb;font-size:13px}
    th{background:#f7f7f7;font-weight:600;color:#444;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
    tr:hover td{background:#fafafa}
    tr:last-child td{border-bottom:none}

    /* ── BOTÕES ── */
    .btn{display:inline-block;padding:8px 16px;background:#2c2c2c;color:#fff;text-decoration:none;border:none;cursor:pointer;font-size:13px;font-weight:500;letter-spacing:.1px;transition:background .15s;border-radius:3px}
    .btn:hover{background:#111}
    .btn-sm{padding:5px 10px;font-size:12px}
    .btn-danger{background:#c0392b}.btn-danger:hover{background:#a93226}
    .btn-success{background:#27ae60}.btn-success:hover{background:#219a52}
    .btn-secondary{background:#888}.btn-secondary:hover{background:#666}

    /* ── FORMULÁRIOS ── */
    input,textarea,select{padding:8px 10px;border:1px solid #d0d0d0;border-radius:3px;margin:4px 0;font-size:14px;font-family:inherit;background:#fff;color:#222;width:100%}
    input:focus,textarea:focus,select:focus{outline:none;border-color:#555}
    input[type="file"]{padding:5px 0;border:none;background:transparent}
    input[type="checkbox"],input[type="radio"]{width:auto}
    label{display:block;font-weight:500;margin-top:12px;color:#333;font-size:13px}

    /* ── ALERTAS ── */
    .alert{padding:11px 16px;margin-bottom:20px;font-size:13px;border-radius:3px;border-left:3px solid}
    .success{background:#edfaf3;color:#1a5c38;border-color:#27ae60}
    .error{background:#fdf0ef;color:#7b2020;border-color:#c0392b}

    /* ── CARDS / STATS ── */
    .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
    .card{background:#fff;padding:20px;border:1px solid #e0e0e0;border-radius:3px}
    .card h3{color:#555;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
    .card .num{font-size:30px;font-weight:700;color:#111}

    /* ── BADGES ── */
    .badge{display:inline-block;padding:3px 8px;border-radius:2px;font-size:11px;font-weight:600;letter-spacing:.2px}
    .badge-pending{background:#fef9e7;color:#7d6608;border:1px solid #f9e79f}
    .badge-confirmed{background:#eafaf1;color:#1e8449;border:1px solid #a9dfbf}
    .badge-cancelled{background:#fdedec;color:#922b21;border:1px solid #f5b7b1}

    /* ── OUTROS ── */
    .search{margin-bottom:18px}
    .search input{max-width:360px}
    .empty{text-align:center;padding:50px 20px;color:#888;font-size:14px}
    .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    .form-section{background:#fff;border:1px solid #e0e0e0;padding:22px;margin-bottom:20px;border-radius:3px}

    /* ── CANVAS DO CERTIFICADO — responsivo via scale ── */
    /* O canvas interno continua 800×600 (para o drag funcionar),
       mas o wrapper encolhe e o CSS transform escala proporcionalmente */
    .cert-canvas-wrap{width:100%;overflow:hidden;margin:20px 0}
    .cert-canvas-wrap .cert-canvas-scaler{
        width:800px;height:600px;
        transform-origin:top left;
        /* o scale real é aplicado via JS no resize */
    }

    /* ── BREAKPOINTS ── */

    /* Tablet (≤1024px): sidebar mais estreita */
    @media(max-width:1024px){
        .nav{width:140px}
        .main{margin-left:140px;padding:22px 20px}
    }

    /* Mobile (≤768px): sidebar vira drawer off-canvas */
    @media(max-width:768px){
        .nav{transform:translateX(-100%);transition:transform .25s;width:200px}
        .nav.open{transform:translateX(0)}
        .nav-overlay.open{display:block}
        .nav-toggle{display:flex}
        .main{margin-left:0;padding:16px;padding-top:60px}
        h1{font-size:17px}
        .form-row{grid-template-columns:1fr}
        .page-header{flex-direction:column;align-items:flex-start}
        .stats{grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
        .card{padding:14px}
        .card .num{font-size:24px}
        .btn{padding:8px 12px;font-size:12px}
        .search input{max-width:100%}
        table{font-size:12px}
        th,td{padding:8px 10px}
    }

    /* Telas muito pequenas (≤400px) */
    @media(max-width:400px){
        .main{padding:12px;padding-top:56px}
        .stats{grid-template-columns:1fr 1fr}
    }
    </style>
    </head><body>
    <button class="nav-toggle" id="nav-toggle" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
    <div class="nav-overlay" id="nav-overlay"></div>
    <nav class="nav" id="nav-sidebar">
        <div class="nav-logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="-4 -4 432.92 107.83" style="display:block;width:100%;height:auto"><g transform="translate(99.645971,-14.114862)"><path fill="#ffffff" d="m -68.040987,112.66672 c -3.825554,-2.03104 -12.467662,-7.15842 -12.8588,-7.62915 -0.275916,-0.33206 -0.377854,-2.06875 -0.446101,-7.60016 -0.04865,-3.94336 -0.0079,-8.04143 0.09049,-9.10683 l 0.178949,-1.93709 1.364721,-0.87015 c 2.078073,-1.32498 9.82205,-5.74819 12.259228,-7.00223 l 2.163257,-1.11309 1.673202,0.83192 c 2.645383,1.31531 12.70286,7.0711 13.579451,7.77138 l 0.79375,0.63411 0.09039,8.95487 0.09039,8.95487 -0.619557,0.55567 c -1.534556,1.37633 -14.749727,8.84513 -15.627117,8.83196 -0.18605,-0.003 -1.415563,-0.57703 -2.732251,-1.27608 z m 34.924917,-0.92329 c -6.403989,-3.56863 -11.156407,-6.4774 -11.332449,-6.93616 -0.09026,-0.23522 -0.164116,-4.46754 -0.164116,-9.40515 v -8.97748 l 1.457806,-0.93255 c 1.751411,-1.12036 10.090821,-5.83085 12.727361,-7.189 l 1.882042,-0.96949 3.8065,2.08081 c 6.49338,3.54958 11.456966,6.62009 11.806245,7.30343 0.239726,0.469 0.327869,2.97974 0.33073,9.42078 l 0.0039,8.78138 -2.315104,1.42657 c -6.303558,3.88428 -13.067531,7.59969 -13.83729,7.60075 -0.225327,3e-4 -2.189858,-0.99145 -4.365625,-2.20389 z m 301.43938,2.05336 c -0.10914,-0.11093 -0.19844,-0.65583 -0.19844,-1.21089 0,-0.88264 0.0912,-1.03087 0.7276,-1.18206 0.93587,-0.22235 1.56628,-0.77601 1.75932,-1.54513 0.13361,-0.53236 -0.87648,-3.71769 -3.38081,-10.66135 l -0.40555,-1.12448 h 1.65211 1.65212 l 1.01965,3.44601 c 0.56081,1.8953 1.04605,3.41335 1.07832,3.37343 0.0323,-0.0399 0.50928,-1.59062 1.06003,-3.44601 l 1.00135,-3.37343 h 1.54814 c 0.85147,0 1.54814,0.0979 1.54814,0.21755 0,0.38751 -4.4446,12.93478 -4.88038,13.77748 -0.23226,0.44916 -0.7192,1.01118 -1.08207,1.24895 -0.72941,0.47792 -2.78792,0.79666 -3.09953,0.47993 z m -90.81823,-0.49281 c -0.43657,-0.19724 -1.02215,-0.53305 -1.3013,-0.74626 -0.4984,-0.38067 -0.49726,-0.40432 0.0635,-1.31167 l 0.57107,-0.92402 1.33583,0.66109 c 1.18954,0.5887 1.44718,0.62774 2.35249,0.3565 1.25258,-0.37528 1.5894,-0.76299 1.79349,-2.06449 l 0.15928,-1.0158 -0.57306,0.50774 c -0.79383,0.70334 -3.12447,0.87789 -4.21208,0.31547 -2.3012,-1.19 -3.29976,-4.67005 -2.30305,-8.02632 0.43126,-1.45222 1.72616,-2.84277 2.92816,-3.14445 1.00976,-0.25343 2.98087,0.0418 3.5167,0.52669 0.58945,0.53344 0.82832,0.54247 0.82832,0.0313 0,-0.30038 0.32484,-0.39687 1.33606,-0.39687 h 1.33607 l -0.0793,6.41614 c -0.0777,6.2884 -0.0919,6.43388 -0.71458,7.30688 -0.88609,1.24237 -2.41991,1.87679 -4.52409,1.87123 -0.94589,-0.002 -2.07698,-0.16592 -2.51354,-0.36315 z m 4.30675,-6.64715 c 0.53561,-0.37516 0.58804,-0.63012 0.58804,-2.85985 0,-2.81031 -0.221,-3.28318 -1.64224,-3.51381 -0.84745,-0.13753 -1.08739,-0.0505 -1.78507,0.64714 -0.91658,0.91658 -1.16013,2.53328 -0.67663,4.49148 0.36837,1.49194 2.22034,2.14249 3.5159,1.23504 z m -167.510575,2.61674 c -0.09701,-0.097 -0.176389,-3.54983 -0.176389,-7.67292 v -7.49652 h 5.027083 5.027084 v 1.19062 1.19063 h -3.439584 -3.439583 v 1.98437 1.98438 h 3.042708 3.042709 v 1.32291 1.32292 h -3.042709 -3.042708 v 3.175 3.175 h -1.411111 c -0.776111,0 -1.490486,-0.0794 -1.5875,-0.17639 z m 11.388969,0.0119 c -0.103551,-0.10355 -0.188275,-2.66875 -0.188275,-5.70043 v -5.51215 h 1.455209 c 1.299462,0 1.455208,0.0592 1.455208,0.55268 0,0.53186 0.03067,0.52688 0.81405,-0.13229 0.573052,-0.48219 1.121359,-0.68498 1.852084,-0.68498 h 1.038033 v 1.45521 1.45521 h -1.161314 c -2.104261,0 -2.264218,0.32518 -2.342704,4.7625 l -0.06786,3.83646 -1.33308,0.078 c -0.733194,0.0429 -1.417803,-0.007 -1.521354,-0.11024 z m 10.251821,-0.33283 c -2.184694,-0.99237 -3.177675,-2.95502 -2.969203,-5.86869 0.148214,-2.07149 0.675543,-3.13399 2.144812,-4.32155 0.941381,-0.76089 1.168447,-0.82181 3.063304,-0.82181 1.814565,0 2.152475,0.0808 2.980916,0.71267 1.213787,0.9258 1.838611,2.28443 2.005235,4.36021 l 0.134372,1.674 h -3.608099 c -4.05644,0 -4.299987,0.14257 -2.802206,1.64035 0.731584,0.73158 0.927005,0.79425 2.119347,0.67969 0.744711,-0.0716 1.543404,-0.33431 1.8445,-0.6068 0.516621,-0.46754 0.552475,-0.46006 1.320034,0.27531 l 0.788989,0.7559 -0.595111,0.64625 c -0.327311,0.35544 -0.978239,0.8095 -1.446508,1.00902 -1.20867,0.51501 -3.698465,0.44775 -4.980382,-0.13455 z m 4.26168,-7.10944 c -0.387235,-1.63566 -2.287477,-2.18575 -3.46901,-1.00422 -0.357188,0.35719 -0.649432,0.89297 -0.649432,1.19063 0,0.50071 0.160497,0.54119 2.14535,0.54119 h 2.145349 z m 7.379987,7.10944 c -1.937554,-0.88011 -3.028811,-2.75046 -3.028811,-5.19121 0,-3.52399 2.157195,-5.95313 5.286673,-5.95313 3.200423,0 5.023443,2.03868 5.02765,5.6224 l 0.0015,1.25677 h -3.571875 c -4.001387,0 -4.258568,0.14776 -2.798478,1.60785 0.964087,0.96409 2.544096,1.05554 3.887894,0.22502 l 0.887293,-0.54837 0.640421,0.74453 c 0.803147,0.93371 0.583725,1.42329 -0.97639,2.17853 -1.465234,0.70931 -3.864335,0.73511 -5.355853,0.0576 z m 4.376571,-6.92303 c 0,-1.21206 -1.73677,-2.17229 -3.030854,-1.6757 -0.427649,0.1641 -1.202479,1.41363 -1.202479,1.93917 0,0.17076 0.815184,0.27772 2.116666,0.27772 1.955957,0 2.116667,-0.0411 2.116667,-0.54119 z m 10.31875,-0.24684 v -7.67863 h 5.159375 5.159375 v 1.19062 1.19063 h -3.571875 -3.571875 v 1.85208 1.85208 h 3.042709 3.042708 v 1.19063 1.19062 h -3.042708 -3.042709 v 2.11667 2.11667 h 3.586372 3.586372 l -0.08064,1.25677 -0.08064,1.25677 -5.09323,0.0719 -5.093229,0.0719 z m 14.42411,7.3504 c -0.06713,-0.18956 -0.95232,-2.76119 -1.967085,-5.71472 l -1.845029,-5.37007 1.619514,0.0784 1.619514,0.0784 0.902828,3.30729 c 0.496556,1.81901 0.991319,3.40024 1.099475,3.51384 0.108156,0.1136 0.460393,-0.77937 0.782751,-1.98437 0.322357,-1.20501 0.767687,-2.81601 0.989621,-3.57999 l 0.403517,-1.38906 h 1.551651 1.551651 l -1.901155,5.62239 -1.901154,5.6224 -1.392021,0.0801 c -0.963922,0.0555 -1.429559,-0.0259 -1.514078,-0.26458 z m 10.500624,-0.24628 c -1.488992,-0.73704 -2.378771,-1.94535 -2.755654,-3.74215 -0.365125,-1.74075 0.01504,-3.88728 0.933251,-5.26949 0.779792,-1.17384 2.600759,-2.06695 4.214313,-2.06695 3.121046,0 4.962523,2.02736 4.962523,5.46344 v 1.41573 h -3.589705 -3.589704 l 0.171503,0.59531 c 0.486871,1.69 2.807929,2.32823 4.566905,1.25578 0.47646,-0.29049 0.914837,-0.52817 0.974171,-0.52817 0.05933,0 0.365208,0.36138 0.679721,0.80307 0.487204,0.68422 0.519241,0.86664 0.216454,1.23256 -1.159956,1.40179 -4.751258,1.84694 -6.783778,0.84087 z m 4.444016,-6.85728 c 0,-0.80906 -1.166069,-1.84006 -2.08112,-1.84006 -0.548699,0 -0.98677,0.24305 -1.467235,0.81405 -1.149599,1.36622 -0.965998,1.5672 1.431689,1.5672 1.955957,0 2.116666,-0.0411 2.116666,-0.54119 z m 4.7625,1.73182 v -5.68854 h 1.455209 c 1.271398,0 1.455208,0.0658 1.455208,0.52119 0,0.4943 0.04968,0.48748 0.96296,-0.13229 1.37143,-0.93068 3.44299,-0.91935 4.56525,0.025 1.13232,0.95278 1.3478,2.04005 1.34948,6.80922 l 10e-4,4.18039 -1.52135,-0.0794 -1.52136,-0.0793 -0.13229,-4.30976 c -0.14123,-4.60104 -0.20658,-4.80551 -1.53875,-4.81432 -0.67753,-0.004 -1.66185,0.44455 -1.962927,0.89546 -0.106912,0.16011 -0.196209,2.10682 -0.198437,4.32601 l -0.0041,4.0349 h -1.455208 -1.455209 z m 14.102967,5.37793 c -1.09856,-0.54231 -1.40297,-1.7322 -1.40297,-5.48406 v -3.46575 h -0.79375 c -0.74741,0 -0.79375,-0.0608 -0.79375,-1.04076 0,-0.93869 0.0714,-1.04897 0.72761,-1.12448 0.67864,-0.0781 0.73298,-0.1772 0.80758,-1.47278 l 0.08,-1.38906 h 1.42755 1.42756 l 0.08,1.38906 0.08,1.38906 0.99219,0.0821 c 0.96473,0.0798 0.99219,0.11096 0.99219,1.12448 0,1.0397 -0.003,1.04236 -1.05834,1.04236 h -1.05833 v 2.7609 c 0,3.42823 0.22312,4.11827 1.33158,4.11827 0.7958,0 0.81355,0.0272 0.73416,1.12448 l -0.0814,1.12448 -1.45521,0.0543 c -0.80037,0.0299 -1.71684,-0.0748 -2.03662,-0.23267 z m 10.94426,0.13422 c -0.097,-0.097 -0.17639,-3.55464 -0.17639,-7.68362 v -7.50722 l 2.15518,0.0768 2.15518,0.0769 1.92481,5.42396 c 1.46406,4.12562 1.97542,5.29729 2.13627,4.89479 0.1163,-0.29104 0.98958,-2.73183 1.94062,-5.42396 l 1.72915,-4.89479 2.04918,-0.0773 2.04919,-0.0773 v 7.68404 7.68405 h -1.47833 -1.47833 l 0.0893,-5.53828 c 0.0491,-3.04605 0.004,-5.38527 -0.10056,-5.19826 -0.15348,0.27492 -1.55903,4.07722 -3.70497,10.02265 -0.2404,0.66603 -0.3751,0.73321 -1.32626,0.66146 l -1.05868,-0.0799 -1.94511,-5.39366 c -1.06981,-2.96652 -1.98442,-5.43297 -2.03246,-5.48101 -0.048,-0.048 -0.037,2.40887 0.0246,5.45981 l 0.11197,5.54715 h -1.44398 c -0.79419,0 -1.52336,-0.0794 -1.62037,-0.17639 z m 20.30724,-0.0657 c -0.30291,-0.1304 -0.86846,-0.61467 -1.25677,-1.07616 -0.54987,-0.65347 -0.70603,-1.11928 -0.70603,-2.10599 0,-2.27967 1.3445,-3.30574 4.74589,-3.62186 1.98032,-0.18405 2.16559,-0.36217 1.5645,-1.50415 -0.62313,-1.18385 -1.76279,-1.31311 -2.76257,-0.31333 -0.52754,0.52755 -0.92684,0.66146 -1.97234,0.66146 -1.12677,0 -1.3109,-0.073 -1.3109,-0.51996 0,-0.80811 1.45126,-2.26588 2.59768,-2.60936 2.42538,-0.72666 4.85412,-0.17396 5.98248,1.36141 0.48045,0.65376 0.56656,1.25688 0.69886,4.89479 0.083,2.28204 0.20206,4.32775 0.26459,4.54603 0.0888,0.30982 -0.18741,0.4143 -1.25897,0.47628 -1.1755,0.068 -1.41604,-0.002 -1.6747,-0.48497 -0.29032,-0.54246 -0.32217,-0.54617 -0.82007,-0.0956 -0.58581,0.53014 -3.19055,0.7793 -4.09165,0.39138 z m 4.05647,-2.56009 c 0.45302,-0.29302 0.59531,-0.63657 0.59531,-1.43729 v -1.05224 h -1.07572 c -1.68059,0 -2.61644,0.63151 -2.62439,1.77094 -0.009,1.28024 1.64474,1.66298 3.1048,0.71859 z m 5.88698,-2.8864 v -5.68854 h 1.45521 c 1.2714,0 1.45521,0.0658 1.45521,0.52119 0,0.4943 0.0497,0.48748 0.96296,-0.13229 1.37143,-0.93068 3.44298,-0.91935 4.56525,0.025 1.13179,0.95234 1.3478,2.04016 1.34948,6.79601 l 0.001,4.16719 h -1.43197 -1.43196 l -0.0894,-4.25502 c -0.0808,-3.84806 -0.13852,-4.29091 -0.60298,-4.63021 -0.73262,-0.53519 -1.81603,-0.46373 -2.62573,0.17318 l -0.69714,0.54837 v 4.08184 4.08184 h -1.45521 -1.45521 z m 13.27939,5.15259 c -0.4817,-0.29368 -1.04724,-0.94426 -1.25677,-1.44572 -1.14229,-2.73389 0.60978,-4.71344 4.49272,-5.07603 l 1.76854,-0.16515 -0.08,-0.81209 c -0.0538,-0.54696 -0.3019,-0.93605 -0.75975,-1.19176 -0.85707,-0.47868 -2.10379,-0.20231 -2.28941,0.5075 -0.11484,0.43918 -0.36983,0.51712 -1.69187,0.51712 -1.74844,0 -1.85769,-0.16443 -1.02102,-1.53674 1.08345,-1.77707 4.44095,-2.45452 6.81799,-1.37569 1.77253,0.80447 2.10329,1.84248 2.21372,6.94732 l 0.0901,4.16719 h -1.41392 c -1.17333,0 -1.4366,-0.0867 -1.54719,-0.50962 -0.13151,-0.50288 -0.14431,-0.50289 -0.96742,-9.9e-4 -1.13149,0.68994 -3.20235,0.6779 -4.3558,-0.0253 z m 4.51384,-2.31872 c 0.29093,-0.25281 0.46302,-0.78045 0.46302,-1.41967 v -1.01733 h -1.13175 c -1.54467,0 -2.30783,0.57649 -2.30783,1.74332 0,0.70827 0.13679,0.97305 0.59531,1.15233 0.7241,0.28313 1.75644,0.0843 2.38125,-0.45865 z m 20.96823,2.41503 c -2.09834,-1.10622 -3.03109,-2.76215 -3.03109,-5.38119 0,-2.08494 0.58506,-3.5086 1.92557,-4.68558 1.08574,-0.95329 2.25872,-1.25702 4.15194,-1.07511 2.46724,0.23707 3.91744,2.02734 4.1677,5.14502 l 0.13437,1.674 h -3.71562 c -3.59266,0 -3.71101,0.0176 -3.57642,0.5323 0.0766,0.29277 0.50649,0.84136 0.9554,1.21909 0.6958,0.58548 1.00375,0.66876 2.08766,0.56461 0.70763,-0.068 1.50697,-0.33529 1.80252,-0.60276 0.51661,-0.46752 0.55245,-0.46009 1.31821,0.27356 0.75475,0.7231 0.76818,0.77513 0.32617,1.26391 -1.31063,1.4493 -4.75956,2.01415 -6.54641,1.07215 z m 4.26164,-6.85804 c -0.0883,-0.23018 -0.1606,-0.52784 -0.1606,-0.66146 0,-0.53352 -1.09983,-1.30128 -1.86411,-1.30128 -0.9387,0 -2.10464,1.01937 -2.10464,1.84006 0,0.5007 0.1605,0.54119 2.14498,0.54119 1.84723,0 2.12268,-0.0581 1.98437,-0.41851 z m 4.77829,7.12129 c -0.097,-0.097 -0.17639,-2.65686 -0.17639,-5.68854 v -5.51215 h 1.45521 c 1.29479,0 1.45521,0.0603 1.45521,0.54718 0,0.54422 0.004,0.54399 0.82755,-0.0421 1.57333,-1.1203 4.61132,-0.91943 5.2906,0.34981 0.27488,0.51364 0.28164,0.51361 0.84978,-0.003 0.31498,-0.28654 0.83276,-0.65492 1.15061,-0.81862 0.85096,-0.43827 2.93562,-0.36203 3.78268,0.13835 1.36847,0.80837 1.59253,1.75336 1.59253,6.71673 v 4.48894 h -1.45521 -1.45521 v -4.10104 c 0,-3.74827 -0.0455,-4.14656 -0.52916,-4.63021 -0.68115,-0.68115 -1.85878,-0.67647 -2.59595,0.0103 -0.54745,0.51003 -0.57905,0.76271 -0.57905,4.6302 v 4.09074 h -1.43701 -1.437 l -0.0844,-4.25521 c -0.0763,-3.85166 -0.13306,-4.29077 -0.59794,-4.63021 -0.67421,-0.49227 -1.73744,-0.4764 -2.47025,0.0369 -0.56585,0.39634 -0.58804,0.57104 -0.58804,4.63021 v 4.21833 h -1.41111 c -0.77611,0 -1.49049,-0.0794 -1.5875,-0.17639 z m 21.38715,-0.26251 c -1.18057,-0.62129 -2.26768,-1.70152 -2.67842,-2.66146 -0.53135,-1.24186 -0.45711,-4.21467 0.13733,-5.4986 0.96091,-2.07547 2.51733,-3.04583 4.87342,-3.03835 2.81893,0.009 4.54991,1.83823 4.85763,5.13348 l 0.16261,1.74133 h -3.71766 c -3.5947,0 -3.71305,0.0176 -3.57846,0.5323 0.0766,0.29277 0.50649,0.84136 0.9554,1.21909 0.6958,0.58548 1.00375,0.66876 2.08767,0.56461 0.70763,-0.068 1.50697,-0.33529 1.80252,-0.60276 0.5166,-0.46752 0.55244,-0.46009 1.3182,0.27356 0.75589,0.72419 0.76885,0.77476 0.32617,1.27312 -1.23629,1.39181 -4.81726,1.97365 -6.54641,1.06368 z m 4.10105,-7.10173 c 0,-2.16637 -3.1815,-2.27688 -3.92767,-0.13642 l -0.27814,0.79788 h 2.1029 c 2.06671,0 2.10291,-0.0114 2.10291,-0.66146 z m 4.95077,7.37613 c -0.10355,-0.10355 -0.18827,-2.66875 -0.18827,-5.70043 v -5.51215 h 1.4552 c 1.29479,0 1.45521,0.0603 1.45521,0.54718 0,0.54309 0.006,0.54273 0.83565,-0.0479 1.08899,-0.77543 3.27278,-0.97892 4.37706,-0.40787 1.43104,0.74002 1.66646,1.69521 1.66646,6.76154 v 4.52408 h -1.45521 -1.45521 v -4.10104 c 0,-3.74827 -0.0455,-4.14656 -0.52916,-4.63021 -0.65266,-0.65266 -1.87235,-0.68351 -2.61512,-0.0661 -0.51648,0.4293 -0.56198,0.76175 -0.62462,4.56407 l -0.0676,4.10104 -1.33308,0.078 c -0.73319,0.0429 -1.4178,-0.007 -1.52135,-0.11024 z m 13.71707,-0.41893 c -0.92263,-0.7959 -1.19134,-2.0734 -1.19903,-5.70042 l -0.006,-2.97657 h -0.79375 c -0.74966,0 -0.79375,-0.0588 -0.79375,-1.05833 0,-0.99954 0.0441,-1.05833 0.79375,-1.05833 0.78573,0 0.79375,-0.0147 0.79375,-1.45521 v -1.45521 h 1.5875 1.5875 v 1.45521 1.45521 h 0.92604 c 0.90399,0 0.92604,0.0252 0.92604,1.05833 0,1.03313 -0.0221,1.05833 -0.92604,1.05833 h -0.92604 v 3.12209 c 0,3.39593 0.13371,3.75708 1.39104,3.75708 0.72584,0 0.7528,0.0449 0.67469,1.12448 l -0.0814,1.12448 -1.62508,0.078 c -1.38026,0.0663 -1.73111,-0.0134 -2.32895,-0.52917 z m 14.34264,0.31998 c -2.03491,-0.61272 -3.64173,-2.34892 -3.64173,-3.93497 0,-0.82662 0.004,-0.82866 1.43469,-0.82866 1.40639,0 1.44074,0.0183 1.74182,0.93064 0.36471,1.10506 1.38209,1.71519 2.86008,1.71519 1.23252,0 2.43007,-0.82797 2.43007,-1.68012 0,-1.06756 -0.72512,-1.65285 -3.18646,-2.57203 -3.77042,-1.40804 -5.32694,-3.13354 -4.89649,-5.42804 0.78563,-4.18778 8.67082,-4.86313 10.66264,-0.91323 0.95654,1.89687 0.85574,2.12773 -0.92604,2.12103 -1.45378,-0.005 -1.53898,-0.0437 -1.91823,-0.8599 -0.72936,-1.56976 -3.00642,-1.94879 -4.24809,-0.70712 -1.14889,1.14889 -0.33676,2.29317 2.23916,3.15491 2.8054,0.9385 4.36362,1.99469 4.97203,3.37011 1.43588,3.24605 -0.81486,5.92324 -4.93739,5.8729 -1.02419,-0.0125 -2.18792,-0.12083 -2.58606,-0.24071 z m 22.81674,-0.0338 c -1.17385,-0.46807 -2.64597,-2.20905 -2.64597,-3.12919 0,-0.16025 0.61556,-0.27773 1.4552,-0.27773 0.80037,0 1.45521,0.11907 1.45521,0.26459 0,0.14552 0.23813,0.5027 0.52917,0.79375 1.03267,1.03267 3.61781,0.45938 3.38478,-0.75063 -0.10141,-0.5266 -0.96467,-0.96946 -2.92058,-1.49829 -0.87714,-0.23715 -1.91239,-0.75538 -2.4474,-1.22512 -0.80147,-0.70371 -0.92722,-0.9753 -0.92722,-2.00263 0,-1.50269 0.7002,-2.52466 2.11861,-3.0922 2.53572,-1.01459 5.75827,-0.17252 6.69789,1.75021 0.26516,0.5426 0.40696,1.10815 0.31511,1.25677 -0.0918,0.14862 -0.7511,0.27022 -1.465,0.27022 -1.07678,0 -1.35142,-0.10145 -1.61149,-0.59531 -0.4187,-0.79508 -0.74308,-0.98332 -1.70272,-0.98814 -0.96313,-0.005 -1.44198,0.40866 -1.44198,1.24516 0,0.67943 0.14319,0.75632 3.17177,1.70324 1.6417,0.5133 2.08759,0.77809 2.60245,1.54547 1.11819,1.66659 0.40525,3.62619 -1.67914,4.6153 -0.97612,0.4632 -3.84571,0.53043 -4.88869,0.11453 z m 11.11757,-0.0364 c -1.12231,-0.57852 -1.32002,-1.36817 -1.32423,-5.28879 l -0.004,-3.63802 h -0.92604 c -0.90151,0 -0.92604,-0.0276 -0.92604,-1.04157 0,-0.98171 0.0494,-1.04633 0.8599,-1.12448 0.83855,-0.0808 0.86188,-0.11738 0.93986,-1.47197 l 0.08,-1.38906 h 1.44138 1.44138 v 1.45521 1.45521 h 0.92604 c 0.90399,0 0.92604,0.0252 0.92604,1.05833 0,1.03313 -0.022,1.05833 -0.92604,1.05833 h -0.92596 v 3.12209 c 0,3.39593 0.13371,3.75708 1.39105,3.75708 0.72584,0 0.75279,0.0449 0.67468,1.12448 l -0.0814,1.12448 -1.4552,0.0685 c -0.80248,0.0378 -1.74957,-0.0832 -2.11146,-0.26979 z m 7.64318,-0.28224 c -0.75489,-0.39869 -1.43786,-1.05463 -1.93675,-1.86012 -0.67482,-1.08955 -0.76907,-1.48823 -0.758,-3.20656 0.0238,-3.69026 1.71144,-5.7098 4.93293,-5.90303 3.17042,-0.19016 5.08319,1.66919 5.2499,5.10327 l 0.0771,1.5875 -3.63802,0.0736 c -2.00091,0.0405 -3.63802,0.12418 -3.63802,0.18599 0,0.0618 0.14224,0.42456 0.31609,0.80612 0.65335,1.43393 2.81879,1.87355 4.42007,0.89734 l 0.89971,-0.5485 0.68464,0.68464 c 0.85408,0.85408 0.61878,1.35405 -1.03552,2.20028 -1.61792,0.82761 -3.98467,0.81889 -5.5741,-0.0205 z m 4.52244,-6.84158 c 0,-0.83062 -1.01576,-1.80299 -1.88345,-1.80299 -0.8367,0 -1.96074,0.9067 -2.21404,1.78594 -0.16776,0.58234 -0.12498,0.59531 1.963,0.59531 2.01851,0 2.13449,-0.0314 2.13449,-0.57826 z m 4.95078,7.29293 c -0.10355,-0.10355 -0.18828,-2.66875 -0.18828,-5.70043 v -5.51215 h 1.45521 c 1.2714,0 1.45521,0.0658 1.45521,0.52119 0,0.4943 0.0497,0.48748 0.96296,-0.13229 1.44405,-0.97996 3.47198,-0.92492 4.66976,0.12674 l 0.88864,0.78024 0.71408,-0.71409 c 0.63767,-0.63767 0.90935,-0.71409 2.53884,-0.71409 1.56736,0 1.92112,0.0905 2.50793,0.64182 1.00464,0.9438 1.20843,2.07445 1.21006,6.71358 l 10e-4,4.18039 -1.52135,-0.0794 -1.52136,-0.0793 -0.0701,-4.23334 c -0.0596,-3.59954 -0.13652,-4.28284 -0.51359,-4.56406 -0.63183,-0.4712 -1.77728,-0.4088 -2.42827,0.13229 -0.51649,0.4293 -0.56199,0.76175 -0.62463,4.56407 l -0.0676,4.10104 h -1.4552 -1.45521 l -0.13229,-4.30976 c -0.14266,-4.64758 -0.19613,-4.80603 -1.62434,-4.81432 -0.38406,-0.002 -0.94897,0.20431 -1.25536,0.45897 -0.51649,0.4293 -0.56199,0.76175 -0.62462,4.56407 l -0.0676,4.10104 -1.33308,0.078 c -0.73319,0.0429 -1.4178,-0.007 -1.52135,-0.11024 z M 74.12751,84.96199 c -4.647397,-1.12668 -8.364276,-4.62851 -9.802582,-9.23542 -0.472284,-1.51273 -0.582511,-2.50986 -0.586174,-5.30265 -0.0052,-3.93271 0.300898,-5.47528 1.653606,-8.33438 1.114017,-2.3546 3.467718,-4.86353 5.554776,-5.92112 4.186021,-2.12122 10.178659,-1.97369 13.74267,0.33833 4.063475,2.63603 5.899644,6.69248 5.899644,13.03345 v 3.13268 h -9.392708 -9.392709 v 0.6095 c 0,0.98992 1.29614,3.29865 2.330595,4.15133 2.897542,2.3884 8.180166,2.0459 11.113454,-0.72055 l 0.960562,-0.90592 1.399279,1.54167 c 0.769604,0.84792 1.635606,1.85684 1.924448,2.24205 l 0.525168,0.70038 -1.573536,1.45681 c -2.777069,2.57108 -5.797096,3.63911 -10.187121,3.60268 -1.461015,-0.0121 -3.337232,-0.1871 -4.169372,-0.38884 z m 8.789023,-18.4588 c 0,-1.1932 -0.790838,-3.25352 -1.515134,-3.94728 -1.458969,-1.39745 -3.689548,-1.88048 -5.65528,-1.22463 -1.782474,0.5947 -3.311474,2.57446 -3.803989,4.92545 l -0.180142,0.8599 h 5.577273 5.577272 z m 53.388057,18.4588 c -5.19582,-1.25964 -8.99767,-5.27303 -10.1611,-10.7265 -0.51362,-2.40752 -0.35332,-7.32629 0.3096,-9.50011 1.93196,-6.33521 6.78748,-10.05417 13.12684,-10.05417 4.05719,0 6.85962,1.0479 9.29151,3.47433 2.70388,2.69781 3.88225,6.19333 3.8905,11.54077 l 0.005,2.97657 h -9.42733 -9.42733 l 0.15353,0.94611 c 0.36364,2.24084 2.50353,4.55249 4.78513,5.16921 0.59604,0.16111 1.97667,0.23978 3.06808,0.17483 2.24141,-0.1334 4.04023,-0.89769 5.49668,-2.33545 l 0.86647,-0.85535 1.51478,1.67382 c 2.75517,3.04443 2.67183,2.62887 0.86306,4.30347 -2.74694,2.54318 -5.84162,3.63736 -10.18564,3.60131 -1.46102,-0.0121 -3.33724,-0.1871 -4.16938,-0.38884 z m 8.78903,-18.22922 c 0,-1.23705 -0.66979,-3.19747 -1.36165,-3.98545 -1.61801,-1.84282 -4.95917,-2.24153 -7.07121,-0.84385 -1.07072,0.70857 -2.2711,2.69197 -2.55458,4.22097 l -0.18395,0.99219 h 5.58569 c 4.70445,0 5.5857,-0.0606 5.5857,-0.38386 z m 51.00443,18.2452 c -4.43887,-1.12441 -7.96143,-4.53462 -8.54265,-8.2702 l -0.17496,-1.12448 h 3.85971 3.85972 v 0.62233 c 0,0.85908 1.0179,2.28985 2.06355,2.90054 1.86761,1.09074 5.97403,0.82149 7.18859,-0.47136 0.78999,-0.8409 0.98448,-2.33677 0.41652,-3.20357 -0.56016,-0.85493 -2.59413,-1.80813 -5.03845,-2.36123 -6.37022,-1.44146 -9.92549,-3.68358 -10.99906,-6.93652 -0.17872,-0.54152 -0.25889,-1.74829 -0.18875,-2.84115 0.3328,-5.18582 5.0031,-8.6194 11.70642,-8.60653 4.60539,0.009 7.66639,1.09913 10.10849,3.60047 1.10096,1.12767 1.5035,1.78893 1.9304,3.17106 0.90707,2.93675 1.1372,2.74888 -3.36728,2.74888 h -3.90664 l -0.47462,-1.33632 c -0.5314,-1.4962 -1.44319,-2.22205 -3.15442,-2.51116 -2.83306,-0.47864 -5.22642,0.89131 -5.22642,2.99159 0,2.08024 0.96154,2.6586 6.66429,4.00852 4.16663,0.98631 6.49374,2.08475 8.02514,3.78804 1.49894,1.66718 2.08569,3.41857 1.91918,5.72858 -0.26261,3.64342 -2.50152,6.22971 -6.68707,7.72457 -2.08384,0.74425 -7.69665,0.95676 -9.98169,0.37794 z m 28.89974,0.14749 c -2.44314,-0.74587 -3.87475,-2.07079 -4.74195,-4.38859 -0.51752,-1.38318 -0.54972,-2.00345 -0.54972,-10.58746 v -9.1182 h -2.24896 -2.24896 v -2.89972 -2.89971 l 2.18281,-0.0769 2.18282,-0.0769 0.0737,-3.64505 0.0736,-3.64505 3.8951,0.0732 3.89509,0.0732 0.0736,3.63803 0.0736,3.63802 h 2.50608 2.50607 v 2.91041 2.91042 h -2.51354 -2.51354 v 8.18733 8.18733 l 0.63876,0.74236 c 0.59747,0.69437 0.76848,0.74165 2.64583,0.73149 l 2.00708,-0.0109 v 2.96551 2.9655 l -1.33594,0.28651 c -1.50368,0.32248 -5.59395,0.3467 -6.60156,0.0391 z m 19.84375,-0.23261 c -4.36692,-1.14144 -7.64063,-4.4843 -9.117,-9.30956 -0.66591,-2.17639 -0.82695,-7.32768 -0.30606,-9.78958 1.46842,-6.9402 6.59585,-11.1125 13.65639,-11.1125 6.95207,0 11.95486,3.87694 13.65671,10.58333 0.59351,2.33881 0.5839,7.48062 -0.0183,9.76843 -1.32857,5.04769 -4.84921,8.71079 -9.5484,9.93478 -2.06076,0.53676 -6.12316,0.5002 -8.32338,-0.0749 z m 6.74687,-6.37005 c 1.33252,-0.61499 2.0737,-1.41771 2.77692,-3.00744 1.18329,-2.67504 1.20329,-8.25638 0.039,-10.88844 -1.74928,-3.95453 -6.70015,-4.8313 -9.46964,-1.67702 -1.40306,1.598 -1.81364,3.26512 -1.80811,7.34173 0.005,3.77249 0.27376,4.85521 1.70264,6.86187 1.23871,1.73961 4.5176,2.40386 6.75918,1.3693 z m 25.03285,6.43189 c -4.57257,-1.22972 -7.77276,-4.60529 -9.03134,-9.52629 -0.65592,-2.56463 -0.71483,-7.86327 -0.11568,-10.4051 1.22864,-5.21242 4.7709,-8.89845 9.57943,-9.96825 2.41645,-0.5376 6.42797,-0.40043 8.3973,0.28715 4.19995,1.46639 7.0661,5.09312 7.47556,9.45932 l 0.14267,1.52136 h -3.7071 -3.7071 l -0.17823,-1.12448 c -0.82406,-5.1989 -8.19697,-5.59452 -9.98698,-0.53589 -0.24209,0.68415 -0.35346,2.42222 -0.35346,5.51596 0,4.49856 0.003,4.52339 0.79375,6.06505 1.00355,1.95715 2.37378,2.78231 4.60568,2.77355 2.59739,-0.0102 4.5421,-1.34925 4.95455,-3.41151 l 0.16321,-0.81602 h 3.70794 3.70795 l -0.1591,1.34334 c -0.4755,4.01489 -3.97013,7.67169 -8.47874,8.87219 -1.75522,0.46736 -5.98664,0.44007 -7.81031,-0.0504 z M 14.124866,64.84476 V 44.84582 l 9.458854,0.11659 c 10.153726,0.12515 10.889517,0.21293 13.966633,1.66619 3.076886,1.45316 4.966202,4.09591 5.40042,7.55403 0.537479,4.28051 -1.168434,7.91203 -4.534315,9.6526 l -0.875436,0.4527 1.185017,0.50328 c 4.111341,1.74609 6.006923,5.91676 5.030136,11.06734 -0.864654,4.55931 -4.046931,7.48649 -9.313384,8.56682 -1.628047,0.33396 -3.882661,0.41834 -11.178646,0.41834 h -9.139279 z m 18.117993,13.08925 c 1.350195,-0.37484 2.900481,-1.92699 3.318617,-3.32261 0.431367,-1.43977 0.13238,-3.77241 -0.628282,-4.90173 -0.3235,-0.48029 -1.119356,-1.16851 -1.768568,-1.52938 -1.107567,-0.61565 -1.470101,-0.66235 -5.876739,-0.75711 L 22.591533,67.3222 v 5.45346 5.45347 l 4.299479,-0.001 c 2.364714,-8.1e-4 4.773045,-0.13295 5.351847,-0.29364 z m 0.305504,-17.13243 c 1.540757,-0.88292 2.214003,-2.20002 2.214003,-4.33133 0,-2.15839 -0.651853,-3.23944 -2.513924,-4.16917 -1.208623,-0.60345 -1.681459,-0.66851 -5.489722,-0.75532 l -4.167187,-0.095 v 5.07711 5.07711 l 4.431771,-0.0884 c 4.088789,-0.0816 4.516382,-0.13693 5.525059,-0.71495 z M 50.1082,70.02704 V 55.21038 h 3.96875 3.96875 V 70.02704 84.84371 H 54.07695 50.1082 Z m 44.979167,0 V 55.21038 h 3.691028 3.691025 l 0.18152,1.60442 0.18152,1.60442 0.82381,-0.77937 c 2.06917,-1.95755 4.67556,-2.95864 7.70297,-2.95864 4.47446,0 7.18345,1.79623 8.66727,5.74693 0.55099,1.46702 0.578,1.99378 0.66524,12.97234 l 0.0909,11.44323 h -3.98412 -3.98412 l -0.006,-10.2526 c -0.005,-9.49956 -0.0427,-10.33391 -0.50837,-11.35954 -0.68985,-1.51943 -1.76217,-2.05434 -4.11603,-2.05323 -2.13211,0.001 -3.69729,0.65937 -4.60792,1.93824 -0.53656,0.75352 -0.55145,1.05744 -0.55145,11.25079 v 10.47634 h -3.968753 -3.96875 z m 62.177083,0 V 55.21038 h 3.66999 3.66999 l 0.18054,1.59571 0.18053,1.59571 1.375,-1.20388 c 2.10568,-1.84364 3.69261,-2.36783 7.19583,-2.37689 2.48679,-0.006 3.10297,0.081 4.23333,0.60084 2.64655,1.21707 4.30564,3.66529 4.87709,7.19684 0.16,0.98876 0.27914,6.07787 0.28029,11.9724 l 0.002,10.2526 h -3.95736 -3.95735 l -0.0775,-10.51719 c -0.0698,-9.46486 -0.12432,-10.60278 -0.54505,-11.3726 -1.28446,-2.35027 -5.84268,-2.54284 -8.15365,-0.34446 l -1.03614,0.98565 v 10.6243 10.6243 h -3.96875 -3.96875 z M 297.7582,63.80933 V 42.77496 h 3.96875 3.96875 l 0.008,11.57552 c 0.008,10.38099 0.0496,11.52091 0.40787,11.04635 0.2197,-0.29104 2.24939,-2.70205 4.51042,-5.35781 l 4.11098,-4.82864 h 4.79107 4.79108 l -0.66523,0.7276 c -0.85533,0.93554 -9.24253,10.64045 -9.70963,11.23512 -0.31198,0.39718 0.32369,1.45513 5.35782,8.91706 3.14148,4.65653 5.71179,8.53102 5.71179,8.60998 0,0.079 -2.01347,0.14357 -4.47438,0.14357 h -4.47437 l -3.79386,-5.94212 -3.79385,-5.94213 -1.38906,1.36903 -1.38907,1.36902 v 4.5731 4.5731 h -3.96875 -3.96875 z M -88.259252,79.94412 c -7.510559,-4.18802 -10.905769,-6.30528 -11.152739,-6.95486 -0.12869,-0.33848 -0.23398,-4.53101 -0.23398,-9.31673 v -8.70132 l 1.12448,-0.76466 c 1.3917,-0.94639 6.916086,-4.14083 11.282459,-6.52401 2.657041,-1.45023 3.437791,-1.76379 4.117164,-1.65355 1.091718,0.17716 13.978735,7.5471 15.22175,8.70514 0.513812,0.47869 0.533317,0.81502 0.533317,9.19616 0,4.78462 -0.07385,8.89176 -0.164116,9.12698 -0.30852,0.804 -14.32707,8.88245 -15.710885,9.05369 -0.780152,0.0966 -1.570604,-0.24482 -5.01745,-2.16684 z m 38.320814,0.97024 c -1.255702,-0.67652 -4.623388,-2.59513 -7.483747,-4.2636 -4.071048,-2.37467 -5.241741,-3.17923 -5.389822,-3.70417 -0.247137,-0.87608 -0.246442,-16.87163 7.72e-4,-17.74842 0.146076,-0.51809 1.093177,-1.19859 4.101042,-2.94662 6.124495,-3.55928 11.265083,-6.30159 11.812606,-6.30159 0.719472,0 13.235724,6.92993 15.18339,8.40666 l 0.859896,0.65198 v 9.08326 9.08325 l -0.859896,0.64465 c -0.933659,0.69994 -8.04528,4.80611 -12.137435,7.00801 -1.400459,0.75356 -2.829209,1.35807 -3.175,1.34336 -0.345791,-0.0147 -1.656104,-0.58026 -2.911806,-1.25677 z m 36.678929,0.006 c -4.276301,-2.27566 -11.892512,-6.76484 -12.501563,-7.36874 -0.593721,-0.5887 -0.595312,-0.61392 -0.595312,-9.43653 0,-8.03951 0.04223,-8.89632 0.46302,-9.39519 0.851922,-1.00998 14.641687,-8.77011 15.584502,-8.77011 0.286767,0 2.8953411,1.3197 5.7968321,2.93267 7.0705614,3.93059 9.5895508,5.47253 9.9059347,6.0637 0.1607395,0.30035 0.2642946,3.92642 0.2642946,9.25451 0,8.7331 -0.00187,8.76254 -0.5953125,9.35351 -1.1573654,1.15254 -14.5209878,8.64092 -15.4119799,8.63618 -0.291041,-0.002 -1.600729,-0.57305 -2.910416,-1.27 z M 52.224866,51.20951 c -1.876567,-0.74609 -2.899498,-2.80593 -2.369992,-4.77236 1.027829,-3.81707 6.93608,-4.15534 8.456721,-0.48419 0.751959,1.81539 -0.190681,4.28668 -1.954317,5.12358 -0.999014,0.47407 -3.104119,0.5418 -4.132412,0.13297 z m -121.576042,-2.552 c -4.594664,-2.56039 -10.363883,-5.97824 -11.244792,-6.66173 l -0.661459,-0.51323 -0.08742,-8.99316 -0.08742,-8.99316 1.275931,-0.92595 c 1.492212,-1.08289 7.350617,-4.45107 11.82132,-6.79643 l 3.132822,-1.643501 2.688012,1.399524 c 3.872457,2.016207 11.492104,6.427647 12.523173,7.250357 l 0.880463,0.70254 -0.02617,7.72778 c -0.01439,4.25028 -0.103688,8.37865 -0.198437,9.17416 -0.156837,1.3168 -0.26709,1.50936 -1.230605,2.14933 -2.632548,1.74852 -13.624219,7.84171 -14.536666,8.05834 -0.355572,0.0844 -1.830212,-0.58712 -4.24875,-1.93487 z m 37.703125,0.56944 c -6.143841,-3.26708 -12.698956,-7.28162 -12.840708,-7.86402 -0.07664,-0.31488 -0.106101,-4.56111 -0.06547,-9.43606 l 0.07388,-8.86354 3.571875,-2.14432 c 1.964531,-1.17938 5.516143,-3.19278 7.89247,-4.47423 l 4.320596,-2.329918 1.235654,0.596364 c 2.012191,0.971144 10.594766,5.812674 12.811176,7.226944 l 2.050521,1.30841 v 9.05652 c 0,10.48406 0.359204,9.24387 -3.307292,11.41884 -3.822541,2.26754 -12.397005,6.99043 -12.683771,6.98634 -0.154446,-0.002 -1.530968,-0.66881 -3.058937,-1.48133 z"/></g></svg>
            <span class="version">v0.001</span>
        </div>
        <div class="nav-links">
            <a href="?p=dash"   <?= $p==='dash'  ?'class="active"':'' ?>>Dashboard</a>
            <a href="?p=events" <?= $p==='events'?'class="active"':'' ?>>Atividades</a>
            <a href="?p=parts"  <?= $p==='parts' ?'class="active"':'' ?>>Participantes</a>
            <a href="?p=fields" <?= $p==='fields'?'class="active"':'' ?>>Campos</a>
            <a href="?p=types"  <?= $p==='types' ?'class="active"':'' ?>>Tipos</a>
            <a href="?p=grids"  <?= $p==='grids' ?'class="active"':'' ?>>Grades</a>
            <a href="?p=checkin"<?= $p==='checkin'?'class="active"':'' ?>>Check-in</a>
            <a href="?p=certs"  <?= $p==='certs' ?'class="active"':'' ?>>Certificados</a>
            <a href="?p=config" <?= $p==='config'?'class="active"':'' ?>>Configurações</a>
        </div>
        <div class="nav-footer">
            <span><?= h($_SESSION['user_name'] ?? '') ?></span>
            <a href="?p=logout">Sair</a>
        </div>
    </nav>
    <div class="main">
    <div class="container">
    <?php if ($m = flash('ok')) echo "<div class='alert success'>$m</div>"; ?>
    <?php if ($m = flash('err')) echo "<div class='alert error'>$m</div>"; ?>
    <?php
}

function html_end() {
    echo '</div></div>
<script>
// ── Hamburger menu ──────────────────────────────────────
(function(){
    var btn     = document.getElementById("nav-toggle");
    var sidebar = document.getElementById("nav-sidebar");
    var overlay = document.getElementById("nav-overlay");
    if (!btn) return;
    function open(){sidebar.classList.add("open");overlay.classList.add("open");document.body.style.overflow="hidden"}
    function close(){sidebar.classList.remove("open");overlay.classList.remove("open");document.body.style.overflow=""}
    btn.addEventListener("click", function(){sidebar.classList.contains("open")?close():open()});
    overlay.addEventListener("click", close);
    document.addEventListener("keydown", function(e){if(e.key==="Escape")close()});
})();

// ── Scale dos canvases do certificado ──────────────────
(function(){
    function scaleCertCanvases(){
        var wraps = document.querySelectorAll(".cert-canvas-wrap");
        wraps.forEach(function(wrap){
            var scaler = wrap.querySelector(".cert-canvas-scaler");
            if (!scaler) return;
            var avail = wrap.offsetWidth;
            var scale = Math.min(1, avail / 800);
            scaler.style.transform = "scale(" + scale + ")";
            wrap.style.height = Math.round(600 * scale) + "px";
        });
    }
    scaleCertCanvases();
    window.addEventListener("resize", scaleCertCanvases);
})();
</script>
</body></html>';
}

// ==================== DASHBOARD ====================
if ($p === 'dash') {
    $stats = array(
        'events' => db()->query("SELECT COUNT(*) c FROM events")->fetch()['c'],
        'parts' => db()->query("SELECT COUNT(*) c FROM participants")->fetch()['c'],
        'checkins' => db()->query("SELECT COUNT(*) c FROM participants WHERE checkin_at IS NOT NULL")->fetch()['c'],
        'pending' => db()->query("SELECT COUNT(*) c FROM participants WHERE status='pending'")->fetch()['c']
    );
    
    html_start('Dashboard');
    $base = get_base_url();
    ?>
    <h1>Dashboard</h1>
    <div class='stats'>
        <div class='card'><h3>Atividades</h3><div class='num'><?= $stats['events'] ?></div></div>
        <div class='card'><h3>Participantes</h3><div class='num'><?= $stats['parts'] ?></div></div>
        <div class='card'><h3>Check-ins</h3><div class='num'><?= $stats['checkins'] ?></div></div>
        <div class='card'><h3>Pendentes</h3><div class='num'><?= $stats['pending'] ?></div></div>
    </div>
    
    <h2 style="margin:30px 0 20px">Links Públicos para Compartilhar</h2>
    <div class="stats" style="grid-template-columns:repeat(4,1fr)">
        <div class="card">
            <h3>Inscrição Geral</h3>
            <input type="text" id="link1" value="<?= $base ?>/index.php?p=public" readonly style="width:100%;padding:8px;background:#f7f7f7;border:1px solid #e0e0e0;margin:10px 0;font-size:11px;border-radius:3px">
            <button onclick="copyToClipboard('link1')" class="btn btn-sm btn-success">Copiar</button>
        </div>
        <div class="card">
            <h3>Criação de Evento (Palestrante)</h3>
            <input type="text" id="link_event" value="<?= $base ?>/public-links.php?p=submit_event" readonly style="width:100%;padding:8px;background:#f7f7f7;border:1px solid #e0e0e0;margin:10px 0;font-size:11px;border-radius:3px">
            <button onclick="copyToClipboard('link_event')" class="btn btn-sm btn-success">Copiar</button>
        </div>
        <div class="card">
            <h3>Check-in Público</h3>
            <input type="text" id="link2" value="<?= $base ?>/index.php?p=checkin" readonly style="width:100%;padding:8px;background:#f7f7f7;border:1px solid #e0e0e0;margin:10px 0;font-size:11px;border-radius:3px">
            <button onclick="copyToClipboard('link2')" class="btn btn-sm btn-success">Copiar</button>
        </div>
        <div class="card">
            <h3>Grades Públicas</h3>
            <p style="font-size:13px;color:#888;margin:10px 0">Acesse <a href="?p=grids">Grades</a> → Copiar link</p>
            <a href="?p=grids" class="btn btn-sm">Ver Grades</a>
        </div>
    </div>
    <!-- Busca pública de certificados -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:20px;margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px">
            <div>
                <h3 style="margin:0 0 4px;font-size:15px;font-weight:600">Busca Pública de Certificados</h3>
                <p style="margin:0;font-size:12px;color:#6b7280">Envie este link para que participantes baixem o próprio certificado.</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <input type="text" id="link-cert-public" value="<?= $base ?>/public-links.php?p=cert_search" readonly
                    style="width:320px;max-width:100%;padding:8px;background:#f7f7f7;border:1px solid #e0e0e0;font-size:11px;border-radius:3px">
                <button onclick="copyToClipboard('link-cert-public')" class="btn btn-sm btn-success">Copiar</button>
            </div>
        </div>
    </div>
    <script>
    function copyToClipboard(id) {
        const input = document.getElementById(id);
        input.select();
        document.execCommand('copy');
        alert('Link copiado para área de transferência!');
    }
    </script>
    
    <h2 style='margin:30px 0 15px'>Atividades Recentes</h2>
    <?php
    echo "<div class=\"table-wrap\"><table><tr><th>Título</th><th>Horário</th><th>Local</th><th>Palestrante</th></tr>";
    foreach (db()->query("SELECT * FROM events ORDER BY id DESC LIMIT 10") as $e) {
        echo "<tr><td><strong>" . h($e['title']) . "</strong></td><td>" . h($e['start_time']) . " - " . h($e['end_time']) . "</td><td>" . h($e['location']) . "</td><td>" . h($e['speaker_name']) . "</td></tr>";
    }
    echo "</table></div>";
    html_end();
    exit;
}

// ==================== EVENTOS ====================
if ($p === 'events') {

    // ── Reenviar email do palestrante ──────────────────────────────────────
    if ($do === 'resend_email' && $id) {
        $stmt = db()->prepare("SELECT * FROM events WHERE id=?");
        $stmt->execute(array($id));
        $ev = $stmt->fetch();
        if ($ev && !empty($ev['speaker_email'])) {
            $s_cfg = get_settings_email();
            $hor   = trim(($ev['start_time'] ?? '') . (!empty($ev['end_time']) ? ' – ' . $ev['end_time'] : ''));
            $msg   = email_palestrante_html($ev['speaker_name'], $ev['title'], $ev['location'], $hor, $s_cfg['site_name']);
            $ok    = send_email($ev['speaker_email'], 'Confirmação de Atividade: ' . $ev['title'], $msg);
            flash($ok ? 'ok' : 'err', $ok ? 'Email reenviado para ' . $ev['speaker_email'] : 'Falha ao enviar. Verifique o error_log do servidor.');
        } else {
            flash('err', 'Palestrante sem email cadastrado.');
        }
        go('?p=events');
    }
    // Delete
    if ($do === 'del' && $id) {
        db()->prepare("DELETE FROM events WHERE id=?")->execute(array($id));
        db()->prepare("DELETE FROM event_meta WHERE event_id=?")->execute(array($id));
        flash('ok', 'Atividade excluída!');
        go('?p=events');
    }
    
    // Save
    if ($_POST && isset($_POST['title'])) {
        // Upload da foto — lógica robusta com diagnóstico
        $photo = null;
        $upload_error = '';

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $upload_errors = array(
                    UPLOAD_ERR_INI_SIZE   => 'Arquivo maior que upload_max_filesize no php.ini.',
                    UPLOAD_ERR_FORM_SIZE  => 'Arquivo maior que MAX_FILE_SIZE no formulário.',
                    UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente no servidor.',
                    UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo temporário.',
                    UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP.',
                );
                $upload_error = $upload_errors[$_FILES['photo']['error']] ?? 'Erro desconhecido no upload (código ' . $_FILES['photo']['error'] . ').';
            } else {
                $allowed_ext = array('jpg', 'jpeg', 'png', 'gif', 'webp');
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed_ext)) {
                    $upload_error = 'Tipo de arquivo não permitido. Use JPG, PNG, GIF ou WEBP.';
                } else {
                    $upload_dir = ensure_uploads_dir();
                    if (!$upload_dir) {
                        $upload_error = 'A pasta uploads/ não tem permissão de escrita. Crie-a via FTP com permissão 777.';
                    } else {
                        $filename = 'event_' . time() . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
                            $photo = 'uploads/' . $filename;
                        } else {
                            $upload_error = 'Falha ao mover o arquivo. Verifique permissões da pasta uploads/.';
                        }
                    }
                }
            }
        }

        // Se houve erro no upload, mostrar e parar
        if ($upload_error) {
            // Manter foto existente mesmo com erro de upload
            if ($id) {
                $ce = db()->prepare("SELECT photo FROM events WHERE id=?");
                $ce->execute(array($id));
                $cr = $ce->fetch();
                $photo = $cr['photo'] ?? '';
            } else {
                $photo = '';
            }
            flash('err', 'Foto não salva: ' . $upload_error);
        }

        // Se não houve upload (campo vazio), manter foto existente
        if ($photo === null) {
            if ($id) {
                $ce = db()->prepare("SELECT photo FROM events WHERE id=?");
                $ce->execute(array($id));
                $cr = $ce->fetch();
                $photo = $cr['photo'] ?? '';
            } else {
                $photo = '';
            }
        }
        
        $d = array(
            $_POST['title'],
            $_POST['start_time'] ?? '',
            $_POST['end_time'] ?? '',
            $_POST['location'] ?? '',
            $_POST['summary'] ?? '',
            $photo,
            $_POST['speaker_name'] ?? '',
            $_POST['speaker_email'] ?? '',
            $_POST['speaker_bio'] ?? '',
            isset($_POST['is_special']) ? 1 : 0,
            (int)($_POST['event_order'] ?? 0)
        );
        
        if ($id) {
            $d[] = $id;
            db()->prepare("UPDATE events SET title=?,start_time=?,end_time=?,location=?,summary=?,photo=?,speaker_name=?,speaker_email=?,speaker_bio=?,is_special=?,event_order=? WHERE id=?")->execute($d);
            flash('ok', 'Atividade atualizada!');
        } else {
            db()->prepare("INSERT INTO events (title,start_time,end_time,location,summary,photo,speaker_name,speaker_email,speaker_bio,is_special,event_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute($d);
            $id = db()->lastInsertId();
            flash('ok', 'Atividade criada!');
            
            // Enviar email para palestrante
            if (!empty($_POST['speaker_email'])) {
                $s_cfg = get_settings_email();
                $horario_fmt = ($_POST['start_time'] ?? '') . (!empty($_POST['end_time']) ? ' – ' . $_POST['end_time'] : '');
                // Buscar nome do tipo de atividade
                $tipo_nome = '';
                if (!empty($_POST['activity_type'])) {
                    $tr = db()->prepare("SELECT name FROM activity_types WHERE id=?");
                    $tr->execute(array((int)$_POST['activity_type']));
                    $tr_row = $tr->fetch();
                    $tipo_nome = $tr_row ? $tr_row['name'] : '';
                }
                $msg_pal = email_palestrante_html(
                    $_POST['speaker_name'], $_POST['title'], $_POST['location'] ?? '',
                    $horario_fmt, $s_cfg['site_name'],
                    $_POST['summary'] ?? '', $_POST['speaker_bio'] ?? '', $tipo_nome, $_POST['speaker_email']
                );
                send_email($_POST['speaker_email'], 'Confirmação de Atividade: ' . $_POST['title'], $msg_pal);
            }
            // Notificar administrador
            $s_cfg2 = get_settings_email();
            $horario_fmt2 = ($_POST['start_time'] ?? '') . (!empty($_POST['end_time']) ? ' – ' . $_POST['end_time'] : '');
            $tipo_nome2 = '';
            if (!empty($_POST['activity_type'])) {
                $tr2 = db()->prepare("SELECT name FROM activity_types WHERE id=?");
                $tr2->execute(array((int)$_POST['activity_type']));
                $tr2_row = $tr2->fetch();
                $tipo_nome2 = $tr2_row ? $tr2_row['name'] : '';
            }
            $msg_adm = email_admin_atividade_html(
                $_POST['title'], $_POST['speaker_name'] ?? '', $_POST['speaker_email'] ?? '',
                $_POST['location'] ?? '', $horario_fmt2, 'Cadastro Interno', $s_cfg2['site_name'],
                $_POST['summary'] ?? '', $_POST['speaker_bio'] ?? '', $tipo_nome2
            );
            notify_admin('Nova Atividade Cadastrada: ' . $_POST['title'], $msg_adm);
        }
        
        // Salvar tipo
        if (!empty($_POST['activity_type'])) {
            save_meta('event', $id, 'activity_type', $_POST['activity_type']);
        }
        
        // Salvar campos customizados
        if (isset($_POST['custom']) && is_array($_POST['custom'])) {
            foreach ($_POST['custom'] as $key => $value) {
                save_meta('event', $id, $key, $value);
            }
        }
        
        go('?p=events');
    }
    
    // Form
    if ($do === 'edit' || $do === 'add') {
        $e = array('title'=>'','start_time'=>'','end_time'=>'','location'=>'','summary'=>'','photo'=>'','speaker_name'=>'','speaker_email'=>'','speaker_bio'=>'','is_special'=>0,'event_order'=>0);
        if ($id) {
            $r = db()->prepare("SELECT * FROM events WHERE id=?");
            $r->execute(array($id));
            $e = $r->fetch();
        }
        
        // Buscar tipos e campos
        $tipos = db()->query("SELECT * FROM activity_types ORDER BY name")->fetchAll();
        $campos = db()->query("SELECT * FROM custom_fields WHERE context='event' ORDER BY id")->fetchAll();
        
        // Carregar activity_type da event_meta (não existe na tabela events)
        $current_activity_type = $id ? get_meta('event', $id, 'activity_type') : '';
        
        html_start($id ? 'Editar Atividade' : 'Nova Atividade');
        ?>
        <h1><?= $id ? 'Editar' : 'Novo' ?> Evento</h1>
        <form method="post" enctype="multipart/form-data" style="max-width:800px">
            <label>Título *</label>
            <input name="title" value="<?= h($e['title']) ?>" required style="width:100%">
            
            <label>Tipo de Atividade</label>
            <select name="activity_type" style="width:100%">
                <option value="">Selecione...</option>
                <?php foreach ($tipos as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $current_activity_type == $t['id'] ? 'selected' : '' ?>>
                        <?= h($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <div class="form-row">
                <div>
                    <label>Horário Início</label>
                    <input type="time" name="start_time" value="<?= h($e['start_time']) ?>" style="width:100%">
                </div>
                <div>
                    <label>Horário Término</label>
                    <input type="time" name="end_time" value="<?= h($e['end_time']) ?>" style="width:100%">
                </div>
            </div>
            
            <label>Local</label>
            <input name="location" value="<?= h($e['location']) ?>" style="width:100%">
            
            <label>Resumo</label>
            <textarea name="summary" rows="4" style="width:100%"><?= h($e['summary']) ?></textarea>
            
            <label>Foto do Palestrante *</label>
            <?php if (!empty($e['photo']) && file_exists(BASE . '/' . $e['photo'])): ?>
                <div style="margin:8px 0">
                    <img src="<?= h(get_base_url() . '/' . $e['photo']) ?>" style="max-width:200px;max-height:120px;border-radius:6px;border:2px solid #e5e7eb;display:block;margin-bottom:6px">
                    <small style="color:#10b981">Foto atual — envie outra para substituir</small>
                </div>
            <?php endif; ?>
            <input type="file" name="photo" accept="image/*" style="width:100%" <?= empty($e['photo']) ? 'required' : '' ?>>
            
            <?php if (!empty($campos)): ?>
            <h3 style="margin:30px 0 15px;color:#374151">Campos Adicionais</h3>
            <?php foreach ($campos as $campo): ?>
                <?php
                $val = get_meta('event', $id, $campo['field_key']);
                ?>
                <label><?= h($campo['field_label']) ?><?= $campo['field_required']?' *':'' ?></label>
                <?php if ($campo['field_type'] === 'textarea'): ?>
                    <textarea name="custom[<?= h($campo['field_key']) ?>]" rows="3" style="width:100%" <?= $campo['field_required']?'required':'' ?>><?= h($val) ?></textarea>
                <?php elseif ($campo['field_type'] === 'select'): ?>
                    <select name="custom[<?= h($campo['field_key']) ?>]" style="width:100%" <?= $campo['field_required']?'required':'' ?>>
                        <option value="">Selecione...</option>
                        <?php foreach (explode(',', $campo['field_options']) as $opt): ?>
                            <option value="<?= h(trim($opt)) ?>" <?= $val===trim($opt)?'selected':'' ?>><?= h(trim($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($campo['field_type'] === 'checkbox'): ?>
                    <label style="font-weight:normal"><input type="checkbox" name="custom[<?= h($campo['field_key']) ?>]" value="1" <?= $val?'checked':'' ?>> <?= h($campo['field_label']) ?></label>
                <?php else: ?>
                    <input name="custom[<?= h($campo['field_key']) ?>]" value="<?= h($val) ?>" style="width:100%" <?= $campo['field_required']?'required':'' ?>>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <h3 style="margin:30px 0 15px;color:#374151">Informações do Palestrante</h3>
            
            <label>Nome do Palestrante</label>
            <input name="speaker_name" value="<?= h($e['speaker_name']) ?>" style="width:100%">
            
            <label>Email do Palestrante</label>
            <input type="email" name="speaker_email" value="<?= h($e['speaker_email']) ?>" style="width:100%">
            
            <label>Bio do Palestrante</label>
            <textarea name="speaker_bio" rows="3" style="width:100%"><?= h($e['speaker_bio']) ?></textarea>
            
            <div class="form-row">
                <div>
                    <label>Ordem de Exibição</label>
                    <input type="number" name="event_order" value="<?= h($e['event_order']) ?>" style="width:100%">
                </div>
                <div style="padding-top:40px">
                    <label><input type="checkbox" name="is_special" value="1" <?= $e['is_special']?'checked':'' ?>> Evento Especial</label>
                </div>
            </div>
            
            <br>
            <button type="submit" class="btn">Salvar</button>
            <a href="?p=events" class="btn btn-secondary">Cancelar</a>
        </form>
        <?php
        html_end();
        exit;
    }
    
    // List
    $search = $_GET['search'] ?? '';
    $where = '';
    $params = array();
    if ($search) {
        $where = "WHERE title LIKE ? OR speaker_name LIKE ? OR location LIKE ?";
        $params = array("%$search%", "%$search%", "%$search%");
    }
    
    $stmt = db()->prepare("SELECT * FROM events $where ORDER BY event_order, start_time");
    $stmt->execute($params);
    $list = $stmt->fetchAll();
    
    html_start('Atividades');
    ?>
    <div class="page-header">
        <h1>Atividades</h1>
        <a href="?p=events&do=add" class="btn">Nova Atividade</a>
    </div>
    <?php if ($m = flash('ok'))  echo "<div class='alert success'>$m</div>"; ?>
    <?php if ($m = flash('err')) echo "<div class='alert error'>$m</div>"; ?>
    <div class="search">
        <form method="get">
            <input type="hidden" name="p" value="events">
            <input type="text" name="search" placeholder="Buscar eventos..." value="<?= h($search) ?>">
        </form>
    </div>
    <div class="table-wrap"><table>
        <tr><th>Título</th><th>Horário</th><th>Local</th><th>Palestrante</th><th>Especial</th><th>Ações</th></tr>
        <?php if (empty($list)): ?>
            <tr><td colspan="6" class="empty">Nenhum evento encontrado</td></tr>
        <?php else: ?>
            <?php foreach ($list as $e): ?>
            <tr>
                <td><strong><?= h($e['title']) ?></strong></td>
                <td><?= h($e['start_time']) ?> - <?= h($e['end_time']) ?></td>
                <td><?= h($e['location']) ?></td>
                <td><?= h($e['speaker_name']) ?></td>
                <td><?= $e['is_special'] ? '⭐' : '' ?></td>
                <td>
                    <a href="?p=events&do=edit&id=<?= $e['id'] ?>" class="btn btn-sm">Editar</a>
                    <button onclick="copyEventLink(<?= $e['id'] ?>)" class="btn btn-sm btn-success">Link</button>
                    <?php if (!empty($e['speaker_email'])): ?>
                    <a href="?p=events&do=resend_email&id=<?= $e['id'] ?>" class="btn btn-sm" onclick="return confirm('Reenviar email de confirmação para <?= h($e['speaker_email']) ?>?')" title="Reenviar email para o palestrante">Email</a>
                    <?php endif; ?>
                    <a href="?p=events&do=del&id=<?= $e['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir esta atividade?')">Excluir</a>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table></div>
    <script>
    function copyEventLink(id) {
        const link = '<?= get_base_url() ?>/public-links.php?p=inscricao&id=' + id;
        const textarea = document.createElement('textarea');
        textarea.value = link;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('Link de inscrição copiado!\n\n' + link);
    }
    </script>
    <?php
    html_end();
    exit;
}

// ==================== PARTICIPANTES ====================
if ($p === 'parts') {

    // ── Reenviar email do participante ─────────────────────────────────────
    if ($do === 'resend_email' && $id) {
        $stmt = db()->prepare("SELECT * FROM participants WHERE id=?");
        $stmt->execute(array($id));
        $part = $stmt->fetch();
        if ($part && !empty($part['email'])) {
            $s_cfg   = get_settings_email();
            $evento  = $part['event_name'] ?? '';
            $doc     = $part['document']   ?? '';
            $org     = $part['organization'] ?? '';
            $phone   = $part['phone']       ?? '';
            $msg     = email_participante_html($part['name'], $evento, $doc, $org, $phone, $part['qr_key'], $s_cfg['site_name']);
            $ok      = send_email($part['email'], 'Confirmação de Inscrição — ' . $s_cfg['site_name'], $msg);
            flash($ok ? 'ok' : 'err', $ok ? 'Email reenviado para ' . $part['email'] : 'Falha ao enviar email. Verifique o error_log do servidor.');
        } else {
            flash('err', 'Participante sem email cadastrado.');
        }
        go('?p=parts');
    }
    // Export CSV
    if ($do === 'export') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=participantes-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, array('ID','Nome','Email','Documento','Organização','Telefone','Evento','Status','Check-in'));
        foreach (db()->query("SELECT * FROM participants ORDER BY name") as $part_row) {
            fputcsv($out, array($part_row['id'],$part_row['name'],$part_row['email'],$part_row['document'],$part_row['organization'],$part_row['phone'],$part_row['event_name'],$part_row['status'],$part_row['checkin_at']?date('d/m/Y H:i',strtotime($part_row['checkin_at'])):''));
        }
        fclose($out);
        exit;
    }
    
    // Import CSV
    if ($do === 'import') {
        if ($_FILES['csv']['error'] === 0) {
            $file = fopen($_FILES['csv']['tmp_name'], 'r');
            $header = fgetcsv($file);
            $imported = 0;
            $errors = array();
            
            while (($row = fgetcsv($file)) !== false) {
                if (count($row) < 2) continue;
                
                $data = array_combine($header, $row);
                $name = $data['Nome'] ?? $data['nome'] ?? '';
                $email = $data['Email'] ?? $data['email'] ?? '';
                
                if (empty($name) || empty($email)) {
                    $errors[] = "Linha ignorada: falta nome ou email";
                    continue;
                }
                
                try {
                    db()->prepare("INSERT INTO participants (name,email,document,organization,phone,event_name,status,qr_key) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute(array(
                            $name,
                            $email,
                            $data['Documento'] ?? $data['documento'] ?? '',
                            $data['Organização'] ?? $data['Organizacao'] ?? $data['organizacao'] ?? '',
                            $data['Telefone'] ?? $data['telefone'] ?? '',
                            $data['Evento'] ?? $data['evento'] ?? '',
                            'pending',
                            qr_key()
                        ));
                    $imported++;
                } catch (Exception $e) {
                    $errors[] = "Erro em $email: " . $e->getMessage();
                }
            }
            fclose($file);
            
            flash('ok', "Importados: $imported participantes" . (count($errors) ? " | Erros: " . count($errors) : ''));
            go('?p=parts');
        }
        
        html_start('Importar Participantes');
        ?>
        <h1>Importar Participantes CSV</h1>
        <div style="max-width:600px;background:#fff;padding:30px;border-radius:8px">
            <p style="margin-bottom:20px">O arquivo CSV deve ter as colunas: Nome, Email, Documento, Organização, Telefone, Evento</p>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="csv" accept=".csv" required style="width:100%;padding:10px;border:2px dashed #d1d5db;border-radius:8px">
                <br><br>
                <button type="submit" class="btn">Importar</button>
                <a href="?p=parts" class="btn btn-secondary">Cancelar</a>
            </form>
        </div>
        <?php
        html_end();
        exit;
    }
    
    // Delete
    if ($do === 'del' && $id) {
        db()->prepare("DELETE FROM participants WHERE id=?")->execute(array($id));
        db()->prepare("DELETE FROM participant_meta WHERE participant_id=?")->execute(array($id));
        flash('ok', 'Participante excluído!');
        go('?p=parts');
    }
    
    // Save
    if ($_POST && isset($_POST['name'])) {
        // Buscar título da grade selecionada para gravar em event_name
        $event_name_val = '';
        if (!empty($_POST['grid_id'])) {
            $gr = db()->prepare("SELECT title FROM grids WHERE id = ?");
            $gr->execute(array((int)$_POST['grid_id']));
            $gr_row = $gr->fetch();
            $event_name_val = $gr_row ? $gr_row['title'] : '';
        }

        $d = array(
            $_POST['name'],
            $_POST['email'],
            $_POST['document'] ?? '',
            $_POST['organization'] ?? '',
            $_POST['phone'] ?? '',
            $event_name_val,
            $_POST['status'],
            $_POST['notes'] ?? ''
        );
        
        if ($id) {
            $d[] = $id;
            db()->prepare("UPDATE participants SET name=?,email=?,document=?,organization=?,phone=?,event_name=?,status=?,notes=? WHERE id=?")->execute($d);
            flash('ok', 'Participante atualizado!');
        } else {
            $qr = qr_key();
            $d[] = $qr;
            db()->prepare("INSERT INTO participants (name,email,document,organization,phone,event_name,status,notes,qr_key) VALUES (?,?,?,?,?,?,?,?,?)")->execute($d);
            $new_id = db()->lastInsertId();
            flash('ok', 'Participante criado!');
            
            // Enviar email para participante com QR Code embutido
            $s_cfg = get_settings_email();
            $evento_nome = $_POST['event_name'] ?? $event_name_val;
            $msg_part = email_participante_html($_POST['name'], $evento_nome, $_POST['document'] ?? '', $_POST['organization'] ?? '', $_POST['phone'] ?? '', $qr, $s_cfg['site_name']);
            $ok_part = send_email($_POST['email'], 'Confirmação de Inscrição — ' . $s_cfg['site_name'], $msg_part);
            if (!$ok_part) error_log("[Bienenstock] Email participante falhou para: " . $_POST['email']);
            
            // Notificar administrador
            $msg_adm = email_admin_participante_html($_POST['name'], $_POST['email'], $evento_nome, $_POST['document'] ?? '', $_POST['organization'] ?? '', $_POST['phone'] ?? '', $s_cfg['site_name']);
            $ok_adm = notify_admin('Nova Inscrição: ' . $_POST['name'], $msg_adm);
            if (!$ok_adm) error_log("[Bienenstock] Email admin (nova inscrição) falhou");
            
            $id = $new_id;
        }
        
        // Salvar campos customizados
        if (isset($_POST['custom']) && is_array($_POST['custom'])) {
            foreach ($_POST['custom'] as $key => $value) {
                save_meta('participant', $id, $key, $value);
            }
        }
        
        // Salvar grid_id
        if (!empty($_POST['grid_id'])) {
            save_meta('participant', $id, 'grid_id', $_POST['grid_id']);
        }
        
        go('?p=parts');
    }
    
    // Form
    if ($do === 'edit' || $do === 'add') {
        $e = array('name'=>'','email'=>'','document'=>'','organization'=>'','phone'=>'','status'=>'pending','notes'=>'');
        if ($id) {
            $r = db()->prepare("SELECT * FROM participants WHERE id=?");
            $r->execute(array($id));
            $e = $r->fetch();
        }
        $campos = db()->query("SELECT * FROM custom_fields WHERE context='participant' ORDER BY id")->fetchAll();
        
        html_start($id ? 'Editar Participante' : 'Novo Participante');
        ?>
        <h1><?= $id ? 'Editar' : 'Novo' ?> Participante</h1>
        <form method="post" style="max-width:800px">
            <label>Nome Completo *</label>
            <input name="name" value="<?= h($e['name']) ?>" required style="width:100%">
            
            <label>Email *</label>
            <input type="email" name="email" value="<?= h($e['email']) ?>" required style="width:100%">
            
            <div class="form-row">
                <div>
                    <label>CPF/Documento</label>
                    <input name="document" value="<?= h($e['document']) ?>" style="width:100%">
                </div>
                <div>
                    <label>Telefone</label>
                    <input name="phone" value="<?= h($e['phone']) ?>" style="width:100%">
                </div>
            </div>
            
            <label>Organização/Empresa</label>
            <input name="organization" value="<?= h($e['organization']) ?>" style="width:100%">
            
            <label>Grade/Programação</label>
            <select name="grid_id" style="width:100%">
                <option value="">Nenhuma</option>
                <?php 
                $current_grid = get_meta('participant', $id, 'grid_id');
                foreach (db()->query("SELECT id, title FROM grids ORDER BY title") as $gr): 
                ?>
                    <option value="<?= $gr['id'] ?>" <?= $current_grid == $gr['id'] ? 'selected' : '' ?>><?= h($gr['title']) ?></option>
                <?php endforeach; ?>
            </select>
            
            <?php if (!empty($campos)): ?>
            <h3 style="margin:25px 0 15px;color:#374151">Campos Adicionais</h3>
            <?php foreach ($campos as $campo): ?>
                <?php
                $val = get_meta('participant', $id, $campo['field_key']);
                ?>
                <label><?= h($campo['field_label']) ?><?= $campo['field_required']?' *':'' ?></label>
                <?php if ($campo['field_type'] === 'textarea'): ?>
                    <textarea name="custom[<?= h($campo['field_key']) ?>]" rows="3" style="width:100%" <?= $campo['field_required']?'required':'' ?>><?= h($val) ?></textarea>
                <?php elseif ($campo['field_type'] === 'select'): ?>
                    <select name="custom[<?= h($campo['field_key']) ?>]" style="width:100%" <?= $campo['field_required']?'required':'' ?>>
                        <option value="">Selecione...</option>
                        <?php foreach (explode(',', $campo['field_options']) as $opt): ?>
                            <option value="<?= h(trim($opt)) ?>" <?= $val===trim($opt)?'selected':'' ?>><?= h(trim($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($campo['field_type'] === 'checkbox'): ?>
                    <label style="font-weight:normal"><input type="checkbox" name="custom[<?= h($campo['field_key']) ?>]" value="1" <?= $val?'checked':'' ?>> <?= h($campo['field_label']) ?></label>
                <?php else: ?>
                    <input name="custom[<?= h($campo['field_key']) ?>]" value="<?= h($val) ?>" style="width:100%" <?= $campo['field_required']?'required':'' ?>>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <label>Status</label>
            <select name="status" style="width:100%">
                <option value="pending" <?= $e['status']==='pending'?'selected':'' ?>>Pendente</option>
                <option value="confirmed" <?= $e['status']==='confirmed'?'selected':'' ?>>Confirmado</option>
                <option value="cancelled" <?= $e['status']==='cancelled'?'selected':'' ?>>Cancelado</option>
            </select>
            
            <label>Observações</label>
            <textarea name="notes" rows="3" style="width:100%"><?= h($e['notes']) ?></textarea>
            
            <br>
            <button type="submit" class="btn">Salvar</button>
            <a href="?p=parts" class="btn btn-secondary">Cancelar</a>
        </form>
        <?php
        html_end();
        exit;
    }
    
    // List
    $search = $_GET['search'] ?? '';
    $where = '';
    $params = array();
    if ($search) {
        $where = "WHERE p.name LIKE ? OR p.email LIKE ? OR p.document LIKE ?";
        $params = array("%$search%", "%$search%", "%$search%");
    }
    
    // JOIN com participant_meta e grids para trazer o título da grade
    $stmt = db()->prepare("
        SELECT p.*, COALESCE(g.title, p.event_name, '') AS event_label
        FROM participants p
        LEFT JOIN participant_meta pm ON pm.participant_id = p.id AND pm.meta_key = 'grid_id'
        LEFT JOIN grids g ON g.id = pm.meta_value
        $where
        ORDER BY p.id DESC
    ");
    $stmt->execute($params);
    $list = $stmt->fetchAll();
    
    html_start('Participantes');
    ?>
    <div class="page-header">
        <h1>Participantes</h1>
        <div>
            <a href="?p=parts&do=export" class="btn btn-success">Exportar</a>
            <a href="?p=parts&do=import" class="btn btn-success">Importar</a>
            <a href="?p=parts&do=add" class="btn">Novo</a>
        </div>
    </div>
    <?php if ($m = flash('ok'))  echo "<div class='alert success'>$m</div>"; ?>
    <?php if ($m = flash('err')) echo "<div class='alert error'>$m</div>"; ?>
    <div class="search">
        <form method="get">
            <input type="hidden" name="p" value="parts">
            <input type="text" name="search" placeholder="Buscar participantes..." value="<?= h($search) ?>">
        </form>
    </div>
    <div class="table-wrap"><table>
        <tr><th>Nome</th><th>Email</th><th>Documento</th><th>Evento</th><th>Status</th><th>Check-in</th><th>QR Code</th><th>Ações</th></tr>
        <?php if (empty($list)): ?>
            <tr><td colspan="8" class="empty">Nenhum participante encontrado</td></tr>
        <?php else: ?>
            <?php foreach ($list as $e): ?>
            <tr>
                <td><strong><?= h($e['name']) ?></strong></td>
                <td><?= h($e['email']) ?></td>
                <td><?= h($e['document']) ?></td>
                <td><?= h($e['event_label']) ?></td>
                <td><span class="badge badge-<?= $e['status'] ?>"><?= h($e['status']) ?></span></td>
                <td><?= $e['checkin_at'] ? date('d/m H:i',strtotime($e['checkin_at'])) : '—' ?></td>
                <td><a href="<?= get_base_url() ?>/index.php?qr=<?= $e['qr_key'] ?>" target="_blank" class="btn btn-sm">Ver</a></td>
                <td>
                    <a href="?p=parts&do=edit&id=<?= $e['id'] ?>" class="btn btn-sm">Editar</a>
                    <a href="?p=parts&do=resend_email&id=<?= $e['id'] ?>" class="btn btn-sm" onclick="return confirm('Reenviar email de confirmação com QR Code para <?= h($e['email']) ?>?')" title="Reenviar confirmação por email">Email</a>
                    <a href="?p=parts&do=del&id=<?= $e['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir?')">Excluir</a>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table></div>
    <?php
    html_end();
    exit;
}

// ==================== CHECK-IN ====================
if ($p === 'checkin') {
    // AJAX
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        if ($_GET['ajax'] === 'search') {
            $q_raw = $_GET['q'] ?? '';
            // Busca direta por qr_key (vinda do scanner) — hex de 32 chars
            if (preg_match('/^[a-f0-9]{32}$/', $q_raw)) {
                $r = db()->prepare("SELECT * FROM participants WHERE qr_key = ? LIMIT 1");
                $r->execute(array($q_raw));
                echo json_encode($r->fetchAll());
                exit;
            }
            // Busca textual normal
            $q = '%' . $q_raw . '%';
            $r = db()->prepare("SELECT * FROM participants WHERE name LIKE ? OR email LIKE ? OR document LIKE ? LIMIT 20");
            $r->execute(array($q, $q, $q));
            echo json_encode($r->fetchAll());
        } elseif ($_GET['ajax'] === 'do' && $_POST) {
            $id = (int)$_POST['id'];
            $r = db()->prepare("SELECT checkin_at FROM participants WHERE id=?");
            $r->execute(array($id));
            $checkin_row = $r->fetch();
            if ($checkin_row && $checkin_row['checkin_at']) {
                echo json_encode(array('ok'=>false,'msg'=>'Check-in já realizado!'));
            } else {
                db()->prepare("UPDATE participants SET checkin_at=NOW(),status='confirmed' WHERE id=?")->execute(array($id));
                echo json_encode(array('ok'=>true,'msg'=>'Check-in realizado com sucesso!'));
            }
        }
        exit;
    }
    
    html_start('Check-in');
    ?>
    <h1>Check-in de Participantes</h1>
    
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
        <div style="background:#fff;padding:30px;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.1)">
            <h2 style="margin-bottom:20px;color:#374151">Scanner QR Code</h2>
            <button id="toggle-camera" onclick="toggleCamera()" class="btn btn-success" style="margin-bottom:15px">Ligar Câmera</button>
            <div id="qr-reader" style="width:100%;max-width:500px"></div>
            <div id="qr-result" style="margin-top:15px;padding:15px;background:#f0fdf4;border-radius:8px;display:none"></div>
        </div>
        
        <div style="background:#fff;padding:30px;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.1)">
            <h2 style="margin-bottom:20px;color:#374151">Buscar Participante</h2>
            <input type="text" id="q" placeholder="Digite nome, email ou documento..." style="width:100%;padding:15px;font-size:16px;border:2px solid #d1d5db;border-radius:8px">
            <div id="res" style="margin-top:25px"></div>
        </div>
    </div>
    
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
    // Scanner QR
    const html5QrCode = new Html5Qrcode("qr-reader");
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    let cameraRunning = false;
    
    function toggleCamera() {
        const btn = document.getElementById('toggle-camera');
        
        if (!cameraRunning) {
            // Ligar câmera
            html5QrCode.start(
                { facingMode: "environment" },
                config,
                (decodedText) => {
                    const qrMatch = decodedText.match(/qr=([a-f0-9]+)/);
                    if (qrMatch) {
                        const qrKey = qrMatch[1];
                        processQR(qrKey);
                    } else {
                        document.getElementById('qr-result').innerHTML = 'QR Code inválido';
                        document.getElementById('qr-result').style.display = 'block';
                    }
                }
            ).then(() => {
                cameraRunning = true;
                btn.innerHTML = 'Desligar Câmera';
                btn.style.background = '#ef4444';
            }).catch(err => {
                console.error('Erro ao iniciar scanner:', err);
                document.getElementById('qr-reader').innerHTML = '<p style="color:#dc2626">Erro ao acessar câmera. Verifique permissões.</p>';
            });
        } else {
            // Desligar câmera
            html5QrCode.stop().then(() => {
                cameraRunning = false;
                btn.innerHTML = 'Ligar Câmera';
                btn.style.background = '#10b981';
                document.getElementById('qr-reader').innerHTML = '';
            }).catch(err => {
                console.error('Erro ao parar scanner:', err);
            });
        }
    }
    
    function processQR(qrKey) {
        // Mostrar feedback imediato
        const res = document.getElementById('qr-result');
        res.innerHTML = '<em>Buscando...</em>';
        res.style.background = '#f0f0f0';
        res.style.display = 'block';

        fetch('?p=checkin&ajax=search&q=' + encodeURIComponent(qrKey))
        .then(r => r.json())
        .then(data => {
            if (data.length > 0) {
                const p = data[0];
                if (p.checkin_at) {
                    res.innerHTML = '⚠️ ' + p.name + ' já fez check-in em ' + p.checkin_at;
                    res.style.background = '#fef3c7';
                } else {
                    doCheckSilent(p.id, function(msg) {
                        res.innerHTML = '✅ ' + msg;
                        res.style.background = '#f0fdf4';
                    });
                }
            } else {
                res.innerHTML = '❌ QR Code não reconhecido';
                res.style.background = '#fee2e2';
            }
            res.style.display = 'block';
            setTimeout(() => { res.style.display = 'none'; }, 4000);
        })
        .catch(() => {
            res.innerHTML = '❌ Erro de comunicação';
            res.style.display = 'block';
        });
    }

    function doCheckSilent(id, cb) {
        const f = new FormData();
        f.append('id', id);
        fetch('?p=checkin&ajax=do', {method:'POST', body:f})
        .then(r => r.json())
        .then(d => { if(cb) cb(d.msg); if(d.ok) document.getElementById('q') && document.getElementById('q').dispatchEvent(new Event('input')); });
    }
    
    // Busca manual
    let t;
    q.oninput = e => {
        clearTimeout(t);
        const v = e.target.value.trim();
        if(v.length < 2) { res.innerHTML = ''; return; }
        t = setTimeout(() => {
            fetch('?p=checkin&ajax=search&q=' + encodeURIComponent(v))
            .then(r => r.json())
            .then(d => {
                if(d.length) {
                    let h = '<table><tr><th>Nome</th><th>Email</th><th>Evento</th><th>Status</th><th>Check-in</th><th>Ação</th></tr>';
                    d.forEach(p => {
                        const checked = p.checkin_at !== null;
                        h += '<tr><td><strong>' + p.name + '</strong></td>';
                        h += '<td>' + p.email + '</td>';
                        h += '<td>' + (p.event_name||'-') + '</td>';
                        h += '<td><span class="badge badge-' + p.status + '">' + p.status + '</span></td>';
                        h += '<td>' + (checked ? p.checkin_at : '—') + '</td>';
                        h += '<td>';
                        if(!checked) {
                            h += '<button class="btn btn-sm btn-success" onclick="doCheck(' + p.id + ')">Check-in</button>';
                        } else {
                            h += '<span style="color:#27ae60;font-weight:600">Confirmado</span>';
                        }
                        h += '</td></tr>';
                    });
                    res.innerHTML = h + '</table>';
                } else {
                    res.innerHTML = '<p class="empty">Nenhum participante encontrado</p>';
                }
            });
        }, 300);
    };
    function doCheck(id) {
        if(!confirm('Confirmar check-in deste participante?')) return;
        const f = new FormData();
        f.append('id', id);
        fetch('?p=checkin&ajax=do', {method:'POST', body:f})
        .then(r => r.json())
        .then(d => {
            alert(d.msg);
            if(d.ok) q.dispatchEvent(new Event('input'));
        });
    }
    </script>
    <?php
    html_end();
    exit;
}




// ── Gera PDF do certificado em PHP puro (CertPDF) ────────────────────────────
// Retorna path do PDF salvo ou '' em caso de erro
function generate_cert_pdf($cert_row, $vars) {
    if (!function_exists('generate_cert_pdf_php')) return '';
    // Diretório temporário compatível com open_basedir
    $tmp_dir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
    if (!is_writable($tmp_dir) && defined('BASE')) {
        $tmp_dir = BASE . '/uploads/certs';
        if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0775, true);
    }
    $tmp_pdf = rtrim($tmp_dir, '/') . '/cert_tmp_' . uniqid() . '.pdf';
    return generate_cert_pdf_php($cert_row, $vars, $tmp_pdf);
}

// ── Gera HTML completo do certificado com bg embutida em base64 ──────────────
function render_certificate_full($cert_row, $vars) {
    if (!$cert_row) return '';
    // Pegar texto do editor visual (positions.main.text) que tem os marcadores processados
    $pos_data = json_decode($cert_row['positions'] ?? '{}', true);
    $tpl_text = isset($pos_data['main']['text']) ? $pos_data['main']['text'] : ($cert_row['template_html'] ?? '');
    $css      = $cert_row['template_css']  ?? '';
    $bg       = $cert_row['bg_image']      ?? '';

    // Substituir variáveis
    foreach ($vars as $k => $v) {
        $tpl_text = str_replace('{' . $k . '}', htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'), $tpl_text);
    }

    // Processar marcadores do editor visual → HTML
    $lines = explode("\n", $tpl_text);
    $out   = '';
    foreach ($lines as $line) {
        $align = 'left';
        if (preg_match('/^\[center\]\s*/i', $line)) { $align='center'; $line=preg_replace('/^\[center\]\s*/i','',$line); }
        elseif (preg_match('/^\[right\]\s*/i', $line))  { $align='right';  $line=preg_replace('/^\[right\]\s*/i', '',$line); }
        $line = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $line);
        $line = preg_replace('/__(.+?)__/s',     '<u>$1</u>',           $line);
        $line = preg_replace('/_(.+?)_/s',       '<em>$1</em>',         $line);
        $out .= '<div style="text-align:' . $align . ';min-height:1.4em">' . $line . '</div>';
    }

    // Imagem de fundo embutida em base64 (portátil para email e arquivo)
    $bg_style = 'background:#fff;';
    if ($bg && file_exists(BASE . '/' . $bg)) {
        $mime    = @mime_content_type(BASE . '/' . $bg) ?: 'image/jpeg';
        $b64     = base64_encode(file_get_contents(BASE . '/' . $bg));
        $bg_style = 'background:url(data:' . $mime . ';base64,' . $b64 . ') center/cover no-repeat;';
    }

    // CSS do template + posicionamento do texto
    $pos_main = $pos_data['main'] ?? [];
    $px = isset($pos_main['x']) ? (int)$pos_main['x'] : 50;
    $py = isset($pos_main['y']) ? (int)$pos_main['y'] : 150;

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
         . 'html,body{margin:0;padding:0;background:#555}'
         . '.cert-outer{width:800px;height:600px;position:relative;overflow:hidden;' . $bg_style . '}'
         . '.cert-text{position:absolute;left:' . $px . 'px;top:' . $py . 'px;width:' . (800-$px*2) . 'px}'
         . $css
         . '</style></head><body>'
         . '<div class="cert-outer">'
         . '<div class="cert-text">' . $out . '</div>'
         . '</div>'
         . '</body></html>';
}

// ── Salva certificado em PDF na pasta uploads/certs/ ─────────────────────────
// $pdf_tmp: caminho do PDF temporário gerado por generate_cert_pdf()
function save_certificate_file($pdf_tmp, $ref_type, $ref_id, $ref_name) {
    if (!$pdf_tmp || !file_exists($pdf_tmp)) return '';
    $upload_dir = ensure_uploads_dir();
    if (!$upload_dir) return '';
    $certs_dir = $upload_dir . 'certs/';
    if (!is_dir($certs_dir)) @mkdir($certs_dir, 0775, true);
    $ht = $certs_dir . '.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");
    }
    $slug  = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($ref_name));
    $fname = $ref_type . '_' . $ref_id . '_' . $slug . '.pdf';
    $fpath = $certs_dir . $fname;
    copy($pdf_tmp, $fpath);
    @unlink($pdf_tmp);
    $rel = 'uploads/certs/' . $fname;
    try {
        db()->prepare("DELETE FROM cert_files WHERE ref_type=? AND ref_id=?")->execute([$ref_type, $ref_id]);
        db()->prepare("INSERT INTO cert_files (ref_type,ref_id,ref_name,file_path) VALUES (?,?,?,?)")
            ->execute([$ref_type, $ref_id, $ref_name, $rel]);
    } catch (Exception $e) {}
    return $rel; // caminho relativo ao BASE
}

// ==================== CERTIFICADOS ====================
if ($p === 'certs') {
    // Gerar e enviar certificados
    if ($do === 'gen' && $_POST) {
        $type = $_POST['type']; // speaker ou participant

        // Buscar template completo do banco — tipo específico, fallback para qualquer tipo
        $cert_tpl_row = db()->prepare("SELECT * FROM certificates WHERE cert_type=?");
        $cert_tpl_row->execute([$type]);
        $cert_tpl_row = $cert_tpl_row->fetch();
        if (!$cert_tpl_row) {
            // Não há template para este tipo → usar o único disponível como fallback
            $cert_tpl_row = db()->query("SELECT * FROM certificates LIMIT 1")->fetch();
        }
        if (!$cert_tpl_row) {
            flash('err', 'Nenhum template de certificado encontrado. Crie um em Certificados → Editar Template.');
            go('?p=certs');
        }

        // Buscar dados globais do evento
        $cert_cfg = db()->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $cert_event_title = $cert_cfg['event_title'] ?? '';
        $cert_date_start  = !empty($cert_cfg['event_date_start']) ? date('d/m/Y', strtotime($cert_cfg['event_date_start'])) : '';
        $cert_date_end    = !empty($cert_cfg['event_date_end'])   ? date('d/m/Y', strtotime($cert_cfg['event_date_end']))   : '';

        $sent = 0;
        if ($type === 'speaker') {
            $events = db()->query("SELECT * FROM events WHERE speaker_email != '' ORDER BY title")->fetchAll();
            foreach ($events as $ev) {
                $ev_grade = db()->prepare("SELECT g.title FROM grids g JOIN event_meta em ON em.meta_value=g.id WHERE em.event_id=? AND em.meta_key='grid_id'");
                $ev_grade->execute([$ev['id']]);
                $grade_title = $ev_grade->fetchColumn() ?: '';
                $horario = trim(($ev['start_time'] ?? '') . ($ev['end_time'] ? ' – ' . $ev['end_time'] : ''));

                $vars = [
                    'nome'        => $ev['speaker_name'],
                    'palestrante' => $ev['speaker_name'],
                    'atividade'   => $ev['title'],
                    'horario'     => $horario,
                    'evento'      => $ev['title'],
                    'nome_evento' => $cert_event_title,
                    'data_inicio' => $cert_date_start,
                    'data_fim'    => $cert_date_end,
                    'data'        => date('d/m/Y'),
                    'grade'       => $grade_title,
                ];

                // Gerar PDF em PHP puro (CertPDF)
                $pdf_tmp = generate_cert_pdf($cert_tpl_row, $vars);
                // Salvar PDF permanente em uploads/certs/
                $pdf_rel = save_certificate_file($pdf_tmp, 'speaker', $ev['id'], $ev['speaker_name']);
                $pdf_abs = $pdf_rel ? BASE . '/' . $pdf_rel : '';
                // Enviar email com corpo simples + PDF em anexo
                if ($ev['speaker_email']) {
                    $h = function($v){ return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8'); };
                    $email_body = email_wrap(
                        'Certificado de Atividade',
                        '<h2>Seu certificado está pronto!</h2>'
                        . '<p>Olá <strong>' . $h($ev['speaker_name']) . '</strong>,</p>'
                        . '<p>Seu certificado de participação como palestrante na atividade <strong>'
                        . $h($ev['title']) . '</strong> está em anexo (PDF).</p>'
                        . '',  // sem mensagem extra
                        get_settings_email()['site_name'] ?? 'Bienenstock'
                    );
                    $fname_pdf = 'certificado_' . preg_replace('/[^a-z0-9]+/','-', mb_strtolower($ev['speaker_name'])) . '.pdf';
                    send_email_with_pdf($ev['speaker_email'], 'Certificado — ' . $ev['title'], $email_body, $pdf_abs, $fname_pdf);
                    $sent++;
                }
            }
            flash('ok', 'Certificados gerados e enviados para ' . $sent . ' palestrante(s)!');
        } else {
            $parts_list = db()->query("SELECT * FROM participants WHERE status='confirmed' AND email != '' ORDER BY name")->fetchAll();
            foreach ($parts_list as $part) {
                $pt_grade = db()->prepare("SELECT g.title FROM grids g JOIN participant_meta pm ON pm.meta_value=g.id WHERE pm.participant_id=? AND pm.meta_key='grid_id'");
                $pt_grade->execute([$part['id']]);
                $grade_title = $pt_grade->fetchColumn() ?: '';

                $vars = [
                    'nome'        => $part['name'],
                    'evento'      => $part['event_name'],
                    'nome_evento' => $cert_event_title,
                    'data_inicio' => $cert_date_start,
                    'data_fim'    => $cert_date_end,
                    'data'        => date('d/m/Y'),
                    'grade'       => $grade_title,
                ];

                $pdf_tmp = generate_cert_pdf($cert_tpl_row, $vars);
                $pdf_rel = save_certificate_file($pdf_tmp, 'participant', $part['id'], $part['name']);
                $pdf_abs = $pdf_rel ? BASE . '/' . $pdf_rel : '';
                $h = function($v){ return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8'); };
                $ev_name = $cert_event_title ?: $part['event_name'];
                $email_body = email_wrap(
                    'Certificado de Participação',
                    '<h2>Seu certificado está pronto!</h2>'
                    . '<p>Olá <strong>' . $h($part['name']) . '</strong>,</p>'
                    . '<p>Seu certificado de participação em <strong>' . $h($ev_name) . '</strong> está em anexo (PDF).</p>'
                    . '',  // sem mensagem extra
                    get_settings_email()['site_name'] ?? 'Bienenstock'
                );
                $fname_pdf = 'certificado_' . preg_replace('/[^a-z0-9]+/','-', mb_strtolower($part['name'])) . '.pdf';
                send_email_with_pdf($part['email'], 'Certificado — ' . $ev_name, $email_body, $pdf_abs, $fname_pdf);
                $sent++;
            }
            flash('ok', 'Certificados gerados e enviados para ' . $sent . ' participante(s)!');
        }
        go('?p=certs');
    }
    
    // Editor
    if ($do === 'edit') {
        $type = $_GET['type'] ?? 'participant';
        $cert = db()->prepare("SELECT * FROM certificates WHERE cert_type = ?");
        $cert->execute(array($type));
        $current = $cert->fetch();
        
        if ($_POST) {
            $html = $_POST['template'];
            $css = $_POST['template_css'] ?? '';
            $positions = json_encode(array(
                'main' => array(
                    'x' => $_POST['pos_main_x'], 
                    'y' => $_POST['pos_main_y'], 
                    'text' => $_POST['text_main']
                )
            ));
            
            // Upload de imagem de fundo do certificado — lógica robusta
            $bg_image = $current['bg_image'] ?? ''; // padrão: manter existente
            $cert_upload_error = '';

            if (isset($_FILES['bg_image_file']) && $_FILES['bg_image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['bg_image_file']['error'] !== UPLOAD_ERR_OK) {
                    $cert_upload_error = 'Erro no upload da imagem (código ' . $_FILES['bg_image_file']['error'] . ').';
                } else {
                    $allowed_ext = array('jpg', 'jpeg', 'png', 'gif', 'webp');
                    $ext = strtolower(pathinfo($_FILES['bg_image_file']['name'], PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowed_ext)) {
                        $cert_upload_error = 'Tipo não permitido. Use JPG, PNG, GIF ou WEBP.';
                    } else {
                        $upload_dir = ensure_uploads_dir();
                        if (!$upload_dir) {
                            $cert_upload_error = 'A pasta uploads/ não tem permissão de escrita. Crie-a via FTP com permissão 777.';
                        } else {
                            $filename = 'cert_' . $type . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['bg_image_file']['tmp_name'], $upload_dir . $filename)) {
                                $bg_image = 'uploads/' . $filename;
                            } else {
                                $cert_upload_error = 'Falha ao mover arquivo. Verifique permissões da pasta uploads/.';
                            }
                        }
                    }
                }
            }
            
            if ($cert_upload_error) {
                flash('err', 'Imagem de fundo não salva: ' . $cert_upload_error);
            }

            if ($current) {
                db()->prepare("UPDATE certificates SET template_html=?, template_css=?, bg_image=?, positions=? WHERE cert_type=?")->execute(array($html, $css, $bg_image, $positions, $type));
            } else {
                db()->prepare("INSERT INTO certificates (cert_type, template_html, template_css, bg_image, positions) VALUES (?,?,?,?,?)")->execute(array($type, $html, $css, $bg_image, $positions));
            }
            flash('ok', 'Template salvo com sucesso!');
            go('?p=certs');
        }
        
        $default_html = '<div class="certificado"><h1>Certificado de Participação</h1><p class="destaque">Certificamos que</p><h2 class="nome">{nome}</h2><p>Participou do evento</p><h3 class="evento">{nome_evento}</h3><p>Realizado de {data_inicio} a {data_fim}</p><div class="assinatura"><p>_________________________</p><p>Coordenação</p></div></div>';
        $default_css = '.certificado{text-align:center;padding:80px 60px;border:8px double #667eea;max-width:800px;margin:0 auto;font-family:Georgia,serif}.certificado h1{color:#667eea;font-size:36px;margin-bottom:40px}.destaque{font-size:20px;color:#666}.nome{color:#333;font-size:32px;margin:20px 0}.evento{color:#667eea;font-size:28px;margin:20px 0}.assinatura{margin-top:60px;font-size:14px}';
        
        $tpl_html = $current ? $current['template_html'] : $default_html;
        $tpl_css = $current ? ($current['template_css'] ?? '') : $default_css;

        // Buscar configurações de evento para substituição nas variáveis
        $cfg_settings = array();
        foreach (db()->query("SELECT setting_key, setting_value FROM settings") as $s) {
            $cfg_settings[$s['setting_key']] = $s['setting_value'];
        }

        // Função para renderizar marcadores de formatação do editor visual em HTML
        function render_cert_text($text, $cfg) {
            $text = h($text);
            // Substituir variáveis
            $text = str_replace('{nome_evento}', h($cfg['event_title'] ?? ''), $text);
            $text = str_replace('{data_inicio}', !empty($cfg['event_date_start']) ? date('d/m/Y', strtotime($cfg['event_date_start'])) : '', $text);
            $text = str_replace('{data_fim}',    !empty($cfg['event_date_end'])   ? date('d/m/Y', strtotime($cfg['event_date_end']))   : '', $text);
            // Processar linha por linha para alinhamento
            $lines = explode("\n", $text);
            $out = '';
            foreach ($lines as $line) {
                $align = 'left';
                if (preg_match('/^\[center\]\s*/i', $line)) { $align = 'center'; $line = preg_replace('/^\[center\]\s*/i', '', $line); }
                elseif (preg_match('/^\[right\]\s*/i', $line))  { $align = 'right';  $line = preg_replace('/^\[right\]\s*/i',  '', $line); }
                // Negrito, itálico, sublinhado
                $line = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $line);
                $line = preg_replace('/__(.+?)__/s',     '<u>$1</u>',           $line);
                $line = preg_replace('/_(.+?)_/s',       '<em>$1</em>',         $line);
                $out .= '<div style="text-align:' . $align . ';min-height:1.4em">' . $line . '</div>';
            }
            return $out;
        }
        
        html_start('Editor de Certificados');
        ?>
        <h1>Editor de Certificados — <?= $type === 'speaker' ? 'Palestrantes' : 'Participantes' ?>
        <small style="font-size:13px;color:#6b7280;font-weight:normal;margin-left:10px">
            v<?= date('ymd', filemtime(__FILE__)) ?>
            | <a href="?p=certs&do=edit&type=<?= $type === 'speaker' ? 'participant' : 'speaker' ?>">
                Editar <?= $type === 'speaker' ? 'Participantes' : 'Palestrantes' ?>
              </a>
        </small>
    </h1>
    <?php if (!$current): ?>
    <div style="background:#fef3c7;border:1px solid #f59e0b;padding:12px;border-radius:6px;margin-bottom:16px">
        ⚠️ Nenhum template salvo para <strong><?= $type === 'speaker' ? 'Palestrantes' : 'Participantes' ?></strong>.
        Configure e salve para usar um template diferente para cada tipo.
    </div>
    <?php endif; ?>
        
        <form method="post" enctype="multipart/form-data" style="max-width:1400px">
            <!-- EDITOR VISUAL -->
            <div id="editor-visual" style="display:block">
                <h3>Editor Visual</h3>
                <p style="color:#6b7280;margin:10px 0">Edite os textos livremente, use botões para inserir campos, arraste ou use ↑↓←→</p>
                
                <div style="margin:20px 0">
                    <label>Imagem de Fundo (opcional)</label>
                    <?php if (!empty($current['bg_image']) && file_exists(BASE . '/' . $current['bg_image'])): ?>
                        <p style="color:#10b981;font-size:13px">Imagem atual: <?= h($current['bg_image']) ?></p>
                    <?php endif; ?>
                    <input type="file" name="bg_image_file" id="bg-upload" accept="image/*" style="margin:10px 0">
                    <p style="font-size:12px;color:#6b7280;margin:4px 0 0">
                        💡 Dimensão recomendada: <strong>800 × 600 px</strong> (proporção 4:3, paisagem).
                        Imagens em outras proporções serão recortadas para preencher o certificado.
                    </p>
                </div>
                
                <?php
                // Carregar texto e posição salvos ANTES de usar no HTML
                $saved_positions = !empty($current['positions']) ? json_decode($current['positions'], true) : null;
                $default_text_participant = "Certificado de Participação\n\nCertificamos que {nome}, Participou do evento {nome_evento} Realizado em {data_inicio} a {data_fim}.";
                $default_text_speaker     = "Certificado de Atividade\n\nCertificamos que {palestrante} ministrou a atividade {atividade} no evento {nome_evento}, realizado em {data_inicio} a {data_fim}, no horário {horario}.";
                $saved_text = $saved_positions['main']['text'] ?? ($type === 'speaker' ? $default_text_speaker : $default_text_participant);
                $saved_x = $saved_positions['main']['x'] ?? 50;
                $saved_y = $saved_positions['main']['y'] ?? 200;
                ?>
                
                <div style="margin:20px 0;padding:15px;background:#f9fafb;border:1px solid #ddd;border-radius:8px">
                    <strong>Inserir Campos:</strong>
                    <button type="button" onclick="insertField('{nome}')" class="btn btn-sm" style="margin:3px">{nome}</button>
                    <button type="button" onclick="insertField('{evento}')" class="btn btn-sm" style="margin:3px">{evento}</button>
                    <button type="button" onclick="insertField('{data}')" class="btn btn-sm" style="margin:3px">{data}</button>
                    <button type="button" onclick="insertField('{nome_evento}')" class="btn btn-sm" style="margin:3px;background:#059669">{nome_evento}</button>
                    <button type="button" onclick="insertField('{data_inicio}')" class="btn btn-sm" style="margin:3px;background:#059669">{data_inicio}</button>
                    <button type="button" onclick="insertField('{data_fim}')" class="btn btn-sm" style="margin:3px;background:#059669">{data_fim}</button>
                    <button type="button" onclick="insertField('{grade}')" class="btn btn-sm" style="margin:3px;background:#7c3aed">{grade}</button>
                    <?php if ($type === 'speaker'): ?>
                    <button type="button" onclick="insertField('{palestrante}')" class="btn btn-sm" style="margin:3px;background:#b45309">{palestrante}</button>
                    <button type="button" onclick="insertField('{atividade}')" class="btn btn-sm" style="margin:3px;background:#b45309">{atividade}</button>
                    <button type="button" onclick="insertField('{horario}')" class="btn btn-sm" style="margin:3px;background:#b45309">{horario}</button>
                    <?php endif; ?>
                    <button type="button" onclick="insertField('{grade}')" class="btn btn-sm" style="margin:3px;background:#7c3aed">{grade}</button>
                </div>
                
                <div class="cert-canvas-wrap">
                <div class="cert-canvas-scaler">
                <div id="cert-canvas" style="position:relative;width:800px;height:600px;border:2px solid #ddd;background-color:#fff;<?php if(!empty($current['bg_image']) && file_exists(BASE.'/'.$current['bg_image'])): ?>background-image:url('<?= h(get_base_url().'/'.$current['bg_image']) ?>');background-size:cover;background-position:center;<?php endif; ?>overflow:hidden">
                    <div class="draggable-elem" id="elem-main" style="position:absolute;top:<?= $saved_y ?>px;left:<?= $saved_x ?>px;cursor:move;border:2px dashed #667eea;background:rgba(255,255,255,0.92);width:680px;border-radius:4px">
                        <!-- Barra de ferramentas do bloco -->
                        <div id="editor-toolbar" style="display:flex;gap:3px;padding:6px 8px;background:#f1f3ff;border-bottom:1px solid #c7d0ff;align-items:center;flex-wrap:wrap;user-select:none">
                            <!-- Mover -->
                            <button type="button" onclick="moveElem('elem-main',0,-10)" class="move-btn" title="Mover para cima">↑</button>
                            <button type="button" onclick="moveElem('elem-main',0,10)"  class="move-btn" title="Mover para baixo">↓</button>
                            <button type="button" onclick="moveElem('elem-main',-10,0)" class="move-btn" title="Mover para esquerda">←</button>
                            <button type="button" onclick="moveElem('elem-main',10,0)"  class="move-btn" title="Mover para direita">→</button>
                            <span class="sep"></span>
                            <!-- Formatação -->
                            <button type="button" onclick="fmt('bold')"      class="fmt-btn" title="Negrito (Ctrl+B)"><b>N</b></button>
                            <button type="button" onclick="fmt('italic')"    class="fmt-btn" title="Itálico (Ctrl+I)"><i>I</i></button>
                            <button type="button" onclick="fmt('underline')" class="fmt-btn" title="Sublinhado (Ctrl+U)"><u>S</u></button>
                            <span class="sep"></span>
                            <!-- Alinhamento — SVGs padrão de editor -->
                            <button type="button" onclick="fmt('justifyLeft')"   class="fmt-btn" title="Alinhar à esquerda">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="4" width="18" height="2"/><rect x="3" y="9" width="12" height="2"/><rect x="3" y="14" width="18" height="2"/><rect x="3" y="19" width="12" height="2"/></svg>
                            </button>
                            <button type="button" onclick="fmt('justifyCenter')" class="fmt-btn" title="Centralizar">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="4" width="18" height="2"/><rect x="6" y="9" width="12" height="2"/><rect x="3" y="14" width="18" height="2"/><rect x="6" y="19" width="12" height="2"/></svg>
                            </button>
                            <button type="button" onclick="fmt('justifyRight')"  class="fmt-btn" title="Alinhar à direita">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="4" width="18" height="2"/><rect x="9" y="9" width="12" height="2"/><rect x="3" y="14" width="18" height="2"/><rect x="9" y="19" width="12" height="2"/></svg>
                            </button>
                            <span class="sep"></span>
                            <!-- Tamanho de fonte -->
                            <select id="font-size-sel" onchange="applyFontSize(this.value)" class="fmt-sel" title="Tamanho da fonte">
                                <?php foreach([10,12,14,16,18,20,24,28,32,36,42,48,56,64] as $fs): ?>
                                <option value="<?= $fs ?>" <?= $fs==18?'selected':'' ?>><?= $fs ?>px</option>
                                <?php endforeach; ?>
                            </select>
                            <span class="sep"></span>
                            <!-- Cor do texto -->
                            <label style="font-size:11px;color:#374151;margin:0 2px" title="Cor do texto">A
                                <input type="color" id="text-color-picker" value="#1e3a8a"
                                    style="width:28px;height:20px;padding:0;border:1px solid #d1d5db;cursor:pointer;vertical-align:middle"
                                    oninput="applyColor(this.value)" title="Cor do texto">
                            </label>
                        </div>
                        <!-- Área de edição WYSIWYG -->
                        <div id="text-editor"
                             contenteditable="true"
                             style="min-height:160px;padding:14px 16px;font-size:18px;line-height:1.7;outline:none;cursor:text"
                             oninput="syncHidden()"
                             onkeydown="handleKey(event)"><?php
                            // Se o texto salvo parece HTML (contenteditable), usar direto
                            // Senão, converter marcadores antigos para HTML
                            $is_html = strpos($saved_text, '<') !== false;
                            if ($is_html) {
                                echo $saved_text; // HTML do contenteditable — usar direto
                            } else {
                                echo render_cert_text($saved_text, $cfg_settings); // marcadores antigos
                            }
                        ?></div>
                        <input type="hidden" name="text_main"  id="text-main"  value="<?= h($saved_text) ?>">
                        <input type="hidden" name="pos_main_x" id="pos-main-x" value="<?= $saved_x ?>">
                        <input type="hidden" name="pos_main_y" id="pos-main-y" value="<?= $saved_y ?>">
                        <!-- CORRIGIDO: campo hidden para template e CSS — garante envio mesmo quando o editor de código está oculto -->
                        <input type="hidden" name="template"      id="hidden-template" value="<?= h($tpl_html) ?>">
                        <input type="hidden" name="template_css"  id="hidden-css"      value="<?= h($tpl_css) ?>">
                    </div>
                </div><!-- /cert-canvas -->
                </div><!-- /cert-canvas-scaler -->
                </div><!-- /cert-canvas-wrap -->
                <style>
                .move-btn{padding:3px 7px;background:#667eea;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11px;line-height:1.4}
                .move-btn:hover{background:#5568d3}
                .fmt-btn{padding:3px 7px;background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;font-size:12px;line-height:1.4;display:inline-flex;align-items:center;justify-content:center}
                .fmt-btn:hover{background:#e5e7eb}
                .fmt-sel{padding:2px 4px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;cursor:pointer;background:#fff}
                .sep{width:1px;height:18px;background:#d1d5db;margin:0 2px;display:inline-block}
                #text-editor:focus{outline:none}
                #text-editor p{margin:0;padding:0}
                /* CSS do template injetado para preview fiel */
                <?= $tpl_css ?>
                </style>
                
                <script>
                // Upload imagem de fundo
                document.getElementById('bg-upload').onchange = function(e) {
                    const reader = new FileReader();
                    reader.onload = ev => document.getElementById('cert-canvas').style.backgroundImage = 'url(' + ev.target.result + ')';
                    reader.readAsDataURL(e.target.files[0]);
                };

                // execCommand — forma mais compatível de WYSIWYG em contenteditable
                function fmt(cmd) {
                    document.getElementById('text-editor').focus();
                    document.execCommand(cmd, false, null);
                    syncHidden();
                }

                function applyFontSize(px) {
                    document.getElementById('text-editor').focus();
                    const sel = window.getSelection();
                    if (!sel.rangeCount || sel.isCollapsed) return;
                    document.execCommand('fontSize', false, '7'); // placeholder
                    document.querySelectorAll('#text-editor font[size="7"]').forEach(el => {
                        el.removeAttribute('size');
                        el.style.fontSize = px + 'px';
                    });
                    syncHidden();
                }

                function applyColor(color) {
                    const editor = document.getElementById('text-editor');
                    editor.focus();
                    const sel = window.getSelection();
                    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
                        // Sem seleção: aplicar na linha inteira (div atual)
                        document.execCommand('foreColor', false, color);
                    } else {
                        document.execCommand('foreColor', false, color);
                    }
                    syncHidden();
                }

                // Inserir variável como texto simples na posição do cursor
                function insertField(field) {
                    const editor = document.getElementById('text-editor');
                    editor.focus();
                    const sel = window.getSelection();
                    if (sel.rangeCount) {
                        const range = sel.getRangeAt(0);
                        range.deleteContents();
                        const node = document.createTextNode(field);
                        range.insertNode(node);
                        range.setStartAfter(node);
                        range.collapse(true);
                        sel.removeAllRanges();
                        sel.addRange(range);
                    } else {
                        editor.innerHTML += field;
                    }
                    syncHidden();
                }

                // Sincronizar o HTML do editor para o hidden que será enviado ao PHP
                function syncHidden() {
                    document.getElementById('text-main').value = document.getElementById('text-editor').innerHTML;
                }

                // Ctrl+B/I/U nativos funcionam automaticamente com contenteditable
                function handleKey(e) {
                    // Nada extra necessário — os atalhos nativos já funcionam
                }

                // Mover o bloco com botões
                function moveElem(elemId, deltaX, deltaY) {
                    const elem = document.getElementById(elemId);
                    const newLeft = (parseInt(elem.style.left) || 0) + deltaX;
                    const newTop  = (parseInt(elem.style.top)  || 0) + deltaY;
                    elem.style.left = newLeft + 'px';
                    elem.style.top  = newTop  + 'px';
                    const field = elemId.replace('elem-', '');
                    document.getElementById('pos-' + field + '-x').value = newLeft;
                    document.getElementById('pos-' + field + '-y').value = newTop;
                    syncPreviewPos(newLeft, newTop);
                }

                function syncPreviewPos(left, top) {
                    const pb = document.getElementById('preview-block');
                    if (pb) { pb.style.left = left + 'px'; pb.style.top = top + 'px'; }
                }

                // Drag and drop
                document.querySelectorAll('.draggable-elem').forEach(elem => {
                    let ox=0, oy=0, mx=0, my=0;
                    elem.onmousedown = function(e) {
                        if (['INPUT','BUTTON','SELECT','TEXTAREA'].includes(e.target.tagName)) return;
                        if (e.target.closest('[contenteditable]')) return;
                        e.preventDefault();
                        mx = e.clientX; my = e.clientY;
                        document.onmouseup   = () => { document.onmouseup = document.onmousemove = null; };
                        document.onmousemove = function(ev) {
                            ox = mx - ev.clientX; oy = my - ev.clientY;
                            mx = ev.clientX; my = ev.clientY;
                            const nl = elem.offsetLeft - ox, nt = elem.offsetTop - oy;
                            elem.style.left = nl + 'px'; elem.style.top = nt + 'px';
                            const f = elem.id.replace('elem-','');
                            document.getElementById('pos-'+f+'-x').value = nl;
                            document.getElementById('pos-'+f+'-y').value = nt;
                            syncPreviewPos(nl, nt);
                        };
                    };
                });

                </script>
            </div>
            
            <br>
            <button type="submit" class="btn">Salvar Template</button>
            <a href="?p=certs" class="btn btn-secondary">Voltar</a>
        </form>
        
        <h3 style="margin-top:30px">Preview do Certificado</h3>
        <p style="color:#6b7280;margin-bottom:15px">Exemplo com dados de teste</p>
        <div class="cert-canvas-wrap"><div class="cert-canvas-scaler"><div style="position:relative;width:800px;height:600px;border:2px solid #ddd;background-color:#fff;<?php if(!empty($current['bg_image']) && file_exists(BASE.'/'.$current['bg_image'])): ?>background-image:url('<?= h(get_base_url() . '/' . $current['bg_image']) ?>');background-size:cover;background-position:center;<?php endif; ?>">;
            <?php
            $pos_data = json_decode($current['positions'] ?? '{}', true);
            if ($pos_data && isset($pos_data['main'])):
                $preview_raw = $pos_data['main']['text'] ?? '';
                $px = $pos_data['main']['x'] ?? 50;
                $py = $pos_data['main']['y'] ?? 200;

                $ev_title = $cfg_settings['event_title'] ?? 'Nome do Evento';
                $dt_start = !empty($cfg_settings['event_date_start']) ? date('d/m/Y', strtotime($cfg_settings['event_date_start'])) : 'dd/mm/aaaa';
                $dt_end   = !empty($cfg_settings['event_date_end'])   ? date('d/m/Y', strtotime($cfg_settings['event_date_end']))   : 'dd/mm/aaaa';
                // Buscar primeira grade para exemplo no preview
                $preview_grade = db()->query("SELECT title FROM grids ORDER BY id LIMIT 1")->fetchColumn() ?: 'Nome da Grade';
                // Buscar primeiro evento para exemplo no preview de palestrante
                $preview_ev = db()->query("SELECT title, speaker_name, start_time, end_time FROM events ORDER BY id LIMIT 1")->fetch();
                $preview_palestrante = $preview_ev['speaker_name'] ?? 'Maria Souza';
                $preview_atividade   = $preview_ev['title']        ?? 'Título da Atividade';
                $preview_horario     = trim(($preview_ev['start_time'] ?? '09:00') . ' - ' . ($preview_ev['end_time'] ?? '10:00'));

                $preview_rendered = $preview_raw;
                $preview_rendered = str_replace('{nome}',        'João Silva da Costa', $preview_rendered);
                $preview_rendered = str_replace('{palestrante}',  $preview_palestrante,  $preview_rendered);
                $preview_rendered = str_replace('{atividade}',    $preview_atividade,    $preview_rendered);
                $preview_rendered = str_replace('{horario}',      $preview_horario,      $preview_rendered);
                $preview_rendered = str_replace('{evento}',      $ev_title,             $preview_rendered);
                $preview_rendered = str_replace('{nome_evento}', $ev_title,             $preview_rendered);
                $preview_rendered = str_replace('{data_inicio}', $dt_start,             $preview_rendered);
                $preview_rendered = str_replace('{data_fim}',    $dt_end,               $preview_rendered);
                $preview_rendered = str_replace('{data}',        date('d/m/Y'),         $preview_rendered);
                $preview_rendered = str_replace('{grade}',       $preview_grade,        $preview_rendered);
                if (strpos($preview_rendered, '<') === false) {
                    $preview_rendered = render_cert_text($preview_rendered, $cfg_settings);
                }
            ?>
            <!-- Preview espelha exatamente o bloco do editor: mesma posição, mesma largura, sem toolbar -->
            <div id="preview-block" style="position:absolute;top:<?= $py ?>px;left:<?= $px ?>px;width:680px;padding:14px 16px;font-size:18px;line-height:1.7;border-radius:4px">
                <?= $preview_rendered ?>
            </div>
            <?php endif; ?>
        </div><!-- /preview-canvas --></div><!-- /scaler --></div><!-- /wrap -->
        <script>
        // Atualizar preview em tempo real ao editar
        (function() {
            const editor = document.getElementById('text-editor');
            const previewBlock = document.getElementById('preview-block');
            if (!editor || !previewBlock) return;

            const vars = <?= json_encode([
                '{nome}'        => 'João Silva da Costa',
                '{palestrante}' => $preview_palestrante,
                '{atividade}'   => $preview_atividade,
                '{horario}'     => $preview_horario,
                '{evento}'      => $cfg_settings['event_title'] ?? 'Nome do Evento',
                '{nome_evento}' => $cfg_settings['event_title'] ?? 'Nome do Evento',
                '{data_inicio}' => !empty($cfg_settings['event_date_start']) ? date('d/m/Y', strtotime($cfg_settings['event_date_start'])) : 'dd/mm/aaaa',
                '{data_fim}'    => !empty($cfg_settings['event_date_end'])   ? date('d/m/Y', strtotime($cfg_settings['event_date_end']))   : 'dd/mm/aaaa',
                '{data}'        => date('d/m/Y'),
                '{grade}'       => $preview_grade,
            ]) ?>;

            function refreshPreview() {
                let html = editor.innerHTML;
                for (const [k, v] of Object.entries(vars)) {
                    html = html.split(k).join(v);
                }
                previewBlock.innerHTML = html;
            }

            editor.addEventListener('input', refreshPreview);
            refreshPreview(); // inicializar
        })();
        </script>
        <?php
        html_end();
        exit;
    }
    
    // Download de certificado individual
    if ($do === 'download' && $id) {
        try {
            $cf = db()->prepare("SELECT * FROM cert_files WHERE id=?");
            $cf->execute([$id]);
            $cf_row = $cf->fetch();
            if ($cf_row && file_exists(BASE . '/' . $cf_row['file_path'])) {
                $ext   = strtolower(pathinfo($cf_row['file_path'], PATHINFO_EXTENSION));
                $mime  = ($ext === 'pdf') ? 'application/pdf' : 'text/html';
                $fname = 'certificado_' . preg_replace('/[^a-z0-9._-]+/', '-',
                             mb_strtolower($cf_row['ref_name'])) . '.' . $ext;
                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . $fname . '"');
                header('Content-Length: ' . filesize(BASE . '/' . $cf_row['file_path']));
                readfile(BASE . '/' . $cf_row['file_path']);
                exit;
            }
        } catch (Exception $e) {}
        flash('err', 'Arquivo não encontrado. Gere os certificados primeiro.');
        go('?p=certs');
    }

    // AJAX: busca de certificados
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_certs') {
        header('Content-Type: application/json; charset=utf-8');
        $q_raw = trim($_GET['q'] ?? '');
        $type  = trim($_GET['type'] ?? '');
        try {
            // Verificar se tabela existe (MySQL)
            try {
                db()->query("SELECT 1 FROM cert_files LIMIT 1");
            } catch (Exception $ex) {
                echo json_encode(['error'=>'Tabela cert_files não existe — execute o install.php para criar as tabelas.']);
                exit;
            }

            $q = $q_raw !== '' ? '%' . $q_raw . '%' : '%';
            $params = [$q];
            $sql = "SELECT id, ref_type, ref_id, ref_name, file_path, created_at
                    FROM cert_files
                    WHERE ref_name LIKE ?";
            if ($type !== '') { $sql .= " AND ref_type=?"; $params[] = $type; }
            $sql .= " ORDER BY created_at DESC LIMIT 100";
            $r = db()->prepare($sql);
            $r->execute($params);
            $rows = $r->fetchAll(PDO::FETCH_ASSOC);

            // Para palestrantes: buscar título do evento também
            foreach ($rows as &$row) {
                $row['event_title'] = '';
                if ($row['ref_type'] === 'speaker' && $row['ref_id']) {
                    $ev = db()->prepare("SELECT title FROM events WHERE id=?");
                    $ev->execute([$row['ref_id']]);
                    $row['event_title'] = $ev->fetchColumn() ?: '';
                }
                // Se a busca foi por texto e ref_name não bateu, verificar event_title
            }
            unset($row);

            // Se busca por texto E ref_name não retornou nada, tentar buscar por título de atividade
            if ($q_raw !== '' && empty($rows)) {
                $sql2 = "SELECT cf.id, cf.ref_type, cf.ref_id, cf.ref_name, cf.file_path, cf.created_at,
                                e.title AS event_title
                         FROM cert_files cf
                         JOIN events e ON cf.ref_id = e.id AND cf.ref_type='speaker'
                         WHERE e.title LIKE ?";
                $p2 = [$q];
                if ($type !== '') { $sql2 .= " AND cf.ref_type=?"; $p2[] = $type; }
                $sql2 .= " ORDER BY cf.created_at DESC LIMIT 50";
                $r2 = db()->prepare($sql2);
                $r2->execute($p2);
                $rows = $r2->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(array_values($rows));
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    html_start('Certificados');
    ?>
    <h1>Certificados
        <small style="font-size:12px;color:#9ca3af;font-weight:normal;margin-left:8px">
            versão <?= substr(md5(filemtime(__FILE__).filesize(__FILE__)), 0, 8) ?>
        </small>
    </h1>

    <!-- Gerar e enviar -->
    <div class="stats" style="margin-bottom:24px">
        <div class="card">
            <h3>Palestrantes</h3>
            <p style="margin:12px 0;color:#6b7280;font-size:13px">Gera o certificado com template completo (imagem de fundo) e envia por email para cada palestrante.</p>
            <a href="?p=certs&do=edit&type=speaker" class="btn">Editar Template</a>
            <form method="post" action="?p=certs&do=gen" style="margin-top:10px">
                <input type="hidden" name="type" value="speaker">
                <button class="btn btn-success" onclick="return confirm('Gerar e enviar certificados para todos os palestrantes?')">Gerar e Enviar para Todos</button>
            </form>
        </div>
        <div class="card">
            <h3>Participantes</h3>
            <p style="margin:12px 0;color:#6b7280;font-size:13px">Gera o certificado com template completo (imagem de fundo) e envia por email para cada participante confirmado.</p>
            <a href="?p=certs&do=edit&type=participant" class="btn">Editar Template</a>
            <form method="post" action="?p=certs&do=gen" style="margin-top:10px">
                <input type="hidden" name="type" value="participant">
                <button class="btn btn-success" onclick="return confirm('Gerar e enviar certificados para todos os participantes confirmados?')">Gerar e Enviar para Todos</button>
            </form>
        </div>
    </div>

    <!-- Busca e download de certificados gerados -->
    <div style="background:#fff;border:1px solid #e5e7eb;padding:24px;margin-bottom:20px">
        <h2 style="font-size:15px;font-weight:600;margin:0 0 16px">Buscar e Baixar Certificados Gerados</h2>
        <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
            <input type="text" id="cert-search" placeholder="Buscar por nome..." style="flex:1;min-width:220px;padding:8px 12px;border:1px solid #d1d5db;font-size:13px">
            <select id="cert-type-filter" style="padding:8px 12px;border:1px solid #d1d5db;font-size:13px">
                <option value="">Todos os tipos</option>
                <option value="speaker">Palestrantes</option>
                <option value="participant">Participantes</option>
            </select>
        </div>
        <div id="cert-results">
            <p style="color:#9ca3af;font-size:13px">Digite um nome para buscar, ou deixe em branco e clique em buscar para ver todos.</p>
        </div>
        <button onclick="searchCerts()" class="btn" style="margin-top:8px">Buscar</button>
    </div>

    <script>
    let certSearchT;
    document.getElementById('cert-search').addEventListener('input', function() {
        clearTimeout(certSearchT);
        certSearchT = setTimeout(searchCerts, 400);
    });
    document.getElementById('cert-type-filter').addEventListener('change', searchCerts);

    function searchCerts() {
        const q    = document.getElementById('cert-search').value;
        const type = document.getElementById('cert-type-filter').value;
        const el   = document.getElementById('cert-results');
        el.innerHTML = '<p style="color:#9ca3af;font-size:13px">Buscando...</p>';

        fetch('?p=certs&ajax=search_certs&q=' + encodeURIComponent(q) + '&type=' + encodeURIComponent(type))
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (data && data.error) throw new Error(data.error);
            if (!Array.isArray(data)) throw new Error('Resposta inválida');
            if (!data.length) {
                el.innerHTML = '<p style="color:#9ca3af;font-size:13px">'
                    + (q ? 'Nenhum certificado encontrado para "' + q + '".' 
                         : 'Ainda não há certificados gerados. Clique em "Gerar e Enviar" acima.')
                    + '</p>';
                return;
            }
            let h = '<table style="width:100%;border-collapse:collapse;font-size:13px">'
                  + '<tr>'
                  + '<th style="text-align:left;padding:8px 10px;background:#f3f4f6;border:1px solid #e5e7eb">Nome</th>'
                  + '<th style="text-align:left;padding:8px 10px;background:#f3f4f6;border:1px solid #e5e7eb">Tipo</th>'
                  + '<th style="text-align:left;padding:8px 10px;background:#f3f4f6;border:1px solid #e5e7eb">Gerado em</th>'
                  + '<th style="padding:8px 10px;background:#f3f4f6;border:1px solid #e5e7eb;text-align:center">Baixar</th>'
                  + '</tr>';
            data.forEach(r => {
                const tipo   = r.ref_type === 'speaker' ? 'Palestrante' : 'Participante';
                const dt     = (r.created_at || '—').substring(0,16);
                const ev     = r.event_title || '';
                const esc    = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                h += '<tr>'
                   + '<td style="padding:8px 10px;border:1px solid #e5e7eb"><strong>' + esc(r.ref_name) + '</strong>'
                   + (ev ? '<br><small style="color:#6b7280">' + esc(ev) + '</small>' : '') + '</td>'
                   + '<td style="padding:8px 10px;border:1px solid #e5e7eb">' + tipo + '</td>'
                   + '<td style="padding:8px 10px;border:1px solid #e5e7eb;white-space:nowrap;font-size:12px">' + esc(dt) + '</td>'
                   + '<td style="padding:8px 10px;border:1px solid #e5e7eb;text-align:center">'
                   + '<a href="?p=certs&do=download&id=' + encodeURIComponent(r.id) + '" '
                   + 'class="btn btn-sm" style="font-size:11px;padding:4px 10px">⬇ PDF</a>'
                   + '</td></tr>';
            });
            el.innerHTML = h + '</table>';
        })
        .catch(err => {
            el.innerHTML = '<p style="color:#ef4444;font-size:13px">Erro ao buscar certificados: ' + err.message 
                + '. Se for a primeira vez, execute o <a href="install.php">install.php</a> para criar a tabela.</p>';
        });
    }
    // Carregar todos ao abrir a página
    searchCerts();
    </script>
    <?php
    html_end();
    exit;
}

// ==================== GRADES ====================
if ($p === 'grids') {
    // AJAX: Salvar horários em tempo real
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'save_time' && $_POST) {
        header('Content-Type: application/json');
        $event_id = (int)$_POST['event_id'];
        $field = $_POST['field'] === 'start_time' ? 'start_time' : 'end_time';
        $value = $_POST['value'];
        
        try {
            db()->prepare("UPDATE events SET $field = ? WHERE id = ?")->execute(array($value, $event_id));
            echo json_encode(array('success' => true));
        } catch (Exception $e) {
            echo json_encode(array('success' => false, 'error' => $e->getMessage()));
        }
        exit;
    }
    
    // Delete
    if ($do === 'del' && $id) {
        db()->prepare("DELETE FROM grids WHERE id=?")->execute(array($id));
        flash('ok', 'Grade excluída!');
        go('?p=grids');
    }
    
    // Save
    if ($_POST && isset($_POST['title'])) {
        // Usar ordem customizada se fornecida, senão ordem padrão
        if (!empty($_POST['event_order']) && isset($_POST['events'])) {
            $order_array = explode(',', $_POST['event_order']);
            $selected = $_POST['events'];
            $ordered_events = array();
            foreach ($order_array as $eid) {
                if (in_array($eid, $selected)) {
                    $ordered_events[] = $eid;
                }
            }
            $events = implode(',', $ordered_events);
        } else {
            $events = isset($_POST['events']) ? implode(',', $_POST['events']) : '';
        }
        
        $d = array(
            $_POST['title'],
            $events,
            isset($_POST['show_times']) ? 1 : 0,
            isset($_POST['show_speakers']) ? 1 : 0,
            isset($_POST['show_types']) ? 1 : 0,
            isset($_POST['show_photos']) ? 1 : 0
        );
        
        if ($id) {
            $d[] = $id;
            db()->prepare("UPDATE grids SET title=?,selected_events=?,show_times=?,show_speakers=?,show_types=?,show_photos=? WHERE id=?")->execute($d);
            flash('ok', 'Grade atualizada!');
        } else {
            db()->prepare("INSERT INTO grids (title,selected_events,show_times,show_speakers,show_types,show_photos) VALUES (?,?,?,?,?,?)")->execute($d);
            flash('ok', 'Grade criada!');
        }
        go('?p=grids');
    }
    
    // Form
    if ($do === 'edit' || $do === 'add') {
        $e = array('title'=>'','selected_events'=>'','show_times'=>1,'show_speakers'=>1,'show_types'=>1,'show_photos'=>1);
        if ($id) {
            $r = db()->prepare("SELECT * FROM grids WHERE id=?");
            $r->execute(array($id));
            $e = $r->fetch();
        }
        $selected = $e['selected_events'] ? explode(',', $e['selected_events']) : array();
        $events = db()->query("SELECT * FROM events ORDER BY event_order, title")->fetchAll();
        
        html_start($id ? 'Editar Grade' : 'Nova Grade');
        ?>
        <h1><?= $id ? 'Editar' : 'Nova' ?> Grade de Programação</h1>
        <form method="post" style="max-width:800px">
            <label>Título da Grade *</label>
            <input name="title" value="<?= h($e['title']) ?>" required style="width:100%">
            
            <label>Eventos Incluídos (arraste para reordenar e edite horários inline)</label>
            <p style="font-size:13px;color:#10b981;margin-bottom:10px">Arraste eventos ou edite horários diretamente. Salvamento automático.</p>
            <div id="events-sortable" style="max-height:400px;overflow-y:auto;border:1px solid #d1d5db;border-radius:6px;padding:15px;background:#f9fafb">
                <?php foreach ($events as $ev): ?>
                    <div class="event-item" data-id="<?= $ev['id'] ?>" style="padding:12px;margin:8px 0;background:<?= in_array($ev['id'],$selected)?'#dbeafe':'#fff' ?>;border:1px solid #ddd;border-radius:6px;cursor:move">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <input type="checkbox" name="events[]" value="<?= $ev['id'] ?>" <?= in_array($ev['id'],$selected)?'checked':'' ?>>
                            <span style="font-weight:600;flex:1;min-width:200px"><?= h($ev['title']) ?></span>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="time" class="event-time-start" data-event-id="<?= $ev['id'] ?>" value="<?= h($ev['start_time']) ?>" style="padding:6px;border:1px solid #ddd;border-radius:4px;font-size:13px">
                                <span>até</span>
                                <input type="time" class="event-time-end" data-event-id="<?= $ev['id'] ?>" value="<?= h($ev['end_time']) ?>" style="padding:6px;border:1px solid #ddd;border-radius:4px;font-size:13px">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="save-status" style="margin-top:10px;font-size:13px;color:#10b981"></div>
            <input type="hidden" name="event_order" id="event_order" value="">
            
            <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
            <script>
            // Drag-and-drop
            const sortable = new Sortable(document.getElementById('events-sortable'), {
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function(evt) {
                    const items = document.querySelectorAll('.event-item');
                    const order = [];
                    items.forEach(item => {
                        order.push(item.getAttribute('data-id'));
                    });
                    document.getElementById('event_order').value = order.join(',');
                    showSaveStatus('Ordem salva! Clique em Salvar para confirmar.');
                }
            });
            
            // Checkbox toggle
            document.querySelectorAll('.event-item input[type="checkbox"]').forEach(cb => {
                cb.addEventListener('change', function() {
                    this.closest('.event-item').style.background = this.checked ? '#dbeafe' : '#fff';
                });
            });
            
            // Salvar horários via AJAX em tempo real
            let saveTimeout;
            document.querySelectorAll('.event-time-start, .event-time-end').forEach(input => {
                input.addEventListener('change', function() {
                    clearTimeout(saveTimeout);
                    const eventId = this.getAttribute('data-event-id');
                    const isStart = this.classList.contains('event-time-start');
                    const value = this.value;
                    
                    showSaveStatus('Salvando...');
                    
                    saveTimeout = setTimeout(() => {
                        fetch('?p=grids&ajax=save_time', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'event_id=' + eventId + '&field=' + (isStart ? 'start_time' : 'end_time') + '&value=' + encodeURIComponent(value)
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                showSaveStatus('Horário salvo automaticamente!');
                                setTimeout(() => showSaveStatus(''), 2000);
                            } else {
                                showSaveStatus('Erro ao salvar');
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            showSaveStatus('Erro de conexão');
                        });
                    }, 500);
                });
            });
            
            function showSaveStatus(msg) {
                document.getElementById('save-status').textContent = msg;
            }
            </script>
            
            <h3 style="margin:25px 0 15px">Opções de Exibição</h3>
            <label style="font-weight:normal"><input type="checkbox" name="show_times" value="1" <?= $e['show_times']?'checked':'' ?>> Mostrar horários</label>
            <label style="font-weight:normal"><input type="checkbox" name="show_speakers" value="1" <?= $e['show_speakers']?'checked':'' ?>> Mostrar palestrantes</label>
            <label style="font-weight:normal"><input type="checkbox" name="show_types" value="1" <?= $e['show_types']?'checked':'' ?>> Mostrar tipos de atividade</label>
            <label style="font-weight:normal"><input type="checkbox" name="show_photos" value="1" <?= $e['show_photos']?'checked':'' ?>> Mostrar fotos</label>
            
            <br><br>
            <button type="submit" class="btn">Salvar</button>
            <a href="?p=grids" class="btn btn-secondary">Cancelar</a>
        </form>
        <?php
        html_end();
        exit;
    }
    
    // List
    $list = db()->query("SELECT * FROM grids ORDER BY id DESC")->fetchAll();
    
    html_start('Grades de Programação');
    ?>
    <div class="page-header">
        <h1>Grades de Programação</h1>
        <a href="?p=grids&do=add" class="btn">Nova Grade</a>
    </div>
    <div class="table-wrap"><table>
        <tr><th>Título</th><th>Atividades</th><th>Opções</th><th>Ações</th></tr>
        <?php if (empty($list)): ?>
            <tr><td colspan="4" class="empty">Nenhuma grade criada</td></tr>
        <?php else: ?>
            <?php foreach ($list as $g): ?>
            <?php
            $count = $g['selected_events'] ? count(explode(',', $g['selected_events'])) : 0;
            $opts = array();
            if ($g['show_times']) $opts[] = 'Horários';
            if ($g['show_speakers']) $opts[] = 'Palestrantes';
            if ($g['show_types']) $opts[] = 'Tipos';
            if ($g['show_photos']) $opts[] = 'Fotos';
            ?>
            <tr>
                <td><strong><?= h($g['title']) ?></strong></td>
                <td><?= $count ?> evento(s)</td>
                <td><?= implode(', ', $opts) ?></td>
                <td>
                    <a href="?p=grids&do=edit&id=<?= $g['id'] ?>" class="btn btn-sm">Editar</a>
                    <button onclick="copyGridLink(<?= $g['id'] ?>)" class="btn btn-sm btn-success">Copiar Link</button>
                    <a href="?p=grids&do=del&id=<?= $g['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir?')">Excluir</a>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table></div>
    <script>
    function copyGridLink(id) {
        const link = '<?= get_base_url() ?>/public-links.php?p=gridview&id=' + id;
        const textarea = document.createElement('textarea');
        textarea.value = link;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('Link público copiado!\n\n' + link);
    }
    </script>
    <?php
    html_end();
    exit;
}

// ==================== TIPOS DE ATIVIDADES ====================
if ($p === 'types') {
    if ($do === 'del' && $id) {
        db()->prepare("DELETE FROM activity_types WHERE id=?")->execute(array($id));
        flash('ok', 'Tipo excluído!');
        go('?p=types');
    }
    
    if ($_POST && isset($_POST['name'])) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $_POST['name'])));
        if ($id) {
            db()->prepare("UPDATE activity_types SET name=?, slug=? WHERE id=?")->execute(array($_POST['name'], $slug, $id));
            flash('ok', 'Tipo atualizado!');
        } else {
            db()->prepare("INSERT INTO activity_types (name, slug) VALUES (?,?)")->execute(array($_POST['name'], $slug));
            flash('ok', 'Tipo criado!');
        }
        go('?p=types');
    }
    
    if ($do === 'edit' || $do === 'add') {
        $e = array('name'=>'');
        if ($id) {
            $r = db()->prepare("SELECT * FROM activity_types WHERE id=?");
            $r->execute(array($id));
            $e = $r->fetch();
        }
        html_start($id ? 'Editar Tipo' : 'Novo Tipo');
        ?>
        <h1><?= $id ? 'Editar' : 'Novo' ?> Tipo de Atividade</h1>
        <form method="post" style="max-width:600px">
            <label>Nome do Tipo *</label>
            <input name="name" value="<?= h($e['name']) ?>" required style="width:100%">
            <br><br>
            <button class="btn">Salvar</button>
            <a href="?p=types" class="btn btn-secondary">Cancelar</a>
        </form>
        <?php
        html_end();
        exit;
    }
    
    $list = db()->query("SELECT * FROM activity_types ORDER BY name")->fetchAll();
    html_start('Tipos de Atividades');
    ?>
    <div class="page-header">
        <h1>Tipos de Atividades</h1>
        <a href="?p=types&do=add" class="btn">Novo Tipo</a>
    </div>
    <div class="table-wrap"><table>
        <tr><th>Nome</th><th>Slug</th><th>Ações</th></tr>
        <?php foreach ($list as $t): ?>
        <tr>
            <td><strong><?= h($t['name']) ?></strong></td>
            <td><?= h($t['slug']) ?></td>
            <td>
                <a href="?p=types&do=edit&id=<?= $t['id'] ?>" class="btn btn-sm">Editar</a>
                <a href="?p=types&do=del&id=<?= $t['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir?')">Excluir</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php
    html_end();
    exit;
}

// ==================== CAMPOS CUSTOMIZÁVEIS ====================
if ($p === 'fields') {
    if ($do === 'del' && $id) {
        db()->prepare("DELETE FROM custom_fields WHERE id=?")->execute(array($id));
        flash('ok', 'Campo excluído!');
        go('?p=fields');
    }
    
    if ($_POST && isset($_POST['field_label'])) {
        $key = strtolower(preg_replace('/[^a-z0-9]+/', '_', $_POST['field_label']));
        $d = array(
            $key,
            $_POST['field_label'],
            $_POST['field_type'],
            $_POST['field_options'] ?? '',
            isset($_POST['field_required']) ? 1 : 0,
            isset($_POST['field_show_in_table']) ? 1 : 0,
            $_POST['context']
        );
        
        if ($id) {
            $d[] = $id;
            db()->prepare("UPDATE custom_fields SET field_key=?,field_label=?,field_type=?,field_options=?,field_required=?,field_show_in_table=?,context=? WHERE id=?")->execute($d);
            flash('ok', 'Campo atualizado!');
        } else {
            db()->prepare("INSERT INTO custom_fields (field_key,field_label,field_type,field_options,field_required,field_show_in_table,context) VALUES (?,?,?,?,?,?,?)")->execute($d);
            flash('ok', 'Campo criado!');
        }
        go('?p=fields');
    }
    
    if ($do === 'edit' || $do === 'add') {
        $e = array('field_label'=>'','field_type'=>'text','field_options'=>'','field_required'=>0,'field_show_in_table'=>0,'context'=>'event');
        if ($id) {
            $r = db()->prepare("SELECT * FROM custom_fields WHERE id=?");
            $r->execute(array($id));
            $e = $r->fetch();
        }
        html_start($id ? 'Editar Campo' : 'Novo Campo');
        ?>
        <h1><?= $id ? 'Editar' : 'Novo' ?> Campo Customizável</h1>
        <form method="post" style="max-width:600px">
            <label>Nome do Campo *</label>
            <input name="field_label" value="<?= h($e['field_label']) ?>" required style="width:100%">
            
            <label>Tipo</label>
            <select name="field_type" style="width:100%">
                <option value="text" <?= $e['field_type']==='text'?'selected':'' ?>>Texto</option>
                <option value="textarea" <?= $e['field_type']==='textarea'?'selected':'' ?>>Texto Longo</option>
                <option value="select" <?= $e['field_type']==='select'?'selected':'' ?>>Seleção</option>
                <option value="checkbox" <?= $e['field_type']==='checkbox'?'selected':'' ?>>Checkbox</option>
            </select>
            
            <label>Opções (para select, separado por vírgula)</label>
            <input name="field_options" value="<?= h($e['field_options']) ?>" style="width:100%">
            
            <label>Contexto</label>
            <select name="context" style="width:100%">
                <option value="event" <?= $e['context']==='event'?'selected':'' ?>>Atividades</option>
                <option value="participant" <?= $e['context']==='participant'?'selected':'' ?>>Participantes</option>
            </select>
            
            <br>
            <label style="font-weight:normal"><input type="checkbox" name="field_required" value="1" <?= $e['field_required']?'checked':'' ?>> Campo obrigatório</label>
            <label style="font-weight:normal"><input type="checkbox" name="field_show_in_table" value="1" <?= $e['field_show_in_table']?'checked':'' ?>> Mostrar na listagem</label>
            
            <br><br>
            <button class="btn">Salvar</button>
            <a href="?p=fields" class="btn btn-secondary">Cancelar</a>
        </form>
        <?php
        html_end();
        exit;
    }
    
    $list = db()->query("SELECT * FROM custom_fields ORDER BY context, field_label")->fetchAll();
    html_start('Campos Customizáveis');
    ?>
    <div class="page-header">
        <h1>Campos Customizáveis</h1>
        <a href="?p=fields&do=add" class="btn">Novo Campo</a>
    </div>
    <div class="table-wrap"><table>
        <tr><th>Campo</th><th>Tipo</th><th>Contexto</th><th>Obrigatório</th><th>Na Tabela</th><th>Ações</th></tr>
        <?php if (empty($list)): ?>
            <tr><td colspan="6" class="empty">Nenhum campo criado</td></tr>
        <?php else: ?>
            <?php foreach ($list as $f): ?>
            <tr>
                <td><strong><?= h($f['field_label']) ?></strong></td>
                <td><?= h($f['field_type']) ?></td>
                <td><?= h($f['context']) ?></td>
                <td><?= $f['field_required'] ? 'Sim' : '—' ?></td>
                <td><?= $f['field_show_in_table'] ? 'Sim' : '—' ?></td>
                <td>
                    <a href="?p=fields&do=edit&id=<?= $f['id'] ?>" class="btn btn-sm">Editar</a>
                    <a href="?p=fields&do=del&id=<?= $f['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Excluir?')">Excluir</a>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table></div>
    <?php
    html_end();
    exit;
}

// ==================== CONFIGURAÇÕES ====================
if ($p === 'config') {
    // Troca de credenciais de login
    if ($_POST && isset($_POST['_action']) && $_POST['_action'] === 'update_login') {
        $new_email = trim($_POST['login_email'] ?? '');
        $new_pass  = trim($_POST['login_pass']  ?? '');
        $cur_pass  = trim($_POST['login_pass_current'] ?? '');
        $user_row  = db()->query("SELECT * FROM users ORDER BY id ASC LIMIT 1")->fetch();
        $errors = [];
        if (!$user_row || !password_verify($cur_pass, $user_row['password'])) {
            $errors[] = 'Senha atual incorreta.';
        }
        if ($new_email && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Novo email inválido.';
        }
        if ($new_pass && strlen($new_pass) < 6) {
            $errors[] = 'Nova senha deve ter pelo menos 6 caracteres.';
        }
        if ($errors) {
            flash('err', implode(' ', $errors));
        } else {
            if ($new_email) {
                db()->prepare("UPDATE users SET email=? WHERE id=?")->execute(array($new_email, $user_row['id']));
            }
            if ($new_pass) {
                db()->prepare("UPDATE users SET password=? WHERE id=?")->execute(array(password_hash($new_pass, PASSWORD_DEFAULT), $user_row['id']));
            }
            flash('ok', 'Credenciais de acesso atualizadas!');
        }
        go('?p=config');
    }

    if ($_POST && !isset($_POST['_action'])) {
        foreach ($_POST as $k => $v) {
            if ($k === 'submit') continue;
            // Não sobrescrever smtp_pass se campo deixado em branco
            if ($k === 'smtp_pass' && $v === '') continue;
            db()->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute(array($k,$v,$v));
        }
        flash('ok', 'Configurações salvas!');
        go('?p=config');
    }
    
    $settings = array();
    foreach (db()->query("SELECT * FROM settings") as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
    
    html_start('Configurações');
    ?>
    <h1>Configurações do Sistema</h1>
    <form method="post" style="max-width:600px">
        <label>Nome do Sistema</label>
        <input name="site_name" value="<?= h($settings['site_name'] ?? 'Bienenstock') ?>" style="width:100%">
        
        <label>Email Remetente / Admin (recebe notificações)</label>
        <input type="email" name="from_email" value="<?= h($settings['from_email'] ?? '') ?>" style="width:100%" placeholder="seuemail@gmail.com">

        <h3 style="margin:28px 0 14px;padding-top:18px;border-top:1px solid #eee;font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:#333">Configuração SMTP para Envio de Emails</h3>
        <p style="font-size:12px;color:#888;margin:0 0 14px;line-height:1.6">
            Configure um servidor SMTP para garantir a entrega dos emails. Sem isso, o PHP usa <code>mail()</code> que costuma cair em spam ou ser bloqueado.<br>
            Presets rápidos:
            <a href="#" onclick="setSmtp('smtp.gmail.com','587','starttls');return false" style="color:#333;margin:0 6px">Gmail</a> ·
            <a href="#" onclick="setSmtp('smtp.office365.com','587','starttls');return false" style="color:#333;margin:0 6px">Outlook/Office365</a> ·
            <a href="#" onclick="setSmtp('smtp.zoho.com','587','starttls');return false" style="color:#333;margin:0 6px">Zoho</a> ·
            <a href="#" onclick="setSmtp('smtp.titan.email','587','starttls');return false" style="color:#333;margin:0 6px">Titan</a> ·
            <a href="#" onclick="setSmtp('smtp.mailgun.org','587','starttls');return false" style="color:#333;margin:0 6px">Mailgun</a> ·
            <a href="#" onclick="setSmtp('smtp-relay.brevo.com','587','starttls');return false" style="color:#333;margin:0 6px">Brevo</a> ·
            <a href="#" onclick="setSmtp('smtp.sendgrid.net','587','starttls');return false" style="color:#333;margin:0 6px">SendGrid</a> ·
            <a href="#" onclick="setSmtp('smtp.locaweb.com.br','587','starttls');return false" style="color:#333;margin:0 6px">Locaweb</a> ·
            <a href="#" onclick="setSmtp('smtp.uol.com.br','587','starttls');return false" style="color:#333;margin:0 6px">UOL</a> ·
            <a href="#" onclick="setSmtp('sandbox.smtp.mailtrap.io','2525','none');return false" style="color:#333;margin:0 6px">Mailtrap</a>
        </p>

        <div class="form-row">
            <div>
                <label>Servidor SMTP</label>
                <input id="smtp_host" name="smtp_host" value="<?= h($settings['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com" style="width:100%">
            </div>
            <div>
                <label>Porta</label>
                <input id="smtp_port" name="smtp_port" value="<?= h($settings['smtp_port'] ?? '587') ?>" placeholder="587" style="width:100%">
            </div>
        </div>

        <div class="form-row">
            <div>
                <label>Usuário SMTP (email completo)</label>
                <input name="smtp_user" value="<?= h($settings['smtp_user'] ?? '') ?>" placeholder="seuemail@dominio.com" style="width:100%">
            </div>
            <div>
                <label>Criptografia</label>
                <select id="smtp_enc" name="smtp_enc" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;font-size:13px">
                    <?php
                    $cur_enc = $settings['smtp_enc'] ?? 'auto';
                    $encs = ['auto'=>'Auto (detecta pela porta)', 'starttls'=>'STARTTLS — porta 587 (Gmail, Outlook, Zoho…)', 'ssl'=>'SSL/TLS direto — porta 465', 'none'=>'Sem criptografia — porta 25/2525 (Mailtrap, local)'];
                    foreach ($encs as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $cur_enc===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label>Senha SMTP</label>
        <input type="password" name="smtp_pass" value="" placeholder="Deixe em branco para manter a senha atual" style="width:100%" autocomplete="new-password">
        <p style="font-size:11px;color:#999;margin:4px 0 0;line-height:1.5">
            <strong>Gmail:</strong> não use a senha da conta — crie uma <strong>Senha de App</strong> em
            <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color:#555">myaccount.google.com/apppasswords</a>
            (requer 2FA ativo). Cole os 16 caracteres normalmente, mesmo com espaços — o sistema remove automaticamente.
        </p>
        <script>
        function setSmtp(host, port, enc) {
            document.getElementById('smtp_host').value = host;
            document.getElementById('smtp_port').value = port;
            document.getElementById('smtp_enc').value  = enc;
        }
        </script>

        <h3 style="margin:28px 0 14px;padding-top:18px;border-top:1px solid #eee;font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:#333">Dados do Evento (usados nos certificados)</h3>

        <label>Nome do Evento</label>
        <input name="event_title" value="<?= h($settings['event_title'] ?? '') ?>" placeholder="Ex: Workshop de Inovação 2026" style="width:100%">

        <div class="form-row">
            <div>
                <label>Data Inicial</label>
                <input type="date" name="event_date_start" value="<?= h($settings['event_date_start'] ?? '') ?>" style="width:100%">
            </div>
            <div>
                <label>Data Final</label>
                <input type="date" name="event_date_end" value="<?= h($settings['event_date_end'] ?? '') ?>" style="width:100%">
            </div>
        </div>
        
        <br><br>
        <button class="btn">Salvar Configurações</button>
    </form>

    <h3 style="margin:32px 0 14px;padding-top:24px;border-top:2px solid #eee;font-size:13px;text-transform:uppercase;letter-spacing:.4px;color:#333">Credenciais de Acesso</h3>
    <form method="post" style="max-width:600px">
        <input type="hidden" name="_action" value="update_login">
        <p style="font-size:12px;color:#888;margin:0 0 14px">Preencha apenas os campos que deseja alterar. A senha atual é obrigatória para confirmar a alteração.</p>

        <label>Senha Atual (obrigatória para confirmar)</label>
        <input type="password" name="login_pass_current" required style="width:100%" autocomplete="current-password">

        <div class="form-row">
            <div>
                <label>Novo Email de Login</label>
                <input type="email" name="login_email" placeholder="Deixe em branco para manter" style="width:100%" autocomplete="username">
            </div>
            <div>
                <label>Nova Senha</label>
                <input type="password" name="login_pass" placeholder="Mínimo 6 caracteres" style="width:100%" autocomplete="new-password">
            </div>
        </div>

        <button class="btn">Atualizar Credenciais</button>
    </form>
    <?php
    html_end();
    exit;
}

// ==================== FORMULÁRIO PÚBLICO ====================
if ($p === 'public') {
    // Processar inscrição
    if ($_POST && isset($_POST['name'])) {
        $qr = qr_key();
        $grid_id = (int)($_POST['grid_id'] ?? 0);
        
        // Buscar título da grade para gravar em event_name
        $event_name_val = '';
        if ($grid_id) {
            $gr = db()->prepare("SELECT title FROM grids WHERE id = ?");
            $gr->execute(array($grid_id));
            $gr_row = $gr->fetch();
            $event_name_val = $gr_row ? $gr_row['title'] : '';
        }
        
        $db->prepare("INSERT INTO participants (name,email,document,organization,phone,event_name,status,qr_key) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(array(
                $_POST['name'],
                $_POST['email'],
                $_POST['document'] ?? '',
                $_POST['organization'] ?? '',
                $_POST['phone'] ?? '',
                $event_name_val,
                'pending',
                $qr
            ));
        
        $participant_id = db()->lastInsertId();
        
        // Salvar grid_id na participant_meta para o select do formulário de edição
        if ($grid_id) {
            save_meta('participant', $participant_id, 'grid_id', $grid_id);
        }
        
        // Salvar campos customizados
        if (isset($_POST['custom']) && is_array($_POST['custom'])) {
            foreach ($_POST['custom'] as $key => $value) {
                save_meta('participant', $participant_id, $key, $value);
            }
        }
        
        // Enviar email confirmação com QR Code embutido
        $s_cfg = get_settings_email();
        $evento_nome_pub = $event_name_val;
        $msg_part = email_participante_html($_POST['name'], $evento_nome_pub, $_POST['document'] ?? '', $_POST['organization'] ?? '', $_POST['phone'] ?? '', $qr, $s_cfg['site_name']);
        send_email($_POST['email'], 'Confirmação de Inscrição — ' . $s_cfg['site_name'], $msg_part);
        
        // Notificar administrador
        $msg_adm = email_admin_participante_html($_POST['name'], $_POST['email'], $evento_nome_pub, $_POST['document'] ?? '', $_POST['organization'] ?? '', $_POST['phone'] ?? '', $s_cfg['site_name']);
        notify_admin('Nova Inscrição (Formulário Público): ' . $_POST['name'], $msg_adm);
        
        $success = true;
    }
    
    // Buscar campos customizados e grades
    $campos = db()->query("SELECT * FROM custom_fields WHERE context='participant' ORDER BY id")->fetchAll();
    $grades = db()->query("SELECT id, title FROM grids ORDER BY title")->fetchAll();
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inscrição - Bienenstock</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#f0f0f0;min-height:100vh;padding:40px 20px;font-size:14px;color:#222}
    .box{max-width:560px;margin:0 auto;background:#fff;padding:36px 40px;border:1px solid #ddd}
    h1{font-size:18px;font-weight:600;color:#111;margin-bottom:4px}
    .sub{color:#888;font-size:13px;margin-bottom:24px;padding-bottom:18px;border-bottom:1px solid #eee}
    label{display:block;font-size:12px;font-weight:600;color:#555;margin-top:14px;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px}
    input,select,textarea{width:100%;padding:8px 10px;border:1px solid #d0d0d0;border-radius:3px;font-size:14px;font-family:inherit}
    input:focus,select:focus,textarea:focus{outline:none;border-color:#555}
    button{width:100%;padding:10px;background:#2c2c2c;color:#fff;border:none;font-size:14px;font-weight:500;cursor:pointer;margin-top:22px;border-radius:3px}
    button:hover{background:#111}
    .success{background:#edfaf3;color:#1a5c38;padding:16px;border-left:3px solid #27ae60;font-size:14px}
    .success h2{font-size:15px;margin-bottom:6px}
    label.check{font-weight:normal;text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:8px;font-size:14px;color:#333}
    label.check input{width:auto}
    </style>
    </head><body>
    <div class="box">
        <h1>Inscrição no Evento</h1>
        <div class="sub">Preencha seus dados para se inscrever</div>
        
        <?php if (isset($success)): ?>
            <div class="success">
                <h2>Inscrição realizada!</h2>
                <p>Enviamos um email de confirmação com seu QR Code.</p>
            </div>
        <?php else: ?>
            <form method="post">
                <label>Nome Completo *</label>
                <input name="name" required>
                
                <label>Email *</label>
                <input type="email" name="email" required>
                
                <label>CPF / Documento</label>
                <input name="document">
                
                <label>Organização</label>
                <input name="organization">
                
                <label>Telefone</label>
                <input name="phone">
                
                <?php if (!empty($campos)): ?>
                    <?php foreach ($campos as $campo): ?>
                        <label><?= h($campo['field_label']) ?><?= $campo['field_required']?' *':'' ?></label>
                        <?php if ($campo['field_type'] === 'textarea'): ?>
                            <textarea name="custom[<?= h($campo['field_key']) ?>]" rows="3" <?= $campo['field_required']?'required':'' ?>></textarea>
                        <?php elseif ($campo['field_type'] === 'select'): ?>
                            <select name="custom[<?= h($campo['field_key']) ?>]" <?= $campo['field_required']?'required':'' ?>>
                                <option value="">Selecione...</option>
                                <?php foreach (explode(',', $campo['field_options']) as $opt): ?>
                                    <option value="<?= h(trim($opt)) ?>"><?= h(trim($opt)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($campo['field_type'] === 'checkbox'): ?>
                            <label class="check"><input type="checkbox" name="custom[<?= h($campo['field_key']) ?>]" value="1"> <?= h($campo['field_label']) ?></label>
                        <?php else: ?>
                            <input name="custom[<?= h($campo['field_key']) ?>]" <?= $campo['field_required']?'required':'' ?>>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <label>Grade / Programação *</label>
                <select name="grid_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($grades as $gr): ?>
                        <option value="<?= $gr['id'] ?>"><?= h($gr['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <button>Inscrever-se</button>
            </form>
        <?php endif; ?>
    </div>
    </body></html>
    <?php
    exit;
}

// 404
html_start('404');
echo '<div class="empty"><h1 style="font-size:72px;color:#aaa">404</h1><h2>Página não encontrada</h2><p><a href="?p=dash" class="btn" style="margin-top:20px">← Voltar ao Dashboard</a></p></div>';
html_end();
