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
    
        /* ═══════════════════════════════════════════════════════════════
           AMIAL MERCHANT PORTAL V2 — design system
           The existing functional pages inherit this shell; no duplicate
           business logic is introduced for presentation.
           ═══════════════════════════════════════════════════════════════ */
        :root{
          --amial-ink:#102c25;--amial-muted:#6b7f78;--amial-line:#dde8e3;
          --amial-green:#116b50;--amial-green-2:#1c8c68;--amial-soft:#eef7f3;
          --amial-gold:#d6a947;--amial-bg:#f5f7f6;--amial-card:#fff;
          --amial-danger:#b94b3e;--amial-warning:#a66f16;
          --amial-shadow:0 14px 36px rgba(19,55,45,.07);
        }
        body{background:var(--amial-bg);color:var(--amial-ink)}
        .side{width:286px;background:linear-gradient(180deg,#0d2e26 0%,#123d32 58%,#0f332a 100%);padding:24px 16px;gap:5px;box-shadow:-8px 0 35px rgba(8,37,29,.12)}
        .brand{font-size:23px;letter-spacing:-.5px;margin-bottom:13px}.brand small{opacity:.7;font-weight:500}
        .store{border-color:#ffffff20;background:linear-gradient(135deg,#ffffff12,#ffffff07);padding:15px 16px;border-radius:16px;margin-bottom:15px}
        .store strong{font-size:16px}.store small{color:#c5ddd4}
        #portal-nav{display:block}
        .nav-section-title{padding:15px 12px 7px;color:#8eb4a7;font-size:10px;font-weight:800;letter-spacing:.3px}
        .nav{display:flex;align-items:center;gap:11px;min-height:45px;padding:11px 12px;border-radius:12px;font-weight:700;color:#cee0da;transition:background .16s ease,transform .16s ease,color .16s ease}
        .nav:hover{background:#ffffff0f;color:#fff;transform:translateX(-2px)}
        .nav.active{background:linear-gradient(135deg,#1b825f,#236f58);box-shadow:0 8px 18px #061c1638;color:#fff}
        .nav-icon{width:29px;height:29px;border-radius:9px;background:#ffffff0d;display:inline-flex;align-items:center;justify-content:center;font-size:15px;flex:none}
        .nav.active .nav-icon{background:#ffffff20}
        main{padding:26px clamp(18px,3vw,46px) 48px;max-width:1700px;margin:0 auto}
        .top{background:#ffffffd9;backdrop-filter:blur(12px);border:1px solid var(--amial-line);border-radius:18px;padding:16px 18px;box-shadow:0 8px 28px rgba(20,58,48,.04);margin-bottom:20px}
        .top h1{font-size:25px;letter-spacing:-.3px}.top .eyebrow{font-size:11px;color:var(--amial-green);font-weight:800;margin-bottom:4px}
        .top-tools{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.live-pill{display:inline-flex;align-items:center;gap:7px;border:1px solid #d8e8e1;background:#f8fbfa;border-radius:999px;padding:7px 11px;font-size:11px;color:#527168}
        .live-pill:before{content:"";width:7px;height:7px;border-radius:50%;background:#24a474;box-shadow:0 0 0 4px #24a47418}
        .badge{border-color:#d8e8e1;background:#f4faf7;color:#24624e;padding:7px 12px}
        .panel,.metric{border-color:var(--amial-line);box-shadow:var(--amial-shadow);border-radius:18px}
        .panel{padding:20px}.panel h2{font-size:17px;letter-spacing:-.2px}
        .table-wrap{border:1px solid #e4ece8;border-radius:14px;background:#fff}
        table{min-width:650px}th{position:sticky;top:0;background:#f7faf8;color:#536b62;font-size:11px;font-weight:800;z-index:1}td{color:#243d35}
        tr:hover td{background:#fbfdfc}
        .field input,.field select{background:#fff;border-color:#d5e2dd;border-radius:11px;outline:none}.field input:focus,.field select:focus{border-color:#6db49a;box-shadow:0 0 0 3px #19815f12}
        button.action,.link-action{background:linear-gradient(135deg,#177454,#1d8664);border-radius:11px;box-shadow:0 7px 15px #155b451c}
        button.secondary{background:#eef6f2;color:#1b684f;box-shadow:none;border:1px solid #d9e9e2}
        .dashboard-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#123d32 0%,#176348 62%,#1a805d 100%);color:#fff;border-radius:23px;padding:24px 26px;margin-bottom:18px;box-shadow:0 18px 44px #123a2f20}
        .dashboard-hero:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;left:-90px;top:-125px;background:#ffffff0b}
        .hero-kicker{font-size:11px;color:#b8ddcf;font-weight:800;margin-bottom:7px}.dashboard-hero h2{font-size:25px;margin:0 0 7px;position:relative;z-index:1}
        .dashboard-hero p{margin:0;color:#d4e9e1;line-height:1.8;max-width:780px;position:relative;z-index:1}
        .hero-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:17px;position:relative;z-index:1}
        .hero-actions button{border:1px solid #ffffff2b;background:#ffffff12;color:#fff;border-radius:10px;padding:9px 13px;font:700 12px Tahoma;cursor:pointer}
        .hero-actions button.primary{background:#fff;color:#195d47;border-color:#fff}
        .kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
        .kpi-card{background:#fff;border:1px solid var(--amial-line);border-radius:17px;padding:16px;box-shadow:0 9px 24px rgba(18,58,47,.05);min-width:0}
        .kpi-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.kpi-icon{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:#edf7f2;color:#157052;font-size:17px}
        .kpi-label{font-size:11px;color:#6b7f78;font-weight:700}.kpi-value{font-size:22px;font-weight:900;letter-spacing:-.4px;margin-top:12px;overflow-wrap:anywhere}
        .kpi-foot{font-size:10px;color:#83948e;margin-top:8px;min-height:16px}.kpi-card.gold .kpi-icon{background:#fbf4e4;color:#a87516}.kpi-card.red .kpi-icon{background:#fff0ee;color:#ad4c40}.kpi-card.blue .kpi-icon{background:#eef4fb;color:#3a6d9e}
        .dash-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(300px,.8fr);gap:14px;margin-bottom:14px}.dash-grid.equal{grid-template-columns:repeat(2,minmax(0,1fr))}
        .chart-card{background:#fff;border:1px solid var(--amial-line);border-radius:18px;padding:19px;box-shadow:var(--amial-shadow);min-width:0}
        .chart-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px}.chart-head h3{margin:0;font-size:15px}.chart-head small{color:#7a8d86}
        .trend-svg{width:100%;height:220px;display:block}.chart-labels{display:flex;justify-content:space-between;gap:10px;font-size:10px;color:#899993;margin-top:2px}
        .mix-list{display:grid;gap:14px}.mix-row{display:grid;grid-template-columns:88px 1fr auto;gap:9px;align-items:center;font-size:12px}.mix-row strong{font-size:12px}.mix-svg{width:100%;height:9px;display:block}
        .ops-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ops-card{background:#f8fbfa;border:1px solid #e1ebe7;border-radius:14px;padding:14px}.ops-card span{font-size:10px;color:#71857e}.ops-card strong{display:block;font-size:21px;margin-top:6px}
        .attention-list{display:grid;gap:9px}.attention-item{display:flex;gap:10px;align-items:flex-start;padding:11px 12px;background:#f8fbfa;border:1px solid #e2ebe7;border-radius:12px}.attention-item .att-icon{width:29px;height:29px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:#eef6f2;color:#1b7658;flex:none}.attention-item.warn .att-icon{background:#fff5e4;color:#a57018}.attention-item.danger .att-icon{background:#fff0ed;color:#b34f42}.attention-item strong{display:block;font-size:12px}.attention-item small{display:block;color:#71847d;line-height:1.6;margin-top:2px}
        .recent-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.recent-head h3{margin:0;font-size:16px}.text-button{border:0;background:transparent;color:#176b50;font:700 12px Tahoma;cursor:pointer}
        .source-chip{display:inline-flex;align-items:center;border-radius:999px;background:#eff7f3;color:#4f7467;padding:5px 9px;font-size:10px}
        .dashboard-empty{padding:34px;text-align:center;border:1px dashed #ccdcd5;border-radius:16px;color:#6d8279;background:#fbfdfc}
        .skeleton{position:relative;overflow:hidden;background:#edf2ef;border-radius:12px;min-height:84px}.skeleton:after{content:"";position:absolute;inset:0;transform:translateX(100%);background:linear-gradient(90deg,transparent,#ffffff9e,transparent);animation:merchantShimmer 1.3s infinite}
        @keyframes merchantShimmer{to{transform:translateX(-100%)}}
        @media(max-width:1180px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.dash-grid,.dash-grid.equal{grid-template-columns:1fr}.side{width:min(88vw,340px)}}
        @media(max-width:600px){.dashboard-hero{padding:20px 17px;border-radius:18px}.dashboard-hero h2{font-size:21px}.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.kpi-card{padding:13px}.kpi-value{font-size:18px}.kpi-icon{width:32px;height:32px}.ops-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.chart-card{padding:15px}.trend-svg{height:185px}.mix-row{grid-template-columns:75px 1fr auto}}

    
        .roles-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:11px;margin-top:12px}
        .role-card{border:1px solid #e0eae5;background:#fbfdfc;border-radius:14px;padding:14px}
        .role-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.role-card h4{margin:0;font-size:14px}.role-card p{font-size:11px;color:#758780;line-height:1.6;margin:6px 0 0}
        .role-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.role-meta span{font-size:10px;background:#eef6f2;color:#4d7164;border-radius:999px;padding:5px 8px}
        .permission-groups{display:grid;gap:8px;max-height:430px;overflow:auto;padding-inline-end:4px}.permission-group{border:1px solid #e2ebe7;border-radius:12px;background:#fbfdfc}.permission-group summary{cursor:pointer;padding:11px 12px;font-size:12px;font-weight:800}.permission-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;padding:0 12px 12px}
        .permission-option{display:flex;align-items:flex-start;gap:8px;border:1px solid #e7eeeb;background:#fff;border-radius:9px;padding:8px;font-size:11px;line-height:1.5}.permission-option input{width:auto;min-height:auto;margin-top:2px}.permission-option.sensitive{border-color:#f1ddc0;background:#fffaf2}
        .staff-status{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 8px;font-size:10px;font-weight:800}.staff-status.on{background:#eaf7f1;color:#177052}.staff-status.off{background:#f6eeee;color:#9b4b42}
        @media(max-width:650px){.permission-options{grid-template-columns:1fr}.roles-grid{grid-template-columns:1fr}}

    
        .filter-bar{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:8px;align-items:end;margin-bottom:14px}
        .filter-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
        .pager{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:13px;padding-top:12px;border-top:1px solid #e6ece9}.pager-actions{display:flex;gap:6px}.pager button{min-width:42px}
        @media(max-width:1000px){.filter-bar{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:650px){.filter-bar{grid-template-columns:1fr 1fr}.filter-actions{grid-column:1/-1}}

    </style>
