<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private Banner $banner
    ){}

    function index(Request $request): View
    {
        $queryParams = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $banner = $this->banner->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('title', 'like', "%{$value}%");
                }
            });
            $queryParams = ['search' => $request['search']];
        } else {
            $banner = $this->banner;
        }

        $banners = $banner->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);
        return view('admin-views.banner.index', compact('banners', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'url' => 'required|url',
            'image' => 'required|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        try {
            $banner = $this->banner;
            $banner->title = $request->title;
            $banner->url = $request->url;
            $banner->image = $request->has('image') ? Helpers::upload('banner/', APPLICATION_IMAGE_FORMAT, $request->file('image')) : null;
            $banner->status = 1;
            $banner->receiver = $request->has('receiver') ? $request->receiver : null;
            $banner->save();

        } catch (\Exception $e) {
            Toastr::warning(translate('Banner added failed'));
            return back();
        }

        Toastr::success(translate('Banner added successfully'));
        return back();
    }

    public function edit(int $id): View
    {
        $banner = $this->banner->find($id);
        return view('admin-views.banner.edit', compact('banner'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'url' => 'required|url',
            'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        $banner = $this->banner->find($id);
        $banner->title = $request->title;
        $banner->url = $request->url;
        $banner->image = $request->has('image') ? Helpers::update('banner/', $banner->image, APPLICATION_IMAGE_FORMAT, $request->file('image')) : $banner->image;
        $banner->receiver = $request->has('receiver') ? $request->receiver : $banner->receiver;
        $banner->save();
        Toastr::success(translate('Banner updated successfully'));
        return redirect(route('admin.banner.index'));
    }

    public function status(Request $request): RedirectResponse
    {
        $banner = $this->banner->find($request->id);
        $banner->status = !$banner->status;
        $banner->save();
        Toastr::success(translate('Banner status updated'));
        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $banner = $this->banner->find($request->id);
        Helpers::delete('banner/' . $banner['image']);
        $banner->delete();
        Toastr::success(translate('Banner removed'));
        return back();
    }
}
