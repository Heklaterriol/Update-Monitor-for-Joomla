<?php

namespace Custom\Component\UpdateMonitor\Administrator\View\Monitor;

\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Monitor;
use Custom\Component\UpdateMonitor\Administrator\Service\Severity;
use Custom\Component\UpdateMonitor\Administrator\Service\Store;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

class HtmlView extends BaseHtmlView
{
    public ?object $core = null;
    public int $extCount = 0;
    public array $findings = [];
    public string $findingsChecked = '';
    public array $stats = [];
    public array $items = [];
    public array $log = [];
    public array $servers = [];
    public string $filter = 'pending';

    public function display($tpl = null)
    {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $app = Factory::getApplication();

        // Opening the component (no explicit layout) starts a check without sending mail
        if ($this->getLayout() === 'default'
            && $app->getInput()->getCmd('layout', '') === ''
            && ComponentHelper::getParams('com_updatemonitor')->get('auto_check_on_open', 1)
            && $app->getIdentity()->authorise('core.admin', 'com_updatemonitor')
            && !$this->checkedRecently($db)) {
            [$text, $type] = Monitor::flash(Monitor::create()->run('open'));
            $app->enqueueMessage($text, $type);
        }

        ToolbarHelper::title(Text::_('COM_UPDATEMONITOR'), 'bell');

        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_updatemonitor')) {
            ToolbarHelper::preferences('com_updatemonitor');
        }

        switch ($this->getLayout()) {
            case 'updates':
                $this->filter = Factory::getApplication()->getInput()->getCmd('filter', 'pending');
                $this->items  = $this->updates($db, $this->filter === 'ignored' ? "status='pending' AND ignored=1" : "status='pending' AND ignored=0");
                break;
            case 'history':
                $this->items = $this->updates($db, '1=1', 'first_detected DESC', 300);
                break;
            case 'log':
                $this->log = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__updatemonitor_log') . ' ORDER BY id DESC', 0, 300)->loadObjectList();
                break;
            case 'security':
                $store                = new Store($db);
                $this->findings        = json_decode($store->getState('findings', '[]'), true) ?: [];
                $this->findingsChecked = $store->getState('findings_checked');
                break;
            case 'servers':
                $this->servers = $this->servers($db);
                break;
            default:
                $this->dashboard($db);
        }

        parent::display($tpl);
    }

    /** Auto-check on open is a GET request: do not run more than once per minute (protects against reload floods / forced requests). */
    private function checkedRecently(DatabaseInterface $db): bool
    {
        $last = (new Store($db))->getState('last_check');

        return $last !== '' && (time() - strtotime($last . ' UTC')) < 60;
    }

    private function updates(DatabaseInterface $db, string $where, string $order = '', int $limit = 500): array
    {
        $rows = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__updatemonitor_updates') . ' WHERE ' . $where . ($order ? ' ORDER BY ' . $order : ''), 0, $limit)->loadObjectList();

        if ($order === '') {
            $core = static fn ($r) => $r->type_label === 'Joomla Core' ? 0 : 1;
            usort($rows, static fn ($a, $b) => [$core($a), -Severity::rank($a->severity), $a->name] <=> [$core($b), -Severity::rank($b->severity), $b->name]);
        }

        return $rows;
    }

    private function dashboard(DatabaseInterface $db): void
    {
        $store   = new Store($db);
        $pending = $this->updates($db, "status='pending' AND ignored=0");
        $s       = ['pending' => \count($pending), 'security' => 0, 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0];

        foreach ($pending as $r) {
            if ($r->severity !== '') {
                $s['security']++;
                $s[$r->severity] = ($s[$r->severity] ?? 0) + 1;
            }
        }

        $cut = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $s['ignored']      = (int) $db->setQuery("SELECT COUNT(*) FROM " . $db->quoteName('#__updatemonitor_updates') . " WHERE status='pending' AND ignored=1")->loadResult();
        $s['resolved30']   = (int) $db->setQuery("SELECT COUNT(*) FROM " . $db->quoteName('#__updatemonitor_updates') . " WHERE status='resolved' AND resolved_at >= " . $db->quote($cut))->loadResult();
        $s['last_check']   = $store->getState('last_check');
        $s['last_success'] = $store->getState('last_success');

        $found          = json_decode($store->getState('findings', '[]'), true) ?: [];
        $s['findings']  = \count($found);
        $s['vel']       = \count(array_filter($found, static fn ($f) => $f['kind'] === 'vel'));
        $s['installer_list'] = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__updates') . ' WHERE extension_id > 0')->loadResult();
        $this->stats = $s;
        $rest = [];

        foreach ($pending as $r) {
            if ($r->type_label === 'Joomla Core') {
                $this->core = $r;
            } else {
                $rest[] = $r;
            }
        }

        $this->extCount = \count($rest);
        $this->items    = \array_slice($rest, 0, 10);
        $this->log   = $db->setQuery('SELECT * FROM ' . $db->quoteName('#__updatemonitor_log') . ' ORDER BY id DESC', 0, 10)->loadObjectList();
    }

    private function servers(DatabaseInterface $db): array
    {
        $q = $db->getQuery(true)
            ->select([
                $db->quoteName('s.update_site_id'), $db->quoteName('s.name'), $db->quoteName('s.type'), $db->quoteName('s.location'),
                $db->quoteName('s.enabled'), $db->quoteName('s.last_check_timestamp'), $db->quoteName('e.name', 'extension'),
            ])
            ->from($db->quoteName('#__update_sites', 's'))
            ->join('LEFT', $db->quoteName('#__update_sites_extensions', 'se') . ' ON se.update_site_id = s.update_site_id')
            ->join('LEFT', $db->quoteName('#__extensions', 'e') . ' ON e.extension_id = se.extension_id')
            ->order($db->quoteName('s.name'));

        return $db->setQuery($q)->loadObjectList();
    }
}
