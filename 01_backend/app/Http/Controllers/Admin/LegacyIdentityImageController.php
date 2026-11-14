<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-SEC-IDENTITY-PRIVATE-001 — صورُ الهويّة القديمة (العمود identification_image) كانت على القرص
 * العامّ: من عرف اسمَ الملفّ فتحه بلا دخول. صار nginx يحجب /storage/user/identity/، وهذا المسارُ
 * الوحيدُ الذي يخدمها: موظّفٌ مسجَّلٌ بصلاحيّة عرض العملاء، واسمُ ملفٍّ بلا مسارٍ ولا امتداداتٍ غريبة.
 */
class LegacyIdentityImageController extends Controller
{
    public function __invoke(string $file): StreamedResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,120}$/', $file) === 1 && ! str_contains($file, '..'), 404);

        $path = 'user/identity/'.$file;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, $file, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
