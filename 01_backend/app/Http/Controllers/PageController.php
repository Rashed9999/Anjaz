<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\CentralLogics\Helpers;
use Illuminate\Contracts\View\View;

class PageController extends Controller
{
    public function __construct(
        private BusinessSetting $businessSetting,
    ){}

    public function getTermsAndConditions(): View
    {
        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'terms_and_conditions_section' => [
                'title' => $this->businessSetting->where('key', 'terms_and_conditions_title')->value('value'),
                'sub_title' => $this->businessSetting->where('key', 'terms_and_conditions_sub_title')->value('value'),
            ]
        ];

        return view('landing.terms-and-condition', compact('data'));
    }

    public function getPrivacyPolicy(): View
    {
        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'privacy_policy_section' => [
                'title' => $this->businessSetting->where('key', 'privacy_policy_title')->value('value'),
                'sub_title' => $this->businessSetting->where('key', 'privacy_policy_sub_title')->value('value'),
            ]
        ];
        return view('landing.privacy-and-policy', compact('data'));
    }

    public function getAboutUs(): View
    {
        $testimonialData = $this->businessSetting->where('key', 'testimonial')->first();
        $businessStatisticsDownloadData = $this->businessSetting->where('key', 'business_statistics_download')->first();
        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'business_statistics_section' => [
                'testimonial_data' => $testimonialData ? $this->filterStatus($testimonialData) : null,
                'download_data' => $businessStatisticsDownloadData ? json_decode($businessStatisticsDownloadData->value, true) : null,
                'status' => $this->businessSetting->where('key', 'landing_business_statistics_status')->value('value'),
                'header_title' => null
            ],
            'agent_registration_section' => [
                'data' => Helpers::get_business_settings('agent_registration_section'),
                'status' => $this->businessSetting->where('key', 'landing_how_it_works_section_status')->value('value'),
                'header_title' => $this->businessSetting->where('key', 'landing_agent_registration_section_title')->first(),
            ],
            'about_us_section' => [
                'title' => $this->businessSetting->where('key', 'about_us_title')->value('value'),
                'sub_title' => $this->businessSetting->where('key', 'about_us_sub_title')->value('value'),
                'image' => $this->businessSetting->where('key', 'about_us_image')->value('value'),
            ]
        ];

        return view('landing.about-us', compact('data'));
    }

    protected function filterStatus(object $data): array
    {
        return collect(json_decode($data->value, true))->filter(function ($item) {
            return $item['status'] == 1 || $item['status'] == '1';
        })->all();
    }
}
