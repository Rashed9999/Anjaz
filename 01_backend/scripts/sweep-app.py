#!/usr/bin/env python3
"""
AMIAL-SWEEP-002 — **كلُّ شاشةٍ في التطبيق: أيُوصَل إليها؟**

══════════════════════════════════════════════════════════════════════
نمطُ العطل الأكثر تكراراً في أميال هو «مبنيٌّ ولا يُوصَل إليه»، ووقع
مرّتين في هذه الجلسة وحدها: شاشةُ الطلبات المرسَلة، وتوليدُ المتغيّرات.

و`flutter analyze` لا يمسكه: شاشةٌ لا يفتحها أحدٌ **تُصرَّف بلا خطأ**.

فهذا يعدّ كلَّ `Screen` في `lib/features`، ويسأل: هل يُذكر اسمُها في
ملفٍّ آخر؟ فإن لم — فهي شيفرةٌ ميّتةٌ أو بابٌ لم يُوصَل بعد.

**والذِّكرُ ليس ضغطاً** (القاعدة التاسعة): هذا يمسك الغيابَ الكامل، لا
الزرَّ الذي يفتح الشاشةَ الخطأ. ذاك لِـ`click-check`.
══════════════════════════════════════════════════════════════════════
"""

import re
import subprocess
import sys
from pathlib import Path

LIB = Path(__file__).resolve().parents[2] / "02_flutter_app" / "lib"

# شاشاتٌ تُفتح بالتوجيه لا بالاسم — تُستثنى بذكر سببها لا بصمت.
EXPECTED_ROOTS = {
    "SplashScreen", "DashboardScreen", "SignInScreen", "MainScreen",
}


def main() -> int:
    if not LIB.is_dir():
        print(f"لا مجلّد: {LIB}", file=sys.stderr)
        return 2

    files = sorted(LIB.rglob("*_screen.dart"))
    corpus = {p: p.read_text(encoding="utf-8", errors="ignore") for p in LIB.rglob("*.dart")}

    orphans, wired = [], 0

    for path in files:
        classes = re.findall(r"^class (\w+Screen)\b", corpus[path], re.M)
        if not classes:
            continue

        for cls in classes:
            if cls.startswith("_"):
                continue

            # **والذِّكرُ داخل الملفّ نفسِه ذِكر.** شاشةٌ تُعرَّف وتُفتح من
            # لوحةٍ في الملفّ ذاته موصولةٌ تماماً — واستثناءُ ملفِّها كان
            # يتّهم أربعَ شاشاتِ صيدليّةٍ سليمة. (يُستثنى سطرُ التعريف
            # وحدَه لا الملفُّ كلُّه.)
            # **والمطابقةُ بحدود الكلمة لا بالاحتواء.** `_XScreenState`
            # يحتوي `XScreen` كسلسلةٍ فرعيّة، فبحثٌ بالاحتواء يجد كلَّ
            # شاشةٍ في حالتها ويقول «موصولة» — صفرُ يتامى كذباً.
            word = re.compile(rf"(?<![A-Za-z0-9_]){re.escape(cls)}(?![A-Za-z0-9_])")

            # **وتعريفُ الشيءِ ليس استعمالاً.** يُنزع من ملفّها: سطرُ
            # الصنف، ومُنشِئُها، و`State<X>`، و`createState`. وبلا نزعِ
            # المُنشِئ مرّ المسبارُ على شاشةٍ يتيمةٍ فعلاً وقال «موصولة».
            own = corpus[path]
            for pattern in (
                rf"^class {cls}\b.*$",
                rf"^\s*const {cls}\(.*$",
                rf"^\s*{cls}\(.*$",
                rf"State<{cls}>",
                r"createState\(\)[^\n]*",
            ):
                own = re.sub(pattern, "", own, flags=re.M)

            referenced = bool(word.search(own)) or any(
                word.search(text) for other, text in corpus.items() if other != path
            )

            if referenced or cls in EXPECTED_ROOTS:
                wired += 1
            else:
                orphans.append((cls, path.relative_to(LIB)))

    print("═══ مسحُ شاشات التطبيق ═══")
    print(f"شاشات  : {wired + len(orphans)}")
    print(f"موصولة : {wired}")
    print(f"يتيمة  : {len(orphans)}\n")

    if orphans:
        print("── يتيمةٌ (لا يذكرها ملفٌّ آخر) ──")
        for cls, rel in orphans:
            print(f"  {cls:<42} {rel}")

    return 1 if orphans else 0


if __name__ == "__main__":
    raise SystemExit(main())
