/// AMIAL-CONTACT-001 — معلومات التواصل الرسمية.
///
/// **هام:** استبدل هذه القيم بأرقام أميال باي الحقيقية قبل الإطلاق.
/// كل الشاشات تقرأ من هنا — التغيير في مكان واحد يطبّق في كل الواجهات.
class ContactConstants {
  ContactConstants._();

  // ============ أرقام الهاتف ============
  /// رقم WhatsApp الرسمي (بدون + أو 00)
  /// مثال يمني: 967700000000
  static const String whatsappNumber = '967777000000';

  /// رقم الهاتف للاتصال (مع كود الدولة)
  static const String phoneNumber = '+967777000000';

  // ============ Email ============
  static const String supportEmail = 'support@amialpay.com';
  static const String salesEmail = 'sales@amialpay.com';

  // ============ روابط جاهزة ============

  /// رابط WhatsApp مع رسالة ترقية مُعدّة مسبقاً.
  static String upgradeWhatsAppUrl({required String planLabel, required int priceSar}) {
    final msg = Uri.encodeComponent(
      'مرحباً 👋\n\n'
      'أرغب في ترقية اشتراكي في أميال باي إلى:\n'
      '🎯 الخطّة: $planLabel\n'
      '💰 السعر: $priceSar ر.ي / شهرياً\n\n'
      'يرجى تزويدي بطرق الدفع المتاحة.'
    );
    return 'https://wa.me/$whatsappNumber?text=$msg';
  }

  /// رابط WhatsApp للدعم العام.
  static String supportWhatsAppUrl() {
    final msg = Uri.encodeComponent(
      'مرحباً 👋\n\nأحتاج مساعدة في تطبيق أميال باي.'
    );
    return 'https://wa.me/$whatsappNumber?text=$msg';
  }

  /// رابط tel: للاتصال المباشر.
  static String phoneUrl() => 'tel:$phoneNumber';

  /// رابط mailto:.
  static String mailUrl(String subject) =>
      'mailto:$supportEmail?subject=${Uri.encodeComponent(subject)}';
}
