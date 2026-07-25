package com.amyalpay.app

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

    private val secureChannel = "amyal_pay/secure_screen"

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

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
