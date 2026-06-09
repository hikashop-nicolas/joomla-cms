/**
 * Customize - Language plugin (admin side).
 *
 * In customize mode core records, without altering the rendered output:
 *   - strings: language key => translated text (simple translations);
 *   - sprintf: rendered result => { key, format } (composed strings, e.g. "Written by: Admin").
 * Both are appended to the page as a JSON island (#customize-lang-map). This script matches on-page
 * text nodes against them and wraps matches as editable "lang" areas. A simple translation is edited
 * in place; a composed string opens a popover to edit its format ("Written by: %s"). Editing saves a
 * Joomla language override. A MutationObserver applies the same to dynamically inserted content.
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

  var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, OPTION: 1, TITLE: 1, NOSCRIPT: 1 };
  var editing = false;
  var maps = null;

  function reloadFrame() {
    var f = window.document.getElementById('customize-frame');

    if (f) {
      try {
        f.contentWindow.location.reload();
      } catch (e) {
        f.src = f.src;
      }
    }
  }

  // Read the JSON island into { exact: {text: key}, sprintf: {rendered: {key, format}} }.
  function readMaps(doc) {
    var el = doc.getElementById('customize-lang-map');

    if (!el) {
      return null;
    }

    var data;

    try {
      data = JSON.parse(window.atob(el.textContent.trim()));
    } catch (e) {
      return null;
    }

    var exact = Object.create(null);
    var prefix = Object.create(null);
    var sprintf = Object.create(null);
    var strings = data.strings || {};
    var phRe = /%(\d+\$)?[sdufeEgGxXobc]|%%/;

    Object.keys(strings).forEach(function (key) {
      var text = strings[key];

      if (typeof text !== 'string') {
        return;
      }

      var trimmed = text.trim();

      if (trimmed === '') {
        return;
      }

      if (!phRe.test(text)) {
        // Simple translation: match the text as-is.
        if (!(trimmed in exact)) {
          exact[trimmed] = key;
        }
      } else {
        // Format string: match its literal prefix (the text before the first placeholder), e.g. the
        // "Written by: " text node that precedes a <span>author</span>.
        var at = text.search(phRe);
        var pfx = at > 0 ? text.slice(0, at).trim() : '';

        if (pfx.length >= 4 && !(pfx in prefix)) {
          prefix[pfx] = { key: key, format: text };
        }
      }
    });

    var composed = data.sprintf || {};

    Object.keys(composed).forEach(function (rendered) {
      var trimmed = String(rendered).trim();

      // Whole rendered result of a plain composed string, e.g. "Hits: 1" or "Published: 01 January 2024".
      if (trimmed !== '' && !(trimmed in sprintf)) {
        sprintf[trimmed] = composed[rendered];
      }
    });

    return { exact: exact, sprintf: sprintf, prefix: prefix };
  }

  // Return { key, format } for a text node if its trimmed text is a recorded translation editable
  // here (format is null for a simple translation, or the format string for a composed one).
  function matchTextNode(node) {
    if (!maps) {
      return null;
    }

    var parent = node.parentNode;

    if (!parent || parent.nodeType !== 1 || SKIP[parent.nodeName]) {
      return null;
    }

    if (parent.getAttribute('data-customize-type') === 'lang') {
      return null;
    }

    var trimmed = node.nodeValue.trim();

    if (trimmed === '') {
      return null;
    }

    var match = null;

    if (maps.exact[trimmed]) {
      match = { key: maps.exact[trimmed], format: null };
    } else if (maps.sprintf[trimmed]) {
      match = { key: maps.sprintf[trimmed].key, format: maps.sprintf[trimmed].format };
    } else if (maps.prefix[trimmed]) {
      match = { key: maps.prefix[trimmed].key, format: maps.prefix[trimmed].format };
    }

    if (!match) {
      return null;
    }

    // Avoid false positives where DB text happens to equal a translation: if this text is the label
    // of an enclosing non-lang area (e.g. a menu item title that reads "Home"), leave it to that area.
    var area = parent.closest('[data-customize-type]');

    if (area && area.getAttribute('data-customize-type') !== 'lang'
      && area.getAttribute('data-customize-name') === trimmed) {
      return null;
    }

    return match;
  }

  // Wrap the trimmed text of a node in an editable lang span, preserving surrounding whitespace.
  function wrapNode(doc, node, match) {
    var value = node.nodeValue;
    var trimmed = value.trim();
    var start = value.indexOf(trimmed);
    var frag = doc.createDocumentFragment();

    if (start > 0) {
      frag.appendChild(doc.createTextNode(value.slice(0, start)));
    }

    var span = doc.createElement('span');
    span.setAttribute('data-customize-type', 'lang');
    span.setAttribute('data-customize-id', match.key);
    span.setAttribute('data-customize-name', match.key);

    if (match.format) {
      span.setAttribute('data-customize-format', match.format);
    }

    span.textContent = trimmed;
    frag.appendChild(span);

    var after = value.slice(start + trimmed.length);

    if (after) {
      frag.appendChild(doc.createTextNode(after));
    }

    if (node.parentNode) {
      node.parentNode.replaceChild(frag, node);
    }
  }

  function instrument(doc, root) {
    if (!maps) {
      return;
    }

    root = root || doc.body;
    var walkRoot = root.nodeType === 1 ? root : doc.body;
    var walker = doc.createTreeWalker(walkRoot, NodeFilter.SHOW_TEXT, null);
    var targets = [];
    var node;

    while ((node = walker.nextNode())) {
      var match = matchTextNode(node);

      if (match) {
        targets.push({ node: node, match: match });
      }
    }

    targets.forEach(function (it) {
      wrapNode(doc, it.node, it.match);
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
            var match = matchTextNode(n);

            if (match) {
              wrapNode(doc, n, match);
            }
          } else if (n.nodeType === 1) {
            instrument(doc, n);
          }
        });
      });
    });

    obs.observe(doc.body, { childList: true, subtree: true });
  }

  // Edit a composed string's format ("Written by: %s") in a popover.
  function editFormat(ctx) {
    var doc = ctx.doc;
    var span = ctx.el;
    var format = span.getAttribute('data-customize-format');
    var existing = doc.getElementById('customize-format-edit');

    if (existing) {
      existing.remove();
    }

    var box = doc.createElement('div');
    box.id = 'customize-format-edit';
    box.className = 'customize-popover';

    var rect = span.getBoundingClientRect();
    var win = doc.defaultView;
    box.style.top = (rect.bottom + win.scrollY) + 'px';
    box.style.left = (rect.left + win.scrollX) + 'px';

    var input = doc.createElement('input');
    input.type = 'text';
    input.value = format;

    var hint = doc.createElement('div');
    hint.className = 'customize-hint';
    hint.textContent = t('PLG_CUSTOMIZE_LANGUAGE_FORMAT_HINT', 'Keep the %s / %d placeholders where the value goes.');

    box.appendChild(JC.ui.label(doc, t('PLG_CUSTOMIZE_LANGUAGE_LABEL', 'Text')));
    box.appendChild(input);
    box.appendChild(hint);

    var bar = JC.ui.makeBar(doc);
    box.appendChild(bar.el);
    doc.body.appendChild(box);
    input.focus();
    input.select();

    bar.cancel.addEventListener('click', function () {
      box.remove();
    });

    bar.save.addEventListener('click', function () {
      JC.ui.saving(bar.save);

      JC.callAction('language', 'save', { key: ctx.data.id, value: input.value }).then(function (res) {
        if (res && res.success) {
          box.remove();
          reloadFrame();
        } else {
          JC.ui.resetSave(bar.save);
          var reason = (res && res.message) || t('PLG_CUSTOMIZE_LANGUAGE_UNKNOWN_ERROR', 'unknown error');
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_LANGUAGE_SAVE_FAILED', 'Could not save the translation.') + ' ' + reason);
        }
      }).catch(function () {
        JC.ui.resetSave(bar.save);
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_LANGUAGE_SAVE_ERROR', 'Save error.'));
      });
    });
  }

  // Edit a simple translation in place; saving writes a language override.
  function editInline(ctx) {
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

  function editLang(ctx) {
    if (ctx.el.getAttribute('data-customize-format')) {
      editFormat(ctx);
    } else {
      editInline(ctx);
    }
  }

  JC.on('customize:frame-ready', function (e) {
    if (!e.detail || !e.detail.doc) {
      return;
    }

    var doc = e.detail.doc;
    maps = readMaps(doc);

    // Defer until after the other plugins' frame-ready listeners have tagged their areas (modules,
    // menu items, ...), so the "is this an area's own label?" guard can see them.
    doc.defaultView.setTimeout(function () {
      instrument(doc);
      observe(doc);
    }, 0);
  });

  JC.registerAreaType('lang', { label: t('PLG_CUSTOMIZE_LANGUAGE_AREA', 'Text') });
  JC.registerButton('lang', { id: 'edit', label: t('PLG_CUSTOMIZE_LANGUAGE_BTN_EDIT', 'Edit text'), order: 10, onClick: editLang });
}(window));
