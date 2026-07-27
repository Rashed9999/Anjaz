import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:open_file/open_file.dart';
import 'package:share_plus/share_plus.dart';
import 'package:path_provider/path_provider.dart';
import 'package:amyal_pay/common/models/notification_body.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/helper/notification_helper.dart';
import 'package:amyal_pay/main.dart';
import 'package:url_launcher/url_launcher.dart';

class PdfDownloaderHelper {

  static Future<void> downloadAndOpenPdf({required Uint8List pdfData, String? baseFileName = 'file'}) async {
    try {
      // 1. Prepare file name and paths
      final fileName = '${baseFileName}_${DateTime.now().millisecondsSinceEpoch}.pdf';
      final Directory? dir = Platform.isIOS
          ? await getApplicationDocumentsDirectory()
          : await _getAndroidDownloadDirectory();

      final filePath = '${dir?.path}/$fileName';
      final file = File(filePath);


      // 4. Write the file with verification
      await _writeFileWithVerification(file, pdfData);

      // 5. Open the file with platform-specific handling
     try{
       await _openFileWithFallback(filePath);
     }catch(e){
       showCustomSnackBarHelper('filed_to_process_pdf'.tr);
     }


    } catch (e) {
      debugPrint('PDF download failed: $e');
      showCustomSnackBarHelper('filed_to_process_pdf'.tr);
    }
  }

  static Future<void> _writeFileWithVerification(File file, Uint8List data) async {
    // Write with forced flush
    await file.writeAsBytes(data, flush: true);

    // Verify the file was written
    if (!await file.exists()) {
      throw Exception('File failed to write');
    }

    final fileSize = (await file.stat()).size;
    if (fileSize == 0) {
      await file.delete();
      throw Exception('File was written as 0 bytes');
    }
  }

  static Future<void> _openFileWithFallback(String filePath) async {

    if(Platform.isAndroid) {
      // AMIAL-PDF-OPEN-001 — لا تُترك النتيجة صامتة.
      //
      // كان المسار: حاول الفتح، فإن فشل اعرض إشعاراً «اضغط للفتح». وعلى
      // أندرويد 13 فما فوق يحتاج الإشعار إذناً منفصلاً — فإن مُنع (وهو
      // الافتراضي حتى يُطلب) لا يقع شيء مرئيّ إطلاقاً: يضغط المستخدم
      // «تحميل» فلا يرى ولا يسمع شيئاً، والملفّ محفوظ على جهازه.
      //
      // وسبب الفشل نفسه كان في البيان: منذ أندرويد 11 لا يرى التطبيق أي
      // قارئ PDF ما لم يُعلن ذلك في <queries>، فيعود resolveActivity
      // فارغاً. أُصلح الإعلان — وهذا يضمن أن يُقال للمستخدم ما جرى مهما
      // كانت النتيجة.
      OpenResult? result;
      try {
        result = await OpenFile.open(filePath, type: 'application/pdf');
        if (result.type == ResultType.done) return;
      } catch (e) {
        debugPrint('AMIAL-PDF: تعذّر فتح الملفّ — $e');
      }

      // لا قارئ PDF على الجهاز: كثير من الهواتف الاقتصادية بلا واحد.
      // المشاركة تعمل دائماً وتُوصِل الملفّ إلى واتساب أو البريد أو أي
      // تطبيق يقرؤه — مخرجٌ حقيقيّ لا رسالة اعتذار.
      if (result?.type == ResultType.noAppToOpen) {
        await _shareInstead(filePath);
        return;
      }

      // الإشعار يبقى مساعداً لا معتمَداً عليه.
      try {
        final NotificationBody payload = NotificationBody(
          title: 'اكتمل التنزيل',
          body: 'اضغط لفتح الإيصال',
          type: 'download',
          filePath: filePath,
        );
        NotificationHelper.showDownloadNotification(
            jsonEncode(payload.toJson()), flutterLocalNotificationsPlugin);
      } catch (_) {}

      // ثمّ يُقال للمستخدم صراحةً — وهذا ما كان غائباً.
      await _shareInstead(filePath);

    } else {
      try {
        // Initial attempt to open
        final result = await OpenFile.open(filePath);

        // Handle the result
        switch (result.type) {
          case ResultType.done:
            return; // Success!
          case ResultType.fileNotFound:
          // Try temporary directory as fallback (iOS specific)
            if (Platform.isIOS) {
              await _iosFallbackOpen(filePath);
            } else {
              throw Exception('File not found at $filePath');
            }
            break;
          case ResultType.noAppToOpen:
            await _handleNoPdfViewer(filePath);
            break;
          case ResultType.permissionDenied:
            throw Exception('Permission denied to open file');
          case ResultType.error:
            throw Exception('Error opening file: ${result.message}');
        }
      } catch (e) {
        debugPrint('ios error $e');
      }
    }


  }

  /// يعرض ورقة المشاركة على الملفّ المحفوظ.
  ///
  /// مخرجٌ يعمل حين لا يوجد قارئ PDF: يستطيع المستخدم إرساله لنفسه عبر
  /// واتساب أو حفظه في تطبيق ملفّات — بدل أن يبقى الملفّ على جهازه لا
  /// يعرف أنه موجود.
  static Future<void> _shareInstead(String filePath) async {
    try {
      await SharePlus.instance.share(
        ShareParams(files: [XFile(filePath)], subject: 'إيصال أميال باي'),
      );
    } catch (_) {
      showCustomSnackBarHelper(
          'حُفظ الإيصال في ملفّات التطبيق، لكن لا يوجد تطبيق يفتح ملفّات PDF '
          'على جهازك. ثبّت قارئ PDF ثم أعد المحاولة.');
    }
  }

  static Future<void> _iosFallbackOpen(String originalPath) async {
    try {
      // Try moving to temporary directory
      final tempDir = await getTemporaryDirectory();
      final tempPath = '${tempDir.path}/${originalPath.split('/').last}';
      await File(originalPath).copy(tempPath);

      // Try opening from temp location
      final result = await OpenFile.open(tempPath);
      if (result.type != ResultType.done) {
        throw Exception('Failed to open from temp location');
      }
    } catch (e) {
      throw Exception('iOS fallback failed: $e');
    }
  }

  static Future<void> _handleNoPdfViewer(String filePath) async {
    if (Platform.isIOS) {
      final Uri uri = Uri.parse('itms-apps://itunes.apple.com/search?term=pdf+reader');

      if (await canLaunchUrl(uri)) {
        await launchUrl(uri);
      }
    } else {
      // Android - try system default viewer
      final result = await OpenFile.open(filePath, type: 'application/pdf');
      if (result.type != ResultType.done) {
        throw Exception('No PDF viewer available');
      }
    }
  }

  static Future<Directory?> _getAndroidDownloadDirectory() async {
    // AMIAL-FIX(PDF): الكتابة في /storage/emulated/0/Download محظورة على
    // أندرويد 10+ (scoped storage) → FileSystemException → «فشل معالجة PDF».
    // نستخدم مجلّد التطبيق الخارجي (لا يحتاج أي أذونات على كل الإصدارات)،
    // و open_file يفتح منه عبر FileProvider الخاص به.
    try {
      final d = await getExternalStorageDirectory();
      if (d != null) return d;
    } catch (_) {}
    return getApplicationDocumentsDirectory();
  }

}