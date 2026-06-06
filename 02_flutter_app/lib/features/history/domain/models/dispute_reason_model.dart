
class DisputeReasonModel {
  int? id;
  String? reason;
  String? userType;

  DisputeReasonModel({
    this.id,
    this.reason,
    this.userType,

  });

  factory DisputeReasonModel.fromJson(Map<String, dynamic> json) => DisputeReasonModel(
    id: json["id"],
    reason: json["reason"],
    userType: json["user_type"],
  );

  Map<String, dynamic> toJson() => {
    "id": id,
    "reason": reason,
    "user_type": userType,
  };
}
