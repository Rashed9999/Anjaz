<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\CustomerSystemsCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerSystemsCenterController extends Controller
{
    public function __construct(
        private readonly CustomerSystemsCenterService $systems,
    ) {}

    public function index(): View
    {
        return view('admin-views.amial.customer-systems.index', [
            'snapshot' => $this->systems->snapshot(),
        ]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        return response()->json($this->systems->snapshot());
    }
}
