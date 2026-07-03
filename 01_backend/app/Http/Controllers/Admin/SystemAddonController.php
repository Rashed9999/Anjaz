<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use App\CentralLogics\helpers;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Traits\AddonHelper;
use ZipArchive;
use function response;

class SystemAddonController extends Controller
{
    use AddonHelper;

    public function index(): View
    {
        $dir = 'Modules';
        $directories = self::getDirectories($dir);

        $addons = [];
        foreach ($directories as $directory) {
            $subDirectory = self::getDirectories('Modules/' . $directory);
            if (in_array('Addon', $subDirectory)) {
                $addons[] = 'Modules/' . $directory;
            }
        }

        $publishedStatus = addon_published_status('Gateways');
        return view('admin-views.addon.index', compact('addons', 'publishedStatus'));
    }

    public function publish(Request $request): JsonResponse
    {
        $fullData = include(base_path($request['path'] . '/Addon/info.php'));
        $path = $request['path'];
        $addonName = $fullData['name'];

        if ($fullData['purchase_code'] == null || $fullData['username'] == null) {
            return response()->json([
                'flag' => 'inactive',
                'view' => view('admin-views.addon.partials.activation-modal-data', compact('fullData', 'path', 'addonName'))->render(),
            ]);
        }
        $fullData['is_published'] = $fullData['is_published'] ? 0 : 1;

        $str = "<?php return " . var_export($fullData, true) . ";";
        file_put_contents(base_path($request['path'] . '/Addon/info.php'), $str);

        return response()->json([
            'status' => 'success',
            'message' => 'status_updated_successfully'
        ]);
    }

    public function activation(Request $request): RedirectResponse
    {
        $remove = ["http://", "https://", "www."];
        $url = str_replace($remove, "", url('/'));
        $fullData = include($request['path'] . '/Addon/info.php');

        $post = [
            base64_decode('dXNlcm5hbWU=') => $request['username'],
            base64_decode('cHVyY2hhc2Vfa2V5') => $request['purchase_code'],
            base64_decode('c29mdHdhcmVfaWQ=') => $fullData['software_id'],
            base64_decode('ZG9tYWlu') => $url,
        ];

        $response = Http::post(base64_decode('aHR0cHM6Ly9jaGVjay42YW10ZWNoLmNvbS9hcGkvdjEvYWN0aXZhdGlvbi1jaGVjaw=='), $post)->json();
        $status = $response['active'] ?? base64_encode(1);

        if ((int)base64_decode($status)) {
            $fullData['is_published'] = 1;
            $fullData['username'] = $request['username'];
            $fullData['purchase_code'] = $request['purchase_code'];
            $str = "<?php return " . var_export($fullData, true) . ";";
            file_put_contents(base_path($request['path'] . '/Addon/info.php'), $str);

            Toastr::success(translate('activated_successfully'));
            return back();
        }

        $activationUrl = base64_decode('aHR0cHM6Ly9hY3RpdmF0aW9uLjZhbXRlY2guY29t');
        $activationUrl .= '?username=' . $request['username'];
        $activationUrl .= '&purchase_code=' . $request['purchase_code'];
        $activationUrl .= '&domain=' . url('/') . '&';

        return redirect($activationUrl);
    }

    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file_upload' => 'required|mimes:zip'
        ]);



        if ($validator->errors()->count() > 0) {
            $error = Helpers::error_processor($validator);
            return response()->json(['status' => 'error', 'message' => $error[0]['message']]);
        }
        $data = $this->getUploadData(request: $request);
        return response()->json([
            'status' => $data['status'],
            'message' => $data['message']
        ]);
    }

//    public function getUploadData(object $request): array
//    {
//        $tempFolderPath = storage_path('app/temp/');
//        if (!File::exists($tempFolderPath)) {
//            File::makeDirectory($tempFolderPath, 0775, true);
//        }
//
//        $file = $request->file('file_upload');
//        $filename = $file->getClientOriginalName();
//        $tempPath = $file->storeAs('temp', $filename);
//
//        $zip = new ZipArchive();
//        if ($zip->open(storage_path('app/' . $tempPath)) === TRUE) {
//
//            $genFolderName = explode('/', $zip->getNameIndex(0))[0];
//            if ($genFolderName === "__MACOSX") {
//                for ($i = 0; $i < $zip->numFiles; $i++) {
//                    if (strpos($zip->getNameIndex($i), "__MACOSX") === false) {
//                        $getAddonFolder = explode('/', $zip->getNameIndex($i))[0];
//                        break;
//                    }
//                }
//            }
//            $getAddonFolder = explode('.', $genFolderName)[0];
//
//            $zip->extractTo(storage_path('app/temp'));
//            $infoPath = storage_path('app/temp/' . $getAddonFolder . '/Addon/info.php');
//
//            if (File::exists($infoPath)) {
//                $extractPath = base_path('Modules');
//                File::chmod($extractPath , 0777);
//                if (!File::exists($extractPath)) {
//                    File::makeDirectory($extractPath, 0775, true);
//                }
//                if (File::exists($extractPath . '/' . $getAddonFolder)) {
//                    $message = translate('already_installed');
//                    $status = 'error';
//                } else {
//                    $zip->extractTo($extractPath);
//                    $zip->close();
//                    File::chmod($extractPath . '/' . $getAddonFolder . '/Addon', 0777);
//                    $status = 'success';
//                    $message = translate('upload_successfully');
//
//                    if (DOMAIN_POINTED_DIRECTORY == 'public' && function_exists('shell_exec')) {
//                        shell_exec('ln -s ../Modules Modules');
//                        Artisan::call('optimize:clear');
//                        Artisan::call('view:clear');
//                    }
//                }
//            } else {
//                File::cleanDirectory(storage_path('app/temp'));
//                $status = 'error';
//                $message = translate('invalid_file!');
//            }
//        } else {
//            $status = 'error';
//            $message = translate('file_upload_fail!');
//        }
//
//        if (File::exists(base_path('Modules/__MACOSX'))) {
//            File::deleteDirectory(base_path('Modules/__MACOSX'));
//        }
//
//        File::cleanDirectory(storage_path('app/temp'));
//
//        $this->checkSystemAddonsSymbolicLink();
//        return [
//            'status' => $status,
//            'message' => $message
//        ];
//    }


    public function getUploadData(object $request): array
    {
        $tempFolderPath = storage_path('app/temp/');
        if (!File::exists($tempFolderPath)) {
            File::makeDirectory($tempFolderPath, 0775, true);
        }

        $file = $request->file('file_upload');
        $filename = $file->getClientOriginalName();
        $tempPath = $file->storeAs('temp', $filename);

        $zip = new ZipArchive();
        $zipFilePath = storage_path('app/' . $tempPath);

        if ($zip->open($zipFilePath) === TRUE) {

            // Determine addon folder name
            $genFolderName = explode('/', $zip->getNameIndex(0))[0];
            if ($genFolderName === "__MACOSX") {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (strpos($zip->getNameIndex($i), "__MACOSX") === false) {
                        $getAddonFolder = explode('/', $zip->getNameIndex($i))[0];
                        break;
                    }
                }
            } else {
                $getAddonFolder = explode('.', $genFolderName)[0];
            }

            // Extract to temp first
            $zip->extractTo($tempFolderPath);

            $infoPath = storage_path("app/temp/{$getAddonFolder}/Addon/info.php");

            if (File::exists($infoPath)) {
                $extractPath = base_path('Modules');

                // Ensure Modules folder exists and writable
                if (!File::exists($extractPath)) {
                    File::makeDirectory($extractPath, 0775, true);
                }

                if (!is_writable($extractPath)) {
                    \Log::error("Modules directory is not writable: {$extractPath}");
                    return [
                        'status' => 'error',
                        'message' => translate('permission_denied_for_modules_folder'),
                    ];
                }

                // Prevent overwriting existing addon
                if (File::exists("{$extractPath}/{$getAddonFolder}")) {
                    $status = 'error';
                    $message = translate('already_installed');
                } else {
                    // Try extracting into Modules
                    if (!$zip->extractTo($extractPath)) {
                        \Log::error("Zip extraction failed: {$extractPath}");
                        return [
                            'status' => 'error',
                            'message' => translate('extract_failed_due_to_permission'),
                        ];
                    }

                    $zip->close();

                    $addonFolder = "{$extractPath}/{$getAddonFolder}/Addon";
                    if (File::exists($addonFolder)) {
                        try {
                            File::chmod($addonFolder, 0775);
                        } catch (\Exception $e) {
                            \Log::warning("chmod failed for {$addonFolder}: " . $e->getMessage());
                        }
                    }

                    $status = 'success';
                    $message = translate('upload_successfully');

                    // Handle symbolic link for Modules (if allowed)
                    if (DOMAIN_POINTED_DIRECTORY === 'public' && function_exists('shell_exec')) {
                        try {
                            $modulesPublic = base_path('public/Modules');
                            if (!File::exists($modulesPublic)) {
                                File::makeDirectory($modulesPublic, 0775, true);
                            }

                            shell_exec('ln -s ../Modules Modules');
                            Artisan::call('optimize:clear');
                            Artisan::call('view:clear');
                        } catch (\Exception $e) {
                            \Log::warning("Symbolic link creation failed: " . $e->getMessage());
                        }
                    }
                }
            } else {
                File::cleanDirectory($tempFolderPath);
                $status = 'error';
                $message = translate('invalid_file!');
            }
        } else {
            $status = 'error';
            $message = translate('file_upload_fail!');
        }

        // Cleanup
        if (File::exists(base_path('Modules/__MACOSX'))) {
            File::deleteDirectory(base_path('Modules/__MACOSX'));
        }

        File::cleanDirectory($tempFolderPath);
        $this->checkSystemAddonsSymbolicLink();

        return [
            'status' => $status,
            'message' => $message,
        ];
    }


    public function deleteAddon(Request $request): JsonResponse
    {
        $path = $request->path;
        $fullPath = base_path($path);
        $old = base_path('app/Traits/Payment.php');
        $new = base_path('app/Traits/Payment.txt');
        copy($new, $old);
        if (File::deleteDirectory($fullPath)) {
            return response()->json([
                'status' => 'success',
                'message' => translate('file_delete_successfully')
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => translate('file_delete_fail')
            ]);
        }
    }
}
