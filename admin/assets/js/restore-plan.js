(function(){
'use strict';
const cfg=window.SiteVaultRestorePlan||{};
const button=document.getElementById('sitevault-prepare-restore-plan');
if(!button)return;
const status=document.getElementById('sitevault-restore-plan-status');
const bar=document.getElementById('sitevault-restore-plan-progress-bar');
const pct=document.getElementById('sitevault-restore-plan-progress-value');

async function post(action,fields,timeoutMs){
  const body=new URLSearchParams();
  body.append('action',action);
  body.append('nonce',cfg.nonce||'');
  Object.keys(fields||{}).forEach(k=>body.append(k,fields[k]));
  const controller=new AbortController();
  const timer=setTimeout(()=>controller.abort(),Number(timeoutMs||60000));
  let res;
  try{
    res=await fetch(cfg.ajaxUrl,{
      method:'POST',
      body,
      credentials:'same-origin',
      cache:'no-store',
      signal:controller.signal,
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}
    });
  }catch(err){
    clearTimeout(timer);
    if(err&&err.name==='AbortError'){
      const e=new Error('Restore-plan request timed out and can be retried safely.');
      e.status=0;
      throw e;
    }
    throw err;
  }
  clearTimeout(timer);
  let json=null;
  try{json=await res.json();}catch(e){}
  if(!res.ok||!json||json.success!==true){
    const msg=json&&json.data&&json.data.message?json.data.message:('Restore-plan request failed ('+res.status+').');
    const e=new Error(msg); e.status=res.status; e.payload=json; throw e;
  }
  return json.data;
}

function render(ws){
  if(!ws)return;
  const entries=Math.max(1,Number((ws.entries||[]).length));
  const extracted=Number(ws.extracted_entries||0);
  const verifyTotal=Math.max(1,entries-1);
  const verified=Number(ws.verified_entries||0);
  let p=0,text='Preparing restore workspace…';
  if(ws.stage==='extract'){
    p=Math.min(70,Math.round((extracted/entries)*70));
    text='Extracting package parts: '+extracted+' / '+entries;
  }else if(ws.stage==='verify'){
    p=70+Math.min(25,Math.round((verified/verifyTotal)*25));
    text='Verifying extracted payload: '+verified+' / '+verifyTotal;
  }else if(ws.status==='prepared'){
    p=96;
    text='Workspace verified. Building compatibility plan…';
  }
  bar.style.width=p+'%';
  pct.textContent=p+'%';
  status.textContent=text;
}

button.addEventListener('click',async function(){
  button.disabled=true;
  status.className='sitevault-local-action-status is-running';
  status.textContent='Initializing restore workspace…';
  bar.style.width='1%'; pct.textContent='1%';
  try{
    const init=await post('sitevault_restore_plan_init',{},30000);
    const planId=init.plan_id;
    render(init);
    let done=false;
    while(!done){
      const data=await post('sitevault_restore_plan_step',{plan_id:planId},90000);
      if(data.workspace)render(data.workspace);
      if(data.status==='complete'){
        bar.style.width='100%'; pct.textContent='100%';
        status.textContent='Restore plan prepared successfully.';
        status.className='sitevault-local-action-status is-complete';
        done=true;
        setTimeout(()=>{window.location.href=window.location.pathname+window.location.search+'#sitevault-restore-compatibility';window.location.reload();},700);
        break;
      }
      await new Promise(resolve=>setTimeout(resolve,250));
    }
  }catch(err){
    status.textContent=err.message+' Reloading this page and retrying is safe.';
    status.className='sitevault-local-action-status is-error';
    button.disabled=false;
  }
});
})();