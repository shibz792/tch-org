FROM php:8.3-apache

# Install required extensions
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module (needed for your .htaccess / router)
RUN a2enmod rewrite

# Copy all files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/uploads

# Apache config
COPY .htaccess /var/www/html/
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/friendly-urls.conf \
    && a2enconf friendly-urls

EXPOSE 80
