#!/usr/bin/env python3
"""
AMIAL-PRESS-001 — **يُضغط كلُّ زرٍّ في اللوحة، لا ثمانيةٌ وعشرون.**

══════════════════════════════════════════════════════════════════════
القاعدة التاسعة: «زرٌّ لم يُضغط ليس مبنيّاً». و`click-check.mjs` تضغط
٢٨ زرّاً مختارةً بيد — وهي عقودٌ دقيقةٌ لمسارات بعينها، لا مسح.

ومسحُ `sweep-admin.php` أثبت أنّ ١٧٢ مساراً تُفتح — **ولا تُضغط أزرارُ
أكثرِها**. فصفحةٌ تردّ ٢٠٠ وأزرارُها ميّتةٌ تمرّ من كلّ فحوصنا.

وأخطرُ صور العطل — كما في `CLAUDE.md` — **الموتُ الصامت**: معالجٌ يقرأ
`null.disabled` فيموت، فيُضغط الزرُّ ولا يحدث شيء: لا طلبٌ يصل، ولا
رسالةٌ تظهر، ولا خطأٌ في أيّ سجلّ. وهذا ما عطّل زرّ «إيداع» في الشبّاك.

**فالقياسُ هنا ليس «هل ظهر خطأ» بل: هل حدث شيءٌ أصلاً؟**

لكلّ زرٍّ يُرصد بعد الضغط:
  · طلبُ شبكةٍ خرج، أو
  · انتقالٌ إلى عنوانٍ آخر، أو
  · تغيُّرٌ في نصّ الصفحة (نافذةٌ فُتحت، جدولٌ تحمَّل)

فإن لم يحدث أيٌّ من الثلاثة **ولم يكن الزرُّ معطَّلاً بوضوح** — فهو ميّت.

**والأخطاءُ في الطرفيّة تُلتقط أيضاً**: `TypeError` في معالجِ ضغطةٍ هو
سببُ الموتِ الصامت لا عرَضُه.
══════════════════════════════════════════════════════════════════════

الاستعمال:
    php artisan db:seed --class=DemoDataSeeder
    AMIAL_DISABLE_ADMIN_CAPTCHA=true php artisan serve --port 8123 &
    python3 scripts/press-every-button.py --base http://127.0.0.1:8123

**والمتغيّرُ ليس زينة.** رمزُ الكابتشا **صورةٌ** ورمزُها في الجلسة — لا
يقرؤه مسبار. وهو معطَّلٌ في الإنتاج أصلاً
(`AMIAL_DISABLE_ADMIN_CAPTCHA=true`)، فالمسبارُ يعمل عليه بلا إعداد؛
والمحلّيُّ وحدَه يحتاج السطرَ أعلاه.
"""

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]

# أزرارٌ لا تُضغط، وسببُ كلٍّ مكتوبٌ لا مسكوتٌ عنه.
SKIP_TEXT = re.compile(
    r"خروج|logout|حذف|delete|تجميد|إيقاف|رفض|اعتماد|تحويل|صرف|دفع|"
    r"إرسال|تنفيذ|موافقة|تصدير|export|طباعة",
    re.I,
)


def admin_pages() -> list[str]:
    """مساراتُ GET التي تعرض صفحةً — من جدول المسارات نفسِه لا من قائمةٍ بيد."""
    out = subprocess.run(
        ["php", "artisan", "route:list", "--json"],
        cwd=ROOT, capture_output=True, text=True, timeout=180,
    )
    try:
        routes = json.loads(out.stdout)
    except json.JSONDecodeError:
        print("تعذّر قراءةُ جدول المسارات", file=sys.stderr)
        return []

    pages = []
    for r in routes:
        uri, methods = r.get("uri", ""), r.get("method", "")
        if "GET" not in methods or not uri.startswith("admin/"):
            continue
        # ذواتُ المعاملات تحتاج بياناتٍ — يتولّاها `sweep-admin.php`.
        if "{" in uri or any(x in uri for x in ("export", "logout", ".json", "download")):
            continue
        pages.append("/" + uri)

    return sorted(set(pages))


def _visible_sig(page) -> str:
    """بصمةُ النصّ **الذي يراه الإنسان** — لا شيفرةُ الصفحة.

    مطواةٌ تنفتح لا تغيّر من الشيفرة إلّا صنفاً واحداً، **وتغيّر المرئيَّ
    كثيراً**. فهذا القياسُ يفرّق بين زرٍّ عمل بلا طلبِ شبكةٍ وزرٍّ ميّت.

    **والمقارنةُ بالنصّ لا بطوله.** تبويبان طولُهما متقارب يُقرآن سواءً
    بالطول — وقد وقع: تبويبانِ سليمانِ عُدّا ميّتين لأنّ الفرقَ في الطول
    كان دون العشرين محرفاً. **والأرقامُ تُنزع** لأنّ ساعةً في الترويسة
    تتغيّر وحدَها فتجعل كلَّ زرٍّ «حيّاً».
    """
    try:
        raw = page.locator("body").inner_text(timeout=2000) or ""
    except Exception:
        return ""

    return re.sub(r"\s+", " ", re.sub(r"[0-9٠-٩]+", "", raw)).strip()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8123")
    ap.add_argument("--limit", type=int, default=0, help="عددُ الصفحات (0 = الكلّ)")
    # الافتراضُ حسابُ `DemoDataSeeder` — ويُغلَب بالبيئة على قاعدةٍ أخرى.
    ap.add_argument("--phone", default=os.environ.get("PROBE_ADMIN_PHONE", "967700000000"))
    ap.add_argument("--password", default=os.environ.get("PROBE_ADMIN_PASSWORD", "Admin@2026"))
    args = ap.parse_args()

    pages = admin_pages()
    if args.limit:
        pages = pages[: args.limit]

    if not pages:
        print("لا صفحاتٍ تُفحص", file=sys.stderr)
        return 2

    dead: list[tuple[str, str, str]] = []
    errors: list[tuple[str, str]] = []
    pressed = skipped = 0

    with sync_playwright() as p:
        # **المتصفّحُ المثبَّتُ سلفاً، لا تنزيلٌ جديد.** الحاويةُ فيها
        # chromium تحت `/opt/pw-browsers`، ونسخةُ playwright تطلب مجلّداً
        # برقمٍ آخر. فيُمرَّر المسارُ صراحةً بدل `playwright install`.
        exe = None
        for cand in (
            "/opt/pw-browsers/chromium/chrome-linux/chrome",
            "/opt/pw-browsers/chromium-1194/chrome-linux/chrome",
        ):
            if Path(cand).exists():
                exe = cand
                break

        browser = p.chromium.launch(headless=True, executable_path=exe)
        ctx = browser.new_context(locale="ar")
        page = ctx.new_page()

        console: list[str] = []
        page.on("console", lambda m: console.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: console.append(str(e)))

        # ══════════════════════════════════════════════════════════════
        #  **الدخولُ يُتحقَّق منه، ولا يُفترض.**
        #
        #  أوّلُ تشغيلٍ لهذا المسبار أبلغ عن **ستّين زرّاً ميّتاً** — وكلُّها
        #  حقولُ صفحة الدخول: الدخولُ فشل، فبقيت كلُّ صفحةٍ تُعيد التوجيه
        #  إليها، والمسبارُ يضغط النموذجَ نفسَه في كلّ مرّةٍ ويسمّيه عطلاً.
        #
        #  **ومسبارٌ يكذب أسوأ من غيابه.** فيُتأكَّد من الدخول قبل أيّ ضغطة،
        #  ويُوقَف المسحُ بخطأٍ صريحٍ إن لم ينجح.
        # ══════════════════════════════════════════════════════════════
        #  **وكلمةُ المرورِ كانت مخترَعةً.** كُتب هنا
        #  `"sweep-probe-password"` — ولا حسابَ في هذا المشروع بهذا السرّ.
        #  فالدخولُ يفشل **دائماً**، ويخرج المسبارُ بالرمز ٢ قبل أن يضغط
        #  زرّاً واحداً. أي أنّ هذا الملفَّ كلَّه (٢٢٥ سطراً) لم يُنتج
        #  نتيجةً واحدةً منذ كُتب.
        #
        #  والسرُّ الصحيحُ ليس سرّاً: `DemoDataSeeder` يُنشئ مديرَ العرض
        #  بـ`Admin@2026`. **ويُقرأ من البيئة أوّلاً** — فمن يشغّله على
        #  قاعدةٍ غيرِ قاعدة العرض يمرّره بلا تعديل ملفّ.
        page.goto(f"{args.base}/admin/auth/login", wait_until="networkidle")
        page.fill('input[name="phone"]', args.phone)
        page.fill('input[name="password"]', args.password)

        # **وجودُ الحقل ليس شرطاً.** حقلُ رمز التحقّق يُعرض دائماً في
        # القالب، و`AMIAL_DISABLE_ADMIN_CAPTCHA` تُلغي التحقّقَ لا العرض.
        # فيُملأ بأيّ شيءٍ **ويُقاس الدخولُ بنتيجته** لا بشكل الصفحة.
        captcha = page.locator('input[name="default_captcha_value"]')
        if captcha.count():
            captcha.first.fill("000000")

        # **ورمزُ PIN حلّ محلَّ الكابتشا** (AMIAL-AUTH-PIN). ويُملآن معاً
        # لا أحدُهما: النموذجُ قد يحمل أيَّهما بحسب النشرة، **ومسبارٌ يعرف
        # صيغةً واحدةً يعمى عند أوّل تغيير** — وهو ما وقع فعلاً فعميت
        # ثلاثةُ مسابرَ دفعةً واحدة.
        pin = page.locator('input[name="login_pin"]')
        if pin.count():
            pin.first.fill(os.environ.get("PROBE_ADMIN_PIN", "4321"))

        page.click('button[type="submit"]')
        page.wait_for_load_state("networkidle")

        if "login" in page.url:
            # **ويُقال أيُّ البابين أُغلق.** «تعذّر الدخول» وحدَها أرسلت
            # خلف كلمةِ مرورٍ صحيحة: السببُ كان الكابتشا لا الحساب.
            body = page.content()
            print(f"تعذّر الدخول — بقي على {page.url}", file=sys.stderr)

            if "Captcha" in body or "كابتشا" in body:
                print("  السبب: **الكابتشا** — ورمزُها صورةٌ لا يقرؤها مسبار.",
                      file=sys.stderr)
                print("  فيُشغَّل الخادمُ بها معطَّلة:", file=sys.stderr)
                print("     AMIAL_DISABLE_ADMIN_CAPTCHA=true php artisan serve --port 8123",
                      file=sys.stderr)
                print("  (وهي معطَّلةٌ في الإنتاج أصلاً — فالمسبارُ يعمل عليه بلا شيء)",
                      file=sys.stderr)
            else:
                print(f"  السبب: الحسابُ أو السرّ. المجرَّب: {args.phone}", file=sys.stderr)
                print("  وإن كانت القاعدةُ فارغة:", file=sys.stderr)
                print("     php artisan db:seed --class=DemoDataSeeder", file=sys.stderr)
                print("  أو مرِّر حساباً آخر: PROBE_ADMIN_PHONE=… PROBE_ADMIN_PASSWORD=…",
                      file=sys.stderr)

            browser.close()
            return 2

        for path in pages:
            try:
                page.goto(args.base + path, wait_until="networkidle", timeout=20000)
            except Exception:
                continue

            # **ما يُضغَط عنصرٌ يُضغَط.** كان المرشِّحُ يشمل
            # `[data-testid]:visible` كلَّها — فالتقط **عناوينَ الصفحات**
            # (وسمُ الاختبار عليها لا على زرّ) وأبلغ عنها «ميّتة». وعنوانٌ
            # لا يفعل شيئاً حين يُضغَط: هذا هو الصواب لا العطل.
            buttons = page.locator(
                "button:visible, a[data-act]:visible, "
                "a[data-testid]:visible, button[data-testid]:visible"
            )
            n = min(buttons.count(), 40)

            for i in range(n):
                b = buttons.nth(i)
                try:
                    label = (b.inner_text(timeout=1500) or "").strip()[:40]
                except Exception:
                    continue

                if not label or SKIP_TEXT.search(label):
                    skipped += 1
                    continue
                if b.is_disabled():
                    skipped += 1
                    continue

                # **والتبويبُ المفتوحُ سلفاً لا يُقاس بضغطة.** ضغطُ التبويب
                # النشط لا يفعل شيئاً — وهو الصواب. فعُدَّ سبعةً منها
                # «ميّتة» في أوّل تشغيلٍ صادق.
                try:
                    if (b.get_attribute("aria-selected") == "true"
                            or "active" in (b.get_attribute("class") or "").split()):
                        skipped += 1
                        continue
                except Exception:
                    pass

                before_url = page.url
                before_html = len(page.content())
                before_seen = _visible_sig(page)

                requests: list[str] = []
                on_request = lambda r: requests.append(r.url)  # noqa: E731
                page.on("request", on_request)
                console.clear()

                try:
                    b.click(timeout=2500)
                    page.wait_for_timeout(450)
                    pressed += 1
                except Exception:
                    page.remove_listener("request", on_request)
                    skipped += 1
                    continue

                page.remove_listener("request", on_request)

                moved = page.url != before_url
                changed = abs(len(page.content()) - before_html) > 60
                # **والنصُّ المرئيُّ هو الإشارةُ الثالثة، وبها أُصلح كذبٌ.**
                #
                # أوّلُ تشغيلٍ ناجح — بعد إصلاح الدخول — أبلغ عن **٣٥ زرّاً
                # ميّتاً من ٤٠**، وكلُّها مطاوي القائمة الجانبيّة وتبويبات
                # الصفحة. وهي تعمل: تفتح قسماً أو تبدّل تبويباً بـCSS.
                #
                # والسببُ أنّ القياسَ كان على طول **الشيفرة** — و
                # `class="collapse"` ← `class="collapse show"` خمسةُ محارف،
                # دون عتبة الستّين. فقيل «لم يحدث شيء» وقد حدث كلُّ شيء.
                #
                # **والنصُّ المرئيُّ يقفز** حين ينفتح مطواة: بنودُ القائمة
                # كانت مخفيّةً فصارت تُقرأ. (وحارسٌ يكذب أسوأ من غيابه —
                # وهذا الملفُّ كذب مرّتين قبل أن يصدق.)
                seen_changed = _visible_sig(page) != before_seen

                if not (requests or moved or changed or seen_changed):
                    dead.append((path, label, "ضُغط ولم يحدث شيء"))

                for c in console:
                    if "TypeError" in c or "is not a function" in c or "null" in c:
                        errors.append((path, f"{label} → {c[:90]}"))

                if moved:
                    try:
                        page.goto(args.base + path, wait_until="networkidle", timeout=15000)
                    except Exception:
                        break

        browser.close()

    print("═══ ضغطُ أزرار اللوحة ═══")
    print(f"صفحات : {len(pages)}")
    print(f"ضُغط  : {pressed}")
    print(f"مُخطّى : {skipped}  (خطرٌ أو معطَّلٌ أو بلا نصّ)")
    print(f"ميّت  : {len(dead)}")
    print(f"أخطاء : {len(errors)}\n")

    if dead:
        print("── ضُغط ولم يحدث شيء ──")
        for path, label, why in dead[:40]:
            print(f"  {path:<44} «{label}»")
        print()

    if errors:
        print("── أخطاءٌ في الطرفيّة عند الضغط ──")
        for path, msg in errors[:20]:
            print(f"  {path:<44} {msg}")

    return 1 if (dead or errors) else 0


if __name__ == "__main__":
    raise SystemExit(main())
