<?php
/**
 * BIENSTOCK — Bootstrap centralizado de configuração e conexão com o banco.
 *
 * Resolve as credenciais do banco e estabelece a conexão PDO em $db.
 * Prioridade de configuração (padrão 12-factor):
 *   1. Variáveis de ambiente (Docker)  — DB_HOST, DB_NAME, DB_USER, DB_PASS
 *   2. config.php (hospedagem clássica — gerado pelo install.php)
 *
 * Compatível com PHP 7.4+.
 */

if (!defined('BASE')) {
    define('BASE', __DIR__);
}

// 1) Carrega o config.php legado, se existir (define $host, $name, $user, $pass)
$config_file = BASE . '/config.php';
if (file_exists($config_file)) {
    require $config_file;
}

// 2) Variáveis de ambiente têm precedência sobre o config.php
$host = getenv('DB_HOST') ?: (isset($host) ? $host : 'localhost');
$name = getenv('DB_NAME') ?: (isset($name) ? $name : 'bienenstock');
$user = getenv('DB_USER') ?: (isset($user) ? $user : 'root');
$pass = getenv('DB_PASS') ?: (isset($pass) ? $pass : '');

// 3) Conexão PDO
try {
    $db = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (PDOException $e) {
    die('Erro DB: ' . $e->getMessage());
}
