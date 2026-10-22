<?php

declare(strict_types=1);

use Muwathaq\Cipher;
use Muwathaq\Config;
use Muwathaq\CoreEvidenceClient;
use Muwathaq\CoreOperationsClient;
use Muwathaq\CoreDecisionClient;
use Muwathaq\CoreProvisioningClient;
use Muwathaq\Database;
use Muwathaq\HttpError;
use Muwathaq\Policy;
use Muwathaq\ReviewerCredentials;
use Muwathaq\ReviewerAudit;
use Muwathaq\Signature;
use function Muwathaq\assertCsrf;
use function Muwathaq\csrf;
use function Muwathaq\e;
use function Muwathaq\jsonResponse;
use function Muwathaq\now;
use function Muwathaq\redirect;
use function Muwathaq\requireReviewer;
use function Muwathaq\reviewer;

require __DIR__.'/../src/bootstrap.php';

session_name('muwathaq_session');
session_set_cookie_params(['httponly' => true, 'secure' => (Config::value('APP_ENV') === 'production'), 'samesite' => 'Lax', 'path' => '/']);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

function layout(string $title, string $content): never
{
    $reviewer = reviewer();
    $navigation = '';
    if ($reviewer) {
        $navigation = '<aside class="sidebar"><div class="brand">موثّق <small>مركز التحقق</small></div>'
            .'<div class="session"><span>موظفة الجلسة</span><b>'.e($reviewer).'</b><a href="/logout">تسجيل الخروج</a></div>'
            .'<nav><a href="/">الرئيسية</a><strong>الحسابات</strong>'
            .'<a href="/accounts?list=all">كل الحسابات</a>'
            .'<a href="/accounts?list=new">الحسابات الجديدة</a>'
            .'<a href="/accounts?list=customer_female">عملاء — إناث</a>'
            .'<a href="/accounts?list=customer_male">عملاء — ذكور</a>'
            .'<a href="/accounts?list=merchant">حسابات التجار</a>'
            .'<a href="/accounts?list=agent">حسابات الوكلاء</a>'
            .'<a href="/accounts/new-agent">إنشاء وكيل</a>'
            .'<a href="/accounts?list=active">الحسابات النشطة</a>'
            .'<a href="/accounts?list=suspended">الحسابات الموقوفة</a>'
            .'<a href="/accounts?list=review">حسابات قيد المراجعة</a>'
            .'<strong>الرقابة</strong><a href="/audit">سجل نشاط الجلسة</a></nav></aside>';
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' | موثّق</title><style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#172033;font:15px Tahoma,Arial,sans-serif}.shell{min-height:100vh;display:flex}.sidebar{width:254px;flex:0 0 254px;background:#0d2b61;color:#fff;padding:22px 14px}.brand{font-size:22px;font-weight:bold;padding:0 10px 20px}.brand small{display:block;font-size:12px;font-weight:normal;color:#b9c9e7;margin-top:4px}.session{border:1px solid #ffffff2a;border-radius:10px;padding:12px;margin:0 0 18px}.session span{display:block;color:#b9c9e7;font-size:12px}.session b{display:block;overflow-wrap:anywhere;margin:5px 0 10px}.session a{color:#fff;font-size:12px}.sidebar nav{display:grid;gap:3px}.sidebar nav strong{font-size:12px;color:#a9bee5;margin:12px 10px 3px}.sidebar nav a{color:#e9f0ff;text-decoration:none;padding:9px 10px;border-radius:7px}.sidebar nav a:hover{background:#ffffff1c}.page{min-width:0;flex:1}.top{background:#fff;border-bottom:1px solid #e4e9f2;padding:18px 5%;display:flex;justify-content:space-between;align-items:center}.top a{color:#0d2b61;text-decoration:none;font-weight:bold}.wrap{max-width:1240px;margin:28px auto;padding:0 20px}.card{background:#fff;border:1px solid #e4e9f2;border-radius:14px;padding:20px;margin:16px 0;box-shadow:0 4px 18px #0d2b6110}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.stat{padding:16px;border-radius:12px;background:#f5f8fd}.muted{color:#667085}.tag{display:inline-block;padding:4px 9px;border-radius:20px;background:#e9eef7;font-size:12px}.pending{background:#fff2cc}.approved,.active{background:#d9f5df}.rejected,.suspended{background:#ffe1e1}.btn{display:inline-block;background:#1268d6;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer;text-decoration:none}.btn.danger{background:#b42318}.btn.secondary{background:#eef2f8;color:#172033}input,select,textarea{box-sizing:border-box;width:100%;padding:10px;border:1px solid #cfd8e6;border-radius:8px;margin:5px 0 12px}label{font-weight:bold}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #e9edf3;text-align:right}.alert{padding:12px;border-radius:8px;background:#fff2cc}.error{background:#ffe3e3}.req{padding:9px;background:#f7f9fc;border-radius:8px;margin:5px 0}.toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.toolbar form{display:flex;gap:8px;align-items:end;flex:1}.toolbar input{margin:0;max-width:330px}@media(max-width:820px){.sidebar{width:100%;flex:none}.shell{display:block}.sidebar nav{grid-template-columns:repeat(2,minmax(0,1fr))}.sidebar nav strong{grid-column:1/-1}.wrap{padding:0 14px}.hide-mobile{display:none}}@media(max-width:480px){table{font-size:12px}.sidebar nav{grid-template-columns:1fr}}
    </style></head><body><div class="shell">'.$navigation.'<section class="page"><header class="top"><a href="/">موثّق — مركز مراجعة فتح الحساب</a>'.($reviewer ? '<span class="muted">جلسة مراجعة داخلية</span>' : '').'</header><main class="wrap">'.$content.'</main></section></div></body></html>';
    exit;
}

function findCase(string $ulid): array
{
    $statement = Database::pdo()->prepare('SELECT * FROM muwathaq_cases WHERE case_ulid = ?');
    $statement->execute([$ulid]);
    $case = $statement->fetch();
    if (!$case) throw new HttpError(404, 'CASE_NOT_FOUND', 'قضية موثّق غير موجودة.');
    return $case;
}

function findCaseForApplication(string $applicationUlid): ?array
{
    if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $applicationUlid)) {
        return null;
    }
    $statement = Database::pdo()->prepare('SELECT * FROM muwathaq_cases WHERE core_application_ulid = ?');
    $statement->execute([$applicationUlid]);
    $case = $statement->fetch();
    return $case ?: null;
}

/** ينشئ موثّق القضية والملف المشفّر؛ لا يكتب أي مستخدم أو محفظة في أميال باي. */
function createMuwathaqOwnedCase(array $input, array $files, ?string $creator): array
{
    $kind = (string) ($input['subject_kind'] ?? '');
    if (!in_array($kind, ['customer', 'merchant', 'agent'], true)) throw new HttpError(422, 'INVALID_ACCOUNT_TYPE', 'نوع الحساب غير صحيح.');
    if ($kind === 'agent' && $creator === null) throw new HttpError(403, 'AGENT_INTERNAL_ONLY', 'إنشاء الوكيل متاح لموظفي موثّق فقط.');
    $first = trim((string) ($input['f_name'] ?? ''));
    $last = trim((string) ($input['l_name'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    if (mb_strlen($first) < 2 || mb_strlen($last) < 2 || mb_strlen($phone) < 5 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpError(422, 'INVALID_APPLICANT', 'أكمل الاسم والهاتف والبريد الصحيحين.');
    }
    $gender = strtolower(trim((string) ($input['gender'] ?? '')));
    $businessType = trim((string) ($input['business_type'] ?? ''));
    $storeName = trim((string) ($input['store_name'] ?? ''));
    if ($kind === 'customer' && !in_array($gender, ['male', 'female'], true)) throw new HttpError(422, 'GENDER_REQUIRED', 'حدد جنس العميل لاختيار سياسة التحقق.');
    if ($kind === 'merchant' && ($businessType === '' || $storeName === '')) throw new HttpError(422, 'MERCHANT_DETAILS_REQUIRED', 'اسم المنشأة وقطاعها مطلوبان.');
    $path = match ($kind) { 'agent' => 'agent_operation', 'merchant' => 'merchant_sector', default => $gender === 'female' ? 'women_progressive' : 'standard_customer' };
    $tier = $path === 'women_progressive' ? max(1, min(3, (int) ($input['requested_tier'] ?? 1))) : null;
    $applicationUlid = strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
    $eventUlid = strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
    $payload = [
        'event_version' => 1, 'event_type' => 'account_opening.created_in_muwathaq',
        'applicant' => ['f_name' => $first, 'l_name' => $last, 'legal_name' => trim($first.' '.$last), 'phone' => $phone, 'email' => $email, 'gender' => $gender ?: null],
        'subject' => ['store_name' => $storeName ?: null, 'business_type' => $businessType ?: null, 'operating_address' => trim((string) ($input['operating_address'] ?? '')) ?: null],
    ];
    $db = Database::pdo(); $time = now();
    $db->beginTransaction();
    try {
        $insert = $db->prepare('INSERT INTO muwathaq_cases (case_ulid,core_event_ulid,core_application_ulid,origin,created_by_identity,subject_kind,verification_path,business_type,requested_tier,status,payload_encrypted,received_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $caseUlid = strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
        $insert->execute([$caseUlid,$eventUlid,$applicationUlid,'muwathaq',$creator,$kind,$path,$businessType ?: null,$tier,'submitted',Cipher::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),$time,$time,$time]);
        $caseId = (int) $db->lastInsertId();
        $case = ['id'=>$caseId,'case_ulid'=>$caseUlid,'core_application_ulid'=>$applicationUlid,'subject_kind'=>$kind,'verification_path'=>$path,'business_type'=>$businessType ?: null,'requested_tier'=>$tier,'origin'=>'muwathaq'];
        $evidence = [];
        foreach (Policy::evidenceKinds($case) as $evidenceKind) {
            $file = $files[$evidenceKind] ?? null;
            $requiresDocument = in_array($evidenceKind, Policy::documentEvidenceKinds($case), true);
            if ($requiresDocument && (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
                throw new HttpError(422, 'EVIDENCE_REQUIRED', 'ارفع الدليل المطلوب: '.$evidenceKind.'.');
            }
            $encrypted = null; $mime = null; $sha = null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) throw new HttpError(422, 'EVIDENCE_TOO_LARGE', 'حجم كل مستند لا يتجاوز 5 ميغابايت.');
                $raw = file_get_contents((string) $file['tmp_name']);
                if ($raw === false) throw new HttpError(422, 'EVIDENCE_UNREADABLE', 'تعذر قراءة المستند المرفوع.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: 'application/octet-stream';
                if (!in_array($mime, ['application/pdf','image/jpeg','image/png','image/webp'], true)) throw new HttpError(422, 'EVIDENCE_TYPE_INVALID', 'صيغة المستند يجب أن تكون PDF أو صورة JPEG/PNG/WebP.');
                $encrypted = Cipher::encrypt($raw); $sha = hash('sha256', $raw);
            }
            $record = $db->prepare('INSERT INTO muwathaq_case_evidence (case_id,kind,state,payload_encrypted,document_encrypted,document_mime,document_sha256,submitted_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $record->execute([$caseId,$evidenceKind,'submitted',Cipher::encrypt(json_encode(['declared_at'=>$time], JSON_THROW_ON_ERROR)),$encrypted,$mime,$sha,$time,$time,$time]);
            $evidence[$evidenceKind] = ['id'=>(int)$db->lastInsertId(),'kind'=>$evidenceKind,'state'=>'submitted','document_received'=>$encrypted !== null,'document_mime'=>$mime,'submitted_at'=>$time];
        }
        $db->prepare('UPDATE muwathaq_cases SET evidence_encrypted=?,updated_at=? WHERE id=?')->execute([Cipher::encrypt(json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),$time,$caseId]);
        $db->commit();
        return findCase($caseUlid);
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function accountOpeningForm(string $kind, string $action, string $token, bool $internal = false): string
{
    $merchant = $kind === 'merchant'; $agent = $kind === 'agent';
    $title = $agent ? 'إنشاء طلب وكيل' : ($merchant ? 'فتح حساب تاجر' : 'فتح حساب عميل');
    $agentNote = $agent ? '<p class="alert">ينشأ الوكيل داخل موثّق فقط، ولا يصبح حسابه التشغيلي نشطاً قبل اعتماد مراجع آخر.</p>' : '';
    $gender = $kind === 'customer' ? '<label>الجنس</label><select name="gender" required><option value="">اختر</option><option value="female">أنثى</option><option value="male">ذكر</option></select><label>مستوى البداية للعميلة</label><select name="requested_tier"><option value="1">المستوى 1</option><option value="2">المستوى 2</option><option value="3">المستوى 3</option></select>' : '';
    $business = ($merchant || $agent) ? '<label>'.($agent ? 'اسم نقطة/نشاط الوكيل' : 'اسم المنشأة').'</label><input name="store_name" required><label>القطاع أو النشاط</label><input name="business_type" required><label>العنوان التشغيلي</label><textarea name="operating_address" required></textarea>' : '';
    $fileKinds = $agent ? ['owner_identity'=>'هوية الوكيل','operating_address'=>'إثبات العنوان التشغيلي'] : ($merchant ? ['owner_identity'=>'هوية مالك المنشأة','business_registration'=>'السجل أو الترخيص','operating_address'=>'إثبات عنوان النشاط'] : ['identity_document'=>'وثيقة الهوية']);
    if ($kind === 'customer') $fileKinds = ['identity_document'=>'وثيقة الهوية (مطلوبة للذكر أو مستوى 2/3 للأنثى)'];
    $files=''; foreach ($fileKinds as $name=>$label) $files.='<label>'.$label.'</label><input type="file" name="'.$name.'" accept="application/pdf,image/jpeg,image/png,image/webp">';
    return '<div class="card" style="max-width:720px;margin:30px auto"><h2>'.$title.'</h2>'.$agentNote.'<p class="muted">تُحفظ بيانات الطلب وأدلته مشفّرة في موثّق. لا تُنشأ محفظة أو بيانات دخول هنا.</p><form method="post" action="'.e($action).'" enctype="multipart/form-data"><input type="hidden" name="_token" value="'.e($token).'"><input type="hidden" name="subject_kind" value="'.e($kind).'"><label>الاسم الأول</label><input name="f_name" required><label>اسم العائلة</label><input name="l_name" required><label>الهاتف</label><input name="phone" inputmode="tel" required><label>البريد الإلكتروني</label><input type="email" name="email" required>'.$gender.$business.'<h3>أدلة التحقق</h3>'.$files.'<label><input type="checkbox" name="terms" value="1" required style="width:auto"> أقر بصحة البيانات وأوافق على مراجعتها من موثّق.</label><br><br><button class="btn" type="submit">إرسال طلب موثّق</button></form></div>';
}

try {
    if ($path === '/health') {
        Database::pdo()->query('SELECT 1');
        jsonResponse(['status' => 'ok', 'service' => 'muwathaq']);
    }

    if ($path === '/api/v1/internal/registration-contract') {
        if ($method !== 'POST') throw new HttpError(405, 'METHOD_NOT_ALLOWED', 'الطريقة غير مسموحة.');
        $body = file_get_contents('php://input') ?: '';
        Signature::verifyAmialRequest($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $selector = $payload['selector'] ?? [];
        if (!is_array($selector)) throw new HttpError(422, 'INVALID_SELECTOR', 'محدد سياسة فتح الحساب غير صالح.');
        jsonResponse(['success' => true, 'data' => Policy::registrationContract($selector)]);
    }

    if ($path === '/api/v1/internal/applications') {
        if ($method !== 'POST') throw new HttpError(405, 'METHOD_NOT_ALLOWED', 'الطريقة غير مسموحة.');
        $body = file_get_contents('php://input') ?: '';
        Signature::verifyAmialRequest($body);
        $event = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $application = $payload['application'] ?? [];
        foreach (['application_ulid', 'subject_kind', 'verification_path'] as $key) {
            if (!is_string($application[$key] ?? null) || trim($application[$key]) === '') {
                throw new HttpError(422, 'INVALID_APPLICATION', 'بيانات طلب فتح الحساب غير مكتملة.');
            }
        }
        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $event)) {
            throw new HttpError(422, 'INVALID_IDEMPOTENCY_KEY', 'مفتاح منع التكرار غير صحيح.');
        }
        Policy::for($application);
        $db = Database::pdo();
        $check = $db->prepare('SELECT case_ulid FROM muwathaq_cases WHERE core_event_ulid = ? OR core_application_ulid = ? LIMIT 1');
        $check->execute([$event, $application['application_ulid']]);
        if ($existing = $check->fetch()) {
            jsonResponse(['accepted' => true, 'case_ulid' => $existing['case_ulid'], 'duplicate' => true], 202);
        }
        $caseUlid = strtoupper(bin2hex(random_bytes(13)));
        $caseUlid = substr($caseUlid, 0, 26); // مرجع فريد داخلي؛ ليس معلومة عميل.
        $time = now();
        $insert = $db->prepare('INSERT INTO muwathaq_cases (case_ulid,core_event_ulid,core_application_ulid,subject_kind,verification_path,business_type,requested_tier,status,payload_encrypted,received_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([$caseUlid, $event, $application['application_ulid'], $application['subject_kind'], $application['verification_path'], $application['business_type'] ?? null, $application['requested_tier'] ?? null, 'submitted', Cipher::encrypt($body), $time, $time, $time]);
        jsonResponse(['accepted' => true, 'case_ulid' => $caseUlid, 'duplicate' => false], 202);
    }

    if (preg_match('#^/api/v1/internal/applications/([0-9A-HJKMNP-TV-Z]{26})/evidence$#', $path, $match)) {
        if ($method !== 'POST') throw new HttpError(405, 'METHOD_NOT_ALLOWED', 'الطريقة غير مسموحة.');
        $body = file_get_contents('php://input') ?: '';
        Signature::verifyAmialRequest($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $evidence = $payload['evidence'] ?? null;
        if (!is_array($evidence) || !is_int($evidence['id'] ?? null) || !is_string($evidence['kind'] ?? null)) {
            throw new HttpError(422, 'INVALID_EVIDENCE', 'بيانات الدليل غير صالحة.');
        }
        $lookup = Database::pdo()->prepare('SELECT * FROM muwathaq_cases WHERE core_application_ulid = ?');
        $lookup->execute([$match[1]]);
        $case = $lookup->fetch();
        if (!$case) throw new HttpError(404, 'CASE_NOT_FOUND', 'قضية موثّق غير موجودة.');
        $items = $case['evidence_encrypted'] ? Cipher::decryptJson($case['evidence_encrypted']) : [];
        $items[(string) $evidence['kind']] = [
            'id' => $evidence['id'],
            'kind' => $evidence['kind'],
            'state' => (string) ($evidence['state'] ?? 'submitted'),
            'document_received' => (bool) ($evidence['document_received'] ?? false),
            'document_mime' => $evidence['document_mime'] ?? null,
            'submitted_at' => $evidence['submitted_at'] ?? null,
        ];
        Database::pdo()->prepare('UPDATE muwathaq_cases SET evidence_encrypted=?,status=CASE WHEN status="submitted" THEN "under_review" ELSE status END,updated_at=? WHERE id=?')
            ->execute([Cipher::encrypt(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), now(), $case['id']]);
        jsonResponse(['accepted' => true], 202);
    }


    if ($path === '/open-account') {
        if ($method === 'POST') {
            assertCsrf();
            if (empty($_POST['terms'])) throw new HttpError(422, 'TERMS_REQUIRED', 'يلزم قبول إقرار صحة البيانات.');
            $kind = (string) ($_POST['subject_kind'] ?? '');
            if (!in_array($kind, ['customer', 'merchant'], true)) throw new HttpError(403, 'PUBLIC_ACCOUNT_TYPE_FORBIDDEN', 'هذه البوابة تفتح حساب العميل أو التاجر فقط.');
            $case = createMuwathaqOwnedCase($_POST, $_FILES, null);
            redirect('/open-account?submitted='.rawurlencode($case['case_ulid']));
        }
        $submitted = trim((string) ($_GET['submitted'] ?? ''));
        $message = $submitted !== '' ? '<div class="card"><h2>تم استلام طلبك</h2><p>مرجع موثّق: <b>'.e($submitted).'</b>. بعد الاعتماد فقط يهيئ أميال باي المحفظة ويرسل طريقة الدخول الآمنة.</p></div>' : '<div class="card"><h2>فتح حساب عبر موثّق</h2><a class="btn" href="/open-account?type=customer">حساب عميل</a> <a class="btn" href="/open-account?type=merchant">حساب تاجر</a></div>';
        $type = (string) ($_GET['type'] ?? '');
        if (in_array($type, ['customer','merchant'], true)) $message = accountOpeningForm($type, '/open-account', csrf());
        layout('فتح حساب', $message);
    }

    if ($path === '/login') {
        if ($method === 'POST') {
            assertCsrf();
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $credential = ReviewerCredentials::current();
            if ($credential === null
                || !hash_equals(mb_strtolower($credential['email']), $email)
                || !password_verify((string) ($_POST['password'] ?? ''), $credential['password_hash'])) {
                layout('دخول المراجع', '<div class="card error">بيانات الدخول غير صحيحة.</div>'.loginForm());
            }
            session_regenerate_id(true);
            $_SESSION['reviewer'] = $credential['email'];
            ReviewerAudit::record($credential['email'], 'session_started');
            redirect('/');
        }
        layout('دخول المراجع', loginForm());
    }

    if ($path === '/logout') { session_destroy(); redirect('/login'); }

    $reviewer = requireReviewer();

    if ($path === '/accounts/new-agent') {
        if ($method === 'POST') {
            assertCsrf();
            if (empty($_POST['terms'])) throw new HttpError(422, 'TERMS_REQUIRED', 'يلزم إقرار صحة بيانات الوكيل.');
            $case = createMuwathaqOwnedCase($_POST, $_FILES, $reviewer);
            ReviewerAudit::record($reviewer, 'agent_opening_created', $case);
            redirect('/cases/'.$case['case_ulid']);
        }
        layout('إنشاء وكيل', accountOpeningForm('agent', '/accounts/new-agent', csrf(), true));
    }

    if ($path === '/') {
        $db = Database::pdo();
        $counts = $db->query("SELECT status, COUNT(*) total FROM muwathaq_cases GROUP BY status")->fetchAll();
        $map = array_column($counts, 'total', 'status');
        $cases = $db->query("SELECT * FROM muwathaq_cases ORDER BY FIELD(status,'submitted','needs_more_info','under_review','approved','rejected'), received_at DESC LIMIT 100")->fetchAll();
        $html = '<div class="grid"><div class="stat">طلبات جديدة<br><b>'.(int)($map['submitted'] ?? 0).'</b></div><div class="stat">قيد المراجعة<br><b>'.(int)($map['under_review'] ?? 0).'</b></div><div class="stat">تحتاج استكمالاً<br><b>'.(int)($map['needs_more_info'] ?? 0).'</b></div><div class="stat">بانتظار تسليم القرار<br><b>'.(int)$db->query("SELECT COUNT(*) FROM muwathaq_cases WHERE core_delivery_status = 'pending' AND decision IS NOT NULL")->fetchColumn().'</b></div></div><div class="card"><h2>قضايا فتح الحساب</h2><table><tr><th>القضية</th><th>النوع/المسار</th><th>الحالة</th><th class="hide-mobile">الاستلام</th><th></th></tr>';
        foreach ($cases as $case) {
            $html .= '<tr><td>'.e($case['case_ulid']).'</td><td>'.e($case['subject_kind']).'<br><span class="muted">'.e($case['verification_path']).'</span></td><td><span class="tag '.e($case['status']).'">'.e($case['status']).'</span></td><td class="hide-mobile">'.e($case['received_at']).'</td><td><a class="btn secondary" href="/cases/'.e($case['case_ulid']).'">فتح</a></td></tr>';
        }
        layout('قضايا موثّق', $html.'</table></div>');
    }


    if ($path === '/accounts') {
        $list = (string) ($_GET['list'] ?? 'all');
        $labels = [
            'all' => 'كل الحسابات', 'new' => 'الحسابات الجديدة', 'customer_female' => 'عملاء — إناث',
            'customer_male' => 'عملاء — ذكور', 'merchant' => 'حسابات التجار', 'agent' => 'حسابات الوكلاء',
            'active' => 'الحسابات النشطة', 'suspended' => 'الحسابات الموقوفة', 'review' => 'حسابات قيد المراجعة',
        ];
        if (!array_key_exists($list, $labels)) throw new HttpError(404, 'ACCOUNT_LIST_NOT_FOUND', 'قائمة الحسابات غير موجودة.');
        $search = trim((string) ($_GET['q'] ?? ''));
        ReviewerAudit::record($reviewer, 'directory_opened', null, ['list' => $list]);
        try {
            $directory = CoreOperationsClient::directory($list, $search);
            $rows = (array) ($directory['data'] ?? []);
            $html = '<div class="card"><div class="toolbar"><div><h2>'.e($labels[$list]).'</h2><p class="muted">البيانات التشغيلية تقرأ مباشرة من أميال باي. لا تُنسخ إلى موثّق.</p></div><form method="get"><input type="hidden" name="list" value="'.e($list).'"><input name="q" value="'.e($search).'" placeholder="بحث بالاسم أو الهاتف أو المرجع"><button class="btn" type="submit">بحث</button></form></div><table><tr><th>صاحب الحساب</th><th>النوع</th><th>الحالة</th><th class="hide-mobile">المسار/القطاع</th><th class="hide-mobile">تاريخ الطلب</th><th></th></tr>';
            foreach ($rows as $row) {
                $applicant = (array) ($row['applicant'] ?? []);
                $case = findCaseForApplication((string) ($row['application_ulid'] ?? ''));
                $details = $case
                    ? '<a class="btn secondary" href="/cases/'.e($case['case_ulid']).'">تفاصيل التحقق</a>'
                    : '<span class="muted">لم تصل قضية موثّق بعد</span>';
                $html .= '<tr><td><b>'.e($applicant['name'] ?? '—').'</b><br><span class="muted">'.e($applicant['phone'] ?? '—').'</span></td><td>'.e($row['account_type'] ?? '—').'<br><span class="muted">'.e($row['customer_gender'] ?? '').'</span></td><td><span class="tag '.e($row['operational_status'] ?? 'pending').'">'.e($row['operational_status'] ?? 'pending').'</span><br><span class="muted">'.e($row['opening_status'] ?? '').'</span></td><td class="hide-mobile">'.e($row['verification_path'] ?? '—').'<br><span class="muted">'.e($row['business_type'] ?? '').'</span></td><td class="hide-mobile">'.e($row['submitted_at'] ?? '—').'</td><td>'.$details.'</td></tr>';
            }
            if ($rows === []) $html .= '<tr><td colspan="6" class="muted">لا توجد حسابات ضمن هذا التصنيف.</td></tr>';
            layout($labels[$list], $html.'</table></div>');
        } catch (Throwable $error) {
            error_log((string) $error);
            layout($labels[$list], '<div class="card error"><h2>تعذر جلب دليل الحسابات</h2><p>تحقق من اتصال موثّق الداخلي بأميال باي ثم أعد المحاولة.</p></div>');
        }
    }

    if ($path === '/audit') {
        $events = Database::pdo()->query('SELECT reviewer_identity,action,case_ulid,delivery_status,occurred_at FROM muwathaq_reviewer_audit_outbox ORDER BY id DESC LIMIT 200')->fetchAll();
        $html = '<div class="card"><h2>سجل نشاط الجلسة</h2><p class="muted">يحفظ محلياً أولاً ثم يُرسل إلى سجل تدقيق أميال باي. لا يتضمن مستندات أو بيانات هوية.</p><table><tr><th>الموظفة</th><th>الإجراء</th><th>القضية</th><th>حالة الإرسال</th><th>الوقت</th></tr>';
        foreach ($events as $event) $html .= '<tr><td>'.e($event['reviewer_identity']).'</td><td>'.e($event['action']).'</td><td>'.e($event['case_ulid'] ?? '—').'</td><td><span class="tag '.e($event['delivery_status']).'">'.e($event['delivery_status']).'</span></td><td>'.e($event['occurred_at']).'</td></tr>';
        if ($events === []) $html .= '<tr><td colspan="5" class="muted">لا يوجد نشاط مسجل بعد.</td></tr>';
        layout('سجل نشاط الجلسة', $html.'</table></div>');
    }

    if (preg_match('#^/cases/([0-9A-F]{26})$#', $path, $match)) {
        $case = findCase($match[1]);
        ReviewerAudit::record($reviewer, 'case_opened', $case);
        $payload = Cipher::decryptJson($case['payload_encrypted']);
        $applicant = $payload['applicant'] ?? [];
        $policy = Policy::for($case);
        $requirements = '';
        foreach ($policy['requirements'] as $key => $label) $requirements .= '<div class="req"><label><input type="checkbox" name="affirmed[]" value="'.e($key).'" style="width:auto"> '.e($label).'</label></div>';
        $subject = $payload['subject'] ?? [];
        $evidence = $case['evidence_encrypted'] ? Cipher::decryptJson($case['evidence_encrypted']) : [];
        $evidenceHtml = '';
        foreach ($evidence as $item) {
            $documentLink = !empty($item['document_received']) && is_string($item['kind'] ?? null)
                ? ' · <a href="/cases/'.e($case['case_ulid']).'/evidence/'.e($item['kind']).'/document" target="_blank" rel="noopener">عرض المستند الآمن</a>'
                : '';
            $evidenceHtml .= '<div class="req"><b>'.e($item['kind'] ?? 'evidence').'</b> · '.e($item['state'] ?? 'submitted')
                .$documentLink.'<br><span class="muted">'.e($item['submitted_at'] ?? '').'</span></div>';
        }
        if ($evidenceHtml === '') $evidenceHtml = '<div class="alert">لم تصل أدلة إكمال من أميال باي بعد.</div>';
        $content = '<a class="btn secondary" href="/">العودة للقضايا</a><div class="card"><h2>قضية '.e($case['case_ulid']).'</h2><p><span class="tag">'.e($case['status']).'</span> <span class="muted">سياسة '.e($policy['version']).'</span></p><div class="grid"><div><b>صاحب الطلب</b><br>'.e($applicant['legal_name'] ?? '—').'<br>'.e($applicant['phone'] ?? '—').'<br>'.e($applicant['email'] ?? '—').'</div><div><b>نوع الحساب</b><br>'.e($case['subject_kind']).'<br>'.e($case['verification_path']).'<br>'.e($case['business_type'] ?? '—').'</div><div><b>تفاصيل النشاط</b><br>'.e(json_encode($subject, JSON_UNESCAPED_UNICODE) ?: '—').'</div></div></div><div class="card"><h3>أدلة الإكمال الواردة</h3><p class="muted">لا تعتمد القضية لمجرد وصول ملخص؛ افتح المستند عبر القناة المحمية ثم أقرّ بالمتطلبات.</p>'.$evidenceHtml.'</div>';
        // المحافظة ليست قيمة افتراضية: يلزم اختيارها صراحة عند الاعتماد.
        $governorates = '<option value="">اختر المحافظة بعد مراجعة أدلة الإقامة</option>';
        foreach (Policy::GOVERNORATES as $code => $name) $governorates .= '<option value="'.e($code).'">'.e($name).' ('.e($code).')</option>';
        $content .= '<form method="post" action="/cases/'.e($case['case_ulid']).'/decision" class="card"><input type="hidden" name="_token" value="'.e(csrf()).'"><h3>قرار المراجع</h3><p class="muted">لا تعتمد إلا بعد رؤية الأدلة في موثّق وإقرار البنود التالية.</p>'.$requirements.'<label>القرار</label><select name="decision"><option value="under_review">قيد المراجعة</option><option value="needs_more_info">طلب استكمال</option><option value="approved">اعتماد</option><option value="rejected">رفض</option></select><label>محافظة الإقامة أو الموقع التشغيلي</label><select name="governorate">'.$governorates.'</select><p class="muted">اختيار المحافظة شرط للاعتماد فقط، ولا يلزم للرفض أو طلب الاستكمال.</p><label>ملاحظة للقرار</label><textarea name="reason" rows="3" maxlength="1000"></textarea><button class="btn" type="submit">حفظ القرار وإرساله لأميال باي</button></form>';
        if ($case['decision']) $content .= '<div class="card"><b>آخر قرار:</b> '.e($case['decision']).' · حالة الإرسال لأميال باي: '.e($case['core_delivery_status']).'<br>'.e($case['core_last_error'] ?? '').'</div>';
        layout('مراجعة القضية', $content);
    }


    if (preg_match('#^/cases/([0-9A-F]{26})/evidence/([a-z_]+)/document$#', $path, $match)) {
        $case = findCase($match[1]);
        if (($case['origin'] ?? 'amial_legacy') === 'muwathaq') {
            ReviewerAudit::record($reviewer, 'evidence_opened', $case);
            $statement = Database::pdo()->prepare('SELECT document_encrypted,document_mime FROM muwathaq_case_evidence WHERE case_id=? AND kind=?');
            $statement->execute([$case['id'], $match[2]]); $document = $statement->fetch();
            if (!$document || empty($document['document_encrypted'])) throw new HttpError(404, 'EVIDENCE_DOCUMENT_NOT_FOUND', 'المستند غير متاح.');
            header('Content-Type: '.($document['document_mime'] ?: 'application/octet-stream')); header('X-Content-Type-Options: nosniff'); echo Cipher::decrypt($document['document_encrypted']); exit;
        }
    }

    if (preg_match('#^/cases/([0-9A-F]{26})/evidence/([a-z_]+)/document$#', $path, $match)) {
        $case = findCase($match[1]);
        ReviewerAudit::record($reviewer, 'evidence_opened', $case);
        $items = $case['evidence_encrypted'] ? Cipher::decryptJson($case['evidence_encrypted']) : [];
        $item = $items[$match[2]] ?? null;
        if (!is_array($item) || empty($item['document_received']) || !is_int($item['id'] ?? null)) {
            throw new HttpError(404, 'EVIDENCE_DOCUMENT_NOT_FOUND', 'مستند الدليل غير متاح لهذه القضية.');
        }
        CoreEvidenceClient::stream($case['core_application_ulid'], $item['id'], $reviewer);
    }

    if (preg_match('#^/cases/([0-9A-F]{26})/decision$#', $path, $match)) {
        if ($method !== 'POST') throw new HttpError(405, 'METHOD_NOT_ALLOWED', 'الطريقة غير مسموحة.');
        assertCsrf();
        $case = findCase($match[1]);
        if (in_array($case['status'], ['approved', 'rejected'], true)) throw new HttpError(409, 'FINAL_CASE', 'القضية حُسمت سابقاً ولا تقبل قراراً جديداً.');
        $decision = (string) ($_POST['decision'] ?? '');
        if (!in_array($decision, ['under_review', 'needs_more_info', 'approved', 'rejected'], true)) throw new HttpError(422, 'INVALID_DECISION', 'قرار غير صحيح.');
        $governorate = trim((string) ($_POST['governorate'] ?? ''));
        if ($decision === 'approved') {
            $evidence = $case['evidence_encrypted'] ? Cipher::decryptJson($case['evidence_encrypted']) : [];
            foreach (Policy::evidenceKinds($case) as $kind) {
                if (($evidence[$kind]['state'] ?? null) !== 'submitted') {
                    throw new HttpError(422, 'EVIDENCE_NOT_RECEIVED', 'لا يمكن الاعتماد قبل وصول جميع الأدلة المطلوبة من أميال باي.');
                }
            }
            foreach (Policy::documentEvidenceKinds($case) as $kind) {
                if (empty($evidence[$kind]['document_received'])) {
                    throw new HttpError(422, 'DOCUMENT_NOT_RECEIVED', 'هذا المسار يتطلب مستنداً محمياً لم يصل إلى موثّق.');
                }
            }
        }
        $context = $decision === 'approved' ? Policy::decisionContext($case, (array) ($_POST['affirmed'] ?? []), $governorate) : ['policy_version' => Policy::for($case)['version']];
        $reference = 'MWT-'.strtoupper(substr(hash('sha256', $case['case_ulid'].'|'.$decision.'|'.microtime(true)), 0, 20));
        $statement = Database::pdo()->prepare('UPDATE muwathaq_cases SET status=?,review_started_at=COALESCE(review_started_at,?),reviewer_identity=?,policy_version=?,decision=?,decision_reference=?,decision_reason=?,decision_context_json=?,core_delivery_status="pending",core_last_error=NULL,updated_at=? WHERE id=?');
        $statement->execute([$decision, now(), $reviewer, $context['policy_version'], $decision, $reference, trim((string) ($_POST['reason'] ?? '')) ?: null, json_encode($context, JSON_UNESCAPED_UNICODE), now(), $case['id']]);
        $case = findCase($match[1]);
        ReviewerAudit::record($reviewer, 'decision_recorded', $case, ['decision' => $decision, 'delivery_status' => 'pending']);
        try {
            if (($case['origin'] ?? 'amial_legacy') === 'muwathaq') CoreProvisioningClient::deliver($case);
            else CoreDecisionClient::deliver($case);
            Database::pdo()->prepare('UPDATE muwathaq_cases SET core_delivery_status="delivered",core_delivered_at=?,core_last_error=NULL,updated_at=? WHERE id=?')->execute([now(), now(), $case['id']]);
        } catch (Throwable $error) {
            Database::pdo()->prepare('UPDATE muwathaq_cases SET core_delivery_status="pending",core_last_error=?,updated_at=? WHERE id=?')->execute([mb_substr($error->getMessage(), 0, 500), now(), $case['id']]);
        }
        redirect('/cases/'.$match[1]);
    }

    throw new HttpError(404, 'NOT_FOUND', 'الصفحة غير موجودة.');
} catch (HttpError $error) {
    if (str_starts_with($path, '/api/')) jsonResponse(['success' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()], $error->status);
    http_response_code($error->status);
    layout('خطأ', '<div class="card error">'.e($error->getMessage()).'</div>');
} catch (Throwable $error) {
    error_log((string) $error);
    if (str_starts_with($path, '/api/')) jsonResponse(['success' => false, 'code' => 'INTERNAL_ERROR', 'message' => 'تعذر تنفيذ العملية.'], 500);
    http_response_code(500);
    layout('خطأ تقني', '<div class="card error">تعذر تنفيذ العملية. راجع إعدادات الخدمة.</div>');
}

function loginForm(): string
{
    return '<form method="post" class="card" style="max-width:420px;margin:80px auto"><input type="hidden" name="_token" value="'.e(csrf()).'"><h2>دخول مراجع موثّق</h2><p class="muted">واجهة داخلية للمراجعين المخولين فقط.</p><label>البريد</label><input type="email" name="email" required><label>كلمة المرور</label><input type="password" name="password" required><button class="btn" type="submit">دخول آمن</button></form>';
}
