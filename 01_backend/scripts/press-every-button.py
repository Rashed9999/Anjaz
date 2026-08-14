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
    php artisan serve --port 8123 &
    python3 scripts/press-every-button.py --base http://127.0.0.1:8123
"""

import argparse
import json
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


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8123")
    ap.add_argument("--limit", type=int, default=0, help="عددُ الصفحات (0 = الكلّ)")
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
        page.goto(f"{args.base}/admin/auth/login", wait_until="networkidle")
        page.fill('input[name="phone"]', "967700000000")
        page.fill('input[name="password"]', "sweep-probe-password")

        # **وجودُ الحقل ليس شرطاً.** حقلُ رمز التحقّق يُعرض دائماً في
        # القالب، و`AMIAL_DISABLE_ADMIN_CAPTCHA` تُلغي التحقّقَ لا العرض.
        # فيُملأ بأيّ شيءٍ **ويُقاس الدخولُ بنتيجته** لا بشكل الصفحة.
        captcha = page.locator('input[name="default_captcha_value"]')
        if captcha.count():
            captcha.first.fill("000000")

        page.click('button[type="submit"]')
        page.wait_for_load_state("networkidle")

        if "login" in page.url:
            print(f"تعذّر الدخول — بقي على {page.url}", file=sys.stderr)
            browser.close()
            return 2

        for path in pages:
            try:
                page.goto(args.base + path, wait_until="networkidle", timeout=20000)
            except Exception:
                continue

            buttons = page.locator(
                "button:visible, a[data-act]:visible, [data-testid]:visible"
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

                before_url = page.url
                before_text = len(page.content())
                requests: list[str] = []
                page.on("request", lambda r: requests.append(r.url))
                console.clear()

                try:
                    b.click(timeout=2500)
                    page.wait_for_timeout(450)
                    pressed += 1
                except Exception:
                    skipped += 1
                    continue

                moved = page.url != before_url
                changed = abs(len(page.content()) - before_text) > 60

                if not (requests or moved or changed):
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
