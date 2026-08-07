import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';

/// AMIAL-WEB-ONLY-PORTALS-001 — لوحتا الإدارة والوكيل خرجتا من التطبيق.
///
/// **ولماذا شاشةٌ بدل الحذف الصامت؟**
///
/// لأنّ `RoleRouter` بلا حالةٍ لـ`admin`/`agent` يسقط إلى `default` —
/// وهي شاشة العميل. فحسابُ وكيلٍ يدخل التطبيق يهبط في محفظة عميلٍ فارغة،
/// لا رسالةَ ولا خطأ: يظنّ أنّ رصيده ضاع. والصمت هنا أسوأ من المنع.
///
/// (القاعدة السابعة: «غير معروف» ليس صفراً. وحسابٌ لا تُخدَم لوحتُه هنا
/// يُقال له ذلك صراحةً مع البديل، لا يُعرض له صفرٌ عن نفسه.)
///
/// فتبقى نقطةُ هبوطٍ واحدة تقول ثلاثة: أين تعمل لوحتك، وكيف تفتحها،
/// وكيف تخرج من هنا.
class WebPortalNoticeScreen extends StatelessWidget {
  /// `admin` أو `agent` — يحدّد العنوان والنصّ.
  final String role;

  const WebPortalNoticeScreen({super.key, required this.role});

  bool get _isAdmin => role == 'admin';

  String get _title => _isAdmin ? 'لوحة الإدارة' : 'بوّابة شركات الصرافة';

  /// عنوان البوّابة على المتصفّح. **عامٌّ عمداً** — الخلط بين المسارين
  /// يُرسل كلاً منهما إلى بوّابةٍ ترفضه بلا رسالةٍ مفهومة، فيُفحص نصّاً
  /// في `web_only_portals_guard_test.dart` لا بالنظر إليه.
  String get portalUrl => _isAdmin
      ? '${AppConstants.productionDomain}/admin/auth/login'
      : '${AppConstants.productionDomain}/agent/login';

  String get _who => _isAdmin
      ? 'حسابك حساب إدارة المنصّة.'
      : 'حسابك حساب شركة صرافة أو موظّف فيها.';

  String get _whatFor => _isAdmin
      ? 'الإدارة والتقارير والإعدادات كلّها على المتصفّح.'
      : 'الشبّاك والورديّات والخزنة والتسويات كلّها على المتصفّح.';

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(_title),
        automaticallyImplyLeading: false,
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 24),
              Icon(
                _isAdmin ? Icons.admin_panel_settings : Icons.storefront,
                size: 78,
                color: AmialColors.primary,
              ),
              const SizedBox(height: 20),
              Text(
                'هذه اللوحة تعمل على المتصفّح',
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 20, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 10),
              Text(
                '$_who $_whatFor',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey.shade700, height: 1.6),
              ),
              const SizedBox(height: 24),

              // العنوان ظاهرٌ نصّاً — لا خلف زرٍّ وحده. فمن فتح التطبيق على
              // هاتفٍ ويعمل على حاسوبٍ آخر يحتاج أن يقرأه لا أن يضغطه.
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: SelectableText(
                  portalUrl,
                  textAlign: TextAlign.center,
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: AmialColors.primary,
                  ),
                ),
              ),
              const SizedBox(height: 16),

              ElevatedButton.icon(
                onPressed: _open,
                icon: const Icon(Icons.open_in_browser),
                label: const Text('فتح في المتصفّح'),
                style: ElevatedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 15),
                  backgroundColor: AmialColors.primary,
                  foregroundColor: Colors.white,
                ),
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: _copy,
                icon: const Icon(Icons.copy_all_outlined),
                label: const Text('نسخ الرابط'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 15),
                ),
              ),

              const SizedBox(height: 28),
              // مخرجٌ صريح. شاشةٌ بلا خروجٍ تحبس من دخل بحسابٍ خاطئ على
              // جهازه، فيُعيد التثبيت ليخرج.
              TextButton.icon(
                onPressed: _logout,
                icon: const Icon(Icons.logout, size: 18),
                label: const Text('تسجيل الخروج'),
                style: TextButton.styleFrom(foregroundColor: Colors.red),
              ),
              const SizedBox(height: 20),
              Text(
                'تطبيق أميال باي للعملاء والتجّار.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey.shade500, fontSize: 12),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _open() async {
    final uri = Uri.parse(portalUrl);
    // externalApplication: المتصفّح الحقيقيّ لا WebView داخل التطبيق —
    // فجلسة البوّابة كوكي متصفّحٍ، وWebView يفقدها عند إغلاق الشاشة.
    final ok =
        await launchUrl(uri, mode: LaunchMode.externalApplication).catchError(
      (_) => false,
    );
    if (!ok) {
      // لا نصمت: نسخُ الرابط بديلٌ يعمل حين لا متصفّحَ افتراضيّاً.
      await _copy();
    }
  }

  Future<void> _copy() async {
    await Clipboard.setData(ClipboardData(text: portalUrl));
    Get.snackbar('تمّ النسخ', 'الصق الرابط في المتصفّح',
        snackPosition: SnackPosition.BOTTOM);
  }

  void _logout() {
    // نمرّ بمتحكّم الدخول نفسه لا بمسحٍ يدويّ — كي يُبطَل الرمز على الخادم
    // وتُنسى الهويّة في تقارير الأعطال كما في أيّ خروجٍ آخر (هو من ينقل
    // إلى شاشة البدء عند النجاح).
    Get.find<AuthController>().logout();
  }
}
