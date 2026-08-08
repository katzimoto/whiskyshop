FROM wordpress:6.8.3-php8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends msmtp-mta mariadb-client wget $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* \
    && wget -q -O /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

COPY php-mail.ini /usr/local/etc/php/conf.d/php-mail.ini
COPY msmtp-send.sh /usr/local/bin/msmtp-send
RUN chmod +x /usr/local/bin/msmtp-send
