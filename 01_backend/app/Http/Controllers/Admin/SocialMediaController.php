<?php

namespace App\Http\Controllers\Admin;

use App\Models\SocialMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;

class SocialMediaController extends Controller
{
    public function index(): View
    {
        return view('admin-views.social-media.social-media');
    }

    public function store(Request $request): JsonResponse
    {
        try {
            SocialMedia::updateOrInsert([
                'name' => $request->get('name'),
            ], [
                'name' => $request->get('name'),
                'link' => $request->get('link'),
            ]);

            return response()->json([
                'success' => 1,
            ]);

        } catch (\Exception $exception) {
            return response()->json([
                'error' => 1,
            ]);
        }
    }

    public function show(int $socialMedia): JsonResponse
    {
        $data = SocialMedia::where('id', $socialMedia)->first();
        return response()->json($data);
    }

    public function edit(SocialMedia $socialMedia): JsonResponse
    {
        return response()->json($socialMedia);
    }

    public function update(Request $request, int $socialMedia): JsonResponse
    {
        $socialMedia = SocialMedia::find($socialMedia);
        $socialMedia->name = $request->name;
        $socialMedia->link = $request->link;
        $socialMedia->save();

        return response()->json();
    }

    public function fetch(Request $request): JsonResponse
    {
        if ($request->ajax()) {
            $data = SocialMedia::orderBy('id', 'desc')->get()
            ->map(function ($data) {
                return [
                    'id' => $data->id,
                    'name' => translate($data->name),
                    'link' => $data->link,
                    'status' => $data->status,
                ];
            });
        } else {
            $data = [];
        }

        return response()->json($data);
    }

    public function socialMediaStatusUpdate(Request $request): RedirectResponse
    {
        $data=SocialMedia::find($request?->id);
        $data->status =  $data->status ? 0 :1;
        $data?->save();

        if($data->status == 1){
            Toastr::success(Str::title($data->name).' '.translate('is_Enabled!'));
        } else{
            Toastr::warning(Str::title($data->name).' '.translate('is_Disabled!'));
        }

        return back();
    }
}
