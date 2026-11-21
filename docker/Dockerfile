FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install mysqli gd \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
