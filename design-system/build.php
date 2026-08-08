<?php

/**
 * AMIAL-DS-001 — مولّدُ أساسات نظام التصميم.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لمَ مولّدٌ لا ملفّاتٌ مكتوبةٌ باليد:**
 *
 * نظامُ تصميمٍ يُكتب بيده ينفصل عن الشيفرة في أوّل تغييرِ لون. وهو ليس
 * احتمالاً نظريّاً — **هو ما وقع في هذا المشروع بعينه**: كان الأزرقُ
 * `#053391` في التطبيق و`#0f2b46` في لوحة الإدارة، أزرقان مختلفان
 * لعلامةٍ واحدة، لأنّ القيمة نُسخت ولم تُشتق.
 *
 * فتُقرأ التوكِنز من `amial-tokens.css` — المصدرِ المحروس نفسِه — وتُولَّد
 * المعايناتُ منها. **ومن غيّر لوناً في المصدر تتغيّر معاينتُه معه، أو
 * يسقط الحارس.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **التشغيل:**   php design-system/build.php
 * **الحارس:**    tests/Feature/DesignSystemSyncTest.php
 *
 * والمخرَجُ معايناتُ HTML مستقلّة، يقرأ كلٌّ منها سطرُه الأوّل
 * `<!-- @dsCard … -->` فيبني كلود ديزاين منها بطاقاتِ اللوحة.
 */

$root = __DIR__;
$tokensFile = $root . '/../01_backend/public/assets/css/amial-tokens.css';

if (! is_file($tokensFile)) {
    fwrite(STDERR, "لم يُعثر على مصدر التوكِنز: {$tokensFile}\n");
    exit(1);
}

// ═══════════════════════════════════════════════════════════════════════
// ١) قراءةُ التوكِنز من كتلة :root وحدها
// ═══════════════════════════════════════════════════════════════════════

$css = file_get_contents($tokensFile);

if (! preg_match('/:root\s*\{(.*?)\}/s', $css, $m)) {
    fwrite(STDERR, "لا كتلةَ :root في مصدر التوكِنز.\n");
    exit(1);
}

/** @var array<string,string> $tokens */
$tokens = [];

// التعليقُ العربيّ بعد القيمة يُلتقط كوصفٍ للتوكِن — فالشرحُ مكتوبٌ
// أصلاً في المصدر، ونقلُه إلى مكانٍ ثانٍ يعني نسختين تفترقان.
$notes = [];

foreach (preg_split('/\R/', $m[1]) as $line) {
    if (! preg_match('/(--amial-[a-z-]+)\s*:\s*([^;]+);(?:\s*\/\*\s*(.*?)\s*\*\/)?/i', $line, $t)) {
        continue;
    }

    $tokens[$t[1]] = trim($t[2]);
    $notes[$t[1]] = isset($t[3]) ? trim($t[3]) : '';
}

if ($tokens === []) {
    fwrite(STDERR, "لم يُقرأ أيُّ توكِن.\n");
    exit(1);
}

// ═══════════════════════════════════════════════════════════════════════
// ٢) نسبةُ التباين — WCAG 2.1
// ═══════════════════════════════════════════════════════════════════════

/**
 * **ولمَ تُحسب لا تُكتب:** بنكٌ يعرض مبالغَ مالية، ونصٌّ لا يُقرأ في شمس
 * صنعاء عطلٌ لا تجميل. والنسبةُ تتغيّر مع كلّ تعديلِ لون — فحسابُها من
 * القيمة يجعلها تصدق دائماً، وكتابتُها تجعلها تكذب بعد أوّل تغيير.
 */
function luminance(string $hex): float
{
    $hex = ltrim($hex, '#');

    $ch = [];

    foreach ([0, 2, 4] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $ch[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
}

function contrast(string $a, string $b): float
{
    $la = luminance($a);
    $lb = luminance($b);

    return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
}

/** درجةُ WCAG للنصّ العاديّ (AA = 4.5 · AAA = 7). */
function grade(float $ratio): array
{
    if ($ratio >= 7.0) {
        return ['AAA', '#0a7f3f'];
    }

    if ($ratio >= 4.5) {
        return ['AA', '#0a7f3f'];
    }

    if ($ratio >= 3.0) {
        return ['AA كبير فقط', '#b8860b'];
    }

    return ['دون الحدّ', '#DC0A0B'];
}

// ═══════════════════════════════════════════════════════════════════════
// ٣) الهيكلُ المشترك للمعاينات
// ═══════════════════════════════════════════════════════════════════════

$shell = function (string $card, string $title, string $body) use ($tokens): string {
    // القيمُ تُحقن في :root فتصير المعاينةُ مستقلّةً — كلود ديزاين يعرض
    // كلَّ ملفٍّ وحده بلا وصولٍ إلى ملفّ التوكِنز.
    $vars = '';

    foreach ($tokens as $k => $v) {
        $vars .= "      {$k}: {$v};\n";
    }

    return <<<HTML
    <!-- @dsCard {$card} -->
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} — أميال باي</title>
    <!--
      مولَّدٌ آليّاً من 01_backend/public/assets/css/amial-tokens.css
      لا يُحرَّر بيد. شغِّل: php design-system/build.php
    -->
    <style>
    :root {
    {$vars}}
    * { box-sizing: border-box; }
    body {
      margin: 0; padding: 28px;
      background: var(--amial-background);
      color: var(--amial-text);
      font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
      line-height: 1.7;
    }
    h1 { font-size: 20px; margin: 0 0 4px; color: var(--amial-primary); }
    .sub { color: var(--amial-text-secondary); font-size: 13px; margin: 0 0 24px; }
    .grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); }
    .card {
      background: var(--amial-surface);
      border: 1px solid var(--amial-border);
      border-radius: var(--amial-radius);
      box-shadow: var(--amial-shadow);
      overflow: hidden;
    }
    .meta { padding: 12px 14px; }
    .name { font-weight: 600; font-size: 13px; }
    .val  { font-family: ui-monospace, monospace; font-size: 12px; color: var(--amial-text-secondary); direction: ltr; text-align: right; }
    .note { font-size: 11px; color: var(--amial-text-muted); margin-top: 4px; }
    .sec  { margin: 30px 0 12px; font-size: 13px; font-weight: 700; color: var(--amial-text-secondary); }
    .warn {
      background: #FFF4F4; border: 1px solid var(--amial-red);
      border-radius: var(--amial-radius-sm);
      padding: 14px 16px; margin: 0 0 22px; font-size: 13px;
    }
    .warn b { color: var(--amial-red); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--amial-surface); }
    th, td { text-align: right; padding: 9px 12px; border-bottom: 1px solid var(--amial-border); }
    th { font-size: 11px; color: var(--amial-text-muted); font-weight: 600; }
    </style>
    </head>
    <body>
    {$body}
    </body>
    </html>
    HTML;
};

$out = [];

// ═══════════════════════════════════════════════════════════════════════
// ٤) الألوان — بأربطةِ تباينٍ محسوبة
// ═══════════════════════════════════════════════════════════════════════

$groups = [
    'ألوان العلامة' => ['--amial-primary', '--amial-primary-dark', '--amial-primary-light',
                        '--amial-yellow', '--amial-yellow-dark', '--amial-yellow-light',
                        '--amial-red'],
    'الأسطح' => ['--amial-background', '--amial-surface', '--amial-border'],
    'النصّ' => ['--amial-text', '--amial-text-secondary', '--amial-text-muted'],
];

$body = "<h1>ألوان أميال باي</h1>\n"
      . "<p class=\"sub\">مستخرَجةٌ بكسل-بكسل من الشعار الرسميّ · مصدرُها الوحيد "
      . "<code>amial_colors.dart</code> ومرآتُها <code>amial-tokens.css</code></p>\n";

foreach ($groups as $label => $keys) {
    $body .= "<div class=\"sec\">{$label}</div>\n<div class=\"grid\">\n";

    foreach ($keys as $k) {
        if (! isset($tokens[$k])) {
            continue;
        }

        $hex = $tokens[$k];
        $note = $notes[$k] !== '' ? '<div class="note">' . htmlspecialchars($notes[$k]) . '</div>' : '';

        // النصُّ فوق اللون: أبيضُ أو داكنٌ بحسب أيّهما أوضح.
        $onWhite = contrast($hex, '#FFFFFF');
        $onDark = contrast($hex, '#1A2433');
        $fg = $onWhite >= $onDark ? '#FFFFFF' : '#1A2433';
        $ratio = max($onWhite, $onDark);
        [$g, $gc] = grade($ratio);

        $body .= <<<CARD
        <div class="card">
          <div style="background:{$hex};height:74px;display:flex;align-items:flex-end;justify-content:space-between;padding:8px 12px;color:{$fg};font-size:11px">
            <span>نصٌّ فوقه</span><span style="opacity:.85">{$ratio}:1</span>
          </div>
          <div class="meta">
            <div class="name">{$k}</div>
            <div class="val">{$hex}</div>
            <div class="note" style="color:{$gc}">التباين: {$g}</div>
            {$note}
          </div>
        </div>

        CARD;
    }

    $body .= "</div>\n";
}

// جدولُ قراءةِ النصّ على الأسطح — وهو ما يقرأه العميلُ فعلاً.
$body .= "<div class=\"sec\">قراءةُ النصّ على الأسطح</div>\n<table>\n"
       . "<tr><th>النصّ</th><th>على السطح</th><th>على الخلفيّة</th></tr>\n";

foreach (['--amial-text' => 'الأساسيّ', '--amial-text-secondary' => 'الثانويّ',
          '--amial-text-muted' => 'الباهت'] as $k => $ar) {
    if (! isset($tokens[$k])) {
        continue;
    }

    $cs = contrast($tokens[$k], $tokens['--amial-surface']);
    $cb = contrast($tokens[$k], $tokens['--amial-background']);
    [$gs, $gsc] = grade($cs);
    [$gb, $gbc] = grade($cb);

    $body .= "<tr><td>{$ar} <span class=\"val\">{$tokens[$k]}</span></td>"
           . "<td style=\"color:{$gsc}\">{$cs}:1 — {$gs}</td>"
           . "<td style=\"color:{$gbc}\">{$cb}:1 — {$gb}</td></tr>\n";
}

$body .= "</table>\n";

// العددُ يُحسب من المصدر لا يُكتب — فرقمٌ مكتوبٌ يكذب بعد أوّل توكِنٍ يُضاف.
$colourCount = count(array_filter(
    $tokens,
    static fn (string $v): bool => (bool) preg_match('/^#[0-9a-f]{3,8}$/i', $v),
));

$out['foundations/colors.html'] = $shell(
    'group="الأساسات" name="الألوان" subtitle="' . $colourCount . ' توكِناً · بتباينٍ محسوب"',
    'الألوان',
    $body,
);

// ═══════════════════════════════════════════════════════════════════════
// ٥) المسافاتُ والحواف
// ═══════════════════════════════════════════════════════════════════════

$body = "<h1>الحوافّ والظلال</h1>\n"
      . "<p class=\"sub\">ما هو معرَّفٌ اليوم في التوكِنز — لا أكثر</p>\n<div class=\"grid\">\n";

foreach (['--amial-radius', '--amial-radius-sm'] as $k) {
    if (! isset($tokens[$k])) {
        continue;
    }

    $body .= <<<CARD
    <div class="card">
      <div style="padding:18px;display:flex;justify-content:center">
        <div style="width:100%;height:56px;background:var(--amial-primary-light);border-radius:{$tokens[$k]}"></div>
      </div>
      <div class="meta"><div class="name">{$k}</div><div class="val">{$tokens[$k]}</div></div>
    </div>

    CARD;
}

if (isset($tokens['--amial-shadow'])) {
    $body .= <<<CARD
    <div class="card">
      <div style="padding:18px;display:flex;justify-content:center;background:var(--amial-background)">
        <div style="width:100%;height:56px;background:#fff;border-radius:var(--amial-radius);box-shadow:{$tokens['--amial-shadow']}"></div>
      </div>
      <div class="meta"><div class="name">--amial-shadow</div><div class="val">{$tokens['--amial-shadow']}</div></div>
    </div>

    CARD;
}

$body .= "</div>\n"
       . "<div class=\"warn\" style=\"margin-top:26px;background:#FFFBEB;border-color:var(--amial-yellow-dark)\">"
       . "<b>ولا سُلَّمَ مسافاتٍ في التوكِنز اليوم.</b> الحشواتُ والفجواتُ مكتوبةٌ "
       . "رقماً في كلّ ودجت على حدة. وهذا يُقال ولا يُخترع له سُلَّمٌ هنا — "
       . "فمعاينةٌ تعرض ما ليس مبنيّاً تكذب. (القاعدة السابعة: «غير معروف» ليس صفراً.)"
       . "</div>\n";

$out['foundations/spacing.html'] = $shell(
    'group="الأساسات" name="الحوافّ والظلال" subtitle="حافّتان وظلٌّ واحد · ولا سُلَّمَ مسافات"',
    'الحوافّ والظلال',
    $body,
);

// ═══════════════════════════════════════════════════════════════════════
// ٦) الطباعة — وهنا يُقال ما قِيس
// ═══════════════════════════════════════════════════════════════════════

$body = <<<'BODY'
<h1>الطباعة</h1>
<p class="sub">قِيست، ولم تُفترض</p>

<div class="warn">
  <b>الخطُّ المحزوم لا يحمل حرفاً عربيّاً واحداً.</b><br><br>
  <code>Rubik-Regular.ttf</code> فيه ٦٩٠ محرفاً، ونصيبُ النطاق العربيّ
  (U+0600–U+06FF) منها <b>صفر</b>. والأشكالُ التقديميّة (U+FE70–U+FEFF)
  <b>صفر</b>. فحرفُ «أ» و«م» و«ي» غيرُ موجودةٍ فيه إطلاقاً.<br><br>
  والتطبيق يفرض <code>languageCode = 'ar'</code> عند أوّل إقلاع، ويعلن
  <code>fontFamily: 'Rubik'</code> في الثيم الفاتح و<code>'Roboto'</code>
  في الداكن — <b>وكلاهما بلا عربيّة، والداكنُ غيرُ محزومٍ أصلاً.</b><br><br>
  <b>فما يقرأه العميلُ اليمنيّ اليوم يرسمه خطُّ نظامِ جهازه.</b> يختلف بين
  سامسونج وشاومي وآيفون، ويختلف بين إصدارين من النظام نفسِه. ولا خطأ في
  أيّ بناء، ولا تحذير في أيّ سجلّ — النصُّ يظهر، فيُظنّ أنّه محكوم.
</div>

<div class="sec">ما يُحكَم اليوم</div>
<table>
  <tr><th>الوجه</th><th>الحال</th></tr>
  <tr><td>اللاتينيّ والأرقام</td><td style="color:#0a7f3f">محكومٌ — Rubik بأربعة أوزان (300/400/500/600)</td></tr>
  <tr><td>العربيّ — وهو لغةُ التطبيق</td><td style="color:#DC0A0B">غيرُ محكوم — خطُّ النظام، يختلف بكلّ جهاز</td></tr>
  <tr><td>الثيم الداكن</td><td style="color:#DC0A0B">Roboto — غيرُ محزومٍ ولا عربيّ فيه</td></tr>
  <tr><td>سُلَّمُ الأحجام</td><td style="color:#b8860b">غيرُ معرَّفٍ في توكِن — أرقامٌ في كلّ ودجت</td></tr>
</table>

<div class="sec">Rubik — الأوزان المحزومة (لاتينيّ فقط)</div>
<div class="card" style="padding:18px">
  <div style="font-weight:300;font-size:22px">Amial Pay — 1,250,000 YER</div>
  <div style="font-weight:400;font-size:22px">Amial Pay — 1,250,000 YER</div>
  <div style="font-weight:500;font-size:22px">Amial Pay — 1,250,000 YER</div>
  <div style="font-weight:600;font-size:22px">Amial Pay — 1,250,000 YER</div>
</div>

<p class="sub" style="margin-top:18px">
  ولا تُعرض هنا عيّنةٌ عربيّةٌ بوزنٍ محدَّد، لأنّ عرضَها يوهم بتحكّمٍ لا وجودَ له.
</p>
BODY;

$out['foundations/typography.html'] = $shell(
    'group="الأساسات" name="الطباعة" subtitle="لاتينيٌّ محكوم · وعربيٌّ غيرُ محكوم"',
    'الطباعة',
    $body,
);

// ═══════════════════════════════════════════════════════════════════════
// ٧) الكتابة
// ═══════════════════════════════════════════════════════════════════════

$written = 0;

foreach ($out as $rel => $html) {
    $path = $root . '/' . $rel;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    // المسافاتُ البادئة في heredoc تُزال — فالمخرَجُ نظيفٌ ومستقرٌّ بين
    // التشغيلات، وإلّا سقط حارسُ الانجراف على فرقٍ لا معنى له.
    $clean = preg_replace('/^ {4}/m', '', $html) . "\n";

    if (! is_file($path) || file_get_contents($path) !== $clean) {
        file_put_contents($path, $clean);
        $written++;
    }
}

printf("توكِنز مقروءة: %d · معاينات: %d · كُتب/حُدِّث: %d\n",
    count($tokens), count($out), $written);
