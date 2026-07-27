<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * AMIAL-OPS-CONSOLE-001 — حالة تشغيل المنصّة في مكان واحد.
 *
 * **الفجوة التي يسدّها:** عطل تنزيل الإيصالات كان سببه أن عامل الطابور لا
 * يستمع إلى طابور `receipts`. فبقيت المهامّ تنتظر في الجدول **إلى الأبد بلا
 * خطأ ولا سجلّ ولا مهمّة فاشلة** — طابور ينمو وحده في صمت. ولم يُكتشف إلا
 * من تسجيل شاشة أرسله مالك المشروع بعد أسابيع.
 *
 * والدرس أن أخطر الأعطال ليست التي تصرخ، بل التي **لا أثر لها**. فهذه
 * الخدمة تبحث عن الصمت نفسه.
 *
 * **الإشارة الأهمّ ليست عدد المهامّ المنتظرة بل عمر أقدمها.** طابورٌ فيه
 * ألف مهمّة عمرها ثوانٍ نظامٌ مشغول؛ وطابورٌ فيه مهمّة واحدة عمرها ساعة
 * عاملٌ ميّت. والعدد وحده لا يفرّق بينهما.
 */
class OpsHealthService
{
    /** بعدها يُعدّ الطابور متعثّراً — أطول من أي مهمّة سويّة في هذا النظام. */
    private const STALL_SECONDS = 300;

    /** مجلّدات لا يعمل بدونها توليد المستندات. */
    private const REQUIRED_DIRS = ['mpdf', 'private', 'receipts', 'documents', 'signatures'];

    /** كل ما تعرضه الشاشة، في استدعاء واحد. */
    public function snapshot(): array
    {
        return [
            'queues' => $this->queues(),
            'failed' => $this->failedSummary(),
            'storage' => $this->storage(),
            'documents' => $this->documents(),
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * حالة كل طابور: كم ينتظر، ومنذ متى ينتظر أقدمه.
     *
     * @return array<int, array{queue:string, pending:int, oldest_seconds:int, stalled:bool}>
     */
    public function queues(): array
    {
        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as pending, MIN(available_at) as oldest')
            ->groupBy('queue')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $age = $r->oldest ? max(0, time() - (int) $r->oldest) : 0;
            $out[] = [
                'queue' => (string) $r->queue,
                'pending' => (int) $r->pending,
                'oldest_seconds' => $age,
                'stalled' => $age > self::STALL_SECONDS,
            ];
        }

        // طابور معروف بلا صفوف يُعرض صفراً بدل أن يغيب: غيابُه يُقرأ «سليم»
        // وهو في الحقيقة «لا نعلم».
        $known = ['receipts', 'notifications', 'default'];
        foreach ($known as $q) {
            if (!collect($out)->contains('queue', $q)) {
                $out[] = ['queue' => $q, 'pending' => 0, 'oldest_seconds' => 0, 'stalled' => false];
            }
        }

        usort($out, fn ($a, $b) => $b['oldest_seconds'] <=> $a['oldest_seconds']);
        return $out;
    }

    /** المهامّ الفاشلة مجمَّعةً بنوع الخطأ — لا قائمةً طويلة متكرّرة. */
    public function failedSummary(): array
    {
        $total = DB::table('failed_jobs')->count();

        $recent = DB::table('failed_jobs')
            ->orderByDesc('failed_at')->limit(50)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);

        $groups = [];
        foreach ($recent as $row) {
            $job = $this->jobName($row->payload);
            $head = $this->exceptionHead($row->exception);
            $key = $job . '|' . $head;

            $groups[$key] ??= [
                'job' => $job,
                'queue' => (string) $row->queue,
                'error' => $head,
                'count' => 0,
                'last_at' => (string) $row->failed_at,
                'sample_uuid' => (string) $row->uuid,
            ];
            $groups[$key]['count']++;
        }

        return ['total' => $total, 'groups' => array_values($groups)];
    }

    /** المجلّدات التي يكتب فيها توليد المستندات — والكتابة تُجرَّب لا تُفترَض. */
    public function storage(): array
    {
        $out = [];
        foreach (self::REQUIRED_DIRS as $dir) {
            $writable = false;
            $error = null;
            try {
                // فحصٌ فعليّ: الوحدة المركّبة على storage/app تحجب ما أنشأته
                // الصورة تحتها، فوجود المجلّد في الصورة لا يعني وجوده الآن.
                $probe = "$dir/.ops_probe_" . uniqid();
                Storage::disk('local')->put($probe, 'x');
                $writable = Storage::disk('local')->exists($probe);
                Storage::disk('local')->delete($probe);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $out[] = ['dir' => $dir, 'writable' => $writable, 'error' => $error];
        }

        return $out;
    }

    /** المستندات المخبّأة وإيصالات لم يُولَّد ملفّها بعد. */
    public function documents(): array
    {
        $cached = 0;
        try {
            $cached = count(Storage::disk('local')->files('documents'));
        } catch (\Throwable) {
        }

        $pending = 0;
        try {
            $pending = DB::table('receipts')->whereNull('pdf_storage_path')->count();
        } catch (\Throwable) {
        }

        return ['cached' => $cached, 'receipts_without_pdf' => $pending];
    }

    /** اسم المهمّة من حمولتها — لا الحمولة كاملة، فهي غير مقروءة. */
    private function jobName(?string $payload): string
    {
        $data = json_decode((string) $payload, true);
        $name = $data['displayName'] ?? ($data['job'] ?? 'مهمّة غير معروفة');
        return class_basename((string) $name);
    }

    /**
     * أوّل سطر من الاستثناء — وهو ما يُشخَّص به.
     *
     * الأثر كاملاً يملأ الشاشة ويُخفي أن عشرين فشلاً سببها واحد.
     */
    private function exceptionHead(?string $exception): string
    {
        $first = strtok((string) $exception, "\n") ?: 'خطأ غير معروف';
        return mb_substr(trim($first), 0, 180);
    }
}
