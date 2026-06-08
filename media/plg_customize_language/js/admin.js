/**
 * Customize - Language plugin (admin side).
 *
 * Core Language::_ wraps each translated string with its key in customize mode using private-use
 * markers (U+E000 key U+E001 text U+E002). This turns those markers into editable "lang" areas in
 * the iframe (and strips them from attributes), and saves edits as a Joomla language override.
 * Nested translations (a string composed from other translated strings, e.g. dates) nest markers;
 * only leaf strings are made editable, template strings are unwrapped. A MutationObserver runs the
 * same instrumentation on dynamically inserted content (form validation messages, alerts, AJAX),
 * so those become editable too.
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
(function (window) {
  'use strict';

  var JC = window.JoomlaCustomize;

  if (!JC) {
    return;
  }

  function t(key, fallback) {
    return JC.text(key, fallback);
  }

  var START = String.fromCharCode(0xE000);
  var SEP = String.fromCharCode(0xE001);
  var END = String.fromCharCode(0xE002);
  var OPEN_RE = new RegExp(START + '[^' + SEP + ']*' + SEP, 'g');
  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, OPTION: 1, TITLE: 1, NOSCRIPT: 1 };
  var editing = false;

  // Remove all markers, keeping the text (handles nesting): drop every "START key SEP" and END.
  function stripMarkers(value) {
    return value.replace(OPEN_RE, '').split(END).join('');
  }

  // Parse a marker string into a fragment of (possibly nested) lang spans.
  function parseInto(doc, text) {
    var frag = doc.createDocumentFragment();
    var stack = [frag];
    var i = 0;
    var buf = '';

    function flush() {
      if (buf) {
        stack[stack.length - 1].appendChild(doc.createTextNode(buf));
        buf = '';
      }
    }

    while (i < text.length) {
      var c = text.charAt(i);

      if (c === START) {
        var sep = text.indexOf(SEP, i + 1);

        if (sep === -1) {
          buf += c;
          i++;
          continue;
        }

        flush();
        var key = text.slice(i + 1, sep);
        var span = doc.createElement('span');
        span.setAttribute('data-customize-type', 'lang');
        span.setAttribute('data-customize-id', key);
        span.setAttribute('data-customize-name', key);
        stack[stack.length - 1].appendChild(span);
        stack.push(span);
        i = sep + 1;
      } else if (c === END) {
        flush();
        if (stack.length > 1) {
          stack.pop();
        }
        i++;
      } else {
        buf += c;
        i++;
      }
    }

    flush();
    return frag;
  }

  // A lang span that contains another lang span is a template; unwrap it (keep leaves editable).
  function unwrapTemplates(frag) {
    var spans = frag.querySelectorAll('span[data-customize-type="lang"]');

    Array.prototype.forEach.call(spans, function (s) {
      if (s.querySelector('[data-customize-type="lang"]') && s.parentNode) {
        while (s.firstChild) {
          s.parentNode.insertBefore(s.firstChild, s);
        }
        s.parentNode.removeChild(s);
      }
    });
  }

  function spanify(doc, node) {
    var frag = parseInto(doc, node.nodeValue);
    unwrapTemplates(frag);

    if (node.parentNode) {
      node.parentNode.replaceChild(frag, node);
    }
  }

  // Instrument a subtree (the whole body on load, or a freshly inserted node): strip markers from
  // attributes, turn marker text into editable spans (strip where a span can't go).
  function instrument(doc, root) {
    root = root || doc.body;

    var els = root.nodeType === 1 ? [root] : [];

    if (root.querySelectorAll) {
      els = els.concat(Array.prototype.slice.call(root.querySelectorAll('*')));
    }

    els.forEach(function (el) {
      for (var i = 0; i < el.attributes.length; i++) {
        var attr = el.attributes[i];

        if (attr.value.indexOf(START) !== -1) {
          el.setAttribute(attr.name, stripMarkers(attr.value));
        }
      }
    });

    if (root === doc.body && doc.title && doc.title.indexOf(START) !== -1) {
      doc.title = stripMarkers(doc.title);
    }

    var walkRoot = root.nodeType === 1 ? root : doc.body;
    var walker = doc.createTreeWalker(walkRoot, NodeFilter.SHOW_TEXT, null);
    var targets = [];
    var node;

    while ((node = walker.nextNode())) {
      if (node.nodeValue.indexOf(START) === -1) {
        continue;
      }

      var parent = node.parentNode;

      if (parent && SKIP[parent.nodeName]) {
        node.nodeValue = stripMarkers(node.nodeValue);
      } else {
        targets.push(node);
      }
    }

    targets.forEach(function (n) {
      spanify(doc, n);
    });
  }

  // Instrument dynamically inserted content (validation messages, AJAX, etc.) the same way.
  function observe(doc) {
    var win = doc.defaultView;

    if (!win.MutationObserver) {
      return;
    }

    var obs = new win.MutationObserver(function (mutations) {
      if (editing) {
        return;
      }

      mutations.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, function (n) {
          if (n.nodeType === 3) {
            if (n.nodeValue.indexOf(START) === -1) {
              return;
            }

            if (n.parentNode && SKIP[n.parentNode.nodeName]) {
              n.nodeValue = stripMarkers(n.nodeValue);
            } else {
              spanify(doc, n);
            }
          } else if (n.nodeType === 1 && n.textContent && n.textContent.indexOf(START) !== -1) {
            instrument(doc, n);
          }
        });
      });
    });

    obs.observe(doc.body, { childList: true, subtree: true });
  }

  // Edit a translated string in place; saving writes a language override.
  function editLang(ctx) {
    var doc = ctx.doc;
    var span = ctx.el;

    if (span.getAttribute('data-customize-editing') === '1') {
      return;
    }

    span.setAttribute('data-customize-editing', '1');
    editing = true;
    JC.emit('customize:edit-start');

    var original = span.textContent;
    span.setAttribute('contenteditable', 'true');
    span.classList.add('customize-editing');
    span.focus();

    // The string may sit inside a link or button; suppress its activation while editing, and place
    // the Save/Cancel bar OUTSIDE that element so its own clicks are not blocked.
    var interactive = span.closest ? span.closest('a, button') : null;
    var anchor = interactive || span;

    var bar = JC.ui.makeBar(doc);
    anchor.parentNode.insertBefore(bar.el, anchor.nextSibling);

    function blockClick(ev) {
      ev.preventDefault();
      ev.stopPropagation();
    }

    span.addEventListener('click', blockClick, true);
    if (interactive) {
      interactive.addEventListener('click', blockClick, true);
    }

    function teardown() {
      span.removeAttribute('contenteditable');
      span.classList.remove('customize-editing');
      span.removeEventListener('keydown', onKey);
      span.removeEventListener('click', blockClick, true);
      if (interactive) {
        interactive.removeEventListener('click', blockClick, true);
      }
      span.removeAttribute('data-customize-editing');
      editing = false;
      JC.emit('customize:edit-end');
      if (bar.el.parentNode) {
        bar.el.parentNode.removeChild(bar.el);
      }
    }

    function cancel() {
      span.textContent = original;
      teardown();
    }

    function save() {
      JC.ui.saving(bar.save);

      JC.callAction('language', 'save', { key: ctx.data.id, value: span.textContent }).then(function (res) {
        if (res && res.success) {
          teardown();
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_LANGUAGE_SAVED', 'Translation saved.'));
        } else {
          JC.ui.resetSave(bar.save);
          var reason = (res && res.message) || t('PLG_CUSTOMIZE_LANGUAGE_UNKNOWN_ERROR', 'unknown error');
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_LANGUAGE_SAVE_FAILED', 'Could not save the translation.') + ' ' + reason);
        }
      }).catch(function () {
        JC.ui.resetSave(bar.save);
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_LANGUAGE_SAVE_ERROR', 'Save error.'));
      });
    }

    function onKey(e) {
      e.stopPropagation();

      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        save();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        cancel();
      }
    }

    span.addEventListener('keydown', onKey);
    bar.save.addEventListener('click', save);
    bar.cancel.addEventListener('click', cancel);
  }

  JC.on('customize:frame-ready', function (e) {
    if (e.detail && e.detail.doc) {
      instrument(e.detail.doc);
      observe(e.detail.doc);
    }
  });

  JC.registerAreaType('lang', { label: t('PLG_CUSTOMIZE_LANGUAGE_AREA', 'Text') });
  JC.registerButton('lang', { id: 'edit', label: t('PLG_CUSTOMIZE_LANGUAGE_BTN_EDIT', 'Edit text'), order: 10, onClick: editLang });
}(window));
