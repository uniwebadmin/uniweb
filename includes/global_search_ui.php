<?php
if (defined('UNIWEB_GLOBAL_SEARCH_RENDERED')) return;
define('UNIWEB_GLOBAL_SEARCH_RENDERED', true);
$searchHistoryScope = isset($_SESSION['admin_id'])
    ? 'admin_' . (int)$_SESSION['admin_id']
    : 'merchant_' . (int)($_SESSION['merchant_id'] ?? 0);
?>
<button type="button" data-spotlight-open class="hidden sm:flex items-center gap-2 h-9 px-3 rounded-lg border border-gray-700 bg-dark-800/70 text-xs text-gray-400 hover:text-white hover:border-sky-500/50" title="Search pages, IDs, GSTIN, PAN (Ctrl+K)">
    <span>⌕</span><span class="hidden md:inline">Search</span><kbd class="hidden md:inline text-[9px] border border-gray-700 rounded px-1">Ctrl K</kbd>
</button>
<button type="button" data-spotlight-open class="sm:hidden theme-toggle-btn" aria-label="Search (Ctrl+K)">⌕</button>
<span data-search-tip class="hidden lg:inline text-[10px] text-gray-500 max-w-[9rem] leading-tight">Try TXN… LNK… GSTIN PAN</span>

<div id="uniweb-spotlight" class="hidden fixed inset-0 z-[100] bg-black/65 backdrop-blur-sm p-3 sm:p-10" role="dialog" aria-modal="true" aria-label="Universal search">
    <div class="max-w-2xl mx-auto mt-[8vh] rounded-2xl border border-gray-700 bg-dark-900 shadow-2xl overflow-hidden">
        <div class="flex items-center gap-3 px-4 border-b border-gray-700">
            <span class="text-gray-500 text-xl">⌕</span>
            <input id="uniweb-spotlight-input" type="search" class="w-full bg-transparent py-4 text-base outline-none text-white placeholder:text-gray-500" placeholder="Pages, TXN, LNK, STL, RFD, merchant, GSTIN, PAN…" autocomplete="off">
            <button type="button" data-spotlight-close class="text-xs text-gray-500 border border-gray-700 rounded px-2 py-1">ESC</button>
        </div>
        <div id="uniweb-spotlight-results" class="max-h-[60vh] overflow-y-auto p-2">
            <p class="px-3 py-6 text-center text-sm text-gray-500">Type 2+ characters. Examples: TXN…, LNK…, GSTIN, PAN, merchant name</p>
        </div>
        <div class="px-4 py-2 border-t border-gray-800 text-[10px] text-gray-600">Ctrl+K to open · examples: TXN… LNK… GSTIN PAN · recent searches stay in this browser only</div>
    </div>
</div>

<script>
(function(){
    const modal=document.getElementById('uniweb-spotlight'), input=document.getElementById('uniweb-spotlight-input'), box=document.getElementById('uniweb-spotlight-results');
    if(!modal||!input||!box)return;
    let timer=null, controller=null;
    const key=<?= json_encode('uniweb_recent_searches_' . $searchHistoryScope) ?>;
    const history=()=>{try{return JSON.parse(localStorage.getItem(key)||'[]').filter(Boolean).slice(0,5)}catch(e){return[]}};
    const save=q=>{try{localStorage.setItem(key,JSON.stringify([q,...history().filter(x=>x.toLowerCase()!==q.toLowerCase())].slice(0,5)))}catch(e){}};
    const clear=()=>{while(box.firstChild)box.removeChild(box.firstChild)};
    const note=text=>{clear();const p=document.createElement('p');p.className='px-3 py-8 text-center text-sm text-gray-500';p.textContent=text;box.appendChild(p)};
    const showRecent=()=>{
        const rows=history(); clear();
        if(!rows.length){note('Type 2+ characters. Examples: TXN…, LNK…, GSTIN, PAN');return}
        const bar=document.createElement('div');bar.className='px-3 pt-2 pb-1 flex items-center justify-between';
        const h=document.createElement('p');h.className='text-[10px] uppercase tracking-wide text-gray-600';h.textContent='Recent searches';
        const clearBtn=document.createElement('button');clearBtn.type='button';clearBtn.className='text-[10px] text-gray-600 hover:text-red-400';clearBtn.textContent='Clear';
        clearBtn.onclick=()=>{try{localStorage.removeItem(key)}catch(e){}note('Type 2+ characters. Examples: TXN…, LNK…, GSTIN, PAN')};
        bar.append(h,clearBtn);box.appendChild(bar);
        rows.forEach(q=>{const b=document.createElement('button');b.type='button';b.className='block w-full text-left px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:bg-white/5';b.textContent='↻  '+q;b.onclick=()=>{input.value=q;run(q)};box.appendChild(b)});
    };
    const render=rows=>{
        clear();
        if(!rows.length){note('No matching result. Try an ID, name, phone, email or amount.');return}
        const groups={},order=[];
        rows.forEach(r=>{
            const t=r.type; if(!groups[t]){groups[t]=[];order.push(t)} groups[t].push(r);
        });
        const typeOrder=['Page','ID','Merchant','Staff','Transaction','Settlement','Payment Link','QR Code','Refund','Invoice','Ticket','Complaint','Mandate','Dispute','Team','Forward Queue','KYC','Platform Ledger','Chargeback','Payout'];
        order.sort((a,b)=>{const ia=typeOrder.indexOf(a),ib=typeOrder.indexOf(b);return(ia===-1?99:ia)-(ib===-1?99:ib)});
        order.forEach(type=>{
            const items=groups[type];
            const hdr=document.createElement('div');hdr.className='px-3 pt-3 pb-1 text-[10px] uppercase tracking-wide text-gray-600';hdr.textContent=type+(type==='Page'?'':'s');box.appendChild(hdr);
            items.forEach(r=>{
                const a=document.createElement('a');a.href=r.url;a.className='flex items-center justify-between gap-4 px-3 py-2.5 rounded-xl hover:bg-white/5 border border-transparent hover:border-gray-800';
                const left=document.createElement('div'), title=document.createElement('p'), sub=document.createElement('p'), tag=document.createElement('span');
                title.className='text-sm font-medium text-white';title.textContent=r.title;
                sub.className='text-xs text-gray-500 mt-0.5';sub.textContent=r.subtitle;
                tag.className='text-[10px] px-2 py-1 rounded-full bg-sky-500/10 text-sky-400 whitespace-nowrap';tag.textContent=r.type;
                left.append(title,sub);a.append(left,tag);a.onclick=()=>save(input.value.trim());box.appendChild(a);
            });
        });
    };
    const run=q=>{
        q=q.trim();if(q.length<2){showRecent();return}
        if(controller)controller.abort();controller=new AbortController();note('Searching…');
        fetch('global_search.php?q='+encodeURIComponent(q),{headers:{'X-Requested-With':'XMLHttpRequest'},signal:controller.signal})
            .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json()})
            .then(data=>render(data.results||[]))
            .catch(err=>{if(err.name!=='AbortError')note('Search is temporarily unavailable. Please retry.')});
    };
    const open=()=>{modal.classList.remove('hidden');input.value='';showRecent();setTimeout(()=>input.focus(),20)};
    const close=()=>modal.classList.add('hidden');
    document.querySelectorAll('[data-spotlight-open]').forEach(b=>b.addEventListener('click',open));
    document.querySelectorAll('[data-spotlight-close]').forEach(b=>b.addEventListener('click',close));
    modal.addEventListener('click',e=>{if(e.target===modal)close()});
    input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>run(input.value),250)});
    input.addEventListener('keydown',e=>{if(e.key==='Enter'){const q=input.value.trim();if(q.length>=2){save(q);run(q)}}});
    document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();modal.classList.contains('hidden')?open():close()}else if(e.key==='Escape'&&!modal.classList.contains('hidden'))close()});

    const bindLiveForms=()=>document.querySelectorAll('form[data-live-search-form]').forEach(form=>{
        if(form.dataset.liveSearchBound==='1')return;form.dataset.liveSearchBound='1';
        const targetId=form.dataset.resultsTarget, target=document.getElementById(targetId);if(!target)return;
        let wait=null, request=null;
        const refresh=()=>{
            const url=new URL(form.action||location.href,location.href);url.search=new URLSearchParams(new FormData(form)).toString();
            if(request)request.abort();request=new AbortController();target.style.opacity='.55';
            fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'},signal:request.signal}).then(r=>{if(!r.ok)throw new Error();return r.text()}).then(html=>{
                const doc=new DOMParser().parseFromString(html,'text/html'), next=doc.getElementById(targetId);
                if(next){target.replaceChildren(...Array.from(next.childNodes));history.replaceState({},'',url);target.style.opacity='1'}
            }).catch(err=>{if(err.name!=='AbortError')target.style.opacity='1'});
        };
        form.addEventListener('submit',e=>{e.preventDefault();refresh()});
        form.querySelectorAll('select,input[type=date]').forEach(el=>el.addEventListener('change',refresh));
        form.querySelectorAll('input[type=text],input[type=search]').forEach(el=>el.addEventListener('input',()=>{clearTimeout(wait);wait=setTimeout(refresh,400)}));
    });
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bindLiveForms);else bindLiveForms();

    const initMerchantSelects=()=>{
        document.querySelectorAll('[data-merchant-select]').forEach(wrap=>{
            if(wrap.dataset.bound==='1')return;
            wrap.dataset.bound='1';
            const input=wrap.querySelector('[data-merchant-select-filter]');
            const select=wrap.querySelector('select');
            if(!input||!select)return;
            const sync=()=>{
                const q=input.value.trim().toLowerCase();
                let visible=0;
                Array.from(select.options).forEach(opt=>{
                    if(opt.value===''||opt.value==='0'){opt.hidden=false;visible++;return;}
                    const show=q===''||opt.textContent.toLowerCase().includes(q);
                    opt.hidden=!show;
                    if(show)visible++;
                });
                if(q!==''&&visible===1){
                    const only=Array.from(select.options).find(o=>!o.hidden&&o.value!==''&&o.value!=='0');
                    if(only)select.value=only.value;
                }
            };
            input.addEventListener('input',sync);
            input.addEventListener('keydown',e=>{
                if(e.key==='Enter'){e.preventDefault();sync();select.focus();}
            });
        });
    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initMerchantSelects);else initMerchantSelects();
})();
</script>
