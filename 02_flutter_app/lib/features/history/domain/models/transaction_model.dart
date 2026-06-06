import 'package:amyal_pay/helper/date_converter_helper.dart';

import 'dart:convert';

class TransactionModel {
  int? totalSize;
  int? limit;
  int? offset;
  String? transactionType;
  String? balanceType;
  DateTime? startDate;
  DateTime? endDate;
  List<Transactions>? transactions;
  TransactionAdminInfo? transactionAdminInfo;

  TransactionModel({this.totalSize, this.limit, this.offset, this.transactionType, this.balanceType, this.startDate, this.endDate, this.transactions, this.transactionAdminInfo});

  TransactionModel.fromJson(Map<String, dynamic> json) {
    totalSize = json['total_size'];
    limit = json['limit'];
    offset = json['offset'];
    transactionType = json['transaction_type'];
    balanceType = json['balance_type'];
    startDate = DateConverterHelper.convertDurationDateTimeFromString(json['start_date']);
    endDate = DateConverterHelper.convertDurationDateTimeFromString(json['end_date']);
    if (json['transactions'] != null) {
      transactions = <Transactions>[];
      json['transactions'].forEach((v) {
        transactions!.add(Transactions.fromJson(v));
      });
    }

    transactionAdminInfo = json['transaction_admin_info'] != null
        ? TransactionAdminInfo.fromJson(json['transaction_admin_info'])
        : null;
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['total_size'] = totalSize;
    data['limit'] = limit;
    data['offset'] = offset;
    data['transaction_type'] = transactionType;
    data['balance_type'] = balanceType;
    data['start_date'] = DateConverterHelper.formatDate(startDate ?? DateTime.now());
    data['end_date'] = DateConverterHelper.formatDate(endDate ?? DateTime.now());
    if (transactions != null) {
      data['transactions'] = transactions!.map((v) => v.toJson()).toList();
    }
    if (transactionAdminInfo != null) {
      data['transaction_admin_info'] = transactionAdminInfo!.toJson();
    }
    return data;
  }
}

class Transactions {
  String? id;
  String? transactionType;
  String? transactionId;
  double? debit;
  double? credit;
  UserInfo? userInfo;
  String? createdAt;
  double? amount;
  Receiver? receiver;
  Sender? sender;
  Dispute? dispute;
  double? charge;


  Transactions({
    this.id,
    this.transactionType,
    this.transactionId,
    this.debit,
    this.credit,
    this.userInfo,
    this.createdAt,
    this.receiver,
    this.sender,
    this.amount,
    this.dispute,
    this.charge,

  });

  Transactions.fromJson(Map<String, dynamic> json) {
    id = json['id'].toString();
    transactionType = json['transaction_type'];
    transactionId = json['transaction_id'];
    debit = json['debit'].toDouble();
    credit = json['credit'].toDouble();
    userInfo = json['user_info'] != null
        ? UserInfo.fromJson(json['user_info'])
        : null;
    receiver = json['receiver'] != null
        ? Receiver.fromJson(json['receiver'])
        : null;
    sender = json['sender'] != null
        ? Sender.fromJson(json['sender'])
        : null;
    createdAt = json['created_at'];
    amount = json['amount'].toDouble();
    dispute = json['dispute'] != null
        ? Dispute.fromJson(json['dispute'])
        : null;
    charge = double.tryParse('${json['charge']}'); // Assuming charge is a double, defaulting to 0.0 if not present
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['id'] = id;
    data['transaction_type'] = transactionType;
    data['transaction_id'] = transactionId;
    data['debit'] = debit;
    data['credit'] = credit;
    if (userInfo != null) {
      data['user_info'] = userInfo!.toJson();
    }
    data['created_at'] = createdAt;
    data['amount'] = amount;
    data['dispute'] = dispute?.toJson();
    data['charge'] = charge;
    return data;
  }
}

class Dispute {
  int? id;
  int? senderId;
  String? senderType;
  String? transactionId;
  double? amount;
  DisputeStatus? status;
  String? trxId;
  List<String>? reportReason;
  String? comment;
  String? deniedNote;

  Dispute({
    this.id,
    this.senderId,
    this.senderType,
    this.transactionId,
    this.amount,
    this.status,
    this.trxId,
    this.reportReason,
    this.comment,
    this.deniedNote,
  });

  factory Dispute.fromJson(Map<String, dynamic> json) => Dispute(
    id: json["id"],
    senderId: json["sender_id"],
    senderType: json["sender_type"],
    transactionId: json["transaction_id"],
    amount: json["amount"]?.toDouble(),
    status: DisputeStatus.fromJson(json["status"]),
    trxId: json["trx_id"],
    reportReason: json["report_reason"] == null ? [] : List<String>.from(jsonDecode(json["report_reason"])!.map((x) => x)),
    comment: json["comment"],
    deniedNote: json["denied_note"],
  );

  Map<String, dynamic> toJson() => {
    "id": id,
    "sender_id": senderId,
    "sender_type": senderType,
    "transaction_id": transactionId,
    "amount": amount,
    "status": status?.toJson(),
    "trx_id": trxId,
    "report_reason": reportReason == null ? [] : List<dynamic>.from(reportReason!.map((x) => x)),
    "comment": comment,
    "denied_note": deniedNote,
  };
}

enum DisputeStatus {
  pending('pending'),
  approved('approved'),
  denied('denied'),
  disputed('disputed');


  final String value;
  const DisputeStatus(this.value);

  factory DisputeStatus.fromJson(String value) {
    return DisputeStatus.values.firstWhere(
          (e) => e.value == value,
      orElse: () => DisputeStatus.pending, // default value if not found
    );
  }

  String toJson() => value;

}


class UserInfo {
  String? phone;
  String? name;

  UserInfo({this.phone, this.name});

  UserInfo.fromJson(Map<String, dynamic> json) {
    phone = json['phone'] ?? '';
    name = json['name'] ?? '';
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['phone'] = phone;
    data['name'] = name;
    return data;
  }
}

class Sender {
  String? phone;
  String? name;
  String? image;

  Sender({this.phone, this.name, this.image});

  Sender.fromJson(Map<String, dynamic> json) {
    phone = json['phone'] ?? '';
    name = json['name'] ?? '';
    image = json['image_fullpath'] ?? '';
  }
}

class Receiver {
  String? phone;
  String? name;
  String? image;
  Receiver({this.phone, this.name, this.image});

  Receiver.fromJson(Map<String, dynamic> json) {
    phone = json['phone'] ?? '';
    name = json['name'] ?? '';
    image = json['image_fullpath'] ?? '';
  }
}
class TransactionAdminInfo {
  String? name;
  String? phone;
  String? image;

  TransactionAdminInfo({
    this.name,
    this.phone,
    this.image,
  });

  factory TransactionAdminInfo.fromJson(Map<String, dynamic> json) => TransactionAdminInfo(
    name: json["name"],
    phone: json["phone"],
    image: json["image"],
  );

  Map<String, dynamic> toJson() => {
    "name": name,
    "phone": phone,
    "image": image,
  };
}