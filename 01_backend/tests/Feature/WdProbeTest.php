<?php
namespace Tests\Feature;
use App\Models\{EMoney,User,WithdrawRequest};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class WdProbeTest extends TestCase {
  use RefreshDatabase;
  public function test_probe(): void {
    $admin = User::factory()->create(['type'=>ADMIN_TYPE,'role'=>'admin']);
    app(\App\Services\PlatformRoleService::class)->assign($admin, 'platform_admin');
    $c = User::factory()->create(['type'=>CUSTOMER_TYPE,'role'=>'customer','is_active'=>1,'zone_code'=>'SOUTH']);
    EMoney::create(['user_id'=>$c->id,'current_balance'=>'1000.0000','pending_balance'=>'5100.0000','held_balance'=>'0','charge_earned'=>'0']);
    $r = WithdrawRequest::create(['user_id'=>$c->id,'amount'=>'5000','admin_charge'=>'100','request_status'=>'pending','is_paid'=>0]);
    echo "\nCHARGE SAVED: ".var_export($r->fresh()->admin_charge, true)."\n";
    echo "USER REL: ".var_export(WithdrawRequest::with('user')->find($r->id)?->user?->id, true)."\n";
    echo "PERM money.move: ".var_export($admin->hasPlatformPermission('platform.money.move'), true)."\n";
    \Illuminate\Support\Facades\Log::listen(fn($m) => print("LOG: ".$m->message."\n"));
    $resp = $this->actingAs($admin,'user')->from('/admin/withdraw')
        ->post(route('admin.withdraw.status-update'), ['request_id'=>$r->id,'request_status'=>'deny']);
    echo "STATUS: ".$resp->status()." → ".($resp->headers->get('Location') ?? '-')."\n";
    echo "REQ: ".$r->fresh()->request_status."  BAL: ".EMoney::where('user_id',$c->id)->value('current_balance')."\n";
    $this->assertTrue(true);
  }
}
