<?php
/**
 * BIENENSTOCK — Instalador headless (CLI / Docker).
 *
 * Usado pelo entrypoint do container para provisionar o banco de forma
 * automática e idempotente a partir de variáveis de ambiente.
 *
 * NÃO acesse este arquivo pelo navegador (protegido na configuração do Apache).
 *
 * Variáveis de ambiente:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 *   ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASS
 *   DB_WAIT_RETRIES (padrão: 30 tentativas, 2s de intervalo)
 */

$host = getenv('DB_HOST') ?: 'localhost';
$name = getenv('DB_NAME') ?: 'bienenstock';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$admin_name  = getenv('ADMIN_NAME')  ?: 'Administrador';
$admin_email = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
$admin_pass  = getenv('ADMIN_PASS')  ?: 'admin123';

if (strlen($admin_pass) < 6) {
    fwrite(STDERR, "[install_cli] Aviso: ADMIN_PASS deve ter pelo menos 6 caracteres.\n");
}

// ── Aguarda o banco ficar disponível (com retry) ──────────────────────────
$maxAttempts = (int)(getenv('DB_WAIT_RETRIES') ?: 30);
$pdo = null;
for ($i = 1; $i <= $maxAttempts; $i++) {
    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        break;
    } catch (PDOException $e) {
        fwrite(STDOUT, "[install_cli] Aguardando banco ($i/$maxAttempts): " . $e->getMessage() . "\n");
        sleep(2);
    }
}

if (!$pdo) {
    fwrite(STDERR, "[install_cli] Não foi possível conectar ao banco de dados.\n");
    exit(1);
}

// ── Cria/usa o banco ───────────────────────────────────────────────────────
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");

// ── Aplica o schema (idempotente) ──────────────────────────────────────────
$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "[install_cli] schema.sql não encontrado em " . __DIR__ . "\n");
    exit(1);
}

foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
    if ($sql !== '') {
        $pdo->exec($sql);
    }
}

// ── Admin + remetente padrão (idempotente) ─────────────────────────────────
$pw = password_hash($admin_pass, PASSWORD_DEFAULT);
$pdo->prepare("INSERT IGNORE INTO users (name,email,password) VALUES (?,?,?)")
    ->execute(array($admin_name, $admin_email, $pw));

$pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('from_email', ?) ON DUPLICATE KEY UPDATE setting_value=?")
    ->execute(array($admin_email, $admin_email));

// ── Grava config.php (fallback p/ hospedagem clássica e consistência) ──────
$config_content = "<?php\n"
    . "\$host=" . var_export($host, true) . ";\n"
    . "\$name=" . var_export($name, true) . ";\n"
    . "\$user=" . var_export($user, true) . ";\n"
    . "\$pass=" . var_export($pass, true) . ";\n";
file_put_contents(__DIR__ . '/config.php', $config_content);

fwrite(STDOUT, "[install_cli] Instalação concluída. Admin: $admin_email\n");
