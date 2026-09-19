<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\Merchant\MerchantLogoService;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BusinessSettingsController extends Controller
{
    use UploadSizeHelperTrait;
    public function __construct(
        private Merchant $merchant,
    ){}

    public function shopIndex(): View
    {
        $merchant = $this->merchant->where(['user_id' => auth()->user()->id])->first();
        return view('merchant-views.business-settings.shop-index', compact('merchant'));
    }

    public function shopUpdate(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['logo']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'logo' => 'nullable|image|max:'. min($this->maxImageSizeKB, 2048) .'|mimes:jpg,jpeg,png',
        ]);

        $merchant = $this->merchant->where(['user_id' => auth()->user()->id])->first();

        if ($request->hasFile('logo')) {
            try {
                $logo = app(MerchantLogoService::class)->replaceFromUploadedFile($merchant, $request->file('logo'));
            } catch (\InvalidArgumentException $e) {
                Toastr::error($e->getMessage());
                return back();
            }
        } else {
            $logo = $merchant['logo'];
        }

        $merchant->store_name = $request->store_name;
        $merchant->callback = $request->callback;
        $merchant->address = $request->address;
        $merchant->bin = $request->bin;
        $merchant->logo = $logo;
        $merchant->update();

        Toastr::success(translate('Successfully updated.'));
        return back();
    }

    public function integrationIndex(): View
    {
        $merchant = $this->merchant->where(['user_id' => auth()->user()->id])->first();
        return view('merchant-views.business-settings.integration-index', compact('merchant'));
    }

    public function integrationUpdate(Request $request): JsonResponse
    {
        $merchant = $this->merchant->where(['user_id' => auth()->user()->id])->first();
        $merchant->public_key = Str::random(50);
        $merchant->secret_key = Str::random(50);
        $merchant->update();

        return response()->json([
            'merchant' => $merchant,
            'message' => translate('Successfully updated.')
        ]);
    }
}
