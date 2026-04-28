FROM php:8.4-fpm-alpine

ARG UID=1000
ARG GID=1000

# Dependências temporárias pra compilar extensões
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    linux-headers

# Dependências do sistema
RUN apk add --no-cache \
    bash \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    mysql-client \
    supervisor \
    nginx \
    nodejs \
    npm

# Instala extensões PHP
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
    gd \
    pdo_mysql \
    bcmath \
    pcntl \
    intl \
    zip \
    opcache \
    exif

# Instala Redis e limpa deps de build
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Instala Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Cria usuário
RUN addgroup -g ${GID} laravel && adduser -G laravel -g laravel -s /bin/sh -D -u ${UID} laravel

# Configurações do PHP
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www
USER laravel

EXPOSE 9000
CMD ["php-fpm"]
