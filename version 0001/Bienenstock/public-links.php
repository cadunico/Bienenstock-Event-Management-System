<?php
/**
 * BIENENSTOCK - Sistema de Gestão de Eventos
 * Versão: 0.001 — Módulo: Páginas Públicas
 *
 * Desenvolvedor: Carlos Eduardo (cadunico)
 *
 * Licença: GPL v3
 *   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Conteúdo: CC BY-SA 3.0
 *   https://creativecommons.org/licenses/by-sa/3.0/
 */
session_start();
require 'config.php';
$db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Carrega SMTP Mailer no topo — fora de funções para garantir disponibilidade
if (file_exists(__DIR__ . '/smtp_mailer.php')) {
    require_once __DIR__ . '/smtp_mailer.php';
}

function h($t) { return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8'); }

function get_base_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    return $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
}

function ensure_uploads_dir() {
    $dir = __DIR__ . '/uploads/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true) || @mkdir($dir, 0777, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
        if (!is_writable($dir)) { @chmod($dir, 0777); }
    }
    $ht = $dir . '.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht,
            "<FilesMatch \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$\">\n" .
            "    Order Deny,Allow\n    Deny from all\n</FilesMatch>\n"
        );
    }
    return (is_dir($dir) && is_writable($dir)) ? $dir : '';
}

function get_event_meta($db, $event_id, $key, $default = '') {
    $stmt = $db->prepare("SELECT meta_value FROM event_meta WHERE event_id = ? AND meta_key = ?");
    $stmt->execute(array($event_id, $key));
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ? $r['meta_value'] : $default;
}

// ── Email helpers ─────────────────────────────────────────────────────────
function pl_get_settings() {
    global $db;
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { $rows = array(); }
    $from = trim($rows['from_email'] ?? '');
    if (empty($from)) {
        try {
            $r = $db->query("SELECT email FROM users ORDER BY id ASC LIMIT 1")->fetch();
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

function pl_send_email($to, $subject, $body) {
    $to = trim($to ?? '');
    if (empty($to)) return false;
    $s    = pl_get_settings();
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
function pl_notify_admin($subject, $body) {
    $s = pl_get_settings();
    if (!empty($s['admin_email'])) {
        return pl_send_email($s['admin_email'], $subject, $body);
    }
    return false;
}

function pl_qr_image_tag($data, $size = 180) {
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
         . '&format=png&qzone=2&data=' . rawurlencode($data);
    return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
         . 'width="' . $size . '" height="' . $size . '" alt="QR Code" '
         . 'style="display:block;margin:10px auto;border:4px solid #fff">';
}

function pl_email_wrap($titulo_hd, $conteudo, $site_name) {
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
    . '.ft{font-size:10px;color:#bbb;text-align:center;padding:8px 0 14px}'
    . '</style></head><body><div class="wrap">'
    . '<div class="hd"><h1>' . $ht . '</h1><p>' . $hs . ' · v0.001</p></div>'
    . '<div class="bd">' . $conteudo . '</div>'
    . '<div class="ft">' . $hs . ' · v0.001 · GPL v3 / CC BY-SA 3.0</div>'
    . '</div></body></html>';
}

function pl_email_participante($nome, $evento, $doc, $org, $phone, $qr_key, $site_name) {
    $h   = function($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $qr_url = get_base_url() . '/index.php?qr=' . rawurlencode($qr_key);
    $rows = '<tr><td>Evento</td><td>'  . $h($evento) . '</td></tr>'
          . '<tr><td>Nome</td><td>'    . $h($nome)   . '</td></tr>'
          . ($doc   ? '<tr><td>Documento</td><td>'    . $h($doc)   . '</td></tr>' : '')
          . ($org   ? '<tr><td>Organização</td><td>' . $h($org) . '</td></tr>' : '')
          . ($phone ? '<tr><td>Telefone</td><td>'     . $h($phone) . '</td></tr>' : '')
          . '<tr><td>Data</td><td>' . date('d/m/Y H:i') . '</td></tr>';
    $corpo = '<h2>Inscrição Confirmada</h2>'
           . '<p>Olá <strong>' . $h($nome) . '</strong>, sua inscrição foi registrada com sucesso!</p>'
           . '<table class="info">' . $rows . '</table>'
           . '<div class="qrbox">'
           . '<p style="font-size:11px;font-weight:600;color:#333;text-transform:uppercase;letter-spacing:.5px;margin:0 0 4px">QR Code para Check-in</p>'
           . pl_qr_image_tag($qr_url, 180)
           . '<p style="font-size:11px;color:#888;margin:8px 0 0">Apresente este código na entrada do evento</p>'
           . '</div>';
    return pl_email_wrap('Confirmação de Inscrição', $corpo, $site_name);
}

function pl_email_admin_participante($nome, $email, $evento, $doc, $org, $phone, $site_name) {
    $h   = function($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $rows = '<tr><td>Nome</td><td>'   . $h($nome)   . '</td></tr>'
          . '<tr><td>Email</td><td>'  . $h($email)  . '</td></tr>'
          . '<tr><td>Evento</td><td>' . $h($evento) . '</td></tr>'
          . ($doc   ? '<tr><td>Documento</td><td>'    . $h($doc)   . '</td></tr>' : '')
          . ($org   ? '<tr><td>Organização</td><td>' . $h($org) . '</td></tr>' : '')
          . ($phone ? '<tr><td>Telefone</td><td>'     . $h($phone) . '</td></tr>' : '')
          . '<tr><td>Recebido em</td><td>' . date('d/m/Y H:i') . '</td></tr>';
    $corpo = '<h2>Nova Inscrição Recebida</h2>'
           . '<table class="info">' . $rows . '</table>';
    return pl_email_wrap('Nova Inscrição — ' . $site_name, $corpo, $site_name);
}

function pl_email_palestrante($nome, $titulo, $local, $horario, $site_name) {
    $h   = function($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $rows = '<tr><td>Atividade</td><td>' . $h($titulo) . '</td></tr>'
          . ($local   ? '<tr><td>Local</td><td>'    . $h($local)   . '</td></tr>' : '')
          . ($horario ? '<tr><td>Horário</td><td>' . $h($horario) . '</td></tr>' : '');
    $corpo = '<h2>Proposta Recebida</h2>'
           . '<p>Olá <strong>' . $h($nome) . '</strong>, sua proposta foi recebida e será analisada pela equipe.</p>'
           . '<table class="info">' . $rows . '</table>';
    return pl_email_wrap('Proposta de Atividade Recebida', $corpo, $site_name);
}

function pl_email_admin_atividade($titulo, $palestrante, $email_pal, $resumo, $site_name) {
    $h   = function($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $rows = '<tr><td>Atividade</td><td>'    . $h($titulo)      . '</td></tr>'
          . '<tr><td>Palestrante</td><td>'  . $h($palestrante) . '</td></tr>'
          . '<tr><td>Email</td><td>'        . $h($email_pal)   . '</td></tr>'
          . ($resumo ? '<tr><td>Resumo</td><td>' . $h(mb_substr($resumo,0,300)) . (mb_strlen($resumo)>300?'&hellip;':'') . '</td></tr>' : '')
          . '<tr><td>Recebido em</td><td>'  . date('d/m/Y H:i') . '</td></tr>';
    $corpo = '<h2>Nova Proposta de Atividade</h2>'
           . '<table class="info">' . $rows . '</table>';
    return pl_email_wrap('Nova Proposta — ' . $site_name, $corpo, $site_name);
}


$p = $_GET['p'] ?? '';
$id = $_GET['id'] ?? 0;

// Grid público
if ($p === 'gridview' && $id) {
    $grid = $db->prepare("SELECT * FROM grids WHERE id=?");
    $grid->execute(array($id));
    $g = $grid->fetch();
    
    if (!$g) die('Grade não encontrada');
    
    $event_ids = $g['selected_events'] ? explode(',', $g['selected_events']) : array();
    $events = array();
    if ($event_ids) {
        $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
        $stmt = $db->prepare("SELECT * FROM events WHERE id IN ($placeholders) ORDER BY start_time");
        $stmt->execute($event_ids);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Pré-carregar tipos de atividade de todos os eventos de uma vez
    $activity_types_map = array();
    if ($g['show_types'] && $event_ids) {
        $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
        $stmt_types = $db->prepare("
            SELECT em.event_id, at.name 
            FROM event_meta em
            JOIN activity_types at ON at.id = em.meta_value
            WHERE em.event_id IN ($placeholders) AND em.meta_key = 'activity_type'
        ");
        $stmt_types->execute($event_ids);
        foreach ($stmt_types->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $activity_types_map[$row['event_id']] = $row['name'];
        }
    }
    
    $base_url = get_base_url();
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($g['title']) ?></title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#f0f0f0;padding:30px 20px;font-size:14px;color:#222}
    h1{font-size:20px;font-weight:600;color:#111;margin-bottom:20px;letter-spacing:-.2px}
    table{width:100%;background:#fff;border-collapse:collapse;border:1px solid #e0e0e0}
    th,td{padding:11px 14px;text-align:left;border-bottom:1px solid #ebebeb;font-size:13px}
    th{background:#2c2c2c;font-weight:600;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
    tr:last-child td{border-bottom:none}
    tr:hover td{background:#fafafa}
    .tipo-badge{display:inline-block;padding:2px 8px;background:#f0f0f0;color:#444;border:1px solid #ddd;font-size:11px;font-weight:600;letter-spacing:.2px}
    .foto-evento{width:56px;height:56px;object-fit:cover;border:1px solid #ddd}
    .foto-palestrante{width:48px;height:48px;object-fit:cover;border-radius:50%;border:1px solid #ddd}
    small{color:#888}
    </style>
    </head><body>
    <h1><?= h($g['title']) ?></h1>
    <table>
        <tr>
            <th>Atividade</th>
            <?php if ($g['show_types']): ?><th>Tipo</th><?php endif; ?>
            <?php if ($g['show_times']): ?><th>Horário</th><?php endif; ?>
            <?php if ($g['show_speakers']): ?><th>Palestrante</th><?php endif; ?>
            <?php if ($g['show_photos']): ?><th>Foto</th><?php endif; ?>
            <th>Local</th>
        </tr>
        <?php foreach ($events as $e): ?>
        <tr>
            <td><strong><?= h($e['title']) ?></strong><br><small style="color:#6b7280"><?= h($e['summary']) ?></small></td>
            <?php if ($g['show_types']): ?>
                <td><?php if (isset($activity_types_map[$e['id']])): ?><span class="tipo-badge"><?= h($activity_types_map[$e['id']]) ?></span><?php else: ?>—<?php endif; ?></td>
            <?php endif; ?>
            <?php if ($g['show_times']): ?>
                <td><?= h($e['start_time']) ?><?= $e['end_time'] ? ' — ' . h($e['end_time']) : '' ?></td>
            <?php endif; ?>
            <?php if ($g['show_speakers']): ?>
                <td><?= h($e['speaker_name']) ?><?= $e['speaker_email'] ? '<br><small style="color:#6b7280">'.h($e['speaker_email']).'</small>' : '' ?></td>
            <?php endif; ?>
            <?php if ($g['show_photos']): ?>
                <td>
                    <?php
                    $foto_src = '';
                    if (!empty($e['speaker_photo']) && file_exists(__DIR__ . '/' . $e['speaker_photo'])) {
                        $foto_src = $base_url . '/' . $e['speaker_photo'];
                        $foto_class = 'foto-palestrante';
                    } elseif (!empty($e['photo']) && file_exists(__DIR__ . '/' . $e['photo'])) {
                        $foto_src = $base_url . '/' . $e['photo'];
                        $foto_class = 'foto-evento';
                    }
                    ?>
                    <?php if ($foto_src): ?>
                        <img src="<?= h($foto_src) ?>" class="<?= $foto_class ?>" alt="Foto">
                    <?php else: ?>
                        <span style="color:#9ca3af">—</span>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
            <td><?= h($e['location']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </body></html>
    <?php
    exit;
}

// Inscrição pública por evento
if ($p === 'inscricao' && $id) {
    $event = $db->prepare("SELECT * FROM events WHERE id=?");
    $event->execute(array($id));
    $ev = $event->fetch();
    
    if (!$ev) die('Evento não encontrado');
    
    if ($_POST) {
        $qr = bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO participants (name,email,document,phone,organization,status,qr_key) VALUES (?,?,?,?,?,?,?)")
            ->execute(array($_POST['name'], $_POST['email'], $_POST['document'] ?? '', $_POST['phone'] ?? '', $_POST['organization'] ?? '', 'pending', $qr));

        // Email para o participante com QR Code embutido
        $s_cfg = pl_get_settings();
        $nome  = $_POST['name'];
        $email = $_POST['email'];
        $doc   = $_POST['document']    ?? '';
        $org   = $_POST['organization'] ?? '';
        $phone = $_POST['phone']        ?? '';
        $ev_nome = $ev['title'];
        $msg_part = pl_email_participante($nome, $ev_nome, $doc, $org, $phone, $qr, $s_cfg['site_name']);
        pl_send_email($email, 'Confirmação de Inscrição — ' . $s_cfg['site_name'], $msg_part);

        // Notificar administrador
        $msg_adm = pl_email_admin_participante($nome, $email, $ev_nome, $doc, $org, $phone, $s_cfg['site_name']);
        pl_notify_admin('Nova Inscrição: ' . $nome, $msg_adm);

        $success = true;
    }
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inscrição - <?= h($ev['title']) ?></title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#f0f0f0;min-height:100vh;padding:40px 20px;font-size:14px;color:#222}
    .box{max-width:560px;margin:0 auto;background:#fff;padding:36px 40px;border:1px solid #ddd}
    h1{font-size:18px;font-weight:600;color:#111;margin-bottom:6px}
    .info{color:#666;font-size:13px;margin-bottom:24px;padding-bottom:18px;border-bottom:1px solid #eee}
    label{display:block;font-size:12px;font-weight:600;color:#555;margin-top:14px;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px}
    input{width:100%;padding:8px 10px;border:1px solid #d0d0d0;border-radius:3px;font-size:14px;font-family:inherit}
    input:focus{outline:none;border-color:#555}
    button{width:100%;padding:10px;background:#2c2c2c;color:#fff;border:none;font-size:14px;font-weight:500;cursor:pointer;margin-top:22px;border-radius:3px}
    button:hover{background:#111}
    .success{background:#edfaf3;color:#1a5c38;padding:16px;border-left:3px solid #27ae60;font-size:14px}
    </style>
    </head><body>
    <div class="box">
        <h1><?= h($ev['title']) ?></h1>
        <div class="info">
            <?php if ($ev['location']): ?><strong>Local:</strong> <?= h($ev['location']) ?><?php endif; ?>
            <?php if ($ev['start_time']): ?>   <strong>Horário:</strong> <?= h($ev['start_time']) ?><?= $ev['end_time'] ? ' – '.h($ev['end_time']) : '' ?><?php endif; ?>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success">Inscrição realizada com sucesso! Verifique seu email.</div>
        <?php else: ?>
            <form method="post">
                <label>Nome Completo *</label>
                <input name="name" required>
                <label>Email *</label>
                <input type="email" name="email" required>
                <label>CPF / Documento</label>
                <input name="document">
                <label>Telefone</label>
                <input name="phone">
                <label>Organização</label>
                <input name="organization">
                <button>Inscrever-se</button>
            </form>
        <?php endif; ?>
    </div>
    </body></html>
    <?php
    exit;
}

// Formulário público para palestrante propor evento
if ($p === 'submit_event') {
    if ($_POST && isset($_POST['title'])) {
        // Upload foto — lógica robusta com diagnóstico
        $photo = '';
        $upload_error = '';

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                $upload_error = 'Erro no upload da foto (código ' . $_FILES['photo']['error'] . ').';
            } else {
                $allowed_ext = array('jpg', 'jpeg', 'png', 'gif', 'webp');
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed_ext)) {
                    $upload_error = 'Tipo não permitido. Use JPG, PNG, GIF ou WEBP.';
                } else {
                    $upload_dir = ensure_uploads_dir();
                    if (!$upload_dir) {
                        $upload_error = 'A pasta uploads/ não tem permissão de escrita. Crie-a via FTP com permissão 777.';
                    } else {
                        $filename = 'event_' . time() . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $filename)) {
                            $photo = 'uploads/' . $filename;
                        } else {
                            $upload_error = 'Falha ao mover o arquivo enviado.';
                        }
                    }
                }
            }
        }
        
        // Criar evento como "pendente de aprovação" (event_order = -1)
        $db->prepare("INSERT INTO events (title,summary,photo,speaker_name,speaker_email,speaker_bio,event_order,is_special) VALUES (?,?,?,?,?,?,-1,0)")
            ->execute(array(
                $_POST['title'],
                $_POST['summary'],
                $photo,
                $_POST['speaker_name'],
                $_POST['speaker_email'],
                $_POST['speaker_bio'] ?? ''
            ));
        
        $event_id = $db->lastInsertId();
        
        // Salvar tipo de atividade
        if (!empty($_POST['activity_type'])) {
            $db->prepare("INSERT INTO event_meta (event_id, meta_key, meta_value) VALUES (?,?,?)")
                ->execute(array($event_id, 'activity_type', $_POST['activity_type']));
        }
        
        // Salvar campos customizados
        if (isset($_POST['custom']) && is_array($_POST['custom'])) {
            foreach ($_POST['custom'] as $key => $value) {
                $db->prepare("INSERT INTO event_meta (event_id, meta_key, meta_value) VALUES (?,?,?)")
                    ->execute(array($event_id, $key, $value));
            }
        }
        
        $success = true;

        // Buscar nome do tipo de atividade
        $tipo_nome_pl = '';
        if (!empty($_POST['activity_type'])) {
            $tr_pl = $db->prepare("SELECT name FROM activity_types WHERE id=?");
            $tr_pl->execute(array((int)$_POST['activity_type']));
            $tr_pl_row = $tr_pl->fetch();
            $tipo_nome_pl = $tr_pl_row ? $tr_pl_row['name'] : '';
        }

        // Email para o palestrante confirmando recebimento da proposta
        if (!empty($_POST['speaker_email'])) {
            $s_cfg = pl_get_settings();
            $msg_pal = pl_email_palestrante(
                $_POST['speaker_name'], $_POST['title'], '', '', $s_cfg['site_name'],
                $_POST['summary'] ?? '', $_POST['speaker_bio'] ?? '', $tipo_nome_pl, $_POST['speaker_email']
            );
            pl_send_email($_POST['speaker_email'], 'Proposta Recebida: ' . $_POST['title'], $msg_pal);
        }

        // Notificar administrador sobre nova proposta
        $s_cfg = pl_get_settings();
        $msg_adm = pl_email_admin_atividade(
            $_POST['title'], $_POST['speaker_name'] ?? '', $_POST['speaker_email'] ?? '',
            $_POST['summary'] ?? '', $s_cfg['site_name'],
            $_POST['speaker_bio'] ?? '', $tipo_nome_pl
        );
        pl_notify_admin('Nova Proposta de Atividade: ' . $_POST['title'], $msg_adm);
    }
    $tipos  = $db->query("SELECT * FROM activity_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $campos = $db->query("SELECT * FROM custom_fields WHERE context='event' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Propor Palestra</title>
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;background:#f0f0f0;min-height:100vh;padding:40px 20px;font-size:14px;color:#222}
    .box{max-width:640px;margin:0 auto;background:#fff;padding:36px 40px;border:1px solid #ddd}
    h1{font-size:18px;font-weight:600;color:#111;margin-bottom:4px}
    .sub{color:#888;font-size:13px;margin-bottom:26px;padding-bottom:18px;border-bottom:1px solid #eee}
    h3{font-size:13px;font-weight:600;color:#333;text-transform:uppercase;letter-spacing:.4px;margin-top:26px;margin-bottom:2px;padding-top:18px;border-top:1px solid #eee}
    label{display:block;font-size:12px;font-weight:600;color:#555;margin-top:14px;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px}
    input,textarea,select{width:100%;padding:8px 10px;border:1px solid #d0d0d0;border-radius:3px;font-size:14px;font-family:inherit}
    input[type="file"]{border:none;padding:4px 0;font-size:13px}
    input:focus,textarea:focus,select:focus{outline:none;border-color:#555}
    button{width:100%;padding:10px;background:#2c2c2c;color:#fff;border:none;font-size:14px;font-weight:500;cursor:pointer;margin-top:24px;border-radius:3px}
    button:hover{background:#111}
    .success{background:#edfaf3;color:#1a5c38;padding:16px;border-left:3px solid #27ae60;font-size:14px;margin-bottom:16px}
    .warn{background:#fef9e7;color:#7d6608;padding:12px;border-left:3px solid #f9e79f;font-size:13px;margin-top:10px}
    label.check{font-weight:normal;text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:8px;font-size:14px;color:#333}
    label.check input{width:auto}
    </style>
    </head><body>
    <div class="box">
        <h1>Propor Palestra / Workshop</h1>
        <div class="sub">Envie sua proposta. A equipe irá revisar e definir horário e local.</div>
        
        <?php if (isset($success)): ?>
            <div class="success">
                Proposta enviada com sucesso! Aguarde a aprovação da equipe.
                <?php if ($upload_error): ?>
                    <div class="warn">A proposta foi salva, mas a foto não foi enviada: <?= h($upload_error) ?></div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data">
                <label>Título da Atividade *</label>
                <input name="title" required>
                
                <label>Tipo de Atividade *</label>
                <select name="activity_type" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($tipos as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                
                <label>Foto do Palestrante *</label>
                <input type="file" name="photo" accept="image/*" required>
                
                <label>Resumo / Descrição *</label>
                <textarea name="summary" rows="5" required></textarea>
                
                <?php if (!empty($campos)): ?>
                    <h3>Informações Adicionais</h3>
                    <?php foreach ($campos as $campo): ?>
                        <label><?= htmlspecialchars($campo['field_label']) ?><?= $campo['field_required']?' *':'' ?></label>
                        <?php if ($campo['field_type'] === 'textarea'): ?>
                            <textarea name="custom[<?= htmlspecialchars($campo['field_key']) ?>]" rows="3" <?= $campo['field_required']?'required':'' ?>></textarea>
                        <?php elseif ($campo['field_type'] === 'select'): ?>
                            <select name="custom[<?= htmlspecialchars($campo['field_key']) ?>]" <?= $campo['field_required']?'required':'' ?>>
                                <option value="">Selecione...</option>
                                <?php foreach (explode(',', $campo['field_options']) as $opt): ?>
                                    <option value="<?= htmlspecialchars(trim($opt)) ?>"><?= htmlspecialchars(trim($opt)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($campo['field_type'] === 'checkbox'): ?>
                            <label class="check"><input type="checkbox" name="custom[<?= htmlspecialchars($campo['field_key']) ?>]" value="1"> <?= htmlspecialchars($campo['field_label']) ?></label>
                        <?php else: ?>
                            <input name="custom[<?= htmlspecialchars($campo['field_key']) ?>]" <?= $campo['field_required']?'required':'' ?>>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <h3>Sobre o Palestrante</h3>
                
                <label>Seu Nome *</label>
                <input name="speaker_name" required>
                
                <label>Seu Email *</label>
                <input type="email" name="speaker_email" required>
                
                <label>Bio / Currículo (opcional)</label>
                <textarea name="speaker_bio" rows="3"></textarea>
                
                <button>Enviar Proposta</button>
            </form>
        <?php endif; ?>
    </div>
    </body></html>
    <?php
    exit;
}

// ==================== BUSCA PÚBLICA DE CERTIFICADOS ====================
if ($p === 'cert_search') {
    $settings = pl_get_settings();
    $site_name = $settings['site_name'] ?? 'Bienenstock';

    // AJAX: buscar certificados pelo nome ou título de atividade
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
        header('Content-Type: application/json; charset=utf-8');
        $q_raw = trim($_GET['q'] ?? '');
        if (strlen($q_raw) < 2) { echo json_encode([]); exit; }
        $q = '%' . $q_raw . '%';
        try {
            // Buscar por nome do participante
            $r = $db->prepare(
                "SELECT id, ref_name, ref_type, ref_id, file_path, created_at
                 FROM cert_files WHERE ref_name LIKE ?
                 ORDER BY created_at DESC LIMIT 50"
            );
            $r->execute([$q]);
            $rows = $r->fetchAll(PDO::FETCH_ASSOC);
            // Adicionar event_title para palestrantes
            foreach ($rows as &$row) {
                $row['event_title'] = '';
                if ($row['ref_type'] === 'speaker' && $row['ref_id']) {
                    $ev = $db->prepare("SELECT title FROM events WHERE id=?");
                    $ev->execute([$row['ref_id']]);
                    $row['event_title'] = $ev->fetchColumn() ?: '';
                }
            }
            unset($row);
            // Se não achou por nome, tentar por título de atividade
            if (empty($rows)) {
                $r2 = $db->prepare(
                    "SELECT cf.id, cf.ref_name, cf.ref_type, cf.ref_id, cf.file_path, cf.created_at,
                            e.title AS event_title
                     FROM cert_files cf JOIN events e ON cf.ref_id=e.id AND cf.ref_type='speaker'
                     WHERE e.title LIKE ? ORDER BY cf.created_at DESC LIMIT 50"
                );
                $r2->execute([$q]);
                $rows = $r2->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(array_values($rows));
        } catch (Exception $e) {
            echo json_encode([]);
        }
        exit;
    }

    // Download de certificado
    if (isset($_GET['dl']) && (int)$_GET['dl'] > 0) {
        $cf_id = (int)$_GET['dl'];
        try {
            $cf = $db->prepare("SELECT * FROM cert_files WHERE id=?");
            $cf->execute([$cf_id]);
            $cf_row = $cf->fetch(PDO::FETCH_ASSOC);
            if ($cf_row && file_exists(BASE . '/' . $cf_row['file_path'])) {
                $ext  = strtolower(pathinfo($cf_row['file_path'], PATHINFO_EXTENSION));
                $mime = ($ext === 'pdf') ? 'application/pdf' : 'text/html';
                $fname = 'certificado_' . preg_replace('/[^a-z0-9._-]+/','',
                             mb_strtolower($cf_row['ref_name'])) . '.' . $ext;
                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . $fname . '"');
                header('Content-Length: ' . filesize(BASE . '/' . $cf_row['file_path']));
                readfile(BASE . '/' . $cf_row['file_path']);
                exit;
            }
        } catch (Exception $e) {}
        // Certificado não encontrado — redirecionar com mensagem
        header('Location: ?p=cert_search&err=1');
        exit;
    }

    // ── HTML da página pública ────────────────────────────────────────────────
    $has_error = isset($_GET['err']);
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Certificados — <?= h($site_name) ?></title>
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f5f7;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:40px 20px}
    .wrap{width:100%;max-width:620px}
    .logo-area{text-align:center;margin-bottom:32px}
    .logo-area h1{font-size:22px;font-weight:700;color:#1e293b;margin-bottom:4px}
    .logo-area p{font-size:14px;color:#64748b}
    .card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:32px}
    .card h2{font-size:18px;font-weight:600;color:#1e293b;margin-bottom:8px}
    .card p{font-size:14px;color:#64748b;margin-bottom:20px;line-height:1.5}
    .search-row{display:flex;gap:8px}
    .search-row input{flex:1;padding:11px 14px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;transition:border .15s}
    .search-row input:focus{outline:none;border-color:#4f46e5}
    .search-row button{padding:11px 20px;background:#4f46e5;color:#fff;border:none;border-radius:7px;font-size:15px;font-weight:600;cursor:pointer;white-space:nowrap}
    .search-row button:hover{background:#4338ca}
    #results{margin-top:20px}
    .result-item{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;gap:12px;background:#fafafa}
    .result-item:hover{border-color:#4f46e5;background:#f5f3ff}
    .result-info{flex:1;min-width:0}
    .result-name{font-size:15px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .result-meta{font-size:12px;color:#6b7280;margin-top:2px}
    .result-badge{font-size:11px;font-weight:600;padding:2px 8px;border-radius:12px;white-space:nowrap}
    .badge-speaker{background:#dbeafe;color:#1d4ed8}
    .badge-participant{background:#dcfce7;color:#15803d}
    .btn-dl{padding:8px 16px;background:#4f46e5;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap}
    .btn-dl:hover{background:#4338ca}
    .msg{padding:14px 16px;border-radius:8px;font-size:14px;margin-bottom:16px}
    .msg-err{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626}
    .msg-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;text-align:center}
    .hint{font-size:13px;color:#9ca3af;text-align:center;margin-top:12px}
    .spinner{display:inline-block;width:18px;height:18px;border:2.5px solid #e5e7eb;border-top-color:#4f46e5;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px}
    @keyframes spin{to{transform:rotate(360deg)}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo-area">
        <h1><?= h($site_name) ?></h1>
        <p>Busca e download de certificados</p>
    </div>
    <div class="card">
        <h2>🎓 Seu Certificado</h2>
        <p>Digite seu nome (ou o título da atividade) para encontrar e baixar o seu certificado.</p>
        <?php if ($has_error): ?>
            <div class="msg msg-err">Certificado não encontrado ou arquivo removido. Verifique se o certificado já foi gerado.</div>
        <?php endif; ?>
        <div class="search-row">
            <input type="text" id="q" placeholder="Digite seu nome..." autocomplete="off"
                autofocus oninput="onInput()" onkeydown="if(event.key==='Enter')doSearch()">
            <button onclick="doSearch()">Buscar</button>
        </div>
        <div id="results"></div>
    </div>
</div>
<script>
const BASE_URL = '<?= h(get_base_url()) ?>/public-links.php?p=cert_search';
let searchTimer;

function onInput() {
    clearTimeout(searchTimer);
    const q = document.getElementById('q').value.trim();
    if (q.length < 2) {
        document.getElementById('results').innerHTML = '';
        return;
    }
    searchTimer = setTimeout(doSearch, 400);
}

function doSearch() {
    const q   = document.getElementById('q').value.trim();
    const res = document.getElementById('results');
    if (q.length < 2) {
        res.innerHTML = '<p class="hint">Digite pelo menos 2 caracteres.</p>';
        return;
    }
    res.innerHTML = '<p class="hint"><span class="spinner"></span>Buscando...</p>';
    fetch(BASE_URL + '&ajax=search&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data) || !data.length) {
                res.innerHTML = '<div class="msg msg-info">Nenhum certificado encontrado para <strong>"' + esc(q) + '"</strong>.<br>Verifique se o certificado já foi gerado pelo organizador.</div>';
                return;
            }
            let h = '';
            data.forEach(r => {
                const badge = r.ref_type === 'speaker'
                    ? '<span class="result-badge badge-speaker">Palestrante</span>'
                    : '<span class="result-badge badge-participant">Participante</span>';
                const ev = r.event_title ? '<br>' + esc(r.event_title) : '';
                const dt = (r.created_at||'').substring(0,10);
                h += '<div class="result-item">'
                   + '<div class="result-info">'
                   + '<div class="result-name">' + esc(r.ref_name||'') + '</div>'
                   + '<div class="result-meta">' + badge + ev + (dt ? ' · ' + dt : '') + '</div>'
                   + '</div>'
                   + '<a class="btn-dl" href="' + BASE_URL + '&dl=' + encodeURIComponent(r.id) + '">⬇ Baixar PDF</a>'
                   + '</div>';
            });
            res.innerHTML = h;
        })
        .catch(() => {
            res.innerHTML = '<p class="hint" style="color:#dc2626">Erro ao buscar. Tente novamente.</p>';
        });
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body></html>
    <?php
    exit;
}

echo 'Página não encontrada';
