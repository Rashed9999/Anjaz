<?php

namespace App\Services;

use App\Models\User;
use App\Support\PlatformAccessTabs;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPERATOR-GRAIN-002 — **المنحُ صلاحيّةً صلاحيّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان يقبل «تبويب + قراءة/كتابة» فيمنح كتلةَ التبويب كلَّها. وقاله صاحبُ
 * المشروع: «قد أحتاج إلى موظّف يرى سجلّ التدقيق فقط من هذه القائمة،
 * بينما في الصلاحيات يمكنك فقط اختيار القائمة كاملة».
 *
 * **والتخزينُ كان دقيقاً أصلاً**: `admin_user_permissions` صفٌّ لكلّ
 * صلاحيّة. فالخشونةُ كانت في المنح وحدَه — تُجمَع الصلاحيّاتُ ثمّ تُكتب
 * مفرَّقة، فلا يُمكن أن يُكتب بعضُها.
 *
 * فصار المنحُ يقبل **قائمةَ رموزٍ صريحة**، ويبقى منحُ التبويب كاملاً
 * اختصاراً يُترجَم إلى الرموز نفسِها.
 */
class PlatformTabAccessService
{
    /**
     * @param array<string,array{read?:mixed,write?:mixed}> $submitted اختصارُ التبويبات
     * @param list<string> $permissions رموزٌ مختارةٌ صراحةً — وهي الأدقّ
     *
     * @return array<string,string> التبويباتُ ومستوياتُها، للعرض
     */
    public function sync(
        User $operator,
        array $submitted,
        int $grantedBy,
        array $permissions = [],
    ): array {
        $catalog = PlatformAccessTabs::allPermissions();

        // ── ① الرموزُ المختارةُ صراحةً ──
        //
        // **ويُصفَّى ما ليس في القاموس.** رمزٌ يصل من نموذجٍ معدَّلٍ في
        // المتصفّح ويُكتب كما جاء يمنح ما لم تعرضه شاشةٌ قطّ — وهو
        // تصعيدُ امتيازٍ من حقلٍ مخفيّ. (القاعدة الثامنة: ما يأتي من
        // المتصفّح يُفحَص.)
        $codes = [];
        $rejected = [];

        foreach ($permissions as $code) {
            $code = trim((string) $code);

            if (isset($catalog[$code])) {
                $codes[] = $code;
            } elseif ($code !== '') {
                $rejected[] = $code;
            }
        }

        // ── ② واختصارُ «التبويب كاملاً» يبقى ──
        $tabs = PlatformAccessTabs::all();
        $normalized = [];

        foreach ($submitted as $tab => $choice) {
            if (!isset($tabs[$tab]) || !is_array($choice)) {
                continue;
            }

            $level = !empty($choice['write']) ? 'write'
                : (!empty($choice['read']) ? 'read' : null);

            if ($level === null) {
                continue;
            }

            $normalized[$tab] = $level;
            $codes = array_merge($codes, PlatformAccessTabs::permissionCodes($tab, $level));
        }

        $codes = array_values(array_unique($codes));

        if ($codes === []) {
            throw new \InvalidArgumentException(
                $rejected === []
                    ? 'اختر صلاحيّةً واحدةً على الأقلّ'
                    : 'لم تُقبل أيُّ صلاحيّة: ' . implode('، ', $rejected));
        }

        // ── ③ وتُشتقّ مستوياتُ التبويبات من الرموز، للعرض ──
        //
        // **ولا تُكتب من المُدخَل.** من اختار صلاحيّةً واحدةً من تبويبٍ
        // يجب أن يُرى تبويبُه مُعلَّماً، وإلّا فُتحت الشاشةُ في المرّة
        // التالية فارغةً فبدا أنّ المنحَ لم يُحفظ.
        foreach ($codes as $code) {
            $meta = $catalog[$code];
            $tab = $meta['tab'];

            if ($meta['level'] === 'write' || ($normalized[$tab] ?? '') !== 'write') {
                $normalized[$tab] = $meta['level'] === 'write' ? 'write'
                    : ($normalized[$tab] ?? 'read');
            }
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', $codes)->pluck('id', 'code');

        $missing = array_values(array_diff($codes, $permissionIds->keys()->all()));

        if ($missing !== []) {
            // **صلاحيّةٌ في القاموس ولا صفَّ لها في القاعدة** — تُقال
            // بالاسم، ولا يُكتب المنحُ ناقصاً فيبدو تامّاً.
            throw new \LogicException('صلاحيات غير مهيأة في القاعدة: ' . implode('، ', $missing));
        }

        DB::transaction(function () use ($operator, $normalized, $codes, $permissionIds, $grantedBy) {
            DB::table('platform_operator_tab_access')->where('user_id', $operator->id)->delete();
            DB::table('admin_user_permissions')->where('user_id', $operator->id)->delete();

            foreach ($normalized as $tab => $level) {
                DB::table('platform_operator_tab_access')->insert([
                    'user_id' => $operator->id, 'tab_code' => $tab, 'access_level' => $level,
                    'granted_by_user_id' => $grantedBy, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ($codes as $code) {
                DB::table('admin_user_permissions')->insertOrIgnore([
                    'user_id' => $operator->id, 'permission_id' => $permissionIds[$code],
                    'granted_by_user_id' => $grantedBy, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $normalized;
    }

    /** @return array<string,string> */
    public function for(User $operator): array
    {
        return DB::table('platform_operator_tab_access')->where('user_id', $operator->id)
            ->pluck('access_level', 'tab_code')->all();
    }

    /**
     * **ما مُنح فعلاً** — من جدول الصلاحيّات لا من جدول التبويبات.
     *
     * وهو ما يجب أن تُرسَم به الشاشة: جدولُ التبويبات ملخَّصٌ للعرض،
     * **والحقيقةُ في الصلاحيّات**. ورسمُ الشاشة من الملخَّص يجعل من
     * مُنح صلاحيّةً واحدةً يظهر وكأنّه مُنح التبويبَ كلَّه، فيُعاد الحفظُ
     * فيتوسّع المنحُ بلا أن يطلب أحدٌ توسيعَه.
     *
     * @return list<string>
     */
    public function permissionsFor(User $operator): array
    {
        return DB::table('admin_user_permissions as aup')
            ->join('permissions as p', 'p.id', '=', 'aup.permission_id')
            ->where('aup.user_id', $operator->id)
            ->pluck('p.code')->all();
    }
}
