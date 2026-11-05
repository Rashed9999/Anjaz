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

    /**
     * يقبل مفتاحاً مستقلاً للتدوير، أو يشتق مفتاح اتجاه من سر النقل الموحد.
     * لا تُستعمل قيمة النقل مباشرةً كتوقيع في القناتين.
     */
    public static function transportSecret(string $specificKey, string $purpose): string
    {
        $specific = self::value($specificKey);
        if ($specific !== null) {
            return $specific;
        }

        $shared = self::required('MUWATHAQ_TRANSPORT_SECRET');

        return hash_hmac('sha256', $purpose, $shared);
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
        // ترقيات ذاتية صغيرة للتطبيق المستقل. نتحمل وجود العمود عند إعادة التشغيل.
        try {
            $db->exec('ALTER TABLE muwathaq_cases ADD COLUMN evidence_encrypted LONGTEXT NULL AFTER payload_encrypted');
        } catch (\PDOException) {
            // العمود موجود غالباً؛ مخطط موثّق لا يعتمد على هجرة يدوية خارج التطبيق.
        }

        // قضايا موثّق المنشأة هنا هي المصدر الجديد للحقيقة. الحالات القديمة
        // التي وصلت من أميال باي تبقى قابلة للمراجعة ولا تتغير.
        foreach ([
            "ALTER TABLE muwathaq_cases ADD COLUMN origin VARCHAR(32) NOT NULL DEFAULT 'amial_legacy' AFTER core_application_ulid",
            "ALTER TABLE muwathaq_cases ADD COLUMN created_by_identity VARCHAR(190) NULL AFTER origin",
        ] as $sql) { try { $db->exec($sql); } catch (\PDOException) {} }

        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS muwathaq_case_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(40) NOT NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'submitted',
  payload_encrypted LONGTEXT NULL,
  document_encrypted LONGTEXT NULL,
  document_mime VARCHAR(100) NULL,
  document_sha256 VARCHAR(64) NULL,
  submitted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY muwathaq_case_evidence_kind (case_id, kind),
  CONSTRAINT muwathaq_case_evidence_case_fk FOREIGN KEY (case_id) REFERENCES muwathaq_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS muwathaq_reviewer_credentials (
  id TINYINT UNSIGNED PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS muwathaq_reviewer_audit_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_ulid VARCHAR(26) NOT NULL UNIQUE,
  reviewer_identity VARCHAR(190) NOT NULL,
  action VARCHAR(64) NOT NULL,
  case_ulid VARCHAR(26) NULL,
  core_application_ulid VARCHAR(26) NULL,
  subject_kind VARCHAR(32) NULL,
  payload_encrypted LONGTEXT NOT NULL,
  delivery_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  delivered_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  occurred_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX muwathaq_audit_delivery (delivery_status, occurred_at),
  INDEX muwathaq_audit_reviewer_time (reviewer_identity, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
}

final class ReviewerCredentials
{
    /** @return array{email:string,password_hash:string}|null */
    public static function current(): ?array
    {
        $credential = Database::pdo()
            ->query('SELECT email, password_hash FROM muwathaq_reviewer_credentials WHERE id = 1')
            ->fetch();

        return $credential ?: null;
    }

    public static function save(string $email, string $passwordHash): void
    {
        $statement = Database::pdo()->prepare(
            'INSERT INTO muwathaq_reviewer_credentials (id,email,password_hash,created_at,updated_at)
             VALUES (1,?,?,?,?)
             ON DUPLICATE KEY UPDATE email=VALUES(email), password_hash=VALUES(password_hash), updated_at=VALUES(updated_at)'
        );
        $time = now();
        $statement->execute([$email, $passwordHash, $time, $time]);
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
    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Stored encrypted value is invalid.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) throw new RuntimeException('Could not decrypt stored value.');
        return $plain;
    }

    public static function decryptJson(string $encoded): array
    {
        $plain = self::decrypt($encoded);
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
        $secret = Config::transportSecret('MUWATHAQ_BRIDGE_SECRET', 'amial-to-muwathaq-v1');
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
            'women_progressive' => match (max(1, min(3, (int) ($case['requested_tier'] ?? 1)))) {
                1 => [
                    'version' => 'women-tier-1-v1',
                    'requirements' => [
                        'verified_phone' => 'تأكيد ملكية رقم الهاتف',
                        'verified_residence' => 'تأكيد محافظة الإقامة والعنوان',
                    ],
                ],
                2 => [
                    'version' => 'women-tier-2-v1',
                    'requirements' => [
                        'verified_phone' => 'تأكيد ملكية رقم الهاتف',
                        'verified_residence' => 'تأكيد محافظة الإقامة والعنوان',
                        'verified_identity' => 'مراجعة وثيقة الهوية من دون اشتراط صورة شخصية',
                    ],
                ],
                3 => [
                    'version' => 'women-tier-3-v1',
                    'requirements' => [
                        'verified_phone' => 'تأكيد ملكية رقم الهاتف',
                        'verified_residence' => 'تأكيد محافظة الإقامة والعنوان',
                        'verified_identity' => 'مراجعة وثيقة الهوية',
                        'verified_ownership_assurance' => 'ضمان ملكية متقدم: مراجعة حضورية أو خيار بيومتري بموافقة صاحبة الحساب',
                    ],
                ],
            },
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
                    // القطاع يحدد لوحة العمل وقدراتها، لا قائمة وثائق مختلفة.
                    'verified_business_requirements' => 'توثيق بيانات المنشأة ونشاطها',
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

    /**
     * عقد التسجيل هو ما تستهلكه واجهات أميال باي. المتطلبات في
     * review_requirements قرار موثّق؛ أما registration_fields فهي الحد الأدنى
     * لإنشاء طلب آمن ولا تتضمن وثائق أو بيانات KYC قديمة.
     *
     * @return array<string,mixed>
     */
    public static function registrationContract(array $selector): array
    {
        $kind = strtolower(trim((string) ($selector['subject_kind'] ?? '')));
        $gender = strtolower(trim((string) ($selector['gender'] ?? '')));
        $businessType = trim((string) ($selector['business_type'] ?? ''));

        if ($kind === 'customer') {
            if (!in_array($gender, ['female', 'male'], true)) {
                throw new HttpError(422, 'GENDER_REQUIRED', 'يجب تحديد الجنس لاختيار سياسة العميل.');
            }

            $path = $gender === 'female' ? 'women_progressive' : 'standard_customer';
            $policy = self::for(['verification_path' => $path]);

            return [
                'policy_version' => $policy['version'],
                'subject_kind' => 'customer',
                'verification_path' => $path,
                'registration_fields' => [
                    'declared_legal_name', 'gender', 'phone', 'verified_email',
                    'login_password', 'transaction_pin', 'terms_acceptance',
                ],
                'review_requirements' => $policy['requirements'],
                'sector' => null,
            ];
        }

        if ($kind === 'merchant') {
            if ($businessType === '') {
                throw new HttpError(422, 'BUSINESS_TYPE_REQUIRED', 'اختر قطاع المنشأة قبل طلب عقد التسجيل.');
            }
            $policy = self::for(['verification_path' => 'merchant_sector']);

            return [
                'policy_version' => $policy['version'],
                'subject_kind' => 'merchant',
                'verification_path' => 'merchant_sector',
                'registration_fields' => [
                    'business_name', 'business_type', 'declared_owner_name',
                    'phone', 'verified_email', 'login_password',
                    'transaction_pin', 'terms_acceptance',
                ],
                'review_requirements' => $policy['requirements'],
                'sector' => [
                    'code' => $businessType,
                    'purpose' => 'dashboard_and_operational_capabilities',
                    'label' => 'القطاع يحدد لوحة المنشأة والصلاحيات التشغيلية بعد الاعتماد.',
                ],
            ];
        }

        throw new HttpError(422, 'UNSUPPORTED_ACCOUNT_TYPE', 'نوع فتح الحساب غير مدعوم.');
    }

    /** @return list<string> */
    public static function evidenceKinds(array $case): array
    {
        return match ($case['verification_path']) {
            'women_progressive' => match (max(1, min(3, (int) ($case['requested_tier'] ?? 1)))) {
                1 => ['phone', 'residence'],
                2 => ['phone', 'residence', 'identity_document'],
                3 => ['phone', 'residence', 'identity_document', 'ownership_assurance'],
            },
            'standard_customer' => ['phone', 'residence', 'identity_document'],
            'merchant_sector' => ['owner_identity', 'business_registration', 'operating_address'],
            'agent_operation' => ['admin_provisioning', 'owner_identity', 'operating_address'],
            default => [],
        };
    }

    /** @return list<string> */
    public static function documentEvidenceKinds(array $case): array
    {
        return match ($case['verification_path']) {
            'women_progressive' => (int) ($case['requested_tier'] ?? 1) >= 2 ? ['identity_document'] : [],
            'standard_customer' => ['identity_document'],
            'merchant_sector' => ['owner_identity', 'business_registration', 'operating_address'],
            'agent_operation' => ['owner_identity', 'operating_address'],
            default => [],
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
            'verified_ownership_assurance' => in_array('verified_ownership_assurance', $affirmed, true),
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
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, Config::transportSecret('MUWATHAQ_DECISION_SECRET', 'muwathaq-to-amial-v1'));
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


final class CoreProvisioningClient
{
    /** يرسل قرار تهيئة لا يتضمن كلمة مرور أو PIN أو صوراً خاماً. */
    public static function deliver(array $case): void
    {
        $payload = Cipher::decryptJson($case['payload_encrypted']);
        $body = json_encode([
            'event_version' => 1,
            'event_type' => 'account.approved',
            'application_ulid' => $case['core_application_ulid'],
            'decision_reference' => $case['decision_reference'],
            'subject_kind' => $case['subject_kind'],
            'verification_path' => $case['verification_path'],
            'business_type' => $case['business_type'],
            'requested_tier' => $case['requested_tier'] === null ? null : (int) $case['requested_tier'],
            'applicant' => $payload['applicant'] ?? [],
            'subject' => $payload['subject'] ?? [],
            'decision_context' => json_decode((string) $case['decision_context_json'], true) ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, Config::transportSecret('MUWATHAQ_DECISION_SECRET', 'muwathaq-to-amial-v1'));
        $request = curl_init(rtrim(Config::required('AMIAL_CORE_BASE_URL'), '/').'/api/v1/internal/muwathaq/provisioning');
        curl_setopt_array($request, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Muwathaq-Timestamp: '.$timestamp, 'X-Muwathaq-Signature: '.$signature],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($request); $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE); $error = curl_error($request); curl_close($request);
        if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('Core provisioning failed'.($status ? " (HTTP {$status})" : '').($error ? ": {$error}" : ''));
    }
}

final class CoreOperationsClient
{
    /** @return array<string,mixed> */
    public static function directory(string $list, string $query = ''): array
    {
        $url = rtrim(Config::required('AMIAL_CORE_BASE_URL'), '/').'/api/v1/internal/muwathaq/account-directory?'
            .http_build_query(['list' => $list, 'q' => $query, 'limit' => 100]);
        return self::request('GET', $url, '');
    }

    public static function recordAudit(array $event): void
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::request(
            'POST',
            rtrim(Config::required('AMIAL_CORE_BASE_URL'), '/').'/api/v1/internal/muwathaq/reviewer-audit-events',
            $body,
        );
    }

    /** @return array<string,mixed> */
    private static function request(string $method, string $url, string $body): array
    {
        $timestamp = (string) time();
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            Config::transportSecret('MUWATHAQ_DECISION_SECRET', 'muwathaq-to-amial-v1'),
        );
        $request = curl_init($url);
        curl_setopt_array($request, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? $body : null,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
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

        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Amial reviewer operations failed'.($status ? " (HTTP {$status})" : '').($error ? ": {$error}" : ''));
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Amial reviewer operations returned invalid JSON.');
        }
        return $decoded;
    }
}

/**
 * Outbox محلي: أي تحرك للمراجعة يحفظ أولاً في موثّق ثم يرسل إلى أميال.
 * بذلك لا يضيع سجل التدقيق عند انقطاع الاتصال بين التطبيقين.
 */
final class ReviewerAudit
{
    public static function record(string $reviewer, string $action, ?array $case = null, array $context = []): void
    {
        $event = [
            'event_ulid' => strtoupper(substr(bin2hex(random_bytes(13)), 0, 26)),
            'reviewer_identity' => $reviewer,
            'action' => $action,
            'application_ulid' => $case['core_application_ulid'] ?? null,
            'case_ulid' => $case['case_ulid'] ?? null,
            'subject_kind' => $case['subject_kind'] ?? null,
            'context' => $context,
            'occurred_at' => now(),
        ];

        $db = Database::pdo();
        $time = now();
        $db->prepare('INSERT INTO muwathaq_reviewer_audit_outbox (event_ulid,reviewer_identity,action,case_ulid,core_application_ulid,subject_kind,payload_encrypted,delivery_status,occurred_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,"pending",?,?,?)')
            ->execute([
                $event['event_ulid'], $reviewer, $action, $event['case_ulid'], $event['application_ulid'],
                $event['subject_kind'], Cipher::encrypt(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                $event['occurred_at'], $time, $time,
            ]);
        self::deliver((int) $db->lastInsertId());
    }

    public static function deliver(int $id): void
    {
        $db = Database::pdo();
        $row = $db->prepare('SELECT * FROM muwathaq_reviewer_audit_outbox WHERE id = ?');
        $row->execute([$id]);
        $event = $row->fetch();
        if (!$event || $event['delivery_status'] === 'delivered') return;

        try {
            CoreOperationsClient::recordAudit(Cipher::decryptJson($event['payload_encrypted']));
            $db->prepare('UPDATE muwathaq_reviewer_audit_outbox SET delivery_status="delivered",attempts=attempts+1,delivered_at=?,last_error=NULL,updated_at=? WHERE id=?')
                ->execute([now(), now(), $id]);
        } catch (\Throwable $error) {
            $db->prepare('UPDATE muwathaq_reviewer_audit_outbox SET delivery_status="pending",attempts=attempts+1,last_error=?,updated_at=? WHERE id=?')
                ->execute([mb_substr($error->getMessage(), 0, 500), now(), $id]);
        }
    }

    public static function dispatchPending(int $limit = 100): int
    {
        $db = Database::pdo();
        $ids = $db->query('SELECT id FROM muwathaq_reviewer_audit_outbox WHERE delivery_status = "pending" ORDER BY id ASC LIMIT '.max(1, min(500, $limit)))->fetchAll();
        foreach ($ids as $row) self::deliver((int) $row['id']);
        return count($ids);
    }
}

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
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
