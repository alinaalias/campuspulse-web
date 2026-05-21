# 1. Use the official, stable PHP 8.2 Apache image
FROM php:8.2-apache

# 2. Download the magical PHP Extension Installer script
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# 3. Make it executable and install the heavy extensions in seconds (No compiling!)
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions grpc protobuf zip

# 4. Enable Apache mod_rewrite (Essential for routing)
RUN a2enmod rewrite

# 5. Set the working directory
WORKDIR /var/www/html

# 6. Copy your project files AND your pre-built vendor folder
COPY . .

# 7. Secure file permissions for Apache
RUN chown -R www-data:www-data /var/www/html

# 8. Expose standard web port
EXPOSE 80