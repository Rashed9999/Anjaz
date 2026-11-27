import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "apply_recipe.py"
spec = importlib.util.spec_from_file_location("saher_apply_recipe", SCRIPT)
saher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(saher)

GOOD_REF = "SAHER-SECUR-0033"
RECIPE = "merchant-note-rbac-0033"
TARGET = "01_backend/routes/admin/amial.php"
OLD = "Route::post('/note', [$mc, 'addNote'])->name('note');"
NEW = "Route::post('/note', [$mc, 'addNote'])->middleware('platform:platform.customers.notes.create')->name('note');"


class ApprovedRecipeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.target = self.root / TARGET
        self.target.parent.mkdir(parents=True)
        self.target.write_text("<?php\n" + OLD + "\n", encoding="utf-8")

        self.manifest = self.root / saher.ROOT_RELATIVE_MANIFEST
        self.manifest.parent.mkdir(parents=True)
        self.write_manifest(TARGET, OLD, NEW)

    def tearDown(self):
        self.tmp.cleanup()

    def write_manifest(self, path, before, after):
        self.manifest.write_text(json.dumps({
            "schema_version": 1,
            "recipes": [{
                "id": RECIPE,
                "file": path,
                "before": before,
                "after": after,
                "rule_id": "SAHER.GUARD.WRITE_BEHIND_READ_PERMISSION",
                "security_tests": [],
            }]
        }), encoding="utf-8")

    def test_preview_is_read_only_and_has_a_real_diff(self):
        result = saher.preview_or_apply(self.root, RECIPE, GOOD_REF, apply=False)
        self.assertTrue(result["changed"])
        self.assertIn("+Route::", result["diff"])
        self.assertIn(OLD, self.target.read_text(encoding="utf-8"))

    def test_exact_patch_is_applied_once_then_becomes_no_op(self):
        result = saher.preview_or_apply(self.root, RECIPE, GOOD_REF, apply=True)
        self.assertTrue(result["changed"])
        self.assertIn(NEW, self.target.read_text(encoding="utf-8"))
        result = saher.preview_or_apply(self.root, RECIPE, GOOD_REF, apply=True)
        self.assertEqual("ALREADY_APPLIED", result["reason"])
        self.assertFalse(result["changed"])

    def test_source_mismatch_fails_without_changing_file(self):
        self.target.write_text("<?php\n// unrelated\n", encoding="utf-8")
        with self.assertRaises(saher.RecipeError):
            saher.preview_or_apply(self.root, RECIPE, GOOD_REF, apply=True)
        self.assertIn("unrelated", self.target.read_text(encoding="utf-8"))

    def test_duplicated_match_fails_closed(self):
        self.target.write_text("<?php\n" + OLD + "\n" + OLD + "\n", encoding="utf-8")
        with self.assertRaises(saher.RecipeError):
            saher.preview_or_apply(self.root, RECIPE, GOOD_REF, apply=True)
        self.assertEqual(2, self.target.read_text(encoding="utf-8").count(OLD))

    def test_recipe_cannot_escape_the_allowlisted_file(self):
        self.write_manifest("../.env", OLD, NEW)
        with self.assertRaises(saher.RecipeError):
            saher.preview_or_apply(self.root, RECIPE, GOOD_REF, apply=True)

    def test_invalid_reference_is_never_used_as_patch_or_command(self):
        with self.assertRaises(saher.RecipeError):
            saher.preview_or_apply(self.root, RECIPE, "SAHER-0033;rm -rf /", apply=True)

    def test_unknown_recipe_is_refused(self):
        with self.assertRaises(saher.RecipeError):
            saher.preview_or_apply(self.root, "unreviewed-test", GOOD_REF, apply=True)

    def test_existing_symlink_file_is_rejected(self):
        self.target.unlink()
        self.target.symlink_to(self.manifest)
        with self.assertRaises(saher.RecipeError):
            saher.preview_or_apply(self.root, RECIPE, GOOD_REF, apply=True)


if __name__ == "__main__":
    unittest.main()
