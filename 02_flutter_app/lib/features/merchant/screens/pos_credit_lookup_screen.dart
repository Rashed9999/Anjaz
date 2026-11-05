import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/merchant/controllers/customer_credit_controller.dart';
import 'package:amial_pay/features/merchant/screens/credit_customer_statement_screen.dart';

/// A cashier searches by phone and opens a collection-only customer view.
/// Full debt statements and merchant-wide totals remain owner-only.
class PosCreditLookupScreen extends StatefulWidget {
  const PosCreditLookupScreen({super.key});

  @override
  State<PosCreditLookupScreen> createState() => _PosCreditLookupScreenState();
}

class _PosCreditLookupScreenState extends State<PosCreditLookupScreen> {
  final _phone = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  Future<void> _lookup() async {
    final phone = _phone.text.trim();
    if (phone.isEmpty || _busy) return;
    setState(() { _busy = true; _error = null; });
    try {
      final response = await Get.find<CustomerCreditController>()
          .repo.lookupByPhone(phone);
      if (!mounted) return;
      if (response.statusCode != 200 || response.body is! Map ||
          response.body['success'] != true) {
        setState(() => _error = response.body is Map
            ? response.body['message']?.toString() ?? 'credit_collect_pos_failed'.tr
            : 'credit_collect_pos_failed'.tr);
        return;
      }
      final result = Map<String, dynamic>.from(response.body['meta'] as Map);
      if (result['found'] != true || result['account_id'] == null) {
        setState(() => _error = 'credit_collect_pos_no_account'.tr);
        return;
      }
      if (result['is_active'] != true) {
        setState(() => _error = 'credit_collect_pos_inactive'.tr);
        return;
      }
      final customer = <String, dynamic>{
        'id': result['account_id'],
        'customer_name': result['customer_name'],
        'customer_phone': phone,
        'current_balance': result['current_balance'],
        'credit_limit': result['credit_limit'],
      };
      await Get.to(() => CreditCustomerStatementScreen(
        customer: customer, collectionOnly: true,
      ));
    } catch (_) {
      if (mounted) setState(() => _error = 'credit_collect_pos_network'.tr);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: Text('credit_collect_pos_title'.tr)),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const Icon(Icons.request_quote_outlined,
              size: 60, color: AmialColors.primary),
          const SizedBox(height: 18),
          Text('credit_collect_pos_hint'.tr,
              style: const TextStyle(fontSize: 15)),
          const SizedBox(height: 22),
          TextField(
            controller: _phone,
            enabled: !_busy,
            keyboardType: TextInputType.phone,
            onSubmitted: (_) => _lookup(),
            decoration: InputDecoration(
              labelText: 'credit_collect_pos_phone'.tr,
              prefixIcon: const Icon(Icons.phone_outlined),
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          if (_error != null)
            Padding(padding: const EdgeInsets.symmetric(vertical: 10),
              child: Text(_error!, style: const TextStyle(color: AmialColors.danger))),
          FilledButton.icon(
            onPressed: _busy ? null : _lookup,
            icon: _busy
                ? const SizedBox(width: 18, height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.search),
            label: Text('credit_collect_pos_lookup'.tr),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          ),
        ],
      ),
    );
  }
}
