import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

class DateConverterHelper {
  /// الوقت المالي يُخزَّن ويُرسل من الخادم كـ UTC، لكن أميال تعرضه دائماً
  /// بتوقيت مكة. لا نعتمد على منطقة الجهاز: فقد يكون العميل في بلد آخر أو
  /// ضبط الساعة يدوياً، بينما تاريخ الحركة يجب أن يكون واحداً لدى الأطراف.
  static const Duration _meccaOffset = Duration(hours: 3);

  /// يحوّل لحظةً مطلقة إلى وقت مكة (`Asia/Riyadh`، بلا توقيت صيفي).
  /// القيمة الناتجة تستعمل للعرض فقط؛ لا تُعاد إلى الخادم بدلاً من UTC.
  static DateTime toMecca(DateTime value) {
    final utc = value.isUtc
        ? value
        : DateTime.utc(value.year, value.month, value.day, value.hour,
            value.minute, value.second, value.millisecond, value.microsecond);
    return utc.add(_meccaOffset);
  }

  /// تواريخ API غير المزوّدة بمنطقة تُعد UTC أيضاً؛ هذا يحافظ على توافق
  /// السجلات القديمة التي كانت تُسجّل والخادم مضبوط على UTC.
  static DateTime parseApiInstant(String value) {
    final parsed = DateTime.parse(value);
    return parsed.isUtc
        ? parsed
        : DateTime.utc(parsed.year, parsed.month, parsed.day, parsed.hour,
            parsed.minute, parsed.second, parsed.millisecond, parsed.microsecond);
  }

  static DateTime fromApi(String value) {
    return toMecca(parseApiInstant(value));
  }

  static DateTime? tryParseApiInstant(String? value) {
    if (value == null || value.trim().isEmpty) return null;
    try {
      return parseApiInstant(value);
    } on FormatException {
      return null;
    }
  }

  static DateTime? tryFromApi(String? value) {
    final instant = tryParseApiInstant(value);
    return instant == null ? null : toMecca(instant);
  }

  static DateTime nowInMecca() => DateTime.now().toUtc().add(_meccaOffset);

  static String formatDate(DateTime dateTime) {
    return DateFormat('yyyy-MM-dd HH:mm:ss').format(dateTime.toUtc());
  }

  static String estimatedDate(DateTime dateTime) {
    return DateFormat('dd MMM yyyy').format(toMecca(dateTime));
  }

  static DateTime convertStringToDatetime(String dateTime) {
    return fromApi(dateTime);
  }
  static String localDateToIsoStringAMPM(DateTime dateTime) {
    return DateFormat('HH:mm | d-MMM-yyyy').format(toMecca(dateTime));
  }

  static DateTime isoStringToLocalDate(String dateTime) {
    return fromApi(dateTime);
  }

  static String isoStringToLocalTimeOnly(String dateTime) {
    return DateFormat('HH:mm').format(isoStringToLocalDate(dateTime));
  }
  static String isoStringToLocalAMPM(String dateTime) {
    // اسم الدالة تاريخي؛ العقد الموحد لأميال لا يعرض AM/PM.
    return isoStringToLocalTimeOnly(dateTime);
  }

  static String isoStringToLocalDateOnly(String dateTime) {
    return DateFormat('dd MMM yyyy').format(isoStringToLocalDate(dateTime));
  }

  static String localDateToIsoString(DateTime dateTime) {
    return DateFormat('yyyy-MM-ddTHH:mm:ss.SSS').format(dateTime.toUtc());
  }

  static String convertTimeToTime(String time) {
    return DateFormat('HH:mm').format(DateFormat('HH:mm:ss').parse(time));
  }

  static String dateStringMonthYear(DateTime ? dateTime) {
    return DateFormat('d MMM,y').format(dateTime!);
  }

  static int getDifferenceFromPresent(String date){
    final parsedDate = fromApi(date);
    final currentDate = nowInMecca();
    final normalizedParsedDate = DateTime(parsedDate.year, parsedDate.month, parsedDate.day);
    final normalizedCurrentDate = DateTime(currentDate.year, currentDate.month, currentDate.day);
    final difference = normalizedCurrentDate.difference(normalizedParsedDate).inDays;

    return difference;
  }

  static String getRelativeDateStatus(String inputDate, BuildContext context) {
    try {
      final difference = getDifferenceFromPresent(inputDate);
      if (difference == 0) {
        return 'today'.tr;
      } else if (difference == 1) {
        return 'yesterday'.tr;
      } else {
        return DateFormat('dd/MM/yyyy').format(fromApi(inputDate));
      }
    } catch (e) {
      return 'invalid_date'.tr; // Localized "Invalid date"
    }
  }

  static String timeAgo(String date){
    final parsedDate = fromApi(date);
    final now = nowInMecca();
    final difference = now.difference(parsedDate);

    if (difference.inSeconds < 60) {
      return 'Just now';
    } else if (difference.inMinutes < 60) {
      return '${difference.inMinutes} ${difference.inMinutes == 1 ? 'minute' : 'minutes'} ago';
    } else if (difference.inHours < 24) {
      return '${difference.inHours} ${difference.inHours == 1 ? 'hour' : 'hours'} ago';
    } else if (difference.inDays == 1) {
      return 'yesterday';
    } else if (difference.inDays < 7) {
      return '${difference.inDays} ${difference.inDays == 1 ? 'day' : 'days'} ago';
    } else if (difference.inDays < 30) {
      final weeks = (difference.inDays / 7).floor();
      return '$weeks ${weeks == 1 ? 'week' : 'weeks'} ago';
    } else if (difference.inDays < 365) {
      final months = (difference.inDays / 30).floor();
      return '$months ${months == 1 ? 'month' : 'months'} ago';
    } else {
      final years = (difference.inDays / 365).floor();
      return '$years ${years == 1 ? 'year' : 'years'} ago';
    }
  }

  static Map<String, DateTime?> getDateRangeForFilter(String? filterKey) {
    final DateTime now = nowInMecca();
    final DateTime today = DateTime(now.year, now.month, now.day);

    late DateTime startDate;
    DateTime endDate = today;

    switch (filterKey) {
      case 'this_week':
        final int weekday = today.weekday;
        startDate = today.subtract(Duration(days: weekday - 1));
        break;

      case 'last_7_days':
        startDate = today.subtract(const Duration(days: 6));
        break;

      case 'last_15_days':
        startDate = today.subtract(const Duration(days: 14));
        break;

      case 'this_month':
        startDate = DateTime(today.year, today.month, 1);
        break;

      case 'last_30_days':
        startDate = today.subtract(const Duration(days: 29));
        break;

      case 'last_60_days':
        startDate = today.subtract(const Duration(days: 59));
        break;

      case 'this_year':
        startDate = DateTime(today.year, 1, 1);
        break;

      case 'last_year':
        startDate = DateTime(today.year - 1, 1, 1);
        endDate = DateTime(today.year - 1, 12, 31);
        break;

      default:
        return {
          'startDate': null,
          'endDate': null,
        };
    }

    return {
      'startDate': startDate,
      'endDate': endDate,
    };
  }

  static DateTime? convertDurationDateTimeFromString(String? dateTime) => DateFormat('yyyy-MM-dd HH:mm:ss').tryParse(dateTime ?? '');
}
