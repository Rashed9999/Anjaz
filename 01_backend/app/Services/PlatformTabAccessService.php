<?php

namespace App\Services;

use App\Models\User;
use App\Support\PlatformAccessTabs;
use Illuminate\Support\Facades\DB;

/** يحفظ اختيار «تبويب + قراءة/كتابة» ويولّد منه الصلاحيات المحروسة. */
class PlatformTabAccessService
{
    /** @param array<string,array{read?:mixed,write?:mixed}> $submitted */
    public function sync(User $operator, array $submitted, int $grantedBy): array
    {
        $tabs = PlatformAccessTabs::all();
        $normalized = [];
        foreach ($submitted as $code => $choice) {
            if (!isset($tabs[$code]) || !is_array($choice)) continue;
            $level = !empty($choice['write']) ? 'write' : (!empty($choice['read']) ? 'read' : null);
            if ($level) $normalized[$code] = $level;
        }
        if ($normalized === []) {
            throw new \InvalidArgumentException('اختر تبويباً واحداً بصلاحية قراءة على الأقل');
        }

        $codes = [];
        foreach ($normalized as $code => $level) {
            $codes = array_merge($codes, PlatformAccessTabs::permissionCodes($code, $level));
        }
        $permissionIds = DB::table('permissions')->whereIn('code', array_unique($codes))->pluck('id', 'code');
        $missing = array_values(array_diff(array_unique($codes), $permissionIds->keys()->all()));
        if ($missing !== []) throw new \LogicException('صلاحيات تبويب غير مهيأة: ' . implode(', ', $missing));

        DB::transaction(function () use ($operator, $normalized, $permissionIds, $grantedBy) {
            DB::table('platform_operator_tab_access')->where('user_id', $operator->id)->delete();
            DB::table('admin_user_permissions')->where('user_id', $operator->id)->delete();
            foreach ($normalized as $tab => $level) {
                DB::table('platform_operator_tab_access')->insert([
                    'user_id' => $operator->id, 'tab_code' => $tab, 'access_level' => $level,
                    'granted_by_user_id' => $grantedBy, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (PlatformAccessTabs::permissionCodes($tab, $level) as $permission) {
                    DB::table('admin_user_permissions')->insertOrIgnore([
                        'user_id' => $operator->id, 'permission_id' => $permissionIds[$permission],
                        'granted_by_user_id' => $grantedBy, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
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
}
