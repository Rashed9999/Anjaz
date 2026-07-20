import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/async_state_view.dart';

/// AMIAL-GIFT-CARDS-001 — «بطاقات هديتي» (جهة العميل).
///
/// يعرض بطاقات الهدايا الصادرة لهاتف العميل وأرصدتها. يستخدمها لدى التاجر بكودها.
class MyGiftCardsScreen extends StatefulWidget {
  const MyGiftCardsScreen({super.key});

  @override
  State<MyGiftCardsScreen> createState() => _MyGiftCardsScreenState();
}

class _MyGiftCardsScreenState extends State<MyGiftCardsScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _cards = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/me/gift-cards');
      if (r.statusCode == 200 && r.body is Map) {
        _cards = (((r.body['meta'] ?? {})['cards'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList();
      } else {
        _error = 'تعذّر تحميل البطاقات';
      }
    } catch (_) {
      _error = 'تعذّر الاتصال — تحقّق من الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(title: const Text('بطاقات هديتي'), backgroundColor: AmyalColors.primary, foregroundColor: Colors.white),
      body: AsyncStateView(
        loading: _loading,
        error: _error,
        isEmpty: _cards.isEmpty,
        emptyText: 'لا بطاقات هدايا',
        emptyIcon: Icons.card_giftcard,
        onRetry: _load,
        child: ListView(padding: const EdgeInsets.all(14), children: _cards.map(_card).toList()),
      ),
    );
  }

  Widget _card(Map<String, dynamic> c) {
    final active = c['status'] == 'active';
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: active ? [AmyalColors.primary, const Color(0xFF1D4FB8)] : [AmyalColors.textSecondary, AmyalColors.textMuted],
          begin: Alignment.topLeft, end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Row(children: [Icon(Icons.card_giftcard, color: Colors.white), SizedBox(width: 8),
          Text('بطاقة هدية', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold))]),
        const SizedBox(height: 16),
        Text('${c['balance']} ر.ي',
            style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        Row(children: [
          SelectableText('${c['code']}', textDirection: TextDirection.ltr,
              style: const TextStyle(color: Colors.white70, fontFamily: 'monospace', fontSize: 14)),
          const SizedBox(width: 8),
          InkWell(
            onTap: () {
              Clipboard.setData(ClipboardData(text: '${c['code']}'));
              ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('نُسخ الكود'), backgroundColor: Color(0xFF2E7D32)));
            },
            child: const Icon(Icons.copy, color: Colors.white70, size: 16),
          ),
        ]),
        if (!active)
          Padding(padding: const EdgeInsets.only(top: 6),
              child: Text({'void': 'ملغاة', 'depleted': 'مستنفدة'}[c['status']] ?? '${c['status']}',
                  style: const TextStyle(color: Colors.white, fontSize: 11))),
        if (c['expires_at'] != null)
          Padding(padding: const EdgeInsets.only(top: 6),
              child: Text('تنتهي: ${c['expires_at']}', style: const TextStyle(color: Colors.white70, fontSize: 11))),
      ]),
    );
  }
}
