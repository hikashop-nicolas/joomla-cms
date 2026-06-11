<?php

/**
 * One-time demo content seed: a few featured articles so the front page has layouts and modules worth
 * editing in customize mode. Seeds straight through Joomla's database service, the full web
 * application is awkward to boot in CLI, and articles only need to be published + Public + on the
 * front page to display. Run from the Joomla webroot after install; skips if content already exists.
 *
 * Mounted, not baked into the image, so it can be iterated.
 */

const _JEXEC = 1;
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

$db = Factory::getContainer()->get(DatabaseInterface::class);

if ((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__content'))->loadResult() > 0) {
    echo "[seed] content already present; skipping\n";
    exit(0);
}

$now      = gmdate('Y-m-d H:i:s');
$articles = [
    [
        'Welcome to the Customize demo',
        'welcome-customize-demo',
        '<p>This Joomla site runs the <strong>customize-mode</strong> fork. In the administrator, edit a '
        . 'menu item and click <strong>Customize</strong> in the toolbar to edit this page in place.</p>',
    ],
    [
        'Editing layouts',
        'editing-layouts',
        '<p>Hover any block and use <strong>Edit layout</strong> to create a template override, or the '
        . '<strong>Parent</strong> / <strong>Children</strong> controls to walk the layout tree.</p>',
    ],
    [
        'Modules and language',
        'modules-and-language',
        '<p>Module settings and custom HTML are editable in place, and so is any translated string on '
        . 'the page, saved as a Joomla language override.</p>',
    ],
];

$ordering = 0;

foreach ($articles as [$title, $alias, $body]) {
    $ordering++;

    $db->setQuery(
        $db->getQuery(true)
            ->insert($db->quoteName('#__content'))
            ->columns($db->quoteName([
                'title', 'alias', 'introtext', 'fulltext', 'state', 'catid', 'created', 'created_by',
                'modified', 'publish_up', 'images', 'urls', 'attribs', 'metadesc', 'metadata',
                'featured', 'ordering', 'access', 'language',
            ]))
            ->values(implode(',', [
                $db->quote($title), $db->quote($alias), $db->quote($body), $db->quote(''),
                1, 2, $db->quote($now), 0, $db->quote($now), $db->quote($now),
                $db->quote('{}'), $db->quote('{}'), $db->quote('{}'), $db->quote(''), $db->quote('{}'),
                1, $ordering, 1, $db->quote('*'),
            ]))
    )->execute();

    $id = (int) $db->insertid();

    $db->setQuery(
        $db->getQuery(true)
            ->insert($db->quoteName('#__content_frontpage'))
            ->columns($db->quoteName(['content_id', 'ordering']))
            ->values($id . ', ' . $ordering)
    )->execute();

    echo "[seed] article created: $title (id $id)\n";
}

echo "[seed] done\n";
