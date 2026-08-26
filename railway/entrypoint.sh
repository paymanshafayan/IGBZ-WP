#!/usr/bin/env bash
# راه‌انداز پیش‌نمایش ریل‌وی — بوت‌استرپ خودکار طبق HANDOFF §۷.۵ (بدون ویزارد دستی)
# ترتیب: آپاچی بالا می‌آید؛ هم‌زمان در پس‌زمینه: صبر برای دیتابیس ← نصب هسته ←
# ووکامرس ← igbz-suite ← زبان فارسی ← (بقیهٔ تنظیمات با mu-plugin های آزموده 030/100)
set -Eeuo pipefail

WEBROOT=/var/www/html
SRC=/usr/src/igbz

bootstrap() {
	# ۱) صبر تا فایل‌های هسته توسط انتری‌پوینت رسمی در وب‌روت قرار بگیرند
	until [ -f "$WEBROOT/wp-load.php" ]; do sleep 2; done

	# ۲) همگام‌سازی کد افزونه و mu-pluginها (هر استقرار = آخرین نسخهٔ مخزن)
	mkdir -p "$WEBROOT/wp-content/plugins" "$WEBROOT/wp-content/mu-plugins"
	rsync -a --delete "$SRC/plugins/igbz-suite/" "$WEBROOT/wp-content/plugins/igbz-suite/"
	rsync -a "$SRC/mu-plugins/" "$WEBROOT/wp-content/mu-plugins/"
	chown -R www-data:www-data "$WEBROOT/wp-content/plugins/igbz-suite" "$WEBROOT/wp-content/mu-plugins"

	# ۳) صبر برای دیتابیس
	local tries=0
	until wp --allow-root --path="$WEBROOT" db check >/dev/null 2>&1; do
		tries=$((tries+1)); [ "$tries" -gt 60 ] && { echo "igbz: db unreachable"; return 1; }
		sleep 5
	done

	# ۴) نصب هسته (فقط بار اول) — رمز ادمین از متغیر محیطی؛ هرگز rand()
	local URL="${WP_PUBLIC_URL:-https://${RAILWAY_PUBLIC_DOMAIN:-localhost}}"
	if ! wp --allow-root --path="$WEBROOT" core is-installed >/dev/null 2>&1; then
		wp --allow-root --path="$WEBROOT" core install \
			--url="$URL" \
			--title="شاپ بیوتی — فروشگاه نمونه آرایشی" \
			--admin_user="${WP_ADMIN_USER:-admin}" \
			--admin_password="${WP_ADMIN_PASSWORD:?WP_ADMIN_PASSWORD env var is required}" \
			--admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
			--skip-email
	fi
	# آدرس سایت همیشه با دامنهٔ فعلی ریل‌وی هم‌گام بماند
	wp --allow-root --path="$WEBROOT" option update home "$URL" >/dev/null
	wp --allow-root --path="$WEBROOT" option update siteurl "$URL" >/dev/null

	# ۵) ووکامرس قبل از igbz-suite (ترتیب الزامی — HANDOFF §۷.۵)
	if ! wp --allow-root --path="$WEBROOT" plugin is-installed woocommerce 2>/dev/null; then
		wp --allow-root --path="$WEBROOT" plugin install woocommerce
	fi
	wp --allow-root --path="$WEBROOT" plugin activate woocommerce igbz-suite >/dev/null 2>&1 || true

	# ۶) زبان فارسی واقعی (اینترنت اینجا باز است — بستهٔ رسمی، نه ترجمهٔ موقت دمو)
	wp --allow-root --path="$WEBROOT" language core install fa_IR --activate >/dev/null 2>&1 || true
	wp --allow-root --path="$WEBROOT" language plugin install woocommerce fa_IR >/dev/null 2>&1 || true

	echo "igbz: bootstrap done — $URL"
}

bootstrap &
exec docker-entrypoint.sh "$@"
