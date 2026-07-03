#!/bin/bash
# ══════════════════════════════════════════════════════════
# أميال باي — استعادة نسخة احتياطية
#
# الاستخدام:
#   ./docker/restore.sh backups/amial_pay_20260630_030000.sql.gz
# ══════════════════════════════════════════════════════════

set -e

BACKUP_FILE="$1"
CONTAINER="${DB_CONTAINER:-amial-mysql-prod}"

if [ -z "$BACKUP_FILE" ] || [ ! -f "$BACKUP_FILE" ]; then
    echo "❌ الاستخدام: ./docker/restore.sh path/to/backup.sql.gz"
    exit 1
fi

if [ -f ".env.production" ]; then
    export $(grep -E '^DB_(DATABASE|USERNAME|PASSWORD)=' .env.production | xargs)
fi

echo "⚠️  تحذير: هذا سيستبدل كل بيانات قاعدة البيانات الحالية!"
echo "    الملف: $BACKUP_FILE"
echo "    القاعدة: ${DB_DATABASE}"
read -p "اكتب 'نعم' للتأكيد: " CONFIRM

if [ "$CONFIRM" != "نعم" ]; then
    echo "تمّ الإلغاء."
    exit 0
fi

echo "🔄 جارٍ الاستعادة..."
gunzip < "$BACKUP_FILE" | docker exec -i "$CONTAINER" \
    mysql -u"${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}"

echo "✓ تمّت الاستعادة بنجاح."
echo "  يُنصَح بتشغيل: docker compose -f docker-compose.prod.yml restart amial-app"
