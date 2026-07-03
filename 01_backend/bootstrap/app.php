<?php

/**
 * AMIAL-REFACTOR-CORE-001 (v0.6) + AMIAL-ZONE-001 + AMIAL-LEGAL-001 (v0.7-A)
 *
 * Laravel 11 — تسجيل middleware و routes.
 *
 * التغييرات عن v0.6:
 *   + alias: 'amial.zone' => EnforceZonePolicy::class
 *   + alias: 'amial.terms' => RequireTermsAcceptance::class
 *   + alias: 'amial.block-in-production' => BlockInProduction::class
 *   + withRouting: تسجيل routes/api/amial.php
 *   + withExceptions: تحويل تلقائي للـ structured exceptions إلى JSON
 */

use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\{AdminMiddleware,
    AgentMiddleware,
    Authenticate,
    BlockInProduction,
    CheckDeviceId,
    CustomerMiddleware,
    DeviceVerifyMiddleware,
    EncryptCookies,
    EnforceIdempotency,
    EnforceZonePolicy,
    InactiveAuthCheck,
    EnforceUsageLimit,
    MerchantMiddleware,
    PerUserRateLimit,
    RedirectIfAuthenticated,
    RequirePermission,
    RequireTermsAcceptance,
    SecuritySentinel,
    TrackLastActiveAt,
    VerifyCsrfToken};
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // ملاحظة الدمج: راوتات 6cash الأساسية (web/api/v1/admin/merchant) تُحمّل عبر
        // App\Providers\RouteServiceProvider. هنا نسجّل راوتات أميال فقط + الأوامر.
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            \Illuminate\Support\Facades\Route::prefix('api/v1/amial')
                ->middleware('api')
                ->group(base_path('routes/api/amial.php'));

            // AMIAL-ADMIN-001 (v0.8): Admin Blade views
            \Illuminate\Support\Facades\Route::middleware(['web', 'admin'])
                ->prefix('admin/amial')
                ->name('admin.amial.')
                ->group(base_path('routes/admin/amial.php'));

            // AMIAL-HEALTH-001 (v1.0-C): public health checks
            \Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/api/health.php'));

            // AMIAL-UNIFIED-AUTH-001 (v1.5): unified login (public)
            \Illuminate\Support\Facades\Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api/unified-auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // AMIAL-DEVOPS-001 — حرج: بلا هذا، PerUserRateLimit و
        // SecuritySentinelService (يعتمدان على $request->ip() في عدّة
        // مواضع) سيريان كل الطلبات قادمة من IP واحد (عنوان الـ reverse
        // proxy: Nginx الداخلي، Docker، أو طبقة Cloudways) — فتنهار
        // حماية Rate Limiting وكشف الاحتيال بالـ IP بصمت تام.
        //
        // نثق بكل الوكلاء ('*') لأنّ التطبيق لا يُفتَح مباشرة للإنترنت
        // في أيّ بيئة نشر (Docker demo أو استضافة Cloudways) — المنفذ
        // الوحيد المكشوف هو عبر طبقة reverse proxy لا نتحكّم بعنوانها
        // الثابت. هذا نمط معتمد قياسياً للتطبيقات خلف PaaS/CDN غير
        // معروفة الـ IP سلفاً (نفس توصية Laravel الرسمية لبيئات كهذه).
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->use([
            TrustProxies::class,
            HandleCors::class,
            PreventRequestsDuringMaintenance::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);
        $middleware->group('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ]);
        $middleware->group('api', [
            'throttle:60,1',
            SubstituteBindings::class,
        ]);

        // AMIAL-SENTINEL-001 — الحارس المخفي يراقب كل طلب web/api (وضع monitor افتراضياً)
        $middleware->prependToGroup('web', SecuritySentinel::class);
        $middleware->prependToGroup('api', SecuritySentinel::class);

        // AMIAL-OPS-001 — وضع الصيانة المُدار من لوحة الأدمن (يمرّ الأدمن/الدخول/ping)
        $middleware->appendToGroup('api', \App\Http\Middleware\AmialMaintenanceMode::class);

        // AMIAL-SEC-HEADERS-001 — ترويسات أمان لكل استجابة (كشف غيابها اختبار الاختراق)
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'auth' => Authenticate::class,
            'auth.basic' => AuthenticateWithBasicAuth::class,
            'cache.headers' => SetCacheHeaders::class,
            'can' => Authorize::class,
            'guest' => RedirectIfAuthenticated::class,
            'password.confirm' => RequirePassword::class,
            'signed' => ValidateSignature::class,
            'throttle' => ThrottleRequests::class,
            'verified' => EnsureEmailIsVerified::class,

            'admin' => AdminMiddleware::class,
            'customerAuth' => CustomerMiddleware::class,
            'agentAuth' => AgentMiddleware::class,
            'trackLastActiveAt' => TrackLastActiveAt::class,
            'inactiveAuthCheck' => InactiveAuthCheck::class,
            'deviceVerify' => DeviceVerifyMiddleware::class,
            'checkDeviceId' => CheckDeviceId::class,
            'merchant' => MerchantMiddleware::class,

            // Amial Pay v0.6
            'amial.idempotency' => EnforceIdempotency::class,

            // Amial Pay v0.7-A
            'amial.zone' => EnforceZonePolicy::class,
            'amial.terms' => RequireTermsAcceptance::class,
            'amial.block-in-production' => BlockInProduction::class,

            // Amial Pay v1.0-A (RBAC)
            'rbac' => RequirePermission::class,

            // Amial Pay v1.0-D (Rate Limiting)
            'amial.rate-limit' => PerUserRateLimit::class,
            'amial.usage' => EnforceUsageLimit::class,
            'amial.pos-permission' => \App\Http\Middleware\PosPermission::class,
            'amial.agent' => \App\Http\Middleware\EnsureAgent::class,  // AMIAL-FIX-004

            // Amial Pay — الحارس المخفي (Application-level IDS)
            'amial.sentinel' => SecuritySentinel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\InsufficientBalanceException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return new \Illuminate\Http\JsonResponse($e->toApiArray(), 402);
            }
        });

        $exceptions->render(function (\App\Exceptions\DuplicateTransactionException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return new \Illuminate\Http\JsonResponse($e->toApiArray(), 409);
            }
        });

        $exceptions->render(function (\App\Exceptions\EnvironmentMutationBlockedException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return new \Illuminate\Http\JsonResponse([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => 'ENV_MUTATION_BLOCKED',
                    'errors' => (object)[],
                    'meta' => (object)[],
                ], 403);
            }
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        // CRITICAL-001-SUBS — فحص الاشتراكات يومياً 8 صباحاً
        $schedule->job(new \App\Jobs\CheckExpiringSubscriptionsJob)
            ->dailyAt('08:00')
            ->name('subs-check-expiring')
            ->withoutOverlapping()
            ->onOneServer();

        // P0-BACKUPS — نسخة احتياطية يومية 3 صباحاً
        $schedule->command('amial:backup')
            ->dailyAt('03:00')
            ->name('db-backup')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/backup.log'));
    })
    ->create();

return $app;
