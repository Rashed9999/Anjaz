@extends('site.layout')

@section('title', 'المحفظة الإلكترونيّة ونقاط البيع')
@section('desc', 'أميال باي: حوّل وادفع واستلم في اليمن — للأفراد والتجّار وشركات الصرافة وفروعها.')

@section('body')

<section class="hero">
    <div class="wrap">
        <div class="hero-grid">
            <div class="hero-copy">
                <span class="eyebrow"><span></span> منصة مالية يمنية متكاملة</span>
                <h1>تحكّم بالمال.<br><em>وتحرّك بثقة.</em></h1>
                <p class="lede">
                    أميال باي تجمع المحفظة والدفع ونقاط البيع وشبكة الصرافة في تجربةٍ واحدة
                    واضحة للأفراد والتجّار وشركات الصرافة.
                </p>
                <div class="cta">
                    <a class="btn btn-gold btn-lg" href="{{ route('site.personal') }}">اكتشف خدمات الأفراد</a>
                    <a class="btn btn-hero-secondary btn-lg" href="{{ route('site.business') }}">حلول الأعمال</a>
                </div>
                <div class="hero-trust" aria-label="مرتكزات المنصّة">
                    <span>دفتر قيود متوازن</span>
                    <span>إيصالات قابلة للتحقّق</span>
                    <span>صلاحيات تشغيلية واضحة</span>
                </div>
            </div>

            <div class="product-frame" aria-label="لمحة عن تجربة أميال باي">
                <div class="product-topbar">
                    <span class="product-brand"><i></i> أميال باي</span>
                    <span class="product-status">متصل الآن</span>
                </div>
                <div class="product-balance">
                    <span>رصيد المحفظة</span>
                    <strong>١٢٥,٠٠٠ <small>ر.ي</small></strong>
                    <span class="balance-note">آخر تحديث منذ لحظات</span>
                </div>
                <div class="product-actions">
                    <span><b>↗</b> إرسال</span>
                    <span><b>⌁</b> دفع QR</span>
                    <span><b>↓</b> سحب نقدي</span>
                </div>
                <div class="product-activity">
                    <div class="activity-head"><strong>نشاط حديث</strong><span>عرض الكل</span></div>
                    <div class="activity-row">
                        <i class="activity-icon transfer">↙</i>
                        <span><b>تحويل مستلم</b><small>اليوم، ١٠:٤٥ ص</small></span>
                        <strong class="positive">+ ٢٠,٠٠٠</strong>
                    </div>
                    <div class="activity-row">
                        <i class="activity-icon payment">✓</i>
                        <span><b>دفع إلى تاجر</b><small>أمس، ٠٦:٢٠ م</small></span>
                        <strong>− ٧,٥٠٠</strong>
                    </div>
                </div>
                <div class="product-safe"><span>✓</span> كل حركة موثّقة وقابلة للمراجعة</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="section-head">
            <span class="section-kicker">خدمات مصممة حولك</span>
            <h2>منصة واحدة، تجربة تناسب عملك</h2>
            <p>لكل فئة بوابتها وأدواتها ومسارها الواضح—من دون تعقيدٍ أو شاشات لا تحتاجها.</p>
        </div>

        <div class="audience-grid">
            <a class="audience-card personal" href="{{ route('site.personal') }}">
                <div class="audience-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c.6-4 3.2-6 7.5-6s6.9 2 7.5 6"/></svg></div>
                <span class="audience-label">المحفظة الرقمية</span>
                <h3>للأفراد</h3>
                <p>حوّل وادفع واستلم، وتابع كل حركة مالية من هاتفك.</p>
                <span class="go">استكشف المحفظة <b>←</b></span>
            </a>

            <a class="audience-card business" href="{{ route('site.business') }}">
                <div class="audience-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10h16v10H4z"/><path d="M6 10V6h12v4M8 14h3m2 0h3"/></svg></div>
                <span class="audience-label">نقطة البيع الذكية</span>
                <h3>للتجّار</h3>
                <p>اقبل المدفوعات وأدر مبيعاتك وموظفيك وتقاريرك من مكان واحد.</p>
                <span class="go">اكتشف حلول الأعمال <b>←</b></span>
            </a>

            <a class="audience-card exchange" href="{{ route('site.exchange') }}">
                <div class="audience-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V9l7-5 7 5v11M9 20v-5h6v5M7.5 10h.01M16.5 10h.01"/></svg></div>
                <span class="audience-label">شبكة تشغيل موحّدة</span>
                <h3>لشركات الصرافة</h3>
                <p>أدر الفروع والخزائن والورديات والتسويات عبر بوابة واحدة.</p>
                <span class="go">استكشف بوابة الشركات <b>←</b></span>
            </a>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <div class="section-head">
            <span class="section-kicker">ثقة في كل خطوة</span>
            <h2>بنية مالية، وليست مجرد واجهة دفع</h2>
            <p>تعمل المنصة على تنظيم الحركة المالية والتشغيلية من لحظة الطلب حتى الإيصال والمراجعة.</p>
        </div>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="m13 2-9 12h7l-1 8 10-13h-7z"/></svg></div>
                <h3>تجربة دفع سلسة</h3>
                <p>تحويلات ومدفوعات مصممة لتكون مباشرة وواضحة في كل خطوة.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M7 3h8l3 3v15H7z"/><path d="M15 3v4h4M10 13l1.5 1.5L15 11"/></svg></div>
                <h3>إيصالات يمكن التحقق منها</h3>
                <p>كل معاملة تُترك لها بصمة واضحة تسهّل المراجعة والمتابعة.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M4 8h16v12H4z"/><path d="M7 8V5h10v3M8 14h.01M12 14h4"/></svg></div>
                <h3>تشغيل نقدي ورقمي معًا</h3>
                <p>خدمات المحفظة ترتبط بعمليات الفروع والنقاط التشغيلية بوضوح.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 3 8.2 7 10 4-1.8 7-5.4 7-10V6z"/><path d="m9 12 2 2 4-4"/></svg></div>
                <h3>ضوابط مدمجة</h3>
                <p>صلاحيات وحدود وسجلّات مراجعة ترافق العمل التشغيلي والمالي.</p>
            </div>
        </div>
    </div>
</section>

<section class="section system-section">
    <div class="wrap">
        <div class="system-grid">
            <div class="system-copy">
                <span class="section-kicker">تشغيل يمكن رؤيته</span>
                <h2>النقد والرقمي في لوحة تشغيل واحدة</h2>
                <p>أميال باي لا تفترض أن كل المال رقمي. عمليات الفروع والخزائن والورديات والتسويات تُدار كجزء من الصورة المالية نفسها.</p>
                <ul class="ticks">
                    <li>خزنة نقد لكل فرع وعهدة لكل شباك</li>
                    <li>ورديات تبدأ وتنتهي بجرد فعلي</li>
                    <li>تسويات قابلة للمتابعة بين الشركة وفروعها</li>
                </ul>
            </div>

            <div class="operation-panel">
                <div class="operation-title"><span>نظرة تشغيلية</span><i>مباشر</i></div>
                <div class="operation-flow">
                    <span class="flow-node active">المحفظة</span><b></b><span class="flow-node">نقطة البيع</span><b></b><span class="flow-node">الفرع</span>
                </div>
                <div class="operation-line"><span>حركة مالية</span><strong>موثّقة</strong><i></i></div>
                <div class="operation-line"><span>إيصال العملية</span><strong>متاح للتحقق</strong><i></i></div>
                <div class="operation-line"><span>سجل المراجعة</span><strong>مرتبط بالحدث</strong><i></i></div>
            </div>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <div class="cta-band">
            <span>أميال باي للأفراد والأعمال</span>
            <h2>اجعل حركتك المالية أوضح</h2>
            <p>ابدأ بالخدمة التي تناسبك، أو تحدث إلى فريق أميال باي عن احتياج مؤسستك.</p>
            <div class="cta">
                <a class="btn btn-gold btn-lg" href="{{ route('site.personal') }}">استكشف أميال باي</a>
                <a class="btn btn-hero-secondary btn-lg" href="{{ route('site.contact') }}">تواصل معنا</a>
            </div>
        </div>
    </div>
</section>

@endsection
