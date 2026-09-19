"""Counterexamples for the gate: damaged inputs must fail, not be skipped."""

import importlib.util
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "source-integrity.py"
SPEC = importlib.util.spec_from_file_location("source_integrity", SCRIPT)
gate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(gate)


class SourceIntegrityTest(unittest.TestCase):
    def test_normal_php(self):
        self.assertEqual([], gate.validate_source("app/Example.php", b"<?php\nreturn [];\n"))

    def test_inline_text_is_not_php(self):
        self.assertTrue(gate.validate_source("routes/api.php", b"this is not PHP"))

    def test_binary_php_rejected_before_lint(self):
        self.assertTrue(gate.validate_source("routes/api.php", b"Y\xaa\xe7\x8a"))

    def test_utf8_binary_controls_rejected(self):
        self.assertTrue(gate.validate_source("screen.dart", b"import 'x';\x00"))

    def test_php_bom_cannot_emit_response_bytes(self):
        self.assertTrue(gate.validate_source("routes/api.php", b"\xef\xbb\xbf<?php\n"))

    def test_blade_is_not_required_to_start_with_php(self):
        self.assertEqual([], gate.validate_source("page.blade.php", b"<div>{{ $name }}</div>"))

    def test_valid_arabic_catalogue(self):
        self.assertEqual([], gate.validate_source(
            "02_flutter_app/assets/language/ar.json", '{"name":"أميال"}'.encode()))

    def test_shell_cannot_replace_json(self):
        self.assertTrue(gate.validate_source("assets/language/ar.json", b"#!/bin/bash\necho ok"))

    def test_duplicate_json_keys_rejected(self):
        self.assertTrue(gate.validate_source("x.json", b'{"a":"first","a":"second"}'))

    def test_empty_catalogue_rejected(self):
        self.assertTrue(gate.validate_source("02_flutter_app/assets/language/ar.json", b"{}"))

    def test_array_catalogue_rejected(self):
        self.assertTrue(gate.validate_source("02_flutter_app/assets/language/en.json", b"[]"))

    def test_shebang_loss_rejected(self):
        self.assertTrue(gate.validate_source("01_backend/scripts/verify.sh", b"echo ok"))

    def test_merge_marker_rejected(self):
        self.assertTrue(gate.validate_source("x.dart", b"<<<<<<< HEAD\nleft\n=======\nright\n>>>>>>> new"))


if __name__ == "__main__":
    unittest.main()
