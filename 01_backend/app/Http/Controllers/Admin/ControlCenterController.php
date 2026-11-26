<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\FeatureBalanceUnknownException;
use App\Exceptions\FeatureHasFundsException;
use App\Http\Controllers\Controller;
use App\Services\ControlCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

final class ControlCenterController extends Controller
{
    public function __construct(private readonly ControlCenterService $controls) {}

    public function home(Request $request): View
    {
        return $this->page($request, 'home');
    }

    public function services(Request $request): View
    {
        return $this->page($request, 'services');
    }

    public function emergency(Request $request): View
    {
        return $this->page($request, 'emergency');
    }

    private function page(Request $request, string $section): View
    {
        $flags = $this->controls->overview();
        $ready = $this->ready();

        $requests = $ready
            ? DB::table('control_center_requests')
                ->orderByDesc('created_at')->limit(40)->get()
            : collect();

        $events = $ready
            ? DB::table('control_center_events')
                ->orderByDesc('id')->limit(30)->get()
            : collect();

        return view('admin-views.amial.control-center.shell', [
            'section' => $section,
            'features' => $flags,
            'ready' => $ready,
            'requests' => $requests,
            'events' => $events,
            'connectedCount' => count(array_filter($flags, fn ($f) => $f['connected'])),
            'activeCount' => count(array_filter($flags, fn ($f) => $f['connected'] && $f['enabled'])),
            'pendingCount' => $requests->where('status', 'pending')
                ->filter(fn ($r) => now()->lessThan($r->expires_at))->count(),
            'canRequest' => (bool) $request->user()?->hasPlatformPermission('platform.settings.update'),
            'canApprove' => (bool) $request->user()?->hasPlatformPermission('platform.control.approve'),
        ]);
    }

    public function change(Request $request, string $key): JsonResponse
    {
        if (! $this->ready()) {
            return $this->error('CONTROL_NOT_READY', 'أكمل ترحيل قاعدة البيانات قبل استخدام المفاتيح.', 503);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        try {
            $result = $this->controls->request(
                $request->user(), $key, (bool) $data['enabled'], trim($data['reason'])
            );

            return response()->json([
                'success' => true,
                'code' => strtoupper($result['state']),
                'message' => match ($result['state']) {
                    'pending' => 'أُنشئ طلب الموافقة أو ما زال بانتظار الموافقة الثانية؛ لم يتغيّر وضع الخدمة.',
                    'applied' => 'طُبّق التغيير وسُجّل أثره.',
                    default => 'الخدمة في الحالة المطلوبة بالفعل.',
                },
                'request_id' => $result['request_id'],
            ], $result['state'] === 'pending' ? 202 : 200);
        } catch (FeatureBalanceUnknownException $e) {
            return $this->error('BALANCE_UNKNOWN', $e->getMessage(), 409);
        } catch (FeatureHasFundsException $e) {
            return $this->error('FEATURE_HAS_FUNDS', $e->getMessage(), 409);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('FEATURE_NOT_FOUND', 'لم يتم العثور على مفتاح الخدمة.', 404);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 'لا يمكن تنفيذ القرار: '. $e->getMessage(), 409);
        }
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        if (! $this->ready()) {
            return $this->error('CONTROL_NOT_READY', 'قاعدة التحكم غير مهيأة.', 503);
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        try {
            $state = $this->controls->decide(
                $request->user(), $id, $data['decision'] === 'approve', trim($data['reason'])
            );
            return response()->json([
                'success' => true,
                'code' => strtoupper($state),
                'message' => $state === 'approved'
                    ? 'اعتمد الموظف الثاني القرار ونُفذ على الخادم.'
                    : 'رُفض الطلب دون تنفيذ التغيير.',
            ]);
        } catch (FeatureBalanceUnknownException $e) {
            return $this->error('BALANCE_UNKNOWN', $e->getMessage(), 409);
        } catch (FeatureHasFundsException $e) {
            return $this->error('FEATURE_HAS_FUNDS', $e->getMessage(), 409);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 'تعذّر اعتماد القرار: '. $e->getMessage(), 409);
        }
    }

    private function ready(): bool
    {
        return Schema::hasTable('control_center_requests')
            && Schema::hasTable('control_center_events')
            && Schema::hasTable('control_center_policies');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message], $status);
    }
}
