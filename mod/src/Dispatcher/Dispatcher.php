<?php

namespace Custom\Module\UpdateMonitor\Administrator\Dispatcher;

\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Health;
use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Custom\Component\UpdateMonitor\Administrator\Service\Store;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/** Administrator dashboard module: compact update overview for the Joomla control panel. */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data            = parent::getLayoutData();
        $data['summary'] = null;
        $app             = Factory::getApplication();
        $user            = $app->getIdentity();

        // needs the component and the permission to use it
        if (!$user || !class_exists(Store::class) || !$user->authorise('core.manage', 'com_updatemonitor')) {
            return $data;
        }

        try {
            $lang = $app->getLanguage();
            $lang->load('com_updatemonitor', JPATH_ADMINISTRATOR);
            $lang->load('com_updatemonitor', JPATH_ADMINISTRATOR . '/components/com_updatemonitor');

            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $store = new Store($db);
            $q     = $db->getQuery(true)
                ->select($db->quoteName(['name', 'type_label', 'installed_version', 'available_version', 'severity']))
                ->from($db->quoteName('#__updatemonitor_updates'))
                ->where($db->quoteName('status') . ' = ' . $db->quote('pending'))
                ->where($db->quoteName('ignored') . ' = 0');

            $core     = null;
            $pending  = 0;
            $security = [];

            foreach ($db->setQuery($q)->loadObjectList() as $r) {
                if ($r->type_label === 'Joomla Core') {
                    $core = $r;
                    continue;
                }

                $pending++;

                if ($r->severity !== '') {
                    $security[$r->severity] = ($security[$r->severity] ?? 0) + 1;
                }
            }

            $found = json_decode($store->getState('findings', '[]'), true) ?: [];

            $data['summary'] = [
                'core'       => $core,
                'pending'    => $pending,
                'security'   => $security,
                'findings'   => \count($found),
                'vel'        => \count(array_filter($found, static fn ($f) => $f['kind'] === 'vel')),
                'last_check' => $store->getState('last_check'),
                'health'     => $store->getState('last_check') !== '' ? (new Health($store, ComponentHelper::getParams('com_updatemonitor')))->evaluate() : null,
                'severity'   => Severity::class,
            ];
        } catch (\Throwable $e) {
            $data['summary'] = null;
        }

        return $data;
    }
}
