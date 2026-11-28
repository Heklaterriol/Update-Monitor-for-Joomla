<?php

namespace Custom\Component\UpdateMonitor\Administrator\Controller;

\defined('_JEXEC') or die;

use Custom\Component\UpdateMonitor\Administrator\Service\Monitor;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class ActionController extends BaseController
{
    private function guard(): void
    {
        $this->checkToken('request');

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_updatemonitor')) {
            throw new \RuntimeException(Text::_('COM_UPDATEMONITOR_MSG_NOT_AUTHORISED'), 403);
        }
    }

    private function back(string $layout = 'default', string $msg = '', string $type = 'message'): void
    {
        $this->setRedirect(Route::_('index.php?option=com_updatemonitor&view=monitor&layout=' . $layout, false), $msg, $type);
    }

    public function ignore(): void
    {
        $this->toggle(1);
    }

    public function unignore(): void
    {
        $this->toggle(0);
    }

    private function toggle(int $value): void
    {
        $this->guard();

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $id = $this->input->getInt('id');
        $q  = $db->getQuery(true)->update($db->quoteName('#__updatemonitor_updates'))
            ->set($db->quoteName('ignored') . ' = :v')->where($db->quoteName('id') . ' = :id')
            ->bind(':v', $value, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($q)->execute();

        Monitor::create()->store()->log('ignore', ($value ? 'Update ignored' : 'Update un-ignored') . ' (ID ' . $id . ')');

        $this->back($this->input->getCmd('return', 'updates'), Text::_($value ? 'COM_UPDATEMONITOR_MSG_IGNORED' : 'COM_UPDATEMONITOR_MSG_UNIGNORED'));
    }

    public function clearlog(): void
    {
        $this->guard();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__updatemonitor_log'))->execute();
        Monitor::create()->store()->log('cleanup', 'Activity log cleared manually');
        $this->back('log', Text::_('COM_UPDATEMONITOR_MSG_LOG_CLEARED'));
    }

    public function check(): void
    {
        $this->guard();
        [$text, $type] = Monitor::flash(Monitor::create()->run('manual'));
        $this->back('default', $text, $type);
    }

    public function testchannels(): void
    {
        $this->guard();
        $res = Monitor::create()->notifier()->testChannels();

        if (!$res) {
            $this->back('default', Text::_('COM_UPDATEMONITOR_MSG_TESTCHAN_NONE'), 'warning');

            return;
        }

        $ok   = array_keys(array_filter($res));
        $fail = array_keys(array_filter($res, static fn ($v) => !$v));
        $msg  = $ok ? Text::sprintf('COM_UPDATEMONITOR_MSG_TESTCHAN_OK', implode(', ', $ok)) : '';
        $msg .= $fail ? ' ' . Text::sprintf('COM_UPDATEMONITOR_MSG_TESTCHAN_FAIL', implode(', ', $fail)) : '';
        $this->back('default', trim($msg), $fail ? 'warning' : 'message');
    }

    public function testmail(): void
    {
        $this->guard();
        $n = Monitor::create()->notifier()->sendTest();
        $this->back('default', $n > 0 ? Text::sprintf('COM_UPDATEMONITOR_MSG_TESTMAIL_SENT', $n) : Text::_('COM_UPDATEMONITOR_MSG_TESTMAIL_FAILED'), $n > 0 ? 'message' : 'error');
    }
}
