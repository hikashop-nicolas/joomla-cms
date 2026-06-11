/**
 * Customize - View plugin (admin side).
 *
 * Core fires onCustomizeRenderView for every nested sub-layout; the PHP handler wraps each in
 * <!--customize-block-start:meta--> ... <!--customize-block-end--> comment markers carrying the
 * block's identity and source. This upgrades each marker pair into a "view-block" area: the single
 * root element is marked in place, or a multi-element range is wrapped in a div. Every level is
 * marked; areas only show their outline and toolbar on hover, so this stays unobtrusive. "Edit layout"
 * creates the layout override and opens Joomla's native template editor; "Parent"/"Children" navigate
 * the block hierarchy (read from the DOM nesting).
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
import JC from 'customize.api';

  function t(key, fallback) {
    return JC.text(key, fallback);
  }

  var START = 'customize-block-start:';
  var END = 'customize-block-end';

  function parseMeta(text) {
    var meta = {};

    text.split(';').forEach(function (pair) {
      var i = pair.indexOf('=');

      if (i > 0) {
        meta[pair.slice(0, i)] = pair.slice(i + 1);
      }
    });

    return meta;
  }

  function dropMarkers(startC, endC) {
    if (startC.parentNode) {
      startC.parentNode.removeChild(startC);
    }
    if (endC.parentNode) {
      endC.parentNode.removeChild(endC);
    }
  }

  // Turn a start/end comment pair (siblings) into one "view-block" element: mark the single root
  // element in place, or wrap a multi-element range in a div. The markers are consumed either way.
  function wrapRange(doc, startC, endC, meta) {
    var parent = startC.parentNode;

    // Only safe when the markers are siblings; otherwise drop them (block stays non-editable).
    if (!parent || parent !== endC.parentNode) {
      dropMarkers(startC, endC);

      return;
    }

    var between = [];
    var n = startC.nextSibling;

    while (n && n !== endC) {
      between.push(n);
      n = n.nextSibling;
    }

    var els = between.filter(function (x) { return x.nodeType === 1; });
    var txt = between.filter(function (x) { return x.nodeType === 3 && x.nodeValue.trim() !== ''; });
    var target;

    if (!els.length) {
      dropMarkers(startC, endC);

      return;
    }

    if (els.length === 1 && txt.length === 0 && !els[0].hasAttribute('data-customize-type')) {
      // Single root element: mark it directly, no wrapper, no layout impact.
      target = els[0];
    } else {
      // Multiple elements (or loose text): wrap the range in a div so the whole block is one area.
      target = doc.createElement('div');
      parent.insertBefore(target, startC);
      between.forEach(function (x) { target.appendChild(x); });
    }

    target.setAttribute('data-customize-type', 'view-block');
    target.setAttribute('data-customize-component', meta.component || '');
    target.setAttribute('data-customize-view', meta.view || '');
    target.setAttribute('data-customize-layout', meta.layout || '');
    target.setAttribute('data-customize-block', meta.block || '');
    target.setAttribute('data-customize-occ', meta.occ || '');
    target.setAttribute('data-customize-template', meta.template || '');
    target.setAttribute('data-customize-source', meta.source || '');
    target.setAttribute('data-customize-name', meta.block || meta.view || 'block');

    if (meta.override === '1') {
      target.setAttribute('data-customize-cue', t('PLG_CUSTOMIZE_VIEW_OVERRIDDEN', 'Overridden'));
    }

    dropMarkers(startC, endC);
  }

  function instrument(doc) {
    var iterator = doc.createNodeIterator(doc.body, NodeFilter.SHOW_COMMENT, null);
    var comments = [];
    var node;

    while ((node = iterator.nextNode())) {
      var v = node.nodeValue;

      if (v.indexOf(START) === 0 || v === END) {
        comments.push(node);
      }
    }

    // Pair start/end via a stack (sub-layouts nest; inner ends are reached first in document order).
    var stack = [];

    comments.forEach(function (c) {
      var v = c.nodeValue;

      if (v.indexOf(START) === 0) {
        stack.push({ node: c, meta: parseMeta(v.slice(START.length)) });

        return;
      }

      var open = stack.pop();

      if (!open) {
        if (c.parentNode) {
          c.parentNode.removeChild(c);
        }

        return;
      }

      wrapRange(doc, open.node, c, open.meta);
    });

    stack.forEach(function (open) {
      if (open.node.parentNode) {
        open.node.parentNode.removeChild(open.node);
      }
    });
  }

  // After all blocks are wrapped, flag each with whether it sits inside another block and/or contains
  // blocks, so the toolbar can offer parent/child navigation. The hierarchy is read from the DOM
  // nesting (a block physically contains its sub-layouts), so no server-side metadata is needed.
  function markHierarchy(doc) {
    Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type="view-block"]'), function (el) {
      if (el.parentElement && el.parentElement.closest('[data-customize-type="view-block"]')) {
        el.setAttribute('data-customize-hasparent', '1');
      }

      if (el.querySelector('[data-customize-type="view-block"]')) {
        el.setAttribute('data-customize-haschildren', '1');
      }
    });
  }

  // Every block in this one's subtree, de-duplicated by layout (keeping the first/shallowest), each
  // tagged with its depth relative to this block (1 = direct child). querySelectorAll is in document
  // order (pre-order), so the first occurrence of a repeated layout is its shallowest.
  function subtreeBlocks(root) {
    var seen = {};
    var items = [];

    Array.prototype.forEach.call(root.querySelectorAll('[data-customize-type="view-block"]'), function (el) {
      var name = el.getAttribute('data-customize-name') || el.getAttribute('data-customize-block') || 'block';

      if (seen[name]) {
        return;
      }

      seen[name] = true;

      var depth = 1;
      var p = el.parentElement && el.parentElement.closest('[data-customize-type="view-block"]');

      while (p && p !== root) {
        depth++;
        p = p.parentElement && p.parentElement.closest('[data-customize-type="view-block"]');
      }

      items.push({ el: el, name: name, depth: depth });
    });

    return items;
  }

  // "Parent": jump to the block enclosing this one.
  function selectParent(ctx) {
    var parent = ctx.el.parentElement && ctx.el.parentElement.closest('[data-customize-type="view-block"]');

    if (parent && JC.select) {
      JC.select(parent);
    }
  }

  // "Children": list this block's whole subtree (de-duplicated by layout, indented by depth) and jump
  // to the chosen one.
  function showChildren(ctx) {
    var doc = ctx.doc;
    var existing = doc.getElementById('customize-view-children');

    if (existing) {
      existing.remove();
    }

    var items = subtreeBlocks(ctx.el);

    if (!items.length) {
      return;
    }

    var menu = doc.createElement('div');
    menu.id = 'customize-view-children';
    menu.className = 'customize-popover';

    var win = doc.defaultView;
    var rect = ctx.el.getBoundingClientRect();
    menu.style.top = (rect.top + win.scrollY) + 'px';
    menu.style.left = (rect.left + win.scrollX) + 'px';

    items.forEach(function (it) {
      var btn = doc.createElement('button');
      btn.type = 'button';
      btn.className = 'customize-menu-item';
      btn.textContent = it.name;
      // Indent by depth so the subtree's nesting is legible.
      btn.style.paddingLeft = (8 + (it.depth - 1) * 14) + 'px';
      btn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        menu.remove();

        if (JC.select) {
          JC.select(it.el);
        }
      });
      menu.appendChild(btn);
    });

    doc.body.appendChild(menu);

    // Dismiss on the next click outside the menu.
    win.setTimeout(function () {
      doc.addEventListener('click', function dismiss(ev) {
        if (!menu.contains(ev.target)) {
          menu.remove();
          doc.removeEventListener('click', dismiss);
        }
      });
    }, 0);
  }

  // "Edit layout": create the override server-side, open Joomla's native template editor in a new tab.
  function openLayoutEditor(ctx) {
    JC.callAction('view', 'override', {
      component: ctx.data.component,
      view: ctx.data.view,
      layout: ctx.data.layout,
      block: ctx.data.block,
      template: ctx.data.template,
      source: ctx.data.source
    }).then(function (res) {
      if (res && res.success && res.url) {
        window.open(res.url, '_blank', 'noopener');
      } else {
        JC.ui.toast(ctx.doc, (res && res.message) || t('PLG_CUSTOMIZE_VIEW_UNKNOWN_ERROR', 'unknown error'));
      }
    }).catch(function () {
      JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_VIEW_SAVE_ERROR', 'Save error.'));
    });
  }

  JC.on('customize:frame-ready', function (e) {
    if (e.detail && e.detail.doc) {
      instrument(e.detail.doc);
      markHierarchy(e.detail.doc);
    }
  });

  JC.registerAreaType('view-block', { label: t('PLG_CUSTOMIZE_VIEW_AREA', 'Layout block') });
  JC.registerButton('view-block', { id: 'parent', label: t('PLG_CUSTOMIZE_VIEW_PARENT', 'Parent'), order: 10, requires: 'hasparent', onClick: selectParent });
  JC.registerButton('view-block', { id: 'children', label: t('PLG_CUSTOMIZE_VIEW_CHILDREN', 'Children'), order: 20, requires: 'haschildren', onClick: showChildren });
  JC.registerButton('view-block', { id: 'advanced', label: t('PLG_CUSTOMIZE_VIEW_BTN_EDIT', 'Edit layout'), order: 90, onClick: openLayoutEditor });
