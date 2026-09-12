#!/usr/bin/env python3
"""AMIAL-SOURCE-001: fail before interpreting damaged source as valid code.

Read-only, standard-library-only check. In particular PHP's syntax checker
accepts arbitrary inline bytes if a damaged file has lost its opening tag.
This gate complements language compilers; it does not replace them.
"""

import argparse
import json
from pathlib import Path
import re
import subprocess
import sys


ROOT = Path(__file__).resolve().parents[2]
TEXT_SUFFIXES = {
    ".php", ".dart", ".sh", ".py", ".js", ".mjs", ".cjs",
    ".json", ".yaml", ".yml", ".sql", ".xml", ".css", ".html",
}
REQUIRED = (
    "01_backend/routes/api/amial.php",
    "01_backend/scripts/verify.sh",
    "02_flutter_app/assets/language/ar.json",
    "02_flutter_app/assets/language/en.json",
    "02_flutter_app/lib/features/merchant/screens/merchant_services_hub_screen.dart",
)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            # Do not echo source values, which could contain secrets.
            raise ValueError("duplicate JSON object key")
        result[key] = value
    return result


def validate_source(relative: str, data: bytes) -> list[str]:
    """Return rule names, never source contents or secret values."""
    try:
        text = data.decode("utf-8", errors="strict")
    except UnicodeDecodeError:
        return ["invalid UTF-8 (binary/corrupt source)"]

    errors = []
    if re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f]", text):
        errors.append("unexpected binary control characters")
    if re.search(r"^(?:<<<<<<< |=======\s*$|>>>>>>> )", text, re.MULTILINE):
        errors.append("unresolved merge marker")

    path = Path(relative)
    pure_php = path.suffix == ".php" and not relative.endswith(".blade.php")
    if pure_php and not re.match(r"^<\?php(?:\s|$)", text):
        errors.append("missing PHP opening tag at byte zero")
    if relative == "01_backend/scripts/verify.sh" and not text.startswith("#!/"):
        errors.append("verification entrypoint lost its shebang")
    if path.suffix == ".json":
        try:
            value = json.loads(text, object_pairs_hook=unique_object)
            if relative.startswith("02_flutter_app/assets/language/"):
                if not isinstance(value, dict) or not value:
                    errors.append("translation catalogue must be a nonempty object")
                elif not all(isinstance(v, str) for v in value.values()):
                    errors.append("translation values must be strings")
        except (ValueError, json.JSONDecodeError):
            errors.append("invalid JSON or duplicate object keys")
    return errors


def inspect(root: Path) -> tuple[int, list[tuple[str, list[str]]]]:
    result = subprocess.run(
        ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"],
        cwd=root, check=True, capture_output=True,
    )
    paths = sorted(set(result.stdout.decode("utf-8").split("\0")) - {""})
    failures = []
    checked = 0
    for relative in paths:
        path = root / relative
        if path.suffix not in TEXT_SUFFIXES or not path.is_file():
            continue
        checked += 1
        errors = validate_source(relative, path.read_bytes())
        if errors:
            failures.append((relative, errors))
    for relative in REQUIRED:
        if not (root / relative).is_file():
            failures.append((relative, ["required source file is missing"]))
    if checked == 0:
        failures.append(("repository", ["no source files inspected"]))
    return checked, failures


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=ROOT)
    args = parser.parse_args()
    try:
        checked, failures = inspect(args.root.resolve())
    except (OSError, subprocess.CalledProcessError, UnicodeDecodeError):
        print("SOURCE INTEGRITY: could not inspect the repository", file=sys.stderr)
        return 2
    for relative, errors in failures:
        print(f"FAIL {relative}: {'; '.join(errors)}")
    print(f"SOURCE INTEGRITY: checked={checked}, failed_files={len(failures)}")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
