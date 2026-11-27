<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Core logic: check for updates, track history, trigger notifications.
 * Does NOT install anything.
 */
final class Monitor
{
    private const LOCK_TTL     = 1800; // seconds; a lock older than this is considered stale
    private const MISSED_LIMIT = 3;    // successful runs an update may be missing before it counts as withdrawn

    private Store $store;
    private Notifier $notifier;

    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
        $this->store    = new Store($db);
        $this->notifier = new Notifier($this->store, $params);
    }

    public static function create(): self
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('com_updatemonitor', JPATH_ADMINISTRATOR);
        $lang->load('com_updatemonitor', JPATH_ADMINISTRATOR . '/components/com_updatemonitor');

        return new self(Factory::getContainer()->get(DatabaseInterface::class), ComponentHelper::getParams('com_updatemonitor'));
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function notifier(): Notifier
    {
        return $this->notifier;
    }

    /**
     * Message text and Joomla message type for a check result:
     * green when nothing is pending, yellow when updates are available, red on errors.
     *
     * @return array{0:string,1:string}
     */
    public static function flash(array $res): array
    {
        if (($res['message'] ?? '') === 'locked') {
            return [Text::_('COM_UPDATEMONITOR_MSG_CHECK_RUNNING'), 'warning'];
        }

        if (empty($res['ok'])) {
            return [Text::_('COM_UPDATEMONITOR_MSG_CHECK_FAILED'), 'error'];
        }

        $n = (int) $res['pending'];

        return [Text::sprintf('COM_UPDATEMONITOR_MSG_CHECK_DONE', $n), $n > 0 ? 'warning' : 'message'];
    }

    /**
     * @param string $trigger 'scheduled' (Scheduled Tasks plugin), 'manual' (Run check now button)
     *                        or 'open' (automatic check when the component is opened; never sends mail)
     *
     * @return array{ok:bool,pending:int,message:string}
     */
    public function run(string $trigger = 'scheduled'): array
    {
        $result = ['ok' => false, 'pending' => 0, 'message' => ''];

        $scheduled = $trigger === 'scheduled';
        $sendMail  = match ($trigger) {
            'manual'    => (bool) $this->params->get('mail_on_manual', 1),
            'scheduled' => (bool) $this->params->get('mail_on_scheduled', 1),
            default     => false,
        };

        if (!$this->acquireLock()) {
            $this->store->log('check', 'Check skipped: another check is still running.', 'warning');
            $result['message'] = 'locked';

            return $result;
        }

        try {
            $this->store->log('check', [
                'manual' => 'Manual check started.',
                'open'   => 'Check started by opening the component (no emails).',
            ][$trigger] ?? 'Scheduled check started.');
            $ok = true;

            if ($this->params->get('refresh', 1)) {
                try {
                    $this->refreshUpdates();
                } catch (\Throwable $e) {
                    $ok = false;
                    $this->store->log('check', 'Update search failed: ' . $this->errorText($e), 'error');
                }
            }

            $current = $this->fetchPending();
            $stats   = $this->sync($current, $ok);

            $this->store->setState('last_check', Store::now());

            if ($ok) {
                $this->store->setState('last_success', Store::now());
            }

            $this->store->log('check', sprintf(
                'Check %s: %d update(s) available, %d new, %d resolved.',
                $ok ? 'finished' : 'finished with errors',
                \count($current),
                $stats['new'],
                $stats['resolved']
            ), $ok ? 'info' : 'warning');

            // Mails only after a successful, current check and only if enabled for this trigger
            if ($ok && $sendMail) {
                $this->notifier->notifyChanges();

                if ($scheduled && $this->checkIsFresh()) {
                    $this->notifier->remind();
                    $this->notifier->reports();
                }
            } elseif ($ok && $trigger !== 'open') {
                $this->store->log('notification', 'Emails are disabled for this kind of check (see Options).');
            }

            if ($ok && $scheduled) {
                $this->cleanup();
            }

            $result = ['ok' => $ok, 'pending' => \count($current), 'message' => ''];
        } catch (\Throwable $e) {
            $this->store->log('check', 'Check failed: ' . $this->errorText($e), 'error');
            $result['message'] = $e->getMessage();
        } finally {
            $this->store->setState('check_running', '');
        }

        return $result;
    }

    /** Exception text for the activity log; file and line only when debug logging is enabled. */
    private function errorText(\Throwable $e): string
    {
        $text = $e->getMessage();

        if ($this->params->get('debug_log', 0)) {
            $text .= ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }

        return $text;
    }

    /** True if the last successful check is recent enough to base reminders/reports on. */
    private function checkIsFresh(): bool
    {
        $last = $this->store->getState('last_success');

        if ($last === '') {
            return false;
        }

        $maxAge = max(1, (int) $this->params->get('max_check_age_hours', 48)) * 3600;

        return (time() - strtotime($last . ' UTC')) <= $maxAge;
    }

    private function acquireLock(): bool
    {
        $db = $this->db;

        if ($this->store->getState('check_running', "\0none") === "\0none") {
            $this->store->setState('check_running', '');
        }

        $now   = (string) time();
        $stale = (string) (time() - self::LOCK_TTL);
        $q     = $db->getQuery(true)->update($db->quoteName('#__updatemonitor_state'))
            ->set($db->quoteName('value') . ' = :now')
            ->where($db->quoteName('name') . ' = ' . $db->quote('check_running'))
            ->where('(' . $db->quoteName('value') . ' = ' . $db->quote('') . ' OR CAST(' . $db->quoteName('value') . ' AS UNSIGNED) < :stale)')
            ->bind(':now', $now)->bind(':stale', $stale);
        $db->setQuery($q)->execute();

        return $db->getAffectedRows() > 0;
    }

    /** Same as the "Find Updates" button in com_installer. */
    private function refreshUpdates(): void
    {
        $model = Factory::getApplication()
            ->bootComponent('com_installer')
            ->getMVCFactory()
            ->createModel('Update', 'Administrator', ['ignore_request' => true]);

        $model->findUpdates(0, 0, (int) ComponentHelper::getParams('com_installer')->get('minimum_stability', 4));
    }

    private static function typeLabel(string $type, string $element): string
    {
        if ($type === 'file' && $element === 'joomla') {
            return 'Joomla Core';
        }

        if ($type === 'language' || ($type === 'package' && preg_match('/^pkg_[a-z]{2,3}-[A-Z]{2}$/', $element))) {
            return 'Language Pack';
        }

        return ['plugin' => 'Plugin', 'module' => 'Module', 'component' => 'Component', 'template' => 'Template', 'library' => 'Library', 'package' => 'Package', 'file' => 'File'][$type] ?? ucfirst($type);
    }

    private function fetchPending(): array
    {
        $db = $this->db;
        $q  = $db->getQuery(true)
            ->select($db->quoteName(
                ['u.extension_id', 'u.name', 'u.element', 'u.type', 'u.folder', 'u.client_id', 'u.version', 'u.infourl', 'u.changelogurl', 'e.manifest_cache'],
                ['extension_id', 'name', 'element', 'type', 'folder', 'client_id', 'available', 'infourl', 'changelogurl', 'manifest_cache']
            ))
            ->from($db->quoteName('#__updates', 'u'))
            ->join('INNER', $db->quoteName('#__extensions', 'e') . ' ON ' . $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('u.extension_id'))
            ->where($db->quoteName('u.extension_id') . ' > 0');

        $out     = [];
        $total   = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__updates'))->loadResult();
        $linked  = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__updates') . ' WHERE ' . $db->quoteName('extension_id') . ' > 0')->loadResult();
        $rows    = $db->setQuery($q)->loadAssocList();
        $skipped = 0;

        foreach ($rows as $r) {
            $installed = (json_decode((string) $r['manifest_cache'], true) ?: [])['version'] ?? '?';

            if ($installed !== '?' && version_compare($r['available'], $installed, '<=')) {
                $skipped++;
                continue;
            }

            $out[] = [
                'ident'        => implode('|', [$r['type'], $r['folder'], $r['client_id'], $r['element']]),
                'extension_id' => (int) $r['extension_id'],
                'element'      => $r['element'],
                'type'         => $r['type'],
                'folder'       => (string) $r['folder'],
                'client_id'    => (int) $r['client_id'],
                'name'         => $r['name'],
                'type_label'   => self::typeLabel($r['type'], $r['element']),
                'installed'    => $installed,
                'available'    => $r['available'],
                'infourl'      => Severity::safeUrl((string) $r['infourl']),
                'changelogurl' => Severity::safeUrl((string) $r['changelogurl']),
            ];
        }

        // #__updates also holds packages offered by update servers that are NOT installed
        // (extension_id = 0, e.g. the full list of translation packs). They are ignored on purpose.
        // Only warn if entries belonging to installed extensions could not be matched.
        $this->store->log('check', sprintf(
            'Installer update list (#__updates): %d entries, %d for installed extensions (%d matched, %d skipped as not newer than installed, %d counted), %d for packages that are not installed (ignored).',
            $total,
            $linked,
            \count($rows),
            $skipped,
            \count($out),
            $total - $linked
        ), $linked > \count($rows) ? 'warning' : 'info');

        return $out;
    }

    private function getRow(string $uid): ?object
    {
        $db = $this->db;
        $q  = $db->getQuery(true)->select('*')->from($db->quoteName('#__updatemonitor_updates'))
            ->where($db->quoteName('uid') . ' = :u')->bind(':u', $uid);

        return $db->setQuery($q)->loadObject() ?: null;
    }

    /** @return array{new:int,resolved:int} */
    private function sync(array $current, bool $ok): array
    {
        $db       = $this->db;
        $now      = Store::now();
        $stats    = ['new' => 0, 'resolved' => 0];
        $seen     = [];
        $idents   = [];
        $fetchLog = (bool) $this->params->get('fetch_changelog', 1);

        // time budget for fetching changelogs, so many slow servers cannot stall a run
        Severity::$deadline = time() + 120;

        foreach ($current as $c) {
            $uid            = sha1($c['ident'] . '|' . $c['available']);
            $seen[$uid]     = true;
            $idents[$c['ident']] = true;
            $row            = $this->getRow($uid);
            $sec            = ($fetchLog && $c['changelogurl'] !== '') ? Severity::fetch($c['changelogurl'], $c['available']) : null;

            if (!$row) {
                $this->store->insert('#__updatemonitor_updates', [
                    'uid'               => $uid,
                    'ident'             => $c['ident'],
                    'extension_id'      => $c['extension_id'],
                    'element'           => $c['element'],
                    'type'              => $c['type'],
                    'folder'            => $c['folder'],
                    'client_id'         => $c['client_id'],
                    'name'              => $c['name'],
                    'type_label'        => $c['type_label'],
                    'installed_version' => $c['installed'],
                    'available_version' => $c['available'],
                    'severity'          => $sec['severity'] ?? '',
                    'security_info'     => $sec && $sec['items'] ? json_encode($sec['items'], JSON_UNESCAPED_UNICODE) : null,
                    'infourl'           => $c['infourl'],
                    'changelogurl'      => $c['changelogurl'],
                    'status'            => 'pending',
                    'first_detected'    => $now,
                    'last_seen'         => $now,
                ]);
                $stats['new']++;
                $this->store->log('detected', sprintf('New update detected: %s %s → %s%s', $c['name'], $c['installed'], $c['available'], ($sec['severity'] ?? '') !== '' ? ' [security: ' . $sec['severity'] . ']' : ''));
                continue;
            }

            $upd = (object) [
                'id'                => $row->id,
                'installed_version' => $c['installed'],
                'last_seen'         => $now,
                'missed'            => 0,
                'infourl'           => $c['infourl'],
                'changelogurl'      => $c['changelogurl'],
            ];

            if ($row->status === 'resolved') {
                $upd->status         = 'pending';
                $upd->resolved_at    = null;
                $upd->resolve_reason = '';
                $this->store->log('detected', sprintf('Update reappeared: %s → %s', $c['name'], $c['available']));
            }

            if ($sec !== null) {
                $upd->severity      = $sec['severity'];
                $upd->security_info = $sec['items'] ? json_encode($sec['items'], JSON_UNESCAPED_UNICODE) : null;

                if (Severity::rank($sec['severity']) > Severity::rank((string) $row->severity)) {
                    $this->store->log('detected', sprintf('Security severity increased for %s %s: %s → %s', $c['name'], $c['available'], Severity::label((string) $row->severity), Severity::label($sec['severity'])), 'warning');
                }
            }

            $this->store->update('#__updatemonitor_updates', (array) $upd, true);
        }

        // Resolution handling – only after a fully successful update search
        if ($ok) {
            $q = $db->getQuery(true)->select('*')->from($db->quoteName('#__updatemonitor_updates'))->where($db->quoteName('status') . ' = ' . $db->quote('pending'));

            foreach ($db->setQuery($q)->loadObjectList() as $row) {
                if (isset($seen[$row->uid])) {
                    continue;
                }

                if (isset($idents[$row->ident])) {
                    $this->resolve($row, 'superseded');
                    $stats['resolved']++;
                    continue;
                }

                $eq = $db->getQuery(true)->select($db->quoteName('manifest_cache'))->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('extension_id') . ' = :id')->bind(':id', $row->extension_id, ParameterType::INTEGER);
                $mc = $db->setQuery($eq)->loadResult();

                if ($mc === null) {
                    $this->resolve($row, 'removed');
                    $stats['resolved']++;
                    continue;
                }

                $installed = (json_decode((string) $mc, true) ?: [])['version'] ?? '0';

                if (version_compare($installed, $row->available_version, '>=')) {
                    $this->resolve($row, 'installed');
                    $stats['resolved']++;
                    continue;
                }

                // Not listed, but not installed either (update site hiccup, stability change, withdrawn release).
                // Do not write a "resolved" entry immediately.
                $missed = (int) $row->missed + 1;

                if ($missed >= self::MISSED_LIMIT) {
                    $this->resolve($row, 'withdrawn');
                    $stats['resolved']++;
                } else {
                    $this->store->update('#__updatemonitor_updates', ['id' => $row->id, 'missed' => $missed]);
                }
            }
        }

        return $stats;
    }

    private function resolve(object $row, string $reason): void
    {
        $this->store->update('#__updatemonitor_updates', [
            'id'             => $row->id,
            'status'         => 'resolved',
            'resolved_at'    => Store::now(),
            'resolve_reason' => $reason,
        ]);

        $this->store->log('resolved', sprintf('Update resolved (%s): %s %s', $reason, $row->name, $row->available_version));
    }

    private function cleanup(): void
    {
        if (!$this->params->get('auto_cleanup', 1)) {
            return;
        }

        $today = gmdate('Y-m-d');

        if ($this->store->getState('last_cleanup') === $today) {
            return;
        }

        $db      = $this->db;
        $logDays = max(1, (int) $this->params->get('log_retention_days', 90));
        $histDays = (int) $this->params->get('history_retention_days', 365);

        $cut = gmdate('Y-m-d H:i:s', time() - $logDays * 86400);
        $q   = $db->getQuery(true)->delete($db->quoteName('#__updatemonitor_log'))->where($db->quoteName('created') . ' < :c')->bind(':c', $cut);
        $db->setQuery($q)->execute();
        $logDeleted = $db->getAffectedRows();

        $histDeleted = 0;

        if ($histDays > 0) {
            $cut = gmdate('Y-m-d H:i:s', time() - $histDays * 86400);
            $q   = $db->getQuery(true)->delete($db->quoteName('#__updatemonitor_updates'))
                ->where($db->quoteName('status') . ' = ' . $db->quote('resolved'))->where($db->quoteName('resolved_at') . ' < :c')->bind(':c', $cut);
            $db->setQuery($q)->execute();
            $histDeleted = $db->getAffectedRows();
        }

        $this->store->setState('last_cleanup', $today);
        $this->store->log('cleanup', sprintf('Cleanup: %d log entries and %d history entries removed.', $logDeleted, $histDeleted));
    }
}
