# پیش‌نمایش دائمی فروشگاه IGBZ روی ریل‌وی
# وردپرس رسمی (آپاچی) + ابزار خط فرمان وردپرس + افزونهٔ igbz-suite از همین مخزن
FROM wordpress:php8.2-apache

# ابزار خط فرمان وردپرس (برای نصب خودکار بدون ویزارد)
COPY --from=wordpress:cli-php8.2 /usr/local/bin/wp /usr/local/bin/wp

RUN rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf && ls /etc/apache2/mods-enabled/ | grep mpm

# کد افزونه و mu-pluginها در مسیر منبع — در هر استارت به ولوم همگام می‌شوند
COPY igbz-suite /usr/src/igbz/plugins/igbz-suite
COPY railway/mu-plugins /usr/src/igbz/mu-plugins
COPY railway/entrypoint.sh /usr/local/bin/igbz-entrypoint.sh
RUN chmod +x /usr/local/bin/igbz-entrypoint.sh && apt-get update && apt-get install -y --no-install-recommends rsync default-mysql-client && rm -rf /var/lib/apt/lists/*

ENTRYPOINT ["igbz-entrypoint.sh"]
CMD ["apache2-foreground"]
