import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/common/models/config_model.dart';
import 'package:amial_pay/features/splash/domain/reposotories/splash_repo.dart';

class SplashController extends GetxController implements GetxService{
   final SplashRepo splashRepo;
  SplashController({required this.splashRepo});

  ConfigModel? _configModel;

  final DateTime _currentTime = DateTime.now();

  DateTime get currentTime => _currentTime;
  bool _firstTimeConnectionCheck = true;
  bool get firstTimeConnectionCheck => _firstTimeConnectionCheck;

  // AMIAL-FIX(GRAY-SCREENS): كثير من شاشات 6cash تفكّ configModel! و
  // configModel!.systemFeature! مباشرةً؛ فإن فشل جلب الإعداد (أو تأخّر) يكون
  // _configModel = null فتنهار الشاشة إلى رمادي كامل. نُعيد إعداداً افتراضياً
  // غير فارغ (قيمه محايدة) بدل null فلا تنهار أيّ شاشة، ويظلّ النوع ?‎ فلا يتغيّر
  // شيء عند المستدعين. fromJson({}) آمن تماماً (كل الحقول لها افتراضيات).
  ConfigModel? _defaultConfig;
  ConfigModel? get configModel =>
      _configModel ?? (_defaultConfig ??= ConfigModel.fromJson(<String, dynamic>{}));

  Future<Response> getConfigData() async {
    Response response = await splashRepo.getConfigData();
    if(response.statusCode == 200 && response.body is Map){
      _configModel = ConfigModel.fromJson(Map<String, dynamic>.from(response.body));
    }
   else {
     ApiChecker.checkApi(response);
   }
    update();
    return response;

  }

  Future<bool> initSharedData() {
    return splashRepo.initSharedData();
  }

   void removeSharedData() {
    return splashRepo.removeSharedData();
  }

  bool isRestaurantClosed() {
    DateTime open = DateFormat('hh:mm').parse('');
    DateTime close = DateFormat('hh:mm').parse('');
    DateTime openTime = DateTime(_currentTime.year, _currentTime.month, _currentTime.day, open.hour, open.minute);
    DateTime closeTime = DateTime(_currentTime.year, _currentTime.month, _currentTime.day, close.hour, close.minute);
    if(closeTime.isBefore(openTime)) {
      closeTime = closeTime.add(const Duration(days: 1));
    }
    if(_currentTime.isAfter(openTime) && _currentTime.isBefore(closeTime)) {
      return false;
    }else {
      return true;
    }
  }


  void setFirstTimeConnectionCheck(bool isChecked) {
    _firstTimeConnectionCheck = isChecked;
  }

}
