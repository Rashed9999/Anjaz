import 'package:amyal_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:flutter/material.dart';

/// AMIAL-PROFILE-UI-001 — أيقونة صفّ في «حسابي».
///
/// كانت كل أيقونة صورة PNG موروثة من القالب الأصلي بلون مختلف (أخضر، أحمر،
/// برتقالي، أصفر…) فتبدو صفحة الحساب كقوس قزح بلا هوية. الآن مربّع واحد
/// بخلفية زرقاء خفيفة وأيقونة Material بلون البراند — شكل واحد لكل الصفوف.
///
/// نُبقي على الأصل صورةً إن لم نعرف ما يقابلها، فلا تنكسر أي شاشة.
class ProfileRowIcon extends StatelessWidget {
  final String? image;
  final IconData? iconData;
  const ProfileRowIcon({super.key, this.image, this.iconData});

  /// خريطة أصول القالب ← أيقونات Material.
  static const Map<String, IconData> _iconForAsset = {
    Images.editProfile: Icons.person_outline_rounded,
    Images.favoriteNumberIcon: Icons.star_border_rounded,
    Images.languageLogo: Icons.translate_rounded,
    Images.pinChangeLogo: Icons.lock_outline_rounded,
    Images.transactionLimitProfile: Icons.speed_rounded,
    Images.sendMoneyProfile: Icons.north_east_rounded,
    Images.requestProfile: Icons.request_page_outlined,
    Images.withdrawProfile: Icons.account_balance_outlined,
    Images.supportLogo: Icons.headset_mic_outlined,
    Images.questionLogo: Icons.help_outline_rounded,
    Images.aboutUs: Icons.info_outline_rounded,
    Images.terms: Icons.description_outlined,
    Images.privacy: Icons.privacy_tip_outlined,
    Images.logOut: Icons.logout_rounded,
    Images.selfDelete: Icons.delete_outline_rounded,
    Images.fingerprint: Icons.fingerprint_rounded,
    Images.changeTheme: Icons.dark_mode_outlined,
    Images.hideBalance: Icons.visibility_off_outlined,
    Images.twoFactorAuthentication: Icons.shield_outlined,
  };

  /// «تسجيل الخروج» و«حذف الحساب» إجراءان هدّامان — أحمر، وما عداهما أزرق.
  static const Set<String> _destructive = {Images.logOut, Images.selfDelete};

  @override
  Widget build(BuildContext context) {
    final IconData? icon = iconData ?? (image != null ? _iconForAsset[image] : null);

    if (icon == null) {
      // أصل غير معروف — نعرضه كما هو بدل إخفائه.
      return SizedBox(
        width: 38,
        height: 38,
        child: image != null
            ? CustomAssetImageWidget(image!, fit: BoxFit.contain)
            : const SizedBox.shrink(),
      );
    }

    final bool danger = image != null && _destructive.contains(image);
    final Color color = danger ? AmyalColors.red : AmyalColors.primary;

    return Container(
      width: 38,
      height: 38,
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(11),
      ),
      child: Icon(icon, color: color, size: 20),
    );
  }
}

class MenuItem extends StatelessWidget {
  const MenuItem({
    super.key,
    required this.image,
    required this.title,
    this.iconData,
  });
  final String? image;
  final String title;
  final IconData? iconData;

  @override
  Widget build(BuildContext context) {
    final bool danger =
        image != null && ProfileRowIcon._destructive.contains(image);

    return Padding(
      padding: const EdgeInsets.symmetric(
          vertical: Dimensions.paddingSizeSmall,
          horizontal: Dimensions.paddingSizeDefault),
      child: Row(children: [
        ProfileRowIcon(image: image, iconData: iconData),
        const SizedBox(width: Dimensions.paddingSizeDefault),

        Expanded(
          child: Text(
            title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: rubikRegular.copyWith(
              fontSize: Dimensions.fontSizeLarge - 1,
              color: danger ? AmyalColors.red : null,
            ),
          ),
        ),
        const SizedBox(width: Dimensions.paddingSizeSmall),

        Icon(Icons.arrow_forward_ios_rounded,
            size: Dimensions.radiusSizeDefault,
            color: AmyalColors.textMuted),
      ]),
    );
  }
}
