/**
 * Customize - Module plugin (admin side).
 *
 * Loaded in the com_menus Customize host page. Modules are marked server-side (ModulesRenderer);
 * this registers the "module" area type and a settings popover (title, show title, published).
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
    var reason = (res && res.message) || t('PLG_CUSTOMIZE_MODULE_UNKNOWN_ERROR', 'unknown error');
    return t('PLG_CUSTOMIZE_MODULE_SAVE_FAILED', 'Save failed: %s').replace('%s', reason);
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

  function editModule(ctx) {
    ctx.callAction('module', 'load', { id: ctx.data.id }).then(function (res) {
      if (!res || !res.success) {
        JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_MODULE_LOAD_FAILED', 'Could not load module settings.'));
        return;
      }

      showPopover(ctx, res);
    });
  }

  function showPopover(ctx, data) {
    var doc = ctx.doc;
    var existing = doc.getElementById('customize-module-props');
    if (existing) {
      existing.remove();
    }

    var box = doc.createElement('div');
    box.id = 'customize-module-props';
    box.className = 'customize-popover';

    var rect = ctx.el.getBoundingClientRect();
    var win = doc.defaultView;
    box.style.top = (rect.top + win.scrollY) + 'px';
    box.style.left = (rect.left + win.scrollX) + 'px';

    var titleInput = doc.createElement('input');
    titleInput.type = 'text';
    titleInput.value = data.title || '';

    var showWrap = doc.createElement('label');
    showWrap.className = 'customize-check';
    var showTitle = doc.createElement('input');
    showTitle.type = 'checkbox';
    showTitle.checked = !!data.showtitle;
    showWrap.appendChild(showTitle);
    showWrap.appendChild(doc.createTextNode(t('PLG_CUSTOMIZE_MODULE_SHOW_TITLE', 'Show title')));

    var pubWrap = doc.createElement('label');
    pubWrap.className = 'customize-check';
    var published = doc.createElement('input');
    published.type = 'checkbox';
    published.checked = data.published === 1;
    pubWrap.appendChild(published);
    pubWrap.appendChild(doc.createTextNode(t('PLG_CUSTOMIZE_MODULE_PUBLISHED', 'Published')));

    box.appendChild(JC.ui.label(doc, t('PLG_CUSTOMIZE_MODULE_TITLE', 'Title')));
    box.appendChild(titleInput);
    box.appendChild(showWrap);
    box.appendChild(pubWrap);

    var bar = JC.ui.makeBar(doc);
    box.appendChild(bar.el);
    doc.body.appendChild(box);

    bar.cancel.addEventListener('click', function () {
      box.remove();
    });

    bar.save.addEventListener('click', function () {
      JC.ui.saving(bar.save);

      ctx.callAction('module', 'save', {
        id: ctx.data.id,
        title: titleInput.value,
        showtitle: showTitle.checked ? 1 : 0,
        published: published.checked ? 1 : 0
      }).then(function (res) {
        if (res && res.success) {
          box.remove();
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_MODULE_SAVED', 'Module saved.'));
          reloadFrame();
        } else {
          JC.ui.resetSave(bar.save);
          JC.ui.toast(doc, failMessage(res));
        }
      }).catch(function () {
        JC.ui.resetSave(bar.save);
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_MODULE_SAVE_ERROR', 'Save error.'));
      });
    });
  }

  // Edit a custom (mod_custom) module's HTML body in place with a WYSIWYG editor.
  function editContent(ctx) {
    var contentEl = ctx.el.querySelector('.mod-custom');

    if (!contentEl) {
      JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_MODULE_NO_CONTENT', 'No editable content here.'));
      return;
    }

    JC.editInline({ doc: ctx.doc, el: contentEl }, {
      html: contentEl.innerHTML,
      save: function (content) {
        return ctx.callAction('module', 'savecontent', { id: ctx.data.id, html: content }).then(function (res) {
          if (res && res.success) {
            JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_MODULE_CONTENT_SAVED', 'Content saved.'));
          } else {
            JC.ui.toast(ctx.doc, failMessage(res));
          }

          return { success: !!(res && res.success), html: res && res.html };
        });
      }
    });
  }

  function openModuleEditor(ctx) {
    window.open('index.php?option=com_modules&task=module.edit&id=' + encodeURIComponent(ctx.data.id), '_blank', 'noopener');
  }

  JC.registerAreaType('module', { label: t('PLG_CUSTOMIZE_MODULE_AREA', 'Module') });
  JC.registerButton('module', { id: 'edit', label: t('PLG_CUSTOMIZE_MODULE_BTN_EDIT', 'Edit'), order: 10, onClick: editModule });
  // Only shown on custom modules (data-customize-custom emitted by the renderer).
  JC.registerButton('module', { id: 'content', label: t('PLG_CUSTOMIZE_MODULE_BTN_CONTENT', 'Edit content'), order: 20, requires: 'custom', onClick: editContent });
  JC.registerButton('module', { id: 'advanced', label: t('PLG_CUSTOMIZE_MODULE_BTN_ADVANCED', 'Advanced'), order: 90, onClick: openModuleEditor });
}(window));
