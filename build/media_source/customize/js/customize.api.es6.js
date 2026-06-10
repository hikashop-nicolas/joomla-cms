/**
 * Joomla Customize - public extension API.
 *
 * The default export is the JoomlaCustomize API; the engine and customize plugins import it. It is
 * also assigned to window.JoomlaCustomize as a convenience global (and for debugging).
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
  var bus = new EventTarget();
  var areaTypes = {};
  var buttons = {};
  var uid = 0;

  function options() {
    return (window.Joomla && window.Joomla.getOptions) ? (window.Joomla.getOptions('customize') || {}) : {};
  }

  var JoomlaCustomize = {
    /**
     * Register (or augment) an area type: { label, icon }.
     */
    registerAreaType: function (id, def) {
      areaTypes[id] = Object.assign({ id: id, label: id, icon: '' }, areaTypes[id] || {}, def || {});
      return this;
    },

    /**
     * Register a button for an area type: { id, label, icon, order, onClick(ctx) }.
     */
    registerButton: function (typeId, def) {
      if (!def || !def.id) {
        return this;
      }

      buttons[typeId] = (buttons[typeId] || []).filter(function (b) {
        return b.id !== def.id;
      });
      buttons[typeId].push(Object.assign({ order: 100 }, def));
      buttons[typeId].sort(function (a, b) {
        return a.order - b.order;
      });

      return this;
    },

    getAreaType: function (id) {
      return areaTypes[id] || { id: id, label: id, icon: '' };
    },

    getButtons: function (typeId) {
      return (buttons[typeId] || []).slice();
    },

    /**
     * Call a customize plugin's server handler. Resolves with the parsed handler result.
     */
    callAction: function (plugin, action, payload) {
      var opts = options();
      var url = opts.ajaxBase + '&plugin=' + encodeURIComponent(plugin);
      var body = new FormData();

      body.append('action', action);
      body.append('payload', JSON.stringify(payload || {}));

      if (opts.token) {
        body.append(opts.token, '1');
      }

      return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (response) {
          return response.text();
        })
        .then(function (text) {
          // Be resilient to stray output (PHP notices, BOM) prepended to the JSON body.
          var json = JoomlaCustomize._parseJson(text);
          var data = json && json.data ? json.data : [];
          var first = Array.isArray(data) ? data[0] : data;

          if (typeof first === 'string') {
            first = JoomlaCustomize._parseJson(first) || first;
          }

          return first;
        });
    },

    /**
     * Parse a JSON document that may be preceded/followed by stray output.
     */
    _parseJson: function (text) {
      if (typeof text !== 'string') {
        return null;
      }

      try {
        return JSON.parse(text);
      } catch (e) {
        var start = text.indexOf('{');
        var end = text.lastIndexOf('}');

        if (start !== -1 && end > start) {
          try {
            return JSON.parse(text.slice(start, end + 1));
          } catch (e2) {
            return null;
          }
        }

        return null;
      }
    },

    /**
     * Replace an element's content with an in-place WYSIWYG (TinyMCE) editor, and restore the
     * rendered content on save. opts: { html, save(content) -> Promise<{ success, html? }> }.
     */
    editInline: function (ctx, opts) {
      var doc = ctx.doc;
      var el = ctx.el;

      if (el.getAttribute('data-customize-editing') === '1') {
        return;
      }

      el.setAttribute('data-customize-editing', '1');
      JoomlaCustomize.emit('customize:edit-start');

      var original = el.innerHTML;
      var startHtml = (opts && typeof opts.html === 'string') ? opts.html : original;

      var ta = doc.createElement('textarea');
      ta.id = 'customize-editor-' + (++uid);
      ta.className = 'customize-editor';
      ta.value = startHtml;
      el.innerHTML = '';
      el.appendChild(ta);

      var bar = JoomlaCustomize.ui.makeBar(doc);
      el.parentNode.insertBefore(bar.el, el.nextSibling);

      var editor = null;

      function teardown() {
        try {
          if (editor) { editor.remove(); }
        } catch (e) {}

        el.removeAttribute('data-customize-editing');
        JoomlaCustomize.emit('customize:edit-end');

        if (bar.el.parentNode) {
          bar.el.parentNode.removeChild(bar.el);
        }
      }

      this._loadScript(doc, options().tinymceSrc).then(function (tinymce) {
        if (!tinymce) {
          return;
        }

        tinymce.init({
          target: ta,
          license_key: 'gpl',
          promotion: false,
          menubar: false,
          statusbar: false,
          plugins: 'lists link autolink',
          toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
          height: 240
        }).then(function (eds) {
          editor = eds && eds[0];
        });
      });

      bar.cancel.addEventListener('click', function () {
        teardown();
        el.innerHTML = original;
      });

      bar.save.addEventListener('click', function () {
        if (!editor) {
          return;
        }

        JoomlaCustomize.ui.saving(bar.save);
        var content = editor.getContent();

        Promise.resolve(opts.save(content)).then(function (res) {
          if (res && res.success) {
            teardown();
            el.innerHTML = (res.html != null) ? res.html : content;
            JoomlaCustomize.emit('customize:dom-updated', { el: el });
          } else {
            JoomlaCustomize.ui.resetSave(bar.save);
          }
        }).catch(function () {
          JoomlaCustomize.ui.resetSave(bar.save);
        });
      });
    },

    /**
     * Load a script into a (same-origin) document once, resolving with its global hook.
     */
    _loadScript: function (doc, src) {
      return new Promise(function (resolve) {
        if (!src) {
          resolve(null);
          return;
        }

        var win = doc.defaultView;

        if (win.tinymce) {
          resolve(win.tinymce);
          return;
        }

        var existing = doc.getElementById('customize-tinymce');

        if (existing) {
          existing.addEventListener('load', function () { resolve(win.tinymce || null); });
          return;
        }

        var s = doc.createElement('script');
        s.id = 'customize-tinymce';
        s.src = src;
        s.onload = function () { resolve(win.tinymce || null); };
        s.onerror = function () { resolve(null); };
        (doc.head || doc.documentElement).appendChild(s);
      });
    },

    /**
     * Small shared UI helpers for customize plugins (rendered inside the iframe document).
     */
    ui: {
      toast: function (doc, message) {
        var note = doc.createElement('div');
        note.className = 'customize-toast';
        note.textContent = message;
        doc.body.appendChild(note);
        doc.defaultView.setTimeout(function () {
          if (note.parentNode) {
            note.parentNode.removeChild(note);
          }
        }, 2500);
      },

      label: function (doc, text) {
        var l = doc.createElement('div');
        l.className = 'customize-field-label';
        l.textContent = text;
        return l;
      },

      makeBar: function (doc) {
        var el = doc.createElement('div');
        el.className = 'customize-inline-bar';

        var save = doc.createElement('button');
        save.type = 'button';
        save.className = 'customize-action customize-action-save';
        save.textContent = JoomlaCustomize.text('COM_MENUS_CUSTOMIZE_SAVE', 'Save');

        var cancel = doc.createElement('button');
        cancel.type = 'button';
        cancel.className = 'customize-action customize-action-cancel';
        cancel.textContent = JoomlaCustomize.text('COM_MENUS_CUSTOMIZE_CANCEL', 'Cancel');

        el.appendChild(save);
        el.appendChild(cancel);

        return { el: el, save: save, cancel: cancel };
      },

      saving: function (button) {
        button.disabled = true;
        button.textContent = JoomlaCustomize.text('COM_MENUS_CUSTOMIZE_SAVING', 'Saving…');
      },

      resetSave: function (button) {
        button.disabled = false;
        button.textContent = JoomlaCustomize.text('COM_MENUS_CUSTOMIZE_SAVE', 'Save');
      }
    },

    /**
     * Translate a key via Joomla.Text, falling back to the given default when not registered.
     */
    text: function (key, fallback) {
      if (window.Joomla && window.Joomla.Text && typeof window.Joomla.Text._ === 'function') {
        var value = window.Joomla.Text._(key);

        if (value && value !== key) {
          return value;
        }
      }

      return (fallback != null) ? fallback : key;
    },

    /**
     * The admin host's side panel element (in the parent document), for plugins that add controls
     * not tied to an on-page element (e.g. "Add module").
     */
    panel: function () {
      return window.document.querySelector('.customize-panel');
    },

    on: function (name, callback) {
      bus.addEventListener(name, callback);
      return this;
    },

    emit: function (name, detail) {
      bus.dispatchEvent(new CustomEvent(name, { detail: detail }));
      return this;
    }
  };

  window.JoomlaCustomize = JoomlaCustomize;

export default JoomlaCustomize;
