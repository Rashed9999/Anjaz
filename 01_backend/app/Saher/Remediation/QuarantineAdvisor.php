<?php

namespace App\Saher\Remediation;

use Illuminate\Routing\Route;

/**
 * SAHER-AUTOFIX-001: only offer containment for a fresh, concrete
 * administrative write route whose current middleware still shows the defect.
 * Findings and evidence are data, never executable instructions.
 */
final class QuarantineAdvisor
{
    private const SUPPORTED = [
        'SAHER.GUARD.WRITE_BEHIND_READ_PERMISSION',
        'SAHER.GUARD.WRITE_WITHOUT_PERMISSION',
    ];

    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return array{eligible:bool,route_name:?string,method:?string,reason:string} */
    public function assess(object $finding): array
    {
        $denied = fn (string $why): array => [
            'eligible' => false, 'route_name' => null, 'method' => null,
            'reason' => $why,
        ];

        if (! in_array($finding->rule_id ?? '', self::SUPPORTED, true)
            || ($finding->source_code ?? '') !== 'guards'
            || ($finding->asset_type ?? '') !== 'route') {
            return $denied('هذا النوع يحتاج إصلاحاً برمجياً أو تحقيقاً متخصصاً؛ الحظر التلقائي غير متاح له.');
        }

        if (! in_array($finding->status ?? '', ['OPEN', 'REOPENED'], true)
            || ! in_array($finding->severity ?? '', ['HIGH', 'CRITICAL'], true)
            || ! in_array($finding->confidence ?? '', ['PROVEN', 'HIGH_CONFIDENCE'], true)) {
            return $denied('يلزم اكتشاف مفتوح وعالي الخطورة وموثوق قبل احتواء أي مسار.');
        }

        $name = (string) ($finding->asset_key ?? '');
        if ($name === '' || strlen($name) > 190 || ! str_starts_with($name, 'admin.')) {
            return $denied('الاحتواء التلقائي محصور في مسارات إدارية مسمّاة بوضوح.');
        }

        /** @var Route|null $route */
        $route = app('router')->getRoutes()->getByName($name);
        if (! $route || ! str_starts_with($route->uri(), 'admin/')) {
            return $denied('المسار لم يعد موجوداً في الإصدار الجاري؛ أعد الفحص أولاً.');
        }

        $writes = array_values(array_intersect(self::WRITES, $route->methods()));
        if ($writes === []) {
            return $denied('المسار لم يعد يُجري كتابةً؛ أعد فحص ساهر.');
        }

        // Never quarantine Saher's own routes or platform login / recovery.
        if (str_contains($route->uri(), '/saher/')
            || preg_match('~/(login|logout|auth|recovery)(/|$)~i', $route->uri())) {
            return $denied('لا يُسمح باحتواء مسارات ساهر أو المصادقة والاستعادة ذاتياً.');
        }

        $permissions = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'platform:')) {
                foreach (explode(',', substr($middleware, 9)) as $permission) {
                    $permissions[] = trim($permission);
                }
            }
        }
        $permissions = array_values(array_filter(array_unique($permissions)));

        if ($finding->rule_id === self::SUPPORTED[1] && $permissions !== []) {
            return $denied('ظهرت صلاحية على المسار بعد الاكتشاف؛ يجب إعادة الفحص.');
        }

        if ($finding->rule_id === self::SUPPORTED[0]) {
            if ($permissions === [] || ! collect($permissions)->every(
                fn (string $permission) => preg_match('~\.(view|read|list|export)$~', $permission) === 1
            )) {
                return $denied('تغيّرت صلاحيات المسار بعد الاكتشاف؛ يجب إعادة الفحص.');
            }
        }

        return [
            'eligible' => true,
            'route_name' => $name,
            'method' => implode('|', $writes),
            'reason' => 'احتواء مؤقت: سيُرفض كل طلب كتابة إلى هذا المسار (403) لجميع الأدوار، حتى المدير، دون تعديل الأرصدة أو الشيفرة.',
        ];
    }
}
