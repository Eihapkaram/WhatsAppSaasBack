# استخدام نسخة PHP الرسمية مع Nginx المدمجة أو إعداد يدوي مستقر
FROM php:8.2-fpm-alpine

# تثبيت الإضافات الأساسية للارافل وباسبورت والإكسيل
RUN apk add --no-cache \
    nginx \
    shadow \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    zip \
    libzip-dev \
    unzip \
    git \
    curl \
    bash \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip bcmath

# تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# إعداد ملفات العمل
WORKDIR /var/www/html
COPY . .

# تثبيت حزم لارافل بدون حزم الـ Development وتوليد الـ Autoload
RUN composer install --no-dev --optimize-autoloader

# ضبط الصلاحيات للمجلدات الحيوية في لارافل
RUN chown -r www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# نسخ إعدادات Nginx المخصصة لـ Railway (بورت متغير)
COPY ./docker/nginx.conf /etc/nginx/nginx.conf

# تجهيز سكريبت التشغيل المزدوج (السيرفر والـ Queue)
COPY ./docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# المنفذ الافتراضي لـ Railway
EXPOSE 8080

ENTRYPOINT ["entrypoint.sh"]
