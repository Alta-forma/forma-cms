/**
 * Forma admin – CodeMirror, htmx hooks, meta collapse, hosting health dot.
 */
(function () {
  function mountEditors(root) {
    if (typeof CodeMirror === 'undefined') return;
    (root || document).querySelectorAll('textarea.code-editor').forEach(function (ta) {
      if (ta.codemirror) return;
      var mode = ta.dataset.mode || 'markdown';
      var fill = ta.dataset.cm === 'fill' || ta.classList.contains('htaccess-editor');
      var cm = CodeMirror.fromTextArea(ta, {
        mode: mode,
        theme: 'monokai',
        lineNumbers: true,
        lineWrapping: true,
        tabSize: 2,
        indentWithTabs: false,
        // Expand fully so settings cards grow; only #settings-panel scrolls
        viewportMargin: Infinity
      });
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

  function beforeRequest(evt) {
    var elt = evt.target;
    if (!(elt instanceof HTMLFormElement)) return;
    elt.querySelectorAll('textarea.code-editor').forEach(function (ta) {
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
      function refresh() {
        var titleEl = scope.querySelector('[data-seo-field="title"]');
        var descEl = scope.querySelector('[data-seo-field="desc"]');
        var imgEl = scope.querySelector('[data-seo-field="image"]');
        var title = titleEl ? titleEl.value : '';
        var desc = descEl ? descEl.value : '';
        var img = imgEl ? absImg(imgEl.value) : '';
        var mirror = scope.querySelector('[data-seo-og-mirror]');
        if (mirror && imgEl) mirror.value = imgEl.value;
        var t = box.querySelector('[data-preview="title"]');
        var d = box.querySelector('[data-preview="desc"]');
        var ot = box.querySelector('[data-preview="og-title"]');
        var od = box.querySelector('[data-preview="og-desc"]');
        var im = box.querySelector('[data-preview="image"]');
        if (t) t.textContent = clip(title || 'Page title', 60);
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
          c.textContent = n + ' / ~' + max;
          c.classList.toggle('over', n > max);
        });
      }
      scope.addEventListener('input', function (e) {
        if (e.target && e.target.matches('[data-seo-field], [name="seo_title"], [name="seo_description"], [name="featured_image"], [name="default_og_image"]')) {
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
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      var mode = field.getAttribute('data-media-mode') || 'path';
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
    var toast = document.getElementById('fx-toast');
    if (toast && toast.dataset.show === '1') {
      toast.classList.add('show');
      setTimeout(function () {
        toast.classList.remove('show');
        toast.dataset.show = '0';
      }, 1800);
    }
  }

  function refreshHealthDot() {
    var dot = document.getElementById('health-dot');
    if (!dot) return;
    fetch('actions/hosting-summary.php', { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var fail = data.fail || 0, warn = data.warn || 0;
        if (fail > 0) {
          dot.style.display = 'block';
          dot.style.background = '#f87171';
          dot.classList.add('pulse');
        } else if (warn > 0) {
          dot.style.display = 'block';
          dot.style.background = '#fbbf24';
          dot.classList.add('pulse');
        } else {
          dot.style.display = 'none';
          dot.classList.remove('pulse');
        }
      })
      .catch(function () {});
  }

  document.addEventListener('DOMContentLoaded', function () {
    mountEditors(document);
    wireMetaPanels(document);
    wireSeoPreviews(document);
    wireMediaFields(document);
    refreshHealthDot();
  });
  document.body.addEventListener('htmx:beforeRequest', beforeRequest);
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
