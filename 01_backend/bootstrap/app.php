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

            // AMIAL-AGENT-PORTAL-001 — بوّابة شركات الصرافة وفروعها.
            //
            // منفصلةٌ عن `admin/*` عمداً: جمهورٌ آخر بمصادقةٍ أخرى. وخلطُها
            // بلوحة الإدارة يجعل خطأً واحداً في وسيطٍ يفتح المنصّة كلّها
            // لموظّف شبّاك.
            \Illuminate\Support\Facades\Route::middleware('web')
                ->prefix('agent')
                ->name('agent.')
                ->group(base_path('routes/agent.php'));

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
        // AMIAL-PORTAL-HOSTS-001 — قبل كلّ شيء: من طرق بابَ بوّابةٍ على
        // مضيفٍ ليس مضيفَها يُنقل إليه. ومطفأٌ ما لم يُضبط المتغيّران.
        $middleware->prepend(\App\Http\Middleware\PortalHostRedirect::class);

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
            // **بعد `TrustProxies` مباشرةً وقبل الجلسة.**
            // يقرأ `X-Forwarded-Proto` (فيلزم أن يكون الوسيط موثوقاً
            // قبله)، ويكتب `session.secure` قبل أن تُبنى كوكي الجلسة
            // في `StartSession` — والترتيب هنا ليس ذوقاً بل شرط عمل.
            \App\Http\Middleware\HttpsPosture::class,
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
        // AMIAL-419-LOOP-001 — صفحةٌ تحمل رمزَ CSRF لا تُخزَّن في المتصفّح.
        // (`no-cache` وحدَها تسمح باستعمال النسخة **حين يتعذّر التحقّق** —
        // وهو ما يقع على اتّصالٍ ضعيف، فيصل رمزٌ من جلسةٍ ماتت ⇒ ٤١٩.)
        $middleware->appendToGroup('web', \App\Http\Middleware\NoStoreCsrfPages::class);
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
            // AMIAL-OPERATOR-RBAC-001: «هل هو موظّف منصّة؟» يجيب عنه admin،
            // و«هل يحقّ له هذا الفعل؟» يجيب عنه platform. وخلطُ السؤالين هو
            // ما جعل موظّف الدعم قادراً على تصفير رمز عميل سرّي.
            'platform' => \App\Http\Middleware\PlatformPermissionMiddleware::class,

            // AMIAL-AGENT-PORTAL-001 — بوّابة شركات الصرافة وفروعها.
            // جمهورٌ آخر: يدخل ليخدم عملاء فرعه لا ليرى المنصّة.
            'agent.portal' => \App\Http\Middleware\AgentPortalMiddleware::class,
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
            // AMIAL-ZONE-BOUNDARY-001: موقع الوكيل لحظة العملية النقدية —
            // الحدّ الحقيقي بين العملتين هو الوكيل لا العميل.
            'amial.agent-location' => \App\Http\Middleware\EnforceAgentCashLocation::class,
            'amial.terms' => RequireTermsAcceptance::class,
            'amial.block-in-production' => BlockInProduction::class,

            // Amial Pay v1.0-A (RBAC)
            'rbac' => RequirePermission::class,

            // Amial Pay v1.0-D (Rate Limiting)
            'amial.rate-limit' => PerUserRateLimit::class,
            'amial.usage' => EnforceUsageLimit::class,
            'amial.pos-permission' => \App\Http\Middleware\PosPermission::class,
            // AMIAL-API-ACCESS-001 — مصادقة الشركاء بمفتاح API
            'amial.api-key' => \App\Http\Middleware\AuthenticateApiKey::class,
            'amial.agent' => \App\Http\Middleware\EnsureAgent::class,  // AMIAL-FIX-004

            // Amial Pay — الحارس المخفي (Application-level IDS)
            'amial.sentinel' => SecuritySentinel::class,

            // AMIAL-MAINT-001 — حارس الميزات (لوحة الصيانة الأولية)
            'feature' => \App\Http\Middleware\RequireFeatureEnabled::class,

            // AMIAL-ENTITLEMENTS-001 — **البوّابة الواحدة للباقات**.
            //
            // ولا تُخلط بـ`feature:` أعلاه: تلك مفتاحُ صيانةٍ للمنصّة كلّها
            // («الخدمة متوقّفة مؤقتاً»)، وهذه **إذنُ اشتراكٍ لتاجرٍ بعينه**.
            // واسمان متشابهان لمعنيين مختلفين أوقعا خلطاً من قبل.
            'capability' => \App\Http\Middleware\EnsureCapability::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ══════════════════════════════════════════════════════════════
        // AMIAL-CLOCK-GUARD-002 — صفحةُ ٤١٩ لا تُخزَّن.
        //
        // **العطل الذي أدخل هذا — وهو عطلٌ في الحارس نفسه.**
        //
        // صفحة ٤١٩ تقيس فارق الساعة، وتفعل ذلك بطبع وقت الخادم **داخل
        // HTML**: `data-server-ms="{{ now()->valueOf() }}"`. ثمّ يقارنه
        // جافاسكربت بساعة المتصفّح.
        //
        // وبلا ترويسةٍ تمنع التخزين، يحتفظ المتصفّح بالصفحة. فتُعرَض بعد
        // يومين ووقتُ الخادم فيها مجمّدٌ عند لحظة أوّل عرض — فتُعلن فارقاً
        // قدرُه يومان، **ويكبر يوماً كلّ يوم**.
        //
        // ووقع ذلك فعلاً: أعلنت الصفحة تأخّراً قدره ٤٤ ساعة، فأرسلتُ صاحب
        // المشروع يُصلح ساعة خادمه — وكانت مضبوطةً ومزامَنة:
        //
        //     System clock synchronized: yes
        //     NTP service: active
        //
        // **وحارسٌ يكذب أسوأ من غيابه**: لا يُطمئن فحسب، بل يُرسل من
        // يصدّقه خلف عطلٍ لا وجود له.
        // ══════════════════════════════════════════════════════════════
        // ويُستعمل `respond` لا `render`: Laravel يحوّل
        // `TokenMismatchException` إلى `HttpException` **قبل** مطابقة
        // معالجات `render`، فلا يُطابقها معالجٌ مكتوبٌ على الصنف الأصليّ —
        // يمرّ بلا خطأٍ ولا أثر. (كتبتُه أوّلاً كذلك، فقاسَ الردُّ
        // `no-cache, private` ولم يتغيّر.)
        //
        // و`no-cache` ليست `no-store`: الأولى تعني «تحقّق قبل الاستعمال»،
        // والمتصفّح يحتفظ بالنسخة ويستعملها حين يتعذّر التحقّق. والثانية
        // تمنع الاحتفاظ أصلاً — وهي المطلوبة لصفحةٍ قياسُها مطبوعٌ فيها.
        // ══════════════════════════════════════════════════════════════
        //  AMIAL-OBSERVABILITY-001 — كلُّ عطلٍ يُسجَّل حيث يُقرأ.
        //
        //  **الثمن:** ثلاثةُ أعطالٍ في يومٍ واحدٍ وصلت عبر صاحب المشروع لا
        //  عبر جهاز. ولم يكن في المنصّة تتبّعُ أخطاءٍ من جهة الخادم.
        //
        //  ويُستعمل `respond` لا `report`: الأوّلُ يعرف **رمزَ الردّ**،
        //  فيُميَّز العطلُ (٥٠٠) من السلوك الصحيح (٤٠٤ · ٤٢٢ · ٤٠٣). وبلا
        //  ذلك يُغرق الجدولُ برفضٍ سليمٍ فيُخفي ما يستحقّ النظر.
        // ══════════════════════════════════════════════════════════════
        $exceptions->respond(function ($response, \Throwable $e, $request) {
            app(\App\Services\ErrorTrackingService::class)
                ->record($e, $request, $response->getStatusCode());

            if ($response->getStatusCode() === 419) {
                $response->headers->set('Cache-Control',
                    'no-store, no-cache, must-revalidate, max-age=0');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Expires', '0');
            }

            return $response;
        });

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

        // AMIAL-FIX(VISIBILITY): كل خطأ على مسار API يُصيَّر JSON صريحاً — حتى لو
        // لم يرسل التطبيق ترويسة Accept. بدون هذا يُرجع Laravel صفحة HTML على
        // أخطاء 500 فيفشل jsonDecode ويُظهر التطبيق «Server Error» عامّة تُخفي
        // السبب. الآن يظهر السبب الحقيقي (وفي وضع debug: الصنف والملفّ/السطر).
        $exceptions->render(function (\Throwable $e, $request) {
            if (!($request->expectsJson() || $request->is('api/*'))) {
                return null; // غير API — دع Laravel يعرض صفحته المعتادة
            }
            // استثناءات تُصيّر نفسها أو لها معالج مخصّص أعلاه — لا نلمسها
            if (method_exists($e, 'render')) {
                return null;
            }
            // AMIAL-FIX(VISIBILITY-2): HttpResponseException يحمل استجابة
            // جاهزة — وهو الآلية التي يردّ بها الوسيط عبر abort(response(...)).
            // لا render() فيه ولا HttpExceptionInterface، فكان يسقط في فرع
            // 500 أدناه: يردّ الوسيط «رقم الجهاز غير مطابق» فيرى المستخدم
            // «حدث خطأ في الخادم». نُخرج استجابته كما هي.
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return $e->getResponse();
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return new \Illuminate\Http\JsonResponse([
                    'success' => false, 'code' => 'VALIDATION_FAILED',
                    'message' => 'بيانات غير صحيحة',
                    'errors' => $e->errors(), 'meta' => (object)[],
                ], 422);
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return new \Illuminate\Http\JsonResponse([
                    'success' => false, 'code' => 'UNAUTHENTICATED',
                    'message' => 'انتهت الجلسة، سجّل الدخول من جديد',
                    'errors' => (object)[], 'meta' => (object)[],
                ], 401);
            }
            $status = ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface)
                ? $e->getStatusCode() : 500;
            $payload = [
                'success' => false,
                'code' => $status >= 500 ? 'SERVER_ERROR' : 'REQUEST_ERROR',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'حدث خطأ في الخادم',
                'errors' => (object)[], 'meta' => (object)[],
            ];
            if (config('app.debug')) {
                $payload['debug'] = [
                    'exception' => get_class($e),
                    'at' => $e->getFile() . ':' . $e->getLine(),
                ];
            }
            return new \Illuminate\Http\JsonResponse($payload, $status);
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        // CRITICAL-001-SUBS — فحص الاشتراكات يومياً 8 صباحاً
        $schedule->job(new \App\Jobs\CheckExpiringSubscriptionsJob)
            ->dailyAt('08:00')
            ->name('subs-check-expiring')
            ->withoutOverlapping()
            ->onOneServer();

        // AMIAL-WA-AGENT-002 — تذكير الوكلاء قبل إغلاق نافذة التسوية.
        //
        // النافذة ٢٢:٠٠–٢٤:٠٠، والتذكير ٢٣:٠٠ — ساعةٌ تكفي لجمع أرقام
        // اليوم ورفعه، ولا تصل مبكّرةً فتُنسى قبل موعدها.
        $schedule->command('amial:agent-settlement-reminder')
            ->dailyAt('23:00')
            ->name('agent-settlement-reminder')
            ->withoutOverlapping()
            ->onOneServer();

        // P0-BACKUPS — نسخة احتياطية يومية 3 صباحاً
        $schedule->command('amial:backup')
            ->dailyAt('03:00')
            ->name('db-backup')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/backup.log'));

        // AMIAL-OBSERVABILITY-001 — نبضُ التوفّر كلَّ خمس دقائق.
        //
        // **بلا هذا لا يُعرف إلّا الحاضر**: تفتح اللوحةَ فتراه سليماً ولا
        // تعرف أنّه سقط ثلاث مرّاتٍ ليلاً. وخمسٌ حدٌّ وسط: أقلُّ منها
        // يُنمّي الجدول، وأكثرُ يُخفي انقطاعاً قصيراً.
        $schedule->command('amial:health-check')
            ->everyFiveMinutes()
            ->name('health-check')
            ->withoutOverlapping();
    })
    ->create();

return $app;
