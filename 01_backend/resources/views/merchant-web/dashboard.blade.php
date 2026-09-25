<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $storeName }} | لوحة أميال للأعمال</title>
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        *{box-sizing:border-box}body{margin:0;background:#f4f8f7;color:#142e28;font-family:Tahoma,"Segoe UI",sans-serif}
        .shell{display:flex;min-height:100vh}.side{width:258px;flex-shrink:0;background:#112f29;color:#fff;padding:27px 15px;display:flex;flex-direction:column;gap:7px}
        .brand{font-size:22px;font-weight:900;margin:0 12px 15px}.brand span{color:#e8bd59}.store{background:#ffffff12;border:1px solid #ffffff25;padding:14px;border-radius:14px;margin-bottom:12px;line-height:1.9;font-size:13px}
        .store strong{display:block;font-size:17px}.store small{color:#d1e6df}.nav{width:100%;border:0;text-align:right;background:transparent;color:#d9e9e3;border-radius:11px;padding:14px 15px;cursor:pointer;font:600 14px Tahoma}
        .nav.active,.nav:hover{background:#267b5c;color:white}.logout{margin-top:auto}.logout button{background:none;border:1px solid #ffffff55;color:white;width:100%;padding:13px;border-radius:11px;cursor:pointer}
        main{flex:1;min-width:0;padding:29px clamp(15px,3vw,48px)}.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px}.top h1{margin:0;font-size:27px}
        .muted{color:#688078;font-size:13px;line-height:1.8}.badge{display:inline-block;border:1px solid #b9dccb;color:#146e4c;background:#e6f5ed;border-radius:999px;padding:6px 13px;font-size:12px}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:15px;margin-bottom:20px}
        .metric,.panel{background:white;border:1px solid #dceae5;border-radius:17px;padding:22px;box-shadow:0 9px 35px #15362e08}.metric small{display:block;color:#60776c}.metric strong{display:block;font-size:24px;margin-top:11px;word-break:break-word}
        .panel{margin-bottom:15px}.panel h2{margin:0 0 15px;font-size:18px}.buttons{display:flex;flex-wrap:wrap;gap:10px}
        button.action{background:#167550;color:white;border:0;border-radius:10px;padding:12px 18px;cursor:pointer;font-weight:700}
        button.secondary{background:#eaf4ef;color:#136447}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;text-align:right;min-width:570px}th,td{padding:13px;border-bottom:1px solid #e7eeeb;font-size:13px}th{color:#5c7367;background:#f7faf9}tr:last-child td{border:0}
        form.editor{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end}
        label.field{font-size:12px;color:#3a6254;display:flex;flex-direction:column;gap:7px}
        .field input,.field select{padding:12px;border:1px solid #cbdcd5;border-radius:9px;min-height:44px;font:14px Tahoma;width:100%}
        .error{border:1px solid #e7af9e;background:#fff0ea;color:#9f3420;padding:14px;border-radius:12px}
        .note{background:#edf6f3;border-right:3px solid #21956e;padding:15px;border-radius:7px;color:#35594a;font-size:13px;line-height:1.9}
        #message{position:fixed;bottom:21px;left:21px;background:#173f32;color:white;border-radius:11px;padding:14px 20px;display:none;max-width:min(90vw,480px);z-index:9}
        [hidden]{display:none!important}@media(max-width:850px){.shell{display:block}.side{width:100%;padding:13px;display:block}.brand{margin:4px 9px 10px}.store{display:none}.nav{display:inline-block;width:auto;font-size:12px;padding:11px}.logout{display:inline-block}.logout button{padding:10px}main{padding:20px 13px}.top h1{font-size:21px}}
    </style>
</head>
<body>
<div class="shell">
    <aside class="side" aria-label="التنقل داخل لوحة المنشأة">
        <div class="brand">أميال <span>باي</span> <small style="font-size:12px">الأعمال</small></div>
        <div class="store"><strong>{{ $storeName }}</strong><small>{{ $businessType }} · {{ $plan }}</small></div>
        <button class="nav active" data-tab="overview">◈ نظرة عامة</button>
        <button class="nav" data-tab="wallet">◉ المحفظة وكشف الحساب</button>
        <button class="nav" data-tab="sector">⌁ تشغيل قطاع {{ $businessType }}</button>
        <button class="nav" data-tab="products">▤ المنتجات والمخزون</button>
        <button class="nav" data-tab="branches">⌂ الفروع</button>
        <button class="nav" data-tab="staff">♙ الموظفون والصلاحيات</button>
        <button class="nav" data-tab="devices">▣ أجهزة نقاط البيع</button>
        <button class="nav" data-tab="reports">▥ التقارير</button>
        <button class="nav" data-tab="settings">⚙ الهوية والفواتير</button>
        <button class="nav" data-tab="plans">✧ باقتي ومميزاتي</button>
        <form class="logout" method="post" action="{{ route('merchant.web.logout') }}">@csrf
            <button type="submit">تسجيل الخروج</button>
        </form>
    </aside>
    <main>
        <header class="top">
            <div><h1 id="page-title">نظرة عامة</h1><p class="muted">{{ $storeName }} · بيانات حيّة من حساب المنشأة نفسه</p></div>
            <span class="badge">{{ $businessType }} · {{ $plan }}</span>
        </header>
        <div id="content" aria-live="polite"><div class="panel">جارٍ تحميل بيانات المنشأة…</div></div>
    </main>
</div>
<div id="message" role="status"></div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function(){
  'use strict';
  const routes = @json([
      'overview' => route('merchant.web.data.overview'),
      'sector' => route('merchant.web.data.sector'),
      'sectorProducts' => route('merchant.web.data.sector.products'),
      'sectorProductsCreate' => route('merchant.web.data.sector.products.create'),
      'sectorOperations' => route('merchant.web.data.sector.operations'),
      'plans' => route('merchant.web.data.plans'),
      'stats' => route('merchant.web.data.stats'),
      'wallet' => route('merchant.web.data.wallet'),
      'ledger' => route('merchant.web.data.ledger'),
      'products' => route('merchant.web.data.products'),
      'productsCreate' => route('merchant.web.data.products.create'),
      'branches' => route('merchant.web.data.branches'),
      'branchesCreate' => route('merchant.web.data.branches.create'),
      'roles' => route('merchant.web.data.roles'),
      'rolesCreate' => route('merchant.web.data.roles.create'),
      'staff' => route('merchant.web.data.staff'),
      'staffCreate' => route('merchant.web.data.staff.create'),
      'devices' => route('merchant.web.data.devices'),
      'deviceActivation' => route('merchant.web.data.devices.activate'),
      'receipts' => route('merchant.web.data.receipts'),
      'receiptsSave' => route('merchant.web.data.receipts.save'),
      'login' => route('merchant.web.login')
  ]);
  const csrf = @json(csrf_token());
  const actualSector = @json($businessTypeCode);
  const actualSectorName = @json($businessType);
  const titles={overview:'نظرة عامة',sector:'تشغيل قطاع '+actualSectorName,wallet:'المحفظة وكشف الحساب',products:'المنتجات والمخزون',branches:'الفروع',staff:'الموظفون والصلاحيات',devices:'أجهزة نقاط البيع',reports:'التقارير',settings:'الهوية والفواتير',plans:'باقتي ومميزاتي'};
  let active='overview';const content=document.getElementById('content'),notice=document.getElementById('message');
  function node(tag,text,className){const e=document.createElement(tag);if(text!==undefined&&text!==null)e.textContent=String(text);if(className)e.className=className;return e}
  function box(title){const p=node('div',null,'panel');p.append(node('h2',title));content.append(p);return p}
  function metric(title,value){const d=node('div',null,'metric');d.append(node('small',title),node('strong',value===undefined||value===null?'غير متاح':String(value)));return d}
  function money(v){if(v===undefined||v===null||v==='')return'غير متاح';const bits=String(v).split('.');return bits[0].replace(/\B(?=(\d{3})+(?!\d))/g,',')+(bits[1]?'.'+bits[1].slice(0,2):'')+' ر.ي'}
  function grid(items){const g=node('div',null,'grid');items.forEach(x=>g.append(metric(x[0],x[1])));content.append(g)}
  function hint(p,msg){p.append(node('p',msg,'note'))}
  function table(p,cols,rows){const wrap=node('div',null,'table-wrap'),t=node('table'),thead=node('thead'),h=node('tr'),body=node('tbody');cols.forEach(x=>h.append(node('th',x[0])));thead.append(h);t.append(thead);(rows||[]).forEach(row=>{const tr=node('tr');cols.forEach(c=>tr.append(node('td',c[1](row))));body.append(tr)});t.append(body);wrap.append(t);p.append(wrap);if(!rows||rows.length===0)p.append(node('p','لا توجد سجلات لهذه المنشأة حاليًا.','muted'))}
  function message(s){notice.textContent=s;notice.style.display='block';setTimeout(()=>notice.style.display='none',4800)}
  async function api(key,body,url){const init={credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}};if(body!==undefined){init.method='POST';init.headers['Content-Type']='application/json';init.headers['Idempotency-Key']='mw-'+Date.now()+'-'+Math.random().toString(36).slice(2);init.body=JSON.stringify(body)}const res=await fetch(url||routes[key],init);if(res.status===401){window.location.href=routes.login;throw Error('انتهت الجلسة')}const json=await res.json();if(!res.ok||json.success===false)throw Error(json.message||'لم ينجح تحميل البيانات');return json.meta||{}}
  function form(p,fields,button,submit){const f=node('form',null,'editor');fields.forEach(([key,label,type,options])=>{const l=node('label',label,'field');let inp;if(options){inp=node('select');options.forEach(o=>{const op=node('option',o.label);op.value=o.value;inp.append(op)})}else{inp=node('input');inp.type=type||'text';if(type==='number'){inp.step='any';inp.min='0'}if(type==='password')inp.autocomplete='new-password'}inp.name=key;inp.required=['name','price','trade_name','sale_price','base_price','price_per_liter','display_name','employee_code','password'].includes(key);l.append(inp);f.append(l)});const btn=node('button',button,'action');btn.type='submit';f.append(btn);f.addEventListener('submit',async ev=>{ev.preventDefault();btn.disabled=true;try{const data=Object.fromEntries(new FormData(f).entries());Object.keys(data).forEach(k=>{if(data[k]==='')delete data[k]});const result=await submit(data);if(result.activation_code){f.replaceChildren();const code=node('strong',result.activation_code);code.style.fontSize='29px';code.style.letterSpacing='5px';const secret=node('div',null,'note');secret.append(node('p','رمز التفعيل (صالح لمرة واحدة، حتى '+result.expires_at+')'),code);const copy=node('button','نسخ الرمز','action secondary');copy.type='button';copy.addEventListener('click',()=>navigator.clipboard.writeText(result.activation_code).then(()=>message('تم نسخ الرمز')));secret.append(copy);p.append(secret);message('تم إنشاء رمز التفعيل؛ انسخه قبل مغادرة الصفحة')}else{message(result.message||'تم الحفظ');await load(active)}}catch(e){message(e.message)}finally{btn.disabled=false}});p.append(f)}
  async function overview(){const [o,s]=await Promise.all([api('overview'),api('stats')]);const c=o.counts||{};grid([['مبيعات اليوم',money(s.today_sales)],['رصيد محفظة المنشأة',money(s.current_balance)],['المرتجعات اليوم',money(s.today_refunds)],['الموظفون النشطون',c.active_employees],['أجهزة البيع المتصلة',c.active_device_sessions],['الورديات المفتوحة',c.open_shifts]]);const p=box('حالة التشغيل');hint(p,'تُقيّد مدفوعات أميال لصالح محفظة المنشأة، وتُسجّل العمليات مع الموظف والفرع والجهاز. المبيعات النقدية تبقى في درج النقدية حتى تسويتها.');table(p,[['آخر الورديات',r=>r.opened_by_name||'—'],['الفرع',r=>r.branch_name||'—'],['الفتح',r=>r.opened_at||'—']],o.open_shifts||[])}
  async function wallet(){const [w,l]=await Promise.all([api('wallet'),api('ledger')]);const r=w.report||{},v=r.wallet||{};grid([['رصيد المحفظة',money(v.balance)],['وارد المحفظة خلال الفترة',money(v.received)],['صادر المحفظة خلال الفترة',money(v.paid_out)],['حركة المحفظة الصافية',money(v.net_movement)]]);const p=box('كشف قيود المحفظة');hint(p,'مصدر الأرقام هو الدفتر المالي، وليس مجموع المبيعات النقدية أو ديون العملاء.');table(p,[['التاريخ',x=>x.date],['البيان',x=>x.description||x.source_type],['الاتجاه',x=>x.direction==='credit'?'وارد':'صادر'],['المبلغ',x=>money(x.amount)],['المرجع',x=>x.reference]],l.entries||[])}
  async function products(){
    const data=(await api('sectorProducts')).result||{};
    const p=box('أصناف '+actualSectorName);
    if(actualSector==='fuel'){
      table(p,[['الوقود',x=>x.name],['رمز المنتج',x=>x.product_code||'—'],['سعر اللتر',x=>money(x.price_per_liter)]],data.products||[]);
    }else if(actualSector==='pharmacy'){
      table(p,[['الدواء',x=>x.trade_name],['الاسم العلمي',x=>x.generic_name||'—'],['الباركود',x=>x.barcode||'—'],['سعر البيع',x=>money(x.sale_price)],['المخزون',x=>x.current_stock??'—']],data.products||[]);
    }else if(actualSector==='wholesale'){
      table(p,[['الصنف',x=>x.name],['SKU',x=>x.sku||'—'],['السعر الأساسي',x=>money(x.base_price)],['المخزون',x=>x.current_stock??'—']],data.products||[]);
    }else{
      table(p,[['الصنف',x=>x.name],['الباركود',x=>x.barcode||'—'],['سعر البيع',x=>money(x.price)],['الكمية',x=>x.quantity??'—']],data.products||[]);
    }
    const f=actualSector==='fuel'?[['name','اسم الوقود'],['product_code','رمز المنتج'],['price_per_liter','سعر اللتر','number']]:
      actualSector==='pharmacy'?[['trade_name','الاسم التجاري'],['generic_name','الاسم العلمي'],['barcode','الباركود'],['sale_price','سعر البيع','number'],['cost_price','سعر الشراء','number']]:
      actualSector==='wholesale'?[['name','اسم الصنف'],['sku','رمز SKU'],['barcode','الباركود'],['base_price','السعر الأساسي','number'],['initial_stock','المخزون الأولي','number']]:
      [['name','اسم المنتج'],['price','سعر البيع','number'],['quantity','الكمية','number'],['barcode','الباركود']];
    const create=box('إضافة صنف إلى '+actualSectorName);
    hint(create,'الحقول ومخزون الأصناف يختلفان بحسب القطاع. ينفّذ الخادم حدود باقتك وصلاحياتك قبل الحفظ.');
    form(create,f,'حفظ الصنف',d=>api('sectorProductsCreate',d));
  }
  function labelValue(p,label,value){const d=node('div',null,'metric');d.append(node('small',label),node('strong',value??'—'));p.append(d)}
  async function sector(){
    const [data,ops]=await Promise.all([
      api('sector'),api('sectorOperations').catch(e=>({unavailable:e.message})),
    ]);
    const d=data.result||{},o=ops.result||{};
    let cards=[];
    switch(actualSector){
      case 'quick_sale':
        cards=[['مبيعات اليوم',money(d.total_all)],['عدد البيعات',d.sales_count],['المحصل اليوم',money(d.realized_revenue)],['الذمم غير المحصلة',money(d.outstanding_credit_total)]];break;
      case 'retail':
        cards=[['مواقع المخزون',d.locations?.length],['تحويلات بانتظار الموافقة',d.pending?.transfers_to_approve],['جرد بانتظار المراجعة',d.pending?.counts_in_review],['تحديثات سعر معلّقة',d.pending?.prices_proposed]];break;
      case 'fuel':
        cards=[['مبيعات الوقود',money(d.today?.total_amount)],['اللترات المباعة',d.today?.total_liters],['عدد البيعات',d.today?.sales_count],['ساعة الذروة',d.today?.peak_hour===null?'غير متاحة':d.today?.peak_hour]];break;
      case 'pharmacy':
        cards=[['مبيعات الصيدلية',money(d.today?.total_amount)],['عدد البيعات',d.today?.sales_count],['الأدوية',d.products_count],['عملاء الصيدلية',d.customers_count]];break;
      case 'wholesale':
        cards=[['فواتير اليوم',d.today?.invoices_count],['قيمة الفواتير',money(d.today?.total_amount)],['إجمالي الذمم',money(d.total_receivable)],['المتأخرات',money(d.overdue_amount)]];break;
      case 'restaurant':
        cards=[['طاولات المطعم',d.count],['الطاولات المشغولة',(d.tables||[]).filter(t=>t.status==='occupied').length],['طاولات متاحة',(d.tables||[]).filter(t=>t.status==='available').length]];break;
      default: cards=[['القطاع',actualSectorName]];
    }
    grid(cards);
    const p=box('مساحة إدارة '+actualSectorName);
    if(ops.unavailable){hint(p,'بعض الوظائف غير متاحة حاليًا: '+ops.unavailable);return}
    if(actualSector==='fuel'){
      table(p,[['المضخة',x=>x.pump_name||'مضخة '+x.pump_number],['رقم',x=>x.pump_number],['النوع',x=>x.pump_type||'—']],o.pumps||[]);
    }else if(actualSector==='pharmacy'){
      table(p,[['التنبيه',x=>x.product?.trade_name||x.type||'تنبيه مخزون'],['الدرجة',x=>x.severity||'—'],['التاريخ',x=>x.created_at||'—']],o.alerts||[]);
    }else if(actualSector==='wholesale'){
      table(p,[['الفاتورة',x=>x.invoice_number||x.id],['الحالة',x=>x.status],['الإجمالي',x=>money(x.total_amount)],['المتبقي',x=>money(x.balance_due)]],o.invoices||[]);
    }else if(actualSector==='retail'){
      const cat=o.tree||[];hint(p,'التصنيفات وبيانات المخزون تُقرأ من محرك التجزئة مباشرةً.');table(p,[['التصنيف',x=>x.name||x.name_ar||'—'],['الكود',x=>x.code||'—']],Array.isArray(cat)?cat:[]);
    }else if(actualSector==='restaurant'){
      table(p,[['الطاولة',x=>x.label],['المقاعد',x=>x.seats],['الحالة',x=>x.status]],o.tables||[]);
    }else{
      table(p,[['الطلب المعلق',x=>x.label||x.ulid||x.id],['الإنشاء',x=>x.created_at||'—']],o.tickets||[]);
    }
    hint(p,'هذه البيانات من وحدة قطاع منشأتك المسجّل؛ القطاعات الأخرى لا تمنح وصولًا إلى أعمالها.');
  }
  function limitText(v){return v===-1?'بلا حد':v===0?'غير متاح':v??'—'}
  function capabilityStatus(row){
    if(row.capability?.status==='coming_soon'||row.state==='coming_soon')return'قريباً';
    return {available:'متاحة',locked_by_plan:'تحتاج ترقية',locked_by_role:'تحتاج صلاحية',limit_reached:'وصلت للحد',not_applicable:'خاصة بقطاع آخر'}[row.state]||'غير متاحة';
  }
  async function plans(preview){
    const suffix=preview?'?preview_sector='+encodeURIComponent(preview):'';
    const data=await api('plans',undefined,routes.plans+suffix);
    const current=data.current_plan||{},usage=data.usage||{},manifest=data.manifest||{},comparison=data.comparison||{};
    const p=box('باقتي الحالية');
    const body=node('div',null,'grid');
    [['الباقة',current.name],['السعر الشهري',(current.price_monthly??0)+' ر.س'],['انتهاء الاشتراك',current.expires_at?new Date(current.expires_at).toLocaleDateString('ar-YE'):'دون موعد انتهاء'],['عمليات الشهر',(usage.monthly_operations?.current??0)+' / '+limitText(usage.monthly_operations?.max)],['الأصناف',(usage.products?.current??0)+' / '+limitText(usage.products?.max)],['الموظفون',(usage.employees?.current??0)+' / '+limitText(usage.employees?.max)]].forEach(x=>labelValue(body,x[0],x[1]));
    p.append(body);
    if(current.is_expired)hint(p,'انتهى اشتراكك المدفوع، والباقات والأذونات محسوبة حالياً على المجانية حتى التجديد.');
    else hint(p,'الباقة الفعلية وعداداتها من نظام الاستحقاقات نفسه الذي يتحكم في نقاط البيع.');
    const previewBox=box('مقارنة الباقات حسب القطاع');
    const select=node('select');select.setAttribute('aria-label','قطاع المقارنة');
    select.style.cssText='border:1px solid #b7d1c6;border-radius:10px;padding:13px;max-width:100%;font:inherit';
    (data.sectors||[]).forEach(v=>{const op=node('option',v.label+(v.is_my_sector?' — نشاطي':''));op.value=v.code;op.selected=v.code===data.preview_sector;select.append(op)});
    select.addEventListener('change',async()=>{content.replaceChildren(node('div','جارٍ تحديث المقارنة…','panel'));try{await plans(select.value)}catch(e){content.replaceChildren(node('div',e.message,'error'))}});
    previewBox.append(select);
    if(data.preview_only)hint(previewBox,'معاينة أسعار ومزايا قطاع آخر فقط؛ لا تُنشئ نشاطاً جديداً ولا تفتح صلاحياته لحسابك.');
    else hint(previewBox,'المميزات أدناه محسوبة لقطاع منشأتك من نفس السجل الذي يقرّر السماح أو المنع عند الاستخدام.');
    if(comparison.vertical_note)previewBox.append(node('p',comparison.vertical_note.replaceAll('**',''),'muted'));
    const cards=node('div',null,'grid');
    let previousCodes=new Set();
    const liveByCode=new Map((data.live_plans||[]).map(plan=>[plan.code,plan]));
    (comparison.plans||[]).forEach(plan=>{
      const card=node('div',null,'metric');
      card.style.border=plan.code===current.code?'2px solid #238a66':'1px solid #dceae5';
      card.append(node('small',plan.code===current.code?'باقتك الحالية':'باقة متاحة'),node('strong',plan.label),node('h3',(plan.price_monthly??0)+' ر.س شهرياً'));
      card.append(node('p','السنوي: '+(plan.price_annual??0)+' ر.س','muted'));
      if(plan.pitch?.headline)card.append(node('p',plan.pitch.headline,'muted'));
      const limits=node('div',null,'muted');(plan.limits||[]).forEach(row=>limits.append(node('p',row.label+': '+row.text)));card.append(limits);
      const catalog=liveByCode.get(plan.code);
      const liveCapabilities=(catalog?.capabilities||[]).filter(x=>x.status!=='coming_soon');
      const newlyAdded=liveCapabilities.filter(x=>!previousCodes.has(x.code));
      previousCodes=new Set(liveCapabilities.map(x=>x.code));
      const added=node('div',null,'muted');
      added.append(node('strong','مميزات هذه الباقة ('+liveCapabilities.length+')'));
      const summary= node('p','ما تضيفه مقارنة بالباقة السابقة: '+newlyAdded.length+' ميزة');
      added.append(summary);
      const details=node('details'),show=node('summary','عرض الميزات المشمولة بالتفصيل');
      show.style.cssText='cursor:pointer;font-weight:700;color:#146c50;padding:9px 0';
      details.append(show);
      liveCapabilities.forEach(item=>details.append(node('p','✓ '+item.name)));
      if(!liveCapabilities.length)details.append(node('p','لا تتوفر بيانات كتالوج الباقة حالياً.'));
      added.append(details);
      const planned=(catalog?.capabilities||[]).filter(x=>x.status==='coming_soon');
      if(planned.length)added.append(node('p',planned.length+' ميزة قيد التطوير؛ لا تُحسب ضمن المتاح حالياً.'));
      card.append(added);cards.append(card);
    });
    previewBox.append(cards);
    hint(previewBox,'الأسعار بالريال السعودي. التفعيل والتجديد يتمّان حالياً عبر خدمة العملاء، ولا تُنفَّذ خصومات من المحفظة بمجرد اختيار باقة.');
    const live=box('مميزاتي الفعلية — '+data.actual_sector_name);
    if(data.preview_only)hint(live,'هذه حالات القدرات الحالية لقطاعك الحقيقي، وليست للقطاع الذي تستعرض أسعاره.');
    const groups=new Map();
    (manifest.capabilities||[]).forEach(row=>{const group=row.capability?.group||'أخرى';if(!groups.has(group))groups.set(group,[]);groups.get(group).push(row)});
    for(const [group,items]of groups){
      const h=node('h3',group);h.style.marginTop='24px';live.append(h);
      table(live,[['الميزة',x=>x.capability?.name||'—'],['الحالة',x=>capabilityStatus(x)],['ما تتيحه',x=>x.capability?.description||'—']],items);
    }
  }
  async function branches(){const data=await api('branches');const p=box('الفروع التابعة لمنشأتك');table(p,[['اسم الفرع',x=>x.name],['المدينة',x=>x.city||'—'],['العنوان',x=>x.address||'—'],['الحالة',x=>x.is_active?'نشط':'متوقف']],data.branches||[]);const create=box('إضافة فرع');form(create,[['name','اسم الفرع'],['address','العنوان'],['city','المدينة']], 'إنشاء الفرع',d=>api('branchesCreate',d))}
  async function staff(){const [data,roles]=await Promise.all([api('staff'),api('roles')]);const p=box('الموظفون');table(p,[['الموظف',x=>x.display_name],['الرمز',x=>x.employee_code],['الفرع',x=>x.branch_name||'المنشأة'],['الحالة',x=>x.is_active?'نشط':'موقوف']],data.staff||[]);const create=box('إضافة موظف نقطة بيع');const choices=(roles.roles||[]).filter(r=>r.is_active).map(r=>({value:r.id,label:r.name_ar}));form(create,[['display_name','اسم الموظف'],['employee_code','رمز الدخول'],['password','كلمة مرور الموظف','password'],['merchant_role_id','الصلاحية','select',[{value:'',label:'الدور الافتراضي (كاشير)'},...choices]]], 'إنشاء حساب الموظف',d=>api('staffCreate',d))}
  async function devices(){const data=await api('devices');const p=box('الأجهزة المرخصة');table(p,[['الجهاز',x=>x.display_name],['الفرع',x=>x.branch_name||'—'],['الحالة',x=>x.is_active?'نشط':'غير نشط'],['الجلسات',x=>x.live_sessions??0]],data.devices||[]);const create=box('تفعيل جهاز بيع جديد');hint(create,'ينشئ مالك المنشأة رمزًا مؤقتًا صالحًا لمرة واحدة. أدخله في تطبيق نقطة البيع على الجهاز الجديد.');form(create,[['display_name','اسم الجهاز']], 'إنشاء رمز التفعيل',async d=>{return api('deviceActivation',d)})}
  async function reports(){const r=(await api('wallet')).report||{};const sales=r.sales||{},methods=sales.by_payment_method||{};grid([['إجمالي المبيعات',money(sales.gross)],['عدد المبيعات',sales.count],['نقدًا',money(methods.cash)],['عبر أميال',money(methods.amial_pay)],['آجل',money(methods.credit)]]);const p=box('الحركة اليومية');hint(p,'التقرير يفصل المبيعات عن التحصيلات وعن حركة المحفظة؛ لا تُحسب التحويلات الشخصية مبيعات.');table(p,[['الحركة',x=>x.label_ar],['نقدًا',x=>x.available?money(x.cash):'غير متاح'],['أميال',x=>x.available?money(x.amial_pay):'غير متاح'],['آجل',x=>x.available?money(x.credit):'غير متاح']],r.movement?.rows||[])}
  async function settings(){const data=await api('receipts'),s=data.settings||{};const p=box('هوية فاتورة منشأتك');form(p,[['store_name','اسم المنشأة'],['header_note','ترويسة الفاتورة'],['footer_note','تذييل الفاتورة'],['phone','هاتف المنشأة'],['address','عنوان المنشأة'],['paper_width','عرض الطابعة','select',[{value:'58',label:'58 مم'},{value:'80',label:'80 مم'}]]], 'حفظ إعدادات الفاتورة',d=>api('receiptsSave',d));p.querySelectorAll('input,select').forEach(input=>{if(s[input.name]!==undefined&&s[input.name]!==null)input.value=s[input.name];if(input.name==='store_name')input.value=@json($storeName)});hint(p,'إعدادات الفاتورة موحدة بين الويب وكل نقاط البيع. صلاحية طباعة السند متاحة بحسب خصائص القطاع.')}
  const pages={overview,sector,wallet,products,branches,staff,devices,reports,settings,plans};
  async function load(tab){active=tab;document.getElementById('page-title').textContent=titles[tab];document.querySelectorAll('[data-tab]').forEach(e=>{e.classList.toggle('active',e.dataset.tab===tab);e.setAttribute('aria-current',e.dataset.tab===tab?'page':'false')});content.replaceChildren(node('div','جارٍ تحميل بيانات المنشأة…','panel'));try{content.replaceChildren();await pages[tab]()}catch(e){content.replaceChildren();content.append(node('div',e.message||'تعذّر تحميل البيانات','error'))}}
  document.querySelectorAll('[data-tab]').forEach(e=>e.addEventListener('click',()=>load(e.dataset.tab)));
  load('overview');
})();
</script>
</body>
</html>
