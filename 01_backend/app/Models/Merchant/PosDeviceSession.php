<?php

namespace App\Models\Merchant;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-POS-DEVICES-003 — **ربطُ رمزٍ بمقعد.**
 *
 * الترويسةُ تأتي من التطبيق فتُزوَّر أو تُحذف؛ **وهذا الصفُّ في الخادم**.
 * فالرمزُ يعرف مقعدَه ولو صمت حاملُه.
 */
class PosDeviceSession extends Model
{
    protected $table = 'pos_device_sessions';

    protected $fillable = [
        'access_token_id', 'pos_device_id', 'merchant_user_id', 'actor_user_id',
        'started_at', 'last_seen_at', 'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(PosDevice::class, 'pos_device_id');
    }

    /**
     * **ربطُ هذا الرمز — مختوماً كان أو جارياً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **والختمُ لا يُستثنى هنا، وهذا ثمنٌ دُفع في التجربة لا نظريّة:**
     *
     * كانت تُرشِّح `whereNull('ended_at')`، فبدت طبيعيّةً. والنتيجةُ أنّ
     * **إلغاءَ الجهاز كان يُضعف الحراسةَ بدل أن يشدّها**:
     *
     *   يُلغى الجهاز ⇒ تُختم جلستُه ⇒ `forToken` تُرجع `null`
     *     ⇒ فيُقرأ الرمزُ «**غيرُ مربوطٍ**» لا «مربوطٌ بجهازٍ ملغى»
     *       ⇒ فيسقط في فرع الوضع الصامت ⇒ **٢٠٠ ويكمل العمل**
     *
     * أي أنّ زرَّ «ألغِ الجهاز» كان يُحرّر الرمزَ من قيده. وقِيس بالتشغيل:
     * تحقّقان من عشرة أخرجا ٢٠٠ حيث يجب ٤٠١.
     *
     * **فمن رُبط مرّةً يبقى محكوماً بربطه** — والحكمُ على الحالة يقع في
     * البوّابة لا في هذا الاستعلام.
     */
    public static function forToken(string $accessTokenId): ?self
    {
        return static::where('access_token_id', $accessTokenId)->first();
    }

    /**
     * **يُختم كلُّ ربطٍ على جهازٍ ألغي.**
     *
     * وبهذا يصير الإلغاءُ فعّالاً على الجلسات القائمة لا على التسجيل
     * وحدَه — وإلّا بقي الجهازُ الملغى يعمل حتّى ينتهي رمزُه.
     */
    public static function endAllForDevice(int $posDeviceId): int
    {
        return static::where('pos_device_id', $posDeviceId)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }
}
