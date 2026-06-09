/**
 * Customize - Content plugin (admin side).
 *
 * Loaded in the com_menus Customize host page. The article body is marked server-side as a
 * "content" area; on frame-ready we also mark the title, image and details block as their own
 * areas, so each element shows a single "Edit" button. Shared UI helpers come from JoomlaCustomize.ui
 * and styling from media/customize/css/customize-frame.css; strings come from Joomla.Text.
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

  function failMessage(res) {
    var reason = (res && res.message) || t('PLG_CUSTOMIZE_CONTENT_UNKNOWN_ERROR', 'unknown error');
    return t('PLG_CUSTOMIZE_CONTENT_SAVE_FAILED', 'Save failed: %s').replace('%s', reason);
  }

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

  // --- Body (WYSIWYG) ---------------------------------------------------------

  function editBody(ctx) {
    if (ctx.data.field !== 'introtext') {
      JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_CONTENT_FULLTEXT_NOTICE', 'This article shows full text. Edit it from the administrator.'));
      return;
    }

    JC.editInline(ctx, {
      html: ctx.el.innerHTML,
      save: function (content) {
        return ctx.callAction('content', 'save', { id: ctx.data.id, field: 'introtext', html: content })
          .then(function (res) {
            if (!res || !res.success) {
              JC.ui.toast(ctx.doc, failMessage(res));
            }

            return { success: !!(res && res.success), html: res && res.html };
          });
      }
    });
  }

  // --- Title (in place, plain text) ------------------------------------------

  function editTitle(ctx) {
    var doc = ctx.doc;
    var titleEl = ctx.el;

    if (titleEl.getAttribute('data-customize-editing') === '1') {
      return;
    }

    titleEl.setAttribute('data-customize-editing', '1');
    JC.emit('customize:edit-start');

    var target = titleEl.querySelector('a') || titleEl;
    var original = target.textContent;
    target.setAttribute('contenteditable', 'true');
    target.classList.add('customize-editing');
    target.focus();

    var bar = JC.ui.makeBar(doc);
    titleEl.parentNode.insertBefore(bar.el, titleEl.nextSibling);

    function teardown() {
      target.removeAttribute('contenteditable');
      target.classList.remove('customize-editing');
      target.removeEventListener('keydown', onKey);
      titleEl.removeAttribute('data-customize-editing');
      JC.emit('customize:edit-end');
      if (bar.el.parentNode) {
        bar.el.parentNode.removeChild(bar.el);
      }
    }

    function cancel() {
      target.textContent = original;
      teardown();
    }

    function save() {
      var value = target.textContent.trim();

      if (!value) {
        return;
      }

      JC.ui.saving(bar.save);

      ctx.callAction('content', 'save', { id: ctx.data.id, field: 'title', html: value })
        .then(function (res) {
          if (res && res.success) {
            target.textContent = (res.html != null) ? res.html : value;
            teardown();
            JC.ui.toast(doc, t('PLG_CUSTOMIZE_CONTENT_SAVED', 'Saved.'));
          } else {
            JC.ui.resetSave(bar.save);
            JC.ui.toast(doc, failMessage(res));
          }
        })
        .catch(function () {
          JC.ui.resetSave(bar.save);
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_CONTENT_SAVE_ERROR', 'Save error.'));
        });
    }

    function onKey(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        save();
      } else if (e.key === 'Escape') {
        cancel();
      }
    }

    target.addEventListener('keydown', onKey);
    bar.save.addEventListener('click', save);
    bar.cancel.addEventListener('click', cancel);
  }

  // --- Image (native media picker) -------------------------------------------

  function editImage(ctx) {
    var host = window.document.getElementById('customize-media-host');
    var field = host ? host.querySelector('joomla-field-media') : null;

    if (!field) {
      JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_CONTENT_MEDIA_UNAVAILABLE', 'Media field unavailable.'));
      return;
    }

    var input = field.querySelector('input');

    function onChange() {
      field.removeEventListener('change', onChange);
      var url = input ? input.value : '';

      if (!url) {
        return;
      }

      ctx.callAction('content', 'saveimage', { id: ctx.data.id, target: 'intro', url: url })
        .then(function (res) {
          if (res && res.success) {
            JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_CONTENT_IMAGE_SAVED', 'Image saved.'));
            reloadFrame();
          } else {
            JC.ui.toast(ctx.doc, failMessage(res));
          }
        })
        .catch(function () {
          JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_CONTENT_SAVE_ERROR', 'Save error.'));
        });
    }

    field.addEventListener('change', onChange);

    if (typeof field.show === 'function') {
      field.show();
    } else {
      var btn = field.querySelector('button');
      if (btn) {
        btn.click();
      }
    }
  }

  // --- Properties (popover on the details block) -----------------------------

  function editProps(ctx) {
    ctx.callAction('content', 'load', { id: ctx.data.id }).then(function (res) {
      if (!res || !res.success) {
        JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_CONTENT_PROPS_LOAD_FAILED', 'Could not load properties.'));
        return;
      }

      showPropsPopover(ctx, res);
    });
  }

  function showPropsPopover(ctx, data) {
    var doc = ctx.doc;
    var existing = doc.getElementById('customize-props');
    if (existing) {
      existing.remove();
    }

    var box = doc.createElement('div');
    box.id = 'customize-props';
    box.className = 'customize-popover';

    var rect = ctx.el.getBoundingClientRect();
    var win = doc.defaultView;
    box.style.top = (rect.top + win.scrollY) + 'px';
    box.style.left = (rect.left + win.scrollX) + 'px';

    var catSel = doc.createElement('select');
    (data.categories || []).forEach(function (c) {
      var o = doc.createElement('option');
      o.value = c.id;
      o.textContent = c.title;
      if (c.id === data.catid) {
        o.selected = true;
      }
      catSel.appendChild(o);
    });

    var stateSel = doc.createElement('select');
    [
      { v: 1, t: t('PLG_CUSTOMIZE_CONTENT_PUBLISHED', 'Published') },
      { v: 0, t: t('PLG_CUSTOMIZE_CONTENT_UNPUBLISHED', 'Unpublished') },
      { v: 2, t: t('PLG_CUSTOMIZE_CONTENT_ARCHIVED', 'Archived') }
    ].forEach(function (s) {
      var o = doc.createElement('option');
      o.value = s.v;
      o.textContent = s.t;
      if (s.v === data.state) {
        o.selected = true;
      }
      stateSel.appendChild(o);
    });

    var featWrap = doc.createElement('label');
    featWrap.className = 'customize-check';
    var feat = doc.createElement('input');
    feat.type = 'checkbox';
    feat.checked = !!data.featured;
    featWrap.appendChild(feat);
    featWrap.appendChild(doc.createTextNode(t('PLG_CUSTOMIZE_CONTENT_FEATURED', 'Featured')));

    // Inline "create new category": parent selector + name input.
    var newCatLink = doc.createElement('button');
    newCatLink.type = 'button';
    newCatLink.className = 'customize-link';
    newCatLink.textContent = t('PLG_CUSTOMIZE_CONTENT_NEW_CATEGORY', '+ New category');

    var newCatWrap = doc.createElement('div');
    newCatWrap.style.display = 'none';

    var parentSel = doc.createElement('select');
    var topOpt = doc.createElement('option');
    topOpt.value = '1';
    topOpt.textContent = t('PLG_CUSTOMIZE_CONTENT_TOP_LEVEL', '- Top level -');
    parentSel.appendChild(topOpt);
    (data.categories || []).forEach(function (c) {
      var o = doc.createElement('option');
      o.value = c.id;
      o.textContent = c.title;
      parentSel.appendChild(o);
    });

    var nameInput = doc.createElement('input');
    nameInput.type = 'text';
    nameInput.placeholder = t('PLG_CUSTOMIZE_CONTENT_NEW_CATEGORY_NAME', 'New category name');

    newCatWrap.appendChild(JC.ui.label(doc, t('PLG_CUSTOMIZE_CONTENT_PARENT_CATEGORY', 'Parent category')));
    newCatWrap.appendChild(parentSel);
    newCatWrap.appendChild(JC.ui.label(doc, t('PLG_CUSTOMIZE_CONTENT_NEW_CATEGORY_NAME', 'New category name')));
    newCatWrap.appendChild(nameInput);

    var newCatMode = false;
    newCatLink.addEventListener('click', function () {
      newCatMode = true;
      catSel.style.display = 'none';
      newCatLink.style.display = 'none';
      newCatWrap.style.display = 'block';
      nameInput.focus();
    });

    box.appendChild(JC.ui.label(doc, t('PLG_CUSTOMIZE_CONTENT_CATEGORY', 'Category')));
    box.appendChild(catSel);
    box.appendChild(newCatLink);
    box.appendChild(newCatWrap);
    box.appendChild(JC.ui.label(doc, t('PLG_CUSTOMIZE_CONTENT_STATUS', 'Status')));
    box.appendChild(stateSel);
    box.appendChild(featWrap);

    var bar = JC.ui.makeBar(doc);
    box.appendChild(bar.el);
    doc.body.appendChild(box);

    bar.cancel.addEventListener('click', function () {
      box.remove();
    });

    bar.save.addEventListener('click', function () {
      var action;
      var args;

      if (newCatMode) {
        if (!nameInput.value.trim()) {
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_CONTENT_ENTER_CATEGORY_NAME', 'Enter a category name.'));
          return;
        }

        action = 'newcategory';
        args = {
          id: ctx.data.id,
          name: nameInput.value.trim(),
          parent: parseInt(parentSel.value, 10) || 1,
          state: parseInt(stateSel.value, 10),
          featured: feat.checked ? 1 : 0
        };
      } else {
        action = 'saveprops';
        args = {
          id: ctx.data.id,
          catid: parseInt(catSel.value, 10),
          state: parseInt(stateSel.value, 10),
          featured: feat.checked ? 1 : 0
        };
      }

      JC.ui.saving(bar.save);

      ctx.callAction('content', action, args).then(function (res) {
        if (res && res.success) {
          box.remove();
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_CONTENT_PROPS_SAVED', 'Properties saved.'));
          reloadFrame();
        } else {
          JC.ui.resetSave(bar.save);
          JC.ui.toast(doc, failMessage(res));
        }
      }).catch(function () {
        JC.ui.resetSave(bar.save);
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_CONTENT_SAVE_ERROR', 'Save error.'));
      });
    });
  }

  // --- Advanced: open the full article form in a new tab ----------------------

  function openArticleEditor(ctx) {
    window.open('index.php?option=com_content&task=article.edit&id=' + encodeURIComponent(ctx.data.id), '_blank', 'noopener');
  }

  // --- Mark the title, image and details as their own areas -------------------

  function tagArea(el, type, id, name) {
    if (!el || el.hasAttribute('data-customize-type')) {
      return;
    }

    el.setAttribute('data-customize-type', type);
    el.setAttribute('data-customize-id', id);
    el.setAttribute('data-customize-name', name);
  }

  JC.on('customize:frame-ready', function (e) {
    var doc = e.detail && e.detail.doc;

    if (!doc) {
      return;
    }

    Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type="content"]'), function (body) {
      var id = body.getAttribute('data-customize-id');
      var name = body.getAttribute('data-customize-name') || '';
      var itemContent = body.closest('.item-content');
      var item = (itemContent && itemContent.parentElement) || body.parentElement || itemContent;

      // Title
      var titleEl = itemContent ? itemContent.querySelector('.item-title') : null;
      if (!titleEl && item) {
        titleEl = item.querySelector('h1, h2, h3');
      }
      tagArea(titleEl, 'content-title', id, name);

      // Image: the first image not inside the body text, or a placeholder to add one.
      var imageArea = null;
      if (item) {
        var imgs = item.querySelectorAll('img');
        for (var k = 0; k < imgs.length; k++) {
          if (!body.contains(imgs[k])) {
            imageArea = imgs[k].closest('figure') || imgs[k];
            break;
          }
        }
      }

      if (!imageArea && item) {
        imageArea = doc.createElement('div');
        imageArea.className = 'customize-image-placeholder';
        imageArea.textContent = t('PLG_CUSTOMIZE_CONTENT_ADD_IMAGE', 'Add image');
        item.insertBefore(imageArea, item.firstChild);
      }

      tagArea(imageArea, 'content-image', id, name);

      // Details block (article info) -> properties popover. If it isn't shown on the page,
      // surface Properties on the text area instead (the 'props' button requires this flag).
      var detailsEl = (itemContent || item) ? (itemContent || item).querySelector('.article-info') : null;
      if (detailsEl) {
        tagArea(detailsEl, 'content-props', id, name);
      } else {
        body.setAttribute('data-customize-props', '1');
      }
    });
  });

  // --- Registration ----------------------------------------------------------

  JC.registerAreaType('content', { label: t('PLG_CUSTOMIZE_CONTENT_AREA_TEXT', 'Text') });
  JC.registerAreaType('content-title', { label: t('PLG_CUSTOMIZE_CONTENT_AREA_TITLE', 'Title') });
  JC.registerAreaType('content-image', { label: t('PLG_CUSTOMIZE_CONTENT_AREA_IMAGE', 'Image') });
  JC.registerAreaType('content-props', { label: t('PLG_CUSTOMIZE_CONTENT_AREA_DETAILS', 'Details') });

  var editLabel = t('PLG_CUSTOMIZE_CONTENT_BTN_EDIT', 'Edit');
  var advancedLabel = t('PLG_CUSTOMIZE_CONTENT_BTN_ADVANCED', 'Advanced');

  JC.registerButton('content', { id: 'edit', label: editLabel, order: 10, onClick: editBody });
  // Shown on the text area only when there is no details block (data-customize-props flag).
  JC.registerButton('content', { id: 'props', label: t('PLG_CUSTOMIZE_CONTENT_BTN_PROPERTIES', 'Properties'), order: 20, requires: 'props', onClick: editProps });
  JC.registerButton('content-title', { id: 'edit', label: editLabel, order: 10, onClick: editTitle });
  JC.registerButton('content-image', { id: 'edit', label: editLabel, order: 10, onClick: editImage });
  JC.registerButton('content-props', { id: 'edit', label: editLabel, order: 10, onClick: editProps });

  // Open the full article form in a new tab from any content element.
  ['content', 'content-title', 'content-image', 'content-props'].forEach(function (type) {
    JC.registerButton(type, { id: 'advanced', label: advancedLabel, order: 90, onClick: openArticleEditor });
  });
}(window));
