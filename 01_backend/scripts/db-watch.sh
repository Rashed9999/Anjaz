#!/usr/bin/env bash
# AMIAL-DB-FORENSICS-001 — يرصد لحظةَ موت MariaDB وما حولها.
#
# لا يُشخَّص ما لا يُرى: ماتت ثلاثَ مرّاتٍ بلا أثر. فهذا يعاين كلَّ ثانيةٍ
# ويكتب سطراً واحداً عند الاختفاء — ومعه الذاكرةُ الحرّة وأثقلُ ثلاث
# عمليّات. وهما ما يفرّق OOM عن قتلِ مجموعةٍ خارجيّ.
LOG=${1:-/tmp/db-watch.log}
prev=1
while true; do
  n=$(pgrep -c -x mariadbd 2>/dev/null || echo 0)
  s=$(pgrep -c -x mariadbd-safe 2>/dev/null || echo 0)
  if [[ "$n" -eq 0 && "$prev" -ne 0 ]]; then
    {
      echo "════ ماتت $(date '+%F %T') ════"
      echo "mariadbd=$n  mariadbd-safe=$s   (الحارسُ حيٌّ؟ ${s})"
      echo "الذاكرة: $(free -m | awk '/^Mem:/{print "total="$2" used="$3" available="$7}')"
      echo "أثقل ٣:"
      ps -eo rss,pid,comm --sort=-rss | head -4 | sed 's/^/  /'
      echo "آخرُ سطرين من سجلّ الخطأ:"
      tail -2 /var/log/mysql/error.log 2>/dev/null | sed 's/^/  /' || echo "  (لا سجلّ)"
      echo
    } >> "$LOG"
  fi
  prev=$n
  sleep 1
done
