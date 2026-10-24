Warning: truncated output (original token count: 64620)
Total output lines: 3279

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function(){
  'use strict';
  const routes = {{ \Illuminate\Support\Js::from($merchantRoutes) }};
  const csrf = @json(csrf_token());
  const actualSector = @json($businessTypeCode);
  const actualSectorName = @json($businessType);
  const navigation = @json($portalNavigation);
  const titles=Object.fromEntries(navigation.map(item=>[item.tab,item.label]));
  const navigationSymbols={dashboard_customize:'⌁',insights:'⌂',account_balance_wallet:'◉',inventory_2:'▤',payments:'◫',account_tree:'⌘',groups:'♙',people:'♟',point_of_sale:'▣',analytics:'▥',receipt_long:'▧',undo:'↶',print:'▰',extension:'✚',auto_awesome:'✧',storefront:'⌂',local_shipping:'▦',shopping_cart:'▨',business_center:'▣'};
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
      const icon=node('span',navigationSymbols[item.icon]||'•','nav-icon'),label=node('span',item.label);
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
  function openMerchantCommandCenter(){
    let layer=document.getElementById('merchant-command-layer');
    if(layer){
      layer.hidden=false;
      document.body.classList.add('merchant-command-open');
      document.getElementById('merchant-command-trigger')?.setAttribute('aria-expanded','true');
      layer.querySelector('input')?.focus();
      return;
    }
    const trigger=document.getElementById('merchant-command-trigger');
    layer=node('div',null,'merchant-command-layer');
    layer.id='merchant-command-layer';
    const dialog=node('section',null,'merchant-command');
    dialog.id='merchant-command-center';
    dialog.setAttribute('role','dialog');
    dialog.setAttribute('aria-modal','true');
    dialog.setAttribute('aria-labelledby','merchant-command-title');
    const head=node('div',null,'merchant-command-head');
    const title=node('h2','ابحث أو انتقل إلى مساحة عمل','');title.id='merchant-command-title';
    const close=node('button','×','merchant-command-close');close.type='button';close.setAttribute('aria-label','إغلاق البحث والتنقل السريع');
    head.append(title,close);
    const input=node('input');input.type='search';input.placeholder='اكتب اسم المساحة، مثل: مبيعات أو محفظة أو موردون';input.autocomplete='off';input.setAttribute('aria-label','البحث في مساحات عمل المنشأة');
    const help=node('p','تظهر فقط المساحات المتاحة لمنشأتك وباقتك. اضغط Esc للإغلاق.','merchant-command-help');
    const results=node('div',null,'merchant-command-results');
    const groups={
      overview:'نظرة عامة',sector:'التشغيل والمبيعات',sales:'التشغيل والمبيعات',returns:'التشغيل والمبيعات',products:'التشغيل والمبيعات',customers:'التشغيل والمبيعات',debts:'التشغيل والمبيعات',suppliers:'التشغيل والمبيعات',expenses:'التشغيل والمبيعات',assets:'التشغيل والمبيعات',
      branches:'الفريق ونقاط البيع',posSetup:'الفريق ونقاط البيع',staff:'الفريق ونقاط البيع',devices:'الفريق ونقاط البيع',
      wallet:'المالية والتقارير',reports:'المالية والتقارير',documents:'المالية والتقارير',settings:'إعدادات المنشأة',integrations:'إعدادات المنشأة',plans:'إعدادات المنشأة'
    };
    const available=()=>navigation.filter(item=>item.state==='available');
    const render=(query='')=>{
      const q=query.trim().toLocaleLowerCase('ar');
      const rows=available().filter(item=>!q||item.label.toLocaleLowerCase('ar').includes(q)||(groups[item.tab]||'').toLocaleLowerCase('ar').includes(q));
      results.replaceChildren();
      if(!rows.length){results.append(node('p','لا توجد مساحة متاحة تطابق بحثك.','merchant-command-empty'));return}
      rows.forEach(item=>{
        const button=node('button',null,'merchant-command-item');button.type='button';
        const copy=node('span');copy.append(node('strong',item.label),node('small',groups[item.tab]||'مساحة عمل'));
        button.append(copy,node('span','فتح ←','merchant-command-open'));
        button.addEventListener('click',()=>{closeMerchantCommandCenter();load(item.tab)});results.append(button);
      });
    };
    close.addEventListener('click',()=>closeMerchantCommandCenter());
    layer.addEventListener('click',event=>{if(event.target===layer)closeMerchantCommandCenter()});
    input.addEventListener('input',()=>render(input.value));
    input.addEventListener('keydown',event=>{if(event.key==='Enter'){const first=results.querySelector('button');if(first){event.preventDefault();first.click()}}});
    dialog.append(head,input,help,results);layer.append(dialog);document.body.append(layer);render();
    document.body.classList.add('merchant-command-open');
    trigger?.setAttribute('aria-expanded','true');
    requestAnimationFrame(()=>input.focus());
    layer._merchantCommandTrigger=trigger;
  }
  function closeMerchantCommandCenter(){
    const layer=document.getElementById('merchant-command-layer');
    if(!layer||layer.hidden)return false;
    layer.hidden=true;
    document.body.classList.remove('merchant-command-open');
    document.getElementById('merchant-command-trigger')?.setAttribute('aria-expanded','false');
    layer._merchantCommandTrigger?.focus();
    return true;
  }
  document.getElementById('merchant-command-trigger')?.addEventListener('click',openMerchantCommandCenter);
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
    const defs=actualSector==='quick_sale'?[['cash','نقد','cash'],['amial_pay','أميال باي','wallet']]:[['cash','نقد','cash'],['amial_pay','أميال باي','wallet'],['credit','آجل / حساب','credit'],['other','أخرى','other']];
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
    const available=new Set(navigation.filter(item=>item.state==='available').map(item=>item.tab));
    const add=(icon,title,detail,tone='',tab=null)=>{
      const actionable=tab!==null&&available.has(tab),r=node(actionable?'button':'div','attention-item'+(tone?' '+tone:'')+(actionable?' attention-action':''));
      if(actionable){r.type='button';r.setAttribute('aria-label','فتح '+(titles[tab]||title));r.addEventListener('click',()=>load(tab))}
      r.append(node('span',icon,'att-icon'));const x=node('div');x.append(node('strong',title),node('small',detail));r.append(x);
      if(actionable)r.append(node('span','فتح','attention-open'));
      list.append(r)
    };
    if(actualSector!=='quick_sale'&&receivable>0)add('◫','ذمم عملاء قائمة',money(receivable)+' ما زالت مستحقة على العملاء.','warn','debts');
    if(open>0)add('◷','ورديات مفتوحة',open+' وردية لم تُقفل بعد؛ راقب جرد الصندوق وتسليم النقد.','warn','sector');
    if(devices>0&&live<devices)add('▣','أجهزة غير متصلة',(devices-live)+' جهاز مرخص لا يملك جلسة نشطة الآن.','','devices');
    if((actualSector==='quick_sale'||receivable===0)&&open===0&&(devices===0||live===devices))add('✓','التشغيل مستقر',actualSector==='quick_sale'?'لا توجد ورديات أو أجهزة تحتاج إجراءً فوريًا.':'لا توجد ذمم أو ورديات مفتوحة تحتاج إجراءً فوريًا.');
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
    hero.append(node('div','لوحة القيادة اليومية','hero-kicker'),node('h2',actualSector==='quick_sale'?'مبيعاتك وتحصيلك اليوم':'صورة واحدة لحالة منشأتك الآن'));
    hero.append(node('p',actualSector==='quick_sale'
      ?'البيع السريع يعمل بمبلغ مباشر: النقد يبقى في درج الوردية، ومدفوعات أميال تصل إلى محفظة المنشأة، وكل عملية لها فاتورة.'
      :'المبيعات، طرق الدفع، المحفظة، الذمم ونقاط البيع معروضة كلٌّ من مصدره الحقيقي؛ لا نخلط النقد في الدرج برصيد أميال أو بالدين الآجل.'));
    const actions=node('div',null,'hero-actions'),available=new Map(navigation.map(x=>[x.tab,x.state==='available']));
    [['sales','فتح المبيعات',true],['products','المنتجات والمخزون',false],['posSetup','إعداد نقطة بيع',false],['reports','التقارير',false]].forEach(([tab,label,primary])=>{
      if(!available.get(tab))return;const b=node('button',label,primary?'primary':'');b.type='button';b.onclick=()=>load(tab);actions.append(b)
    });
    hero.append(actions);return hero
  }

  // دليلُ مساحات العمل لا ينشئ صلاحيةً أو مساراً: هو مجرد نافذة منظمة
  // فوق القائمة التي جهزها الخادم من الاستحقاقات الفعلية للمنشأة.
  function workspaceDirectoryCard(){
    const groups=[
      ['البيع والتشغيل',['sector','sales','returns','customers','debts']],
      ['الأصناف والتوريد',['products','suppliers','expenses','assets']],
      ['الفريق ونقاط البيع',['branches','posSetup','staff','devices']],
      ['المالية والرقابة',['wallet','reports','documents']],
      ['إدارة المنشأة',['settings','integrations','plans']],
    ];
    const byTab=new Map(navigation.filter(item=>item.state==='available').map(item=>[item.tab,item]));
    const card=node('section',null,'workspace-directory');
    const head=node('div',null,'workspace-directory-head'),copy=node('div');
    copy.append(node('h3','مساحات العمل',''),node('p','انتقل مباشرة إلى الأدوات المتاحة لمنشأتك. تظهر المساحات المسموح بها فقط.',''));
    head.append(copy,node('span','حسب الباقة والصلاحية','workspace-directory-note'));card.append(head);
    const grid=node('div',null,'workspace-directory-grid');
    groups.forEach(([group,tabs])=>{
      const items=tabs.map(tab=>byTab.get(tab)).filter(Boolean);
      if(!items.length)return;
      const column=node('section',null,'workspace-group');
      column.append(node('h4',group));
      const links=node('div',null,'workspace-links');
      items.forEach(item=>{
        const button=node('button',null,'workspace-link');button.type='button';button.dataset.tab=item.tab;
        button.setAttribute('aria-label','فتح '+item.label);
        button.append(node('span',navigationSymbols[item.icon]||'•','workspace-link-icon'),node('span',item.label));
        button.addEventListener('click',()=>load(item.tab));links.append(button);
      });
      column.append(links);grid.append(column);
    });
    if(!grid.childElementCount)return null;
    card.append(grid);return card;
  }

  function operationalReadinessCard(setup={},counts={}){
    const available=new Map(navigation.map(item=>[item.tab,item.state==='available']));
    const steps=[];
    if(available.get('branches'))steps.push(['فرع أو موقع تشغيل',Boolean(setup.has_branch),'branches','أضف الفرع الذي ستعمل منه نقاط البيع والفريق.']);
    if(available.get('staff')){
      steps.push(['دور تشغيلي',Boolean(setup.has_role),'staff','حدّد دوراً وصلاحياته قبل توزيع العمل على الموظفين.']);
      steps.push(['موظف نقطة بيع',Boolean(setup.has_employee),'staff','أنشئ موظفاً مستقلاً بدلاً من مشاركة حساب المالك.']);
    }
    if(available.get('devices'))steps.push(['جهاز نقطة بيع',Boolean(setup.has_device),'devices','فعّل جهازاً واربطه بالتشغيل قبل بدء الوردية.']);
    if(!steps.length)return null;

    const completed=steps.filter(([,done])=>done).length;
    const card=node('section',null,'readiness-card');
    const head=node('div',null,'readiness-head'),copy=node('div');
    copy.append(node('h3','تجهيز التشغيل',''),node('p','هذه قائمة تجهيز تشغيلية فقط؛ لا تمثل اعتماداً مالياً أو رصيداً.',''));
    head.append(copy,node('span',completed+' من '+steps.length+' مكتمل','readiness-count'));card.append(head);
    const list=node('div',null,'readiness-list');
    steps.forEach(([label,done,tab,detail])=>{
      const row=node('div',null,'readiness-row '+(done?'done':'pending'));
      const state=node('span',done?'✓':'○','readiness-state');
      const body=node('div',null,'readiness-copy');body.append(node('strong',label),node('small',done?'جاهز للتشغيل.':detail));
      row.append(state,body);
      if(!done){const go=node('button','إكمال','action secondary readiness-action');go.type='button';go.addEventListener('click',()=>load(tab));row.append(go)}
      list.append(row);
    });
    card.append(list);
    if(Number(counts.open_shifts||0)>0)card.append(node('p','لديك '+counts.open_shifts+' وردية مفتوحة حالياً؛ تابع الجرد والتسليم من مساحة التشغيل المرتبطة بها.','readiness-open-shifts'));
    return card;
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

    content.append(quickHero(d));
    const directory=workspaceDirectoryCard();
    if(directory)content.append(directory);
    const readiness=operationalReadinessCard(o.setup||{},counts);
    if(readiness)content.append(readiness);
    content.append(dashboardPeriodBar(days));
    const overviewKpis=[
      ['مبيعات اليوم',money(sales.gross),'↗',d.today_change_percent===null?'المقارنة تحتاج مبيعات أمس':((Number(d.today_change_percent)>=0?'+':'')+Number(d.today_change_percent).toFixed(1)+'% عن أمس')],
      ['عدد عمليات البيع',String(todayCount),'▧','متوسط الفاتورة '+money(todayAvg),'blue'],
      ['المبيعات النقدية',money(methods.cash),'▣','تبقى في درج الوردية حتى التسليم'],
      ['مدفوعات أميال',money(methods.amial_pay),'◉','تصل إلى محفظة المنشأة','gold']
    ];
    if(actualSector!=='quick_sale'){
      overviewKpis.push(
        ['المبيعات الآجلة',money(methods.credit),'◫','تُسجل في ذمم العملاء','blue'],
        ['إجمالي الذمم',money(r.receivables?.amount),'◌','لا يُعدّ نقدًا أو رصيد محفظة',Number(r.receivables?.amount||0)>0?'red':'']
      );
    }
    overviewKpis.push(
      ['رصيد المحفظة',money(r.wallet?.balance),'◎','محفظة أميال الإلكترونية فقط','gold'],
      ['مرتجعات اليوم',returnToday===null?'غير متاحة':money(returnToday),'↶',saleReturn?.available?(saleReturn.count+' مرتجع من '+saleReturn.source):'لا يملك هذا القطاع مصدر مرتجع','red'],
      ['متوسط '+(d.days||14)+' يوم',money(d.average_ticket),'◇',(d.period_count||0)+' عملية خلال الفترة']
    );
    dashboardKpis(overviewKpis);

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
      table(recent,[['الوق…44620 tokens truncated…e(p,[['الشهر',x=>x.period],['إهلاك الشهر',x=>money(x.amount)],['المتراكم',x=>money(x.accumulated_after)],['القيمة بعد الإهلاك',x=>money(x.book_value_after)]],data.depreciations||[]);
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
  document.addEventListener('keydown',e=>{
    if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){
      e.preventDefault();openMerchantCommandCenter();return;
    }
    if(e.key==='Escape'&&closeMerchantCommandCenter())return;
    if(e.key==='Escape'&&sidebar.classList.contains('open')){setMenu(false);menuToggle.focus()}
  });
  compactLayout.addEventListener('change',()=>setMenu(false));
  buildNavigation();
  load(active);
})();
</script>
