<?php

namespace App\Support;

/**
 * AMIAL-PORTAL-HOSTS-001 — لكلّ بوّابةٍ مضيفُها، ومَن أخطأ البابَ يُنقل إليه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الخطّة:**
 *
 *     amialpay.com          →  الموقع العامّ والدخول الموحّد
 *     admin.amialpay.com    →  لوحة الإدارة (وأدوارُها تُقرّر ما يراه كلٌّ)
 *     agent.amialpay.com    →  شركات الصرافة وفروعها
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا مطفأةٌ افتراضيّاً؟**
 *
 * لأنّ ربط المسارات بمضيفٍ لا يُترجَم بعدُ يُنتج ٤٠٤ على لوحة الإدارة
 * كلّها — لا رسالةَ خطأ، بل «الصفحة غير موجودة» على نظامٍ يعمل. فالشيفرة
 * تُدفع اليوم وهي صامتة، وتُشغَّل بمتغيّرَي بيئةٍ **بعد** أن يُترجَم
 * النطاقان وتُصدَر شهادتاهما.
 *
 *     AMIAL_ADMIN_HOST=admin.amialpay.com
 *     AMIAL_AGENT_HOST=agent.amialpay.com
 *
 * وقبل ضبطهما كلُّ شيء كما هو الآن.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُقفَل البابُ الخطأ — يُحوَّل.**
 *
 * فمن حفظ `amialpay.com/admin` في مفضّلته أو مرّره لزميلٍ لا يجوز أن يرى
 * ٤٠٤. يُنقل إلى `admin.amialpay.com/admin` بالمسار نفسه.
 *
 * **ولا تُشارَك كوكي الجلسة بين المضيفات** (`SESSION_DOMAIN` يبقى فارغاً):
 * ضبطُها على `.amialpay.com` يجعل جلسةً واحدةً تسري على النطاقات كلّها —
 * فيذهب العزلُ الذي جاءت النطاقاتُ من أجله.
 */
class PortalHost
{
    /** مضيف لوحة الإدارة، أو `null` إن لم يُفعَّل الفصل بعد. */
    public static function admin(): ?string
    {
        return self::clean(config('amial.hosts.admin'));
    }

    /** مضيف بوّابة شركات الصرافة، أو `null`. */
    public static function agent(): ?string
    {
        return self::clean(config('amial.hosts.agent'));
    }

    /** أفُعِّل الفصل أصلاً؟ */
    public static function enabled(): bool
    {
        return self::admin() !== null || self::agent() !== null;
    }

    /**
     * المضيف الصحيح لمسارٍ ما، أو `null` إن كان أيُّ مضيفٍ يصلح.
     *
     * يُقرأ من المسار لا من اسم المسار: أسماء المسارات تتغيّر، والبادئة
     * `admin/` و`agent/` عقدٌ ثابتٌ يعتمد عليه كلّ شيء في المشروع.
     */
    public static function expectedFor(string $path): ?string
    {
        $path = ltrim($path, '/');

        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            return self::admin();
        }

        if ($path === 'agent' || str_starts_with($path, 'agent/')) {
            return self::agent();
        }

        return null;
    }

    private static function clean(mixed $v): ?string
    {
        $v = trim((string) $v);

        // ولا يُقبل عنوانٌ كاملٌ بدل مضيف: `https://admin.x.com/` تُنتج
        // مضيفاً لا يُطابق شيئاً، فتُقفل اللوحة بصمت.
        $v = preg_replace('~^https?://~i', '', $v);
        $v = rtrim((string) $v, '/');

        return $v === '' ? null : mb_strtolower($v);
    }
}
