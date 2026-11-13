# حوكمة مهارات أميال

## الغرض

المهارة تستدعى عند تحقق وصفها، لا لأنها موجودة. عند تداخل المهارات ابدأ بمهارة الحدود أو الأثر، ثم استخدم المرجع المتخصص الأقل الذي يغطي المهمة.

## ترتيب الاستدعاء

| نوع العمل | البداية | ثم عند الحاجة |
|---|---|---|
| تعديل قائم | `amial-impact` | `amial-architect` ثم المتخصص |
| مال أو رصيد أو قيد | `amial-financial-core` | `amial-wallet`، `amial-double-entry`، `amial-financial-truth` |
| وكيل أو تسوية | `amial-agent-operations` | `amial-settlement`، `amial-rbac`، `amial-fraud` |
| هوية أو KYC | `amial-muwathaq-boundary` | `amial-api`، `amial-rbac` |
| ربط منصات | `amial-cross-platform-contracts` | `amial-api`، `amial-database` |
| دمج أو نشر | `amial-release-gate` | `amial-production-readiness`، `code-review` |
| واجهة | `amial-interactive-ui` | `amial-flutter` أو `amial-banking-ui` |

## تقليل الحمل

- مهارات `amial-admin-command` و`amial-printing` و`amial-production-readiness` و`amial-financial-truth` مراجع متخصصة طويلة: لا تحملها إلا عند تحقق مجالها المحدد.
- لا تستدعي مهارات القطاعات التجارية أو الطباعة في عملية وكيل عادية.
- القواعد المتخصصة تكمل القواعد العامة ولا تستبدل بوابة الإصدار أو تحليل الأثر.
