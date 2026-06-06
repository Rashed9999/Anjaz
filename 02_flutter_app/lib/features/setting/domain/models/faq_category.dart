class FaqCategory {
  int? id;
  String? name;
  int? status;
  int? clickCount;
  String? createdAt;
  String? updatedAt;

  FaqCategory(
      {this.id,
        this.name,
        this.status,
        this.clickCount,
        this.createdAt,
        this.updatedAt});

  FaqCategory.fromJson(Map<String, dynamic> json) {
    id = json['id'];
    name = json['name'];
    status = int.tryParse(json['status'].toString());
    clickCount = int.tryParse(json['click_count'].toString());
    createdAt = json['created_at'];
    updatedAt = json['updated_at'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['id'] = id;
    data['name'] = name;
    data['status'] = status;
    data['click_count'] = clickCount;
    data['created_at'] = createdAt;
    data['updated_at'] = updatedAt;
    return data;
  }
}
