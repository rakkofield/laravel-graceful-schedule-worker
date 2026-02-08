FROM php:7.4-cli

RUN apt-get update \
  && apt-get install -y libzip-dev \
  && docker-php-ext-configure pcntl --enable-pcntl \
  && docker-php-ext-install pcntl zip \
  && pecl install redis \
  && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . ./demo
COPY --from=package . .

WORKDIR /app/demo

RUN composer install \
    && composer run-script post-root-package-install \
    && composer run-script post-create-project-cmd

RUN ln -sf /proc/1/fd/1 storage/logs/scheduler.log

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
