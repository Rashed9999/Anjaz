<?php

namespace App\Traits;

use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait UploadSizeHelperTrait
{
    protected $maxImageSizeBytes;
    protected $maxImageSizeKB;
    protected $maxImageSizeReadable;

    public function initUploadLimits(string $fileType = 'image'): void
    {
        $this->maxImageSizeBytes = uploadMaxFileSize($fileType);
        $this->maxImageSizeKB = $this->maxImageSizeBytes / 1024;
        $this->maxImageSizeReadable = convertToReadableSize($this->maxImageSizeBytes);
    }

    /**
     * @param Request $request
     * @param array $fieldNames
     * @param string $fileType
     * @return JsonResponse|RedirectResponse|true
     */
    protected function validateUploadedFile(Request $request, array $fieldNames, string $fileType = 'image'): true|JsonResponse|RedirectResponse
    {
        $this->initUploadLimits($fileType);
        foreach ($fieldNames as $fieldName) {

            if (!isset($_FILES[$fieldName])) {
                continue;
            }

            $files = $request->file($fieldName);
            if (!is_array($files)) {
                $files = [$files];
            }
            foreach ($files as $file) {
                if (!$file) {
                    continue;
                }
                if ($file->getError() === UPLOAD_ERR_INI_SIZE) {
                    $message = translate($fieldName . ' size must be less than ' . $this->maxImageSizeReadable);
                    return $this->uploadErrorResponse($request, $message, $fieldName);
                }
            }
        }

        return true;
    }

    /**
     * @param Request $request
     * @param string $message
     * @param $fieldName
     * @return JsonResponse|RedirectResponse
     */
    private function uploadErrorResponse(Request $request, string $message, $fieldName): JsonResponse|RedirectResponse
    {
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json([
                'errors' => [['code' => $fieldName, 'message' => $message]]
            ]);
        }

        Toastr::error($message);
        return back();
    }

}
