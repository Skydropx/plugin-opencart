# Local OpenCart store for testing the Skydropx extension.
# Mirrors the upstream tools/Dockerfile (php:8.2-apache + gd, zip, mysqli, curl)
# and bakes the OpenCart release into the image so www-data owns it.
FROM php:8.2-apache

ARG OPENCART_VERSION=4.1.0.4

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
       wait-for-it unzip curl ca-certificates \
       libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libcurl4-openssl-dev libwebp-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j"$(nproc)" gd zip mysqli curl \
  && a2enmod rewrite \
  && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL "https://github.com/opencart/opencart/archive/refs/tags/${OPENCART_VERSION}.tar.gz" -o /tmp/opencart.tar.gz \
  && mkdir -p /tmp/opencart \
  && tar -xzf /tmp/opencart.tar.gz -C /tmp/opencart --strip-components=1 \
  && cp -a /tmp/opencart/upload/. /var/www/html/ \
  && rm -rf /tmp/opencart /tmp/opencart.tar.gz \
  && chown -R www-data:www-data /var/www/html

COPY entrypoint.sh /usr/local/bin/opencart-entrypoint
RUN chmod +x /usr/local/bin/opencart-entrypoint

CMD ["opencart-entrypoint"]
