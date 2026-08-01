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
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active' => 'boolean',
        'max_txn_amount' => 'decimal:4',
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
}
