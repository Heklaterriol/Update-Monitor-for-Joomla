<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

/**
 * Detects that scheduled checks stopped running (banner on the dashboard).
 * Only checks started by the Scheduled Tasks plugin count: manual checks and the check on opening
 * the component must not hide a broken cron job.
 */
final class Health
{
    public function __construct(private Store $store, private Registry $params)
    {
    }

    /**
     * @return array{level:string,title:string,text:string,reasons:array<int,string>}|null null = everything fine
     */
    public function evaluate(): ?array
    {
        if (!$this->params->get('warn_missing_checks', 1)) {
            return null;
        }

        $limitHours = max(1, (int) $this->params->get('max_check_age_hours', 48));
        $lastOk     = $this->store->getState('last_scheduled_success');
        $age        = $lastOk !== '' ? time() - strtotime($lastOk . ' UTC') : null;

        if ($age !== null && $age <= $limitHours * 3600) {
            return ($this->store->getState('last_scheduled_ok') === '0') ? $this->failedLast() : null;
        }

        $reasons = $this->reasons();

        if ($age === null) {
            // nothing recorded yet: only worth a warning if something is visibly wrong
            return [
                'level'   => $reasons ? 'warning' : 'info',
                'title'   => Text::_('COM_UPDATEMONITOR_HEALTH_TITLE_NEVER'),
                'text'    => Text::_('COM_UPDATEMONITOR_HEALTH_TEXT_NEVER'),
                'reasons' => $reasons,
            ];
        }

        return [
            'level'   => 'danger',
            'title'   => Text::_('COM_UPDATEMONITOR_HEALTH_TITLE_OVERDUE'),
            'text'    => Text::sprintf('COM_UPDATEMONITOR_HEALTH_TEXT_OVERDUE', $lastOk . ' UTC', (int) floor($age / 3600), $limitHours),
            'reasons' => $reasons,
        ];
    }

    private function failedLast(): array
    {
        return [
            'level'   => 'warning',
            'title'   => Text::_('COM_UPDATEMONITOR_HEALTH_TITLE_FAILED'),
            'text'    => Text::_('COM_UPDATEMONITOR_HEALTH_R_FAILED'),
            'reasons' => [],
        ];
    }

    /** Likely causes, derived from Joomla's plugin and scheduler tables. */
    private function reasons(): array
    {
        $db      = $this->store->db;
        $reasons = [];

        try {
            $q = $db->getQuery(true)->select($db->quoteName('enabled'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('updatemonitor'));
            $enabled = $db->setQuery($q)->loadResult();

            if ($enabled !== null && (int) $enabled === 0) {
                $reasons[] = Text::_('COM_UPDATEMONITOR_HEALTH_R_NO_PLUGIN');
            }

            $q = $db->getQuery(true)
                ->select($db->quoteName(['title', 'state', 'locked', 'next_execution']))
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('updatemonitor.check'))
                ->order($db->quoteName('state') . ' DESC');
            $task = $db->setQuery($q)->loadAssoc();

            if (!$task) {
                $reasons[] = Text::_('COM_UPDATEMONITOR_HEALTH_R_NO_TASK');
            } elseif ((int) $task['state'] !== 1) {
                $reasons[] = Text::sprintf('COM_UPDATEMONITOR_HEALTH_R_TASK_OFF', $task['title']);
            } else {
                $due = !empty($task['next_execution']) ? strtotime($task['next_execution'] . ' UTC') : 0;

                if ($due > 0 && (time() - $due) > 3600) {
                    $reasons[] = Text::sprintf('COM_UPDATEMONITOR_HEALTH_R_DUE', $task['title'], $task['next_execution'] . ' UTC');
                }

                if (!empty($task['locked'])) {
                    $reasons[] = Text::sprintf('COM_UPDATEMONITOR_HEALTH_R_LOCKED', $task['title']);
                }
            }
        } catch (\Throwable $e) {
            // scheduler tables unavailable: no diagnosis possible
        }

        if ($this->store->getState('last_scheduled_ok') === '0') {
            $reasons[] = Text::_('COM_UPDATEMONITOR_HEALTH_R_FAILED');
        }

        return $reasons;
    }
}
