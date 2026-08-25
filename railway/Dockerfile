# پیش‌نمایش دائمی فروشگاه IGBZ روی ریل‌وی
# وردپرس رسمی (آپاچی) + ابزار خط فرمان وردپرس + افزونهٔ igbz-suite از همین مخزن
FROM wordpress:php8.2-apache

# ابزار خط فرمان وردپرس (برای نصب خودکار بدون ویزارد)
COPY --from=wordpress:cli-php8.2 /usr/local/bin/wp /usr/local/bin/wp

# رفع مشکل شناخته‌شدهٔ ماژول وب‌سرور در بعضی محیط‌ها (بی‌ضرر اگر از قبل درست باشد)
RUN a2dismod mpm_event mpm_worker 2>/dev/null; a2enmod mpm_prefork 2>/dev/null; true

# کد افزونه و mu-pluginها در مسیر منبع — در هر استارت به ولوم همگام می‌شوند
COPY igbz-suite /usr/src/igbz/plugins/igbz-suite
COPY railway/mu-plugins /usr/src/igbz/mu-plugins
COPY railway/entrypoint.sh /usr/local/bin/igbz-entrypoint.sh
RUN chmod +x /usr/local/bin/igbz-entrypoint.sh && apt-get update && apt-get install -y --no-install-recommends rsync default-mysql-client && rm -rf /var/lib/apt/lists/*

ENTRYPOINT ["igbz-entrypoint.sh"]
CMD ["apache2-foreground"]
