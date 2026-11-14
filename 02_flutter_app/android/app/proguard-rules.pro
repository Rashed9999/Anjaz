# AMIAL-SEC-OBFUSCATE-001 — قواعدُ R8 للإصدار.
# Flutter وإضافاتُه تجلب قواعدَها الخاصّة (consumer rules)؛ هنا ما لا تجلبه.
-dontwarn com.google.android.play.core.**
-dontwarn com.google.mlkit.**
-keep class io.flutter.** { *; }
-keep class amialpay.com.** { *; }
# يُحفظ رقمُ السطر لتتبّع انهيارات الإنتاج (يُقلب بملفّات --split-debug-info).
-keepattributes SourceFile,LineNumberTable
