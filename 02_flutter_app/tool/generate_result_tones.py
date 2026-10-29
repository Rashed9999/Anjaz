# -*- coding: utf-8 -*-
"""
AMIAL-PAY-SOUND-001 — نغمتا نتيجة الدفع.

تُولَّد ولا تُنزَّل: ملفٌّ من الشبكة يحمل رخصةً لا تُقرأ، ونغمةُ منتجٍ
ماليّ تُشغَّل ملايينَ المرّات — فمصدرُها يجب أن يكون معروفاً.

والطابعُ جرسيّ لا جيبيٌّ خالص: الجيبُ الخالص رقيقٌ يبتلعه ضجيجُ المحلّ،
والجرسُ يُسمَع لأنّ توافقيّاتِه العليا تخترق الضجيج ثمّ تخبو سريعاً.
"""
import math, struct, wave

SR = 44100

# نسبةُ التوافقيّات ووزنُها وسرعةُ خبوّها — كلّما علت خبت أسرع، وهو ما
# يجعل الصوتَ جرساً لا صفيراً.
PARTIALS = [(1.0, 1.00, 1.0), (2.0, 0.45, 1.7), (3.0, 0.22, 2.6), (4.2, 0.11, 3.6)]


def bell(freq, dur, vol=1.0, decay=4.2):
    n = int(SR * dur)
    out = [0.0] * n
    attack = int(SR * 0.004)          # هجومٌ قصيرٌ يمنع الطقطقة
    for i in range(n):
        t = i / SR
        s = 0.0
        for ratio, weight, fast in PARTIALS:
            s += weight * math.sin(2 * math.pi * freq * ratio * t) \
                 * math.exp(-decay * fast * t / dur)
        a = i / attack if i < attack else 1.0
        # تلاشٍ في آخر ٢٠ مِلّي ثانية — وإلّا قُطع الصوتُ فطقطق
        r = min(1.0, (n - i) / (SR * 0.02))
        out[i] = s * vol * a * r
    return out


def mix(layers):
    """يخلط طبقاتٍ لكلٍّ إزاحتُها الزمنيّة."""
    total = max(int(SR * off) + len(buf) for off, buf in layers)
    out = [0.0] * total
    for off, buf in layers:
        start = int(SR * off)
        for i, v in enumerate(buf):
            out[start + i] += v
    return out


def normalize(buf, peak=0.86):
    m = max(abs(v) for v in buf) or 1.0
    return [v * peak / m for v in buf]


def write(path, buf):
    with wave.open(path, 'w') as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(b''.join(
            struct.pack('<h', max(-32767, min(32767, int(v * 32767))))
            for v in normalize(buf)))


# ── تمّ الدفع: ثلاثُ درجاتٍ صاعدة (دو · مي · صول) — يُقرأ «تمّ» فوراً ──
success = mix([
    (0.00, bell(523.25, 0.55, 0.75)),   # C5
    (0.09, bell(659.25, 0.60, 0.85)),   # E5
    (0.18, bell(783.99, 0.85, 1.00)),   # G5
    (0.18, bell(1567.98, 0.55, 0.25)),  # بريقٌ خفيفٌ يعلو الضجيج
])

# ── فشل الدفع: درجتان هابطتان منخفضتان — تُقرأ «لا» بلا فزع ──
failure = mix([
    (0.00, bell(311.13, 0.36, 0.95, decay=5.5)),   # Eb4
    (0.20, bell(233.08, 0.70, 1.00, decay=4.0)),   # Bb3
    (0.20, bell(116.54, 0.70, 0.35, decay=4.0)),   # قرارٌ يعطيها ثِقلاً
])

write('android/app/src/main/res/raw/pay_success.wav', success)
write('android/app/src/main/res/raw/pay_failed.wav', failure)

for name, buf in (('pay_success', success), ('pay_failed', failure)):
    print('%-12s %.2f ثانية · ذروة %.2f' %
          (name, len(buf) / SR, max(abs(v) for v in normalize(buf))))
