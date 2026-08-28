#!/usr/bin/env bash
#
# Run the unit tests and the PHP syntax check. Needs no running site.
#
# Usage:  bash _devenv/test.sh [--lint-only] [--tests-only]
#
set -Eeuo pipefail

DEVENV="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$DEVENV/.." && pwd)"
WORK="$DEVENV/.work"

die() { printf '\n\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$WORK/node_modules/@php-wasm/cli" ] || die "environment not built — run: bash _devenv/setup.sh"

RUN_TESTS=1; RUN_LINT=1
case "${1:-}" in
	--lint-only)  RUN_TESTS=0 ;;
	--tests-only) RUN_LINT=0 ;;
esac

PHP_CLI="$WORK/node_modules/@php-wasm/cli/main.js"
[ -f "$PHP_CLI" ] || PHP_CLI="$(find "$WORK/node_modules/@php-wasm/cli" -maxdepth 1 -name '*.js' | head -1)"
[ -f "$PHP_CLI" ] || die "could not find the php-wasm CLI entry point"

status=0

if [ "$RUN_TESTS" = "1" ]; then
	echo "==> unit tests"
	node "$PHP_CLI" "$REPO/igbz-suite/tests/run.php" || status=1
	echo
fi

if [ "$RUN_TESTS" = "1" ]; then
	echo "==> drift guard (PHASE-01-INVENTORY.json vs code)"
	if command -v python3 >/dev/null 2>&1; then
		python3 "$REPO/_devenv/phase01_inventory.py" --check || status=1
	else
		echo "  skipped: python3 not available"
	fi
	echo
fi

if [ "$RUN_LINT" = "1" ]; then
	echo "==> php syntax check"
	# Written to a temp file rather than the repo, so `git status` stays clean.
	LINTER="$WORK/lint.php"
	cat > "$LINTER" <<'PHP'
<?php
$root = $argv[1] ?? '.';
$errors = $count = 0;
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( $file->getExtension() !== 'php' ) { continue; }
	$count++;
	$src = file_get_contents( $file->getPathname() );
	try {
		// TOKEN_PARSE makes the tokenizer enforce real syntax rules without executing anything.
		token_get_all( $src, TOKEN_PARSE );
	} catch ( \ParseError $e ) {
		$errors++;
		printf( "  %s\n    %s on line %d\n", $file->getPathname(), $e->getMessage(), $e->getLine() );
	}
}
printf( "%d files, %d errors\n", $count, $errors );
exit( $errors > 0 ? 1 : 0 );
PHP
	node "$PHP_CLI" "$LINTER" "$REPO/igbz-suite" || status=1
fi

exit $status
