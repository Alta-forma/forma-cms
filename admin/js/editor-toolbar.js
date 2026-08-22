/**
 * Forma editor toolbar — insert pages/posts/uploads/snippets + quick-add modals.
 */
(function () {
  const initialized = new WeakSet();

  function csrf() {
    const m = document.body.getAttribute('hx-headers');
    if (!m) return '';
    try { return JSON.parse(m)['X-CSRF-Token'] || ''; } catch (e) { return ''; }
  }

  function isMd(cm) {
    return (cm.getOption('mode') || '') === 'markdown';
  }

  function insertAtCursor(text, cm) {
    if (!cm) return;
    cm.replaceSelection(text);
    cm.focus();
  }

  function closeDropdowns(toolbar) {
    toolbar.querySelectorAll('.dropdown.active').forEach((d) => d.classList.remove('active'));
  }

  function modal(title, fieldsHtml, onSubmit) {
    const backdrop = document.createElement('div');
    backdrop.className = 'fx-modal-backdrop';
    backdrop.innerHTML =
      '<div class="fx-modal" role="dialog">' +
      '<h3>' + title + '</h3>' +
      '<form id="fx-quick-form">' + fieldsHtml +
      '<div class="fx-modal-actions">' +
      '<button type="button" class="delete-btn" data-cancel style="min-width:auto;padding:6px 12px">Cancel</button>' +
      '<button type="submit" class="standard-btn" style="min-width:auto;padding:6px 12px">Create &amp; insert</button>' +
      '</div></form></div>';
    document.body.appendChild(backdrop);
    const form = backdrop.querySelector('#fx-quick-form');
    backdrop.querySelector('[data-cancel]').onclick = () => backdrop.remove();
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) backdrop.remove(); });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      onSubmit(new FormData(form), () => backdrop.remove());
    });
    form.querySelector('input,textarea')?.focus();
  }

  function quickAdd(kind, cm, toolbar) {
    if (kind === 'page') {
      modal('Quick add page',
        '<div class="form-group"><label>Filename</label><input name="filename" required placeholder="about"></div>' +
        '<div class="form-group"><label>Title</label><input name="title" placeholder="About"></div>' +
        '<div class="form-group"><label>Slug</label><input name="slug" placeholder="/about"></div>',
        (fd, done) => {
          fd.append('kind', 'page');
          fd.append('csrf_token', csrf());
          postQuick(fd, cm, toolbar, done);
        });
    } else if (kind === 'post') {
      modal('Quick add post',
        '<div class="form-group"><label>Filename</label><input name="filename" required placeholder="my-post"></div>' +
        '<div class="form-group"><label>Title</label><input name="title" required placeholder="My post"></div>',
        (fd, done) => {
          fd.append('kind', 'post');
          fd.append('csrf_token', csrf());
          postQuick(fd, cm, toolbar, done);
        });
    } else if (kind === 'snippet') {
      modal('Quick add snippet',
        '<div class="form-group"><label>Filename</label><input name="filename" required placeholder="cta-box"></div>' +
        '<div class="form-group"><label>Shortcode</label><input name="shortcode" required placeholder="cta"></div>' +
        '<div class="form-group"><label>Content</label><textarea name="content" rows="4"><p>New snippet</p></textarea></div>',
        (fd, done) => {
          fd.append('kind', 'snippet');
          fd.append('csrf_token', csrf());
          postQuick(fd, cm, toolbar, done);
        });
    }
  }

  function postQuick(fd, cm, toolbar, done) {
    fetch('actions/toolbar-quick-add.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf() } })
      .then((r) => r.json())
      .then((data) => {
        if (!data.success) throw new Error(data.error || 'Failed');
        insertAtCursor(data.insert || '', cm);
        done();
        loadAll(toolbar, cm);
      })
      .catch((err) => alert(err.message || 'Quick add failed'));
  }

  function loadType(type, toolbar, cm, render) {
    return fetch('actions/toolbar-data.php?type=' + encodeURIComponent(type))
      .then((r) => r.json())
      .then((data) => {
        const btn = toolbar.querySelector('[data-dropdown="' + type + '"]');
        if (!btn) return;
        const content = btn.closest('.dropdown').querySelector('.dropdown-content');
        render(content, data.items || [], btn, cm, toolbar);
      })
      .catch(() => {
        const btn = toolbar.querySelector('[data-dropdown="' + type + '"]');
        if (!btn) return;
        btn.closest('.dropdown').querySelector('.dropdown-content').innerHTML =
          '<div class="dropdown-header">Error</div><div class="dropdown-item">Failed to load</div>';
      });
  }

  function loadAll(toolbar, cm) {
    loadType('pages', toolbar, cm, (content, items, btn, cmInst, tb) => {
      let html = '<div class="dropdown-header">Pages</div>';
      html += '<div class="dropdown-item add-new" data-quick="page"><i class="fas fa-plus"></i> Quick add…</div>';
      items.forEach((it) => {
        html += '<div class="dropdown-item" data-insert="' + esc(it.path) + '"><i class="fas fa-file-alt"></i> ' + esc(it.label) + '</div>';
      });
      content.innerHTML = html;
      wireInsert(content, btn, cmInst, tb);
    });
    loadType('posts', toolbar, cm, (content, items, btn, cmInst, tb) => {
      let html = '<div class="dropdown-header">Blog</div>';
      html += '<div class="dropdown-item add-new" data-quick="post"><i class="fas fa-plus"></i> Quick add…</div>';
      items.forEach((it) => {
        html += '<div class="dropdown-item" data-insert="' + esc(it.path) + '"><i class="fas fa-blog"></i> ' + esc(it.title) + '</div>';
      });
      content.innerHTML = html;
      wireInsert(content, btn, cmInst, tb);
    });
    loadType('uploads', toolbar, cm, (content, items, btn, cmInst, tb) => {
      let html = '<div class="dropdown-header">Uploads</div>';
      html += '<div class="dropdown-item add-new" data-quick="upload"><i class="fas fa-plus"></i> Upload &amp; insert…</div>';
      items.forEach((it) => {
        html += '<div class="dropdown-item" data-insert="' + esc(it.path) + '"><i class="fas fa-file"></i> ' + esc(it.filename) + '</div>';
      });
      content.innerHTML = html;
      wireInsert(content, btn, cmInst, tb);
    });
    loadType('snippets', toolbar, cm, (content, items, btn, cmInst, tb) => {
      let html = '<div class="dropdown-header">Snippets</div>';
      html += '<div class="dropdown-item add-new" data-quick="snippet"><i class="fas fa-plus"></i> Quick add…</div>';
      items.forEach((it) => {
        html += '<div class="dropdown-item" data-insert="' + esc(it.insert) + '"><i class="fas fa-code"></i> ' + esc(it.filename) + ' <span style="opacity:.5">[[' + esc(it.shortcode) + ']]</span></div>';
      });
      content.innerHTML = html;
      wireInsert(content, btn, cmInst, tb);
    });
  }

  function wireInsert(content, btn, cm, toolbar) {
    content.querySelectorAll('[data-insert]').forEach((el) => {
      el.addEventListener('click', () => {
        insertAtCursor(el.getAttribute('data-insert'), cm);
        closeDropdowns(toolbar);
      });
    });
    content.querySelectorAll('[data-quick]').forEach((el) => {
      el.addEventListener('click', () => {
        const kind = el.getAttribute('data-quick');
        closeDropdowns(toolbar);
        if (kind === 'upload') {
          pickUpload(cm, toolbar);
        } else {
          quickAdd(kind, cm, toolbar);
        }
      });
    });
  }

  function pickUpload(cm, toolbar) {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.accept = 'image/*,audio/*,video/*,.pdf,.svg,.webp';
    input.style.display = 'none';
    document.body.appendChild(input);
    input.onchange = () => {
      const files = input.files;
      input.remove();
      if (!files || !files.length) return;
      const insertFrom = (data) => {
        const path = data.insert || data.path || data.url || '';
        const name = data.filename || 'file';
        const ext = (name.split('.').pop() || '').toLowerCase();
        const img = ['jpg','jpeg','png','gif','svg','webp','avif'].includes(ext);
        let text = path;
        if (img) text = isMd(cm) ? '![' + name + '](' + path + ')' : '<img src="' + path + '" alt="' + name + '">';
        else if (isMd(cm)) text = '[' + name + '](' + path + ')';
        else text = '<a href="' + path + '">' + name + '</a>';
        insertAtCursor(text, cm);
        loadAll(toolbar, cm);
      };
      if (window.FormaUploads && window.FormaUploads.uploadFiles) {
        window.FormaUploads.uploadFiles(files, {
          url: 'actions/toolbar-quick-add.php',
          field: 'file',
          kind: 'upload',
          list: false,
          onEach: (res) => { if (res.ok && res.data) insertFrom(res.data); },
        });
        return;
      }
      const file = files[0];
      const fd = new FormData();
      fd.append('kind', 'upload');
      fd.append('file', file);
      fd.append('csrf_token', csrf());
      fetch('actions/toolbar-quick-add.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf() } })
        .then((r) => r.json())
        .then((data) => {
          if (!data.success) throw new Error(data.error || 'Upload failed');
          insertFrom(data);
        })
        .catch((e) => alert(e.message));
    };
    input.click();
  }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function hideSnippets(toolbar) {
    if (document.querySelector('#snippet-form')) {
      const d = toolbar.querySelector('[data-dropdown="snippets"]')?.closest('.dropdown');
      if (d) d.style.display = 'none';
    }
  }

  function createToolbar(cm) {
    if (!cm || initialized.has(cm)) return;
    const wrap = cm.getWrapperElement();
    if (!wrap || wrap.querySelector('.editor-toolbar')) {
      initialized.add(cm);
      return;
    }

    const toolbar = document.createElement('div');
    toolbar.className = 'editor-toolbar dynamic-toolbar';
    toolbar.innerHTML =
      '<div class="dropdown"><button type="button" class="toolbar-btn" data-dropdown="pages"><i class="fas fa-file-alt"></i> Pages</button><div class="dropdown-content"><div class="dropdown-header">Loading…</div></div></div>' +
      '<div class="dropdown"><button type="button" class="toolbar-btn" data-dropdown="posts"><i class="fas fa-blog"></i> Blog</button><div class="dropdown-content"><div class="dropdown-header">Loading…</div></div></div>' +
      '<div class="dropdown"><button type="button" class="toolbar-btn" data-dropdown="uploads"><i class="fas fa-upload"></i> Uploads</button><div class="dropdown-content"><div class="dropdown-header">Loading…</div></div></div>' +
      '<div class="dropdown"><button type="button" class="toolbar-btn" data-dropdown="snippets"><i class="fas fa-code"></i> Snippets</button><div class="dropdown-content"><div class="dropdown-header">Loading…</div></div></div>' +
      '<button type="button" class="toolbar-upload-btn" title="Upload & insert"><i class="fas fa-cloud-upload-alt"></i></button>';

    wrap.appendChild(toolbar);
    hideSnippets(toolbar);

    toolbar.querySelectorAll('.toolbar-btn').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const dd = btn.closest('.dropdown');
        const open = dd.classList.contains('active');
        closeDropdowns(toolbar);
        if (!open) dd.classList.add('active');
      });
    });

    toolbar.querySelector('.toolbar-upload-btn')?.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      pickUpload(cm, toolbar);
    });

    loadAll(toolbar, cm);
    initialized.add(cm);
  }

  if (!window._fxToolbarDocClick) {
    window._fxToolbarDocClick = (e) => {
      if (!e.target.closest('.editor-toolbar .dropdown')) {
        document.querySelectorAll('.editor-toolbar .dropdown.active').forEach((d) => d.classList.remove('active'));
      }
    };
    document.addEventListener('click', window._fxToolbarDocClick);
  }

  window.FormaToolbar = {
    mount(root) {
      (root || document).querySelectorAll('.CodeMirror').forEach((el) => {
        if (el.CodeMirror) createToolbar(el.CodeMirror);
      });
      (root || document).querySelectorAll('textarea.code-editor').forEach((ta) => {
        if (ta.codemirror) createToolbar(ta.codemirror);
      });
    },
  };
})();
