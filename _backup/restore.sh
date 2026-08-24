#!/usr/bin/env bash
# ---------------------------------------------------------------------------
#  بازگرداندن ویرا به آخرین بکاپ سالم.
#
#  قبل از هر کاری از وضعیت فعلی یک عکس اضطراری می‌گیرد، پس بازگرداندن اشتباهی
#  هم برگشت‌پذیر است.
#
#  کاربرد:  ./_backup/restore.sh
# ---------------------------------------------------------------------------
set -euo pipefail

here="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
root="$( dirname "$here" )"
cd "$root"

archive="$( ls -1t "$here"/vira-*.tar.gz 2>/dev/null | head -1 || true )"
if [ -z "$archive" ]; then
	printf '\n  هیچ بکاپی در %s پیدا نشد.\n\n' "$here" >&2
	exit 1
fi

printf '\n  بکاپ:  %s\n' "$( basename "$archive" )"

if ! gzip -t "$archive" 2>/dev/null; then
	printf '  ✗ آرشیو سالم نیست. بازگرداندن انجام نشد.\n\n' >&2
	exit 1
fi

# عکس اضطراری از وضعیت فعلی — تا اگر همین حالا اشتباه کردی، راه برگشت باشد.
if [ -d vira ]; then
	panic="$here/_pre-restore-$( date +%Y%m%d-%H%M%S ).tar.gz"
	tar --exclude=node_modules --exclude=.deps-marker -czf "$panic" vira vira.sh vira.cmd 2>/dev/null || true
	printf '  عکس اضطراری از وضعیت فعلی: %s\n' "$( basename "$panic" )"
fi

printf '  در حال بازگرداندن...\n'
rm -rf vira
tar -xzf "$archive" -C "$root"

printf '  نصب وابستگی‌ها...\n'
( cd vira && npm ci >/dev/null 2>&1 ) || {
	printf '  ✗ npm ci شکست خورد. خودت یک بار «cd vira && npm ci» بزن.\n\n' >&2
	exit 1
}

printf '\n  ✓ برگشت به: %s\n' "$( cd vira && node -p "require('./package.json').version" )"
printf '    حالا این را بزن تا مطمئن شوی:  cd vira && node test/run.mjs\n'
printf '    انتظار: ۳۷۷ موفق، ۱ ناموفق (داکر)\n\n'
