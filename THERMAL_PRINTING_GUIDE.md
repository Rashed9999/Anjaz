# طباعة الإيصالات الحرارية + البلوتوث — دليل التنفيذ

## 1) الباكند — جاهز ومُختبَر ✅

أُضيفت نقطة نهاية تُصيّر إيصالاً حرارياً بمقاس **58مم أو 80مم** عند الطلب:

```
GET /api/v1/amial/receipts/{id}/thermal?size=58   → PDF عرض 164pt (58مم)
GET /api/v1/amial/receipts/{id}/thermal?size=80   → PDF عرض 227pt (80مم)
```

- يتطلّب مصادقة (توكن العميل/التاجر)؛ لا يُطبع إيصال غيرك (محمي ضدّ IDOR).
- يحوي: اسم النشاط، رقم الإيصال، التاريخ، المبلغ (كبير)، الرسوم/الصافي، المرسِل/المستلم،
  رمز التحقّق + **QR حقيقي** (SVG)، تذييل.
- المقاس غير المدعوم يعود لـ58مم افتراضياً.
- **4 اختبارات** تُثبت المقاسات والحماية (`ThermalReceiptTest`).

القالب: `resources/views/receipts/thermal.blade.php` (متجاوب حسب `$widthMm`).

---

## 2) تطبيق Flutter — الطباعة عبر البلوتوث (للتنفيذ على جهاز فيه Flutter SDK)

> **ملاحظة صدق:** لا أستطيع تشغيل/اختبار Flutter في بيئتي (SDK غير مثبّت + يحتاج
> طابعة بلوتوث حقيقية). لذا أوثّق التنفيذ الدقيق بدل شحن كود غير مُختبَر. الكود أدناه
> مُراجَع ومتوافق مع الحزم الحالية، ويُشغَّل على جهازك.

### أ) الطريقة الموصى بها: ESC/POS مباشرة (الأسرع والأنظف لطابعات POS)

طابعات نقاط البيع الحرارية تفهم أوامر **ESC/POS** عبر البلوتوث — أخفّ وأدقّ من إرسال PDF.

**1. أضِف الحزم إلى `pubspec.yaml`:**
```yaml
  esc_pos_utils_plus: ^2.0.4      # بناء أوامر ESC/POS
  print_bluetooth_thermal: ^1.1.4 # اتصال البلوتوث بالطابعة الحرارية
```

**2. أذونات Android** (`android/app/src/main/AndroidManifest.xml`):
```xml
<uses-permission android:name="android.permission.BLUETOOTH" />
<uses-permission android:name="android.permission.BLUETOOTH_CONNECT" />
<uses-permission android:name="android.permission.BLUETOOTH_SCAN" />
```

**3. خدمة الطباعة** (`lib/features/receipts/services/thermal_printer_service.dart`):
```dart
import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';

class ThermalPrinterService {
  /// يعرض الطابعات المقترنة عبر البلوتوث.
  Future<List<BluetoothInfo>> pairedPrinters() =>
      PrintBluetoothThermal.pairedBluetooths;

  /// يتّصل بطابعة بعنوان MAC.
  Future<bool> connect(String mac) =>
      PrintBluetoothThermal.connect(macPrinterAddress: mac);

  /// يطبع إيصالاً. size: PaperSize.mm58 أو PaperSize.mm80.
  Future<void> printReceipt(Receipt r, {PaperSize size = PaperSize.mm58}) async {
    final profile = await CapabilityProfile.load();
    final gen = Generator(size, profile);
    final List<int> bytes = [];

    bytes.addAll(gen.text('أميال باي',
        styles: const PosStyles(align: PosAlign.center, bold: true, height: PosTextSize.size2)));
    bytes.addAll(gen.text('إيصال دفع', styles: const PosStyles(align: PosAlign.center)));
    bytes.addAll(gen.hr());
    bytes.addAll(gen.row([
      PosColumn(text: 'رقم الإيصال', width: 6),
      PosColumn(text: r.receiptNumber, width: 6, styles: const PosStyles(align: PosAlign.right)),
    ]));
    bytes.addAll(gen.row([
      PosColumn(text: 'التاريخ', width: 6),
      PosColumn(text: r.issuedAt, width: 6, styles: const PosStyles(align: PosAlign.right)),
    ]));
    bytes.addAll(gen.hr());
    bytes.addAll(gen.text('${r.amount} ريال',
        styles: const PosStyles(align: PosAlign.center, bold: true, height: PosTextSize.size3)));
    bytes.addAll(gen.hr());
    // QR للتحقّق
    bytes.addAll(gen.qrcode('https://api.amialpay.com/v/${r.verificationCode}'));
    bytes.addAll(gen.text('شكراً لك', styles: const PosStyles(align: PosAlign.center)));
    bytes.addAll(gen.feed(2));
    bytes.addAll(gen.cut());

    await PrintBluetoothThermal.writeBytes(bytes);
  }
}
```

**4. الاستخدام في شاشة الإيصال** (`receipt_detail_screen.dart`):
```dart
// زرّ "طباعة"
IconButton(
  icon: const Icon(Icons.print),
  onPressed: () async {
    final svc = ThermalPrinterService();
    final printers = await svc.pairedPrinters();
    // اعرض قائمة اختيار الطابعة (BottomSheet)، ثم:
    await svc.connect(selectedMac);
    await svc.printReceipt(receipt, size: PaperSize.mm58); // أو mm80
  },
),
```

### ب) بديل: طباعة الـPDF الحراري (إن أراد التاجر مقاسات ورقية)

استخدم نقطة الباكند الجاهزة + حزمة `printing`:
```yaml
  printing: ^5.13.0
```
```dart
final url = 'https://api.amialpay.com/api/v1/amial/receipts/${id}/thermal?size=58';
final bytes = await http.get(Uri.parse(url), headers: {'Authorization': 'Bearer $token'});
await Printing.layoutPdf(onLayout: (_) async => bytes.bodyBytes); // يفتح حوار الطباعة
```

---

## 3) الخلاصة

| الجزء | الحالة |
|---|---|
| قالب حراري 58/80مم (باكند) | ✅ مبنيّ ومُختبَر (4 اختبارات) |
| نقطة `/receipts/{id}/thermal` | ✅ تعمل، محمية، مع QR |
| طباعة بلوتوث ESC/POS (Flutter) | 📝 موثّقة بالكامل — تُنفَّذ على جهاز فيه SDK + طابعة |
| طباعة PDF حراري (Flutter) | 📝 موثّقة (حزمة printing) |

**للتاجر:** بعد تنفيذ الجزء الـFlutter، سيضغط "طباعة" → يختار طابعته البلوتوث → يخرج
الإيصال فوراً على الرول الحراري (58 أو 80مم).
