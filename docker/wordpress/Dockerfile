FROM wordpress:6.8.3-php8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends msmtp-mta \
    && rm -rf /var/lib/apt/lists/*

COPY php-mail.ini /usr/local/etc/php/conf.d/php-mail.ini
