#!/usr/bin/env bash
#
# Start the WordPress + WooCommerce test site with igbz-suite mounted.
#
# Usage:  bash _devenv/run.sh [--port 9400] [--php 8.2]
#
# Serves the WordPress zip from a local HTTP server and points the Playground CLI's --wp flag
# at it, which avoids the blocked wordpress.org download entirely.
#
set -Eeuo pipefail

DEVENV="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$DEVENV/.." && pwd)"
WORK="$DEVENV/.work"

PORT=9400
ZIP_PORT=8799
PHP_VERSION=8.2

while [ $# -gt 0 ]; do
	case "$1" in
		--port) PORT="$2"; shift 2 ;;
		--php)  PHP_VERSION="$2"; shift 2 ;;
		*) echo "unknown option: $1" >&2; exit 1 ;;
	esac
done

die() { printf '\n\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$WORK/node_modules/@wp-playground/cli" ] || die "environment not built — run: bash _devenv/setup.sh"
[ -d "$WORK/woocommerce" ] || die "WooCommerce not extracted — run: bash _devenv/setup.sh"
[ -f "$WORK/.wp-zip-name" ] || die "WordPress zip not published — run: bash _devenv/setup.sh"

WP_ZIP_NAME="$(cat "$WORK/.wp-zip-name")"
[ -f "$WORK/serve/$WP_ZIP_NAME" ] || die "missing $WORK/serve/$WP_ZIP_NAME — run: bash _devenv/setup.sh"

# Is anything bound to this TCP port?
#
# This deliberately does NOT use `curl -sf`: a Playground instance that is still booting, or any
# server answering 3xx/4xx/5xx, makes curl exit non-zero and the port looks free. The result was a
# second instance dying with EADDRINUSE several minutes into a boot. A raw connect() is the only
# honest answer. ('fuser'/'ss'/'lsof' are not installed in this sandbox.)
port_in_use() {
	python3 - "$1" <<-'PY'
		import socket, sys
		s = socket.socket()
		s.settimeout(1)
		sys.exit(0 if s.connect_ex(("127.0.0.1", int(sys.argv[1]))) == 0 else 1)
	PY
}

# Refuse to start if the WordPress port is already taken, rather than failing confusingly later.
if port_in_use "$PORT"; then
	die "something is already listening on port $PORT.
Stop it first (ps aux | grep wp-playground, then kill the pid), or pick another port:
  bash _devenv/run.sh --port 9401"
fi

# Serve the WordPress zip locally.
#
# If a previous run left a server on this port, reuse it when it is serving the right file, and
# otherwise pick a free port. ('fuser' is not installed in this sandbox, so we cannot just kill
# the holder, and killing an unrelated process would be rude anyway.)
ZIP_PID=""
if curl -sf -o /dev/null --max-time 3 "http://127.0.0.1:$ZIP_PORT/$WP_ZIP_NAME" 2>/dev/null; then
	echo "==> reusing the zip server already running on 127.0.0.1:$ZIP_PORT"
else
	# Find a port nobody is listening on.
	for _ in $(seq 1 20); do
		if port_in_use "$ZIP_PORT"; then
			ZIP_PORT=$(( ZIP_PORT + 1 ))
		else
			break
		fi
	done

	( cd "$WORK/serve" && exec python3 -m http.server "$ZIP_PORT" --bind 127.0.0.1 ) \
		>"$WORK/zipserver.log" 2>&1 &
	ZIP_PID=$!
	trap 'if [ -n "$ZIP_PID" ]; then kill "$ZIP_PID" 2>/dev/null || true; fi' EXIT

	for _ in $(seq 1 50); do
		curl -sf -o /dev/null --max-time 2 "http://127.0.0.1:$ZIP_PORT/$WP_ZIP_NAME" && break
		kill -0 "$ZIP_PID" 2>/dev/null || break   # server died; stop waiting
		sleep 0.2
	done

	curl -sf -o /dev/null --max-time 5 "http://127.0.0.1:$ZIP_PORT/$WP_ZIP_NAME" || {
		echo "--- zipserver.log ---" >&2
		tail -20 "$WORK/zipserver.log" >&2 || true
		die "the local zip server on port $ZIP_PORT did not come up"
	}
fi

echo "==> serving $WP_ZIP_NAME on 127.0.0.1:$ZIP_PORT"
echo "==> starting WordPress on 0.0.0.0:$PORT (PHP $PHP_VERSION)"
echo

cd "$WORK"
exec node node_modules/@wp-playground/cli/wp-playground.js server \
	--port "$PORT" \
	--php "$PHP_VERSION" \
	--wp "http://127.0.0.1:$ZIP_PORT/$WP_ZIP_NAME" \
	--login \
	--define-bool WP_DEBUG true \
	--define-bool WP_DEBUG_LOG true \
	--define-bool WP_DEBUG_DISPLAY false \
	--mount "$REPO/igbz-suite:/wordpress/wp-content/plugins/igbz-suite" \
	--mount "$WORK/woocommerce:/wordpress/wp-content/plugins/woocommerce" \
	--mount "$WORK/mu:/wordpress/wp-content/mu-plugins"
# NOTE (1406/05/31): Elementor + Hello Elementor removed to keep repo lightweight (~108 MB saved).
# Mount lines for elementor/hello-elementor intentionally omitted. If needed later, re-add:
#   --mount "$WORK/plugins/elementor:/wordpress/wp-content/plugins/elementor" \
#   --mount "$WORK/themes/hello-elementor:/wordpress/wp-content/themes/hello-elementor"
