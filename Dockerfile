# syntax=docker/dockerfile:1

# Use FrankenPHP with PHP 8.2 on Alpine as base
FROM dunglas/frankenphp:php8.4-alpine

# Switch to root for installation tasks
USER root

# Install PHP extensions using the built-in installer
# and system dependencies (dcron for cron functionality)
RUN install-php-extensions \
    zip \
    curl \
    pdo_mysql \
    pdo \
    redis \
    && apk add --no-cache dcron

# Create app user and group for running as non-root
RUN addgroup -S app && adduser -S app -G app

# Set working directory
WORKDIR /app

# Copy composer files first to leverage Docker cache
COPY composer.json composer.lock* ./

# Install Composer dependencies
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-interaction --no-dev --optimize-autoloader

# Copy Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Copy application code
COPY . /app

# Copy cron configuration and set permissions
COPY cron/telegram-bot-cron /etc/cron.d/telegram-bot-cron
RUN chmod 0644 /etc/cron.d/telegram-bot-cron \
    && touch /var/log/cron.log

# Create and set permissions for data directory and log files
RUN mkdir -p /app/data \
    && touch /app/data/caddy-access.log /app/data/php-access.log /app/data/php-error.log \
    && chown -R app:app /app/data /var/log/cron.log \
    && chmod -R 775 /app/data

# Create a startup script that runs cron as root but Caddy as app
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh \
    && echo 'crond -b -L /var/log/cron.log' >> /usr/local/bin/start.sh \
    && echo 'mkdir -p /data/caddy/locks' >> /usr/local/bin/start.sh \
    && echo 'mkdir -p /config/caddy' >> /usr/local/bin/start.sh \
    && echo 'touch /app/data/caddy-access.log /app/data/php-access.log /app/data/php-error.log' >> /usr/local/bin/start.sh \
    && echo 'chown -R app:app /app/data /var/log/cron.log /data/caddy /config/caddy' >> /usr/local/bin/start.sh \
    && echo 'su -s /bin/sh -c "exec /usr/local/bin/frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile" app' >> /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

# We need to run as root to start crond, but Caddy will run as app user
USER root


# Start both Caddy and Cron
CMD ["/usr/local/bin/start.sh"]
