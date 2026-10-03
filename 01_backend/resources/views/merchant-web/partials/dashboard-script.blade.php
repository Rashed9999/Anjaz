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
  function refundMethodLabel(v){return {cash:'نقد',wallet:'إلى محفظة العميل',credit_account:'خصم من ذمة العميل'}[v]||v||'—'}
  function returnStatusLabel(v){return {requested:'بانتظار المراجعة',approved:'معتمد',rejected:'مرفوض',completed:'مكتمل',pending_approval:'بانتظار اعتماد الإدارة'}[v]||v||'—'}
  function returnSettlementLabel(v){return {credit_note:'خصم من الذمة',refund_pending:'رد مالي مستحق',refund_partial:'رد مالي جزئي',refund_paid:'تم رد المال'}[v]||v||'—'}

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
    const symbols={dashboard_customize:'⌁',insights:'⌂',account_balance_wallet:'◉',inventory_2:'▤',payments:'◫',account_tree:'⌘',groups:'♙',people:'♟',point_of_sale:'▣',analytics:'▥',receipt_long:'▧',undo:'↶',print:'▰',extension:'✚',auto_awesome:'✧',storefront:'⌂',local_shipping:'▦',shopping_cart:'▨',business_center:'▣'};
    const groups=[
      ['نظرة عامة',['overview']],
      ['التشغيل والمبيعات',['sector','sales','returns','products','customers','debts','suppliers','expenses','assets']],
      ['الفريق ونقاط البيع',['branches','posSetup','staff','devices']],
      ['المالية والتقارير',['wallet','reports','documents']],
      ['إعدادات المنشأة',['settings','integrations','plans']],
    ];
    const byTab=new Map(navigation.map(item=>[item.tab,item])),seen=new Set();
    const appendItem=item=>{
      if(!item||seen.has(item.tab))return;seen.add(item.tab);
      const button=node('button',null,'nav');button.type='button';button.dataset.tab=item.tab;button.dataset.state=item.state;
      const icon=node('span',symbols[item.icon]||'•','nav-icon'),label=node('span',item.label);
      button.append(icon,label);button.title=item.state==='available'?item.label:navigationNote(item.state);
      button.addEventListener('click',()=>{setMenu(false);load(item.tab)});nav.append(button);
    };
    groups.forEach(([title,tabs])=>{
      const present=tabs.filter(tab=>byTab.has(tab));
      if(!present.length)return;
      nav.append(node('div',title,'nav-section-title'));
      present.forEach(tab=>appendItem(byTab.get(tab)));
    });
    const rest=navigation.filter(item=>!seen.has(item.tab));
    if(rest.length){nav.append(node('div','المزيد','nav-section-title'));rest.forEach(appendItem)}
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
  async function uploadCsv(key,file){
    const data=new FormData();data.append('file',file);
    const res=await fetch(routes[key],{
      method:'POST',credentials:'same-origin',
      headers:{Accept:'application/json','X-CSRF-TOKEN':csrf,'Idempotency-Key':'mw-csv-'+Date.now()+'-'+Math.random().toString(36).slice(2)},
      body:data
    });
    if(res.status===401){window.location.href=routes.login;throw Error('انتهت الجلسة')}
    let json={};try{json=await res.json()}catch(_){throw Error('وصل رد غير صالح من خادم الاستيراد')}
    if(!res.ok||json.success===false)throw Error(json.message||'لم ينجح الاستيراد');
    return json.meta&&typeof json.meta==='object'?json.meta:json
  }
  function downloadRoute(key){window.open(routes[key],'_blank','noopener')}
  function bulkCsvControls(panel,kind,onDone){
    const row=node('div',null,'buttons');
    const template=action('تنزيل قالب CSV',()=>downloadRoute(kind==='products'?'sectorProductsTemplate':'sectorCustomersTemplate'));
    const exportBtn=action('تصدير CSV',()=>downloadRoute(kind==='products'?'sectorProductsExport':'sectorCustomersExport'));
    const importBtn=action('استيراد CSV',()=>input.click(),false),input=node('input');
    input.type='file';input.accept='.csv,text/csv,text/plain';input.hidden=true;
    const result=node('div');result.setAttribute('aria-live','polite');
    importBtn.type='button';
    input.onchange=async()=>{
      const file=input.files?.[0];if(!file)return;
      if(file.size>5*1024*1024){message('الملف أكبر من 5MB');input.value='';return}
      if(!await amialConfirm('استيراد الملف','سيتم فحص الملف صفاً صفاً وتطبيق قواعد القطاع والباقة قبل حفظ أي بيانات.',{confirmLabel:'بدء الاستيراد'})){input.value='';return}
      importBtn.disabled=true;result.replaceChildren(node('p','جارٍ فحص واستيراد الملف…','note'));
      try{
        const data=await uploadCsv(kind==='products'?'sectorProductsImport':'sectorCustomersImport',file);
        const summary=node('div',null,'note');
        summary.append(node('strong','نتيجة الاستيراد: '),
          node('span','أضيف '+(data.added||0)+' · موجود من محاولة سابقة '+(data.already_done||0)+' · مرفوض '+(data.skipped||0)));
        result.replaceChildren(summary);
        const errors=data.row_errors||[];
        if(errors.length){
          table(result,[['صف',x=>x.line],['سبب الرفض',x=>x.reason]],errors);
          hint(result,'يُعرض أول 50 خطأ صفّي فقط. أصلح الملف وأعد رفعه؛ الصفوف الناجحة سابقاً لن تتكرر.');
        }else{
          hint(result,'اكتمل الاستيراد دون صفوف مرفوضة. إعادة نفس الملف آمنة ولن تنشئ نسخاً مكررة من الصفوف التي اكتملت.');
        }
        if(onDone)await onDone();
      }catch(e){result.replaceChildren(node('p',e.message||'تعذّر الاستيراد','error'))}
      finally{importBtn.disabled=false;input.value=''}
    };
    row.append(template,exportBtn,importBtn,input);panel.append(row,result);
  }
  function form(p,fields,button,submit){const f=node('form',null,'editor');fields.forEach(([key,label,type,options])=>{const l=node('label',label,'field');let inp;if(options){inp=node('select');options.forEach(o=>{const op=node('option',o.label);op.value=o.value;inp.append(op)})}else{inp=node('input');inp.type=type||'text';if(type==='number'){inp.step='any';inp.min='0'}if(type==='password')inp.autocomplete='new-password'}inp.name=key;inp.required=['name','price','trade_name','sale_price','base_price','price_per_liter','display_name','employee_code','password'].includes(key);l.append(inp);f.append(l)});const btn=node('button',button,'action');btn.type='submit';f.append(btn);f.addEventListener('submit',async ev=>{ev.preventDefault();btn.disabled=true;try{const data=Object.fromEntries(new FormData(f).entries());Object.keys(data).forEach(k=>{if(data[k]==='')delete data[k]});const result=await submit(data);if(result.activation_code){f.replaceChildren();const code=node('strong',result.activation_code);code.style.fontSize='29px';code.style.letterSpacing='5px';const secret=node('div',null,'note');secret.append(node('p','رمز التفعيل (صالح لمرة واحدة، حتى '+result.expires_at+')'),code);const copy=node('button','نسخ الرمز','action secondary');copy.type='button';copy.addEventListener('click',()=>navigator.clipboard.writeText(result.activation_code).then(()=>message('تم نسخ الرمز')));secret.append(copy);p.append(secret);message('تم إنشاء رمز التفعيل؛ انسخه قبل مغادرة الصفحة')}else{message(result.message||'تم الحفظ');await load(active)}}catch(e){message(e.message)}finally{btn.disabled=false}});p.append(f)}
  function svgNode(tag,attrs={}){
    const e=document.createElementNS('http://www.w3.org/2000/svg',tag);
    Object.entries(attrs).forEach(([k,v])=>e.setAttribute(k,String(v)));return e
  }
  function kpiCard(label,value,icon,foot='',tone=''){
    const card=node('article',null,'kpi-card'+(tone?' '+tone:'')),head=node('div',null,'kpi-head');
    head.append(node('span',label,'kpi-label'),node('span',icon,'kpi-icon'));
    card.append(head,node('div',value,'kpi-value'),node('div',foot,'kpi-foot'));return card
  }
  function dashboardKpis(items){
    const g=node('section',null,'kpi-grid');items.forEach(x=>g.append(kpiCard(...x)));content.append(g);return g
  }
  function salesTrendCard(series,total,change){
    const card=node('section',null,'chart-card'),head=node('div',null,'chart-head'),copy=node('div');
    copy.append(node('h3','اتجاه المبيعات'),node('small','آخر '+series.length+' يوم · '+money(total)));
    const changeText=change===null||change===undefined?'لا توجد مقارنة كافية':((Number(change)>=0?'+':'')+Number(change).toFixed(1)+'% عن أمس');
    head.append(copy,node('span',changeText,'source-chip'));card.append(head);
    if(!series.length){card.append(node('div','لا توجد بيانات في الفترة.','dashboard-empty'));return card}
    const w=760,h=220,pad=28,values=series.map(x=>Number(x.total||0)),max=Math.max(1,...values);
    const svg=svgNode('svg',{viewBox:'0 0 '+w+' '+h,class:'trend-svg','aria-label':'رسم اتجاه المبيعات','role':'img'});
    for(let i=0;i<4;i++){const y=pad+(h-pad*2)*(i/3);svg.append(svgNode('line',{x1:pad,y1:y,x2:w-pad,y2:y,stroke:'#e9efec','stroke-width':'1'}))}
    const points=values.map((v,i)=>{const x=pad+(series.length===1?0:i*(w-pad*2)/(series.length-1));const y=h-pad-(v/max)*(h-pad*2);return [x,y]});
    const area='M '+points[0][0]+' '+(h-pad)+' L '+points.map(p=>p[0]+' '+p[1]).join(' L ')+' L '+points.at(-1)[0]+' '+(h-pad)+' Z';
    svg.append(svgNode('path',{d:area,fill:'#1b8b6513'}));
    svg.append(svgNode('polyline',{points:points.map(p=>p.join(',')).join(' '),fill:'none',stroke:'#177455','stroke-width':'4','stroke-linecap':'round','stroke-linejoin':'round'}));
    points.forEach((p,i)=>{if(i===points.length-1||i===0||i===Math.floor(points.length/2))svg.append(svgNode('circle',{cx:p[0],cy:p[1],r:'5',fill:'#fff',stroke:'#177455','stroke-width':'3'}))});
    card.append(svg);
    const labels=node('div',null,'chart-labels'),fmt=d=>String(d||'').slice(5).split('-').reverse().join('/');
    labels.append(node('span',fmt(series[0]?.date)),node('span',fmt(series[Math.floor(series.length/2)]?.date)),node('span',fmt(series.at(-1)?.date)));card.append(labels);
    return card
  }
  function paymentMixCard(methods={}){
    const card=node('section',null,'chart-card'),head=node('div',null,'chart-head');
    head.append(node('div',null));head.firstChild.append(node('h3','طرق الدفع'),node('small','توزيع مبيعات اليوم حسب طريقة التحصيل'));card.append(head);
    const defs=[['cash','نقد','cash'],['amial_pay','أميال باي','wallet'],['credit','آجل / حساب','credit'],['other','أخرى','other']];
    const total=defs.reduce((s,[k])=>s+Number(methods[k]||0),0),list=node('div',null,'mix-list');
    defs.forEach(([key,label])=>{
      const value=Number(methods[key]||0),pct=total>0?Math.max(0,Math.min(100,value/total*100)):0,row=node('div',null,'mix-row');
      const svg=svgNode('svg',{viewBox:'0 0 100 9',preserveAspectRatio:'none',class:'mix-svg'});
      svg.append(svgNode('rect',{x:0,y:0,width:100,height:9,rx:4.5,fill:'#eaf0ed'}));
      svg.append(svgNode('rect',{x:0,y:0,width:pct,height:9,rx:4.5,fill:key==='cash'?'#177455':key==='amial_pay'?'#d5a43d':key==='credit'?'#6384a7':'#9aa9a3'}));
      row.append(node('strong',label),svg,node('span',money(value)));list.append(row)
    });
    if(total===0)list.append(node('div','لا توجد مبيعات مالية اليوم بعد.','dashboard-empty'));
    card.append(list);return card
  }
  function operationsCard(counts={}){
    const card=node('section',null,'chart-card'),head=node('div',null,'chart-head');head.append(node('div'));head.firstChild.append(node('h3','حالة التشغيل'),node('small','الأشخاص والأجهزة والورديات المرتبطة بالمنشأة'));card.append(head);
    const g=node('div',null,'ops-grid');
    [['الموظفون النشطون',counts.active_employees??0],['الأجهزة المتصلة',counts.active_device_sessions??0],['الورديات المفتوحة',counts.open_shifts??0],['الفروع النشطة',counts.active_branches??0]].forEach(([label,value])=>{const d=node('div',null,'ops-card');d.append(node('span',label),node('strong',value));g.append(d)});
    card.append(g);return card
  }
  function attentionCard(report={},counts={}){
    const card=node('section',null,'chart-card'),head=node('div',null,'chart-head');head.append(node('div'));head.firstChild.append(node('h3','يحتاج انتباهك'),node('small','تنبيهات تشغيلية مبنية على الحالة الحالية'));card.append(head);
    const list=node('div',null,'attention-list'),receivable=Number(report.receivables?.amount||0),open=Number(counts.open_shifts||0),devices=Number(counts.devices||0),live=Number(counts.active_device_sessions||0);
    const add=(icon,title,detail,tone='')=>{const r=node('div',null,'attention-item'+(tone?' '+tone:''));r.append(node('span',icon,'att-icon'));const x=node('div');x.append(node('strong',title),node('small',detail));r.append(x);list.append(r)};
    if(receivable>0)add('◫','ذمم عملاء قائمة',money(receivable)+' ما زالت مستحقة على العملاء.','warn');
    if(open>0)add('◷','ورديات مفتوحة',open+' وردية لم تُقفل بعد؛ راقب جرد الصندوق وتسليم النقد.','warn');
    if(devices>0&&live<devices)add('▣','أجهزة غير متصلة',(devices-live)+' جهاز مرخص لا يملك جلسة نشطة الآن.');
    if(receivable===0&&open===0&&(devices===0||live===devices))add('✓','التشغيل مستقر','لا توجد ذمم أو ورديات مفتوحة تحتاج إجراءً فوريًا.');
    card.append(list);return card
  }
  function sectorIntelligenceCard(sector={}){
    if(!sector||!(sector.cards||[]).length)return null;
    const section=node('section',null,'chart-card'),head=node('div',null,'chart-head'),copy=node('div');
    const titles={retail:'ذكاء المبيعات والمخزون',pharmacy:'صيدلية · المخزون والصلاحية',fuel:'محطة الوقود · التشغيل اليومي',restaurant:'المطعم · الطلبات والطاولات',wholesale:'الجملة · الاستحقاقات والذمم'};
    copy.append(node('h3',titles[sector.kind]||'مؤشرات القطاع'),node('small','من محرك القطاع نفسه · '+(sector.meta?.source||'المصدر التشغيلي')));
    head.append(copy,node('span',actualSectorName||sector.vertical||'القطاع','source-chip'));section.append(head);

    const g=node('div',null,'kpi-grid');
    (sector.cards||[]).forEach(x=>{
      const tone=x.tone==='danger'?'red':x.tone==='warning'?'gold':x.tone==='ok'?'':'blue';
      const value=x.money?money(x.value):String(x.value??'—');
      g.append(kpiCard(x.label,value,x.tone==='danger'?'!':x.tone==='warning'?'◷':'◇','',tone));
    });
    section.append(g);

    const lists=sector.lists||{};
    if(sector.kind==='retail'){
      if((lists.stock_attention||[]).length){
        const stockTitle=node('h3','تنبيهات المخزون حسب الموقع');section.append(stockTitle);
        hint(section,'المتاح = الموجود ناقص المحجوز. امتلاء المستودع لا يخفي نفاد فرع البيع.');
        table(section,[
          ['الصنف',x=>x.product],['الموقع',x=>x.location],
          ['الموجود',x=>x.on_hand],['المحجوز',x=>x.reserved||'0'],['المتاح',x=>x.available],
          ['الحالة',x=>x.state==='negative'?'رصيد سالب':x.state==='out'?'نافد للبيع':'تحت حد الطلب']
        ],lists.stock_attention);
      }
      if((lists.top_products||[]).length){
        const title=node('h3','الأصناف الأعلى أداءً');section.append(title);
        table(section,[
          ['الصنف',x=>x.name],['صافي الكمية',x=>x.qty],['الإيراد',x=>money(x.revenue)],
          ['المرتجع',x=>x.returned_qty||'0'],['الربح المعروف',x=>money(x.profit)],
          ['الهامش',x=>x.margin_percent===null?'غير متاح':x.margin_percent+'%']
        ],lists.top_products);
        if(sector.meta?.cost_note)hint(section,sector.meta.cost_note);
      }
    }else if(sector.kind==='pharmacy'&&(lists.expiring_batches||[]).length){
      const title=node('h3','الدفعات الأقرب للصلاحية');section.append(title);
      table(section,[
        ['الدواء',x=>x.product],['التشغيلة',x=>x.batch_number],['الصلاحية',x=>x.expiry_date||'—'],
        ['المتبقي',x=>x.quantity_remaining],
        ['الحالة',x=>x.status==='expired'?'منتهية':x.status==='near_expiry'?'تنتهي خلال 30 يومًا':'قريبة']
      ],lists.expiring_batches);
    }else if(sector.kind==='fuel'&&(lists.by_product||[]).length){
      const title=node('h3','مبيعات الوقود اليوم حسب النوع');section.append(title);
      table(section,[['النوع',x=>x.name],['اللترات',x=>x.liters],['القيمة',x=>money(x.total)]],lists.by_product);
    }else if(sector.kind==='restaurant'&&(lists.active_orders||[]).length){
      const title=node('h3','الطلبات المفتوحة الآن');section.append(title);
      table(section,[
        ['الطلب',x=>x.order_no||x.invoice_number||x.id],['الحالة',x=>saleStatusLabel(x.status)],
        ['الإجمالي',x=>money(x.total)],['وقت الفتح',x=>x.opened_at?new Date(x.opened_at).toLocaleString('ar-YE'):'—']
      ],lists.active_orders);
    }else if(sector.kind==='wholesale'&&(lists.overdue_invoices||[]).length){
      const title=node('h3','الفواتير الأكثر تأخرًا');section.append(title);
      table(section,[
        ['الفاتورة',x=>x.invoice_number],['العميل',x=>x.customer],['تاريخ الاستحقاق',x=>x.due_date||'—'],
        ['أيام التأخير',x=>x.days_overdue],['الرصيد',x=>money(x.balance_due)]
      ],lists.overdue_invoices);
    }
    return section
  }

  function dashboardPeriodBar(days){
    const bar=node('section',null,'panel'),row=node('div',null,'buttons');
    hint(bar,'غيّر الفترة لتحليل اتجاه المبيعات ومؤشرات القطاع دون تغيير رصيد المحفظة أو تعريف «مبيعات اليوم».');
    [7,14,30].forEach(n=>{
      const b=action(n+' أيام',()=>{content.replaceChildren();overview(n).catch(e=>message(e.message))},n!==days);
      if(n===days)b.disabled=true;row.append(b);
    });
    bar.append(row);return bar
  }

  function quickHero(dashboard){
    const hero=node('section',null,'dashboard-hero');
    hero.append(node('div','لوحة القيادة اليومية','hero-kicker'),node('h2','صورة واحدة لحالة منشأتك الآن'));
    hero.append(node('p','المبيعات، طرق الدفع، المحفظة، الذمم ونقاط البيع معروضة كلٌّ من مصدره الحقيقي؛ لا نخلط النقد في الدرج برصيد أميال أو بالدين الآجل.'));
    const actions=node('div',null,'hero-actions'),available=new Map(navigation.map(x=>[x.tab,x.state==='available']));
    [['sales','فتح المبيعات',true],['products','المنتجات والمخزون',false],['posSetup','إعداد نقطة بيع',false],['reports','التقارير',false]].forEach(([tab,label,primary])=>{
      if(!available.get(tab))return;const b=node('button',label,primary?'primary':'');b.type='button';b.onclick=()=>load(tab);actions.append(b)
    });
    hero.append(actions);return hero
  }

  async function overview(days=14){
    if(!actualSector){
      const data=await api('sectorTypes'),p=box('ابدأ بتحديد نشاط المنشأة');
      hint(p,'نوع النشاط يحدد مسارات البيع والمخزون والتقارير. لن نعرض أدوات قطاع آخر أو نخمن مصدر أرقام غير موجود.');
      const form=node('form',null,'editor'),label=node('label','نوع النشاط التجاري','field'),select=node('select');
      select.name='business_type';select.required=true;select.append(new Option('اختر النشاط',''));
      (data.business_types||[]).forEach(type=>{const option=new Option(type.label+(type.hint?' — '+type.hint:''),type.code);select.append(option)});
      label.append(select);form.append(label);
      const save=node('button','حفظ وفتح لوحة المنشأة','action');save.type='submit';form.append(save);
      form.addEventListener('submit',async ev=>{ev.preventDefault();if(!select.value)return;save.disabled=true;try{await api('sectorTypeSave',{business_type:select.value},undefined,'PUT');window.location.reload()}catch(e){message(e.message);save.disabled=false}});
      p.append(form);return;
    }

    const dashUrl=new URL(routes.dashboardV2,window.location.href);dashUrl.searchParams.set('days',String(days));
    const [bundle,o]=await Promise.all([api('dashboardV2',undefined,dashUrl.toString()),api('overview')]);
    const r=bundle.financial||{},d=bundle.dashboard||{},sector=bundle.sector||{},counts=o.counts||{},sales=r.sales||{},methods=sales.by_payment_method||{},movement=r.movement||{};
    const todayCount=Number(sales.count||0),todayGross=Number(sales.gross||0),todayAvg=todayCount>0?todayGross/todayCount:0;
    const saleReturn=(movement.rows||[]).find(x=>x.code==='sale_return'),returnToday=saleReturn?.available?Number(saleReturn.total||0):null;

    content.append(quickHero(d),dashboardPeriodBar(days));
    dashboardKpis([
      ['مبيعات اليوم',money(sales.gross),'↗',d.today_change_percent===null?'المقارنة تحتاج مبيعات أمس':((Number(d.today_change_percent)>=0?'+':'')+Number(d.today_change_percent).toFixed(1)+'% عن أمس')],
      ['عدد عمليات البيع',String(todayCount),'▧','متوسط الفاتورة '+money(todayAvg),'blue'],
      ['المبيعات النقدية',money(methods.cash),'▣','تبقى في درج الوردية حتى التسليم'],
      ['مدفوعات أميال',money(methods.amial_pay),'◉','تصل إلى محفظة المنشأة','gold'],
      ['المبيعات الآجلة',money(methods.credit),'◫','تُسجل في ذمم العملاء','blue'],
      ['رصيد المحفظة',money(r.wallet?.balance),'◎','محفظة أميال الإلكترونية فقط','gold'],
      ['إجمالي الذمم',money(r.receivables?.amount),'◌','لا يُعدّ نقدًا أو رصيد محفظة',Number(r.receivables?.amount||0)>0?'red':''],
      ['مرتجعات اليوم',returnToday===null?'غير متاحة':money(returnToday),'↶',saleReturn?.available?(saleReturn.count+' مرتجع من '+saleReturn.source):'لا يملك هذا القطاع مصدر مرتجع','red'],
      ['متوسط '+(d.days||14)+' يوم',money(d.average_ticket),'◇',(d.period_count||0)+' عملية خلال الفترة'],
    ]);

    const analytics=node('div',null,'dash-grid');
    analytics.append(salesTrendCard(d.series||[],d.period_total,d.today_change_percent),paymentMixCard(methods));content.append(analytics);

    const operation=node('div',null,'dash-grid equal');
    operation.append(operationsCard(counts),attentionCard(r,counts));content.append(operation);

    const sectorCard=sectorIntelligenceCard(sector);if(sectorCard)content.append(sectorCard);

    const recent=node('section',null,'chart-card'),recentHead=node('div',null,'recent-head');
    recentHead.append(node('h3','آخر المبيعات'),node('button','عرض سجل المبيعات','text-button'));
    recentHead.lastChild.type='button';recentHead.lastChild.onclick=()=>load('sales');recent.append(recentHead);
    const rows=d.recent_sales||[];
    if(rows.length){
      table(recent,[['الوقت',x=>x.occurred_at?new Date(x.occurred_at).toLocaleString('ar-YE'):'—'],['الفاتورة',x=>x.document_number||x.reference],['طريقة الدفع',x=>paymentLabel(x.payment_method)],['الحالة',x=>saleStatusLabel(x.status)],['القيمة',x=>money(x.amount)]],rows);
    }else recent.append(node('div','لم تُسجل مبيعات في الفترة الحالية بعد.','dashboard-empty'));
    recent.append(node('div','المصدر: '+(d.source||sales.source||'سجل القطاع'),'source-chip'));content.append(recent);

    if((o.open_shifts||[]).length){
      const shifts=box('الورديات المفتوحة الآن');
      hint(shifts,'هذه قائمة تشغيلية فقط؛ قيمة النقد المتوقعة والجرد النهائي تبقى في تقرير الوردية نفسه.');
      table(shifts,[['الموظف',x=>x.opened_by_name||'—'],['الموقع',x=>x.branch_name||'المنشأة الرئيسية'],['وقت الفتح',x=>x.opened_at?new Date(x.opened_at).toLocaleString('ar-YE'):'—']],o.open_shifts||[]);
    }
  }
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
  function customerName(row){
    return row.full_name||row.customer_name||row.company_name||'عميل';
  }
  function customerPhone(row){
    return row.phone||row.customer_phone||'—';
  }
  function customerBalance(row){
    return row.current_balance===undefined||row.current_balance===null?null:Number(row.current_balance);
  }
  function customerClassLabel(v){
    return {gold:'ذهبي',silver:'فضي',bronze:'برونزي'}[v]||v||'—';
  }
  function customerEditor(row=null){
    document.getElementById('customer-editor')?.remove();
    const p=box(row?'تعديل بيانات العميل':'إضافة عميل');
    p.id='customer-editor';
    const fields=actualSector==='pharmacy'?[
      ['full_name','اسم العميل / المريض','text'],
      ['phone','رقم الهاتف','tel'],
      ['date_of_birth','تاريخ الميلاد','date'],
      ['gender','الجنس','select',[{value:'',label:'غير محدد'},{value:'male',label:'ذكر'},{value:'female',label:'أنثى'}]],
      ['notes','ملاحظات','text'],
    ]:actualSector==='wholesale'?[
      ['full_name','اسم العميل','text'],
      ['company_name','اسم المنشأة / الشركة','text'],
      ['phone','رقم الهاتف','tel'],
      ['email','البريد الإلكتروني','email'],
      ['city','المدينة','text'],
      ['address','العنوان','text'],
      ['credit_limit','حد الائتمان','number'],
      ['payment_terms_days','مدة السداد بالأيام','number'],
      ['tax_number','الرقم الضريبي','text'],
      ['notes','ملاحظات','text'],
    ]:[
      ['name','اسم العميل','text'],
      ['phone','رقم الهاتف','tel'],
      ['credit_limit','حد الائتمان (اختياري)','number'],
    ];

    const frm=node('form',null,'editor');
    fields.forEach(([key,label,type,options])=>{
      const holder=node('label',label,'field');
      let input;
      if(type==='select'){
        input=node('select');
        (options||[]).forEach(o=>{const op=node('option',o.label);op.value=o.value;input.append(op)});
      }else{
        input=node('input');input.type=type||'text';
        if(type==='number'){input.min='0';input.step='any'}
      }
      input.name=key;
      const sourceKey=key==='name'?'customer_name':key==='phone'?(actualSector==='retail'||actualSector==='quick_sale'||actualSector==='restaurant'?'customer_phone':'phone'):key;
      const value=row?.[sourceKey]??row?.[key]??'';
      input.value=value===null||value===undefined?'':String(value);
      if(['full_name','name'].includes(key))input.required=true;
      if(key==='phone'&&!['pharmacy','wholesale'].includes(actualSector))input.required=true;
      holder.append(input);frm.append(holder);
    });

    const actions=node('div',null,'buttons');
    const save=node('button',row?'حفظ التعديلات':'إضافة العميل','action');save.type='submit';
    const cancel=node('button','إلغاء','action secondary');cancel.type='button';cancel.onclick=()=>p.remove();
    actions.append(save,cancel);frm.append(actions);
    frm.addEventListener('submit',async ev=>{
      ev.preventDefault();save.disabled=true;
      try{
        const payload=Object.fromEntries(new FormData(frm).entries());
        Object.keys(payload).forEach(k=>{if(payload[k]==='')delete payload[k]});
        let result;
        if(row&&['pharmacy','wholesale'].includes(actualSector)){
          result=await api('sectorCustomersUpdate',payload,routes.sectorCustomersUpdate.replace('__ID__',String(row.id)),'PUT');
        }else{
          result=await api('sectorCustomersCreate',payload);
        }
        message(result.message||'تم حفظ العميل');
        await load('customers');
      }catch(e){message(e.message);save.disabled=false}
    });
    p.append(frm);
    p.scrollIntoView({behavior:'smooth',block:'start'});
  }
  async function showCustomerProfile(id){
    document.getElementById('customer-profile-360')?.remove();
    const data=await api('sectorCustomerProfile',undefined,routes.sectorCustomerProfile.replace('__ID__',String(id)));
    const pData=data.profile||{},identity=pData.identity||{},commerce=pData.commerce||{},credit=pData.credit||null;
    const p=box('ملف العميل 360° · '+(identity.company_name||identity.name||'العميل'));p.id='customer-profile-360';
    hint(p,'هذا الملف يجمع سجل القطاع مع دفتر الدين الموحد دون جمع الرصيدين معاً؛ كل رقم يحتفظ بمصدره حتى لا تتكرر الذمة أو المبيعات.');

    const summary=node('div',null,'kpi-grid');
    summary.append(
      kpiCard('إجمالي المشتريات',money(commerce.sales_total||0),'↗',(commerce.sales_count||0)+' عملية'),
      kpiCard('متوسط الفاتورة',commerce.average_ticket!==undefined?money(commerce.average_ticket):'حسب القطاع','◇',commerce.last_visit_at?'آخر زيارة '+new Date(commerce.last_visit_at).toLocaleDateString('ar-YE'):(commerce.last_purchase_date?'آخر شراء '+commerce.last_purchase_date:'لا توجد زيارة مسجلة'),'blue'),
      kpiCard('الدين الموحد',credit?money(credit.current_balance):'لا يوجد حساب آجل','◫',credit?.credit_limit?'الحد '+money(credit.credit_limit):'لا يُفترض وجود دين من مجرد وجود العميل',Number(credit?.current_balance||0)>0?'red':''),
      kpiCard('الهاتف',identity.phone||'غير مسجل','♟',identity.company_name||identity.city||'ملف العميل')
    );
    p.append(summary);

    const identityPanel=node('section',null,'panel');identityPanel.append(node('h2','بيانات العميل'));
    const identityGrid=node('div',null,'grid');
    const pairs=[
      ['الاسم',identity.name||'—'],['المنشأة',identity.company_name||'—'],['الهاتف',identity.phone||'—'],
      ['البريد',identity.email||'—'],['المدينة',identity.city||'—'],['العنوان',identity.address||'—'],
      ['الرقم الضريبي',identity.tax_number||'—'],['التصنيف',customerClassLabel(identity.classification)]
    ].filter(([,v])=>v!=='—');
    pairs.forEach(([label,value])=>identityGrid.append(metric(label,String(value))));identityPanel.append(identityGrid);p.append(identityPanel);

    if(pData.kind==='pharmacy'){
      const clinical=pData.clinical||{},clinicalPanel=node('section',null,'panel');clinicalPanel.append(node('h2','بيانات السلامة الدوائية'));
      hint(clinicalPanel,'هذه البيانات تخص ملف الصيدلية وتستخدم لتنبيه البيع عند الحساسية أو الحالات الخاصة؛ لا تُعرض خارج هذا القطاع.');
      const rows=[
        ['الحساسيات',(clinical.allergies||[]).join('، ')||'لا توجد مسجلة'],
        ['الأمراض المزمنة',(clinical.chronic_conditions||[]).join('، ')||'لا توجد مسجلة'],
        ['الأدوية المنتظمة',(clinical.regular_medications||[]).join('، ')||'لا توجد مسجلة'],
        ['الحمل',clinical.is_pregnant?'نعم':'لا'],['الرضاعة',clinical.is_breastfeeding?'نعم':'لا']
      ];
      table(clinicalPanel,[['البند',x=>x[0]],['البيانات',x=>x[1]]],rows);p.append(clinicalPanel);
    }

    if(pData.kind==='wholesale'){
      const policy=pData.wholesale_credit_policy||{},policyPanel=node('section',null,'panel');policyPanel.append(node('h2','سياسة عميل الجملة'));
      const g=node('div',null,'grid');
      g.append(metric('حد الائتمان',money(policy.credit_limit||0)),metric('رصيد الجملة التشغيلي',money(policy.current_balance_snapshot||0)),
        metric('الائتمان المتاح',money(policy.available_credit||0)),metric('مدة السداد',policy.payment_terms_days?policy.payment_terms_days+' يوم':'غير محددة'));
      policyPanel.append(g);hint(policyPanel,policy.note||'');p.append(policyPanel);

      const invoices=commerce.recent_invoices||[];
      const inv=node('section',null,'panel');inv.append(node('h2','آخر فواتير الجملة'));
      table(inv,[['الفاتورة',x=>x.document_number],['التاريخ',x=>x.invoice_date||'—'],['الاستحقاق',x=>x.due_date||'—'],
        ['الإجمالي',x=>money(x.total)],['المدفوع',x=>money(x.paid)],['المتبقي',x=>money(x.balance_due)],['الحالة',x=>saleStatusLabel(x.status)]],invoices);
      p.append(inv);

      const cols=commerce.recent_collections||[];
      if(cols.length){const cp=node('section',null,'panel');cp.append(node('h2','آخر التحصيلات'));table(cp,[['التاريخ',x=>x.date||'—'],['المبلغ',x=>money(x.amount)],['الطريقة',x=>paymentLabel(x.payment_method)],['المرجع',x=>x.reference_number||x.reference||'—']],cols);p.append(cp)}
    }else{
      const sales=commerce.recent_sales||[];
      const sp=node('section',null,'panel');sp.append(node('h2','آخر المشتريات'));
      table(sp,[['التاريخ',x=>x.occurred_at?new Date(x.occurred_at).toLocaleString('ar-YE'):'—'],
        ['الفاتورة',x=>x.document_number||x.reference],['طريقة الدفع',x=>paymentLabel(x.payment_method)],
        ['الإجمالي',x=>money(x.total)],['الحالة',x=>saleStatusLabel(x.status)]],sales);p.append(sp);
    }

    if(credit){
      const ledger=node('section',null,'panel');ledger.append(node('h2','دفتر الدين الموحد'));
      const cg=node('div',null,'grid');
      cg.append(metric('الرصيد الحالي',money(credit.current_balance)),metric('الحد',credit.credit_limit?money(credit.credit_limit):'غير محدد'),
        metric('إجمالي الزيادات',money(credit.totals?.debit||0)),metric('إجمالي السداد/الخصم',money(credit.totals?.credit||0)));
      ledger.append(cg);
      const pdf=node('a','تنزيل كشف الحساب PDF','link-action');pdf.href=debtUrl('debtStatementPdf',credit.account_id);pdf.target='_blank';pdf.rel='noopener noreferrer';ledger.append(pdf);
      table(ledger,[['التاريخ',x=>x.created_at?new Date(x.created_at).toLocaleString('ar-YE'):'—'],
        ['النوع',x=>x.type==='sale'?'بيع آجل':x.type==='payment'?'سداد':x.type==='return'?'مرتجع':'تعديل'],
        ['المبلغ',x=>money(x.amount)],['الرصيد بعد',x=>money(x.balance_after)],
        ['المرجع',x=>x.reference_number||x.reference_id||'—'],['ملاحظة',x=>x.note||'—']],credit.movements||[]);
      p.append(ledger);
    }

    const close=action('إغلاق الملف',()=>p.remove());p.append(close);
    p.scrollIntoView({behavior:'smooth',block:'start'});
  }

  async function customers(search=''){
    const url=new URL(routes.sectorCustomers,window.location.href);
    if(search)url.searchParams.set('search',search);
    const data=await api('sectorCustomers',undefined,url.toString()),r=data.result||{};
    const rows=Array.isArray(r.customers)?r.customers:[];
    const pagination=r.pagination||{};
    const balanceRows=rows.filter(x=>customerBalance(x)!==null);
    const debtors=balanceRows.filter(x=>customerBalance(x)>0).length;
    const totalKnown=balanceRows.reduce((sum,x)=>sum+Math.max(0,customerBalance(x)||0),0);

    const summary=node('section',null,'kpi-grid');
    summary.append(
      kpiCard('العملاء المعروضون',String(rows.length),'♟',pagination.total!==undefined?'إجمالي السجل '+pagination.total:'حسب نتائج البحث'),
      kpiCard('عملاء برصيد مستحق',String(debtors),'◫',balanceRows.length?'ضمن النتائج المعروضة':'لا يعلن هذا القطاع رصيداً هنا','blue'),
      kpiCard('رصيد مستحق ظاهر',balanceRows.length?money(totalKnown):'غير معروض','◌','لا يُخلط مع رصيد المحفظة',totalKnown>0?'red':''),
      kpiCard('نوع ملف العميل',actualSector==='pharmacy'?'ملف صيدلية':actualSector==='wholesale'?'عميل جملة':'عميل المنشأة','◇','المصدر تابع لقطاع المنشأة')
    );
    content.append(summary);

    const p=box('قاعدة بيانات العملاء');
    hint(p,actualSector==='pharmacy'
      ?'هذا ملف عملاء الصيدلية نفسه المستخدم أثناء البيع والوصفات؛ لا ننشئ نسخة عملاء موازية في الويب.'
      :actualSector==='wholesale'
        ?'هذه قاعدة عملاء الجملة نفسها المرتبطة بالفواتير وشروط السداد والحدود الائتمانية.'
        :'العميل هنا هو الحساب الموحد الذي يمكن أن يرتبط بالبيع الآجل وكشف الحساب. وجوده لا يعني أن عليه ديناً.');
    bulkCsvControls(p,'customers');
    hint(p,'استيراد العملاء لا يستورد أرصدة الديون أو الحركات المالية. الأرصدة تُنشأ فقط من بيع آجل/تحصيل/مرتجع موثق، فلا يمكن لملف CSV اختراع ذمة.');

    const tools=node('form',null,'product-tools'),searchLabel=node('label','بحث بالاسم أو الهاتف','field'),input=node('input');
    input.type='search';input.value=search;input.placeholder='اسم العميل أو رقم الهاتف';searchLabel.append(input);
    const go=node('button','بحث','action secondary');go.type='submit';
    const add=node('button','+ إضافة عميل','action');add.type='button';add.onclick=()=>customerEditor();
    tools.append(searchLabel,go,add);p.append(tools);
    tools.addEventListener('submit',ev=>{ev.preventDefault();customers(input.value.trim()).catch(e=>message(e.message))});

    if(actualSector==='pharmacy'){
      table(p,[
        ['العميل',x=>customerName(x)],['الهاتف',x=>customerPhone(x)],
        ['تاريخ الميلاد',x=>x.date_of_birth||'—'],
        ['الجنس',x=>x.gender==='male'?'ذكر':x.gender==='female'?'أنثى':'—'],
        ['ملاحظات',x=>x.notes||'—'],
        ['الإجراء',x=>buttons([action('فتح الملف',()=>showCustomerProfile(x.id)),action('تعديل',()=>customerEditor(x))])]
      ],rows);
    }else if(actualSector==='wholesale'){
      table(p,[
        ['العميل',x=>customerName(x)],['المنشأة',x=>x.company_name||'—'],
        ['الهاتف',x=>customerPhone(x)],['المدينة',x=>x.city||'—'],
        ['الرصيد المستحق',x=>money(x.current_balance||0)],
        ['حد الائتمان',x=>x.credit_limit?money(x.credit_limit):'غير محدد'],
        ['مدة السداد',x=>x.payment_terms_days?x.payment_terms_days+' يوم':'—'],
        ['الإجراء',x=>buttons([action('فتح الملف',()=>showCustomerProfile(x.id)),action('تعديل',()=>customerEditor(x))])]
      ],rows);
    }else{
      const canOpenDebt=navigation.some(item=>item.tab==='debts'&&item.state==='available');
      table(p,[
        ['العميل',x=>customerName(x)],['الهاتف',x=>customerPhone(x)],
        ['التصنيف',x=>customerClassLabel(x.classification)],
        ['الرصيد المستحق',x=>money(x.current_balance||0)],
        ['حد الائتمان',x=>Number(x.credit_limit||0)>0?money(x.credit_limit):'غير محدد'],
        ['آخر سداد',x=>x.last_payment_at||'—'],
        ['الإجراء',x=>buttons([
          action('فتح الملف',()=>showCustomerProfile(x.id)),
          action('تعديل',()=>customerEditor(x)),
          canOpenDebt?action('تحصيل / دين',()=>{load('debts').then(()=>setTimeout(()=>debtDetails(x.id),100))}):null
        ])]
      ],rows);
    }

    if(!rows.length)p.append(node('div',search?'لا توجد نتائج مطابقة للبحث.':'لا يوجد عملاء مسجلون حتى الآن.','dashboard-empty'));
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
      if(!await amialConfirm('تأكيد التحصيل','سيتم تسجيل تحصيل بقيمة '+money(value)+' '+label+'.',{confirmLabel:'تأكيد التحصيل'}))return;
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
    const generic=['retail','restaurant'].includes(actualSector);
    const editable=generic||['pharmacy','wholesale','fuel'].includes(actualSector);
    let items=[],options={categories:[],brands:[],units:[]},search='',lowOnly=false,productStatus='active',productPage=1,productMeta={};
    const page=box('المنتجات والباركود · '+actualSectorName);
    hint(page,generic
      ?'تُضاف الأصناف والتصنيفات والعلامات والوحدات إلى كتالوج المنشأة نفسه الذي تقرؤه نقاط البيع. الباركود الأساسي والبديل وحجم العبوة مرتبطان بالمخزون نفسه.'
      :'المنتجات والباركود من نظام القطاع نفسه. لا تخلط مخزون الصيدلية أو الجملة بكتالوج التجزئة.');
    bulkCsvControls(page,'products',()=>refresh(1));
    if(actualSector==='pharmacy'){
      hint(page,'CSV الصيدلية يدير بطاقة الدواء فقط. الدفعات والكميات وتواريخ الصلاحية تبقى في «الدفعات والصلاحية» حتى لا نخلق مخزوناً بلا Batch.');
    }else if(actualSector==='wholesale'){
      hint(page,'CSV الجملة يقبل الرصيد الافتتاحي للصنف فقط. التشغيلات والوحدات التفصيلية تبقى في «الوحدات والتشغيلات» لتظل قابلة للتتبع.');
    }
    const tools=node('div',null,'product-tools'),codeLabel=node('label',null,'field'),code=node('input');
    codeLabel.append(node('span','ابحث بالاسم أو SKU أو الباركود'));
    code.type='search';code.placeholder='امسح الباركود بقارئ USB أو أدخله يدوياً';
    code.setAttribute('aria-label','بحث أو مسح باركود المنتج');code.autocomplete='off';
    codeLabel.append(code);tools.append(codeLabel);
    const searchButton=node('button','بحث','action secondary'),exactButton=node('button','مطابقة الباركود','action secondary'),cameraButton=node('button','فتح كاميرا الباركود','action secondary');
    searchButton.type='button';exactButton.type='button';cameraButton.type='button';
    tools.append(searchButton,exactButton,cameraButton);
    const statusLabel=node('label',null,'field'),statusSelect=node('select');
    statusLabel.append(node('span','حالة الصنف'));
    [['active','النشطة'],['inactive','الموقوفة'],['all','الكل']].forEach(([v,l])=>statusSelect.append(new Option(l,v)));
    statusLabel.append(statusSelect);tools.append(statusLabel);
    const lowCheck=node('label',null,'field'),lowInput=node('input');lowInput.type='checkbox';lowInput.style.width='20px';lowInput.style.minHeight='20px';
    lowCheck.append(lowInput,node('span','المخزون المنخفض فقط'));tools.append(lowCheck);page.append(tools);
    const stats=node('div'),feedback=node('div',null,'product-lookup'),results=node('div'),editor=node('div'),catalogue=node('div');
    page.append(stats,feedback,results,editor,catalogue);
    function blankPanel(text,warning=false){
      feedback.replaceChildren(node('p',text,'note'+(warning?' warning-note':'')));
    }
    function showStockLocations(p){
      feedback.replaceChildren();
      const panel=node('section',null,'panel');
      panel.append(node('h3','المخزون حسب الموقع · '+(p.display_name||p.name||'الصنف')));
      if(p.stock_source==='legacy_unallocated'){
        panel.append(node('p','هذا رصيد تاريخي لم يُوزع على موقع بعد. سيبقى ظاهراً كرصيد انتقالي، لكنه لا يُنسب إلى فرع أو مستودع من دون حركة مخزون موثقة.','note warning-note'));
        const legacy=node('div',null,'grid');
        legacy.append(metric('الموجود',String(p.stock_value??0)),metric('المتاح',String(p.available_stock??p.stock_value??0)));
        panel.append(legacy);
      }else{
        hint(panel,'المتاح = الموجود ناقص المحجوز. هذه الأرقام من product_stocks وليست من مرآة quantity القديمة.');
        table(panel,[
          ['الموقع',x=>x.location],['الموجود',x=>x.on_hand],['المحجوز',x=>x.reserved],
          ['المتاح',x=>x.available],['حد إعادة الطلب',x=>x.reorder_level],
          ['الحالة',x=>x.state==='out'?'نافد للبيع':x.state==='low'?'تحت حد الطلب':'سليم']
        ],p.stock_locations||[]);
        if(Number(p.stock_location_count||0)>(p.stock_locations||[]).length){
          hint(panel,'يُعرض أول 12 موقعاً هنا؛ افتح مركز المخزون للقائمة الكاملة.');
        }
      }
      feedback.append(panel);
      panel.scrollIntoView({behavior:'smooth',block:'nearest'});
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
      active_ingredient:p?.active_ingredient||'',strength:p?.strength||'',
      dosage_form:p?.dosage_form||'',manufacturer:p?.manufacturer||'',
      sale_price:p?.sale_price??'',cost_price:p?.cost_price??'',
      barcode:p?.barcode||'',sku:p?.sku||'',unit:p?.unit||'',
      low_stock_threshold:p?.low_stock_threshold??0,
      requires_prescription:p?.requires_prescription?'1':'0',
      dosage_instructions:p?.dosage_instructions||'',description:p?.description||'',
      is_active:p?.is_active===false?'0':'1',
    }:actualSector==='wholesale'?{
      name:p?.name||'',sku:p?.sku||'',barcode:p?.barcode||'',
      base_price:p?.base_price??'',cost_price:p?.cost_price??'',
      initial_stock:p?null:0,unit:p?.unit||'',low_stock_threshold:p?.low_stock_threshold??0,
    }:{
      name:p?.name||'',product_code:p?.product_code||'',price_per_liter:p?.price_per_liter??'',note:'',
    };
    async function refresh(pageNo=1){
      productPage=pageNo;
      const url=new URL(routes.productsV2,window.location.href);
      if(search)url.searchParams.set('search',search);
      url.searchParams.set('status',productStatus);
      if(lowOnly)url.searchParams.set('low_stock_only','1');
      url.searchParams.set('page',String(productPage));
      const [r,opts]=await Promise.all([
        api('productsV2',undefined,url.toString()),
        generic?api('sectorCatalogOptions').catch(e=>({unavailable:e.message})):Promise.resolve({})
      ]);
      items=r.rows||[];productMeta=r;options=opts||{categories:[],brands:[],units:[]};
      render();
    }
    function render(){
      results.replaceChildren();stats.replaceChildren();
      const s=productMeta.summary||{},pg=productMeta.pagination||{};
      const kpis=node('div',null,'kpi-grid');
      kpis.append(
        kpiCard('إجمالي الأصناف',String(s.total??0),'▤',(s.active??0)+' نشط'),
        kpiCard('أصناف موقوفة',String(s.inactive??0),'○','لا تظهر في نقطة البيع','blue'),
        kpiCard('مخزون منخفض',s.low_stock===null?'غير مطبق':String(s.low_stock),'!','حسب حد التنبيه لكل صنف',Number(s.low_stock||0)>0?'red':''),
        kpiCard('نفد من المخزون',s.out_of_stock===null?'غير مطبق':String(s.out_of_stock),'×','أصناف متتبعة وصلت للصفر',Number(s.out_of_stock||0)>0?'red':'')
      );
      stats.append(kpis);

      const summary=node('p',(pg.total??items.length)+' نتيجة · الصفحة '+(pg.current_page||1)+' من '+(pg.last_page||1)+' · البحث والتصفية من الخادم','muted');
      results.append(summary);
      const wrap=node('div',null,'table-wrap'),tableEl=node('table'),thead=node('thead'),h=node('tr'),body=node('tbody');
      ['المنتج','SKU / الرمز','الباركود','السعر','المتاح / الموجود','الحالة','الإجراءات'].forEach(x=>h.append(node('th',x)));
      thead.append(h);tableEl.append(thead);
      items.forEach(p=>{
        const tr=node('tr'),name=p.display_name||p.trade_name||p.name||'—';
        const low=p.low_stock===true,stock=p.stock_value??p.quantity??p.current_stock;
        const available=p.available_stock??stock;
        const stockText=stock===null||stock===undefined
          ?'غير مطبق'
          :(generic
            ?String(available)+' / '+String(stock)+(p.stock_source==='legacy_unallocated'?' · غير موزع':'')
            :String(stock));
        tr.append(
          node('td',name),
          node('td',p.sku||p.product_code||'—'),
          node('td',p.barcode||'—'),
          node('td',amount(p.price??p.sale_price??p.base_price??p.price_per_liter)),
          node('td',stockText),
          node('td',p.is_active===false?'موقوف':p.out_of_stock===true?'نافد':low?'منخفض':'نشط')
        );
        const td=node('td'),actions=node('div',null,'product-actions');
        if(editable){const edit=node('button',actualSector==='fuel'?'تعديل السعر':'تعديل','action secondary');edit.type='button';edit.onclick=()=>showEditor(p);actions.append(edit)}
        if(['pharmacy','wholesale','fuel'].includes(actualSector)){
          const inventory=node('button',actualSector==='pharmacy'?'الدفعات والصلاحية':actualSector==='wholesale'?'الوحدات والتشغيلات':'سجل السعر','action secondary');
          inventory.type='button';inventory.onclick=()=>showSectorInventory(p);actions.append(inventory);
        }
        if(generic&&!p.is_variant_parent){
          const alias=node('button','باركودات / عبوات','action secondary');alias.type='button';alias.onclick=()=>showAliases(p);actions.append(alias);
        }
        if(generic){
          const stockLocations=node('button','المخزون حسب الموقع','action secondary');
          stockLocations.type='button';stockLocations.onclick=()=>showStockLocations(p);actions.append(stockLocations);
        }
        td.append(actions);tr.append(td);body.append(tr);
      });
      tableEl.append(body);wrap.append(tableEl);results.append(wrap);
      if(items.length===0)results.append(node('p','لا توجد منتجات تطابق البحث أو التصفية الحالية.','note'));

      if((pg.last_page||1)>1){
        const pager=node('div',null,'pager'),info=node('span','صفحة '+pg.current_page+' من '+pg.last_page+' · '+pg.total+' صنف','muted'),actions=node('div',null,'pager-actions');
        const prev=action('السابق',()=>refresh(pg.current_page-1).catch(e=>blankPanel(e.message,true)));
        const next=action('التالي',()=>refresh(pg.current_page+1).catch(e=>blankPanel(e.message,true)));
        prev.disabled=pg.current_page<=1;next.disabled=pg.current_page>=pg.last_page;
        actions.append(prev,next);pager.append(info,actions);results.append(pager);
      }
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
        ['active_ingredient','المادة الفعالة'],['strength','التركيز / القوة'],
        ['dosage_form','الشكل الدوائي'],['manufacturer','الشركة المصنعة'],
        ['sale_price','سعر البيع *','number'],['cost_price','سعر الشراء','number'],
        ['barcode','الباركود'],['sku','رمز SKU'],['unit','وحدة القياس'],
        ['low_stock_threshold','حد تنبيه المخزون','number'],
        ['requires_prescription','يتطلب وصفة','select',[{id:1,name:'نعم'},{id:0,name:'لا'}]],
        ['dosage_instructions','تعليمات الجرعة'],['description','ملاحظات الصنف'],
        ['is_active','حالة الصنف','select',[{id:1,name:'نشط'},{id:0,name:'موقوف'}]],
      ]:actualSector==='wholesale'?[
        ['name','اسم المنتج *'],['base_price','سعر البيع *','number'],
        ['cost_price','سعر الشراء','number'],['sku','رمز SKU'],['barcode','الباركود'],
        ['unit','وحدة القياس'],...(product?[]:[['initial_stock','مخزون البداية','number']]),
        ['low_stock_threshold','حد التنبيه','number'],
      ]:product
        ?[['price_per_liter','سعر اللتر الجديد *','number'],['note','سبب / ملاحظة تغيير السعر']]
        :[['name','اسم الوقود *'],['product_code','رمز المنتج'],['price_per_liter','سعر اللتر *','number']];
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
    async function showSectorInventory(product){
      editor.replaceChildren();
      const panel=node('section',null,'product-editor');
      panel.append(node('h3',
        actualSector==='pharmacy'
          ?'الدفعات والصلاحية · '+(product.trade_name||product.name||'')
          :actualSector==='wholesale'
            ?'الوحدات والتشغيلات · '+(product.name||'')
            :'سجل تغيّر السعر · '+(product.name||'')
      ));
      hint(panel,actualSector==='pharmacy'
        ?'رصيد الدواء لا يُعدّل كرقم حر؛ الدفعة هي مصدر الكمية وتاريخ الصلاحية والتكلفة.'
        :actualSector==='wholesale'
          ?'الاستلام يتم كتَشغيلة قابلة للتتبع، ووحدات البيع تتحول إلى الوحدة الأساسية بعامل صريح.'
          :'تغيير سعر الوقود يمر بمحرك التسعير ويحفظ السعر السابق والجديد والمنفذ.');

      try{
        const data=await api('sectorProductInventory',undefined,routes.sectorProductInventory.replace('__ID__',String(product.id)));
        const r=data.result||{};

        if(actualSector==='pharmacy'){
          const batches=r.batches||[];
          table(panel,[
            ['التشغيلة',x=>x.batch_number],['الصلاحية',x=>x.expiry_date||'—'],
            ['المستلم',x=>x.quantity_received],['المتبقي',x=>x.quantity_remaining],
            ['التكلفة',x=>x.cost_per_unit===null?'—':money(x.cost_per_unit)],
            ['المورد',x=>x.supplier_name||'—'],['الحالة',x=>x.status||'—']
          ],batches);

          const formEl=node('form',null,'editor');
          field(formEl,'batch_number','رقم التشغيلة *');
          field(formEl,'expiry_date','تاريخ الصلاحية *','date');
          field(formEl,'manufactured_at','تاريخ الإنتاج','date');
          field(formEl,'quantity_received','الكمية المستلمة *','number');
          field(formEl,'cost_per_unit','تكلفة الوحدة','number');
          field(formEl,'supplier_name','اسم المورد');
          field(formEl,'supplier_invoice','رقم فاتورة المورد');
          const save=node('button','استلام الدفعة','action');save.type='submit';formEl.append(save);
          formEl.addEventListener('submit',async ev=>{
            ev.preventDefault();save.disabled=true;
            try{
              const payload=Object.fromEntries(new FormData(formEl).entries());
              Object.keys(payload).forEach(k=>{if(payload[k]==='')delete payload[k]});
              await api('sectorProductInventoryReceive',payload,routes.sectorProductInventoryReceive.replace('__ID__',String(product.id)));
              message('تم استلام الدفعة وتحديث مخزون الدواء');
              await refresh(productPage);await showSectorInventory(product);
            }catch(e){blankPanel(e.message,true)}finally{save.disabled=false}
          });
          panel.append(node('h3','استلام دفعة جديدة'),formEl);
        }else if(actualSector==='wholesale'){
          const units=r.units||[],lots=r.lots||[];
          panel.append(node('h3','وحدات البيع والتحويل'));
          table(panel,[
            ['الرمز',x=>x.code],['الوحدة',x=>x.name],
            ['عامل التحويل',x=>x.factor_to_base],['الأساسية',x=>x.is_base?'نعم':'لا']
          ],units);
          const uf=node('form',null,'editor');
          field(uf,'code','رمز الوحدة *');field(uf,'name','اسم الوحدة *');
          field(uf,'factor_to_base','كم تساوي من الوحدة الأساسية *','number');
          field(uf,'is_base','هل هي الوحدة الأساسية','select','0',[{id:0,name:'لا'},{id:1,name:'نعم'}]);
          const us=node('button','حفظ الوحدة','action secondary');us.type='submit';uf.append(us);
          uf.addEventListener('submit',async ev=>{
            ev.preventDefault();us.disabled=true;
            try{
              const payload=Object.fromEntries(new FormData(uf).entries());
              await api('sectorProductUnitSave',payload,routes.sectorProductUnitSave.replace('__ID__',String(product.id)));
              message('تم حفظ وحدة التحويل');await showSectorInventory(product);
            }catch(e){blankPanel(e.message,true)}finally{us.disabled=false}
          });
          panel.append(uf,node('h3','التشغيلات / Lots'));
          table(panel,[
            ['رقم التشغيلة',x=>x.lot_number],['الموقع',x=>x.location||'—'],
            ['المتبقي',x=>x.quantity_remaining],['الصلاحية',x=>x.expiry_date||'—'],
            ['تكلفة الوحدة',x=>x.cost_per_unit===null?'—':money(x.cost_per_unit)],
            ['مرجع المورد',x=>x.supplier_reference||'—']
          ],lots);
          const lf=node('form',null,'editor');
          field(lf,'lot_number','رقم التشغيلة *');field(lf,'quantity','الكمية المستلمة *','number');
          field(lf,'unit_id','الوحدة','select','',units.map(u=>({id:u.id,name:u.name})));
          field(lf,'location','موقع التخزين');field(lf,'received_at','تاريخ الاستلام','date');
          field(lf,'expiry_date','تاريخ الصلاحية','date');field(lf,'cost_per_unit','تكلفة الوحدة','number');
          field(lf,'supplier_reference','مرجع المورد');
          const ls=node('button','استلام التشغيلة','action');ls.type='submit';lf.append(ls);
          lf.addEventListener('submit',async ev=>{
            ev.preventDefault();ls.disabled=true;
            try{
              const payload=Object.fromEntries(new FormData(lf).entries());
              Object.keys(payload).forEach(k=>{if(payload[k]==='')delete payload[k]});
              await api('sectorProductInventoryReceive',payload,routes.sectorProductInventoryReceive.replace('__ID__',String(product.id)));
              message('تم استلام التشغيلة وتحديث مخزون الجملة');
              await refresh(productPage);await showSectorInventory(product);
            }catch(e){blankPanel(e.message,true)}finally{ls.disabled=false}
          });
          panel.append(lf);
        }else if(actualSector==='fuel'){
          const history=(r.history||[]).filter(x=>!x.product||x.product===product.name);
          table(panel,[
            ['التاريخ',x=>x.created_at?new Date(x.created_at).toLocaleString('ar-YE'):'—'],
            ['السعر السابق',x=>money(x.old_price)],['السعر الجديد',x=>money(x.new_price)],
            ['الفرق',x=>money(x.delta)],['بواسطة',x=>x.changed_by||'—'],['الملاحظة',x=>x.note||'—']
          ],history);
          if(!history.length)hint(panel,'لا توجد تغييرات سعر مسجلة لهذا النوع حتى الآن.');
        }
      }catch(e){
        hint(panel,e.message||'تعذّر تحميل تفاصيل المخزون.');
      }

      const close=node('button','إغلاق','action secondary');close.type='button';close.onclick=()=>editor.replaceChildren();panel.append(close);
      editor.append(panel);editor.scrollIntoView({behavior:'smooth',block:'start'});
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
    searchButton.onclick=()=>{search=code.value.trim();refresh(1).catch(e=>blankPanel(e.message,true))};
    code.addEventListener('keydown',ev=>{if(ev.key==='Enter'){ev.preventDefault();search=code.value.trim();refresh(1).catch(e=>blankPanel(e.message,true))}});
    lowInput.addEventListener('change',()=>{lowOnly=lowInput.checked;refresh(1).catch(e=>blankPanel(e.message,true))});
    statusSelect.addEventListener('change',()=>{productStatus=statusSelect.value;refresh(1).catch(e=>blankPanel(e.message,true))});
    async function lookup(){
      const barcode=code.value.trim();
      if(!barcode){blankPanel('أدخل رقم الباركود أو امسحه في الحقل.');return}
      const url=new URL(routes.sectorBarcodeLookup,window.location.href);
      url.searchParams.set('barcode',barcode);
      try{
        const hit=await api('sectorBarcodeLookup',undefined,url.toString());
        const p=hit.product||{};
        search=barcode;code.value=barcode;await refresh(1);
        blankPanel('الصنف: '+(p.trade_name||p.name||'—')+' · رمز: '+barcode+' · حجم العبوة: '+(hit.pack_size||1));
      }catch(e){
        blankPanel('لا يوجد صنف بهذا الرمز في كتالوج منشأتك. '+(editable?'يمكنك إضافته أدناه.':'')+' '+e.message,true);
        if(!items.some(p=>p.barcode===barcode)&&generic)showEditor(null,barcode);
      }
    }
    exactButton.onclick=()=>lookup().catch(e=>blankPanel(e.message,true));
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
        cards=[['مبيعات اليوم',money(d.total_all)],['عدد البيعات',d.sales_count],['نقد',money(d.by_method?.cash||0)],['أميال باي',money(d.by_method?.amial_pay||0)]];break;
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
    }else if(actualSector==='quick_sale'){
      hint(p,'هذا القطاع لا يملك كتالوجاً أو مخزوناً: نقطة البيع تدخل المبلغ ثم تحصّل نقداً أو عبر أميال باي وتصدر الفاتورة.');
      table(p,[['طريقة الدفع',x=>x.label],['مبيعات اليوم',x=>money(x.amount)]],[
        {label:'نقد',amount:o.by_method?.cash||0},
        {label:'أميال باي',amount:o.by_method?.amial_pay||0}
      ]);
      workspaceActions(p,['sales','returns','reports','wallet','documents']);
    }else{
      hint(p,'لا توجد وحدة تشغيل إضافية لهذا القطاع.');
    }
    hint(p,'هذه البيانات من وحدة قطاع منشأتك المسجّل؛ القطاعات الأخرى لا تمنح وصولًا إلى أعمالها.');
  }
  function isoDate(d){return d.toISOString().slice(0,10)}
  function defaultSalesFilters(){
    const to=new Date(),from=new Date();from.setDate(from.getDate()-29);
    return {from:isoDate(from),to:isoDate(to),payment_method:'',status:'',employee_id:'',search:''};
  }
  async function sales(filters=null,page=1){
    const state=(navigation.find(item=>item.tab==='sales')||{}).state;
    if(state&&state!=='available'){
      const p=box('سجل '+actualSectorName);hint(p,navigationNote(state));return;
    }

    filters=filters||defaultSalesFilters();
    const url=new URL(routes.salesV2,window.location.href);
    Object.entries(filters).forEach(([k,v])=>{if(v!==''&&v!==null&&v!==undefined)url.searchParams.set(k,v)});
    url.searchParams.set('page',String(page));

    const staffPromise=navigation.some(item=>item.tab==='staff'&&item.state==='available')
      ? api('staff').catch(()=>({staff:[]})):Promise.resolve({staff:[]});
    const [data,staffData]=await Promise.all([api('salesV2',undefined,url.toString()),staffPromise]);
    const rows=data.rows||[],summary=data.summary||{},pagination=data.pagination||{},staff=staffData.staff||[];
    const avg=Number(summary.count||0)>0?Number(summary.total||0)/Number(summary.count):0;

    dashboardKpis([
      ['إجمالي الفترة',money(summary.total||0),'↗',(summary.count||0)+' عملية'],
      ['عدد المبيعات',String(summary.count||0),'▧','حسب الفلاتر الحالية','blue'],
      ['متوسط الفاتورة',money(avg),'◇','إجمالي الفترة ÷ عدد العمليات'],
      ['مصدر السجل',data.source||'—','◎','مصدر قطاع المنشأة الحقيقي','gold'],
    ]);

    const p=box('سجل '+actualSectorName);
    hint(p,'الفلاتر تعمل على الخادم قبل الترقيم. نتائج الوقود والصيدلية والجملة لا تُقرأ من merchant_sales، وتفاصيل العملية تظل من محرك قطاعها الأصلي.');

    const ff=node('form',null,'filter-bar');
    const makeField=(label,name,type='text',options=null)=>{
      const holder=node('label',label,'field'),input=options?node('select'):node('input');
      input.name=name;
      if(options){options.forEach(o=>{const op=node('option',o.label);op.value=o.value;input.append(op)})}
      else input.type=type;
      input.value=filters[name]??'';holder.append(input);return [holder,input]
    };
    const [fromL,from]=makeField('من','from','date'),[toL,to]=makeField('إلى','to','date');
    const methods=actualSector==='quick_sale'
      ?[
        {value:'',label:'كل طرق الدفع'},{value:'cash',label:'نقد'},
        {value:'amial_pay',label:'أميال باي'}
      ]
      :[
        {value:'',label:'كل طرق الدفع'},{value:'cash',label:'نقد'},
        {value:'amial_pay',label:'أميال باي'},{value:'credit',label:'آجل'},
        {value:'mixed',label:'مختلط'},{value:'company_card',label:'حساب شركة'},
        {value:'corporate',label:'حساب مؤسسي'}
      ];
    const [methodL,method]=makeField('طريقة الدفع','payment_method','select',methods);
    const statusOptions=actualSector==='quick_sale'
      ?[
        {value:'',label:'كل الحالات'},{value:'completed',label:'مكتملة'}
      ]
      :[
        {value:'',label:'كل الحالات'},{value:'completed',label:'مكتملة'},
        {value:'credit_unpaid',label:'آجل غير مسدد'},{value:'credit_paid',label:'آجل مسدد'},
        {value:'issued',label:'صادرة'},{value:'partial_paid',label:'مسددة جزئياً'},
        {value:'paid',label:'مسددة'}
      ];
    const [statusL,status]=makeField('الحالة','status','select',statusOptions);
    const employeeOptions=[{value:'',label:'كل الموظفين'},...staff.map(x=>({value:x.id,label:x.display_name+' — '+x.employee_code}))];
    const [employeeL,employee]=makeField('الموظف','employee_id','select',employeeOptions);
    const [searchL,search]=makeField('بحث','search','search');search.placeholder='فاتورة، مرجع أو عميل';
    const actions=node('div',null,'filter-actions'),apply=node('button','تطبيق','action'),reset=node('button','آخر 30 يوماً','action secondary');
    apply.type='submit';reset.type='button';reset.onclick=()=>{content.replaceChildren();sales(defaultSalesFilters(),1).catch(e=>message(e.message))};
    actions.append(apply,reset);ff.append(fromL,toL,methodL,statusL,employeeL,searchL,actions);p.append(ff);
    ff.addEventListener('submit',ev=>{
      ev.preventDefault();
      const next=Object.fromEntries(new FormData(ff).entries());
      content.replaceChildren();sales(next,1).catch(e=>{content.replaceChildren(node('div',e.message,'error'))});
    });

    table(p,[
      ['الوقت',x=>x.occurred_at?new Date(x.occurred_at).toLocaleString('ar-YE'):'—'],
      ['الفاتورة',x=>x.document_number||x.reference],
      ['العميل',x=>x.customer_name||'—'],
      ['الموظف',x=>x.employee_name||'المالك / غير منسوب'],
      ['الفرع',x=>x.branch_name||'—'],
      ['طريقة الدفع',x=>paymentLabel(x.payment_method)],
      ['الحالة',x=>saleStatusLabel(x.status)],
      ['الإجمالي',x=>money(x.amount)],
      ['المرتجع',x=>x.refunded_total===null||x.refunded_total===undefined?'غير متاح':money(x.refunded_total)],
      ['التفاصيل',x=>saleDetailButton({...x,id:x.detail_id, sale_ulid:actualSector==='wholesale'||actualSector==='restaurant'?undefined:x.detail_id})]
    ],rows);
    if(!rows.length)p.append(node('div','لا توجد مبيعات مطابقة للفلاتر الحالية.','dashboard-empty'));

    const pager=node('div',null,'pager'),info=node('span','صفحة '+(pagination.current_page||1)+' من '+(pagination.last_page||1)+' · '+(pagination.total||0)+' سجل','muted'),pa=node('div',null,'pager-actions');
    const prev=action('السابق',()=>{if((pagination.current_page||1)>1){content.replaceChildren();sales(filters,(pagination.current_page||1)-1)}});
    const next=action('التالي',()=>{if((pagination.current_page||1)<(pagination.last_page||1)){content.replaceChildren();sales(filters,(pagination.current_page||1)+1)}});
    prev.disabled=(pagination.current_page||1)<=1;next.disabled=(pagination.current_page||1)>=(pagination.last_page||1);pa.append(prev,next);pager.append(info,pa);p.append(pager);
  }
  function saleIdentifier(row){return String((actualSector==='wholesale'||actualSector==='restaurant'?row.id:(row.sale_ulid||row.ulid||row.id))||'')}
  function saleDetailButton(row){const id=saleIdentifier(row),button=node('button','عرض','action secondary');button.type='button';button.disabled=!id;button.addEventListener('click',()=>showSaleDetail(id));return button}
  function invoiceUrl(id,inline=false){
    const raw=routes.sectorSaleInvoice.replace('__ID__',encodeURIComponent(id));
    if(!inline)return raw;
    const url=new URL(raw,window.location.href);url.searchParams.set('inline','1');return url.toString();
  }
  function invoiceActions(id,record={}){
    if(!['quick_sale','retail','pharmacy','fuel','wholesale','restaurant'].includes(actualSector))return null;
    const actions=node('div',null,'buttons');

    const preview=node('button','معاينة / طباعة','action secondary');preview.type='button';
    preview.onclick=()=>window.open(invoiceUrl(id,true),'_blank','noopener');

    const download=node('button','تنزيل PDF','action secondary');download.type='button';
    download.onclick=()=>window.open(invoiceUrl(id,false),'_blank','noopener');

    const share=node('button','مشاركة الفاتورة','action secondary');share.type='button';
    share.onclick=async()=>{
      share.disabled=true;
      try{
        const response=await fetch(invoiceUrl(id,false),{credentials:'same-origin',headers:{Accept:'application/pdf'}});
        if(!response.ok)throw Error('تعذّر تجهيز ملف الفاتورة للمشاركة');
        const blob=await response.blob();
        const number=record.invoice_number||record.order_number||record.sale_ulid||id;
        const file=new File([blob],'amial_invoice_'+String(number).replace(/[^A-Za-z0-9_-]+/g,'_')+'.pdf',{type:'application/pdf'});
        if(navigator.share&&(!navigator.canShare||navigator.canShare({files:[file]}))){
          await navigator.share({title:'فاتورة أميال باي '+number,text:'فاتورة من '+@json($storeName),files:[file]});
        }else{
          const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=file.name;a.click();
          setTimeout(()=>URL.revokeObjectURL(a.href),1000);
          message('المتصفح لا يدعم المشاركة المباشرة؛ تم تنزيل PDF لتشاركه عبر واتساب أو أي تطبيق.');
        }
      }catch(e){
        if(e?.name!=='AbortError')message(e.message||'تعذّرت مشاركة الفاتورة');
      }finally{share.disabled=false}
    };
    actions.append(preview,download,share);return actions
  }
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
    const docs=invoiceActions(id,record);if(docs)p.append(docs);
    if(navigation.some(x=>x.tab==='returns'&&x.state==='available')){
      const returnActions=node('div',null,'buttons');
      const startReturn=action('بدء مرتجع / استرداد',()=>showReturnEditor(id,record));
      returnActions.append(startReturn);p.append(returnActions);
    }
    hint(p,'«معاينة / طباعة» يفتح PDF الرسمي في عارض المتصفح. الطباعة الحرارية المباشرة عبر Bluetooth/USB تبقى من تطبيق نقطة البيع لأنها تعتمد قدرات الجهاز الفعلية.');
  }
  function returnRoute(key,id){return routes[key].replace('__ID__',encodeURIComponent(String(id)))}

  async function showReturnEditor(id,record={}){
    const data=await api('sectorReturnInfo',undefined,returnRoute('sectorReturnInfo',id));
    const panel=box('مرتجع / استرداد · '+(record.invoice_number||record.order_number||record.sale_ulid||id));

    if(actualSector==='wholesale'){
      const invoice=data.invoice||{},lines=invoice.items||[],previous=data.returns||[];
      hint(panel,'مرتجع الجملة = طلب ثم مراجعة. عند الاعتماد يعود المخزون ويُنشأ إشعار دائن. إن كانت الفاتورة مدفوعة، يظهر أي مبلغ مستحق للرد صراحةً ولا يُعتبر مدفوعاً حتى يوجد صرف حقيقي.');
      if(previous.length)table(panel,[
        ['المرجع',x=>x.return_ulid],['الحالة',x=>returnStatusLabel(x.status)],
        ['الإجمالي',x=>money(x.total_amount)],['خُصم من الذمة',x=>money(x.credited_amount||0)],
        ['مستحق رد مالي',x=>money(x.refund_due_amount||0)]
      ],previous);

      if(!lines.length){hint(panel,'لا تعلن الفاتورة أسطرًا قابلة للمرتجع.');return}
      const frm=node('form'),list=node('div',null,'permission-groups'),inputs=[];
      lines.forEach(line=>{
        const row=node('div',null,'permission-option'),check=node('input');check.type='checkbox';
        const qty=node('input');qty.type='number';qty.step='0.001';qty.min='0.001';qty.max=String(line.quantity||0);qty.value=String(line.quantity||0);qty.disabled=true;
        check.onchange=()=>qty.disabled=!check.checked;
        const copy=node('span',(line.product_name||line.name||'صنف')+' · المباع '+(line.quantity||0)+' · '+money(line.line_total||0));
        row.append(check,copy,qty);list.append(row);inputs.push({line,check,qty});
      });
      const reasonLabel=node('label','سبب المرتجع','field'),reason=node('input');reason.required=true;reason.maxLength=500;reasonLabel.append(reason);
      const save=action('إرسال طلب المرتجع');save.type='submit';frm.append(list,reasonLabel,save);panel.append(frm);
      frm.onsubmit=async ev=>{
        ev.preventDefault();
        const items=inputs.filter(x=>x.check.checked).map(x=>({invoice_item_id:x.line.id,quantity:x.qty.value}));
        if(!items.length){message('اختر صنفاً واحداً على الأقل');return}
        save.disabled=true;
        try{
          await api('sectorReturnCreate',{reason:reason.value.trim(),items},returnRoute('sectorReturnCreate',id));
          message('تم إرسال طلب المرتجع للمراجعة');await load('returns');
        }catch(e){message(e.message)}finally{save.disabled=false}
      };
      return;
    }

    const sale=data.sale||{},lines=data.lines||[],remaining=Number(data.remaining||0),methods=data.available_methods||[];
    if(data.fully_refunded||remaining<=0){hint(panel,'تم استرداد كامل قيمة هذه العملية ولا يوجد مبلغ متبقٍ للاسترداد.');return}

    hint(panel,'«مرتجع أصناف» يربط حركة المال بالمخزون ويمنع إرجاع كمية أكثر مما بيع. «استرداد مالي فقط» لا يغيّر المخزون ويستخدم للحالات التي لا تعود فيها بضاعة.');
    const frm=node('form'),modeLabel=node('label','نوع العملية','field'),mode=node('select');
    mode.append(new Option('مرتجع أصناف + استرداد مالي','goods'),new Option('استرداد مالي فقط','money'));modeLabel.append(mode);
    const methodLabel=node('label','طريقة رد المال','field'),method=node('select');
    methods.forEach(m=>method.append(new Option(refundMethodLabel(m),m)));methodLabel.append(method);
    const amountLabel=node('label','مبلغ الاسترداد','field'),amount=node('input');amount.type='number';amount.step='0.01';amount.min='0.01';amount.max=String(remaining);amountLabel.append(amount);
    const reasonLabel=node('label','سبب الاسترداد','field'),reason=node('input');reason.maxLength=500;reasonLabel.append(reason);
    frm.append(modeLabel,methodLabel,amountLabel,reasonLabel);

    const lineBox=node('div',null,'permission-groups'),entries=[];
    lines.forEach(line=>{
      const available=Number(line.refundable_quantity||0);
      if(available<=0)return;
      const row=node('div',null,'permission-option'),check=node('input');check.type='checkbox';
      const qty=node('input');qty.type='number';qty.step='0.001';qty.min='0.001';qty.max=String(available);qty.value=String(available);qty.disabled=true;
      const condition=node('select');
      [['good','سليم — يعود للمخزون'],['damaged','تالف — لا يعود للمخزون'],['expired','منتهي — لا يعود للمخزون']].forEach(([v,l])=>condition.append(new Option(l,v)));
      condition.disabled=true;
      check.onchange=()=>{qty.disabled=!check.checked;condition.disabled=!check.checked;recalc()};
      qty.oninput=recalc;condition.onchange=recalc;
      const copy=node('span',(line.name||'صنف')+' · المتاح '+line.refundable_quantity+' × '+money(line.unit_price));
      row.append(check,copy,qty,condition);lineBox.append(row);entries.push({line,check,qty,condition});
    });
    frm.append(lineBox);

    function recalc(){
      const goods=mode.value==='goods';
      lineBox.style.display=goods?'grid':'none';
      amount.readOnly=goods;
      if(goods){
        const total=entries.filter(x=>x.check.checked).reduce((s,x)=>s+Number(x.qty.value||0)*Number(x.line.unit_price||0),0);
        amount.value=total>0?total.toFixed(2):'';
      }
    }
    mode.onchange=recalc;recalc();

    if(methods.includes('cash')){
      hint(panel,'الاسترداد النقدي يخرج من درج وردية مفتوحة ويُسجل كحركة نقد خارجة؛ لا يخصم من محفظة أميال.');
    }
    const save=action('تنفيذ الاسترداد');save.type='submit';frm.append(save);panel.append(frm);
    frm.onsubmit=async ev=>{
      ev.preventDefault();
      const value=Number(amount.value||0);
      if(value<=0||value>remaining){message('مبلغ الاسترداد غير صحيح أو يتجاوز المتبقي');return}
      const payload={amount:amount.value,refund_method:method.value};
      if(reason.value.trim())payload.reason=reason.value.trim();
      if(mode.value==='goods'){
        const selected=entries.filter(x=>x.check.checked);
        if(!selected.length){message('اختر صنفاً واحداً على الأقل');return}
        payload.items=selected.map(x=>({
          sale_item_id:x.line.id,quantity:x.qty.value,condition:x.condition.value,
          restock:x.condition.value==='good'
        }));
      }
      save.disabled=true;
      try{
        const result=await api('sectorReturnCreate',payload,returnRoute('sectorReturnCreate',id));
        if(result.approval)message('تم إرسال طلب الاعتماد إلى الجهة المخولة؛ لا يتحرك المال حتى الموافقة.');
        else message('تم تسجيل الاسترداد وربط أثره المالي والتشغيلي.');
        await load('returns');
      }catch(e){message(e.message)}finally{save.disabled=false}
    };
  }

  async function returns(){
    const [data,ops]=await Promise.all([
      api('sectorReturns'),
      actualSector==='wholesale'?api('overview').catch(()=>({open_shifts:[]})):Promise.resolve({open_shifts:[]})
    ]);
    const rows=data.refunds||data.returns||[];

    if(actualSector==='wholesale'){
      const approved=rows.filter(x=>x.status==='approved');
      const paidFor=x=>Number(x.refund_paid_amount??(x.settlements||[]).reduce((s,m)=>s+Number(m.amount||0),0));
      const remainingFor=x=>Math.max(0,Number(x.refund_due_amount||0)-paidFor(x));
      const due=approved.reduce((s,x)=>s+remainingFor(x),0);
      const paid=approved.reduce((s,x)=>s+paidFor(x),0);
      const credited=approved.reduce((s,x)=>s+Number(x.credited_amount||0),0);
      const openShifts=ops.open_shifts||[];
      dashboardKpis([
        ['طلبات المرتجع',String(rows.length),'↶',rows.filter(x=>x.status==='requested').length+' بانتظار المراجعة'],
        ['مرتجعات معتمدة',String(approved.length),'✓','أعيد مخزونها واعتمد إشعارها','blue'],
        ['خُصم من الذمم',money(credited),'◫','إشعارات دائنة حقيقية'],
        ['تم رده فعلياً',money(paid),'✓','نقد من وردية أو أميال من المحفظة','gold'],
        ['متبقي للعملاء',money(due),'!','التزام لم يتحرك منه المال بعد',due>0?'red':'']
      ]);
      const p=box('مرتجعات الجملة');
      hint(p,'الآن يوجد فصل كامل بين «المستحق» و«المدفوع»: اعتماد المرتجع يعيد المخزون ويخفض الذمة، ثم صرف المستحق وحده هو الذي يخرج نقداً من وردية محددة أو أميال من محفظة المنشأة.');
      table(p,[
        ['المرجع',x=>x.return_ulid],['الفاتورة',x=>x.invoice?.invoice_number||x.invoice_id],
        ['العميل',x=>x.customer?.full_name||'—'],['الإجمالي',x=>money(x.total_amount)],
        ['الحالة',x=>returnStatusLabel(x.status)],['خُصم من الذمة',x=>money(x.credited_amount||0)],
        ['أصل المستحق',x=>money(x.refund_due_amount||0)],
        ['المدفوع',x=>money(paidFor(x))],
        ['المتبقي',x=>money(remainingFor(x))],
        ['التسوية',x=>returnSettlementLabel(x.settlement_type)],
        ['الإجراء',x=>{
          if(x.status==='requested')return buttons([
            action('اعتماد',()=>resolveWholesaleReturn(x.id,true)),
            action('رفض',()=>resolveWholesaleReturn(x.id,false))
          ]);
          if(x.status==='approved'&&remainingFor(x)>0){
            return action('صرف المستحق',()=>settleWholesaleReturn(x,remainingFor(x),openShifts),false)
          }
          return remainingFor(x)<=0&&Number(x.refund_due_amount||0)>0?'مكتمل':'—'
        }]
      ],rows);
      return;
    }

    const completed=rows.filter(x=>x.status==='completed'),pending=rows.filter(x=>x.status==='pending_approval');
    const total=completed.reduce((s,x)=>s+Number(x.refund_amount||0),0);
    dashboardKpis([
      ['المرتجعات',String(rows.length),'↶',completed.length+' مكتمل'],
      ['المبلغ المسترد',money(total),'◇','فقط المرتجعات المكتملة','gold'],
      ['بانتظار اعتماد',String(pending.length),'!','لا يتحرك المال قبل الاعتماد',pending.length?'red':''],
      ['آخر سجل',rows[0]?.created_at?new Date(rows[0].created_at).toLocaleDateString('ar-YE'):'—','▧','مرتبط بالفاتورة الأصلية']
    ]);
    const p=box('سجل المرتجعات والاسترداد');
    hint(p,'النقد يُسجل في عهدة الوردية، المحفظة تمر بقيد متوازن، والآجل يخفض ذمة العميل. لا يوجد تعديل يدوي لرصيد التاجر.');
    table(p,[
      ['التاريخ',x=>x.created_at?new Date(x.created_at).toLocaleString('ar-YE'):'—'],
      ['مرجع المرتجع',x=>x.refund_ulid],['البيع الأصلي',x=>x.original_sale_ulid],
      ['العميل',x=>x.customer_name||x.customer_phone||'—'],
      ['الطريقة',x=>refundMethodLabel(x.refund_method)],['المبلغ',x=>money(x.refund_amount)],
      ['الحالة',x=>returnStatusLabel(x.status)],['السبب',x=>x.reason||'—']
    ],rows);
  }

  async function settleWholesaleReturn(row,remaining,openShifts){
    document.getElementById('wholesale-return-settlement')?.remove();
    const p=box('صرف مستحق المرتجع · '+row.return_ulid);p.id='wholesale-return-settlement';
    hint(p,'لن تُغلق هذه الذمة بمجرد حفظ نموذج: النقد يجب أن يخرج من وردية مفتوحة، وأميال يجب أن ينجح قيدها المتوازن من محفظة المنشأة إلى حساب العميل.');

    const history=row.settlements||[];
    if(history.length){
      table(p,[['التاريخ',x=>x.created_at?new Date(x.created_at).toLocaleString('ar-YE'):'—'],
        ['الطريقة',x=>x.method==='cash'?'نقد':'أميال باي'],['المبلغ',x=>money(x.amount)],
        ['المرجع',x=>x.reference||x.settlement_ulid],['ملاحظة',x=>x.note||'—']],history);
    }

    const frm=node('form',null,'editor');
    const amountLabel=node('label','المبلغ — المتبقي '+money(remaining),'field'),amount=node('input');
    amount.type='number';amount.step='0.01';amount.min='0.01';amount.max=String(remaining);amount.value=String(remaining);amount.required=true;amountLabel.append(amount);

    const invoiceBranchId=row.invoice?.branch_id??null;
    const eligibleShifts=openShifts.filter(s=>String(s.branch_id??'')===String(invoiceBranchId??''));

    const methodLabel=node('label','طريقة الصرف','field'),method=node('select');
    const cashOption=new Option('نقد من درج وردية POS','cash');
    cashOption.disabled=!eligibleShifts.length;
    method.append(cashOption,new Option('إلى محفظة العميل في أميال','amial_pay'));
    if(!eligibleShifts.length)method.value='amial_pay';
    methodLabel.append(method);

    const shiftLabel=node('label','الوردية التي سيخرج منها النقد','field'),shift=node('select');
    shift.append(new Option(eligibleShifts.length?'اختر وردية فرع الفاتورة':'لا توجد وردية مفتوحة في موقع الفاتورة',''));
    eligibleShifts.forEach(s=>shift.append(new Option((s.opened_by_name||'وردية')+(s.branch_name?' · '+s.branch_name:' · المنشأة الرئيسية')+' — #'+s.id,String(s.id))));
    shiftLabel.append(shift);
    if(!eligibleShifts.length){
      const guardNote=node('p','الصرف النقدي معطّل حتى تُفتح وردية في نفس موقع الفاتورة الأصلية. يمكنك رد المبلغ إلى محفظة العميل إذا كان حسابه مرتبطاً.','note');
      frm.append(guardNote);
    }

    const refLabel=node('label','مرجع خارجي / سند (اختياري)','field'),reference=node('input');reference.maxLength=100;refLabel.append(reference);
    const noteLabel=node('label','ملاحظة الصرف','field'),note=node('input');note.maxLength=500;noteLabel.append(note);
    const save=action('تنفيذ صرف المستحق',()=>{},false);save.type='submit';
    const cancel=action('إلغاء',()=>p.remove());
    frm.append(amountLabel,methodLabel,shiftLabel,refLabel,noteLabel,buttons([save,cancel]));p.append(frm);

    const syncShift=()=>{
      shiftLabel.style.display=method.value==='cash'?'grid':'none';
      shift.required=method.value==='cash';
    };method.onchange=syncShift;syncShift();

    frm.onsubmit=async ev=>{
      ev.preventDefault();
      const value=Number(amount.value||0);
      if(!(value>0)||value>remaining){message('مبلغ الصرف يجب أن يكون موجباً ولا يتجاوز المتبقي');return}
      if(method.value==='cash'&&!shift.value){message('اختر الوردية التي سيخرج منها النقد');return}
      const channel=method.value==='cash'?'درج الوردية #'+shift.value:'محفظة العميل في أميال';
      if(!await amialConfirm('تأكيد الصرف','سيتم صرف '+money(value)+' عبر '+channel+'. هذا إجراء مالي حقيقي.',{confirmLabel:'تنفيذ الصرف',danger:true}))return;

      save.disabled=true;
      try{
        const idem='mw-wret-'+row.id+'-'+Date.now()+'-'+Math.random().toString(36).slice(2);
        const result=await api('sectorReturnSettle',{
          amount:amount.value,
          method:method.value,
          cashier_shift_id:method.value==='cash'?Number(shift.value):null,
          reference:reference.value.trim()||null,
          note:note.value.trim()||null,
          idempotency_key:idem
        },returnRoute('sectorReturnSettle',row.id),undefined,{'Idempotency-Key':idem});
        message('تم الصرف فعلياً. المتبقي '+money(result.refund_remaining_amount||0));
        await load('returns');
      }catch(e){message(e.message);save.disabled=false}
    };
    p.scrollIntoView({behavior:'smooth',block:'start'});
  }

  async function resolveWholesaleReturn(id,approve){
    const note=await amialPrompt(approve?'اعتماد المرتجع':'رفض المرتجع',approve?'ملاحظة الاعتماد (اختيارية)':'سبب الرفض',{multiline:true,required:!approve,minLength:approve?0:3,confirmLabel:approve?'اعتماد':'رفض'});
    if(note===null)return;
    try{
      await api('sectorReturnResolve',{approve:Boolean(approve),decision_note:note||null},returnRoute('sectorReturnResolve',id));
      message(approve?'تم اعتماد المرتجع وتحديث المخزون والذمة.':'تم رفض طلب المرتجع.');
      await load('returns');
    }catch(e){message(e.message)}
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
    const usageRows=[
      ['الباقة',current.name],
      ['السعر الشهري',(current.price_monthly??0)+' ر.س'],
      ['انتهاء الاشتراك',current.expires_at?new Date(current.expires_at).toLocaleDateString('ar-YE'):'دون موعد انتهاء'],
      ['عمليات الشهر',(usage.monthly_operations?.current??0)+' / '+limitText(usage.monthly_operations?.max)]
    ];
    if(actualSector!=='quick_sale')usageRows.push(['الأصناف',(usage.products?.current??0)+' / '+limitText(usage.products?.max)]);
    usageRows.push(['الموظفون',(usage.employees?.current??0)+' / '+limitText(usage.employees?.max)]);
    usageRows.forEach(x=>labelValue(body,x[0],x[1]));
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
  async function branches(){
    const [data,staffData,devicesData]=await Promise.all([api('branches'),api('staff').catch(()=>({staff:[]})),api('devices').catch(()=>({devices:[]}))]);
    const rows=data.branches||[],staff=staffData.staff||[],devices=devicesData.devices||[],active=rows.filter(x=>x.is_active).length;
    dashboardKpis([
      ['الفروع',String(rows.length),'⌘',active+' نشط'],
      ['الموظفون المرتبطون',String(staff.filter(x=>x.branch_id).length),'♙','موظفون بنطاق فرع','blue'],
      ['الأجهزة المرتبطة',String(devices.filter(x=>x.branch_id).length),'▣','أجهزة مسندة لفروع','gold'],
      ['المدن',String(new Set(rows.map(x=>x.city).filter(Boolean)).size),'◇','تغطية الفروع الحالية']
    ]);
    const p=box('الفروع التابعة لمنشأتك');
    hint(p,'الفرع نطاق تشغيلي للموظف والجهاز والتقارير؛ لا ينشئ محفظة مالية مستقلة ولا ينقل الأموال تلقائياً.');
    table(p,[
      ['اسم الفرع',x=>x.name],['المدينة',x=>x.city||'—'],['العنوان',x=>x.address||'—'],
      ['الموظفون',x=>staff.filter(s=>String(s.branch_id||'')===String(x.id)).length],
      ['الأجهزة',x=>devices.filter(d=>String(d.branch_id||'')===String(x.id)).length],
      ['الحالة',x=>{const s=node('span',x.is_active?'نشط':'متوقف','staff-status '+(x.is_active?'on':'off'));return s}]
    ],rows);
    const create=box('إضافة فرع');
    hint(create,'بعد الإنشاء اربط الموظف والجهاز بالفرع من «إعداد نقطة بيع».');
    form(create,[['name','اسم الفرع'],['address','العنوان'],['city','المدينة']],'إنشاء الفرع',d=>api('branchesCreate',d));
  }
  async function posSetup(){
    const [branchesData,staffData,devicesData,rolesData,opsData,printData]=await Promise.all([
      api('branches'),api('staff'),api('devices'),api('roles'),
      api('overview').catch(()=>({counts:{},open_shifts:[]})),
      api('printMonitor').catch(()=>({profiles:[],jobs:[],stats:{}}))
    ]);
    const canUseBranches=navigation.some(item=>item.tab==='branches'&&item.state==='available');
    const branches=canUseBranches?(branchesData.branches||[]).filter(x=>x.is_active):[];
    const staff=(staffData.staff||[]).filter(x=>x.is_active);
    const devices=(devicesData.devices||[]).filter(x=>x.is_active);
    const roles=(rolesData.roles||[]).filter(x=>x.is_active);

    const status=box('حالة نقاط البيع الآن');
    hint(status,'صورة تشغيلية من الجلسات والورديات والطابعات الفعلية. لا تُحسب «نقطة بيع متصلة» من مجرد وجود جهاز مسجل.');
    const counts=opsData.counts||{},printStats=printData.stats||{},profiles=printData.profiles||[],openShifts=opsData.open_shifts||[];
    const degraded=profiles.filter(x=>x.status==='degraded').length;
    const live=node('div',null,'kpi-grid');
    live.append(
      kpiCard('الموظفون النشطون',String(counts.active_employees??staff.length),'♟','حسابات صالحة للتشغيل'),
      kpiCard('أجهزة متصلة الآن',String(counts.active_device_sessions??0),'▣',(counts.devices??devices.length)+' جهاز مسجل','blue'),
      kpiCard('ورديات مفتوحة',String(counts.open_shifts??openShifts.length),'◉',opsData.open_shifts_has_more?'توجد ورديات إضافية خارج المعاينة':'المعاينة الحالية','gold'),
      kpiCard('طابعات تحتاج فحص',String(degraded),'!','فشل حديث في الطباعة',degraded>0?'red':'')
    );
    status.append(live);

    if(openShifts.length){
      status.append(node('h3','الورديات المفتوحة الآن'));
      table(status,[
        ['الموظف',x=>x.opened_by_name||x.employee_code||'—'],
        ['النوع',x=>x.shift_type==='fuel'?'نوبة وقود':'وردية كاشير'],
        ['موقع التشغيل',x=>x.branch_name||'المنشأة الرئيسية'],
        ['وقت الفتح',x=>x.opened_at?new Date(x.opened_at).toLocaleString('ar-YE'):'—']
      ],openShifts);
    }else{
      hint(status,'لا توجد وردية مفتوحة الآن. يجب فتح الوردية من جهاز POS قبل بدء تحصيل النقد.');
    }

    if(profiles.length){
      status.append(node('h3','صحة الطابعات المرتبطة'));
      table(status,[
        ['الطابعة',x=>x.name],['الجهاز',x=>x.device_name||'—'],
        ['الموقع',x=>x.branch_name||'المنشأة الرئيسية'],
        ['الاتصال',x=>x.connection_type||'—'],
        ['الحالة',x=>x.status==='degraded'?'تحتاج فحص':x.status==='active'?'سليمة':x.status||'—'],
        ['آخر نجاح',x=>x.last_success_at?new Date(x.last_success_at).toLocaleString('ar-YE'):'—'],
        ['آخر فشل',x=>x.last_failure_at?new Date(x.last_failure_at).toLocaleString('ar-YE'):'—']
      ],profiles.slice(0,12));
      if(Number(printStats.failure_rate||0)>0)hint(status,'نسبة فشل الطباعة خلال الفترة المرصودة: '+Number(printStats.failure_rate||0).toFixed(2)+'%. افتح «المستندات والطباعة» لمعرفة المهمة والفاتورة ورمز الخطأ.');
    }

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
      const reason=await amialPrompt('رفض طلب الموظف','سبب الرفض',{multiline:true,required:true,minLength:3,confirmLabel:'رفض الطلب'});
      if(reason===null)return;
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
  function openMerchantModal(title,subtitle=''){
    document.querySelector('.merchant-modal-backdrop')?.remove();
    const backdrop=node('div',null,'merchant-modal-backdrop');
    const dialog=node('section',null,'merchant-modal');
    dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-label',title);
    const head=node('header',null,'merchant-modal-head'),copy=node('div');
    copy.append(node('h2',title));
    if(subtitle)copy.append(node('p',subtitle));
    const closeBtn=node('button','×','merchant-modal-close');closeBtn.type='button';closeBtn.setAttribute('aria-label','إغلاق');
    head.append(copy,closeBtn);
    const body=node('div',null,'merchant-modal-body');
    dialog.append(head,body);backdrop.append(dialog);document.body.append(backdrop);
    document.body.classList.add('merchant-modal-open');
    let closed=false;const closeHandlers=[];
    const onKey=ev=>{if(ev.key==='Escape')close()};
    const close=()=>{
      if(closed)return;closed=true;document.removeEventListener('keydown',onKey);
      backdrop.remove();document.body.classList.remove('merchant-modal-open');
      closeHandlers.splice(0).forEach(fn=>{try{fn()}catch(_){}});
    };
    closeBtn.addEventListener('click',close);
    backdrop.addEventListener('mousedown',ev=>{if(ev.target===backdrop)close()});
    document.addEventListener('keydown',onKey);
    requestAnimationFrame(()=>closeBtn.focus());
    return {backdrop,dialog,body,close,onClose:fn=>{if(typeof fn==='function')closeHandlers.push(fn)}};
  }

  function amialConfirm(title,detail,options={}){
    return new Promise(resolve=>{
      const modal=openMerchantModal(title,options.subtitle||'');
      let settled=false;
      const finish=value=>{if(settled)return;settled=true;modal.close();resolve(value)};
      modal.onClose(()=>{if(!settled){settled=true;resolve(false)}});
      const copy=node('div',null,'modal-confirm-copy'+(options.danger?' danger':''));
      copy.append(node('strong',options.heading||title),node('p',detail));
      const actions=node('div',null,'merchant-modal-actions');
      const cancel=node('button',options.cancelLabel||'إلغاء','action secondary');cancel.type='button';cancel.onclick=()=>finish(false);
      const ok=node('button',options.confirmLabel||'تأكيد','action'+(options.danger?' danger-action':''));ok.type='button';ok.onclick=()=>finish(true);
      actions.append(cancel,ok);modal.body.append(copy,actions);requestAnimationFrame(()=>ok.focus());
    });
  }

  function amialPrompt(title,labelText,options={}){
    return new Promise(resolve=>{
      const modal=openMerchantModal(title,options.subtitle||'');
      let settled=false;
      const finish=value=>{if(settled)return;settled=true;modal.close();resolve(value)};
      modal.onClose(()=>{if(!settled){settled=true;resolve(null)}});
      const frm=node('form',null,'merchant-modal-form'),label=node('label',labelText,'field');
      const input=options.multiline?node('textarea'):node('input');
      if(!options.multiline)input.type=options.type||'text';
      input.value=options.value??'';
      if(options.placeholder)input.placeholder=options.placeholder;
      if(options.min!==undefined)input.min=String(options.min);
      if(options.max!==undefined)input.max=String(options.max);
      if(options.step!==undefined)input.step=String(options.step);
      if(options.maxLength)input.maxLength=options.maxLength;
      if(options.required)input.required=true;
      if(options.multiline)input.rows=4;
      label.append(input);frm.append(label);
      if(options.note)frm.append(node('div',options.note,'note'));
      const actions=node('div',null,'merchant-modal-actions');
      const cancel=node('button',options.cancelLabel||'إلغاء','action secondary');cancel.type='button';cancel.onclick=()=>finish(null);
      const ok=node('button',options.confirmLabel||'متابعة','action');ok.type='submit';actions.append(cancel,ok);frm.append(actions);modal.body.append(frm);
      frm.onsubmit=ev=>{
        ev.preventDefault();const value=String(input.value??'');
        if(options.required&&!value.trim()){message(options.requiredMessage||'هذا الحقل مطلوب');input.focus();return}
        if(options.minLength&&value.trim().length<options.minLength){message(options.minLengthMessage||('اكتب '+options.minLength+' أحرف على الأقل'));input.focus();return}
        finish(value);
      };
      requestAnimationFrame(()=>input.focus());
    });
  }

  function amialSelect(title,labelText,options,config={}){
    return new Promise(resolve=>{
      const modal=openMerchantModal(title,config.subtitle||'');
      let settled=false;
      const finish=value=>{if(settled)return;settled=true;modal.close();resolve(value)};
      modal.onClose(()=>{if(!settled){settled=true;resolve(null)}});
      const frm=node('form',null,'merchant-modal-form'),label=node('label',labelText,'field'),select=node('select');
      (options||[]).forEach(opt=>select.append(new Option(opt.label,String(opt.value))));
      if(config.value!==undefined&&config.value!==null)select.value=String(config.value);
      label.append(select);frm.append(label);
      if(config.note)frm.append(node('div',config.note,'note'));
      const actions=node('div',null,'merchant-modal-actions');
      const cancel=node('button',config.cancelLabel||'إلغاء','action secondary');cancel.type='button';cancel.onclick=()=>finish(null);
      const ok=node('button',config.confirmLabel||'اختيار','action');ok.type='submit';actions.append(cancel,ok);frm.append(actions);modal.body.append(frm);
      frm.onsubmit=ev=>{ev.preventDefault();finish(select.value)};
      requestAnimationFrame(()=>select.focus());
    });
  }

  function modalField(labelText,name,type='text'){
    const label=node('label',labelText,'field'),input=node('input');
    input.name=name;input.type=type;
    if(type==='password')input.autocomplete='new-password';
    label.append(input);return {label,input};
  }

  async function editStaffScope(row,roles,branches){
    const modal=openMerchantModal(
      'إدارة الموظف · '+row.display_name,
      'غيّر الدور أو فرع التشغيل من مكان واحد. تغيير الفرع ينهي جلسات POS القديمة حمايةً للصندوق.'
    );
    const frm=node('form',null,'editor merchant-modal-form');

    const roleLabel=node('label','الدور التشغيلي','field'),roleSelect=node('select');
    roles.filter(x=>x.is_active).forEach(r=>{const o=node('option',r.name_ar);o.value=String(r.id);roleSelect.append(o)});
    const currentRole=row.roles?.[0]?.id;if(currentRole)roleSelect.value=String(currentRole);roleLabel.append(roleSelect);

    const branchLabel=node('label','فرع التشغيل','field'),branchSelect=node('select');
    branchSelect.append(new Option('المنشأة الرئيسية',''));
    branches.filter(x=>x.is_active).forEach(b=>branchSelect.append(new Option(b.name+(b.city?' — '+b.city:''),String(b.id))));
    branchSelect.value=row.branch_id===null||row.branch_id===undefined?'':String(row.branch_id);branchLabel.append(branchSelect);

    const meta=node('div',null,'modal-summary');
    const addMeta=(k,v)=>{const x=node('div');x.append(node('span',k),node('strong',v));meta.append(x)};
    addMeta('رمز الدخول',row.employee_code||'—');
    addMeta('الحالة',row.is_active?'نشط':'موقوف');
    addMeta('آخر دخول',row.last_login_at?new Date(row.last_login_at).toLocaleString('ar-YE'):'لم يسجل بعد');

    const actions=node('div',null,'merchant-modal-actions'),save=action('حفظ التغييرات',()=>{},false),cancel=action('إلغاء',modal.close);
    save.type='submit';actions.append(cancel,save);frm.append(roleLabel,branchLabel,meta,actions);modal.body.append(frm);

    frm.onsubmit=async ev=>{
      ev.preventDefault();save.disabled=true;
      try{
        const tasks=[];
        const nextRole=String(roleSelect.value||''),oldRole=String(currentRole||'');
        const nextBranch=String(branchSelect.value||''),oldBranch=row.branch_id===null||row.branch_id===undefined?'':String(row.branch_id);
        if(nextRole&&nextRole!==oldRole)tasks.push(api('staffRole',{merchant_role_id:Number(nextRole)},routes.staffRole.replace('__ID__',String(row.id))));
        if(nextBranch!==oldBranch)tasks.push(api('staffBranch',{branch_id:nextBranch===''?null:Number(nextBranch)},routes.staffBranch.replace('__ID__',String(row.id))));
        if(!tasks.length){message('لم يتغير الدور أو فرع التشغيل');save.disabled=false;return}
        const results=await Promise.all(tasks),ended=results.reduce((n,x)=>n+Number(x.ended_device_sessions||0),0);
        modal.close();
        message(ended>0?'تم الحفظ وإنهاء '+ended+' جلسة POS قديمة؛ يلزم تسجيل الدخول مجدداً.':'تم تحديث الموظف بنجاح');
        await load('staff');
      }catch(e){message(e.message);save.disabled=false}
    };
  }

  async function staff(){
    const [data,rolesData,approvalData,performance,branchData]=await Promise.all([
      api('staff'),api('roles'),api('approvals'),
      api('staffPerformance').catch(()=>({staff:[],grand_total:0,unattributed_total:0,source:'غير متاح'})),
      api('branches').catch(()=>({branches:[]}))
    ]);
    const staffRows=data.staff||[],roles=rolesData.roles||[],catalogue=rolesData.permission_catalogue||[],
      approvals=approvalData.approvals||[],branches=branchData.branches||[];
    const activeStaff=staffRows.filter(x=>x.is_active).length;
    const perfById=new Map((performance.staff||[]).map(x=>[String(x.id),x]));

    dashboardKpis([
      ['الموظفون',String(staffRows.length),'♙',activeStaff+' نشط'],
      ['الأدوار',String(roles.filter(x=>x.is_active).length),'◎','حزم صلاحيات فعلية من الخادم','blue'],
      ['مبيعات الموظفين · '+(performance.days||7)+' أيام',money(performance.grand_total||0),'↗','المصدر: '+(performance.source||'سجل القطاع'),'gold'],
      ['طلبات الاعتماد',String(approvals.filter(x=>x.status==='pending').length),'!',approvals.length?'تحتاج قرار المالك':'لا توجد طلبات معلقة',approvals.some(x=>x.status==='pending')?'red':'']
    ]);

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

    const p=box('فريق نقاط البيع');
    const teamHead=node('div',null,'staff-toolbar'),teamCopy=node('div');
    teamCopy.append(node('h3','الموظفون، أدوارهم وفروعهم'),node('p','أضف الموظف أولاً ثم اربطه بدور وفرع. الصلاحيات المتقدمة تبقى في نافذة منفصلة ولا تزاحم العمل اليومي.'));
    const addStaff=action('+ إضافة موظف',()=>openCreateStaff(),false);
    const manageRoles=action('الأدوار والصلاحيات',()=>openRoles());
    teamHead.append(teamCopy,buttons([addStaff,manageRoles]));p.append(teamHead);

    table(p,[
      ['الموظف',x=>x.display_name],
      ['رمز الدخول',x=>x.employee_code],
      ['الدور',x=>(x.roles||[]).map(r=>r.name_ar).join('، ')||'الدور الافتراضي'],
      ['الفرع',x=>x.branch_name||'المنشأة الرئيسية'],
      ['مبيعات الفترة',x=>money(perfById.get(String(x.id))?.sales_total||0)],
      ['عمليات اليوم',x=>perfById.get(String(x.id))?.today_count||0],
      ['الحالة',x=>{const s=node('span',x.is_active?'نشط':'موقوف','staff-status '+(x.is_active?'on':'off'));return s}],
      ['الإجراء',x=>{
        const manage=action('إدارة',()=>editStaffScope(x,roles,branches));
        const toggle=action(x.is_active?'إيقاف':'تفعيل',async()=>{
          if(!await amialConfirm((x.is_active?'إيقاف':'تفعيل')+' حساب الموظف','الحساب: «'+x.display_name+'».'+(x.is_active?' سيتم قطع جلسات العمل المفتوحة فوراً.':''),{confirmLabel:x.is_active?'إيقاف الحساب':'تفعيل الحساب',danger:x.is_active}))return;
          toggle.disabled=true;
          try{
            const result=await api('staffToggle',{},routes.staffToggle.replace('__ID__',String(x.id)));
            message(result.message||(x.is_active?'تم الإيقاف':'تم التفعيل'));await load('staff');
          }catch(e){message(e.message);toggle.disabled=false}
        });
        return buttons([manage,toggle])
      }]
    ],staffRows);

    if(Number(performance.unattributed_total||0)>0){
      hint(p,'يوجد '+money(performance.unattributed_total)+' من مبيعات الفترة غير منسوب لموظف POS (مثل مبيعات نفذها المالك مباشرة).');
    }

    function openCreateStaff(){
      const modal=openMerchantModal(
        'إضافة موظف نقطة بيع',
        'هذا ينشئ حساب دخول للموظف. الجهاز نفسه يُدار بشكل مستقل من إعداد نقاط البيع.'
      );
      const frm=node('form',null,'editor merchant-modal-form');
      const name=modalField('اسم الموظف','display_name'),code=modalField('رمز الدخول','employee_code'),pass=modalField('كلمة مرور مؤقتة','password');
      name.input.required=code.input.required=pass.input.required=true;

      const roleLabel=node('label','الدور والصلاحيات','field'),roleSelect=node('select');roleSelect.name='merchant_role_id';
      roleSelect.append(new Option('الدور الافتراضي لنقطة البيع',''));
      roles.filter(r=>r.is_active).forEach(r=>roleSelect.append(new Option(r.name_ar,String(r.id))));roleLabel.append(roleSelect);

      const branchLabel=node('label','فرع التشغيل','field'),branchSelect=node('select');branchSelect.name='branch_id';
      branchSelect.append(new Option('المنشأة الرئيسية',''));
      branches.filter(b=>b.is_active).forEach(b=>branchSelect.append(new Option(b.name+(b.city?' — '+b.city:''),String(b.id))));branchLabel.append(branchSelect);

      const note=node('div',null,'note');
      note.textContent='الدور يحدد ماذا يستطيع الموظف فعله، والفرع يحدد أين يعمل. ربط جهاز POS يتم من إعداد الأجهزة ولا ينشأ ضمن هذا الحساب.';
      const actions=node('div',null,'merchant-modal-actions'),cancel=action('إلغاء',modal.close),save=action('إنشاء حساب الموظف',()=>{},false);save.type='submit';
      actions.append(cancel,save);frm.append(name.label,code.label,pass.label,roleLabel,branchLabel,note,actions);modal.body.append(frm);

      frm.onsubmit=async ev=>{
        ev.preventDefault();save.disabled=true;
        try{
          const payload=Object.fromEntries(new FormData(frm).entries());
          Object.keys(payload).forEach(k=>{if(payload[k]==='')delete payload[k]});
          if(payload.merchant_role_id)payload.merchant_role_id=Number(payload.merchant_role_id);
          if(payload.branch_id)payload.branch_id=Number(payload.branch_id);
          const result=await api('staffCreate',payload);
          modal.close();message(result.message||'تم إنشاء حساب الموظف');await load('staff');
        }catch(e){message(e.message);save.disabled=false}
      };
    }

    function openRoles(){
      const modal=openMerchantModal(
        'الأدوار والصلاحيات',
        'الأدوار الجاهزة تكفي أغلب المتاجر. أنشئ دوراً مخصصاً فقط عندما تحتاج مجموعة صلاحيات مختلفة فعلاً.'
      );
      const top=node('div',null,'modal-toolbar');
      top.append(node('p','كل دور حزمة صلاحيات يطبقها الخادم عند تنفيذ العملية، وليس مجرد إخفاء أزرار.','muted'));
      const createRole=action('+ إنشاء دور مخصص',()=>{modal.close();openCreateRole()},false);top.append(createRole);modal.body.append(top);

      const roleGrid=node('div',null,'roles-grid'),catalogueMap=new Map(catalogue.map(x=>[x.code,x]));
      roles.forEach(role=>{
        const card=node('article',null,'role-card'),head=node('div',null,'role-card-head');
        head.append(node('h4',role.name_ar),node('span',role.is_system?'دور جاهز':'مخصص','source-chip'));card.append(head);
        if(role.description_ar)card.append(node('p',role.description_ar));
        const meta=node('div',null,'role-meta');
        meta.append(node('span',(role.permissions_count||0)+' صلاحية'),node('span',(role.assignments_count||0)+' موظف'));card.append(meta);
        const details=node('details'),summary=node('summary','عرض الصلاحيات ('+(role.permissions_count||0)+')');details.append(summary);
        const names=(role.permissions||[]).map(code=>catalogueMap.get(code)?.name||code);
        details.append(node('p',names.join(' · ')||'لا توجد صلاحيات','muted'));card.append(details);roleGrid.append(card)
      });
      modal.body.append(roleGrid);
    }

    function openCreateRole(){
      const modal=openMerchantModal(
        'إنشاء دور مخصص',
        'اختر أقل قدر من الصلاحيات التي يحتاجها هذا الدور. الإجراءات الحساسة مميزة بوضوح.'
      );
      const frm=node('form',null,'merchant-modal-form'),fields=node('div',null,'editor');
      const name=modalField('اسم الدور','name_ar'),desc=modalField('وصف مختصر','description_ar');
      name.input.required=true;name.input.maxLength=80;desc.input.maxLength=240;fields.append(name.label,desc.label);frm.append(fields);

      const grouped=new Map();catalogue.forEach(item=>{if(!grouped.has(item.group))grouped.set(item.group,[]);grouped.get(item.group).push(item)});
      const permissionBox=node('div',null,'permission-groups');
      grouped.forEach((items,group)=>{
        const details=node('details',null,'permission-group');details.open=['الموظفون','الصندوق'].includes(group);
        details.append(node('summary',group+' · '+items.length+' صلاحية'));
        const opts=node('div',null,'permission-options');
        items.forEach(item=>{
          const label=node('label',null,'permission-option'+(item.sensitive?' sensitive':'')),check=node('input');check.type='checkbox';check.name='permissions';check.value=item.code;
          label.append(check,node('span',item.name+(item.sensitive?' · إجراء حساس':'')));opts.append(label)
        });
        details.append(opts);permissionBox.append(details)
      });
      frm.append(permissionBox);
      const actions=node('div',null,'merchant-modal-actions'),cancel=action('إلغاء',modal.close),save=action('إنشاء الدور',()=>{},false);save.type='submit';actions.append(cancel,save);frm.append(actions);modal.body.append(frm);

      frm.onsubmit=async ev=>{
        ev.preventDefault();
        const permissions=[...frm.querySelectorAll('input[name="permissions"]:checked')].map(x=>x.value);
        if(!permissions.length){message('اختر صلاحية واحدة على الأقل');return}
        save.disabled=true;
        try{
          await api('rolesCreate',{name_ar:name.input.value.trim(),description_ar:desc.input.value.trim()||null,permissions});
          modal.close();message('تم إنشاء الدور ويمكن اختياره عند إضافة الموظف');await load('staff');
        }catch(e){message(e.message);save.disabled=false}
      };
    }

  }

  // AMIAL-MERCHANT-WEB-PROCUREMENT-001 — المالك أصبح Web-only؛ لذلك
  // الموردون والمشتريات والمصروفات تُدار هنا من مصادر الخادم نفسها، لا
  // من نسخة Flutter مهجورة ولا من جدول مالي موازٍ.
  function dataUrl(key,id){return routes[key].replace('__ID__',encodeURIComponent(String(id)))}
  function poStatus(v){return {draft:'مسودة',approved:'معتمد',partially_received:'مستلم جزئياً',completed:'مكتمل',cancelled:'ملغى'}[v]||v||'—'}
  function ledgerType(v){return {opening:'رصيد افتتاحي',po_receive:'استلام شراء',payment:'سداد',supplier_refund:'تحصيل من المورد',po_return:'مرتجع شراء',adjustment:'تسوية'}[v]||v||'—'}
  function buttons(items){const wrap=node('div',null,'buttons');items.filter(Boolean).forEach(item=>wrap.append(item));return wrap}
  function action(label,handler,secondary=true){const b=node('button',label,secondary?'action secondary':'action');b.type='button';b.addEventListener('click',handler);return b}

  async function showSupplier(id){
    const data=await api('supplierShow',undefined,dataUrl('supplierShow',id));
    document.getElementById('supplier-detail')?.remove();
    const supplier=data.supplier||{},p=box('كشف المورد — '+(supplier.name||''));
    p.id='supplier-detail';
    hint(p,'هذا الكشف هو سجل المديونية: الاستلام يزيد ما عليك للمورد، والسداد أو إشعار المرتجع يخفضه. لا يغيّر رصيد محفظة أميال من نفسه.');
    const summary=node('div',null,'grid');
    summary.append(metric('علينا للمورد',money(supplier.current_debt)),metric('لنا عند المورد',money(supplier.current_credit||0)),metric('صافي المركز',money(Number(supplier.current_debt||0)-Number(supplier.current_credit||0))),metric('الهاتف',supplier.phone||'—'));
    p.append(summary);
    table(p,[['التاريخ',x=>x.created_at||'—'],['الحركة',x=>ledgerType(x.entry_type)],['القيمة',x=>money(x.amount)],['مدفوع نقداً',x=>x.cash_amount===null||x.cash_amount===undefined?'—':money(x.cash_amount)],['علينا بعد',x=>money(x.debt_after)],['لنا بعد',x=>money(x.credit_after||0)],['المرجع',x=>x.reference||'—'],['المستند',x=>['payment','supplier_refund'].includes(x.entry_type)&&x.entry_ulid?action('PDF',()=>window.open(dataUrl('supplierPaymentPdf',x.entry_ulid),'_blank','noopener')):'—'],['ملاحظة',x=>x.note||'—']],data.ledger||[]);
    const pdf=action('تنزيل كشف المورد PDF',()=>window.open(dataUrl('supplierStatementPdf',id),'_blank','noopener'),false);
    p.append(buttons([pdf]));
    p.scrollIntoView({behavior:'smooth',block:'start'});
  }

  async function chooseCashierShift(openShifts,verb){
    if(!Array.isArray(openShifts)||openShifts.length===0)return null;
    const source=await amialSelect(
      'مصدر الحركة النقدية','مصدر النقد',
      [
        {value:'external',label:'نقد خارجي — لا يخصم من درج POS'},
        {value:'shift',label:'درج وردية POS مفتوحة'}
      ],
      {note:'حدد المصدر الحقيقي حتى تبقى مطابقة الدرج والتقارير صحيحة.',confirmLabel:'متابعة'}
    );
    if(source===null)return undefined;
    if(source==='external')return null;
    const picked=await amialSelect(
      verb,'الوردية',
      openShifts.map(s=>({
        value:s.id,
        label:(s.opened_by_name||'وردية')+(s.branch_name?' · '+s.branch_name:'')+' (#'+s.id+')'
      })),
      {confirmLabel:'اختيار الوردية'}
    );
    if(picked===null)return undefined;
    return Number(picked);
  }

  async function paySupplier(row,openShifts){
    const debt=Number(row.current_debt||0);
    if(!(debt>0)){message('لا توجد مديونية مستحقة لهذا المورد');return}
    const raw=await amialPrompt('سداد المورد · '+row.name,'المبلغ',{type:'number',min:0.01,step:'0.01',required:true,note:'الحد الأقصى '+money(row.current_debt),confirmLabel:'متابعة'});
    if(raw===null)return;
    const amount=Number(raw);
    if(!(amount>0)||amount>debt){message('اكتب مبلغاً صحيحاً لا يتجاوز الرصيد المستحق');return}
    const note=(await amialPrompt('ملاحظة السداد','ملاحظة (اختياري)',{multiline:true,confirmLabel:'متابعة'}))??'';
    const shiftId=await chooseCashierShift(openShifts,'دفع هذا المبلغ');
    if(shiftId===undefined)return;
    await api('supplierPayment',{
      amount:String(amount),note,
      ...(shiftId?{cashier_shift_id:shiftId}:{})
    },dataUrl('supplierPayment',row.id));
    message('تم تسجيل سداد المورد');
    await load('suppliers');
  }

  async function paySupplierWallet(row){
    const debt=Number(row.current_debt||0);
    if(!(debt>0)){message('لا توجد مديونية مستحقة لهذا المورد');return}
    if(!row.phone){message('أضف رقم هاتف المورد المرتبط بحساب أميال أولاً');return}
    const raw=await amialPrompt('سداد المورد عبر أميال · '+row.name,'المبلغ',{type:'number',min:0.01,step:'0.01',required:true,note:'الحد الأقصى '+money(row.current_debt),confirmLabel:'متابعة'});
    if(raw===null)return;
    const amount=Number(raw);
    if(!(amount>0)||amount>debt){message('اكتب مبلغاً صحيحاً لا يتجاوز الرصيد المستحق');return}
    const note=(await amialPrompt('ملاحظة السداد','ملاحظة (اختياري)',{multiline:true,confirmLabel:'متابعة'}))??'';
    if(!await amialConfirm('تأكيد سداد المورد عبر أميال','سيُخصم '+money(amount)+' من محفظة المنشأة ويرسل إلى حساب أميال المرتبط برقم المورد '+row.phone+'.',{confirmLabel:'إرسال المبلغ',danger:true}))return;
    const result=await api('supplierWalletPayment',{amount:String(amount),note},dataUrl('supplierWalletPayment',row.id));
    message('تم السداد عبر أميال — مرجع العملية '+(result.transaction_id||'—'));
    await load('suppliers');
  }

  async function collectSupplierCredit(row,openShifts){
    const credit=Number(row.current_credit||0);
    if(!(credit>0)){message('لا يوجد رصيد لنا عند هذا المورد');return}
    const raw=await amialPrompt('تحصيل من المورد · '+row.name,'المبلغ',{type:'number',min:0.01,step:'0.01',value:String(credit),required:true,note:'الحد الأقصى '+money(row.current_credit),confirmLabel:'متابعة'});
    if(raw===null)return;
    const amount=Number(raw);
    if(!(amount>0)||amount>credit){message('اكتب مبلغاً صحيحاً لا يتجاوز الرصيد لنا عند المورد');return}
    const note=(await amialPrompt('ملاحظة التحصيل','ملاحظة (اختياري)',{multiline:true,confirmLabel:'متابعة'}))??'';
    const shiftId=await chooseCashierShift(openShifts,'استلام هذا المبلغ من المورد');
    if(shiftId===undefined)return;
    const result=await api('supplierCreditRefund',{
      amount:String(amount),note,
      ...(shiftId?{cashier_shift_id:shiftId}:{})
    },dataUrl('supplierCreditRefund',row.id));
    message('تم تحصيل رصيد المورد');
    if(result.receipt?.entry_ulid&&await amialConfirm('تم التحصيل','تم إنشاء سند تحصيل رسمي. هل تريد فتحه الآن؟',{confirmLabel:'فتح السند'})){
      window.open(dataUrl('supplierPaymentPdf',result.receipt.entry_ulid),'_blank','noopener');
    }
    await load('suppliers');
  }

  async function receivePurchaseOrder(row,openShifts){
    const data=await api('purchaseOrderShow',undefined,dataUrl('purchaseOrderShow',row.id));
    const order=data.order||{},items=(order.items||[]).filter(item=>Number(item.quantity||0)>Number(item.received_quantity||0));
    if(!items.length){message('لا توجد كميات متبقية للاستلام');return}
    const payload=[];
    for(const item of items){
      const remaining=Number(item.quantity||0)-Number(item.received_quantity||0);
      const raw=await amialPrompt('استلام بند الشراء · '+item.name,'الكمية المستلمة',{type:'number',min:0.001,step:'0.001',max:remaining,value:String(remaining),note:'المتبقي '+remaining,confirmLabel:'إضافة الكمية'});
      if(raw===null)continue;
      const qty=Number(raw);
      if(qty>0&&qty<=remaining)payload.push({item_id:item.id,received_quantity:String(qty)});
      else if(raw.trim()!==''){message('كمية غير صحيحة للصنف '+item.name);return}
    }
    if(!payload.length){message('لم تحدد أي كمية للاستلام');return}
    const paid=await amialPrompt('الدفع عند الاستلام','المبلغ المدفوع نقداً',{type:'number',min:0,step:'0.01',value:'0',required:true,note:'اكتب 0 إذا كان الاستلام كله آجلاً.',confirmLabel:'متابعة'});
    if(paid===null)return;
    let shiftId=null;
    if(Number(paid)>0){
      shiftId=await chooseCashierShift(openShifts,'دفع قيمة الاستلام');
      if(shiftId===undefined)return;
    }
    await api('purchaseOrderReceive',{
      items:payload,paid_now:paid||'0',
      ...(shiftId?{cashier_shift_id:shiftId}:{})
    },dataUrl('purchaseOrderReceive',row.id));
    message('تم الاستلام وتحديث المخزون وحساب المورد');
    await load('suppliers');
  }

  async function createReturnFromOrder(row,openShifts){
    const data=await api('purchaseOrderShow',undefined,dataUrl('purchaseOrderShow',row.id));
    const order=data.order||{};
    const items=(order.items||[]).filter(item=>Number(item.received_quantity||0)>Number(item.returned_quantity||0));
    if(!items.length){message('لا توجد بضاعة قابلة للرد في هذا الأمر');return}
    const picked=await amialSelect(
      'اختيار بند المرتجع','البند',
      items.map(item=>({value:item.id,label:item.name+' — المتاح '+(Number(item.received_quantity||0)-Number(item.returned_quantity||0))})),
      {confirmLabel:'اختيار البند'}
    );
    if(picked===null)return;
    const item=items.find(x=>String(x.id)===String(picked));
    if(!item){message('اختيار غير صحيح');return}
    const max=Number(item.received_quantity||0)-Number(item.returned_quantity||0);
    const raw=await amialPrompt('كمية مرتجع الشراء · '+item.name,'الكمية',{type:'number',min:0.001,step:'0.001',max,value:String(max),required:true,note:'الحد الأقصى '+max,confirmLabel:'متابعة'});
    if(raw===null)return;
    const qty=Number(raw);
    if(!(qty>0)||qty>max){message('كمية الرد غير صحيحة');return}
    const reason=await amialPrompt('سبب مرتجع الشراء','السبب',{multiline:true,required:true,minLength:3,placeholder:'تالف، منتهي، زائد عن الأمر…',confirmLabel:'متابعة'});
    if(!reason||reason.trim().length<3){message('سبب المرتجع مطلوب');return}
    const settlement=await amialSelect(
      'طريقة تسوية المرتجع','طريقة التسوية',
      [
        {value:'cash_refund',label:'استرداد نقدي من المورد'},
        {value:'credit_note',label:'خصم القيمة من دين المورد'}
      ],
      {note:'الإغلاق أو ESC يلغي العملية ولا يختار طريقة ضمنياً.',confirmLabel:'متابعة'}
    );
    if(settlement===null)return;
    const cash=settlement==='cash_refund';
    let shiftId=null;
    if(cash){
      shiftId=await chooseCashierShift(openShifts,'استلام مبلغ المرتجع');
      if(shiftId===undefined)return;
    }
    await api('purchaseReturnCreate',{
      supplier_id:order.supplier_id,
      purchase_order_id:order.id,
      settlement_type:cash?'cash_refund':'credit_note',
      ...(shiftId?{cashier_shift_id:shiftId}:{}),
      reason:reason.trim(),
      items:[{purchase_order_item_id:item.id,quantity:String(qty)}]
    });
    message('سُجل المرتجع وبانتظار الاعتماد');
    await load('suppliers');
  }

  function purchaseOrderActions(row,openShifts){
    const list=[action('PDF',()=>window.open(dataUrl('purchaseOrderPdf',row.id),'_blank','noopener'))];
    if(row.status==='draft')list.push(action('اعتماد',async()=>{await api('purchaseOrderApprove',{},dataUrl('purchaseOrderApprove',row.id));message('تم اعتماد أمر الشراء');await load('suppliers')},false));
    if(row.status==='approved'||row.status==='partially_received')list.push(action('استلام',()=>receivePurchaseOrder(row,openShifts),false));
    if(row.status==='draft'||row.status==='approved')list.push(action('إلغاء',async()=>{if(!await amialConfirm('إلغاء أمر الشراء','سيتم إلغاء أمر الشراء '+row.po_number+' مع بقاء أثره التاريخي.',{confirmLabel:'إلغاء الأمر',danger:true}))return;await api('purchaseOrderCancel',{},dataUrl('purchaseOrderCancel',row.id));message('تم إلغاء الأمر');await load('suppliers')}));
    if(row.status==='partially_received'||row.status==='completed')list.push(action('مرتجع',()=>createReturnFromOrder(row,openShifts)));
    return buttons(list);
  }

  function returnActions(row){
    if(row.status!=='pending')return node('span',row.status==='approved'?'معتمد':'مرفوض','note');
    const approve=action('اعتماد',async()=>{await api('purchaseReturnApprove',{},dataUrl('purchaseReturnApprove',row.id));message('اعتُمد المرتجع وتحدّث المخزون وحساب المورد');await load('suppliers')},false);
    const reject=action('رفض',async()=>{const reason=await amialPrompt('رفض مرتجع الشراء','سبب الرفض',{multiline:true,required:true,minLength:5,confirmLabel:'رفض المرتجع'});if(reason===null)return;await api('purchaseReturnReject',{reason:reason.trim()},dataUrl('purchaseReturnReject',row.id));message('رُفض المرتجع');await load('suppliers')});
    return buttons([approve,reject]);
  }

  function purchaseOrderCreator(panel,suppliersList,products){
    const f=node('form',null,'editor');
    const supplierLabel=node('label','المورد','field'),supplier=node('select');supplier.name='supplier_id';supplier.required=true;supplier.append(new Option('اختر المورد',''));
    suppliersList.forEach(s=>supplier.append(new Option(s.name,s.id)));supplierLabel.append(supplier);f.append(supplierLabel);
    const lines=node('div'),rows=[];
    const assetCategories={furniture:'أثاث',equipment:'معدات',computer:'أجهزة وتقنية',vehicle:'مركبات',machinery:'آلات',fixtures:'تجهيزات',building_improvement:'تحسينات مبانٍ',other:'أخرى'};
    const addLine=()=>{
      const row=node('div',null,'editor');
      const typeLabel=node('label','نوع البند','field'),type=node('select');
      [['inventory','بضاعة/مخزون'],['fixed_asset','أصل ثابت'],['other','شراء آخر غير مخزني']].forEach(([v,l])=>type.append(new Option(l,v)));typeLabel.append(type);
      const productLabel=node('label','الصنف من المخزون','field'),product=node('select');product.append(new Option('غير مربوط بصنف',''));
      products.forEach(p=>product.append(new Option(p.name||p.trade_name||('منتج '+p.id),p.id)));
      productLabel.append(product);
      const nameLabel=node('label','اسم البند','field'),name=node('input');name.required=true;nameLabel.append(name);
      const qtyLabel=node('label','الكمية','field'),qty=node('input');qty.type='number';qty.step='0.001';qty.min='0.001';qty.value='1';qty.required=true;qtyLabel.append(qty);
      const costLabel=node('label','تكلفة الوحدة','field'),cost=node('input');cost.type='number';cost.step='0.01';cost.min='0';cost.required=true;costLabel.append(cost);
      const assetBox=node('div',null,'editor');
      const catLabel=node('label','فئة الأصل','field'),cat=node('select');Object.entries(assetCategories).forEach(([v,l])=>cat.append(new Option(l,v)));catLabel.append(cat);
      const lifeLabel=node('label','العمر الإنتاجي بالأشهر','field'),life=node('input');life.type='number';life.min='1';life.max='600';life.value='60';lifeLabel.append(life);
      const salvageLabel=node('label','القيمة المتبقية للوحدة','field'),salvage=node('input');salvage.type='number';salvage.step='0.01';salvage.min='0';salvage.value='0';salvageLabel.append(salvage);
      assetBox.append(catLabel,lifeLabel,salvageLabel);
      const remove=action('حذف البند',()=>{row.remove();const i=rows.findIndex(x=>x.row===row);if(i>=0)rows.splice(i,1)});
      const syncType=()=>{
        const isAsset=type.value==='fixed_asset';
        assetBox.hidden=!isAsset;
        life.required=isAsset;
        if(product.value){type.value='inventory';assetBox.hidden=true;life.required=false}
      };
      product.addEventListener('change',()=>{const p=products.find(x=>String(x.id)===String(product.value));if(p){name.value=p.name||p.trade_name||'';const cp=p.cost_price??p.purchase_price??p.unit_cost;if(cp!==undefined&&cp!==null)cost.value=cp}syncType()});
      type.addEventListener('change',()=>{if(type.value!=='inventory')product.value='';syncType()});
      syncType();
      row.append(typeLabel,productLabel,nameLabel,qtyLabel,costLabel,assetBox,remove);lines.append(row);
      rows.push({row,type,product,name,qty,cost,cat,life,salvage});
    };
    addLine();f.append(lines);
    const add=action('إضافة بند آخر',addLine);f.append(add);
    const save=node('button','إنشاء أمر الشراء','action');save.type='submit';f.append(save);
    f.addEventListener('submit',async e=>{
      e.preventDefault();if(!supplier.value){message('اختر المورد');return}
      const items=rows.filter(x=>x.row.isConnected).map(x=>{
        const item={
          ...(x.product.value?{product_id:Number(x.product.value)}:{}),
          item_type:x.type.value,
          name:x.name.value.trim(),quantity:x.qty.value,unit_cost:x.cost.value
        };
        if(x.type.value==='fixed_asset'){
          item.asset_category=x.cat.value;
          item.useful_life_months=Number(x.life.value);
          item.salvage_value=x.salvage.value||'0';
        }
        return item;
      }).filter(x=>x.name&&Number(x.quantity)>0);
      if(!items.length){message('أضف بنداً واحداً على الأقل');return}
      save.disabled=true;try{await api('purchaseOrderCreate',{supplier_id:Number(supplier.value),items});message('تم إنشاء أمر الشراء كمسودة');await load('suppliers')}catch(err){message(err.message)}finally{save.disabled=false}
    });
    panel.append(f);
  }

  async function suppliers(){
    const [s,o,returns,productData,ops]=await Promise.all([
      api('suppliers'),api('purchaseOrders'),api('purchaseReturns'),
      api('sectorProducts').catch(()=>({products:[]})),
      api('overview').catch(()=>({open_shifts:[]}))
    ]);
    const supplierRows=s.suppliers||[],orders=o.orders||[],returnRows=returns.returns||[];
    const openShifts=(ops.open_shifts||[]).filter(x=>x.shift_type==='cashier');
    grid([['علينا للموردين',money(s.totals?.total_debt||0)],['لنا عند الموردين',money(s.totals?.total_credit||0)],['صافي المستحق',money(s.totals?.net_payable||0)],['عدد الموردين',s.totals?.suppliers_count??supplierRows.length],['أوامر شراء نشطة',s.totals?.active_po_count??0],['مرتجعات معلقة',returnRows.filter(x=>x.status==='pending').length]]);

    const p=box('الموردون');
    hint(p,'نُظهر ما علينا للمورد وما لنا عنده منفصلين. فائض المرتجع لا يضيع في ملاحظة؛ يصبح رصيداً لنا ويُستهلك تلقائياً في المشتريات التالية أو يُحصّل نقداً.');
    table(p,[['المورد',x=>x.name],['الهاتف',x=>x.phone||'—'],['علينا',x=>money(x.current_debt)],['لنا',x=>money(x.current_credit||0)],['الصافي',x=>money(Number(x.current_debt||0)-Number(x.current_credit||0))],['الإجراءات',x=>buttons([action('كشف الحساب',()=>showSupplier(x.id)),Number(x.current_debt||0)>0?action('سداد نقدي',()=>paySupplier(x,openShifts),false):null,Number(x.current_debt||0)>0?action('سداد أميال',()=>paySupplierWallet(x),false):null,Number(x.current_credit||0)>0?action('تحصيل من المورد',()=>collectSupplierCredit(x,openShifts),false):null])]],supplierRows);

    const create=box('إضافة مورد');
    form(create,[['name','اسم المورد'],['contact_person','مسؤول التواصل'],['phone','الهاتف','tel'],['email','البريد','email'],['address','العنوان'],['category','التصنيف'],['opening_balance','رصيد افتتاحي مستحق','number']],'حفظ المورد',d=>api('supplierCreate',d));

    const ordersPanel=box('أوامر الشراء');
    hint(ordersPanel,'المخزون لا يزيد عند إنشاء الأمر أو اعتماده؛ يزيد فقط عند تسجيل الاستلام الفعلي.');
    table(ordersPanel,[['الأمر',x=>x.po_number],['المورد',x=>x.supplier?.name||'—'],['الإجمالي',x=>money(x.total_amount)],['الحالة',x=>poStatus(x.status)],['التاريخ',x=>x.created_at||'—'],['الإجراء',x=>purchaseOrderActions(x,openShifts)]],orders);

    const createOrder=box('أمر شراء جديد');
    const products=productData.products||productData.items||productData.result?.products||[];
    purchaseOrderCreator(createOrder,supplierRows,Array.isArray(products)?products:[]);

    const returnsPanel=box('مرتجعات الشراء');
    hint(returnsPanel,'المرتجع لا يحرّك المخزون أو الأصل أو الدين إلا بعد الاعتماد. إذا أعاد المورد نقداً يمكنك تحديد درج POS الذي دخل إليه المبلغ؛ والنقد الخارجي لا يغيّر أي وردية.');
    table(returnsPanel,[['المرجع',x=>x.return_ulid],['المورد',x=>x.supplier?.name||'—'],['القيمة',x=>money(x.total_amount)],['التسوية',x=>x.settlement_type==='cash_refund'?'استرداد نقدي':'خصم من دين المورد'],['الحالة',x=>x.status==='pending'?'بانتظار الاعتماد':x.status==='approved'?'معتمد':'مرفوض'],['الإجراء',returnActions]],returnRows);
  }

  const expenseLabels={rent:'إيجار',salary:'رواتب',utilities:'كهرباء ومياه',supplies:'مستلزمات',transport:'نقل',other:'أخرى'};
  async function editExpense(row){
    const title=await amialPrompt('تعديل المصروف','البيان',{value:row.title||'',required:true,confirmLabel:'متابعة'});if(title===null)return;
    const amount=await amialPrompt('تعديل المصروف','المبلغ',{type:'number',min:0.01,step:'0.01',value:String(row.amount||''),required:true,confirmLabel:'حفظ'});if(amount===null||!(Number(amount)>0))return;
    await api('expenseUpdate',{title:title.trim(),amount,category:row.category||'other',spent_on:row.spent_on,note:row.note||''},dataUrl('expenseUpdate',row.id));
    message('تم تعديل المصروف');await load('expenses');
  }
  async function expenses(){
    const [data,ops]=await Promise.all([
      api('expenses'),
      api('overview').catch(()=>({open_shifts:[]}))
    ]);
    const rows=data.expenses||[];
    const openShifts=(ops.open_shifts||[]).filter(x=>x.shift_type==='cashier');
    grid([['إجمالي المصروفات النشطة',money(data.total||0)],['عدد القيود',data.count??rows.length],...Object.entries(data.by_category||{}).slice(0,2).map(([k,v])=>[expenseLabels[k]||k,money(v)])]);
    const p=box('سجل المصروفات');
    hint(p,'المصروف التشغيلي لا يُحذف من التاريخ. التصحيح يُنشئ عكساً مستقلاً بتاريخ الإلغاء. وإذا خرج النقد من درج POS اختر الوردية نفسها حتى يظل إغلاق الصندوق صحيحاً.');
    table(p,[['التاريخ',x=>x.spent_on],['البيان',x=>x.title],['الفئة',x=>expenseLabels[x.category]||x.category],['المبلغ',x=>money(x.amount)],['المصدر',x=>x.payment_source==='cash_shift'?'درج وردية #'+x.cashier_shift_id:'نقد خارجي'],['ملاحظة',x=>x.note||'—'],['الإجراء',x=>buttons([
      action('تعديل الوصف',()=>editExpense(x)),
      action('إلغاء القيد',async()=>{
        const reason=await amialPrompt('إلغاء قيد المصروف','سبب الإلغاء',{multiline:true,required:true,minLength:5,note:'لن يُحذف المصروف من التاريخ؛ سيُسجل قيد عكسي.',confirmLabel:'متابعة'});
        if(reason===null)return;
        if(!await amialConfirm('تأكيد القيد العكسي','سيُسجل عكس محاسبي للمصروف ولن يُحذف أثره التاريخي.',{confirmLabel:'تسجيل الإلغاء',danger:true}))return;
        await api('expenseDelete',{reason:reason.trim()},dataUrl('expenseDelete',x.id),'DELETE');
        message('تم إلغاء المصروف بقيد عكسي');await load('expenses')
      })
    ])]],rows);
    const create=box('تسجيل مصروف');
    const sourceOptions=[
      {value:'',label:'نقد خارجي — لا يغيّر أي درج POS'},
      ...openShifts.map(s=>({value:String(s.id),label:'من درج وردية #'+s.id+(s.branch_name?' — '+s.branch_name:'')+(s.opened_by_name?' — '+s.opened_by_name:'')}))
    ];
    form(create,[['title','البيان'],['amount','المبلغ','number'],['category','الفئة','select',Object.entries(expenseLabels).map(([value,label])=>({value,label}))],['spent_on','التاريخ','date'],['cashier_shift_id','مصدر النقد','select',sourceOptions],['note','ملاحظة']],'تسجيل المصروف',d=>api('expenseCreate',d));
  }

  const assetCategoryLabels={furniture:'أثاث',equipment:'معدات',computer:'أجهزة وتقنية',vehicle:'مركبات',machinery:'آلات',fixtures:'تجهيزات',building_improvement:'تحسينات مبانٍ',other:'أخرى'};
  async function assetDetails(id){
    const data=await api('assetShow',undefined,dataUrl('assetShow',id)),a=data.asset||{};
    const p=box('تفاصيل الأصل — '+(a.name||''));
    hint(p,'سجل الإهلاك تاريخي ولا يعاد حساب الأشهر السابقة عند فتح الشاشة.');
    const g=node('div',null,'grid');
    g.append(metric('تكلفة الاقتناء',money(a.acquisition_cost)),metric('الإهلاك المتراكم',money(a.accumulated_depreciation)),metric('القيمة الدفترية',money(a.book_value)),metric('العمر الإنتاجي',String(a.useful_life_months||0)+' شهر'));
    p.append(g);
    table(p,[['الشهر',x=>x.period],['إهلاك الشهر',x=>money(x.amount)],['المتراكم',x=>money(x.accumulated_after)],['القيمة بعد الإهلاك',x=>money(x.book_value_after)]],data.depreciations||[]);
    if((data.adjustments||[]).length)table(p,[['التاريخ',x=>x.effective_on],['التعديل',x=>x.type==='supplier_return'?'رد للمورد':x.type],['الكمية',x=>x.quantity],['خفض التكلفة',x=>money(x.cost_amount)],['عكس الإهلاك',x=>money(x.depreciation_reversed)]],data.adjustments||[]);
    p.scrollIntoView({behavior:'smooth',block:'start'});
  }
  async function disposeAsset(row,openShifts){
    const date=await amialPrompt('استبعاد الأصل','تاريخ الاستبعاد',{type:'date',value:new Date().toISOString().slice(0,10),required:true,confirmLabel:'متابعة'});if(date===null)return;
    const reason=await amialPrompt('استبعاد الأصل','سبب الاستبعاد أو البيع',{multiline:true,required:true,minLength:5,confirmLabel:'متابعة'});if(reason===null)return;
    const proceeds=await amialPrompt('استبعاد الأصل','متحصلات البيع إن وجدت',{type:'number',min:0,step:'0.01',value:'',note:'اتركه فارغاً عند الإتلاف دون بيع.',confirmLabel:'متابعة'});if(proceeds===null)return;
    let shiftId=null;
    if(proceeds.trim()!==''&&Number(proceeds)>0){
      shiftId=await chooseCashierShift(openShifts,'استلام متحصلات بيع الأصل');
      if(shiftId===undefined)return;
    }
    await api('assetDispose',{
      disposed_on:date,reason:reason.trim(),
      ...(proceeds.trim()!==''?{disposal_proceeds:proceeds}:{}),
      ...(shiftId?{cashier_shift_id:shiftId}:{})
    },dataUrl('assetDispose',row.id));
    message('تم استبعاد الأصل وتجميد الإهلاك');await load('assets');
  }
  async function assets(){
    const [data,ops]=await Promise.all([
      api('assets'),
      api('overview').catch(()=>({open_shifts:[]}))
    ]);
    const rows=data.assets||[],t=data.totals||{};
    const openShifts=(ops.open_shifts||[]).filter(x=>x.shift_type==='cashier');
    grid([['تكلفة الأصول',money(t.acquisition_cost)],['الإهلاك المتراكم',money(t.accumulated_depreciation)],['القيمة الدفترية',money(t.book_value)],['أصول نشطة',t.active_count??0]]);
    const p=box('سجل الأصول الثابتة');
    hint(p,'الأثاث والمعدات والسيارات لا تُسجل كمصروف تشغيلي كامل عند الشراء. الأصل القادم من أمر شراء يُنشأ تلقائياً عند الاستلام ويُهلك على عمره الإنتاجي.');
    table(p,[['الأصل',x=>x.name],['الفئة',x=>assetCategoryLabels[x.category]||x.category],['التكلفة الجارية',x=>money(x.carrying_cost_basis)],['الإهلاك',x=>money(x.accumulated_depreciation)],['القيمة الدفترية',x=>money(x.book_value)],['الحالة',x=>x.status==='active'?'نشط':x.status==='returned_to_supplier'?'مردود للمورد':'مستبعد'],['المصدر',x=>x.source==='purchase_order'?'أمر شراء':'رصيد افتتاحي'],['الإجراء',x=>buttons([action('التفاصيل',()=>assetDetails(x.id)),x.status==='active'?action('استبعاد',()=>disposeAsset(x,openShifts)):null])]],rows);

    const post=box('إثبات الإهلاك');
    const prev=new Date();prev.setUTCDate(1);prev.setUTCMonth(prev.getUTCMonth()-1);const m=prev.toISOString().slice(0,7);
    hint(post,'الإهلاك الدوري يُثبت للشهور المغلقة فقط. الشهر الجاري لا يُرحّل قبل إغلاقه، وإعادة الطلب للشهر نفسه لا تكرر القيد.');
    const monthLabel=node('label','حتى شهر مغلق','field'),month=node('input');month.type='month';month.value=m;month.max=m;monthLabel.append(month);
    const postBtn=node('button','إثبات الإهلاك','action');postBtn.type='button';postBtn.onclick=async()=>{postBtn.disabled=true;try{const r=await api('assetDepreciation',{through:month.value});message('تم إثبات '+r.entries_posted+' قيد إهلاك بقيمة '+money(r.amount_posted));await load('assets')}catch(e){message(e.message)}finally{postBtn.disabled=false}};
    post.append(monthLabel,postBtn);

    const create=box('تسجيل أصل افتتاحي');
    hint(create,'للأصول الموجودة قبل تشغيل النظام فقط. لا ينشئ هذا التسجيل شراءً ولا يخصم نقداً أو محفظة.');
    form(create,[['name','اسم الأصل'],['category','الفئة','select',Object.entries(assetCategoryLabels).map(([value,label])=>({value,label}))],['quantity','الكمية','number'],['acquisition_cost','تكلفة الاقتناء','number'],['salvage_value','القيمة المتبقية','number'],['useful_life_months','العمر الإنتاجي بالأشهر','number'],['acquired_on','تاريخ الاقتناء','date'],['depreciation_starts_on','بدء الإهلاك','date']],'تسجيل الأصل',d=>api('assetOpening',d));
  }

  async function devices(){
    const [data,branchData]=await Promise.all([api('devices'),api('branches').catch(()=>({branches:[]}))]);
    const rows=data.devices||[],branches=branchData.branches||[],active=rows.filter(x=>x.is_active).length,live=rows.reduce((s,x)=>s+Number(x.live_sessions||0),0);
    dashboardKpis([
      ['المقاعد المستخدمة',String(data.used??active),'▣',data.unlimited?'دون حد':('من '+(data.max??'—'))],
      ['الأجهزة النشطة',String(active),'✓','أجهزة صالحة للتشغيل'],
      ['الجلسات المفتوحة',String(live),'◉','جلسات نقطة بيع حالية','gold'],
      ['أجهزة غير نشطة',String(rows.length-active),'○','تبقى محفوظة للتدقيق']
    ]);
    const p=box('أجهزة نقاط البيع');
    hint(p,'الجهاز أصل للمنشأة وليس موظفاً. نقل الجهاز بين الفروع يقطع جلساته الحالية فوراً، والإلغاء لا يحذف تاريخه المالي.');
    const deviceTools=node('div',null,'staff-toolbar compact');deviceTools.append(node('div'),buttons([action('+ تفعيل جهاز جديد',createDeviceActivation,false)]));p.append(deviceTools);
    table(p,[
      ['الجهاز',x=>x.display_name],['الفرع',x=>x.branch_name||'المنشأة الرئيسية'],
      ['آخر ظهور',x=>x.last_seen_at?new Date(x.last_seen_at).toLocaleString('ar-YE'):'—'],['الجلسات',x=>x.live_sessions??0],
      ['البصمة',x=>x.fingerprint_suffix?('•••• '+x.fingerprint_suffix):'—'],
      ['الحالة',x=>{const s=node('span',x.is_active?'نشط':'غير نشط','staff-status '+(x.is_active?'on':'off'));return s}],
      ['الإجراء',x=>{
        if(!x.is_active)return 'محفوظ للتدقيق';
        const edit=action('تعديل',()=>editDevice(x,branches));
        const revoke=action('إلغاء الجهاز',async()=>{
          if(!await amialConfirm('إلغاء جهاز نقطة البيع','الجهاز: «'+x.display_name+'». سيتم إنهاء جلساته وإخلاء مقعد POS مع بقاء سجله التاريخي.',{confirmLabel:'إلغاء الجهاز',danger:true}))return;
          revoke.disabled=true;
          try{
            await api('deviceDestroy',{},routes.deviceDestroy.replace('__ID__',String(x.id)),'DELETE');
            message('تم إلغاء الجهاز وإخلاء المقعد');await load('devices');
          }catch(e){message(e.message);revoke.disabled=false}
        });
        return buttons([edit,revoke])
      }]
    ],rows);

    async function editDevice(row,branchRows){
      const modal=openMerchantModal(
        'تعديل جهاز POS · '+row.display_name,
        'تغيير الفرع ينهي الجلسات المفتوحة على الجهاز حتى لا تبقى عهدة الفرع السابق فعالة.'
      );
      const frm=node('form',null,'editor merchant-modal-form'),nameLabel=node('label','اسم الجهاز','field'),name=node('input');
      name.value=row.display_name||'';name.maxLength=120;name.required=true;nameLabel.append(name);
      const branchLabel=node('label','الفرع','field'),branch=node('select');branch.append(new Option('المنشأة الرئيسية',''));
      branchRows.filter(x=>x.is_active).forEach(b=>branch.append(new Option(b.name+(b.city?' — '+b.city:''),String(b.id))));
      branch.value=row.branch_id===null||row.branch_id===undefined?'':String(row.branch_id);branchLabel.append(branch);
      const meta=node('div',null,'modal-summary');
      const a=node('div');a.append(node('span','الجلسات المفتوحة'),node('strong',String(row.live_sessions||0)));
      const b=node('div');b.append(node('span','آخر ظهور'),node('strong',row.last_seen_at?new Date(row.last_seen_at).toLocaleString('ar-YE'):'—'));
      meta.append(a,b);
      const actions=node('div',null,'merchant-modal-actions'),cancel=action('إلغاء',modal.close),save=action('حفظ الجهاز',()=>{},false);save.type='submit';
      actions.append(cancel,save);frm.append(nameLabel,branchLabel,meta,actions);modal.body.append(frm);
      frm.onsubmit=async ev=>{
        ev.preventDefault();save.disabled=true;
        try{
          const result=await api('deviceUpdate',{
            display_name:name.value.trim(),
            branch_id:branch.value===''?null:Number(branch.value)
          },routes.deviceUpdate.replace('__ID__',String(row.id)),'PATCH');
          const ended=Number(result.ended_sessions||0);modal.close();
          message(ended>0?'تم تحديث الجهاز وإنهاء '+ended+' جلسة قديمة.':'تم تحديث الجهاز.');
          await load('devices');
        }catch(err){message(err.message);save.disabled=false}
      };
    }

    function createDeviceActivation(){
      const modal=openMerchantModal(
        'تفعيل جهاز نقطة بيع جديد',
        'أنشئ رمزاً مؤقتاً لمرة واحدة ثم أدخله في تطبيق POS على الجهاز الفعلي.'
      );
      const frm=node('form',null,'editor merchant-modal-form'),nameLabel=node('label','اسم الجهاز','field'),name=node('input');
      name.required=true;name.maxLength=120;name.placeholder='مثال: كاشير 1';nameLabel.append(name);
      const branchLabel=node('label','الفرع','field'),branch=node('select');branch.append(new Option('المنشأة الرئيسية',''));
      branches.filter(x=>x.is_active).forEach(b=>branch.append(new Option(b.name+(b.city?' — '+b.city:''),String(b.id))));branchLabel.append(branch);
      const note=node('div','المقعد لا يُستهلك حتى يتم تفعيل الرمز على الجهاز الفعلي.','note');
      const actions=node('div',null,'merchant-modal-actions'),cancel=action('إلغاء',modal.close),save=action('إنشاء رمز التفعيل',()=>{},false);save.type='submit';
      actions.append(cancel,save);frm.append(nameLabel,branchLabel,note,actions);modal.body.append(frm);
      frm.onsubmit=async ev=>{
        ev.preventDefault();save.disabled=true;
        try{
          const result=await api('deviceActivation',{
            display_name:name.value.trim(),
            ...(branch.value!==''?{branch_id:Number(branch.value)}:{})
          });
          frm.replaceChildren();
          const secret=node('div',null,'activation-secret');
          secret.append(node('span','رمز التفعيل','activation-label'),node('strong',result.activation_code||'—','activation-code'));
          if(result.expires_at)secret.append(node('small','صالح حتى '+result.expires_at));
          const copy=action('نسخ الرمز',()=>navigator.clipboard.writeText(result.activation_code||'').then(()=>message('تم نسخ رمز التفعيل')));
          const done=action('إغلاق',async()=>{modal.close();await load('devices')},false);
          const aa=node('div',null,'merchant-modal-actions');aa.append(copy,done);modal.body.append(secret,aa);
          message('تم إنشاء رمز التفعيل');
        }catch(err){message(err.message);save.disabled=false}
      };
    }

  }
  async function reports(days=30){
    const dashUrl=new URL(routes.dashboardV2,window.location.href);dashUrl.searchParams.set('days',String(Math.min(30,Math.max(7,days))));
    const profitUrl=new URL(routes.profitReport,window.location.href);profitUrl.searchParams.set('days',String(Math.min(90,Math.max(1,days))));
    const [financial,dashboard,profitData,exportData]=await Promise.all([
      api('wallet'),
      api('dashboardV2',undefined,dashUrl.toString()),
      api('profitReport',undefined,profitUrl.toString()).catch(()=>null),
      api('reportExports').catch(()=>({reports:[]}))
    ]);
    const r=financial.report||{},sales=r.sales||{},methods=sales.by_payment_method||{},d=dashboard.dashboard||dashboard;
    const toolbar=box('نطاق التقرير');
    hint(toolbar,'الاتجاه أدناه من مبيعات القطاع الفعلية، بينما بطاقات طرق الدفع والمحفظة من تقرير اليوم المالي.');
    const periods=node('div',null,'buttons');
    [7,14,30].forEach(n=>{const b=action(n+' أيام',()=>{content.replaceChildren();reports(n).catch(e=>message(e.message))},n!==days);if(n===days)b.disabled=true;periods.append(b)});
    const goSales=action('فتح سجل المبيعات المفصل',()=>load('sales'),false);periods.append(goSales);toolbar.append(periods);

    dashboardKpis([
      ['مبيعات اليوم',money(sales.gross),'↗',(sales.count||0)+' عملية'],
      ['نقد اليوم',money(methods.cash),'▣','من طرق الدفع الفعلية'],
      ['أميال اليوم',money(methods.amial_pay),'◉','مدفوعات المحفظة','gold'],
      ['آجل اليوم',money(methods.credit),'◫','ليس رصيد محفظة','blue']
    ]);
    const charts=node('div',null,'dash-grid');
    charts.append(salesTrendCard(d.series||[],d.period_total,d.today_change_percent),paymentMixCard(methods));content.append(charts);

    if(profitData?.totals){
      const t=profitData.totals;
      const profit=box('الربحية · '+days+' يوم');
      hint(profit,'الربح الإجمالي يعتمد تكلفة البضاعة المحفوظة لحظة البيع. صافي الربح يخصم المصروفات التشغيلية المسجلة، ولا يعامل شراء الأصل الثابت كمصروف.');
      const pg=node('div',null,'kpi-grid');
      pg.append(
        kpiCard('الإيراد',money(t.revenue),'↗','الفترة المختارة'),
        kpiCard('الربح الإجمالي',money(t.gross_profit??t.profit),'◇','قبل مصروفات التشغيل','gold'),
        kpiCard('مصروفات التشغيل',t.operating_expenses===null||t.operating_expenses===undefined?'غير موزعة':money(t.operating_expenses),'◫','نقد + إهلاك','blue'),
        kpiCard('النتيجة النهائية',t.net_result===null||t.net_result===undefined?'غير متاحة':money(t.net_result),'◎','بعد المصروفات والاستبعادات',Number(t.net_result||0)<0?'red':'')
      );profit.append(pg);
      table(profit,[['المؤشر',x=>x.label],['القيمة',x=>x.value===null||x.value===undefined?'غير متاح':money(x.value)]],[
        {label:'تكلفة البضاعة',value:t.cost},{label:'مصروفات نقدية',value:t.cash_operating_expenses},
        {label:'إهلاك الأصول',value:t.depreciation_expense},{label:'ربح/خسارة استبعاد أصل',value:t.asset_disposal_gain_loss},
        {label:'صافي الربح التشغيلي',value:t.net_profit},{label:'النتيجة النهائية',value:t.net_result}
      ]);
    }else{
      const p=box('الربحية');
      hint(p,'محرك الربحية التفصيلي غير متاح لهذا القطاع أو الباقة حالياً؛ لا نستبدله برقم تقديري. المبيعات والطرق والحركة المالية أعلاه تظل من مصادرها الصحيحة.');
    }

    const movement=box('الحركة المالية اليومية');
    hint(movement,'يفصل هذا التقرير المبيعات عن التحصيلات وعن حركة المحفظة؛ التحويلات الشخصية والأرصدة الافتتاحية لا تتحول إلى مبيعات.');
    table(movement,[['الحركة',x=>x.label_ar],['نقدًا',x=>x.available?money(x.cash):'غير متاح'],['أميال',x=>x.available?money(x.amial_pay):'غير متاح'],['رصيد مورد',x=>x.available?money(x.supplier_credit):'غير متاح'],['آجل',x=>x.available?money(x.credit):'غير متاح']],r.movement?.rows||[]);

    const exports=box('تصدير التقرير');
    hint(exports,'التصدير يستخدم محرك التقارير الخلفي نفسه؛ الملف يُجهّز في Queue ثم يصبح قابلاً للتنزيل. لا يعاد حساب أرقام مختلفة عن التقارير الأصلية.');
    const exportButtons=node('div',null,'buttons');
    [
      ['CSV','csv'],
      ['PDF','pdf'],
      ['Excel متوافق','excel']
    ].forEach(([label,format])=>{
      const b=action('تصدير '+label,()=>requestMerchantReportExport(format,days,b));
      exportButtons.append(b);
    });
    exports.append(exportButtons);

    const recentExports=(exportData.reports||[]).filter(x=>x.report_type==='merchant_ledger').slice(0,10);
    table(exports,[
      ['التاريخ',x=>x.created_at?new Date(x.created_at).toLocaleString('ar-YE'):'—'],
      ['الصيغة',x=>x.format==='excel'?'Excel متوافق':String(x.format||'').toUpperCase()],
      ['الحالة',x=>({queued:'بانتظار التجهيز',processing:'جارٍ التجهيز',ready:'جاهز',failed:'فشل'}[x.status]||x.status||'—')],
      ['الصفوف',x=>x.row_count??'—'],
      ['التنزيل',x=>{
        if(x.status!=='ready')return '—';
        return action('تنزيل',()=>window.open(
          routes.reportExportDownload.replace('__ULID__',encodeURIComponent(x.export_ulid)),
          '_blank','noopener'
        ))
      }]
    ],recentExports);
    if(!recentExports.length)hint(exports,'لا توجد تصديرات سابقة لدفتر التاجر.');
  }

  function localDateString(date){
    const y=date.getFullYear(),m=String(date.getMonth()+1).padStart(2,'0'),d=String(date.getDate()).padStart(2,'0');
    return y+'-'+m+'-'+d
  }

  async function requestMerchantReportExport(format,days,button){
    button.disabled=true;
    const to=new Date(),from=new Date();from.setDate(from.getDate()-Math.max(0,Number(days||30)-1));
    try{
      const result=await api('reportExportRequest',{
        report_type:'merchant_ledger',
        format,
        from:localDateString(from),
        to:localDateString(to)
      });
      const ulid=result.export_ulid;
      if(!ulid)throw Error('لم يصل معرّف التصدير من الخادم');
      message('بدأ تجهيز التقرير. سأتابع حالته حتى يصبح جاهزاً.');
      await waitForReportExport(ulid,button);
    }catch(e){
      message(e.message||'تعذّر طلب التصدير');
      button.disabled=false;
    }
  }

  async function waitForReportExport(ulid,button){
    const started=Date.now();
    const statusUrl=routes.reportExportStatus.replace('__ULID__',encodeURIComponent(ulid));
    while(Date.now()-started<65000){
      await new Promise(resolve=>setTimeout(resolve,1800));
      const status=await api('reportExportStatus',undefined,statusUrl);
      if(status.status==='ready'&&status.is_ready){
        message('التقرير جاهز للتنزيل');
        window.open(routes.reportExportDownload.replace('__ULID__',encodeURIComponent(ulid)),'_blank','noopener');
        button.disabled=false;
        return
      }
      if(status.status==='failed'){
        button.disabled=false;
        throw Error(status.error||'فشل تجهيز التقرير')
      }
    }
    button.disabled=false;
    message('التقرير ما زال يُجهّز في الخلفية؛ سيظهر في قائمة التصديرات عند فتح التقارير مرة أخرى.');
  }
  async function documents(){
    const [receiptData,salesData,devicesData,printData]=await Promise.all([
      api('receipts'),
      api('salesV2').catch(()=>({rows:[],summary:{},pagination:{}})),
      api('devices').catch(()=>({devices:[]})),
      api('printMonitor').catch(()=>({profiles:[],jobs:[],stats:{total:0,completed:0,failed:0,failure_rate:0,queue_length:0}}))
    ]);
    const settings=receiptData.settings||{},rows=salesData.rows||[],devices=devicesData.devices||[],
      printStats=printData.stats||{},printerProfiles=printData.profiles||[],printJobs=printData.jobs||[];
    const autoPrint=settings.auto_print_receipts===true||settings.auto_print_receipts===1;

    dashboardKpis([
      ['مقاس الإيصال الحراري',(settings.paper_width||80)+' مم','▰','إعداد المنشأة الحالي'],
      ['الطباعة التلقائية',autoPrint?'مفعّلة':'متوقفة','◎','تطبق داخل تطبيق POS المؤهل',autoPrint?'gold':''],
      ['طابعات معروفة',String(printerProfiles.length),'▣','تُكتشف من نتائج الطباعة الفعلية','blue'],
      ['نسبة فشل الطباعة',(Number(printStats.failure_rate||0)).toFixed(2)+'%','!','آخر '+(printData.days||30)+' يوم',Number(printStats.failure_rate||0)>5?'red':''],
      ['فواتير حديثة',String(rows.length),'▧','PDF رسمي قابل لإعادة التنزيل']
    ]);

    const architecture=box('مركز المستندات والطباعة');
    hint(architecture,'المستند المالي يُنشأ من بيانات البيع المسجلة، وليس من لقطة شاشة. إعادة التنزيل أو الطباعة لا تنشئ بيعة ثانية ولا تغيّر المحفظة أو الدفتر.');
    const cards=node('div',null,'roles-grid');
    [
      ['PDF رسمي','فاتورة القطاع مع رقمها وQR للتحقق وإعادة التنزيل.','متاح'],
      ['طباعة المتصفح','معاينة PDF ثم الطباعة إلى طابعة المكتب أو طابعة النظام.','متاح على الويب'],
      ['طباعة حرارية 58/80 مم','Bluetooth / USB / شبكة عبر تطبيق POS وقدرات الجهاز.','من تطبيق نقطة البيع'],
      ['مشاركة','مشاركة ملف PDF مباشرة من المتصفح المدعوم أو تنزيله للمشاركة.','متاح'],
    ].forEach(([title,desc,state])=>{
      const card=node('article',null,'role-card'),head=node('div',null,'role-card-head');
      head.append(node('h4',title),node('span',state,'source-chip'));card.append(head,node('p',desc));cards.append(card)
    });
    architecture.append(cards);

    const recent=box('الفواتير الحديثة');
    hint(recent,'هذه نفس عمليات البيع في سجل القطاع؛ زر المستند لا يعيد حساب الإجمالي.');
    table(recent,[
      ['التاريخ',x=>x.occurred_at?new Date(x.occurred_at).toLocaleString('ar-YE'):'—'],
      ['الفاتورة',x=>x.document_number||x.reference],
      ['العميل',x=>x.customer_name||'—'],
      ['طريقة الدفع',x=>paymentLabel(x.payment_method)],
      ['الإجمالي',x=>money(x.amount)],
      ['المستند',x=>invoiceActions(String(x.detail_id??x.id),x)]
    ],rows.slice(0,12));
    if(!rows.length)recent.append(node('div','لا توجد مبيعات حديثة لها مستندات بعد.','dashboard-empty'));

    const monitor=box('مراقبة الطباعة الحرارية');
    hint(monitor,'هذه البيانات لا تتخيل أن الخادم طبع الورقة؛ تطبيق POS هو الذي يرسل فعلياً للطابعة، ثم يسجل النجاح أو سبب الفشل هنا. فشل الطباعة لا يلغي البيع.');

    const health=node('div',null,'kpi-grid');
    health.append(
      kpiCard('مهام الطباعة',String(printStats.total||0),'▰',(printStats.completed||0)+' ناجح'),
      kpiCard('فشل',String(printStats.failed||0),'!','يمكن تتبع السبب والجهاز',Number(printStats.failed||0)>0?'red':''),
      kpiCard('قيد التنفيذ',String(printStats.queue_length||0),'◷','queued / printing / retrying','gold'),
      kpiCard('أجهزة POS نشطة',String(devices.filter(x=>x.is_active).length),'▣','مصدر التنفيذ المحلي','blue')
    );
    monitor.append(health);

    if(printerProfiles.length){
      monitor.append(node('h3','الطابعات المكتشفة'));
      table(monitor,[
        ['الطابعة',x=>x.name],['الاتصال',x=>x.connection_type==='bluetooth'?'Bluetooth':x.connection_type==='network'?'شبكة':x.connection_type],
        ['النقطة',x=>x.endpoint_hint||'—'],['الورق',x=>x.paper_size||'—'],
        ['الجهاز',x=>x.device_name||'—'],['الفرع',x=>x.branch_name||'المنشأة الرئيسية'],
        ['الحالة',x=>x.status==='degraded'?'تحتاج فحص':x.status==='active'?'نشطة':x.status||'—'],
        ['آخر نجاح',x=>x.last_success_at?new Date(x.last_success_at).toLocaleString('ar-YE'):'—'],
        ['آخر فشل',x=>x.last_failure_at?new Date(x.last_failure_at).toLocaleString('ar-YE'):'—']
      ],printerProfiles);
    }else hint(monitor,'لم يصل بعد تقرير طباعة من أي جهاز POS. ستظهر الطابعة تلقائياً بعد أول محاولة طباعة من التطبيق.');

    if(printJobs.length){
      monitor.append(node('h3','آخر مهام الطباعة'));
      table(monitor,[
        ['الوقت',x=>x.created_at?new Date(x.created_at).toLocaleString('ar-YE'):'—'],
        ['المستند',x=>x.document_number||x.document_id||x.document_type],
        ['النوع',x=>x.document_type||'—'],['الطابعة',x=>x.printer_name||'—'],
        ['الموظف',x=>x.employee_name||'المالك'],['الجهاز',x=>x.device_name||'—'],
        ['الحالة',x=>x.status==='completed'?'نجاح':x.status==='failed'?'فشل':x.status],
        ['السبب',x=>x.error_code?x.error_code+' · '+(x.error_message||''):x.result_message||'—']
      ],printJobs.slice(0,20));
    }

    const setup=box('إعدادات الفاتورة والطباعة');
    hint(setup,'هوية الفاتورة مشتركة بين الويب ونقاط البيع. خيار الطباعة التلقائية لا يعني أن المتصفح يستطيع التحكم بطابعة Bluetooth؛ تنفذه طبقة الطباعة في الجهاز المؤهل.');
    form(setup,[
      ['store_name','اسم المنشأة'],['header_note','ترويسة الفاتورة'],['footer_note','تذييل الفاتورة'],
      ['phone','هاتف المنشأة'],['address','عنوان المنشأة'],
      ['paper_width','عرض الطابعة','select',[{value:'58',label:'58 مم'},{value:'80',label:'80 مم'}]],
      ['auto_print_receipts','طباعة الإيصال تلقائياً في POS','select',[{value:'0',label:'لا'},{value:'1',label:'نعم'}]]
    ],'حفظ إعدادات المستندات',d=>{
      if('auto_print_receipts' in d)d.auto_print_receipts=d.auto_print_receipts==='1';
      if('paper_width' in d)d.paper_width=Number(d.paper_width);
      return api('receiptsSave',d)
    });
    setup.querySelectorAll('input,select').forEach(input=>{
      if(input.name==='store_name')input.value=@json($storeName);
      else if(input.name==='auto_print_receipts')input.value=autoPrint?'1':'0';
      else if(settings[input.name]!==undefined&&settings[input.name]!==null)input.value=String(settings[input.name]);
    });

    const pos=box('الطباعة الحرارية والأجهزة');
    hint(pos,'الويب لا يتحكم في Bluetooth/USB مباشرة. اختبر الطابعة من تطبيق نقطة البيع؛ بعدها تظهر صحة الطابعة ونتيجة المهمة أعلاه من تقرير الجهاز نفسه.');
    const actions=node('div',null,'buttons');
    if(navigation.some(x=>x.tab==='devices'&&x.state==='available')){
      const d=action('إدارة أجهزة POS',()=>load('devices'));actions.append(d);
    }
    if(navigation.some(x=>x.tab==='posSetup'&&x.state==='available')){
      const p=action('إعداد نقطة بيع',()=>load('posSetup'));actions.append(p);
    }
    pos.append(actions);
  }

  async function integrations(){
    const hub=box('التكاملات والتطبيقات');
    hint(hub,'هذه الصفحة تعرض فقط التكاملات التي لها مسار فعلي في المشروع. «غير متاح» تعني أنه لا يوجد محرك خادم مكتمل بعد، لا أن الزر معطل.');
    const cards=node('div',null,'roles-grid');

    const card=(title,desc,state,tone='')=>{
      const x=node('article',null,'role-card'),head=node('div',null,'role-card-head');
      const chip=node('span',state,'source-chip');if(tone==='warn')chip.style.cssText='background:#fff5e4;color:#9a6918';
      head.append(node('h4',title),chip);x.append(head,node('p',desc));cards.append(x);return x
    };

    const share=card('مشاركة الفواتير','PDF الرسمي يمكن مشاركته من مركز المستندات عبر Web Share على الأجهزة المدعومة، مع تنزيل آمن كبديل.','متاح');
    const bShare=action('فتح المستندات والطباعة',()=>load('documents'));share.append(bShare);

    const print=card('الطباعة الحرارية','الطباعة عبر Bluetooth / USB / الشبكة تنفذ من تطبيق POS باستخدام قدرات الجهاز، وليست ادعاء طباعة مباشر من المتصفح.','متاح عبر POS');
    if(navigation.some(x=>x.tab==='devices'&&x.state==='available'))print.append(action('إدارة أجهزة POS',()=>load('devices')));

    let apiData=null,apiLocked=null;
    try{apiData=await api('integrationApiKeys')}catch(e){apiLocked=e.message}
    const apiCard=card('واجهة الشركاء API','وصول خارجي مقيد بمفتاح تاجر. المفتاح الكامل يظهر مرة واحدة فقط ولا يُخزّن كنص قابل للاسترجاع.',apiData?'متاح':'حسب الباقة',apiData?'':'warn');
    const endpoint=node('code','GET /api/v1/amial/partner/sales');endpoint.style.cssText='display:block;direction:ltr;text-align:left;background:#f4f7f6;padding:9px;border-radius:8px;margin:9px 0;font-size:11px';apiCard.append(endpoint);
    if(apiLocked)apiCard.append(node('p',apiLocked,'muted'));

    const backup=card('نسخة احتياطية قطاعية','تنزيل JSON من مصدر قطاعك الحقيقي: منتجات ومبيعات القطاع، ومعها دفاتر الآجل والبيانات المشتركة. قد يتضمن بيانات عملاء حساسة.','حسب الباقة');
    const backupButton=action('إنشاء وتنزيل النسخة',async()=>{
      if(!await amialConfirm('تنزيل نسخة احتياطية','قد تحتوي النسخة على بيانات عملاء وذمم ومبيعات حساسة وستُنزل إلى هذا الجهاز.',{confirmLabel:'تنزيل النسخة',danger:true}))return;
      window.open(routes.backupDownload,'_blank','noopener');
    });backup.append(backupButton);

    const webhooks=card('Webhooks للتاجر','لا يوجد حالياً عقد Webhook عام للتاجر في الخادم. لن نعرض عنواناً أو Secret غير موجودين.','غير متاح حالياً','warn');
    webhooks.append(node('p','عند بنائه يجب أن يشمل توقيعاً، Idempotency، حماية Replay وسجل تسليم.','muted'));

    hub.append(cards);

    if(!apiData)return;

    const keys=box('مفاتيح API');
    hint(keys,'المفاتيح المقنّعة أدناه لا يمكن تحويلها إلى السر الكامل. عند فقد المفتاح أنشئ واحداً جديداً ثم عطّل القديم.');
    table(keys,[
      ['الوصف',x=>x.label],['المفتاح',x=>x.masked],['الحالة',x=>x.is_active?'نشط':'موقوف'],
      ['آخر استخدام',x=>x.last_used_at?new Date(x.last_used_at).toLocaleString('ar-YE'):'لم يُستخدم'],
      ['تاريخ الإنشاء',x=>x.created_at?new Date(x.created_at).toLocaleDateString('ar-YE'):'—'],
      ['الإجراء',x=>buttons([
        action(x.is_active?'تعطيل':'تفعيل',async()=>{
          try{
            await api('integrationApiKeyToggle',{},routes.integrationApiKeyToggle.replace('__ID__',String(x.id)));
            message('تم تحديث حالة المفتاح');await load('integrations');
          }catch(e){message(e.message)}
        }),
        action('حذف',async()=>{
          if(!await amialConfirm('حذف مفتاح التكامل','سيُحذف المفتاح نهائياً وأي تكامل يستخدمه سيتوقف فوراً.',{confirmLabel:'حذف المفتاح',danger:true}))return;
          try{
            await api('integrationApiKeyDelete',{},routes.integrationApiKeyDelete.replace('__ID__',String(x.id)),'DELETE');
            message('تم حذف المفتاح');await load('integrations');
          }catch(e){message(e.message)}
        })
      ])]
    ],apiData.keys||[]);

    const create=box('إنشاء مفتاح API جديد');
    hint(create,'بعد الإنشاء سيظهر السر الكامل مرة واحدة فقط. انسخه إلى النظام الخارجي ولا ترسله في رسائل أو صور.');
    const frm=node('form',null,'editor'),labelHolder=node('label','وصف المفتاح','field'),label=node('input');
    label.maxLength=60;label.placeholder='مثال: نظام المحاسبة';labelHolder.append(label);
    const save=action('توليد المفتاح');save.type='submit';frm.append(labelHolder,save);create.append(frm);
    frm.onsubmit=async ev=>{
      ev.preventDefault();save.disabled=true;
      try{
        const result=await api('integrationApiKeysCreate',{label:label.value.trim()||null});
        const secret=result.api_key;
        frm.replaceChildren();
        const notice=node('div',null,'note');
        notice.append(node('strong','احفظ المفتاح الآن — لن يظهر مجدداً'));
        const code=node('code',secret);code.style.cssText='display:block;direction:ltr;text-align:left;word-break:break-all;background:#fff;padding:11px;border-radius:8px;margin:9px 0';
        const copy=action('نسخ المفتاح');copy.type='button';copy.onclick=()=>navigator.clipboard.writeText(secret).then(()=>message('تم نسخ المفتاح'));
        notice.append(code,copy);create.append(notice);
      }catch(e){message(e.message);save.disabled=false}
    };
  }

  async function settings(){
    const data=await api('receipts'),s=data.settings||{};
    const hub=box('إعدادات المنشأة');
    hint(hub,'الإعدادات موزعة بحسب مصدرها الفعلي. لا نكرر إعداد الموظفين أو الأجهزة داخل نموذج آخر؛ افتح القسم المختص لتبقى الحقيقة في مكان واحد.');
    const cards=node('div',null,'roles-grid');
    [
      ['هوية الفاتورة','اسم المنشأة، الترويسة، التذييل وعرض الورق','receipt',null],
      ['الموظفون والصلاحيات','الأدوار وحسابات الدخول والموافقات','staff','staff'],
      ['نقاط البيع والأجهزة','تفعيل الجهاز وربطه بالتشغيل','devices','devices'],
      ['الفروع','مواقع التشغيل ونطاق الموظفين','branches','branches'],
      ['الباقة والمميزات','الحدود والمزايا المتاحة للقطاع','plans','plans'],
    ].forEach(([title,desc,icon,tab])=>{
      if(tab&&!navigation.some(x=>x.tab===tab&&x.state==='available'))return;
      const card=node('article',null,'role-card'),head=node('div',null,'role-card-head');
      head.append(node('h4',title),node('span',icon==='receipt'?'الحالي':'فتح','source-chip'));card.append(head,node('p',desc));
      if(tab){const b=action('إدارة '+title,()=>load(tab));card.append(b)}
      cards.append(card)
    });
    hub.append(cards);

    const p=box('هوية الفاتورة والطباعة');
    hint(p,'هذه الإعدادات موحدة بين الويب وكل نقاط البيع. الفاتورة نفسها تبقى قابلة للطباعة وPDF بحسب مسار البيع والقطاع.');
    form(p,[['store_name','اسم المنشأة'],['header_note','ترويسة الفاتورة'],['footer_note','تذييل الفاتورة'],['phone','هاتف المنشأة'],['address','عنوان المنشأة'],['paper_width','عرض الطابعة','select',[{value:'58',label:'58 مم'},{value:'80',label:'80 مم'}]]], 'حفظ إعدادات الفاتورة',d=>api('receiptsSave',d));
    p.querySelectorAll('input,select').forEach(input=>{if(s[input.name]!==undefined&&s[input.name]!==null)input.value=s[input.name];if(input.name==='store_name')input.value=@json($storeName)});
  }
  const pages={overview,sector,sales,returns,customers,wallet,debts,products,suppliers,expenses,assets,branches,posSetup,staff,devices,reports,documents,integrations,settings,plans};
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
