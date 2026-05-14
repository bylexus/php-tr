FROM php:8.3-cli
LABEL author="bylexus <info@alexi.ch>"

WORKDIR /src

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        git \
        unzip \
        libpq-dev \
    && apt-get autoremove -y && apt-get clean -y \
    && docker-php-ext-install pdo_mysql pgsql pdo_pgsql pcntl

# install composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php --install-dir=/usr/bin --filename=composer && \
    php -r "unlink('composer-setup.php');"
