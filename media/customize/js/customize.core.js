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

    var overlay = null;
    var toolbar = null;
    var current = null;
    var editing = false;
    var dragEl = null;
    var dragType = null;

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
      overlay = doc.createElement('div');
      overlay.className = 'customize-area-outline';
      overlay.style.display = 'none';

      toolbar = doc.createElement('div');
      toolbar.className = 'customize-toolbar';
      toolbar.style.display = 'none';

      doc.body.appendChild(overlay);
      doc.body.appendChild(toolbar);
    }

    function hide() {
      current = null;
      if (overlay) { overlay.style.display = 'none'; }
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
      doc.body.classList.toggle('customize-dragging-active', on);

      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type="' + type + '"]'), function (el) {
        if (el !== dragEl) {
          el.classList.toggle('customize-droppable', on);
        }

        if (!on) {
          el.classList.remove('customize-drop-target');
        }
      });

      if (!on) {
        Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-dropzone]'), function (z) {
          z.classList.remove('customize-drop-target');
        });
      }
    }

    function makeDragHandle(handle, el, doc) {
      handle.setAttribute('draggable', 'true');
      handle.classList.add('customize-draggable');

      handle.addEventListener('dragstart', function (ev) {
        dragEl = el;
        dragType = el.getAttribute('data-customize-type');
        el.classList.add('customize-dragging');
        highlightDroppables(doc, dragType, true);
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
      });
    }

    function addDropTarget(doc, target, acceptType) {
      target.addEventListener('dragover', function (e) {
        if (dragEl && dragType === acceptType && dragEl !== target) {
          e.preventDefault();
          target.classList.add('customize-drop-target');
        }
      });

      target.addEventListener('dragleave', function () {
        target.classList.remove('customize-drop-target');
      });

      target.addEventListener('drop', function (e) {
        target.classList.remove('customize-drop-target');

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

    function setupSortable(doc) {
      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type], [data-customize-dropzone]'), function (target) {
        var acceptType = target.getAttribute('data-customize-dropzone') || target.getAttribute('data-customize-type');

        if (target.hasAttribute('data-customize-dropzone') || (JC.getAreaType(acceptType) || {}).draggable) {
          addDropTarget(doc, target, acceptType);
        }
      });
    }

    function showFor(el, doc) {
      current = el;
      var win = doc.defaultView;
      var rect = el.getBoundingClientRect();
      var top = rect.top + win.scrollY;
      var left = rect.left + win.scrollX;

      overlay.style.display = 'block';
      overlay.style.top = top + 'px';
      overlay.style.left = left + 'px';
      overlay.style.width = rect.width + 'px';
      overlay.style.height = rect.height + 'px';

      var type = el.getAttribute('data-customize-type');
      var name = el.getAttribute('data-customize-name') || '';
      var areaType = JC.getAreaType(type);

      toolbar.innerHTML = '';

      var label = doc.createElement('span');
      label.className = 'customize-toolbar-label';
      label.textContent = (areaType.label || type) + (name ? ' · ' + name : '');
      toolbar.appendChild(label);

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
        });
        actions.appendChild(btn);
      });

      toolbar.appendChild(actions);
      toolbar.style.display = 'flex';
      toolbar.style.top = top + 'px';
      toolbar.style.left = left + 'px';
    }

    function wire(doc) {
      buildChrome(doc);

      var areas = doc.querySelectorAll('[data-customize-type]');
      setStatus(JC.text('COM_MENUS_CUSTOMIZE_STATUS_ACTIVE', 'Customize mode active · %s editable area(s)').replace('%s', areas.length), true);

      doc.addEventListener('mouseover', function (event) {
        // Keep the toolbar visible while the pointer is on it.
        if (toolbar && (event.target === toolbar || toolbar.contains(event.target))) {
          return;
        }

        if (editing) {
          return;
        }

        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (el) {
          if (el !== current) {
            showFor(el, doc);
          }
        } else {
          hide();
        }
      });

      JC.emit('customize:frame-ready', { doc: doc, areas: areas.length });

      // Wire drag-to-reorder after plugins have tagged their areas/zones.
      setupSortable(doc);
    }

    frame.addEventListener('load', function () {
      overlay = null;
      toolbar = null;
      current = null;

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

    // While an inline editor is open, hide the hover outline and suppress re-showing it.
    JC.on('customize:edit-start', function () {
      editing = true;
      hide();
    });

    JC.on('customize:edit-end', function () {
      editing = false;
    });
  });
}(window, document));
