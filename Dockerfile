FROM php:8.2-apache

# 1. Install necessary system OS packages
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    libcurl4-openssl-dev \
    && docker-php-ext-install zip curl

# 2. CRITICAL: Install gRPC and Protobuf using the fast mlocati script
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions grpc protobuf

# 3. Enable Apache rewrite rules
RUN a2enmod rewrite && \
    sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# 4. Copy the pre-built vendor/ folder and app files
COPY vendor/ ./vendor/
COPY . .

# 5. Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2ctl", "-D", "FOREGROUND"]