<?php

namespace App\Http\Controllers\Merchant;

use App\Exceptions\UsageLimitExceededException;
use App\Http\Controllers\Controller;
use App\Models\CustomerCreditAccount;
use App\Models\FuelProduct;
use App\Models\FuelStation;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\Pharmacy;
use App\Models\PharmacyCustomer;
use App\Models\PharmacyProduct;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleProduct;
use App\Services\Access\EntitlementService;
use App\Services\AuditService;
use App\Services\UsageLimitService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WebBulkDataController extends Controller
{
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly WebSectorController $sectorController,
        private readonly EntitlementService $entitlements,
        private readonly UsageLimitService $usage,
        private readonly AuditService $audit,
    ) {}

    public function productTemplate(Request $request): StreamedResponse
    {
        return $this->template($request, 'products');
    }

    public function customerTemplate(Request $request): StreamedResponse
    {
        return $this->template($request, 'customers');
    }

    public function exportProducts(Request $request): StreamedResponse
    {
        return $this->export($request, 'products');
    }

    public function exportCustomers(Request $request): StreamedResponse
    {
        return $this->export($request, 'customers');
    }

    public function importProducts(Request $request): JsonResponse
    {
        return $this->import($request, 'products');
    }

    public function importCustomers(Request $request): JsonResponse
    {
        return $this->import($request, 'customers');
    }

    private function template(Request $request, string $kind): StreamedResponse
    {
        $sector = $this->sector($request);
        $schema = $this->schema($sector, $kind);
        $filename = "amial-{$sector}-{$kind}-template.csv";

        return response()->streamDownload(function () use ($schema) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $schema['columns']);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function export(Request $request, string $kind): StreamedResponse
    {
        $owner = $this->owner($request);
        $sector = $this->sector($request);
        $schema = $this->schema($sector, $kind);
        [$query, $mapper] = $this->exportQuery($owner->id, $sector, $kind, $schema['columns']);
        $filename = 'amial-' . $sector . '-' . $kind . '-' . now()->format('Ymd-His') . '.csv';

        $this->audit->record([
            'actor_type' => 'merchant',
            'actor_user_id' => $owner->id,
            'subject_type' => 'merchant_bulk_export',
            'subject_id' => $kind,
            'action' => 'MERCHANT_DATA_EXPORTED',
            'decision_code' => 'STARTED',
            'severity' => 'info',
            'context' => ['sector' => $sector, 'kind' => $kind, 'format' => 'csv'],
        ]);

        return response()->streamDownload(function () use ($query, $mapper, $schema) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $schema['columns']);

            foreach ($query->cursor() as $row) {
                $values = $mapper($row);
                fputcsv($out, array_map(fn ($v) => $this->csvSafe($v), $values));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function import(Request $request, string $kind): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }

        $owner = $this->owner($request);
        $sector = $this->sector($request);
        $schema = $this->schema($sector, $kind);
        $path = $request->file('file')->getRealPath();
        $handle = $path ? fopen($path, 'r') : false;

        if (! $handle) {
            return $this->error('CSV_OPEN_FAILED', 'تعذّر فتح ملف CSV', 422);
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);
            return $this->error('CSV_EMPTY', 'الملف فارغ أو لا يحتوي ترويسة', 422);
        }

        $headers = array_map(fn ($h) => $this->header((string) $h), $header);
        if (count(array_unique($headers)) !== count($headers)) {
            fclose($handle);
            return $this->error('CSV_DUPLICATE_COLUMNS', 'ملف CSV يحتوي أعمدة مكررة', 422);
        }

        $missing = array_values(array_diff($schema['required'], $headers));
        if ($missing) {
            fclose($handle);
            return $this->error(
                'CSV_MISSING_COLUMNS',
                'أعمدة مطلوبة غير موجودة: ' . implode(', ', $missing),
                422
            );
        }

        $unknown = array_values(array_diff($headers, $schema['columns']));
        if ($unknown) {
            fclose($handle);
            return $this->error(
                'CSV_UNKNOWN_COLUMNS',
                'أعمدة غير معروفة: ' . implode(', ', $unknown)
                    . '. نزّل القالب من اللوحة واستخدم أسماءه كما هي.',
                422
            );
        }

        // نقرأ قبل أي كتابة حتى لا نستورد نصف ملف ثم نكتشف أنه أكبر من الحد.
        $rows = [];
        $rowNumber = 1;
        while (($csv = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($this->emptyRow($csv)) continue;
            $rows[] = ['line' => $rowNumber, 'values' => $csv];
            if (count($rows) > self::MAX_ROWS) break;
        }
        fclose($handle);

        if (count($rows) > self::MAX_ROWS) {
            return $this->error(
                'CSV_TOO_MANY_ROWS',
                'الدفعة الواحدة تقبل حتى ' . self::MAX_ROWS
                    . ' صف. قسّم الملف إلى دفعات حتى تبقى الاستجابة قابلة للتتبع وإعادة المحاولة.',
                422
            );
        }

        $fileHash = hash_file('sha256', $path) ?: hash('sha256', json_encode($rows));
        $added = 0;
        $alreadyDone = 0;
        $skipped = 0;
        $reasons = [];
        $rowErrors = [];

        foreach ($rows as $entry) {
            $line = $entry['line'];
            $payload = $this->payload($headers, $entry['values'], $schema);
            $rowHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $idem = hash('sha256', implode('|', [
                $owner->id, $kind, $fileHash, $line, $rowHash,
            ]));

            try {
                $outcome = DB::transaction(function () use (
                    $request, $owner, $sector, $kind, $payload,
                    $fileHash, $line, $rowHash, $idem
                ) {
                    if (DB::table('merchant_bulk_import_rows')
                        ->where('idempotency_key', $idem)
                        ->exists()) {
                        return 'already_done';
                    }

                    // قفل ملف المنشأة يجعل دفعتين متزامنتين لنفس المالك
                    // تعيدان فحص حد المنتجات بعد كل إنشاء ناجح.
                    MerchantProfile::where('user_id', $owner->id)->lockForUpdate()->first();

                    if ($kind === 'products') {
                        $this->usage->assertCanAddProduct($owner, 'auto');
                    }

                    DB::table('merchant_bulk_import_rows')->insert([
                        'merchant_user_id' => $owner->id,
                        'import_type' => $kind,
                        'file_sha256' => $fileHash,
                        'row_number' => $line,
                        'row_sha256' => $rowHash,
                        'idempotency_key' => $idem,
                        'created_at' => now(),
                    ]);

                    $sub = clone $request;
                    $sub->request->replace($payload);
                    $sub->query->replace([]);

                    $response = $kind === 'products'
                        ? $this->sectorController->createProduct($sub, $this->entitlements)
                        : $this->sectorController->createCustomer($sub);

                    if ($response->getStatusCode() >= 300) {
                        $body = $response->getData(true);
                        throw new \DomainException(
                            (string) ($body['message'] ?? 'رفض محرك القطاع الصف'),
                            $response->getStatusCode(),
                        );
                    }

                    return 'added';
                });

                if ($outcome === 'already_done') $alreadyDone++;
                else $added++;
            } catch (UsageLimitExceededException $e) {
                $skipped++;
                $reason = 'تم بلوغ حد المنتجات في الباقة';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                $rowErrors[] = ['line' => $line, 'reason' => $reason];
                // كل الصفوف التالية ستفشل لنفس حد Live Count، فلا نضغط الخادم.
                break;
            } catch (\DomainException|\InvalidArgumentException|\RuntimeException $e) {
                $skipped++;
                $reason = mb_substr(trim($e->getMessage()) ?: 'تعذّر استيراد الصف', 0, 240);
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                if (count($rowErrors) < 50) {
                    $rowErrors[] = ['line' => $line, 'reason' => $reason];
                }
            } catch (\Throwable $e) {
                report($e);
                $skipped++;
                $reason = 'خطأ داخلي أثناء معالجة الصف';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                if (count($rowErrors) < 50) {
                    $rowErrors[] = ['line' => $line, 'reason' => $reason];
                }
            }
        }

        $this->audit->record([
            'actor_type' => 'merchant',
            'actor_user_id' => $owner->id,
            'subject_type' => 'merchant_bulk_import',
            'subject_id' => $kind,
            'action' => 'MERCHANT_DATA_IMPORTED',
            'decision_code' => $skipped > 0 ? 'PARTIAL' : 'COMPLETED',
            'severity' => $skipped > 0 ? 'warning' : 'info',
            'context' => [
                'sector' => $sector,
                'kind' => $kind,
                'file_sha256' => $fileHash,
                'added' => $added,
                'already_done' => $alreadyDone,
                'skipped' => $skipped,
                'reasons' => $reasons,
            ],
        ]);

        return response()->json([
            'success' => true,
            'code' => $skipped > 0 ? 'IMPORTED_WITH_ERRORS' : 'IMPORTED',
            'message' => "أُضيف {$added} · موجود من محاولة سابقة {$alreadyDone} · رُفض {$skipped}",
            'errors' => (object) [],
            'meta' => [
                'sector' => $sector,
                'kind' => $kind,
                'added' => $added,
                'already_done' => $alreadyDone,
                'skipped' => $skipped,
                'reasons' => $reasons,
                'row_errors' => $rowErrors,
                'max_rows_per_batch' => self::MAX_ROWS,
            ],
        ]);
    }

    private function schema(string $sector, string $kind): array
    {
        if ($kind === 'products') {
            return match ($sector) {
                A::BIZ_PHARMACY => [
                    'columns' => [
                        'trade_name', 'generic_name', 'active_ingredient', 'strength',
                        'dosage_form', 'manufacturer', 'unit', 'sku', 'barcode',
                        'sale_price', 'cost_price', 'requires_prescription',
                        'low_stock_threshold', 'description', 'dosage_instructions',
                    ],
                    'required' => ['trade_name', 'sale_price'],
                    'booleans' => ['requires_prescription'],
                    'arrays' => [],
                ],
                A::BIZ_WHOLESALE => [
                    'columns' => [
                        'name', 'sku', 'barcode', 'manufacturer', 'unit',
                        'base_price', 'cost_price', 'low_stock_threshold',
                        'initial_stock', 'description',
                    ],
                    'required' => ['name', 'base_price'],
                    'booleans' => [],
                    'arrays' => [],
                ],
                A::BIZ_FUEL => [
                    'columns' => ['name', 'product_code', 'price_per_liter', 'color_hex'],
                    'required' => ['name', 'price_per_liter'],
                    'booleans' => [],
                    'arrays' => [],
                ],
                default => [
                    'columns' => [
                        'name', 'price', 'cost_price', 'offer_price', 'quantity',
                        'production_date', 'expiry_date', 'category', 'barcode',
                        'sku', 'reorder_level', 'track_stock',
                    ],
                    'required' => ['name', 'price'],
                    'booleans' => ['track_stock'],
                    'arrays' => [],
                ],
            };
        }

        return match ($sector) {
            A::BIZ_PHARMACY => [
                'columns' => [
                    'full_name', 'phone', 'date_of_birth', 'gender',
                    'is_pregnant', 'is_breastfeeding', 'allergies',
                    'chronic_conditions', 'regular_medications', 'notes',
                ],
                'required' => ['full_name'],
                'booleans' => ['is_pregnant', 'is_breastfeeding'],
                'arrays' => ['allergies', 'chronic_conditions', 'regular_medications'],
            ],
            A::BIZ_WHOLESALE => [
                'columns' => [
                    'full_name', 'company_name', 'phone', 'email', 'city',
                    'address', 'tax_number', 'credit_limit',
                    'payment_terms_days', 'notes',
                ],
                'required' => ['full_name'],
                'booleans' => [],
                'arrays' => [],
            ],
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_RESTAURANT => [
                'columns' => ['name', 'phone', 'credit_limit'],
                'required' => ['name', 'phone'],
                'booleans' => [],
                'arrays' => [],
            ],
            default => throw new \DomainException('استيراد العملاء غير متاح لهذا القطاع'),
        };
    }

    /** @return array{0:mixed,1:\Closure} */
    private function exportQuery(int $merchantId, string $sector, string $kind, array $columns): array
    {
        if ($kind === 'products') {
            if ($sector === A::BIZ_PHARMACY) {
                $pharmacyId = Pharmacy::where('merchant_user_id', $merchantId)->value('id');
                return [
                    PharmacyProduct::where('pharmacy_id', $pharmacyId ?: 0)->orderBy('id'),
                    fn ($r) => $this->values($columns, [
                        ...$r->toArray(),
                        'requires_prescription' => $r->requires_prescription ? 1 : 0,
                    ]),
                ];
            }
            if ($sector === A::BIZ_WHOLESALE) {
                $businessId = WholesaleBusiness::where('merchant_user_id', $merchantId)->value('id');
                return [
                    WholesaleProduct::where('business_id', $businessId ?: 0)->orderBy('id'),
                    fn ($r) => $this->values($columns, [
                        ...$r->toArray(),
                        'initial_stock' => $r->current_stock,
                    ]),
                ];
            }
            if ($sector === A::BIZ_FUEL) {
                $stationId = FuelStation::where('merchant_user_id', $merchantId)->value('id');
                return [
                    FuelProduct::where('station_id', $stationId ?: 0)->orderBy('id'),
                    fn ($r) => $this->values($columns, $r->toArray()),
                ];
            }

            return [
                MerchantProduct::where('merchant_user_id', $merchantId)
                    ->whereNull('parent_product_id')->orderBy('id'),
                fn ($r) => $this->values($columns, [
                    ...$r->toArray(),
                    'track_stock' => $r->track_stock ? 1 : 0,
                ]),
            ];
        }

        if ($sector === A::BIZ_PHARMACY) {
            $pharmacyId = Pharmacy::where('merchant_user_id', $merchantId)->value('id');
            return [
                PharmacyCustomer::where('pharmacy_id', $pharmacyId ?: 0)->orderBy('id'),
                fn ($r) => $this->values($columns, [
                    ...$r->toArray(),
                    'is_pregnant' => $r->is_pregnant ? 1 : 0,
                    'is_breastfeeding' => $r->is_breastfeeding ? 1 : 0,
                    'allergies' => implode('|', $r->allergies ?? []),
                    'chronic_conditions' => implode('|', $r->chronic_conditions ?? []),
                    'regular_medications' => implode('|', $r->regular_medications ?? []),
                ]),
            ];
        }

        if ($sector === A::BIZ_WHOLESALE) {
            $businessId = WholesaleBusiness::where('merchant_user_id', $merchantId)->value('id');
            return [
                WholesaleCustomer::where('business_id', $businessId ?: 0)->orderBy('id'),
                fn ($r) => $this->values($columns, $r->toArray()),
            ];
        }

        return [
            CustomerCreditAccount::where('merchant_user_id', $merchantId)->orderBy('id'),
            fn ($r) => $this->values($columns, [
                'name' => $r->customer_name,
                'phone' => $r->customer_phone,
                'credit_limit' => (float) $r->credit_limit > 0 ? $r->credit_limit : null,
            ]),
        ];
    }

    private function payload(array $headers, array $values, array $schema): array
    {
        $row = [];
        foreach ($headers as $i => $key) {
            $value = trim((string) ($values[$i] ?? ''));
            if ($value === '') continue;

            if (in_array($key, $schema['booleans'], true)) {
                $row[$key] = $this->boolean($value);
            } elseif (in_array($key, $schema['arrays'], true)) {
                $row[$key] = array_values(array_filter(array_map(
                    'trim',
                    preg_split('/[|;]/u', $value) ?: []
                ), fn ($v) => $v !== ''));
            } else {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    private function values(array $columns, array $row): array
    {
        return array_map(fn ($key) => $row[$key] ?? null, $columns);
    }

    private function boolean(string $value): bool
    {
        return in_array(
            mb_strtolower(trim($value)),
            ['1', 'true', 'yes', 'y', 'on', 'نعم', 'مفعل', 'مفعّل'],
            true,
        );
    }

    private function header(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value));
    }

    private function emptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') return false;
        }
        return true;
    }

    private function csvSafe(mixed $value): mixed
    {
        if ($value === null) return '';
        $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        // CSV Formula Injection: Excel/Sheets must not execute merchant/customer data.
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
            return "'" . $text;
        }

        return $text;
    }

    private function owner(Request $request)
    {
        $owner = $request->user('merchant_web');
        if (! $owner) {
            abort(401, 'انتهت جلسة التاجر');
        }
        return $owner;
    }

    private function sector(Request $request): string
    {
        $sector = MerchantProfile::where('user_id', $this->owner($request)->id)
            ->value('business_type');

        if (! in_array($sector, [
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_RESTAURANT,
            A::BIZ_PHARMACY, A::BIZ_WHOLESALE, A::BIZ_FUEL,
        ], true)) {
            throw new \DomainException('نوع نشاط المنشأة غير مدعوم لهذا الملف');
        }

        return (string) $sector;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => (object) [],
        ], $status);
    }
}
