/*
 * Session places — administration page. Depends on i18n.js and window.SP_CONTEXT (provided by index.php).
 * Single state, full re-render through innerHTML: every value coming from the database goes through esc() / th().
 * Settings being edited live in state.draft so a re-render never loses input.
 */
(function () {
  'use strict';
  let ctx = window.SP_CONTEXT;
  const { t } = window.SPi18n.make(ctx.lang);
  const app = document.getElementById('sp-admin');

  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Translation with escaped params, for use inside HTML
  const th = (key, params) => {
    if (!params) return t(key);
    const p = {};
    for (const k in params) p[k] = esc(params[k]);
    return t(key, p);
  };

  // format: which address, HTML code and preview are shown (both formats are always served, nothing is saved)
  const state = { format: 'full', draft: null, busy: false, flash: '', flashErr: false, channel: ctx.channel || 'stable', update: null, updateMsg: '' };

  function freshDraft() {
    const s = ctx.settings;
    return {
      public: s.public, lang: s.lang, divisions: s.divisions, hidden: new Set(s.hidden),
      targets: JSON.parse(JSON.stringify(s.targets || {})),
    };
  }
  state.draft = freshDraft();

  function draftPayload() {
    const d = state.draft;
    return { public: d.public, lang: d.lang, divisions: d.divisions, hidden: [...d.hidden].sort((a, b) => a - b), targets: d.targets };
  }

  // Draft differs from the saved settings (compared after the same cleaning: empty target lists dropped)
  function dirty() {
    const norm = s => {
      const targets = {};
      Object.keys(s.targets || {}).sort().forEach(ses => {
        const byLabel = {};
        Object.keys(s.targets[ses]).sort().forEach(l => { if (s.targets[ses][l] > 0) byLabel[l] = s.targets[ses][l]; });
        if (Object.keys(byLabel).length) targets[ses] = byLabel;
      });
      return JSON.stringify([!!s.public, s.lang, !!s.divisions, [...s.hidden].map(Number).sort((a, b) => a - b), targets]);
    };
    return norm(draftPayload()) !== norm(ctx.settings);
  }

  // ---------- API ----------

  async function api(action, body) {
    const r = await fetch('api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action }, body || {})),
    });
    let j;
    try { j = await r.json(); } catch (e) { throw new Error('HTTP ' + r.status); }
    if (j && j.error) throw new Error(j.error);
    return j;
  }

  async function run(fn, okMsg) {
    if (state.busy) return;
    state.busy = true;
    render();
    try {
      const res = await fn();
      flash(typeof okMsg === 'function' ? okMsg(res) : okMsg, false);
    } catch (e) {
      flash(t('admin.failed', { error: e.message }), true);
    } finally {
      state.busy = false;
      render();
    }
  }

  let flashTimer;
  function flash(m, err) {
    state.flash = m || '';
    state.flashErr = !!err;
    clearTimeout(flashTimer);
    if (m) flashTimer = setTimeout(() => { state.flash = ''; render(); }, 6000);
  }

  // ---------- actions ----------

  function saveSettings() {
    run(async () => {
      ctx = await api('settings', { settings: draftPayload() });
      state.draft = freshDraft();
    }, t('admin.saved'));
  }

  async function checkUpdate(force) {
    try {
      state.update = await api('update-check', { force: !!force });
      state.updateMsg = state.update.error ? t('update.checkFailed', { error: state.update.error }) : '';
    } catch (e) {
      state.updateMsg = t('update.checkFailed', { error: e.message });
    }
    render();
  }

  function setChannel(channel) {
    run(async () => {
      await api('update-channel', { channel });
      state.channel = channel;
      await checkUpdate(true);
    });
  }

  function installUpdate() {
    run(async () => {
      const res = await api('update-install');
      state.update = null;
      state.updateMsg = t('update.done', { version: res.version });
    });
  }

  function copy(id) {
    const el = document.getElementById(id);
    el.select();
    (navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.reject())
      .then(() => { flash(t('image.copied')); render(); }, () => document.execCommand('copy'));
  }

  // ---------- rendering ----------

  const sessionLabel = s => t('sessions.label', { n: s.order }) + (s.name ? ' – ' + s.name : '');
  const publicUrl = () => ctx.publicUrl + (state.format === 'compact' ? '&f=compact' : '');
  const embedCode = () => `<img src="${esc(publicUrl())}" alt="${esc(t('image.alt'))}" style="max-width:100%;height:auto">`;

  function render() {
    const focus = document.activeElement && document.activeElement.id;
    app.innerHTML = [
      renderHeader(),
      ctx.gd ? '' : `<div class="sp-alert sp-err">${th('alert.noGd')}</div>`,
      state.flash ? `<div class="sp-alert ${state.flashErr ? 'sp-err' : 'sp-ok'}">${esc(state.flash)}</div>` : '',
      renderImage(),
      renderSessions(),
      renderDistances(),
      `<div class="sp-row"><button type="button" class="sp-primary" id="sp-save" ${state.busy ? 'disabled' : ''}>${th('settings.save')}</button>
        ${dirty() ? `<span class="sp-warn-text">${th('alert.unsaved')}</span>` : ''}</div>`,
      renderPreview(),
    ].join('');
    bind();
    if (focus) {
      const el = document.getElementById(focus);
      if (el) el.focus();
    }
  }

  function renderHeader() {
    let upd = '';
    if (ctx.isAdmin) {
      const u = state.update;
      upd = `<span class="sp-upd">
        <label>${th('update.channel')} <select id="sp-channel" ${state.busy ? 'disabled' : ''}>
          ${['stable', 'nightly'].map(c => `<option value="${c}" ${state.channel === c ? 'selected' : ''}>${th('update.channel.' + c)}</option>`).join('')}
        </select></label>
        ${u && u.available ? `<span>${th('update.available', { latest: u.latest, current: ctx.version })}</span>
          <button type="button" id="sp-update" ${state.busy ? 'disabled' : ''}>${th('update.install')}</button>` : ''}
        ${u && !u.available && !u.error ? `<span class="sp-muted">${th('update.upToDate')}</span>` : ''}
        <button type="button" class="sp-linkbtn" id="sp-update-check">${th('update.check')}</button>
        ${state.updateMsg ? `<span>${esc(state.updateMsg)}</span>` : ''}
      </span>`;
    }
    return `<div class="sp-head"><h2>${th('admin.title')}</h2><span class="sp-muted">v${esc(ctx.version)}</span>${upd}</div>`;
  }

  function renderImage() {
    const d = state.draft;
    const on = ctx.settings.public;
    return `<div class="sp-box">
      <h3>${th('image.title')} <span class="sp-status ${on ? 'sp-on' : ''}">${th(on ? 'image.on' : 'image.off')}</span></h3>
      <label class="sp-chk"><input type="checkbox" id="sp-public" ${d.public ? 'checked' : ''}> ${th('image.public')}</label>
      <div class="sp-row"><label>${th('image.lang')} <select id="sp-lang">
        ${['fr', 'en'].map(l => `<option value="${l}" ${d.lang === l ? 'selected' : ''}>${th('image.lang.' + l)}</option>`).join('')}
      </select></label></div>
      <div class="sp-row"><label>${th('image.format')} <select id="sp-format">
        ${['full', 'compact'].map(f => `<option value="${f}" ${state.format === f ? 'selected' : ''}>${th('image.format.' + f)}</option>`).join('')}
      </select></label></div>
      <div class="sp-row sp-link-row"><label for="sp-url">${th('image.url')}</label>
        <input type="text" id="sp-url" readonly value="${esc(publicUrl())}">
        <button type="button" id="sp-copy-url">${th('image.copy')}</button></div>
      <div class="sp-row sp-link-row"><label for="sp-embed">${th('image.embed')}</label>
        <input type="text" id="sp-embed" readonly value="${esc(embedCode())}">
        <button type="button" id="sp-copy-embed">${th('image.copy')}</button></div>
      <p class="sp-muted sp-small">${th('image.help')}</p>
    </div>`;
  }

  function renderSessions() {
    const d = state.draft;
    const hasQuotas = Object.keys(ctx.quotas).length > 0;
    const rows = ctx.sessions.map(s => {
      const used = ctx.used[s.order] || 0;
      const free = Math.max(0, s.capacity - used);
      return `<tr class="${s.capacity > 0 && free === 0 ? 'sp-full' : ''}">
        <td><input type="checkbox" class="sp-show" value="${s.order}" ${d.hidden.has(s.order) ? '' : 'checked'} ${s.capacity > 0 ? '' : 'disabled'}></td>
        <td>${esc(sessionLabel(s))}</td><td>${s.capacity}</td><td>${used}</td><td><b>${s.capacity > 0 ? free : '–'}</b></td>
      </tr>`;
    }).join('');
    const note = !ctx.selfReg ? th('divisions.noModule') : (!hasQuotas ? th('divisions.none') : '');
    return `<div class="sp-box">
      <h3>${th('sessions.title')}</h3>
      <div class="sp-scroll"><table class="sp-table">
        <tr><th>${th('sessions.show')}</th><th>${th('sessions.session')}</th><th>${th('sessions.capacity')}</th><th>${th('sessions.used')}</th><th>${th('sessions.free')}</th></tr>
        ${rows}
      </table></div>
      <p class="sp-muted sp-small">${th('sessions.help')}</p>
      <label class="sp-chk"><input type="checkbox" id="sp-divisions" ${d.divisions ? 'checked' : ''}> ${th('divisions.show')}
        ${note ? `<span class="sp-muted">(${note})</span>` : ''}</label>
    </div>`;
  }

  function renderDistances() {
    const d = state.draft;
    if (!ctx.labels.length) {
      return `<div class="sp-box"><h3>${th('distances.title')}</h3><p class="sp-muted">${th('distances.none')}</p></div>`;
    }
    const rows = ctx.sessions.filter(s => s.capacity > 0).map(s => {
      const byLabel = d.targets[s.order] || {};
      const total = ctx.labels.reduce((n, l) => n + (parseInt(byLabel[l], 10) || 0), 0);
      return `<tr>
        <td>${esc(sessionLabel(s))}</td><td>${s.targets}</td>
        ${ctx.labels.map((l, i) => `<td><input type="number" min="0" max="999" class="sp-target" id="sp-t-${s.order}-${i}"
          data-ses="${s.order}" data-label="${esc(l)}" value="${byLabel[l] ? esc(byLabel[l]) : ''}"></td>`).join('')}
        <td>${total || ''} ${total > s.targets ? `<span class="sp-warn-text">${th('distances.over', { n: s.targets })}</span>` : ''}</td>
      </tr>`;
    }).join('');
    return `<div class="sp-box">
      <h3>${th('distances.title')}</h3>
      <p class="sp-muted sp-small">${th('distances.help')}</p>
      <div class="sp-scroll"><table class="sp-table">
        <tr><th>${th('sessions.session')}</th><th>${th('distances.targets')}</th>${ctx.labels.map(l => `<th>${esc(l)}</th>`).join('')}<th>${th('distances.total')}</th></tr>
        ${rows}
      </table></div>
    </div>`;
  }

  function renderPreview() {
    if (!ctx.gd) return '';
    return `<div class="sp-box">
      <h3>${th('preview.title')}</h3>
      <p class="sp-muted sp-small">${th('preview.help')}</p>
      <img class="sp-preview" src="preview.php?v=${esc(ctx.hash)}${state.format === 'compact' ? '&amp;f=compact' : ''}" alt="${esc(t('image.alt'))}">
    </div>`;
  }

  // ---------- events ----------

  function bind() {
    const $ = id => document.getElementById(id);
    const on = (id, ev, fn) => { const el = $(id); if (el) el.addEventListener(ev, fn); };
    const d = state.draft;

    on('sp-public', 'change', e => { d.public = e.target.checked; render(); });
    on('sp-lang', 'change', e => { d.lang = e.target.value; render(); });
    on('sp-format', 'change', e => { state.format = e.target.value === 'compact' ? 'compact' : 'full'; render(); });
    on('sp-divisions', 'change', e => { d.divisions = e.target.checked; render(); });
    app.querySelectorAll('.sp-show').forEach(el => el.addEventListener('change', () => {
      const n = parseInt(el.value, 10);
      el.checked ? d.hidden.delete(n) : d.hidden.add(n);
      render();
    }));
    app.querySelectorAll('.sp-target').forEach(el => el.addEventListener('change', () => {
      const ses = el.dataset.ses, label = el.dataset.label, n = parseInt(el.value, 10);
      if (!d.targets[ses]) d.targets[ses] = {};
      if (n > 0) d.targets[ses][label] = Math.min(999, n); else delete d.targets[ses][label];
      render();
    }));
    on('sp-save', 'click', saveSettings);
    on('sp-copy-url', 'click', () => copy('sp-url'));
    on('sp-copy-embed', 'click', () => copy('sp-embed'));

    on('sp-channel', 'change', e => setChannel(e.target.value));
    on('sp-update-check', 'click', () => checkUpdate(true));
    on('sp-update', 'click', installUpdate);
  }

  render();
  if (ctx.isAdmin) checkUpdate(false);
})();
