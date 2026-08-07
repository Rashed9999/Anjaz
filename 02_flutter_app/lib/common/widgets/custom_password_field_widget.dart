
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:amial_pay/util/dimensions.dart';

class CustomPasswordFieldWidget extends StatefulWidget {
  final bool? isShowSuffixIcon;
  final bool? isPassword;
  final bool isIcon;
  final Function? onSuffixTap;
  final String? suffixIconUrl;
  final String? hint;
  final TextAlign textAlign;
  final TextEditingController? controller;
  final double fontSize,letterSpacing;
  final FocusNode? nextFocus,focusNode;
  final TextInputAction textInputAction;
  final bool showMaxLength;
  final TextStyle? hintTextStyle;
  final OutlineInputBorder? enabledBorder;
  final TextStyle? textStyle;
  final Color? suffixIconColor;
  const CustomPasswordFieldWidget({super.key,
    this.isShowSuffixIcon,
    this.isPassword,
    this.isIcon = false,
    this.onSuffixTap,
    this.suffixIconUrl,
    this.hint,
    this.textAlign = TextAlign.start,
    this.controller,
    this.fontSize = 17.0,
    this.letterSpacing = 2.0,
    this.focusNode,
    this.nextFocus,
    this.textInputAction = TextInputAction.next, this.showMaxLength = true, this.hintTextStyle, this.enabledBorder, this.textStyle, this.suffixIconColor,
  });

  @override
  State<CustomPasswordFieldWidget> createState() => _CustomPasswordFieldWidgetState();
}

class _CustomPasswordFieldWidgetState extends State<CustomPasswordFieldWidget> {
  bool _obscureText = true;
  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: widget.controller,
      focusNode: widget.focusNode,
      obscureText: widget.isPassword! ? _obscureText : false,
      cursorColor: Theme.of(context).textTheme.titleLarge!.color,
      textAlign: widget.textAlign,
      keyboardType: TextInputType.number,
      textInputAction: widget.textInputAction,
      maxLength: 4,
      onSubmitted: (text) => widget.nextFocus != null ? FocusScope.of(context).requestFocus(widget.nextFocus)
          : null,
      inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.digitsOnly],

      style: widget.textStyle ?? TextStyle(
        color: Theme.of(context).textTheme.bodyLarge!.color,
        fontSize: widget.fontSize,
        fontWeight: FontWeight.w500,
        letterSpacing: widget.letterSpacing,
      ),
      decoration: InputDecoration(
        counterText: widget.showMaxLength ? null : '',
        contentPadding: const EdgeInsets.symmetric(vertical: 15, horizontal: 22),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
          borderSide: BorderSide(
            color: Theme.of(context).textTheme.titleLarge!.color!,
            width: 2,
          ),
        ),
        enabledBorder: widget.enabledBorder ?? OutlineInputBorder(
          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
          borderSide: BorderSide(
            color: Theme.of(context).primaryColor,
            width: 1,
          ),
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
          borderSide: BorderSide(
            color: Theme.of(context).textTheme.bodyLarge!.color!.withValues(alpha: 0.7),
            width: 1,
          ),
        ),
        hintText: widget.hint,
        hintStyle: widget.hintTextStyle ?? const TextStyle(
          fontWeight: FontWeight.normal,
          fontSize: 12,
          letterSpacing: 1.5,
        ),
        suffixIcon: widget.isShowSuffixIcon!
            ? widget.isPassword!
                ? IconButton(
                    icon: Icon(
                        _obscureText ? Icons.visibility_off : Icons.visibility,
                        color: widget.suffixIconColor ?? Theme.of(context).textTheme.titleLarge!.color,size: 18),
                    onPressed: _toggle)
                : widget.isIcon
                    ? IconButton(
                        onPressed: widget.onSuffixTap as void Function()?,
                        icon: Image.asset(
                          widget.suffixIconUrl!,
                          width: 15,
                          height: 15,
                          color: widget.suffixIconColor ?? Theme.of(context).textTheme.titleLarge!.color,
                        ),
                      )
                    : null
            : null,
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
