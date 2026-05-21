FROM php:8.2-apache

# 1. Install only basic zip and curl (Takes 5 seconds)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    libcurl4-openssl-dev \
    && docker-php-ext-install zip curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Setup Apache routing
RUN a2enmod rewrite && \
    sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# 3. Copy your local pre-built vendor folder and app files
COPY vendor/ ./vendor/
COPY . .

# 4. Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2ctl", "-D", "FOREGROUND"]