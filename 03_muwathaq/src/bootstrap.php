<?php

declare(strict_types=1);

namespace Muwathaq;

use PDO;
use RuntimeException;

final class Config
{
    public static function value(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }

    public static function required(string $key): string
    {
        $value = self::value($key);
        if ($value === null) {
            throw new RuntimeException("Missing required configuration: {$key}");
        }
        return $value;
    }
}

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $databaseUrl = Config::value('DATABASE_URL');
        if ($databaseUrl !== null) {
            $connection = parse_url($databaseUrl);
            $database = ltrim((string) ($connection['path'] ?? ''), '/');
            $host = (string) ($connection['host'] ?? '');
            $port = (string) ($connection['port'] ?? '3306');
            $username = rawurldecode((string) ($connection['user'] ?? ''));
            $password = rawurldecode((string) ($connection['pass'] ?? ''));

            if (($connection['scheme'] ?? '') !== 'mysql' || $host === '' || $database === '' || $username === '') {
                throw new RuntimeException('DATABASE_URL must be a valid mysql://user:password@host:port/database URL.');
            }
        } else {
            $host = Config::required('DB_HOST');
            $port = Config::value('DB_PORT', '3306');
            $database = Config::required('DB_DATABASE');
            $username = Config::required('DB_USERNAME');
            $password = Config::required('DB_PASSWORD');
        }

        self::$pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        self::migrate(self::$pdo);

        return self::$pdo;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS muwathaq_cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_ulid VARCHAR(26) NOT NULL UNIQUE,
  core_event_ulid VARCHAR(26) NOT NULL UNIQUE,
  core_application_ulid VARCHAR(26) NOT NULL UNIQUE,
  subject_kind VARCHAR(32) NOT NULL,
  verification_path VARCHAR(64) NOT NULL,
  business_type VARCHAR(64) NULL,
  requested_tier TINYINT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'submitted',
  payload_encrypted LONGTEXT NOT NULL,
  received_at DATETIME NOT NULL,
  review_started_at DATETIME NULL,
  reviewer_identity VARCHAR(190) NULL,
  policy_version VARCHAR(64) NULL,
  decision VARCHAR(32) NULL,
  decision_reference VARCHAR(100) NULL UNIQUE,
  decision_reason VARCHAR(1000) NULL,
  decision_context_json JSON NULL,
  core_delivery_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  core_delivered_at DATETIME NULL,
  core_last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX muwathaq_cases_status_received (status, received_at),
  INDEX muwathaq_cases_delivery (core_delivery_status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
}

final class Cipher
{
    private static function key(): string
    {
        $key = base64_decode(Config::required('MUWATHAQ_DATA_KEY'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('MUWATHAQ_DATA_KEY must be base64 for exactly 32 bytes.');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Could not encrypt case payload.');
        }
        return base64_encode($iv.$tag.$ciphertext);
    }

    /** @return array<string,mixed> */
    public static function decryptJson(string $encoded): array
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Stored case payload is invalid.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Could not decrypt case payload.');
        }
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            throw new RuntimeException('Stored case JSON is invalid.');
        }
        return $data;
    }
}

final class Signature
{
    public static function verifyAmialRequest(string $body): void
    {
        $secret = Config::required('MUWATHAQ_BRIDGE_SECRET');
        $timestamp = $_SERVER['HTTP_X_AMIAL_TIMESTAMP'] ?? '';
        $signature = $_SERVER['HTTP_X_AMIAL_SIGNATURE'] ?? '';
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            throw new HttpError(401, 'AMIAL_SIGNATURE_EXPIRED', 'انتهت صلاحية توقيع أميال باي.');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        if (!is_string($signature) || !hash_equals($expected, $signature)) {
            throw new HttpError(401, 'AMIAL_SIGNATURE_INVALID', 'توقيع أميال باي غير صحيح.');
        }
    }
}

final class Policy
{
    /** @var array<string,string> */
    public const GOVERNORATES = [
        'YE-AB' => 'أبين', 'YE-AD' => 'عدن', 'YE-AM' => 'عمران', 'YE-BA' => 'البيضاء',
        'YE-DA' => 'الضالع', 'YE-DH' => 'ذمار', 'YE-HD' => 'حضرموت', 'YE-HJ' => 'حجة',
        'YE-HU' => 'الحديدة', 'YE-IB' => 'إب', 'YE-JA' => 'الجوف', 'YE-LA' => 'لحج',
        'YE-MA' => 'مأرب', 'YE-MR' => 'المهرة', 'YE-MW' => 'المحويت', 'YE-RA' => 'ريمة',
        'YE-SA' => 'أمانة العاصمة', 'YE-SD' => 'صعدة', 'YE-SH' => 'شبوة', 'YE-SN' => 'صنعاء',
        'YE-SU' => 'سقطرى', 'YE-TA' => 'تعز',
    ];

    /** @return array{version:string,requirements:array<string,string>} */
    public static function for(array $case): array
    {
        return match ($case['verification_path']) {
            'women_progressive' => [
                'version' => 'women-tier-1-v1',
                'requirements' => [
                    'verified_phone' => 'تأكيد ملكية رقم الهاتف',
                    'verified_residence' => 'تأكيد محافظة الإقامة والعنوان',
                ],
            ],
            'standard_customer' => [
                'version' => 'standard-customer-v1',
                'requirements' => [
                    'verified_phone' => 'تأكيد ملكية رقم الهاتف',
                    'verified_residence' => 'تأكيد محل الإقامة',
                    'verified_identity' => 'مراجعة وثيقة الهوية لصاحب الحساب',
                ],
            ],
            'merchant_sector' => [
                'version' => 'merchant-sector-v1',
                'requirements' => [
                    'verified_owner_identity' => 'توثيق هوية مالك المنشأة',
                    'verified_business_requirements' => 'استكمال متطلبات قطاع المنشأة',
                    'verified_residence' => 'تأكيد موقع النشاط أو العنوان التشغيلي',
                ],
            ],
            'agent_operation' => [
                'version' => 'agent-operation-v1',
                'requirements' => [
                    'admin_provisioned' => 'تأكيد أن الوكيل أُنشئ من الإدارة',
                    'verified_owner_identity' => 'توثيق هوية الوكيل',
                    'verified_residence' => 'تأكيد موقع الوكيل التشغيلي',
                ],
            ],
            default => throw new HttpError(422, 'UNKNOWN_POLICY_PATH', 'مسار التحقق غير مدعوم.'),
        };
    }

    /** @return array<string,mixed> */
    public static function decisionContext(array $case, array $affirmed, string $governorate): array
    {
        $policy = self::for($case);
        foreach (array_keys($policy['requirements']) as $requirement) {
            if (!in_array($requirement, $affirmed, true)) {
                throw new HttpError(422, 'POLICY_NOT_COMPLETE', 'لا يمكن الاعتماد قبل إقرار جميع متطلبات السياسة.');
            }
        }

        if (!isset(self::GOVERNORATES[$governorate])) {
            throw new HttpError(422, 'INVALID_GOVERNORATE', 'اختر محافظة يمنية صحيحة.');
        }

        $context = [
            'policy_version' => $policy['version'],
            'verified_phone' => in_array('verified_phone', $affirmed, true),
            'verified_residence_governorate' => $governorate,
            'verified_identity' => in_array('verified_identity', $affirmed, true),
            'verified_owner_identity' => in_array('verified_owner_identity', $affirmed, true),
            'verified_business_requirements' => in_array('verified_business_requirements', $affirmed, true),
            'admin_provisioned' => in_array('admin_provisioned', $affirmed, true),
        ];

        return array_filter($context, static fn ($value) => is_bool($value) ? $value : $value !== '');
    }
}

final class CoreDecisionClient
{
    public static function deliver(array $case): void
    {
        if (empty($case['decision']) || empty($case['decision_reference'])) {
            return;
        }
        $base = rtrim(Config::required('AMIAL_CORE_BASE_URL'), '/');
        $body = json_encode([
            'decision' => $case['decision'],
            'decision_reference' => $case['decision_reference'],
            'reason' => $case['decision_reason'],
            'context' => json_decode((string) $case['decision_context_json'], true) ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, Config::required('MUWATHAQ_DECISION_SECRET'));
        $url = $base.'/api/v1/internal/muwathaq/applications/'.rawurlencode($case['core_application_ulid']).'/decision';
        $request = curl_init($url);
        curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 'Accept: application/json',
                'X-Muwathaq-Timestamp: '.$timestamp,
                'X-Muwathaq-Signature: '.$signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($request);
        $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $error = curl_error($request);
        curl_close($request);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Core delivery failed'.($status ? " (HTTP {$status})" : '').($error ? ": {$error}" : ''));
        }
    }
}

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $code, string $message)
    {
        parent::__construct($message);
    }
}

function now(): string { return gmdate('Y-m-d H:i:s'); }
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function jsonResponse(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function redirect(string $path): never { header('Location: '.$path, true, 303); exit; }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function assertCsrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_token'] ?? ''))) {
        throw new HttpError(419, 'CSRF_INVALID', 'انتهت صلاحية النموذج؛ أعد المحاولة.');
    }
}
function reviewer(): ?string { return $_SESSION['reviewer'] ?? null; }
function requireReviewer(): string {
    $reviewer = reviewer();
    if ($reviewer === null) redirect('/login');
    return $reviewer;
}
