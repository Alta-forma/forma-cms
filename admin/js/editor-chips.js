/**
 * Forma editor chips — [[shortcode]] tokens as widgets in CodeMirror 5.
 * Source of truth stays the textarea. SEO is a reserved [[seo]] slot.
 */
(function () {
  var STOCK = { search: 1, 'search-ui': 1, 'error-ui': 1 };
  var snippetItems = [];
  var snippetMap = {};
  var nestIssues = {};
  var loaded = false;
  var pop;

  function csrf() {
    var m = document.body.getAttribute('hx-headers');
    if (!m) return '';
    try { return JSON.parse(m)['X-CSRF-Token'] || ''; } catch (e) { return ''; }
  }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function walkNest(code, stack, issues, map) {
    if (stack.indexOf(code) !== -1) {
      stack.concat([code]).forEach(function (c) { issues[c] = 'cycle'; });
      return;
    }
    if (stack.length >= 4) {
      issues[code] = issues[code] || 'depth';
      return;
    }
    var body = map[code] || '';
    var re = /\[\[([a-zA-Z0-9_-]+)\]\]/g;
    var m;
    var next = stack.concat([code]);
    while ((m = re.exec(body))) {
      if (m[1] === 'seo') continue;
      walkNest(m[1], next, issues, map);
    }
  }

  function rebuildNest() {
    nestIssues = {};
    Object.keys(snippetMap).forEach(function (code) {
      walkNest(code, [], nestIssues, snippetMap);
    });
  }

  function loadSnippets() {
    return fetch('actions/toolbar-data.php?type=snippets')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        snippetItems = data.items || [];
        snippetMap = {};
        snippetItems.forEach(function (it) {
          snippetMap[it.shortcode] = it.content || '';
        });
        rebuildNest();
        loaded = true;
      })
      .catch(function () { loaded = true; });
  }

  function tokenKind(code) {
    if (code === 'seo') return 'sys';
    if (STOCK[code]) return 'stock';
    return 'user';
  }

  function findTokens(text) {
    var out = [];
    var skipUntil = 0;
    var meta = text.match(/^\s*<!--META\s*[\s\S]*?-->/);
    if (meta) skipUntil = meta[0].length;
    var re = /\[\[(!?)([a-zA-Z0-9_-]+)\]\]/g;
    var m;
    while ((m = re.exec(text))) {
      if (m[1] === '!') continue;
      if (m.index < skipUntil) continue;
      out.push({
        from: m.index,
        to: m.index + m[0].length,
        code: m[2],
        raw: m[0],
      });
    }
    return out;
  }

  function ensurePop() {
    if (pop) return pop;
    pop = document.createElement('div');
    pop.className = 'fx-chip-pop';
    pop.hidden = true;
    document.body.appendChild(pop);
    document.addEventListener('click', function (e) {
      if (!pop || pop.hidden) return;
      if (pop.contains(e.target) || e.target.closest('.fx-cm-chip')) return;
      hidePop();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') hidePop();
    });
    return pop;
  }

  function hidePop() {
    if (pop) pop.hidden = true;
  }

  function positionPop(anchor) {
    var r = anchor.getBoundingClientRect();
    pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 280)) + 'px';
    pop.style.top = (r.bottom + 8) + 'px';
    pop.hidden = false;
  }

  function itemFor(code) {
    for (var i = 0; i < snippetItems.length; i++) {
      if (snippetItems[i].shortcode === code) return snippetItems[i];
    }
    return null;
  }

  function issueFor(code) {
    if (nestIssues[code]) return nestIssues[code];
    var body = snippetMap[code] || '';
    var re = /\[\[([a-zA-Z0-9_-]+)\]\]/g;
    var m;
    while ((m = re.exec(body))) {
      if (nestIssues[m[1]]) return nestIssues[m[1]];
    }
    return '';
  }

  function openPop(cm, tok, el) {
    ensurePop();
    var kind = tokenKind(tok.code);
    var issue = issueFor(tok.code);
    var it = itemFor(tok.code);
    var html = '';
    if (kind === 'sys') {
      html += '<div class="fx-chip-pop-kicker">System</div>';
      html += '<div class="fx-chip-pop-title">SEO</div>';
      html += '<p>Forma writes <code>&lt;title&gt;</code>, social cards, and JSON-LD here. Delete the chip to turn it off.</p>';
      html += '<div class="switch-row"><div class="sw-text"><strong>Emit on this page</strong>' +
        '<span class="hint">Off removes [[seo]] from the file.</span></div>' +
        '<label class="fx-switch"><input type="checkbox" data-seo-switch checked><span class="track"></span></label></div>';
    } else {
      html += '<div class="fx-chip-pop-kicker">' + (kind === 'stock' ? 'Stock snippet' : 'Snippet') + '</div>';
      html += '<div class="fx-chip-pop-title">[[' + esc(tok.code) + ']]</div>';
      if (it) html += '<p class="hint">' + esc(it.filename) + '</p>';
      if (issue === 'cycle') {
        html += '<p class="fx-chip-pop-bang"><i class="fas fa-exclamation"></i> Nested snippets loop. Fix the snippet bodies.</p>';
      } else if (issue === 'depth') {
        html += '<p class="fx-chip-pop-bang"><i class="fas fa-exclamation"></i> Nesting is deeper than 4 levels.</p>';
      }
      if (it) {
        html += '<button type="button" class="standard-btn" data-open-snippet="' + esc(it.filename) + '">' +
          '<i class="small fas fa-pen"></i> Edit snippet</button>';
      }
    }
    pop.innerHTML = html;
    positionPop(el);
    var sw = pop.querySelector('[data-seo-switch]');
    if (sw) {
      sw.addEventListener('change', function () {
        if (!sw.checked) {
          hidePop();
          removeSeo(cm);
        }
      });
    }
    var openBtn = pop.querySelector('[data-open-snippet]');
    if (openBtn) {
      openBtn.addEventListener('click', function () {
        hidePop();
        var file = openBtn.getAttribute('data-open-snippet');
        var href = 'index.php?section=snippets&file=' + encodeURIComponent(file);
        if (window.htmx) {
          window.htmx.ajax('GET', 'partials/snippets.php?file=' + encodeURIComponent(file), { target: '#main', swap: 'innerHTML' });
          history.pushState({}, '', href);
        } else {
          window.location.href = href;
        }
      });
    }
  }

  /** The leading <!--META ... --> block — collapsed into a chip so raw YAML-ish text doesn't clutter the editor. */
  function openMetaPop(el) {
    ensurePop();
    var html = '';
    html += '<div class="fx-chip-pop-kicker">System</div>';
    html += '<div class="fx-chip-pop-title">Page details</div>';
    html += '<p>Filename, slug, and content type — edit them in the Page details panel above, not here.</p>';
    html += '<button type="button" class="standard-btn" data-open-page-details>' +
      '<i class="small fas fa-sliders"></i> Open panel</button>';
    pop.innerHTML = html;
    positionPop(el);
    var openBtn = pop.querySelector('[data-open-page-details]');
    if (openBtn) {
      openBtn.addEventListener('click', function () {
        hidePop();
        var panel = document.querySelector('#page-form .meta-panel');
        if (!panel) return;
        panel.classList.remove('collapsed');
        var t = panel.querySelector('.meta-panel-toggle');
        if (t) t.setAttribute('aria-expanded', 'true');
        panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var f = panel.querySelector('#filename');
        if (f) f.focus();
      });
    }
  }

  function findMetaBlock(text) {
    var m = /^\s*<!--META\s*[\s\S]*?-->/.exec(text);
    if (!m) return null;
    return { from: 0, to: m[0].length };
  }

  function paintMetaChip(cm, text) {
    var ta = cm.getTextArea && cm.getTextArea();
    if (!ta || !ta.hasAttribute('data-seo-head')) return;
    var block = findMetaBlock(text);
    if (!block) return;
    var from = cm.posFromIndex(block.from);
    var to = cm.posFromIndex(block.to);
    var el = document.createElement('span');
    el.className = 'fx-cm-chip fx-cm-chip-sys';
    el.setAttribute('contenteditable', 'false');
    el.innerHTML = '<span class="fx-cm-chip-label">Page details</span>';
    el.addEventListener('mousedown', function (e) {
      e.preventDefault();
      e.stopPropagation();
      openMetaPop(el);
    });
    var mk = cm.markText(from, to, {
      replacedWith: el,
      atomic: true,
      handleMouseEvents: true,
      inclusiveLeft: false,
      inclusiveRight: false,
    });
    cm._fxMarks.push(mk);
  }

  function clearMarks(cm) {
    (cm._fxMarks || []).forEach(function (mk) {
      try { mk.clear(); } catch (e) {}
    });
    cm._fxMarks = [];
  }

  /** Server-rendered status row inside the Page details meta-panel — mirrors seoStatusRow below. */
  function metaStatusRow(cm) {
    if (cm._fxMetaRow) return cm._fxMetaRow;
    var ta = cm.getTextArea && cm.getTextArea();
    var form = ta && ta.closest('form');
    var row = form && form.querySelector('[data-meta-status]');
    if (!row) return null;
    var btn = row.querySelector('[data-meta-status-btn]');
    if (btn && !btn._fxWired) {
      btn._fxWired = true;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        insertMetaBlock(cm);
        cm.focus();
      });
    }
    cm._fxMetaRow = row;
    return row;
  }

  function renderMetaStatus(cm) {
    var ta = cm.getTextArea && cm.getTextArea();
    if (!ta || ta.getAttribute('data-chips') !== '1') return;
    var row = metaStatusRow(cm);
    if (!row) return;
    row.hidden = !!findMetaBlock(cm.getValue());
  }

  /** Pin a starter <!--META--> block, seeded from the current Filename/Slug fields. */
  function insertMetaBlock(cm) {
    if (findMetaBlock(cm.getValue())) return;
    var ta = cm.getTextArea && cm.getTextArea();
    var form = ta && ta.closest('form');
    var filename = (form && form.querySelector('#filename') && form.querySelector('#filename').value.trim()) || '';
    var slug = (form && form.querySelector('#slug') && form.querySelector('#slug').value.trim()) || (filename ? '/' + filename : '/');
    var lines = ['<!--META', 'slug: ' + (slug || '/'), 'title: ' + (filename || 'New Page'), '-->', ''];
    cm._fxQuiet = true;
    cm.replaceRange(lines.join('\n'), { line: 0, ch: 0 });
    cm._fxQuiet = false;
    paint(cm);
  }

  /** Server-rendered status row inside the SEO meta-panel — plain DOM, not a CodeMirror widget. */
  function seoStatusRow(cm) {
    if (cm._fxSeoRow) return cm._fxSeoRow;
    var ta = cm.getTextArea && cm.getTextArea();
    var form = ta && ta.closest('form');
    var row = form && form.querySelector('[data-seo-status]');
    if (!row) return null;
    var btn = row.querySelector('[data-seo-status-btn]');
    if (btn && !btn._fxWired) {
      btn._fxWired = true;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        insertSeo(cm);
        cm.focus();
      });
    }
    cm._fxSeoRow = row;
    cm._fxSeoRowLabel = row.querySelector('.fx-seo-status-text');
    return row;
  }

  function renderSeoStatus(cm) {
    var ta = cm.getTextArea && cm.getTextArea();
    if (!ta || ta.getAttribute('data-chips') !== '1' || !ta.hasAttribute('data-seo-head')) {
      if (cm._fxSeoRow) cm._fxSeoRow.hidden = true;
      return;
    }
    var has = cm.getValue().indexOf('[[seo]]') !== -1;
    if (has) {
      if (cm._fxSeoRow) cm._fxSeoRow.hidden = true;
      cm._fxHadSeo = true;
      cm._fxSeoRemoved = false;
      return;
    }
    var mode = ta.getAttribute('data-seo-head') || 'auto';
    var row = seoStatusRow(cm);
    if (!row) return;
    var off = cm._fxSeoRemoved || mode === 'off' || mode === 'slot';
    row.hidden = false;
    row.className = 'fx-seo-status ' + (off ? 'is-warn' : 'is-info');
    cm._fxSeoRowLabel.textContent = off
      ? 'SEO is off for this page.'
      : 'SEO auto-inserts after <head>.';
  }

  /** Amber footer badge — same "SEO off" condition as the status row, surfaced next to Save too. */
  function pageAlertEl(cm) {
    if (cm._fxAlert) return cm._fxAlert;
    var ta = cm.getTextArea && cm.getTextArea();
    var scope = ta && ta.closest('.editor-container');
    var el = scope && scope.querySelector('[data-page-alert]');
    if (!el) return null;
    if (!el._fxWired) {
      el._fxWired = true;
      el.addEventListener('click', function (e) {
        e.preventDefault();
        insertSeo(cm);
        cm.focus();
      });
    }
    cm._fxAlert = el;
    return el;
  }

  function renderPageAlert(cm) {
    var ta = cm.getTextArea && cm.getTextArea();
    if (!ta || !ta.hasAttribute('data-seo-head')) {
      if (cm._fxAlert) cm._fxAlert.hidden = true;
      return;
    }
    // Same trigger as the status row: no [[seo]] chip in the file at all,
    // whether it was ever pinned (mode=slot/off) or never inserted (mode=auto).
    var has = cm.getValue().indexOf('[[seo]]') !== -1;
    var mode = ta.getAttribute('data-seo-head') || 'auto';
    var off = !has && (cm._fxSeoRemoved || mode === 'off' || mode === 'slot');
    var el = pageAlertEl(cm);
    if (!el) return;
    el.hidden = has;
    el.title = off
      ? 'SEO is off for this page — click to turn it back on'
      : "SEO isn't pinned on this page — click to add it";
  }

  function headPos(cm) {
    var text = cm.getValue();
    var m = /<head[^>]*>/i.exec(text);
    if (m) return cm.posFromIndex(m.index + m[0].length);
    return { line: 0, ch: 0 };
  }

  /** Keep [[seo]] on its own line so the chip can span the editor. */
  function ensureSeoOwnLine(cm) {
    var text = cm.getValue();
    var idx = text.indexOf('[[seo]]');
    if (idx < 0) return;
    var from = cm.posFromIndex(idx);
    var line = cm.getLine(from.line) || '';
    if (line.trim() === '[[seo]]') return;
    var to = cm.posFromIndex(idx + 7);
    var before = from.ch > 0 ? '\n' : '';
    var after = line.slice(from.ch + 7).trim() !== '' ? '\n' : '';
    if (before || after) {
      cm.replaceRange(before + '[[seo]]' + after, from, to);
    }
  }

  function insertSeo(cm) {
    cm._fxQuiet = true;
    cm._fxSeoRemoved = false;
    cm._fxHadSeo = true;
    if (cm.getValue().indexOf('[[seo]]') === -1) {
      cm.replaceRange('\n[[seo]]\n', headPos(cm));
    } else {
      ensureSeoOwnLine(cm);
    }
    cm._fxQuiet = false;
    paint(cm);
  }

  function removeSeo(cm) {
    var text = cm.getValue();
    var idx = text.indexOf('[[seo]]');
    if (idx < 0) return;
    var from = cm.posFromIndex(idx);
    var to = cm.posFromIndex(idx + 7);
    cm._fxQuiet = true;
    cm.replaceRange('', from, to);
    cm._fxQuiet = false;
    cm._fxHadSeo = false;
    cm._fxSeoRemoved = true;
    paint(cm);
  }

  function paint(cm) {
    if (typeof CodeMirror === 'undefined') return;
    clearMarks(cm);
    var text = cm.getValue();
    paintMetaChip(cm, text);
    var tokens = findTokens(text);
    tokens.forEach(function (tok) {
      var from = cm.posFromIndex(tok.from);
      var to = cm.posFromIndex(tok.to);
      var kind = tokenKind(tok.code);
      var issue = issueFor(tok.code);
      var el = document.createElement('span');
      el.className = 'fx-cm-chip fx-cm-chip-' + kind + (issue ? ' has-bang' : '');
      el.setAttribute('contenteditable', 'false');
      var label = tok.code === 'seo' ? 'SEO' : tok.code;
      el.innerHTML = '<span class="fx-cm-chip-label">' + esc(label) + '</span>' +
        (issue ? '<span class="fx-cm-chip-bang" title="Nesting problem">!</span>' : '');
      el.addEventListener('mousedown', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openPop(cm, tok, el);
      });
      var mk = cm.markText(from, to, {
        replacedWith: el,
        atomic: true,
        handleMouseEvents: true,
        inclusiveLeft: false,
        inclusiveRight: false,
      });
      cm._fxMarks.push(mk);
    });
    renderMetaStatus(cm);
    renderSeoStatus(cm);
    renderPageAlert(cm);
  }

  function addIndentGuide(cm) {
    if (cm._fxIndent) return;
    cm._fxIndent = true;
    cm.addOverlay({
      token: function (stream) {
        if (stream.sol()) {
          var n = 0;
          while (stream.peek() === ' ' || stream.peek() === '\t') {
            stream.next();
            n++;
          }
          if (n) return 'fx-indent';
        }
        stream.skipToEnd();
        return null;
      },
    });
  }

  function mount(cm, ta) {
    if (!cm || cm._fxChips) return;
    cm._fxChips = true;
    cm._fxMarks = [];
    cm._fxHadSeo = !!(ta && ta.value && ta.value.indexOf('[[seo]]') !== -1);
    cm._fxSeoRemoved = false;
    addIndentGuide(cm);
    var t = null;
    function schedule() {
      clearTimeout(t);
      t = setTimeout(function () { paint(cm); }, 80);
    }
    if (ta && ta.hasAttribute('data-seo-head')) {
      cm._fxQuiet = true;
      ensureSeoOwnLine(cm);
      cm._fxQuiet = false;
    }
    cm.on('change', function () {
      var now = cm.getValue().indexOf('[[seo]]') !== -1;
      if (!cm._fxQuiet && cm._fxHadSeo && !now) cm._fxSeoRemoved = true;
      if (now) {
        cm._fxHadSeo = true;
        cm._fxSeoRemoved = false;
      }
      if (!cm._fxQuiet) schedule();
    });
    var ready = loaded ? Promise.resolve() : loadSnippets();
    ready.then(function () { paint(cm); });
  }

  function insertBlock(cm, code) {
    if (!cm) return;
    if (code === 'seo') {
      insertSeo(cm);
      cm.focus();
      return;
    }
    cm.replaceSelection('[[' + code + ']]');
    cm.focus();
  }

  window.FormaChips = {
    mount: function (root) {
      (root || document).querySelectorAll('textarea.code-editor[data-chips="1"]').forEach(function (ta) {
        if (ta.codemirror) mount(ta.codemirror, ta);
      });
    },
    insertBlock: insertBlock,
    reload: function () {
      loaded = false;
      return loadSnippets();
    },
    csrf: csrf,
  };
})();
