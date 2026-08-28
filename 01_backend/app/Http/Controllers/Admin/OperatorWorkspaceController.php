<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/** صفحة عمل موحّدة؛ التبويب ينظّم الواجهات ولا يمنح صلاحية بنفسه. */
class OperatorWorkspaceController extends Controller
{
    public function index(): View
    {
        return view('admin-views.amial.ops.workspace');
    }
}
