'use strict';

/* ============================================================
   ArtForge v5 — js.js
   Config lue depuis window.ARTFORGE (défini dans index.php)
============================================================ */

const CFG = {
  ajaxUrl: (window.ARTFORGE && window.ARTFORGE.ajaxUrl) || 'index.php',
  lsKeys:  'artforge_keys_v2',
  lsFavFilter: 'artforge_fav_filter'
};

const $  = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const sleep = ms => new Promise(r => setTimeout(r, ms));

let apiKeys = [];
let STYLES = [];
let STORYBOARDS = [];
let currentImage = null;
let LOGGED = (window.ARTFORGE && window.ARTFORGE.isLogged) || false;
let CURRENT_USER = (window.ARTFORGE && window.ARTFORGE.username) || '';

const PIPE = {
  running: false, paused: false, stopped: false,
  total: 0, done: 0, failed: 0, active: 0, abort: false,
};

/* ============================================================
   UTILS
============================================================ */
function toast(msg, type='') {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'on ' + type;
  clearTimeout(t._tid);
  t._tid = setTimeout(() => t.className = type, 3500);
}

function compressImage(dataUrl, maxSide=1400) {
  return new Promise(res => {
    const img = new Image();
    img.onload = () => {
      let {width:w, height:h} = img;
      const s = Math.min(1, maxSide / Math.max(w,h));
      w = Math.round(w*s); h = Math.round(h*s);
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      c.getContext('2d').drawImage(img, 0, 0, w, h);
      res(c.toDataURL('image/jpeg', 0.85));
    };
    img.onerror = () => res(dataUrl);
    img.src = dataUrl;
  });
}

/* ============================================================
   MODAL CUSTOM
============================================================ */
function showModal({title, message='', fieldLabel='', fieldValue='', fieldType='textarea', okText='OK', cancelText='Annuler', showCancel=true}) {
  return new Promise(resolve => {
    const overlay = $('modalOverlay');
    $('modalTitle').textContent = title;
    $('modalMsg').textContent = message;
    $('modalMsg').style.display = message ? '' : 'none';
    const fieldWrap = $('modalFieldWrap');
    const input = $('modalFieldInput');
    if (fieldLabel) {
      fieldWrap.classList.remove('hidden');
      $('modalFieldLabel').textContent = fieldLabel;
      input.value = fieldValue;
      if (fieldType === 'text') {
        input.style.minHeight = '42px';
        input.style.height = '42px';
      } else {
        input.style.minHeight = '88px';
        input.style.height = '';
      }
    } else {
      fieldWrap.classList.add('hidden');
      input.value = '';
    }
    $('modalOk').textContent = okText;
    $('modalCancel').textContent = cancelText;
    $('modalCancel').classList.toggle('hidden', !showCancel);
    overlay.classList.add('on');

    const close = (val) => {
      overlay.classList.remove('on');
      $('modalOk').removeEventListener('click', onOk);
      $('modalCancel').removeEventListener('click', onCancel);
      overlay.removeEventListener('click', onOverlay);
      document.removeEventListener('keydown', onKey);
      resolve(val);
    };
    const onOk = () => close(fieldLabel ? input.value.trim() : true);
    const onCancel = () => close(null);
    const onOverlay = e => { if (e.target === overlay) close(null); };
    const onKey = e => {
      if (e.key === 'Escape') close(null);
      if (e.key === 'Enter' && fieldType === 'text' && fieldLabel) { e.preventDefault(); onOk(); }
    };
    $('modalOk').addEventListener('click', onOk);
    $('modalCancel').addEventListener('click', onCancel);
    overlay.addEventListener('click', onOverlay);
    document.addEventListener('keydown', onKey);
    setTimeout(() => input.focus(), 80);
  });
}

/* ============================================================
   BACKEND
============================================================ */
const Backend = {
  async call(action, params = {}, method = 'POST') {
    let url = CFG.ajaxUrl + '?action=' + encodeURIComponent(action);
    let opts;

    if (method === 'GET') {
      if (params && typeof params === 'object') {
        const qs = Object.entries(params)
          .map(([k,v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
          .join('&');
        if (qs) url += '&' + qs;
      }
      opts = { method: 'GET', credentials: 'same-origin' };
    } else {
      opts = {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(params),
        credentials: 'same-origin'
      };
    }

    let res;
    try { res = await fetch(url, opts); }
    catch (e) { throw new Error('Réseau : ' + e.message); }

    if (res.status === 401) { location.reload(); throw new Error('Session expirée'); }

    const txt = await res.text();
    let data = {};
    try { data = JSON.parse(txt); }
    catch (e) {
      console.error('Non-JSON (HTTP ' + res.status + '):', txt.slice(0, 400));
      throw new Error('Erreur serveur HTTP ' + res.status);
    }
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
  },

  login(u, p)            { return this.call('login', {username:u, pin:p}); },
  logout()               { return this.call('logout', {}); },
  me()                   { return this.call('me', {}, 'GET'); },
  getStyles()            { return this.call('get_styles', {}, 'GET'); },
  saveStyle(id,name,prompt) { return this.call('save_style', {id,name,prompt}); },
  deleteStyle(id)        { return this.call('delete_style', {id}); },
  improveStyle(prompt,keys) { return this.call('improve_style', {prompt, keys}); },
  getStoryboards()       { return this.call('get_storyboards', {}, 'GET'); },
  saveStoryboard(id,name,items) { return this.call('save_storyboard', {id,name,items}); },
  deleteStoryboard(id)   { return this.call('delete_storyboard', {id}); },
  improveStoryboard(theme,keys) { return this.call('improve_storyboard', {theme, keys}); },
  analyze(image, keys)   { return this.call('analyze', {image, keys}); },
  generatePrompt(system,user,keys,max=500,temp=0.75) {
    return this.call('generate_prompt', {system, user, keys, max_tokens:max, temperature:temp});
  },
  generateImage(prompt, keys, label='img', size='2K', ratio='9:16') {
    return this.call('generate_image', {prompt, keys, label, size, ratio});
  },
  logGeneration(d)       { return this.call('log_generation', d); },
  history(page=1, perPage=30, favOnly=false) {
    return this.call('history', {page, per_page: perPage, favorites_only: favOnly ? 1 : 0}, 'GET');
  },
  getFavorites(page=1, perPage=30) { return this.call('get_favorites', {page, per_page: perPage}, 'GET'); },
  toggleFavorite(genId)  { return this.call('toggle_favorite', {generation_id: genId}); },
  testKeys(keys)         { return this.call('test_keys', {keys}); }
};

/* ============================================================
   AUTH
============================================================ */
async function doLogin() {
  const u = $('loginUser').value.trim();
  const p = $('loginPin').value.trim();
  const status = $('loginStatus');
  if (!u || !/^\d{4}$/.test(p)) {
    status.className = 'status err';
    status.textContent = 'Pseudo + PIN 4 chiffres requis';
    return;
  }
  const btn = $('loginBtn');
  btn.disabled = true; btn.textContent = '⏳ Connexion…';
  status.className = 'status'; status.textContent = 'Connexion…';
  try {
    const r = await Backend.login(u, p);
    CURRENT_USER = r.user;
    LOGGED = true;
    $('loginBox').classList.add('hidden');
    $('main').classList.remove('hidden');
    $('logoutBtn').classList.remove('hidden');
    $('globalStat').innerHTML = 'Connecté : <b>' + esc(CURRENT_USER) + '</b>';
    await bootApp();
    toast('Bienvenue ' + CURRENT_USER, 'ok');
  } catch (e) {
    status.className = 'status err';
    status.textContent = '❌ ' + e.message;
  } finally {
    btn.disabled = false; btn.textContent = 'Se connecter / Créer un compte';
  }
}

async function doLogout() {
  try { await Backend.logout(); } catch(e) {}
  location.reload();
}

/* ============================================================
   API KEYS
============================================================ */
function updateApiTag() {
  const tag = $('modeTag');
  const stat = $('globalStat');
  if (apiKeys.length) {
    tag.textContent = 'LIVE · ' + apiKeys.length;
    tag.className = 'mode live';
    stat.innerHTML = '<b>' + apiKeys.length + '</b> clé(s) prête(s)';
    $('apiSummary').textContent = apiKeys.length + ' clé(s)';
  } else {
    tag.textContent = 'NON CONFIGURÉ';
    tag.className = 'mode';
    stat.textContent = 'Ajoutez vos clés API pour commencer';
    $('apiSummary').textContent = 'Cliquez pour configurer';
  }
}

async function validateKeys() {
  const raw = $('apiInput').value.split('\n').map(l => l.trim()).filter(l => l.startsWith('sk-'));
  const status = $('apiStatus');
  if (!raw.length) { status.className='status err'; status.textContent='Aucune clé valide'; return; }
  status.className = 'status'; status.textContent = '⏳ Test de ' + raw.length + ' clé(s)…';
  try {
    const r = await Backend.testKeys(raw);
    const valid = (r.results || []).filter(x => x.ok).map(x => x.key);
    if (!valid.length) {
      status.className = 'status err'; status.textContent = '❌ Aucune clé acceptée';
      apiKeys = [];
    } else {
      apiKeys = valid;
      try { localStorage.setItem(CFG.lsKeys, JSON.stringify(valid)); } catch(e){}
      status.className = 'status ok';
      status.textContent = '✓ ' + valid.length + ' clé(s) validée(s)';
      toast(valid.length + ' clé(s) validée(s)', 'ok');
    }
    updateApiTag();
  } catch (e) {
    status.className = 'status err'; status.textContent = '❌ ' + e.message;
  }
}

/* ============================================================
   STYLES
============================================================ */
async function loadStyles() {
  try {
    const r = await Backend.getStyles();
    STYLES = r.styles || [];
    $('styleSelect').innerHTML = STYLES.map(s =>
      `<option value="${esc(s.slug)}">${esc(s.name)}</option>`
    ).join('');
    renderStyleEditors();
  } catch (e) {
    toast('Chargement styles : ' + e.message, 'err');
  }
}

function renderStyleEditors() {
  const box = $('styleEditors');
  box.innerHTML = STYLES.map(s => `
    <div class="style-editor" data-id="${s.id}">
      <h4>
        <input type="text" class="style-name" value="${esc(s.name)}" style="min-height:0;padding:.4rem .6rem;font-size:.9rem;font-weight:600">
        <span class="slug">${esc(s.slug)}</span>
        <button class="btn-mini btn-red" data-del style="margin-left:auto">🗑</button>
      </h4>
      <textarea class="style-prompt">${esc(s.prompt)}</textarea>
      <div class="actions">
        <button class="btn-mini" data-save>💾 Sauvegarder</button>
        <button class="btn-mini" data-improve>✨ IA améliore</button>
        <span class="status" data-status style="margin:0;padding:.3rem .6rem;font-size:.68rem;display:none"></span>
      </div>
    </div>
  `).join('');

  box.querySelectorAll('.style-editor').forEach(el => {
    const id = parseInt(el.dataset.id, 10);
    const nameEl = el.querySelector('.style-name');
    const promptEl = el.querySelector('.style-prompt');
    const statusEl = el.querySelector('[data-status]');

    el.querySelector('[data-save]').addEventListener('click', async () => {
      try {
        await Backend.saveStyle(id, nameEl.value.trim(), promptEl.value.trim());
        toast('Style sauvegardé', 'ok');
        await loadStyles();
      } catch(e) { toast('Sauvegarde : ' + e.message, 'err'); }
    });

    el.querySelector('[data-improve]').addEventListener('click', async (ev) => {
      if (!apiKeys.length) { toast('Configurez vos clés API', 'err'); return; }
      const btn = ev.currentTarget;
      btn.disabled = true; btn.textContent = '⏳…';
      try {
        const r = await Backend.improveStyle(promptEl.value.trim(), apiKeys);
        promptEl.value = r.prompt;
        statusEl.style.display = 'inline-block';
        statusEl.className = 'status warn';
        statusEl.textContent = '✨ Amélioré — Sauvegarder';
      } catch(e) { toast('IA : ' + e.message, 'err'); }
      finally { btn.disabled = false; btn.textContent = '✨ IA améliore'; }
    });

    el.querySelector('[data-del]').addEventListener('click', async () => {
      const ok = await showModal({
        title: 'Supprimer ce style ?',
        message: 'Cette action est irréversible.',
        okText: 'Supprimer',
        cancelText: 'Annuler',
      });
      if (!ok) return;
      try {
        await Backend.deleteStyle(id);
        toast('Style supprimé', 'ok');
        await loadStyles();
      } catch(e) { toast('Suppression : ' + e.message, 'err'); }
    });
  });
}

/* ============================================================
   STORYBOARDS
============================================================ */
async function loadStoryboards() {
  try {
    const r = await Backend.getStoryboards();
    STORYBOARDS = r.storyboards || [];
    $('storyboardSelect').innerHTML = STORYBOARDS.map(s =>
      `<option value="${esc(s.slug)}">${esc(s.name)} (${(s.items||[]).length})</option>`
    ).join('');
    renderSbEditors();
    updateSbHint();
  } catch (e) {
    toast('Chargement storyboards : ' + e.message, 'err');
  }
}

function updateSbHint() {
  const slug = $('storyboardSelect').value;
  const sb = STORYBOARDS.find(s => s.slug === slug);
  if (sb) {
    $('sbHint').textContent = (sb.items||[]).length + ' éléments · ' + (sb.name || '');
  } else {
    $('sbHint').textContent = '— aucune liste sélectionnée';
  }
}

function renderSbEditors() {
  const box = $('sbEditors');
  box.innerHTML = STORYBOARDS.map(s => `
    <div class="sb-editor" data-id="${s.id}">
      <h4>
        <input type="text" class="sb-name" value="${esc(s.name)}" style="min-height:0;padding:.4rem .6rem;font-size:.9rem;font-weight:600">
        <span class="slug">${esc(s.slug)}</span>
        <button class="btn-mini btn-red" data-del style="margin-left:auto">🗑</button>
      </h4>
      <label class="lab" style="margin-top:.3rem">Éléments (un par ligne)</label>
      <textarea class="items-area sb-items">${esc((s.items||[]).join('\n'))}</textarea>
      <div class="actions">
        <button class="btn-mini" data-save>💾 Sauvegarder</button>
        <button class="btn-mini" data-improve>✨ IA améliore</button>
        <span class="status" data-status style="margin:0;padding:.3rem .6rem;font-size:.68rem;display:none"></span>
      </div>
    </div>
  `).join('');

  box.querySelectorAll('.sb-editor').forEach(el => {
    const id = parseInt(el.dataset.id, 10);
    const nameEl = el.querySelector('.sb-name');
    const itemsEl = el.querySelector('.sb-items');
    const statusEl = el.querySelector('[data-status]');

    el.querySelector('[data-save]').addEventListener('click', async () => {
      const items = itemsEl.value.split('\n').map(l => l.trim()).filter(l => l !== '');
      try {
        await Backend.saveStoryboard(id, nameEl.value.trim(), items);
        toast('Liste sauvegardée', 'ok');
        await loadStoryboards();
      } catch(e) { toast('Sauvegarde : ' + e.message, 'err'); }
    });

    el.querySelector('[data-improve]').addEventListener('click', async (ev) => {
      if (!apiKeys.length) { toast('Configurez vos clés API', 'err'); return; }
      const btn = ev.currentTarget;
      btn.disabled = true; btn.textContent = '⏳…';
      try {
        const theme = itemsEl.value.trim() || nameEl.value.trim();
        const r = await Backend.improveStoryboard(theme, apiKeys);
        itemsEl.value = (r.items || []).join('\n');
        statusEl.style.display = 'inline-block';
        statusEl.className = 'status warn';
        statusEl.textContent = '✨ Liste générée — Sauvegarder';
      } catch(e) { toast('IA : ' + e.message, 'err'); }
      finally { btn.disabled = false; btn.textContent = '✨ IA améliore'; }
    });

    el.querySelector('[data-del]').addEventListener('click', async () => {
      const ok = await showModal({
        title: 'Supprimer cette liste ?',
        message: 'Cette action est irréversible.',
        okText: 'Supprimer',
        cancelText: 'Annuler',
      });
      if (!ok) return;
      try {
        await Backend.deleteStoryboard(id);
        toast('Liste supprimée', 'ok');
        await loadStoryboards();
      } catch(e) { toast('Suppression : ' + e.message, 'err'); }
    });
  });
}

/* ============================================================
   IMAGE UPLOAD
============================================================ */
async function handleImage(file) {
  if (!file || !file.type.startsWith('image/')) return;
  const dataUrl = await new Promise((res, rej) => {
    const r = new FileReader();
    r.onload = e => res(e.target.result);
    r.onerror = rej;
    r.readAsDataURL(file);
  });
  currentImage = await compressImage(dataUrl);
  renderPreview();
}

function renderPreview() {
  const w = $('imagePreviewWrap');
  if (!currentImage) { w.innerHTML = ''; return; }
  w.innerHTML = `<div class="preview"><img src="${currentImage}" alt=""><button class="rm" id="rmImage">✕</button></div>`;
  $('rmImage').addEventListener('click', () => { currentImage = null; renderPreview(); });
}

/* ============================================================
   FAVORIS
============================================================ */
async function toggleFavorite(genId, btnEl) {
  if (!genId) { toast('ID image manquant', 'err'); return; }
  btnEl.disabled = true;
  try {
    const r = await Backend.toggleFavorite(genId);
    if (r.is_favorite) {
      btnEl.classList.add('active');
      btnEl.textContent = '★';
      toast('Ajouté aux favoris', 'ok');
    } else {
      btnEl.classList.remove('active');
      btnEl.textContent = '☆';
      toast('Retiré des favoris', 'info');
    }
  } catch (e) {
    toast('Favori : ' + e.message, 'err');
  } finally {
    btnEl.disabled = false;
  }
}

/* ============================================================
   PIPELINE
============================================================ */
function addStep(label, icon) {
  const box = $('stepsBox');
  box.classList.remove('hidden');
  const el = document.createElement('div');
  el.className = 'step running';
  el.innerHTML = `<span class="ico">${icon}</span><span class="msg"><span class="spinner"></span> ${esc(label)}</span>`;
  box.appendChild(el);
  return el;
}
function updateStep(el, msg, ok=true) {
  el.className = 'step ' + (ok ? 'done' : 'error');
  el.querySelector('.msg').innerHTML = (ok ? '✓ ' : '❌ ') + esc(msg);
}

function resetPipelineUI() {
  $('stepsBox').innerHTML = '';
  $('stepsBox').classList.add('hidden');
  $('resultsBox').classList.add('hidden');
  $('resultGrid').innerHTML = '';
  $('resultsCount').textContent = '0 / 0';
  $('pipelineCtrl').classList.add('hidden');
  $('pauseBtn').classList.remove('hidden');
  $('resumeBtn').classList.add('hidden');
  $('stopBtn').disabled = false;
  $('pauseBtn').disabled = false;
  PIPE.running = false; PIPE.paused = false; PIPE.stopped = false;
  PIPE.total = 0; PIPE.done = 0; PIPE.failed = 0; PIPE.active = 0; PIPE.abort = false;
}

function updateCount() {
  const el = $('resultsCount');
  let txt = `${PIPE.done} / ${PIPE.total}`;
  if (PIPE.failed) txt += ` · ${PIPE.failed} err.`;
  el.textContent = txt;
}

async function waitIfPaused() {
  while (PIPE.paused && !PIPE.stopped) await sleep(200);
}

async function generateOne(prompt, size, ratio, label) {
  await waitIfPaused();
  if (PIPE.stopped) throw new Error('Stopped');
  PIPE.active++;
  try {
    const r = await Backend.generateImage(prompt, apiKeys, label, size, ratio);
    return r.url;
  } finally {
    PIPE.active--;
  }
}

/* ---------- Carte image (résultats + favoris + historique) ---------- */
function createImageCard(data) {
  const card = document.createElement('div');
  card.className = 'img-card';
  card._data = data; // référence partagée pour mise à jour genId après log

  const isFav = parseInt(data.is_favorite || 0, 10) === 1;
  const canFavNow = !!data.genId;

  card.innerHTML = `
    <button class="fav-btn ${isFav ? 'active' : ''}" data-fav title="Favori" ${canFavNow ? '' : 'disabled'}>${isFav ? '★' : '☆'}</button>
    <div class="img-wrap">
      <img src="${esc(data.url)}" alt="" loading="lazy" class="new">
    </div>
    <div class="info">
      <div class="meta">${esc(data.style || '')} · #${(data.index||0) + 1}</div>
      <textarea class="prompt-edit">${esc(data.prompt)}</textarea>
      <div class="actions">
        <button class="btn-mini" data-copy>📋 Copier</button>
        <button class="btn-mini" data-regen>↻ Régénérer</button>
        <span class="status" data-msg style="display:none;margin:0;padding:.25rem .5rem;font-size:.65rem"></span>
      </div>
    </div>`;

  const ta = card.querySelector('.prompt-edit');
  const msg = card.querySelector('[data-msg]');
  const favBtn = card.querySelector('[data-fav]');

  // ⭐ handler unique — lit data.genId mis à jour après logGeneration
  favBtn.addEventListener('click', () => {
    if (!data.genId) { toast('Image en cours de sauvegarde, patientez…', 'info'); return; }
    toggleFavorite(data.genId, favBtn);
  });

  // Copier
  card.querySelector('[data-copy]').addEventListener('click', () => {
    navigator.clipboard.writeText(ta.value)
      .then(() => toast('Prompt copié', 'ok'))
      .catch(() => toast('Copie impossible', 'err'));
  });

  // Régénérer
  card.querySelector('[data-regen]').addEventListener('click', async (ev) => {
    const btn = ev.currentTarget;
    if (btn.disabled) return;
    if (!apiKeys.length) { toast('Configurez vos clés API', 'err'); return; }
    btn.disabled = true;
    msg.style.display = 'inline-block';
    msg.className = 'status';
    msg.innerHTML = '<span class="spinner"></span> Régénération…';

    const prompt = ta.value.trim();
    if (!prompt) { toast('Prompt vide', 'err'); btn.disabled = false; return; }
    const size = $('sizeSelect').value;
    const ratio = $('ratioSelect').value;

    try {
      const r = await Backend.generateImage(prompt, apiKeys, 'regen_' + Date.now(), size, ratio);
      const imgWrap = card.querySelector('.img-wrap');
      const oldImgs = imgWrap.querySelectorAll('img');
      oldImgs.forEach(im => { im.classList.remove('new'); im.classList.add('old'); });
      const newImg = document.createElement('img');
      newImg.src = r.url;
      newImg.className = 'new';
      newImg.loading = 'lazy';
      newImg.alt = 'regenerated';
      imgWrap.appendChild(newImg);

      msg.className = 'status ok';
      msg.textContent = '✓ Nouvelle image';
      toast('Image régénérée', 'ok');

      // Nouveau log → nouveau genId pour le favori
      try {
        const lr = await Backend.logGeneration({
          style_slug: data.style,
          user_input: '',
          used_image: 0,
          vision_desc: '',
          final_prompt: prompt,
          image_url: r.url,
          parent_id: data.genId || data.parentId || null,
        });
        if (lr && lr.id) {
          data.genId = lr.id;
          data.parentId = lr.id;
          favBtn.disabled = false;
          favBtn.classList.remove('active');
          favBtn.textContent = '☆';
        }
      } catch (e) {
        console.warn('logGeneration régén échoué :', e);
      }
    } catch (e) {
      msg.className = 'status err';
      msg.textContent = '❌ ' + e.message;
    } finally {
      btn.disabled = false;
    }
  });

  return card;
}

/* ---------- Construction déterministe du prompt (sans IA optimizer) ---------- */
function composeDirectPrompt({stylePrompt, userPrompt, visionDesc, bulkItem, variantIndex}) {
  const parts = [];
  parts.push('PRIMARY VISUAL STYLE — APPLY THROUGHOUT THE ENTIRE IMAGE:\n' + stylePrompt.trim());
  if (userPrompt) parts.push('EXACT USER INTENT / SUBJECT — PRESERVE IT:\n' + userPrompt.trim());
  if (visionDesc) parts.push('SOURCE IMAGE VISUAL DESCRIPTION — USE AS SUBJECT REFERENCE, NOT AS A REQUEST TO COPY TEXT:\n' + visionDesc.trim());
  if (bulkItem) parts.push(
    'MANDATORY BULK / STORYBOARD ELEMENT — THIS MUST BE CLEARLY VISIBLE AND DOMINANT IN THIS IMAGE:\n' + bulkItem.trim() +
    '\nDo not omit, dilute, replace, or merely allude to this element. Make it explicit in the subject, action, framing, and scene where relevant.'
  );
  if (variantIndex > 0) parts.push('VARIATION ' + (variantIndex + 1) + ': keep the same subject, style, palette and mandatory storyboard element; vary only secondary composition details.');
  parts.push('STYLE PRIORITY: the named style rules above govern the whole image, including subject rendering, materials, costume, architecture, color palette, lighting, camera, composition and background. Do not fall back to a generic aesthetic.');
  parts.push('IMAGE QUALITY: coherent composition, precise details, intentional lighting, no text, no watermark.');
  return parts.filter(Boolean).join('\n\n');
}

function buildOptimizerRequest({stylePrompt, userPrompt, visionDesc, bulkItem, variantIndex}) {
  const chunks = [];
  chunks.push('STYLE PROMPT — HIGHEST PRIORITY; preserve and apply every distinctive rule:\n' + stylePrompt.trim());
  if (userPrompt) chunks.push('USER SUBJECT / INTENT — do not replace it:\n' + userPrompt.trim());
  if (visionDesc) chunks.push('IMAGE-TO-TEXT SOURCE ANALYSIS — preserve the recognizable subject and important visual attributes:\n' + visionDesc.trim());
  if (bulkItem) chunks.push('MANDATORY BULK ITEM — MUST BE EXPLICITLY REPRESENTED, not treated as optional inspiration:\n' + bulkItem.trim());
  if (variantIndex > 0) chunks.push('Variation number ' + (variantIndex + 1) + ': preserve style, subject and mandatory bulk item; vary only secondary details.');
  chunks.push('Write one complete English image-generation prompt. Integrate the style throughout the entire prompt, not as a weak suffix. Preserve the user subject. The bulk item is a hard constraint and must be unmistakably visible. Include concrete subject, scene, materials, palette, lighting, camera/framing and composition. Resolve conflicts in this order: user subject, mandatory bulk item, distinctive style rules, secondary embellishment. Do not explain your work; return only the final prompt.');
  return chunks.join('\n\n');
}

/* ---------- Lancement principal : chaque prompt alimente immédiatement la file image ---------- */
async function generate() {
  const styleSlug = $('styleSelect').value;
  const style = STYLES.find(s => s.slug === styleSlug);
  const userPrompt = $('promptInput').value.trim();
  const hasImage = !!currentImage;
  const optimizeWithAI = !!$('aiOptimizerCheck')?.checked;
  if (!style) { toast('Style invalide', 'err'); return; }
  if (!apiKeys.length) { toast('Configurez vos clés API', 'err'); return; }
  if (!hasImage && !userPrompt) { toast('Fournissez une image OU un prompt', 'err'); return; }

  const size = $('sizeSelect').value;
  const ratio = $('ratioSelect').value;
  const countPerElement = Math.max(1, Math.min(10, parseInt($('countInput').value || '1', 10)));
  const concurrency = Math.max(1, Math.min(10, parseInt($('concurrencySelect').value || '2', 10)));
  const useStoryboard = $('storyboardCheck').checked;

  let sbItems = [];
  if (useStoryboard) {
    const sbSlug = $('storyboardSelect').value;
    const sb = STORYBOARDS.find(s => s.slug === sbSlug);
    if (!sb || !(sb.items || []).length) {
      toast('Storyboard vide ou introuvable', 'err');
      return;
    }
    sbItems = sb.items.map(x => String(x).trim()).filter(Boolean);
    if (!sbItems.length) { toast('La liste bulk ne contient aucun élément exploitable.', 'err'); return; }
  } else {
    sbItems = [''];
  }

  const totalImages = countPerElement * sbItems.length;
  resetPipelineUI();
  PIPE.running = true;
  PIPE.paused = false;
  PIPE.stopped = false;
  PIPE.abort = false;
  PIPE.total = totalImages;
  PIPE.done = 0;
  PIPE.failed = 0;
  PIPE.active = 0;
  $('generateBtn').disabled = true;
  $('generateBtn').textContent = optimizeWithAI ? '⏳ Optimisation + images…' : '⏳ Génération directe…';
  $('pipelineCtrl').classList.remove('hidden');
  $('resultsBox').classList.remove('hidden');
  updateCount();

  const grid = $('resultGrid');
  const queue = [];
  let producerDone = false;
  let nextImageIndex = 0;
  let visionDesc = '';
  let promptCount = 0;
  const imageWorkers = Array.from({length: concurrency}, async () => {
    while (true) {
      await waitIfPaused();
      if (PIPE.stopped || PIPE.abort) return;
      if (!queue.length) {
        if (producerDone) return;
        await sleep(100);
        continue;
      }
      const t = queue.shift();
      if (!t) continue;
      try {
        const label = 'artforge_' + (t.styleSlug || 'x') + '_' + t.index + (t.sbItem ? '_' + t.sbItem.slice(0, 18).replace(/[^a-z0-9_-]/gi, '_') : '');
        const url = await generateOne(t.promptFinal, size, ratio, label);
        PIPE.done++;
        updateCount();
        const card = createImageCard({
          prompt: t.promptFinal,
          url,
          style: t.sbItem ? (t.styleSlug + ' · ' + t.sbItem) : t.styleSlug,
          index: t.index,
          parentId: null,
          genId: null,
          is_favorite: 0,
        });
        grid.appendChild(card);
        Backend.logGeneration({
          style_slug: t.styleSlug,
          user_input: t.userPrompt,
          used_image: t.hasImage ? 1 : 0,
          vision_desc: t.visionDesc,
          final_prompt: t.promptFinal,
          image_url: url,
          parent_id: null,
        }).then(r => {
          if (r && r.id) {
            card._data.genId = r.id;
            const favBtn = card.querySelector('[data-fav]');
            if (favBtn) favBtn.disabled = false;
          }
        }).catch(e => {
          console.warn('logGeneration échoué :', e);
          const favBtn = card.querySelector('[data-fav]');
          if (favBtn) favBtn.disabled = false;
        });
      } catch (e) {
        PIPE.failed++;
        updateCount();
        const errCard = document.createElement('div');
        errCard.className = 'img-card';
        errCard.innerHTML = `<div class="img-wrap"><div style="padding:1rem;color:var(--red);font-size:.8rem">❌ ${esc(e.message)}</div></div><div class="meta">${esc(t.sbItem || 'Génération directe')}</div><textarea class="prompt-edit" readonly>${esc(t.promptFinal)}</textarea>`;
        grid.appendChild(errCard);
      }
    }
  });

  try {
    /* L'analyse image→texte est nécessaire uniquement lorsqu'une image source est fournie.
       Aucune requête image→image n'est faite : la sortie reste toujours texte→image. */
    if (hasImage) {
      const s = addStep('Analyse de l’image source → texte…', '🔍');
      try {
        const r = await Backend.analyze(currentImage, apiKeys);
        visionDesc = String(r.content || '').trim();
        if (!visionDesc) throw new Error('L’analyse n’a renvoyé aucune description.');
        updateStep(s, 'Analyse terminée (' + visionDesc.length + ' caractères).');
      } catch (e) {
        updateStep(s, 'Analyse échouée : ' + e.message, false);
        throw e;
      }
    }

    const producerStep = addStep(optimizeWithAI
      ? 'Optimisation des prompts puis lancement immédiat des images…'
      : 'Construction directe des prompts puis lancement immédiat des images…', '✍️');

    for (let itemIndex = 0; itemIndex < sbItems.length; itemIndex++) {
      await waitIfPaused();
      if (PIPE.stopped || PIPE.abort) break;
      const item = sbItems[itemIndex];
      for (let variant = 0; variant < countPerElement; variant++) {
        await waitIfPaused();
        if (PIPE.stopped || PIPE.abort) break;
        let finalPrompt;
        if (optimizeWithAI) {
          const userMsg = buildOptimizerRequest({
            stylePrompt: style.prompt,
            userPrompt,
            visionDesc,
            bulkItem: item,
            variantIndex: variant,
          });
          const r = await Backend.generatePrompt(
            'You are an expert image-prompt engineer. Follow all user constraints literally. The style and mandatory bulk item must be strongly visible in the final image prompt. Return only the final English prompt.',
            userMsg,
            apiKeys,
            900,
            item ? 0.35 : 0.55
          );
          finalPrompt = String(r.content || '').trim();
          if (!finalPrompt) throw new Error('L’optimiseur IA a renvoyé un prompt vide.');
        } else {
          finalPrompt = composeDirectPrompt({
            stylePrompt: style.prompt,
            userPrompt,
            visionDesc,
            bulkItem: item,
            variantIndex: variant,
          });
        }

        promptCount++;
        const idx = nextImageIndex++;
        // Point essentiel : dès que CE prompt existe, il entre dans la file image.
        // Le producteur peut préparer l’élément bulk suivant pendant que les workers génèrent.
        queue.push({
          index: idx,
          promptFinal: finalPrompt,
          sbItem: item,
          styleSlug,
          userPrompt,
          hasImage,
          visionDesc,
        });
        updateStep(producerStep, `${promptCount}/${totalImages} prompt(s) prêt(s) — ${PIPE.done} image(s) terminée(s).`);
      }
    }
    updateStep(producerStep, `${promptCount} prompt(s) créé(s). Les images déjà lancées continuent.`, true);
  } catch (e) {
    toast('Erreur : ' + e.message, 'err');
  } finally {
    producerDone = true;
  }

  await Promise.all(imageWorkers);
  updateCount();
  PIPE.running = false;
  $('generateBtn').disabled = false;
  $('generateBtn').textContent = '🎬 Générer';
  $('pauseBtn').classList.add('hidden');
  $('resumeBtn').classList.add('hidden');
  $('stopBtn').disabled = true;
  if (PIPE.stopped) toast('Génération arrêtée. Les images déjà terminées sont conservées.', 'info');
  else if (!PIPE.failed) toast(`Terminé : ${PIPE.done}/${PIPE.total} image(s) générée(s).`, 'ok');
  else toast(`Terminé : ${PIPE.done} réussie(s), ${PIPE.failed} échec(s).`, 'warn');
}

/* ---------- Contrôles pause / stop / reprendre ---------- */
function bindPipelineControls() {
  $('pauseBtn').addEventListener('click', () => {
    PIPE.paused = true;
    $('pauseBtn').classList.add('hidden');
    $('resumeBtn').classList.remove('hidden');
    toast('En pause — les tâches en cours continuent', 'info');
  });
  $('resumeBtn').addEventListener('click', () => {
    PIPE.paused = false;
    $('resumeBtn').classList.add('hidden');
    $('pauseBtn').classList.remove('hidden');
    toast('Reprise…', 'info');
  });
  $('stopBtn').addEventListener('click', () => {
    PIPE.stopped = true;
    $('stopBtn').disabled = true;
    toast('Arrêt demandé', 'info');
  });
}

/* ============================================================
   HISTORIQUE
============================================================ */
let HIST_STATE = { page: 1, perPage: 30, total: 0, items: [], favOnly: false };

async function loadHistory(page = 1) {
  const box = $('historyBox');
  const pag = $('historyPagination');
  const favOnly = $('historyFavOnly').checked;
  box.innerHTML = '<p class="sub">⏳ Chargement…</p>';
  pag.classList.add('hidden');
  try {
    const r = await Backend.history(page, 30, favOnly);
    const items = r.items || [];
    HIST_STATE = { page: r.page || page, perPage: r.per_page || 30, total: r.total || 0, items, favOnly };

    if (!items.length) {
      box.innerHTML = '<p class="sub">' + (favOnly ? 'Aucun favori pour le moment.' : 'Aucune génération pour le moment.') + '</p>';
      return;
    }

    // Groupes de 30 → session avec 3 visibles par défaut
    const sessionGroups = [];
    for (let i = 0; i < items.length; i += 30) {
      sessionGroups.push(items.slice(i, i + 30));
    }

    box.innerHTML = sessionGroups.map((group, si) => {
      const startId = group[group.length - 1]?.id ?? 0;
      const endId = group[0]?.id ?? 0;
      const visible = group.slice(0, 3);
      const hidden = group.slice(3);
      return `
      <div class="session-block" data-session="${si}">
        <div class="session-header">
          <span class="s-arrow">▶</span>
          <span class="s-title">Session ${si + 1} · ${group.length} image(s)</span>
          <span class="s-meta">ids ${startId}–${endId}</span>
        </div>
        <div class="session-body">
          <div class="result-grid">${visible.map((it, idx) => renderHistoryCard(it, si, idx)).join('')}</div>
          ${hidden.length ? `<div class="result-grid" data-hidden-session="${si}" style="display:none;margin-top:1rem">${hidden.map((it, idx) => renderHistoryCard(it, si, idx + 3)).join('')}</div>` : ''}
        </div>
      </div>`;
    }).join('');

    // Bind : clic sur en-tête de session
    box.querySelectorAll('.session-block').forEach(block => {
      const header = block.querySelector('.session-header');
      header.addEventListener('click', () => {
        block.classList.toggle('open');
        if (block.classList.contains('open')) {
          const hiddenBox = block.querySelector('[data-hidden-session]');
          if (hiddenBox) hiddenBox.style.display = '';
        }
      });
    });

    // Bind : cartes
    bindHistoryCards(box);

    // Pagination
    const totalPages = Math.max(1, Math.ceil(HIST_STATE.total / HIST_STATE.perPage));
    if (totalPages > 1) {
      pag.classList.remove('hidden');
      pag.innerHTML = `
        <button class="btn-mini" data-page="${Math.max(1, page-1)}" ${page<=1?'disabled':''}>← Précédent</button>
        <span class="page-info">Page ${page} / ${totalPages} · ${HIST_STATE.total} image(s)</span>
        <button class="btn-mini" data-page="${Math.min(totalPages, page+1)}" ${page>=totalPages?'disabled':''}>Suivant →</button>
      `;
      pag.querySelectorAll('[data-page]').forEach(btn => {
        btn.addEventListener('click', () => {
          const p = parseInt(btn.dataset.page, 10);
          if (p && p !== page) loadHistory(p);
        });
      });
    }
  } catch(e) {
    box.innerHTML = '<p class="sub">Erreur : ' + esc(e.message) + '</p>';
  }
}

function renderHistoryCard(it) {
  const isFav = parseInt(it.is_favorite || 0, 10) === 1;
  return `
    <div class="img-card history-card" data-id="${it.id}" data-url="${esc(it.image_url)}" data-style="${esc(it.style_slug||'')}">
      <button class="fav-btn ${isFav ? 'active' : ''}" data-fav title="Favori">${isFav ? '★' : '☆'}</button>
      <div class="img-wrap">
        <img src="${esc(it.image_url)}" alt="" loading="lazy" style="border:2px solid var(--green)">
      </div>
      <div class="info">
        <div class="meta">${esc(it.style_slug||'')} · ${esc(it.created_at)}</div>
        <textarea class="prompt-edit">${esc(it.final_prompt)}</textarea>
        <div class="actions">
          <button class="btn-mini" data-copy>📋 Copier</button>
          <button class="btn-mini" data-regen>↻ Régénérer</button>
          <span class="status" data-msg style="display:none;margin:0;padding:.25rem .5rem;font-size:.65rem"></span>
        </div>
      </div>
    </div>`;
}

function bindHistoryCards(box) {
  box.querySelectorAll('.history-card').forEach(card => {
    const ta = card.querySelector('.prompt-edit');
    const msg = card.querySelector('[data-msg]');
    const styleSlug = card.dataset.style || '';
    const parentId = parseInt(card.dataset.id, 10) || null;
    const favBtn = card.querySelector('[data-fav]');

    if (favBtn) {
      favBtn.addEventListener('click', () => toggleFavorite(parentId, favBtn));
    }

    const copyBtn = card.querySelector('[data-copy]');
    if (copyBtn) {
      copyBtn.addEventListener('click', () => {
        navigator.clipboard.writeText(ta.value)
          .then(() => toast('Prompt copié', 'ok'))
          .catch(() => toast('Copie impossible', 'err'));
      });
    }

    const regenBtn = card.querySelector('[data-regen]');
    if (regenBtn) {
      regenBtn.addEventListener('click', async (ev) => {
        if (!apiKeys.length) { toast('Configurez vos clés API', 'err'); return; }
        const btn = ev.currentTarget;
        if (btn.disabled) return;
        btn.disabled = true;
        msg.style.display = 'inline-block';
        msg.className = 'status';
        msg.innerHTML = '<span class="spinner"></span> Régénération…';

        const prompt = ta.value.trim();
        if (!prompt) { toast('Prompt vide', 'err'); btn.disabled = false; return; }
        const size = $('sizeSelect').value;
        const ratio = $('ratioSelect').value;

        try {
          const url = await Backend.generateImage(prompt, apiKeys, 'regen_hist_' + Date.now(), size, ratio);
          const imgWrap = card.querySelector('.img-wrap');
          const oldImgs = imgWrap.querySelectorAll('img');
          oldImgs.forEach(im => { im.classList.remove('new'); im.classList.add('old'); });
          const newImg = document.createElement('img');
          newImg.src = url.url;
          newImg.className = 'new';
          newImg.loading = 'lazy';
          newImg.alt = 'regenerated';
          imgWrap.appendChild(newImg);

          msg.className = 'status ok';
          msg.textContent = '✓ Nouvelle image';
          toast('Image régénérée', 'ok');

          Backend.logGeneration({
            style_slug: styleSlug,
            user_input: '',
            used_image: 0,
            vision_desc: '',
            final_prompt: prompt,
            image_url: url.url,
            parent_id: parentId,
          }).catch(() => {});
        } catch (e) {
          msg.className = 'status err';
          msg.textContent = '❌ ' + e.message;
        } finally {
          btn.disabled = false;
        }
      });
    }
  });
}

/* ============================================================
   FAVORIS (onglet dédié)
============================================================ */
let FAV_STATE = { page: 1, perPage: 30, total: 0, items: [] };

async function loadFavorites(page = 1) {
  const box = $('favBox');
  const pag = $('favPagination');
  box.innerHTML = '<p class="sub">⏳ Chargement…</p>';
  pag.classList.add('hidden');
  try {
    const r = await Backend.getFavorites(page, 30);
    const items = r.items || [];
    FAV_STATE = { page: r.page || page, perPage: r.per_page || 30, total: r.total || 0, items };

    if (!items.length) {
      box.innerHTML = '<p class="sub">Aucun favori pour le moment. Cliquez sur ⭐ sur une image pour l\'ajouter ici.</p>';
      return;
    }

    box.innerHTML = '<div class="result-grid">' + items.map(it => renderHistoryCard(it)).join('') + '</div>';
    bindHistoryCards(box);

    const totalPages = Math.max(1, Math.ceil(FAV_STATE.total / FAV_STATE.perPage));
    if (totalPages > 1) {
      pag.classList.remove('hidden');
      pag.innerHTML = `
        <button class="btn-mini" data-page="${Math.max(1, page-1)}" ${page<=1?'disabled':''}>← Précédent</button>
        <span class="page-info">Page ${page} / ${totalPages} · ${FAV_STATE.total} favori(s)</span>
        <button class="btn-mini" data-page="${Math.min(totalPages, page+1)}" ${page>=totalPages?'disabled':''}>Suivant →</button>
      `;
      pag.querySelectorAll('[data-page]').forEach(btn => {
        btn.addEventListener('click', () => {
          const p = parseInt(btn.dataset.page, 10);
          if (p && p !== page) loadFavorites(p);
        });
      });
    }
  } catch(e) {
    box.innerHTML = '<p class="sub">Erreur : ' + esc(e.message) + '</p>';
  }
}

/* ============================================================
   TABS
============================================================ */
function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  ['create','admin','bulk','history','favorites'].forEach(id => {
    const el = $('tab-' + id);
    if (el) el.classList.toggle('hidden', id !== name);
  });
  if (name === 'history') loadHistory(1);
  if (name === 'bulk') loadStoryboards();
  if (name === 'favorites') loadFavorites(1);
}

/* ============================================================
   BOOT
============================================================ */
async function bootApp() {
  // Restaure les clés API
  try {
    const saved = JSON.parse(localStorage.getItem(CFG.lsKeys) || '[]');
    if (saved.length) {
      apiKeys = saved;
      $('apiInput').value = saved.join('\n');
      $('apiStatus').className = 'status ok';
      $('apiStatus').textContent = '✓ ' + saved.length + ' clé(s) restaurée(s)';
    }
  } catch(e){}
  updateApiTag();

  // Restaure le filtre "favoris uniquement"
  try {
    const favFilter = localStorage.getItem(CFG.lsFavFilter) === '1';
    if (favFilter) $('historyFavOnly').checked = true;
  } catch(e){}

  // Charge les données
  await loadStyles();
  await loadStoryboards();
  bindPipelineControls();
}

/* ============================================================
   BIND — attachement des événements
============================================================ */
function bind() {
  // Auth
  $('loginBtn').addEventListener('click', doLogin);
  $('loginPin').addEventListener('keypress', e => { if (e.key === 'Enter') doLogin(); });
  $('logoutBtn').addEventListener('click', doLogout);

  // Tabs
  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => switchTab(t.dataset.tab));
  });

  // Image upload (click + drag&drop)
  $('imageInput').addEventListener('change', async (e) => {
    const f = e.target.files[0];
    if (f) await handleImage(f);
    e.target.value = '';
  });
  const drop = $('imageDrop');
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); drop.style.borderColor = 'var(--gold)';
  }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); drop.style.borderColor = '';
  }));
  drop.addEventListener('drop', async (e) => {
    const f = e.dataTransfer.files[0];
    if (f) await handleImage(f);
  });

  // Génération
  $('generateBtn').addEventListener('click', generate);

  // + Nouveau style
  $('addStyleBtn').addEventListener('click', async () => {
    const name = await showModal({
      title: 'Nouveau style',
      message: 'Donnez un nom à votre style.',
      fieldLabel: 'Nom du style',
      fieldValue: '',
      fieldType: 'text',
      okText: 'Suivant',
    });
    if (!name) return;
    const promptTxt = await showModal({
      title: 'Prompt système',
      message: 'Décrivez la consigne de style (rôle, règles, palette…).',
      fieldLabel: 'Prompt du style',
      fieldValue: '',
      fieldType: 'textarea',
      okText: 'Créer',
    });
    if (!promptTxt) return;
    try {
      await Backend.saveStyle(0, name.trim(), promptTxt.trim());
      toast('Style ajouté', 'ok');
      await loadStyles();
    } catch(e) { toast('Ajout : ' + e.message, 'err'); }
  });

  // + Nouvelle liste storyboard
  $('addSbBtn').addEventListener('click', async () => {
    const name = await showModal({
      title: 'Nouvelle liste storyboard',
      message: 'Donnez un nom à votre liste.',
      fieldLabel: 'Nom de la liste',
      fieldValue: '',
      fieldType: 'text',
      okText: 'Créer',
    });
    if (!name) return;
    try {
      await Backend.saveStoryboard(0, name.trim(), ['gros plan', 'portrait', 'plan américain']);
      toast('Liste ajoutée', 'ok');
      await loadStoryboards();
    } catch(e) { toast('Ajout : ' + e.message, 'err'); }
  });

  // API panel (accordéon)
  $('apiHead').addEventListener('click', () => {
    $('apiBody').classList.toggle('open');
    $('apiChevron').textContent = $('apiBody').classList.contains('open') ? '▼' : '▲';
  });
  $('apiValidate').addEventListener('click', validateKeys);
  $('apiClear').addEventListener('click', () => {
    $('apiInput').value = '';
    apiKeys = [];
    try { localStorage.removeItem(CFG.lsKeys); } catch(e){}
    $('apiStatus').className = 'status';
    $('apiStatus').textContent = 'Aucune clé configurée';
    updateApiTag();
  });

  // Storyboard checkbox
  $('storyboardCheck').addEventListener('change', () => {
    $('sbSelectWrap').classList.toggle('hidden', !$('storyboardCheck').checked);
    updateSbHint();
  });
  $('storyboardSelect').addEventListener('change', updateSbHint);

  // Filtre favoris (persistant)
  $('historyFavOnly').addEventListener('change', () => {
    try {
      localStorage.setItem(CFG.lsFavFilter, $('historyFavOnly').checked ? '1' : '0');
    } catch(e){}
    loadHistory(1);
  });

  // Initialisation selon l'état de connexion
  if (LOGGED) {
    $('loginBox').classList.add('hidden');
    $('main').classList.remove('hidden');
    $('logoutBtn').classList.remove('hidden');
    $('globalStat').innerHTML = 'Connecté : <b>' + esc(CURRENT_USER) + '</b>';
    bootApp();
  } else {
    $('main').classList.add('hidden');
  }
}

/* ============================================================
   INIT
============================================================ */
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
else bind();

