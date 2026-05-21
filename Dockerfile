FROM php:8.2-apache

# Install only necessary system OS packages
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    libcurl4-openssl-dev \
    && docker-php-ext-install zip curl

# Use the mlocati installer for gRPC extensions
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions grpc protobuf

# Setup Apache
RUN a2enmod rewrite && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
WORKDIR /var/www/html

# Copy the pre-built vendor/ folder from your local machine
# If it's missing, this build will fail here, alerting you immediately
COPY vendor/ ./vendor/

# Copy the rest of the application
COPY . .

RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
CMD ["apache2ctl", "-D", "FOREGROUND"]