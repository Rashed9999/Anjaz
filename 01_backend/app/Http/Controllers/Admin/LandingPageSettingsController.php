<?php

namespace App\Http\Controllers\Admin;

use App\Traits\UploadSizeHelperTrait;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\RedirectResponse;

class LandingPageSettingsController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private BusinessSetting $business_setting,
    ){}

    public function getLandingPageInformation(Request $request): View
    {
        $request->validate([
            'web_page' => 'required|in:' . implode(',', array_column(LANDING_SECTIONS, 'key')),
        ]);

        $webPage = $request->has('web_page') ? $request['web_page'] : 'intro_section';
        $data = null;
        $userRatingData = null;
        $testimonialData = null;
        $intro = null;
        $businessStatsDownloadData = null;
        $imageSource = [];
        $title = $this->business_setting->where('key', 'landing_' . $webPage . '_title')->value('value');
        $status = $this->business_setting->where('key', 'landing_' . $webPage . '_status')->value('value');

        if($webPage == 'intro_section'){
            $userRatingData = $this->business_setting->where('key', 'user_rating_with_total_user_section')->first();
            $data = $this->business_setting->where('key', $webPage)->first();

            $intro = isset($data->value)?json_decode($data->value, true):null;
            $userRatingData = isset($userRatingData->value)?json_decode($userRatingData->value, true):null;

            $imageSource['user_image_one'] = Helpers::onErrorImage($userRatingData['user_image_one'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $userRatingData['user_image_one'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
            $imageSource['user_image_two'] = Helpers::onErrorImage($userRatingData['user_image_two'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $userRatingData['user_image_two'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
            $imageSource['user_image_three'] = Helpers::onErrorImage($userRatingData['user_image_three'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $userRatingData['user_image_three'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
            $imageSource['review_user_icon'] = Helpers::onErrorImage($userRatingData['review_user_icon'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $userRatingData['review_user_icon'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');

            $imageSource['intro_left_image'] = Helpers::onErrorImage($intro['intro_left_image'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $intro['intro_left_image'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
            $imageSource['intro_middle_image'] = Helpers::onErrorImage($intro['intro_middle_image'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $intro['intro_middle_image'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
            $imageSource['intro_right_image'] = Helpers::onErrorImage($intro['intro_right_image'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $intro['intro_right_image'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
        }
        elseif($webPage == 'business_statistics'){
            $businessStatsDownloadData = $this->business_setting->where('key', 'business_statistics_download')->first();
            $testimonialData = $this->business_setting->where('key', 'testimonial')->first();
        }else{
            $data = $this->business_setting->where('key', $webPage)->first();
            $titleAndStatus = $this->business_setting->whereIn('key', ['landing_'.$webPage.'_title','landing_'.$webPage.'_status'])->get();
        }

        return view('admin-views.business-settings.landing-settings.landing-index', compact('data', 'webPage', 'imageSource', 'intro', 'userRatingData', 'businessStatsDownloadData', 'testimonialData', 'title', 'status'));
    }

    public function updateLandingPageInformation(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'web_page' => 'required|in:' . implode(',', array_column(LANDING_SECTIONS, 'key')),
        ]);
        if ($request['web_page'] == 'intro_section') {
            $request->validate([
                'type' => 'required|in:review_and_rating,intro_section_form',
            ]);
            if($request->type == 'intro_section_form'){

                $check = $this->validateUploadedFile($request, ['intro_left_image', 'intro_middle_image', 'intro_right_image']);
                if ($check !== true) {
                    return $check;
                }

                $request->validate([
                    'title' => 'nullable|string',
                    'description' => 'nullable|string',
                    'download_link' => 'nullable|string',
                    'button_name' => 'nullable|string',
                    'intro_left_image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                    'intro_middle_image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                    'intro_right_image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                ]);

                $data = [];
                $intro = $this->business_setting->where('key', 'intro_section')->first();
                if ($intro) {
                    $data = json_decode($intro?->value, true);
                }

                $introData = [
                    'title' => $request->has('title') ? $request['title'] : $data['title'],
                    'description' => $request->has('description') ? $request['description'] : $data['description'],
                    'download_link' => $request->has('download_link') ? $request['download_link'] : $data['download_link'],
                    'button_name' => $request->has('button_name') ? $request['button_name'] : $data['button_name'],
                    'intro_left_image' => $request->has('intro_left_image') ? Helpers::update('landing-page/intro-section/', $data['intro_left_image'], APPLICATION_IMAGE_FORMAT, $request->file('intro_left_image')) : $data['intro_left_image'],
                    'intro_middle_image' =>  $request->has('intro_middle_image') ? Helpers::update('landing-page/intro-section/', $data['intro_middle_image'], APPLICATION_IMAGE_FORMAT, $request->file('intro_middle_image')) : $data['intro_middle_image'],
                    'intro_right_image' =>  $request->has('intro_right_image') ? Helpers::update('landing-page/intro-section/', $data['intro_right_image'],APPLICATION_IMAGE_FORMAT, $request->file('intro_right_image')) : $data['intro_right_image'],
                ];

                $this->business_setting->query()->updateOrInsert(['key' => 'intro_section'], [
                    'value' => json_encode($introData)
                ]);
            }elseif($request->type == 'review_and_rating'){

                $check = $this->validateUploadedFile($request, ['review_user_icon', 'user_image_one', 'user_image_two', 'user_image_three']);
                if ($check !== true) {
                    return $check;
                }

                $request->validate([
                    'review_user_icon' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                    'user_image_one' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                    'user_image_two' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                    'user_image_three' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                ]);

                $data = [];
                $intro = $this->business_setting->where('key', 'user_rating_with_total_user_section')->first();
                if ($intro) {
                    $data = json_decode($intro?->value, true);
                }

                $introData = [
                    'reviewer_name' => $request['reviewer_name'],
                    'rating' => $request['rating'],
                    'total_user_count' => $request['total_user_count'],
                    'total_user_content' => $request['total_user_content'],
                    'review_user_icon' => $request->has('review_user_icon') ? Helpers::update('landing-page/intro-section/', $data['review_user_icon'], APPLICATION_IMAGE_FORMAT, $request->file('review_user_icon')) : $data['review_user_icon'],
                    'user_image_one' => $request->has('user_image_one') ? Helpers::update('landing-page/intro-section/', $data['user_image_one'], APPLICATION_IMAGE_FORMAT, $request->file('user_image_one')) : $data['user_image_one'],
                    'user_image_two' =>  $request->has('user_image_two') ? Helpers::update('landing-page/intro-section/', $data['user_image_two'], APPLICATION_IMAGE_FORMAT, $request->file('user_image_two')) : $data['user_image_two'],
                    'user_image_three' =>  $request->has('user_image_three') ? Helpers::update('landing-page/intro-section/', $data['user_image_three'],APPLICATION_IMAGE_FORMAT, $request->file('user_image_three')) : $data['user_image_three'],
                ];

                $this->business_setting->query()->updateOrInsert(['key' => 'user_rating_with_total_user_section'], [
                    'value' => json_encode($introData)
                ]);
            }
        }
        elseif ($request['web_page'] == 'feature') {
            $check = $this->validateUploadedFile($request, ['image']);
            if ($check !== true) {
                return $check;
            }

            $request->validate([
                'title' => 'required',
                'sub_title' => 'required',
                'image' => ($request->has('id') ? 'nullable' : 'required')
                    . '|image|max:' . $this->maxImageSizeKB
                    . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            ]);

            $feature = $this->business_setting->where('key', 'feature')->first();
            if ($feature) {
                $data = json_decode($feature->value, true);
            } else {
                $data = [];
            }

            $id = $request->input('id');

            $existingFeature = null;
            foreach ($data as $key => $item) {
                if ($item['id'] == $id) {
                    $existingFeature = $key;
                    break;
                }
            }

            if ($existingFeature !== null) {
                $data[$existingFeature]['id'] = $id;
                $data[$existingFeature]['title'] = $request['title'];
                $data[$existingFeature]['sub_title'] = $request['sub_title'];
                $data[$existingFeature]['image'] = $request->has('image') ? Helpers::update('landing-page/feature/', $data[$existingFeature]['image'], APPLICATION_IMAGE_FORMAT, $request->file('image')) : $data[$existingFeature]['image'];

            } else {
                $newItem = [
                    'id' => rand(1000000000, 9999999999),
                    'title' => $request['title'],
                    'sub_title' => $request['sub_title'],
                    'status' => "1",
                    'image' => $request->has('image') ? Helpers::update('landing-page/feature/', null, APPLICATION_IMAGE_FORMAT, $request->file('image')) : null,
                ];

                $data[] = $newItem;
            }

            $this->business_setting->query()->updateOrInsert(['key' => 'feature'], [
                'value' => json_encode($data)
            ]);
        }
        elseif ($request['web_page'] == 'screenshots') {

            $check = $this->validateUploadedFile($request, ['image']);
            if ($check !== true) {
                return $check;
            }

            $request->validate([
                'image' => ($request->has('id') ? 'nullable' : 'required')
                    . '|image|max:' . $this->maxImageSizeKB
                    . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            ]);

            $screenshots = $this->business_setting->where('key', 'screenshots')->first();
            if ($screenshots) {
                $data = json_decode($screenshots->value, true);
            } else {
                $data = [];
            }

            $id = $request->input('id');

            $existingScreenshots = null;
            foreach ($data as $key => $item) {
                if ($item['id'] == $id) {
                    $existingScreenshots = $key;
                    break;
                }
            }

            if ($existingScreenshots !== null) {
                $data[$existingScreenshots]['id'] = $id;
                $data[$existingScreenshots]['image'] = $request->has('image') ? Helpers::update('landing-page/screenshots/', $data[$existingScreenshots]['image'], APPLICATION_IMAGE_FORMAT, $request->file('image')) : $data[$existingScreenshots]['image'];
            }else {
                $newItem = [
                    'id' => rand(1000000000, 9999999999),
                    'image' => $request->has('image') ? Helpers::update('landing-page/screenshots/', null, APPLICATION_IMAGE_FORMAT, $request->file('image')) : null,
                    'status' => '1'
                ];

                $data[] = $newItem;
            }

            $this->business_setting->query()->updateOrInsert(['key' => 'landing_screenshots_status'], [
                'value' => $request['status']
            ]);

            $this->business_setting->query()->updateOrInsert(['key' => 'screenshots'], [
                'value' => json_encode($data)
            ]);
        }
        elseif ($request['web_page'] == 'why_choose_us') {

            $check = $this->validateUploadedFile($request, ['image']);
            if ($check !== true) {
                return $check;
            }

            $request->validate([
                'title' => 'required',
                'sub_title' => 'required',
                'image' => ($request->has('id') ? 'nullable' : 'required')
                    . '|image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            ]);

            $whyChooseUs = $this->business_setting->where('key', 'why_choose_us')->first();
            if ($whyChooseUs) {
                $data = json_decode($whyChooseUs->value, true);
            } else {
                $data = [];
            }

            $id = $request->input('id');

            $existingChooseUs = null;
            foreach ($data as $key => $item) {
                if ($item['id'] == $id) {
                    $existingChooseUs = $key;
                    break;
                }
            }

            if ($existingChooseUs !== null) {
                $data[$existingChooseUs]['id'] = $id;
                $data[$existingChooseUs]['title'] = $request['title'];
                $data[$existingChooseUs]['sub_title'] = $request['sub_title'];
                $data[$existingChooseUs]['image'] = $request->has('image') ? Helpers::update('landing-page/why-choose-us/', $data[$existingChooseUs]['image'], APPLICATION_IMAGE_FORMAT, $request->file('image')) : $data[$existingChooseUs]['image'];

            }else {
                $newItem = [
                    'id' => rand(1000000000, 9999999999),
                    'title' => $request['title'],
                    'sub_title' => $request['sub_title'],
                    'status' => "1",
                    'image' => $request->has('image') ? Helpers::update('landing-page/why-choose-us/', null, APPLICATION_IMAGE_FORMAT, $request->file('image')) : null,
                ];

                $data[] = $newItem;
            }


            $this->business_setting->query()->updateOrInsert(['key' => 'why_choose_us'], [
                'value' => json_encode($data)
            ]);
        }
        else if ($request['web_page'] == 'agent_registration_section') {

            $check = $this->validateUploadedFile($request, ['banner']);
            if ($check !== true) {
                return $check;
            }

            $request->validate([
                'title' => 'required',
                'banner' => 'nullable|image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            ]);

            $data = [];
            $intro = $this->business_setting->where('key', 'agent_registration_section')->first();
            if ($intro) {
                $data = json_decode($intro?->value, true);
            }

            $introData = [
                'title' => $request['title'],
                'banner' => $request->has('banner') ? Helpers::update('landing-page/agent-registration/', $data['banner'], APPLICATION_IMAGE_FORMAT, $request->file('banner')) : $data['banner'],
            ];

            $this->business_setting->query()->updateOrInsert(['key' => 'agent_registration_section'], [
                'value' => json_encode($introData)
            ]);
        }
        elseif ($request['web_page'] == 'how_it_works_section') {

            $check = $this->validateUploadedFile($request, ['image']);
            if ($check !== true) {
                return $check;
            }

            $request->validate([
                'title' => 'required',
                'sub_title' => 'required',
                'image' => ($request->has('id') ? 'nullable' : 'required')
                    . '|image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                ]);

            $howItWorksSection = $this->business_setting->where('key', 'how_it_works_section')->first();
            if ($howItWorksSection) {
                $data = json_decode($howItWorksSection->value, true);
            } else {
                $data = [];
            }

            $id = $request->input('id');

            $existingWorks = null;
            foreach ($data as $key => $item) {
                if ($item['id'] == $id) {
                    $existingWorks = $key;
                    break;
                }
            }

            if ($existingWorks !== null) {
                $data[$existingWorks]['id'] = $id;
                $data[$existingWorks]['title'] = $request['title'];
                $data[$existingWorks]['sub_title'] = $request['sub_title'];
                $data[$existingWorks]['image'] = $request->has('image') ? Helpers::update('landing-page/how-it-works/', $data[$existingWorks]['image'], APPLICATION_IMAGE_FORMAT, $request->file('image')) : $data[$existingWorks]['image'];

            }else{
                $newItem = [
                    'id' => rand(1000000000, 9999999999),
                    'title' => $request['title'],
                    'sub_title' => $request['sub_title'],
                    'status' => "1",
                    'image' => $request->has('image') ? Helpers::update('landing-page/how-it-works/', null, APPLICATION_IMAGE_FORMAT, $request->file('image')) : null,
                ];

                $data[] = $newItem;
            }

            $this->business_setting->query()->updateOrInsert(['key' => 'how_it_works_section'], [
                'value' => json_encode($data)
            ]);
        }
        elseif ($request['web_page'] == 'download_section') {

            $check = $this->validateUploadedFile($request, ['image']);
            if ($check !== true) {
                return $check;
            }

            $request->validate([
                'title' => 'string',
                'sub_title' => 'string',
                'play_store_link' => 'nullable|string',
                'app_store_link' => 'nullable|string',
                'image' => 'nullable||image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            ]);

            $data = [];
            $intro = $this->business_setting->where('key', 'download_section')->first();
            if ($intro) {
                $data = json_decode($intro?->value, true);
            }

            $introData = [
                'title' => $request['title'],
                'sub_title' => $request['sub_title'],
                'play_store_link' => $request['play_store_link'],
                'app_store_link' => $request['app_store_link'],
                'image' => $request->has('image') ? Helpers::update('landing-page/download-section/', $data['image'], APPLICATION_IMAGE_FORMAT, $request->file('image')) : $data['image'],
            ];

            $this->business_setting->query()->updateOrInsert(['key' => 'download_section'], [
                'value' => json_encode($introData)
            ]);
        }
        elseif ($request['web_page'] == 'business_statistics') {
            $request->validate([
                'type' => 'required|in:business_statistics_download,testimonial',
            ]);

            if($request->type == 'business_statistics_download'){

                $check = $this->validateUploadedFile($request, ['download_icon', 'review_icon', 'country_icon']);
                if ($check !== true) {
                    return $check;
                }

                $request->validate([
                    'download_icon' => 'nullable||image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                    'review_icon' => 'nullable||image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                    'country_icon' => 'nullable||image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                ]);

                $data = [];
                $intro = $this->business_setting->where('key', 'business_statistics_download')->first();
                if ($intro) {
                    $data = json_decode($intro?->value, true);
                }

                $introData = [
                    'download_count' => $request['download_count'],
                    'review_count' => $request['review_count'],
                    'title' => $request['title'],
                    'sub_title' => $request['sub_title'],
                    'country_count' => $request['country_count'],
                    'download_sort_description' => $request['download_description'],
                    'review_sort_description' => $request['review_description'],
                    'country_sort_description' => $request['country_description'],
                    'download_icon' => $request->has('download_icon') ? Helpers::update('landing-page/business-statistics/', $data['download_icon'], APPLICATION_IMAGE_FORMAT, $request->file('download_icon')) : $data['download_icon'],
                    'review_icon' => $request->has('review_icon') ? Helpers::update('landing-page/business-statistics/', $data['review_icon'], APPLICATION_IMAGE_FORMAT, $request->file('review_icon')) : $data['review_icon'],
                    'country_icon' => $request->has('country_icon') ? Helpers::update('landing-page/business-statistics/', $data['country_icon'], APPLICATION_IMAGE_FORMAT, $request->file('country_icon')) : $data['country_icon'],
                ];

                $this->business_setting->query()->updateOrInsert(['key' => 'business_statistics_download'], [
                    'value' => json_encode($introData)
                ]);
            }else{

                $check = $this->validateUploadedFile($request, ['image']);
                if ($check !== true) {
                    return $check;
                }

                $request->validate([
                    'image' => ($request->has('id') ? 'nullable' : 'required')
                        . '|image|max:' . $this->maxImageSizeKB . '|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
                ]);

                $testimonial = $this->business_setting->where('key', 'testimonial')->first();
                if ($testimonial) {
                    $data = json_decode($testimonial->value, true);
                } else {
                    $data = [];
                }

                $id = $request->input('id');

                $existingTestimonial = null;
                foreach ($data as $key => $item) {
                    if ($item['id'] == $id) {
                        $existingTestimonial = $key;
                        break;
                    }
                }

                if ($existingTestimonial !== null) {
                    $data[$existingTestimonial]['id'] = $id;
                    $data[$existingTestimonial]['name'] = $request['name'];
                    $data[$existingTestimonial]['rating'] = $request['rating'];
                    $data[$existingTestimonial]['opinion'] = $request['opinion'];
                    $data[$existingTestimonial]['user_type'] = $request['user_type'];
                    $data[$existingTestimonial]['image'] = $request->has('image') ? Helpers::update('landing-page/testimonial/', $data[$existingTestimonial]['image'], APPLICATION_IMAGE_FORMAT, $request->file('image')) : $data[$existingTestimonial]['image'];

                }else{
                    $newItem = [
                        'id' => rand(1000000000, 9999999999),
                        'rating' => $request['rating'],
                        'name' => $request['name'],
                        'opinion' => $request['opinion'],
                        'user_type' => $request['user_type'],
                        'status' => "1",
                        'image' => $request->has('image') ? Helpers::update('landing-page/testimonial/', null, APPLICATION_IMAGE_FORMAT, $request->file('image')) : null,
                    ];

                    $data[] = $newItem;
                }

                $this->business_setting->query()->updateOrInsert(['key' => 'testimonial'], [
                    'value' => json_encode($data)
                ]);
            }
        }
        elseif ($request['web_page'] == 'contact_us_section') {
            $request->validate([
                'title' => 'string',
                'sub_title' => 'string',
            ]);
            $data = [];
            $intro = $this->business_setting->where('key', 'contact_us_section')->first();
            if ($intro) {
                $data = json_decode($intro?->value, true);
            }
            $introData = [
                'title' => $request['title'],
                'sub_title' => $request['sub_title']
            ];

            $this->business_setting->query()->updateOrInsert(['key' => 'contact_us_section'], [
                'value' => json_encode($introData)
            ]);
        }

        if ($request->ajax()) {
            return response()->json(response_formatter(DEFAULT_UPDATE_200), 200);
        }

        Toastr::success(DEFAULT_UPDATE_200['message']);
        return back();
    }

    public function landingPageInformationDelete(string $page, string $id): RedirectResponse
    {
        if (!in_array($page, array_column(LANDING_SECTIONS, 'key'))) {
            Toastr::error(translate('Invalid_Page_Name'));
            return redirect()->back();
        }
        $array = [];
        if ($page == 'feature') {
            $feature = $this->business_setting->where('key', 'feature')->first();
            if ($feature) {
                $data = json_decode($feature->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] != $id) {
                    $array[] = $value;
                } else {
                    file_remover('landing-page/feature/', $value['image']);
                }
            }
        }elseif ($page == 'screenshots') {
            $screenshots = $this->business_setting->where('key', 'screenshots')->first();
            if ($screenshots) {
                $data = json_decode($screenshots->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] != $id) {
                    $array[] = $value;
                } else {
                    file_remover('landing-page/screenshots/', $value['image']);
                }
            }
        }elseif ($page == 'why_choose_us') {
            $whyChooseUs = $this->business_setting->where('key', 'why_choose_us')->first();
            if ($whyChooseUs) {
                $data = json_decode($whyChooseUs->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] != $id) {
                    $array[] = $value;
                } else {
                    file_remover('landing-page/why-choose-us/', $value['image']);
                }
            }
        }elseif ($page == 'how_it_works_section') {
            $howItWorks = $this->business_setting->where('key', 'how_it_works_section')->first();
            if ($howItWorks) {
                $data = json_decode($howItWorks->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] != $id) {
                    $array[] = $value;
                } else {
                    file_remover('landing-page/how-it-works/', $value['image']);
                }
            }
        }elseif ($page == 'business_statistics') {
            $testimonial = $this->business_setting->where('key', 'testimonial')->first();
            if ($testimonial) {
                $data = json_decode($testimonial->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] != $id) {
                    $array[] = $value;
                } else {
                    file_remover('landing-page/testimonial/', $value['image']);
                }
            }
        }
        if($page == 'business_statistics'){
            $this->business_setting->query()->updateOrInsert(['key' => 'testimonial'], [
                'value' => $array
            ]);
        }else{
            $this->business_setting->query()->updateOrInsert(['key' => $page], [
                'value' => $array
            ]);
        }

        Toastr::success(DEFAULT_DELETE_200['message']);
        return back();
    }

    public function landingPageStatusUpdate(string $page, string $id): RedirectResponse
    {
        if (!in_array($page, array_column(LANDING_SECTIONS, 'key'))) {
            Toastr::error(translate('Invalid_Page_Name'));
            return redirect()->back();
        }
        $array = [];
        if ($page == 'screenshots') {
            $screenshots = $this->business_setting->where('key', 'screenshots')->first();
            if ($screenshots) {
                $data = json_decode($screenshots->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] == $id) {
                    $value['status'] = ($value['status'] == 0) ? 1 : 0;
                }
                $array[] = $value;
            }
        }elseif ($page == 'feature') {
            $feature = $this->business_setting->where('key', 'feature')->first();
            if ($feature) {
                $data = json_decode($feature->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] == $id) {
                    $value['status'] = ($value['status'] == 0) ? 1 : 0;
                }
                $array[] = $value;
            }
        }elseif ($page == 'why_choose_us') {
            $feature = $this->business_setting->where('key', 'why_choose_us')->first();
            if ($feature) {
                $data = json_decode($feature->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] == $id) {
                    $value['status'] = ($value['status'] == 0) ? 1 : 0;
                }
                $array[] = $value;
            }
        }elseif ($page == 'how_it_works_section') {
            $howWorks = $this->business_setting->where('key', 'how_it_works_section')->first();
            if ($howWorks) {
                $data = json_decode($howWorks->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] == $id) {
                    $value['status'] = ($value['status'] == 0) ? 1 : 0;
                }
                $array[] = $value;
            }
        }elseif ($page == 'business_statistics') {
            $testimonial = $this->business_setting->where('key', 'testimonial')->first();
            if ($testimonial) {
                $data = json_decode($testimonial->value, true);
            } else {
                $data = [];
            }
            foreach ($data as $value) {
                if ($value['id'] == $id) {
                    $value['status'] = ($value['status'] == 0) ? 1 : 0;
                }
                $array[] = $value;
            }
        }
        if($page == 'business_statistics'){
            $this->business_setting->query()->updateOrInsert(['key' => 'testimonial'], [
                'value' => $array
            ]);
        }else{
            $this->business_setting->query()->updateOrInsert(['key' => $page], [
                'value' => $array
            ]);
        }
        Toastr::success(DEFAULT_STATUS_UPDATE_200['message']);
        return back();
    }

    public function landingPageTitleAndStatus(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'web_page' => 'required|in:' . implode(',', array_column(LANDING_SECTIONS, 'key')),
        ]);
        if ($request['web_page'] == 'intro_section') {
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_intro_section_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
        }
        elseif ($request['web_page'] == 'feature') {
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_feature_title'], [
                'value' => $request->title
            ]);

            $this->business_setting->query()->updateOrInsert(['key' => 'landing_feature_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
        }
        elseif ($request['web_page'] == 'screenshots') {
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_screenshots_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
        }
        elseif ($request['web_page'] == 'why_choose_us') {

            $this->business_setting->query()->updateOrInsert(['key' => 'landing_why_choose_us_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_why_choose_us_title'], [
                'value' => $request->title
            ]);
        }
        else if ($request['web_page'] == 'agent_registration_section') {

            $this->business_setting->query()->updateOrInsert(['key' => 'landing_agent_registration_section_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
        }
        elseif ($request['web_page'] == 'how_it_works_section') {
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_how_it_works_section_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_how_it_works_section_title'], [
                'value' => $request->title
            ]);
        }
        elseif ($request['web_page'] == 'download_section') {
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_download_section_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
        }
        elseif ($request['web_page'] == 'business_statistics') {
            $this->business_setting->query()->updateOrInsert(['key' => 'landing_business_statistics_status'], [
                'value' => $request->has('status') ? '1' : '0'
            ]);
        }

        if ($request->ajax()) {
            return response()->json(response_formatter(DEFAULT_UPDATE_200), 200);
        }
        Toastr::success(DEFAULT_UPDATE_200['message']);
        return back();
    }
}
