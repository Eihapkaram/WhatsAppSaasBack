#!/bin/bash

# تشغيل PHP-FPM في الخلفية
php-fpm -D

# تشغيل Nginx في الخلفية
nginx -g "daemon on;"

# تشغيل الـ Queue Worker المخصص للإنتاج بشكل مستمر
echo "Starting Laravel Queue Worker..."
php /var/www/html/artisan queue:work --cache=file --tries=3 --delay=3
