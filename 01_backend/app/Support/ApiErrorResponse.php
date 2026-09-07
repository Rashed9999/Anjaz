<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * AMIAL-API-ERROR-SHIELD-001 — حاجزٌ لا يسمح لداخل الخادم بالخروج.
 *
 * رسالة الاستثناء قد تحمل SQL أو أسماء أعمدة أو مضيف قاعدة البيانات. هذه
 * التفاصيل تُسجَّل داخل الخادم عبر ErrorTrackingService، أمّا العميل فلا
 * يتسلّم إلا رمزاً آمناً ورقم الطلب الذي يستطيع الدعم البحث به.
 */
final class ApiErrorResponse
{
    /**
     * يحوّل أي استثناء غير مُعالَج إلى عقد API آمن ومتوافق مع الغلاف القائم.
     */
    public static function from(Throwable $exception, Request $request): JsonResponse
    {
        [$status, $code, $message] = self::detailsFor($exception);

        $requestId = (string) $request->attributes->get(
            'request_id',
            $request->header('X-Request-Id', ''),
        );

        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => $requestId !== '' ? ['request_id' => $requestId] : (object) [],
        ], $status);
    }

    /**
     * لا تُستعمل رسالة الاستثناء في أي فرع: ما يصل العميلَ قرارٌ آمن فقط.
     *
     * @return array{0:int,1:string,2:string}
     */
    private static function detailsFor(Throwable $exception): array
    {
        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            return [
                503,
                'SERVER_UNAVAILABLE',
                'تعذّر تنفيذ الطلب بسبب مشكلة مؤقتة في الخادم. أعد المحاولة، وإذا استمرت المشكلة تواصل مع الدعم برمز الطلب.',
            ];
        }

        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 500;

        return match ($status) {
            400 => [400, 'REQUEST_ERROR', 'تعذّر تنفيذ الطلب. تحقّق من البيانات ثم أعد المحاولة.'],
            403 => [403, 'FORBIDDEN', 'لا تملك الصلاحية اللازمة لتنفيذ هذا الطلب.'],
            404 => [404, 'NOT_FOUND', 'العنصر المطلوب غير موجود أو لم يعد متاحاً.'],
            405 => [405, 'METHOD_NOT_ALLOWED', 'طريقة الطلب غير مدعومة.'],
            419 => [419, 'SESSION_EXPIRED', 'انتهت الجلسة أو صلاحية الطلب. أعد المحاولة.'],
            429 => [429, 'RATE_LIMITED', 'تجاوزت عدد المحاولات المسموح بها. انتظر قليلاً ثم أعد المحاولة.'],
            503 => [503, 'SERVER_UNAVAILABLE', 'الخدمة غير متاحة مؤقتاً. أعد المحاولة لاحقاً.'],
            default => [500, 'SERVER_ERROR', 'حدثت مشكلة في الخادم. أعد المحاولة، وإذا استمرت المشكلة تواصل مع الدعم برمز الطلب.'],
        };
    }
}
