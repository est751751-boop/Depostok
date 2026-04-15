FROM php:8.2-apache

# PDO + PostgreSQL extension (Neon.tech için)
RUN apt-get update \
    && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache mod_rewrite + mod_headers (.htaccess için)
RUN a2enmod rewrite headers

# .htaccess'in çalışması için AllowOverride All
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Proje dosyalarını kopyala
COPY . /var/www/html/

# storage klasörü (local dev fallback; Render'da ephemeral ama sorun değil)
RUN mkdir -p /var/www/html/storage \
    && chmod -R 775 /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]
