# Use an official PHP Apache image
FROM php:8.2-apache

# Install basic runtime extensions (No heavy build tools or PECL compilers)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    libcurl4-openssl-dev \
    && docker-php-ext-install zip curl

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set the working directory to the standard Apache folder
WORKDIR /var/www/html

# Copy everything directly (including your pre-built local vendor folder!)
COPY . .

# Set strict permissions for Apache web server security
RUN chown -R www-data:www-data /var/www/html

# Expose standard web port
EXPOSE 80