import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/controllers/share_controller.dart';
import 'package:amial_pay/common/widgets/custom_small_button_widget.dart';
import 'package:amial_pay/features/auth/domain/quick_receive_preferences.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/dimensions.dart';

/// AMIAL-QUICK-RECEIVE-002
///
/// رمز الحساب داخل الجلسة + مفتاح صريح للسماح بعرض رمز الاستلام قبل الدخول.
/// لا تُخزَّن كلمة مرور/PIN/Token، ولا نسمح بتفعيل الاستلام السريع من رقم الهاتف.
class ProfileQRCodeBottomSheetWidget extends StatefulWidget {
  const ProfileQRCodeBottomSheetWidget({super.key});

  @override
  State<ProfileQRCodeBottomSheetWidget> createState() =>
      _ProfileQRCodeBottomSheetWidgetState();
}

class _ProfileQRCodeBottomSheetWidgetState
    extends State<ProfileQRCodeBottomSheetWidget> {
  bool _quickReceiveEnabled = QuickReceivePreferences.isEnabled;
  bool _saving = false;

  Future<void> _toggleQuickReceive(bool next) async {
    if (_saving) return;
    final info = Get.find<ProfileController>().userInfo;

    if (!next) {
      setState(() => _saving = true);
      await QuickReceivePreferences.disable();
      if (!mounted) return;
      setState(() {
        _saving = false;
        _quickReceiveEnabled = false;
      });
      _show('تم إيقاف الاستلام السريع على هذا الجهاز');
      return;
    }

    final address = (info?.uniqueId ?? '').trim();
    if (address.isEmpty) {
      _show(
        'لا يمكن تفعيل الاستلام السريع الآن لأن معرّف الاستلام العام غير متاح. '
        'أعد تحميل بيانات الحساب ثم حاول مرة أخرى.',
        danger: true,
      );
      return;
    }

    final displayName = [info?.fName, info?.lName]
        .whereType<String>()
        .map((part) => part.trim())
        .where((part) => part.isNotEmpty)
        .join(' ');

    setState(() => _saving = true);
    final ok = await QuickReceivePreferences.enable(
      displayName: displayName,
      receiveAddress: address,
      ownerPhone: (info?.phone ?? '').trim(),
    );
    if (!mounted) return;
    setState(() {
      _saving = false;
      _quickReceiveEnabled = ok;
    });

    _show(
      ok
          ? 'تم تفعيل الاستلام السريع على هذا الجهاز'
          : 'تعذّر تفعيل الاستلام السريع',
      danger: !ok,
    );
  }

  void _show(String message, {bool danger = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: danger ? AmialColors.danger : AmialColors.info,
      ),
    );
  }

  String _mask(String value) {
    final v = value.trim();
    if (v.length <= 6) return '••••••';
    return '••••••${v.substring(v.length - 6)}';
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.sizeOf(context);

    return Container(
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: const BorderRadius.vertical(
          top: Radius.circular(AmialSpacing.radiusXl),
        ),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(
            AmialSpacing.screen,
            AmialSpacing.xs,
            AmialSpacing.screen,
            AmialSpacing.xl,
          ),
          child: Directionality(
            textDirection: TextDirection.rtl,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [
                Center(
                  child: Container(
                    width: AmialSpacing.xxl,
                    height: AmialSpacing.xxs,
                    decoration: BoxDecoration(
                      color: AmialColors.border,
                      borderRadius:
                          BorderRadius.circular(AmialSpacing.radiusXl),
                    ),
                  ),
                ),
                const SizedBox(height: AmialSpacing.lg),
                Text(
                  'رمز الاستلام',
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        color: AmialColors.textPrimary,
                        fontWeight: FontWeight.w900,
                      ),
                ),
                const SizedBox(height: AmialSpacing.xxs),
                Text(
                  'اعرض الرمز للمرسل لاستلام الأموال في حسابك.',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: AmialColors.textSecondary,
                      ),
                ),
                const SizedBox(height: AmialSpacing.lg),
                GetBuilder<ProfileController>(
                  builder: (controller) {
                    final info = controller.userInfo;
                    if (info == null) {
                      WidgetsBinding.instance.addPostFrameCallback((_) {
                        final c = Get.find<ProfileController>();
                        if (c.userInfo == null && !c.isLoading) {
                          c.getProfileData(isUpdate: true);
                        }
                      });
                      return const Padding(
                        padding: EdgeInsets.all(AmialSpacing.xl),
                        child: Center(
                          child: CircularProgressIndicator(
                            color: AmialColors.primary,
                          ),
                        ),
                      );
                    }

                    final uniqueId = (info.uniqueId ?? '').trim();
                    final phone = (info.phone ?? '').trim();
                    final payload = uniqueId.isNotEmpty ? uniqueId : phone;

                    if (payload.isEmpty) {
                      return _unavailable(controller);
                    }

                    return Column(
                      children: [
                        QrDisplayWidget(
                          data: payload,
                          size: size.width * 0.5,
                          caption: uniqueId.isNotEmpty
                              ? 'معرّف الحساب ${_mask(uniqueId)}'
                              : 'رمز الحساب داخل التطبيق',
                        ),
                        if (uniqueId.isEmpty) ...[
                          const SizedBox(height: AmialSpacing.sm),
                          Container(
                            padding: const EdgeInsets.all(AmialSpacing.sm),
                            decoration: BoxDecoration(
                              color: AmialColors.warningSurface,
                              borderRadius: BorderRadius.circular(
                                AmialSpacing.radiusMd,
                              ),
                            ),
                            child: const Text(
                              'يمكن عرض هذا الرمز أثناء تسجيل الدخول، لكن '
                              'الاستلام السريع قبل الدخول لن يُفعَّل حتى يتوفر '
                              'معرّف استلام عام للحساب.',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: AmialColors.warning,
                                height: 1.5,
                              ),
                            ),
                          ),
                        ],
                      ],
                    );
                  },
                ),
                const SizedBox(height: AmialSpacing.lg),
                _quickReceiveCard(context),
                const SizedBox(height: AmialSpacing.lg),
                Row(
                  children: [
                    Expanded(
                      child: CustomSmallButtonWidget(
                        text: 'download'.tr,
                        textSize: Dimensions.fontSizeLarge,
                        textColor: AmialColors.textPrimary,
                        backgroundColor: AmialColors.warningSurface,
                        onTap: () {
                          final info = Get.find<ProfileController>().userInfo;
                          Get.find<ShareController>().qrCodeDownloadAndShare(
                            qrCode: info?.qrCode ?? '',
                            phoneNumber: info?.phone ?? '',
                            isShare: false,
                          );
                        },
                      ),
                    ),
                    const SizedBox(width: AmialSpacing.sm),
                    Expanded(
                      child: CustomSmallButtonWidget(
                        text: 'share_QR_code'.tr,
                        textSize: Dimensions.fontSizeLarge,
                        textColor: AmialColors.cardSurface,
                        backgroundColor: AmialColors.primary,
                        onTap: () {
                          final info = Get.find<ProfileController>().userInfo;
                          Get.find<ShareController>().qrCodeDownloadAndShare(
                            qrCode: info?.qrCode ?? '',
                            phoneNumber: info?.phone ?? '',
                            isShare: true,
                          );
                        },
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _quickReceiveCard(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: _quickReceiveEnabled
            ? AmialColors.successSurface
            : AmialColors.background,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(
          color: _quickReceiveEnabled
              ? AmialColors.success.withValues(alpha: 0.28)
              : AmialColors.border,
        ),
      ),
      child: Row(
        children: [
          Container(
            width: AmialSpacing.xxl + AmialSpacing.xs,
            height: AmialSpacing.xxl + AmialSpacing.xs,
            decoration: BoxDecoration(
              color: _quickReceiveEnabled
                  ? AmialColors.success.withValues(alpha: 0.12)
                  : AmialColors.cardSurface,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: Icon(
              Icons.qr_code_2_rounded,
              color: _quickReceiveEnabled
                  ? AmialColors.success
                  : AmialColors.primary,
            ),
          ),
          const SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'الاستلام السريع على هذا الجهاز',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: AmialColors.textPrimary,
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: AmialSpacing.xxs),
                Text(
                  _quickReceiveEnabled
                      ? 'يمكن فتح رمز الاستلام دون تسجيل الدخول.'
                      : 'لن يظهر الرصيد أو الحركات؛ فقط رمز الاستلام العام.',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AmialColors.textSecondary,
                        height: 1.4,
                      ),
                ),
              ],
            ),
          ),
          const SizedBox(width: AmialSpacing.xs),
          if (_saving)
            const SizedBox(
              width: AmialSpacing.lg,
              height: AmialSpacing.lg,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          else
            Switch.adaptive(
              value: _quickReceiveEnabled,
              onChanged: _toggleQuickReceive,
            ),
        ],
      ),
    );
  }

  Widget _unavailable(ProfileController controller) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        color: AmialColors.warningSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      ),
      child: Column(
        children: [
          const Icon(
            Icons.qr_code_2_rounded,
            color: AmialColors.warning,
            size: AmialSpacing.xxl,
          ),
          const SizedBox(height: AmialSpacing.sm),
          const Text(
            'بيانات رمز الاستلام غير متاحة الآن',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: AmialColors.warning,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: AmialSpacing.xs),
          TextButton(
            onPressed: () => controller.getProfileData(
              reload: true,
              isUpdate: true,
            ),
            child: const Text('إعادة المحاولة'),
          ),
        ],
      ),
    );
  }
}
