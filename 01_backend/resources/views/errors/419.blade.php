{{-- AMIAL-419 — «انتهت صلاحية الصفحة» بلغةٍ يفهمها من يقرأها.

     الصفحة الافتراضيّة بيضاء وفيها سطرٌ إنجليزيّ واحد: «419 PAGE EXPIRED».
     ولا تقول ما جرى ولا ما يُفعَل، فيقف صاحبُ شركة صرافةٍ أمام شاشةٍ لا
     تُخبره بشيء ويظنّ النظام معطَّلاً.

     و٤١٩ ليست خطأً واحداً بل ثلاثة تلبس ثوباً واحداً:
       • صفحةٌ بقيت مفتوحةً حتى انتهت جلستُها     ← يُعاد تحميلها
       • كوكيز معطَّلة في المتصفّح                 ← تُفعَّل
       • **ساعةُ الخادم خاطئة**                    ← لا حيلة للمستعمل فيها

     والثالث هو الذي عطّل الدخول فعلاً: كوكي انتهاؤها في الماضي يرميها
     المتصفّح فوراً، فلا جلسةَ في الطلب التالي. ولذلك تُقاس الساعة هنا
     وتُقال صراحةً بدل أن يبقى العطل مجهولاً. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>انتهت صلاحية الصفحة — أميال باي</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
               background: #f5f7fa; color: #1f2937; margin: 0; padding: 24px;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .box { background: #fff; border-radius: 14px; padding: 28px; max-width: 560px; width: 100%;
               box-shadow: 0 6px 24px rgba(0,0,0,.08); }
        h1 { font-size: 20px; margin: 0 0 6px; }
        .sub { color: #6b7280; font-size: 14px; margin-bottom: 20px; }
        .step { background: #f9fafb; border-right: 4px solid #2563eb; padding: 10px 14px;
                border-radius: 8px; margin-bottom: 10px; font-size: 14px; line-height: 1.9; }
        .btn { display: inline-block; background: #2563eb; color: #fff; text-decoration: none;
               padding: 11px 22px; border-radius: 9px; font-size: 15px; border: 0; cursor: pointer; }
        .warn { display: none; background: #fef3c7; border-right: 4px solid #d97706;
                padding: 12px 14px; border-radius: 8px; margin: 16px 0; font-size: 14px; line-height: 1.9; }
        code { background: #eef2ff; padding: 1px 6px; border-radius: 5px; direction: ltr;
               display: inline-block; font-size: 13px; }
    </style>
</head>
<body>
<div class="box">
    <h1>⏳ انتهت صلاحية هذه الصفحة</h1>
    <div class="sub">لم يُنفَّذ شيء، ولم يُمسّ أيّ مبلغ. الصفحة وحدها هي التي شاخت.</div>

    {{-- **الساعة تُقاس في المتصفّح لا في الخادم.**
         الخادم لا يعرف أنّ ساعته خطأ — يقارن وقته بوقته فيصحّ دائماً.
         والمتصفّح وحده يحمل الوقت الصحيح، فمنه يُكشف الفرق. --}}
    <div class="warn" id="clock-warn" data-server-ms="{{ now()->valueOf() }}">
        ⚠️ <strong>ساعة الخادم خاطئة — وهذا سببُ العطل.</strong>
        <div id="clock-detail" style="margin-top:6px"></div>
        <div style="margin-top:6px">
            إعادةُ التحميل لن تكفي. أبلِغ إدارة النظام بضبط ساعة الخادم
            (<code>timedatectl set-ntp true</code> على الخادم نفسه)، أو أعِد تشغيل الخادم.
        </div>
    </div>

    <div class="step">١ · اضغط <strong>«إعادة المحاولة»</strong> — يحلّ ذلك أغلب الحالات.</div>
    <div class="step">٢ · إن تكرّر، تأكّد أنّ <strong>الكوكيز مفعَّلة</strong> ولستَ في تصفّحٍ خاصّ.</div>
    <div class="step">٣ · إن تكرّر بعدهما، فالعطل عند الخادم لا عندك — أبلِغ الإدارة.</div>

    <div style="margin-top:20px">
        <button class="btn" onclick="location.reload()">إعادة المحاولة</button>
    </div>
</div>

<script>
    // وقتُ الخادم من نفس الردّ الذي حمل هذه الصفحة، ووقتُ المستعمل من جهازه.
    // وفرقٌ يتجاوز عشر دقائق ليس تأخّرَ شبكة — هو ساعةٌ خاطئة.
    (function () {
        var box = document.getElementById('clock-warn');
        var serverMs = Number(box.dataset.serverMs);

        // رقمٌ غائبٌ ليس فرقاً هائلاً — وإنذارٌ كاذب يُعلّم تجاهل الإنذارات.
        if (!isFinite(serverMs) || serverMs <= 0) return;

        var server = new Date(serverMs);
        var here = new Date();
        var driftMin = Math.abs(here.getTime() - server.getTime()) / 60000;

        if (driftMin > 10) {
            var fmt = function (d) { return d.toISOString().replace('T', ' ').slice(0, 19) + ' UTC'; };
            document.getElementById('clock-detail').textContent =
                'الخادم يقول: ' + fmt(server) + ' · وجهازك يقول: ' + fmt(here)
                + ' — الفرق نحو ' + Math.round(driftMin / 60) + ' ساعة.';
            box.style.display = 'block';
        }
    })();
</script>
</body>
</html>
