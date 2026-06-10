/**
 * Customize - View plugin (admin side).
 *
 * Core fires onCustomizeRenderView for each sub-layout; the PHP handler wraps the output in
 * <!--customize-block-start:meta--> ... <!--customize-block-end--> comment markers (layout-safe). This
 * upgrades each marker pair into a "view-block" area: it marks the single root element directly when
 * possible (no layout impact), else wraps the range in a div. "Edit layout" creates the layout
 * override and opens Joomla's native template editor in a new tab. (Reorder is added separately.)
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

  // Turn a start/end comment pair (siblings) into one element carrying the block's identity.
  function wrapRange(doc, startC, endC, meta) {
    var parent = startC.parentNode;

    // Only safe when the markers are siblings; otherwise drop them (block stays non-editable).
    if (!parent || parent !== endC.parentNode) {
      if (startC.parentNode) {
        startC.parentNode.removeChild(startC);
      }
      if (endC.parentNode) {
        endC.parentNode.removeChild(endC);
      }
      return;
    }

    var between = [];
    var n = startC.nextSibling;

    while (n && n !== endC) {
      between.push(n);
      n = n.nextSibling;
    }

    var els = between.filter(function (x) { return x.nodeType === 1; });
    var nonWsText = between.filter(function (x) { return x.nodeType === 3 && x.nodeValue.trim() !== ''; });
    var target;

    if (els.length === 1 && nonWsText.length === 0 && !els[0].hasAttribute('data-customize-type')) {
      // Single root element: mark it directly, no wrapper, no layout impact.
      target = els[0];
    } else {
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
    target.setAttribute('data-customize-name', meta.block || meta.view || 'block');

    if (meta.override === '1') {
      target.setAttribute('data-customize-cue', t('PLG_CUSTOMIZE_VIEW_OVERRIDDEN', 'Overridden'));
    }

    if (startC.parentNode) {
      startC.parentNode.removeChild(startC);
    }
    if (endC.parentNode) {
      endC.parentNode.removeChild(endC);
    }
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
      } else {
        var open = stack.pop();

        if (!open) {
          if (c.parentNode) {
            c.parentNode.removeChild(c);
          }
          return;
        }

        wrapRange(doc, open.node, c, open.meta);
      }
    });

    stack.forEach(function (open) {
      if (open.node.parentNode) {
        open.node.parentNode.removeChild(open.node);
      }
    });
  }

  // "Edit layout": create the override server-side, open Joomla's native template editor in a new tab.
  function openLayoutEditor(ctx) {
    JC.callAction('view', 'override', {
      component: ctx.data.component,
      view: ctx.data.view,
      layout: ctx.data.layout,
      block: ctx.data.block,
      template: ctx.data.template
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
    }
  });

  JC.registerAreaType('view-block', { label: t('PLG_CUSTOMIZE_VIEW_AREA', 'Layout block') });
  JC.registerButton('view-block', { id: 'advanced', label: t('PLG_CUSTOMIZE_VIEW_BTN_EDIT', 'Edit layout'), order: 90, onClick: openLayoutEditor });
