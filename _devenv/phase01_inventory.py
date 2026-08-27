#!/usr/bin/env python3
"""Rebuild the deterministic Phase 01 repository inventory.

This tool only reads the IGBZ-WP product and documentation trees. It deliberately
never traverses vira/. Run with --check to fail when PHASE-01-INVENTORY.json is stale.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "PHASE-01-INVENTORY.json"
SRC = ROOT / "igbz-suite" / "src"
TESTS = ROOT / "igbz-suite" / "tests"


def relative(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def aggregate_hash(paths: list[Path]) -> str:
    digest = hashlib.sha256()
    for path in sorted(paths):
        digest.update(relative(path).encode("utf-8"))
        digest.update(b"\0")
        digest.update(path.read_bytes())
        digest.update(b"\0")
    return digest.hexdigest()


def setting_inventory() -> dict[str, object]:
    settings_page = ROOT / "igbz-suite/src/Support/Admin/SettingsPage.php"
    settings_class = ROOT / "igbz-suite/src/Support/Settings.php"
    page = text(settings_page)
    matches = list(re.finditer(r"'key'\s*=>\s*'([^']+)'", page))
    fields: list[dict[str, str]] = []
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(page)
        block = page[match.start() : end]
        type_match = re.search(r"'type'\s*=>\s*'([^']+)'", block)
        fields.append(
            {
                "key": match.group(1),
                "type": type_match.group(1) if type_match else "text",
            }
        )

    settings_source = text(settings_class)
    secret_block = re.search(
        r"private\s+const\s+SECRETS\s*=\s*\[(.*?)\n\s*\];",
        settings_source,
        re.S,
    )
    if not secret_block:
        raise RuntimeError("Settings::SECRETS could not be parsed")
    secrets = sorted(set(re.findall(r"'([^']+)'", secret_block.group(1))))
    passwords = sorted({field["key"] for field in fields if field["type"] == "password"})
    counts = Counter(field["key"] for field in fields)

    return {
        "source": relative(settings_page),
        "field_entries": len(fields),
        "unique_field_keys": len(counts),
        "duplicate_field_keys": sorted(key for key, count in counts.items() if count > 1),
        "password_fields": passwords,
        "password_field_count": len(passwords),
        "secret_registry_source": relative(settings_class),
        "secret_registry": secrets,
        "secret_registry_count": len(secrets),
        "password_secret_overlap": sorted(set(passwords) & set(secrets)),
        "password_secret_overlap_count": len(set(passwords) & set(secrets)),
        "password_missing_from_secret_registry": sorted(set(passwords) - set(secrets)),
        "password_missing_from_secret_registry_count": len(set(passwords) - set(secrets)),
        "registry_without_password_field": sorted(set(secrets) - set(passwords)),
    }


def rest_inventory(source_files: list[Path]) -> dict[str, object]:
    rows = []
    total = 0
    for path in source_files:
        source = text(path)
        registrations = len(re.findall(r"\bregister_rest_route\s*\(", source))
        if registrations:
            total += registrations
            rows.append(
                {
                    "file": relative(path),
                    "registration_calls": registrations,
                    "direct_permission_callback_mentions": source.count("permission_callback"),
                }
            )
    return {
        "registration_calls": total,
        "files_with_registration": len(rows),
        "files": rows,
        "note": "RestApi controllers normally obtain permission_callback through BaseController::route(); zero direct mentions is not evidence of a missing callback.",
    }


def database_inventory() -> dict[str, object]:
    schema = ROOT / "igbz-suite/src/Support/Schema.php"
    plugin = ROOT / "igbz-suite/igbz-suite.php"
    schema_source = text(schema)
    plugin_source = text(plugin)
    tables = re.findall(r'\$sql\[\]\s*=\s*"CREATE TABLE\s+\{\$p\}([A-Za-z0-9_]+)', schema_source)
    version_match = re.search(r"IGBZ_DB_VERSION\s*',\s*([0-9]+)", plugin_source)
    if not version_match:
        raise RuntimeError("IGBZ_DB_VERSION could not be parsed")
    return {
        "version": int(version_match.group(1)),
        "table_count": len(tables),
        "tables": tables,
        "schema_source": relative(schema),
        "version_source": relative(plugin),
    }


def access_inventory() -> dict[str, object]:
    path = ROOT / "igbz-suite/src/Support/Capabilities.php"
    source = text(path)
    roles = sorted(set(re.findall(r"'((?:igbz_tenant_owner|igbz_tenant_staff|igbz_instructor))'", source)))
    capabilities = sorted(set(re.findall(r"'(igbz_manage_[a-z_]+)'", source)))
    return {
        "source": relative(path),
        "roles": roles,
        "role_count": len(roles),
        "capabilities": capabilities,
        "capability_count": len(capabilities),
    }


def shortcode_inventory(source_files: list[Path]) -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    pattern = re.compile(r"\badd_shortcode\s*\(\s*'([^']+)'")
    for path in source_files:
        for name in pattern.findall(text(path)):
            rows.append({"name": name, "file": relative(path)})
    return rows


def cron_inventory(source_files: list[Path]) -> dict[str, object]:
    cron_source = ROOT / "igbz-suite/src/Support/Cron.php"
    hooks = sorted(set(re.findall(r"'(igbz_cron_[a-z_]+)'", text(cron_source))))
    listeners: list[dict[str, str]] = []
    for path in source_files:
        for line_number, line in enumerate(text(path).splitlines(), start=1):
            if "add_action" not in line:
                continue
            constant = re.search(r"Cron::(HOOK_(?:FIVE_MINUTES|HOURLY|DAILY))", line)
            literal = re.search(r"'(igbz_cron_(?:five_minutes|hourly|daily))'", line)
            own = re.search(r"self::(HOOK_(?:FIVE_MINUTES|HOURLY|DAILY))", line)
            if not (constant or literal or own):
                continue
            token = (constant or literal or own).group(1)
            listeners.append(
                {
                    "hook_reference": token,
                    "file": relative(path),
                    "line": str(line_number),
                    "registration": line.strip(),
                }
            )
    return {
        "source": relative(cron_source),
        "central_hooks": hooks,
        "central_hook_count": len(hooks),
        "listeners": listeners,
        "action_scheduler_calls_in_source": sum(
            len(re.findall(r"\bas_(?:enqueue|schedule|unschedule|next_scheduled_action)[a-z_]*\s*\(", text(path)))
            for path in source_files
        ),
    }


def tests_inventory() -> dict[str, object]:
    runner = TESTS / "run.php"
    runner_source = text(runner)
    case_block = re.search(r"\$cases\s*=\s*\[(.*?)\];", runner_source, re.S)
    if not case_block:
        raise RuntimeError("tests/run.php case list could not be parsed")
    cases = re.findall(r"'([A-Za-z0-9_]+)'", case_block.group(1))
    test_files = sorted(TESTS.glob("*.php"))
    return {
        "source": relative(runner),
        "php_file_count": len(test_files),
        "php_files": [relative(path) for path in test_files],
        "main_case_count": len(cases),
        "main_cases": cases,
        "last_recorded_baseline": {
            "assertions": 1251,
            "cases": 24,
            "php_files_linted": 245,
            "syntax_errors": 0,
        },
    }


def provider_candidates(source_files: list[Path]) -> list[str]:
    pattern = re.compile(r"(?:Provider|Gateway|Adapter|Client)(?:Interface)?\.php$")
    return [relative(path) for path in source_files if pattern.search(path.name)]


def build() -> dict[str, object]:
    source_files = sorted(SRC.rglob("*.php"))
    all_product_php = sorted((ROOT / "igbz-suite").rglob("*.php"))
    design_docs = sorted(ROOT.glob("DESIGN-*.md"))
    input_docs = sorted((ROOT / "ِDoc").glob("*"))
    module_root = SRC / "Modules"
    module_counts = {
        module.name: len(list(module.rglob("*.php")))
        for module in sorted(module_root.iterdir())
        if module.is_dir()
    }
    support_count = len(list((SRC / "Support").rglob("*.php")))

    return {
        "format_version": 1,
        "scope": {
            "repository": "paymanshafayan/IGBZ-WP",
            "product_root": "igbz-suite",
            "excluded": ["vira/"],
            "source_snapshot": "arena/01a0435a-igbz-wp from b160311; product code unchanged during Phase 01",
        },
        "documents": {
            "design_document_count": len(design_docs),
            "design_documents": [relative(path) for path in design_docs],
            "input_document_count": len(input_docs),
            "input_documents": [relative(path) for path in input_docs],
            "historical_review": "REVIEW-IGBZ-NopCommerce.md",
            "classification": "Files under ِDoc/ and the nopCommerce review are historical/proposal inputs, not automatically accepted WordPress requirements.",
        },
        "code": {
            "source_php_count": len(source_files),
            "source_php_sha256": aggregate_hash(source_files),
            "source_php_files": [relative(path) for path in source_files],
            "module_php_counts": module_counts,
            "support_php_count": support_count,
            "all_plugin_php_count": len(all_product_php),
        },
        "database": database_inventory(),
        "rest_api": rest_inventory(source_files),
        "settings_and_secrets": setting_inventory(),
        "access_control": access_inventory(),
        "shortcodes": {
            "count": len(shortcode_inventory(source_files)),
            "items": shortcode_inventory(source_files),
        },
        "cron": cron_inventory(source_files),
        "provider_candidates": {
            "count": len(provider_candidates(source_files)),
            "files": provider_candidates(source_files),
            "note": "Filename-based candidate inventory only; the active provider list is a Phase 02 decision and providers must not be aggregated into one implementation phase.",
        },
        "tests": tests_inventory(),
    }


def rendered() -> str:
    return json.dumps(build(), ensure_ascii=False, indent=2, sort_keys=False) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true", help="fail if the committed JSON is stale")
    args = parser.parse_args()
    expected = rendered()
    if args.check:
        actual = OUTPUT.read_text(encoding="utf-8") if OUTPUT.exists() else ""
        if actual != expected:
            print(f"stale inventory: {relative(OUTPUT)}", file=sys.stderr)
            return 1
        print(f"inventory is current: {relative(OUTPUT)}")
        return 0
    OUTPUT.write_text(expected, encoding="utf-8")
    print(f"wrote {relative(OUTPUT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
