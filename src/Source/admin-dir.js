(function(){
const D=JSON.parse(document.getElementById('md-data').textContent);
const $=id=>document.getElementById('md-'+id),esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const ROOT=$('pf').closest('.md');
const TYPE=[["cathedral","Catedrală"],["parish","Parohie"],["mission","Misiune"],["monastery","Mănăstire"],["chapel","Paraclis"]];
const DIO=[["archdiocese","Arhiepiscopia (SUA)"],["canada","Episcopia Canadei"],["south-america","America de Sud"]];
const DEA={archdiocese:[["us-east","Estul SUA"],["us-south","Sudul SUA"],["us-central","Centrul SUA"],["us-west","Vestul SUA"]],canada:[["ca-east","Canada de Est"],["ca-central","Canada de Centru"],["ca-west","Canada de Vest"]],"south-america":[["south-america","America de Sud"]]};
const ROLE=[["metropolitan","Mitropolit"],["bishop","Episcop"],["abbot","Egumen"],["parish-priest","Preot paroh"],["administrator-priest","Preot administrator"],["assigned-priest","Preot desemnat"],["temporary-assigned-priest","Preot desemnat temporar"],["attached-priest","Preot atașat"],["priest","Preot"],["deacon","Diacon"]];
const LAY=[["council-president","Președintele Consiliului"],["arola-director","Director AROLA"],["roya-director","Director ROYA"],["religious-education","Educație religioasă"],["chanter","Cântăreț"],["choir-director","Dirijor"],["contact","Persoană de contact"]];
const GROUP=[["hierarch","Ierarh"],["priest","Preot"],["deacon","Diacon"]];
const TLABEL={ips:"Înaltpreasfințitul Părinte",ps:"Preasfințitul Părinte",pc:"Preacucernicul Părinte",pcuv:"Preacuviosul Părinte",pr:"Părintele",diac:"Diacon"};
const TITLE={hierarch:["ips","ps"],priest:["pc","pcuv","pr"],deacon:["diac"]};
const DAYS=[["Duminică","Sunday","Domingo"],["Sâmbătă","Saturday","Sábado"],["Luni","Monday","Lunes"],["Marți","Tuesday","Martes"],["Miercuri","Wednesday","Miércoles"],["Joi","Thursday","Jueves"],["Vineri","Friday","Viernes"],["Sărbători","Feast days","Días de fiesta"],["Prima duminică a lunii","First Sunday of the month","Primer domingo del mes"]];
const SVCS=[["Sfânta Liturghie","Divine Liturgy","Divina Liturgia"],["Utrenia","Matins","Maitines"],["Vecernia","Vespers","Vísperas"],["Acatistul","Akathist","Acatisto"],["Paraclisul Maicii Domnului","Paraklesis","Paráclesis"],["Spovedanie","Confession","Confesión"],["Ora de cateheză","Catechism class","Catequesis"]];
const FEASTS=[["Sfinții Împărați Constantin și Elena","Saints Constantine and Helen","Santos Constantino y Elena"],["Buna Vestire","The Annunciation","La Anunciación"],["Sfântul Nicolae","Saint Nicholas","San Nicolás"],["Sfânta Treime","Holy Trinity","Santísima Trinidad"],["Adormirea Maicii Domnului","Dormition of the Mother of God","Dormición de la Madre de Dios"],["Învierea Domnului","Resurrection of the Lord","Resurrección del Señor"],["Sfântul Mare Mucenic Gheorghe","Saint George the Great Martyr","San Jorge, Gran Mártir"],["Sfinții Apostoli Petru și Pavel","Saints Peter and Paul","Santos Pedro y Pablo"],["Sfântul Apostol Andrei","Saint Andrew the Apostle","San Andrés Apóstol"],["Sfânta Cuvioasă Parascheva","Saint Parascheva","Santa Parascheva"],["Acoperământul Maicii Domnului","Protection of the Mother of God","Protección de la Madre de Dios"],["Înălțarea Sfintei Cruci","Exaltation of the Holy Cross","Exaltación de la Santa Cruz"],["Sfântul Ioan Botezătorul","Saint John the Baptist","San Juan Bautista"],["Sfântul Dimitrie","Saint Demetrius","San Demetrio"],["Sfinții Arhangheli Mihail și Gavriil","Holy Archangels Michael and Gabriel","Santos Arcángeles Miguel y Gabriel"],["Schimbarea la Față","The Transfiguration","La Transfiguración"],["Nașterea Maicii Domnului","Nativity of the Mother of God","Natividad de la Madre de Dios"],["Intrarea în Biserică a Maicii Domnului","Entry of the Mother of God into the Temple","Presentación de la Madre de Dios"],["Sfântul Ilie","Saint Elijah","San Elías"],["Pogorârea Duhului Sfânt","Descent of the Holy Spirit","Descenso del Espíritu Santo"],["Înălțarea Domnului","Ascension of the Lord","Ascensión del Señor"]];
const PER=10,SRC='s'+'rc';
let P=D.P.slice(),C=D.C.slice();
let TAB=D.mode,EDIT=0,PAGE=0,BUSY=false;
const norm=s=>String(s==null?'':s).normalize('NFD').replace(/[̀-ͯ„”"]/g,'').toLowerCase();
const parById=id=>P.find(p=>p[0]===id),clById=id=>C.find(c=>c.id===id);
const lbl=(list,k)=>(list.find(x=>x[0]===k)||[k,k])[1];
const cityOf=p=>p?p[4]+(p[5]?', '+p[5]:''):'';
const ini=n=>{const w=String(n||'').split(/\s+/).filter(Boolean);return w.length?(w[0][0]+(w.length>1?w[w.length-1][0]:'')).toUpperCase():'?';};
function opts(list,val){return list.map(o=>`<option value="${esc(o[0])}"${o[0]===val?' selected':''}>${esc(o[1])}</option>`).join('');}
function rnd(){const a=new Uint8Array(8);crypto.getRandomValues(a);return [...a].map(x=>x.toString(16).padStart(2,'0')).join('');}
async function post(fields,file){const fd=new FormData();Object.entries(fields).forEach(([k,v])=>fd.append(k,v==null?'':v));if(file)fd.append('photo',file,'photo.jpg');fd.append(D.token,'1');
 const r=await fetch(D.api,{method:'POST',body:fd,credentials:'same-origin'});let j;try{j=await r.json();}catch(e){throw new Error('Răspuns neașteptat de la server ('+r.status+').');}if(!j.ok)throw new Error(j.error||'Eroare.');return j;}
async function shrink(file,max){const url=URL.createObjectURL(file);try{const img=new Image();await new Promise((ok,no)=>{img.onload=ok;img.onerror=no;img[SRC]=url;});
 const s=Math.min(1,max/Math.max(img.naturalWidth,img.naturalHeight)),c=document.createElement('canvas');c.width=Math.max(1,Math.round(img.naturalWidth*s));c.height=Math.max(1,Math.round(img.naturalHeight*s));
 const g=c.getContext('2d');g.fillStyle='#fff';g.fillRect(0,0,c.width,c.height);g.drawImage(img,0,0,c.width,c.height);const blob=await new Promise(r=>c.toBlob(r,'image/jpeg',.86));if(!blob)throw 0;return blob;}finally{URL.revokeObjectURL(url);}}
/* a picked photo: kept in the browser until "Save", then uploaded */
function photoInput(inp,pv,X,get,set,empty){inp.onchange=async()=>{const f=inp.files[0];inp.value='';if(!f)return;try{const blob=await shrink(f,1600);const u=URL.createObjectURL(blob);set({blob,src:u});}catch(e){alert0('Fotografia nu poate fi citită. Încercați JPG sau PNG.');}};
 X.onclick=()=>set({none:true});
 return()=>{const p=get();const src=p&&!p.none?p.src:'';pv.style.backgroundImage=src?`url("${src}")`:'';pv.textContent=src?'':empty();X.hidden=!src;};}
function alert0(m){const e=TAB==='par'?$('pErr'):$('cErr');e.textContent=m;}
async function sendPhoto(p){if(!p||p.none)return p&&p.none?{photo:'none'}:{photo:'keep'};if(!p.blob)return{photo:'keep'};const batch=rnd();const j=await post({action:'upload',batch},p.blob);return{photo:j.name,batch};}
function pager(){const rows=listRows(),pages=Math.max(1,Math.ceil(rows.length/PER));if(PAGE>=pages)PAGE=pages-1;const pg=$('pg');
 if(rows.length>PER){pg.hidden=false;pg.innerHTML=`<button type="button" data-p="${PAGE-1}"${PAGE<=0?' disabled':''}>‹ Înapoi</button><span>${PAGE*PER+1}–${Math.min(rows.length,PAGE*PER+PER)} din ${rows.length}</span><button type="button" data-p="${PAGE+1}"${PAGE>=pages-1?' disabled':''}>Înainte ›</button>`;}else{pg.hidden=true;pg.innerHTML='';}
 return rows.slice(PAGE*PER,PAGE*PER+PER);}
$('pg').onclick=e=>{const b=e.target.closest('button');if(b&&!b.disabled){PAGE=+b.dataset.p;renderList();$('lt').scrollIntoView({behavior:'smooth',block:'start'});}};
/* ---------- tabs ---------- */
function setTab(t){if(!D.can[t])return;TAB=t;EDIT=0;PAGE=0;$('pf').hidden=t!=='par';$('cf').hidden=t!=='cl';$('lt').textContent=t==='par'?'Parohiile':'Clericii';
 $('q').value='';$('q').placeholder=t==='par'?'Căutați după nume sau oraș':'Căutați după nume sau parohie';if(t==='par')loadP(blankP());else loadC(blankC());renderList();}
window.mdMode=t=>{if(t!==TAB&&!BUSY)setTab(t);};
$('dioF').innerHTML='<option value="">Toate eparhiile</option>'+DIO.map(d=>`<option value="${d[0]}">${esc(d[1])}</option>`).join('');
$('dioF').onchange=()=>{PAGE=0;renderList();};$('q').oninput=()=>{PAGE=0;renderList();};
function listRows(){const q=norm($('q').value.trim()),dio=$('dioF').value,words=q.split(/\s+/).filter(Boolean);
 if(TAB==='par')return P.filter(p=>(!dio||p[3]===dio)&&words.every(w=>norm(p[1]+' '+p[4]+' '+p[5]).includes(w)));
 return C.filter(c=>(!dio||c.dio===dio)&&words.every(w=>norm(c.name+' '+c.asg.map(a=>{const p=parById(a[0]);return p?p[1]+' '+p[4]:'';}).join(' ')).includes(w)));}
function renderList(){const all=listRows(),rows=pager();let html;
 if(TAB==='par')html=rows.map(p=>{const n=C.filter(c=>c.asg.some(a=>a[0]===p[0])).length,t=lbl(TYPE,p[2]);return`<div class="dit${p[0]===EDIT?' on':''}" data-id="${p[0]}"><div class="ic sq">${esc(t.slice(0,3).toUpperCase())}</div><div><b>${esc(p[1])}</b><div class="meta">${esc(cityOf(p))} · <span class="ptype ${esc(p[2])}">${esc(t)}</span> · ${n} ${n===1?'cleric':'clerici'}</div><div class="ia">${p[6]?'<button type="button" class="ed">Editează</button>':''}${p[7]?'<button type="button" class="del">Șterge</button>':''}</div></div></div>`;}).join('');
 else html=rows.map(c=>{const ps=c.asg.map(a=>parById(a[0])).filter(Boolean);return`<div class="dit${c.id===EDIT?' on':''}" data-id="${c.id}"><div class="ic" data-bg="${esc(c.th||'')}">${esc(ini(c.name))}</div><div><b>${esc(c.name)}</b><div class="meta">${esc(lbl(GROUP,c.group))}${c.status==='retired'?' · pensionar':''} · ${ps.length?esc(ps.map(p=>p[4]).join(', ')):'fără parohie'}</div><div class="ia">${c.edit?'<button type="button" class="ed">Editează</button>':''}${c.del?'<button type="button" class="del">Șterge</button>':''}</div></div></div>`;}).join('');
 $('list').innerHTML=html||'<p class="empty">Nimic găsit.</p>';$('list').querySelectorAll('[data-bg]').forEach(x=>{if(x.dataset.bg){x.style.backgroundImage='url("'+x.dataset.bg+'")';x.style.color='transparent';}});$('cnt').textContent=all.length+(TAB==='par'?(all.length===1?' parohie':' parohii'):(all.length===1?' cleric':' clerici'));}
$('list').onclick=async e=>{const it=e.target.closest('.dit');if(!it||BUSY)return;const id=+it.dataset.id;
 if(e.target.classList.contains('ed')){e.target.disabled=true;e.target.textContent='Se încarcă…';
  try{const j=await post({action:TAB==='par'?'dir_par_get':'dir_cl_get',id});EDIT=id;if(TAB==='par')loadP(fromServerP(j.item));else loadC(fromServerC(j.item));renderList();(TAB==='par'?$('pf'):$('cf')).scrollIntoView({behavior:'smooth'});}
  catch(x){alert0(x.message);}finally{e.target.disabled=false;e.target.textContent='Editează';}}
 else if(e.target.classList.contains('del')){if(it.querySelector('.confirm'))return;let msg=TAB==='par'?'Mutați parohia la coș? Dispare din director și de pe hartă.':'Mutați fișa la coș? Clericul dispare din director și de pe paginile parohiilor.';
  if(TAB==='par'){const n=C.filter(c=>c.asg.some(a=>a[0]===id)).length;if(n)msg=`Mutați parohia la coș? ${n} ${n===1?'cleric este atribuit':'clerici sunt atribuiți'} aici; atribuirea va fi scoasă din fișa lor.`;}
  const c=document.createElement('div');c.className='confirm';c.innerHTML=`<span>${esc(msg)}</span><div><button type="button" class="yes">Da, mută la coș</button><button type="button" class="no">Renunță</button></div>`;it.appendChild(c);}
 else if(e.target.classList.contains('yes')){e.target.disabled=true;
  try{const j=await post({action:TAB==='par'?'dir_par_trash':'dir_cl_trash',id});if(TAB==='par'){P=P.filter(p=>p[0]!==id);mergeC(j.clergy||[]);}else C=C.filter(c=>c.id!==id);if(EDIT===id){EDIT=0;TAB==='par'?loadP(blankP()):loadC(blankC());}renderList();}
  catch(x){e.target.disabled=false;e.target.closest('.confirm').querySelector('span').textContent=x.message;}}
 else if(e.target.classList.contains('no'))e.target.closest('.confirm').remove();};
function mergeC(rows){rows.forEach(r=>{const k=C.findIndex(c=>c.id===r.id);if(k>=0)C[k]=r;else C.push(r);});C.sort((a,b)=>(a.last+' '+a.name).localeCompare(b.last+' '+b.name,'ro'));}
function ctl(i){return`<div class="ctl">${i>0?'<button type="button" class="up" aria-label="Mută mai sus">↑</button>':''}<button type="button" class="x" aria-label="Scoate">✕</button></div>`;}
function rowsMove(box,arr,paint,after){box.addEventListener('click',e=>{const rw=e.target.closest('.rw');if(!rw||!e.target.closest('.ctl'))return;const i=+rw.dataset.i;
 if(e.target.classList.contains('x'))arr().splice(i,1);else if(e.target.classList.contains('up')){const a=arr();const[x]=a.splice(i,1);a.splice(i-1,0,x);}else return;if(after)after();paint();});}
/* picker: clergy inside the parish form, parishes inside the clergy form */
function picker(kind,id){const it=kind==='c'?clById(id):parById(id);
 if(it)return`<div class="picked"><div><b>${esc(kind==='c'?it.name:it[1])}</b><span>${esc(kind==='c'?lbl(GROUP,it.group)+(it.status==='retired'?' · pensionar':''):cityOf(it))}</span></div><button type="button" class="link chg">Schimbă</button></div>`;
 return`<div class="ac"><input type="search" class="pq" placeholder="${kind==='c'?'Scrieți numele clericului':'Scrieți numele parohiei sau orașul'}" aria-label="Caută" autocomplete="off"><div class="sug" hidden></div></div>`;}
function bindPicker(box,kind,onPick){box.addEventListener('input',e=>{if(!e.target.classList.contains('pq'))return;const q=norm(e.target.value.trim()),sug=e.target.nextElementSibling;if(q.length<2){sug.hidden=true;return;}const words=q.split(/\s+/);
  const hits=(kind==='c'?C.filter(c=>words.every(w=>norm(c.name).includes(w))).map(c=>[c.id,c.name,lbl(GROUP,c.group)]):P.filter(p=>words.every(w=>norm(p[1]+' '+p[4]+' '+p[5]).includes(w))).map(p=>[p[0],p[1],cityOf(p)])).slice(0,8);
  sug.innerHTML=hits.map(h=>`<button type="button" data-id="${h[0]}"><b>${esc(h[1])}</b><span>${esc(h[2])}</span></button>`).join('')||'<p class="empty" style="padding:10px 14px">Nu am găsit.</p>';sug.hidden=false;});
 box.addEventListener('click',e=>{const b=e.target.closest('.sug button');if(b){onPick(+b.closest('.rw').dataset.i,+b.dataset.id);}else if(e.target.classList.contains('chg')){onPick(+e.target.closest('.rw').dataset.i,0);}});}
document.addEventListener('click',e=>{ROOT.querySelectorAll('.rw .sug').forEach(s=>{if(!s.parentElement.contains(e.target))s.hidden=true;});});

/* ================= parish form ================= */
let S;
function blankP(){return{id:0,type:'parish',dio:'archdiocese',dea:'us-east',ro:'',en:'',es:'',street:'',city:'',state:'',zip:'',country:'USA',at:'',geo:'',mail:'',phone:'',email:'',web:'',fb:'',sched:[],feasts:[],clergy:[],clchg:0,lay:[],about:{ro:'',en:'',es:''},photo:null,url:''};}
const idx=(list,ro)=>{const k=list.findIndex(x=>norm(x[0])===norm(ro));return k;};
function fromServerP(d){const s=Object.assign(blankP(),d);
 s.sched=(d.sched||[]).map(r=>{const di=idx(DAYS,r.d[0]),si=idx(SVCS,r.s[0]);return{d:di>=0?di:'x',s:si>=0?si:'x',t:r.t,cd:r.d.slice(),cs:r.s.slice()};});
 s.feasts=(d.feasts||[]).map(f=>{const k=idx(FEASTS,f[0]);return k>=0?{f:k,cx:['','','']}:{f:'x',cx:f.slice()};});
 s.lay=(d.lay||[]).map(l=>({n:l.n,roles:l.roles||[],ph:l.ph||'',em:l.em||''}));
 s.clergy=C.filter(c=>c.asg.some(a=>a[0]===d.id)).map(c=>({c:c.id,r:c.asg.find(a=>a[0]===d.id)[1]||'priest'}));
 if(!DEA[s.dio])s.dio='archdiocese';if(!DEA[s.dio].some(x=>x[0]===s.dea))s.dea=DEA[s.dio][0][0];
 s.photo=d.photo?{src:d.photo}:null;s.clchg=0;return s;}
const PF={pRo:'ro',pEn:'en',pEs:'es',pStreet:'street',pCity:'city',pState:'state',pZip:'zip',pCountry:'country',pAt:'at',pGeo:'geo',pMail:'mail',pPhone:'phone',pEmail:'email',pWeb:'web',pFb:'fb'};
Object.keys(PF).forEach(id=>$(id).addEventListener('input',()=>{S[PF[id]]=$(id).value;sums();}));
$('pType').innerHTML=TYPE.map(t=>`<button type="button" class="chip" data-k="${t[0]}">${esc(t[1])}</button>`).join('');
$('pType').onclick=e=>{const b=e.target.closest('.chip');if(b){S.type=b.dataset.k;paintP();}};
$('pDio').innerHTML=opts(DIO,'');$('pDio').onchange=()=>{S.dio=$('pDio').value;S.dea=DEA[S.dio][0][0];paintP();};
$('pDea').onchange=()=>{S.dea=$('pDea').value;};
$('pGeoBtn').onclick=async()=>{const a=[S.street,S.city,S.state,S.zip,S.country].filter(Boolean).join(', ');if(!S.city){$('pErr').textContent='Scrieți întâi adresa și orașul.';return;}
 $('pErr').textContent='';$('pGeoBtn').disabled=true;$('pGeoBtn').textContent='Se caută…';
 try{const r=await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q='+encodeURIComponent(a),{headers:{'Accept-Language':'ro,en'}});const j=await r.json();
  if(j&&j[0]){S.geo=(+j[0].lat).toFixed(5)+', '+(+j[0].lon).toFixed(5);$('pGeo').value=S.geo;$('pGeoHint').textContent='Găsit: '+j[0].display_name+'. Verificați pe hartă dacă este locul bisericii.';}
  else $('pGeoHint').textContent='Adresa nu a fost găsită. Copiați coordonatele din Google Maps (clic dreapta pe locul bisericii).';}
 catch(x){$('pGeoHint').textContent='Căutarea nu a mers. Copiați coordonatele din Google Maps (clic dreapta pe locul bisericii).';}
 finally{$('pGeoBtn').disabled=false;$('pGeoBtn').textContent='Caută după adresă';sums();}};
let ALANG='ro';
ROOT.querySelectorAll('#md-pf .mng-ltabs button').forEach(b=>b.onclick=()=>{S.about[ALANG]=$('pAbout').innerHTML;ALANG=b.dataset.l;ROOT.querySelectorAll('#md-pf .mng-ltabs button').forEach(x=>x.setAttribute('aria-selected',String(x===b)));$('pAbout').innerHTML=S.about[ALANG];});
$('pAbout').oninput=()=>{S.about[ALANG]=$('pAbout').innerHTML;sums();};
$('pAbout').addEventListener('paste',e=>{const t=(e.clipboardData||window.clipboardData).getData('text/plain');if(t){e.preventDefault();document.execCommand('insertText',false,t);}});
const paintPPhoto=photoInput($('pPhoto'),$('pPhotoPv'),$('pPhotoX'),()=>S.photo,v=>{S.photo=v;paintPPhoto();sums();},()=>'fără fotografie');
const tri=(cls,vals,ph,label)=>`<div class="custom"><span class="cl">${label}</span>${['ro','en','es'].map((l,k)=>`<input type="text" class="${cls}" data-k="${k}" value="${esc(vals[k]||'')}" placeholder="${ph[k]}" maxlength="120">`).join('')}</div>`;
function paintSched(){$('pSched').innerHTML=S.sched.map((r,i)=>{const day=r.d==='x'?null:DAYS[r.d],svc=r.s==='x'?null:SVCS[r.s];
 return`<div class="rw sched" data-i="${i}"><select class="d" aria-label="Ziua">${DAYS.map((x,k)=>`<option value="${k}"${r.d===k?' selected':''}>${esc(x[0])}</option>`).join('')}<option value="x"${r.d==='x'?' selected':''}>Scriu eu…</option></select><select class="s" aria-label="Slujba">${SVCS.map((x,k)=>`<option value="${k}"${r.s===k?' selected':''}>${esc(x[0])}</option>`).join('')}<option value="x"${r.s==='x'?' selected':''}>Scriu eu…</option></select><input type="text" class="t" value="${esc(r.t||'')}" placeholder="10:00" aria-label="Ora" maxlength="40">${ctl(i)}
 ${r.d==='x'?tri('cd',r.cd,['Ziua în română','In English','En español'],'Ziua'):''}${r.s==='x'?tri('cs',r.cs,['Slujba în română','In English','En español'],'Slujba'):''}${day&&svc?`<div class="tr">${esc(day[1])} · ${esc(svc[1])} &nbsp;/&nbsp; ${esc(day[2])} · ${esc(svc[2])}</div>`:''}</div>`;}).join('');sums();}
$('pSched').addEventListener('change',e=>{const rw=e.target.closest('.rw');if(!rw)return;const r=S.sched[+rw.dataset.i];
 if(e.target.classList.contains('d')){r.d=e.target.value==='x'?'x':+e.target.value;if(r.d==='x'&&!r.cd[0])r.cd=['','',''];paintSched();}
 if(e.target.classList.contains('s')){r.s=e.target.value==='x'?'x':+e.target.value;if(r.s==='x'&&!r.cs[0])r.cs=['','',''];paintSched();}});
$('pSched').addEventListener('input',e=>{const rw=e.target.closest('.rw');if(!rw)return;const r=S.sched[+rw.dataset.i];if(e.target.classList.contains('t'))r.t=e.target.value;if(e.target.classList.contains('cd'))r.cd[+e.target.dataset.k]=e.target.value;if(e.target.classList.contains('cs'))r.cs[+e.target.dataset.k]=e.target.value;});
$('addSched').onclick=()=>{S.sched.push({d:0,s:0,t:'10:00',cd:['','',''],cs:['','','']});paintSched();};
rowsMove($('pSched'),()=>S.sched,paintSched);
function paintFeasts(){$('pFeasts').innerHTML=S.feasts.map((r,i)=>`<div class="rw feast" data-i="${i}"><select class="fs" aria-label="Hramul">${FEASTS.map((f,k)=>`<option value="${k}"${r.f===k?' selected':''}>${esc(f[0])}</option>`).join('')}<option value="x"${r.f==='x'?' selected':''}>Alt hram (scriu eu)…</option></select>${ctl(i)}${r.f==='x'?tri('cx',r.cx,['În română','In English','En español'],'Hramul'):`<div class="tr">${esc(FEASTS[r.f][1])} / ${esc(FEASTS[r.f][2])}</div>`}</div>`).join('');sums();}
$('pFeasts').addEventListener('change',e=>{if(!e.target.classList.contains('fs'))return;const r=S.feasts[+e.target.closest('.rw').dataset.i];r.f=e.target.value==='x'?'x':+e.target.value;paintFeasts();});
$('pFeasts').addEventListener('input',e=>{if(e.target.classList.contains('cx'))S.feasts[+e.target.closest('.rw').dataset.i].cx[+e.target.dataset.k]=e.target.value;sums();});
$('addFeast').onclick=()=>{S.feasts.push({f:0,cx:['','','']});paintFeasts();};
rowsMove($('pFeasts'),()=>S.feasts,paintFeasts);
function paintPClergy(){$('pClergy').innerHTML=S.clergy.map((r,i)=>`<div class="rw asg" data-i="${i}">${picker('c',r.c)}<select class="r" aria-label="Rolul">${opts(ROLE,r.r)}</select>${ctl(i)}</div>`).join('');sums();}
bindPicker($('pClergy'),'c',(i,id)=>{S.clergy[i].c=id;S.clchg=1;paintPClergy();if(!id)$('pClergy').querySelector(`.rw[data-i="${i}"] .pq`)?.focus();});
$('pClergy').addEventListener('change',e=>{if(e.target.classList.contains('r')){S.clergy[+e.target.closest('.rw').dataset.i].r=e.target.value;S.clchg=1;}});
$('addAsg').onclick=()=>{S.clergy.push({c:0,r:'parish-priest'});S.clchg=1;paintPClergy();$('pClergy').querySelector('.rw:last-child .pq')?.focus();};
rowsMove($('pClergy'),()=>S.clergy,paintPClergy,()=>{S.clchg=1;});
function paintLay(){$('pLay').innerHTML=S.lay.map((r,i)=>`<div class="rw lay" data-i="${i}"><input type="text" class="ln" value="${esc(r.n)}" placeholder="Numele" aria-label="Numele" maxlength="120">${ctl(i)}<div class="chips full">${LAY.map(l=>`<button type="button" class="chip lr" data-k="${l[0]}" aria-pressed="${r.roles.includes(l[0])}">${esc(l[1])}</button>`).join('')}</div><div class="row full"><input type="text" class="lp" value="${esc(r.ph)}" placeholder="Telefon" aria-label="Telefon" maxlength="60"><input type="text" class="le" value="${esc(r.em)}" placeholder="E-mail" aria-label="E-mail" maxlength="120"></div></div>`).join('');sums();}
$('pLay').addEventListener('input',e=>{const rw=e.target.closest('.rw');if(!rw)return;const r=S.lay[+rw.dataset.i];if(e.target.classList.contains('ln'))r.n=e.target.value;if(e.target.classList.contains('lp'))r.ph=e.target.value;if(e.target.classList.contains('le'))r.em=e.target.value;sums();});
$('pLay').addEventListener('click',e=>{if(!e.target.classList.contains('lr'))return;const r=S.lay[+e.target.closest('.rw').dataset.i],k=e.target.dataset.k;r.roles=r.roles.includes(k)?r.roles.filter(x=>x!==k):[...r.roles,k];e.target.setAttribute('aria-pressed',String(r.roles.includes(k)));});
$('addLay').onclick=()=>{S.lay.push({n:'',roles:[],ph:'',em:''});paintLay();$('pLay').querySelector('.rw:last-child .ln')?.focus();};
rowsMove($('pLay'),()=>S.lay,paintLay);
function sums(){if(!S)return;$('s1').textContent=S.ro?lbl(TYPE,S.type)+' · '+lbl(DIO,S.dio):'';$('s2').textContent=[S.city,S.phone?'tel.':'',S.geo?'pe hartă':''].filter(Boolean).join(' · ');
 $('s3').textContent=S.sched.length?S.sched.length+(S.sched.length===1?' slujbă':' slujbe'):'';$('s4').textContent=S.feasts.map(f=>f.f==='x'?f.cx[0]:FEASTS[f.f][0]).filter(Boolean).join(', ');
 const nc=S.clergy.filter(c=>c.c).length;$('s5').textContent=nc?nc+(nc===1?' cleric':' clerici'):'';$('s6').textContent=S.lay.length?S.lay.length+(S.lay.length===1?' persoană':' persoane'):'';
 $('s7').textContent=[S.photo&&!S.photo.none?'fotografie':'',S.about.ro?'RO':'',S.about.en?'EN':'',S.about.es?'ES':''].filter(Boolean).join(' · ');}
function paintP(){$('pType').querySelectorAll('.chip').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.k===S.type)));$('pDio').value=S.dio;$('pDea').innerHTML=opts(DEA[S.dio],S.dea);sums();}
function loadP(d){S=d;Object.keys(PF).forEach(id=>$(id).value=S[PF[id]]||'');ALANG='ro';ROOT.querySelectorAll('#md-pf .mng-ltabs button').forEach(x=>x.setAttribute('aria-selected',String(x.dataset.l==='ro')));$('pAbout').innerHTML=S.about.ro||'';
 $('pGeoHint').textContent='Sau copiați coordonatele din Google Maps (clic dreapta pe locul bisericii).';$('pClBox').hidden=!D.can.cl;
 paintPPhoto();paintP();paintSched();paintFeasts();paintPClergy();paintLay();
 $('pft').textContent=EDIT?'Modificați parohia':'Parohie nouă';$('pfs').textContent=EDIT?S.ro+(S.city?' – '+S.city:''):'Completați ce știți; restul se poate adăuga oricând.';
 $('pSave').textContent=EDIT?'Salvează modificările':'Salvează parohia';$('pCancel').hidden=!EDIT;$('pErr').textContent='';}
$('pCancel').onclick=()=>{if(BUSY)return;EDIT=0;loadP(blankP());renderList();};
$('pf').onsubmit=async e=>{e.preventDefault();if(BUSY)return;S.about[ALANG]=$('pAbout').innerHTML;
 const miss=[];if(!S.ro.trim())miss.push('numele în română');if(!S.city.trim())miss.push('orașul');if(S.clergy.some(c=>!c.c))miss.push('clericul (sau scoateți rândul gol)');
 if(S.sched.some(r=>(r.d==='x'&&!r.cd[0].trim())||(r.s==='x'&&!r.cs[0].trim())))miss.push('ziua sau slujba scrisă de mână, în română');if(S.feasts.some(f=>f.f==='x'&&!f.cx[0].trim()))miss.push('hramul scris de mână, în română');
 if(S.geo.trim()&&!/^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/.test(S.geo))miss.push('coordonatele corecte (de ex. 41.97906, -87.80023)');
 if(miss.length){$('pErr').textContent='Lipsește: '+miss.join(', ')+'.';return;}
 BUSY=true;$('pErr').textContent='';$('pSave').disabled=true;$('pSave').textContent='Se salvează…';
 try{const ph=await sendPhoto(S.photo);
  const f={action:'dir_par_save',id:EDIT||'',type:S.type,dio:S.dio,dea:S.dea,clchg:S.clchg,...ph};
  Object.values(PF).forEach(k=>f[k]=S[k]||'');['ro','en','es'].forEach(l=>f['about_'+l]=S.about[l]||'');
  f.sched=JSON.stringify(S.sched.map(r=>({d:r.d==='x'?r.cd:DAYS[r.d],s:r.s==='x'?r.cs:SVCS[r.s],t:r.t})));
  f.feasts=JSON.stringify(S.feasts.map(x=>x.f==='x'?x.cx:FEASTS[x.f]));f.lay=JSON.stringify(S.lay.filter(l=>l.n.trim()));f.clergy=JSON.stringify(S.clergy.filter(c=>c.c));
  const j=await post(f);const k=P.findIndex(p=>p[0]===j.item[0]);if(k>=0)P[k]=j.item;else P.push(j.item);P.sort((a,b)=>a[1].localeCompare(b[1],'ro'));mergeC(j.clergy||[]);
  const was=EDIT;EDIT=0;loadP(blankP());renderList();
  $('pok').innerHTML=esc((was?'Modificările au fost salvate.':'Parohia a fost adăugată.')+((j.clergy||[]).length?' Fișele clericilor au fost actualizate.':'')+(j.note?' '+j.note:''))+(j.url?' <a target="_blank" rel="noopener">Vedeți pe site</a>':'');
  const a=$('pok').querySelector('a');if(a)a.setAttribute('hr'+'ef',j.url);$('pok').hidden=false;setTimeout(()=>$('pok').hidden=true,8000);$('pf').scrollIntoView({behavior:'smooth'});}
 catch(x){$('pErr').textContent=x.message;}finally{BUSY=false;$('pSave').disabled=false;if($('pSave').textContent.indexOf('Se ')===0)$('pSave').textContent=EDIT?'Salvează modificările':'Salvează parohia';}};

/* ================= clergy form ================= */
let K;
function blankC(){return{id:0,name:'',last:'',group:'priest',title:'pc',tro:'',ten:'',tes:'',dio:'archdiocese',status:'active',phone:'',email:'',asg:[],photo:null};}
function fromServerC(d){const k=Object.assign(blankC(),d);k.asg=(d.asg||[]).map(a=>({p:a[0],r:a[1]||'priest'}));k.photo=d.photo?{src:d.photo}:null;
 if(k.title!=='other'&&k.tro)k.title='other';return k;}
let lastAuto=true;
$('cGroup').innerHTML=GROUP.map(g=>`<button type="button" class="chip" data-k="${g[0]}">${esc(g[1])}</button>`).join('');
$('cGroup').onclick=e=>{const b=e.target.closest('.chip');if(!b)return;K.group=b.dataset.k;if(K.title!=='other')K.title=TITLE[K.group][0];paintC();};
$('cStatus').innerHTML=[['active','Activ'],['retired','Pensionar']].map(s=>`<button type="button" class="chip" data-k="${s[0]}">${s[1]}</button>`).join('');
$('cStatus').onclick=e=>{const b=e.target.closest('.chip');if(b){K.status=b.dataset.k;paintC();}};
$('cDio').innerHTML=opts(DIO,'');$('cDio').onchange=()=>{K.dio=$('cDio').value;};
$('cTitle').onchange=()=>{K.title=$('cTitle').value;paintC();if(K.title==='other')$('cTro').focus();};
$('cName').oninput=()=>{K.name=$('cName').value;if(lastAuto){const w=K.name.trim().split(/\s+/);K.last=w.length>1?w[w.length-1]:'';$('cLast').value=K.last;}paintPrev();};
$('cLast').oninput=()=>{K.last=$('cLast').value;lastAuto=!K.last;};
['cTro','cTen','cTes'].forEach(id=>$(id).oninput=()=>{K[{cTro:'tro',cTen:'ten',cTes:'tes'}[id]]=$(id).value;paintPrev();});
['cPhone','cEmail'].forEach(id=>$(id).oninput=()=>{K[id==='cPhone'?'phone':'email']=$(id).value;csums();});
const paintCPhoto=photoInput($('cPhoto'),$('cPhotoPv'),$('cPhotoX'),()=>K.photo,v=>{K.photo=v;paintCPhoto();paintPrev();},()=>K.name?ini(K.name):'?');
function paintCPar(){$('cPar').innerHTML=K.asg.map((r,i)=>`<div class="rw asg" data-i="${i}">${picker('p',r.p)}<select class="r" aria-label="Rolul">${opts(ROLE,r.r)}</select>${ctl(i)}</div>`).join('');paintPrev();}
bindPicker($('cPar'),'p',(i,id)=>{K.asg[i].p=id;paintCPar();if(!id)$('cPar').querySelector(`.rw[data-i="${i}"] .pq`)?.focus();});
$('cPar').addEventListener('change',e=>{if(e.target.classList.contains('r')){K.asg[+e.target.closest('.rw').dataset.i].r=e.target.value;paintPrev();}});
$('addCpar').onclick=()=>{K.asg.push({p:0,r:K.group==='deacon'?'deacon':(K.group==='hierarch'?'bishop':'parish-priest')});paintCPar();$('cPar').querySelector('.rw:last-child .pq')?.focus();};
rowsMove($('cPar'),()=>K.asg,paintCPar);
function rank(){return K.title==='other'?K.tro:(TLABEL[K.title]||'');}
function paintPrev(){$('cRk').textContent=rank();$('cNm').textContent=K.name||'Prenume Nume';
 const ps=K.asg.filter(a=>a.p).map(a=>{const p=parById(a.p);return p?lbl(ROLE,a.r)+', '+p[1]+(p[4]?' – '+p[4]:''):'';}).filter(Boolean);$('cPs').textContent=ps.length?ps.join(' · '):'Fără parohie';
 const src=K.photo&&!K.photo.none?K.photo.src:'';const av=$('cAv');av.style.backgroundImage=src?`url("${src}")`:'';av.textContent=src?'':ini(K.name);paintCPhoto();csums();}
function csums(){const n=K.asg.filter(a=>a.p).length;$('c1').textContent=K.name?lbl(GROUP,K.group)+(K.status==='retired'?' · pensionar':''):'';$('c2').textContent=n?n+(n===1?' parohie':' parohii'):'';$('c3').textContent=[K.phone?'tel.':'',K.email?'e-mail':'',K.photo&&!K.photo.none?'fotografie':''].filter(Boolean).join(' · ');}
function paintC(){$('cGroup').querySelectorAll('.chip').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.k===K.group)));$('cStatus').querySelectorAll('.chip').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.k===K.status)));
 const list=TITLE[K.group].map(k=>[k,TLABEL[k]]);if(K.title&&K.title!=='other'&&!TITLE[K.group].includes(K.title)&&TLABEL[K.title])list.push([K.title,TLABEL[K.title]]);
 $('cTitle').innerHTML=(K.title===''?'<option value="" selected>— fără titlu —</option>':'')+opts([...list,['other','Alt titlu (scriu eu)…']],K.title);$('cCustom').hidden=K.title!=='other';$('cDio').value=K.dio;paintPrev();}
function loadC(d){K=d;const w=d.name.trim().split(/\s+/);lastAuto=!d.last||d.last===w[w.length-1];['Name','Last','Tro','Ten','Tes','Phone','Email'].forEach(x=>$('c'+x).value=K[x.toLowerCase()]||'');
 paintC();paintCPar();$('cft').textContent=EDIT?'Modificați fișa clericului':'Cleric nou';$('cSave').textContent=EDIT?'Salvează modificările':'Salvează';$('cCancel').hidden=!EDIT;$('cErr').textContent='';}
$('cCancel').onclick=()=>{if(BUSY)return;EDIT=0;loadC(blankC());renderList();};
$('cf').onsubmit=async e=>{e.preventDefault();if(BUSY)return;const miss=[];if(!K.name.trim())miss.push('numele');if(K.title==='other'&&!K.tro.trim())miss.push('titlul în română');if(K.asg.some(a=>!a.p))miss.push('parohia (sau scoateți rândul gol)');
 if(miss.length){$('cErr').textContent='Lipsește: '+miss.join(', ')+'.';return;}
 BUSY=true;$('cErr').textContent='';$('cSave').disabled=true;$('cSave').textContent='Se salvează…';
 try{const ph=await sendPhoto(K.photo);
  const j=await post({action:'dir_cl_save',id:EDIT||'',name:K.name,last:K.last,group:K.group,title:K.title,tro:K.tro,ten:K.ten,tes:K.tes,dio:K.dio,status:K.status,phone:K.phone,email:K.email,asg:JSON.stringify(K.asg.map(a=>({p:a.p,r:a.r}))),...ph});
  mergeC([j.item]);const was=EDIT;EDIT=0;loadC(blankC());renderList();
  $('cok').textContent=(was?'Modificările au fost salvate.':'Clericul a fost adăugat.')+(j.item.asg.length?' Paginile parohiilor au fost actualizate.':'');$('cok').hidden=false;setTimeout(()=>$('cok').hidden=true,8000);$('cf').scrollIntoView({behavior:'smooth'});}
 catch(x){$('cErr').textContent=x.message;}finally{BUSY=false;$('cSave').disabled=false;if($('cSave').textContent.indexOf('Se ')===0)$('cSave').textContent=EDIT?'Salvează modificările':'Salvează';}};
setTab(D.can[D.mode]?D.mode:(D.can.cl?'cl':'par'));
})();
