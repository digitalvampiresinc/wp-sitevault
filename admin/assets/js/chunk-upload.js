(function(){
'use strict';
const cfg=window.SiteVaultChunkUpload||{};
const form=document.getElementById('sitevault-chunk-upload-form');
if(!form)return;
const fileInput=document.getElementById('sitevault-chunk-file');
const button=document.getElementById('sitevault-chunk-upload-button');
const bar=document.getElementById('sitevault-chunk-progress-bar');
const pct=document.getElementById('sitevault-chunk-progress-value');
const status=document.getElementById('sitevault-chunk-status');
let chunkSize=Math.max(524288,Number(cfg.chunkSize||4194304));
const minChunkSize=524288;

async function post(fields,file,timeoutMs){
  const controller=new AbortController();
  const timer=window.setTimeout(()=>controller.abort(),Number(timeoutMs||45000));
  let body,headers={};
  if(file){
    body=new FormData();
    Object.keys(fields).forEach(k=>body.append(k,fields[k]));
    body.append('nonce',cfg.nonce||'');
    body.append('chunk',file,'chunk.bin');
  }else{
    body=new URLSearchParams();
    Object.keys(fields).forEach(k=>body.append(k,fields[k]));
    body.append('nonce',cfg.nonce||'');
    headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
  }
  let res;
  try{
    res=await fetch(cfg.ajaxUrl,{method:'POST',body,headers,credentials:'same-origin',signal:controller.signal,cache:'no-store'});
  }catch(err){
    window.clearTimeout(timer);
    if(err&&err.name==='AbortError'){
      const timeoutError=new Error('Server did not answer within '+Math.round(Number(timeoutMs||45000)/1000)+' seconds.');
      timeoutError.status=0;
      throw timeoutError;
    }
    throw err;
  }
  window.clearTimeout(timer);
  let json=null;
  try{json=await res.json();}catch(e){}
  if(!res.ok||!json||json.success!==true){
    const msg=json&&json.data&&json.data.message?json.data.message:('Upload request failed ('+res.status+').');
    const err=new Error(msg); err.payload=json; err.status=res.status; throw err;
  }
  return json.data;
}
function fingerprint(file){return 'sitevault_upload_'+file.name+'_'+file.size+'_'+file.lastModified;}
function setProgress(done,total,text){
  const p=total>0?Math.min(100,Math.round(done*100/total)):0;
  bar.style.width=p+'%'; pct.textContent=p+'%';
  if(text)status.textContent=text;
}
(async function(){
  status.textContent='Checking upload connection…';
  try{
    await post({action:'sitevault_upload_ping'},null,15000);
    status.textContent='Uploader ready. Choose a .sitevault package and start upload.';
    status.className='sitevault-help';
  }catch(err){
    status.textContent='Upload connection check failed: '+err.message;
    status.className='sitevault-local-action-status is-error';
  }
})();
form.addEventListener('submit',async function(e){
  e.preventDefault();
  const file=fileInput.files&&fileInput.files[0];
  if(!file)return;
  if(!/\.sitevault$/i.test(file.name)){status.textContent='Choose a .sitevault package.';return;}
  button.disabled=true;
  fileInput.disabled=true;
  status.className='sitevault-local-action-status is-running';
  try{
    const key=fingerprint(file);
    let resumeId=localStorage.getItem(key)||'';
    status.textContent='Initializing resumable upload session…';
    let state=null;
    let initAttempt=0;
    while(!state&&initAttempt<3){
      initAttempt++;
      try{
        state=await post({action:'sitevault_upload_init',name:file.name,size:String(file.size),resume_id:resumeId},null,30000);
      }catch(err){
        if(initAttempt>=3)throw err;
        status.textContent='Upload session did not answer. Retrying ('+(initAttempt+1)+'/3)…';
        await new Promise(resolve=>window.setTimeout(resolve,1500));
      }
    }
    resumeId=state.upload_id; localStorage.setItem(key,resumeId);
    let index=Number(state.next_index||0);
    let offset=Number(state.received_size||0);
    setProgress(offset,file.size,'Uploading package in resumable chunks…');
    while(offset<file.size){
      const blob=file.slice(offset,Math.min(offset+chunkSize,file.size));
      try{
        state=await post({action:'sitevault_upload_chunk',upload_id:resumeId,index:String(index)},blob,60000);
      }catch(err){
        if(err.status===413&&chunkSize>minChunkSize){
          chunkSize=Math.max(minChunkSize,Math.floor(chunkSize/2));
          status.textContent='Server request limit detected. Retrying automatically with '+formatBytes(chunkSize)+' chunks…';
          continue;
        }
        throw err;
      }
      offset=Number(state.received_size||0);
      index=Number(state.next_index||index+1);
      setProgress(offset,file.size,'Uploaded '+formatBytes(offset)+' of '+formatBytes(file.size)+' · chunk '+formatBytes(chunkSize));
    }
    status.textContent='Upload complete. Validating SiteVault package…';
    await post({action:'sitevault_upload_finalize',upload_id:resumeId},null,120000);
    localStorage.removeItem(key);
    setProgress(file.size,file.size,'Upload and validation complete.');
    status.className='sitevault-local-action-status is-complete';
    window.setTimeout(()=>window.location.reload(),700);
  }catch(err){
    status.textContent=err.message+' You can retry; completed chunks will be reused.';
    status.className='sitevault-local-action-status is-error';
    button.disabled=false; fileInput.disabled=false;
  }
});
function formatBytes(n){
  const units=['B','KB','MB','GB','TB']; let i=0,v=Number(n||0);
  while(v>=1024&&i<units.length-1){v/=1024;i++;}
  return (i===0?Math.round(v):v.toFixed(v>=10?1:2))+' '+units[i];
}
})();