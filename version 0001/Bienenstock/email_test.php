<?php
/**
 * BIENENSTOCK - Diagnóstico e Teste de Email
 * Acesse via browser. REMOVA após o diagnóstico.
 */
define('BASE', __DIR__);
require BASE . '/config.php';
try {
    $db = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { die('Erro DB: ' . $e->getMessage()); }
require_once BASE . '/smtp_mailer.php';

$rows       = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$from_email = $rows['from_email'] ?? '';
$smtp_host  = $rows['smtp_host']  ?? '';
$smtp_port  = $rows['smtp_port']  ?? '587';
$smtp_user  = $rows['smtp_user']  ?? '';
$smtp_pass  = $rows['smtp_pass']  ?? '';
$smtp_enc   = $rows['smtp_enc']   ?? 'auto';
$site_name  = $rows['site_name']  ?? 'Bienenstock';
$au         = $db->query("SELECT email FROM users ORDER BY id LIMIT 1")->fetch();

$result = null;
if ($_POST) {
    $to     = trim($_POST['to'] ?? '');
    $method = $_POST['method'] ?? 'smtp';
    $subj   = 'Bienenstock — Teste SMTP ' . date('d/m H:i:s');
    $body   = '<html><body style="font-family:Arial;padding:20px;background:#f0f0f0">'
            . '<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #ddd;padding:24px">'
            . '<h2 style="color:#111;font-size:15px;margin:0 0 12px">Teste de Email — Bienenstock</h2>'
            . '<p style="font-size:13px;color:#444">Email enviado com sucesso via <strong>' . htmlspecialchars($method) . '</strong>.</p>'
            . '<p style="font-size:13px;color:#444">Data/hora: <strong>' . date('d/m/Y H:i:s') . '</strong></p>'
            . '<p style="font-size:12px;color:#aaa;margin-top:16px">Se você recebeu este email, o envio SMTP está funcionando corretamente.</p>'
            . '</div></body></html>';

    if ($method === 'smtp') {
        $m             = new SmtpMailer();
        $m->host       = trim($_POST['smtp_host'] ?? $smtp_host);
        $m->port       = (int)(trim($_POST['smtp_port'] ?? $smtp_port ?: 587));
        $m->username   = trim($_POST['smtp_user'] ?? $smtp_user);
        $m->password   = trim($_POST['smtp_pass'] ?? $smtp_pass);
        $m->encryption = trim($_POST['smtp_enc']  ?? $smtp_enc  ?: 'auto');
        $m->from       = $m->username ?: $from_email;
        $m->from_name  = $site_name;
        $ok  = $m->send_mail($to, $subj, $body);
        $result = ['ok'=>$ok, 'log'=>$m->get_log(), 'err'=>$m->get_last_error(),
            'msg' => $ok ? "✅ SMTP aceito para {$to}" : "❌ Falha SMTP — " . $m->get_last_error()];
    } else {
        $f   = $from_email ?: 'noreply@' . $_SERVER['HTTP_HOST'];
        $hdr = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$site_name} <{$f}>\r\n";
        $ok  = @mail($to, $subj, $body, $hdr);
        $result = ['ok'=>$ok, 'log'=>[], 'err'=>'',
            'msg' => $ok ? "✅ mail() aceito (pode cair em spam se sem SMTP)" : "❌ mail() falhou"];
    }
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Diagnóstico Email — Bienenstock</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:#f0f0f0;padding:24px;font-size:13px;color:#222}
.box{background:#fff;border:1px solid #ddd;padding:20px 24px;margin-bottom:16px;max-width:680px}
h1{font-size:15px;font-weight:700;margin-bottom:18px;color:#111}
h2{font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid #eee;color:#555}
table{width:100%;border-collapse:collapse}
td{padding:6px 10px;border:1px solid #e0e0e0;vertical-align:top;line-height:1.4}
td:first-child{background:#f7f7f7;font-weight:600;width:38%;white-space:nowrap}
.ok{background:#edfaf3;color:#1a5c38;padding:10px 14px;margin-top:10px;font-size:13px;border-left:3px solid #2ecc71}
.err{background:#fdf0ef;color:#7b2020;padding:10px 14px;margin-top:10px;font-size:13px;border-left:3px solid #e74c3c}
.warn{background:#fffbea;color:#7d5000;padding:10px 14px;margin-top:10px;font-size:13px;border-left:3px solid #f39c12}
label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#555;margin:12px 0 3px}
input,select{width:100%;padding:7px 9px;border:1px solid #d0d0d0;font-size:13px;font-family:Arial}
.row2{display:grid;grid-template-columns:3fr 1fr;gap:10px}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
button{background:#2c2c2c;color:#fff;border:none;padding:9px 22px;font-size:13px;cursor:pointer;margin-top:14px;font-weight:600;letter-spacing:.3px}
button:hover{background:#111}
.log{background:#1a1a2e;color:#a0d4b5;padding:12px;font-family:monospace;font-size:11px;max-height:180px;overflow:auto;margin-top:10px;line-height:1.5;white-space:pre}
.presets a{color:#333;margin-right:10px;font-size:12px}
code{background:#f0f0f0;padding:1px 5px;font-family:monospace;font-size:12px}
</style></head><body>

<h1>Diagnóstico de Email — Bienenstock</h1>

<div class="box">
<h2>Configuração atual no banco</h2>
<table>
<tr><td>from_email (remetente/admin)</td><td><?= $h($from_email ?: '⚠️ não configurado') ?></td></tr>
<tr><td>smtp_host</td><td><?= $h($smtp_host ?: '⚠️ não configurado') ?></td></tr>
<tr><td>smtp_port</td><td><?= $h($smtp_port ?: '587 (padrão)') ?></td></tr>
<tr><td>smtp_enc</td><td><?= $h($smtp_enc  ?: 'auto') ?></td></tr>
<tr><td>smtp_user</td><td><?= $h($smtp_user ?: '⚠️ não configurado') ?></td></tr>
<tr><td>smtp_pass</td><td><?= $smtp_pass ? '●●●● ('.strlen($smtp_pass).' chars — ' . (strlen(str_replace(' ','',$smtp_pass)) < strlen($smtp_pass) ? '⚠️ contém espaços, serão removidos' : 'sem espaços ✓') . ')' : '⚠️ não configurado' ?></td></tr>
</table>
<?php if (!$smtp_host || !$smtp_user || !$smtp_pass): ?>
<div class="warn">⚠️ SMTP incompleto. Vá em <strong>Configurações</strong> no painel e preencha os campos SMTP.</div>
<?php else: ?>
<div class="ok">✅ SMTP configurado: <?= $h($smtp_host) ?>:<?= $h($smtp_port) ?> [<?= $h($smtp_enc) ?>] — <?= $h($smtp_user) ?></div>
<?php endif; ?>
</div>

<?php if ($result): ?>
<div class="box">
<h2>Resultado do teste</h2>
<div class="<?= $result['ok'] ? 'ok' : 'err' ?>"><?= $h($result['msg']) ?></div>
<?php if (!empty($result['log'])): ?>
<div class="log"><?= $h(implode("\n", $result['log'])) ?></div>
<?php endif; ?>
</div>
<?php endif; ?>

<div class="box">
<h2>Enviar teste</h2>
<form method="post">

<div style="display:flex;gap:20px;margin-bottom:4px">
<label style="margin:0;display:flex;align-items:center;gap:6px;font-size:13px;text-transform:none;letter-spacing:0;font-weight:400;cursor:pointer">
<input type="radio" name="method" value="smtp" <?= (!isset($_POST['method'])||$_POST['method']==='smtp')?'checked':'' ?>> Via SMTP <span style="color:#888;font-size:11px">(recomendado)</span>
</label>
<label style="margin:0;display:flex;align-items:center;gap:6px;font-size:13px;text-transform:none;letter-spacing:0;font-weight:400;cursor:pointer">
<input type="radio" name="method" value="mail" <?= (isset($_POST['method'])&&$_POST['method']==='mail')?'checked':'' ?>> Via mail() nativo <span style="color:#888;font-size:11px">(costuma cair em spam)</span>
</label>
</div>

<label>Enviar para</label>
<input type="email" name="to" value="<?= $h($from_email) ?>" required>

<h2 style="margin-top:16px">Dados SMTP para este teste</h2>

<p class="presets" style="margin-bottom:8px">Presets:
<a href="#" onclick="sp('smtp.gmail.com','587','starttls');return false">Gmail</a>
<a href="#" onclick="sp('smtp.office365.com','587','starttls');return false">Outlook</a>
<a href="#" onclick="sp('smtp.zoho.com','587','starttls');return false">Zoho</a>
<a href="#" onclick="sp('smtp.titan.email','587','starttls');return false">Titan</a>
<a href="#" onclick="sp('smtp.mailgun.org','587','starttls');return false">Mailgun</a>
<a href="#" onclick="sp('smtp-relay.brevo.com','587','starttls');return false">Brevo</a>
<a href="#" onclick="sp('smtp.sendgrid.net','587','starttls');return false">SendGrid</a>
<a href="#" onclick="sp('smtp.locaweb.com.br','587','starttls');return false">Locaweb</a>
<a href="#" onclick="sp('smtp.uol.com.br','587','starttls');return false">UOL</a>
<a href="#" onclick="sp('sandbox.smtp.mailtrap.io','2525','none');return false">Mailtrap</a>
</p>

<div class="row2">
<div><label>Servidor SMTP</label><input type="text" id="sh" name="smtp_host" value="<?= $h($_POST['smtp_host']??$smtp_host) ?>" placeholder="smtp.gmail.com"></div>
<div><label>Porta</label><input type="number" id="sp2" name="smtp_port" value="<?= $h($_POST['smtp_port']??$smtp_port?:'587') ?>"></div>
</div>
<div class="row2">
<div><label>Usuário (email)</label><input type="email" name="smtp_user" value="<?= $h($_POST['smtp_user']??$smtp_user) ?>" placeholder="email@dominio.com"></div>
<div>
<label>Criptografia</label>
<select id="se" name="smtp_enc">
<?php
$sel = $_POST['smtp_enc'] ?? $smtp_enc ?? 'auto';
$opts = ['auto'=>'Auto','starttls'=>'STARTTLS (587)','ssl'=>'SSL (465)','none'=>'Nenhuma (25/2525)'];
foreach ($opts as $v=>$l) echo "<option value='$v'" . ($sel===$v?' selected':'') . ">$l</option>";
?>
</select>
</div>
</div>
<label>Senha SMTP</label>
<input type="password" name="smtp_pass" value="<?= $h($_POST['smtp_pass']??$smtp_pass) ?>" placeholder="Senha / Senha de App">
<p style="font-size:11px;color:#999;margin:4px 0 0">Gmail: use Senha de App (16 chars). O sistema remove espaços automaticamente.</p>
<button>Enviar Teste</button>
</form>
<script>
function sp(h,p,e){
  document.getElementById('sh').value=h;
  document.getElementById('sp2').value=p;
  document.getElementById('se').value=e;
}
</script>
</div>

<div class="box">
<h2>Referência rápida de provedores</h2>
<table>
<tr><td>Gmail</td><td>smtp.gmail.com · 587 · STARTTLS · Requer Senha de App (2FA obrigatório)</td></tr>
<tr><td>Outlook / Office 365</td><td>smtp.office365.com · 587 · STARTTLS · Senha da conta Microsoft</td></tr>
<tr><td>Hotmail / Live</td><td>smtp-mail.outlook.com · 587 · STARTTLS</td></tr>
<tr><td>Zoho Mail</td><td>smtp.zoho.com · 587 · STARTTLS — ou 465 · SSL</td></tr>
<tr><td>Titan Mail</td><td>smtp.titan.email · 587 · STARTTLS</td></tr>
<tr><td>Mailgun</td><td>smtp.mailgun.org · 587 · STARTTLS · usuário + senha do painel</td></tr>
<tr><td>Brevo (Sendinblue)</td><td>smtp-relay.brevo.com · 587 · STARTTLS · login + chave API</td></tr>
<tr><td>SendGrid</td><td>smtp.sendgrid.net · 587 · STARTTLS · usuário: <code>apikey</code> + API Key</td></tr>
<tr><td>Amazon SES</td><td>email-smtp.us-east-1.amazonaws.com · 587 · STARTTLS</td></tr>
<tr><td>Locaweb</td><td>smtp.locaweb.com.br · 587 · STARTTLS — ou 465 · SSL</td></tr>
<tr><td>UOL Host</td><td>smtp.uol.com.br · 587 · STARTTLS</td></tr>
<tr><td>Mailtrap (testes)</td><td>sandbox.smtp.mailtrap.io · 2525 · Nenhuma · Não chega ao destinatário real</td></tr>
</table>
</div>

<p style="max-width:680px;font-size:11px;color:#aaa;text-align:center;margin-top:4px">
⚠️ Remova <strong>email_test.php</strong> após o diagnóstico — ele expõe configurações SMTP.
</p>
</body></html>
