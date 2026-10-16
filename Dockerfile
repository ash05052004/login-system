FROM php:8.2-apache
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    ca-certificates \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql curl gd \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/uploads \
    && chmod -R 755 /var/www/html
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf
EXPOSE 8080

CMD ["apache2-foreground"]
