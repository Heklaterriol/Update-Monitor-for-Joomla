<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;

return new class () implements InstallerScriptInterface {
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        return true;
    }

    /** Puts the dashboard module on the administrator control panel (position cpanel); failure is not fatal. */
    private function publishModule(DatabaseInterface $db): void
    {
        try {
            // only once ever: do not undo a position or unpublish chosen by the administrator later
            $q = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__updatemonitor_state'))->where($db->quoteName('name') . ' = ' . $db->quote('module_autopublished'));

            if ((int) $db->setQuery($q)->loadResult() > 0) {
                return;
            }

            $q = $db->getQuery(true)->update($db->quoteName('#__modules'))
                ->set($db->quoteName('position') . ' = ' . $db->quote('cpanel'))
                ->set($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('module') . ' = ' . $db->quote('mod_updatemonitor'))
                ->where($db->quoteName('client_id') . ' = 1')
                ->where('(' . $db->quoteName('position') . ' = ' . $db->quote('') . ' OR ' . $db->quoteName('position') . ' IS NULL)');
            $db->setQuery($q)->execute();

            $q  = $db->getQuery(true)->select($db->quoteName('id'))->from($db->quoteName('#__modules'))
                ->where($db->quoteName('module') . ' = ' . $db->quote('mod_updatemonitor'))->where($db->quoteName('client_id') . ' = 1');
            $id = (int) $db->setQuery($q)->loadResult();

            if ($id > 0) {
                $q = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__modules_menu'))->where($db->quoteName('moduleid') . ' = ' . $id);

                if ((int) $db->setQuery($q)->loadResult() === 0) {
                    $row = (object) ['moduleid' => $id, 'menuid' => 0];
                    $db->insertObject('#__modules_menu', $row);
                }
            }

            $flag = (object) ['name' => 'module_autopublished', 'value' => '1'];
            $db->insertObject('#__updatemonitor_state', $flag);
        } catch (\Throwable $e) {
            // the module can still be published by hand
        }
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'install' || $type === 'discover_install') {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $q  = $db->getQuery(true)->update($db->quoteName('#__extensions'))->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('updatemonitor'));
            $db->setQuery($q)->execute();
        }

        if ($type !== 'uninstall') {
            $this->publishModule(Factory::getContainer()->get(DatabaseInterface::class));
        }

        return true;
    }
};
