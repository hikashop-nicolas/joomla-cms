/**
 * Customize - Language plugin (admin side).
 *
 * Core Language::_ wraps each translated string with its key in customize mode using private-use
 * markers (U+E000 key U+E001 text U+E002). This turns those markers into editable "lang" areas in
 * the iframe (and strips them from attributes), and saves edits as a Joomla language override.
 * Nested translations (a string composed from other translated strings, e.g. dates) nest markers;
 * only leaf strings are made editable, template strings are unwrapped.
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

  function instrument(doc) {
    // Strip markers from attribute values and the document title (kept as text, not editable).
    Array.prototype.forEach.call(doc.querySelectorAll('*'), function (el) {
      for (var i = 0; i < el.attributes.length; i++) {
        var attr = el.attributes[i];

        if (attr.value.indexOf(START) !== -1) {
          el.setAttribute(attr.name, stripMarkers(attr.value));
        }
      }
    });

    if (doc.title && doc.title.indexOf(START) !== -1) {
      doc.title = stripMarkers(doc.title);
    }

    // Turn markers in body text nodes into editable spans; strip them where a span can't go.
    var walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, null);
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

  // Joomla.Text._ in the iframe returns markered strings (the script registered them before we could
  // clean the page), so dynamically-rendered messages (form validation, alerts) would show markers.
  // Wrap it to strip markers on read, handling them like the static DOM does.
  function patchJoomlaText(doc) {
    var win = doc.defaultView;
    var store = win.Joomla && win.Joomla.Text;

    if (!store || typeof store._ !== 'function' || store.customizePatched) {
      return;
    }

    var original = store._;
    store._ = function (key, def) {
      var s = original.call(this, key, def);
      return (typeof s === 'string' && s.indexOf(START) !== -1) ? stripMarkers(s) : s;
    };
    store.customizePatched = true;
  }

  // Edit a translated string in place; saving writes a language override.
  function editLang(ctx) {
    var doc = ctx.doc;
    var span = ctx.el;

    if (span.getAttribute('data-customize-editing') === '1') {
      return;
    }

    span.setAttribute('data-customize-editing', '1');
    JC.emit('customize:edit-start');

    var original = span.textContent;
    span.setAttribute('contenteditable', 'true');
    span.classList.add('customize-editing');
    span.focus();

    var bar = JC.ui.makeBar(doc);
    span.parentNode.insertBefore(bar.el, span.nextSibling);

    // The string may sit inside a link or button; suppress its activation while editing so clicking
    // to place the caret doesn't navigate or submit.
    var interactive = span.closest ? span.closest('a, button') : null;

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
      patchJoomlaText(e.detail.doc);
      instrument(e.detail.doc);
    }
  });

  JC.registerAreaType('lang', { label: t('PLG_CUSTOMIZE_LANGUAGE_AREA', 'Text') });
  JC.registerButton('lang', { id: 'edit', label: t('PLG_CUSTOMIZE_LANGUAGE_BTN_EDIT', 'Edit text'), order: 10, onClick: editLang });
}(window));
