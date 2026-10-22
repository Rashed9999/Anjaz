<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::table('muwathaq_account_opening_applications',function(Blueprint $t):void{$t->string('origin',32)->default('amial_legacy')->after('user_id')->index();});}public function down():void{Schema::table('muwathaq_account_opening_applications',function(Blueprint $t):void{$t->dropIndex(['origin']);$t->dropColumn('origin');});}};
