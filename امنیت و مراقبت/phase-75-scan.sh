#!/usr/bin/env bash
# فاز ۷۵ — ممیزی امنیتی نهاییِ تکرارپذیر روی فایل‌های ردیابی‌شدهٔ گیت.
# خروجی: report-phase75.txt (خام) + جمع‌بندی برای SECURITY-AUDIT-FINAL.md
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"
OUT="امنیت و مراقبت/phase-75-raw.txt"
: > "$OUT"

phpfiles=$(git ls-files 'igbz-suite/**/*.php' 'igbz-suite/*.php' | grep -v '/tests/')

say() { printf '%s\n' "$*" | tee -a "$OUT"; }

say "== 1) permission_callback روی هر مسیر REST =="
# الگوی محصول: route('GET', cb, \$auth) که BaseController جای permission_callback می‌گذارد.
# شکاف واقعی = فراخوانی route() با کمتر از سه آرگومان (بدون \$auth صریح) یا permission خالی.
total=0; without_auth=0
for f in $phpfiles; do
  while IFS= read -r line; do
    total=$((total+1))
    if ! echo "$line" | grep -qE ',[[:space:]]*(\$[A-Za-z_]+|\[[^]]*\])[[:space:]]*[,)]'; then
      without_auth=$((without_auth+1)); say "  GAP ${f}:${line}"
    fi
  done < <(grep -nF 'this->route(' "$f" || true)
done
say "  route_calls=$total without_explicit_permission=$without_auth (پیش‌فرض __return_true یعنی عمومی — هر GAP باید عمداً عمومی باشد)"

say "== 2) توابع خطرناک (eval/exec/shell/unserialize بدون گارد/proc) =="
hits=0
while IFS= read -r line; do
  f=${line%%:*}
  case "$f" in */tests/*) continue;; esac
  hits=$((hits+1)); say "  HIT $line"
done < <(grep -nE "\b(eval|exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(" $phpfiles || true)
say "  dangerous_calls=$hits"

say "== 3) unserialize / base64_decode / create_function =="
while IFS= read -r line; do
  f=${line%%:*}; case "$f" in */tests/*) continue;; esac
  say "  HIT $line"
done < <(grep -nE "\b(unserialize|create_function)\s*\(|base64_decode\s*\(" $phpfiles | grep -v "allowed_classes" | grep -vE "Crypto|Base64" || true)

say "== 4) ورودی خام \$_GET/\$_POST/\$_REQUEST/\$_COOKIE بدون wp_unslash|sanitize|absint|filter_input =="
while IFS= read -r line; do
  f=${line%%:*}; case "$f" in */tests/*) continue;; esac
  if ! echo "$line" | grep -qE "wp_unslash|sanitize_|absint|intval|filter_input|check_admin_referer|rest_"; then
    say "  HIT $line"
  fi
done < <(grep -nE "\$_(GET|POST|REQUEST|COOKIE)\[" $phpfiles | grep -v "isset" || true)

say "== 5) اسکن سیکرت در کل گیت (نمونه‌های الگو) =="
while IFS= read -r line; do
  say "  CANDIDATE $line"
done < <(git grep -nE "(AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{36}|sk-[A-Za-z0-9]{20}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|xox[baprs]-)" -- . ':!vira' 2>/dev/null | grep -vE "\.md:|/tests/|\.cjs|EXAMPLE|PLACEHOLDER|تست|نمونه" || true)
say "  (الگوهای رمز عبور در entrypoint/runbook عمداً از متغیر محیطی می‌آیند — بررسی دستی زیر)"

say "== 6) رشته‌ای که شبیه مقدار واقعی سیکرت است (نه نام کلید) =="
git grep -nE "(api_key|secret|token|password)['\"]?\s*(=>|:|=)\s*['\"][A-Za-z0-9+/]{24,}['\"]" -- 'igbz-suite/**/*.php' ':!igbz-suite/tests/' 2>/dev/null | grep -v "=> ''" >> "$OUT" || true
say "  (خالی = سبز)"

say "== 7) دسترسی فایل/دایرکتوری حساس: wp-config/debug.log در مخزن؟ =="
git ls-files | grep -E "(wp-config\.php|debug\.log|\.env$|\.mysql_history|id_rsa)" | tee -a "$OUT"
say "  (خالی = سبز)"

say "== 8) exit/ABSPATH سراسری در فایل‌های محصول (نمونه‌گیری بارگذاری مستقیم) =="
no_guard=0
for f in $phpfiles; do
  case "$f" in */tests/*) continue;; esac
  if ! grep -qE "defined\( 'ABSPATH' \)|defined\( 'WP_UNINSTALL_PLUGIN' \)" "$f"; then no_guard=$((no_guard+1)); say "  GAP $f"; fi
done
say "  files_without_direct_access_guard=$no_guard"

say "== 9) register_setting بدون sanitize_callback =="
for f in $phpfiles; do
  n=$(grep -c "register_setting" "$f" || true); p=$(grep -c "sanitize" "$f" || true)
  [ "${n:-0}" -gt 0 ] && [ "$n" -gt "$p" ] && say "  CHECK $f register=$n sanitize_mentions=$p"
done
say "  (CHECK = بازبینی دستی)"

say "== 10) حلقهٔ SSRF: هر wp_remote_* از UrlGuard می‌گذرد؟ =="
gu=""; for f in $phpfiles; do grep -l "wp_remote_" "$f" >/dev/null 2>&1 || continue; grep -q "UrlGuard\|Http" "$f" || gu="$gu $f"; done
say "  files_with_raw_remote: ${gu:-none}"

echo "---- raw report: $OUT"
