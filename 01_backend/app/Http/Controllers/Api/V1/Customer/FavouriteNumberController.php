<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\FavouriteNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FavouriteNumberController extends Controller
{
    public function index(Request $request): array
    {
        $limit = $request->has('limit') ? $request->limit : 10;
        $offset = $request->has('offset') ? $request->offset : 1;
        $search = $request->search;

        $allFavourites = FavouriteNumber::where('user_id', $request->user()->id)
            ->when(isset($search), function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->get();

        $total = FavouriteNumber::where('user_id', $request->user()->id)->count();

        return [
            'favorite_number_limit' => Helpers::get_business_settings('favorite_number_limit') ?? 0,
            'total' => $total,
            'f_and_f' => $allFavourites->where('type', 'f_and_f')->values(),
            'agent' => $allFavourites->where('type', 'agent')->values(),
            'others' => $allFavourites->where('type', 'others')->values(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:f_and_f,agent,others',
            'name' => 'required|string|max:255',
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^(\+?\d{6,16})$/',
                Rule::unique('favourite_numbers')->where(function ($query) use ($request) {
                    return $query->where('user_id', $request->user()->id);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $favourite = FavouriteNumber::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'phone' => $request->phone,
            'type' => $request->type,
        ]);

        return response()->json([
            'message' => 'Favourite number added.',
            'data' => $favourite
        ], 200);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'sometimes|string|in:f_and_f,agent,others',
            'name' => 'sometimes|string|max:255',
            'phone' => [
                'sometimes',
                'string',
                'max:20',
                'regex:/^(\+?\d{6,16})$/',
                Rule::unique('favourite_numbers')->ignore($id)->where(function ($query) use ($request) {
                    return $query->where('user_id', $request->user()->id);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $favourite = FavouriteNumber::where('user_id', $request->user()->id)->find($id);

        if (!$favourite) {
            return response()->json(['message' => 'Favourite number not found.'], 404);
        }

        $favourite->update([
            'type'   => $request->has('type') ? $request->type : $favourite->type,
            'name'   => $request->has('name') ? $request->name : $favourite->name,
            'phone' => $request->has('phone') ? $request->phone : $favourite->phone,
        ]);

        return response()->json([
            'message' => 'Favourite number updated successfully.',
            'data' => $favourite
        ], 200);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $favourite = FavouriteNumber::where('user_id', $request->user()->id)->find($id);

        if (!$favourite) {
            return response()->json(['message' => 'Favourite number not found.'], 404);
        }

        $favourite->delete();

        return response()->json(['message' => 'Favourite number deleted successfully.'], 200);
    }
}
