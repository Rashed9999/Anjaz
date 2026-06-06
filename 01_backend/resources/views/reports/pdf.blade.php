<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<style>
  body{font-family:'DejaVu Sans',sans-serif;direction:rtl;color:#1A2233;font-size:11px}
  .head{background:#053391;color:#fff;padding:16px 20px;margin-bottom:16px}
  .head h1{font-size:18px;margin:0}
  .head .sub{font-size:10px;opacity:.85;margin-top:4px}
  .head .bar{height:3px;background:#FECA1E;margin-top:8px}
  table{width:100%;border-collapse:collapse;margin:0 16px}
  th{background:#EEF2FB;color:#053391;padding:8px 6px;text-align:right;font-size:10px;border-bottom:2px solid #053391}
  td{padding:7px 6px;text-align:right;font-size:10px;border-bottom:1px solid #ECE6D5}
  tr:nth-child(even){background:#FCFAF2}
  .foot{margin:20px 16px 0;font-size:9px;color:#8B97A8;border-top:1px solid #ECE6D5;padding-top:8px;text-align:center}
</style>
</head>
<body>
  <div class="head">
    <h1>أميال باي — {{ $title }}</h1>
    <div class="sub">تاريخ الإنشاء: {{ $generatedAt }} · عدد السجلات: {{ count($rows) }}</div>
    <div class="bar"></div>
  </div>

  <table>
    <thead>
      <tr>
        @foreach($headers as $h)
          <th>{{ $h }}</th>
        @endforeach
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $row)
        <tr>
          @foreach($row as $cell)
            <td>{{ $cell }}</td>
          @endforeach
        </tr>
      @empty
        <tr><td colspan="{{ count($headers) }}" style="text-align:center;padding:20px">لا توجد بيانات في هذه الفترة</td></tr>
      @endforelse
    </tbody>
  </table>

  <div class="foot">
    أميال باي · تقرير مُولّد آلياً · هذا المستند سرّي ومخصص للجهة الطالبة فقط
  </div>
</body>
</html>
