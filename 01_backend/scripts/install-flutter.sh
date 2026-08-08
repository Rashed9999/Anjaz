#!/usr/bin/env bash
# AMIAL-DART-GATE-001 — تثبيت Flutter لبوّابة التصريف في verify.sh
#
# **لمَ سكربتٌ لا سطرٌ في وثيقة:** الطبقةُ السابعة في verify.sh تُخطّى
# صامتةً إن غاب Flutter. وبيئةُ العمل تُبنى من جديدٍ كلَّ جلسة، فما لا
# يُثبَّت بأمرٍ واحدٍ لا يُثبَّت.
#
# والإصدارُ يُقرأ من قناة stable — **وهي نفسُها ما يستعمله codemagic.yaml**
# (`flutter: stable`). فبوّابةٌ محلّيّةٌ بإصدارٍ مختلف تُطمئن ثمّ يسقط البناء.
set -euo pipefail

DEST="${1:-/opt/flutter}"

if [[ -x "$DEST/bin/flutter" ]]; then
  echo "✓ Flutter مثبَّتٌ سلفاً: $("$DEST/bin/flutter" --version | head -1)"
  exit 0
fi

echo "⏳ قراءة إصدار stable الحاليّ…"
VER=$(curl -sS "https://storage.googleapis.com/flutter_infra_release/releases/releases_linux.json" \
  | python3 -c "import json,sys; d=json.load(sys.stdin); c=d['current_release']['stable']; print(next(r['archive'] for r in d['releases'] if r['hash']==c))")

echo "⏳ تنزيل $VER …"
curl -sSL -o /tmp/flutter.tar.xz \
  "https://storage.googleapis.com/flutter_infra_release/releases/$VER"

mkdir -p "$(dirname "$DEST")"
tar xf /tmp/flutter.tar.xz -C "$(dirname "$DEST")"
rm -f /tmp/flutter.tar.xz

git config --global --add safe.directory "$DEST" 2>/dev/null || true

echo "✓ $("$DEST/bin/flutter" --version | head -1)"
echo "  ثمّ: cd 02_flutter_app && $DEST/bin/flutter pub get"
