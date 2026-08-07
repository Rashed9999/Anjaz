import 'package:flutter/material.dart';
import 'package:get/get_state_manager/src/simple/get_state.dart';
import 'package:amial_pay/features/home/controllers/menu_controller.dart';
import 'package:amial_pay/util/dimensions.dart';

class BottomItemWidget extends StatelessWidget {
  final String icon;
  final String name;
  final int? selectIndex;
  final VoidCallback? onTap;
  const BottomItemWidget({super.key, required this.icon, required this.name, this.selectIndex, this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(onTap: onTap, child: GetBuilder<MenuItemController>(
        builder: (menuItemController) {
          return Column(mainAxisSize: MainAxisSize.min, children: [
            SizedBox(
              height: 20,
              width: Dimensions.fontSizeExtraLarge,
              child: Image.asset(
                icon, fit: BoxFit.contain,
                color: menuItemController.currentTabIndex == selectIndex
                    ? Theme.of(context).primaryColor.withValues(alpha: 0.8) : Theme.of(context).hintColor.withValues(alpha: 0.4),

              ),
            ),
            const SizedBox(height: 6.0),

            Text(name, style: TextStyle(
              color: menuItemController.currentTabIndex == selectIndex
                  ? Theme.of(context).primaryColor.withValues(alpha: 0.8) : Theme.of(context).hintColor.withValues(alpha: 0.4),


              fontSize: Dimensions.fontSizeSmall,
              fontWeight: FontWeight.w400,
            ))

          ]);
        }
    ));
  }
}
