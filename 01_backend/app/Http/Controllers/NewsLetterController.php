<?php

namespace App\Http\Controllers;

use App\Models\NewsLetter;
use Illuminate\Http\Request;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\RedirectResponse;

class NewsLetterController extends Controller
{
    public function __construct(
        private NewsLetter $newsLetter
    ){}

    public function newsLetterSubscribe(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email|unique:news_letters,email',
        ]);

        $newsLetter = $this->newsLetter;
        $newsLetter->email = $request->email;
        $newsLetter->save();

        Toastr::success(translate('subscription_successful'));
        return back();
    }
}
