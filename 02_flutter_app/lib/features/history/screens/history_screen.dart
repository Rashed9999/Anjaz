import 'package:scroll_to_index/scroll_to_index.dart';
import 'package:amyal_pay/common/widgets/filter_icon_widget.dart';
import 'package:amyal_pay/common/widgets/no_data_widget.dart';
import 'package:amyal_pay/common/widgets/paginated_list_widget.dart';
import 'package:amyal_pay/features/history/controllers/report_dispute_controller.dart';
import 'package:amyal_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amyal_pay/features/history/domain/models/transaction_model.dart';
import 'package:amyal_pay/features/history/widgets/history_shimmer_widget.dart';
import 'package:amyal_pay/features/history/widgets/transaction_filter_drawer_widget.dart';
import 'package:amyal_pay/features/history/widgets/transaction_history_widget.dart';
import 'package:amyal_pay/features/history/widgets/transaction_type_button_widget.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/helper/pdf_downloader_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/util/styles.dart';

class HistoryScreen extends StatefulWidget {
  final Transactions? transactions;
  const HistoryScreen({super.key, this.transactions});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  AutoScrollController? _tabScrollController;
  final ScrollController _scrollController = ScrollController();

  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();



  @override
  void initState() {
    super.initState();

    final transactionHistoryController = Get.find<TransactionHistoryController>();

    final bool alreadyFiltered = _isFiltered(transactionHistoryController.transactionModel);
    transactionHistoryController.resetFilter();



    if(transactionHistoryController.transactionModel?.transactionType != 'all' || alreadyFiltered){
      final String type = transactionHistoryController.transactionType[transactionHistoryController.transactionTypeIndex];
      transactionHistoryController.getTransactionData(1, transactionType: type);

    }

    Get.find<ReportDisputeController>().getReasonList();

    _tabScrollController = AutoScrollController(
      viewportBoundaryGetter: () => Rect.fromLTRB(0, 0, 0, MediaQuery.of(context).padding.bottom),
      axis: Axis.horizontal,
    );

    _tabScrollController?.scrollToIndex(
      transactionHistoryController.transactionTypeIndex,
      preferPosition: AutoScrollPosition.middle,
      duration: const Duration(milliseconds: 300),
    );
  }

  @override
  Widget build(BuildContext context) {

    return Scaffold(
      key: _scaffoldKey,
      endDrawer: const Drawer(
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.zero,
        ),
        child: TransactionFilterDrawerWidget(),
      ),
      appBar: PreferredSize(
        preferredSize: const Size(double.maxFinite, 70),
        child: Container(
          color: Theme.of(context).primaryColor,
          padding: const EdgeInsets.all(Dimensions.paddingSizeLarge).copyWith(top: 0, bottom: Dimensions.paddingSizeDefault),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              GetBuilder<TransactionHistoryController>(
                  builder: (transactionHistoryController) {
                    return Row(children: [

                      Text('transaction_history'.tr, style: rubikSemiBold.copyWith(
                        fontSize: Dimensions.fontSizeExtraLarge,color: Colors.white,),
                      ),

                      const Spacer(),

                      InkWell(
                        onTap: ()=> transactionHistoryController.downloadTransactionHistory(
                          transactionType: transactionHistoryController.transactionModel?.transactionType ?? '',
                          balanceType: transactionHistoryController.transactionModel?.balanceType,
                          startDate: transactionHistoryController.transactionModel?.startDate,
                          endDate: transactionHistoryController.transactionModel?.endDate,
                        ).then((pdfData) async {
                          if(pdfData != null){
                            await PdfDownloaderHelper.downloadAndOpenPdf(pdfData: pdfData, baseFileName: 'Transaction_Statement');
                          }else {
                            showCustomSnackBarHelper('you_do_not_have_transactions'.tr);
                          }
                        }),
                        child: transactionHistoryController.isLoading ?
                        SizedBox(
                          height: Dimensions.paddingSizeExtraLarge,
                          width: Dimensions.paddingSizeExtraLarge,
                          child: CircularProgressIndicator(color: Colors.white),
                        ) :
                        Icon(Icons.file_download_outlined, color: Colors.white, size: Dimensions.radiusSizeExtraLarge),
                      ),
                      const SizedBox(width: Dimensions.paddingSizeDefault),

                      GetBuilder<TransactionHistoryController>(
                          builder: (transactionHistoryController) {
                            return FilterIconWidget(
                              isFiltered: _isFiltered(transactionHistoryController.transactionModel),
                              onTap: ()=>  _scaffoldKey.currentState?.openEndDrawer(),
                              iconSize: Dimensions.radiusSizeExtraLarge,
                            );
                          }
                      ),
                    ]);
                  }
              ),
            ],
          ),
        ),
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async {
            final transactionHistoryController = Get.find<TransactionHistoryController>();
            transactionHistoryController.resetFilter();
            await transactionHistoryController.getTransactionData(
              1,
              reload: true,
              transactionType: transactionHistoryController.transactionType[transactionHistoryController.transactionTypeIndex],
            );
            Get.find<ReportDisputeController>().getReasonList();

          },
          child: CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(parent: ClampingScrollPhysics()),
              controller: _scrollController, slivers: [

            SliverPersistentHeader(pinned: true, delegate: SliverDelegate(
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall),
                height: 50, alignment: Alignment.centerLeft,
                color: Theme.of(context).cardColor,
                child: GetBuilder<TransactionHistoryController>( builder: (historyController) {

                  _tabScrollController?.scrollToIndex(historyController.transactionTypeIndex);

                  return ListView(
                    controller: _tabScrollController,
                    shrinkWrap: true,
                    padding: const EdgeInsets.only(left: Dimensions.paddingSizeSmall),
                    scrollDirection: Axis.horizontal,
                    children: historyController.transactionType.map((element) {
                      int index =  historyController.transactionType.indexOf(element);
                      return  AutoScrollTag(
                        key: ValueKey(index),
                        controller: _tabScrollController!,
                        index: index,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 5),
                          child: TransactionTypeButtonWidget(
                            text: element.tr, index: index,
                            onTap: (){
                              historyController.setIndex(index);
                              historyController.getTransactionData(1, transactionType: element, reload: index !=0);
                            },
                          ),
                        ),
                      );
                    }).toList(),
                  );
                }),
              ),
            )),

            SliverToBoxAdapter(
              child: GetBuilder<TransactionHistoryController>(builder: (transactionHistoryController){
                final List<Transactions> transactionList = transactionHistoryController.transactionModel?.transactions ?? [];

                return transactionHistoryController.transactionModel != null ? PaginatedListWidget(
                  scrollController: _scrollController,
                  totalSize: transactionHistoryController.transactionModel?.totalSize,
                  onPaginate: (int offset) async => await transactionHistoryController.getTransactionData(
                    offset,
                    transactionType: transactionHistoryController.transactionModel?.transactionType,
                    balanceType: transactionHistoryController.transactionModel?.balanceType,
                    startDate: transactionHistoryController.transactionModel?.startDate,
                    endDate: transactionHistoryController.transactionModel?.endDate,
                  ),
                  offset: transactionHistoryController.transactionModel?.offset,
                  itemView: transactionList.isNotEmpty ? ListView.builder(
                    itemCount: transactionList.length,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall),
                    itemBuilder: (context, index) {
                      return TransactionHistoryWidget(transactions: transactionList[index]);
                    },
                  ) : const NoDataFoundWidget(fromHome: false),
                ) : const HistoryShimmerWidget();
              }),
            ),
          ]),
        ),
      ),
    );
  }
}



class SliverDelegate extends SliverPersistentHeaderDelegate {
  Widget child;
  SliverDelegate({required this.child});

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    return child;
  }
  @override
  double get maxExtent => 50;

  @override
  double get minExtent => 50;

  @override
  bool shouldRebuild(SliverDelegate oldDelegate) {
    return oldDelegate.maxExtent != 50 || oldDelegate.minExtent != 50 || child != oldDelegate.child;
  }
}

bool _isFiltered(TransactionModel? transactionModel) {
  if (transactionModel == null) return false;

  final int nonNullFilterCount = [
    transactionModel.startDate,
    transactionModel.endDate,
  ].whereType<Object>().length;

  return nonNullFilterCount > 0;
}


