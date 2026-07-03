<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\helpers;
use App\Http\Controllers\Controller;
use App\Models\FAQ;
use App\Models\FAQCategory;
use App\Models\FavouriteNumber;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GeneralController extends Controller
{
    public function __construct(
        private User $user,
        private FAQCategory $FAQCategory,
        private FAQ $faq,
    ){}

    public function faqCategory(Request $request): JsonResponse
    {
        $categoryPriority =  helpers::get_business_settings('faq_category_priority_type') ?? 'latest';

        // Define allowed priority types for validation
        $allowedPriorities = ['latest', 'popularity', 'a_to_z', 'z_to_a'];

        $categories = $this->FAQCategory->active()
            ->when($categoryPriority == 'latest', function ($query) {
                $query->latest();
            })
            ->when($categoryPriority == 'popularity', function ($query) {
                $query->orderBy('click_count', 'DESC');
            })
            ->when($categoryPriority == 'a_to_z', function ($query) {
                $query->orderBy('name', 'ASC');
            })
            ->when($categoryPriority == 'z_to_a', function ($query) {
                $query->orderBy('name', 'DESC');
            })
            ->when(!in_array($categoryPriority, $allowedPriorities), function ($query) {
                $query->latest();
            })
            ->get();

        return response()->json($categories);

    }

    public function faq(Request $request): array
    {
        $limit = $request->has('limit') ? $request->limit : 10;
        $offset = $request->has('offset') ? $request->offset : 1;
        $categoryId = $request->category_id;

        $faqPriority = helpers::get_business_settings('faq_priority_type') ?? 'latest';

        // Define allowed priority types for validation
        $allowedPriorities = ['latest', 'a_to_z', 'z_to_a'];

        $faqs = $this->faq->with('faqCategory')
            ->active()
            ->when($faqPriority == 'latest', function ($query) {
                $query->latest();
            })
            ->when($faqPriority == 'a_to_z', function ($query) {
                $query->orderBy('question', 'ASC');
            })
            ->when($faqPriority == 'z_to_a', function ($query) {
                $query->orderBy('question', 'DESC');
            })
            ->when(!in_array($faqPriority, $allowedPriorities), function ($query) {
                $query->latest();
            })
            ->when($categoryId, function ($query) use ($categoryId){
                $query->where('category_id', $categoryId);
            })
            ->paginate($limit, ['*'], 'page', $offset);

        return [
            'total_size' => $faqs->total(),
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'faqs' => $faqs->items()
        ];
    }

    public function checkCustomer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user = $this->user->where('phone', $request->phone)->where('type', 2)->first();

        if (!isset($user)) {
            return response()->json(['message' => 'Customer is not available'], 404);
        }

        $favoriteNumberStatus =  Helpers::get_business_settings('favorite_number_status') ?? 0;
        $isFavouriteExist = false;
        if ($favoriteNumberStatus == 1) {
            $favouriteList = FavouriteNumber::where('user_id', $request->user()->id)->pluck('phone')->toArray();
            $isFavouriteExist = in_array($user->phone, $favouriteList);
        }

        return response()->json([
            'message' => 'Customer is available',
            'data' => ['name' => $user->f_name . ' ' . $user->l_name, 'image' => $user->image, 'is_favourite' => $isFavouriteExist],
            'user_info' => $user,
        ], 200);
    }

    public function checkAgent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $user = $this->user->where('phone', $request->phone)->where('type', 1)->first();

        if (!isset($user)) {
            return response()->json(['message' => 'Agent is not available'], 404);
        }
        $favoriteNumberStatus =  Helpers::get_business_settings('favorite_number_status') ?? 0;
        $isFavouriteExist = false;
        if ($favoriteNumberStatus == 1) {
            $favouriteList = FavouriteNumber::where('user_id', $request->user()->id)->pluck('phone')->toArray();
            $isFavouriteExist = in_array($user->phone, $favouriteList);
        }

        return response()->json([
            'message' => 'Agent is available',
            'data' => ['name' => $user->f_name . ' ' . $user->l_name, 'image' => $user->image, 'is_favourite' => $isFavouriteExist],
        ], 200);
    }
}
