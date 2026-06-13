<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/** @var \Joomla\Component\Templates\Administrator\View\Template\HtmlView $this */

// Shown briefly inside the editing dialog after a save/cancel; the script tells the opener to close.
$fromTask    = Factory::getApplication()->getInput()->getCmd('from-task', 'save');
$messageType = $fromTask === 'cancel' ? 'joomla:cancel' : 'joomla:content-select';

$this->getDocument()->getWebAssetManager()->addInlineScript(
    'if (window.parent && window.parent !== window) {'
    . ' window.parent.postMessage({ messageType: ' . json_encode($messageType) . ' }, window.location.origin); }'
);
?>
<div class="px-4 py-5 my-5 text-center">
    <span class="fa-8x mb-4 icon-check" aria-hidden="true"></span>
    <h1 class="display-6"><?php echo Text::_('COM_TEMPLATES_FILE_SAVE_SUCCESS'); ?></h1>
</div>
