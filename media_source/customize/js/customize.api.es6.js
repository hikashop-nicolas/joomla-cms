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
  var transients = [];
  // The signed customize token carried into the iframe; refreshed in place for long sessions.
  var frameToken = null;

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

          // A non-JSON body (login redirect) or a request-level rejection means the admin session
          // expired; the plugin also flags its own token rejection with authExpired.
          if (json === null || json.success === false) {
            return JoomlaCustomize._sessionExpired();
          }

          var data = json.data ? json.data : [];
          var first = Array.isArray(data) ? data[0] : data;

          if (typeof first === 'string') {
            first = JoomlaCustomize._parseJson(first) || first;
          }

          if (first && first.authExpired) {
            return JoomlaCustomize._sessionExpired();
          }

          return first;
        });
    },

    /**
     * Call a template-scoped customize action on com_templates (no plugin needed). Posts the active
     * style id from the options so the server knows which template/style to act on. Resolves with the
     * parsed handler result ({ success, ... }).
     */
    templateAction: function (action, payload) {
      var opts = options();
      var url = 'index.php?option=com_templates&task=ajax.customize&format=json';
      var body = new FormData();

      body.append('action', action);
      body.append('payload', JSON.stringify(payload || {}));
      body.append('id', opts.styleId || '');

      if (opts.token) {
        body.append(opts.token, '1');
      }

      return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (response) {
          return response.text();
        })
        .then(function (text) {
          var json = JoomlaCustomize._parseJson(text);

          // A non-JSON body (the request was redirected to the admin login) or a request-level
          // rejection (invalid token / not authorised) means the admin session has expired.
          if (json === null || json.success === false) {
            return JoomlaCustomize._sessionExpired();
          }

          var data = json.data ? json.data : json;
          var first = Array.isArray(data) ? data[0] : data;

          if (typeof first === 'string') {
            first = JoomlaCustomize._parseJson(first) || first;
          }

          return first;
        });
    },

    /**
     * Whether the admin session has expired this session (so server actions can no longer be saved).
     */
    sessionExpired: false,

    /**
     * Record that the admin session has expired: announce it once (the engine shows a re-login notice)
     * and return a result the callers recognise as a failed, non-retryable save.
     */
    _sessionExpired: function () {
      if (!JoomlaCustomize.sessionExpired) {
        JoomlaCustomize.sessionExpired = true;
        JoomlaCustomize.emit('customize:session-expired', {});
      }

      return { success: false, sessionExpired: true };
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
     * Open a core admin edit screen (article, module, menu item, ...) in a JoomlaDialog iframe
     * instead of a new tab, and refresh the preview when it closes. opts:
     * { url, title?, checkin?, onClose?, area? }.
     *
     * Mirrors Joomla's native modal-edit flow: the screen is opened with layout=modal, so that on
     * Save & Close / Cancel its controller redirects to the modalreturn layout, which posts a
     * (joomla:content-select | joomla:cancel) message to this window; we close on either, then run
     * onClose. checkin releases the edit lock if the dialog is dismissed without Save/Cancel. area is
     * the block the dialog was opened from; focus returns to it once the refreshed preview re-renders.
     */
    openEditModal: function (opts) {
      // Resolve against the current admin page (not just the origin) so the relative "index.php"
      // keeps the site subfolder and the administrator/ path.
      var url = new URL(opts.url, window.location.href);
      url.searchParams.set('tmpl', 'component');

      var token = (window.Joomla && window.Joomla.getOptions) ? window.Joomla.getOptions('csrf.token', '') : '';
      if (token) {
        url.searchParams.set(token, '1');
      }

      // Tell core's modalreturn script to report back via postMessage, not the legacy modal API.
      window.JoomlaExpectingPostMessage = true;

      return import('joomla.dialog').then(function (module) {
        var JoomlaDialog = module.default;
        var dialog = new JoomlaDialog({ popupType: 'iframe', src: url.toString(), textHeader: opts.title || '' });
        dialog.classList.add('customize-edit-dialog');

        function onMessage(event) {
          if (event.origin !== window.location.origin) {
            return;
          }

          var type = event.data && event.data.messageType;
          if (type === 'joomla:content-select' || type === 'joomla:cancel') {
            dialog.close();
          }
        }

        dialog.addEventListener('joomla-dialog:close', function () {
          window.removeEventListener('message', onMessage);
          delete window.JoomlaExpectingPostMessage;

          if (opts.checkin && window.Joomla && typeof window.Joomla.request === 'function') {
            window.Joomla.request({ url: opts.checkin + (token ? '&' + token + '=1' : ''), method: 'POST' });
          }

          // Return focus to the originating block once the preview reloads (read its id now, while it
          // is still in the iframe, before onClose refreshes the frame).
          if (opts.area && typeof JoomlaCustomize.selectAfterReload === 'function') {
            JoomlaCustomize.selectAfterReload(opts.area);
          }

          if (typeof opts.onClose === 'function') {
            opts.onClose();
          }

          dialog.destroy();
        });

        window.addEventListener('message', onMessage);
        dialog.show();

        return dialog;
      });
    },

    /**
     * The current customize token (the signed value carried in the iframe URL). Initialised from the
     * page options, then kept fresh by the engine's periodic refresh.
     */
    getFrameToken: function () {
      if (frameToken === null) {
        frameToken = options().frameToken || '';
      }

      return frameToken;
    },

    /**
     * Replace the customize token with a freshly minted one.
     */
    setFrameToken: function (value) {
      if (value) {
        frameToken = value;
      }

      return this;
    },

    /**
     * Reload the preview iframe, e.g. after an edit dialog persisted a change, using the current
     * token so a long session that outlived the original one still loads in customize mode.
     */
    reloadFrame: function () {
      // Once the session has expired, reloading the preview can't recover (it would just drop the
      // customize overlay once the token lapses too); leave it in place so the re-login notice shows.
      if (JoomlaCustomize.sessionExpired) {
        return;
      }

      var frame = window.document.getElementById(options().frameId || 'customize-frame');

      if (!frame) {
        return;
      }

      try {
        var win = frame.contentWindow;
        var url = new URL(win.location.href);
        var token = JoomlaCustomize.getFrameToken();

        if (token) {
          url.searchParams.set('customize', token);
        }

        win.location.replace(url.toString());
      } catch (e) {
        frame.src = frame.src;
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

      // A double-click that launches this editor leaves a native text selection over the very nodes we
      // replace below. On a cold editor load that dangling selection can wedge TinyMCE's iframe init,
      // leaving the editor drawn but greyed out. Clear it before swapping the content for the editor.
      try {
        var winSel = (doc.defaultView || window).getSelection();
        if (winSel) { winSel.removeAllRanges(); }
      } catch (e) {}

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
          // Move focus into the editor once it is ready, so keyboard users land inside it.
          auto_focus: ta.id,
          license_key: 'gpl',
          promotion: false,
          menubar: false,
          statusbar: false,
          plugins: 'lists link image autolink',
          toolbar: 'undo redo | bold italic underline | bullist numlist | link customizeimage | removeformat',
          // Keep URLs as provided (root-relative), so they are not rewritten relative to the iframe's
          // SEF page URL (which would 404). Matches how Joomla stores content image paths.
          convert_urls: false,
          // A custom "insert image" button that opens the Media Manager directly and inserts at the
          // cursor. We avoid TinyMCE's own image dialog because its modal backdrop sits over the
          // Save/Cancel bar, so the bar becomes unclickable while it is open.
          setup: function (editor) {
            editor.ui.registry.addButton('customizeimage', {
              icon: 'image',
              tooltip: JoomlaCustomize.text('COM_TEMPLATES_CUSTOMIZE_INSERT_IMAGE', 'Insert image'),
              onAction: function () {
                JoomlaCustomize._mediaPicker(function (url) {
                  editor.insertContent('<img src="' + url.replace(/"/g, '%22') + '" alt="">');
                });
              }
            });
          },
          height: 240
        }).then(function (eds) {
          editor = eds && eds[0];

          // Belt and braces alongside auto_focus, in case the editor was created already focused-away.
          if (editor) {
            editor.focus();
          }
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
     * TinyMCE file-picker that reuses the host page's hidden Joomla media field, so the inline
     * editor's "Image" button browses the Media Manager and inserts the chosen image. Runs in the
     * host (parent) document; the callback inserts into the editor (which lives in the iframe).
     */
    _mediaPicker: function (callback) {
      var host = window.document.getElementById('customize-media-host');
      var field = host ? host.querySelector('joomla-field-media') : null;

      if (!field) {
        return;
      }

      var input = field.querySelector('input');

      function onPicked() {
        field.removeEventListener('change', onPicked);

        var value = input ? input.value : '';
        var url = value ? value.split('#')[0] : '';

        // The media field yields a path relative to the site root (e.g. "images/foo.jpg"); make it
        // root-relative so it resolves in the article regardless of the page's (SEF) URL.
        if (url && url.charAt(0) !== '/' && !/^https?:\/\//i.test(url)) {
          var paths = (window.Joomla && window.Joomla.getOptions) ? (window.Joomla.getOptions('system.paths') || {}) : {};
          url = (paths.root || '').replace(/\/$/, '') + '/' + url;
        }

        if (url) {
          callback(url, { alt: '' });
        }
      }

      field.addEventListener('change', onPicked);

      if (typeof field.show === 'function') {
        field.show();
      } else {
        var btn = field.querySelector('button');

        if (btn) {
          btn.click();
        }
      }
    },

    /**
     * Small shared UI helpers for customize plugins (rendered inside the iframe document).
     */
    ui: {
      toast: function (doc, message) {
        var note = doc.createElement('div');
        note.className = 'customize-toast';
        // role=status (implicit aria-live=polite) so saves/failures are announced to screen readers.
        note.setAttribute('role', 'status');
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
        save.textContent = JoomlaCustomize.text('COM_TEMPLATES_CUSTOMIZE_SAVE', 'Save');

        var cancel = doc.createElement('button');
        cancel.type = 'button';
        cancel.className = 'customize-action customize-action-cancel';
        cancel.textContent = JoomlaCustomize.text('COM_TEMPLATES_CUSTOMIZE_CANCEL', 'Cancel');

        el.appendChild(save);
        el.appendChild(cancel);

        return { el: el, save: save, cancel: cancel };
      },

      saving: function (button) {
        button.disabled = true;
        button.textContent = JoomlaCustomize.text('COM_TEMPLATES_CUSTOMIZE_SAVING', 'Saving…');
      },

      resetSave: function (button) {
        button.disabled = false;
        button.textContent = JoomlaCustomize.text('COM_TEMPLATES_CUSTOMIZE_SAVE', 'Save');
      },

      /**
       * Build a positioned popover with a Save/Cancel bar. It is placed next to opts.anchor, appended
       * to the page, auto-registered as a transient (so the engine dismisses it when another action
       * starts or the selection changes), and closed on Cancel or Escape. The caller supplies only the
       * fields and a save handler.
       *
       * opts: {
       *   anchor,            // element to position next to (required)
       *   placement,         // 'top' (default, at the anchor's top) or 'below' (under it)
       *   className,         // extra class on the popover (optional)
       *   content,           // array of field nodes, appended in order before the bar
       *   saveOnEnter,       // true to also save on Enter (handy for select/input-only popovers)
       *   onSave(api),       // run on Save; api = { close, bar } (use bar with ui.saving/resetSave)
       *   onClose(reason)    // optional extra teardown; reason is 'user' for Cancel/Escape
       * }
       *
       * Returns { el, bar, close }.
       */
      popover: function (doc, opts) {
        opts = opts || {};
        var win = doc.defaultView || window;

        var box = doc.createElement('div');
        box.className = 'customize-popover' + (opts.className ? ' ' + opts.className : '');
        box.setAttribute('role', 'dialog');

        if (opts.ariaLabel) {
          box.setAttribute('aria-label', opts.ariaLabel);
        }

        if (opts.anchor && opts.anchor.getBoundingClientRect) {
          var rect = opts.anchor.getBoundingClientRect();
          var top = (opts.placement === 'below' ? rect.bottom : rect.top) + win.scrollY;
          box.style.top = top + 'px';
          box.style.left = (rect.left + win.scrollX) + 'px';
        }

        (opts.content || []).forEach(function (node) {
          if (node) {
            box.appendChild(node);
          }
        });

        var bar = JoomlaCustomize.ui.makeBar(doc);
        box.appendChild(bar.el);

        var closed = false;
        var off = null;

        function close(reason) {
          if (closed) {
            return;
          }

          closed = true;

          if (off) {
            off();
            off = null;
          }

          if (box.parentNode) {
            box.parentNode.removeChild(box);
          }

          if (typeof opts.onClose === 'function') {
            opts.onClose(reason);
          }
        }

        bar.cancel.addEventListener('click', function () { close('user'); });

        if (typeof opts.onSave === 'function') {
          bar.save.addEventListener('click', function () {
            opts.onSave({ close: close, bar: bar });
          });
        }

        var focusables = function () {
          return box.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
        };

        box.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            e.preventDefault();
            close('user');
          } else if (opts.saveOnEnter && e.key === 'Enter') {
            e.preventDefault();
            bar.save.click();
          } else if (e.key === 'Tab') {
            // Trap focus within the popover so keyboard users do not Tab out to the page behind it.
            var items = focusables();

            if (!items.length) {
              return;
            }

            var first = items[0];
            var last = items[items.length - 1];

            if (e.shiftKey && doc.activeElement === first) {
              e.preventDefault();
              last.focus();
            } else if (!e.shiftKey && doc.activeElement === last) {
              e.preventDefault();
              first.focus();
            }
          }
        });

        doc.body.appendChild(box);

        // Keep the popover within the visible viewport, so it never opens off-screen (e.g. anchored to
        // the bottom edge of a tall region). Done after appending, once it has measurable dimensions.
        if (box.style.top) {
          var margin = 8;
          var maxLeft = win.scrollX + win.innerWidth - box.offsetWidth - margin;
          var maxTop = win.scrollY + win.innerHeight - box.offsetHeight - margin;
          box.style.left = Math.max(win.scrollX + margin, Math.min(parseFloat(box.style.left) || 0, maxLeft)) + 'px';
          box.style.top = Math.max(win.scrollY + margin, Math.min(parseFloat(box.style.top) || 0, maxTop)) + 'px';
        }

        // Move focus into the popover (the first control, which is a content field/button since the
        // Save/Cancel bar is appended last), so keyboard users land in it instead of behind it.
        var firstFocusable = focusables()[0];

        if (firstFocusable) {
          firstFocusable.focus();
        }

        off = JoomlaCustomize.registerTransient(function () { close(); });

        return { el: box, bar: bar, close: close };
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
    },

    // Transient overlays (popovers, pickers, menus). A plugin calls registerTransient(teardown) when it
    // opens one; the engine calls dismissTransients() whenever another action starts, the selection
    // changes, or focus leaves, so overlays never stack. New buttons need no cross-wiring: they just
    // register their own teardown. registerTransient returns a function that unregisters it.
    registerTransient: function (teardown) {
      transients.push(teardown);

      return function () {
        var i = transients.indexOf(teardown);

        if (i !== -1) {
          transients.splice(i, 1);
        }
      };
    },

    dismissTransients: function () {
      var pending = transients;
      transients = [];

      for (var i = 0; i < pending.length; i++) {
        try {
          pending[i]();
        } catch (e) {}
      }
    }
  };

  window.JoomlaCustomize = JoomlaCustomize;

export default JoomlaCustomize;
