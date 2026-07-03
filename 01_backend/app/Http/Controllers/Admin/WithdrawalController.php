<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\WithdrawalMethod;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(
        private WithdrawalMethod $withdrawalMethod
    ){}

    public function addMethod(Request $request): View
    {
        $withdrawalMethods = $this->withdrawalMethod->latest()->paginate(Helpers::pagination_limit());
        return view('admin-views.withdrawal.index', compact('withdrawalMethods'));
    }

    public function storeMethod(Request $request): RedirectResponse
    {
        $request->validate([
            'method_name' => 'required',
            'field_type' => 'required|array',
            'field_name' => 'required|array',
            'placeholder' => 'required|array',
        ]);

        $methodFields = [];
        foreach ($request->field_name as $key=>$field_name) {
            $methodFields[] = [
                'input_type' => $request->field_type[$key],
                'input_name' => strtolower(str_replace(' ', "_", $request->field_name[$key])),
                'placeholder' => $request->placeholder[$key],
            ];
        }

        $this->withdrawalMethod->updateOrCreate(
            ['method_name' => $request->method_name],
            ['method_fields' => $methodFields]
        );

        Toastr::success('successfully added');
        return back();
    }

    public function deleteMethod(Request $request): RedirectResponse
    {
        $withdrawalMethods = $this->withdrawalMethod->find($request->id);
        $withdrawalMethods->delete();

        Toastr::success(translate('successfully removed'));
        return back();
    }
}
