<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\Bonus;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BonusController extends Controller
{
    public function __construct(
        private Bonus $bonus
    )
    {
    }

    function index(Request $request): View
    {
        $queryParams = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $bonuses = $this->bonus->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('title', 'like', "%{$value}%");
                }
            });
            $queryParams = ['search' => $request['search']];
        } else {
            $bonuses = $this->bonus;
        }

        $bonuses = $bonuses->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);
        return view('admin-views.bonus.index', compact('bonuses', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required',
            'user_type' => 'required|in:all,customer,agent',
            'maximum_add_money_amount' => 'required|numeric|min:0|not_in:0',
            'limit_per_user' => 'required|numeric|min:1',
            'bonus_type' => 'required|in:percentage,flat',
            'bonus' => 'required|numeric|min:0|not_in:0',
            'maximum_bonus_amount' => $request['bonus_type'] == 'percentage' ? 'required|numeric|min:0|not_in:0' : '',
            'start_date_time' => [
                'required',
                'date_format:Y-m-d\TH:i',
                'after_or_equal:today',
            ],
            'end_date_time' => [
                'required',
                'date_format:Y-m-d\TH:i',
                function ($attribute, $value, $fail) use ($request) {
                    $startDateTime = $request->input('start_date_time');
                    if (strtotime($value) <= strtotime($startDateTime)) {
                        $fail('The end date and time must be after the start date and time.');
                    }
                },
            ],
        ]);

        $bonus = $this->bonus;
        $bonus->title = $request['title'];
        $bonus->user_type = $request['user_type'];
        $bonus->min_add_money_amount = $request['maximum_add_money_amount'];
        $bonus->limit_per_user = $request['limit_per_user'];
        $bonus->bonus_type = $request['bonus_type'];
        $bonus->bonus = $request['bonus'];
        $bonus->max_bonus_amount = !is_null($request['maximum_bonus_amount']) ? $request['maximum_bonus_amount'] : 0;
        $bonus->start_date_time = $request['start_date_time'];
        $bonus->end_date_time = $request['end_date_time'];
        $bonus->save();

        Toastr::success(translate('Bonus added successfully!'));
        return back();
    }

    public function edit(int $id): View
    {
        $bonus = $this->bonus->find($id);
        return view('admin-views.bonus.edit', compact('bonus'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'title' => 'required',
            'user_type' => 'required|in:all,customer,agent',
            'maximum_add_money_amount' => 'required|numeric|min:0|not_in:0',
            'limit_per_user' => 'required|numeric|min:1',
            'bonus_type' => 'required|in:percentage,flat',
            'bonus' => 'required|numeric|min:0|not_in:0',
            'maximum_bonus_amount' => $request['bonus_type'] == 'percentage' ? 'required|numeric|min:0|not_in:0' : '',
            'start_date_time' => [
                'required',
                'date_format:Y-m-d\TH:i',
                'after_or_equal:today',
            ],
            'end_date_time' => [
                'required',
                'date_format:Y-m-d\TH:i',
                function ($attribute, $value, $fail) use ($request) {
                    $startDateTime = $request->input('start_date_time');
                    if (strtotime($value) <= strtotime($startDateTime)) {
                        $fail('The end date and time must be after the start date and time.');
                    }
                },
            ],
        ]);

        $bonus = $this->bonus->find($id);
        $bonus->title = $request['title'];
        $bonus->user_type = $request['user_type'];
        $bonus->min_add_money_amount = $request['maximum_add_money_amount'];
        $bonus->limit_per_user = $request['limit_per_user'];
        $bonus->bonus_type = $request['bonus_type'];
        $bonus->bonus = $request['bonus'];
        $bonus->max_bonus_amount = !is_null($request['maximum_bonus_amount']) ? $request['maximum_bonus_amount'] : 0;
        $bonus->start_date_time = $request['start_date_time'];
        $bonus->end_date_time = $request['end_date_time'];
        $bonus->save();

        Toastr::success(translate('Bonus updated successfully!'));
        return back();
    }

    public function status(Request $request): RedirectResponse
    {
        $bonus = $this->bonus->find($request->id);
        $bonus->is_active = !$bonus->is_active;
        $bonus->save();

        Toastr::success(translate('Bonus status updated!'));
        return back();
    }

    public function delete(Request $request): JsonResponse
    {
        $bonus = $this->bonus->find($request->id);
        $bonus->delete();

        return response()->json();
    }
}
