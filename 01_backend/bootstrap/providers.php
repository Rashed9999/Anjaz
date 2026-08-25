<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Service Providers
    |--------------------------------------------------------------------------
    */

    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    // App\Providers\BroadcastServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    App\Providers\ConfigServiceProvider::class,

    // AMIAL-WHOLESALE-ACCESS-001 — policy واحدة تحرس كل endpoint للجملة
    // وتزوّد Flutter بنفس snapshot الباقات/الصلاحيات.
    App\Providers\WholesaleAccessServiceProvider::class,

    /*
    |--------------------------------------------------------------------------
    | Third-Party Service Providers
    |--------------------------------------------------------------------------
    */

    Laravel\Passport\PassportServiceProvider::class,

    // AMIAL-AUDIT-ORPHAN-002 — أُوقف مزوّد laravelpkg/laravelchk (فحص ترخيص
    // قالب 6cash). كان يسجّل مسارين على خادم أميال الإنتاجي:
    //
    //   ANY  /dmvf              ← يقبل كل الطرائق، بلا أي وسيط مصادقة
    //   GET  /activation-check
    //
    // و /dmvf يرسل نطاق الخادم ومفتاح الشراء إلى
    // https://check.6amtech.com/api/v1/domain-check — وكل سلسلة في الشيفرة
    // مُرمَّزة بـ base64 (اسم النطاق، أسماء الحقول، اسم متغيّر البيئة)، وهو
    // ترميز لا يضيف أماناً بل يُخفي السلوك عن أي بحث في الشيفرة. لم يظهر في
    // فحصي الأول لعناوين URL الخارجية لهذا السبب بالضبط.
    //
    // نقطة نهاية عامّة غير مصادَقة تُخرج بيانات من خادم مالي مرخَّص لا يجوز
    // بقاؤها. والمسارات معطّلة أصلاً (قوالبها محذوفة) — أي أنها كانت سطح
    // هجوم بلا وظيفة.
    //
    // الحزمة تبقى في vendor حتى لا يتعطّل التركيب؛ إيقاف المزوّد يمنع تسجيل
    // مساراتها. ولإزالتها نهائياً: composer remove laravelpkg/laravelchk
    // Laravelpkg\Laravelchk\LaravelchkServiceProvider::class,

    Stevebauman\Location\LocationServiceProvider::class,
];
