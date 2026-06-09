/**
 * Customize - Language plugin (admin side).
 *
 * In customize mode core records every key => translated string it produces and appends it to the
 * page as a JSON island (#customize-lang-map), WITHOUT altering the rendered output. This script
 * reads that map and wraps matching on-page text nodes as editable "lang" areas; editing saves a
 * Joomla language override. A MutationObserver does the same for dynamically inserted content.
 *
 * Only text that renders as its own node equal to a recorded translation is editable; composed /
 * sprintf strings (e.g. "Category: X", dates) are intentionally left alone.
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
  var lookup = null;

  // Build a trimmed-text => key lookup from the JSON island, skipping sprintf/format templates whose
  // rendered text differs from the raw translation.
  function readLookup(doc) {
    var el = doc.getElementById('customize-lang-map');

    if (!el) {
      return null;
    }

    var map;

    try {
      map = JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }

    var lk = Object.create(null);

    Object.keys(map).forEach(function (key) {
      var text = map[key];

      if (typeof text !== 'string') {
        return;
      }

      var trimmed = text.trim();

      if (trimmed === '' || trimmed.indexOf('%') !== -1) {
        return;
      }

      if (!(trimmed in lk)) {
        lk[trimmed] = key;
      }
    });

    return lk;
  }

  // The key for a text node if its trimmed text is a recorded translation and it is editable here.
  function matchTextNode(node) {
    if (!lookup) {
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

    var key = lookup[trimmed];

    if (!key) {
      return null;
    }

    // Avoid false positives where DB text happens to equal a translation: if this text is the label
    // of an enclosing non-lang area (e.g. a menu item title that reads "Home"), it is that area's
    // content, not an independent translation, so leave it to that area.
    var area = parent.closest('[data-customize-type]');

    if (area && area.getAttribute('data-customize-type') !== 'lang'
      && area.getAttribute('data-customize-name') === trimmed) {
      return null;
    }

    return key;
  }

  // Wrap the trimmed text of a node in an editable lang span, preserving surrounding whitespace.
  function wrapNode(doc, node, key) {
    var value = node.nodeValue;
    var trimmed = value.trim();
    var start = value.indexOf(trimmed);
    var frag = doc.createDocumentFragment();

    if (start > 0) {
      frag.appendChild(doc.createTextNode(value.slice(0, start)));
    }

    var span = doc.createElement('span');
    span.setAttribute('data-customize-type', 'lang');
    span.setAttribute('data-customize-id', key);
    span.setAttribute('data-customize-name', key);
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
    if (!lookup) {
      return;
    }

    root = root || doc.body;
    var walkRoot = root.nodeType === 1 ? root : doc.body;
    var walker = doc.createTreeWalker(walkRoot, NodeFilter.SHOW_TEXT, null);
    var targets = [];
    var node;

    while ((node = walker.nextNode())) {
      var key = matchTextNode(node);

      if (key) {
        targets.push({ node: node, key: key });
      }
    }

    targets.forEach(function (it) {
      wrapNode(doc, it.node, it.key);
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
            var key = matchTextNode(n);

            if (key) {
              wrapNode(doc, n, key);
            }
          } else if (n.nodeType === 1) {
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
    if (!e.detail || !e.detail.doc) {
      return;
    }

    var doc = e.detail.doc;
    lookup = readLookup(doc);

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
