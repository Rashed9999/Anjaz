<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;

class MerchantController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private Merchant $merchant,
        private User $user,
        private EMoney $e_money,
        private Transaction $transaction
    ){}

    public function index(Request $request): View
    {
        $ip = env('APP_MODE') == 'live' ? $request->ip() : '61.247.180.82';
        $currentUserInfo = Location::get($ip);
        return view('admin-views.merchant.index', compact('currentUserInfo'));
    }

    public function list(Request $request): View
    {
        $queryParam = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $merchants = $this->user->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%");
                }
            });
            $queryParam = ['search' => $request['search']];
        } else {
            $merchants = $this->user;
        }

        $merchants = $merchants->with('merchant')->merchantUser()->latest()->paginate(Helpers::pagination_limit())->appends($queryParam);
        return view('admin-views.merchant.list', compact('merchants', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image', 'logo']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'image' => 'required|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'logo' => 'required|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'country_code' => 'required',
            'phone' => 'required|unique:users|min:8|max:20',
            'password' => 'required|min:8',
            'identification_type' => 'required',
            'identification_number' => 'required',
            'store_name' => 'required',
            'callback' => 'required',
            'address' => 'required',
            'bin' => 'required',
            'identification_image' => 'required|array',
            'identification_image.*' => 'image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ],[
            'password.min' => translate('Password must contain 8 characters'),
            'country_code.required' => translate('Country code select is required')
        ]);

        $phone = $request->country_code . $request->phone;
        $merchant = $this->user->where(['phone' => $phone])->first();
        if (isset($merchant)){
            Toastr::warning(translate('This phone number is already taken'));
            return back();
        }

        try {
            DB::beginTransaction();

            if ($request->has('image')) {
                $imageName = Helpers::upload('merchant/', APPLICATION_IMAGE_FORMAT, $request->file('image'));
            } else {
                $imageName = 'def.png';
            }

            if ($request->has('logo')) {
                $logo = Helpers::upload('merchant/', APPLICATION_IMAGE_FORMAT, $request->file('logo'));
            } else {
                $logo = 'def.png';
            }

            $identityImageNames = [];
            if (!empty($request->file('identification_image'))) {
                foreach ($request->identification_image as $img) {
                    $identityImage = Helpers::upload('merchant/', APPLICATION_IMAGE_FORMAT, $img);
                    $identityImageNames[] = $identityImage;
                }
                $identityImage = json_encode($identityImageNames);
            } else {
                $identityImage = json_encode([]);
            }

            $user = $this->user;
            $user->f_name = $request->f_name;
            $user->l_name = $request->l_name;
            $user->email = $request->email;
            $user->dial_country_code = $request->country_code;
            $user->phone = $phone;
            $user->password = bcrypt($request->password);
            $user->type = MERCHANT_TYPE;    //['Admin'=>0, 'Agent'=>1, 'Customer'=>2, 'Merchant'=>3]
            $user->image = $imageName;
            $user->identification_type = $request->identification_type;
            $user->identification_number = $request->identification_number;
            $user->identification_image = $identityImage;
            $user->is_kyc_verified = 1;
            $user->save();

            $user->find($user->id);
            $user->unique_id = $user->id . mt_rand(1111, 99999);
            $user->save();

            $merchant = $this->merchant;
            $merchant->user_id = $user->id;
            $merchant->store_name = $request->store_name;
            $merchant->callback = $request->callback;
            $merchant->address = $request->address;
            $merchant->bin = $request->bin;
            $merchant->logo = $logo;
            $merchant->public_key = Str::random(50);
            $merchant->secret_key = Str::random(50);
            $merchant->merchant_number = $request->phone;
            $merchant->save();

            $emoney = $this->e_money;
            $emoney->user_id = $user->id;
            $emoney->save();

            DB::commit();

            Toastr::success(translate('Merchant Added Successfully!'));
            return redirect()->route('admin.merchant.list');
        }catch (\Exception $exception){
            DB::rollBack();
            Toastr::warning(translate('Merchant Added Failed!'));
            return back();
        }
    }

    public function status(Request $request): RedirectResponse
    {
        $user = $this->user->find($request->id);
        $user->is_active = !$user->is_active;
        $user->save();
        Toastr::success('Merchant status updated!');
        return back();
    }

    public function edit(int $id): View
    {
        $user = $this->user->find($id);
        $merchant = $this->merchant->where(['user_id' => $user->id])->first();
        return view('admin-views.merchant.edit', compact('user','merchant' ));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image', 'logo']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'identification_type' => 'required',
            'identification_number' => 'required',
            'store_name' => 'required',
            'callback' => 'required',
            'address' => 'required',
            'bin' => 'required',
            'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'logo' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            'identification_image' => 'nullable|array',
            'identification_image.*' => 'image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ],[

        ]);

        try {
            DB::beginTransaction();

            $user = $this->user->find($id);
            $merchant = $this->merchant->where(['user_id' => $user->id])->first();

            if ($request->has('image')) {
                $imageName = Helpers::update('merchant/', $user->image, APPLICATION_IMAGE_FORMAT, $request->file('image'));
            } else {
                $imageName = $user['image'];
            }

            if ($request->has('logo')) {
                $logo = Helpers::update('merchant/', $merchant->logo, APPLICATION_IMAGE_FORMAT, $request->file('logo'));
            } else {
                $logo = $merchant['logo'];
            }

            if ($request->has('identification_image')){
                foreach (json_decode($user['identification_image'], true) as $img) {
                    if (Storage::disk('public')->exists('merchant/' . $img)) {
                        Storage::disk('public')->delete('merchant/' . $img);
                    }
                }
                $imgKeeper = [];
                foreach ($request->identification_image as $img) {
                    $identityImage = Helpers::upload('merchant/', APPLICATION_IMAGE_FORMAT, $img);
                    $imgKeeper[] = $identityImage;
                }
                $identityImage = json_encode($imgKeeper);
            } else {
                $identityImage = $user['identification_image'];
            }

            $user->f_name = $request->f_name;
            $user->l_name = $request->l_name;
            $user->email = $request->has('email') ? $request->email : $user->email;

            if ($request->has('password') && strlen($request->password) > 7) {
                $user->password = bcrypt($request->password);
            }

            $user->image = $imageName;
            $user->identification_type = $request->identification_type;
            $user->identification_number = $request->identification_number;
            $user->identification_image = $identityImage;
            $user->update();

            $merchant->user_id = $user->id;
            $merchant->store_name = $request->store_name;
            $merchant->callback = $request->callback;
            $merchant->address = $request->address;
            $merchant->bin = $request->bin;
            $merchant->logo = $logo;
            $merchant->update();

            DB::commit();

            Toastr::success(translate('Merchant Updated Successfully!'));
            return redirect()->route('admin.merchant.list');
        }catch (\Exception $exception){
            DB::rollBack();
            Toastr::warning(translate('Merchant Updated Failed!'));
            return back();
        }
    }

    public function view(int $id): View
    {
        $user = $this->user->with('emoney', 'merchant')->find($id);
        return view('admin-views.view.details', compact('user'));
    }

    public function transaction(Request $request, int $id): View
    {
        $queryParam = [];
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
                        ->orWhere('transaction_id', 'like', "%{$value}%")
                        ->orWhere('balance', 'like', "%{$value}%");
                }
            });
            $queryParam = ['search' => $request['search']];
        } else {
            $transactions = $this->transaction;
        }

        $transactions = $transactions->where('user_id', $id)->latest()->paginate(Helpers::pagination_limit())->appends($queryParam);

        $user = $this->user->find($id);
        return view('admin-views.view.transaction', compact('user', 'transactions', 'search'));
    }
}
