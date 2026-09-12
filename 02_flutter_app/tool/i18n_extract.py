#!/usr/bin/env python3
"""AMIAL-I18N-004 — استخراجُ النصوص العربيّة المحفورة إلى مفاتيح ترجمة.

**لماذا أداةٌ لا استبدالٌ يدويّ:** ٨٢٥ نصّاً محفوراً، وتعديلٌ نمطيٌّ جشع
قطع دالّةَ سهمٍ في هذا المشروع سلفاً فأنتج قوساً زائداً عطّل ملفّاً كاملاً
(القاعدة الخامسة). فالأداةُ تعمل على **حرفٍ نصّيٍّ كاملٍ مطابَقٍ بدقّة**،
وتُبقي التعليقاتِ والمفاتيحَ وأسماءَ الأصول خارجَ نظرها، **ويُصرَّف الناتجُ
بعدها دائماً**.
"""
import json, re, sys, unicodedata

# نصٌّ عربيٌّ داخل حرفٍ مفرد، غيرُ مُقحَمٍ ($) وغيرُ متبوعٍ بـ.tr
LIT = re.compile(r"'((?:[^'\\$]|\\.)*[؀-ۿ](?:[^'\\$]|\\.)*)'(?!\s*\.tr)")

# ما لا يُترجَم: مسارُ أصل، أو مفتاحٌ تقنيّ، أو تعليق.
SKIP_LINE = re.compile(r"^\s*(//|///)")

# **والنصُّ المُقحَم يُترك كلَّه** — ولا يُقتطع منه مفتاح.
#
# استثناءُ `$` من محتوى الحرف لا يكفي: في
#
#     'تضيف على ما قبلها ${plan['adds_count']} قدرة:'
#
# يقع داخلَ الإقحام **حرفان مفردان**، فيرى المُطابِقُ ما بينهما نصّاً
# قائماً بذاته ويستخرج `]} قدرة:` مفتاحاً. والنتيجةُ **تُصرَّف** — فلا
# يمسكها المحلّل — وتعرض النصّ صحيحاً بالمصادفة، ومعها مفتاحٌ خرِبٌ في
# ملفّ اللغة ونصٌّ لا يُترجَم أبداً.
#
# والنصُّ المُقحَم يحتاج `trParams` أو تقسيماً بيد إنسان، فيُقال إنّه
# مُخطّىً ولا يُسكَت عنه. (القاعدة السابعة.)
SKIP_CTX  = ('assets/', 'package:', 'http', 'Key(', 'key:', r'${')

# **`.tr` جالبٌ يُنفَّذ، و`const` تُحسب وقتَ التصريف** — فلا يجتمعان.
#
# أوّلُ تشغيلٍ للأداة أنتج `title: const Text('آلة حاسبة'.tr,` — وهي لا
# تُصرَّف إطلاقاً (`Invalid constant value`). أي أنّ الأداةَ المكتوبةَ
# لتجنّبَ عطلَ القاعدة الخامسة كانت **تصنع عطلاً من صنفٍ آخر**.
#
# ونزعُ `const` من السطر كلِّه آمنٌ معنىً: أسوأُ ما يقع فقدُ تحسينِ
# تصريفٍ لعنصرٍ مجاور (`EdgeInsets` · `SizedBox`)، لا تغيّرُ سلوك.
CONST_TOKEN = re.compile(r'\bconst\s+')

# **و`const` تسري إلى الداخل** — فلا يكفي سطرُ النصّ وحدَه.
#
# نزعُ ما على السطر أسقط أربعةً من خمسة، وبقي:
#
#     const Expanded(child: Column(children: [
#       Text('ترقية لخطّة مدفوعة'.tr, …)          ← سطران تحت `const`
#
# فيُمشى إلى الوراء من كلّ `.tr` **بعدّ الأقواس**، ويُنزَع كلُّ `const`
# يفتح تعبيراً ما زال محيطاً به. والحروفُ النصّيّةُ تُقنَّع قبل العدّ —
# فقوسٌ داخل نصٍّ عربيٍّ يقلب العدّ ويُنتج عطلاً مكان عطل.

# **والجملةُ المقطوعةُ على سطرين جملةٌ واحدة.** Dart تلصق الحرفين
# المتجاورين، فـ
#
#     'أجهزةُ الكاشير المسجَّلة في متجرك: سمِّها، '
#     'وألغِ ما فُقد منها فيتوقّف فوراً.'
#
# نصٌّ واحدٌ يقرؤه التاجر. وتحويلُ كلِّ شطرٍ وحدَه أنتج `'a'.tr 'b'.tr` —
# **لا يُصرَّف أصلاً**، وأسوأُ منه أنّه يقطع الجملةَ مفتاحين لا يُترجَم
# أحدُهما وحدَه. فتُلحَم قبل الاستخراج، ولا تُلحَم إلّا ما فيه عربيّة.
JOIN = re.compile(
    r"'((?:[^'\\$]|\\.)*)'\s*\n\s*'((?:[^'\\$]|\\.)*)'")


def join_adjacent(src):
    while True:
        m = JOIN.search(src)
        while m and not re.search('[؀-ۿ]', m.group(1) + m.group(2)):
            m = JOIN.search(src, m.start() + 1)
        if not m:
            return src
        src = src[:m.start()] + "'" + m.group(1) + m.group(2) + "'" + src[m.end():]


def _masked(src):
    """يُبدل محتوى الحروف النصّيّة بنقاطٍ ليستقيم عدُّ الأقواس."""
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c in "'\"":
            j = i + 1
            while j < n and src[j] != c:
                j += 2 if src[j] == '\\' else 1
            out.append(c + '.' * max(0, j - i - 1) + (c if j < n else ''))
            i = j + 1
        else:
            out.append(c)
            i += 1
    return ''.join(out)


def drop_enclosing_const(src):
    scan = _masked(src)
    cuts = []                       # (بداية، نهاية) لكلّ `const ` يُنزَع

    for m in re.finditer(r"\.tr\b", scan):
        i, depth, levels = m.start(), 0, 0
        while i > 0 and levels < 8:
            c = scan[i]
            if c in ')]}':
                depth += 1
            elif c in '([{':
                if depth:
                    depth -= 1
                else:
                    # خرجنا مستوىً: قبل القوس اسمُ المُنشئ، وقبله ربّما `const`
                    # قبل القوس اسمُ المُنشئ، وقبله ربّما `const` — ويُبحَث
                    # في المقطع كلِّه لا في ذيله: مسحُ المعرّف يبتلع كلمةَ
                    # `const` نفسَها لأنّها حروف.
                    j = i - 1
                    while j >= 0 and (scan[j].isalnum() or scan[j] in '_.<> \t\n'):
                        j -= 1
                    k = re.search(r'\bconst\s+', scan[j + 1:i])
                    if k:
                        cuts.append((j + 1 + k.start(), j + 1 + k.end(), ''))

                    # **و`const` تقع على تصريحٍ لا على مُنشئ**:
                    # `const labels = { 'retail': 'التجزئة' }`. فلا قوسَ
                    # قبله يحمل الكلمة، ويُبحَث خلف علامة الإسناد.
                    #
                    # **وهنا تُبدَّل ولا تُحذَف**: نزعُها من مُنشئٍ يترك
                    # نداءً سليماً، ونزعُها من تصريحٍ يترك `labels = {…}`
                    # بلا كلمةِ تصريحٍ إطلاقاً — `Undefined name`.
                    elif j >= 0 and scan[j] == '=':
                        d = re.search(r'\bconst\s+(?=[\w<>,\s]*$)', scan[:j])
                        if d:
                            cuts.append((d.start(), d.end(), 'final '))

                    levels += 1
            elif c == ';':
                break
            i -= 1

    for a, b, rep in sorted(set(cuts), reverse=True):
        src = src[:a] + rep + src[b:]
    return src

def key_for(text, used):
    """**المفتاحُ هو النصُّ العربيُّ نفسُه.**

    ولمَ لا رمزٌ لاتينيّ: `.tr` تُرجع **المفتاحَ نفسَه** حين تغيب ترجمتُه.
    فمفتاحٌ مثل `merchant_services` يُعرَض هكذا خاماً — وهو بعينه العطلُ
    الذي أُصلح في شاشة الباقات (‏`advanced_reports` في وجه تاجرٍ يمنيّ).

    **والنصُّ العربيُّ مفتاحاً يجعل الغيابَ يسقط على عربيّةٍ صحيحة** — أي
    أنّ أسوأَ حالةٍ هي حالُ اليوم بالضبط، لا أسوأُ منها. (القاعدة السابعة:
    الغيابُ يُقال ولا يُلبَس ثوبَ الحضور.)
    """
    return text

def process(path, existing):
    src = join_adjacent(open(path, encoding='utf-8').read())
    out, found = [], {}
    for line in src.split('\n'):
        if SKIP_LINE.match(line) or any(s in line for s in SKIP_CTX):
            out.append(line); continue
        hit = []

        def sub(m):
            t = m.group(1)
            if not t.strip() or len(t) < 2:
                return m.group(0)
            k = key_for(t, {**existing, **found})
            found[k] = t
            hit.append(k)
            return f"'{k}'.tr"

        new = LIT.sub(sub, line)
        if hit:
            new = CONST_TOKEN.sub('', new)
        out.append(new)
    return drop_enclosing_const('\n'.join(out)), found

if __name__ == '__main__':
    ar = json.load(open('assets/language/ar.json', encoding='utf-8'))
    total = {}
    for p in sys.argv[1:]:
        new_src, found = process(p, {**ar, **total})
        if found:
            open(p, 'w', encoding='utf-8').write(new_src)
            total.update(found)
            print(f'✓ {p}: {len(found)} نصّاً')
    print(json.dumps(total, ensure_ascii=False, indent=2))
