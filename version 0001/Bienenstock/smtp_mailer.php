<?php
/**
 * BIENENSTOCK - SMTP Mailer
 * Cliente SMTP nativo em PHP puro — sem dependências externas.
 * Suporta: STARTTLS (587/2525), SSL direto (465), sem criptografia (25).
 * Autenticação: LOGIN e PLAIN. Suporte a anexos (PDF, imagens, etc).
 * Compatível com Gmail, Outlook, Zoho, Titan, Mailgun, SendGrid, Brevo,
 *               Mailtrap, OVH, Locaweb, UOL Host, etc.
 *
 * Desenvolvedor: Carlos Eduardo (cadunico) — GPL v3
 */
class SmtpMailer {

    public $host       = '';
    public $port       = 587;
    public $username   = '';
    public $password   = '';
    public $from       = '';
    public $from_name  = '';
    public $timeout    = 30;
    public $encryption = 'auto'; // auto|starttls|ssl|none

    // Anexos: array de ['name'=>'cert.pdf','mime'=>'application/pdf','data'=><bytes>]
    public $attachments = [];

    private $conn       = null;
    private $log        = [];
    private $last_error = '';

    // ── Adicionar anexo a partir de arquivo no disco ──────────────────────────
    public function attach_file($filepath, $filename = null) {
        if (!file_exists($filepath)) return false;
        $data = file_get_contents($filepath);
        if ($data === false) return false;
        $mime = @mime_content_type($filepath) ?: 'application/octet-stream';
        $this->attachments[] = [
            'name' => $filename ?: basename($filepath),
            'mime' => $mime,
            'data' => $data,
        ];
        return true;
    }

    // ── Adicionar anexo a partir de bytes em memória ──────────────────────────
    public function attach_data($data, $filename, $mime = 'application/octet-stream') {
        $this->attachments[] = ['name' => $filename, 'mime' => $mime, 'data' => $data];
    }

    // ── Envio principal ───────────────────────────────────────────────────────
    public function send_mail($to, $subject, $html_body) {
        $to = trim($to ?? '');
        if (!$to || !$this->host || !$this->username) {
            $this->last_error = 'Configuração incompleta (host/usuário/senha ausentes)';
            error_log('[Bienenstock SMTP] ' . $this->last_error);
            return false;
        }
        $this->password = str_replace(' ', '', $this->password);

        try {
            $this->do_connect();
            $this->do_auth();

            $this->scmd("MAIL FROM:<{$this->from}>", '250');
            foreach (explode(',', $to) as $r) {
                $r = trim($r);
                if ($r) $this->scmd("RCPT TO:<{$r}>", '25'); // aceita 250 e 251
            }
            $this->scmd("DATA", '354');

            $msg_id   = '<' . time() . '.' . mt_rand(10000,99999) . '@' . $this->host . '>';
            $enc_sub  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $enc_from = '=?UTF-8?B?' . base64_encode($this->from_name ?: $this->from) . '?=';
            $plain    = wordwrap(strip_tags(str_replace(
                ['<br>','<br/>','<BR>','</p>','</div>','</li>'], "\n", $html_body)), 72, "\n");

            if (!empty($this->attachments)) {
                // ── multipart/mixed: corpo HTML + anexos ──────────────────────
                $outer = 'mix_' . md5(uniqid(mt_rand(), true));
                $inner = 'alt_' . md5(uniqid(mt_rand(), true));

                $hdr = "Date: "       . date('r')   . "\r\n"
                     . "Message-ID: " . $msg_id     . "\r\n"
                     . "From: "       . $enc_from   . " <{$this->from}>\r\n"
                     . "To: <"        . $to         . ">\r\n"
                     . "Subject: "    . $enc_sub    . "\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: multipart/mixed; boundary=\"{$outer}\"\r\n"
                     . "X-Mailer: Bienenstock/0.001\r\n";

                // Parte interna: texto + html
                $alt = "--{$inner}\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n"
                     . "Content-Transfer-Encoding: base64\r\n\r\n"
                     . chunk_split(base64_encode($plain)) . "\r\n"
                     . "--{$inner}\r\n"
                     . "Content-Type: text/html; charset=UTF-8\r\n"
                     . "Content-Transfer-Encoding: base64\r\n\r\n"
                     . chunk_split(base64_encode($html_body)) . "\r\n"
                     . "--{$inner}--\r\n";

                $body = "--{$outer}\r\n"
                      . "Content-Type: multipart/alternative; boundary=\"{$inner}\"\r\n\r\n"
                      . $alt . "\r\n";

                // Cada anexo
                foreach ($this->attachments as $att) {
                    $enc_name = '=?UTF-8?B?' . base64_encode($att['name']) . '?=';
                    $body .= "--{$outer}\r\n"
                           . "Content-Type: {$att['mime']}; name=\"{$enc_name}\"\r\n"
                           . "Content-Transfer-Encoding: base64\r\n"
                           . "Content-Disposition: attachment; filename=\"{$enc_name}\"\r\n\r\n"
                           . chunk_split(base64_encode($att['data'])) . "\r\n";
                }
                $body .= "--{$outer}--\r\n";

            } else {
                // ── multipart/alternative sem anexos ─────────────────────────
                $boundary = 'b_' . md5(uniqid(mt_rand(), true));
                $hdr = "Date: "       . date('r')   . "\r\n"
                     . "Message-ID: " . $msg_id     . "\r\n"
                     . "From: "       . $enc_from   . " <{$this->from}>\r\n"
                     . "To: <"        . $to         . ">\r\n"
                     . "Subject: "    . $enc_sub    . "\r\n"
                     . "MIME-Version: 1.0\r\n"
                     . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                     . "X-Mailer: Bienenstock/0.001\r\n";
                $body = "--{$boundary}\r\n"
                      . "Content-Type: text/plain; charset=UTF-8\r\n"
                      . "Content-Transfer-Encoding: base64\r\n\r\n"
                      . chunk_split(base64_encode($plain)) . "\r\n"
                      . "--{$boundary}\r\n"
                      . "Content-Type: text/html; charset=UTF-8\r\n"
                      . "Content-Transfer-Encoding: base64\r\n\r\n"
                      . chunk_split(base64_encode($html_body)) . "\r\n"
                      . "--{$boundary}--\r\n";
            }

            // Escapar ponto solitário no início de linha (RFC 5321 §4.5.2)
            $full = preg_replace('/^\.$/m', '..', $hdr . "\r\n" . $body);
            $this->swrite($full);
            $this->scmd(".", '250');
            $this->swrite("QUIT\r\n");
            @fclose($this->conn); $this->conn = null;
            return true;

        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            error_log('[Bienenstock SMTP] ' . $this->last_error);
            if ($this->conn) { @fclose($this->conn); $this->conn = null; }
            return false;
        }
    }

    // ── Conexão TCP + negociação TLS ──────────────────────────────────────────
    private function do_connect() {
        $enc = $this->resolve_enc();
        $this->slog("Conectando [{$enc}] em {$this->host}:{$this->port}");
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                                 | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                                 | STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ]]);
        $scheme = ($enc === 'ssl') ? 'ssl' : 'tcp';
        $errno = $errstr = 0;
        $this->conn = @stream_socket_client(
            "{$scheme}://{$this->host}:{$this->port}",
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->conn) throw new Exception("Falha na conexão com {$this->host}:{$this->port} — {$errstr}");
        stream_set_timeout($this->conn, $this->timeout);
        $banner = $this->sread();
        $this->slog("Banner: " . trim($banner));
        if (strpos($banner, '220') === false) throw new Exception("Banner inesperado: " . trim($banner));
        $this->swrite("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'bienenstock') . "\r\n");
        $ehlo = $this->sread();
        $this->slog("EHLO: " . str_replace("\r\n", " | ", trim($ehlo)));
        if ($enc === 'starttls') {
            $this->swrite("STARTTLS\r\n");
            $r = $this->sread();
            if (strpos($r, '220') === false) throw new Exception("STARTTLS recusado: " . trim($r));
            $ok = stream_socket_enable_crypto($this->conn, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$ok) throw new Exception("Falha ao ativar TLS — o servidor suporta TLS 1.2+?");
            $this->slog("TLS estabelecido");
            $this->swrite("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'bienenstock') . "\r\n");
            $this->sread();
        }
    }

    // ── Autenticação: AUTH LOGIN → AUTH PLAIN fallback ────────────────────────
    private function do_auth() {
        $this->swrite("AUTH LOGIN\r\n");
        $r = $this->sread();
        if (strpos($r, '334') !== false) {
            $this->swrite(base64_encode($this->username) . "\r\n"); $this->sread();
            $this->swrite(base64_encode($this->password) . "\r\n");
            $resp = $this->sread();
            if (strpos($resp, '235') !== false) return;
            $msg = trim($resp);
            if (strpos($resp, '534') !== false || strpos($resp, '535') !== false) {
                if (strpos($resp, 'Application-specific') !== false || strpos($resp, 'InvalidSecondFactor') !== false) {
                    $msg = 'Gmail exige Senha de App em myaccount.google.com/apppasswords (requer 2FA ativo).';
                } else {
                    $msg = 'Usuário ou senha incorretos. Verifique as credenciais SMTP.';
                }
            }
            throw new Exception("Auth LOGIN falhou: $msg");
        }
        $this->slog("AUTH LOGIN indisponível — tentando AUTH PLAIN");
        $plain = base64_encode("\0{$this->username}\0{$this->password}");
        $this->swrite("AUTH PLAIN {$plain}\r\n");
        $resp = $this->sread();
        if (strpos($resp, '235') === false) throw new Exception("AUTH PLAIN falhou: " . trim($resp));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function resolve_enc() {
        if ($this->encryption !== 'auto') return $this->encryption;
        if ((int)$this->port === 465) return 'ssl';
        if ((int)$this->port === 25)  return 'none';
        return 'starttls';
    }

    private function scmd($cmd, $expect) {
        $this->swrite($cmd . "\r\n");
        $resp = $this->sread();
        if ($expect && strpos($resp, $expect) === false)
            throw new Exception("Resposta inesperada para '" . substr($cmd,0,30) . "': " . trim($resp));
        return $resp;
    }

    private function swrite($data) { if ($this->conn) @fwrite($this->conn, $data); }

    private function sread() {
        $resp = ''; $start = time();
        while ($this->conn && !feof($this->conn)) {
            if (time() - $start > $this->timeout) break;
            $line = @fgets($this->conn, 512);
            if ($line === false) break;
            $resp .= $line;
            $this->slog('< ' . rtrim($line));
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $resp;
    }

    private function slog($msg) { $this->log[] = $msg; }
    public function get_log()        { return $this->log; }
    public function get_last_error() { return $this->last_error; }
}
