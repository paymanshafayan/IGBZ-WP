#!/usr/bin/env bash
# راه‌انداز پیش‌نمایش ریل‌وی — بوت‌استرپ خودکار طبق HANDOFF §۷.۵ (بدون ویزارد دستی)
# ترتیب: آپاچی بالا می‌آید؛ هم‌زمان در پس‌زمینه: صبر برای دیتابیس ← نصب هسته ←
# ووکامرس ← igbz-suite ← زبان فارسی ← (بقیهٔ تنظیمات با mu-plugin های آزموده 030/100)
set -Eeuo pipefail

WEBROOT=/var/www/html
SRC=/usr/src/igbz

# ریل‌وی ممکن است تصویر پایه را با چند MPM فعال اجرا کند. برای mod_php وردپرس
# فقط prefork مجاز است؛ این کار باید در زمان اجرا و پیش از راه‌انداز رسمی انجام شود.
normalize_apache_mpm() {
	echo "igbz: normalizing Apache MPM modules"
	a2dismod mpm_event mpm_worker mpm_prefork >/dev/null 2>&1 || true
	rm -f /etc/apache2/mods-enabled/mpm_event.* \
		/etc/apache2/mods-enabled/mpm_worker.* \
		/etc/apache2/mods-enabled/mpm_prefork.*
	a2enmod mpm_prefork >/dev/null
	echo "igbz: enabled MPM modules:"
	find /etc/apache2/mods-enabled -maxdepth 1 -type l -name 'mpm_*.*' -printf '  %f -> %l\n' | sort
	apache2ctl -t
}

normalize_apache_mpm

# راه‌انداز رسمی وردپرس فقط مراحل آماده‌سازی را هنگام دریافت فرمان apache2 اجرا می‌کند.
# آن را در پس‌زمینه اجرا می‌کنیم تا فایل‌های وردپرس و wp-config.php ساخته شوند؛
# سپس bootstrap را اجرا کرده و در پایان همان فرایند آپاچی را نگه می‌داریم.
echo "igbz: initializing WordPress files and configuration"
# Keep WordPress in maintenance mode while the first-boot bootstrap mutates the
# database and activates plugins.  Railway may probe the public port as soon as
# Apache binds; without this guard a probe can race the wp-cli bootstrap and lock
# wp_options (notably during setup_theme/theme-root discovery).
touch "$WEBROOT/.maintenance"
cleanup_maintenance() {
	rm -f "$WEBROOT/.maintenance"
}
# WordPress maintenance mode alone is not sufficient: theme discovery can still
# touch wp_options before that check. Deny the document root at Apache level
# until wp-cli has finished the first-boot mutations.
cat > /etc/apache2/conf-available/igbz-bootstrap-deny.conf <<'APACHECONF'
<Directory /var/www/html>
    Require all denied
</Directory>
APACHECONF
a2enconf igbz-bootstrap-deny >/dev/null
trap 'cleanup_maintenance; kill "${APACHE_PID:-}" 2>/dev/null || true' TERM INT EXIT

docker-entrypoint.sh apache2-foreground &
APACHE_PID=$!

until [ -f "$WEBROOT/wp-load.php" ] && [ -s "$WEBROOT/wp-config.php" ]; do
	if ! kill -0 "$APACHE_PID" 2>/dev/null; then
		wait "$APACHE_PID"
	fi
	sleep 2
done

bootstrap() {
	# ۱) اطمینان از قرارگرفتن فایل‌های هسته در وب‌روت
	until [ -f "$WEBROOT/wp-load.php" ]; do sleep 2; done

	# ۲) همگام‌سازی کد افزونه و mu-pluginها (هر استقرار = آخرین نسخهٔ مخزن)
	echo "igbz: syncing plugin and mu-plugin files"
	mkdir -p "$WEBROOT/wp-content/plugins" "$WEBROOT/wp-content/mu-plugins"
	rsync -a --delete "$SRC/plugins/igbz-suite/" "$WEBROOT/wp-content/plugins/igbz-suite/"
	rsync -a "$SRC/mu-plugins/" "$WEBROOT/wp-content/mu-plugins/"
	mkdir -p "$WEBROOT/wp-content/uploads/igbz-sample-assets"
	rsync -a "$SRC/assets/" "$WEBROOT/wp-content/uploads/igbz-sample-assets/"
	chown -R www-data:www-data "$WEBROOT/wp-content/plugins/igbz-suite" "$WEBROOT/wp-content/mu-plugins" "$WEBROOT/wp-content/uploads/igbz-sample-assets"
	echo "igbz: plugin and sample image files synced"

	# ۳) صبر برای دیتابیس — خطا عمداً پنهان نمی‌شود تا علت توقف در لاگ مشخص باشد
	echo "igbz: waiting for database"
	local tries=0
	until wp --allow-root --path="$WEBROOT" db check --skip-ssl-verify-server-cert; do
		tries=$((tries+1))
		if [ "$tries" -gt 60 ]; then
			echo "igbz: db unreachable after 60 attempts"
			return 1
		fi
		echo "igbz: database not ready, attempt $tries/60"
		sleep 5
	done
	echo "igbz: database ready"

	# فاز ۷۰ — TLS اختیاری به دیتابیس (network خصوصی ریل‌وی رمزنگاری‌شده است؛
	# برای دیتابیس عمومی/سفارشی IGBZ_DB_TLS=1 بگذارید تا MYSQLI_CLIENT_SSL فعال شود)
	if [ "${IGBZ_DB_TLS:-0}" = "1" ]; then
		echo "igbz: enabling database TLS (MYSQLI_CLIENT_SSL)"
		wp --allow-root --path="$WEBROOT" config set MYSQL_CLIENT_FLAGS 'MYSQLI_CLIENT_SSL' --raw
	fi

	# ۴) نصب هسته (فقط بار اول) — رمز ادمین از متغیر محیطی؛ هرگز rand()
	local URL="${WP_PUBLIC_URL:-https://${RAILWAY_PUBLIC_DOMAIN:-localhost}}"
	if ! wp --allow-root --path="$WEBROOT" core is-installed; then
		echo "igbz: installing WordPress core"
		wp --allow-root --path="$WEBROOT" core install \
			--url="$URL" \
			--title="شاپ بیوتی — فروشگاه نمونه آرایشی" \
			--admin_user="${WP_ADMIN_USER:-admin}" \
			--admin_password="${WP_ADMIN_PASSWORD:?WP_ADMIN_PASSWORD env var is required}" \
			--admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
			--skip-email
		echo "igbz: WordPress core installed"
	else
		echo "igbz: WordPress core already installed"
	fi
	# آدرس سایت همیشه با دامنهٔ فعلی ریل‌وی هم‌گام بماند
	wp --allow-root --path="$WEBROOT" option update home "$URL"
	wp --allow-root --path="$WEBROOT" option update siteurl "$URL"

	# ۵) ووکامرس قبل از igbz-suite (ترتیب الزامی — HANDOFF §۷.۵)
	if ! wp --allow-root --path="$WEBROOT" plugin is-installed woocommerce; then
		echo "igbz: installing WooCommerce"
		wp --allow-root --path="$WEBROOT" plugin install woocommerce
		echo "igbz: WooCommerce installed"
	else
		echo "igbz: WooCommerce already installed"
	fi
	if ! wp --allow-root --path="$WEBROOT" plugin is-active woocommerce; then
		echo "igbz: activating WooCommerce"
		wp --allow-root --path="$WEBROOT" plugin activate woocommerce
	fi
	if ! wp --allow-root --path="$WEBROOT" plugin is-active igbz-suite; then
		echo "igbz: activating igbz-suite"
		wp --allow-root --path="$WEBROOT" plugin activate igbz-suite
	fi
	echo "igbz: required plugins active"

	# ۶) زبان فارسی واقعی (اینترنت اینجا باز است — بستهٔ رسمی، نه ترجمهٔ موقت دمو)
	echo "igbz: installing Persian language packs"
	wp --allow-root --path="$WEBROOT" language core install fa_IR --activate || echo "igbz: warning — Persian core language was not installed"
	wp --allow-root --path="$WEBROOT" language plugin install woocommerce fa_IR || echo "igbz: warning — Persian WooCommerce language was not installed"

	echo "igbz: bootstrap done — $URL"
}

bootstrap
cleanup_maintenance
# Only now expose WordPress to Railway probes and users.
a2disconf igbz-bootstrap-deny >/dev/null
apache2ctl graceful

# فاز ۷۰ — گیت آمادگی: سلامت خودمان را می‌پرسیم تا زمانی که ۲۰۰ بدهد؛
# نتیجه در لاگ استقرار دیده می‌شود و railway.json هم healthcheckPath دارد.
(
	try=0
	until [ "$try" -gt 30 ]; do
		try=$((try+1))
		code=$(curl -s -o /tmp/igbz-health.json -w "%{http_code}" "http://127.0.0.1/?igbz_health=1" || echo 000)
		if [ "$code" = "200" ]; then
			touch /tmp/igbz-ready
			echo "igbz: ready (health 200, attempt $try)"
			exit 0
		fi
		echo "igbz: not ready yet (health $code, attempt $try/30)"
		sleep 10
	done
	echo "igbz: WARNING — health never reached 200; inspect /tmp/igbz-health.json"
) &

# فاز ۷۱ — worker/cron سرور: با IGBZ_SERVER_CRON=1 حلقهٔ پس‌زمینه‌ای هر ۶۰ ثانیه
# wp-cron و صف کارها را از CLI می‌راند (به‌جای ضربان loopback وب). loopback در
# پلتفرم‌های کانتینری (ریل‌وی) دقیق نیست: درخواست‌ها می‌میرند، ضربان گم می‌شود و
# صف پشت‌می‌گذارد. قدم اول این نیست که wp-cron را خاموش کنیم — فعال می‌ماند تا
# اگر حلقه مُرد، سایت همچنان کار کند (دو راننده بدتر از صفر راننده نیست)؛ اما
# رویدادهای تکرارشونده idempotent اند و دوبار زدنشان بی‌ضرر است.
if [ "${IGBZ_SERVER_CRON:-0}" = "1" ]; then
	echo "igbz: server cron/worker loop enabled (60s beat)"
	(
		while true; do
			# فقط وقتی بوت‌استرپ تمام شده (وگرنه wp روی نصب ناتمام می‌خرد)
			if [ -f "$WEBROOT/wp-load.php" ] && wp --allow-root --path="$WEBROOT" core is-installed >/dev/null 2>&1; then
				wp --allow-root --path="$WEBROOT" cron event run --due >/dev/null 2>&1 || echo "igbz: cron beat failed (will retry)"
				wp --allow-root --path="$WEBROOT" igbz jobs drain >/dev/null 2>&1 || echo "igbz: worker drain failed (will retry)"
			fi
			sleep 60
		done
	) &
fi

# کانتینر تا پایان عمر آپاچی زنده می‌ماند؛ حلقهٔ آمادگی فقط تزئینیِ لاگ نیست —
# خروج موفقش هرگز فرایند والد را نمی‌کشد.
wait "$APACHE_PID"
