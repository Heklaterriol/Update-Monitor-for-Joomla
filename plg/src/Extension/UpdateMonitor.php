<?php

namespace Custom\Plugin\Task\UpdateMonitor\Extension;

\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Monitor;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;

/** Scheduled task: runs the Update Monitor for Joomla check (com_updatemonitor). */
final class UpdateMonitor extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected $autoloadLanguage = true;

    protected const TASKS_MAP = [
        'updatemonitor.check' => [
            'langConstPrefix' => 'PLG_TASK_UPDATEMONITOR_TASK_CHECK',
            'method'          => 'runCheck',
        ],
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    private function runCheck(ExecuteTaskEvent $event): int
    {
        if (!class_exists(Monitor::class)) {
            $this->logTask('com_updatemonitor is not installed.', 'error');

            return Status::KNOCKOUT;
        }

        try {
            $res = Monitor::create()->run('scheduled');
            $this->logTask(sprintf('Update Monitor: %s, %d pending update(s).', $res['ok'] ? 'OK' : 'finished with problems (see activity log)', $res['pending']));

            return Status::OK;
        } catch (\Throwable $e) {
            $this->logTask('Update Monitor failed: ' . $e->getMessage(), 'error');

            return Status::KNOCKOUT;
        }
    }
}
