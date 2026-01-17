FROM php:8.4-fpm

# Install dependencies + ekstensi MySQL
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip unzip curl git \
 && docker-php-ext-install pdo_mysql mbstring bcmath gd

WORKDIR /var/www/html

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy project
COPY . .

# Install dependencies Laravel
RUN composer install --no-dev --optimize-autoloader

CMD ["php-fpm"]
