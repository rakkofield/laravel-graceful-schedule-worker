FROM php:7.2-cli

RUN docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . ./

RUN composer install \
    && composer run-script post-root-package-install \
    && composer run-script post-create-project-cmd

RUN ln -sf /proc/1/fd/1 storage/logs/scheduler.log
