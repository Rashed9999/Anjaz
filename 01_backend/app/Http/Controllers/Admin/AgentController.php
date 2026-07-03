<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Stevebauman\Location\Facades\Location;

class AgentController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private User        $user,
        private EMoney      $eMoney,
        private Transaction $transaction
    )
    {
    }

    public function index(Request $request): View
    {
        $ip = env('APP_MODE') == 'live' ? $request->ip() : '61.247.180.82';
        $currentUserInfo = Location::get($ip);
        return view('admin-views.agent.index', compact('currentUserInfo'));
    }

    public function list(Request $request): View
    {
        $queryParams = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $agents = $this->user->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%");
                }
            });
            $queryParams = ['search' => $request['search']];
        } else {
            $agents = $this->user;
        }

        $agents = $agents->agent()->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);
        return view('admin-views.agent.list', compact('agents', 'search'));
    }

    public function search(Request $request): JsonResponse
    {
        $key = explode(' ', $request['search']);
        $delivery_men = DeliveryMan::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('f_name', 'like', "%{$value}%")
                    ->orWhere('l_name', 'like', "%{$value}%")
                    ->orWhere('email', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%")
                    ->orWhere('identity_number', 'like', "%{$value}%");
            }
        })->get();
        return response()->json([
            'view' => view('admin-views.agent.partials._table', compact('delivery_men'))->render()
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'image' => 'required|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'email' => '',
            'phone' => [
                'required',
                Rule::unique('users')->where(function ($query) {
                    return $query->whereNull('deleted_at');
                }),
                'min:5',
                'max:20',
            ],
            'country_code' => 'required',
            'gender' => 'required',
            'occupation' => 'required',
            'password' => 'required|min:4|max:4',
        ], [
            'password.min' => translate('Password must contain 4 characters'),
            'password.max' => translate('Password must contain 4 characters'),
        ]);

        $phone = $request->country_code . $request->phone;
        $agent = $this->user->where(['phone' => $phone])->first();
        if (isset($agent)) {
            Toastr::warning(translate('This phone number is already taken'));
            return back();
        }

        DB::transaction(function () use ($request, $phone) {
            $user = $this->user;
            $user->f_name = $request->f_name;
            $user->l_name = $request->l_name;
            $user->image = Helpers::upload('agent/', APPLICATION_IMAGE_FORMAT, $request->file('image'));
            $user->email = $request->email;
            $user->dial_country_code = $request->country_code;
            $user->phone = $phone;
            $user->gender = $request->gender;
            $user->occupation = $request->occupation;
            $user->password = bcrypt($request->password);
            $user->type = AGENT_TYPE;
            $user->referral_id = $request->referral_id ?? null;
            $user->identification_image = json_encode([]);
            $user->save();

            $user->find($user->id);
            $user->unique_id = $user->id . mt_rand(1111, 99999);
            $user->save();

            $emoney = $this->eMoney;
            $emoney->user_id = $user->id;
            $emoney->save();
        });

        Toastr::success(translate('Agent Added Successfully!'));
        return redirect(route('admin.agent.list'));
    }

    public function edit(int $id): View
    {
        $agent = $this->user->find($id);
        return view('admin-views.agent.edit', compact('agent'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'occupation' => 'required',
            'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        $agent = $this->user->find($id);
        $agent->f_name = $request->f_name;
        $agent->l_name = $request->l_name;
        $agent->image = $request->has('image') ? Helpers::update('agent/', $agent->image, APPLICATION_IMAGE_FORMAT, $request->file('image')) : $agent->image;
        $agent->email = $request->has('email') ? $request->email : $agent->email;
        $agent->gender = $request->has('gender') ? $request->gender : $agent->gender;
        $agent->occupation = $request->occupation;
        if ($request->has('password') && strlen($request->password) > 3) {
            $agent->password = bcrypt($request->password);
        }
        $agent->type = AGENT_TYPE;
        $agent->referral_id = $request->referral_id ?? null;
        $agent->save();

        Toastr::success(translate('Agent updated successfully'));
        return redirect(route('admin.agent.list'));
    }

    public function view(int $id): View
    {
        $user = $this->user->with('emoney')->find($id);
        return view('admin-views.view.details', compact('user'));
    }

    public function transaction(Request $request, int $id): View
    {
        $queryParams = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);

            $users = $this->user->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%")
                        ->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%");
                }
            })->get()->pluck('id')->toArray();

            $transactions = $this->transaction->where(function ($q) use ($key, $users) {
                foreach ($key as $value) {
                    $q->orWhereIn('from_user_id', $users)
                        ->orWhere('to_user_id', $users)
                        ->orWhere('transaction_type', 'like', "%{$value}%")
                        ->orWhere('balance', 'like', "%{$value}%");
                }
            });
            $queryParams = ['search' => $request['search']];
        } else {
            $transactions = $this->transaction;
        }


        $transactions = $transactions->where('user_id', $id)->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);

        $user = $this->user->find($id);
        return view('admin-views.view.transaction', compact('user', 'transactions', 'search'));
    }

    public function status(Request $request): RedirectResponse
    {
        $user = $this->user->find($request->id);
        $user->is_active = !$user->is_active;
        $user->save();
        Toastr::success(translate('Agent status updated'));

        return back();
    }

    public function getKycRequest(Request $request): View
    {
        $queryParams = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $agents = $this->user->where('is_kyc_verified', '!=', 1)->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%");
                }
            });
            $queryParams = ['search' => $request['search']];
        } else {
            $agents = $this->user->where('is_kyc_verified', '!=', 1);
        }

        $agents = $agents->orderByDesc('id')->agent()->paginate(Helpers::pagination_limit())->appends($queryParams);
        return view('admin-views.agent.kyc_list', compact('agents', 'search'));
    }

    public function updateKycStatus(int $id, int $status): RedirectResponse
    {
        $user = $this->user->find($id);
        if (!isset($user)) {
            Toastr::error(translate('agent not found'));
            return back();
        }
        $user->is_kyc_verified = in_array($status, [1, 2]) ? $status : $user->is_kyc_verified;
        $user->save();

        $data = [
            'title' => $status == 1 ? translate('verification_request_is_accepted') : translate('verification_request_is_denied'),
            'description' => '',
            'image' => '',
            'type' => 'kyc_verification',
        ];

        Helpers::send_push_notif_to_device($user->fcm_token, $data);

        Toastr::success(translate('Successfully updated.'));
        return back();
    }
}
