enum FavType {
  fAndF, agent, others, unknown,
}

class _FavTypeConverter {
  // Singleton pattern
  static final instance = _FavTypeConverter._();
  _FavTypeConverter._();

  // String → Enum
  FavType fromString(String? value) {
    if (value == null) return FavType.unknown;
    switch (value.toLowerCase()) {
      case 'f_and_f': return FavType.fAndF;
      case 'agent':   return FavType.agent;
      case 'others':  return FavType.others;
      default:       return FavType.unknown;
    }
  }

  // Enum → String
  String toValueString(FavType? type) {
    switch (type) {
      case FavType.fAndF:  return 'f_and_f';
      case FavType.agent:  return 'agent';
      case FavType.others: return 'others';
      case FavType.unknown: return 'unknown';
      case null: return 'unknown';
    }
  }
}

extension FavTypeExtention on FavType? {
  String get toValueString {
    return _FavTypeConverter.instance.toValueString(this);
  }
  FavType fromFavTypeToString(String? value) {
    return _FavTypeConverter.instance.fromString(value);
  }
}

extension StringToFavTypeExtention on String? {
  FavType get fromFavTypeToString {
    return _FavTypeConverter.instance.fromString(this);
  }
}