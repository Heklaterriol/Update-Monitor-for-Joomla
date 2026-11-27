<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/** Key/value state and activity log. */
final class Store
{
    public function __construct(public readonly DatabaseInterface $db)
    {
    }

    public static function now(): string
    {
        return Factory::getDate()->toSql();
    }

    /** insertObject()/updateObject() take the object BY REFERENCE, so it must be a variable. */
    public function insert(string $table, array $data): void
    {
        $obj = (object) $data;
        $this->db->insertObject($table, $obj);
    }

    public function update(string $table, array $data, bool $updateNulls = false): void
    {
        $obj = (object) $data;
        $this->db->updateObject($table, $obj, 'id', $updateNulls);
    }

    public function getState(string $name, string $default = ''): string
    {
        $db = $this->db;
        $q  = $db->getQuery(true)->select($db->quoteName('value'))->from($db->quoteName('#__updatemonitor_state'))
            ->where($db->quoteName('name') . ' = :n')->bind(':n', $name);
        $v = $db->setQuery($q)->loadResult();

        return $v === null ? $default : (string) $v;
    }

    public function setState(string $name, string $value): void
    {
        $db  = $this->db;
        $has = $this->getState($name, "\0none") !== "\0none";

        if ($has) {
            $q = $db->getQuery(true)->update($db->quoteName('#__updatemonitor_state'))
                ->set($db->quoteName('value') . ' = :v')->where($db->quoteName('name') . ' = :n')
                ->bind(':v', $value)->bind(':n', $name);
            $db->setQuery($q)->execute();
        } else {
            $this->insert('#__updatemonitor_state', ['name' => $name, 'value' => $value]);
        }
    }

    public function log(string $event, string $message, string $level = 'info'): void
    {
        try {
            $this->insert('#__updatemonitor_log', [
                'created' => self::now(),
                'level'   => $level,
                'event'   => $event,
                'message' => mb_substr($message, 0, 1000),
            ]);
        } catch (\Throwable $e) {
            // logging must never break a run, but do not fail silently either
            \Joomla\CMS\Log\Log::add('Update Monitor could not write log entry: ' . $e->getMessage(), \Joomla\CMS\Log\Log::WARNING, 'updatemonitor');
        }
    }
}
