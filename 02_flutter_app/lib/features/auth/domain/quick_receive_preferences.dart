import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// AMIAL-QUICK-RECEIVE-002
///
/// إعدادٌ محليٌّ صريح لميزة «الاستلام السريع» على هذا الجهاز.
///
/// الميزة لا تخزّن كلمة مرور ولا PIN ولا رمز جلسة. ما يُحفظ هو عنوان استقبال
/// عام (account_number) + اسم عرض مختصر فقط، لأن الشاشة نفسها مصممة لتُفتح قبل
/// تسجيل الدخول. لا نستخدم رقم الهاتف كحمولة QR؛ إن لم يأتِ account_number
/// من ملف العميل فلا تُفعَّل الميزة أصلاً.
class QuickReceivePreferences {
  QuickReceivePreferences._();

  static const _enabledKey = 'amial_quick_receive_enabled';
  static const _addressKey = 'amial_quick_receive_address';
  static const _nameKey = 'amial_quick_receive_name';
  static const _ownerPhoneKey = 'amial_quick_receive_owner_phone';

  static SharedPreferences? get _prefs {
    try {
      return Get.find<SharedPreferences>();
    } catch (_) {
      return null;
    }
  }

  static bool get isEnabled {
    final prefs = _prefs;
    if (prefs == null) return false;
    return (prefs.getBool(_enabledKey) ?? false) &&
        (prefs.getString(_addressKey) ?? '').trim().isNotEmpty;
  }

  static ({String displayName, String receiveAddress, String ownerPhone})?
      read() {
    final prefs = _prefs;
    if (prefs == null) return null;

    final enabled = prefs.getBool(_enabledKey) ?? false;
    final address = (prefs.getString(_addressKey) ?? '').trim();
    if (!enabled || address.isEmpty) return null;

    return (
      displayName: (prefs.getString(_nameKey) ?? '').trim(),
      receiveAddress: address,
      ownerPhone: (prefs.getString(_ownerPhoneKey) ?? '').trim(),
    );
  }

  static Future<bool> enable({
    required String displayName,
    required String receiveAddress,
    required String ownerPhone,
  }) async {
    final prefs = _prefs;
    final address = receiveAddress.trim();

    // AMIAL-QUICK-RECEIVE-003 — **ومالكٌ فارغٌ يُبطل حارسَ الحساب القديم.**
    //
    // `disableIfOwnedByAnother` تخرج صامتةً إن كان المالكُ المخزَّن
    // فارغاً — فتفعيلٌ بلا هاتفٍ يُنتج تفضيلاً **لا يُنظَّف أبداً**
    // مهما تبدّل صاحبُ الجهاز. فيُشترط هنا لا هناك: الفحصُ عند الكتابة
    // أضمنُ من الفحص عند القراءة.
    if (prefs == null || address.isEmpty || _digits(ownerPhone).isEmpty) {
      return false;
    }

    try {
      await prefs.setString(_addressKey, address);
      await prefs.setString(_nameKey, displayName.trim());
      await prefs.setString(_ownerPhoneKey, ownerPhone.trim());
      await prefs.setBool(_enabledKey, true);
      return true;
    } catch (_) {
      return false;
    }
  }

  static Future<void> disable() async {
    final prefs = _prefs;
    if (prefs == null) return;

    try {
      await prefs.setBool(_enabledKey, false);
      await prefs.remove(_addressKey);
      await prefs.remove(_nameKey);
      await prefs.remove(_ownerPhoneKey);
    } catch (_) {
      // Fail closed: read() still requires enabled=true + non-empty address.
    }
  }

  /// الأرقامُ وحدَها — فالرقمُ الواحد يصل بأربع صيغ.
  static String _digits(String v) => v.replaceAll(RegExp(r'[^0-9]'), '');

  /// آخرُ تسعةِ أرقام — وهي الجزءُ الوطنيُّ في اليمن، الثابتُ بين الصيغ.
  static String _nationalTail(String v) {
    final d = _digits(v);

    return d.length <= 9 ? d : d.substring(d.length - 9);
  }

  /// يمنع عرض عنوان حسابٍ سابق بعد أن يصبح الجهاز لحساب عميل آخر.
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// **ولا تُقارَن الأرقامُ حرفيّاً.** المخزَّنُ يأتي من الملفّ الشخصيّ
  /// (`967777100001`)، والداخلُ يأتي رمزَ اتّصالٍ + رقماً (`+967` و
  /// `777100001`). فمطابقةٌ حرفيّةٌ **تُطفئ الميزةَ على صاحبها في كلّ
  /// دخول** — حاجزٌ يشلّ عملاً سليماً، ويُطفَأ عند أوّل شكوى.
  ///
  /// وهو العطلُ المسجَّل في المشروع مرّتين: «مقارنةٌ حرفيّةٌ تجعل الحساب
  /// يعمل من شاشةٍ ويُرفض من أخرى».
  ///
  /// **وحين يتعذّر التعرّف يُطفأ لا يُترك.** رقمٌ داخلٌ لا يُقرأ منه شيء
  /// يعني أنّنا لا نعرف صاحبَ الجهاز — و«غير معروف» هنا ليس «هو نفسُه».
  /// ══════════════════════════════════════════════════════════════════
  static Future<void> disableIfOwnedByAnother(String currentPhone) async {
    final data = read();
    if (data == null) return;

    if (!isSameOwner(storedOwner: data.ownerPhone, currentPhone: currentPhone)) {
      await disable();
    }
  }

  /// AMIAL-QUICK-RECEIVE-004 — **مقارنةُ المالك، في موضعٍ واحد.**
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// كانت المقارنةُ مكتوبةً مرّتين: هنا **بآخر تسعة أرقام**، وفي
  /// `quick_receive_screen.dart` **حرفيّةً** (`owner != lastPhone`).
  ///
  /// والمصدران لا يتّفقان أبداً في الاستعمال الحقيقيّ:
  ///
  ///     المخزَّن  ← الملفّ الشخصيّ    : 967777100001
  ///     الأخير   ← ما كتبه في الدخول : 777100001
  ///
  /// فالمقارنةُ الحرفيّةُ تُخرج «مختلفان» **في كلّ مرّة**، فتردّ الشاشةُ
  /// `null` وتعرض حالةَ «مُعطَّل» — **وقد فُعّلت الميزةُ فعلاً**. أي أنّ
  /// البطاقةَ في شاشة الدخول تَعِد، والشاشةُ تنكر.
  ///
  /// **وهو حاجزٌ يشلّ عملاً سليماً، وذاك أسوأ من ثغرة**: لا خطأَ في أيّ
  /// سجلّ، ولا شيءَ يُشتكى منه إلّا «لا تعمل».
  ///
  /// **و«لا أعرف» ليس «هو نفسُه»**: رقمٌ لا يُقرأ منه شيءٌ يعني أنّنا لا
  /// نعرف صاحبَ الجهاز، فيُغلق لا يُفتح. (القاعدة السابعة.)
  /// ══════════════════════════════════════════════════════════════════
  static bool isSameOwner({
    required String storedOwner,
    required String currentPhone,
  }) {
    final owner = _nationalTail(storedOwner);
    final current = _nationalTail(currentPhone);

    if (owner.isEmpty || current.isEmpty) return false;

    return owner == current;
  }
}
