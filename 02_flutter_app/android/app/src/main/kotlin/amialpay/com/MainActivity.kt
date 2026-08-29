package amialpay.com

import android.media.AudioAttributes
import android.media.MediaPlayer
import android.view.WindowManager
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

    private fun play(resId: Int) {
        // مشغّلٌ سابقٌ لم يُحرَّر يُسرّب الذاكرةَ ويقطع نفسَه عند ضغطتين
        // متتاليتين — فيُغلق قبل أن يُفتح غيرُه.
        player?.release()
        player = null

        val mp = MediaPlayer.create(applicationContext, resId) ?: return
        mp.setAudioAttributes(
            AudioAttributes.Builder()
                // نغمةُ نتيجةٍ لا موسيقى: تخرج على مسار التنبيهات فتُسمع
                // ولو كانت الوسائطُ مخفوضة.
                .setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build()
        )
        mp.setOnCompletionListener {
            it.release()
            if (player === it) player = null
        }
        player = mp
        mp.start()
    }

    override fun onDestroy() {
        player?.release()
        player = null
        super.onDestroy()
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, soundChannel)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "success" -> { play(R.raw.pay_success); result.success(true) }
                    "failure" -> { play(R.raw.pay_failed); result.success(true) }
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
