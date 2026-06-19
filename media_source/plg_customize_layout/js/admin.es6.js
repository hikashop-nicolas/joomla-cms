/**
 * Customize - Layout plugin (admin side).
 *
 * Generic layout-block editing: any template that renders a region through the core LayoutHelper emits
 * the data-customize-grid / data-customize-block contract this plugin consumes. It registers a single
 * "layout-block" area type so the engine's existing drag + Ctrl+arrow + remove machinery applies to the
 * position blocks themselves, and persists the resulting per-grid order (and hidden set) through
 * com_templates (JoomlaCustomize.templateAction), which writes it into the style's child template.
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
import JC from 'customize.api';

(function () {
  'use strict';

  const t = (key, fallback) => JC.text(key, fallback);

  // The grid container a block belongs to. Search from the block's PARENT, not the block itself: a
  // block can also BE a grid (e.g. the content column is a shell block and the "main" grid container),
  // and it must resolve to its containing grid, not to itself.
  function gridOf(block) {
    var from = block && block.parentElement;
    return from && from.closest ? from.closest('[data-customize-grid]') : null;
  }

  // The current top-to-bottom block order of a grid, read from its direct-child wrappers.
  function orderOf(grid) {
    return Array.prototype.map.call(
      grid.querySelectorAll(':scope > [data-customize-block]'),
      function (b) { return b.getAttribute('data-customize-block'); }
    );
  }

  // --- The generic block area type -------------------------------------------
  // The engine repositions the block in the DOM on drop / Ctrl+arrow, then calls onReorder. For a flow
  // grid the move is already visible, so we only persist. For a CSS grid (data-customize-grid-mode
  // ="grid"), the regions are pinned by grid-template-areas, so the DOM move alone changes nothing
  // visually; we reload so the template re-renders the grid in the saved order. onDelete hides the
  // block (kept in the manifest so it can be restored from the panel).
  JC.registerAreaType('layout-block', {
    label: t('PLG_CUSTOMIZE_LAYOUT_BLOCK', 'Block'),
    draggable: true,

    onReorder: function (info) {
      const grid = gridOf(info.dragged);

      if (!grid) {
        return;
      }

      const name = grid.getAttribute('data-customize-grid');
      const isCssGrid = grid.getAttribute('data-customize-grid-mode') === 'grid';

      JC.templateAction('saveArrangement', { grid: name, order: orderOf(grid) }).then(function (r) {
        if (r && r.success) {
          JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_LAYOUT_SAVED', 'Layout saved.'));

          if (isCssGrid) {
            // The visual order only updates once the template regenerates grid-template-areas.
            JC.reloadFrame();
          } else if (panel) {
            refreshHidden();
          }
        } else {
          JC.ui.toast(info.doc, (r && r.message) || t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
          // The DOM was reordered optimistically; resync with the server on failure.
          JC.reloadFrame();
        }
      }).catch(function () {
        JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
        JC.reloadFrame();
      });
    },

    onDelete: function (info) {
      const el = info.el;
      const doc = info.doc;

      if (!el.hasAttribute('data-customize-removable')) {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_LAYOUT_NOT_REMOVABLE', 'This block cannot be removed.'));
        return false;
      }

      const grid = gridOf(el);

      if (!grid) {
        return false;
      }

      const name = grid.getAttribute('data-customize-grid');
      const id = el.getAttribute('data-customize-block');

      // Merge with the stored hidden set (hidden blocks are not in the DOM), then save the remaining
      // order plus the extended hidden list.
      return JC.templateAction('listPositions', {}).then(function (r) {
        const g = (r && r.grids && r.grids[name]) || {};
        const hidden = (g.hidden || []).slice();

        if (hidden.indexOf(id) === -1) {
          hidden.push(id);
        }

        const order = orderOf(grid).filter(function (x) { return x !== id; });

        return JC.templateAction('saveArrangement', { grid: name, order: order, hidden: hidden }).then(function (s) {
          if (s && s.success) {
            JC.ui.toast(doc, t('PLG_CUSTOMIZE_LAYOUT_REMOVED', 'Block hidden.'));
            JC.reloadFrame();
            return true;
          }

          JC.ui.toast(doc, (s && s.message) || t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
          return false;
        });
      }).catch(function () {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
        return false;
      });
    }
  });

  // --- Split a position -------------------------------------------------------
  // A toolbar button on a module-position block: pick a layout, and the server divides the position
  // into N sub-positions (cols / ratios / 2x2), distributing its modules, then reloads. The helper
  // renders the block as a self-contained flex/grid row (no conflict with the template's own grid).
  const SPLIT_LAYOUTS = [
    { id: 'cols-2', label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT_COLUMNS', '%s columns').replace('%s', '2'), cells: [1, 1] },
    { id: 'cols-2-13', label: '33 / 67', cells: [1, 2] },
    { id: 'cols-2-31', label: '67 / 33', cells: [2, 1] },
    { id: 'cols-3', label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT_COLUMNS', '%s columns').replace('%s', '3'), cells: [1, 1, 1] },
    { id: 'cols-3-121', label: '25 / 50 / 25', cells: [1, 2, 1] },
    { id: 'cols-4', label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT_COLUMNS', '%s columns').replace('%s', '4'), cells: [1, 1, 1, 1] },
    { id: 'rows-2', label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT_ROWS', '%s rows').replace('%s', '2'), cells: [1, 1], rows: true },
    { id: 'grid-2x2', label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT_GRID', '2 × 2'), cells: [1, 1, 1, 1], grid: true }
  ];

  function openSplit(ctx) {
    const doc = ctx.doc;
    // Splits are keyed by position name, so a block (main column) and an outer position are split the
    // same way: no grid context is needed, just the position to divide.
    const block = ctx.el.getAttribute('data-customize-block');

    if (!block) {
      return;
    }

    let pop;

    const list = doc.createElement('div');
    list.className = 'customize-split-choices';

    SPLIT_LAYOUTS.forEach(function (lay) {
      const opt = doc.createElement('button');
      opt.type = 'button';
      opt.className = 'customize-split-choice';

      const diagram = doc.createElement('span');
      diagram.className = 'customize-split-diagram' + (lay.grid ? ' is-grid' : '') + (lay.rows ? ' is-rows' : '');
      lay.cells.forEach(function (w) {
        const cell = doc.createElement('span');
        cell.style.flexGrow = String(w);
        diagram.appendChild(cell);
      });

      const label = doc.createElement('span');
      label.className = 'customize-split-choice-label';
      label.textContent = lay.label;

      opt.appendChild(diagram);
      opt.appendChild(label);

      opt.addEventListener('click', function () {
        JC.templateAction('splitPosition', { block: block, layout: lay.id }).then(function (r) {
          if (r && r.success) {
            if (pop) {
              pop.close();
            }

            JC.reloadFrame();
          } else {
            JC.ui.toast(doc, (r && r.message) || t('PLG_CUSTOMIZE_LAYOUT_SPLIT_FAILED', 'Could not split the position.'));
          }
        }).catch(function () {
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_LAYOUT_SPLIT_FAILED', 'Could not split the position.'));
        });
      });

      list.appendChild(opt);
    });

    // Anchor to the toolbar (right where the Split button was clicked), not the position element: for a
    // tall region like a sidebar the element's bottom edge is far down the page, so the popover would
    // open out of view.
    const anchor = doc.querySelector('.customize-toolbar') || ctx.el;

    pop = JC.ui.popover(doc, {
      anchor: anchor,
      placement: 'below',
      className: 'customize-split-pop',
      ariaLabel: t('PLG_CUSTOMIZE_LAYOUT_SPLIT', 'Split'),
      content: [JC.ui.label(doc, t('PLG_CUSTOMIZE_LAYOUT_SPLIT', 'Split')), list]
    });
  }

  // Move a position block (or split cell) by picking a sibling in the same grid / split to swap places
  // with. A keyboard- and click-friendly alternative to drag/Ctrl+arrows: opened by the grip's
  // Enter/click and by the toolbar "Move" button. The reorder + persistence reuses the area type's own
  // onReorder (saveArrangement for a block, reorderSplit for a split cell).
  function openMove(block, doc) {
    const type = block.getAttribute('data-customize-type');
    const parent = block.parentElement;

    if (!parent) {
      return;
    }

    const peers = Array.prototype.filter.call(parent.children, function (c) {
      return c.getAttribute && c.getAttribute('data-customize-type') === type && c.hasAttribute('data-customize-block');
    });
    const others = peers.filter(function (p) { return p !== block; });

    if (!others.length) {
      JC.ui.toast(doc, t('PLG_CUSTOMIZE_LAYOUT_MOVE_NONE', 'There is no other position here to swap with.'));
      return;
    }

    const sel = doc.createElement('select');
    others.forEach(function (o) {
      const opt = doc.createElement('option');
      opt.value = o.getAttribute('data-customize-block') || '';
      opt.textContent = o.getAttribute('data-customize-name') || opt.value;
      sel.appendChild(opt);
    });

    JC.ui.popover(doc, {
      anchor: block,
      placement: 'below',
      ariaLabel: t('PLG_CUSTOMIZE_LAYOUT_MOVE', 'Move block'),
      saveOnEnter: true,
      content: [JC.ui.label(doc, t('PLG_CUSTOMIZE_LAYOUT_SWAP_WITH', 'Swap with')), sel],
      onSave: function (api) {
        const target = others.filter(function (o) {
          return (o.getAttribute('data-customize-block') || '') === sel.value;
        })[0];

        api.close();

        if (!target) {
          return;
        }

        // Swap block <-> target in the DOM, then let the area type persist the new order.
        const marker = doc.createComment('move');
        block.parentNode.insertBefore(marker, block);
        target.parentNode.insertBefore(block, target);
        marker.parentNode.insertBefore(target, marker);
        marker.parentNode.removeChild(marker);

        const def = JC.getAreaType(type);

        if (def && typeof def.onReorder === 'function') {
          def.onReorder({ dragged: block, target: target, doc: doc, type: type });
        }

        // Keep focus on the moved block (a flow grid stays put; a split cell reloads, detaching it).
        if (typeof JC.select === 'function' && block.isConnected) {
          JC.select(block);
        }
      }
    });
  }

  JC.registerButton('layout-block', {
    id: 'move',
    label: t('PLG_CUSTOMIZE_LAYOUT_MOVE', 'Move block'),
    order: 10,
    requires: 'movable',
    onClick: function (ctx) { openMove(ctx.el, ctx.doc); }
  });

  JC.registerButton('split-cell', {
    id: 'move',
    label: t('PLG_CUSTOMIZE_LAYOUT_MOVE', 'Move block'),
    order: 10,
    requires: 'split-owner',
    onClick: function (ctx) { openMove(ctx.el, ctx.doc); }
  });

  JC.registerButton('layout-block', {
    id: 'split',
    label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT', 'Split'),
    order: 50,
    requires: 'splittable',
    onClick: openSplit
  });

  // An outer module position (a region outside a reorderable grid, e.g. a sidebar or a top row) is
  // selectable so it can be split, but not draggable: reordering outer regions is template-grid
  // specific, whereas a split stays inside the region and so is generic. Same Split control.
  JC.registerAreaType('layout-position', {
    label: t('PLG_CUSTOMIZE_LAYOUT_POSITION', 'Position')
  });

  JC.registerButton('layout-position', {
    id: 'split',
    label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT', 'Split'),
    order: 50,
    requires: 'splittable',
    onClick: openSplit
  });

  // A cell of a split: draggable to reorder it among that split's other cells (the slots keep their
  // widths, so a drag moves a position into another slot). sameParentOnly keeps a drag inside its own
  // split; onReorder persists the new cell order, then reloads so the positional widths re-render.
  JC.registerAreaType('split-cell', {
    label: t('PLG_CUSTOMIZE_LAYOUT_POSITION', 'Position'),
    draggable: true,
    sameParentOnly: true,

    onReorder: function (info) {
      const cell = info.dragged;
      const owner = cell.getAttribute('data-customize-split-owner');
      const container = cell.parentElement;

      if (!owner || !container) {
        return;
      }

      const order = Array.prototype.map.call(
        container.querySelectorAll(':scope > [data-customize-block]'),
        function (c) { return c.getAttribute('data-customize-block'); }
      );

      JC.templateAction('reorderSplit', { owner: owner, order: order }).then(function (r) {
        if (r && r.success) {
          JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_LAYOUT_SAVED', 'Layout saved.'));
        } else {
          JC.ui.toast(info.doc, (r && r.message) || t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
        }

        // Reload either way: the cells were reordered optimistically, but the slot widths only update
        // once the template re-renders the split in the saved order.
        JC.reloadFrame();
      }).catch(function () {
        JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
        JC.reloadFrame();
      });
    }
  });

  JC.registerButton('split-cell', {
    id: 'split',
    label: t('PLG_CUSTOMIZE_LAYOUT_SPLIT', 'Split'),
    order: 50,
    requires: 'splittable',
    onClick: openSplit
  });

  // Undo a split from any of its cells: the server folds the sub-positions' modules back into the owner,
  // undeclares the extra positions, and drops the split (and nested splits), then we reload.
  function unsplit(ctx) {
    const owner = ctx.el.getAttribute('data-customize-split-owner');

    if (!owner) {
      return;
    }

    JC.templateAction('unsplitPosition', { block: owner }).then(function (r) {
      if (r && r.success) {
        JC.reloadFrame();
      } else {
        JC.ui.toast(ctx.doc, (r && r.message) || t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
      }
    }).catch(function () {
      JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
    });
  }

  // Every split cell carries data-customize-split-owner, so Unsplit is offered on all of them (it merges
  // the whole split back, not just one cell).
  JC.registerButton('split-cell', {
    id: 'unsplit',
    label: t('PLG_CUSTOMIZE_LAYOUT_UNSPLIT', 'Unsplit'),
    order: 60,
    requires: 'split-owner',
    onClick: unsplit
  });

  // --- Hidden-blocks panel (restore) -----------------------------------------
  // A small section in the admin panel listing blocks hidden in each grid, each with a Restore button,
  // so hiding is never a one-way action. Refreshed whenever the preview reloads.
  let panel = null;
  let listEl = null;

  function buildPanel() {
    const host = JC.panel && JC.panel();

    if (!host) {
      return;
    }

    const doc = window.document;
    panel = doc.createElement('div');
    panel.className = 'customize-layout-panel';
    panel.style.display = 'none';

    const heading = doc.createElement('div');
    heading.className = 'customize-field-label';
    heading.textContent = t('PLG_CUSTOMIZE_LAYOUT_HIDDEN_HEADING', 'Hidden blocks');

    listEl = doc.createElement('div');
    listEl.className = 'customize-layout-hidden';

    panel.appendChild(heading);
    panel.appendChild(listEl);
    host.appendChild(panel);
  }

  function restore(gridName, id, grid) {
    const hidden = (grid.hidden || []).filter(function (x) { return x !== id; });
    const order = (grid.order || []).filter(function (x) { return x !== id; });

    JC.templateAction('saveArrangement', { grid: gridName, order: order, hidden: hidden }).then(function (s) {
      if (s && s.success) {
        JC.reloadFrame();
      }
    });
  }

  function refreshHidden() {
    if (!panel) {
      return;
    }

    JC.templateAction('listPositions', {}).then(function (r) {
      const grids = (r && r.grids) || {};
      const doc = window.document;
      let any = false;

      listEl.textContent = '';

      Object.keys(grids).forEach(function (gridName) {
        (grids[gridName].hidden || []).forEach(function (id) {
          any = true;

          const row = doc.createElement('div');
          row.className = 'customize-layout-hidden-row';

          const label = doc.createElement('span');
          label.textContent = id;

          const btn = doc.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-sm btn-outline-secondary';
          btn.textContent = t('PLG_CUSTOMIZE_LAYOUT_RESTORE', 'Restore');
          btn.addEventListener('click', function () {
            restore(gridName, id, grids[gridName]);
          });

          row.appendChild(label);
          row.appendChild(btn);
          listEl.appendChild(row);
        });
      });

      panel.style.display = any ? '' : 'none';
    });
  }

  // --- Dedicated block grip --------------------------------------------------
  // A movable block often wraps a module (or other customize areas), and the engine selects the
  // innermost area on hover, so the block itself is hard to grab by mouse. Inject a small grip on each
  // movable block, wired through the engine's drag machinery, so grabbing the block is unambiguous:
  // dragging it reorders (or drops on the remove bar to hide), clicking it selects the block.
  function addGrips(doc) {
    if (typeof JC.makeDragHandle !== 'function') {
      return;
    }

    Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type="layout-block"][data-customize-movable], [data-customize-type="split-cell"]'), function (block) {
      if (block.querySelector(':scope > .customize-block-grip')) {
        return;
      }

      const grip = doc.createElement('button');
      grip.type = 'button';
      grip.className = 'customize-block-grip';
      grip.title = t('PLG_CUSTOMIZE_LAYOUT_MOVE', 'Move block');
      grip.setAttribute('aria-label', grip.title);
      grip.textContent = '⠿'; // braille drag dots

      block.insertBefore(grip, block.firstChild);
      JC.makeDragHandle(grip, block, doc);

      // Click or Enter on the grip (vs dragging it) opens the swap picker, so a position is movable by
      // keyboard, not only by drag. A real drag fires dragstart, not click, so the two never collide.
      grip.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        openMove(block, doc);
      });

      // An empty position has no rendered height, so it cannot be grabbed or used as a drop target.
      // Give it a stable visible slot NOW (not during a drag): growing a block when a drag starts
      // reflows the page and shifts the drag source, which aborts the native drag.
      if (block.offsetHeight < 12 && !block.querySelector(':scope > .customize-block-empty')) {
        const empty = doc.createElement('span');
        empty.className = 'customize-block-empty';
        empty.textContent = block.getAttribute('data-customize-name') || block.getAttribute('data-customize-block');
        block.appendChild(empty);
      }
    });
  }

  buildPanel();
  refreshHidden();

  // Wire a split's resize separator: drag it OR use the arrow keys to trade grow weight between the two
  // adjacent cells. The inline flex-grow is live immediately; the per-cell weights are persisted (on
  // release for a drag, debounced for the keyboard). Exposed as a WAI-ARIA window splitter so it is
  // keyboard-operable, not pointer-only.
  function wireSplitResize(doc, handle) {
    const win = doc.defaultView;
    const owner = handle.getAttribute('data-customize-split-owner');

    handle.setAttribute('role', 'separator');
    handle.setAttribute('aria-orientation', 'vertical');
    handle.setAttribute('tabindex', '0');
    handle.setAttribute('aria-valuemin', '0');
    handle.setAttribute('aria-valuemax', '100');
    handle.setAttribute('title', t('PLG_CUSTOMIZE_LAYOUT_RESIZE', 'Drag to resize'));
    handle.setAttribute('aria-label', handle.getAttribute('title'));

    const growOf = (el) => parseFloat(win.getComputedStyle(el).flexGrow) || 1;

    // The two adjacent cells this separator sits between, or null.
    function pair() {
      const cell = handle.closest('.customize-split-cell');
      const next = cell && cell.nextElementSibling;
      return (cell && next && next.classList.contains('customize-split-cell')) ? { cell, next } : null;
    }

    // Set the left cell's grow to `a` (the rest goes to the right cell) and mirror it on the ARIA value.
    function apply(p, a, total) {
      p.cell.style.flexGrow = String(a);
      p.next.style.flexGrow = String(total - a);
      const pct = Math.round((a / total) * 100);
      handle.setAttribute('aria-valuenow', String(pct));
      handle.setAttribute('aria-valuetext', pct + '% / ' + (100 - pct) + '%');
    }

    function persist(container) {
      const weights = Array.prototype.map.call(
        container.querySelectorAll(':scope > .customize-split-cell'),
        (c) => Math.round(growOf(c) * 1000) / 1000
      );

      JC.templateAction('resizeSplit', { owner: owner, weights: weights }).then(function (r) {
        if (r && r.success) {
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_LAYOUT_SAVED', 'Layout saved.'));
        } else {
          JC.ui.toast(doc, (r && r.message) || t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
        }
      }).catch(function () {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED', 'Could not save the layout.'));
      });
    }

    // Reflect the initial rendered ratio on the ARIA value.
    const start = pair();

    if (start) {
      apply(start, growOf(start.cell), growOf(start.cell) + growOf(start.next));
    }

    handle.addEventListener('pointerdown', function (down) {
      const p = pair();

      if (!p) {
        return;
      }

      down.preventDefault();
      down.stopPropagation();

      const startX = down.clientX;
      const combined = p.cell.getBoundingClientRect().width + p.next.getBoundingClientRect().width;
      const growA = growOf(p.cell);
      const total = growA + growOf(p.next);
      // Keep each cell at least ~48px wide so neither collapses out of reach.
      const minGrow = combined > 0 ? Math.min(total * 0.1, (48 / combined) * total) : total * 0.1;

      function onMove(move) {
        let a = growA + (combined > 0 ? ((move.clientX - startX) / combined) * total : 0);
        apply(p, Math.max(minGrow, Math.min(a, total - minGrow)), total);
      }

      function onUp() {
        win.removeEventListener('pointermove', onMove);
        win.removeEventListener('pointerup', onUp);
        persist(p.cell.parentElement);
      }

      win.addEventListener('pointermove', onMove);
      win.addEventListener('pointerup', onUp);
    });

    // Keyboard: Left/Right step 5%, Home/End to the limits; the save is debounced so a burst is one save.
    let saveTimer = null;

    handle.addEventListener('keydown', function (e) {
      if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) === -1) {
        return;
      }

      const p = pair();

      if (!p) {
        return;
      }

      e.preventDefault();

      const growA = growOf(p.cell);
      const total = growA + growOf(p.next);
      const step = total * 0.05;
      const minGrow = total * 0.1;
      let a = growA;

      if (e.key === 'ArrowLeft') {
        a = growA - step;
      } else if (e.key === 'ArrowRight') {
        a = growA + step;
      } else if (e.key === 'Home') {
        a = minGrow;
      } else if (e.key === 'End') {
        a = total - minGrow;
      }

      apply(p, Math.max(minGrow, Math.min(a, total - minGrow)), total);
      win.clearTimeout(saveTimer);
      saveTimer = win.setTimeout(() => persist(p.cell.parentElement), 350);
    });
  }

  JC.on('customize:frame-ready', function (e) {
    const doc = e.detail && e.detail.doc;

    if (doc) {
      addGrips(doc);
      Array.prototype.forEach.call(doc.querySelectorAll('.customize-split-resize'), function (handle) {
        wireSplitResize(doc, handle);
      });
    }

    refreshHidden();
  });
})();
