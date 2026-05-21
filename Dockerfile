FROM php:8.2-apache

# 1. Install only the system-level OS packages (No composer, no build-tools)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install zip

# 2. Use the light installer ONLY for the critical system extensions
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions grpc protobuf

# 3. Setup Apache
RUN a2enmod rewrite && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# 4. Copy everything (This now includes your pre-built vendor/ folder!)
COPY . .

# 5. Fix permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80