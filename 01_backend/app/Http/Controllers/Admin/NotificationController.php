<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private Notification $notification
    ){}

    function index(Request $request): View
    {
        $queryParam = [];
        $search = $request['search'];
        if ($request->has('search')) {
            $key = explode(' ', $request['search']);
            $notifications = $this->notification->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('title', 'like', "%{$value}%")
                        ->orWhere('description', 'like', "%{$value}%");
                }
            });
            $queryParam = ['search' => $request['search']];
        } else {
            $notifications = $this->notification;
        }

        $notifications = $notifications->latest()->paginate(Helpers::pagination_limit())->appends($queryParam);
        return view('admin-views.notification.index', compact('notifications', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'title' => 'required',
            'receiver' => 'required',
            'description' => 'required',
            'image' => 'required|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ], [
            'title.required' => 'title is required!',
        ]);

        $notification = $this->notification;
        $notification->title = $request->title;
        $notification->receiver = $request->receiver;
        $notification->description = $request->description;
        $notification->image = $request->has('image') ? Helpers::upload('notification/', APPLICATION_IMAGE_FORMAT, $request->file('image')) : null;
        $notification->status = 1;
        $notification->save();

        $data = [];
        $data['title'] = $request->title;
        $data['description'] = $request->description;
        $data['image'] = '';
        $data['receiver'] = strtolower($request->receiver);
        $data['type'] = 'general';

        try {
            if ($request->receiver == 'all' || $request->receiver == 'customers' || $request->receiver == 'agents') {
                Helpers::send_push_notif_to_topic($data);

            } else {
                throw new \Exception();
            }

        } catch (\Exception $e) {
            Toastr::warning('Push notification failed!');
        }

        Toastr::success('Notification sent successfully!');
        return back();
    }

    public function edit(int $id): View
    {
        $notification = $this->notification->find($id);
        return view('admin-views.notification.edit', compact('notification'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate([
            'title' => 'required',
            'description' => 'required',
            'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
        ], [
            'title.required' => 'title is required!',
        ]);

        $oldNotification = $this->notification->find($id);
        $notification = $this->notification;
        $notification->title = $request->title;
        $notification->description = $request->description;
        $notification->receiver = $request->has('receiver') ? $request->receiver : $oldNotification->receiver;
        $notification->image = $request->has('image') ? Helpers::update('notification/', $oldNotification->image, APPLICATION_IMAGE_FORMAT, $request->file('image')) : $oldNotification->image;
        $notification->save();

        $data = [];
        $data['title'] = $request->has('title') ? $request->title : $oldNotification->title;
        $data['description'] = $request->has('description') ? $request->description : $oldNotification->description;
        $data['image'] = '';
        $data['receiver'] = strtolower($request->has('receiver') ? $request->receiver : $oldNotification->receiver);
        $data['type'] = 'general';

        try {
            if ($request->receiver == 'all' || $request->receiver == 'customers' || $request->receiver == 'agents') {
                Helpers::send_push_notif_to_topic($data);
                Toastr::success('Notification resend successfully!');

            } else {
                throw new \Exception();
            }

        } catch (\Exception $e) {
            Toastr::warning('Push notification failed!');
        }

        return redirect()->route('admin.notification.add-new');
    }

    public function status(Request $request): RedirectResponse
    {
        $notification = $this->notification->find($request->id);
        $notification->status = $request->status;
        $notification->save();
        Toastr::success('Notification status updated!');
        return back();
    }

    public function delete(Request $request): RedirectResponse
    {
        $notification = $this->notification->find($request->id);
        Helpers::delete('notification/' . $notification['image']);
        $notification->delete();
        Toastr::success('Notification removed!');
        return back();
    }
}
