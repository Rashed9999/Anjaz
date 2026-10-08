<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-UNIFIED-VERIFICATION-001
 *
 * Keeps the existing `kyc_verification_cases` record as the single case per
 * account, then adds the workflow metadata around it.  Existing documents,
 * residence reviews and merchant requests remain where they are; the workflow
 * references their outcome instead of copying sensitive files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_verification_cases', function (Blueprint $table) {
            if (! Schema::hasColumn('kyc_verification_cases', 'case_ulid')) {
                $table->string('case_ulid', 26)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'subject_kind')) {
                $table->string('subject_kind', 32)->nullable()->index()->after('user_id');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'merchant_vertical')) {
                $table->string('merchant_vertical', 64)->nullable()->index()->after('subject_kind');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'target_level')) {
                $table->unsignedTinyInteger('target_level')->default(1)->after('merchant_vertical');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'policy_version')) {
                $table->string('policy_version', 32)->nullable()->after('target_level');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'workflow_status')) {
                $table->string('workflow_status', 32)->default('collecting')->index()->after('status');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'current_step')) {
                $table->string('current_step', 64)->nullable()->after('workflow_status');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'final_decided_by')) {
                $table->unsignedBigInteger('final_decided_by')->nullable()->index()->after('reviewed_by');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'final_decided_at')) {
                $table->timestamp('final_decided_at')->nullable()->after('final_decided_by');
            }
            if (! Schema::hasColumn('kyc_verification_cases', 'final_reason')) {
                $table->string('final_reason', 500)->nullable()->after('final_decided_at');
            }
        });

        Schema::create('verification_requirement_policies', function (Blueprint $table) {
            $table->id();
            $table->string('subject_kind', 32)->index();
            $table->string('merchant_vertical', 64)->nullable()->index();
            $table->unsignedTinyInteger('target_level')->default(1);
            $table->string('policy_version', 32);
            $table->json('requirements');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['subject_kind', 'merchant_vertical', 'target_level', 'policy_version'], 'verification_policy_version_unique');
        });

        Schema::create('verification_case_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('verification_case_id')->index();
            $table->string('step_key', 64);
            $table->unsignedTinyInteger('step_order');
            $table->string('status', 32)->default('blocked')->index();
            $table->json('evidence_snapshot')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['verification_case_id', 'step_key'], 'verification_case_step_unique');
            $table->index(['verification_case_id', 'step_order'], 'verification_case_step_order_idx');
        });

        Schema::create('verification_case_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('verification_case_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['verification_case_id', 'created_at'], 'verification_case_events_timeline_idx');
        });

        // The first policies intentionally live in data, not conditionals in a
        // controller.  Operations can version a policy later without rewriting
        // an already auditable case.
        DB::table('verification_requirement_policies')->insert([
            [
                'subject_kind' => 'customer', 'merchant_vertical' => null, 'target_level' => 3,
                'policy_version' => '2026.10.1', 'is_active' => true,
                'requirements' => json_encode([
                    ['key' => 'contact', 'label' => 'إثبات الهاتف والبريد الإلكتروني'],
                    ['key' => 'residence', 'label' => 'البيانات وإثبات السكن'],
                    ['key' => 'identity', 'label' => 'الهوية الشخصية'],
                    ['key' => 'ownership', 'label' => 'إثبات صاحب الهوية'],
                    ['key' => 'risk_review', 'label' => 'مراجعة المخاطر'],
                    ['key' => 'final_activation', 'label' => 'القرار والاعتماد النهائي'],
                ], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'subject_kind' => 'merchant', 'merchant_vertical' => null, 'target_level' => 1,
                'policy_version' => '2026.10.1', 'is_active' => true,
                'requirements' => json_encode([
                    ['key' => 'contact', 'label' => 'إثبات الهاتف والبريد الإلكتروني'],
                    ['key' => 'owner_identity', 'label' => 'هوية مالك المنشأة'],
                    ['key' => 'establishment', 'label' => 'بيانات المنشأة والسجل التجاري'],
                    ['key' => 'risk_review', 'label' => 'مراجعة المخاطر'],
                    ['key' => 'final_activation', 'label' => 'القرار والاعتماد النهائي'],
                ], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'subject_kind' => 'merchant', 'merchant_vertical' => 'quick_sale', 'target_level' => 1,
                'policy_version' => '2026.10.1', 'is_active' => true,
                'requirements' => json_encode([
                    ['key' => 'contact', 'label' => 'إثبات الهاتف والبريد الإلكتروني'],
                    ['key' => 'owner_identity', 'label' => 'هوية مالك نقطة البيع'],
                    ['key' => 'quick_sale_location', 'label' => 'اسم النشاط وموقع وصورة نقطة البيع'],
                    ['key' => 'risk_review', 'label' => 'مراجعة المخاطر'],
                    ['key' => 'final_activation', 'label' => 'القرار والاعتماد النهائي'],
                ], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_case_events');
        Schema::dropIfExists('verification_case_steps');
        Schema::dropIfExists('verification_requirement_policies');

        Schema::table('kyc_verification_cases', function (Blueprint $table) {
            foreach (['case_ulid', 'subject_kind', 'merchant_vertical', 'target_level', 'policy_version', 'workflow_status', 'current_step', 'final_decided_by', 'final_decided_at', 'final_reason'] as $column) {
                if (Schema::hasColumn('kyc_verification_cases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
