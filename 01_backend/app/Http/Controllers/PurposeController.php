<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Models\Purpose;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PurposeController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private Purpose $purpose
    ){}

    public function index(): View
    {
        $purposes = $this->purpose->paginate(Helpers::pagination_limit());
        return view('admin-views.purpose.index', compact('purposes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'title' => 'required',
            'color' => 'required',
            'logo' => 'required|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        $purpose = $this->purpose;
        $purpose->title = $request->title;
        $purpose->logo = Helpers::upload('purpose/', APPLICATION_IMAGE_FORMAT, $request->file('logo'));
        $purpose->color = $request->color;
        $purpose->save();

        Toastr::success(translate('Successfully Added!'));
        return back();
    }

    public function edit(Request $request): View
    {
        $purpose = $this->purpose->find($request->id);
        return view('admin-views.purpose.edit', compact('purpose'));
    }

    public function update(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'title' => 'required',
            'color' => 'required',
            'logo' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ]);

        $purpose = $this->purpose->find($request->id);
        $purpose->title = $request->title;
        $purpose->logo = $request->has('logo') ? Helpers::update('purpose/', $purpose->logo, APPLICATION_IMAGE_FORMAT, $request->file('logo')) : $purpose->logo;
        $purpose->color = $request->color;
        $purpose->save();

        Toastr::success(translate('Successfully Updated!'));
        return redirect(route('admin.purpose.index'));
    }

    public function delete(Request $request): RedirectResponse
    {
        $purpose = $this->purpose->find($request->id);
        unlink('storage/app/public/purpose/' . $purpose->logo);
        $purpose->delete();

        Toastr::success(translate('Successfully Deleted!'));
        return back();
    }
}
