<?php

namespace App\Models\Agent;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * AMIAL-AGENT-STAFF-001 — موظّف شركة صرافة.
 *
 * ليس `User` عمداً: لا محفظة له ولا توثيق هويّةٍ في المنصّة. الرصيد
 * الإلكترونيّ سيولةُ الفرع، والموظّف يتصرّف نيابةً عنه ويحمل نقداً في درجه.
 *
 * **والدور هنا يحدّد النطاق:**
 *   • `head_office`    — الإدارة العامّة: كلّ الفروع، ولا يقف على شبّاك.
 *   • `branch_manager` — فرعُه وحده: موظّفوه وخزنته وورديّاته.
 *   • `teller`         — ورديّتُه وحدها: إيداعٌ وسحبٌ ودرجُه.
 */
class AgentStaff extends AuthUser implements Authenticatable
{
    protected $table = 'agent_staff';

    public const ROLE_HEAD_OFFICE = 'head_office';
    public const ROLE_BRANCH_MANAGER = 'branch_manager';
    public const ROLE_TELLER = 'teller';

    public const ROLE_LABELS = [
        self::ROLE_HEAD_OFFICE => 'الإدارة العامّة',
        self::ROLE_BRANCH_MANAGER => 'مدير فرع',
        self::ROLE_TELLER => 'صرّاف (شبّاك)',
    ];

    protected $fillable = [
        'agent_user_id', 'branch_id', 'username', 'name', 'phone',
        'password', 'role', 'is_active', 'max_txn_amount', 'created_by',
        'daily_limit', 'daily_count_limit', 'capabilities',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active' => 'boolean',
        'max_txn_amount' => 'decimal:4',
        'daily_limit' => 'decimal:4',
        'daily_count_limit' => 'integer',
        'last_login_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AgentBranch::class, 'branch_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(AgentShift::class, 'staff_id');
    }

    /** الورديّة المفتوحة — الصرّاف لا يفتح ورديّتين. */
    public function openShift(): ?AgentShift
    {
        return $this->shifts()->where('status', AgentShift::STATUS_OPEN)->latest('id')->first();
    }

    public function isHeadOffice(): bool
    {
        return $this->role === self::ROLE_HEAD_OFFICE;
    }

    public function isBranchManager(): bool
    {
        return $this->role === self::ROLE_BRANCH_MANAGER;
    }

    public function isTeller(): bool
    {
        return $this->role === self::ROLE_TELLER;
    }

    /**
     * الفروع التي يراها هذا الموظّف.
     *
     * تُحسب من الدور لا من الطلب: معرّفُ فرعٍ يأتي من المتصفّح يمكن تغييره،
     * وشركةُ صرافةٍ ترى خزنة منافستها كارثةٌ تجارية قبل أن تكون خرقاً أمنياً.
     *
     * @return array<int>
     */
    public function visibleBranchIds(): array
    {
        if ($this->isHeadOffice()) {
            return AgentBranch::where('agent_user_id', $this->agent_user_id)
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return $this->branch_id ? [(int) $this->branch_id] : [];
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    // ══════════════════════════════════════════════════════════════════
    // الصلاحيات — AMIAL-TELLER-WS-001
    // ══════════════════════════════════════════════════════════════════

    /**
     * ما يستطيع كلّ دورٍ أن يفعله افتراضاً.
     *
     * **وتُعرَض للصرّاف على شاشته لا تُخفى عنه.** صرّافٌ لا يعرف حدود
     * صلاحيته يحاول ما لا يملك أمام العميل فيقف، فيبدو النظام معطّلاً
     * وهو يعمل. ومعرفةُ الحدّ قبل المحاولة توفّر الموقف كلّه.
     */
    public const CAPABILITIES = [
        'deposit' => 'إيداع نقديّ للعميل',
        'withdraw' => 'صرف سحبٍ برمز العميل',
        'transfer' => 'تحويل بين المحافظ',
        'adjust_balance' => 'تعديل رصيدٍ يدويّاً',
        'cancel_operation' => 'إلغاء عمليّة منفَّذة',
        'override_limit' => 'تجاوز الحدّ بلا موافقة',
        'open_shift' => 'فتح ورديّة',
        'close_shift' => 'إغلاق ورديّة',
        'view_reports' => 'عرض تقارير الفرع',
        'manage_staff' => 'إدارة الموظّفين',
    ];

    /**
     * الافتراضيّ حسب الدور.
     *
     * **ولا يملك أحدٌ `adjust_balance` ولا `cancel_operation`** — ولا
     * الإدارة العامّة. فتعديلُ رصيدٍ من الشبّاك يعني أنّ الوكيل يستطيع أن
     * يمنح نفسه مالاً بلا قيدٍ مقابل، وإلغاءُ عمليّةٍ منفَّذة يعني محو
     * أثرِ حركةٍ وقعت. وكلاهما يمرّ بأميال لا بالوكيل.
     *
     * @return array<string, bool>
     */
    public static function defaultCapabilities(string $role): array
    {
        $none = array_fill_keys(array_keys(self::CAPABILITIES), false);

        return match ($role) {
            self::ROLE_TELLER => array_merge($none, [
                'deposit' => true, 'withdraw' => true,
                'open_shift' => true, 'close_shift' => true,
            ]),
            self::ROLE_BRANCH_MANAGER => array_merge($none, [
                'close_shift' => true, 'override_limit' => true,
                'view_reports' => true, 'manage_staff' => true,
            ]),
            self::ROLE_HEAD_OFFICE => array_merge($none, [
                'override_limit' => true, 'close_shift' => true,
                'view_reports' => true, 'manage_staff' => true, 'transfer' => true,
            ]),
            default => $none,
        };
    }

    /**
     * صلاحيات هذا الموظّف — الافتراضيّ، معدَّلاً بما خُصّص له.
     *
     * **والتخصيص لا يُوسّع فوق الدور.** لو سُمح لصار عمودُ `capabilities`
     * باباً خلفياً يمنح صرّافاً صلاحيات الإدارة بتعديل صفٍّ واحد.
     * فالتخصيص **يضيّق فقط**.
     *
     * ══════════════════════════════════════════════════════════════
     * **واسمها ليس `capabilities()` عمداً.**
     *
     * فالعمود في القاعدة اسمه `capabilities`. ودالّةٌ بالاسم نفسه تجعل
     * Eloquent — حين لا يجد السِمة في الصفّ (استعلامٌ اختار أعمدةً
     * بعينها) — يظنّها **علاقة**، فيستدعيها وينتظر `Relation` فيجد
     * مصفوفة، فيرمي استثناءً في مكانٍ لا يدلّ على سببه.
     *
     * @return array<string, bool>
     */
    public function effectiveCapabilities(): array
    {
        $base = self::defaultCapabilities((string) $this->role);
        $custom = $this->getAttribute('capabilities');

        if (is_string($custom)) {
            $custom = json_decode($custom, true);
        }

        if (!is_array($custom)) {
            return $base;
        }

        foreach ($base as $key => $allowed) {
            // `false` صريحة تمنع. و`true` لا تمنح ما لا يملكه الدور.
            if (array_key_exists($key, $custom) && $custom[$key] === false) {
                $base[$key] = false;
            }
        }

        return $base;
    }

    /**
     * **واسمها ليست `can()`.** فـ`AgentStaff` يرث `Authenticatable`،
     * وفيه `can($abilities, $arguments = [])` لبوّابات Laravel. وتعريفُ
     * `can(string): bool` فوقه خطأٌ فادحٌ عند تحميل الصنف — يُسقط كلّ
     * شيءٍ يلمس الموظّفين، لا هذه الدالّة وحدها.
     */
    public function hasCapability(string $capability): bool
    {
        return $this->effectiveCapabilities()[$capability] ?? false;
    }
}
