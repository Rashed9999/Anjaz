import 'package:flutter/widgets.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/language/controllers/localization_controller.dart';

/// AMIAL-I18N-004 — **اتّجاهُ الشاشة يُسأل، ولا يُفرَض.**
///
/// ══════════════════════════════════════════════════════════════════════
/// كان `TextDirection.rtl` مكتوباً في **ثلاثةَ عشرَ موضعاً**، فالشاشةُ
/// تبقى من اليمين **حتّى لو تُرجم نصُّها كلُّه**: عنوانٌ إنجليزيٌّ يبدأ
/// من اليمين، وسهمُ الرجوع في الجهة الخطأ، وأعمدةٌ معكوسة.
///
/// **والآليّةُ كانت موجودةً ولا تُسأل**: `LocalizationController.isLtr`
/// مضبوطةٌ صحيحةً منذ البداية (`_locale.languageCode != 'ar'`). فالعطلُ
/// ليس غيابَ مصدرٍ للحقيقة — بل ثلاثةَ عشرَ موضعاً لا يقرؤه.
///
/// **ولمَ لا يُحذَف السطرُ فحسب:** الحذفُ يجعل الاتّجاه يرثه المحيطُ —
/// وبعضُ هذه المواضع أوراقٌ سفليّةٌ وحوارات، وسياقُها قد يكون خارج
/// `MaterialApp`. فيبقى التصريحُ ويتحوّل من رقمٍ ثابتٍ إلى سؤال.
///
/// **ويُسأل بحذر**: هذه الأدواتُ تُبنى في اختباراتٍ بلا حقنٍ حيّ، ونداءُ
/// `Get.find` في شجرةٍ بلا مُتحكِّمٍ يُسقط الشاشةَ كاملةً — وهو بعينه
/// ما وقع في `ShiftStatusTile` هذه الجلسة. فالغيابُ يسقط على العربيّة،
/// وهي لغةُ المنتج الأولى.
///
/// يظهر في : التطبيق ← كلُّ شاشةٍ من الثلاثةَ عشرَ موضعاً (الدخول ·
/// القبضُ السريع · رمزُ الملفّ · الإيصالُ الحراريّ · لوحةُ الموظّف ·
/// محفظةُ التاجر · قوقعةُ التاجر · ختمُ البناء). وفي لوحة الإدارة: لا.
///
/// @see \Tests direction_follows_the_language_test.dart
TextDirection appTextDirection() {
  if (!Get.isRegistered<LocalizationController>()) {
    return TextDirection.rtl;
  }

  return Get.find<LocalizationController>().isLtr
      ? TextDirection.ltr
      : TextDirection.rtl;
}
