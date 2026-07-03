<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\UserLogHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private UserLogHistory $userLogHistory,
    ){}

    public function log(Request $request): View
    {
        $search = $request->input('search');
        $queryParam = !empty($search) ? ['search' => $search] : [];

        $userLogs = $this->userLogHistory
            ->with('user')
            ->when(!empty($search), function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('device_id', 'like', "%{$search}%")
                        ->orWhere('browser', 'like', "%{$search}%")
                        ->orWhere('os', 'like', "%{$search}%")
                        ->orWhere('device_model', 'like', "%{$search}%");
                })
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->whereRaw("CONCAT(f_name, ' ', l_name) LIKE ?", ["%{$search}%"]);;
                    });
            });

        $userLogs = $userLogs->latest()->paginate(Helpers::pagination_limit())->appends($queryParam);
        return view('admin-views.user.log-list', compact('userLogs', 'search'));
    }
}
