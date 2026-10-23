import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// قناة الدعم الحقيقية + مركز الحلول الفورية للعميل.
/// تقرأ من إعداد الدعم المخصص، لا من بيانات شركة قديمة أو فارغة في config،
/// وتعرض سبباً واضحاً إن تعذر فتح تطبيق الاتصال، بالإضافة إلى دليل حلول
/// ذاتي تفاعلي لأهم استفسارات الأموال والحسابات.
class SupportScreen extends StatefulWidget {
  const SupportScreen({super.key});

  @override
  State<SupportScreen> createState() => _SupportScreenState();
}

class _SupportScreenState extends State<SupportScreen> {
  String _phone = '';
  String _whatsapp = '';
  String _email = '';
  bool _loading = true;
  bool _failed = false;
  String _searchQuery = '';
  String _selectedCategory = 'all';

  static const List<Map<String, String>> _categories = [
    {'code': 'all', 'label': 'الكل'},
    {'code': 'transfers', 'label': 'التحويلات والحوالات'},
    {'code': 'holds', 'label': 'الأرصدة والحجوزات'},
    {'code': 'security', 'label': 'الأمان وPIN والأجهزة'},
    {'code': 'kyc', 'label': 'التوثيق والحدود'},
    {'code': 'services', 'label': 'الدفع الآمن والتجار'},
  ];

  static const List<Map<String, String>> _quickAnswers = [
    {
      'category': 'transfers',
      'q': 'حوّلت مبلغاً لرقم خاطئ، كيف أسترجع أموالي فوراً؟',
      'a': 'إذا كانت الحوالة لا تزال داخل نافذة التراجع يمكنك إلغاؤها فوراً من سجل العمليات. وإذا اكتملت، تواصل مع الدعم برقم العملية لفتح «بلاغ تحويل لمستلم خاطئ»؛ سيحجز النظام المبلغ المتبقي في محفظة المستلم احترازياً، وما صُرف منه يُسجَّل ذمّة تُقتطع تلقائياً فور دخول أي رصيد لحسابه.',
    },
    {
      'category': 'transfers',
      'q': 'الحوالة تظهر «معلّقة / قيد التسليم»، متى تصل للمستلم؟',
      'a': 'تمر الحوالات بنافذة تراجع قصيرة لحمايتك من أخطاء الإدخال ثم تُسلَّم تلقائياً. لا تقم بإعادة إرسال الحوالة مرة ثانية؛ يمكنك تزويد الدعم برقم الحوالة للتحقق من عدّاد التسليم.',
    },
    {
      'category': 'holds',
      'q': 'لماذا يختلف «الرصيد المتاح» عن «الرصيد الكلي» في محفظتي؟',
      'a': 'الفرق يمثل مبالغ محجوزة مؤقتاً لعمليات جارية (حوالة في نافذة التراجع، صفقة دفع آمن لم تُستلم بعد، أو حجز احترازي لدعوى قائمة). يظهر لدى موظف الدعم تفصيل كل مبلغ محجوز وموعد الإفراج التلقائي عنه.',
    },
    {
      'category': 'holds',
      'q': 'تم خصم مبلغ تلقائياً فور شحني للمحفظة، ما السبب؟',
      'a': 'يحدث ذلك فقط إذا كانت على الحساب ذمّة مستحقة ناتجة عن استلام حوالة سابقة بالخطأ صدر بها قرار استرداد رسمي من فريق النزاعات.',
    },
    {
      'category': 'security',
      'q': 'فقدت هاتفي أو سُرق، كيف أحمي أموالي دون تعطيل مصالحي؟',
      'a': 'تواصل مع الدعم فوراً لحظر الجهاز المسروق وحده وإنهاء جلساته النشطة، أو لطلب تجميد مؤقت للحساب. وإذا فقدت الشريحة يمكنك تقديم طلب «استعادة الحساب» الموثّق.',
    },
    {
      'category': 'security',
      'q': 'قُفل الرمز السري (PIN) بعد محاولات خاطئة أو نسيته، ماذا أفعل؟',
      'a': 'يمكنك استعادة الرمز السري عبر بريدك الإلكتروني الموثّق من شاشة الأمان، أو طلب إعادة تعيين PIN عبر مركز الدعم (يخضع لموافقة ثنائية لحماية حسابك).',
    },
    {
      'category': 'kyc',
      'q': 'كيف أعرف المتبقي من حدي اليومي والشهري وكيف أرفع السقف؟',
      'a': 'تعتمد حدود التحويل والرصيد على فئة توثيق الهوية (KYC Tier). يمكنك رفع سقف حسابك باستكمال توثيق الهوية الوطنية وإثبات السكن من مركز التوثيق في التطبيق.',
    },
    {
      'category': 'services',
      'q': 'كيف يحميني «الدفع الآمن» عند الشراء من تاجر أو فرد؟',
      'a': 'يحجز نظام الدفع الآمن المبلغ في حساب ضمان مستقل ولا يُفرج عنه للبائع إلا بعد استلامك للبضاعة وإعطائه «كود التسليم» المكون من أرقام خاصة بك، وفي حال الخلاف يمكنك فتح نزاع وإرفاق الصور.',
    },
    {
      'category': 'services',
      'q': 'انقطع الإنترنت أثناء الدفع لتاجر ولا أعرف هل خُصم المبلغ أم لا',
      'a': 'لا تقم بإعادة الدفع مباشرة! يحتفظ التطبيق برقم تتبّع تشخيصي للمحاولة يمنع الخصم المكرر، ويمكن لموظف الدعم التحقق فوراً بالرقم التشخيصي هل وُلد قيد مالي أم لا.',
    },
  ];

  @override
  void initState() {
    super.initState();
    _loadContact();
  }

  Future<void> _loadContact() async {
    setState(() {
      _loading = true;
      _failed = false;
    });

    final config = Get.find<SplashController>().configModel;
    var phone = (config?.companyPhone ?? '').trim();
    var email = (config?.companyEmail ?? '').trim();
    var whatsapp = '';

    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/support-contact');
      final body = response.body;
      final meta = body is Map ? body['meta'] : null;
      final contact = meta is Map ? meta['contact'] : null;
      if (response.statusCode == 200 && contact is Map) {
        final serverPhone = contact['phone_number']?.toString().trim() ?? '';
        final serverEmail = contact['support_email']?.toString().trim() ?? '';
        phone = serverPhone.isEmpty ? phone : serverPhone;
        email = serverEmail.isEmpty ? email : serverEmail;
        whatsapp = contact['whatsapp_number']?.toString().trim() ?? '';
      } else {
        _failed = true;
      }
    } catch (_) {
      _failed = true;
    }

    if (!mounted) return;
    setState(() {
      _phone = phone;
      _email = email;
      _whatsapp = whatsapp;
      _loading = false;
    });
  }

  Future<void> _open(Uri uri, String label) async {
    final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (opened || !mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('تعذر فتح $label على هذا الجهاز'),
        backgroundColor: AmialColors.red,
      ),
    );
  }

  List<Map<String, String>> get _filteredAnswers {
    final q = _searchQuery.trim().toLowerCase();
    return _quickAnswers.where((item) {
      final matchesCat = _selectedCategory == 'all' ||
          item['category'] == _selectedCategory;
      if (!matchesCat) return false;
      if (q.isEmpty) return true;
      final hay = '${item['q']} ${item['a']}'.toLowerCase();
      return hay.contains(q);
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final answers = _filteredAnswers;
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('الدعم والمساعدة')),
      body: RefreshIndicator(
        onRefresh: _loadContact,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            const SizedBox(height: 12),
            Center(
              child: Container(
                width: 88,
                height: 88,
                decoration: BoxDecoration(
                  color: AmialColors.primary.withValues(alpha: 0.08),
                  shape: BoxShape.circle,
                ),
                child: const Icon(
                  Icons.support_agent_rounded,
                  size: 44,
                  color: AmialColors.primary,
                ),
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'كيف يمكننا مساعدتك؟',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 6),
            const Text(
              'اختر وسيلة التواصل المناسبة أو ابحث في مركز الحلول الفورية.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AmialColors.textSecondary),
            ),
            const SizedBox(height: 24),
            if (_loading)
              const Center(child: CircularProgressIndicator())
            else ...[
              if (_whatsapp.isNotEmpty)
                _contactCard(
                  icon: Icons.chat_rounded,
                  label: 'واتساب الدعم',
                  value: _whatsapp,
                  filled: true,
                  onTap: () => _open(
                    Uri.parse(
                      'https://wa.me/' +
                          _whatsapp.replaceAll(RegExp(r'[^0-9]'), ''),
                    ),
                    'واتساب',
                  ),
                ),
              if (_whatsapp.isNotEmpty) const SizedBox(height: 12),
              if (_phone.isNotEmpty)
                _contactCard(
                  icon: Icons.phone_rounded,
                  label: 'اتصال بالدعم',
                  value: _phone,
                  filled: _whatsapp.isEmpty,
                  onTap: () => _open(
                    Uri(scheme: 'tel', path: _phone),
                    'تطبيق الاتصال',
                  ),
                ),
              if (_phone.isNotEmpty && _email.isNotEmpty)
                const SizedBox(height: 12),
              if (_email.isNotEmpty)
                _contactCard(
                  icon: Icons.email_rounded,
                  label: 'بريد الدعم',
                  value: _email,
                  filled: false,
                  onTap: () => _open(
                    Uri(scheme: 'mailto', path: _email),
                    'البريد',
                  ),
                ),
              if (_phone.isEmpty && _email.isEmpty && _whatsapp.isEmpty)
                _emptyContact(),
              if (_failed) ...[
                const SizedBox(height: 18),
                TextButton.icon(
                  onPressed: _loadContact,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('إعادة المحاولة'),
                ),
              ],
            ],
            const SizedBox(height: 28),
            const Text(
              'مركز الحلول الفورية (الأموال والحسابات)',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 10),
            TextField(
              onChanged: (v) => setState(() => _searchQuery = v),
              decoration: InputDecoration(
                hintText: 'ابحث عن مشكلتك: حوالة، رقم خاطئ، رصيد محجوز، رمز سري…',
                prefixIcon: const Icon(Icons.search_rounded),
                filled: true,
                fillColor: Colors.white,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 12,
                ),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: const BorderSide(color: AmialColors.border),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: const BorderSide(color: AmialColors.border),
                ),
              ),
            ),
            const SizedBox(height: 10),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: _categories.map((cat) {
                  final selected = _selectedCategory == cat['code'];
                  return Padding(
                    padding: const EdgeInsets.only(left: 8),
                    child: ChoiceChip(
                      label: Text(cat['label']!),
                      selected: selected,
                      onSelected: (_) =>
                          setState(() => _selectedCategory = cat['code']!),
                    ),
                  );
                }).toList(),
              ),
            ),
            const SizedBox(height: 12),
            ...answers.map(
              (item) => Container(
                margin: const EdgeInsets.only(bottom: 10),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AmialColors.border),
                ),
                child: ExpansionTile(
                  title: Text(
                    item['q']!,
                    style: const TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: AmialColors.textPrimary,
                    ),
                  ),
                  childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                  children: [
                    Text(
                      item['a']!,
                      style: const TextStyle(
                        fontSize: 13,
                        height: 1.5,
                        color: AmialColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _emptyContact() => Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: const Text(
          'بيانات الدعم غير متاحة حالياً. أعد المحاولة لاحقاً.',
          textAlign: TextAlign.center,
          style: TextStyle(color: AmialColors.textSecondary),
        ),
      );

  Widget _contactCard({
    required IconData icon,
    required String label,
    required String value,
    required bool filled,
    required Future<void> Function() onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: filled ? AmialColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: filled ? AmialColors.primary : AmialColors.border,
          ),
        ),
        child: Row(children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: filled
                  ? Colors.white.withValues(alpha: 0.16)
                  : AmialColors.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: filled ? Colors.white : AmialColors.primary),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: filled ? Colors.white : AmialColors.textPrimary,
                  ),
                ),
                Text(
                  value,
                  textDirection: TextDirection.ltr,
                  style: TextStyle(
                    fontSize: 12,
                    color: filled ? Colors.white70 : AmialColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
          Icon(
            Icons.chevron_left_rounded,
            color: filled ? Colors.white70 : AmialColors.textMuted,
          ),
        ]),
      ),
    );
  }
}
