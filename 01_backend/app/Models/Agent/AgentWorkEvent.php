<?php

namespace App\Models\Agent;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-WORKTIME-001 — حدثُ دوامٍ في سلسلةٍ لا تُكسر بلا أثر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **البصمة تُحسب من الحقول الجوهريّة وحدها.**
 *
 * ولو دخل فيها `updated_at` لتغيّرت البصمة بكلّ لمسةٍ عابرة، فصار كسرُ
 * السلسلة حدثاً يوميّاً لا إشارةَ تلاعب — وحارسٌ يصرخ كلّ يوم لا يُسمَع
 * حين يصرخ لسبب.
 *
 * ولو خرج منها حقلٌ جوهريّ — الوقت مثلاً — لصار تعديلُه لا يكسر شيئاً،
 * وهو بالضبط ما بُنيت السلسلة لمنعه.
 */
class AgentWorkEvent extends Model
{
    protected $table = 'agent_work_events';

    protected $fillable = [
        'agent_user_id', 'branch_id', 'staff_id', 'shift_id',
        'event', 'occurred_at', 'source', 'actor_staff_id', 'meta',
        'prev_hash', 'hash',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'meta' => 'array',
    ];

    public const SHIFT_OPEN = 'shift_open';
    public const SHIFT_CLOSE = 'shift_close';
    public const BREAK_START = 'break_start';
    public const BREAK_END = 'break_end';

    public const EVENTS = [
        self::SHIFT_OPEN, self::SHIFT_CLOSE, self::BREAK_START, self::BREAK_END,
    ];

    public const LABELS = [
        self::SHIFT_OPEN => 'فتح ورديّة',
        self::SHIFT_CLOSE => 'إغلاق ورديّة',
        self::BREAK_START => 'بداية استراحة',
        self::BREAK_END => 'نهاية استراحة',
    ];

    /**
     * بصمةُ هذا الحدث مربوطةً بما قبله.
     *
     * **والوقت يدخل بصيغةٍ ثابتة** (`Y-m-d H:i:s`): لو أُخذ كما هو
     * لاختلفت البصمة بين كتابةٍ وقراءةٍ لأنّ التخزين يُهمل الكسور —
     * فتُقرأ السلسلة مكسورةً وهي سليمة، وذلك أسوأ من ألّا تُفحص.
     */
    public function computeHash(?string $prevHash): string
    {
        $payload = implode('|', [
            $prevHash ?? '',
            (int) $this->agent_user_id,
            (int) $this->branch_id,
            (int) $this->staff_id,
            (int) $this->shift_id,
            (string) $this->event,
            $this->occurred_at instanceof \DateTimeInterface
                ? $this->occurred_at->format('Y-m-d H:i:s')
                : (string) $this->occurred_at,
            (string) $this->source,
            (int) $this->actor_staff_id,
        ]);

        return hash('sha256', $payload);
    }

    public function staff()
    {
        return $this->belongsTo(AgentStaff::class, 'staff_id');
    }
}
