<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** فصل منشئ الجمعية/الحملة عن صاحب قرار الاعتماد. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charity_organizations', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('org_ulid');
            $table->index('created_by_admin_id', 'charity_org_created_by_idx');
            $table->foreign('created_by_admin_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('charity_campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('campaign_ulid');
            $table->index('created_by_admin_id', 'charity_campaign_created_by_idx');
            $table->foreign('created_by_admin_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charity_campaigns', function (Blueprint $table) {
            $table->dropForeign(['created_by_admin_id']);
            $table->dropIndex('charity_campaign_created_by_idx');
            $table->dropColumn('created_by_admin_id');
        });
        Schema::table('charity_organizations', function (Blueprint $table) {
            $table->dropForeign(['created_by_admin_id']);
            $table->dropIndex('charity_org_created_by_idx');
            $table->dropColumn('created_by_admin_id');
        });
    }
};
