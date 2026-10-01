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
        button.secondary{background:#eaf4ef;color:#136447}
        .link-action{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;background:#167550;color:white;border:0;border-radius:10px;padding:12px 18px;min-height:44px;font-weight:700}
        .warning-note{background:#fff7e8;border-right-color:#ce922b;color:#81520c}.danger-note{background:#fff0ed;border-right-color:#bb4c35;color:#832f23}
        .filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px}.filters label{flex:1 1 180px}.buttons{margin-top:12px}
        .table-wrap{overflow-x:auto}.product-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:16px}.product-tools label{flex:1 1 190px}.product-actions{display:flex;gap:7px;flex-wrap:wrap}.product-actions button{padding:8px 11px;font-size:12px}.product-editor{margin-top:18px;background:#f7fbf9;border:1px solid #d7ece2;border-radius:15px;padding:17px}.product-editor h3{margin:0 0 12px}.product-editor form{margin:0}.product-lookup{margin-top:10px}.scanner-preview{width:100%;max-width:420px;border-radius:12px;display:block;margin:10px auto;background:#16332d;max-height:350px}table{border-collapse:collapse;width:100%;text-align:right;min-width:570px}th,td{padding:13px;border-bottom:1px solid #e7eeeb;font-size:13px}th{color:#5c7367;background:#f7faf9}tr:last-child td{border:0}
        form.editor{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end}
        label.field{font-size:12px;color:#3a6254;display:flex;flex-direction:column;gap:7px}
        .field input,.field select{padding:12px;border:1px solid #cbdcd5;border-radius:9px;min-height:44px;font:14px Tahoma;width:100%}
        .error{border:1px solid #e7af9e;background:#fff0ea;color:#9f3420;padding:14px;border-radius:12px}
        .note{background:#edf6f3;border-right:3px solid #21956e;padding:15px;border-radius:7px;color:#35594a;font-size:13px;line-height:1.9}
        .setup-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin:0 0 20px}.setup-step{border:1px solid #d7e7df;border-radius:12px;padding:11px 12px;background:#f8fbfa;color:#5b7268;font-size:12px;font-weight:700}.setup-step.current{background:#167550;color:#fff;border-color:#167550}.setup-step.done{background:#e5f5ec;border-color:#95d2ae;color:#146e4c}.setup-choice{display:flex;gap:9px;flex-wrap:wrap;margin:0 0 14px}.setup-choice button{border:1px solid #bfd9cd;background:#fff;color:#165a42;border-radius:10px;padding:10px 13px;font:700 13px Tahoma;cursor:pointer}.setup-choice button.active{background:#e4f4eb;border-color:#167550}.setup-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.setup-summary{display:grid;gap:9px;margin:13px 0}.setup-summary div{display:flex;justify-content:space-between;gap:15px;padding:11px 13px;border-radius:10px;background:#f5faf7;border:1px solid #e0eee7}.setup-summary strong{color:#224b3c}.setup-secret{background:#fff9ec;border:1px solid #ead28e;border-radius:14px;padding:16px;margin-top:15px}.setup-code{font:800 27px/1.3 monospace;letter-spacing:4px;direction:ltr;color:#155d45;margin:8px 0}
        #message{position:fixed;bottom:21px;left:21px;background:#173f32;color:white;border-radius:11px;padding:14px 20px;display:none;max-width:min(90vw,480px);z-index:9}
        [hidden]{display:none!important}
        .mobile-bar,.nav-backdrop{display:none}.table-help{display:none}
        .mobile-bar{align-items:center;gap:12px;min-width:0;padding:12px 16px;background:#fff;border-bottom:1px solid #dceae5}
        .nav-toggle{width:48px;height:48px;flex:none;border:1px solid #cbded7;border-radius:12px;background:#eaf5ef;color:#124332;font-size:24px;cursor:pointer}
        .mobile-brand{font-size:20px;font-weight:900;white-space:nowrap}.mobile-brand strong{color:#bd8829}.mobile-brand small{font-size:12px;color:#577065}
        .mobile-store{margin-inline-start:auto;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;font-size:12px;color:#547266}
        @media(max-width:1199px){
          .shell{display:block}.mobile-bar{display:flex;position:sticky;top:0;z-index:19;box-shadow:0 2px 16px #123c3010}
          .side{position:fixed;top:0;right:0;bottom:0;width:min(86vw,340px);padding:24px 15px;z-index:22;overflow-y:auto;visibility:hidden;transform:translateX(105%);transition:transform .22s ease,visibility .22s ease;box-shadow:-18px 0 44px #09291f25}
          .side.open{visibility:visible;transform:translateX(0)}.side .store{display:block}
          .side .nav{display:block;width:100%;min-height:47px;font-size:14px;padding:13px 15px}
          .side .logout{display:block;margin-top:17px}.side .logout button{min-height:44px}
          .nav-backdrop:not([hidden]){display:block;position:fixed;inset:0;background:#09251bc0;z-index:21;width:100%;border:0;cursor:pointer}
          body.merchant-nav-open{overflow:hidden}
          main{padding:21px clamp(13px,3.5vw,32px);width:100%;max-width:100%;min-width:0}
          .top h1{font-size:23px}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.plans-grid{grid-template-columns:1fr}
          .table-wrap{max-width:100%;overflow-x:auto;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch}
          .table-help{display:block;font-size:12px;color:#547568;margin:0 0 9px}
          .panel{padding:clamp(14px,3vw,22px)}.metric{padding:clamp(14px,3vw,22px)}
        }
        @media(max-width:600px){
          .mobile-bar{padding:9px 12px;gap:9px}.mobile-brand{font-size:18px}
          .mobile-store{max-width:33vw}main{padding:16px 12px}
          .top{align-items:flex-start}.top h1{font-size:22px}.grid{gap:10px}
          .metric strong{font-size:clamp(18px,5vw,24px)}.metric small{font-size:12px}
          form.editor{grid-template-columns:1fr}.field input,.field select{min-height:48px}.setup-steps{grid-template-columns:1fr}.setup-summary div{align-items:flex-start;flex-direction:column;gap:4px}
          button.action{min-height:46px}#message{left:10px;right:10px;bottom:12px;max-width:none}
        }
        @media(prefers-reduced-motion:reduce){.side{transition:none}}
    </style>
</head>
<body>
<div class="shell">
    <header class="mobile-bar">
        <button type="button" class="nav-toggle" id="menu-toggle" aria-controls="merchant-side" aria-expanded="false" aria-label="فتح القائمة">☰</button>
        <span class="mobile-brand">أميال <strong>باي</strong> <small>الأعمال</small></span>
        <span class="mobile-store">{{ $storeName }}</span>
    </header>
    <button type="button" class="nav-backdrop" id="nav-backdrop" aria-label="إغلاق القائمة" hidden></button>
    <aside class="side" id="merchant-side" aria-label="التنقل داخل لوحة المنشأة">
        <div class="brand">أميال <span>باي</span> <small style="font-size:12px">الأعمال</small></div>
        <div class="store"><strong>{{ $storeName }}</strong><small>{{ $businessType }} · {{ $plan }}</small></div>
        <nav id="portal-nav" aria-label="أقسام منشأتك"></nav>
        <form class="logout" method="post" action="{{ route('merchant.web.logout') }}">@csrf
            <button type="submit">تسجيل الخروج</button>
        </form>
    </aside>
    <main>
        <header class="top">
            <div><h1 id="page-title">{{ $portalNavigation[0]['label'] ?? 'لوحة المنشأة' }}</h1><p class="muted">{{ $storeName }} · بيانات حيّة من حساب المنشأة نفسه</p></div>
            <span class="badge">{{ $businessType }} · {{ $plan }}</span>
        </header>
        <div id="content" aria-live="polite"><div class="panel">جارٍ تحميل بيانات المنشأة…</div></div>
    </main>
</div>
<div id="message" role="status"></div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function(){
  'use strict';
  const routes = {{ \Illuminate\Support\Js::from($merchantRoutes) }};
  const csrf = @json(csrf_token());
  const actualSector = @json($businessTypeCode);
  const actualSectorName = @json($businessType);
  const navigation = @json($portalNavigation);
  const titles=Object.fromEntries(navigation.map(item=>[item.tab,item.label]));
  let stopScanner=null;
  let active=navigation[0]?.tab||'overview';const content=document.getElementById('content'),notice=document.getElementById('message');
  function node(tag,text,className){const e=document.createElement(tag);if(text!==undefined&&text!==null)e.textContent=String(text);if(className)e.className=className;return e}
  function box(title){const p=node('div',null,'panel');p.append(node('h2',title));content.append(p);return p}
  function metric(title,value){const d=node('div',null,'metric');d.append(node('small',title),node('strong',value===undefined||value===null?'غير متاح':String(value)));return d}
  function money(v){if(v===undefined||v===null||v==='')return'غير متاح';const bits=String(v).split('.');return bits[0].replace(/\B(?=(\d{3})+(?!\d))/g,',')+(bits[1]?'.'+bits[1].slice(0,2):'')+' ر.ي'}
  function paymentLabel(v){return {cash:'نقد',credit:'أجل',amial_pay:'أميال باي',customer_wallet:'أميال باي',mixed:'مختلط',corporate:'حساب شركة',company_card:'حساب شركة'}[v]||v||'—'}
  function saleStatusLabel(v){return {completed:'مكتملة',credit_unpaid:'آجلة — غير مسددة',credit_paid:'آجلة — مسددة',pending_payment:'بانتظار الدفع',paid:'مدفوعة',pending:'معلّقة',approved:'معتمدة',rejected:'مرفوضة',cancelled:'ملغاة',canceled:'ملغاة',voided:'ملغاة',closed:'مغلقة',open:'مفتوحة'}[v]||v||'—'}
  function grid(items){const g=node('div',null,'grid');items.forEach(x=>g.append(metric(x[0],x[1])));content.append(g)}
  function hint(p,msg){p.append(node('p',msg,'note'))}
  function table(p,cols,rows){const wrap=node('div',null,'table-wrap'),t=node('table'),thead=node('thead'),h=node('tr'),body=node('tbody');cols.forEach(x=>h.append(node('th',x[0])));thead.append(h);t.append(thead);(rows||[]).forEach(row=>{const tr=node('tr');cols.forEach(c=>{const cell=node('td'),value=c[1](row);if(value instanceof Node)cell.append(value);else cell.textContent=value===undefined||value===null?'—':String(value);tr.append(cell)});body.append(tr)});t.append(body);wrap.append(t);wrap.tabIndex=0;wrap.setAttribute('role','region');wrap.setAttribute('aria-label','جدول قابل للتمرير أفقياً');p.append(node('p','اسحب الجدول أفقياً لمشاهدة جميع الأعمدة','table-help'),wrap);if(!rows||rows.length===0)p.append(node('p','لا توجد سجلات لهذه المنشأة حاليًا.','muted'))}
  function message(s){notice.textContent=s;notice.style.display='block';setTimeout(()=>notice.style.display='none',4800)}
  function navigationNote(state){
    return {
      locked_by_plan:'هذه المساحة تحتاج باقة أعلى. راجع «باقتي ومميزاتي» لمعرفة الميزة والسعر الحقيقيين.',
      locked_by_role:'هذه المساحة تحتاج صلاحية من مالك المنشأة أو مديرها.',
      limit_reached:'وصلت منشأتك إلى الحد المتاح لهذه المساحة. راجع الباقة أو بيانات الاستخدام.',
      coming_soon:'هذه المساحة قيد الإطلاق وليست عملية تشغيل متاحة بعد.',
      not_applicable:'هذه المساحة لا تنطبق على نشاط منشأتك.',
    }[state]||'هذه المساحة غير متاحة لهذا الحساب حالياً.';
  }
  function buildNavigation(){
    const nav=document.getElementById('portal-nav');nav.replaceChildren();
    const symbols={dashboard_customize:'⌁',insights:'◈',account_balance_wallet:'◉',inventory_2:'▤',payments:'◫',account_tree:'⌂',groups:'♙',point_of_sale:'▣',analytics:'▥',receipt_long:'▧',auto_awesome:'✧',storefront:'⌂'};
    navigation.forEach(item=>{
      const button=node('button',(symbols[item.icon]||'•')+' '+item.label,'nav');
      button.type='button';button.dataset.tab=item.tab;button.dataset.state=item.state;
      button.title=item.state==='available'?item.label:navigationNote(item.state);
      button.addEventListener('click',()=>{setMenu(false);load(item.tab)});nav.append(button);
    });
  }
  function workspaceActions(panel,tabs){
    const available=new Map(navigation.map(item=>[item.tab,item.state==='available']));
    const names=new Map(navigation.map(item=>[item.tab,item.label]));
    const usable=tabs.filter(tab=>available.get(tab));
    if(!usable.length)return;
    const actions=node('div',null,'buttons');
    usable.forEach(tab=>{
      const button=node('button','فتح '+(names.get(tab)||tab),'action secondary');
      button.type='button';button.addEventListener('click',()=>load(tab));actions.append(button);
    });
    panel.append(node('h3','إجراءات هذه المساحة'),actions);
  }
  async function api(key,body,url,method){const init={credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}};if(body!==undefined){init.method=method||'POST';init.headers['Content-Type']='application/json';init.headers['Idempotency-Key']='mw-'+Date.now()+'-'+Math.random().toString(36).slice(2);init.body=JSON.stringify(body)}const res=await fetch(url||routes[key],init);if(res.status===401){window.location.href=routes.login;throw Error('انتهت الجلسة')}const json=await res.json();if(!res.ok||json.success===false)throw Error(json.message||'لم ينجح تحميل البيانات');const meta=json.meta;if(meta&&typeof meta==='object'&&!Array.isArray(meta)&&Object.keys(meta).length)return meta;return json.data&&typeof json.data==='object'?json.data:{}}
  function form(p,fields,button,submit){const f=node('form',null,'editor');fields.forEach(([key,label,type,options])=>{const l=node('label',label,'field');let inp;if(options){inp=node('select');options.forEach(o=>{const op=node('option',o.label);op.value=o.value;inp.append(op)})}else{inp=node('input');inp.type=type||'text';if(type==='number'){inp.step='any';inp.min='0'}if(type==='password')inp.autocomplete='new-password'}inp.name=key;inp.required=['name','price','trade_name','sale_price','base_price','price_per_liter','display_name','employee_code','password'].includes(key);l.append(inp);f.append(l)});const btn=node('button',button,'action');btn.type='submit';f.append(btn);f.addEventListener('submit',async ev=>{ev.preventDefault();btn.disabled=true;try{const data=Object.fromEntries(new FormData(f).entries());Object.keys(data).forEach(k=>{if(data[k]==='')delete data[k]});const result=await submit(data);if(result.activation_code){f.replaceChildren();const code=node('strong',result.activation_code);code.style.fontSize='29px';code.style.letterSpacing='5px';const secret=node('div',null,'note');secret.append(node('p','رمز التفعيل (صالح لمرة واحدة، حتى '+result.expires_at+')'),code);const copy=node('button','نسخ الرمز','action secondary');copy.type='button';copy.addEventListener('click',()=>navigator.clipboard.writeText(result.activation_code).then(()=>message('تم نسخ الرمز')));secret.append(copy);p.append(secret);message('تم إنشاء رمز التفعيل؛ انسخه قبل مغادرة الصفحة')}else{message(result.message||'تم الحفظ');await load(active)}}catch(e){message(e.message)}finally{btn.disabled=false}});p.append(f)}
  async function overview(){
    if(!actualSector){
      const data=await api('sectorTypes'),p=box('اختر نشاط منشأتك');
      hint(p,'يحدّد النشاط أدوات المنشأة وصلاحياتها. يمكن للإدارة مراجعة التغيير لاحقاً عند الحاجة.');
      const form=node('form',null,'editor'),label=node('label','نوع النشاط التجاري','field'),select=node('select');
      select.name='business_type';select.required=true;select.append(new Option('اختر النشاط',''));
      (data.business_types||[]).forEach(type=>{const option=new Option(type.label+(type.hint?' — '+type.hint:''),type.code);select.append(option)});
      label.append(select);form.append(label);
      const save=node('button','حفظ وفتح لوحة المنشأة','action');save.type='submit';form.append(save);
      form.addEventListener('submit',async ev=>{ev.preventDefault();if(!select.value)return;save.disabled=true;try{await api('sectorTypeSave',{business_type:select.value},undefined,'PUT');window.location.reload()}catch(e){message(e.message);save.disabled=false}});
      p.append(form);return;
    }
    const [o,financial]=await Promise.all([api('overview'),api('wallet')]);const c=o.counts||{},r=financial.report||{},movement=r.movement||{},returnRow=(movement.rows||[]).find(x=>x.code==='sale_return');grid([['مبيعات اليوم',money(r.sales?.gross)],['رصيد محفظة المنشأة',money(r.wallet?.balance)],['مرتجعات اليوم',returnRow?.available===true?money(returnRow.total):'غير متاح'],['الموظفون النشطون',c.active_employees],['أجهزة البيع المتصلة',c.active_device_sessions],['الورديات المفتوحة',c.open_shifts]]);const p=box('حالة التشغيل');hint(p,'مبيعات اليوم من مصدر القطاع الحقيقي، لا من حركة المحفظة. تُقيّد مدفوعات أميال لصالح محفظة المنشأة، وتبقى المبيعات النقدية في درج الوردية والآجلة في الذمم حتى التحصيل.');table(p,[['آخر الورديات',r=>r.opened_by_name||'—'],['موقع التشغيل',r=>r.branch_name||'—'],['الفتح',r=>r.opened_at||'—']],o.open_shifts||[])}
  async function wallet(){
    const [w,l,verification,origins]=await Promise.all([
      api('wallet'),api('ledger'),api('walletVerification').catch(e=>({unavailable:e.message})),
      api('walletOrigins').catch(e=>({unavailable:e.message}))
    ]);
    const r=w.report||{},v=r.wallet||{};
    grid([['رصيد محفظة المنشأة',money(v.balance)],['وارد المحفظة خلال الفترة',money(v.received)],['صادر المحفظة خلال الفترة',money(v.paid_out)],['حركة المحفظة الصافية',money(v.net_movement)]]);
    const verify=box('مطابقة المحفظة مع الدفتر المالي');
    hint(verify,'محفظة واحدة باسم مالك المنشأة لجميع مدفوعات أميال. مبيعات النقد تبقى في الدرج، ومبيعات الآجل في الذمم حتى التحصيل.');
    if(verification.unavailable){
      const notice=node('div','تعذّر التحقق من مطابقة الرصيد: '+verification.unavailable,'note warning-note');verify.append(notice);
    }else{
      const truth=verification.verification||{},state=truth.state;
      const label=state==='reconciled'?'الرصيدان متطابقان':state==='mismatch'?'فرق مالي يحتاج مراجعة الإدارة':'المطابقة غير متاحة';
      const notice=node('div',label+(truth.reason?' — '+truth.reason:''),'note'+(state==='mismatch'?' danger-note':state==='reconciled'?'':' warning-note'));
      notice.setAttribute('role','status');verify.append(notice);
      const values=node('div',null,'grid');
      values.append(metric('الرصيد التشغيلي',money(truth.operational_balance)),metric('الرصيد في الدفتر',money(truth.ledger_balance)),metric('الفرق',money(truth.gap)));
      verify.append(values);
      if(truth.last_entry_ulid)hint(verify,'آخر قيد مالي: '+truth.last_entry_ulid);
      if(state==='mismatch')hint(verify,'لا يتم تصحيح الرصيد من هذه الشاشة. راجع الإدارة وكشف القيود للتحقيق في مصدر الفرق.');
    }
    const audit=box('من أين جاء رصيد المحفظة؟');
    hint(audit,'حركة دفتر المحفظة المثبتة منذ بداية السجل. لا تُحسب المبيعات النقدية ولا الفواتير الآجلة أموالاً في المحفظة.');
    if(origins.unavailable){
      hint(audit,'تعذّر تحميل مصادر الرصيد: '+origins.unavailable);
    }else if(origins.available!==true){
      audit.append(node('div',origins.note_ar||'لا يوجد دفتر متاح للتتبّع.','note warning-note'));
    }else{
      const s=origins.summary||{};
      const totals=node('div',null,'grid');
      totals.append(metric('كل الوارد المثبت',money(s.posted_in)),metric('كل الصادر المثبت',money(s.posted_out)),
        metric('صافي القيود',money(s.posted_net)),metric('فرق المحفظة والدفتر',money(s.gap)));
      audit.append(totals);
      if(origins.note_ar)hint(audit,origins.note_ar);
      if((origins.sources||[]).length===0){
        audit.append(node('p','دفتر المحفظة موجود، لكن لا توجد له قيود مثبتة. لا يُفسّر هذا وحده الرصيد التشغيلي.','note warning-note'));
      }else{
        table(audit,[['مصدر القيد',x=>x.label_ar],['الوارد',x=>money(x.received)],
          ['الصادر',x=>money(x.paid_out)],['الصافي',x=>money(x.net)],
          ['عدد السطور',x=>x.line_count],['الرمز المحاسبي',x=>x.source_type]],origins.sources);
        const links=node('div',null,'buttons'),drill=node('div');
        const openSource=async(source,page)=>{
          const url=new URL(routes.ledger,window.location.href);
          url.searchParams.set('source_type',source.source_type);
          url.searchParams.set('page',String(page));
          const details=await api('ledger',undefined,url.toString());
          drill.replaceChildren(node('h3','قيود '+source.label_ar));
          table(drill,[['التاريخ',x=>x.date],['الوصف',x=>x.description||x.source_type],
            ['الاتجاه',x=>x.direction==='credit'?'وارد':'صادر'],['المبلغ',x=>money(x.amount)],
            ['الرصيد بعد',x=>money(x.balance_after)],['مرجع القيد',x=>x.reference]],details.entries||[]);
          const pg=details.pagination||{},nav=node('div',null,'buttons');
          if((pg.current_page||1)>1){
            const back=node('button','السابق','action secondary');
            back.type='button';back.onclick=()=>openSource(source,pg.current_page-1).catch(e=>message(e.message));nav.append(back);
          }
          if((pg.current_page||1)<(pg.last_page||1)){
            const next=node('button','التالي','action secondary');
            next.type='button';next.onclick=()=>openSource(source,pg.current_page+1).catch(e=>message(e.message));nav.append(next);
          }
          drill.append(nav);
        };
        (origins.sources||[]).forEach(source=>{
          // Deliberately create DOM text nodes rather than HTML from ledger metadata.
          const button=node('button','تفاصيل: '+source.label_ar,'action secondary');
          button.type='button';button.onclick=()=>openSource(source,1).catch(e=>message(e.message));
          links.append(button);
        });
        audit.append(links,drill);
      }
    }
    const p=box('كشف قيود المحفظة الموحدة');
    hint(p,'مصدر هذه العمليات هو الدفتر نفسه الذي تقرؤه الإدارة والتطبيق. التحويلات الشخصية والأرصدة الافتتاحية ليست مبيعات.');
    table(p,[['التاريخ',x=>x.date],['البيان',x=>x.description||x.source_type],['النوع',x=>x.source_type||'—'],['الاتجاه',x=>x.direction==='credit'?'وارد':'صادر'],['المبلغ',x=>money(x.amount)],['الرصيد بعد',x=>money(x.balance_after)],['المرجع',x=>x.reference]],l.entries||[]);
    const pagination=l.pagination||{};
    if(pagination.last_page>1){
      const controls=node('div',null,'buttons');
      const back=node('button','السابق','action secondary'),forward=node('button','التالي','action secondary');
      back.disabled=(pagination.current_page||1)<=1;forward.disabled=(pagination.current_page||1)>=pagination.last_page;
      const loadLedger=async page=>{
        const url=new URL(routes.ledger,window.location.href);url.searchParams.set('page',String(page));
        const next=await api('ledger',undefined,url.toString());
        p.replaceChildren(node('h2','كشف قيود المحفظة الموحدة'));
        table(p,[['التاريخ',x=>x.date],['البيان',x=>x.description||x.source_type],['النوع',x=>x.source_type||'—'],['الاتجاه',x=>x.direction==='credit'?'وارد':'صادر'],['المبلغ',x=>money(x.amount)],['الرصيد بعد',x=>money(x.balance_after)],['المرجع',x=>x.reference]],next.entries||[]);
        const info=node('p','الصفحة '+next.pagination.current_page+' من '+next.pagination.last_page,'muted');p.append(info);
        if(next.pagination.current_page>1){const b=node('button','السابق','action secondary');b.onclick=()=>loadLedger(next.pagination.current_page-1).catch(e=>message(e.message));p.append(b)}
        if(next.pagination.current_page<next.pagination.last_page){const b=node('button','التالي','action secondary');b.onclick=()=>loadLedger(next.pagination.current_page+1).catch(e=>message(e.message));p.append(b)}
      };
      back.onclick=()=>loadLedger(pagination.current_page-1).catch(e=>message(e.message));
      forward.onclick=()=>loadLedger(pagination.current_page+1).catch(e=>message(e.message));
      controls.append(back,node('span','الصفحة '+pagination.current_page+' من '+pagination.last_page,'muted'),forward);p.append(controls);
    }
  }
  function debtUrl(key,id){return routes[key].replace('__ID__',encodeURIComponent(String(id)))}
  function collectionVoucher(panel,result){
    if(!result.receipt_number){panel.append(node('p','تم التسجيل؛ تعذّر إظهار رقم السند. راجع سجل التحصيلات.','note warning-note'));return}
    const done=node('div',null,'note');done.append(node('p','تم التحصيل برقم سند '+result.receipt_number+' — المتبقي '+money(result.new_balance)));
    const a=node('a','عرض السند / طباعة PDF','link-action');
    a.href=debtUrl('debtCollectionReceipt',result.collection_id);a.target='_blank';a.rel='noopener noreferrer';
    done.append(a);panel.append(done);
  }
  function pendingWalletBox(panel,result){
    const info=node('div',null,'note');info.append(node('p','طلب أميال رقم '+result.payment_code+' — ينتظر موافقة العميل ودفعه من تطبيق أميال.'));
    if(result.payment_url){
      const link=node('a','فتح رابط دفع العميل','link-action');link.href=result.payment_url;link.target='_blank';link.rel='noopener noreferrer';info.append(link);
      const copy=node('button','نسخ رابط الدفع','action secondary');copy.type='button';
      copy.onclick=()=>navigator.clipboard.writeText(result.payment_url).then(()=>message('تم نسخ الرابط')).catch(()=>message('تعذّر النسخ'));
      info.append(copy);
    }
    const confirm=node('button','التحقق من وصول الدفع وإصدار سند','action');
    confirm.type='button';confirm.onclick=async()=>{
      confirm.disabled=true;
      try{
        const done=await api('debtConfirmWallet',{},debtUrl('debtConfirmWallet',result.collection_id));
        info.remove();collectionVoucher(panel,done);message('تم التحقق من أميال وتخفيض الدين');
      }catch(e){message(e.message);confirm.disabled=false}
    };
    info.append(confirm);panel.append(info);
  }
  function collectionForm(id,account,invoices){
    const p=node('section',null,'panel');p.append(node('h2','تحصيل دين من العميل'));
    p.append(node('p','اختر نقداً أو أميال. النقد لا يودع في المحفظة؛ أميال لا يُحصل إلا بعد دفع العميل بنفسه.','note'));
    const f=node('form',null,'editor'),balance=Number(account.current_balance||0);
    const amountLabel=node('label','المبلغ بالريال اليمني','field'),amount=node('input');amount.name='amount';amount.type='number';amount.step='0.01';amount.min='0.01';amount.max=String(balance);amount.required=true;amountLabel.append(amount);
    const methodLabel=node('label','طريقة السداد','field'),method=node('select');
    for(const [v,title] of [['cash','تحصيل نقدي'],['amial_pay','دفع بمحفظة أميال']]){const o=node('option',title);o.value=v;method.append(o)}
    method.name='payment_method';methodLabel.append(method);
    const invoiceLabel=node('label','تخصيص السداد لفاتورة (اختياري)','field'),invoice=node('select');
    invoice.name='sale_movement_ulid';const any=node('option','توزيع على أقدم الديون');any.value='';invoice.append(any);
    (invoices||[]).forEach(x=>{const option=node('option',(x.reference_number||x.movement_ulid)+' — '+money(x.remaining));option.value=x.movement_ulid;invoice.append(option)});
    invoiceLabel.append(invoice);
    const noteLabel=node('label','ملاحظة التحصيل','field'),note=node('input');note.name='note';note.maxLength=255;noteLabel.append(note);
    const button=node('button','متابعة التحصيل','action');button.type='submit';
    const resultPanel=node('div');resultPanel.setAttribute('aria-live','polite');
    f.append(amountLabel,methodLabel,invoiceLabel,noteLabel,button);
    const key='mw-credit-'+Date.now()+'-'+Math.random().toString(36).slice(2);
    f.addEventListener('submit',async e=>{
      e.preventDefault();
      const value=Number(amount.value);
      const selected=(invoices||[]).find(x=>x.movement_ulid===invoice.value);
      if(!Number.isFinite(value)||value<=0||value>balance||(selected&&value>Number(selected.remaining))){
        message('المبلغ يتجاوز الدين أو المتبقي من الفاتورة');return
      }
      const label=method.value==='cash'?'نقداً في صندوق المنشأة':'بطلب دفع ينتظر موافقة العميل';
      if(!window.confirm('تأكيد تحصيل '+money(value)+' '+label+'؟'))return;
      button.disabled=true;
      try{
        const data={amount:amount.value,idempotency_key:key};
        if(invoice.value)data.sale_movement_ulid=invoice.value;
        if(note.value.trim())data.note=note.value.trim();
        const isCash=method.value==='cash';
        const response=await api(isCash?'debtCollectCash':'debtRequestWallet',data,
          debtUrl(isCash?'debtCollectCash':'debtRequestWallet',id));
        resultPanel.replaceChildren();
        if(isCash){collectionVoucher(resultPanel,response);message('تم التحصيل النقدي وإصدار السند')}
        else pendingWalletBox(resultPanel,response);
        f.querySelectorAll('input,select').forEach(x=>x.disabled=true);
        button.textContent='تم إنشاء التحصيل';button.disabled=true;
      }catch(err){message(err.message);button.disabled=false}
    });
    p.append(f,resultPanel);return p;
  }
  async function debtDetails(id){
    const detail=box('كشف حساب العميل');
    detail.id='debt-customer-detail';
    hint(detail,'جارٍ تحميل سجل العميل والفواتير المفتوحة…');
    try{
      const [statement,breakdown]=await Promise.all([
        api('debtStatement',undefined,debtUrl('debtStatement',id)),
        api('debtInvoices',undefined,debtUrl('debtInvoices',id))
      ]);
      detail.replaceChildren(node('h2','كشف حساب: '+(statement.account?.customer_name||'العميل')));
      const figures=node('div',null,'grid');
      figures.append(metric('الدين الحالي',money(statement.account?.current_balance)),metric('المتبقي في الفواتير',money(breakdown.invoices_total)),metric('رصيد خارج الفواتير',money(breakdown.unlinked_balance)));
      detail.append(figures);
      if(breakdown.unlinked_note_ar){detail.append(node('p',breakdown.unlinked_note_ar,'note warning-note'))}
      const actions=node('div',null,'buttons');
      const pdf=node('a','تنزيل كشف الحساب PDF','link-action');
      pdf.href=debtUrl('debtStatementPdf',id);pdf.target='_blank';pdf.rel='noopener noreferrer';
      actions.append(pdf);
      const back=node('button','العودة لقائمة العملاء','action secondary');
      back.type='button';back.onclick=()=>{detail.remove();document.getElementById('debt-customers-panel')?.scrollIntoView({behavior:'smooth'})};
      actions.append(back);detail.append(actions);
      if(Number(statement.account?.current_balance||0)>0){
        detail.append(collectionForm(id,statement.account,breakdown.invoices||[]));
      }
      detail.append(node('h3','الفواتير غير المسددة'));
      table(detail,[['الفاتورة',x=>x.reference_number||x.movement_ulid],['تاريخ الإصدار',x=>x.issued_at||'—'],['الاستحقاق',x=>x.due_date||'غير محدد'],['القيمة',x=>money(x.original_amount)],['المتبقي',x=>money(x.remaining)]],breakdown.invoices||[]);
      detail.append(node('h3','جميع الحركات المسجلة'));
      table(detail,[['التاريخ',x=>x.created_at],['النوع',x=>x.type==='sale'?'بيع آجل':x.type==='payment'?'سداد':x.type==='return'?'مرتجع':'تعديل'],['المبلغ',x=>money(x.amount)],['الرصيد بعد',x=>money(x.balance_after)],['المرجع',x=>x.reference_number||x.reference_id||'—']],statement.movements||[]);
      detail.scrollIntoView({behavior:'smooth',block:'start'});
    }catch(e){detail.replaceChildren(node('div','تعذّر تحميل كشف العميل: '+e.message,'error'))}
  }
  async function debts(search='',filter='',page=1){
    const [summary,customers]=await Promise.all([
      api('debts'),(async()=>{
        const url=new URL(routes.debtCustomers,window.location.href);
        if(search)url.searchParams.set('search',search);
        if(filter)url.searchParams.set('filter',filter);
        url.searchParams.set('page',String(page));
        return api('debtCustomers',undefined,url.toString());
      })()
    ]);
    grid([['إجمالي الديون المستحقة',money(summary.total_due)],['العملاء المدينون',summary.debtors_count],['المتجاوزون للحد',summary.over_limit_count]]);
    try{
      const pending=await api('debtPending');
      if((pending.collections||[]).length){
        const q=box('طلبات أميال المعلقة والتحصيلات التي تحتاج مراجعة');
        hint(q,'حتى بعد إعادة تحميل الصفحة، يمكنك الرجوع إلى الطلب والتحقق من دفع العميل دون إنشاء طلب جديد.');
        (pending.collections||[]).forEach(item=>{
          const boxItem=node('div',null,'panel');
          boxItem.append(node('strong',item.collection_ref+' — '+money(item.paid)));
          if(item.needs_review){boxItem.append(node('p','وصل المال لكن الدين تغير؛ اتصل بالإدارة قبل اتخاذ إجراء آخر.','note danger-note'))}
          else pendingWalletBox(boxItem,item);
          q.append(boxItem);
        });
      }
    }catch(e){message('تعذّر تحميل طلبات التحصيل المعلقة: '+e.message)}
    const p=box('حسابات العملاء الآجلة');p.id='debt-customers-panel';
    hint(p,'هذه بيانات دفتر الآجل نفسه في تطبيق العميل ونقاط البيع. البيع بالآجل لا يزيد رصيد المحفظة حتى السداد.');
    const controls=node('form',null,'filters');
    const searchLabel=node('label','بحث بالاسم أو الهاتف','field'),searchInput=node('input');
    searchInput.name='search';searchInput.type='search';searchInput.value=search;searchInput.placeholder='اسم العميل أو رقم الهاتف';searchLabel.append(searchInput);
    const filterLabel=node('label','الحالة','field'),select=node('select');
    for(const [value,label] of [['','جميع العملاء'],['debtors','لديهم ديون'],['over_limit','تجاوزوا الحد'],['paid_up','مسدد بالكامل']]){
      const option=node('option',label);option.value=value;select.append(option);
    }
    select.value=filter;filterLabel.append(select);
    const submit=node('button','بحث / تصفية','action');submit.type='submit';
    controls.append(searchLabel,filterLabel,submit);
    controls.addEventListener('submit',async e=>{e.preventDefault();content.replaceChildren();try{await debts(searchInput.value.trim(),select.value,1)}catch(err){content.replaceChildren(node('div',err.message,'error'))}});
    p.append(controls);
    const rows=customers.customers||[];
    table(p,[['العميل',x=>x.customer_name],['رقم الهاتف',x=>x.customer_phone],['الدين المستحق',x=>money(x.current_balance)],['حد الآجل',x=>money(x.credit_limit)],['الحالة',x=>x.is_active?'نشط':'موقوف']],rows);
    const buttons=node('div',null,'buttons');
    rows.forEach(customer=>{
      const button=node('button','كشف حساب '+customer.customer_name,'action secondary');
      button.type='button';
      button.onclick=()=>{document.getElementById('debt-customer-detail')?.remove();debtDetails(customer.id)};
      buttons.append(button);
    });
    p.append(buttons);
    const pg=customers.pagination||{};
    if(pg.last_page>1){
      const pager=node('div',null,'buttons');
      for(const [label,n,enabled] of [['السابق',pg.current_page-1,pg.current_page>1],['التالي',pg.current_page+1,pg.current_page<pg.last_page]]){
        const button=node('button',label,'action secondary');button.disabled=!enabled;
        button.type='button';button.onclick=()=>{content.replaceChildren();debts(search,filter,n).catch(e=>content.replaceChildren(node('div',e.message,'error')))};
        pager.append(button);
      }
      pager.append(node('span','الصفحة '+pg.current_page+' من '+pg.last_page,'muted'));p.append(pager);
    }
    const create=box('تسجيل عميل في دفتر الآجل');
    hint(create,'هذه العملية تنشئ حساب العميل أو تعدّل حدّه؛ لا تضيف ديناً ولا تحرك المحفظة. يسجل موظف نقطة البيع البيعة الآجلة من التطبيق.');
    form(create,[['phone','رقم هاتف العميل','tel'],['name','اسم العميل'],['credit_limit','الحد الائتماني (اختياري)','number']],'حفظ حساب العميل',d=>api('debtCustomersSave',d));
    create.querySelector('input[name="phone"]').required=true;
  }
  async function products(){
    const generic=['retail','quick_sale','restaurant'].includes(actualSector);
    const editable=generic||['pharmacy','wholesale'].includes(actualSector);
    let items=[],options={categories:[],brands:[],units:[]},search='',lowOnly=false;
    const page=box('المنتجات والباركود · '+actualSectorName);
    hint(page,generic
      ?'تُضاف الأصناف والتصنيفات والعلامات والوحدات إلى كتالوج المنشأة نفسه الذي تقرؤه نقاط البيع. الباركود الأساسي والبديل وحجم العبوة مرتبطان بالمخزون نفسه.'
      :'المنتجات والباركود من نظام القطاع نفسه. لا تخلط مخزون الصيدلية أو الجملة بكتالوج التجزئة.');
    const tools=node('div',null,'product-tools'),codeLabel=node('label',null,'field'),code=node('input');
    codeLabel.append(node('span','ابحث بالاسم أو SKU أو الباركود'));
    code.type='search';code.placeholder='امسح الباركود بقارئ USB أو أدخله يدوياً';
    code.setAttribute('aria-label','بحث أو مسح باركود المنتج');code.autocomplete='off';
    codeLabel.append(code);tools.append(codeLabel);
    const searchButton=node('button','بحث بالباركود','action secondary'),cameraButton=node('button','فتح كاميرا الباركود','action secondary');
    searchButton.type='button';cameraButton.type='button';
    tools.append(searchButton,cameraButton);
    const lowCheck=node('label',null,'field'),lowInput=node('input');lowInput.type='checkbox';lowInput.style.width='20px';lowInput.style.minHeight='20px';
    lowCheck.append(lowInput,node('span','المخزون المنخفض فقط'));tools.append(lowCheck);page.append(tools);
    const feedback=node('div',null,'product-lookup'),results=node('div'),editor=node('div'),catalogue=node('div');
    page.append(feedback,results,editor,catalogue);
    function blankPanel(text,warning=false){
      feedback.replaceChildren(node('p',text,'note'+(warning?' warning-note':'')));
    }
    const amount=x=>x===null||x===undefined?'—':money(x);
    function field(form,key,caption,type='text',value='',choices=null){
      const label=node('label',null,'field');label.append(node('span',caption));
      const input=choices?node('select'):node('input');
      if(choices){input.append(new Option('اختر',''));choices.forEach(v=>input.append(new Option(v.name,String(v.id))))}
      else{input.type=type;if(type==='number'){input.min='0';input.step='any'}}
      input.name=key;input.value=value===null||value===undefined?'':String(value);
      if(key==='barcode'||key==='sku'){input.dir='ltr';input.autocomplete='off'}
      label.append(input);form.append(label);return input;
    }
    const dataFor=p=>generic?{
      name:p?.name||'',price:p?.price||'',cost_price:p?.cost_price??'',
      offer_price:p?.offer_price??'',quantity:p?.quantity??0,
      sku:p?.sku||'',barcode:p?.barcode||'',reorder_level:p?.reorder_level??0,
      category_id:p?.category_id||'',brand_id:p?.brand_id||'',unit_id:p?.unit_id||'',
      expiry_date:p?.expiry_date?String(p.expiry_date).slice(0,10):'',
      track_stock:p?.track_stock===false?'0':'1',is_active:p?.is_active===false?'0':'1',
    }:actualSector==='pharmacy'?{
      trade_name:p?.trade_name||'',generic_name:p?.generic_name||'',
      sale_price:p?.sale_price??'',cost_price:p?.cost_price??'',
      barcode:p?.barcode||'',sku:p?.sku||'',unit:p?.unit||'',
      low_stock_threshold:p?.low_stock_threshold??0,
    }:actualSector==='wholesale'?{
      name:p?.name||'',sku:p?.sku||'',barcode:p?.barcode||'',
      base_price:p?.base_price??'',cost_price:p?.cost_price??'',
      initial_stock:p?null:0,unit:p?.unit||'',low_stock_threshold:p?.low_stock_threshold??0,
    }:{
      name:p?.name||'',product_code:p?.product_code||'',price_per_liter:p?.price_per_liter??'',
    };
    async function refresh(){
      const [r,opts]=await Promise.all([
        api('sectorProducts'),
        generic?api('sectorCatalogOptions').catch(e=>({unavailable:e.message})):Promise.resolve({})
      ]);
      items=(r.result?.products||[]);options=opts||{categories:[],brands:[],units:[]};
      render();
    }
    function render(){
      results.replaceChildren();
      const visible=items.filter(p=>{
        const stock=Number(p.quantity??p.current_stock??0);
        const limit=Number(p.reorder_level??p.low_stock_threshold??0);
        const hay=[p.name,p.trade_name,p.sku,p.barcode,
          ...(p.barcodes||[]).map(b=>b.barcode)].join(' ').toLocaleLowerCase();
        return (!search||hay.includes(search.toLocaleLowerCase()))
          &&(!lowOnly||stock<=limit);
      });
      const summary=node('p',visible.length+' صنف من '+items.length+' · البحث محلي وتُطابَق الرموز عند المسح من الخادم','muted');
      results.append(summary);
      const wrap=node('div',null,'table-wrap'),table=node('table'),thead=node('thead'),h=node('tr'),body=node('tbody');
      ['المنتج','الباركود الأساسي','السعر','الرصيد','الإجراءات'].forEach(x=>h.append(node('th',x)));
      thead.append(h);table.append(thead);
      visible.forEach(p=>{
        const tr=node('tr'),name=p.display_name||p.trade_name||p.name||'—';
        tr.append(node('td',name),node('td',p.barcode||p.product_code||'—'),
          node('td',amount(p.price??p.sale_price??p.base_price??p.price_per_liter)),
          node('td',p.quantity??p.current_stock??'—'));
        const td=node('td'),actions=node('div',null,'product-actions');
        if(editable){const edit=node('button','تعديل','action secondary');edit.type='button';edit.onclick=()=>showEditor(p);actions.append(edit)}
        if(generic&&!p.is_variant_parent){
          const alias=node('button','باركودات / عبوات','action secondary');alias.type='button';alias.onclick=()=>showAliases(p);actions.append(alias);
        }
        td.append(actions);tr.append(td);body.append(tr);
      });table.append(body);wrap.append(table);results.append(wrap);
      if(visible.length===0)results.append(node('p','لا توجد منتجات تطابق البحث. جرّب رمزاً آخر أو أضف صنفاً جديداً.','note'));
    }
    function showEditor(product=null,prefill=''){
      editor.replaceChildren();
      const panel=node('section',null,'product-editor');
      panel.append(node('h3',product?'تعديل: '+(product.trade_name||product.name):'إضافة منتج'));
      const values=dataFor(product),form=node('form',null,'editor');
      const definitions=generic?[
        ['name','اسم المنتج *'],['price','سعر البيع *','number'],['cost_price','سعر الشراء','number'],
        ['offer_price','سعر العرض','number'],['quantity','رصيد البداية / الكمية','number'],
        ['sku','رمز SKU'],['barcode','الباركود الأساسي'],['category_id','التصنيف','select',options.categories||[]],
        ['brand_id','العلامة التجارية','select',options.brands||[]],
        ['unit_id','وحدة القياس','select',options.units||[]],
        ['reorder_level','حد تنبيه المخزون','number'],
        ['expiry_date','تاريخ انتهاء الصلاحية','date'],
        ['track_stock','تتبع المخزون','select',[{id:1,name:'نعم'},{id:0,name:'لا'}]],
        ['is_active','حالة الصنف','select',[{id:1,name:'نشط'},{id:0,name:'موقوف'}]],
      ]:actualSector==='pharmacy'?[
        ['trade_name','الاسم التجاري *'],['generic_name','الاسم العلمي'],
        ['sale_price','سعر البيع *','number'],['cost_price','سعر الشراء','number'],
        ['barcode','الباركود'],['sku','رمز SKU'],['unit','وحدة القياس'],
        ['low_stock_threshold','حد تنبيه المخزون','number'],
      ]:actualSector==='wholesale'?[
        ['name','اسم المنتج *'],['base_price','سعر البيع *','number'],
        ['cost_price','سعر الشراء','number'],['sku','رمز SKU'],['barcode','الباركود'],
        ['unit','وحدة القياس'],...(product?[]:[['initial_stock','مخزون البداية','number']]),
        ['low_stock_threshold','حد التنبيه','number'],
      ]:[['name','اسم الوقود *'],['product_code','رمز المنتج'],['price_per_liter','سعر اللتر *','number']];
      for(const [key,title,type,choices]of definitions)field(form,key,title,type==='select'?'text':type||'text',values[key],choices||null);
      const buttons=node('div',null,'buttons'),save=node('button',product?'حفظ التعديلات':'إضافة المنتج','action');
      const cancel=node('button','إلغاء','action secondary');cancel.type='button';cancel.onclick=()=>editor.replaceChildren();
      save.type='submit';buttons.append(save,cancel);form.append(buttons);panel.append(form);
      if(generic){
        const catalogButton=node('button','بحث في الكتالوج المشترك','action secondary');
        catalogButton.type='button';catalogButton.onclick=async()=>{
          const barcode=form.elements.namedItem('barcode').value.trim();
          if(!barcode){blankPanel('أدخل الباركود أولاً.');return}
          const url=new URL(routes.sectorCatalogLookup,window.location.href);
          url.searchParams.set('barcode',barcode);
          try{
            const entry=await api('sectorCatalogLookup',undefined,url.toString());
            const current=form.elements.namedItem('name');
            if(!current.value.trim())current.value=entry.name||'';
            const tag=entry.is_verified?'موثّق':'مقترح غير مراجع';
            blankPanel('اقتراح الكتالوج: '+(entry.name||'—')+' ('+tag+'). الأسعار تحددها أنت.');
          }catch(e){blankPanel(e.message||'لم نجد الصنف في الكتالوج.',true)}
        };panel.append(catalogButton);
      }
      if(prefill)form.elements.namedItem('barcode').value=prefill;
      form.addEventListener('submit',async ev=>{
        ev.preventDefault();save.disabled=true;
        try{
          const values=Object.fromEntries(new FormData(form).entries());
          for(const key of Object.keys(values))if(values[key]==='')delete values[key];
          if(generic&&product&&form.elements.namedItem('offer_price').value.trim()==='')values.offer_price=null;
          if(generic&&product&&form.elements.namedItem('expiry_date').value.trim()==='')values.expiry_date=null;
          if(generic&&product&&form.elements.namedItem('barcode').value.trim()==='')
            values.barcode=''; // an explicit clear must remove the old primary
          const path=product?routes.sectorProductsUpdate.replace('__ID__',String(product.id)):routes.sectorProductsCreate;
          const response=product?await api('sectorProductsUpdate',values,path,'PUT'):await api('sectorProductsCreate',values);
          message(response.message||'حُفظ المنتج والباركود');
          editor.replaceChildren();await refresh();
        }catch(e){blankPanel(e.message||'تعذّر حفظ المنتج',true)}
        finally{save.disabled=false}
      });
      editor.append(panel);
      editor.scrollIntoView({behavior:'smooth',block:'start'});
    }
    function showAliases(product){
      editor.replaceChildren();
      const panel=node('section',null,'product-editor');
      panel.append(node('h3','باركودات وعبوات: '+product.name));
      hint(panel,'كل رمز يُطابق هذا المنتج نفسه. باركود الكرتون يضيف عدد القطع المحدد للسلة ويخصمه من المخزون عند إتمام البيع.');
      table(panel,[['الرمز',b=>b.barcode],['حجم العبوة',b=>b.pack_size],['النوع',b=>b.is_primary?'أساسي':'بديل']],product.barcodes||[]);
      const form=node('form',null,'editor');
      field(form,'barcode','باركود العبوة *');
      field(form,'pack_size','عدد القطع عند المسح *','number','1');
      const btn=node('button','ربط هذا الباركود','action');btn.type='submit';form.append(btn);
      form.addEventListener('submit',async ev=>{
        ev.preventDefault();btn.disabled=true;
        try{
          const data=Object.fromEntries(new FormData(form).entries());
          if(!data.barcode.trim()||!(Number(data.pack_size)>0)||!Number.isInteger(Number(data.pack_size)))
            throw Error('أدخل رمزاً وعدداً صحيحاً موجباً من القطع؛ كاشير القطع لا يدعم العبوات الكسرية.');
          await api('sectorProductBarcodeAdd',data,routes.sectorProductBarcodeAdd.replace('__ID__',String(product.id)));
          message('تم ربط الباركود بالمنتج نفسه');editor.replaceChildren();await refresh();
        }catch(e){blankPanel(e.message,true)}finally{btn.disabled=false}
      });
      panel.append(form);editor.append(panel);editor.scrollIntoView({behavior:'smooth',block:'start'});
    }
    function renderCatalogue(){
      catalogue.replaceChildren();if(!generic)return;
      const p=node('section',null,'product-editor');p.append(node('h3','التصنيفات والعلامات ووحدات القياس'));
      if(options.unavailable){p.append(node('p',options.unavailable,'note warning-note'));catalogue.append(p);return}
      for(const [kind,title,values] of [
        ['categories','التصنيفات',options.categories||[]],['brands','العلامات',options.brands||[]],['units','الوحدات',options.units||[]]
      ]){
        const details=node('details');details.append(node('summary',title+' ('+values.length+')'));
        const names=node('p',values.map(x=>x.name).join(' · ')||'لا توجد عناصر بعد','muted');details.append(names);
        const form=node('form',null,'editor'),inp=field(form,'name','اسم '+title);
        const button=node('button','إضافة','action secondary');button.type='submit';form.append(button);
        form.addEventListener('submit',async ev=>{
          ev.preventDefault();if(!inp.value.trim())return;button.disabled=true;
          try{
            await api('sectorCatalogAdd',{name:inp.value.trim()},routes.sectorCatalogAdd.replace('__KIND__',kind));
            message('تمت الإضافة');await refresh();renderCatalogue();
          }catch(e){blankPanel(e.message,true)}finally{button.disabled=false}
        });
        details.append(form);p.append(details);
      }
      catalogue.append(p);
    }
    const add=node('button','+ إضافة منتج جديد','action');add.type='button';add.onclick=()=>showEditor();
    page.insertBefore(add,editor);
    code.addEventListener('input',()=>{search=code.value.trim();render()});
    lowInput.addEventListener('change',()=>{lowOnly=lowInput.checked;render()});
    async function lookup(){
      const barcode=code.value.trim();
      if(!barcode){blankPanel('أدخل رقم الباركود أو امسحه في الحقل.');return}
      const url=new URL(routes.sectorBarcodeLookup,window.location.href);
      url.searchParams.set('barcode',barcode);
      try{
        const hit=await api('sectorBarcodeLookup',undefined,url.toString());
        const p=hit.product||{};
        search=barcode;render();
        blankPanel('الصنف: '+(p.trade_name||p.name||'—')+' · رمز: '+barcode+' · حجم العبوة: '+(hit.pack_size||1));
      }catch(e){
        blankPanel('لا يوجد صنف بهذا الرمز في كتالوج منشأتك. '+(editable?'يمكنك إضافته أدناه.':'')+' '+e.message,true);
        if(!items.some(p=>p.barcode===barcode)&&generic)showEditor(null,barcode);
      }
    }
    searchButton.onclick=()=>lookup().catch(e=>blankPanel(e.message,true));
    code.addEventListener('keydown',ev=>{if(ev.key==='Enter'){ev.preventDefault();lookup().catch(e=>blankPanel(e.message,true))}});
    cameraButton.onclick=async()=>{
      if(!('BarcodeDetector' in window)||!navigator.mediaDevices?.getUserMedia){
        blankPanel('ماسح الكاميرا غير مدعوم في هذا المتصفح. استخدم قارئ USB أو تطبيق نقطة البيع على الهاتف.',true);return;
      }
      if(stopScanner){stopScanner();stopScanner=null}
      const preview=node('video',null,'scanner-preview');preview.autoplay=true;preview.muted=true;preview.playsInline=true;
      feedback.replaceChildren(preview);
      let stream=null,alive=true;
      const stop=()=>{alive=false;if(stream)stream.getTracks().forEach(t=>t.stop());preview.srcObject=null;preview.remove()};
      stopScanner=stop;
      try{
        const detector=new BarcodeDetector({formats:['ean_13','ean_8','upc_a','upc_e','code_128','code_39','qr_code']});
        stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});
        if(!alive){stream.getTracks().forEach(t=>t.stop());return}
        preview.srcObject=stream;await preview.play();
        const frame=async()=>{
          if(!alive)return;
          try{
            const found=await detector.detect(preview);
            if(found.length&&found[0].rawValue){
              code.value=found[0].rawValue;search=code.value.trim();
              stop();stopScanner=null;await lookup();return;
            }
          }catch(_){/* Keep USB/manual fallback. */}
          if(alive)requestAnimationFrame(frame);
        };requestAnimationFrame(frame);
      }catch(e){stop();stopScanner=null;blankPanel('تعذّر تشغيل الكاميرا. اسمح بالصلاحية أو أدخل الباركود يدوياً.',true)}
    };
    await refresh();renderCatalogue();
  }
  function labelValue(p,label,value){const d=node('div',null,'metric');d.append(node('small',label),node('strong',value??'—'));p.append(d)}
  async function sector(){
    const state=(navigation.find(item=>item.tab==='sector')||{}).state;
    if(state&&state!=='available'){
      const p=box('مساحة '+actualSectorName);hint(p,navigationNote(state));
      hint(p,'لا تُعرض أرقام صفرية ولا أزرار تشغيل وهمية عندما يمنع الاستحقاق أو تكون الوحدة غير جاهزة.');return;
    }
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
      workspaceActions(p,['sales','products','staff','devices','wallet']);
    }else if(actualSector==='pharmacy'){
      table(p,[['التنبيه',x=>x.product?.trade_name||x.type||'تنبيه مخزون'],['الدرجة',x=>x.severity||'—'],['التاريخ',x=>x.created_at||'—']],o.alerts||[]);
      workspaceActions(p,['sales','products','debts','wallet']);
    }else if(actualSector==='wholesale'){
      table(p,[['الفاتورة',x=>x.invoice_number||x.id],['الحالة',x=>x.status],['الإجمالي',x=>money(x.total_amount)],['المتبقي',x=>money(x.balance_due)]],o.invoices||[]);
      workspaceActions(p,['sales','products','wallet']);
    }else if(actualSector==='retail'){
      const cat=o.tree||[];hint(p,'التصنيفات وبيانات المخزون تُقرأ من محرك التجزئة مباشرةً.');table(p,[['التصنيف',x=>x.name||x.name_ar||'—'],['الكود',x=>x.code||'—']],Array.isArray(cat)?cat:[]);
      workspaceActions(p,['sales','products','debts','branches','reports','wallet']);
    }else if(actualSector==='restaurant'){
      table(p,[['الطاولة',x=>x.label],['المقاعد',x=>x.seats],['الحالة',x=>x.status]],o.tables||[]);
      workspaceActions(p,['sales','products','debts','wallet']);
    }else{
      table(p,[['الطلب المعلق',x=>x.label||x.ulid||x.id],['الإنشاء',x=>x.created_at||'—']],o.tickets||[]);
      workspaceActions(p,['sales','products','debts','reports','wallet']);
    }
    hint(p,'هذه البيانات من وحدة قطاع منشأتك المسجّل؛ القطاعات الأخرى لا تمنح وصولًا إلى أعمالها.');
  }
  async function sales(){
    const state=(navigation.find(item=>item.tab==='sales')||{}).state;
    if(state&&state!=='available'){
      const p=box('سجل '+actualSectorName);hint(p,navigationNote(state));return;
    }
    const data=await api('sectorSales'),r=data.result||{};
    const p=box('سجل '+actualSectorName);
    hint(p,'يعرض هذا السجل المصدر التشغيلي الحقيقي لقطاع منشأتك. لا يُستبدل بسجل قطاع آخر ولا يخلط بين طرق الدفع.');
    if(actualSector==='fuel'){
      table(p,[['المرجع',x=>x.sale_number||x.reference_number||x.id],['الكمية',x=>x.liters??x.quantity??'—'],['المبلغ',x=>money(x.total_amount??x.amount)],['طريقة الدفع',x=>paymentLabel(x.payment_method)],['الحالة',x=>saleStatusLabel(x.status)],['التفاصيل',saleDetailButton]],r.sales||[]);
    }else if(actualSector==='pharmacy'){
      table(p,[['المرجع',x=>x.invoice_number||x.sale_number||x.id],['المبلغ',x=>money(x.total_amount??x.amount)],['طريقة الدفع',x=>paymentLabel(x.payment_method)],['الحالة',x=>saleStatusLabel(x.status)],['التاريخ',x=>x.created_at||'—'],['التفاصيل',saleDetailButton]],r.sales||[]);
    }else if(actualSector==='wholesale'){
      table(p,[['الفاتورة',x=>x.invoice_number||x.id],['العميل',x=>x.customer?.name||x.customer_name||'—'],['الإجمالي',x=>money(x.total_amount)],['المتبقي',x=>money(x.balance_due)],['الحالة',x=>saleStatusLabel(x.status)],['التفاصيل',saleDetailButton]],r.invoices||[]);
    }else if(actualSector==='restaurant'){
      table(p,[['الطلب',x=>x.order_number||x.id],['الطاولة',x=>x.table?.label||x.table_label||'—'],['الإجمالي',x=>money(x.total_amount??x.amount)],['الدفع',x=>paymentLabel(x.payment_method)],['الحالة',x=>saleStatusLabel(x.status)],['التفاصيل',saleDetailButton]],r.orders||[]);
    }else{
      table(p,[['المرجع',x=>x.invoice_number||x.sale_number||x.sale_ulid||x.ulid||x.id],['الإجمالي',x=>money(x.total_amount??x.grand_total??x.amount)],['طريقة الدفع',x=>paymentLabel(x.payment_method)],['الحالة',x=>saleStatusLabel(x.status)],['التاريخ',x=>x.created_at||'—'],['التفاصيل',saleDetailButton]],r.sales||[]);
    }
  }
  function saleIdentifier(row){return String((actualSector==='wholesale'||actualSector==='restaurant'?row.id:(row.sale_ulid||row.ulid||row.id))||'')}
  function saleDetailButton(row){const id=saleIdentifier(row),button=node('button','عرض','action secondary');button.type='button';button.disabled=!id;button.addEventListener('click',()=>showSaleDetail(id));return button}
  function invoiceButton(id){if(!['quick_sale','retail','pharmacy','fuel','wholesale','restaurant'].includes(actualSector))return null;const button=node('button','تنزيل الفاتورة','action secondary');button.type='button';button.addEventListener('click',()=>window.open(routes.sectorSaleInvoice.replace('__ID__',encodeURIComponent(id)),'_blank','noopener'));return button}
  async function showSaleDetail(id){
    const data=await api('sectorSaleDetail',undefined,routes.sectorSaleDetail.replace('__ID__',encodeURIComponent(id)));
    const detail=data.result||{},record=detail.sale||detail.invoice||detail.order||{};
    const p=box('تفاصيل العملية '+(record.invoice_number||record.order_number||record.sale_ulid||id));
    const detailCards=[['الإجمالي',money(record.total_amount??record.amount)],['طريقة الدفع',paymentLabel(record.payment_method)],['الحالة',saleStatusLabel(record.status)],['التاريخ',record.created_at]];
    const paymentRef=record.paid_transaction_id||record.transaction_id||record.payment_reference;
    if(paymentRef)detailCards.push(['مرجع دفع أميال',paymentRef]);
    if(record.settled_at)detailCards.push(['وقت التسوية',record.settled_at]);
    if(record.payment_method==='mixed'){detailCards.push(['الجزء النقدي',money(record.cash_amount)],['جزء أميال باي',money(record.wallet_amount)])}
    grid(detailCards);
    const lines=detail.lines||detail.items||record.items||[];
    if(lines.length)table(p,[['الصنف',x=>x.name||x.product?.name||x.product_name||'—'],['الكمية',x=>x.quantity??x.qty??'—'],['السعر',x=>money(x.unit_price??x.price)],['الإجمالي',x=>money(x.line_total??x.total_amount??x.total)]],lines);
    else hint(p,'لا يعلن محرك هذا القطاع أسطر الفاتورة في هذا السجل بعد؛ تُعرض بيانات العملية المتاحة فقط.');
    const invoice=invoiceButton(id);if(invoice){const actions=node('div',null,'buttons');actions.append(invoice);p.append(actions)}
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
    const cards=node('div',null,'grid plans-grid');
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
  async function posSetup(){
    const [branchesData,staffData,devicesData,rolesData]=await Promise.all([api('branches'),api('staff'),api('devices'),api('roles')]);
    const canUseBranches=navigation.some(item=>item.tab==='branches'&&item.state==='available');
    const branches=canUseBranches?(branchesData.branches||[]).filter(x=>x.is_active):[];
    const staff=(staffData.staff||[]).filter(x=>x.is_active);
    const devices=(devicesData.devices||[]).filter(x=>x.is_active);
    const roles=(rolesData.roles||[]).filter(x=>x.is_active);
    const panel=box('إعداد نقطة بيع جديدة');
    hint(panel,canUseBranches
      ?'اربط نقطة البيع بفرع أو بالمنشأة الرئيسية، ثم الموظف والجهاز. الجهاز أصل للمنشأة وليس ملكاً دائماً للموظف.'
      :'باقة الأعمال تعمل على المنشأة الرئيسية: أنشئ الموظف والجهاز الآن، وتصبح الفروع اختياراً إضافياً عند الترقية إلى مؤسسة.');
    let step=1,employeeMode='new',deviceMode='new',activation=null;
    const steps=node('div',null,'setup-steps'),stage=node('div');
    [['1','الفرع والموظف'],['2','الجهاز'],['3','المراجعة والتفعيل']].forEach(([number,label])=>{const item=node('div',number+' · '+label,'setup-step');item.dataset.step=number;steps.append(item)});
    panel.append(steps,stage);
    const value=(input)=>String(input?.value||'').trim();
    const field=(label,name,type='text',options=null)=>{
      const holder=node('label',label,'field'),input=options?node('select'):node('input');
      input.name=name;
      if(options){options.forEach(option=>{const item=node('option',option.label);item.value=option.value;input.append(item)})}
      else {input.type=type;if(type==='password')input.autocomplete='new-password';}
      holder.append(input);return [holder,input];
    };
    const activeStep=()=>steps.querySelectorAll('.setup-step').forEach(item=>{const n=Number(item.dataset.step);item.classList.toggle('current',n===step);item.classList.toggle('done',n<step)});
    const button=(label,klass='action')=>{const item=node('button',label,klass);item.type='button';return item};
    function setChoiceButtons(container,selected){container.querySelectorAll('button').forEach(b=>b.classList.toggle('active',b.dataset.value===selected))}
    function renderStepOne(){
      step=1;activeStep();stage.replaceChildren();
      const formEl=node('form',null,'editor');
      const branchOptions=[
        {value:'',label:canUseBranches?'المنشأة الرئيسية (بدون فرع)':'المنشأة الرئيسية'},
        ...branches.map(b=>({value:b.id,label:b.name+(b.city?' — '+b.city:'')})),
      ];
      const [branchLabel,branchSelect]=field(canUseBranches?'موقع نقطة البيع':'موقع التشغيل','branch_id','select',branchOptions);
      formEl.append(branchLabel);
      const modeLabel=node('div','الموظف','field'),modes=node('div',null,'setup-choice');
      [['new','إضافة موظف جديد'],['existing','استخدام موظف موجود']].forEach(([mode,label])=>{const b=button(label);b.className='setup-choice-button';b.dataset.value=mode;modes.append(b);b.addEventListener('click',()=>{employeeMode=mode;setChoiceButtons(modes,mode);renderEmployeeFields()})});
      modeLabel.append(modes);formEl.append(modeLabel);
      const employeeFields=node('div');formEl.append(employeeFields);
      const next=button('متابعة إلى الجهاز');next.type='submit';formEl.append(next);
      function renderEmployeeFields(){
        employeeFields.replaceChildren();
        if(employeeMode==='existing'){
          const [staffLabel,staffSelect]=field(canUseBranches?'الموظف النشط في موقع التشغيل':'الموظف النشط في المنشأة','existing_staff_id','select',[{value:'',label:'اختر الموظف'}]);
          const update=()=>{const normaliseBranch=x=>x===null||x===undefined?'':String(x);const selectedBranch=normaliseBranch(branchSelect.value);const rows=staff.filter(s=>normaliseBranch(s.branch_id)===selectedBranch);staffSelect.replaceChildren(node('option',rows.length?'اختر الموظف':'لا يوجد موظف نشط في موقع التشغيل'));staffSelect.options[0].value='';rows.forEach(s=>{const op=node('option',s.display_name+' — '+s.employee_code);op.value=s.id;staffSelect.append(op)});staffSelect.disabled=!rows.length;};
          branchSelect.addEventListener('change',update);update();staffSelect.required=true;employeeFields.append(staffLabel);
        }else{
          const [nameLabel,nameInput]=field('اسم الموظف','display_name');
          const [codeLabel,codeInput]=field('رمز دخول الموظف','employee_code');
          const [passwordLabel,passwordInput]=field('كلمة مرور مؤقتة','password','password');
          const [roleLabel,roleSelect]=field('دور الموظف','merchant_role_id','select',[{value:'',label:'الدور الافتراضي لنقطة البيع'},...roles.map(r=>({value:r.id,label:r.name_ar}))]);
          [nameInput,codeInput,passwordInput].forEach(x=>x.required=true);
          employeeFields.append(nameLabel,codeLabel,passwordLabel,roleLabel);
        }
      }
      setChoiceButtons(modes,employeeMode);renderEmployeeFields();
      formEl.addEventListener('submit',event=>{
        event.preventDefault();
        if(employeeMode==='existing'&&!formEl.querySelector('[name="existing_staff_id"]').value){message('اختر موظفاً نشطاً في موقع التشغيل');return}
        if(employeeMode==='new'&&(!value(formEl.querySelector('[name="display_name"]'))||!value(formEl.querySelector('[name="employee_code"]'))||!value(formEl.querySelector('[name="password"]')))){message('أكمل بيانات الموظف');return}
        renderStepTwo({branchId:branchSelect.value||null,branchName:branchSelect.selectedOptions[0].textContent,employeeId:employeeMode==='existing'?formEl.querySelector('[name="existing_staff_id"]').value:null,employeeName:employeeMode==='existing'?formEl.querySelector('[name="existing_staff_id"]').selectedOptions[0].textContent:null,staffPayload:employeeMode==='new'?{display_name:value(formEl.querySelector('[name="display_name"]')),employee_code:value(formEl.querySelector('[name="employee_code"]')),password:value(formEl.querySelector('[name="password"]')),merchant_role_id:value(formEl.querySelector('[name="merchant_role_id"]'))||null,branch_id:branchSelect.value||null}:null});
      });
    }
    function renderStepTwo(data){
      step=2;activeStep();stage.replaceChildren();
      const formEl=node('form',null,'editor');
      const info=node('p','موقع التشغيل: '+data.branchName,'note');stage.append(info);
      const modeLabel=node('div','الجهاز','field'),modes=node('div',null,'setup-choice');
      [['new','تفعيل جهاز جديد'],['existing','استخدام جهاز نشط']].forEach(([mode,label])=>{const b=button(label);b.className='setup-choice-button';b.dataset.value=mode;modes.append(b);b.addEventListener('click',()=>{deviceMode=mode;setChoiceButtons(modes,mode);renderDeviceFields()})});
      modeLabel.append(modes);formEl.append(modeLabel);
      const deviceFields=node('div');formEl.append(deviceFields);
      const back=button('رجوع','action secondary'),next=button('مراجعة الإعداد');next.type='submit';const actions=node('div',null,'setup-actions');actions.append(back,next);formEl.append(actions);stage.append(formEl);
      back.onclick=renderStepOne;
      function renderDeviceFields(){
        deviceFields.replaceChildren();
        if(deviceMode==='existing'){
          const normaliseBranch=x=>x===null||x===undefined?'':String(x);const rows=devices.filter(d=>normaliseBranch(d.branch_id)===normaliseBranch(data.branchId));
          const [deviceLabel,deviceSelect]=field('جهاز نشط في الفرع','existing_device_id','select',[{value:'',label:rows.length?'اختر الجهاز':'لا يوجد جهاز نشط في هذا الفرع'},...rows.map(d=>({value:d.id,label:d.display_name+(d.hint?' — '+d.hint:'')}))]);
          deviceSelect.required=true;deviceSelect.disabled=!rows.length;deviceFields.append(deviceLabel);
        }else{
          const [nameLabel,nameInput]=field('اسم الجهاز','display_name');nameInput.placeholder='مثال: كاشير 1';nameInput.required=true;deviceFields.append(nameLabel);
          deviceFields.append(node('p','سيظهر رمز تفعيل صالح لمرة واحدة لمدة 15 دقيقة. يُدخل في تطبيق نقطة البيع على الجهاز الفعلي.','note'));
        }
      }
      setChoiceButtons(modes,deviceMode);renderDeviceFields();
      formEl.addEventListener('submit',event=>{event.preventDefault();const selected=deviceMode==='existing'?formEl.querySelector('[name="existing_device_id"]'):null;if(deviceMode==='existing'&&!selected.value){message('اختر جهازاً نشطاً من هذا الفرع');return}if(deviceMode==='new'&&!value(formEl.querySelector('[name="display_name"]'))){message('اكتب اسماً واضحاً للجهاز');return}renderStepThree({...data,deviceId:selected?.value||null,deviceName:selected?.selectedOptions?.[0]?.textContent||value(formEl.querySelector('[name="display_name"]'))});});
    }
    function renderStepThree(data){
      step=3;activeStep();stage.replaceChildren();
      const review=node('div',null,'setup-summary');
      [['موقع التشغيل',data.branchName],['الموظف',data.employeeName||data.staffPayload.display_name],['الجهاز',data.deviceName],['الدور',data.staffPayload?(data.staffPayload.merchant_role_id?'الدور المحدد':'الدور الافتراضي لنقطة البيع'):'الدور الحالي للموظف']].forEach(([label,text])=>{const row=node('div');row.append(node('span',label),node('strong',text));review.append(row)});
      stage.append(node('p','راجع الإعداد قبل الإنشاء','muted'),review);
      const note=node('p',data.branchId?'سيُربط الموظف والجهاز بالفرع المحدد. كل عملية تحفظ الموظف والجهاز والوردية.':'سيعمل الموظف والجهاز على المنشأة الرئيسية دون فرع. كل عملية تحفظ الموظف والجهاز والوردية، ويمكن نقلهما إلى فرع لاحقاً عند توفر ميزة الفروع.','note');stage.append(note);
      const actions=node('div',null,'setup-actions'),back=button('رجوع','action secondary'),save=button(data.staffPayload||deviceMode==='new'?'إنشاء وإظهار التفعيل':'إتمام الإعداد');actions.append(back,save);stage.append(actions);
      back.onclick=()=>renderStepTwo(data);
      save.onclick=async()=>{
        save.disabled=true;back.disabled=true;
        try{
          // رمز الجهاز لا يحجز مقعداً ولا يظهر قبل اكتمال حساب الموظف. إن
          // تعذّر إنشاء الحساب يبقى الرمز مخفياً وينتهي تلقائياً، فلا ننشئ
          // موظفاً يتيماً بسبب فشل الخطوة اللاحقة.
          if(deviceMode==='new'){activation=await api('deviceActivation',{display_name:data.deviceName,branch_id:data.branchId});}
          let employee={id:data.employeeId,display_name:data.employeeName};
          if(data.staffPayload){employee=await api('staffCreate',data.staffPayload);}
          stage.replaceChildren(node('h3','تم إعداد نقطة البيع'),node('p','أُسند الموظف والجهاز إلى الفرع المختار. لا تبدأ المبيعات إلا بعد دخول الموظف وفتح وردية على الجهاز.','note'));
          const result=node('div',null,'setup-summary');
          [['الفرع',data.branchName],['الموظف',employee.employee_code?employee.employee_code+' — '+(data.staffPayload?.display_name||'موظف جديد'):data.employeeName],['الجهاز',data.deviceName]].forEach(([label,text])=>{const row=node('div');row.append(node('span',label),node('strong',text));result.append(row)});stage.append(result);
          if(activation?.activation_code){const secret=node('div',null,'setup-secret');secret.append(node('strong','رمز تفعيل الجهاز'),node('div',activation.activation_code,'setup-code'),node('p','ينتهي في: '+activation.expires_at,'muted'));const copy=button('نسخ رمز التفعيل','action secondary');copy.onclick=()=>navigator.clipboard.writeText(activation.activation_code).then(()=>message('تم نسخ رمز التفعيل'));secret.append(copy);stage.append(secret)}
          message('تم حفظ إعداد نقطة البيع');
        }catch(error){message(error.message);save.disabled=false;back.disabled=false;}
      };
    }
    renderStepOne();
  }
  function approvalAction(row){
    const actions=node('div',null,'buttons');
    if(row.status==='granted'){
      actions.append(node('span','تمت الموافقة — ينتظر إعادة التنفيذ','note'));
      return actions;
    }
    const grant=button('موافقة لمرة واحدة','action');
    grant.onclick=async()=>{
      grant.disabled=true;
      try{
        await api('approvalGrant',{note:'اعتماد من مالك المنشأة'},
          routes.approvalGrant.replace('__ID__',encodeURIComponent(row.id)));
        message('تم منح الإذن؛ يستطيع الموظف إعادة تنفيذ العملية الآن');
        await load('staff');
      }catch(e){message(e.message);grant.disabled=false}
    };
    const reject=button('رفض','action secondary');
    reject.onclick=async()=>{
      const reason=window.prompt('اكتب سبب الرفض للموظف');
      if(!reason||!reason.trim())return;
      reject.disabled=true;
      try{
        await api('approvalReject',{reason:reason.trim()},
          routes.approvalReject.replace('__ID__',encodeURIComponent(row.id)));
        message('تم رفض طلب الإذن');
        await load('staff');
      }catch(e){message(e.message);reject.disabled=false}
    };
    actions.append(grant,reject);return actions;
  }
  async function staff(){
    const [data,roles,approvalData]=await Promise.all([
      api('staff'),api('roles'),api('approvals')
    ]);
    const approvals=approvalData.approvals||[];
    if(approvals.length){
      const a=box('طلبات اعتماد نقاط البيع');
      hint(a,'هذه أفعال بدأها موظف نقطة البيع وتحتاج إذن المالك. الموافقة لا تنفذ العملية عن الموظف؛ تمنحه إذناً لمرة واحدة ثم يعيد التنفيذ بنفسه.');
      table(a,[
        ['الموظف',x=>(x.requested_by_name||'موظف')+(x.employee_code?' — '+x.employee_code:'')],
        ['الإجراء',x=>x.permission_label||x.permission_code],
        ['المبلغ',x=>x.amount===null?'—':money(x.amount)],
        ['السبب',x=>x.reason||'—'],
        ['ينتهي',x=>x.expires_at||'—'],
        ['الحالة',x=>x.status==='pending'?'بانتظار قرار':'موافق عليه'],
        ['القرار',approvalAction]
      ],approvals);
    }
    const p=box('الموظفون');
    table(p,[['الموظف',x=>x.display_name],['الرمز',x=>x.employee_code],['الفرع',x=>x.branch_name||'المنشأة'],['الحالة',x=>x.is_active?'نشط':'موقوف']],data.staff||[]);
    const create=box('إضافة موظف نقطة بيع');
    const choices=(roles.roles||[]).filter(r=>r.is_active).map(r=>({value:r.id,label:r.name_ar}));
    form(create,[['display_name','اسم الموظف'],['employee_code','رمز الدخول'],['password','كلمة مرور الموظف','password'],['merchant_role_id','الصلاحية','select',[{value:'',label:'الدور الافتراضي لنقطة البيع'},...choices]]], 'إنشاء حساب الموظف',d=>api('staffCreate',d))
  }
  async function devices(){const data=await api('devices');const p=box('الأجهزة المرخصة');table(p,[['الجهاز',x=>x.display_name],['الفرع',x=>x.branch_name||'—'],['الحالة',x=>x.is_active?'نشط':'غير نشط'],['الجلسات',x=>x.live_sessions??0]],data.devices||[]);const create=box('تفعيل جهاز بيع جديد');hint(create,'ينشئ مالك المنشأة رمزًا مؤقتًا صالحًا لمرة واحدة. أدخله في تطبيق نقطة البيع على الجهاز الجديد.');form(create,[['display_name','اسم الجهاز']], 'إنشاء رمز التفعيل',async d=>{return api('deviceActivation',d)})}
  async function reports(){const r=(await api('wallet')).report||{};const sales=r.sales||{},methods=sales.by_payment_method||{};grid([['إجمالي المبيعات',money(sales.gross)],['عدد المبيعات',sales.count],['نقدًا',money(methods.cash)],['عبر أميال',money(methods.amial_pay)],['آجل',money(methods.credit)]]);const p=box('الحركة اليومية');hint(p,'التقرير يفصل المبيعات عن التحصيلات وعن حركة المحفظة؛ لا تُحسب التحويلات الشخصية مبيعات.');table(p,[['الحركة',x=>x.label_ar],['نقدًا',x=>x.available?money(x.cash):'غير متاح'],['أميال',x=>x.available?money(x.amial_pay):'غير متاح'],['آجل',x=>x.available?money(x.credit):'غير متاح']],r.movement?.rows||[])}
  async function settings(){const data=await api('receipts'),s=data.settings||{};const p=box('هوية فاتورة منشأتك');form(p,[['store_name','اسم المنشأة'],['header_note','ترويسة الفاتورة'],['footer_note','تذييل الفاتورة'],['phone','هاتف المنشأة'],['address','عنوان المنشأة'],['paper_width','عرض الطابعة','select',[{value:'58',label:'58 مم'},{value:'80',label:'80 مم'}]]], 'حفظ إعدادات الفاتورة',d=>api('receiptsSave',d));p.querySelectorAll('input,select').forEach(input=>{if(s[input.name]!==undefined&&s[input.name]!==null)input.value=s[input.name];if(input.name==='store_name')input.value=@json($storeName)});hint(p,'إعدادات الفاتورة موحدة بين الويب وكل نقاط البيع. صلاحية طباعة السند متاحة بحسب خصائص القطاع.')}
  const pages={overview,sector,sales,wallet,debts,products,branches,posSetup,staff,devices,reports,settings,plans};
  async function load(tab){if(stopScanner){stopScanner();stopScanner=null;}active=tab;document.getElementById('page-title').textContent=titles[tab]||'بوابة المنشأة';document.querySelectorAll('[data-tab]').forEach(e=>{e.classList.toggle('active',e.dataset.tab===tab);e.setAttribute('aria-current',e.dataset.tab===tab?'page':'false')});content.replaceChildren(node('div','جارٍ تحميل بيانات المنشأة…','panel'));try{content.replaceChildren();if(!pages[tab])throw Error('هذا القسم غير معروف');await pages[tab]()}catch(e){content.replaceChildren();content.append(node('div',e.message||'تعذّر تحميل البيانات','error'))}}
  const sidebar=document.getElementById('merchant-side');
  const menuToggle=document.getElementById('menu-toggle');
  const menuBackdrop=document.getElementById('nav-backdrop');
  const compactLayout=window.matchMedia('(max-width:1199px)');
  function setMenu(open){
    const visible=Boolean(open&&compactLayout.matches);
    sidebar.classList.toggle('open',visible);
    menuToggle.setAttribute('aria-expanded',String(visible));
    menuToggle.setAttribute('aria-label',visible?'إغلاق القائمة':'فتح القائمة');
    menuBackdrop.hidden=!visible;
    document.body.classList.toggle('merchant-nav-open',visible);
  }
  menuToggle.addEventListener('click',()=>setMenu(!sidebar.classList.contains('open')));
  menuBackdrop.addEventListener('click',()=>{setMenu(false);menuToggle.focus()});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&sidebar.classList.contains('open')){setMenu(false);menuToggle.focus()}});
  compactLayout.addEventListener('change',()=>setMenu(false));
  buildNavigation();
  load(active);
})();
</script>
</body>
</html>
