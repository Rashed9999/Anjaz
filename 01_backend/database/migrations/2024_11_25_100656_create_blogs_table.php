<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('readable_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('writer')->nullable();
            $table->string('title');
            $table->longText('description');
            $table->string('image')->nullable();
            $table->datetime('publish_date')->default(now());
            $table->tinyInteger('status')->default(0); // 0: inactive, 1: active
            $table->tinyInteger('is_draft')->default(0); // 0: not draft, 1: draft
            $table->text('draft_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('blogs');
    }
};
