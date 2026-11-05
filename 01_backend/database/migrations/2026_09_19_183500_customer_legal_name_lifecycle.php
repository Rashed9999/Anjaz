<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-LEGAL-NAME-001
 *
 * الاسم في التسجيل تصريح قانوني رباعي، لا لقب عرض حر.
 * نحفظ الاسم المصرّح به منفصلاً عن الاسم الموثّق، ونحفظ تاريخ التغييرات
 * مشفراً لأن تاريخ الأسماء بيانات هوية لا يجب نسخه إلى audit العام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'family_name')) {
                $table->string('family_name', 80)->nullable()->after('grandfather_name');
            }
            if (!Schema::hasColumn('users', 'declared_legal_name')) {
                $table->string('declared_legal_name', 300)->nullable()->after('family_name')->index();
            }
            if (!Schema::hasColumn('users', 'verified_legal_name')) {
                $table->string('verified_legal_name', 300)->nullable()->after('declared_legal_name');
            }
            if (!Schema::hasColumn('users', 'legal_name_status')) {
                $table->string('legal_name_status', 32)->nullable()->after('verified_legal_name')->index();
            }
            if (!Schema::hasColumn('users', 'legal_name_verified_at')) {
                $table->timestamp('legal_name_verified_at')->nullable()->after('legal_name_status');
            }
            if (!Schema::hasColumn('users', 'legal_name_locked_at')) {
                $table->timestamp('legal_name_locked_at')->nullable()->after('legal_name_verified_at');
            }
        });

        Schema::create('legal_name_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('event_type', 48)->index();
            $table->string('source', 48);
            $table->text('old_name_encrypted')->nullable();
            $table->text('new_name_encrypted')->nullable();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->unsignedBigInteger('reviewer_id')->nullable()->index();
            $table->string('match_status', 24)->nullable()->index();
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('residence_verifications', function (Blueprint $table) {
            $table->string('document_name', 300)->nullable()->after('evidence_date');
            $table->string('name_match_status', 24)->nullable()->after('document_name')->index();
            $table->unsignedTinyInteger('name_match_score')->nullable()->after('name_match_status');
            $table->string('name_review_note', 500)->nullable()->after('name_match_score');
            $table->unsignedBigInteger('name_confirmed_by')->nullable()->after('name_review_note');
            $table->timestamp('name_confirmed_at')->nullable()->after('name_confirmed_by');
        });

        // التوافق التاريخي: لا نعلن أن الاسم القديم «موثق». ننسخه كتصرّح
        // مبدئي فقط لكي تبقى الحسابات القديمة قابلة للمراجعة ولا تصبح null.
        DB::table('users')
            ->where('type', defined('CUSTOMER_TYPE') ? CUSTOMER_TYPE : 2)
            ->whereNull('declared_legal_name')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $parts = array_values(array_filter([
                        trim((string) ($row->f_name ?? '')),
                        trim((string) ($row->father_name ?? '')),
                        trim((string) ($row->grandfather_name ?? '')),
                        trim((string) ($row->family_name ?? $row->l_name ?? '')),
                    ], fn ($v) => $v !== ''));

                    DB::table('users')->where('id', $row->id)->update([
                        'family_name' => $row->family_name ?? $row->l_name ?? null,
                        'declared_legal_name' => $parts ? implode(' ', $parts) : null,
                        'legal_name_status' => $parts ? 'legacy_declared' : null,
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_name_events');

        Schema::table('residence_verifications', function (Blueprint $table) {
            foreach ([
                'document_name', 'name_match_status', 'name_match_score',
                'name_review_note', 'name_confirmed_by', 'name_confirmed_at',
            ] as $column) {
                if (Schema::hasColumn('residence_verifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'family_name', 'declared_legal_name', 'verified_legal_name',
                'legal_name_status', 'legal_name_verified_at', 'legal_name_locked_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
