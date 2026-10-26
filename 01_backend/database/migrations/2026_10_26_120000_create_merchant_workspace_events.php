<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('merchant_workspace_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('event_id')->unique();
            $t->string('tenant_id', 64)->index();
            $t->string('kind', 20);
            $t->string('state', 20);
            $t->json('payload');
            $t->json('actor_context');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('delivered_at')->nullable()->index();
            $t->string('failure_code', 80)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_workspace_events');
    }
};
