enum DisputeTimeType {
  day('day'),
  hour('hour'),
  minute('minute');

  final String value;
  const DisputeTimeType(this.value);

  factory DisputeTimeType.fromJson(String value) {
    return DisputeTimeType.values.firstWhere(
          (e) => e.value == value,
      orElse: () => DisputeTimeType.day, // default value if not found
    );
  }

  String toJson() => value;
}
