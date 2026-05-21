# Use a lightweight Ubuntu base instead of the official PHP source-compilation image
FROM ubuntu:22.04

# Stop Ubuntu from asking for timezone input during the build
ENV DEBIAN_FRONTEND=noninteractive

# 1. Add the official PHP repository and install pre-compiled extensions
RUN apt-get update && apt-get install -y software-properties-common \
    && add-apt-repository ppa:ondrej/php -y \
    && apt-get update && apt-get install -y \
    apache2 \
    libapache2-mod-php8.2 \
    php8.2 \
    php8.2-grpc \
    php8.2-protobuf \
    php8.2-curl \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-zip \
    php8.2-sodium \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 2. Enable Apache mod_rewrite and allow .htaccess files to work
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# 3. Set the working directory
WORKDIR /var/www/html

# 4. Copy your project files (and your locally built vendor folder!)
COPY . .

# 5. Set strict permissions for Apache web server security
RUN chown -R www-data:www-data /var/www/html

# 6. Start Apache in the foreground
CMD ["apache2ctl", "-D", "FOREGROUND"]

EXPOSE 80