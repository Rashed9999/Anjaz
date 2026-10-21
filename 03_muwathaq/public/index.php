<?php

declare(strict_types=1);

use Muwathaq\Cipher;
use Muwathaq\Config;
use Muwathaq\CoreEvidenceClient;
use Muwathaq\CoreDecisionClient;
use Muwathaq\Database;
use Muwathaq\HttpError;
use Muwathaq\Policy;
use Muwathaq\ReviewerCredentials;
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
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' | موثّق</title><style>
    body{margin:0;background:#f3f6fa;color:#172033;font:15px Tahoma,Arial,sans-serif}.top{background:#0d2b61;color:#fff;padding:17px 6%;display:flex;justify-content:space-between;align-items:center}.top a{color:#fff;text-decoration:none}.wrap{max-width:1120px;margin:28px auto;padding:0 16px}.card{background:#fff;border:1px solid #e4e9f2;border-radius:14px;padding:20px;margin:16px 0;box-shadow:0 4px 18px #0d2b6110}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.stat{padding:16px;border-radius:12px;background:#f5f8fd}.muted{color:#667085}.tag{display:inline-block;padding:4px 9px;border-radius:20px;background:#e9eef7;font-size:12px}.pending{background:#fff2cc}.approved{background:#d9f5df}.rejected{background:#ffe1e1}.btn{display:inline-block;background:#1268d6;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer;text-decoration:none}.btn.danger{background:#b42318}.btn.secondary{background:#eef2f8;color:#172033}input,select,textarea{box-sizing:border-box;width:100%;padding:10px;border:1px solid #cfd8e6;border-radius:8px;margin:5px 0 12px}label{font-weight:bold}table{width:100%;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #e9edf3;text-align:right}.alert{padding:12px;border-radius:8px;background:#fff2cc}.error{background:#ffe3e3}.req{padding:9px;background:#f7f9fc;border-radius:8px;margin:5px 0}@media(max-width:600px){table{font-size:12px}.hide-mobile{display:none}}
    </style></head><body><header class="top"><a href="/">موثّق — مركز مراجعة فتح الحساب</a>'.($reviewer ? '<span>'.e($reviewer).' · <a href="/logout">خروج</a></span>' : '').'</header><main class="wrap">'.$content.'</main></body></html>';
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
            redirect('/');
        }
        layout('دخول المراجع', loginForm());
    }

    if ($path === '/logout') { session_destroy(); redirect('/login'); }

    $reviewer = requireReviewer();
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

    if (preg_match('#^/cases/([0-9A-F]{26})$#', $path, $match)) {
        $case = findCase($match[1]);
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
        $governorates = '';
        foreach (Policy::GOVERNORATES as $code => $name) $governorates .= '<option value="'.e($code).'">'.e($name).' ('.e($code).')</option>';
        $content .= '<form method="post" action="/cases/'.e($case['case_ulid']).'/decision" class="card"><input type="hidden" name="_token" value="'.e(csrf()).'"><h3>قرار المراجع</h3><p class="muted">لا تعتمد إلا بعد رؤية الأدلة في موثّق وإقرار البنود التالية.</p>'.$requirements.'<label>القرار</label><select name="decision"><option value="under_review">قيد المراجعة</option><option value="needs_more_info">طلب استكمال</option><option value="approved">اعتماد</option><option value="rejected">رفض</option></select><label>محافظة الإقامة أو الموقع التشغيلي</label><select name="governorate">'.$governorates.'</select><label>ملاحظة للقرار</label><textarea name="reason" rows="3" maxlength="1000"></textarea><button class="btn" type="submit">حفظ القرار وإرساله لأميال باي</button></form>';
        if ($case['decision']) $content .= '<div class="card"><b>آخر قرار:</b> '.e($case['decision']).' · حالة الإرسال لأميال باي: '.e($case['core_delivery_status']).'<br>'.e($case['core_last_error'] ?? '').'</div>';
        layout('مراجعة القضية', $content);
    }

    if (preg_match('#^/cases/([0-9A-F]{26})/evidence/([a-z_]+)/document$#', $path, $match)) {
        $case = findCase($match[1]);
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
        if ($governorate === '') $governorate = 'YE-AD';
        if ($decision === 'approved' && $case['verification_path'] === 'women_progressive') {
            $evidence = $case['evidence_encrypted'] ? Cipher::decryptJson($case['evidence_encrypted']) : [];
            foreach (['phone', 'residence'] as $kind) {
                if (($evidence[$kind]['state'] ?? null) !== 'submitted') {
                    throw new HttpError(422, 'EVIDENCE_NOT_RECEIVED', 'لا يمكن اعتماد حساب نسائي قبل وصول دليل الهاتف والسكن إلى موثّق.');
                }
            }
        }
        $context = $decision === 'approved' ? Policy::decisionContext($case, (array) ($_POST['affirmed'] ?? []), $governorate) : ['policy_version' => Policy::for($case)['version']];
        $reference = 'MWT-'.strtoupper(substr(hash('sha256', $case['case_ulid'].'|'.$decision.'|'.microtime(true)), 0, 20));
        $statement = Database::pdo()->prepare('UPDATE muwathaq_cases SET status=?,review_started_at=COALESCE(review_started_at,?),reviewer_identity=?,policy_version=?,decision=?,decision_reference=?,decision_reason=?,decision_context_json=?,core_delivery_status="pending",core_last_error=NULL,updated_at=? WHERE id=?');
        $statement->execute([$decision, now(), $reviewer, $context['policy_version'], $decision, $reference, trim((string) ($_POST['reason'] ?? '')) ?: null, json_encode($context, JSON_UNESCAPED_UNICODE), now(), $case['id']]);
        $case = findCase($match[1]);
        try {
            CoreDecisionClient::deliver($case);
            Database::pdo()->prepare('UPDATE muwathaq_cases SET core_delivery_status="delivered",core_delivered_at=?,core_last_error=NULL,updated_at=? WHERE id=?')->execute([now(), now(), $case['id']]);
        } catch (Throwable $error) {
            Database::pdo()->prepare('UPDATE muwathaq_cases SET core_delivery_status="pending",core_last_error=?,updated_at=? WHERE id=?')->execute([mb_substr($error->getMessage(), 0, 500), now(), $case['id']]);
        }
        redirect('/cases/'.$match[1]);
    }

    throw new HttpError(404, 'NOT_FOUND', 'الصفحة غير موجودة.');
} catch (HttpError $error) {
    if (str_starts_with($path, '/api/')) jsonResponse(['success' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()], $error->status);
    layout('خطأ', '<div class="card error">'.e($error->getMessage()).'</div>');
} catch (Throwable $error) {
    error_log((string) $error);
    if (str_starts_with($path, '/api/')) jsonResponse(['success' => false, 'code' => 'INTERNAL_ERROR', 'message' => 'تعذر تنفيذ العملية.'], 500);
    layout('خطأ تقني', '<div class="card error">تعذر تنفيذ العملية. راجع إعدادات الخدمة.</div>');
}

function loginForm(): string
{
    return '<form method="post" class="card" style="max-width:420px;margin:80px auto"><input type="hidden" name="_token" value="'.e(csrf()).'"><h2>دخول مراجع موثّق</h2><p class="muted">واجهة داخلية للمراجعين المخولين فقط.</p><label>البريد</label><input type="email" name="email" required><label>كلمة المرور</label><input type="password" name="password" required><button class="btn" type="submit">دخول آمن</button></form>';
}
