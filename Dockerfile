# Use an official PHP Apache image
FROM php:8.2-apache

# Install system dependencies required for Firestore/gRPC
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install zip

# Install gRPC and Protobuf extensions (REQUIRED for Firestore)
RUN pecl install grpc protobuf \
    && docker-php-ext-enable grpc protobuf

# Enable Apache mod_rewrite (Essential for most PHP apps)
RUN a2enmod rewrite

# Set the working directory to the standard Apache folder
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Copy your project files into the container
COPY . .

# Set permissions for Apache
RUN chown -R www-data:www-data /var/www/html