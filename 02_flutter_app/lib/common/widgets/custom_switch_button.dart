import 'package:flutter/material.dart';

class CustomSwitchButton extends StatefulWidget {
  final void Function(bool isToggled) onToggled;
  final bool isToggled;
  const CustomSwitchButton({super.key, required this.onToggled, this.isToggled = false});

  @override
  State<CustomSwitchButton> createState() => _CustomSwitchButtonState();
}

class _CustomSwitchButtonState extends State<CustomSwitchButton> {
  bool? isToggled;
  double size = 25;
  double innerPadding = 0;

  @override
  void initState() {
    innerPadding = size / 10;
    super.initState();
    isToggled = widget.isToggled;
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () {
        setState(() => isToggled = !widget.isToggled);
        widget.onToggled(widget.isToggled);
      },
      onPanEnd: (b) {
        setState(() => isToggled = !widget.isToggled);
        widget.onToggled(widget.isToggled);
      },
      child: AnimatedContainer(
        height: size,
        width: size * 2,
        padding: EdgeInsets.all(innerPadding),
        alignment: isToggled! ?  Alignment.centerRight : Alignment.centerLeft,
        duration: const Duration(milliseconds: 300),
        curve: Curves.easeOut,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(50),
          color: isToggled! ? Theme.of(context).primaryColor : Colors.grey.shade300,
        ),
        child: Container(
          width: size - innerPadding * 2,
          height: size - innerPadding * 2,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(100),
            color:  Colors.white,
          ),
        ),
      ),
    );
  }
}