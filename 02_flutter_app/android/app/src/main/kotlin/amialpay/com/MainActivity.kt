package amialpay.com

import android.media.AudioAttributes
import android.media.MediaPlayer
import android.speech.tts.TextToSpeech
import android.view.WindowManager
import java.util.Locale
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * AMIAL-SEC-CAPTURE-001 — منع تصوير الشاشة على الشاشات الحسّاسة.
 *
 * FLAG_SECURE يمنع لقطة الشاشة وتسجيل الشاشة، ويُخفي محتوى النافذة من قائمة
 * التطبيقات الأخيرة. ضابط قياسي في تطبيقات المحافظ والبنوك.
 *
 * لا نُفعّله على مستوى التطبيق كلّه — يبقى المستخدم قادراً على تصوير إيصالاته
 * ومشاركتها. تُفعّله الشاشة الحسّاسة عند دخولها وتُطفئه عند خروجها عبر هذه
 * القناة.
 */
class MainActivity : FlutterFragmentActivity() {

    private val secureChannel = "amial_pay/secure_screen"

    /**
     * AMIAL-PAY-SOUND-001 — نغمتا «تمّ الدفع» و«فشل الدفع».
     *
     * قاله صاحب المشروع: «عند اتمام الدفع صوت تم الدفع او فشل الدفع..
     * هذه لا تعمل لدي».
     *
     * وقِيس فلم تكن قد وُجدت قطّ: لا مشغّلَ صوتٍ واحداً في التطبيق كلّه،
     * ولا ملفَّ صوتٍ في المشروع غير نغمة الإشعار. ونتيجةُ الدفع كانت
     * صامتةً تماماً — لا صوتَ ولا اهتزاز.
     *
     * **وتُشغَّل من هنا لا بحزمةٍ جديدة.** إضافةُ مشغّلٍ خارجيّ تُدخل
     * وحدةَ Gradle لا يُصرِّفها أحدٌ في هذه البيئة، وأوّلُ من يقرؤها
     * Codemagic بعد دقائق من البناء — وقد سقط مرّتين بهذا بعينه.
     * فـ`MediaPlayer` من إطار أندرويد نفسِه: بلا تبعيّةٍ ولا خطر.
     */
    private val soundChannel = "amial_pay/feedback_sound"
    private var player: MediaPlayer? = null

    /**
     * AMIAL-PAY-VOICE-001 — النطق بالعربيّة: «تمّ الدفع بنجاح» / «فشل الدفع».
     *
     * طلبه صاحب المشروع: «الا يمكنك جعل صوت يتكلم العربي».
     *
     * ولا يُسجَّل ملفُّ صوتٍ بشريّ: تسجيلُه يحتاج صوتاً ورخصةً ومراجعة،
     * ويتجمّد فلا يُترجَم ولا يُغيَّر. **ومُركِّبُ الكلام في الجهاز نفسِه**
     * ينطقها بصوت المستخدم المعتاد، بلا حزمةٍ ولا ميغابايت.
     *
     * **ولا يُفترَض وجودُه.** كثيرٌ من الأجهزة بلا صوتٍ عربيٍّ منزَّل —
     * فيُسأل `isLanguageAvailable` ولا يُخمَّن، وإن غاب بقيت النغمةُ
     * وحدَها. **وصمتٌ لأنّ الصوتَ العربيّ غيرُ منزَّل أسوأ من نغمة.**
     */
    private var tts: TextToSpeech? = null

    // تُكتب على خيط تهيئة المُركِّب وتُقرأ على الخيط الرئيسيّ — فبلا
    // `@Volatile` قد يقرأ الرئيسيُّ قيمةً قديمةً فيصمت وهو قادرٌ على النطق.
    @Volatile
    private var arabicSpeech = false

    private fun initSpeech() {
        if (tts != null) return
        tts = TextToSpeech(applicationContext) { status ->
            arabicSpeech = if (status == TextToSpeech.SUCCESS) {
                val engine = tts ?: return@TextToSpeech
                val code = engine.setLanguage(Locale("ar"))
                // MISSING_DATA / NOT_SUPPORTED كلاهما «لا صوتَ عربيّ».
                code != TextToSpeech.LANG_MISSING_DATA &&
                    code != TextToSpeech.LANG_NOT_SUPPORTED
            } else {
                false
            }
        }
    }

    private fun say(text: String) {
        if (!arabicSpeech) return
        tts?.speak(text, TextToSpeech.QUEUE_FLUSH, null, "amial_pay_result")
    }

    private fun play(resId: Int, speech: String? = null) {
        // مشغّلٌ سابقٌ لم يُحرَّر يُسرّب الذاكرةَ ويقطع نفسَه عند ضغطتين
        // متتاليتين — فيُغلق قبل أن يُفتح غيرُه.
        player?.release()
        player = null

        // ══════════════════════════════════════════════════════════════
        // **والنطقُ مرّةً واحدةً بالضبط.**
        //
        // أوّلُ صياغةٍ نطقت من موضعين — من نهاية النغمة، ومن احتياطٍ
        // بعد `start()` — فنغمةٌ قصيرةٌ تنتهي قبل أن يُقرأ الاحتياطُ
        // تُنتج نطقاً مرّتين، والثانيةُ تقطع الأولى بـ`QUEUE_FLUSH`
        // فتُسمَع «تم الد… تم الدفع بنجاح».
        //
        // **ويُنطَق ولو لم تُشغَّل النغمةُ إطلاقاً**: مورِدٌ لا يُفتح أو
        // مشغّلٌ يرفض لا يجوز أن يُسقط الخبر — والنغمةُ تُلفت، والكلامُ
        // هو الخبر.
        // ══════════════════════════════════════════════════════════════
        var spoken = false
        val speakOnce = {
            if (!spoken) {
                spoken = true
                if (speech != null) say(speech)
            }
        }

        val mp = MediaPlayer.create(applicationContext, resId)
        if (mp == null) {
            speakOnce()
            return
        }

        mp.setAudioAttributes(
            AudioAttributes.Builder()
                // نغمةُ نتيجةٍ لا موسيقى: تخرج على مسار التنبيهات فتُسمع
                // ولو كانت الوسائطُ مخفوضة.
                .setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build()
        )

        // **الجرسُ يلفت، والكلامُ يخبر** — ولا يُنطقان معاً فيتداخلان.
        // وهذا ترتيبُ أجهزة نقاط البيع المعروف: نغمةٌ قصيرةٌ ثمّ نتيجة.
        mp.setOnCompletionListener {
            it.release()
            if (player === it) player = null
            speakOnce()
        }
        mp.setOnErrorListener { _, _, _ ->
            speakOnce()
            true
        }

        player = mp

        try {
            mp.start()
        } catch (e: IllegalStateException) {
            speakOnce()
        }
    }

    override fun onDestroy() {
        player?.release()
        player = null
        tts?.stop()
        tts?.shutdown()
        tts = null
        super.onDestroy()
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        // **تُهيَّأ مبكّراً.** تهيئةُ المُركِّب غيرُ فوريّة، فطلبُها لحظةَ
        // اكتمال الدفع يُنتج صمتاً في أوّل عمليّةٍ ثمّ نطقاً فيما بعدها —
        // وهو عطلٌ يظهر مرّةً ولا يتكرّر، فلا يُصدَّق من يبلغ عنه.
        initSpeech()

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, soundChannel)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "success" -> {
                        play(R.raw.pay_success, "تم الدفع بنجاح")
                        result.success(true)
                    }
                    "failure" -> {
                        play(R.raw.pay_failed, "فشل الدفع")
                        result.success(true)
                    }
                    // تقول الشاشةُ إن كان الجهازُ ينطق العربيّة أصلاً،
                    // فلا تَعِد بما لا يقع.
                    "canSpeakArabic" -> result.success(arabicSpeech)
                    else -> result.notImplemented()
                }
            }

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, secureChannel)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "enable" -> {
                        runOnUiThread {
                            window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
                        }
                        result.success(true)
                    }
                    "disable" -> {
                        runOnUiThread {
                            window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                        }
                        result.success(true)
                    }
                    else -> result.notImplemented()
                }
            }
    }
}
