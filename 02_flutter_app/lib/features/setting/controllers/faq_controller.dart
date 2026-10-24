import 'package:flutter/material.dart';
import 'package:amial_pay/features/setting/domain/models/faq_category.dart';
import 'package:amial_pay/features/setting/domain/models/faq_model.dart';
import 'package:amial_pay/features/setting/domain/reposotories/faq_repo.dart';
import 'package:get/get.dart';

class FaqController extends GetxController implements GetxService {
  final FaqRepo faqrepo;
  FaqController({required this.faqrepo});

  bool _isLoading = false;
  bool get isLoading => _isLoading;

  bool _loadFailed = false;
  bool get loadFailed => _loadFailed;

  List<HelpTopic>? _helpTopics;
  List<HelpTopic>? get helpTopics => _helpTopics;

  List<FaqCategory>? _faqCategoryList;
  List<FaqCategory>? get faqCategoryList => _faqCategoryList;

  final ScrollController scrollController = ScrollController();

  int _selectedFagIndex = 0;
  int get selectedFagIndex => _selectedFagIndex;

  int _apiHitCount = 0;
  int? _pageSize;

  int _offset = 1;
  int get offset => _offset;

  @override
  void onInit(){
    super.onInit();
    scrollController.addListener(() {
      if (!scrollController.hasClients ||
          scrollController.position.maxScrollExtent != scrollController.position.pixels ||
          _pageSize == null ||
          (_helpTopics?.length ?? 0) >= _pageSize!) {
        return;
      }
      final categories = _faqCategoryList;
      final categoryId = categories != null &&
              _selectedFagIndex >= 0 &&
              _selectedFagIndex < categories.length
          ? categories[_selectedFagIndex].id
          : null;
      getFaqList(offset + 1, categoryId: categoryId, paginationLoading: true);
    });
  }



  Future getFaqList(int offset, {int? categoryId, bool reload = false, int index = 0, bool isFirst = false,bool paginationLoading = false}) async{
    _offset = offset;
    _apiHitCount ++;
    if(reload){
      _helpTopics = null;
    }
    if (isFirst || reload) {
      _loadFailed = false;
    }
    if(paginationLoading){
      _isLoading = true;
    }
    if(!isFirst){
      update();
    }
    Response response = await faqrepo.getFaqList(categoryId: categoryId, offset: offset);
    if(response.body != null && response.body != {} && response.statusCode == 200){
      if(_offset == 1){
        _helpTopics = [];
        _helpTopics!.addAll(FaqModel.fromJson(response.body).helpTopics ?? []);
      }else{
        _helpTopics?.addAll(FaqModel.fromJson(response.body).helpTopics ?? []);
      }
      _pageSize = FaqModel.fromJson(response.body).totalSize;
    } else{
      _helpTopics = [];
      _pageSize = 0;
      _loadFailed = true;
    }

    _apiHitCount--;
    _isLoading = false;

    if(_apiHitCount==0){
      update();
    }
  }

  Future getFaqCategoryList(bool reload, {bool isUpdate = true}) async {
    if (_faqCategoryList == null || reload) {
      _faqCategoryList = null;
      if (isUpdate) {
        update();
      }
    }
    Response response = await faqrepo.getFaqCategoryList();
    if (response.statusCode == 200) {
      _faqCategoryList = [];
      _selectedFagIndex = 0;
      _faqCategoryList!.add(FaqCategory(name: "all"));
      response.body.forEach((banner) {
        _faqCategoryList!.add(FaqCategory.fromJson(banner));
      });
    } else {
      _faqCategoryList = [];
      // شاشة الأسئلة تبقى قابلة للاستخدام حتى إذا تعذر تحميل التصنيفات.
      // لا نطلق صفحة خطأ عامة فوق المستخدم؛ تعرض الشاشة زر إعادة المحاولة.
    }
    update();

  }


  void updateSelectedFaqIndex({int? index, bool reload = true}) async {
    if(index !=null){
      _selectedFagIndex = index;
      await getFaqList(1,categoryId: _faqCategoryList?[index].id, reload: reload);
    }

    if(reload){
      update();
    }
  }
}
