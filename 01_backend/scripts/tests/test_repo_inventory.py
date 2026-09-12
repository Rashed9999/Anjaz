"""The inventory is lexical evidence; regressions must not invent broken APIs."""

import importlib.util
from pathlib import Path
import re
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "repo-inventory.py"
SPEC = importlib.util.spec_from_file_location("repo_inventory", SCRIPT)
inventory = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = inventory
SPEC.loader.exec_module(inventory)


class RepoInventoryTest(unittest.TestCase):
    def test_comments_do_not_create_navigation_references(self):
        source = "// Get.to(() => OldScreen());\n/* OldScreen() */ const NewScreen();"
        self.assertNotIn("OldScreen", [t.value for t in inventory.tokens(source)])

    def test_nested_dart_interpolation_is_opaque(self):
        source = "'/reports/${(r.body['meta'] as Map? ?? {})['export_ulid'] ?? ''}/status'"
        self.assertEqual("/reports/{dynamic}/status", inventory.evaluate(inventory.tokens(source), {}))

    def test_known_constant_and_dynamic_suffix(self):
        self.assertEqual("/api/v1/branches{dynamic}", inventory.interpolate("$base/branches$query", {"base": "/api/v1"}))

    def test_php_implicit_public_method(self):
        source = "function index() {} public function store() {} protected function secret() {} private static function hidden() {}"
        self.assertEqual(["index", "store"], inventory.public_methods(source))

    def test_uri_parameters_normalized_without_query(self):
        self.assertEqual("/api/items/{}", inventory.normalize_uri("/api/items/{item}?page=2"))

    def test_route_groups_aliases_and_invokable_controller(self):
        source = r"""<?php
use App\Http\Controllers\Api\ExampleController as Example;
use App\Http\Controllers\Api\SnapshotController;
Route::prefix('api')->middleware(['api', 'auth:api'])->name('api.')->group(function () {
    $controller = Example::class;
    Route::prefix('items')->group(function () use ($controller) {
        Route::get('/{id}', [$controller, 'show'])->name('show');
        Route::post('/', [$controller, 'store'])->middleware('capability:items');
    });
    Route::get('/snapshot', SnapshotController::class)->name('snapshot');
});
"""
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "routes.php").write_text(source)
            scanner = inventory.Routes(root)
            scanner.read("routes.php")
        self.assertEqual(3, len(scanner.rows))
        show, store, snapshot = scanner.rows
        self.assertEqual("/api/items/{id}", show["uri"])
        self.assertEqual("api.show", show["name"])
        self.assertEqual(r"App\Http\Controllers\Api\ExampleController", show["controller"])
        self.assertEqual(["api", "auth:api"], show["middleware"])
        self.assertEqual(["api", "auth:api", "capability:items"], store["middleware"])
        self.assertEqual("__invoke", snapshot["handler"])
        self.assertEqual("/api/snapshot", snapshot["uri"])

    def test_multi_method_route(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "routes.php").write_text("<?php Route::match(['get', 'post'], '/check', function () {});")
            scanner = inventory.Routes(root)
            scanner.read("routes.php")
        self.assertEqual(["GET", "POST"], [r["method"] for r in scanner.rows])


class RestoredSourceContractTest(unittest.TestCase):
    """Static regressions for the repaired files, not UI/HTTP execution."""

    @classmethod
    def setUpClass(cls):
        cls.root = SCRIPT.parents[2]
        scanner = inventory.Routes(cls.root)
        scanner.read("01_backend/routes/api/amial.php", {
            "prefix": "api/v1/amial", "name": "", "middleware": ["api"], "namespace": "",
        })
        cls.routes = scanner.rows

    def test_all_three_operations_endpoints_are_registered_and_authenticated(self):
        rows = [r for r in self.routes if r["controller"] and r["controller"].endswith("MerchantOperationsCenterController")]
        self.assertEqual({("GET", "summary"), ("GET", "roles"), ("POST", "createRole")},
                         {(r["method"], r["handler"]) for r in rows})
        for row in rows:
            self.assertIn("auth:api", row["middleware"])
            self.assertIn("amial.pos-device", row["middleware"])
            self.assertTrue(row["uri"].startswith("/api/v1/amial/merchant/operations-center"))
        write = next(r for r in rows if r["method"] == "POST")
        self.assertIn("amial.rate-limit:merchant_role_create,20,1", write["middleware"])

    def test_owner_entrypoint_is_present_without_new_capability(self):
        source = (self.root / "02_flutter_app/lib/features/merchant/screens/merchant_services_hub_screen.dart").read_text()
        self.assertRegex(source, r"if \(access\.isMerchantOwner\)[\s\S]{0,1000}MerchantOperationsCenterScreen\(\)")
        self.assertNotIn("access.has('operations_center')", source)

    def test_restored_service_codes_exist_in_backend(self):
        hub = (self.root / "02_flutter_app/lib/features/merchant/screens/merchant_services_hub_screen.dart").read_text()
        constants = (self.root / "01_backend/app/Support/Access/AccessConstants.php").read_text()
        codes = set(re.findall(r"_Svc\('([a-z0-9_]+)'", hub))
        known = set(re.findall(r"const F_\w+ = '([a-z0-9_]+)'", constants))
        self.assertGreaterEqual(len(codes), 20)
        self.assertTrue(codes <= known, codes - known)


if __name__ == "__main__":
    unittest.main()
