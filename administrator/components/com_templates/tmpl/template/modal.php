<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Jfcherng\Diff\DiffHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Templates\Administrator\View\Template\HtmlView $this */

$app   = Factory::getApplication();
$doc   = $app->getDocument();
$input = $app->getInput();

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $doc->getWebAssetManager();

// A focused single-file editor for a dialog: validation, keepalive, the show core/diff toggle
// behaviour and the pane styles. Not com_templates.admin-templates (JS), which drives the file tree
// this layout intentionally omits.
$wa->useScript('form.validate')
    ->useScript('keepalive')
    ->useScript('com_templates.admin-template-toggle-switch')
    ->useStyle('com_templates.admin-templates');

// $hasCore: this file overrides an original we can show. $isNew: the override does not exist yet
// (seeded from the original, created on save) so there is nothing to compare against yet.
$hasCore = !empty($this->source->coreFile);
$isNew   = !empty($this->source->isNew);
$canDiff = $hasCore && !$isNew && is_file($this->source->filePath);

$rootLen      = \strlen(JPATH_ROOT);
$originalPath = $hasCore ? substr((string) $this->source->coreFile, $rootLen) : '';
$overridePath = !empty($this->source->filePath) ? substr((string) $this->source->filePath, $rootLen) : '';

// Scroll the original/diff pane into view when its toggle is switched on.
if ($canDiff) {
    $wa->addInlineScript(
        "document.addEventListener('DOMContentLoaded', function () {"
        . " var bind = function (toggle, pane) {"
        . " var t = document.getElementById(toggle), p = document.getElementById(pane);"
        . " if (t && p) { t.addEventListener('click', function () { setTimeout(function () { p.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 0); }); } };"
        . " bind('jform_show_core1', 'core-pane'); bind('jform_show_diff1', 'diff-main'); });"
    );
}
?>
<div class="subhead noshadow mb-3">
    <?php echo $doc->getToolbar('toolbar')->render(); ?>
</div>
<div class="container-popup">
    <?php if ($this->type === 'file') : ?>
        <?php if ($hasCore) : ?>
            <dl class="row small text-muted mb-3">
                <dt class="col-sm-3 text-truncate"><?php echo Text::_('COM_TEMPLATES_DIFF_CORE'); ?></dt>
                <dd class="col-sm-9"><code><?php echo $this->escape($originalPath); ?></code></dd>
                <dt class="col-sm-3 text-truncate"><?php echo Text::_('COM_TEMPLATES_DIFF_OVERRIDE'); ?></dt>
                <dd class="col-sm-9">
                    <code><?php echo $this->escape($overridePath); ?></code>
                    <?php if ($isNew) : ?>
                        <span class="badge bg-info"><?php echo Text::_('COM_TEMPLATES_OVERRIDE_PENDING'); ?></span>
                    <?php endif; ?>
                </dd>
            </dl>
        <?php endif; ?>

        <?php if ($canDiff) : ?>
            <div class="d-flex justify-content-end gap-2 mb-2" id="toggle-buttons">
                <?php echo $this->form->renderField('show_core'); ?>
                <?php echo $this->form->renderField('show_diff'); ?>
            </div>
        <?php endif; ?>

        <div id="override-pane">
            <form action="<?php echo Route::_('index.php?option=com_templates&view=template&layout=modal&id=' . $input->getInt('id') . '&file=' . $this->file . '&isMedia=' . $input->get('isMedia', 0)); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">
                <div class="editor-border">
                    <?php echo $this->form->getInput('source'); ?>
                </div>
                <input type="hidden" name="isMedia" value="<?php echo $input->get('isMedia', 0); ?>">
                <input type="hidden" name="task" value="">
                <?php echo HTMLHelper::_('form.token'); ?>
                <?php echo $this->form->getInput('extension_id'); ?>
                <?php echo $this->form->getInput('filename'); ?>
            </form>
        </div>

        <?php if ($canDiff) : ?>
            <div id="core-pane" class="mt-3">
                <h2><?php echo Text::_('COM_TEMPLATES_FILE_CORE_PANE'); ?></h2>
                <div class="editor-border">
                    <?php echo $this->form->getInput('core'); ?>
                </div>
            </div>

            <?php
            $difference = DiffHelper::calculateFiles(
                $this->source->coreFile,
                $this->source->filePath,
                ComponentHelper::getParams('com_templates')->get('difference', 'SideBySide'),
                [
                    'context'          => 1,
                    'ignoreLineEnding' => true,
                ],
                [
                    'language' => [
                        'old_version' => Text::_('COM_TEMPLATES_DIFF_CORE'),
                        'new_version' => Text::_('COM_TEMPLATES_DIFF_OVERRIDE'),
                        'differences' => Text::_('COM_TEMPLATES_DIFF_DIFFERENCES'),
                    ],
                    'resultForIdenticals' => Text::_('COM_TEMPLATES_DIFF_IDENTICAL'),
                    'detailLevel'         => 'word',
                    'spaceToHtmlTag'      => true,
                    'wrapperClasses'      => ['diff-wrapper', 'columns-order-ignore'],
                ]
            );
            ?>
            <div id="diff-main" class="mt-3">
                <h2><?php echo Text::_('COM_TEMPLATES_FILE_COMPARE_PANE'); ?></h2>
                <div class="diff-pane">
                    <div id="diff"><?php echo $difference; ?></div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
