# Use a lightweight Ubuntu 22.04 base
FROM ubuntu:22.04

# Stop Ubuntu from asking for timezone input during the build
ENV DEBIAN_FRONTEND=noninteractive

# Install Apache, PHP 8.1 (default for 22.04), and the pre-compiled gRPC extension
RUN apt-get update && apt-get install -y \
    apache2 \
    php \
    libapache2-mod-php \
    php-grpc \
    php-protobuf \
    php-curl \
    php-mbstring \
    php-xml \
    php-zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and allow .htaccess files to work
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set the working directory
WORKDIR /var/www/html

# Copy your project files (and your locally built vendor folder!)
COPY . .

# Set strict permissions for Apache web server security
RUN chown -R www-data:www-data /var/www/html

# Start Apache in the foreground
CMD ["apache2ctl", "-D", "FOREGROUND"]

EXPOSE 80