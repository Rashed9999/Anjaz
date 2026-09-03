<?php

namespace App\Services;

use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\ReconciliationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-LEDGER-001 (v1.7)
 *
 * LedgerService — محرك القيد المزدوج (double-entry).
 *
 * **الاستخدام الأساسي:**
 *   $ledger->post(
 *     sourceType: 'send_money',
 *     sourceId: $txId,
 *     description: 'تحويل من أحمد إلى محمد',
 *     lines: [
 *       ['account' => "USER_WALLET_{$sender->id}", 'direction' => 'debit', 'amount' => '100'],
 *       ['account' => "USER_WALLET_{$receiver->id}", 'direction' => 'credit', 'amount' => '100'],
 *     ],
 *   );
 *
 * **الضمانات:**
 *   - يتحقق أن مجموع debits = مجموع credits (التوازن)
 *   - يقفل الحسابات (lockForUpdate) لمنع race conditions
 *   - يسجل balance_before/after لكل سطر (snapshot)
 *   - idempotent (نفس idempotency_key لا يُسجَّل مرتين)
 *   - append-only (لا تعديل، لا حذف)
 *
 * **التصحيح:**
 *   $ledger->reverse($entryId, 'سبب التصحيح');
 *   → ينشئ قيداً عكسياً (لا يحذف الأصلي)
 */
class LedgerService
{
    /**
     * تسجيل journal entry بقيود متوازنة.
     *
     * @param array $lines [['account' => code, 'direction' => debit|credit, 'amount' => string, 'description' => ?], ...]
     * @throws RuntimeException عند عدم التوازن أو رصيد غير كافٍ
     */
    public function post(
        string $sourceType,
        ?string $sourceId,
        string $description,
        array $lines,
        ?string $idempotencyKey = null,
        ?int $createdByUserId = null,
        array $metadata = [],
        string $zoneCode = 'SOUTH',
        bool $allowNegative = false,
        bool $isReversal = false,
        ?int $reversesEntryId = null,
    ): LedgerJournalEntry {
        if (count($lines) < 2) {
            throw new RuntimeException('Journal entry requires at least 2 lines (double-entry)');
        }

        // فحص idempotency
        if ($idempotencyKey) {
            $existing = LedgerJournalEntry::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing; // تم تسجيله مسبقاً
            }
        }

        // التحقق من التوازن قبل أي شيء
        $this->assertBalanced($lines);

        return DB::transaction(function () use (
            $sourceType, $sourceId, $description, $lines,
            $idempotencyKey, $createdByUserId, $metadata, $zoneCode, $allowNegative,
            $isReversal, $reversesEntryId,
        ) {
            // ══════════════════════════════════════════════════════════
            // AMIAL-MULTI-CURRENCY-002 — **قيدٌ «متوازنٌ» عبر عملتين ليس
            // متوازناً.**
            //
            // ١٠٠ مدينٌ بالدولار و١٠٠ دائنٌ بالريال يجتازان `assertBalanced`
            // (الجمعان متساويان عدداً) **وهما ليسا قيداً محاسبيّاً** — بل
            // خلقُ مالٍ من فرق الصرف بلا حسابٍ يستقبله. ولا يمسكه ميزانُ
            // مراجعةٍ لأنّه سيتوازن عددياً هو الآخر.
            //
            // فالشرطُ: **كلُّ سطور القيد الواحد بعملةٍ واحدة**. والصرفُ
            // يُقيَّد قيدين مرتبطين عبر `FX_POSITION_*`، كلٌّ متوازنٌ داخل
            // عملته. (‏`FxConversionService`.)
            // ══════════════════════════════════════════════════════════
            $entryCurrency = $this->assertSingleCurrency($lines);

            // إنشاء الرأس
            $totalDebit = $this->sumByDirection($lines, 'debit');
            $entry = LedgerJournalEntry::create([
                'currency' => $entryCurrency,
                'entry_ulid' => (string) Str::ulid(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'description_ar' => $description,
                'total_amount' => $totalDebit,
                'is_reversal' => $isReversal,
                'reverses_entry_id' => $reversesEntryId,
                'status' => 'posted',
                'created_by_user_id' => $createdByUserId,
                'zone_code' => $zoneCode,
                'metadata' => $metadata,
                'posted_at' => now(),
                'created_at' => now(),
            ]);

            // تسجيل كل سطر مع قفل الحساب
            foreach ($lines as $line) {
                $account = LedgerAccount::where('account_code', $line['account'])
                    ->lockForUpdate()
                    ->first();

                if (!$account) {
                    throw new RuntimeException("Ledger account not found: {$line['account']}");
                }

                $amount = (string) $line['amount'];
                if (bccomp($amount, '0', 4) <= 0) {
                    throw new RuntimeException('Line amount must be positive');
                }

                $balanceBefore = (string) $account->current_balance;
                $balanceAfter = $account->applyDirection($balanceBefore, $line['direction'], $amount);

                // فحص الرصيد السالب (لحسابات الأصول مثل user wallets)
                if (!$allowNegative && in_array($account->account_type, ['asset', 'liability'], true)
                    && bccomp($balanceAfter, '0', 4) < 0) {
                    throw new RuntimeException(
                        "Insufficient balance in account {$account->account_code}: "
                        . "{$balanceBefore} cannot support {$line['direction']} of {$amount}"
                    );
                }

                LedgerEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'direction' => $line['direction'],
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description_ar' => $line['description'] ?? null,
                    'metadata' => $line['metadata'] ?? null,
                    'created_at' => now(),
                ]);

                // تحديث الرصيد المخزّن (cached)
                $account->current_balance = $balanceAfter;
                $account->save();
            }

            return $entry;
        });
    }

    /**
     * عكس قيد (التصحيح المحاسبي الصحيح — لا حذف).
     *
     * ينشئ entry جديد بقيود معكوسة (debit ↔ credit) ويربطه بالأصلي.
     */
    public function reverse(int $journalEntryId, string $reason, ?int $adminUserId = null): LedgerJournalEntry
    {
        return DB::transaction(function () use ($journalEntryId, $reason, $adminUserId) {
            $original = LedgerJournalEntry::with('lines')->lockForUpdate()->find($journalEntryId);
            if (!$original) {
                throw new RuntimeException('Original entry not found');
            }
            if ($original->status === 'reversed') {
                throw new RuntimeException('Entry already reversed');
            }
            if ($original->is_reversal) {
                throw new RuntimeException('Cannot reverse a reversal entry');
            }

            // بناء السطور المعكوسة
            $reversedLines = $original->lines->map(function (LedgerEntryLine $line) {
                return [
                    'account' => $line->account->account_code,
                    'direction' => $line->direction === 'debit' ? 'credit' : 'debit',
                    'amount' => (string) $line->amount,
                    'description' => 'عكس: ' . ($line->description_ar ?? ''),
                ];
            })->toArray();

            // تسجيل القيد العكسي
            $reversal = $this->post(
                sourceType: $original->source_type . '_reversal',
                sourceId: $original->source_id,
                description: "قيد عكسي للمعاملة {$original->entry_ulid}: {$reason}",
                lines: $reversedLines,
                createdByUserId: $adminUserId,
                metadata: ['reverses' => $original->id, 'reason' => $reason],
                zoneCode: $original->zone_code,
                allowNegative: true, // العكس قد يُنزل رصيد مؤقتاً
                isReversal: true,
                reversesEntryId: $original->id,
            );

            // علم الأصلي كـ reversed
            $original->status = 'reversed';
            $original->reversed_by_entry_id = $reversal->id;
            $original->save();

            return $reversal;
        });
    }

    /**
     * إنشاء أو جلب حساب user wallet.
     */

    /**
     * **`firstOrCreate` ليس ذرّيّاً — وتحت الضغط يُخرج ٥٠٠.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فهو `SELECT` ثمّ `INSERT` في خطوتين. وعمليّتان متزامنتان على حسابٍ
     * لم يُنشأ بعدُ تُخفقان في القراءة معاً، ثمّ تُدرجان معاً، فتصطدم
     * الثانيةُ بـ`1062 Duplicate entry` — **وتسقط العمليّةُ الماليّة كلُّها**
     * على تصادمٍ لا علاقة له بها.
     *
     * وقِيس فوقع فعلاً: `Duplicate entry 'PLATFORM_FEE'`.
     *
     * **وخطرُه الحقيقيّ ليس حسابَ النظام** — ذاك يُنشأ مرّةً في عمر
     * المنصّة. خطرُه `USER_WALLET_{id}`: **أوّلُ عمليّتين متزامنتين على
     * مستخدمٍ جديد**، وهو بالضبط ما تكثر منه تجربةٌ يُسجَّل فيها مستعملون
     * جدد ويُحوَّل إليهم في اللحظة نفسها.
     *
     * والعلاجُ أنّ التصادم **ليس خطأً بل جواب**: من سبقني أنشأه، فأقرؤه.
     */
    private function firstOrCreateAccount(array $key, array $attrs): LedgerAccount
    {
        try {
            return LedgerAccount::firstOrCreate($key, $attrs);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // **وقراءةٌ قافلة، لا عاديّة.** فالمستوى `REPEATABLE READ` يخدم
            // القراءةَ العاديّة من لقطةِ بدء المعاملة، والصفُّ الذي أنشأه
            // غيرُنا ليس فيها — فنقرأ فراغاً ونرمي الخطأ من جديد على صفٍّ
            // موجود. والقراءةُ القافلة ترى آخرَ ما استقرّ.
            $found = LedgerAccount::where($key)->lockForUpdate()->first();

            if (! $found) {
                throw $e;   // تصادمٌ على قيدٍ آخر — لا يُبتلع
            }

            return $found;
        }
    }

    /**
     * AMIAL-MULTI-CURRENCY-002 — **الأساسُ يحتفظ برمزه، وغيرُه يُلحَق به.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `USER_WALLET_5` يبقى محفظةَ الريال **كما هو**، و`USER_WALLET_5_USD`
     * لغيره. وهذا قرارٌ مقصود: أيُّ ترقيمٍ جديدٍ للريال — مثل
     * `USER_WALLET_5_YER` — كان يعني **نقلَ كلّ قيدٍ في تاريخ المنصّة**
     * إلى رمزٍ آخر، وهجرةً على بياناتٍ ماليّةٍ حيّة لا تُختبَر إلّا بوقوعها.
     *
     * والثمنُ المقبول: رمزٌ غيرُ متماثل. والحارسُ يقرأ العملةَ من عمود
     * `currency` لا من صيغة الرمز.
     */
    public function getOrCreateUserWallet(
        int $userId,
        string $zoneCode = 'SOUTH',
        string $currency = \App\Support\Money\Currencies::BASE,
    ): LedgerAccount {
        $cur = \App\Support\Money\Currencies::normalize($currency);
        $isBase = \App\Support\Money\Currencies::isBase($cur);
        $code = $isBase ? "USER_WALLET_{$userId}" : "USER_WALLET_{$userId}_{$cur}";
        $label = $isBase ? '' : ' ('.\App\Support\Money\Currencies::nameAr($cur).')';

        return $this->firstOrCreateAccount(
            ['account_code' => $code],
            [
                'account_type' => 'liability', // MERGE-FIX: محفظة المستخدم = التزام على المنصّة
                'name_ar' => "محفظة المستخدم {$userId}{$label}",
                'owner_user_id' => $userId,
                'owner_type' => 'user',
                'normal_balance' => 'credit', // liability => credit-normal (credit=دخول/زيادة)
                'current_balance' => '0',
                'currency' => $cur,
                'zone_code' => $zoneCode,
            ],
        );
    }

    /**
     * يُسوّي الدفتر مع محفظةٍ تغيّرت من خارجه — بقيدٍ صريح لا بتزوير رصيد.
     *
     * AMIAL-LEDGER-ADJUST-001.
     *
     * ليس كل تغيّرٍ في الرصيد عمليةً بين طرفين: هناك الشحن الإداريّ، وتصحيح
     * خطأ، وبذور التجربة. وهذه تُغيّر `e_money` مباشرةً فينحرف الدفتر، ثم
     * يُرفض أوّل خصمٍ بعدها بـ«الرصيد لا يكفي» — وهو رفضٌ صحيح لأن الدفتر
     * لا يعرف من أين جاء المال.
     *
     * فالعلاج قيدٌ يقول ذلك صراحةً: مقابلُ الفرق يُسجَّل في حساب تسويات
     * خارجية، فيبقى الأثر مقروءاً ويُسأل عنه.
     *
     * **ولا تُستعمل هذه الدالّة لإسكات انحراف.** من ناداها فقد أقرّ أن
     * المال دخل من خارج الدفتر وسمّى السبب. أمّا مسارٌ ماليّ ينسى الترحيل
     * ثم يُنادي هذه ليمرّ، فقد حوّل عطلاً إلى عادة.
     */
    /**
     * AMIAL-LEDGER-OPENING-003 — **الافتتاحُ ليس التصحيح.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `reconcileWalletBalance` صارت **مسارَ التصحيح المضبوط** وحدَه: قضيّةٌ
     * معتمَدةٌ وأربعُ عيونٍ وملاحظةٌ دائمة. وهذا صحيحٌ لتصحيح انحرافٍ ظهر
     * بعد أن صار للمحفظة تاريخٌ في الدفتر.
     *
     * **لكنّها كانت أيضاً المسارَ الوحيدَ للرصيد الافتتاحيّ** — وذاك يقع
     * **لحظةَ النشر**، قبل أن تُشغَّل مصالحةٌ ليليّةٌ واحدة، فلا قضيّةَ
     * موجودةٌ ولا يمكن أن توجد. فصار أمرُ الترحيل يشترط ما لا يتحقّق:
     * **حاجزٌ يجعل الميزةَ التي يحرسها مستحيلة**، وكلُّ محفظةٍ قديمةٍ
     * تبقى صفراً في الدفتر فيُرفض أوّلُ خصمٍ عليها بـ«الرصيد لا يكفي».
     *
     * فيُفصَل المساران بالاسم لا بالرايات:
     *
     *   • **الافتتاح** — رصيدُ الدفتر **صفرٌ قطعاً**. لا شيءَ يُناقَض،
     *     فلا شيءَ يُصحَّح: تُدخَل المحفظةُ القائمةُ إلى الدفتر كما هي.
     *     ولا يُقبل على حسابٍ له قيدٌ واحد — **فلا يصلح باباً خلفيّاً
     *     للتعديل**.
     *   • **التصحيح** — `reconcileWalletBalance` بقضيّتها وعيونها الأربع.
     *
     * ومقابلُ القيد **حسابٌ معلَّقٌ (أصل) لا حقوقُ ملكيّة**: المالُ موجودٌ
     * فعلاً في بنكٍ أو عهدةِ وكيلٍ ولم يدخل الدفترَ بعد، وحقوقُ الملكيّة
     * تبتلعه صامتاً فلا يُسأل عنه أحد. (‏وهو المنطقُ نفسُه في `a2f9b37`.)
     *
     * @return LedgerJournalEntry|null  و`null` تعني: لا شيءَ يُفتَح
     */
    public function openWalletBalance(int $userId, string $reason): ?LedgerJournalEntry
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Opening a wallet balance requires a stated reason.');
        }

        return DB::transaction(function () use ($userId, $reason): ?LedgerJournalEntry {
            $account = $this->getOrCreateUserWallet($userId);

            $wallet = \App\Models\EMoney::where('user_id', $userId)->value('current_balance');
            $wallet = $wallet === null ? '0' : (string) $wallet;

            $inLedger = $this->computeBalanceFromLines($account->id);

            // **الشرطُ الذي يمنع هذا المسارَ من أن يصير تعديلاً.** حسابٌ له
            // رصيدٌ في الدفتر له تاريخ، وتغييرُه تصحيحٌ يمرّ بأربع عيون.
            if (bccomp($inLedger, '0', 4) !== 0) {
                throw new RuntimeException(
                    'This wallet already has a ledger history — a correction '
                    . 'must go through an approved reconciliation case, not an opening entry.'
                );
            }

            if (bccomp($wallet, '0', 4) <= 0) {
                return null; // لا رصيدَ يُفتَح — ولا قيدَ بصفر
            }

            $opening = $this->getOrCreateSystemAccount(
                'OPENING_BALANCE_SUSPENSE', 'asset',
                'حساب معلّق لأرصدة افتتاحيّة مرحَّلة عند النشر', 'debit'
            );

            return $this->post(
                sourceType: 'opening_balance',
                sourceId: (string) $userId,
                description: "رصيد افتتاحيّ للمحفظة: {$reason}",
                lines: [
                    ['account' => $opening->account_code, 'direction' => 'debit', 'amount' => $wallet],
                    ['account' => $account->account_code, 'direction' => 'credit', 'amount' => $wallet],
                ],
                // مفتاحٌ واحدٌ لكلّ محفظة — فإعادةُ تشغيل أمر النشر لا تضاعف.
                idempotencyKey: "wallet-opening-balance:{$userId}",
                metadata: [
                    'reason' => $reason,
                    'wallet' => $wallet,
                    'ledger_before' => $inLedger,
                ],
            );
        });
    }

    /**
     * Controlled correction of a wallet/ledger variance.
     *
     * This is deliberately not a convenience repair API. A caller must provide
     * an approved reconciliation case, two distinct operators, and a durable
     * approval note. No wallet row is changed here; the only financial effect is
     * one balanced, idempotent journal entry linked back to that case.
     *
     * @param array{case_ulid:string,maker_admin_id:int,checker_admin_id:int,approval_note:string} $control
     */
    public function reconcileWalletBalance(
        int $userId,
        string $reason,
        array $control = [],
    ): ?LedgerJournalEntry {
        if (!\Illuminate\Support\Facades\Schema::hasTable('reconciliation_cases')) {
            throw new RuntimeException('Reconciliation cases migration is required before an external adjustment.');
        }

        $caseUlid = trim((string) ($control['case_ulid'] ?? ''));
        $makerId = (int) ($control['maker_admin_id'] ?? 0);
        $checkerId = (int) ($control['checker_admin_id'] ?? 0);
        $approvalNote = trim((string) ($control['approval_note'] ?? ''));

        if ($caseUlid === '' || $makerId < 1 || $checkerId < 1 || $approvalNote === '') {
            throw new RuntimeException('External adjustment requires case, maker, checker, and approval note.');
        }
        if ($makerId === $checkerId) {
            throw new RuntimeException('Maker and checker must be different users.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('External adjustment requires a specific reason.');
        }

        return DB::transaction(function () use ($userId, $reason, $caseUlid, $makerId, $checkerId, $approvalNote): ?LedgerJournalEntry {
            $case = ReconciliationCase::query()
                ->where('case_ulid', $caseUlid)
                ->lockForUpdate()
                ->first();

            if (!$case) {
                throw new RuntimeException('Reconciliation case not found.');
            }
            if ($case->case_type !== 'wallet' || (int) $case->subject_user_id !== $userId) {
                throw new RuntimeException('The approval case does not belong to this wallet.');
            }
            if ($case->status !== 'pending_approval') {
                throw new RuntimeException('External adjustment requires a case pending approval.');
            }
            if ((int) $case->maker_admin_id !== $makerId || (int) $case->checker_admin_id !== $checkerId) {
                throw new RuntimeException('Case maker/checker do not match the supplied approval.');
            }

            $account = $this->getOrCreateUserWallet($userId);
            $wallet = \App\Models\EMoney::where('user_id', $userId)->value('current_balance');
            $wallet = $wallet === null ? '0' : (string) $wallet;
            $inLedger = $this->computeBalanceFromLines($account->id);
            $delta = bcsub($wallet, $inLedger, 4);

            if (bccomp($delta, '0', 4) === 0) {
                return null;
            }

            // This is a suspense account, not permanent equity. Every entry
            // remains traceable to a case until finance reclassifies its origin.
            $adjust = $this->getOrCreateSystemAccount(
                'EXTERNAL_ADJUSTMENT_SUSPENSE', 'asset', 'حساب معلّق لتسويات خارجية قيد التحقيق', 'debit'
            );
            $positive = bccomp($delta, '0', 4) > 0;
            $magnitude = $positive ? $delta : bcmul($delta, '-1', 4);

            $entry = $this->post(
                sourceType: 'external_adjustment',
                sourceId: $case->case_ulid,
                description: "تسوية قضية {$case->case_ulid}: {$reason}",
                lines: $positive
                    ? [
                        ['account' => $adjust->account_code, 'direction' => 'debit', 'amount' => $magnitude],
                        ['account' => $account->account_code, 'direction' => 'credit', 'amount' => $magnitude],
                    ]
                    : [
                        ['account' => $account->account_code, 'direction' => 'debit', 'amount' => $magnitude],
                        ['account' => $adjust->account_code, 'direction' => 'credit', 'amount' => $magnitude],
                    ],
                idempotencyKey: "reconciliation-case:{$case->case_ulid}:external-adjustment",
                createdByUserId: $makerId,
                metadata: [
                    'case_ulid' => $case->case_ulid,
                    'reason' => $reason,
                    'approval_note' => $approvalNote,
                    'maker_admin_id' => $makerId,
                    'checker_admin_id' => $checkerId,
                    'wallet' => $wallet,
                    'ledger_before' => $inLedger,
                ],
                allowNegative: true,
            );

            $case->forceFill([
                'status' => 'corrected',
                'action_taken' => $approvalNote,
                'resolution_journal_entry_id' => $entry->id,
            ])->save();

            app(AuditService::class)->record([
                'actor_type' => 'admin',
                'actor_user_id' => $makerId,
                'subject_type' => 'reconciliation_case',
                'subject_id' => $case->case_ulid,
                'action' => 'EXTERNAL_ADJUSTMENT_POSTED',
                'decision_code' => 'APPROVED_RECONCILIATION_CASE',
                'severity' => 'high',
                'context' => [
                    'checker_admin_id' => $checkerId,
                    'journal_entry_id' => $entry->id,
                    'wallet_user_id' => $userId,
                    'difference' => $delta,
                ],
            ]);

            return $entry;
        });
    }

    /**
     * جلب حساب نظام (PLATFORM_FEE, ESCROW_HOLD, ...).
     */
    public function getOrCreateSystemAccount(
        string $code,
        string $type,
        string $name,
        string $normal = 'credit',
        string $currency = \App\Support\Money\Currencies::BASE,
    ): LedgerAccount {
        return $this->firstOrCreateAccount(
            ['account_code' => $code],
            [
                'account_type' => $type,
                'name_ar' => $name,
                'owner_type' => 'platform',
                'normal_balance' => $normal,
                'current_balance' => '0',
                'currency' => \App\Support\Money\Currencies::normalize($currency),
            ],
        );
    }

    /**
     * رصيد حساب من القيود (للتحقق من صحة الـ cached balance).
     */
    public function computeBalanceFromLines(int $accountId): string
    {
        $account = LedgerAccount::findOrFail($accountId);

        $debits = (string) LedgerEntryLine::where('account_id', $accountId)
            ->where('direction', 'debit')->sum('amount');
        $credits = (string) LedgerEntryLine::where('account_id', $accountId)
            ->where('direction', 'credit')->sum('amount');

        return $account->normal_balance === 'debit'
            ? bcsub($debits, $credits, 4)
            : bcsub($credits, $debits, 4);
    }

    /**
     * يُرجع عملةَ القيد — ويرمي إن اختلطت.
     *
     * **ويُقرأ من عمود `currency` على الحساب لا من صيغة الرمز**: الأساسُ
     * يحتفظ برمزه القديم (`USER_WALLET_5`) فلا يدلّ الرمزُ على العملة.
     * وحسابٌ لا يوجد يُترَك لفحصِ ما بعدُ ليُخرج رسالتَه الأوضح.
     */
    private function assertSingleCurrency(array $lines): string
    {
        $codes = array_values(array_unique(array_map(
            static fn ($l) => (string) $l['account'], $lines
        )));

        $currencies = LedgerAccount::whereIn('account_code', $codes)
            ->pluck('currency', 'account_code');

        $seen = [];
        foreach ($codes as $code) {
            $cur = $currencies[$code] ?? null;
            if ($cur === null) {
                continue;   // حسابٌ مفقود — يُبلَّغ عنه بعد قليلٍ باسمه
            }
            $seen[strtoupper($cur)] = true;
        }

        if (count($seen) > 1) {
            throw new RuntimeException(sprintf(
                'Cross-currency journal entry (%s): قيدٌ واحدٌ لا يخلط عملتين. '
                .'الصرفُ يُقيَّد قيدين مرتبطين عبر FX_POSITION_*.',
                implode(' + ', array_keys($seen))
            ));
        }

        return (string) (array_key_first($seen) ?: \App\Support\Money\Currencies::BASE);
    }

    // ============================================================
    private function assertBalanced(array $lines): void
    {
        $totalDebit = $this->sumByDirection($lines, 'debit');
        $totalCredit = $this->sumByDirection($lines, 'credit');

        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new RuntimeException(
                "Unbalanced journal entry: debits ({$totalDebit}) != credits ({$totalCredit})"
            );
        }
        if (bccomp($totalDebit, '0', 4) <= 0) {
            throw new RuntimeException('Journal entry total must be positive');
        }
    }

    private function sumByDirection(array $lines, string $direction): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            if ($line['direction'] === $direction) {
                $sum = bcadd($sum, (string) $line['amount'], 4);
            }
        }
        return $sum;
    }
}
