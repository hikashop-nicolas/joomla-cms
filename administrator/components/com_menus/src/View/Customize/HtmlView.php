<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_menus
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Menus\Administrator\View\Customize;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Hosts the live frontend page of a menu item in an iframe for visual customization.
 *
 * @since  __DEPLOY_VERSION__
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The menu item being customized.
     *
     * @var    \stdClass|null
     * @since  __DEPLOY_VERSION__
     */
    protected $item;

    /**
     * The routed, customize-flagged frontend URL to load in the iframe.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $previewUrl = '';

    /**
     * The signed customize token carried in the preview URL and refreshed by the engine.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $customizeToken = '';

    /**
     * Execute and display a template script.
     *
     * @param   string  $tpl  The name of the template file to parse.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $id  = $app->getInput()->getInt('id', 0);

        if (!$this->getCurrentUser()->authorise('core.edit', 'com_menus')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $model = $app->bootComponent('com_menus')->getMVCFactory()
            ->createModel('Item', 'Administrator', ['ignore_request' => true]);

        // Mint the signed token for this editor (the permission check above is the gate) and carry it
        // in the preview URL; the engine refreshes it for long sessions.
        $this->customizeToken = CustomizeMode::mintToken((int) $this->getCurrentUser()->id);

        $this->item       = $model->getItem($id);
        $this->previewUrl = $this->buildPreviewUrl($id);

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Build the frontend preview URL for a menu item, carrying the customize flag.
     *
     * @param   integer  $id  The menu item id.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function buildPreviewUrl(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        // Route by Itemid in the site context; falls back to a non-SEF URL if routing yields nothing.
        $url = Route::link('site', 'index.php?Itemid=' . $id . '&customize=' . $this->customizeToken, false, Route::TLS_IGNORE, true);

        if (empty($url)) {
            $url = Uri::root() . 'index.php?Itemid=' . $id . '&customize=' . $this->customizeToken;
        }

        return $url;
    }

    /**
     * Add the page title and the back-to-edit button.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function addToolbar()
    {
        $app = Factory::getApplication();
        $app->getInput()->set('hidemainmenu', true);

        $title = ($this->item && !empty($this->item->title)) ? $this->item->title : Text::_('COM_MENUS_ITEMS');
        ToolbarHelper::title(Text::sprintf('COM_MENUS_CUSTOMIZE_VIEW_TITLE', $title), 'paint-brush');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->linkButton('back', 'JTOOLBAR_BACK')
            ->url('index.php?option=com_menus&view=item&layout=edit&id=' . (int) ($this->item->id ?? 0))
            ->icon('icon-arrow-left');
    }
}
