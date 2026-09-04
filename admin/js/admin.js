/**
 * Forma admin – CodeMirror, htmx hooks, meta collapse, hosting health dot.
 */
(function () {
  var RING = 2 * Math.PI * 15.5; // r=15.5 in 36×36 viewBox

  function layoutAlerts() {
    var el = document.getElementById('fx-admin-alerts');
    var h = el ? el.offsetHeight : 0;
    document.body.classList.toggle('has-fx-alerts', h > 0);
    document.documentElement.style.setProperty('--fx-alert-h', h + 'px');
  }

  function csrfToken() {
    var m = document.body && document.body.getAttribute('hx-headers');
    if (!m) return '';
    try { return JSON.parse(m)['X-CSRF-Token'] || ''; } catch (e) { return ''; }
  }

  function toastHost() {
    var el = document.getElementById('fx-upload-toasts');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'fx-upload-toasts';
    el.className = 'fx-upload-toasts';
    el.setAttribute('aria-live', 'polite');
    document.body.appendChild(el);
    return el;
  }

  function setRing(svg, pct, spinning) {
    var fill = svg.querySelector('.fill');
    if (!fill) return;
    svg.classList.toggle('is-spin', !!spinning);
    var p = Math.max(0, Math.min(1, pct || 0));
    fill.setAttribute('stroke-dashoffset', String(RING * (1 - p)));
  }

  function ringSvg() {
    return '<svg class="fx-upload-ring" viewBox="0 0 36 36" aria-hidden="true">' +
      '<circle class="track" cx="18" cy="18" r="15.5" fill="none"/>' +
      '<circle class="fill" cx="18" cy="18" r="15.5" fill="none" stroke-dasharray="' + RING.toFixed(2) + '" stroke-dashoffset="' + RING.toFixed(2) + '"/>' +
      '</svg>';
  }

  function iconClass(ext) {
    ext = String(ext || '').toLowerCase();
    if (/^(jpe?g|png|gif|webp|svg|ico|avif)$/.test(ext)) return 'fa-file-image';
    if (/^(mp3|m4a|wav|ogg)$/.test(ext)) return 'fa-file-audio';
    if (/^(mp4|webm|mov|avi)$/.test(ext)) return 'fa-file-video';
    if (ext === 'pdf') return 'fa-file-pdf';
    if (/^(js|css|json|xml|html|htm)$/.test(ext)) return 'fa-file-code';
    if (/^(md|txt|csv|rtf)$/.test(ext)) return 'fa-file-alt';
    return 'fa-file';
  }

  function attrUrl(url) {
    return String(url || '').replace(/\\/g, '/').replace(/'/g, '%27');
  }

  function bindProgress(root) {
    var svg = root.querySelector('.fx-upload-ring');
    var status = root.querySelector('.fx-upload-toast-status, .upload-ghost-pct');
    return {
      el: root,
      svg: svg,
      status: status,
      queued: function () {
        if (this.svg) this.svg.classList.remove('is-spin');
        setRing(this.svg, 0, false);
        if (this.status) this.status.textContent = 'Waiting…';
      },
      progress: function (loaded, total) {
        if (total > 0) {
          var p = loaded / total;
          setRing(this.svg, p, false);
          if (this.status) this.status.textContent = Math.round(p * 100) + '%';
        } else {
          setRing(this.svg, 0.15, true);
          if (this.status) this.status.textContent = 'Uploading…';
        }
      },
      waiting: function () {
        if (this.svg) this.svg.classList.remove('is-spin');
        setRing(this.svg, 1, false);
        if (this.status) this.status.textContent = 'Processing…';
      },
      done: function (msg) {
        if (this.svg) {
          this.svg.classList.remove('is-spin');
          this.svg.classList.add('is-done');
        }
        setRing(this.svg, 1, false);
        if (this.status) this.status.textContent = msg || 'Uploaded';
        if (!root.classList.contains('fx-upload-toast')) return;
        setTimeout(function () {
          root.classList.add('is-out');
          setTimeout(function () { root.remove(); }, 280);
        }, 1400);
      },
      fail: function (msg) {
        if (this.svg) {
          this.svg.classList.remove('is-spin');
          this.svg.classList.add('is-fail');
        }
        setRing(this.svg, 1, false);
        if (this.status) this.status.textContent = msg || 'Failed';
        root.classList.add('is-fail');
      }
    };
  }

  function makeToast(fileName) {
    var row = document.createElement('div');
    row.className = 'fx-upload-toast';
    row.innerHTML = ringSvg() +
      '<div class="fx-upload-toast-body">' +
      '<strong class="fx-upload-toast-name"></strong>' +
      '<span class="fx-upload-toast-status">Starting…</span>' +
      '</div>';
    row.querySelector('.fx-upload-toast-name').textContent = fileName || 'Upload';
    toastHost().appendChild(row);
    var ui = bindProgress(row);
    ui.queued();
    return ui;
  }

  function makeListGhost(file, parent) {
    var list = parent || document.getElementById('uploads-list');
    if (!list) return null;
    var row = document.createElement('div');
    row.className = 'file-item upload-item is-ghost';
    row.setAttribute('aria-busy', 'true');
    row.title = file.name || 'Upload';
    var isImg = /\.(png|jpe?g|gif|webp|avif)$/i.test(file.name || '');
    var blobUrl = '';
    if (isImg) {
      try { blobUrl = URL.createObjectURL(file); } catch (e) { blobUrl = ''; }
    }
    var thumb = blobUrl
      ? '<span class="upload-thumb is-ghost-thumb" style="background-image:url(\'' + attrUrl(blobUrl) + '\')"></span>'
      : '';
    row.innerHTML = '<span class="upload-ghost-icon">' + ringSvg() + thumb + '</span>' +
      '<span class="upload-ghost-text"><span class="upload-name"></span><span class="upload-ghost-pct">Waiting…</span></span>';
    row.querySelector('.upload-name').textContent = file.name || 'Upload';
    row._fxBlob = blobUrl;
    list.appendChild(row);
    var ui = bindProgress(row);
    ui.queued();
    ui.promote = function (data) {
      var filename = (data && (data.filename || (data.files && data.files[0] && data.files[0].filename))) || '';
      var url = (data && data.url) || (data && data.path) || '';
      if (!url || url.indexOf('/admin/') !== -1) {
        url = filename ? '/uploads/' + encodeURIComponent(filename) : '';
      }
      if (row._fxBlob) {
        try { URL.revokeObjectURL(row._fxBlob); } catch (e) {}
        row._fxBlob = '';
      }
      var name = filename || file.name || 'file';
      var ext = (name.split('.').pop() || '').toLowerCase();
      var showThumb = /^(jpe?g|png|gif|webp|svg|ico)$/i.test(ext);
      row.className = 'file-item upload-item';
      row.removeAttribute('aria-busy');
      row.title = name;
      row.innerHTML = (showThumb && url
        ? '<span class="upload-thumb' + (ext === 'svg' ? ' is-svg' : '') + '"><img src="' + attrUrl(url) + '" alt="" onerror="this.parentNode.classList.add(\'is-missing\');this.remove()"></span>'
        : '<i class="fas ' + iconClass(ext) + '"></i>') +
        '<span class="upload-name"></span>';
      row.querySelector('.upload-name').textContent = name;
      row.setAttribute('hx-get', 'partials/uploads.php?file=' + encodeURIComponent(name));
      row.setAttribute('hx-target', '#main');
      row.setAttribute('hx-swap', 'innerHTML');
      row.setAttribute('hx-push-url', 'index.php?section=uploads&file=' + encodeURIComponent(name));
      if (window.htmx) window.htmx.process(row);
    };
    var origFail = ui.fail.bind(ui);
    ui.fail = function (msg) {
      origFail(msg);
      row.classList.add('is-ghost-fail');
      row.removeAttribute('aria-busy');
      row.title = (msg || 'Failed') + ' — click to dismiss';
      row.addEventListener('click', function () {
        if (row._fxBlob) {
          try { URL.revokeObjectURL(row._fxBlob); } catch (e) {}
        }
        row.remove();
      }, { once: true });
    };
    return ui;
  }

  function parseUploadResponse(xhr) {
    var text = xhr.responseText || '';
    try {
      return JSON.parse(text);
    } catch (e) {
      return { success: false, error: text ? 'Unexpected response' : (xhr.statusText || 'Upload failed') };
    }
  }

  function uploadOne(file, opts) {
    opts = opts || {};
    var ui = opts.ui || makeToast(file.name);
    ui.progress(0, file.size || 0);
    return new Promise(function (resolve) {
      var xhr = new XMLHttpRequest();
      var fd = new FormData();
      fd.append(opts.field || 'file', file);
      if (opts.kind) fd.append('kind', opts.kind);
      fd.append('csrf_token', csrfToken());
      xhr.open('POST', opts.url || 'actions/uploads-save.php');
      xhr.setRequestHeader('X-CSRF-Token', csrfToken());
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Forma-Upload', '1');
      xhr.upload.addEventListener('progress', function (e) {
        ui.progress(e.loaded, e.lengthComputable ? e.total : 0);
      });
      xhr.addEventListener('loadstart', function () {
        if (file.size === 0) ui.progress(0, 0);
      });
      xhr.addEventListener('load', function () {
        ui.waiting();
        var data = parseUploadResponse(xhr);
        if (xhr.status >= 200 && xhr.status < 300 && data && data.success) {
          if (typeof ui.promote === 'function') ui.promote(data);
          else ui.done('Uploaded');
          resolve({ ok: true, data: data, file: file });
        } else {
          var err = (data && (data.error || data.message)) || ('HTTP ' + xhr.status);
          ui.fail(err);
          resolve({ ok: false, error: err, file: file });
        }
      });
      xhr.addEventListener('error', function () {
        ui.fail('Network error');
        resolve({ ok: false, error: 'Network error', file: file });
      });
      xhr.addEventListener('abort', function () {
        ui.fail('Cancelled');
        resolve({ ok: false, error: 'Cancelled', file: file });
      });
      xhr.send(fd);
    });
  }

  function uploadFiles(fileList, opts) {
    opts = opts || {};
    var files = Array.prototype.slice.call(fileList || []).filter(Boolean);
    if (!files.length) return Promise.resolve([]);
    var list = document.getElementById('uploads-list');
    var useList = opts.list !== false && !!list;
    var frag = useList ? document.createDocumentFragment() : null;
    var uis = files.map(function (file) {
      return useList ? makeListGhost(file, frag) : makeToast(file.name);
    });
    if (frag && list) {
      var empty = list.querySelector('.hint');
      if (empty) empty.remove();
      list.insertBefore(frag, list.firstChild);
    }
    var chain = Promise.resolve([]);
    files.forEach(function (file, i) {
      chain = chain.then(function (acc) {
        return uploadOne(file, Object.assign({}, opts, { ui: uis[i] })).then(function (res) {
          acc.push(res);
          if (typeof opts.onEach === 'function') opts.onEach(res);
          return acc;
        });
      });
    });
    return chain.then(function (results) {
      if (typeof opts.onAll === 'function') opts.onAll(results);
      return results;
    });
  }

  function queueFromForm(form, fileList) {
    return uploadFiles(fileList, {
      url: 'actions/uploads-save.php',
      field: 'file',
      list: true
    });
  }

  function fromDropzone(input) {
    if (!input || input._fxHandled) return;
    var files = input.files;
    if (!files || !files.length) return;
    input._fxHandled = true;
    var list = Array.prototype.slice.call(files);
    var form = input.closest('#upload-form') || input.form || input.closest('form');
    input.value = '';
    setTimeout(function () { input._fxHandled = false; }, 0);
    queueFromForm(form, list);
  }

  window.FormaUploads = {
    uploadFiles: uploadFiles,
    uploadOne: uploadOne,
    makeToast: makeToast,
    fromDropzone: fromDropzone,
  };

  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input || input.type !== 'file') return;
    if (input.hasAttribute('data-media-file')) return;
    if (input.id !== 'upload-input' && !input.closest('[data-fx-uploads]')) return;
    fromDropzone(input);
  });

  document.addEventListener('drop', function (e) {
    var form = e.target && e.target.closest ? e.target.closest('#upload-form') : null;
    if (!form) return;
    e.preventDefault();
    form.classList.remove('dz-drag-hover');
    var dt = e.dataTransfer;
    if (!dt || !dt.files || !dt.files.length) return;
    queueFromForm(form, dt.files);
  });
})();

(function () {
  function mountEditors(root) {
    if (typeof CodeMirror === 'undefined') return;
    (root || document).querySelectorAll('textarea.code-editor').forEach(function (ta) {
      if (ta.codemirror) return;
      var mode = ta.dataset.mode || 'markdown';
      var fill = ta.dataset.cm === 'fill' || ta.classList.contains('htaccess-editor');
      var cm;
      try {
        cm = CodeMirror.fromTextArea(ta, {
          mode: mode === 'null' ? null : mode,
          theme: 'monokai',
          lineNumbers: true,
          lineWrapping: true,
          tabSize: 2,
          indentWithTabs: false,
          viewportMargin: Infinity
        });
      } catch (err) {
        console.error('Forma: CodeMirror failed', err);
        return;
      }
      ta.codemirror = cm;
      cm.on('change', function () { cm.save(); });
      if (fill) {
        var wrap = ta.closest('.htaccess-editor-wrap');
        if (wrap) {
          cm.setSize('100%', 'auto');
          requestAnimationFrame(function () { cm.refresh(); });
        }
      }
    });
    if (window.FormaToolbar) window.FormaToolbar.mount(root || document);
  }

  function wireMetaPanels(root) {
    (root || document).querySelectorAll('.meta-panel-toggle').forEach(function (btn) {
      if (btn.dataset.wired) return;
      btn.dataset.wired = '1';
      btn.addEventListener('click', function () {
        var panel = btn.closest('.meta-panel');
        if (!panel) return;
        panel.classList.toggle('collapsed');
        var open = !panel.classList.contains('collapsed');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        try { localStorage.setItem('fx-meta-collapsed', open ? '0' : '1'); } catch (e) {}
      });
    });
    // Restore collapse preference (default collapsed for blog/podcast noise)
    try {
      if (localStorage.getItem('fx-meta-collapsed') === '1') {
        (root || document).querySelectorAll('.meta-panel').forEach(function (p) {
          p.classList.add('collapsed');
          var t = p.querySelector('.meta-panel-toggle');
          if (t) t.setAttribute('aria-expanded', 'false');
        });
      }
    } catch (e) {}
  }

  function closeBlogPreview() {
    var el = document.getElementById('fx-preview-backdrop');
    if (!el) return;
    var iframe = el.querySelector('iframe');
    var url = iframe && iframe.dataset.blob;
    if (url) {
      try { URL.revokeObjectURL(url); } catch (e) {}
    }
    el.remove();
    document.removeEventListener('keydown', previewEsc, true);
  }

  function previewEsc(e) {
    if (e.key === 'Escape') closeBlogPreview();
  }

  function setPreviewWidth(shell, width) {
    shell.style.setProperty('--preview-w', width);
    shell.querySelectorAll('[data-preview-size]').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-preview-size') === width);
    });
  }

  function openBlogPreview() {
    var form = document.getElementById('blog-form');
    if (!form) return;
    form.querySelectorAll('textarea.code-editor').forEach(function (ta) {
      if (ta.codemirror) ta.codemirror.save();
    });
    var fd = new FormData(form);
    fetch('actions/blog-preview.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrfToken(), 'HX-Request': 'false' }
    }).then(function (r) {
      if (!r.ok) throw new Error('Preview failed');
      return r.text();
    }).then(function (html) {
      closeBlogPreview();
      var blob = new Blob([html], { type: 'text/html' });
      var url = URL.createObjectURL(blob);
      var backdrop = document.createElement('div');
      backdrop.id = 'fx-preview-backdrop';
      backdrop.className = 'fx-preview-backdrop';
      backdrop.innerHTML =
        '<div class="fx-preview-shell" role="dialog" aria-label="Post preview">' +
          '<div class="fx-preview-toolbar">' +
            '<strong>Preview</strong>' +
            '<div class="fx-preview-sizes">' +
              '<button type="button" data-preview-size="390px" title="Phone"><i class="fas fa-mobile-alt"></i></button>' +
              '<button type="button" data-preview-size="768px" title="Tablet"><i class="fas fa-tablet-alt"></i></button>' +
              '<button type="button" data-preview-size="100%" title="Desktop" class="is-active"><i class="fas fa-desktop"></i></button>' +
            '</div>' +
            '<button type="button" class="standard-btn fx-preview-close" data-preview-close>' +
              '<i class="small fas fa-xmark"></i> Close</button>' +
          '</div>' +
          '<div class="fx-preview-viewport">' +
            '<iframe class="fx-preview-iframe" title="Post preview"></iframe>' +
          '</div>' +
        '</div>';
      document.body.appendChild(backdrop);
      var iframe = backdrop.querySelector('iframe');
      iframe.dataset.blob = url;
      iframe.src = url;
      var shell = backdrop.querySelector('.fx-preview-shell');
      setPreviewWidth(shell, '100%');
      backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeBlogPreview();
      });
      backdrop.addEventListener('click', function (e) {
        var close = e.target.closest('[data-preview-close]');
        if (close) closeBlogPreview();
        var size = e.target.closest('[data-preview-size]');
        if (size) setPreviewWidth(shell, size.getAttribute('data-preview-size'));
      });
      document.addEventListener('keydown', previewEsc, true);
    }).catch(function () {
      var t = document.getElementById('fx-toast');
      if (t) {
        t.textContent = 'Preview failed';
        t.classList.add('show');
        t.dataset.show = '1';
        setTimeout(function () { t.classList.remove('show'); t.dataset.show = '0'; }, 1800);
      }
    });
  }

  function beforeRequest(evt) {
    var elt = (evt.detail && evt.detail.elt) || evt.target;
    var form = null;
    if (elt instanceof HTMLFormElement) {
      form = elt;
    } else if (elt && elt.form) {
      form = elt.form;
    } else if (elt && elt.getAttribute) {
      var formId = elt.getAttribute('form');
      if (formId) form = document.getElementById(formId);
    }
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelectorAll('textarea.code-editor').forEach(function (ta) {
      if (ta.codemirror) ta.codemirror.save();
    });
  }

  function wireSeoPreviews(root) {
    (root || document).querySelectorAll('[data-seo-preview]').forEach(function (box) {
      if (box.dataset.wiredPreview) return;
      box.dataset.wiredPreview = '1';
      var scope = box.closest('form') || box.parentElement || document;
      function clip(s, n) {
        s = (s || '').trim();
        if (s.length <= n) return s;
        return s.slice(0, n - 1) + '…';
      }
      function absImg(v) {
        v = (v || '').trim();
        if (!v) return '';
        if (/^https?:\/\//i.test(v)) return v;
        if (v.charAt(0) !== '/') v = '/' + v;
        return v;
      }
      var suffixOn = box.getAttribute('data-title-suffix') === '1';
      var titleSep = box.getAttribute('data-title-sep') || ' — ';
      var siteTitle = box.getAttribute('data-site-title') || '';
      function withSuffix(t) {
        t = (t || '').trim();
        if (!t) return t;
        if (suffixOn && siteTitle && t.indexOf(siteTitle) === -1) return t + titleSep + siteTitle;
        return t;
      }
      function rawTitle() {
        var titleEl = scope.querySelector('[data-seo-field="title"]');
        var t = titleEl ? titleEl.value : '';
        if (!t) {
          var fb = scope.querySelector('[name="title"]');
          if (fb) t = fb.value || '';
        }
        return t;
      }
      function refresh() {
        var descEl = scope.querySelector('[data-seo-field="desc"]');
        var imgEl = scope.querySelector('[data-seo-field="image"]');
        var title = rawTitle();
        var desc = descEl ? descEl.value : '';
        var img = imgEl ? absImg(imgEl.value) : '';
        var mirror = scope.querySelector('[data-seo-og-mirror]');
        if (mirror && imgEl) mirror.value = imgEl.value;
        var t = box.querySelector('[data-preview="title"]');
        var d = box.querySelector('[data-preview="desc"]');
        var ot = box.querySelector('[data-preview="og-title"]');
        var od = box.querySelector('[data-preview="og-desc"]');
        var im = box.querySelector('[data-preview="image"]');
        var rendered = withSuffix(title || 'Page title');
        if (t) t.textContent = clip(rendered, 60);
        if (d) d.textContent = clip(desc || 'Meta description will appear here.', 160);
        if (ot) ot.textContent = title || 'Page title';
        if (od) od.textContent = clip(desc, 120);
        if (im) {
          if (img) {
            im.style.backgroundImage = 'url(' + img + ')';
            im.innerHTML = '';
          } else {
            im.style.backgroundImage = '';
            im.innerHTML = '<span>No image</span>';
          }
        }
        scope.querySelectorAll('[data-count-for]').forEach(function (c) {
          var name = c.getAttribute('data-count-for');
          var field = scope.querySelector('[name="' + name + '"]');
          if (!field) return;
          var max = name.indexOf('title') !== -1 ? 60 : 160;
          var n = (field.value || '').length;
          if (name === 'seo_title') n = withSuffix(rawTitle()).length;
          c.textContent = n + ' / ~' + max;
          c.classList.toggle('over', n > max);
        });
      }
      scope.addEventListener('input', function (e) {
        if (e.target && e.target.matches('[data-seo-field], [name="seo_title"], [name="seo_description"], [name="featured_image"], [name="default_og_image"], [name="title"]')) {
          refresh();
        }
      });
      refresh();
    });
  }

  function csrfToken() {
    var m = document.body && document.body.getAttribute('hx-headers');
    if (!m) return '';
    try { return JSON.parse(m)['X-CSRF-Token'] || ''; } catch (e) { return ''; }
  }

  function formatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function absUploadUrl(path) {
    path = (path || '').trim();
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    if (path.indexOf('/uploads/') === 0 || path.indexOf('uploads/') === 0) {
      return '/' + path.replace(/^\/+/, '');
    }
    return path;
  }

  function setMediaValue(field, path) {
    var input = field.querySelector('[data-media-input]');
    if (!input) return;
    input.value = path || '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    var form = input.closest('form');
    if (form && input.name === 'featured_image') {
      var mirror = form.querySelector('[data-seo-og-mirror]');
      if (mirror) mirror.value = input.value;
    }
    refreshMediaThumb(field);
  }

  function refreshMediaThumb(field) {
    var thumb = field.querySelector('[data-media-thumb]');
    var input = field.querySelector('[data-media-input]');
    if (!thumb || !input) return;
    var accept = field.getAttribute('data-media-accept') || 'image';
    if (accept !== 'image') {
      thumb.style.display = 'none';
      return;
    }
    var v = (input.value || '').trim();
    var url = absUploadUrl(v);
    if (url && (/\.(png|jpe?g|gif|webp|svg|ico)(\?|$)/i.test(url) || url.indexOf('/uploads/') !== -1)) {
      thumb.classList.add('has-img');
      thumb.classList.remove('is-empty');
      thumb.style.backgroundImage = 'url("' + url.replace(/"/g, '\\"') + '")';
      thumb.innerHTML = '';
    } else if (v) {
      thumb.classList.add('has-img', 'is-empty');
      thumb.style.backgroundImage = '';
      thumb.innerHTML = '<i class="fas fa-file"></i>';
    } else {
      thumb.classList.remove('has-img', 'is-empty');
      thumb.style.backgroundImage = '';
      thumb.innerHTML = '';
    }
  }

  function openMediaPicker(field) {
    var accept = field.getAttribute('data-media-accept') || 'image';
    var mode = field.getAttribute('data-media-mode') || 'path';
    var backdrop = document.createElement('div');
    backdrop.className = 'fx-modal-backdrop fx-media-modal';
    backdrop.innerHTML =
      '<div class="fx-modal" role="dialog" aria-label="Choose media">' +
      '<div class="fx-modal-header" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:.25rem">' +
      '<strong>Choose from Uploads</strong>' +
      '<button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>' +
      '<div class="fx-media-modal-toolbar">' +
      '<input type="search" placeholder="Filter files…" data-filter>' +
      '<button type="button" class="fx-media-btn primary" data-upload-here><i class="fas fa-upload"></i><span>Upload new</span></button>' +
      '</div>' +
      '<div class="fx-modal-body"><div class="fx-media-empty">Loading…</div></div>' +
      '<div class="fx-modal-actions"><button type="button" class="standard-btn" data-close>Cancel</button></div>' +
      '</div>';
    document.body.appendChild(backdrop);

    function close() { backdrop.remove(); }
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop || e.target.closest('[data-close]')) close();
    });

    var body = backdrop.querySelector('.fx-modal-body');
    var filter = backdrop.querySelector('[data-filter]');
    var files = [];

    function valueFor(file) {
      return mode === 'basename' ? file.filename : file.path;
    }

    function render() {
      var q = (filter.value || '').trim().toLowerCase();
      var list = files.filter(function (f) {
        return !q || (f.filename || '').toLowerCase().indexOf(q) !== -1;
      });
      if (!list.length) {
        body.innerHTML = '<div class="fx-media-empty">' +
          (files.length ? 'No matches.' : 'No uploads yet — use Upload new.') + '</div>';
        return;
      }
      var grid = document.createElement('div');
      grid.className = 'fx-media-grid';
      list.forEach(function (f) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fx-media-item';
        var thumbHtml = f.is_image
          ? '<div class="thumb" style="background-image:url(\'' + String(f.url).replace(/'/g, '%27') + '\')"></div>'
          : '<div class="thumb"><i class="fas ' + (f.icon || 'fa-file') + '"></i></div>';
        btn.innerHTML = thumbHtml +
          '<div class="meta"><strong title="' + f.filename.replace(/"/g, '&quot;') + '">' + f.filename + '</strong>' +
          '<span>' + formatBytes(f.size) + '</span></div>';
        btn.addEventListener('click', function () {
          setMediaValue(field, valueFor(f));
          close();
        });
        grid.appendChild(btn);
      });
      body.innerHTML = '';
      body.appendChild(grid);
    }

    filter.addEventListener('input', render);

    fetch('actions/media-picker.php?type=' + encodeURIComponent(accept), {
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) throw new Error(data.error || 'Failed to load');
        files = data.files || [];
        render();
      })
      .catch(function (err) {
        body.innerHTML = '<div class="fx-media-empty" style="color:#f87171">' + (err.message || 'Error') + '</div>';
      });

    backdrop.querySelector('[data-upload-here]').addEventListener('click', function () {
      triggerMediaUpload(field, function () { close(); });
    });
  }

  function triggerMediaUpload(field, onDone) {
    var fileInput = field.querySelector('[data-media-file]');
    if (!fileInput) return;
    fileInput.value = '';
    fileInput.onchange = function () {
      var files = fileInput.files;
      if (!files || !files.length) return;
      var mode = field.getAttribute('data-media-mode') || 'path';
      var api = window.FormaUploads;
      var finish = function (res) {
        if (!res || !res.ok || !res.data) return;
        var val = mode === 'basename' ? res.data.filename : (res.data.path || res.data.url);
        setMediaValue(field, val);
        if (typeof onDone === 'function') onDone(res.data);
      };
      if (api && api.uploadFiles) {
        api.uploadFiles(files, {
          url: 'actions/media-picker.php',
          field: 'file',
          list: false,
          onEach: finish
        });
        return;
      }
      var file = files[0];
      var fd = new FormData();
      fd.append('file', file);
      fetch('actions/media-picker.php', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
        body: fd
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (!res.ok || !res.j.success) throw new Error((res.j && res.j.error) || 'Upload failed');
          var val = mode === 'basename' ? res.j.filename : res.j.path;
          setMediaValue(field, val);
          var toast = document.getElementById('fx-toast');
          if (toast) {
            toast.textContent = 'Uploaded';
            toast.dataset.show = '1';
            toast.classList.add('show');
            setTimeout(function () { toast.classList.remove('show'); toast.dataset.show = '0'; }, 1600);
          }
          if (typeof onDone === 'function') onDone(res.j);
        })
        .catch(function (err) {
          alert(err.message || 'Upload failed');
        });
    };
    fileInput.click();
  }

  function wireMediaFields(root) {
    (root || document).querySelectorAll('.fx-media-field').forEach(function (field) {
      if (field.dataset.wiredMedia) {
        refreshMediaThumb(field);
        return;
      }
      field.dataset.wiredMedia = '1';
      var browse = field.querySelector('[data-media-browse]');
      var upload = field.querySelector('[data-media-upload]');
      var input = field.querySelector('[data-media-input]');
      if (browse) browse.addEventListener('click', function () { openMediaPicker(field); });
      if (upload) upload.addEventListener('click', function () { triggerMediaUpload(field); });
      if (input) input.addEventListener('input', function () { refreshMediaThumb(field); });
      refreshMediaThumb(field);
    });
  }

  function afterSwap(evt) {
    mountEditors(evt.detail.elt);
    wireMetaPanels(evt.detail.elt);
    wireSeoPreviews(evt.detail.elt);
    wireMediaFields(evt.detail.elt);
    layoutAlerts();
    var toast = document.getElementById('fx-toast');
    if (toast && toast.dataset.show === '1') {
      toast.classList.add('show');
      setTimeout(function () {
        toast.classList.remove('show');
        toast.dataset.show = '0';
      }, 1800);
    }
  }

  document.body.addEventListener('click', function (e) {
    if (e.target.closest('[data-blog-preview]')) {
      e.preventDefault();
      openBlogPreview();
      return;
    }
    var trigger = e.target.closest('[data-file-trigger]');
    if (trigger) {
      var wrap = trigger.closest('.fx-file-pick');
      var input = wrap && wrap.querySelector('input[type="file"]');
      if (input) input.click();
    }
  });
  document.body.addEventListener('change', function (e) {
    var input = e.target;
    if (!input || !input.matches || !input.matches('.fx-file-pick input[type="file"]')) return;
    var name = input.closest('.fx-file-pick').querySelector('[data-file-name]');
    if (!name) return;
    var file = input.files && input.files[0];
    name.textContent = file ? file.name : 'No file chosen';
    name.classList.toggle('is-set', !!file);
  });
  document.addEventListener('DOMContentLoaded', function () {
    mountEditors(document);
    wireMetaPanels(document);
    wireSeoPreviews(document);
    wireMediaFields(document);
    layoutAlerts();
  });
  document.body.addEventListener('htmx:beforeRequest', function (evt) {
    beforeRequest(evt);
    var src = evt.detail && evt.detail.elt;
    if (!src || src.tagName !== 'FORM') return;
    if (src.id === 'upload-form') return;
    var enc = (src.getAttribute('hx-encoding') || src.enctype || '').toLowerCase();
    if (enc.indexOf('multipart') === -1) return;
    var input = src.querySelector('input[type="file"]');
    var file = input && input.files && input.files[0];
    if (!file) return;
    if (!window.FormaUploads || !window.FormaUploads.makeToast) return;
    var toast = window.FormaUploads.makeToast(file ? file.name : 'Upload');
    toast.progress(0, file ? file.size : 0);
    src._fxHxToast = toast;
  });
  document.body.addEventListener('htmx:xhr:progress', function (evt) {
    var elt = evt.detail && evt.detail.elt;
    var toast = elt && elt._fxHxToast;
    if (!toast || !evt.detail) return;
    toast.progress(evt.detail.loaded || 0, evt.detail.total || 0);
  });
  document.body.addEventListener('htmx:afterRequest', function (evt) {
    var elt = evt.detail && evt.detail.elt;
    var toast = elt && elt._fxHxToast;
    if (!toast) return;
    var xhr = evt.detail.xhr;
    var ok = xhr && xhr.status >= 200 && xhr.status < 300 && !(evt.detail.failed);
    if (ok) toast.done('Uploaded');
    else toast.fail('Failed');
    elt._fxHxToast = null;
  });
  document.body.addEventListener('htmx:afterSwap', afterSwap);
  document.body.addEventListener('htmx:load', function (evt) {
    mountEditors(evt.detail.elt);
    wireMetaPanels(evt.detail.elt);
    wireSeoPreviews(evt.detail.elt);
    wireMediaFields(evt.detail.elt);
  });

  window.FormaEditor = { mount: mountEditors };
  window.FormaMedia = { wire: wireMediaFields, open: openMediaPicker };
})();
