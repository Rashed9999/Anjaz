# عقد الربط: أميال باي ←→ موثّق

موثّق خدمة مراجعة على `muwathaq.amialpay.com`. ليس بوابة تسجيل منفصلة:
كل واجهات التسجيل تبقى في أميال باي، وتبدأ هذه القناة بعد إنشاء الحساب في
حالة انتظار.

## 1. أميال باي يرسل قضية جديدة

`POST {MUWATHAQ_BASE_URL}/api/v1/internal/applications`

الرؤوس الإلزامية:

```text
Content-Type: application/json
Accept: application/json
Idempotency-Key: <event_ulid>
X-Amial-Timestamp: <unix seconds>
X-Amial-Signature: HMAC_SHA256("<timestamp>.<raw JSON body>", MUWATHAQ_BRIDGE_SECRET)
```

يُقبل الرد `200` أو `201` أو `202` فقط. أي فشل يبقي الرسالة في صندوق أميال
باي ويعيد إرسالها تلقائياً. يجب أن يحفظ موثّق مفتاح `Idempotency-Key` ويعيد
نجاحاً للحدث المكرر من دون إنشاء قضية ثانية.

الحمولة تتضمن `application_ulid` ومسار التحقق ونوع الحساب وبيانات اتصال
المالك/المنشأة اللازمة للمراجعة. لا تتضمن كلمة مرور أو PIN أو محفظة أو صورة
هوية أو رقم هوية.

## 2. موثّق يعيد القرار

`POST https://amialpay.com/api/v1/internal/muwathaq/applications/{application_ulid}/decision`

الرؤوس:

```text
Content-Type: application/json
X-Muwathaq-Timestamp: <unix seconds>
X-Muwathaq-Signature: HMAC_SHA256("<timestamp>.<raw JSON body>", MUWATHAQ_DECISION_SECRET)
```

الجسم:

```json
{
  "decision": "approved | rejected | needs_more_info | under_review",
  "decision_reference": "unique-review-reference",
  "reason": "optional reviewer message",
  "context": {
    "policy_version": "women-tier-1-v1",
    "reviewer_reference": "reviewer-123",
    "risk_band": "standard",
    "notes_code": "RESIDENCE_CONFIRMED",
    "verified_phone": true,
    "verified_residence_governorate": "YE-AD"
  }
}
```

أميال باي وحده ينشئ المحفظة بعد قرار `approved`. لا يقبل القرار بلا توقيع
صحيح، ولا يقبل قراراً مختلفاً لقضية حُسمت سابقاً.

## إعداد النشر

على خادم أميال باي تُضبط المتغيرات التالية في بيئة النشر فقط:

```text
MUWATHAQ_BASE_URL=https://muwathaq.amialpay.com
MUWATHAQ_BRIDGE_SECRET=<secret used only for Amial Pay -> Muwathaq>
MUWATHAQ_DECISION_SECRET=<different secret used only for Muwathaq -> Amial Pay>
```

لا يُحوّل DNS ولا يُفعّل الرابط في الإنتاج قبل نشر مستقبِل موثّق وTLS وتخزين
مفاتيح التكرار. إذا كان موثّق غير متاح، لا يضيع التسجيل ولا تُنشأ محفظة.
