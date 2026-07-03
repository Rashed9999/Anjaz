<?php

namespace App\Http\Controllers\Admin;

use App\Models\NewsLetter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\CentralLogics\helpers;
use App\Models\ContactMessage;
use App\Http\Controllers\Controller;
use App\Mail\CustomerMessageMail;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Mail;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactMessageController extends Controller
{
    public function list(Request $request): View
    {
        $queryParams = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $contacts = ContactMessage::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('name', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%")
                        ->orWhere('mobile_number', 'like', "%{$value}%");
                }
            });
            $queryParams = ['search' => $request['search']];
        } else {
            $contacts = new ContactMessage();
        }
        $contacts = $contacts->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);
        return view('admin-views.contact-message.index', compact('contacts', 'search'));
    }

    public function view(int $id): View
    {
        $contact = ContactMessage::findOrFail($id);
        return view('admin-views.contact-message.view', compact('contact'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $contact = ContactMessage::find($request->id);
        $contact->delete();
        Toastr::success(translate('Message Delete successfully'));
        return redirect()->route('admin.contact.list');
    }

    public function sendMail(Request $request, int $id): RedirectResponse
    {
        $contact = ContactMessage::findOrFail($id);
        $data = array('body' => $request['mail_body'],
            'name' => $contact->name
        );

        try {
            Mail::to($contact['email'])->send(new CustomerMessageMail($request, $data));

            $contact->update([
                'reply' => json_encode([
                    'subject' => $request['subject'],
                    'body' => $request['mail_body']
                ]),
                'seen' => 1,
            ]);
            Toastr::success(translate('Mail_sent_successfully'));
            return back();
        } catch (\Throwable $th) {
            Toastr::error(translate('Something_went_wrong_please_check_your_mail_config'));
            return back();
        }
    }

    public function subscribedEmails(Request $request): View
    {
        $queryParams = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $subscribers = NewsLetter::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->where('email', 'like', "%{$value}%");
                }
            });
            $queryParams = ['search' => $request['search']];
        } else {
            $subscribers = new NewsLetter();
        }
        $subscribers = $subscribers->latest()->paginate(Helpers::pagination_limit())->appends($queryParams);
        return view('admin-views.contact-message.email-subscriber', compact('subscribers', 'search'));
    }

    public function subscribedEmailsExport(Request $request): StreamedResponse
    {
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $subscribers = NewsLetter::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('email', 'like', "%{$value}%");
                }
            });
        } else {
            $subscribers =  new NewsLetter();
        }
        $subscribers = $subscribers->latest()->get();

        $data = [];
        foreach ($subscribers as $key => $subscriber) {
            $data[] = [
                'SL' => ++$key,
                'Email' => $subscriber->email,
                'Subscribe At' => date('d M Y h:m A', strtotime($subscriber['created_at'])),
            ];
        }

        return (new FastExcel($data))->download('subscribe-email.xlsx');
    }
}
