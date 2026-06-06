import 'package:flutter/material.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/setting/domain/models/faq_category.dart';
import 'package:amyal_pay/features/setting/domain/models/faq_model.dart';
import 'package:amyal_pay/features/setting/domain/reposotories/faq_repo.dart';
import 'package:get/get.dart';

class FaqController extends GetxController implements GetxService {
  final FaqRepo faqrepo;
  FaqController({required this.faqrepo});

  bool _isLoading = false;
  bool get isLoading => _isLoading;

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
      if(scrollController.position.maxScrollExtent == scrollController.position.pixels) {
        if((_helpTopics?.length ?? 0) < _pageSize! ) {
          getFaqList(offset + 1, categoryId: _faqCategoryList?[_selectedFagIndex].id, paginationLoading: true);
        }
      }
    });
  }



  Future getFaqList(int offset, {int? categoryId, bool reload = false, int index = 0, bool isFirst = false,bool paginationLoading = false}) async{
    _offset = offset;
    _apiHitCount ++;
    if(reload){
      _helpTopics = null;
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
      ApiChecker.checkApi(response);
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