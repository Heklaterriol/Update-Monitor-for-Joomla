<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** Classifies an update as major, minor or patch from the first three version numbers. */
final class VersionJump
{
    private const COLORS = ['major' => '#6a1b9a', 'minor' => '#1565c0', 'patch' => '#2e7d32'];

    /** @return string major|minor|patch, or '' if a version cannot be read */
    public static function type(string $from, string $to): string
    {
        $a = self::parts($from);
        $b = self::parts($to);

        if ($a === null || $b === null) {
            return '';
        }

        if ($a[0] !== $b[0]) {
            return 'major';
        }

        return $a[1] !== $b[1] ? 'minor' : 'patch';
    }

    /** @return int[]|null */
    private static function parts(string $v): ?array
    {
        if (!preg_match('/^\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/i', $v, $m)) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    }

    public static function badge(string $type): string
    {
        if ($type === '') {
            return '';
        }

        $cls = ['major' => 'bg-dark', 'minor' => 'bg-primary', 'patch' => 'bg-success'][$type];
        $key = 'COM_UPDATEMONITOR_JUMP_' . strtoupper($type);

        return '<span class="badge ' . $cls . '" title="' . htmlspecialchars(Text::_($key . '_HINT'), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(Text::_($key), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public static function emailBadge(string $type): string
    {
        if ($type === '') {
            return '';
        }

        return '<span style="background:' . self::COLORS[$type] . ';color:#fff;padding:1px 7px;border-radius:10px;font-size:11px">'
            . htmlspecialchars(Text::_('COM_UPDATEMONITOR_JUMP_' . strtoupper($type)), ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
