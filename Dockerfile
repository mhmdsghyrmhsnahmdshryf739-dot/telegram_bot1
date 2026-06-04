FROM php:8.2-cli

# تثبيت الملحقات المطلوبة لـ MadelineProto
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libsqlite3-dev \
    libzip-dev \
    curl \
    && docker-php-ext-install pdo_sqlite zip

# تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# إنشاء مجلد العمل
WORKDIR /app

# نسخ الملفات
COPY . .

# تثبيت الاعتماديات
RUN composer require danog/madelineproto

# تعيين متغير المنفذ من Render
ENV PORT=8080

# فتح المنفذ
EXPOSE $PORT

# تشغيل البوت
CMD php -S 0.0.0.0:$PORT -t .