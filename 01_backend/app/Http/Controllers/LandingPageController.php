<?php

namespace App\Http\Controllers;

use App\Mail\ContactMail;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\FAQ;
use App\Models\FAQCategory;
use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use App\CentralLogics\Helpers;
use App\Models\ContactMessage;
use App\Models\BusinessSetting;
use Illuminate\Contracts\View\View;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class LandingPageController extends Controller
{
    public function __construct(
        private BusinessSetting $businessSetting,
        private ContactMessage  $contactMessage,
    ) {}

    public function landingPageHome(): View
    {
        $screenshotsData = $this->businessSetting->where('key', 'screenshots')->first();
        $whyChooseUsData = $this->businessSetting->where('key', 'why_choose_us')->first();
        $featureData = $this->businessSetting->where('key', 'feature')->first();
        $howItWorksData = $this->businessSetting->where('key', 'how_it_works_section')->first();
        $testimonialData = $this->businessSetting->where('key', 'testimonial')->first();
        $businessStatisticsDownloadData = $this->businessSetting->where('key', 'business_statistics_download')->first();

        $data = [
            'intro_section' => [
                'data' => Helpers::get_business_settings('intro_section'),
                'rating_and_user_data' => Helpers::get_business_settings('user_rating_with_total_user_section'),
                'status' => $this->businessSetting->where('key', 'landing_intro_section_status')->value('value'),
                'header_title' => null
            ],
            'feature_section' => [
                'data' => $featureData ? $this->filterStatus($featureData) : null,
                'status' => $this->businessSetting->where('key', 'landing_feature_status')->value('value'),
                'header_title' => $this->businessSetting->where('key', 'landing_feature_title')->first()
            ],
            'screenshots_section' => [
                'data' => $screenshotsData ? $this->filterStatus($screenshotsData) : null,
                'status' => $this->businessSetting->where('key', 'landing_screenshots_status')->value('value'),
                'header_title' => null
            ],
            'why_choose_us_section' => [
                'data' => $whyChooseUsData ? $this->filterStatus($whyChooseUsData) : null,
                'status' => $this->businessSetting->where('key', 'landing_why_choose_us_status')->value('value'),
                'header_title' => $this->businessSetting->where('key', 'landing_why_choose_us_title')->first(),
            ],
            'agent_registration_section' => [
                'data' => Helpers::get_business_settings('agent_registration_section'),
                'status' => $this->businessSetting->where('key', 'landing_agent_registration_section_status')->value('value'),
                'header_title' => $this->businessSetting->where('key', 'landing_agent_registration_section_title')->first(),
            ],
            'how_it_works_section' => [
                'data' => $howItWorksData ? $this->filterStatus($howItWorksData) : null,
                'status' => $this->businessSetting->where('key', 'landing_how_it_works_section_status')->value('value'),
                'header_title' => $this->businessSetting->where('key', 'landing_how_it_works_section_title')->first(),
            ],
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'business_statistics_section' => [
                'testimonial_data' => $testimonialData ? $this->filterStatusTestimonial($testimonialData) : null,
                'download_data' => $businessStatisticsDownloadData ? json_decode($businessStatisticsDownloadData->value, true) : null,
                'status' => $this->businessSetting->where('key', 'landing_business_statistics_status')->value('value'),
                'header_title' => null
            ]
        ];

        $imageSource = [];
        $imageSource['intro_left_image'] = Helpers::onErrorImage($data['intro_section']['data']['intro_left_image'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $data['intro_section']['data']['intro_left_image'], dynamicAsset(path: 'public/assets/landing/img/media/ss-1.png'), 'landing-page/intro-section/');
        $imageSource['intro_middle_image'] = Helpers::onErrorImage($data['intro_section']['data']['intro_middle_image'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $data['intro_section']['data']['intro_middle_image'], dynamicAsset(path: 'public/assets/landing/img/media/ss-1.png'), 'landing-page/intro-section/');
        $imageSource['intro_right_image'] = Helpers::onErrorImage($data['intro_section']['data']['intro_right_image'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $data['intro_section']['data']['intro_right_image'], dynamicAsset(path: 'public/assets/landing/img/media/ss-1.png'), 'landing-page/intro-section/');

        $imageSource['user_image_one'] = Helpers::onErrorImage($data['intro_section']['rating_and_user_data']['user_image_one'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $data['intro_section']['rating_and_user_data']['user_image_one'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
        $imageSource['user_image_two'] = Helpers::onErrorImage($data['intro_section']['rating_and_user_data']['user_image_two'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $data['intro_section']['rating_and_user_data']['user_image_two'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');
        $imageSource['user_image_three'] = Helpers::onErrorImage($data['intro_section']['rating_and_user_data']['user_image_three'], dynamicStorage(path: 'storage/app/public/landing-page/intro-section') . '/' . $data['intro_section']['rating_and_user_data']['user_image_three'], dynamicAsset(path: 'public/assets/admin/img/900x400/img1.jpg'), 'landing-page/intro-section/');

        return view('landing.landing-page-home', compact('data', 'imageSource'));
    }

    public function contactUs(): View
    {
        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'contact_us_section' => [
                'data' => Helpers::get_business_settings('contact_us_section'),
                'status' => null,
                'header_title' => null
            ]
        ];
        return view('landing.contact-us', compact('data'));
    }

    public function contactUsMessage(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email:filter',
            'subject' => 'required',
            'message' => 'required',
        ], [
            'name.required' => translate('Name is required!'),
            'email.required' => translate('Email is required!'),
            'email.filter' => translate('Must be a valid email!'),
            'message.required' => translate('Message is required!'),
            'subject.required' => translate('Subject is required!'),
        ]);

        $email = Helpers::get_business_settings('email');

        $messageData = [
            'name' => $request->name,
            'email' => $request->email,
            'subject' => $request->subject,
            'message' => $request->message,
        ];

        $contactMessage = $this->contactMessage;
        $contactMessage->name = $request->name;
        $contactMessage->email = $request->email;
        $contactMessage->subject = $request->subject;
        $contactMessage->message = $request->message;
        $contactMessage->save();
        $businessName = Helpers::get_business_settings('business_name') ?? '6Cash';
        $subject = 'Enquiry from ' . $businessName;

        try {
            if (config('mail.status')) {
                Mail::to($email)->send(new ContactMail($messageData, $subject));
                Toastr::success(translate('Thanks_for_your_enquiry._We_will_get_back_to_you_soon.'));
            }
        } catch (\Exception $ex) {
            Toastr::warning(translate('Mail_config_error.'));
            info($ex->getMessage());
        }
        return redirect()->back();
    }

    public function faq(Request $request): RedirectResponse|view
    {
        if (helpers::get_business_settings('faq_section_status') == 0) {
            return redirect()->route('landing-page-home');
        }

        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'faq_section_status' => helpers::get_business_settings('faq_section_status'),
            'faq_intro_title' => helpers::get_business_settings('faq_intro_title'),
            'faq_intro_subtitle' => helpers::get_business_settings('faq_intro_subtitle'),
            'faq_download_app_button_status' => helpers::get_business_settings('faq_download_app_button_status'),
            'faq_download_app_button_title' => helpers::get_business_settings('faq_download_app_button_title'),
            'faq_download_app_button_subtitle' => helpers::get_business_settings('faq_download_app_button_subtitle'),
            'play_store_status' => helpers::get_business_settings('faq_download_app_button_android_button'),
            'app_store_status' => helpers::get_business_settings('faq_download_app_button_apple_button'),
            'icon_image' => helpers::get_business_settings('faq_download_app_button_icon'),
            'background_image' => helpers::get_business_settings('faq_download_app_button_background'),
        ];

        $categoryPriority = helpers::get_business_settings('faq_category_priority_type') ?? 'latest';
        $allowedPriorities = ['latest', 'popularity', 'a_to_z', 'z_to_a'];

        $categories = FAQCategory::active()
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

        $faqPriority = helpers::get_business_settings('faq_priority_type') ?? 'latest';
        $allowedPriorities = ['latest', 'a_to_z', 'z_to_a'];

        $search = $request->input('search');
        $categoryName = $request->category;
        $queryParams = [
            'search' => $search,
            'category' => $categoryName,
        ];

        $faqs = FAQ::active()
            ->with('faqCategory')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('question', 'like', "%{$search}%")
                        ->orWhere('answer', 'like', "%{$search}%")
                        ->orWhereHas('faqCategory', function ($subQuery) use ($search) {
                            $subQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
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
            ->when($categoryName, function ($query) use ($categoryName) {
                $query->whereHas('faqCategory', function ($query) use ($categoryName) {
                    $query->where('name', $categoryName);
                });
            })
            ->paginate(Helpers::pagination_limit())
            ->appends($queryParams);

        return view('landing.faq', compact('data', 'categories', 'faqs'));
    }

    public function blog(Request $request): RedirectResponse|view
    {
        if (helpers::get_business_settings('blog_section_status') == 0) {
            return redirect()->route('landing-page-home');
        }

        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'blog_section_status' => helpers::get_business_settings('blog_section_status'),
            'blog_intro_title' => helpers::get_business_settings('blog_intro_title'),
            'blog_intro_subtitle' => helpers::get_business_settings('blog_intro_subtitle'),
            'blog_download_app_button_status' => helpers::get_business_settings('blog_download_app_button_status'),
            'blog_download_app_button_title' => helpers::get_business_settings('blog_download_app_button_title'),
            'blog_download_app_button_subtitle' => helpers::get_business_settings('blog_download_app_button_subtitle'),
            'play_store_status' => helpers::get_business_settings('blog_download_app_button_android_button'),
            'app_store_status' => helpers::get_business_settings('blog_download_app_button_apple_button'),
            'icon_image' => helpers::get_business_settings('blog_download_app_button_icon'),
            'background_image' => helpers::get_business_settings('blog_download_app_button_background'),
        ];

        $categoryPriority = helpers::get_business_settings('blog_category_priority_type') ?? 'latest';
        $allowedPriorities = ['latest', 'popularity', 'a_to_z', 'z_to_a'];

        $categories = BlogCategory::active()
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

        $blogPriority = helpers::get_business_settings('blog_priority_type') ?? 'latest';
        $allowedPriorities = ['latest', 'a_to_z', 'z_to_a'];

        $search = $request->input('search');
        $categoryName = $request->category;
        $queryParams = [
            'search' => $search,
            'category' => $categoryName,
        ];

        $blogs = Blog::active()
            ->with('blogCategory')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('writer', 'like', "%{$search}%")
                        ->orWhereHas('blogCategory', function ($subQuery) use ($search) {
                            $subQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($blogPriority == 'latest', function ($query) {
                $query->latest();
            })
            ->when($blogPriority == 'popularity', function ($query) {
                $query->orderBy('click_count', 'DESC');
            })
            ->when($blogPriority == 'a_to_z', function ($query) {
                $query->orderBy('title', 'ASC');
            })
            ->when($blogPriority == 'z_to_a', function ($query) {
                $query->orderBy('title', 'DESC');
            })
            ->when(!in_array($blogPriority, $allowedPriorities), function ($query) {
                $query->latest();
            })
            ->when($categoryName, function ($query) use ($categoryName) {
                $query->whereHas('blogCategory', function ($query) use ($categoryName) {
                    $query->where('slug', $categoryName);
                });
            })
            ->paginate(Helpers::pagination_limit())
            ->appends($queryParams);

        $recentBlogs = Blog::active()
            ->with('blogCategory')
            ->latest()
            ->take(5)
            ->get();

        return view('landing.blog', compact('data', 'categories', 'recentBlogs', 'blogs'));
    }

    public function blogDetails($slug): RedirectResponse|view
    {
        if (helpers::get_business_settings('blog_section_status') == 0) {
            return redirect()->route('landing-page-home');
        }

        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ],
            'blog_download_app_button_status' => helpers::get_business_settings('blog_download_app_button_status'),
            'blog_download_app_button_title' => helpers::get_business_settings('blog_download_app_button_title'),
            'blog_download_app_button_subtitle' => helpers::get_business_settings('blog_download_app_button_subtitle'),
            'play_store_status' => helpers::get_business_settings('blog_download_app_button_android_button'),
            'app_store_status' => helpers::get_business_settings('blog_download_app_button_apple_button'),
            'icon_image' => helpers::get_business_settings('blog_download_app_button_icon'),
            'background_image' => helpers::get_business_settings('blog_download_app_button_background'),
        ];

        $blog = Blog::firstWhere('slug', $slug);
        $blog->increment('click_count');

        $description = $blog->description;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($description);
        libxml_clear_errors();

        $articleTags = $dom->getElementsByTagName('h2');
        $articleLinks = [];
        foreach ($articleTags as $index => $articleTag) {
            if ($articleTag instanceof \DOMElement) {
                $articleContent = $articleTag->textContent;
                $id = 'article-section-' . $index;
                $articleTag->setAttribute('id', $id); // Set an ID for each <h2>
                $articleLinks[] = [
                    'id' => $id,
                    'text' => $articleContent
                ];
            }
        }
        $updatedDescription = $dom->saveHTML();

        $popularBlogs = Blog::active()
            ->with('blogCategory')
            ->whereNot('slug', $slug)
            ->orderBy('click_count', 'DESC')
            ->take(3)
            ->get();

        return view('landing.blog-details', compact('data', 'blog', 'updatedDescription', 'articleLinks', 'popularBlogs'));
    }

    public function popularBlogs(Request $request): RedirectResponse|view
    {
        if (helpers::get_business_settings('blog_section_status') == 0) {
            return redirect()->route('landing-page-home');
        }

        $data = [
            'download_section' => [
                'data' => Helpers::get_business_settings('download_section'),
                'status' => $this->businessSetting->where('key', 'landing_download_section_status')->value('value'),
                'header_title' => null
            ]
        ];

        $categoryPriority = helpers::get_business_settings('blog_category_priority_type') ?? 'latest';
        $allowedPriorities = ['latest', 'popularity', 'a_to_z', 'z_to_a'];

        $categories = BlogCategory::active()
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

        $search = $request->input('search');
        $categoryName = $request->category;
        $queryParams = [
            'search' => $search,
            'category' => $categoryName,
        ];

        $popularBlogs = Blog::active()
            ->with('blogCategory')
            ->where('click_count', '>', 0)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('writer', 'like', "%{$search}%");
                });
            })
            ->when($categoryName, function ($query) use ($categoryName) {
                $query->whereHas('blogCategory', function ($query) use ($categoryName) {
                    $query->where('slug', $categoryName);
                });
            })
            ->orderBy('click_count', 'DESC')
            ->paginate(Helpers::pagination_limit())
            ->appends($queryParams);

        return view('landing.popular-blogs', compact('data', 'popularBlogs', 'categories'));
    }

    protected function filterStatus(object $data): array
    {
        return collect(json_decode($data->value, true))->filter(function ($item) {
            return $item['status'] == 1 || $item['status'] == '1';
        })->all();
    }

    protected function filterStatusTestimonial(object $data): Collection
    {
        return collect(json_decode($data->value, true))->filter(function ($item) {
            return $item['status'] == 1 || $item['status'] == '1';
        });
    }
}
