import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **الأسعارُ المقترَحة**.
///
/// **والمقترِحُ لا يعتمد**: الخادم يرفضه، وهذه الشاشة لا ترسم زرَّ
/// الاعتماد لمن لا يملكه.
class RetailPricesScreen extends StatefulWidget {
  const RetailPricesScreen({super.key});

  @override
  State<RetailPricesScreen> createState() => _RetailPricesScreenState();
}

class _RetailPricesScreenState extends State<RetailPricesScreen> {
  RetailVerticalController get c => Get.find<RetailVerticalController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadPendingPrices());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الأسعار المقترَحة'),
        backgroundColor: AmialColors.background,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: c.loadPendingPrices,
        child: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.pendingPrices.isEmpty,
              emptyTitle: 'لا أسعار تنتظر الاعتماد',
              emptyHint: 'السعر نسخةٌ لها سريانٌ واعتماد — ولا يُكتب فوقه.',
              emptyIcon: Icons.sell_outlined,
              onRetry: c.loadPendingPrices,
              grantedBy: 'مالك المتجر',
              child: ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: c.pendingPrices.length,
                itemBuilder: (_, i) {
                  final p = c.pendingPrices[i];
                  return Card(
                    color: AmialColors.cardSurface,
                    margin: const EdgeInsets.only(bottom: 8),
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${p['product']}',
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 14)),
                          const SizedBox(height: 4),
                          Text('${p['price']} ر.ي'
                              '${p['offer_price'] != null ? ' · عرض ${p['offer_price']}' : ''}',
                              style: const TextStyle(fontSize: 13)),
                          if (p['reason'] != null)
                            Text('${p['reason']}',
                                style: const TextStyle(
                                    fontSize: 11, color: AmialColors.textMuted)),
                          const SizedBox(height: 10),
                          VerticalActionButton(
                            c: c,
                            permission: RetailVerticalController.pPriceApprove,
                            label: 'اعتماد السعر',
                            icon: Icons.check_rounded,
                            color: Colors.green,
                            onPressed: () async {
                              final ok = await c.approvePrice(p['id'] as int);
                              if (!mounted) return;
                              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                                content: Text(ok ? 'اعتُمد السعر' : c.lastError.value),
                                backgroundColor: ok ? Colors.green : AmialColors.red,
                              ));
                              if (ok) await c.loadPendingPrices();
                            },
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
            )),
      ),
    );
  }
}
