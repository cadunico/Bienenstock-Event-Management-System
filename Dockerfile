# syntax=docker/dockerfile:1

# BIENENSTOCK — imagem da aplicação (PHP 8.2 + Apache)
# Extensões necessárias: pdo, pdo_mysql, gd (com FreeType/WebP), mbstring, zip

FROM php:8.2-apache

# ── Dependências do sistema + extensões PHP ────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libonig-dev \
        fonts-dejavu-core \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql gd mbstring zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ── Apache: mod_rewrite + AllowOverride + proteção de arquivos sensíveis ────
RUN a2enmod rewrite
COPY ["docker/apache.conf", "/etc/apache2/conf-available/bienenstock.conf"]
RUN a2enconf bienenstock

# ── Aplicação ───────────────────────────────────────────────────────────────
COPY ["version 0001/Bienenstock/", "/var/www/html/"]

# ── Entrypoint de inicialização ─────────────────────────────────────────────
COPY ["docker/entrypoint.sh", "/usr/local/bin/entrypoint.sh"]
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p /var/www/html/uploads/certs

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
