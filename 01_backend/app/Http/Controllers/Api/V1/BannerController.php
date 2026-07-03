<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;

class BannerController extends Controller
{
    public function __construct(
        private Banner $banner
    ){}

    public function getCustomerBanner(Request $request): Collection
    {
        $banners = $this->banner->select('title', 'image', 'url', 'receiver')->customerAndAll()->active()->get();
        return $banners;
    }

    public function getAgentBanner(Request $request): Collection
    {
        $banners = $this->banner->select('title', 'image', 'url', 'receiver')->agentAndAll()->active()->get();
        return $banners;
    }
}
