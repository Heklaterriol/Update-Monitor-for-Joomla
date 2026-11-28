<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Registry\Registry;

/** Recipients, mail composition, notifications, reminders and reports. */
final class Notifier
{
    private const TD = 'border:1px solid #ccc;padding:6px 10px;vertical-align:top;text-align:left;';

    public function __construct(private Store $store, private Registry $params)
    {
    }

    // ---------------------------------------------------------------- recipients

    /** @return array<string,string> email => name */
    public function recipients(): array
    {
        $out = [];

        if ($this->params->get('include_super_users', 1)) {
            $db = $this->store->db;
            $q  = $db->getQuery(true)->select($db->quoteName(['id', 'email', 'name']))->from($db->quoteName('#__users'))
                ->where($db->quoteName('block') . ' = 0')->where($db->quoteName('sendEmail') . ' = 1');
            $factory = Factory::getContainer()->get(UserFactoryInterface::class);

            foreach ($db->setQuery($q)->loadObjectList() as $row) {
                if ($factory->loadUserById((int) $row->id)->authorise('core.admin')) {
                    $out[strtolower($row->email)] = $row->name;
                }
            }
        }

        foreach (preg_split('/[\s,;]+/', (string) $this->params->get('extra_recipients', ''), -1, PREG_SPLIT_NO_EMPTY) as $mail) {
            if (filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $out[strtolower($mail)] = '';
            }
        }

        return $out;
    }

    private function adminBase(): string
    {
        $base = Severity::safeUrl((string) $this->params->get('site_url', ''));

        return rtrim($base !== '' ? $base : Uri::root(), '/') . '/administrator/';
    }

    private function send(array $recipients, string $subject, string $html): int
    {
        $sent = 0;

        foreach ($recipients as $email => $name) {
            try {
                $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
                $mailer->addRecipient($email, $name);
                $mailer->setSubject($subject);
                $mailer->isHtml(true);
                $mailer->setBody($html);

                if ($mailer->send() === true) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->store->log('notification', 'Mail to ' . $email . ' failed: ' . $e->getMessage(), 'error');
            }
        }

        return $sent;
    }

    public function sendTest(): int
    {
        $r = $this->recipients();

        if (!$r) {
            return 0;
        }

        $n = $this->send($r, Text::sprintf('COM_UPDATEMONITOR_MAIL_TEST_SUBJECT', $this->site()), $this->wrap('<p>' . $this->e(Text::_('COM_UPDATEMONITOR_MAIL_TEST_BODY')) . '</p>'));
        $this->store->log('notification', 'Test mail sent to ' . $n . ' recipient(s).');

        return $n;
    }

    // ---------------------------------------------------------------- rendering

    private function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private function site(): string
    {
        return (string) Factory::getApplication()->get('sitename');
    }

    private function wrap(string $inner): string
    {
        $links = '<p><a href="' . $this->e($this->adminBase() . 'index.php?option=com_installer&view=update') . '">' . $this->e(Text::_('COM_UPDATEMONITOR_MAIL_LINK_UPDATES')) . '</a><br>'
            . $this->e($this->adminBase() . 'index.php?option=com_installer&view=update') . '</p>'
            . '<p><a href="' . $this->e($this->adminBase() . 'index.php?option=com_updatemonitor') . '">' . $this->e(Text::_('COM_UPDATEMONITOR_MAIL_LINK_MONITOR')) . '</a></p>';

        return '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">' . $inner . $links . '</div>';
    }

    /** @param array<int,array{row:object,note:string}> $items */
    private function table(array $items, bool $note): string
    {
        $th = static fn (string $k) => '<th style="' . self::TD . 'background:#f0f0f0">' . htmlspecialchars(Text::_($k), ENT_QUOTES, 'UTF-8') . '</th>';
        $h  = '<table style="border-collapse:collapse;font-size:14px"><thead><tr>'
            . $th('COM_UPDATEMONITOR_COL_NAME') . $th('COM_UPDATEMONITOR_COL_TYPE') . $th('COM_UPDATEMONITOR_COL_INSTALLED')
            . $th('COM_UPDATEMONITOR_COL_AVAILABLE') . $th('COM_UPDATEMONITOR_COL_SEVERITY') . ($note ? $th('COM_UPDATEMONITOR_MAIL_COL_NOTE') : '')
            . '</tr></thead><tbody>';

        foreach ($items as $it) {
            $r    = $it['row'];
            $link = Severity::safeUrl((string) $r->infourl);
            $name = $link !== '' ? '<a href="' . $this->e($link) . '">' . $this->e($r->name) . '</a>' : $this->e($r->name);
            $info = '';

            foreach (array_slice(json_decode((string) $r->security_info, true) ?: [], 0, 3) as $si) {
                $info .= '<div style="font-size:12px;color:#555">' . $this->e(mb_substr($si['text'], 0, 200)) . '</div>';
            }

            $td = '<td style="' . self::TD . '">';
            $h .= '<tr>' . $td . $name . '</td>' . $td . $this->e($r->type_label) . '</td>' . $td . $this->e($r->installed_version) . '</td>'
                . $td . $this->e($r->available_version) . '</td>' . $td . Severity::emailBadge((string) $r->severity) . $info . '</td>'
                . ($note ? $td . $this->e($it['note']) . '</td>' : '') . '</tr>';
        }

        return $h . '</tbody></table>';
    }

    private function openNote(object $r): string
    {
        return Text::sprintf('COM_UPDATEMONITOR_MAIL_NOTE_OPEN_SINCE', substr((string) $r->first_detected, 0, 10));
    }

    private function heading(string $key, string $color = '#333'): string
    {
        return '<h3 style="font-size:16px;margin:22px 0 8px;padding-left:8px;border-left:4px solid ' . $color . '">' . $this->e(Text::_($key)) . '</h3>';
    }

    /**
     * Mail body sections: Joomla Core (separate), new/changed updates, further open updates.
     * Every open update ends up in exactly one section.
     *
     * @param array<int,array{row:object,note:string}> $changed new or changed in this mail
     * @param array<int,array{row:object,note:string}> $open    other open updates
     */
    private function sections(array $changed, array $open): string
    {
        $isCore = static fn (array $i): bool => $i['row']->type_label === 'Joomla Core';
        $core   = array_values(array_filter(array_merge($changed, $open), $isCore));
        $chg    = array_values(array_filter($changed, static fn ($i) => !$isCore($i)));
        $opn    = array_values(array_filter($open, static fn ($i) => !$isCore($i)));
        $h      = '';

        if ($core) {
            $h .= $this->heading('COM_UPDATEMONITOR_MAIL_SECTION_CORE', '#f4511e') . $this->table($core, true);
        }

        if ($chg) {
            $h .= $this->heading('COM_UPDATEMONITOR_MAIL_SECTION_NEW', '#d32f2f') . $this->table($chg, true);
        }

        if ($opn) {
            $h .= $this->heading('COM_UPDATEMONITOR_MAIL_SECTION_OPEN', '#757575') . $this->table($opn, true);
        }

        return $h;
    }

    private function subjectPrefix(array $items): string
    {
        $max = '';

        foreach ($items as $it) {
            $max = Severity::max($max, (string) $it['row']->severity);
        }

        return $max === '' ? '' : Text::sprintf('COM_UPDATEMONITOR_MAIL_SECURITY_PREFIX', strtoupper(Severity::label($max))) . ' ';
    }

    private function pending(bool $ignored = false): array
    {
        $db = $this->store->db;
        $q  = $db->getQuery(true)->select('*')->from($db->quoteName('#__updatemonitor_updates'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('pending'))->where($db->quoteName('ignored') . ' = ' . ($ignored ? 1 : 0))
            ->order($db->quoteName('name'));

        return $db->setQuery($q)->loadObjectList();
    }

    // ---------------------------------------------------------------- notifications

    public function notifyChanges(): int
    {
        $items = [];

        foreach ($this->pending() as $r) {
            if ($r->notified_at === null && $this->params->get('notify_new', 1)) {
                $items[] = ['row' => $r, 'note' => Text::_('COM_UPDATEMONITOR_MAIL_NOTE_NEW'), 'prev' => null];
            } elseif ($r->notified_at !== null && $this->params->get('notify_increase', 1)
                && Severity::rank((string) $r->severity) > Severity::rank((string) $r->notified_severity)) {
                $items[] = ['row' => $r, 'note' => Text::sprintf('COM_UPDATEMONITOR_MAIL_NOTE_INCREASED', Severity::label((string) $r->notified_severity), Severity::label((string) $r->severity)), 'prev' => $r];
            }
        }

        if (!$items) {
            return 0;
        }

        $changedIds = array_map(static fn ($i) => $i['row']->id, $items);
        $open       = [];

        foreach ($this->pending() as $r) {
            if (!\in_array($r->id, $changedIds, true)) {
                $open[] = ['row' => $r, 'note' => $this->openNote($r)];
            }
        }

        $recipients = $this->recipients();

        if (!$recipients) {
            $this->store->log('notification', 'No recipients configured / no Super User with "Receive System Emails".', 'warning');

            return 0;
        }

        // Claim first (protects against duplicate mails), revert if nothing could be sent
        $db   = $this->store->db;
        $now  = Store::now();
        $orig = [];

        foreach ($items as $it) {
            $r = $it['row'];
            $orig[$r->id] = (object) ['id' => $r->id, 'notified_at' => $r->notified_at, 'notified_severity' => $r->notified_severity, 'notify_count' => $r->notify_count];
            $this->store->update('#__updatemonitor_updates', [
                'id' => $r->id, 'notified_at' => $now, 'notified_severity' => $r->severity, 'notify_count' => (int) $r->notify_count + 1, 'last_reminder_at' => null,
            ], true);
        }

        $subject = $this->subjectPrefix($items) . Text::sprintf('COM_UPDATEMONITOR_MAIL_SUBJECT_NEW', $this->site(), \count($items));
        $body    = $this->wrap(
            '<p>' . $this->e(Text::sprintf('COM_UPDATEMONITOR_MAIL_INTRO_NEW', $this->site(), \count($items))) . ' '
            . $this->e(Text::sprintf('COM_UPDATEMONITOR_MAIL_TOTAL_OPEN', \count($items) + \count($open))) . '</p>'
            . $this->sections($items, $open)
        );
        $sent    = $this->send($recipients, $subject, $body);

        if ($sent === 0) {
            foreach ($orig as $o) {
                $this->store->update('#__updatemonitor_updates', (array) $o, true);
            }
        }

        $this->store->log('notification', sprintf('Notification for %d update(s) sent to %d of %d recipient(s).', \count($items), $sent, \count($recipients)), $sent ? 'info' : 'error');

        return $sent;
    }

    /** Mail about NEW vulnerable-extension and end-of-life findings (once per finding). */
    public function notifyFindings(array $findings): int
    {
        if (!$this->params->get('notify_findings', 1)) {
            return 0;
        }

        $current     = array_values(array_filter($findings, static fn ($f) => \in_array($f['kind'], ['vel', 'eol'], true)));
        $currentKeys = array_map(static fn ($f) => $f['key'], $current);
        $known       = json_decode($this->store->getState('findings_notified', '[]'), true) ?: [];
        $keep        = array_values(array_intersect($known, $currentKeys));
        $new         = array_values(array_filter($current, static fn ($f) => !\in_array($f['key'], $known, true)));

        if (!$new) {
            if ($keep !== $known) {
                $this->store->setState('findings_notified', (string) json_encode($keep));
            }

            return 0;
        }

        $recipients = $this->recipients();

        if (!$recipients) {
            $this->store->log('notification', 'No recipients for security findings.', 'warning');

            return 0;
        }

        // claim first (no duplicate mails), revert if nothing could be sent
        $this->store->setState('findings_notified', (string) json_encode(array_values(array_unique(array_merge($keep, array_map(static fn ($f) => $f['key'], $new))))));

        $max = '';

        foreach ($new as $f) {
            $max = Severity::max($max, $f['severity']);
        }

        $prefix  = $max !== '' ? Text::sprintf('COM_UPDATEMONITOR_MAIL_SECURITY_PREFIX', strtoupper(Severity::label($max))) . ' ' : '';
        $subject = $prefix . Text::sprintf('COM_UPDATEMONITOR_MAIL_SUBJECT_FINDINGS', $this->site(), \count($new));
        $body    = $this->wrap('<p>' . $this->e(Text::sprintf('COM_UPDATEMONITOR_MAIL_INTRO_FINDINGS', $this->site())) . '</p>' . $this->findingsTable($new));
        $sent    = $this->send($recipients, $subject, $body);

        if ($sent === 0) {
            $this->store->setState('findings_notified', (string) json_encode($keep));
        }

        $this->store->log('notification', sprintf('Security findings (%d new) sent to %d of %d recipient(s).', \count($new), $sent, \count($recipients)), $sent ? 'info' : 'error');

        return $sent;
    }

    /** @param array<int,array<string,string>> $findings */
    private function findingsTable(array $findings): string
    {
        $th = static fn (string $k) => '<th style="' . self::TD . 'background:#f0f0f0">' . htmlspecialchars(Text::_($k), ENT_QUOTES, 'UTF-8') . '</th>';
        $h  = '<table style="border-collapse:collapse;font-size:14px"><thead><tr>'
            . $th('COM_UPDATEMONITOR_COL_SEVERITY') . $th('COM_UPDATEMONITOR_MAIL_COL_FINDING') . $th('COM_UPDATEMONITOR_MAIL_COL_DETAILS')
            . '</tr></thead><tbody>';

        foreach ($findings as $f) {
            $td    = '<td style="' . self::TD . '">';
            $link  = Severity::safeUrl((string) $f['url']);
            $title = $link !== '' ? '<a href="' . $this->e($link) . '">' . $this->e($f['title']) . '</a>' : $this->e($f['title']);
            $h    .= '<tr>' . $td . Severity::emailBadge((string) $f['severity']) . '</td>' . $td . $title . '</td>' . $td . $this->e($f['detail']) . '</td></tr>';
        }

        return $h . '</tbody></table>';
    }

    public function remind(): int
    {
        $days = (int) $this->params->get('reminder_days', 7);

        if ($days <= 0) {
            return 0;
        }

        $threshold = time() - $days * 86400;
        $items     = [];

        foreach ($this->pending() as $r) {
            $last = $r->last_reminder_at ?: $r->notified_at;

            if ($last !== null && strtotime($last . ' UTC') <= $threshold) {
                $items[] = ['row' => $r, 'note' => ''];
            }
        }

        $recipients = $this->recipients();

        if (!$items || !$recipients) {
            return 0;
        }

        $db  = $this->store->db;
        $now = Store::now();

        foreach ($items as $it) {
            $this->store->update('#__updatemonitor_updates', ['id' => $it['row']->id, 'last_reminder_at' => $now]);
        }

        $all = array_map(fn ($r) => ['row' => $r, 'note' => $this->openNote($r)], $this->pending());

        $subject = $this->subjectPrefix($all) . Text::sprintf('COM_UPDATEMONITOR_MAIL_SUBJECT_REMINDER', $this->site(), \count($all));
        $body    = $this->wrap('<p>' . $this->e(Text::sprintf('COM_UPDATEMONITOR_MAIL_INTRO_REMINDER', $this->site(), \count($all))) . '</p>' . $this->sections([], $all));
        $sent    = $this->send($recipients, $subject, $body);
        $this->store->log('reminder', sprintf('Reminder for %d pending update(s) sent to %d recipient(s).', \count($items), $sent), $sent ? 'info' : 'error');

        return $sent;
    }

    // ---------------------------------------------------------------- reports

    public function reports(): void
    {
        $tz = new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC'));
        $d  = new \DateTime('now', $tz);

        if ($this->params->get('report_weekly', 0)) {
            $key = $d->format('o-W');

            if ($this->store->getState('last_weekly') !== $key && (int) $d->format('N') >= (int) $this->params->get('report_weekday', 1)) {
                $this->store->setState('last_weekly', $key);
                $this->sendReport('weekly');
            }
        }

        if ($this->params->get('report_monthly', 0)) {
            $key = $d->format('Y-m');

            if ($this->store->getState('last_monthly') !== $key && (int) $d->format('j') >= min(28, max(1, (int) $this->params->get('report_monthday', 1)))) {
                $this->store->setState('last_monthly', $key);
                $this->sendReport('monthly');
            }
        }
    }

    public function sendReport(string $period): int
    {
        $recipients = $this->recipients();

        if (!$recipients) {
            return 0;
        }

        $db    = $this->store->db;
        $since = gmdate('Y-m-d H:i:s', strtotime($period === 'weekly' ? '-7 days' : '-1 month'));

        $count = function (string $col) use ($db, $since): int {
            $q = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__updatemonitor_updates'))->where($db->quoteName($col) . ' >= :s')->bind(':s', $since);

            return (int) $db->setQuery($q)->loadResult();
        };

        $pending = $this->pending();
        $ignored = \count($this->pending(true));
        $items   = array_map(fn ($r) => ['row' => $r, 'note' => $this->openNote($r)], $pending);

        $bySev = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0];

        foreach ($pending as $r) {
            if (isset($bySev[$r->severity])) {
                $bySev[$r->severity]++;
            }
        }

        $sevLine = [];

        foreach ($bySev as $k => $v) {
            if ($v) {
                $sevLine[] = Severity::label($k) . ': ' . $v;
            }
        }

        $html = '<p>' . $this->e(Text::sprintf('COM_UPDATEMONITOR_MAIL_INTRO_REPORT', $this->site())) . '</p><ul>'
            . '<li>' . $this->e(Text::_('COM_UPDATEMONITOR_MAIL_REPORT_PENDING')) . ': <strong>' . \count($pending) . '</strong>' . ($sevLine ? ' (' . $this->e(implode(', ', $sevLine)) . ')' : '') . '</li>'
            . '<li>' . $this->e(Text::_('COM_UPDATEMONITOR_MAIL_REPORT_NEW')) . ': ' . $count('first_detected') . '</li>'
            . '<li>' . $this->e(Text::_('COM_UPDATEMONITOR_MAIL_REPORT_RESOLVED')) . ': ' . $count('resolved_at') . '</li>'
            . '<li>' . $this->e(Text::_('COM_UPDATEMONITOR_MAIL_REPORT_IGNORED')) . ': ' . $ignored . '</li></ul>'
            . $this->sections([], $items);

        $subject = $this->subjectPrefix($items) . Text::sprintf('COM_UPDATEMONITOR_MAIL_SUBJECT_REPORT_' . strtoupper($period), $this->site());
        $sent    = $this->send($recipients, $subject, $this->wrap($html));
        $this->store->log('report', sprintf('%s report sent to %d recipient(s).', ucfirst($period), $sent), $sent ? 'info' : 'error');

        return $sent;
    }
}
