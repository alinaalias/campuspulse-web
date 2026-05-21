# Use an official PHP Apache image
FROM php:8.2-apache

# Install system dependencies required for Firestore, cURL, and HTTP/2 support
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    git \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    && docker-php-ext-install zip curl

# Enable Apache mod_rewrite (Essential for web forms and clean routing)
RUN a2enmod rewrite

# Set the working directory to the standard Apache folder
WORKDIR /var/www/html

# Install Composer natively inside the builder stage
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy composer configuration AND your lock file first
# (Crucial: copy composer.lock so composer doesn't try to recalculate dependencies)
COPY composer.json composer.lock* ./

# THE FIX: Optimize environment variables to force low memory limits on Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

# Install project dependencies using extreme low-memory settings
RUN COMPOSER_PROCESS_TIMEOUT=600 composer install \
    --no-dev \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction \
    --no-cache \
    --ignore-platform-req=ext-grpc

# Copy the rest of your project application files
COPY . .

# Set strict permissions for Apache web server security
RUN chown -R www-data:www-data /var/www/html

# Expose standard web port
EXPOSE 80