#!/usr/bin/env python3
"""Apply only signed-off, exact-text Saher recipes inside a disposable checkout.

Not a general-purpose AI code editor. User-provided finding content is metadata,
never a shell command, file path, permission expression, or patch.
"""
from __future__ import annotations

import argparse
import difflib
import json
import os
from pathlib import Path
import re
import stat
import sys

ROOT_RELATIVE_MANIFEST = ".github/saher/approved-recipes.json"
ALLOWED_FILES = {"01_backend/routes/admin/amial.php"}
RECIPE_ID = re.compile(r"[a-z][a-z0-9-]{5,79}\Z")
FINDING_REF = re.compile(r"SAHER-[A-Z0-9-]{4,60}\Z")


class RecipeError(Exception):
    pass


def preview_or_apply(root: Path, recipe_id: str, finding_ref: str, apply: bool = False) -> dict:
    root = root.resolve(strict=True)
    if not RECIPE_ID.fullmatch(recipe_id):
        raise RecipeError("Invalid recipe ID")
    if not FINDING_REF.fullmatch(finding_ref):
        raise RecipeError("Invalid Saher finding reference")

    manifest = root / ROOT_RELATIVE_MANIFEST
    if manifest.is_symlink() or not manifest.is_file():
        raise RecipeError("Approved recipe manifest is missing or a symlink")

    data = json.loads(manifest.read_text(encoding="utf-8"))
    if data.get("schema_version") != 1 or not isinstance(data.get("recipes"), list):
        raise RecipeError("Unsupported approved recipe schema")
    matches = [r for r in data["recipes"] if isinstance(r, dict) and r.get("id") == recipe_id]
    if len(matches) != 1:
        raise RecipeError("Recipe is absent or ambiguous")
    recipe = matches[0]
    relative = recipe.get("file")
    if not isinstance(relative, str) or relative not in ALLOWED_FILES:
        raise RecipeError("Recipe path is not on the approved route-file allowlist")

    target = root / relative
    if target.is_symlink() or not target.is_file() or not target.resolve().is_relative_to(root):
        raise RecipeError("Unsafe or missing target path")
    before, after = recipe.get("before"), recipe.get("after")
    if (not isinstance(before, str) or not isinstance(after, str)
            or not before or not after or before == after
            or len(before) > 2000 or len(after) > 2500
            or "\r" in before or "\r" in after
            or not before.startswith("Route::")
            or not after.startswith("Route::")
            or "->middleware('platform:" not in after
            or "->name(" not in before or "->name(" not in after):
        raise RecipeError("Recipe violates patch safety preconditions")

    src = target.read_text(encoding="utf-8")
    if not src.lstrip().startswith("<?php"):
        raise RecipeError("Target no longer looks like PHP")
    if src.count(after) == 1 and before not in src:
        return {"changed": False, "reason": "ALREADY_APPLIED", "file": relative, "diff": ""}
    if src.count(before) != 1 or src.count(after) != 0:
        raise RecipeError("Source precondition failed (missing/duplicated/mixed patch); manual review required")

    updated = src.replace(before, after, 1)
    diff = "".join(difflib.unified_diff(
        src.splitlines(keepends=True), updated.splitlines(keepends=True),
        fromfile="a/" + relative, tofile="b/" + relative
    ))
    if not diff:
        raise RecipeError("Diff unexpectedly empty")

    if apply:
        # Keep file mode and only write this reviewed, allowlisted path.
        mode = stat.S_IMODE(target.stat().st_mode)
        tmp = target.with_name(target.name + ".saher-tmp")
        if tmp.exists():
            raise RecipeError("Temporary target exists; refuse to clobber")
        try:
            tmp.write_text(updated, encoding="utf-8")
            os.chmod(tmp, mode)
            tmp.replace(target)
        finally:
            if tmp.exists():
                tmp.unlink()

    return {"changed": True, "reason": "PATCH_READY", "file": relative, "diff": diff}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--recipe", required=True)
    parser.add_argument("--finding", required=True)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    try:
        result = preview_or_apply(args.root, args.recipe, args.finding, apply=args.apply)
    except (RecipeError, OSError, UnicodeError, ValueError) as exc:
        print(f"SECURITY GATE FAILED: {exc}", file=sys.stderr)
        return 2

    if result["changed"]:
        print(result["diff"])
        print("Applied approved recipe" if args.apply else "Preview only (no files changed)")
    else:
        print("Already applied; no change and no PR should be created")

    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as stream:
            stream.write(f"changed={'true' if result['changed'] else 'false'}\n")
            stream.write(f"changed_file={result['file']}\n")
            stream.write(f"outcome={result['reason']}\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
