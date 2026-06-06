import 'dart:convert';
import 'dart:io';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
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
      final NotificationBody payload = NotificationBody(
        title: 'Download Complete',
        body: 'Tap to open transaction_statement.pdf',
        type: 'download',
        filePath: filePath,
      );

      NotificationHelper.showDownloadNotification(jsonEncode(payload.toJson()), flutterLocalNotificationsPlugin);

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
    // Try standard Download directory first

    if(await _requestAndroidStoragePermission() == PermissionStatus.granted) {
      const downloadsPath = '/storage/emulated/0/Download';
      return Directory(downloadsPath);

    }
    return null;
  }

  static Future<PermissionStatus> _requestAndroidStoragePermission() async {
    final plugin = DeviceInfoPlugin();
    final android = await plugin.androidInfo;
    var status = android.version.sdkInt < 33
        ? await Permission.storage.request()
        : PermissionStatus.granted;

    if (status != PermissionStatus.granted) {
      status = await Permission.storage.request();
    }

    return status;
  }
}