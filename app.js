/* ============================================================
   CineStream — Cinejoy-style clone (TMDB catalog + VidFast player)
   ============================================================ */

/* ---- CONFIG ---------------------------------------------------
   TMDB: put your v4 Read Access Token here (or a v3 key in TMDB_V3).
   In production, proxy this server-side. For a static demo we call
   TMDB directly and fall back to bundled sample data if it fails. */
const TMDB_TOKEN = ""; // v4 Read Access Token (unused here)
const TMDB_V3    = "b83c6131d899befe848a35addfd86c4a";  // v3 api_key — real data
/* ---- player settings (persisted) ----------------------------- */
const SETTINGS_KEY='cs_settings';
const DEFAULT_SETTINGS={autoPlay:true,autoNext:true,nextButton:true,hideServer:false,fullscreenButton:true,chromecast:true,sub:'en',server:'',dataSaver:false};
function getSettings(){try{return {...DEFAULT_SETTINGS,...(JSON.parse(localStorage.getItem(SETTINGS_KEY))||{})}}catch{return {...DEFAULT_SETTINGS}}}
function saveSettings(s){localStorage.setItem(SETTINGS_KEY,JSON.stringify(s));}
const dataSaverOn=()=>getSettings().dataSaver || (navigator.connection&&navigator.connection.saveData);

const IMG = (p, size='w500') => {
  if(!p) return placeholder();
  if(dataSaverOn()){ if(size==='w500')size='w300'; if(size==='w1280')size='w780'; }
  return `https://image.tmdb.org/t/p/${size}${p}`;
};
const VIDFAST = 'https://vidfast.vc';
const ACCENT = '95FF50';

const PROVIDERS = [
  {id:8,name:'Netflix',logo:'/rK1KljqmbvO9HQa1PBFLILWah72.png'},
  {id:9,name:'Amazon Prime Video',logo:'/gMZdpavHmxFNnLpMHwVxfqeux2g.png'},
  {id:337,name:'Disney Plus',logo:'/5eZ872CghnHFLB1j8grszbrx0dx.png'},
  {id:350,name:'Apple TV+',logo:'/9icYBfYFcwgCbky5VdGUIKJ4C5i.png'},
  {id:2,name:'Apple TV',logo:'/qdEGArH3lKfFnAtYXMkSYk5wxuG.png'},
  {id:15,name:'Hulu',logo:'/44uAnmSqvA4yBOdbPWN8YgQHjWm.png'},
  {id:1899,name:'HBO Max',logo:'/skypuy7SXuugIQeYg0IglmzoKaS.png'},
  {id:2303,name:'Paramount Plus',logo:'/4N4BMd0Mm0kHAmF7RZgL5lW3cwc.png'},
  {id:386,name:'Peacock Premium',logo:'/a1UIdq5BrkcAxnxcUhFsNbXnxeu.png'},
  {id:283,name:'Crunchyroll',logo:'/uFL3c4Cq8M6WoLymlC5Y8bmGytV.png'},
  {id:43,name:'Starz',logo:'/h25xjouKmiSmFiiqw0aDXbxGZo7.png'},
  {id:526,name:'AMC+',logo:'/wsCUflcmL4dCkzBUaGP8cj1cTkh.png'},
  {id:34,name:'MGM Plus',logo:'/q63Uzpu7JAs566vA2G23Lk7LcID.png'},
  {id:188,name:'YouTube Premium',logo:'/eWHpKZihdiY617s5LipWJWvN28U.png'},
  {id:192,name:'YouTube',logo:'/5Maob4o5w8oZnNeYpCDyVFD3M7X.png'},
  {id:73,name:'Tubi TV',logo:'/9dEuvA8wg5TSeFBZlPxSVxFdimJ.png'},
  {id:300,name:'Pluto TV',logo:'/fN4czqaMQNLeF6sSSIjGbAWzvwK.png'},
];

/* ---- TMDB fetch with cache + fallback ------------------------- */
const cache = new Map();
async function tmdb(path, params={}){
  const key = path + JSON.stringify(params);
  if(cache.has(key)) return cache.get(key);
  const url = new URL('https://api.themoviedb.org/3'+path);
  Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,v));
  const headers = {};
  if(TMDB_TOKEN) headers['Authorization'] = 'Bearer '+TMDB_TOKEN;
  else if(TMDB_V3) url.searchParams.set('api_key', TMDB_V3);
  const r = await fetch(url, {headers});
  if(!r.ok) throw new Error('TMDB '+r.status);
  const j = await r.json();
  cache.set(key, j);
  return j;
}

/* ---- helpers -------------------------------------------------- */
const $ = s => document.querySelector(s);
const app = $('#app');
const year = d => (d||'').slice(0,4);
const rating = v => (v||0).toFixed(1);
const mtype = m => m.media_type || (m.first_air_date || m.name ? 'tv' : 'movie');
const mtitle = m => m.title || m.name || 'Untitled';
const mdate = m => m.release_date || m.first_air_date || '';
function placeholder(){return 'data:image/svg+xml;utf8,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="450"><rect width="100%" height="100%" fill="#1a1a1a"/><text x="50%" y="50%" fill="#555" font-family="sans-serif" font-size="20" text-anchor="middle">No Image</text></svg>');}

/* ---- local history + continue watching ----------------------- */
const HISTORY_KEY='cs_history', PROGRESS_KEY='vidFastProgress';
function getHistory(){try{return JSON.parse(localStorage.getItem(HISTORY_KEY))||[]}catch{return[]}}
function pushHistory(m){
  const t=mtype(m); let h=getHistory().filter(x=>!(x.id===m.id&&x.mediaType===t));
  h.unshift({id:m.id,mediaType:t,title:mtitle(m),poster:m.poster_path,ts:Date.now()});
  localStorage.setItem(HISTORY_KEY, JSON.stringify(h.slice(0,20)));
}
function getProgress(){try{return JSON.parse(localStorage.getItem(PROGRESS_KEY))||{}}catch{return{}}}

/* ============================================================
   COMPONENTS
   ============================================================ */
function posterCard(m){
  const t=mtype(m);
  return `<a href="#/${t==='tv'?'series':'movie'}/${m.id}" class="card group block w-[140px] md:w-[170px] shrink-0">
    <div class="relative rounded-2xl overflow-hidden bg-surface2 shadow-lg">
      <img loading="lazy" src="${IMG(m.poster_path)}" alt="${mtitle(m)}" class="w-full aspect-[2/3] object-cover transition duration-300 group-hover:scale-[1.03]"/>
      <span class="absolute top-2 left-2 text-[11px] font-semibold px-1.5 py-0.5 rounded-md bg-black/70 text-accent transition group-hover:opacity-0">★ ${rating(m.vote_average)}</span>
      <div class="absolute inset-0 flex flex-col items-center justify-center text-center p-3 opacity-0 group-hover:opacity-100 transition duration-300 bg-gradient-to-t from-black/95 via-black/35 to-black/10 backdrop-blur-[1px]">
        <span class="grid place-items-center w-14 h-14 rounded-full bg-white text-black shadow-2xl mb-4 translate-y-2 group-hover:translate-y-0 transition duration-300">
          <svg class="w-7 h-7 ml-1" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
        </span>
        <p class="font-bold text-sm leading-tight drop-shadow-md">${mtitle(m)}</p>
        <p class="text-xs text-white/80 mt-1">${year(mdate(m))} <span class="text-accent">★ ${rating(m.vote_average)}</span></p>
      </div>
    </div>
    <p class="mt-2 text-sm font-medium clamp2 leading-tight group-hover:text-white">${mtitle(m)}</p>
    <p class="text-xs text-muted">${year(mdate(m))}</p>
  </a>`;
}
function skeletonRow(){return `<div class="flex gap-3 overflow-hidden px-4 md:px-8">${Array(8).fill('<div class="w-[140px] md:w-[170px] shrink-0"><div class="skel rounded-2xl aspect-[2/3]"></div><div class="skel h-3 rounded mt-2 w-3/4"></div></div>').join('')}</div>`;}

function scrollRail(btn,dir){
  const wrap=btn.parentElement;
  const rail=wrap.querySelector('.row-scroll');
  if(rail)rail.scrollBy({left:dir*Math.max(420,rail.clientWidth*.78),behavior:'smooth'});
}
function row(title, items, viewAll){
  if(!items||!items.length) return '';
  return `<section class="mt-8 fade-in">
    <div class="flex items-center justify-between px-4 md:px-8 mb-3">
      <h2 class="font-head font-bold text-lg md:text-xl">${title}</h2>

    </div>
    <div class="relative group/rail">
      <button onclick="scrollRail(this,-1)" aria-label="Scroll left" class="hidden md:grid absolute left-3 top-[38%] -translate-y-1/2 z-20 place-items-center w-10 h-10 rounded-full bg-black/65 backdrop-blur border border-white/15 opacity-0 group-hover/rail:opacity-100 transition"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg></button>
      <div class="row-scroll no-sb flex gap-3 md:gap-4 overflow-x-auto px-4 md:px-8 pb-2">
        ${items.map(posterCard).join('')}
      </div>
      <button onclick="scrollRail(this,1)" aria-label="Scroll right" class="hidden md:grid absolute right-3 top-[38%] -translate-y-1/2 z-20 place-items-center w-10 h-10 rounded-full bg-black/65 backdrop-blur border border-white/15 opacity-0 group-hover/rail:opacity-100 transition"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></button>
    </div>
  </section>`;
}

/* ============================================================
   PAGES
   ============================================================ */
/* ---- curated row definitions (real TMDB discover queries) ---- */
const CURATED = [
  {title:'Movies on Netflix', type:'movie', path:'/discover/movie', params:{with_watch_providers:8,watch_region:'US',sort_by:'popularity.desc'}},
  {title:'TV Series on Netflix', type:'tv', path:'/discover/tv', params:{with_watch_providers:8,watch_region:'US',sort_by:'popularity.desc'}},
  {title:'Award Winning Movies', type:'movie', path:'/discover/movie', params:{sort_by:'vote_average.desc','vote_count.gte':3000}},
  {title:'Award Winning Shows', type:'tv', path:'/discover/tv', params:{sort_by:'vote_average.desc','vote_count.gte':1000}},
  {title:'Top Rated Movies', type:'movie', path:'/movie/top_rated', viewAll:'#/category/movie/top_rated'},
  {title:'Top Rated Series', type:'tv', path:'/tv/top_rated', viewAll:'#/category/tv/top_rated'},
  {title:'Psychological Thrillers', type:'movie', path:'/discover/movie', params:{with_genres:'53,9648',sort_by:'vote_average.desc','vote_count.gte':1500}},
  {title:'Halloween Horror', type:'movie', path:'/discover/movie', params:{with_genres:27,sort_by:'popularity.desc'}},
  {title:'Sci-Fi & Mind-Benders', type:'movie', path:'/discover/movie', params:{with_genres:878,sort_by:'vote_average.desc','vote_count.gte':2000}},
  {title:'Based on a True Story', type:'movie', path:'/discover/movie', params:{with_keywords:9672,sort_by:'popularity.desc'}},
  {title:'Animation for Everyone', type:'movie', path:'/discover/movie', params:{with_genres:16,sort_by:'popularity.desc'}},
  {title:'Comedy Gold', type:'movie', path:'/discover/movie', params:{with_genres:35,sort_by:'vote_average.desc','vote_count.gte':2000}},
];

let heroTimer=null;
async function homePage(){
  app.innerHTML = `<div id="hero" class="relative"></div>
    <div id="rows" class="relative z-10">${skeletonRow()}${skeletonRow()}</div>
    <div id="homeFooter" class="relative z-10"></div>`;

  const [movies, tv] = await Promise.all([
    tmdb('/trending/movie/week'), tmdb('/trending/tv/week')
  ]);
  const slides = (movies.results||[]).filter(m=>m.backdrop_path).slice(0,7);
  // fetch title logos for each hero slide (Cinejoy uses the logo image, not text)
  await Promise.all(slides.map(async m=>{
    try{ const img=await tmdb(`/movie/${m.id}/images`,{include_image_language:'en,null'});
      const lg=(img.logos||[]).find(l=>l.file_path.endsWith('.png'))||(img.logos||[])[0];
      m._logo=lg?lg.file_path:null;
    }catch{ m._logo=null; }
  }));
  renderHeroCarousel(slides);

  let html='';
  // Continue Watching directly under hero
  const cw = buildContinue();
  if(cw.length) html += rowRaw('Continue Watching', cw);
  html += providerRail();
  html += row('Trending Movies', movies.results, '#/category/movie/trending');
  html += row('Trending Series', tv.results, '#/category/tv/trending');
  $('#rows').innerHTML = html;

  // "Because you watched" rows (real recommendations)
  const hist = getHistory().slice(0,3);
  for(const h of hist){
    const rec = await tmdb(`/${h.mediaType}/${h.id}/recommendations`);
    if(rec.results&&rec.results.length) $('#rows').insertAdjacentHTML('beforeend', row(`Because you watched "${h.title}"`, rec.results.slice(0,18)));
  }

  // Curated rows — fetched sequentially so the page fills progressively
  for(const c of CURATED){
    const data = await tmdb(c.path, c.params||{});
    const items = (data.results||[]).filter(x=>x.poster_path).map(x=>({...x, media_type:c.type}));
    if(items.length) $('#rows').insertAdjacentHTML('beforeend', row(c.title, items, c.viewAll));
  }

  $('#homeFooter').innerHTML = footer();
}

/* ---- HERO CAROUSEL (auto-rotating trending) ------------------ */
function renderHeroCarousel(slides){
  if(heroTimer){clearInterval(heroTimer);heroTimer=null;}
  const el=$('#hero'); if(!el||!slides.length) return;
  el.innerHTML=`
    <div class="hero-stage">
      ${slides.map((m,i)=>heroSlide(m,i)).join('')}
    </div>
    <div class="hero-dots">${slides.map((_,i)=>`<button data-dot="${i}" class="hdot ${i===0?'on':''}" aria-label="Go to slide ${i+1}"></button>`).join('')}</div>`;
  let idx=0;
  const stage=el.querySelector('.hero-stage');
  const show=(n)=>{
    idx=(n+slides.length)%slides.length;
    stage.querySelectorAll('.hslide').forEach((s,i)=>s.classList.toggle('active',i===idx));
    const dots=[...el.querySelectorAll('.hdot')];
    dots.forEach(d=>d.classList.remove('on'));
    // Force a reflow so the six-second progress animation restarts on every slide.
    void dots[idx].offsetWidth;
    dots[idx].classList.add('on');
    setWashFromImage(slides[idx].backdrop_path);
  };
  setWashFromImage(slides[0].backdrop_path);
  el.querySelectorAll('.hdot').forEach(d=>d.onclick=()=>{show(+d.dataset.dot);restart();});
  const restart=()=>{clearInterval(heroTimer);heroTimer=setInterval(()=>show(idx+1),6000);};
  restart();
}
function heroSlide(m,i){
  const t=mtype(m);
  const detail=`#/${t==='tv'?'series':'movie'}/${m.id}`;
  const watch=`#/watch/${t==='tv'?'tv':'movie'}/${m.id}${t==='tv'?'/1/1':''}`;
  const star=`<svg viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 lg:w-[18px] lg:h-[18px]"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>`;
  const cal=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 lg:w-4 lg:h-4"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>`;
  const heart=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5 lg:w-4 lg:h-4"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/></svg>`;
  const dot=`<span class="text-white/50 select-none text-[8px] lg:text-[10px]">•</span>`;
  const titleBlock = m._logo
    ? `<a href="${detail}" class="block"><img class="max-h-28 lg:max-h-48 object-contain origin-center lg:origin-left drop-shadow-2xl" src="${IMG(m._logo,'w500')}" alt="${mtitle(m)}"/></a>`
    : `<a href="${detail}"><h1 class="font-head font-extrabold text-4xl lg:text-6xl leading-[1.05] drop-shadow-2xl">${mtitle(m)}</h1></a>`;
  return `<div class="hslide ${i===0?'active':''}">
    <div class="hslide-img"><img src="${IMG(m.backdrop_path,'w1280')}" alt=""/></div>
    <div class="hslide-fadeL"></div><div class="hslide-fadeB"></div>
    <div class="absolute inset-0 flex items-end pb-[40vh] px-6 lg:pb-[48vh] lg:pl-16 z-10">
      <div class="w-full max-w-2xl gap-4 lg:gap-6 flex flex-col items-center lg:items-start text-center lg:text-left mx-auto lg:mx-0">
        <div class="gap-4 lg:gap-6 flex flex-col items-center lg:items-start w-full">
          ${titleBlock}
          <div class="flex flex-wrap items-center justify-center lg:justify-start gap-2.5 lg:gap-3 text-white font-medium drop-shadow-md text-sm lg:text-base">
            <div class="flex items-center gap-1.5 text-white fill-white">${star}<span class="font-semibold">${rating(m.vote_average)}<span class="text-white/70">/10</span></span></div>
            ${dot}
            <div class="flex items-center gap-1.5">${cal}<span>${year(mdate(m))}</span></div>
            ${dot}
            <div class="flex items-center gap-1.5">${heart}<span>${genreName(m)}</span></div>
          </div>
          <p class="text-base lg:text-lg text-white line-clamp-3 max-w-xl drop-shadow-md font-medium hidden lg:block">${m.overview||''}</p>
          <p class="text-sm text-white line-clamp-2 max-w-xl drop-shadow-md font-medium lg:hidden text-center">${m.overview||''}</p>
        </div>
        <div class="flex items-center justify-center lg:justify-start gap-3 w-full">
          <a href="${watch}" class="relative rounded-full inline-flex items-center justify-center transition-all duration-200 active:scale-95 font-bold tracking-wide select-none h-[52px] bg-accent text-black hover:scale-105 shadow-xl shadow-black/10 px-6 text-lg min-w-[130px]">
            <svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 mr-2"><path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/></svg> Play
          </a>
          <div class="hero-action-pill inline-flex items-center h-[52px] shrink-0 rounded-full bg-white/10 backdrop-blur-[20px] backdrop-saturate-150 border border-white/10 shadow-lg shadow-black/5">
            <a href="${detail}" aria-label="Add to list" class="group/btn flex items-center justify-center h-full px-5 rounded-l-full transition-colors hover:bg-white/10 active:bg-white/20">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6 text-white transition-transform duration-300 group-hover/btn:scale-110"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
            </a>
            <div class="w-px h-6 bg-white/25 shrink-0"></div>
            <a href="${detail}" aria-label="More Info" class="group/btn flex items-center justify-center h-full px-5 rounded-r-full transition-colors hover:bg-white/10 active:bg-white/20">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-6 h-6 text-white transition-transform duration-300 group-hover/btn:scale-110"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>`;
}
const GENRE_MAP={28:'Action',12:'Adventure',16:'Animation',35:'Comedy',80:'Crime',99:'Documentary',18:'Drama',10751:'Family',14:'Fantasy',36:'History',27:'Horror',10402:'Music',9648:'Mystery',10749:'Romance',878:'Science Fiction',10770:'TV Movie',53:'Thriller',10752:'War',37:'Western',10759:'Action & Adventure',10762:'Kids',10763:'News',10764:'Reality',10765:'Sci-Fi & Fantasy',10766:'Soap',10767:'Talk',10768:'War & Politics'};
function genreName(m){const g=(m.genre_ids||[])[0];return GENRE_MAP[g]||'Featured';}

/* ---- extract an average color from the currently displayed backdrop ---- */
let washRequestId=0;
function setWashFromImage(path, colorStrength=.9, sampleStart=.88){
  const requestId=++washRequestId;
  if(!path){document.documentElement.style.setProperty('--wash','#0d1420');return;}
  const washEl=$('#pageWash');
  if(washEl)washEl.innerHTML=`<img class="page-wash-img" src="${IMG(path,'w1280')}" alt=""/>`;
  const img=new Image(); img.crossOrigin='anonymous';
  img.onload=()=>{
    // Ignore an older carousel image that finishes loading after a detail page opens.
    if(requestId!==washRequestId)return;
    try{
      const c=document.createElement('canvas'); const w=c.width=32, h=c.height=18;
      const ctx=c.getContext('2d'); ctx.drawImage(img,0,0,w,h);
      const d=ctx.getImageData(0,0,w,h).data;
      let r=0,g=0,b=0,n=0;
      // Sample the lower part of the backdrop so the page continues
      // naturally from the color visible at the image's bottom edge.
      const startRow=Math.floor(h*sampleStart);
      for(let y=startRow;y<h;y++){
        for(let x=0;x<w;x++){
          const i=(y*w+x)*4;
          r+=d[i];g+=d[i+1];b+=d[i+2];n++;
        }
      }
      r=Math.round(r/n);g=Math.round(g/n);b=Math.round(b/n);
      // Preserve the edge color so the continuation does not introduce a new tint.
      const mix=(v)=>Math.round(v*colorStrength);
      document.documentElement.style.setProperty('--wash',`rgb(${mix(r)},${mix(g)},${mix(b)})`);
    }catch(e){ document.documentElement.style.setProperty('--wash','#0d1420'); }
  };
  img.onerror=()=>{if(requestId===washRequestId)document.documentElement.style.setProperty('--wash','#0d1420');};
  img.src=`https://image.tmdb.org/t/p/w300${path}`;
}

/* ---- footer -------------------------------------------------- */
function footer(){
  return `<footer class="mt-16 py-10 text-center">
    <a href="#/" class="inline-flex items-center gap-2 mb-3">
      <span class="grid place-items-center w-8 h-8 rounded-lg bg-accent text-black font-head font-extrabold">C</span>
      <span class="font-head font-extrabold text-lg">Cine<span class="text-accent">Stream</span></span>
    </a>
    <p class="text-xs text-muted max-w-md mx-auto px-4">CineStream does not host, store, or distribute any media files. All content is sourced from third-party providers. Metadata by TMDB — this product uses the TMDB API but is not endorsed or certified by TMDB.</p>
  </footer>`;
}

function providerRail(){
  return `<section class="mt-10 group/provider">
    <h2 class="font-head font-bold text-lg md:text-xl px-6 lg:px-16 mb-4">Browse by Provider</h2>
    <div class="relative">
      <button onclick="scrollRail(this,-1)" aria-label="Scroll providers left" class="hidden lg:flex absolute left-4 top-1/2 -translate-y-1/2 z-[60] w-12 h-12 bg-transparent drop-shadow-lg items-center justify-center hover:scale-110 opacity-0 group-hover/provider:opacity-100 transition"><svg class="w-10 h-10 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg></button>
      <button onclick="scrollRail(this,1)" aria-label="Scroll providers right" class="hidden lg:flex absolute right-4 top-1/2 -translate-y-1/2 z-[60] w-12 h-12 bg-transparent drop-shadow-lg items-center justify-center hover:scale-110 opacity-0 group-hover/provider:opacity-100 transition"><svg class="w-10 h-10 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></button>
      <div class="row-scroll no-sb flex gap-5 lg:gap-6 overflow-x-auto overflow-y-visible pb-4 pt-2 px-6 lg:px-16 items-start" style="mask-image:linear-gradient(to right,transparent 0%,black 4%,black 96%,transparent 100%);-webkit-mask-image:linear-gradient(to right,transparent 0%,black 4%,black 96%,transparent 100%)">
        ${PROVIDERS.map(p=>`<a href="#/provider/${p.id}" class="group/tile flex-none flex flex-col items-center gap-2 w-[76px] lg:w-[92px]">
          <div class="w-16 h-16 lg:w-20 lg:h-20 rounded-2xl overflow-hidden bg-white/5 ring-1 ring-white/10 shadow-lg transition-all duration-300 group-hover/tile:scale-105 group-hover/tile:ring-white/30">
            <img loading="lazy" src="${IMG(p.logo,'w154')}" alt="${p.name}" class="w-full h-full object-cover"/>
          </div><span class="text-[11px] lg:text-xs font-medium text-white/55 group-hover/tile:text-white text-center leading-tight line-clamp-2 transition-colors">${p.name}</span>
        </a>`).join('')}
      </div>
    </div>
  </section>`;
}

async function detailPage(kind, id){
  const type = kind==='series'?'tv':'movie';
  app.innerHTML = `<div id="dAmbient" class="pointer-events-none"></div><div class="pt-24 px-4 md:px-8">${skeletonRow()}</div>`;
  const m = await tmdb(`/${type}/${id}`, {append_to_response:'credits,recommendations,similar,watch/providers,videos,images,external_ids,content_ratings,release_dates', include_image_language:'en,null'});
  pushHistory({...m, id:+id, media_type:type});

  const genres=(m.genres||[]).map(g=>g.name);
  const cast=(m.credits?.cast||[]).slice(0,12);
  const recs=(m.recommendations?.results||m.similar?.results||[]).filter(x=>x.poster_path).slice(0,18).map(x=>({...x,media_type:type}));
  const logo=(m.images?.logos||[]).find(l=>l.file_path.endsWith('.png'))||(m.images?.logos||[])[0];
  const creators=(m.created_by||[]).map(c=>c.name).join(', ');
  const director=(m.credits?.crew||[]).find(c=>c.job==='Director');
  const imdb=m.external_ids?.imdb_id;
  const runtimeMinutes=m.runtime||m.episode_run_time?.[0]||0;
  const runtime=runtimeMinutes?`${Math.floor(runtimeMinutes/60)?Math.floor(runtimeMinutes/60)+'h ':''}${runtimeMinutes%60}m`:'';
  const rate = pctRating(m.vote_average);
  // content rating
  let cert='';
  if(type==='tv'){ const r=(m.content_ratings?.results||[]).find(x=>x.iso_3166_1==='US'); cert=r?.rating||''; }
  else { const r=(m.release_dates?.results||[]).find(x=>x.iso_3166_1==='US'); cert=r?.release_dates?.find(d=>d.certification)?.certification||''; }
  const yrs = type==='tv'
    ? `${year(m.first_air_date)}${m.status==='Ended'&&m.last_air_date?'–'+year(m.last_air_date):m.in_production?'– ':''}`
    : year(m.release_date);

  // glass stats panel
  const stats = type==='tv' ? [
    ['Status', m.status||'—'], ['Language',(m.original_language||'').toUpperCase()],
    ['First Aired', fmtDate(m.first_air_date)], ['Last Aired', fmtDate(m.last_air_date)],
    ['Seasons', m.number_of_seasons||'—'], ['Episodes', m.number_of_episodes||'—'],
  ] : [
    ['Status', m.status||'—'], ['Language',(m.original_language||'').toUpperCase()],
    ['Released', fmtDate(m.release_date)], ['Runtime', runtime||'—'],
    ['Budget', m.budget?('$'+(m.budget/1e6).toFixed(0)+'M'):'—'], ['Revenue', m.revenue?('$'+(m.revenue/1e6).toFixed(0)+'M'):'—'],
  ];

  app.innerHTML=`
  <div id="dAmbient" class="pointer-events-none"></div>
  <article class="relative z-10">
    <!-- HERO -->
    <div class="relative min-h-[82vh] lg:min-h-[92vh] overflow-hidden">
      <!-- Sharp artwork dissolves into the same backdrop blurred behind the whole page. -->
      <img src="${IMG(m.backdrop_path,'w1280')}" class="absolute inset-0 w-full h-full object-cover" style="object-position:top center;filter:brightness(1.02);-webkit-mask-image:linear-gradient(to bottom,#000 0%,#000 44%,rgba(0,0,0,.9) 58%,rgba(0,0,0,.38) 78%,transparent 96%);mask-image:linear-gradient(to bottom,#000 0%,#000 44%,rgba(0,0,0,.9) 58%,rgba(0,0,0,.38) 78%,transparent 96%)" alt=""/>
      <div class="absolute inset-0" style="background:linear-gradient(to bottom,rgba(0,0,0,var(--detail-top,.16)) 0%,rgba(0,0,0,var(--detail-mid,.2)) 45%,rgba(0,0,0,var(--detail-bottom,.58)) 100%),linear-gradient(to right,rgba(3,3,3,var(--detail-left,.68)) 0%,rgba(3,3,3,var(--detail-left-mid,.46)) 32%,rgba(3,3,3,.18) 68%,rgba(3,3,3,.08) 100%);-webkit-mask-image:linear-gradient(to bottom,#000 0%,#000 88%,transparent 100%);mask-image:linear-gradient(to bottom,#000 0%,#000 88%,transparent 100%)"></div>

      <div class="relative max-w-[1400px] mx-auto px-6 lg:px-16 pt-24 lg:pt-28 pb-8">
        <div class="detail-copy w-full max-w-[700px] lg:max-w-[650px] flex flex-col items-start text-left">
          ${logo
            ? `<img src="${IMG(logo.file_path,'w500')}" alt="${mtitle(m)}" class="max-h-20 lg:max-h-36 max-w-[75%] lg:max-w-[500px] w-auto object-contain drop-shadow-2xl mb-3 lg:mb-4"/>`
            : `<h1 class="font-head font-extrabold text-4xl md:text-6xl drop-shadow-2xl mb-4">${mtitle(m)}</h1>`}
          <p class="text-sm lg:text-lg text-white/90 font-medium">${genres.join('  •  ')}</p>

          <!-- actions -->
          <div class="flex items-center justify-start gap-3 mt-5 lg:mt-6 flex-wrap">
            <a href="#/watch/${type}/${id}${type==='tv'?'/1/1':''}" class="h-11 min-w-[120px] px-6 rounded-full inline-flex items-center justify-center gap-2 bg-white text-black font-bold shadow-xl shadow-black/15 hover:bg-white/90 hover:scale-105 active:scale-95 transition">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M5 5a2 2 0 0 1 3-1.7l12 7a2 2 0 0 1 0 3.4l-12 7A2 2 0 0 1 5 19z"/></svg> Play
            </a>
            <button class="grid place-items-center w-11 h-11 rounded-full bg-white/10 backdrop-blur-xl border border-white/10 hover:bg-white/20 hover:border-white/20 transition" aria-label="Add to list" title="Add to list"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg></button>
            <button class="grid place-items-center w-11 h-11 rounded-full bg-white/10 backdrop-blur-xl border border-white/10 hover:bg-white/20 hover:border-white/20 transition" aria-label="Download" title="Download"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0 5-5m-5 5-5-5M5 21h14"/></svg></button>
            <button class="grid place-items-center w-11 h-11 rounded-full bg-white/10 backdrop-blur-xl border border-white/10 hover:bg-white/20 hover:border-white/20 transition" aria-label="Mark as watched" title="Mark as watched"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.7 5.1A10.7 10.7 0 0 1 22 12a11 11 0 0 1-1.5 2.8M14.1 14.2a3 3 0 0 1-4.3-4.3M17.5 17.5A10.8 10.8 0 0 1 2 12a10.8 10.8 0 0 1 4.5-5.5M2 2l20 20"/></svg></button>
          </div>

          <!-- meta line -->
          <div class="flex items-center gap-2.5 mt-5 text-sm text-white/85 flex-wrap">
            <span>${yrs}</span>${runtime?`<span>${runtime}</span>`:''}
            ${cert?`<span class="border border-white/30 rounded px-1.5 py-0.5 text-xs">${cert}</span>`:''}
            <span class="text-accent">★ ${rating(m.vote_average)}</span>
            <span class="text-white/50">${(m.vote_count||0)>999?((m.vote_count/1000).toFixed(0)+'k'):m.vote_count} votes</span>
          </div>
          ${creators?`<p class="w-full text-sm text-white/60 mt-3"><span class="text-white/40">Creators:</span> <span class="text-white/85">${creators}</span></p>`:''}
          ${director?`<p class="w-full text-sm lg:text-base text-white/60 mt-2"><span class="text-white/40">Director:</span> <a href="#/person/${director.id}" class="text-white/80 hover:text-white hover:underline underline-offset-4">${director.name}</a></p>`:''}

          <p class="w-full mt-4 lg:mt-5 text-sm lg:text-base text-white/70 leading-relaxed clamp3">${m.overview||''}</p>

          <!-- score badges -->
          <div class="flex items-center gap-5 mt-5">
            ${imdb?`<span class="flex items-center gap-1.5"><span class="bg-[#f5c518] text-black font-bold text-xs px-1.5 py-0.5 rounded">IMDb</span><span class="font-semibold">${rating(m.vote_average)}</span></span>`:''}
            <span class="flex items-center gap-1.5"><span class="text-accent text-lg">✦</span><span class="font-semibold">${rate}%</span></span>
            <span class="flex items-center gap-1.5"><span>🍅</span><span class="font-semibold">${Math.min(99,rate+2)}%</span></span>
          </div>
        </div>

        <!-- glass stats panel -->
        <aside class="hidden lg:block absolute right-16 top-60 w-[280px] rounded-xl border border-white/10 bg-white/[0.07] backdrop-blur-md overflow-hidden shadow-xl shadow-black/15">
          ${stats.map((s,i)=>`<div class="flex items-center justify-between px-5 py-3 ${i<stats.length-1?'border-b border-white/10':''}">
            <span class="text-sm text-white/55">${s[0]}</span><span class="text-sm font-semibold">${s[1]}</span></div>`).join('')}
        </aside>
      </div>
    </div>

    <!-- mobile stats strip -->
    <div class="md:hidden max-w-[1300px] mx-auto px-4 -mt-2 mb-2">
      <div class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-2xl border border-white/10 bg-white/[0.07] backdrop-blur-md p-4">
        ${stats.map(s=>`<div class="flex flex-col"><span class="text-[11px] text-white/45">${s[0]}</span><span class="text-sm font-semibold">${s[1]}</span></div>`).join('')}
      </div>
    </div>

    <div class="relative z-20 max-w-[1400px] mx-auto px-6 lg:px-16 lg:-mt-52">
      ${type==='tv'?`
      <!-- EPISODES -->
      <section class="mt-4">
        <div class="flex items-center justify-between gap-3 mb-5">
          <h2 class="font-head font-bold text-xl md:text-2xl shrink-0">Episodes</h2>
          <div class="flex items-center gap-2 overflow-x-auto no-sb">
            <button class="epctl hidden sm:inline-flex whitespace-nowrap">▦ Ratings</button>
            <button class="epctl whitespace-nowrap" id="orderBtn">↕ Oldest</button>
            <button class="epctl hidden sm:inline-flex whitespace-nowrap">◎ Mark watched</button>
            <div class="relative shrink-0">
              <select id="seasonSel" class="appearance-none bg-white/10 border border-white/15 rounded-lg pl-3 pr-8 py-1.5 text-sm cursor-pointer whitespace-nowrap">
                ${m.seasons.filter(s=>s.season_number>0).map(s=>`<option value="${s.season_number}">${s.name}</option>`).join('')}
              </select>
              <svg class="w-4 h-4 absolute right-2 top-2 pointer-events-none text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
            </div>
          </div>
        </div>
        <div id="epGrid" class="grid grid-cols-2 lg:grid-cols-4 gap-x-4 md:gap-x-5 gap-y-6 md:gap-y-7"></div>
      </section>`:''}

      ${cast.length?`
      <section class="mt-14">
        <h2 class="font-head font-bold text-2xl mb-6">Cast</h2>
        <div class="flex gap-6 md:gap-8 overflow-x-auto no-sb pb-2">
          ${cast.map(c=>`<a href="#/person/${c.id}" class="shrink-0 w-24 text-center group">
            <img src="${IMG(c.profile_path,'w185')}" class="w-20 h-20 md:w-24 md:h-24 rounded-full object-cover mx-auto ring-2 ring-white/10 group-hover:ring-accent transition"/>
            <p class="text-sm font-semibold mt-2 clamp2 leading-tight">${c.name}</p>
            <p class="text-xs text-white/50 clamp2">${c.character||''}</p></a>`).join('')}
        </div>
      </section>`:''}

      <section class="mt-14 -mx-4 md:-mx-8">${row('Might Also Like', recs)}</section>
    </div>
    ${footer()}
  </article>`;

  setDetailAmbient(m.backdrop_path);
  // Detail pages remain image-based: no extracted color replaces the backdrop.
  document.documentElement.style.setProperty('--wash','#050505');

  if(type==='tv'&&m.seasons){
    const seasons=m.seasons.filter(s=>s.season_number>0);
    let order='oldest';
    const loadSeason=async(sn)=>{
      $('#epGrid').innerHTML=Array(4).fill('<div><div class="skel rounded-xl aspect-video"></div><div class="skel h-3 rounded mt-2 w-2/3"></div></div>').join('');
      const s=await tmdb(`/tv/${id}/season/${sn}`);
      let eps=(s.episodes||[]);
      if(order==='newest') eps=[...eps].reverse();
      $('#epGrid').innerHTML=eps.map(e=>episodeCard(e,id,sn)).join('')||'<p class="text-muted">No episodes.</p>';
    };
    $('#seasonSel').onchange=e=>loadSeason(e.target.value);
    const ob=$('#orderBtn'); ob.onclick=()=>{order=order==='oldest'?'newest':'oldest';ob.textContent=order==='oldest'?'↕ Oldest':'↕ Newest';loadSeason($('#seasonSel').value);};
    loadSeason(seasons[0].season_number);
  }
}

function episodeCard(e,id,sn){
  return `<a href="#/watch/tv/${id}/${sn}/${e.episode_number}" class="group block">
    <div class="relative rounded-xl overflow-hidden bg-surface2">
      <img loading="lazy" src="${IMG(e.still_path,'w300')}" class="w-full aspect-video object-cover"/>
      <span class="absolute top-2 left-2 text-[11px] font-semibold px-1.5 py-0.5 rounded bg-black/70">E${e.episode_number}</span>
      <span class="absolute top-2 right-2 grid place-items-center w-6 h-6 rounded-full bg-black/60"><svg class="w-3.5 h-3.5 text-white/80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></span>
      ${e.runtime?`<span class="absolute bottom-2 right-2 text-[11px] font-semibold px-1.5 py-0.5 rounded bg-black/70">${e.runtime}m</span>`:''}
      <span class="absolute inset-0 grid place-items-center opacity-0 group-hover:opacity-100 transition bg-black/30"><span class="grid place-items-center w-11 h-11 rounded-full bg-accent text-black"><svg class="w-5 h-5 ml-0.5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span></span>
    </div>
    <p class="text-sm font-semibold mt-2 clamp2 leading-tight">${e.name}</p>
    <p class="text-xs text-white/55 clamp3 mt-1">${e.overview||''}</p>
  </a>`;
}

function pctRating(v){return Math.round((v||0)*10);}
function fmtDate(d){if(!d)return '—';try{return new Date(d).toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'})}catch{return d}}
function setDetailAmbient(bd){
  const el=$('#dAmbient');
  if(el)el.innerHTML=bd?`<img src="${IMG(bd,'w1280')}" class="ambient-img" alt=""/>`:'';
  setDetailContrast(bd);
}
function setDetailContrast(path){
  if(!path)return;
  const img=new Image();img.crossOrigin='anonymous';
  img.onload=()=>{
    try{
      const c=document.createElement('canvas');c.width=32;c.height=18;
      const x=c.getContext('2d');x.drawImage(img,0,0,32,18);
      const d=x.getImageData(0,0,32,18).data;
      let left=0,all=0,ln=0,an=0;
      for(let y=0;y<18;y++)for(let xx=0;xx<32;xx++){
        const i=(y*32+xx)*4,l=(d[i]*.2126+d[i+1]*.7152+d[i+2]*.0722)/255;
        all+=l;an++;if(xx<18){left+=l;ln++;}
      }
      left/=ln;all/=an;
      // Bright artwork receives more shade; already-dark artwork stays vivid.
      const leftShade=Math.min(.76,Math.max(.42,.36+left*.42));
      const midShade=Math.min(.56,Math.max(.28,.22+left*.34));
      const bottomShade=Math.min(.66,Math.max(.38,.32+all*.36));
      const root=document.documentElement.style;
      root.setProperty('--detail-left',leftShade.toFixed(2));
      root.setProperty('--detail-left-mid',midShade.toFixed(2));
      root.setProperty('--detail-top',Math.min(.3,.08+all*.24).toFixed(2));
      root.setProperty('--detail-mid',Math.min(.38,.12+all*.3).toFixed(2));
      root.setProperty('--detail-bottom',bottomShade.toFixed(2));
      root.setProperty('--detail-copy',Math.min(.68,.34+left*.36).toFixed(2));
    }catch{}
  };
  img.src=`https://image.tmdb.org/t/p/w300${path}`;
}
function clearDetailBackground(){
  const wash=$('#pageWash');
  if(!wash)return;
  wash.style.backgroundImage='none';
  wash.style.backgroundSize='';
  wash.style.backgroundPosition='';
  wash.style.backgroundRepeat='';
  wash.innerHTML='';
  ['--detail-left','--detail-left-mid','--detail-top','--detail-mid','--detail-bottom','--detail-copy'].forEach(v=>document.documentElement.style.removeProperty(v));
}

/* ---- PLAYER (VidFast embed) ---------------------------------- */
function playerPage(type, id, season, episode){
  if(localStorage.getItem('cs_active_party')){
    app.innerHTML=`<div class="min-h-screen grid place-items-center"><div class="text-center"><span class="inline-block w-9 h-9 rounded-full border-2 border-white/20 border-t-accent animate-spin"></span><p class="mt-3 text-white/60">Loading this title for the party…</p></div></div>`;
    launchPartyMedia(type,id,season,episode);return;
  }
  const prog=getProgress();
  const key=(type==='tv'?'t':'m')+id;
  let startAt=0;
  const rec=prog[key];
  if(rec){
    if(type==='movie'&&rec.progress) startAt=Math.floor(rec.progress.watched||0);
    if(type==='tv'&&rec.show_progress){const sp=rec.show_progress[`s${season}e${episode}`]; if(sp?.progress) startAt=Math.floor(sp.progress.watched||0);}
  }
  const st=getSettings();
  const p=new URLSearchParams();
  p.set('autoPlay',st.autoPlay); p.set('theme',ACCENT); p.set('title','true'); p.set('poster','true');
  if(st.hideServer) p.set('hideServer','true');
  if(!st.fullscreenButton) p.set('fullscreenButton','false');
  if(!st.chromecast) p.set('chromecast','false');
  if(st.sub) p.set('sub',st.sub);
  if(st.server) p.set('server',st.server);
  if(startAt) p.set('startAt',startAt);
  if(type==='tv'){ p.set('nextButton',st.nextButton); p.set('autoNext',st.autoNext); }
  const src = type==='movie'
    ? `${VIDFAST}/movie/${id}?${p}`
    : `${VIDFAST}/tv/${id}/${season}/${episode}?${p}`;

  app.innerHTML=`<div class="max-w-[1400px] mx-auto px-2 md:px-8 pt-4">
    <div class="flex items-center justify-between gap-3 mb-3 px-2">
      <button onclick="history.back()" class="flex items-center gap-2 text-sm text-muted hover:text-white">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg> Back
      </button>
      <button onclick="openPartyHub()" class="h-10 px-4 rounded-full bg-white/[.08] border border-white/10 text-sm font-bold hover:bg-white/[.13] transition flex items-center gap-2" aria-label="Open Watch Together">
        <svg class="w-4 h-4 text-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg><span class="hidden sm:inline">Watch Together</span>
      </button>
    </div>
    <div class="relative w-full rounded-2xl overflow-hidden bg-black" style="padding-top:56.25%">
      <iframe class="absolute inset-0 w-full h-full" src="${src}" frameborder="0" allowfullscreen allow="encrypted-media;autoplay;fullscreen;picture-in-picture" referrerpolicy="origin"></iframe>
    </div>
    <p class="text-xs text-muted mt-3 px-2">Playback by VidFast${startAt?` • resuming at ${Math.floor(startAt/60)}m ${startAt%60}s`:''}. Progress is saved automatically for Continue Watching.</p>
    <div id="playerRecs" class="mt-6"></div>
  </div>`;

  tmdb(`/${type}/${id}/recommendations`).then(r=>{
    if(r.results?.length) $('#playerRecs').innerHTML=row('Up Next', r.results.slice(0,12));
  });
}

/* ---- VidFast progress listener ------------------------------- */
const VIDFAST_ORIGINS=['https://vidfast.pro','https://vidfast.in','https://vidfast.io','https://vidfast.me','https://vidfast.net','https://vidfast.pm','https://vidfast.xyz','https://vidfast.vc','https://vidfast.bz'];
window.addEventListener('message',({origin,data})=>{
  if(!VIDFAST_ORIGINS.includes(origin)||!data) return;
  if(data.type==='MEDIA_DATA'){ localStorage.setItem(PROGRESS_KEY, JSON.stringify(data.data)); }
});

/* ---- Continue Watching --------------------------------------- */
function buildContinue(){
  const prog=getProgress(); const out=[];
  for(const k in prog){
    const it=prog[k]; if(!it||!it.progress) continue;
    const ratio=(it.progress.watched||0)/(it.progress.duration||1);
    if(ratio>0.02 && ratio<0.95){
      out.push({id:it.id,poster_path:it.poster_path,title:it.title,media_type:it.type,vote_average:0,_key:k,_ratio:ratio,
        _season:it.last_season_watched,_episode:it.last_episode_watched,
        _remaining:Math.max(1,Math.ceil(((it.progress.duration||0)-(it.progress.watched||0))/60))});
    }
  }
  return out.sort((a,b)=>(b.last_updated||0)-(a.last_updated||0));
}
function toggleContinueEdit(btn){
  const section=btn.closest('.continue-section');
  const editing=section.classList.toggle('editing');
  btn.setAttribute('aria-label',editing?'Finish editing':'Edit Continue Watching');
  btn.innerHTML=editing
    ? `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg>`
    : `<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/></svg>`;
}
function removeContinue(key,btn){
  const prog=getProgress();delete prog[key];localStorage.setItem(PROGRESS_KEY,JSON.stringify(prog));
  const card=btn.closest('.continue-card');const section=btn.closest('.continue-section');card.style.opacity='0';card.style.transform='scale(.85)';
  setTimeout(()=>{card.remove();if(section&&!section.querySelector('.continue-card'))section.remove();},220);
}
function rowRaw(title, items){
  return `<section class="continue-section mt-8 fade-in">
    <div class="flex items-center justify-between px-4 md:px-6 lg:px-8 mb-5">
      <h2 class="font-head font-bold text-lg md:text-xl flex items-center gap-2">${title}<svg class="editing-chevron hidden w-4 h-4 text-white/60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></h2>
      <button onclick="toggleContinueEdit(this)" aria-label="Edit Continue Watching" class="grid place-items-center w-9 h-9 rounded-full text-white/60 hover:text-white hover:bg-white/10 transition"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/></svg></button>
    </div>
    <div class="row-scroll no-sb flex gap-4 md:gap-5 overflow-x-auto px-4 md:px-6 lg:px-8 pb-3">
    ${items.map(m=>{const t=m.media_type; const href=t==='tv'?`#/watch/tv/${m.id}/${m._season||1}/${m._episode||1}`:`#/watch/movie/${m.id}`;
      const ep=t==='tv'?`S${m._season||1}:E${m._episode||1} · `:'';
      return `<div class="continue-card relative block w-[230px] md:w-[260px] shrink-0 transition-all duration-200">
        <a href="${href}" class="continue-link block">
          <div class="relative rounded-xl overflow-hidden bg-surface2 shadow-xl">
            <img loading="lazy" src="${IMG(m.poster_path)}" class="w-full aspect-video object-cover" alt="${m.title}"/>
            <div class="absolute bottom-0 inset-x-0 h-1 bg-white/20"><div class="h-full bg-white/90" style="width:${Math.round((m._ratio||0)*100)}%"></div></div>
          </div>
          <p class="mt-2 text-sm font-semibold clamp2 leading-tight">${m.title}</p>
          <p class="mt-1 text-xs text-white/45">${ep}${m._remaining||1}m left</p>
        </a>
        <button onclick="removeContinue('${m._key}',this)" aria-label="Remove ${m.title}" class="continue-remove hidden absolute -top-2 -right-2 z-30 w-7 h-7 rounded-full bg-white text-black place-items-center shadow-xl border border-black/10"><svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
      </div>`;}).join('')}
    </div></section>`;
}
function continuePage(){
  const cw=buildContinue();
  const hist=getHistory();
  app.innerHTML=`<div class="max-w-[1400px] mx-auto pt-28">
    <h1 class="font-head font-extrabold text-2xl md:text-3xl px-4 md:px-8">My List</h1>
    ${cw.length?rowRaw('Continue Watching',cw):''}
    ${hist.length?row('Recently Opened', hist.map(h=>({id:h.id,poster_path:h.poster,title:h.title,name:h.title,media_type:h.mediaType,vote_average:0}))):''}
    ${(!cw.length&&!hist.length)?`<div class="text-center py-24 px-4"><p class="text-muted">Your list is empty.</p><p class="text-muted text-sm mt-1">Play something and it'll show up here to continue later.</p><a href="#/" class="inline-block mt-5 bg-accent text-black font-semibold px-6 py-2.5 rounded-xl">Browse titles</a></div>`:''}
    ${footer()}
  </div>`;
}

/* ---- category grid ------------------------------------------- */
async function categoryPage(type, key){
  const label=type==='tv'?'Shows':'Movies';
  const genres=type==='tv'
    ? [[10759,'Action & Adventure'],[16,'Animation'],[35,'Comedy'],[80,'Crime'],[99,'Documentary'],[18,'Drama'],[10751,'Family'],[10762,'Kids'],[9648,'Mystery'],[10764,'Reality'],[10765,'Sci-Fi & Fantasy']]
    : [[28,'Action'],[12,'Adventure'],[16,'Animation'],[35,'Comedy'],[80,'Crime'],[99,'Documentary'],[18,'Drama'],[10751,'Family'],[14,'Fantasy'],[27,'Horror'],[9648,'Mystery'],[10749,'Romance'],[878,'Science Fiction'],[53,'Thriller']];
  const years=Array.from({length:35},(_,i)=>new Date().getFullYear()-i);
  const countries=[['US','United States'],['GB','United Kingdom'],['CA','Canada'],['FR','France'],['DE','Germany'],['IN','India'],['JP','Japan'],['KR','South Korea'],['ES','Spain'],['IT','Italy'],['AU','Australia']];
  const chevron=`<svg class="w-4 h-4 text-white/50 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>`;
  const filter=(id,label,options)=>`<div class="browse-filter custom-filter relative min-w-[125px] md:min-w-[140px] rounded-full bg-white/[.06] hover:bg-white/10 border border-white/10 backdrop-blur-md transition-all duration-200">
    <input id="${id}" type="hidden" value=""/>
    <button type="button" class="filter-trigger flex items-center justify-between gap-3 w-full text-sm font-semibold text-white/90 pl-4 pr-3 py-2.5">
      <span class="filter-label truncate">${label}</span>${chevron}
    </button>
    <div class="filter-menu hidden absolute top-[calc(100%+10px)] right-0 z-[80] w-56 max-h-72 overflow-y-auto no-sb rounded-2xl border border-white/15 bg-[#121212]/95 backdrop-blur-2xl shadow-2xl shadow-black/60 p-1.5">
      <button type="button" data-value="" data-label="${label}" class="filter-option w-full flex items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm text-white/65 hover:text-white hover:bg-white/10 transition">All ${label}s</button>
      ${options.map(([v,n])=>`<button type="button" data-value="${v}" data-label="${n}" class="filter-option w-full flex items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm text-white/75 hover:text-white hover:bg-white/10 transition"><span class="truncate">${n}</span></button>`).join('')}
    </div>
  </div>`;

  app.innerHTML=`
    <div id="hero" class="relative"></div>
    <section class="relative z-10 -mt-[40vh] md:-mt-[42vh] pt-8 md:pt-12 px-4 md:px-8 pb-20 max-w-[1500px] mx-auto">
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-5 mb-7 z-[60]">
        <div><h1 class="font-head font-extrabold text-4xl md:text-5xl">${label}</h1><p id="browseCount" class="text-sm text-white/50 mt-1">Discovering ${label.toLowerCase()}…</p></div>
        <div id="browseFilters" class="browse-filters relative z-[70] flex flex-wrap items-center md:justify-end gap-2 md:gap-3">
          <button id="randomTitle" title="Play Random ${type==='tv'?'Show':'Movie'}" class="group flex items-center h-[42px] max-w-[42px] hover:max-w-[125px] overflow-hidden rounded-full bg-white/[.06] hover:bg-white/10 border border-white/10 backdrop-blur-md transition-all duration-300">
            <span class="grid place-items-center w-[42px] h-[42px] shrink-0"><svg class="w-4 h-4 text-white/60 group-hover:text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="12" height="12" x="2" y="10" rx="2"/><path d="m17.9 14 3.5-3.5a2.2 2.2 0 0 0 0-3l-5-4.9a2.2 2.2 0 0 0-3 0L10 6"/><path d="M6 18h.01M10 14h.01M15 6h.01M18 9h.01"/></svg></span><span class="text-sm font-medium whitespace-nowrap pr-4 opacity-0 group-hover:opacity-100 transition">Random</span>
          </button>
          ${filter('filterGenre','Genre',genres)}
          ${filter('filterYear','Year',years.map(y=>[y,y]))}
          ${filter('filterSort','Popular',[['vote_average.desc','Top Rated'],['primary_release_date.desc','Newest'],['revenue.desc','Box Office']])}
          ${filter('filterProvider','Provider',PROVIDERS.map(p=>[p.id,p.name]))}
          ${filter('filterCountry','Country',countries)}
        </div>
      </div>
      <h2 id="browseHeading" class="font-head font-bold text-xl mb-5">Popular ${label}</h2>
      <div id="grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 lg:grid-cols-6 gap-4 md:gap-5"></div>
      <div id="loadMoreWrap" class="grid place-items-center h-24 mt-4">
        <button id="loadMore" class="flex items-center gap-2 px-5 py-2.5 rounded-full bg-white/[.06] border border-white/10 text-sm text-white/60" aria-label="Load more titles">
          <span class="w-4 h-4 rounded-full border-2 border-white/20 border-t-white/80 animate-spin"></span><span>Loading more…</span>
        </button>
      </div>
    </section>`;

  // A type-specific carousel: movie pages only show movies; show pages only show TV.
  const trending=await tmdb(`/trending/${type}/week`);
  const slides=(trending.results||[]).filter(x=>x.backdrop_path).slice(0,7).map(x=>({...x,media_type:type}));
  await Promise.all(slides.map(async m=>{try{const im=await tmdb(`/${type}/${m.id}/images`,{include_image_language:'en,null'});const lg=(im.logos||[]).find(x=>x.file_path.endsWith('.png'))||(im.logos||[])[0];m._logo=lg?.file_path||null;}catch{m._logo=null;}}));
  renderHeroCarousel(slides);

  let page=1,current=[],loading=false;
  const params=()=>{
    let sort=$('#filterSort').value||'popularity.desc';
    if(type==='tv'&&sort==='primary_release_date.desc')sort='first_air_date.desc';
    const p={sort_by:sort,'vote_count.gte':50};
    if($('#filterGenre').value)p.with_genres=$('#filterGenre').value;
    if($('#filterProvider').value){p.with_watch_providers=$('#filterProvider').value;p.watch_region='US';}
    if($('#filterCountry').value)p.with_origin_country=$('#filterCountry').value;
    if($('#filterYear').value)p[type==='tv'?'first_air_date_year':'primary_release_year']=$('#filterYear').value;
    return p;
  };
  const load=async(reset=false)=>{
    if(loading)return;loading=true;
    if(reset){page=1;current=[];$('#grid').innerHTML=Array(12).fill('<div class="skel rounded-2xl aspect-[2/3]"></div>').join('');}
    $('#loadMore').textContent='Loading…';
    try{
      const d=await tmdb(`/discover/${type}`,{...params(),page});
      const items=(d.results||[]).filter(x=>x.poster_path).map(x=>({...x,media_type:type}));
      current.push(...items);
      $('#grid').innerHTML=current.map(posterCardGrid).join('')||'<p class="col-span-full text-center text-muted py-20">No titles match these filters.</p>';
      $('#browseCount').textContent=`${Number(d.total_results||0).toLocaleString()} ${label.toLowerCase()} found`;
      $('#loadMoreWrap').classList.toggle('hidden',page>=d.total_pages);
      page++;
    }finally{loading=false;}
  };
  // Modern custom dropdowns (instead of the browser's dated native select menu).
  document.querySelectorAll('.custom-filter').forEach(drop=>{
    const trigger=drop.querySelector('.filter-trigger');
    const menu=drop.querySelector('.filter-menu');
    trigger.onclick=e=>{
      e.stopPropagation();
      document.querySelectorAll('.filter-menu').forEach(m=>{if(m!==menu)m.classList.add('hidden');});
      menu.classList.toggle('hidden');
      const opening=!menu.classList.contains('hidden');
      drop.classList.toggle('menu-open',opening);
      if(opening&&window.innerWidth<768){
        // Keep the menu attached, but allow enough width for full option names.
        if(menu.parentElement!==drop)drop.appendChild(menu);
        menu.classList.remove('mobile-filter-portal');
        const r=drop.getBoundingClientRect();
        const menuWidth=Math.min(210,window.innerWidth-24);
        menu.style.position='absolute';
        menu.style.zIndex='9999';
        menu.style.width=menuWidth+'px';
        if(r.left+menuWidth>window.innerWidth-12){menu.style.left='auto';menu.style.right='0';}
        else{menu.style.left='0';menu.style.right='auto';}
        menu.style.maxHeight='288px';
        menu.style.top='calc(100% + 8px)';
        menu.style.bottom='auto';
      }else if(opening){
        menu.removeAttribute('style');
      }
    };
    drop.querySelectorAll('.filter-option').forEach(option=>option.onclick=e=>{
      e.stopPropagation();
      const input=drop.querySelector('input');
      input.value=option.dataset.value;
      drop.querySelector('.filter-label').textContent=option.dataset.label;
      menu.querySelectorAll('.filter-option').forEach(o=>o.classList.toggle('selected',o===option));
      menu.classList.add('hidden');drop.classList.remove('menu-open');
      input.dispatchEvent(new Event('change'));
    });
  });
  document.onclick=()=>{
    document.querySelectorAll('.filter-menu').forEach(m=>m.classList.add('hidden'));
    document.querySelectorAll('.custom-filter').forEach(d=>d.classList.remove('menu-open'));
  };

  // Keep filters in their designed hero position, then dock them to the
  // bottom of the viewport only after that original position scrolls away.
  const filterBar=$('#browseFilters');
  const filterHomeY=filterBar.getBoundingClientRect().top+window.scrollY;
  if(window._browseDockHandler)window.removeEventListener('scroll',window._browseDockHandler);
  window._browseDockHandler=()=>{
    if(window.innerWidth<768){filterBar.classList.remove('filters-docked');return;}
    // Dock as soon as the original bar reaches the top edge instead of
    // waiting until its full height has disappeared.
    filterBar.classList.toggle('filters-docked',window.scrollY>filterHomeY-16);
  };
  window.addEventListener('scroll',window._browseDockHandler,{passive:true});
  window._browseDockHandler();

  ['filterGenre','filterYear','filterSort','filterProvider','filterCountry'].forEach(id=>{
    const el=$('#'+id);
    el.onchange=()=>{
      el.closest('.browse-filter').classList.toggle('filter-active',!!el.value);
      load(true);
    };
  });
  $('#loadMore').onclick=()=>load();
  // Automatically fetch the next TMDB page as the loading sentinel nears the viewport.
  const moreObserver=new IntersectionObserver(entries=>{
    if(entries[0].isIntersecting&&!$('#loadMoreWrap').classList.contains('hidden'))load();
  },{rootMargin:'500px 0px'});
  moreObserver.observe($('#loadMoreWrap'));
  $('#randomTitle').onclick=()=>{if(!current.length)return;const m=current[Math.floor(Math.random()*current.length)];location.hash=`#/${type==='tv'?'series':'movie'}/${m.id}`;};
  load(true);
}

function posterCardGrid(m){
  const t=mtype(m);
  return `<a href="#/${t==='tv'?'series':'movie'}/${m.id}" class="card group block">
    <div class="relative rounded-2xl overflow-hidden bg-surface2 shadow-lg">
      <img loading="lazy" src="${IMG(m.poster_path)}" class="w-full aspect-[2/3] object-cover transition duration-300 group-hover:scale-[1.03]" alt="${mtitle(m)}"/>
      <span class="absolute top-2 left-2 text-[11px] font-semibold px-1.5 py-0.5 rounded-md bg-black/70 text-accent transition group-hover:opacity-0">★ ${rating(m.vote_average)}</span>
      <div class="absolute inset-0 flex flex-col items-center justify-center text-center p-3 opacity-0 group-hover:opacity-100 transition duration-300 bg-gradient-to-t from-black/95 via-black/35 to-black/10 backdrop-blur-[1px]">
        <span class="grid place-items-center w-14 h-14 rounded-full bg-white text-black shadow-2xl mb-4 translate-y-2 group-hover:translate-y-0 transition duration-300">
          <svg class="w-7 h-7 ml-1" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
        </span>
        <p class="font-bold text-sm md:text-base leading-tight drop-shadow-md">${mtitle(m)}</p>
        <p class="text-xs text-white/80 mt-1">${year(mdate(m))} <span class="text-accent">★ ${rating(m.vote_average)}</span></p>
      </div>
    </div>
    <p class="mt-2 text-sm font-medium clamp2 group-hover:text-white">${mtitle(m)}</p><p class="text-xs text-muted">${year(mdate(m))}</p></a>`;
}

async function providerPage(id){
  const p=PROVIDERS.find(x=>x.id==id)||{id,name:'Provider',logo:''};
  if(p.logo)setWashFromImage(p.logo,.72,.25);
  app.innerHTML=`<div class="relative z-10 max-w-[1280px] mx-auto px-5 md:px-10 lg:px-16 pt-24 md:pt-28 pb-24">
    <div class="flex items-center gap-4 mb-7">
      ${p.logo?`<img src="${IMG(p.logo,'w154')}" class="w-14 h-14 md:w-16 md:h-16 rounded-2xl object-cover shadow-2xl ring-1 ring-white/15" alt="${p.name}"/>`:''}
      <h1 class="font-head font-extrabold text-3xl md:text-5xl">${p.name}</h1>
    </div>
    <div class="inline-flex items-center rounded-full bg-white/[.07] border border-white/10 backdrop-blur-xl p-1 mb-8">
      <button data-provider-type="movie" class="provider-tab px-5 py-2 rounded-full text-sm font-semibold bg-white text-black">Movies</button>
      <button data-provider-type="tv" class="provider-tab px-5 py-2 rounded-full text-sm font-semibold text-white/60">Series</button>
    </div>
    <div id="providerGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 lg:grid-cols-6 gap-4 md:gap-5"></div>
    <div id="providerSentinel" class="h-24 grid place-items-center"><span class="w-6 h-6 rounded-full border-2 border-white/20 border-t-white animate-spin"></span></div>
  </div>`;
  let type='movie',page=1,loading=false,done=false,items=[];
  const load=async(reset=false)=>{
    if(loading||done&&!reset)return;loading=true;
    if(reset){page=1;done=false;items=[];$('#providerGrid').innerHTML=Array(12).fill('<div class="skel rounded-2xl aspect-[2/3]"></div>').join('');}
    try{
      const d=await tmdb(`/discover/${type}`,{with_watch_providers:id,watch_region:'US',sort_by:'popularity.desc',page});
      const next=(d.results||[]).filter(x=>x.poster_path).map(x=>({...x,media_type:type}));
      items.push(...next);$('#providerGrid').innerHTML=items.map(posterCardGrid).join('')||'<p class="col-span-full py-24 text-center text-white/50">No titles available.</p>';
      done=page>=Math.min(d.total_pages||1,500);page++;$('#providerSentinel').classList.toggle('hidden',done);
    }finally{loading=false;}
  };
  document.querySelectorAll('.provider-tab').forEach(btn=>btn.onclick=()=>{
    type=btn.dataset.providerType;
    document.querySelectorAll('.provider-tab').forEach(b=>{const on=b===btn;b.classList.toggle('bg-white',on);b.classList.toggle('text-black',on);b.classList.toggle('text-white/60',!on);});
    load(true);
  });
  const observer=new IntersectionObserver(e=>{if(e[0].isIntersecting)load();},{rootMargin:'700px 0px'});
  await load(true);observer.observe($('#providerSentinel'));
}

async function personPage(id){
  const p=await tmdb(`/person/${id}`,{append_to_response:'combined_credits'});
  const cr=(p.combined_credits?.cast||[]).filter(c=>c.poster_path).sort((a,b)=>b.popularity-a.popularity).slice(0,24);
  app.innerHTML=`<div class="max-w-[1400px] mx-auto px-4 md:px-8 pt-28">
    <div class="flex gap-5 items-start"><img src="${IMG(p.profile_path,'w300')}" class="w-32 md:w-48 rounded-2xl"/>
    <div><h1 class="font-head font-extrabold text-2xl md:text-4xl">${p.name}</h1>
    <p class="mt-3 text-white/80 max-w-2xl clamp3 md:line-clamp-none">${p.biography||''}</p></div></div>
    <h2 class="font-head font-bold text-xl mt-8 mb-3">Known For</h2>
    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3 md:gap-4">${cr.map(posterCardGrid).join('')}</div></div>`;
}

async function searchPage(q){
  app.innerHTML=`
    <div class="relative z-10 min-h-screen max-w-[1320px] mx-auto px-5 md:px-10 lg:px-16 pb-24">
      <div class="pt-28 md:pt-32 text-center">
        <h1 class="font-head font-extrabold text-3xl md:text-4xl tracking-tight">Let's find a movie night pick</h1>
        <div class="relative mt-6 max-w-2xl mx-auto">
          <input id="pageSearch" value="${q||''}" placeholder="Search movies, TV shows & people…" autocomplete="off"
            class="w-full bg-white/[0.07] backdrop-blur-xl border border-white/15 rounded-full pl-12 pr-12 py-3.5 text-base outline-none focus:bg-white/10 focus:border-white/30 shadow-2xl transition"/>
          <svg class="absolute left-4 top-4 w-5 h-5 text-white/45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
          <button id="clearSearch" class="${q?'':'hidden'} absolute right-4 top-3.5 w-6 h-6 rounded-full text-white/50 hover:text-white" aria-label="Clear search">×</button>
        </div>
        <div id="searchChips" class="mt-5 flex justify-center flex-wrap gap-2"></div>
      </div>
      <div id="searchResults" class="mt-28 md:mt-32"></div>
    </div>`;
  const inp=$('#pageSearch'),box=$('#searchResults'),clear=$('#clearSearch');
  let trending=[];
  try{
    const d=await tmdb('/trending/all/day');
    trending=(d.results||[]).filter(x=>x.poster_path&&(x.media_type==='movie'||x.media_type==='tv')).slice(0,10);
    const bg=trending.find(x=>x.backdrop_path)?.backdrop_path;
    if(bg)setWashFromImage(bg,.72,.35);
  }catch{}
  const chips=[...new Set(getHistory().slice(0,2).map(x=>x.title.split(' ')[0].toLowerCase()))];
  if(!chips.length)chips.push('monster','coyote');
  $('#searchChips').innerHTML=chips.map(x=>`<button class="search-chip px-3 py-1.5 rounded-full bg-white/[.07] border border-white/10 text-xs text-white/70 hover:text-white hover:bg-white/10 transition">${x}</button>`).join('');
  document.querySelectorAll('.search-chip').forEach(c=>c.onclick=()=>{inp.value=c.textContent;run(inp.value);});
  const showTrending=()=>{
    box.innerHTML=`<h2 class="font-head font-bold text-lg md:text-xl text-center mb-6">Trending Today</h2>
      <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-4 md:gap-5">${trending.slice(0,10).map(posterCardGrid).join('')}</div>`;
  };
  const run=async(v)=>{
    v=v.trim();clear.classList.toggle('hidden',!v);
    if(!v){history.replaceState(null,'','#/search');showTrending();return;}
    history.replaceState(null,'','#/search/'+encodeURIComponent(v));
    box.innerHTML=`<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-4 md:gap-5">${Array(10).fill('<div class="skel rounded-2xl aspect-[2/3]"></div>').join('')}</div>`;
    const d=await tmdb('/search/multi',{query:v});
    const results=d.results||[];
    const people=results.filter(x=>x.media_type==='person'&&x.profile_path).slice(0,6);
    const titles=results.filter(x=>x.poster_path&&(x.media_type==='movie'||x.media_type==='tv'));
    let html=`<div class="flex items-end justify-between mb-6"><h2 class="font-head font-bold text-xl md:text-2xl">Results for “${v}”</h2><span class="text-sm text-white/40">${titles.length} titles</span></div>`;
    if(people.length)html+=`<div class="flex gap-5 overflow-x-auto no-sb mb-10">${people.map(p=>`<a href="#/person/${p.id}" class="w-24 shrink-0 text-center"><img src="${IMG(p.profile_path,'w185')}" class="w-20 h-20 rounded-full object-cover mx-auto ring-1 ring-white/15"/><p class="text-sm mt-2 clamp2">${p.name}</p></a>`).join('')}</div>`;
    html+=titles.length?`<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-4 md:gap-5">${titles.map(posterCardGrid).join('')}</div>`:`<p class="text-center text-white/50 py-20">No results found.</p>`;
    box.innerHTML=html;
  };
  let timer;inp.oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>run(inp.value),300);};
  clear.onclick=()=>{inp.value='';inp.focus();run('');};
  if(q)run(q);else showTrending();
}

/* ---- SHORTS (vertical trailer feed) -------------------------- */
async function shortsPage(){
  app.innerHTML=`<div class="fixed inset-0 top-16 bg-black" id="shortsWrap">
    <div id="shortsFeed" class="h-full overflow-y-scroll snap-y snap-mandatory no-sb"></div>
    <p class="absolute top-3 left-1/2 -translate-x-1/2 text-xs text-white/60 z-10">Swipe up for more ↑</p>
  </div>`;
  // Gather trending titles that have YouTube trailers
  const [mv,tvd]=await Promise.all([tmdb('/trending/movie/week'),tmdb('/trending/tv/week')]);
  const pool=[...mv.results.map(m=>({...m,_t:'movie'})),...tvd.results.map(m=>({...m,_t:'tv'}))]
    .sort(()=>Math.random()-0.5).slice(0,12);
  const feed=$('#shortsFeed');
  for(const m of pool){
    const det=await tmdb(`/${m._t}/${m.id}`,{append_to_response:'videos'});
    const tr=(det.videos?.results||[]).find(v=>v.site==='YouTube'&&(v.type==='Trailer'||v.type==='Teaser'))||(det.videos?.results||[])[0];
    if(!tr) continue;
    const slide=document.createElement('div');
    slide.className='relative h-full w-full snap-start snap-always grid place-items-center';
    slide.innerHTML=`<div class="relative w-full max-w-md aspect-[9/16] md:aspect-video bg-black">
      <iframe class="absolute inset-0 w-full h-full" src="https://www.youtube.com/embed/${tr.key}?rel=0&modestbranding=1&playsinline=1" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
      <div class="absolute bottom-0 inset-x-0 p-4 bg-gradient-to-t from-black/90 to-transparent pointer-events-none">
        <p class="font-head font-bold text-lg pointer-events-auto">${mtitle(m)} <span class="text-accent text-sm">★ ${rating(m.vote_average)}</span></p>
        <p class="text-xs text-white/70">${year(mdate(m))} • ${m._t.toUpperCase()}</p>
      </div>
      <a href="#/${m._t==='tv'?'series':'movie'}/${m.id}" class="absolute top-3 right-3 bg-accent text-black text-xs font-semibold px-3 py-1.5 rounded-lg">Details →</a>
    </div>`;
    feed.appendChild(slide);
  }
  if(!feed.children.length) feed.innerHTML=`<p class="text-muted grid place-items-center h-full">No trailers available.</p>`;
}

/* ---- SETTINGS / PLAYER --------------------------------------- */
function settingsPage(){
  const s=getSettings();
  const toggle=(k,label,desc)=>`<label class="flex items-center justify-between gap-4 py-3 border-b border-white/5">
    <span><span class="block text-sm font-medium">${label}</span><span class="block text-xs text-muted">${desc||''}</span></span>
    <input type="checkbox" data-k="${k}" ${s[k]?'checked':''} class="settingToggle w-11 h-6 appearance-none rounded-full bg-white/15 checked:bg-accent relative cursor-pointer transition
      before:content-[''] before:absolute before:top-0.5 before:left-0.5 before:w-5 before:h-5 before:rounded-full before:bg-white before:transition checked:before:translate-x-5"/>
  </label>`;
  app.innerHTML=`<div class="max-w-xl mx-auto px-4 md:px-8 pt-28">
    <h1 class="font-head font-extrabold text-2xl md:text-3xl mb-1">Player Settings</h1>
    <p class="text-muted text-sm mb-6">Preferences apply to the VidFast player and image loading.</p>
    <div class="bg-surface rounded-2xl p-4">
      ${toggle('autoPlay','Autoplay','Start playing automatically when a title opens')}
      ${toggle('nextButton','Next Episode button','Show a “Next Episode” button near the end (TV)')}
      ${toggle('autoNext','Auto-play next episode','Automatically continue to the next episode (TV)')}
      ${toggle('hideServer','Hide server selector','Hide the source/server switch button')}
      ${toggle('fullscreenButton','Fullscreen button','Show the fullscreen control')}
      ${toggle('chromecast','Chromecast button','Show the cast button')}
      ${toggle('dataSaver','Data saver','Load smaller images to save bandwidth')}
      <div class="py-3 border-b border-white/5">
        <label class="block text-sm font-medium mb-1">Default subtitle</label>
        <select id="setSub" class="w-full bg-surface2 border border-white/10 rounded-lg px-3 py-2 text-sm">
          ${[['','Off'],['en','English'],['es','Spanish'],['fr','French'],['de','German'],['ar','Arabic'],['hi','Hindi'],['pt','Portuguese']].map(([v,l])=>`<option value="${v}" ${s.sub===v?'selected':''}>${l}</option>`).join('')}
        </select>
      </div>
      <div class="py-3">
        <label class="block text-sm font-medium mb-1">Preferred server <span class="text-muted font-normal">(optional)</span></label>
        <input id="setServer" value="${s.server||''}" placeholder="e.g. Server 1 (leave blank for default)" class="w-full bg-surface2 border border-white/10 rounded-lg px-3 py-2 text-sm"/>
      </div>
    </div>
    <p id="setSaved" class="text-accent text-sm mt-4 opacity-0 transition">✓ Saved</p>
  </div>`;
  const flash=()=>{const e=$('#setSaved');e.style.opacity=1;setTimeout(()=>e.style.opacity=0,1200);};
  document.querySelectorAll('.settingToggle').forEach(t=>t.onchange=()=>{const s2=getSettings();s2[t.dataset.k]=t.checked;saveSettings(s2);flash();});
  $('#setSub').onchange=e=>{const s2=getSettings();s2.sub=e.target.value;saveSettings(s2);flash();};
  $('#setServer').oninput=e=>{const s2=getSettings();s2.server=e.target.value.trim();saveSettings(s2);flash();};
}

/* ============================================================
   ROUTER
   ============================================================ */
function scrollNav(){}

function setTab(path){
  document.querySelectorAll('.tab').forEach(t=>{
    const target=t.dataset.tab;
    const on=target==='/'?path==='/'
      : target==='/movie'?path.startsWith('/movie')||path.startsWith('/category/movie')
      : target==='/tv'?path.startsWith('/series')||path.startsWith('/category/tv')
      : path.startsWith(target);
    t.classList.toggle('text-white',on);
    t.classList.toggle('text-white/60',!on);
    t.classList.toggle('bg-white/20',on);
    t.classList.toggle('shadow-inner',on);
  });
  // glass pill active link
  document.querySelectorAll('.navlink').forEach(a=>{
    const on = a.dataset.nav===path || (path==='/'&&a.dataset.nav==='/') || (path.startsWith('/series')&&a.dataset.nav==='/tv') || (path.startsWith('/category/tv')&&a.dataset.nav==='/tv') || (path.startsWith('/category/movie')&&a.dataset.nav==='/movie') || (path.startsWith('/movie')&&a.dataset.nav==='/movie');
    a.classList.toggle('nav-active',on);
    a.classList.toggle('text-white/75',!on);
  });
}

async function router(){
  const h=location.hash.replace(/^#/,'')||'/';
  const parts=h.split('/').filter(Boolean);
  // Stop the previous page's carousel before rendering a standalone page.
  // Otherwise its timer keeps changing the detail page's background color.
  if(heroTimer){clearInterval(heroTimer);heroTimer=null;}
  if(typeof closeActiveParty==='function')closeActiveParty();
  if(window._browseDockHandler){window.removeEventListener('scroll',window._browseDockHandler);window._browseDockHandler=null;}
  document.querySelectorAll('.mobile-filter-portal').forEach(m=>m.remove());
  washRequestId++; // invalidate any carousel color extraction still loading
  window.scrollTo(0,0); scrollNav();
  setTab(h);
  // Never carry a previous detail backdrop into Home, Movies, Shows, or another title.
  clearDetailBackground();
  // reset page wash on pages without a hero (home/movie/series set their own)
  if(!['','movie','series'].includes(parts[0]||'')) document.documentElement.style.setProperty('--wash','#0d1420');
  try{
    if(h==='/'||!parts.length) return homePage();
    switch(parts[0]){
      case 'movie': return detailPage('movie',parts[1]);
      case 'series': return detailPage('series',parts[1]);
      case 'watch':
        if(parts[1]==='movie') return playerPage('movie',parts[2]);
        return playerPage('tv',parts[2],parts[3],parts[4]);
      case 'party': return partyPage((parts[1]||'').toUpperCase());
      case 'provider': return providerPage(parts[1]);
      case 'category': return categoryPage(parts[1],parts[2]);
      case 'person': return personPage(parts[1]);
      case 'continue-watching': return continuePage();
      case 'shorts': return shortsPage();
      case 'settings': return settingsPage();
      case 'search': return searchPage(parts[1]?decodeURIComponent(parts[1]):'');
      default: return homePage();
    }
  }catch(e){ app.innerHTML=`<div class="p-8 text-center text-muted">Something went wrong. <a href="#/" class="text-accent">Go home</a></div>`; }
}
window.addEventListener('hashchange',router);
router();
if(typeof resumePartyLobby==='function')resumePartyLobby();


