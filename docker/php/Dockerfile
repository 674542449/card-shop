FROM php:8.5-fpm-trixie

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    unzip \
    git \
    curl \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# PostgreSQL 17 matches the database image; Debian's default client is older.
RUN install -d /usr/share/postgresql-common/pgdg \
    && curl --fail -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && echo 'deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt trixie-pgdg main' > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client-17 \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (OPcache is compiled into PHP 8.5 core)
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    bcmath \
    pcntl

# Install Redis extension via PECL
RUN pecl install redis-6.3.0 && docker-php-ext-enable redis

# Copy Composer from official image
COPY --from=composer:2.11 /usr/bin/composer /usr/bin/composer

# Install supported Node.js 24 LTS for admin builds and DOM-based checks.
RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Configure OPcache
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=2'; \
    echo 'opcache.enable_cli=1'; \
} > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Configure PHP
RUN { \
    echo 'upload_max_filesize=100M'; \
    echo 'post_max_size=104M'; \
    echo 'memory_limit=256M'; \
    echo 'max_execution_time=60'; \
    echo 'expose_php=Off'; \
} > /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Copy entrypoint and worker supervisor scripts
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/worker-supervisor.sh /usr/local/bin/worker-supervisor
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/worker-supervisor

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
