/**
 * Joomla Customize - overlay engine.
 *
 * Runs in the admin Customize host page and drives the same-origin frontend iframe directly:
 * on each iframe load it injects the overlay stylesheet, scans for [data-customize-type] areas,
 * and wires hover -> outline + toolbar (type/name on the left, registered buttons on the right).
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
(function (window, document) {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var JC = window.JoomlaCustomize;
    var opts = (window.Joomla && window.Joomla.getOptions) ? (window.Joomla.getOptions('customize') || {}) : {};
    var frame = document.getElementById(opts.frameId || 'customize-frame');
    var statusEl = document.getElementById('customize-status');

    if (!frame || !JC) {
      return;
    }

    var toolbar = null;
    var srStatus = null;
    var current = null;
    var editing = false;
    // The element kept selected (outline + toolbar) while the user interacts with it (after a button
    // click or double-click), so it stays put when the pointer moves away. Cleared by a click outside.
    var pinned = null;
    var dragEl = null;
    var dragType = null;
    var externalDrag = null;
    var removeBar = null;
    var pendingDelete = null;

    function setStatus(text, ok) {
      if (statusEl) {
        statusEl.textContent = text;
        statusEl.classList.toggle('is-ok', !!ok);
        statusEl.classList.toggle('is-warn', !ok);
      }
    }

    function frameDoc() {
      try {
        return frame.contentDocument || frame.contentWindow.document;
      } catch (e) {
        return null;
      }
    }

    function injectFrameCss(doc) {
      if (!opts.frameCss || doc.getElementById('customize-frame-css')) {
        return;
      }

      var link = doc.createElement('link');
      link.id = 'customize-frame-css';
      link.rel = 'stylesheet';
      link.href = opts.frameCss;
      (doc.head || doc.documentElement).appendChild(link);
    }

    function buildChrome(doc) {
      toolbar = doc.createElement('div');
      toolbar.className = 'customize-toolbar';
      toolbar.style.display = 'none';

      // A visually hidden live region so screen readers hear which area was entered.
      srStatus = doc.createElement('div');
      srStatus.className = 'customize-sr-status';
      srStatus.setAttribute('aria-live', 'polite');
      srStatus.setAttribute('aria-atomic', 'true');

      doc.body.appendChild(toolbar);
      doc.body.appendChild(srStatus);
    }

    function hide() {
      if (current) { current.classList.remove('customize-area-active'); }
      current = null;
      if (toolbar) { toolbar.style.display = 'none'; }
    }

    function dataset(el) {
      var data = {};
      for (var i = 0; i < el.attributes.length; i++) {
        var attr = el.attributes[i];
        if (attr.name.indexOf('data-customize-') === 0) {
          data[attr.name.slice('data-customize-'.length)] = attr.value;
        }
      }
      return data;
    }

    // --- Generic drag-to-reorder ------------------------------------------------
    // An area type marked { draggable: true } is dragged by its toolbar title. Other elements of
    // the same type (and any [data-customize-dropzone="<type>"] zones) become drop targets; the
    // engine repositions the element in the DOM on drop and calls the type's onReorder(info) so the
    // owning plugin can persist the change. This is shared so future draggable things reuse it.

    function highlightDroppables(doc, type, on) {
      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type="' + type + '"]'), function (el) {
        if (el !== dragEl) {
          el.classList.toggle('customize-droppable', on);
        }

        if (!on) {
          el.classList.remove('customize-drop-target');
        }
      });

      // Reveal only the drop zones that accept the dragged type (e.g. module zones for a module drag,
      // not for a field drag).
      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-dropzone="' + type + '"]'), function (z) {
        z.classList.toggle('customize-dropzone-active', on);

        if (!on) {
          z.classList.remove('customize-drop-target');
        }
      });
    }

    // A red "remove" bar shown at the top while dragging an area type whose definition provides an
    // onDelete callback. Dropping the element on it greys the element and shows an inline confirm;
    // confirming calls onDelete({el, doc}) so the owning plugin can delete it. Shared by modules,
    // menu items and any future deletable type.
    function cancelRemove() {
      if (pendingDelete) {
        pendingDelete.classList.remove('customize-pending');
      }
      pendingDelete = null;

      if (removeBar && removeBar.parentNode) {
        removeBar.parentNode.removeChild(removeBar);
      }
      removeBar = null;
    }

    function showRemoveConfirm(doc, bar, areaType) {
      bar.textContent = '';
      bar.classList.add('customize-sticky-form');

      var name = pendingDelete.getAttribute('data-customize-name') || '';
      var label = doc.createElement('span');
      label.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE_CONFIRM', 'Remove %s?').replace('%s', name);

      var del = doc.createElement('button');
      del.type = 'button';
      del.className = 'customize-action customize-action-danger';
      del.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE', 'Remove');

      var cancel = doc.createElement('button');
      cancel.type = 'button';
      cancel.className = 'customize-action customize-action-cancel';
      cancel.textContent = JC.text('COM_MENUS_CUSTOMIZE_CANCEL', 'Cancel');

      bar.appendChild(label);
      bar.appendChild(del);
      bar.appendChild(cancel);

      cancel.addEventListener('click', cancelRemove);

      del.addEventListener('click', function () {
        var target = pendingDelete;
        del.disabled = true;
        del.textContent = JC.text('COM_MENUS_CUSTOMIZE_SAVING', 'Saving…');

        Promise.resolve(areaType.onDelete({ el: target, doc: doc })).then(function (ok) {
          if (ok === false) {
            del.disabled = false;
            del.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE', 'Remove');
          } else {
            // The plugin reloads the iframe on success; just drop our references.
            pendingDelete = null;
            removeBar = null;
          }
        });
      });
    }

    function makeRemoveBar(doc, areaType) {
      var bar = doc.createElement('div');
      bar.className = 'customize-sticky-zone customize-sticky-zone-top customize-sticky-danger';
      bar.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE_HINT', 'Drop here to remove');

      bar.addEventListener('dragover', function (e) {
        if (dragEl) {
          e.preventDefault();
          bar.classList.add('customize-sticky-over');
        }
      });

      bar.addEventListener('dragleave', function () {
        bar.classList.remove('customize-sticky-over');
      });

      bar.addEventListener('drop', function (e) {
        if (!dragEl) {
          return;
        }
        e.preventDefault();
        bar.classList.remove('customize-sticky-over');
        pendingDelete = dragEl;
        pendingDelete.classList.add('customize-pending');
        showRemoveConfirm(doc, bar, areaType);
      });

      doc.body.appendChild(bar);
      return bar;
    }

    function makeDragHandle(handle, el, doc) {
      handle.setAttribute('draggable', 'true');
      handle.classList.add('customize-draggable');

      handle.addEventListener('dragstart', function (ev) {
        dragEl = el;
        dragType = el.getAttribute('data-customize-type');
        el.classList.add('customize-dragging');
        highlightDroppables(doc, dragType, true);

        var def = JC.getAreaType(dragType);
        if (def && typeof def.onDelete === 'function') {
          removeBar = makeRemoveBar(doc, def);
        }

        try {
          ev.dataTransfer.effectAllowed = 'move';
          ev.dataTransfer.setData('text/plain', el.getAttribute('data-customize-id') || '');
        } catch (e) {
          // some browsers restrict dataTransfer
        }
        JC.emit('customize:drag-start', { el: el, doc: doc });
      });

      handle.addEventListener('dragend', function () {
        el.classList.remove('customize-dragging');
        highlightDroppables(doc, dragType, false);
        JC.emit('customize:drag-end', { el: el });
        dragEl = null;
        dragType = null;

        // Keep the remove bar only if the element was dropped on it (a confirm is showing).
        if (!pendingDelete && removeBar) {
          if (removeBar.parentNode) {
            removeBar.parentNode.removeChild(removeBar);
          }
          removeBar = null;
        }
      });
    }

    function addDropTarget(doc, target, acceptType) {
      target.addEventListener('dragover', function (e) {
        var reorder = dragEl && dragType === acceptType && dragEl !== target;
        var external = externalDrag && externalDrag.type === acceptType;

        if (reorder || external) {
          e.preventDefault();
          target.classList.add('customize-drop-target');
        }
      });

      target.addEventListener('dragleave', function () {
        target.classList.remove('customize-drop-target');
      });

      target.addEventListener('drop', function (e) {
        target.classList.remove('customize-drop-target');

        // An external source (e.g. the Add-module bar) dropping a brand new element of this type.
        if (externalDrag && externalDrag.type === acceptType) {
          e.preventDefault();
          externalDrag.onDrop({ target: target, payload: externalDrag.payload, doc: doc });
          return;
        }

        if (!dragEl || dragType !== acceptType || dragEl === target) {
          return;
        }

        e.preventDefault();

        if (target.hasAttribute('data-customize-dropzone')) {
          target.parentNode.insertBefore(dragEl, target);
        } else {
          var rect = target.getBoundingClientRect();
          var after = (e.clientY - rect.top) > (rect.height / 2);
          target.parentNode.insertBefore(dragEl, after ? target.nextSibling : target);
        }

        var def = JC.getAreaType(dragType);

        if (def && typeof def.onReorder === 'function') {
          def.onReorder({ dragged: dragEl, target: target, doc: doc, type: dragType });
        }
      });
    }

    // Let a control outside the iframe (e.g. the Add-module bar in the panel) drag a brand new
    // element of `type` onto the page. Highlights the same drop targets as a reorder; onDrop is
    // called with the chosen target so the caller can create the element there.
    JC.beginExternalDrag = function (type, payload, onDrop) {
      externalDrag = { type: type, payload: payload, onDrop: onDrop };
      dragType = type;
      var doc = frameDoc();

      if (doc) {
        highlightDroppables(doc, type, true);
      }
    };

    JC.endExternalDrag = function () {
      var doc = frameDoc();

      if (doc && externalDrag) {
        highlightDroppables(doc, externalDrag.type, false);
      }

      externalDrag = null;
      dragType = null;
    };

    function setupSortable(doc) {
      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type], [data-customize-dropzone]'), function (target) {
        var acceptType = target.getAttribute('data-customize-dropzone') || target.getAttribute('data-customize-type');

        if (target.hasAttribute('data-customize-dropzone') || (JC.getAreaType(acceptType) || {}).draggable) {
          addDropTarget(doc, target, acceptType);
        }
      });
    }

    // Position the toolbar at the element's top-left. The element's own outline (a CSS class on it)
    // tracks size changes by itself, so only the floating toolbar needs placing.
    function positionFor(el, doc) {
      var win = doc.defaultView;
      var rect = el.getBoundingClientRect();

      toolbar.style.top = (rect.top + win.scrollY) + 'px';
      toolbar.style.left = (rect.left + win.scrollX) + 'px';
    }

    function showFor(el, doc) {
      // Outline the element via a class on itself; the browser tracks its box, so the outline follows
      // size changes (e.g. an inline editor growing the element) with no scripting.
      if (current && current !== el) {
        current.classList.remove('customize-area-active');
      }

      current = el;
      el.classList.add('customize-area-active');

      var type = el.getAttribute('data-customize-type');
      var name = el.getAttribute('data-customize-name') || '';
      var areaType = JC.getAreaType(type);

      toolbar.innerHTML = '';

      var label = doc.createElement('span');
      label.className = 'customize-toolbar-label';
      label.textContent = (areaType.label || type) + (name ? ' · ' + name : '');

      // A plugin can flag an element (e.g. a view block that already has a template override) to show
      // a small cue after the title.
      var cue = el.getAttribute('data-customize-cue');

      if (cue) {
        var cueEl = doc.createElement('span');
        cueEl.className = 'customize-toolbar-cue';
        cueEl.textContent = cue;
        label.appendChild(cueEl);
      }

      // Draggable area types (e.g. modules) use the toolbar title as the drag handle, leaving the
      // element body free for editing its contents. The owning plugin handles the drop and save.
      if (areaType.draggable) {
        makeDragHandle(label, el, doc);
      }

      var actions = doc.createElement('span');
      actions.className = 'customize-toolbar-actions';

      JC.getButtons(type).forEach(function (button) {
        // A button may require a data flag on the element (e.g. requires:'props' -> data-customize-props).
        if (button.requires && !el.hasAttribute('data-customize-' + button.requires)) {
          return;
        }

        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'customize-btn';
        btn.textContent = button.label || button.id;
        btn.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();

          if (typeof button.onClick === 'function') {
            button.onClick({
              el: el,
              doc: doc,
              type: type,
              name: name,
              data: dataset(el),
              callAction: JC.callAction,
              emit: JC.emit
            });
          }

          // Keep this element selected while the user works with it (cleared by a click elsewhere).
          pinned = el;
        });
        actions.appendChild(btn);
      });

      // Order: action buttons first (left), then the title/drag handle (right), so the buttons are
      // easy to reach even on narrow elements like a short translated string.
      toolbar.appendChild(actions);
      toolbar.appendChild(label);
      toolbar.style.display = 'flex';
      positionFor(el, doc);
    }

    // Run an element's primary (first applicable) button action, and select it. Shared by
    // double-click and the keyboard Enter/Space activation.
    function triggerPrimary(el, doc) {
      var type = el.getAttribute('data-customize-type');
      var buttons = JC.getButtons(type);
      var primary = null;

      for (var i = 0; i < buttons.length; i++) {
        if (!buttons[i].requires || el.hasAttribute('data-customize-' + buttons[i].requires)) {
          primary = buttons[i];
          break;
        }
      }

      if (!primary || typeof primary.onClick !== 'function') {
        return;
      }

      if (current !== el) {
        showFor(el, doc);
      }

      pinned = el;
      primary.onClick({
        el: el,
        doc: doc,
        type: type,
        name: el.getAttribute('data-customize-name') || '',
        data: dataset(el),
        callAction: JC.callAction,
        emit: JC.emit
      });
    }

    // Carry customize=1 across in-iframe navigation (same-origin links and forms), so the mode
    // persists without making it sticky in the session (which would leak into normal browsing).
    function carryCustomize(doc) {
      var host = doc.location.host;

      Array.prototype.forEach.call(doc.querySelectorAll('a[href]'), function (a) {
        if (a.host !== host) {
          return;
        }

        var href = a.getAttribute('href');

        if (!href || href.charAt(0) === '#' || a.search.indexOf('customize=1') !== -1) {
          return;
        }

        a.search = (a.search ? a.search + '&' : '?') + 'customize=1';
      });

      Array.prototype.forEach.call(doc.querySelectorAll('form'), function (form) {
        if (form.querySelector('input[name="customize"]')) {
          return;
        }

        var input = doc.createElement('input');
        input.type = 'hidden';
        input.name = 'customize';
        input.value = '1';
        form.appendChild(input);
      });
    }

    function wire(doc) {
      buildChrome(doc);
      carryCustomize(doc);

      var areas = doc.querySelectorAll('[data-customize-type]');
      setStatus(JC.text('COM_MENUS_CUSTOMIZE_STATUS_ACTIVE', 'Customize mode active · %s editable area(s)').replace('%s', areas.length), true);

      var win = doc.defaultView;
      var hideTimer = null;

      function cancelHide() {
        if (hideTimer) {
          win.clearTimeout(hideTimer);
          hideTimer = null;
        }
      }

      // Hide on a short delay so moving from an element to its toolbar (the drag handle) across a
      // small gap doesn't dismiss it mid-grab.
      function scheduleHide() {
        cancelHide();
        hideTimer = win.setTimeout(hide, 250);
      }

      doc.addEventListener('mouseover', function (event) {
        // Keep the toolbar visible while the pointer is on it.
        if (toolbar && (event.target === toolbar || toolbar.contains(event.target))) {
          cancelHide();
          return;
        }

        // While editing, or with an element pinned/selected, don't follow the hover elsewhere.
        if (editing || pinned) {
          return;
        }

        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (el) {
          cancelHide();

          if (el !== current) {
            showFor(el, doc);
          }
        } else {
          scheduleHide();
        }
      });

      // A click outside the selected element (and its toolbar / editor UI) clears the selection.
      // Not while an inline editor is open: the user finishes that with Enter/Escape.
      doc.addEventListener('click', function (event) {
        if (!pinned || editing) {
          return;
        }

        var t = event.target;

        if (pinned.contains(t)
          || (toolbar && toolbar.contains(t))
          || (t.closest && t.closest('.customize-popover, .customize-inline-bar, .customize-sticky-zone'))) {
          return;
        }

        pinned = null;
        var el = t.closest ? t.closest('[data-customize-type]') : null;

        if (el) {
          showFor(el, doc);
        } else {
          hide();
        }
      });

      // Double-click an element to trigger its primary action (the first applicable button).
      doc.addEventListener('dblclick', function (event) {
        if (editing) {
          return;
        }

        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (el) {
          event.preventDefault();
          triggerPrimary(el, doc);
        }
      });

      JC.emit('customize:frame-ready', { doc: doc, areas: areas.length });

      // After plugins have tagged their areas/zones: wire keyboard access and drag-to-reorder.
      setupKeyboard(doc);
      setupSortable(doc);
    }

    // Keyboard access: make the areas reachable and operable without a mouse. A roving tabindex keeps
    // the page to a single Tab stop; arrows move between areas; focusing an area selects it (parity
    // with hover); Enter/Space runs its primary action; Tab reaches the toolbar; Escape deselects.
    // The area set is read live (plugins tag some areas, e.g. translatable strings, after load) and
    // each area is made focusable lazily, so late-added areas are reachable too.
    function setupKeyboard(doc) {
      var tabStop = null;

      // A human label for an area: its type label plus the item name, e.g. "Layout block item".
      function describe(el) {
        var type = el.getAttribute('data-customize-type');
        var name = el.getAttribute('data-customize-name') || '';
        var areaType = JC.getAreaType(type) || {};
        return (areaType.label || type) + (name ? ' ' + name : '');
      }

      function isDraggable(el) {
        return !!((JC.getAreaType(el.getAttribute('data-customize-type')) || {}).draggable);
      }

      function ensureArea(el) {
        if (!el.hasAttribute('tabindex')) {
          el.setAttribute('tabindex', '-1');
        }

        // Announce each area as a named, activatable "Customize area" (role=group is valid when areas
        // nest, e.g. a layout block containing editable text).
        if (!el.getAttribute('role')) {
          el.setAttribute('role', 'group');
        }

        if (!el.getAttribute('aria-roledescription')) {
          el.setAttribute('aria-roledescription', JC.text('COM_MENUS_CUSTOMIZE_AREA_ROLEDESCRIPTION', 'Customize area'));
        }

        if (!el.getAttribute('aria-keyshortcuts')) {
          // Draggable areas can also be reordered from the keyboard (the alternative to drag-and-drop).
          el.setAttribute('aria-keyshortcuts', isDraggable(el) ? 'Enter Control+ArrowUp Control+ArrowDown' : 'Enter');
        }

        if (!el.getAttribute('aria-label')) {
          el.setAttribute('aria-label', describe(el));
        }
      }

      // Tell screen readers which area was entered (and how to edit/move it) via the live region.
      function announce(el) {
        if (!srStatus) {
          return;
        }

        var msg = describe(el) + '. ' + JC.text('COM_MENUS_CUSTOMIZE_AREA_HINT', 'Press Enter to edit');

        if (isDraggable(el)) {
          msg += '. ' + JC.text('COM_MENUS_CUSTOMIZE_AREA_MOVE_HINT', 'Hold Ctrl and press Up or Down to move');
        }

        if (srStatus.textContent !== msg) {
          srStatus.textContent = msg;
        }
      }

      // Move a draggable block among its same-type peers in the same container (keyboard equivalent of
      // drag-and-drop), persist via the type's onReorder, keep focus on it, and announce the new spot.
      function moveBlock(el, dir) {
        var type = el.getAttribute('data-customize-type');
        var position = el.getAttribute('data-customize-position');
        var parent = el.parentNode;

        function peers() {
          return Array.prototype.filter.call(parent.children, function (c) {
            if (!c.getAttribute || c.getAttribute('data-customize-type') !== type) {
              return false;
            }

            return !position || c.getAttribute('data-customize-position') === position;
          });
        }

        var siblings = peers();
        var swap = siblings[siblings.indexOf(el) + dir];

        if (!swap) {
          return;
        }

        parent.insertBefore(el, dir < 0 ? swap : swap.nextSibling);

        var def = JC.getAreaType(type);

        if (def && typeof def.onReorder === 'function') {
          def.onReorder({ dragged: el, target: el, doc: doc, type: type });
        }

        el.focus();
        showFor(el, doc);

        if (srStatus) {
          var now = peers();
          var tmpl = JC.text('COM_MENUS_CUSTOMIZE_AREA_MOVED', 'Moved to position %1$s of %2$s');
          srStatus.textContent = describe(el) + '. ' + tmpl.replace('%1$s', now.indexOf(el) + 1).replace('%2$s', now.length);
        }
      }

      function setTabStop(el) {
        if (tabStop && tabStop !== el) {
          tabStop.setAttribute('tabindex', '-1');
        }

        el.setAttribute('tabindex', '0');
        tabStop = el;
      }

      function areaList() {
        return Array.prototype.slice.call(doc.querySelectorAll('[data-customize-type]'));
      }

      // Move to and select an area (explicitly, not relying on focusin, which some hosts don't fire on
      // a programmatic focus()).
      function select(el) {
        ensureArea(el);
        setTabStop(el);
        el.focus();
        showFor(el, doc);
        pinned = el;
        announce(el);
      }

      var initial = areaList();
      initial.forEach(ensureArea);

      if (initial.length) {
        setTabStop(initial[0]);
      }

      // Focusing an area (Tab or click) selects it and keeps it selected so the toolbar is reachable.
      doc.addEventListener('focusin', function (event) {
        if (editing) {
          return;
        }

        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (el) {
          ensureArea(el);
          setTabStop(el);
          showFor(el, doc);
          pinned = el;
          announce(el);
        }
      });

      // Clear the selection when focus leaves the areas and the toolbar entirely (e.g. tabbing out).
      doc.addEventListener('focusout', function (event) {
        if (editing) {
          return;
        }

        var to = event.relatedTarget;
        var inArea = to && to.closest && to.closest('[data-customize-type]');
        var inToolbar = to && toolbar && toolbar.contains(to);

        if (!inArea && !inToolbar && pinned) {
          pinned = null;
          hide();
        }
      });

      doc.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          // An open editor cancels itself on Escape; only deselect when focus is on a bare area/toolbar.
          if (editing) {
            return;
          }

          if (toolbar && toolbar.contains(event.target) && current) {
            event.preventDefault();
            current.focus();
          } else {
            var area = event.target.closest && event.target.closest('[data-customize-type]');

            if (area && event.target === area) {
              pinned = null;
              hide();
              area.blur();
            }
          }

          return;
        }

        // Navigation/activation only when focus is on the area element itself (not an editor inside it).
        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (!el || event.target !== el || editing) {
          return;
        }

        // Ctrl+Up/Down reorders a draggable block (the keyboard alternative to drag-and-drop).
        if (event.ctrlKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
          if (isDraggable(el)) {
            event.preventDefault();
            moveBlock(el, event.key === 'ArrowUp' ? -1 : 1);
          }

          return;
        }

        var list = areaList();
        var idx = list.indexOf(el);

        if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
          event.preventDefault();

          if (list.length) {
            select(list[(idx + 1) % list.length]);
          }
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
          event.preventDefault();

          if (list.length) {
            select(list[(idx - 1 + list.length) % list.length]);
          }
        } else if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          triggerPrimary(el, doc);
        } else if (event.key === 'Tab' && !event.shiftKey) {
          var firstBtn = toolbar && toolbar.querySelector('.customize-btn');

          if (firstBtn) {
            event.preventDefault();
            firstBtn.focus();
          }
        }
      });
    }

    frame.addEventListener('load', function () {
      toolbar = null;
      srStatus = null;
      current = null;
      // Any in-progress edit/selection is gone with the old document; clear the flags so hover works
      // again (e.g. when a plugin reloads the frame to apply a save).
      editing = false;
      pinned = null;

      var doc = frameDoc();

      if (!doc) {
        setStatus(JC.text('COM_MENUS_CUSTOMIZE_STATUS_CROSS_ORIGIN', 'Cross-origin frontend: live editing needs the postMessage fallback.'), false);
        return;
      }

      var active = doc.querySelector('meta[name="customize-mode"]')
        || (frame.contentWindow && frame.contentWindow.JoomlaCustomizeFrame);

      if (!active) {
        setStatus(JC.text('COM_MENUS_CUSTOMIZE_STATUS_INACTIVE', 'Frontend loaded, but customize mode is not active on the page.'), false);
        return;
      }

      injectFrameCss(doc);
      wire(doc);
    });

    // While an inline editor is open, suppress hover-following but keep the element's outline + bar
    // (it stays selected). The selection is cleared by a click elsewhere.
    JC.on('customize:edit-start', function () {
      editing = true;
    });

    JC.on('customize:edit-end', function () {
      editing = false;
    });
  });
}(window, document));
