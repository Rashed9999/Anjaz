import 'dart:convert';

import 'package:get/get_connect/http/src/response/response.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/util/app_constants.dart' show AppConstants;

class FavNumberRepo{
  final ApiClient apiClient;
  final SharedPreferences sharedPreferences;
  const FavNumberRepo({required this.apiClient, required this.sharedPreferences});

  Future<Response> add({required String type, required String phoneNumber, required String name}) async {
    return await apiClient.postData(AppConstants.addFavouriteNumber, {
      'type' : type,
      'phone' : phoneNumber,
      'name' : name,
    });
  }


  Future<Response> update(int? id, {String? type, String? phoneNumber, String? name}) async {
    return await apiClient.postData('${AppConstants.updateFavouriteNumber}/$id', {
      'type' : type,
      'phone' : phoneNumber,
      'name' : name,
    });
  }

  Future<Response> delete(int? id) async {
    return await apiClient.deleteData('${AppConstants.deleteFavouriteNumber}/$id');
  }


  Future<Response> getList({String? searchBy}) async {
    return await apiClient.getData(AppConstants.getFavouriteNumberList, query: (searchBy?.isNotEmpty ?? false) ? {'search_by': searchBy} : null);
  }

  Future<bool> addToLocalStorage({Map<String, dynamic>? value}) async {
    return await sharedPreferences.setString(AppConstants.favNumberListKey, jsonEncode(value)) ;
  }

  Map<String, dynamic>? getFromLocalStorage() {
    String? favNumberList = sharedPreferences.getString(AppConstants.favNumberListKey);
    if(favNumberList != null && favNumberList.isNotEmpty) {
      return jsonDecode(favNumberList);
    }
    return null;
  }



}