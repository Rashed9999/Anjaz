import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:scroll_to_index/scroll_to_index.dart';
import 'package:amial_pay/features/setting/controllers/faq_controller.dart';
import 'package:amial_pay/features/setting/widgets/custom_fag_expansion_tile.dart';
import 'package:amial_pay/features/setting/widgets/empty_fag_widget.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/setting/widgets/faq_shimmer.dart';

class FaqScreen extends StatefulWidget {
  final String title;
  const FaqScreen({super.key, required this.title});

  @override
  State<FaqScreen> createState() => _FaqScreenState();

}

class _FaqScreenState extends State<FaqScreen> {
  AutoScrollController? menuScrollController;
  FaqController controller   = Get.find();

  @override
  void initState() {
    super.initState();

    _loadData();
  }

  Future<void> _loadData() async {
    menuScrollController = AutoScrollController(
      viewportBoundaryGetter: () => Rect.fromLTRB(0, 0, 0, MediaQuery.of(context).padding.bottom),
      axis: Axis.horizontal,
    );
    Get.find<FaqController>().getFaqCategoryList(false, isUpdate: false);
    Get.find<FaqController>().getFaqList(1, isFirst: true, reload: true);
  }

  @override
  void dispose() {
    menuScrollController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {

    return Scaffold(
      appBar: AppBar(title: Text(widget.title), backgroundColor: AmialColors.primary, foregroundColor: Colors.white, elevation: 0),
      body: GetBuilder<FaqController>(builder: (faqController) {
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

          const SizedBox(height: Dimensions.paddingSizeDefault),

          faqController.faqCategoryList !=null && faqController.faqCategoryList!.length > 1 ?
          SizedBox(
            height: 50,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                 controller: menuScrollController,
                physics: const ClampingScrollPhysics(),
                child: Row(children: faqController.faqCategoryList!.map((element){

                  int? index = faqController.faqCategoryList?.indexOf(element);

                  return  GestureDetector(
                    onTap: () async {
                      faqController.updateSelectedFaqIndex(index: index);
                      await menuScrollController!.scrollToIndex(
                          index ?? 0, preferPosition: AutoScrollPosition.middle,
                          duration: const Duration(milliseconds: 700)
                      );
                      await menuScrollController!.highlight( index ?? 0);
                    },
                    child: AutoScrollTag(
                      controller: menuScrollController!,
                      key: ValueKey(index),
                      index: index ?? 0,
                      child: Container(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(Dimensions.radiusSizeLarge),
                          color: faqController.selectedFagIndex == index ? Theme.of(context).primaryColor : Theme.of(context).cardColor,
                          border: Border.all(color: faqController.selectedFagIndex == index ? Colors.transparent : Theme.of(context).primaryColor.withValues(alpha:0.5), width: 0.6)
                        ),
                        margin: const EdgeInsets.symmetric(horizontal: 5),
                        padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge, vertical: 7),
                        child: Text(element.name?.tr ?? "", style: rubikRegular.copyWith(
                         color: faqController.selectedFagIndex == index ? Colors.white : Theme.of(context).primaryColor
                        )),
                      ),
                    ),
                  );
                }).toList()),
              ),
            ),
          ): const SizedBox(),

          faqController.helpTopics == null ?  const Expanded(child: FaqShimmer()) :
          faqController.loadFailed ? Expanded(
            child: Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.wifi_off_rounded, size: 42, color: AmialColors.textMuted),
                    const SizedBox(height: 12),
                    const Text('تعذر تحميل الأسئلة الشائعة الآن.', textAlign: TextAlign.center),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: _loadData,
                      icon: const Icon(Icons.refresh_rounded),
                      label: const Text('إعادة المحاولة'),
                    ),
                  ],
                ),
              ),
            ),
          ) : Expanded(
            child: faqController.helpTopics !=null && faqController.helpTopics!.isNotEmpty ? Column( children: [
              Expanded(
                child: ListView.builder(
                  itemCount: faqController.helpTopics!.length,
                  controller: faqController.scrollController,
                  padding: const EdgeInsets.only(bottom:  Dimensions.paddingSizeLarge, top: Dimensions.paddingSizeSmall),
                  physics: const ClampingScrollPhysics(),
                  itemBuilder: (ctx, index) {
                    return CustomFaqExpansionTile(
                      expandedCrossAxisAlignment: CrossAxisAlignment.start,
                      iconColor: Theme.of(context).primaryColor,
                      titleWidget : Text(faqController.helpTopics?[index].question ?? "",
                        style: rubikMedium.copyWith(color: Theme.of(context).textTheme.bodyLarge?.color,
                          fontSize: Dimensions.fontSizeLarge,
                        ),
                      ),
                      leading: Text('${index < 9 ? "0" : ""}${index + 1}',
                        style: rubikMedium.copyWith(fontSize: Dimensions.paddingSizeLarge, color: Theme.of(context).hintColor.withValues(alpha:0.4)),
                      ),
                      children: <Widget>[
                        const Row(),
                        Padding( padding: const EdgeInsets.symmetric(vertical: 8),
                          child: Text(
                            faqController.helpTopics?[index].answer ?? "",
                            style: rubikLight.copyWith(color: Theme.of(context).textTheme.bodyLarge?.color), textAlign: TextAlign.justify,

                          ),
                        )
                      ],
                    );
                  },
                ),
              ),
              if(faqController.isLoading) const CircularProgressIndicator()
            ]) : const EmptyFagWidget(),
          ),
        ]);
      }),
    );
  }
}
