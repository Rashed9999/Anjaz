import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

class DateConverterHelper {
  static String formatDate(DateTime dateTime) {
    return DateFormat('yyyy-MM-dd hh:mm:ss').format(dateTime);
  }

  static String estimatedDate(DateTime dateTime) {
    return DateFormat('dd MMM yyyy').format(dateTime);
  }

  static DateTime convertStringToDatetime(String dateTime) {
    return DateFormat("yyyy-MM-ddTHH:mm:ss.SSS").parse(dateTime);
  }
  static String localDateToIsoStringAMPM(DateTime dateTime) {
    return DateFormat('h:mm a | d-MMM-yyyy ').format(dateTime.toLocal());
  }

  static DateTime isoStringToLocalDate(String dateTime) {
    return DateFormat('yyyy-MM-ddTHH:mm:ss.SSS').parse(dateTime, true).toLocal();
  }

  static String isoStringToLocalTimeOnly(String dateTime) {
    return DateFormat('hh:mm aa').format(isoStringToLocalDate(dateTime));
  }
  static String isoStringToLocalAMPM(String dateTime) {
    return DateFormat('a').format(isoStringToLocalDate(dateTime));
  }

  static String isoStringToLocalDateOnly(String dateTime) {
    return DateFormat('dd MMM yyyy').format(isoStringToLocalDate(dateTime));
  }

  static String localDateToIsoString(DateTime dateTime) {
    return DateFormat('yyyy-MM-ddTHH:mm:ss.SSS').format(dateTime.toUtc());
  }

  static String convertTimeToTime(String time) {
    return DateFormat('hh:mm a').format(DateFormat('hh:mm:ss').parse(time));
  }

  static String dateStringMonthYear(DateTime ? dateTime) {
    return DateFormat('d MMM,y').format(dateTime!);
  }

  static int getDifferenceFromPresent(String date){
    final parsedDate = DateTime.parse(date);
    final currentDate = DateTime.now();
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
        return DateFormat('dd/MM/yyyy').format(DateTime.parse(inputDate));
      }
    } catch (e) {
      return 'invalid_date'.tr; // Localized "Invalid date"
    }
  }

  static String timeAgo(String date){
    final parsedDate = DateTime.parse(date);
    final now = DateTime.now();
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
    final DateTime now = DateTime.now();
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
