#!/bin/sh
set -e

APP_DIR="${APACHE_DOCUMENT_ROOT:-/var/www/html}"
cd "$APP_DIR"

# Garante o diretório de uploads (fotos, certificados)
mkdir -p uploads/certs

# Instalação automática via variáveis de ambiente (idempotente).
# Sem DB_*, o usuário usa o instalador web (install.php) pelo navegador.
if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    echo "[entrypoint] Banco configurado via ambiente — executando instalação headless..."
    php install_cli.php
else
    echo "[entrypoint] Variáveis DB_* não definidas — use install.php pelo navegador."
fi

# Permissões para o Apache escrever em uploads/ (e em config.php no fluxo clássico)
chown -R www-data:www-data "$APP_DIR" 2>/dev/null || true

exec "$@"
