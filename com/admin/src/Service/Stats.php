<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Time to fix: from the first detection of an update until the new version was installed.
 * Only history entries closed with the reason "installed" count as fixed; withdrawn, removed and
 * superseded entries are no remediation.
 */
final class Stats
{
    public const GROUPS = ['critical', 'high', 'medium', 'low', 'unknown', 'other'];

    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
    }

    /** Target in days for a severity ('' = no security info). 0 = no target. */
    public function targetDays(string $severity): int
    {
        $options  = ['critical' => 'sla_critical', 'high' => 'sla_high', 'medium' => 'sla_medium', 'low' => 'sla_low', 'unknown' => 'sla_medium', 'other' => 'sla_other'];
        $defaults = ['critical' => 2, 'high' => 7, 'medium' => 30, 'low' => 90, 'unknown' => 30, 'other' => 60];
        $group    = $severity === '' ? 'other' : $severity;

        return max(0, (int) $this->params->get($options[$group] ?? 'sla_other', $defaults[$group] ?? 60));
    }

    public static function human(?int $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }

        if ($seconds < 3600) {
            return '<1 h';
        }

        return $seconds < 172800 ? round($seconds / 3600) . ' h' : round($seconds / 86400, 1) . ' d';
    }

    private static function median(array $list): ?int
    {
        if (!$list) {
            return null;
        }

        sort($list);
        $n = \count($list);

        $mid = intdiv($n, 2);

        return $n % 2 ? (int) $list[$mid] : (int) round(($list[$mid - 1] + $list[$mid]) / 2);
    }

    /** @return array<string,mixed> */
    public function compute(int $days): array
    {
        $db    = $this->db;
        $now   = time();
        $since = $days > 0 ? $now - $days * 86400 : 0;
        $q     = $db->getQuery(true)
            ->select($db->quoteName(['name', 'type_label', 'installed_version', 'available_version', 'severity', 'status', 'ignored', 'first_detected', 'resolved_at', 'resolve_reason']))
            ->from($db->quoteName('#__updatemonitor_updates'));
        $rows = $db->setQuery($q)->loadObjectList();

        $fixed = $open = [];

        foreach (array_merge(self::GROUPS, ['all']) as $g) {
            $fixed[$g] = ['n' => 0, 'sum' => 0, 'max' => 0, 'list' => [], 'within' => 0, 'targeted' => 0];
            $open[$g]  = ['n' => 0, 'sum' => 0, 'max' => 0, 'overdue' => 0];
        }

        $oldest = [];
        $months = [];
        $first  = new \DateTime('first day of this month 00:00:00', new \DateTimeZone('UTC'));

        for ($i = 5; $i >= 0; $i--) {
            $start  = (clone $first)->modify('-' . $i . ' month');
            $end    = (clone $start)->modify('+1 month');
            $months[$start->format('Y-m')] = ['from' => $start->getTimestamp(), 'to' => $end->getTimestamp(), 'detected' => 0, 'fixed' => 0, 'list' => []];
        }

        foreach ($rows as $r) {
            $g      = $r->severity !== '' ? $r->severity : 'other';
            $g      = isset($fixed[$g]) ? $g : 'other';
            $firstTs = strtotime($r->first_detected . ' UTC');
            $target = $this->targetDays((string) $r->severity) * 86400;

            foreach ($months as &$m) {
                if ($firstTs >= $m['from'] && $firstTs < $m['to']) {
                    $m['detected']++;
                }
            }

            unset($m);

            if ($r->status === 'resolved') {
                if ($r->resolve_reason !== 'installed' || empty($r->resolved_at)) {
                    continue;
                }

                $resTs = strtotime($r->resolved_at . ' UTC');
                $d     = max(0, $resTs - $firstTs);

                foreach ($months as &$m) {
                    if ($resTs >= $m['from'] && $resTs < $m['to']) {
                        $m['fixed']++;
                        $m['list'][] = $d;
                    }
                }

                unset($m);

                if ($resTs < $since) {
                    continue;
                }

                foreach ([$g, 'all'] as $k) {
                    $fixed[$k]['n']++;
                    $fixed[$k]['sum'] += $d;
                    $fixed[$k]['max']  = max($fixed[$k]['max'], $d);
                    $fixed[$k]['list'][] = $d;

                    if ($target > 0) {
                        $fixed[$k]['targeted']++;
                        $fixed[$k]['within'] += $d <= $target ? 1 : 0;
                    }
                }

                continue;
            }

            if ($r->status === 'pending' && !(int) $r->ignored) {
                $age  = max(0, $now - $firstTs);
                $over = $target > 0 && $age > $target;

                foreach ([$g, 'all'] as $k) {
                    $open[$k]['n']++;
                    $open[$k]['sum'] += $age;
                    $open[$k]['max']  = max($open[$k]['max'], $age);
                    $open[$k]['overdue'] += $over ? 1 : 0;
                }

                $oldest[] = [
                    'name' => $r->name, 'type' => $r->type_label, 'installed' => $r->installed_version, 'available' => $r->available_version,
                    'severity' => (string) $r->severity, 'age' => $age, 'target' => $target / 86400, 'overdue' => $over,
                ];
            }
        }

        $fin = static fn (array $a): array => [
            'n'        => $a['n'],
            'median'   => self::median($a['list']),
            'avg'      => $a['n'] ? (int) round($a['sum'] / $a['n']) : null,
            'max'      => $a['n'] ? $a['max'] : null,
            'within'   => $a['within'],
            'targeted' => $a['targeted'],
        ];

        usort($oldest, static fn ($a, $b) => $b['age'] <=> $a['age']);

        $outMonths = [];

        foreach ($months as $label => $m) {
            $outMonths[$label] = ['detected' => $m['detected'], 'fixed' => $m['fixed'], 'median' => self::median($m['list'])];
        }

        return [
            'days'   => $days,
            'fixed'  => array_map($fin, $fixed),
            'open'   => array_map(static fn (array $a): array => [
                'n' => $a['n'], 'avg' => $a['n'] ? (int) round($a['sum'] / $a['n']) : null, 'max' => $a['n'] ? $a['max'] : null, 'overdue' => $a['overdue'],
            ], $open),
            'oldest' => \array_slice($oldest, 0, 10),
            'months' => $outMonths,
        ];
    }
}
