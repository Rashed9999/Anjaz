<?php

namespace App\Services\Muwathaq;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\DocumentReuseService;
use App\Services\Kyc\IdentityExpiryService;
use App\Services\Kyc\LegalNameService;
use App\Services\KycOcrService;
use App\Services\SanctionScreeningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الفحوص الآلية التي يملكها أميال باي اليوم، تُرسل مع طلب المراجعة إلى موثّق لتظهر للمراجع
 * إشاراتٍ لا قراراً. قراءة فقط: لا يُكتب شيء على الحساب (فحص العقوبات بالاسم لا screenUser).
 *
 * كل إشارة: pass | warn | fail | unknown. «غير معروف» ليس نجاحاً (القاعدة السابعة):
 * قائمة عقوبات فارغة أو OCR لم يعمل يُقالان صراحةً مع السبب.
 */
class MuwathaqPrecheckService
{
    /**
     * @param list<KycDocument> $documents
     * @return array{version:int, generated_at:string, signals:list<array{key:string,label:string,status:string,detail:string}>, ocr:list<array<string,mixed>>}
     */
    public function for(User $user, array $documents): array
    {
        $signals = [];
        $ocrRows = [];
        $ocrName = null;
        $ocrId = null;

        foreach ($documents as $doc) {
            try {
                $r = app(KycOcrService::class)->forReviewer($doc);
                if (!$r['applicable']) continue;
                $fields = (array) $r['fields'];
                $ocrRows[] = [
                    'kind' => $doc->doc_type, 'status' => (string) $r['status'], 'engine' => $r['engine'],
                    'confidence' => round((float) $r['confidence'], 1), 'min_confidence' => (float) $r['min_confidence'],
                    'fields' => array_intersect_key($fields, array_flip(['full_name', 'national_id', 'date_of_birth', 'expiry_date', 'gender'])),
                ];
                $ocrName ??= isset($fields['full_name']) ? (string) (is_array($fields['full_name']) ? ($fields['full_name']['value'] ?? '') : $fields['full_name']) : null;
                $ocrId ??= isset($fields['national_id']) ? (string) (is_array($fields['national_id']) ? ($fields['national_id']['value'] ?? '') : $fields['national_id']) : null;
            } catch (\Throwable) {
                // يبقى بلا سطر OCR؛ إشارة القراءة أدناه تقول «غير معروف».
            }
        }

        // ── قراءة الوثيقة آلياً ──
        $ran = array_values(array_filter($ocrRows, fn ($r) => !in_array($r['status'], ['not_run', 'disabled', ''], true)));
        $signals[] = $ran === []
            ? $this->s('ocr', 'قراءة الوثيقة آلياً (OCR)', 'unknown', 'لم تُشغَّل القراءة الآلية على هذه الوثائق أو أنها معطلة.')
            : (min(array_column($ran, 'confidence')) >= (float) $ran[0]['min_confidence']
                ? $this->s('ocr', 'قراءة الوثيقة آلياً (OCR)', 'pass', 'ثقة القراءة '.min(array_column($ran, 'confidence')).'% (الحد '.$ran[0]['min_confidence'].'%).')
                : $this->s('ocr', 'قراءة الوثيقة آلياً (OCR)', 'warn', 'ثقة القراءة '.min(array_column($ran, 'confidence')).'% دون الحد '.$ran[0]['min_confidence'].'% — راجع الصورة بعناية.'));

        // ── مطابقة الاسم المصرّح به بالاسم المقروء ──
        try {
            if ($ocrName === null || trim($ocrName) === '') {
                $signals[] = $this->s('name_match', 'مطابقة الاسم بالوثيقة', 'unknown', 'لا اسم مقروء آلياً من الوثيقة.');
            } else {
                $c = app(LegalNameService::class)->compare($user, $ocrName);
                $signals[] = $this->s('name_match', 'مطابقة الاسم بالوثيقة', match ($c['status']) {
                    LegalNameService::STATUS_EXACT, LegalNameService::STATUS_STRONG => 'pass',
                    LegalNameService::STATUS_PARTIAL => 'warn',
                    LegalNameService::STATUS_MISMATCH => 'fail',
                    default => 'unknown',
                }, 'نسبة التطابق '.(int) $c['score'].'% بين المصرّح به والمقروء.');
            }
        } catch (\Throwable) {
            $signals[] = $this->s('name_match', 'مطابقة الاسم بالوثيقة', 'unknown', 'تعذّر حساب المطابقة.');
        }

        // ── رقم الوثيقة ──
        $declaredId = preg_replace('/\D+/', '', (string) ($user->identification_number ?? ''));
        $readId = preg_replace('/\D+/', '', (string) $ocrId);
        $signals[] = $readId === '' || $declaredId === ''
            ? $this->s('id_number', 'رقم الوثيقة', 'unknown', $declaredId === '' ? 'لا رقم مصرّح به في الحساب.' : 'لا رقم مقروء آلياً من الوثيقة.')
            : ($readId === $declaredId
                ? $this->s('id_number', 'رقم الوثيقة', 'pass', 'الرقم المقروء يطابق المصرّح به.')
                : $this->s('id_number', 'رقم الوثيقة', 'fail', 'الرقم المقروء يختلف عن المصرّح به.'));

        // ── صلاحية الهوية ──
        try {
            $e = app(IdentityExpiryService::class)->stateOf($user);
            $signals[] = match ($e['state']) {
                IdentityExpiryService::STATE_VALID => $this->s('expiry', 'صلاحية الهوية', 'pass', 'سارية حتى '.$e['expires_at'].'.'),
                IdentityExpiryService::STATE_DUE => $this->s('expiry', 'صلاحية الهوية', 'warn', 'تنتهي خلال '.$e['days'].' يوماً.'),
                IdentityExpiryService::STATE_EXPIRED => $this->s('expiry', 'صلاحية الهوية', 'fail', 'منتهية منذ '.abs((int) $e['days']).' يوماً.'),
                default => $this->s('expiry', 'صلاحية الهوية', 'unknown', 'لا تاريخ انتهاء معروف.'),
            };
        } catch (\Throwable) {
            $signals[] = $this->s('expiry', 'صلاحية الهوية', 'unknown', 'تعذّر قراءة الصلاحية.');
        }

        // ── إعادة استعمال المستند/الرقم في حساب آخر ──
        try {
            $f = app(DocumentReuseService::class)->findingsFor($user);
            $signals[] = $f['blockers'] !== []
                ? $this->s('reuse', 'تكرار المستند أو الرقم في حساب آخر', 'fail', count($f['blockers']).' تطابق مانع مع حساب آخر.')
                : ($f['warnings'] !== []
                    ? $this->s('reuse', 'تكرار المستند أو الرقم في حساب آخر', 'warn', count($f['warnings']).' تطابق يحتاج تحققاً.')
                    : $this->s('reuse', 'تكرار المستند أو الرقم في حساب آخر', 'pass', 'لا تطابق مع حسابات أخرى.'));
        } catch (\Throwable) {
            $signals[] = $this->s('reuse', 'تكرار المستند أو الرقم في حساب آخر', 'unknown', 'تعذّر الفحص.');
        }

        // ── قوائم العقوبات (قراءة فقط) ──
        try {
            $listSize = Schema::hasTable('sanction_list_entries') ? DB::table('sanction_list_entries')->where('is_active', true)->count() : 0;
            if ($listSize === 0) {
                $signals[] = $this->s('sanctions', 'قوائم العقوبات', 'unknown', 'قائمة العقوبات غير محمّلة؛ لم يُفحص شيء.');
            } else {
                $name = trim((string) ($user->declared_legal_name ?: trim(($user->f_name ?? '').' '.($user->l_name ?? ''))));
                $r = app(SanctionScreeningService::class)->screenName($name, ['national_id' => $user->identification_number ?? null]);
                $signals[] = match ($r['result']) {
                    'confirmed_match' => $this->s('sanctions', 'قوائم العقوبات', 'fail', 'تطابق مؤكد ('.($r['details']['list'] ?? 'قائمة').').'),
                    'potential_match' => $this->s('sanctions', 'قوائم العقوبات', 'warn', 'تطابق محتمل بنسبة '.round((float) $r['score']).'%.'),
                    default => $this->s('sanctions', 'قوائم العقوبات', 'pass', 'لا تطابق في '.$listSize.' قيداً.'),
                };
            }
        } catch (\Throwable) {
            $signals[] = $this->s('sanctions', 'قوائم العقوبات', 'unknown', 'تعذّر الفحص.');
        }

        return ['version' => 1, 'generated_at' => now()->toIso8601String(), 'signals' => $signals, 'ocr' => $ocrRows];
    }

    private function s(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
