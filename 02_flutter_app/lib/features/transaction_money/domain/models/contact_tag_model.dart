import 'package:azlistview/azlistview.dart';
import 'package:flutter_contacts/contact.dart';
import 'package:amial_pay/features/favorite_number/domain/models/favorite_list_model.dart';

class ContactTagModel extends ISuspensionBean{
  final Contact? contact;
  final String tag;
  final FavouriteModel? favouriteModel;
  ContactTagModel({required this.favouriteModel, required this.contact, required this.tag});

  @override
  String getSuspensionTag()=> tag;
}