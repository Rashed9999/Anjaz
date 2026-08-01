#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
scripts/dom-refs.py — معرّفٌ يُطلب من الشاشة ولا وجود له.

══════════════════════════════════════════════════════════════════════
لماذا هذا الملفّ موجود

زرّ «إيداع» في شبّاك الصرّاف كان لا يفعل شيئاً. لا رسالة، ولا خطأ في أيّ
سجلّ، ولا طلبٌ يصل إلى الخادم. والسبب سطرٌ واحد:

    $('ct-deposit').disabled = $('ct-withdraw').disabled = true;

وزرُّ `ct-withdraw` كان قد أُزيل حين صار السحب برمز العميل، وبقي ذكرُه
هنا. فصار السطر يقرأ `null.disabled` — **خارج `try`** — فيُلقي قبل أيّ
طلب، ويموت المعالج بصمت.

وهذا الصنف لا تمسكه أيّ طبقةٍ قائمة: الملفّ يُحلَّل تركيبيّاً بلا خطأ،
والصفحة تردّ ٢٠٠، والاختبارات تنادي نقطة النهاية مباشرةً فتنجح. العطل
يقع في المتصفّح وحده، وعند الضغط وحده.

فهذا الفحص يقرأ كلّ معرّفٍ تطلبه الشيفرة من الشاشة، ويتأكّد أنّ له
`id=` في مكانٍ ما من القوالب التي تُعرَض معه.

══════════════════════════════════════════════════════════════════════
«معه» تعني: ملفّه، وما يُضمِّنه، ومن يُضمِّنه، وإخوتُه تحت ذلك الأب.

فقالبُ اللوحة يشغّل معرّفاتٍ تعيش في جزئيّاته، والجزئيّةُ تشغّل معرّفاتٍ
في أبيها. ولذلك تُبنى **مكوّنات الاتّصال** في رسم التضمين، ويُفحص كلّ
ملفٍّ في مكوّنه لا وحده — وإلّا امتلأ الفحص بإنذاراتٍ كاذبة فأُهمل، وهو
أسوأ من غيابه.
"""

import re
import sys
from pathlib import Path

VIEWS = Path('resources/views')

# ما يُطلب به عنصرٌ من الشاشة
REF_PATTERNS = [
    re.compile(r"""getElementById\(\s*['"]([^'"$among{}]+)['"]\s*\)"""),
    re.compile(r"""\$\(\s*['"]([A-Za-z][\w-]*)['"]\s*\)"""),      # الاختصار $('id')
    re.compile(r"""\$el\(\s*['"]([A-Za-z][\w-]*)['"]\s*\)"""),
]

ID_PATTERN = re.compile(r"""\bid\s*=\s*["']([^"'{}$\s]+)["']""")
INCLUDE_PATTERN = re.compile(r"""@(?:include|extends|includeIf|includeWhen)\(\s*['"]([^'"]+)['"]""")


def view_path(dotted: str) -> Path:
    return VIEWS / (dotted.replace('.', '/') + '.blade.php')


def main() -> int:
    files = sorted(VIEWS.rglob('*.blade.php'))
    text = {f: f.read_text(encoding='utf-8', errors='replace') for f in files}

    # ── مكوّنات الاتّصال في رسم التضمين ──────────────────────────────
    parent = {f: f for f in files}

    def find(a):
        while parent[a] != a:
            parent[a] = parent[parent[a]]
            a = parent[a]
        return a

    def union(a, b):
        ra, rb = find(a), find(b)
        if ra != rb:
            parent[rb] = ra

    for f in files:
        for dotted in INCLUDE_PATTERN.findall(text[f]):
            target = view_path(dotted)
            if target in parent:
                union(f, target)

    groups: dict[Path, list[Path]] = {}
    for f in files:
        groups.setdefault(find(f), []).append(f)

    # المعرّفات المتاحة لكلّ مكوّن — من نصّ الملفّات كاملاً، لأنّ كثيراً
    # منها يُكتب داخل قوالب نصّيّة في الجافاسكربت نفسه.
    ids_of_group = {
        root: {i for f in members for i in ID_PATTERN.findall(text[f])}
        for root, members in groups.items()
    }

    problems = []

    for f in files:
        blocks = re.findall(r'<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>', text[f], re.S)
        if not blocks:
            continue

        available = ids_of_group[find(f)]

        for js in blocks:
            # التعليقات تُزال أوّلاً. فأوّل تشغيلٍ لهذا الفحص أنذر على معرّفٍ
            # ورد في **تعليقٍ عربيٍّ يشرح أنّه أُزيل** — أي أنّ التعليق الذي
            # يوثّق الإصلاح كان يُبقي الإنذار قائماً. وقد وقع هذا الصنف في
            # هذا المشروع مرّةً من قبل، فكُتب هنا صريحاً.
            js = re.sub(r'/\*[\s\S]*?\*/', ' ', js)
            js = re.sub(r'(?m)^\s*//.*$', ' ', js)

            for pat in REF_PATTERNS:
                for ref in pat.findall(js):
                    if '${' in ref or ref in available:
                        continue
                    problems.append((f, ref))

    if not problems:
        return 0

    seen = set()
    for f, ref in problems:
        key = (str(f), ref)
        if key in seen:
            continue
        seen.add(key)
        print(f'      {f}: «{ref}» لا وجود له — الشيفرة تقرأ null والمعالج يموت بصمت')

    return 1


if __name__ == '__main__':
    sys.exit(main())
