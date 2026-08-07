import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:geolocator/geolocator.dart';
import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/home/widgets/set_governorate_sheet.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/favorite_number/screens/amial_favorites_screen.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/features/requested_money/screens/payment_request_create_screen.dart';
import 'package:amial_pay/features/bill_pay/screens/bill_pay_providers_screen.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_request_screen.dart';
import 'package:amial_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amial_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amial_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amial_pay/features/setting/screens/profile_screen.dart';
import 'package:amial_pay/features/setting/screens/qr_code_download_or_share_screen.dart';
import 'package:amial_pay/features/me/screens/my_services_screen.dart';
import 'package:amial_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amial_pay/features/transaction_money/screens/amial_send_money_screen.dart';
import 'package:amial_pay/features/reports/screens/amial_reports_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_pay_screen.dart';
import 'package:amial_pay/common/widgets/amial_pattern_background.dart';

/// AMIAL-CUSTOMER-HOME-002 — الرئيسية بتصميم «المحفظة».
///
/// إعادة تصميم كاملة بلغة بصرية حديثة (مستوحاة من تصاميم المحافظ العالمية)
/// بهوية أميال (أزرق #053391 + أصفر #FECA1E):
///   • محفظة بطبقات وعمق: بطاقة تُطلّ من جيب المحفظة + الرصيد + استلام QR.
///   • «تحويل سريع»: المحوَّل لهم مؤخراً بنقرة واحدة (يعبّئ الرقم تلقائياً).
///   • «آخر العمليات»: بطاقات أفقية أنيقة (±مبلغ ملوّن).
///   • شبكة الخدمات كاملة كما هي (كل الوظائف محفوظة).
class AmialCustomerHomeScreen extends StatefulWidget {
  const AmialCustomerHomeScreen({super.key});

  @override
  State<AmialCustomerHomeScreen> createState() =>
      _AmialCustomerHomeScreenState();
}

class _AmialCustomerHomeScreenState extends State<AmialCustomerHomeScreen> {
  String _name = '';
  String _balance = '0';
  String _qrCode = ''; // SVG رمز العميل لاستقبال المال
  String _phone = '';
  bool _hideBalance = false;
  bool _loading = true;
  List<Map<String, dynamic>> _recent = [];

  // AMIAL-COVERAGE-001: تغطية الخدمة في محافظة المستخدم.
  // رسالة صادقة مشتقّة من وجود وكلاء وتجار فعليين، لا علَم سياسة: العميل
  // الذي انتقل إلى منطقة بلا تغطية لا يُحجَب — يستقبل ويحوّل كالمعتاد، وما
  // يتوقّف هو السحب والدفع لانعدام الوكيل والتاجر. قول ذلك صراحةً أصدق من
  // خطأ غامض عند الضغط، ويصحّح نفسه يوم يُعتمد وكيل هناك.
  String? _coverageNotice;
  bool _coverageIsGap = false;
  /// AMIAL-COVERAGE-002: الحساب بلا محافظة — اللافتة تصير زرّاً لا نصّاً.
  bool _needsGovernorate = false;

  /// AMIAL-COVERAGE-003: حاولنا التحديد التلقائي في هذه الجلسة.
  /// المحاولة مرّة واحدة: من رفض الإذن لا يُسأل في كل فتح للشاشة.
  bool _autoLocateTried = false;

  @override
  void initState() {
    super.initState();
    _load();
    // «تحويل سريع» + عدّاد الإشعارات — دفاعياً
    try {
      Get.find<ContactController>().getSuggestList(type: AppConstants.sendMoney);
    } catch (_) {}
    try {
      Get.find<NotificationsCenterController>().refreshUnreadCount();
    } catch (_) {}
  }

  Future<void> _load() async {
    if (mounted) setState(() => _loading = true);
    try {
      final api = Get.find<ApiClient>();
      final r = await api.getData('/api/v1/customer/get-customer');
      if (r.statusCode == 200 && r.body is Map) {
        final b = r.body as Map;
        final fn = (b['f_name'] ?? '').toString();
        final ln = (b['l_name'] ?? '').toString();
        _name = ('$fn $ln').trim();
        _balance = (b['balance'] ?? '0').toString();
        _qrCode = (b['qr_code'] ?? '').toString();
        _phone = (b['phone'] ?? '').toString();
      }
    } catch (_) {/* دفاعي: نُبقي الواجهة نظيفة */}

    try {
      final cov = await Get.find<ApiClient>()
          .getData('/api/v1/amial/service-coverage');
      final d = (cov.body is Map) ? cov.body['data'] : null;
      if (d is Map) {
        final agents = (d['agents'] ?? 0) as num;
        final merchants = (d['merchants'] ?? 0) as num;
        _needsGovernorate = d['needs_governorate'] == true;
        _coverageIsGap = agents == 0 && merchants == 0;
        // لا نُزعج من تغطيته كاملة — الرسالة تظهر عند النقص فقط.
        _coverageNotice = (_needsGovernorate || agents == 0 || merchants == 0)
            ? (d['notice'] as String?)
            : null;
      }
    } catch (_) {/* التغطية تحسينية — لا توقف الصفحة */}

    // AMIAL-COVERAGE-003: التطبيق يطلب الإذن بنفسه ويحدّد المحافظة.
    //
    // كانت اللافتة تطلب من العميل أن يختار محافظته من قائمة — وهذا عملُ
    // التطبيق لا عملُه. الهاتف يعرف موقعه، والنظام له طريقة معروفة لطلب
    // الإذن كما يُطلب إذن الكاميرا والاستديو. أن نُحيل المستخدم إلى قائمة
    // من اثنتين وعشرين محافظة بدل سؤالٍ واحد يجيب عنه بنقرة هو تحميلٌ له
    // ما تكفّل به الجهاز.
    //
    // القائمة اليدوية تبقى — لكن كمخرجٍ لمن رفض الإذن أو تعذّر تحديده، لا
    // كطريق أوّل.
    if (_needsGovernorate && !_autoLocateTried) {
      _autoLocateTried = true;
      await _autoDetectGovernorate();
    }

    try {
      // «الإيصالات» = السجلّ الموحّد لكل النشاط (تحويلات + خدمات أميال)
      final api = Get.find<ApiClient>();
      final r = await api.getData('/api/v1/amial/receipts');
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body as Map)['meta'];
        final list = meta is Map ? meta['items'] : null;
        if (list is List) {
          _recent = list
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .take(6)
              .toList();
        }
      }
    } catch (_) {/* دفاعي */}

    if (mounted) setState(() => _loading = false);
  }

  void _openReceiveQr() {
    if (_qrCode.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('جارٍ تجهيز رمز الاستلام...')),
      );
      _load();
      return;
    }
    Get.to(() => QrCodeDownloadOrShareScreen(qrCode: _qrCode, phoneNumber: _phone));
  }

  String get _balanceText {
    if (_hideBalance) return '••••••';
    final parts = _balance.split('.');
    final intPart = parts.first.replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (m) => ',',
    );
    return intPart;
  }

  @override
  Widget build(BuildContext context) {
    // AMIAL-PATTERN-001: نقشة هوية خفيفة خلف الترويسة وبطاقة الرصيد —
    // كانت الخلفية لوناً مسطّحاً بلا أي عمق أو انتماء بصري.
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: AmialPatternBackground(
        child: RefreshIndicator(
        color: AmialColors.primary,
        onRefresh: _load,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            _header(),
            Padding(
              // AMIAL-NAV-004: كانت 110 لتعويض مرور المحتوى تحت الشريط
              // العائم. صار Scaffold يحجز ارتفاع الشريط (extendBody: false)
              // فلم تعد الحشوة الزائدة لازمة — كانت تترك فراغاً أسفل الصفحة.
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _walletHero(),
                  if (_coverageNotice != null) ...[
                    const SizedBox(height: 14),
                    _coverageBanner(),
                  ],
                  const SizedBox(height: 22),
                  _quickSendSection(),
                  const SizedBox(height: 22),
                  _recentSection(),
                  const SizedBox(height: 22),
                  _sectionHeader('خدمات أميال',
                      trailing: '', onTrailing: null),
                  const SizedBox(height: 12),
                  _servicesGrid(),
                ],
              ),
            ),
          ],
        ),
      ),
      ),
    );
  }

  // ============ AMIAL-COVERAGE-001 — تغطية الخدمة ============
  //
  // نبرة الرسالة مقصودة: «لا يوجد وكلاء قريبون» لا «ممنوع في منطقتك».
  // الأولى حقيقة يفهمها العميل ويتصرّف بناءً عليها، والثانية تُشعره بأنه
  // مطرود من خدمة يملك فيها رصيداً — وهو غير ممنوع أصلاً: يستقبل ويحوّل.
  Widget _coverageBanner() {
    final color = _coverageIsGap
        ? const Color(0xFFCFA300)
        : const Color(0xFF5F6B7C);

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: _coverageIsGap ? const Color(0xFFFFF8E1) : Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.45)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(
              _needsGovernorate
                  ? Icons.place_outlined
                  : _coverageIsGap
                      ? Icons.storefront_outlined
                      : Icons.info_outline,
              size: 20,
              color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              _coverageNotice!,
              style: const TextStyle(
                  fontSize: 12.5, height: 1.7, color: Color(0xFF1A2433)),
            ),
          ),
        ]),

        // نصيحة بلا طريق ليست نصيحة. الزرّ هو الطريق.
        if (_needsGovernorate) ...[
          const SizedBox(height: 10),
          SizedBox(
            width: double.infinity,
            height: 40,
            child: ElevatedButton.icon(
              onPressed: _openGovernorateSheet,
              icon: const Icon(Icons.my_location_rounded, size: 18),
              label: const Text('اختيار المحافظة يدوياً'),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF053391),
                foregroundColor: Colors.white,
                textStyle: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w600),
              ),
            ),
          ),
        ],
      ]),
    );
  }

  /// يطلب إذن الموقع ويحدّد المحافظة بلا تدخّل من المستخدم.
  ///
  /// يفشل صامتاً: من رفض الإذن أو تعذّر تحديد موقعه يرى اللافتة والزرّ
  /// اليدوي — لا رسالة خطأ على شاشة فتحها ليرى رصيده.
  Future<void> _autoDetectGovernorate() async {
    try {
      var permission = await Geolocator.checkPermission();

      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission != LocationPermission.always &&
          permission != LocationPermission.whileInUse) {
        return;
      }

      if (!await Geolocator.isLocationServiceEnabled()) return;

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 15),
        ),
      );

      final api = Get.find<ApiClient>();
      final resolved = await api.postData('/api/v1/amial/geo/resolve-zone', {
        'latitude': position.latitude,
        'longitude': position.longitude,
      });

      final data = (resolved.body is Map) ? resolved.body['data'] : null;
      final code = (data is Map) ? data['governorate_code'] : null;
      if (code is! String || code.isEmpty) return;

      final saved = await api.postData(
          '/api/v1/amial/me/governorate', {'governorate_code': code});

      final ok = saved.statusCode == 200 &&
          saved.body is Map &&
          saved.body['success'] == true;
      if (!ok || !mounted) return;

      // نُعيد قراءة التغطية وحدها: إعادة _load كاملةً تُعيد استدعاء هذه
      // الدالّة نفسها وتُطيل الانتظار بلا فائدة.
      final cov = await api.getData('/api/v1/amial/service-coverage');
      final d = (cov.body is Map) ? cov.body['data'] : null;
      if (d is! Map || !mounted) return;

      final agents = (d['agents'] ?? 0) as num;
      final merchants = (d['merchants'] ?? 0) as num;
      setState(() {
        _needsGovernorate = false;
        _coverageIsGap = agents == 0 && merchants == 0;
        _coverageNotice = (agents == 0 || merchants == 0)
            ? (d['notice'] as String?)
            : null;
      });
    } catch (_) {
      // الفشل يترك اللافتة والزرّ اليدوي — وهو المخرج المقصود.
    }
  }

  Future<void> _openGovernorateSheet() async {
    final saved = await SetGovernorateSheet.open(context);
    if (!saved || !mounted) return;
    // إعادة التحميل تُبدّل الرسالة فوراً إلى عدد الوكلاء والتجّار — الأثر
    // المرئيّ هو ما يُثبت للمستخدم أن ما فعله وقع.
    await _load();
  }

  // ============ Header — أزرار دائرية بيضاء + عنوان مركزي ============
  Widget _header() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 54, 16, 14),
      child: Row(
        children: [
          _circleBtn(
            child: const Icon(Icons.person_outline_rounded,
                color: AmialColors.primary, size: 24),
            onTap: () => Get.to(() => const ProfileScreen()),
          ),
          const SizedBox(width: 8),
          // AMIAL-FAVORITES-001: المفضّلة في الترويسة لا في الشبكة — الشبكة
          // محكومة بتسعة مداخل بقاعدة مقصودة، وكسرُها لأجل مدخل عاشر يُفسد
          // ما بُنيت لأجله. والترويسة موضعها الطبيعي في المحافظ المهنية.
          _circleBtn(
            child: const Icon(Icons.star_border_rounded,
                color: AmialColors.primary, size: 24),
            onTap: () => Get.to(() => const AmialFavoritesScreen()),
          ),
          Expanded(
            child: Column(
              children: [
                const Text('أميال باي',
                    style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF1A2433))),
                Text(
                  _name.isEmpty ? 'محفظتي وعملياتي' : 'أهلاً، $_name',
                  style: const TextStyle(
                      fontSize: 12, color: AmialColors.textSecondary),
                ),
              ],
            ),
          ),
          _circleBtn(
            child: Obx(() {
              int unread = 0;
              try {
                unread = Get.find<NotificationsCenterController>().unreadCount.value;
              } catch (_) {}
              return Stack(clipBehavior: Clip.none, children: [
                const Icon(Icons.notifications_none_rounded,
                    color: AmialColors.primary, size: 24),
                if (unread > 0)
                  Positioned(
                    top: -2, left: -2,
                    child: Container(
                      width: 9, height: 9,
                      decoration: const BoxDecoration(
                          color: AmialColors.red, shape: BoxShape.circle),
                    ),
                  ),
              ]);
            }),
            onTap: () => Get.to(() => const NotificationsCenterScreen()),
          ),
        ],
      ),
    );
  }

  Widget _circleBtn({required Widget child, required VoidCallback onTap}) {
    return InkWell(
      onTap: onTap,
      customBorder: const CircleBorder(),
      child: Container(
        width: 46, height: 46,
        decoration: BoxDecoration(
          color: Colors.white,
          shape: BoxShape.circle,
          boxShadow: [
            BoxShadow(
                color: Colors.black.withValues(alpha: 0.06),
                blurRadius: 10, offset: const Offset(0, 3)),
          ],
        ),
        child: Center(child: child),
      ),
    );
  }

  // ============ محفظة بطبقات (بطاقة تُطلّ من الجيب) ============
  // AMIAL-HOME-005 — بطاقة الرصيد: تعرض ولا تتصرّف.
  //
  // كانت تحمل ثلاث وظائف فتفشل في الثلاث: بطاقة ثانية «مُطلّة» خلفها (وهي
  // مصدر التراكب البصري)، وزرّ استلام أصفر، وخمس أيقونات إجراءات بداخلها.
  // المحافظ المهنية تفصل: البطاقة للرصيد وحده، والإجراءات في شبكة تحتها.
  Widget _walletHero() {
    return Container(
      padding: const EdgeInsets.fromLTRB(22, 20, 22, 20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF1D4FB8), Color(0xFF053391)],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
        // كبسولة مستديرة بالكامل — لا مستطيل بزوايا خفيفة.
        borderRadius: BorderRadius.circular(28),
        boxShadow: [
          BoxShadow(
            color: AmialColors.primary.withValues(alpha: 0.28),
            blurRadius: 20,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Row(
            children: [
              // زرّ العين على الطرف — لا داخل صفّ أزرار
              InkWell(
                onTap: () => setState(() => _hideBalance = !_hideBalance),
                borderRadius: BorderRadius.circular(20),
                child: Padding(
                  padding: const EdgeInsets.all(6),
                  child: Icon(
                    _hideBalance
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                    color: Colors.white.withValues(alpha: 0.85),
                    size: 22,
                  ),
                ),
              ),
              const Spacer(),
              const Text('ريال يمني',
                  style: TextStyle(
                      color: Colors.white,
                      fontSize: 14,
                      fontWeight: FontWeight.w600)),
            ],
          ),
          const SizedBox(height: 10),
          // الرصيد: نقاط عند الإخفاء — لا نصّ «مخفي»
          _hideBalance
              ? Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: List.generate(
                    6,
                    (_) => Container(
                      width: 11,
                      height: 11,
                      margin: const EdgeInsets.symmetric(horizontal: 4),
                      decoration: const BoxDecoration(
                          color: Colors.white, shape: BoxShape.circle),
                    ),
                  ),
                )
              : FittedBox(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.baseline,
                    textBaseline: TextBaseline.alphabetic,
                    children: [
                      Text(
                        _loading ? '...' : _balanceText,
                        style: const TextStyle(
                            color: Colors.white,
                            fontSize: 34,
                            fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(width: 6),
                      const Text('ر.ي',
                          style: TextStyle(
                              color: AmialColors.yellow,
                              fontSize: 15,
                              fontWeight: FontWeight.w700)),
                    ],
                  ),
                ),
          const SizedBox(height: 8),
          Text(
            _name.isEmpty ? 'محفظتي' : _name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
                color: Colors.white.withValues(alpha: 0.72), fontSize: 12),
          ),
        ],
      ),
    );
  }

  // ============ تحويل سريع (المحوَّل لهم مؤخراً) ============
  static const List<Color> _avatarPalette = [
    Color(0xFF1D4FB8), Color(0xFF12694E), Color(0xFFB8860B),
    Color(0xFF7B1FA2), Color(0xFFC0392B), Color(0xFF00695C),
  ];

  Widget _quickSendSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sectionHeader('تحويل سريع',
            trailing: 'المزيد',
            onTrailing: () => Get.to(() => const AmialSendMoneyScreen())),
        const SizedBox(height: 12),
        GetBuilder<ContactController>(builder: (c) {
          List recent = const [];
          try {
            recent = c.sendMoneySuggestList;
          } catch (_) {}
          // AMIAL-HOME-005: عند غياب المستلمين كان يظهر زرّ «+» وحيد في صفّ
          // بعرض الشاشة، فيبدو القسم عاطلاً. الآن بطاقة دعوة كاملة العرض.
          if (recent.isEmpty) return _quickSendEmpty();
          return SizedBox(
            height: 86,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: [
                // زرّ إضافة (دائرة متقطّعة) — يفتح الإرسال
                _quickSendAdd(),
                const SizedBox(width: 14),
                ...recent.take(8).toList().asMap().entries.map((e) {
                  final m = e.value;
                  final name = ((m.name ?? '') as String).trim();
                  final phone = (m.phoneNumber ?? '') as String;
                  final color =
                      _avatarPalette[e.key % _avatarPalette.length];
                  return Padding(
                    padding: const EdgeInsets.only(left: 14),
                    child: InkWell(
                      onTap: () => Get.to(() =>
                          AmialSendMoneyScreen(initialPhone: phone)),
                      borderRadius: BorderRadius.circular(14),
                      child: Column(
                        children: [
                          Container(
                            width: 54, height: 54,
                            decoration: BoxDecoration(
                              color: color.withValues(alpha: 0.12),
                              shape: BoxShape.circle,
                              border: Border.all(
                                  color: color.withValues(alpha: 0.35)),
                            ),
                            child: Center(
                              child: Text(
                                name.isNotEmpty ? name[0] : '؟',
                                style: TextStyle(
                                    color: color,
                                    fontSize: 20,
                                    fontWeight: FontWeight.bold),
                              ),
                            ),
                          ),
                          const SizedBox(height: 6),
                          SizedBox(
                            width: 58,
                            child: Text(
                              name.isEmpty ? 'مستلِم' : name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              textAlign: TextAlign.center,
                              style: const TextStyle(fontSize: 11.5),
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                }),
              ],
            ),
          );
        }),
      ],
    );
  }

  /// حالة «لا مستلمين بعد» — بطاقة دعوة بعرض القسم بدل زرّ يتيم.
  Widget _quickSendEmpty() {
    return InkWell(
      onTap: () => Get.to(() => const AmialSendMoneyScreen()),
      borderRadius: BorderRadius.circular(18),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
              color: AmialColors.primary.withValues(alpha: 0.18)),
        ),
        child: Row(
          children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                color: AmialColors.primary.withValues(alpha: 0.08),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.person_add_alt_1_rounded,
                  color: AmialColors.primary, size: 22),
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('لا مستلمين بعد',
                      style: TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF1A2433))),
                  SizedBox(height: 2),
                  Text('أرسل أول تحويل ليظهر المستلِم هنا للمرّات القادمة',
                      style: TextStyle(
                          fontSize: 11.5, color: AmialColors.textSecondary)),
                ],
              ),
            ),
            const Icon(Icons.chevron_left_rounded,
                color: AmialColors.textMuted),
          ],
        ),
      ),
    );
  }

  Widget _quickSendAdd() {
    return InkWell(
      onTap: () => Get.to(() => const AmialSendMoneyScreen()),
      borderRadius: BorderRadius.circular(14),
      child: Column(
        children: [
          Container(
            width: 54, height: 54,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white,
              border: Border.all(
                  color: AmialColors.primary.withValues(alpha: 0.45)),
            ),
            child: const Icon(Icons.add_rounded,
                color: AmialColors.primary, size: 26),
          ),
          const SizedBox(height: 6),
          const Text('إرسال',
              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  // ============ آخر العمليات — بطاقات أفقية ============
  static const Map<String, String> _receiptLabels = {
    'send_money': 'تحويل أموال',
    'received_money': 'مبلغ مستلَم',
    'cash_out': 'سحب نقدي',
    'cash_in': 'إيداع نقدي',
    'add_money': 'إضافة رصيد',
    'withdraw': 'طلب سحب',
    'pay_merchant': 'دفع لتاجر',
    'pos_payment': 'دفع نقطة بيع',
    'qr_payment': 'دفع QR',
    'refund': 'استرجاع',
    'safe_payment_funded': 'دفع آمن (حجز)',
    'safe_payment_released': 'دفع آمن (تحرير)',
    'safe_payment_refunded': 'دفع آمن (استرجاع)',
    'family_fund_contribute': 'مساهمة عائلية',
    'donation': 'تبرع',
    'bill_payment': 'دفع فاتورة',
  };

  static const Map<String, IconData> _receiptIcons = {
    'send_money': Icons.arrow_outward_rounded,
    'received_money': Icons.call_received_rounded,
    'cash_out': Icons.local_atm_rounded,
    'cash_in': Icons.savings_outlined,
    'add_money': Icons.add_card_rounded,
    'withdraw': Icons.account_balance_outlined,
    'pay_merchant': Icons.storefront_outlined,
    'pos_payment': Icons.point_of_sale_rounded,
    'qr_payment': Icons.qr_code_2_rounded,
    'refund': Icons.replay_rounded,
    'donation': Icons.volunteer_activism_outlined,
    'bill_payment': Icons.receipt_long_rounded,
  };

  Widget _recentSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sectionHeader('آخر العمليات',
            trailing: 'عرض الكل',
            onTrailing: () => Get.to(() => const ReceiptsListScreen())),
        const SizedBox(height: 12),
        if (_loading)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(
                child: CircularProgressIndicator(color: AmialColors.primary)),
          )
        else if (_recent.isEmpty)
          Container(
            padding: const EdgeInsets.symmetric(vertical: 28),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
            ),
            child: const Column(children: [
              Icon(Icons.receipt_long_outlined,
                  size: 34, color: AmialColors.textMuted),
              SizedBox(height: 8),
              Text('لا توجد عمليات بعد',
                  style: TextStyle(color: AmialColors.textSecondary)),
            ]),
          )
        else
          // AMIAL-HOME-004: قائمة عمليات غنيّة داخل بطاقة بيضاء (كما في مراجع
          // المحافظ): أيقونة ملوّنة + العنوان + سطر وصف + المبلغ + التاريخ.
          // كانت بطاقات أفقية تُقصّ عند الحافة فلا يُقرأ نصفها.
          Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              boxShadow: [
                BoxShadow(
                    color: const Color(0xFF1A2433).withValues(alpha: 0.05),
                    blurRadius: 18,
                    offset: const Offset(0, 6)),
              ],
            ),
            child: Column(
              children: List.generate(
                _recent.length > 5 ? 5 : _recent.length,
                (i) => _recentRow(_recent[i], isLast: i == (_recent.length > 5 ? 4 : _recent.length - 1)),
              ),
            ),
          ),
      ],
    );
  }

  /// صفّ عملية غنيّ — أيقونة + عنوان + وصف + مبلغ + تاريخ.
  Widget _recentRow(Map<String, dynamic> t, {required bool isLast}) {
    final type = (t['receipt_type'] ?? '').toString();
    final title = _receiptLabels[type] ?? (type.isEmpty ? 'عملية' : type);
    final icon = _receiptIcons[type] ?? Icons.swap_horiz_rounded;
    final amountRaw = (t['amount'] ?? '').toString();
    final amount = amountRaw.contains('.')
        ? amountRaw.replaceAll(RegExp(r'\.?0+$'), '')
        : amountRaw;
    final isDebit = (t['direction'] ?? '').toString() == 'debit';
    final rawDate = (t['issued_at'] ?? t['created_at'] ?? '').toString();
    final dt = DateTime.tryParse(rawDate);
    String two(int n) => n.toString().padLeft(2, '0');
    final dateText = dt == null
        ? (rawDate.length >= 10 ? rawDate.substring(0, 10) : rawDate)
        : '${dt.year}/${two(dt.month)}/${two(dt.day)}  •  ${two(dt.hour)}:${two(dt.minute)}';
    final tone = isDebit ? const Color(0xFFDC2626) : const Color(0xFF16A34A);

    return InkWell(
      onTap: () => Get.to(() => const ReceiptsListScreen()),
      borderRadius: BorderRadius.circular(20),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        decoration: BoxDecoration(
          border: isLast
              ? null
              : const Border(
                  bottom: BorderSide(color: Color(0xFFF1F3F6), width: 1)),
        ),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: tone.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon, color: tone, size: 21),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700,
                          color: Color(0xFF1A2433))),
                  const SizedBox(height: 4),
                  // AMIAL-STATUS-CHIP-001: كان يُعرض رقم المرجع الخام
                  // (AMY-20260725-EHTE0K2W) في موضع الحالة — كود لا يعني
                  // للمستخدم شيئاً. المحافظ المهنية تضع هنا **حالة** العملية
                  // في كبسولة ملوّنة + التاريخ. المرجع مكانه شاشة التفاصيل.
                  Row(children: [
                    _statusChip(t),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Text(
                        dateText,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontSize: 10.5, color: AmialColors.textMuted),
                      ),
                    ),
                  ]),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                // AMIAL-RTL-SIGN-001
                AmialLtrNumber(
                  '${isDebit ? '-' : '+'}$amount ر.ي',
                  textAlign: TextAlign.end,
                  style: TextStyle(
                      fontSize: 13.5, fontWeight: FontWeight.bold, color: tone),
                ),
                const SizedBox(height: 3),
                Text(dateText.split('  •  ').first,
                    style: const TextStyle(
                        fontSize: 10, color: AmialColors.textMuted)),
              ],
            ),
          ],
        ),
      ),
    );
  }

  // ============ شبكة الخدمات (كل الوظائف محفوظة) ============
  Widget _servicesGrid() => _buildServicesGrid();

  // AMIAL-HOME-005 — شبكة الخدمات: لون واحد، تسعة مربّعات.
  //
  // كانت 15–16 خدمة في أربعة أعمدة بثمانية ألوان باستيل — اللون يتنافس مع
  // الأيقونة في شبكة متجاورة فيصير ضوضاء، والأيقونة 23px والنصّ 10.5px
  // فيصير مزدحماً غير مقروء.
  //
  // القاعدة المأخوذة من المحافظ المهنية: في *الشبكة* لون واحد وأيقونات
  // مفرّغة (اللون يُميّز في القوائم الرأسية لا في الشبكات)، وتسعة مدخل
  // كحدّ أقصى، وما زاد يذهب خلف «المزيد». الوظائف كلّها محفوظة — لم يُحذف
  // مدخل واحد، بل انتقل ما لا يُستعمل يومياً إلى شاشة «خدماتي».
  Widget _buildServicesGrid() {
    final services = <_Svc>[
      _Svc('إرسال أموال', Icons.north_east_rounded,
          () => Get.to(() => const AmialSendMoneyScreen())),
      _Svc('استلام', Icons.qr_code_2_rounded, _openReceiveQr),
      _Svc('طلب المال', Icons.request_page_outlined,
          () => Get.to(() => const PaymentRequestCreateScreen())),
      _Svc('ادفع لتاجر', Icons.storefront_outlined,
          () => Get.to(() => const MerchantPayScreen())),
      _Svc('دفع الفواتير', Icons.receipt_long_outlined,
          () => Get.to(() => const BillPayProvidersScreen())),
      _Svc('سحب نقدي', Icons.account_balance_outlined,
          () => Get.to(() => const WithdrawRequestScreen())),
      _Svc('الإيصالات', Icons.description_outlined,
          () => Get.to(() => const ReceiptsListScreen())),
      _Svc('التقارير', Icons.bar_chart_rounded,
          () => Get.to(() => const AmialReportsScreen())),
      _Svc('المزيد', Icons.grid_view_rounded,
          () => Get.to(() => const MyServicesScreen())),
    ];

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 18, 14, 18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: [
          BoxShadow(
              color: const Color(0xFF1A2433).withValues(alpha: 0.05),
              blurRadius: 18,
              offset: const Offset(0, 6)),
        ],
      ),
      child: GridView.count(
        crossAxisCount: 3,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 14,
        crossAxisSpacing: 12,
        childAspectRatio: 0.92,
        children: services.map(_serviceTile).toList(),
      ),
    );
  }

  /// مربّع خدمة: أيقونة مفرّغة بلون البراند على مربّع أبيض بظلّ ناعم.
  Widget _serviceTile(_Svc s) {
    return InkWell(
      onTap: s.onTap,
      borderRadius: BorderRadius.circular(18),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          boxShadow: [
            BoxShadow(
                color: const Color(0xFF1A2433).withValues(alpha: 0.07),
                blurRadius: 10,
                offset: const Offset(0, 3)),
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(s.icon, size: 30, color: AmialColors.primary),
            const SizedBox(height: 9),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: Text(s.label,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      fontSize: 12,
                      height: 1.2,
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF1A2433))),
            ),
          ],
        ),
      ),
    );
  }

  /// كبسولة حالة العملية.
  ///
  /// AMIAL-STATUS-CHIP-002 (إصلاح انحدار): كنتُ أقرأ `status` من الإيصال —
  /// وهو حقل يخصّ **توليد ملف PDF** (pending_pdf/pdf_generated/pdf_failed)
  /// لا حالة العملية. وبما أن التوليد صار عند الطلب، تبقى الإيصالات
  /// pending_pdf فظهرت «قيد المعالجة» على كل العمليات الناجحة.
  ///
  /// نفس الفخّ موثّق في `ReceiptModel.operationStatusLabel` — ووقعتُ فيه.
  /// الحقيقة: الإيصال لا يصدر إلا لعملية نُفّذت فعلاً، فالحالة «مكتملة»
  /// بصياغة تناسب النوع، و«ملغاة» إن أُبطل الإيصال.
  Widget _statusChip(Map<String, dynamic> t) {
    final voided = (t['status'] ?? '').toString() == 'voided' ||
        t['is_voided'] == true;
    final type = (t['receipt_type'] ?? '').toString();

    late final String label;
    late final Color color;

    if (voided) {
      label = 'ملغاة';
      color = AmialColors.textMuted;
    } else {
      color = const Color(0xFF16A34A);
      label = switch (type) {
        'send_money' => 'تم التحويل',
        'received_money' => 'تم الاستلام',
        'cash_in' => 'تم الإيداع',
        'cash_out' || 'withdraw' => 'تم السحب',
        'add_money' => 'تمت الإضافة',
        'pay_merchant' || 'pos_payment' || 'qr_payment' => 'تم الدفع',
        'bill_payment' => 'تم السداد',
        'refund' => 'تم الاسترجاع',
        'safe_payment_funded' => 'مجمّد بأمان',
        'safe_payment_released' => 'تم الإفراج',
        'safe_payment_refunded' => 'أُعيد المبلغ',
        'family_fund_contribute' => 'تمت المساهمة',
        'donation' => 'تم التبرع',
        _ => 'مكتملة',
      };
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(label,
          style: TextStyle(
              fontSize: 10, fontWeight: FontWeight.w700, color: color)),
    );
  }

  // ============ رأس قسم موحّد ============
  Widget _sectionHeader(String title,
      {required String trailing, VoidCallback? onTrailing}) {
    return Row(
      children: [
        Text(title,
            style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: Color(0xFF1A2433))),
        const Spacer(),
        if (trailing.isNotEmpty)
          InkWell(
            onTap: onTrailing,
            child: Text(trailing,
                style: const TextStyle(
                    color: AmialColors.primary,
                    fontWeight: FontWeight.w600,
                    fontSize: 12.5)),
          ),
      ],
    );
  }
}

class _Svc {
  final String label;
  final IconData icon;
  final VoidCallback onTap;
  final Color? color;
  _Svc(this.label, this.icon, this.onTap, {this.color});
}
