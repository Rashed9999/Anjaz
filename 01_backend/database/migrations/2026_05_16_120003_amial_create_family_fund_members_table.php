<?php

/**
 * AMIAL-FUND-FAMILY-001 (v0.9-B)
 *
 * family_fund_members: عضوية المستخدمين في الصناديق.
 *
 * الأدوار (قسم 18 من الوثيقة):
 *   - Owner:  ينشئ، يحذف، يدير، يصرف، يدعو
 *   - Admin:  يدعو، يدير اقتراحات الصرف
 *   - Member: يساهم، يقترح صرف، يرى السجل
 *   - Viewer: يرى فقط
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_fund_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fund_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['owner', 'admin', 'member', 'viewer'])->default('member');
            $table->enum('status', ['invited', 'active', 'declined', 'removed'])->default('invited');

            // الإحصاءات (لاستعلامات أسرع بدون JOIN)
            $table->decimal('total_contributed', 20, 4)->default(0);
            $table->decimal('total_disbursed', 20, 4)->default(0);

            $table->timestamp('invited_at')->useCurrent();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('invited_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['fund_id', 'user_id'], 'fund_member_unique');
            $table->index(['user_id', 'status']);

            $table->foreign('fund_id')->references('id')->on('family_funds')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_fund_members');
    }
};
