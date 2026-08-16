#!/usr/bin/env python3
"""
AMIAL-APP-ACTIONS-001 — **جردُ كلّ فعلٍ في التطبيق، لا الأزرارُ وحدَها.**

══════════════════════════════════════════════════════════════════════
**الشرطُ الذي وُلد منه:** «لا تقل ›كلُّ الأزرار تعمل‹ إلّا إذا نتج تقريرٌ
آليٌّ يذكر عدد ما اكتُشف، وعدد ما اختُبر، وعدد ما تعذّر اختباره.»

فيُعدُّ كلُّ ما يُضغط: `FilledButton` · `ElevatedButton` · `TextButton` ·
`OutlinedButton` · `IconButton` · `InkWell` · `GestureDetector` ·
`FloatingActionButton` · `PopupMenuItem` · `ListTile.onTap` ·
أفعالُ الحوارات وأوراقِ القاع.

══════════════════════════════════════════════════════════════════════
**وثلاثةُ أحكامٍ لا حكمان** — و«غير معروف» ليس صفراً (القاعدة السابعة):

  · `dead`      : `onPressed: null` أو `onTap: null` أو معالجٌ فارغٌ `{}`
  · `wired`     : المعالجُ يستدعي متحكّماً أو مستودعاً أو ملاحة
  · `unverified`: معالجٌ موجودٌ **لم يُتتبَّع إلى API** من هنا

**و`unverified` ليست «تعمل».** تتبُّعُ السلسلة كاملةً (زرّ ← معالج ←
متحكّم ← API ← إذن ← أثرٌ ماليّ) يحتاج تشغيلاً، **ولا مُصرِّفَ Flutter في
هذه البيئة**. فيُقال العددُ ولا يُدّعى أنّه مفحوص.

الاستعمال:
    python3 scripts/app-actions.py             # جدولٌ وخلاصة
    python3 scripts/app-actions.py --json
    python3 scripts/app-actions.py --dead      # الميّتةُ وحدَها
"""

import json
import re
import sys
from collections import Counter
from pathlib import Path

APP = Path(__file__).resolve().parents[2] / '02_flutter_app' / 'lib'

# كلُّ ما يُضغط — والاسمُ يُقرأ في التقرير كما هو.
PRESSABLE = [
    'FilledButton', 'ElevatedButton', 'TextButton', 'OutlinedButton',
    'IconButton', 'FloatingActionButton', 'PopupMenuItem',
    'InkWell', 'GestureDetector', 'ListTile', 'CheckboxListTile',
    'SwitchListTile', 'RadioListTile', 'Dismissible', 'ActionChip',
    'ChoiceChip', 'FilterChip', 'InputChip', 'CupertinoButton',
]

HANDLER = re.compile(r'\b(onPressed|onTap|onLongPress|onSubmitted|onChanged|onSelected)\s*:')

# معالجٌ ميّتٌ صراحةً: `null` أو كتلةٌ فارغة أو دالّةٌ لا تفعل شيئاً.
DEAD = re.compile(
    r'\b(onPressed|onTap|onLongPress)\s*:\s*(null|\(\)\s*\{\s*\}|\(\)\s*=>\s*\{\s*\}|\(\s*_\s*\)\s*\{\s*\})')

# معالجٌ موصولٌ بشيءٍ حقيقيّ.
WIRED = re.compile(
    r'(Get\.(to|off|back|toNamed|offNamed|dialog|bottomSheet)|'
    r'Navigator\.|showDialog|showModalBottomSheet|'
    r'\bc\.[a-zA-Z_]+\(|controller\.[a-zA-Z_]+\(|'
    r'repo\.[a-zA-Z_]+\(|apiClient\.|setState\(|_[a-zA-Z]+\()')


def dart_files():
    if not APP.is_dir():
        print(f'مجلَّدُ التطبيق غير موجود: {APP}', file=sys.stderr)
        return []
    return sorted(APP.rglob('*.dart'))


def strip_comments(src: str) -> str:
    """**يُنزع التعليقُ أوّلاً** — شرحٌ يذكر `onPressed: null` ليس زرّاً ميّتاً."""
    return re.sub(r'///[^\n]*|//[^\n]*|/\*.*?\*/', '', src, flags=re.S)


def classify(src: str, at: int) -> str:
    """حكمُ الفعل من النصّ الذي يليه — حتّى نهاية المعالج تقريباً."""
    window = src[at:at + 400]

    if DEAD.search(src[max(0, at - 40):at + 120]):
        return 'dead'
    if WIRED.search(window):
        return 'wired'
    return 'unverified'


def scan():
    rows = []

    for path in dart_files():
        raw = path.read_text(errors='ignore')
        src = strip_comments(raw)
        rel = str(path.relative_to(APP.parent.parent))

        for m in HANDLER.finditer(src):
            # أيُّ عنصرٍ يحمل هذا المعالج؟ يُبحث للخلف عن أقرب اسمٍ معروف.
            back = src[max(0, m.start() - 300):m.start()]
            widget = next(
                (w for w in sorted(PRESSABLE, key=lambda x: -back.rfind(x))
                 if back.rfind(w) >= 0),
                'Unknown')

            rows.append({
                'file': rel,
                'line': src[:m.start()].count('\n') + 1,
                'widget': widget,
                'handler': m.group(1),
                'verdict': classify(src, m.start()),
            })

    return rows


def main() -> int:
    rows = scan()

    if not rows:
        print('لم يُكتشف فعلٌ واحد — تحقّق من مسار التطبيق', file=sys.stderr)
        return 2

    by_verdict = Counter(r['verdict'] for r in rows)
    dead = [r for r in rows if r['verdict'] == 'dead']

    if '--json' in sys.argv:
        print(json.dumps({
            'discovered': len(rows),
            'wired': by_verdict['wired'],
            'dead': by_verdict['dead'],
            'unverified': by_verdict['unverified'],
            'screens': len({r['file'] for r in rows}),
            'actions': rows,
            'not_measurable_here': (
                'تتبُّعُ السلسلة زرّ←API←إذن←أثرٌ ماليّ يحتاج تشغيلاً — '
                'ولا مُصرِّفَ Flutter في هذه البيئة'),
        }, ensure_ascii=False, indent=1))
        return 0

    if '--dead' in sys.argv:
        for r in dead:
            print(f"  {r['file']}:{r['line']}  {r['widget']}.{r['handler']}")
        return 0

    print()
    print('  ══ جردُ أفعال التطبيق ══')
    print()
    print(f"  ملفّاتٌ فيها أفعال : {len({r['file'] for r in rows})}")
    print(f"  أفعالٌ مكتشَفة     : {len(rows)}")
    print()
    print(f"  موصولةٌ بمعالجٍ حقيقيّ : {by_verdict['wired']}")
    print(f"  **ميّتةٌ صراحةً**      : {by_verdict['dead']}")
    print(f"  غيرُ متحقَّقٍ منها     : {by_verdict['unverified']}")
    print()

    print('  ── أكثرُ العناصر ──')
    for w, n in Counter(r['widget'] for r in rows).most_common(8):
        print(f'    {w:24} {n}')

    if dead:
        print()
        print('  ── ميّتةٌ صراحةً (معالجٌ null أو فارغ) ──')
        for r in dead[:40]:
            print(f"    {r['file']}:{r['line']}  {r['widget']}.{r['handler']}")
        if len(dead) > 40:
            print(f'    … و{len(dead) - 40} غيرها')

    print()
    print('  ── ما لا يُقاس من هنا ──')
    print('    · «غيرُ متحقَّق» **ليست ›تعمل‹**: تتبُّعُ زرّ←API←إذن←أثرٍ ماليّ')
    print('      يحتاج تشغيلاً، ولا مُصرِّفَ Flutter في هذه البيئة.')
    print('    · وزرٌّ معطَّلٌ بشرطٍ (‏`enabled ? fn : null`) يُقرأ ميّتاً هنا —')
    print('      وهو صوابٌ في محلّه. فتُراجَع القائمةُ ولا تُؤخذ حكماً.')
    print()

    return 0


if __name__ == '__main__':
    sys.exit(main())
