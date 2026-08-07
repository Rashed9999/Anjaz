import 'package:country_code_picker/country_code_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/code_picker_widget.dart';
import 'package:amial_pay/features/transaction_money/widgets/field_item_widget.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';


class CustomTextFieldWidget extends StatefulWidget {
  final String? hintText;
  final String? title;
  final TextEditingController? controller;
  final FocusNode? focusNode;
  final FocusNode? nextFocus;
  final TextInputType? inputType;
  final TextInputAction? inputAction;
  final bool isPassword;
  final bool? isShowBorder;
  final bool? isAutoFocus;
  final Function(String)? onSubmit;
  final bool? isEnabled;
  final int? maxLines;
  final bool? isShowSuffixIcon;
  final TextCapitalization? capitalization;
  final Function(String text)? onChanged;
  final String? countryDialCode;
  final String? suffixIconUrl;
  final Function(CountryCode countryCode)? onCountryChanged;
  final String? Function(String? )? onValidate;
  final EdgeInsets? contentPadding;
  final double? borderRadius, letterSpacing, fontSize;

  /// AMIAL-DS-003: لون تعبئة الحقل — الافتراضي رمادي أميال الفاتح.
  final Color? fillColor;
  final bool isRequired;
  final String? prefixIcon;
  final Function? onSuffixTap;
  final int? maxLength;
  final Color? suffixIconColor;
  final Widget? suffixIcon;
  final Color? textColor;
  final Color? enabledBorderColor;
  final Color? focusedBorderColor;
  final bool isMaxLimitVisible;

  const CustomTextFieldWidget(
      {super.key,  this.hintText = '',
        this.controller,
        this.focusNode,
        this.nextFocus,
        this.isEnabled = true,
        this.inputType = TextInputType.text,
        this.inputAction = TextInputAction.next,
        this.maxLines = 1,
        this.isShowSuffixIcon = false,
        this.onSubmit,
        this.capitalization = TextCapitalization.none,
        this.isPassword = false,
        this.isShowBorder,
        this.isAutoFocus = false,
        this.countryDialCode,
        this.onCountryChanged,
        this.suffixIconUrl,
        this.onChanged,
        this.onValidate,
        this.title,
        this.contentPadding,
        this.borderRadius,
        this.fillColor,
        this.isRequired = true,
        this.prefixIcon,
        this.onSuffixTap,
        this.letterSpacing,
        this.fontSize,
        this.maxLength,
        this.suffixIconColor,
        this.suffixIcon,
        this.textColor,
        this.enabledBorderColor,
        this.focusedBorderColor,
        this.isMaxLimitVisible = false,
      });

  @override
  CustomTextFieldState createState() => CustomTextFieldState();
}

class CustomTextFieldState extends State<CustomTextFieldWidget> {

  bool _obscureText = true;

  @override
  Widget build(BuildContext context) {
    return Focus(
      onFocusChange:  (v){},
      child: TextFormField(
        maxLines: widget.maxLines,
        controller: widget.controller,
        focusNode: widget.focusNode,
        style:  rubikRegular.copyWith(
          fontSize: widget.fontSize ?? Dimensions.fontSizeDefault, color: widget.textColor ?? Theme.of(context).textTheme.bodyLarge!.color,
          letterSpacing: widget.letterSpacing,
        ),
        textInputAction: widget.inputAction,
        keyboardType: widget.isPassword ? TextInputType.number : widget.inputType,

        cursorColor: Theme.of(context).hintColor,
        textCapitalization: widget.capitalization!,
        enabled: widget.isEnabled,
        autofocus: widget.isAutoFocus!,
        maxLength: widget.maxLength,
        autofillHints: widget.inputType == TextInputType.name ? [AutofillHints.name]
            : widget.inputType == TextInputType.emailAddress ? [AutofillHints.email]
            : widget.inputType == TextInputType.phone ? [AutofillHints.telephoneNumber]
            : widget.inputType == TextInputType.streetAddress ? [AutofillHints.fullStreetAddress]
            : widget.inputType == TextInputType.url ? [AutofillHints.url]
            : widget.inputType == TextInputType.visiblePassword ? [AutofillHints.password] : null,
        obscureText: widget.isPassword ? _obscureText : false,
        inputFormatters: widget.inputType == TextInputType.phone
            ? <TextInputFormatter>[FilteringTextInputFormatter.allow(RegExp('[0-9+]'))]
            : widget.isPassword ? <TextInputFormatter>[FilteringTextInputFormatter.digitsOnly]
            : widget.inputType == TextInputType.datetime ? [DateInputFormatter()] : null,

        decoration: InputDecoration(
          counterText: widget.isMaxLimitVisible ? null : "",
          prefixIcon: widget.prefixIcon != null ? Padding(
            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault,vertical: 5),
            child: Image.asset(widget.prefixIcon!, width: 20,height: 20, color: Theme.of(context).colorScheme.primary.withValues(alpha:0.4),),
          ) : widget.countryDialCode != null ? CodePickerWidget(
            borderRadius: widget.borderRadius,
            onChanged: widget.onCountryChanged,
            initialSelection: widget.countryDialCode,
            favorite: [widget.countryDialCode ?? ""],
            showDropDownButton: true,
            padding: EdgeInsets.zero,
            showFlagMain: true,
            dialogSize: Size(Get.width, Get.height*0.6),
            dialogBackgroundColor: Theme.of(context).cardColor,
            barrierColor: Get.isDarkMode?Colors.black.withValues(alpha:0.4):null,
            textStyle: rubikRegular.copyWith(
              fontSize: Dimensions.fontSizeDefault, color: Theme.of(context).textTheme.bodyLarge!.color,
            ),
          ): null,
          contentPadding: widget.contentPadding ?? const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
          // AMIAL-DS-003 — لغة حقول أميال داخل widget القالب المشترك.
          //
          // بدل إعادة كتابة 11 شاشة، نُغيّر الافتراضيات هنا فترثها كلّها:
          // تعبئة رمادية فاتحة بلا حدّ ظاهر، وحدّ كحلي عند التركيز فقط.
          // كان الحقل مؤطّراً برمادي داكن دائم بنصف قطر 12 — مظهر القالب.
          // من مرّر لوناً صريحاً (borderRadius/enabledBorderColor) يُحترم كما هو.
          focusedBorder : OutlineInputBorder(
            borderRadius: BorderRadius.circular(widget.borderRadius ?? 14),
            borderSide: BorderSide(
                color: widget.focusedBorderColor ?? AmialColors.primary, width: 1.5),
          ),

          enabledBorder : OutlineInputBorder(
            borderRadius: BorderRadius.circular(widget.borderRadius ?? 14),
            borderSide: BorderSide(
              color: widget.enabledBorderColor ?? Colors.transparent, width: 1.5,
            ),
          ),

          errorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(widget.borderRadius ?? 14),
            borderSide: const BorderSide(color: AmialColors.red, width: 1.5),
          ),

          focusedErrorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(widget.borderRadius ?? 14),
            borderSide: const BorderSide(color: AmialColors.red, width: 1.5),
          ),

          filled: true,

          fillColor: widget.fillColor ?? AmialFormTokens.fieldFill,

          hintText: widget.hintText,
          hintStyle: rubikRegular.copyWith(
              fontSize: Dimensions.fontSizeDefault,
              color: Theme.of(context).textTheme.bodySmall?.color?.withValues(alpha:0.5),
              letterSpacing: 0
          ),

          suffixIconConstraints: widget.isPassword ? const BoxConstraints(
            maxHeight: 20 ,
          ): null,

          suffixIcon: widget.isPassword ? Padding(
            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
            child: IconButton(
              alignment: widget.focusNode?.hasFocus == true || widget.controller!.text.isNotEmpty ? Alignment.bottomRight : Alignment.centerRight,
              padding: EdgeInsets.zero,
              icon: Icon(_obscureText ? Icons.visibility_off : Icons.visibility,size:20, color: widget.suffixIconColor ?? Theme.of(context).hintColor.withValues(alpha:0.3)),
              onPressed: _toggle,
            ),
          ) : widget.suffixIcon,
        ),
        onFieldSubmitted: (text) => widget.nextFocus != null ?
        FocusScope.of(context).requestFocus(widget.nextFocus) :
        widget.onSubmit != null ? widget.onSubmit!(text) : null,
        onChanged: widget.onChanged,
        validator: widget.onValidate,

      ),
    );
  }

  void _toggle() {
    if (!mounted) return; // AMIAL-FIX-006
    setState(() {
      _obscureText = !_obscureText;
    });
  }
}