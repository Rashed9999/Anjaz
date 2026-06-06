import 'dart:convert';
import 'package:amyal_pay/features/favorite_number/domain/enums/fav_type.dart';

FavouriteListModel favouriteListModelFromJson(String str) => FavouriteListModel.fromJson(json.decode(str));

String favouriteListModelToJson(FavouriteListModel data) => json.encode(data.toJson());

class FavouriteListModel {
  int? total;
  int? limit;
  List<FavouriteModel>? fAndF;
  List<FavouriteModel>? agent;
  List<FavouriteModel>? others;
  List<FavouriteModel>? all;

  FavouriteListModel({
    this.total,
    this.limit,
    this.fAndF,
    this.agent,
    this.others,
  }) : all = [
    ...?fAndF,
    ...?agent,
    ...?others,
  ];

  factory FavouriteListModel.fromJson(Map<String, dynamic> json) => FavouriteListModel(
    total: int.tryParse('${json["total"]}'),
    limit: int.tryParse('${json["favorite_number_limit"]}'),
    fAndF: json["f_and_f"] == null ? [] : List<FavouriteModel>.from(json["f_and_f"]!.map((x) => FavouriteModel.fromJson(x))),
    agent: json["agent"] == null ? [] : List<FavouriteModel>.from(json["agent"]!.map((x) => FavouriteModel.fromJson(x))),
    others: json["others"] == null ? [] : List<FavouriteModel>.from(json["others"]!.map((x) => FavouriteModel.fromJson(x))),
  );

  Map<String, dynamic> toJson() => {
    "total": total,
    "favorite_number_limit": limit,
    "f_and_f": fAndF == null ? [] : List<dynamic>.from(fAndF!.map((x) => x.toJson())),
    "agent": agent == null ? [] : List<dynamic>.from(agent!.map((x) => x.toJson())),
    "others": others == null ? [] : List<dynamic>.from(others!.map((x) => x.toJson())),
  };
}

class FavouriteModel {
  int? id;
  String? userId;
  String? name;
  String? phone;
  FavType? type;

  FavouriteModel({
    this.id,
    this.userId,
    this.name,
    this.phone,
    this.type,
  });

  factory FavouriteModel.fromJson(Map<String, dynamic> json) => FavouriteModel(
    id: json["id"],
    userId: json["user_id"],
    name: json["name"],
    phone: json["phone"],
    type: '${json["type"]}'.fromFavTypeToString,
  );

  Map<String, dynamic> toJson() => {
    "id": id,
    "user_id": userId,
    "name": name,
    "phone": phone,
    "type": type.toValueString,
  };
}
