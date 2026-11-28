<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Security findings that do not depend on a pending update:
 *  - vel       installed extensions listed in the Joomla Vulnerable Extensions List (VEL)
 *  - eol       PHP, database server and Joomla versions at or near end of life
 *  - disabled  disabled extensions that have a pending update
 *  - nosrc / srvoff / stale   extensions Joomla cannot check for updates ("blind spots")
 *
 * Each finding: key, kind, severity, title, detail, url.
 */
final class Findings
{
    private const VEL_URL       = 'https://extensions.joomla.org/index.php?option=com_vel&format=json';
    private const VEL_RECHECK   = 21600;   // 6 hours
    private const EOL_RECHECK   = 86400;   // 24 hours
    private const CORE_ID_LIMIT = 10000;   // Joomla core extensions have ids below 10000

    private ?array $installed = null;

    public function __construct(private Store $store, private Registry $params)
    {
    }

    /** @return array<int,array<string,string>> */
    public function collect(): array
    {
        $all   = [];
        $steps = [
            'check_vel'        => 'vel',
            'check_platform'   => 'platform',
            'check_blindspots' => 'blindSpots',
            'check_disabled'   => 'disabledOutdated',
        ];

        foreach ($steps as $option => $method) {
            if (!$this->params->get($option, 1)) {
                continue;
            }

            try {
                $all = array_merge($all, $this->$method());
            } catch (\Throwable $e) {
                $this->store->log('security', sprintf("Security check '%s' failed: %s", $method, $e->getMessage()), 'warning');
            }
        }

        usort($all, static fn ($a, $b) => Severity::rank($b['severity']) <=> Severity::rank($a['severity']));

        return $all;
    }

    private function f(string $key, string $kind, string $severity, string $title, string $detail, string $url = ''): array
    {
        return [
            'key'      => $key,
            'kind'     => $kind,
            'severity' => $severity,
            'title'    => $title,
            'detail'   => $detail,
            'url'      => Severity::safeUrl($url),
        ];
    }

    private function norm(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }

    // ------------------------------------------------------------------ installed extensions

    /** Third-party extensions (id >= 10000). Extensions inside a package are skipped by the callers that need that. */
    private function installed(): array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $db = $this->store->db;
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'enabled', 'package_id', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' >= ' . self::CORE_ID_LIMIT)
            ->whereIn($db->quoteName('type'), ['component', 'module', 'plugin', 'template', 'library', 'package'], ParameterType::STRING);

        $this->installed = [];

        foreach ($db->setQuery($q)->loadAssocList() as $r) {
            $m       = json_decode((string) $r['manifest_cache'], true) ?: [];
            $element = (string) $r['element'];

            $this->installed[] = [
                'extension_id' => (int) $r['extension_id'],
                'name'         => (string) ($m['name'] ?? $r['name']),
                'type'         => (string) $r['type'],
                'element'      => $element,
                'folder'       => (string) $r['folder'],
                'enabled'      => (int) $r['enabled'],
                'package_id'   => (int) $r['package_id'],
                'author'       => (string) ($m['author'] ?? ''),
                'version'      => (string) ($m['version'] ?? ''),
                'elementNorm'  => $this->norm((string) preg_replace('/^(com|mod|plg|tpl|lib|pkg)_/', '', $element)),
            ];
        }

        return $this->installed;
    }

    /** Readable name (manifest names are often language keys). */
    private function label(array $e): string
    {
        $name = $e['name'];

        if (!preg_match('/^[A-Z0-9_]+$/', $name)) {
            return $name;
        }

        try {
            $lang = Factory::getApplication()->getLanguage();
            $ext  = match ($e['type']) {
                'plugin'   => 'plg_' . $e['folder'] . '_' . $e['element'],
                'template' => 'tpl_' . $e['element'],
                default    => $e['element'],
            };

            foreach ([JPATH_ADMINISTRATOR, JPATH_ADMINISTRATOR . '/components/' . $ext, JPATH_PLUGINS . '/' . $e['folder'] . '/' . $e['element'], JPATH_SITE . '/modules/' . $ext] as $base) {
                $lang->load($ext . '.sys', $base);
            }

            $t = Text::_($name);

            return $t !== $name ? $t : $e['element'];
        } catch (\Throwable $ex) {
            return $e['element'];
        }
    }

    // ------------------------------------------------------------------ 1. Vulnerable Extensions List

    private function vel(): array
    {
        $installed = $this->installed();
        $instHash  = sha1((string) json_encode(array_map(static fn ($r) => [$r['extension_id'], $r['version']], $installed)));
        $cached    = json_decode($this->store->getState('vel_matches', 'null'), true);
        $last      = (int) $this->store->getState('vel_checked', '0');
        $instSame  = $this->store->getState('vel_inst') === $instHash;

        if ($cached === null || !$instSame || (time() - $last) > self::VEL_RECHECK) {
            $verify   = $this->velVerify();
            $feedSame = $verify !== '' && $verify === $this->store->getState('vel_hash');

            if ($cached === null || !$instSame || !$feedSame) {
                $feed = $this->velFeed();

                if ($feed !== null) {
                    $cached = $this->matchVel($feed, $installed);
                    $this->store->setState('vel_matches', (string) json_encode($cached, JSON_UNESCAPED_UNICODE));
                    $this->store->setState('vel_hash', $verify);
                    $this->store->setState('vel_inst', $instHash);
                } else {
                    $this->store->log('security', 'Vulnerable Extensions List could not be downloaded; using the last known result.', 'warning');
                }
            }

            $this->store->setState('vel_checked', (string) time());
        }

        $out = [];

        foreach ($cached ?: [] as $m) {
            $out[] = $this->velFinding($m);
        }

        return $out;
    }

    private function velVerify(): string
    {
        $body = Severity::download(self::VEL_URL . '&task=verify', 65536, 15);
        $json = $body !== null ? json_decode($body, true) : null;

        return \is_array($json) && \is_string($json['data'] ?? null) ? $json['data'] : '';
    }

    private function velFeed(): ?array
    {
        $body = Severity::download(self::VEL_URL, 16777216, 30);
        $json = $body !== null ? json_decode($body, true) : null;

        if (!\is_array($json)) {
            return null;
        }

        $items = $json['data']['items'] ?? $json['items'] ?? null;

        return \is_array($items) ? $items : null;
    }

    private function velAffected(int $status, string $installed, string $start, string $vulnerable, string $patch): bool
    {
        if ($installed === '') {
            return false;
        }

        if ($start !== '' && version_compare($installed, $start, '<')) {
            return false;
        }

        if ($status === 2) {
            // resolved: affected only while older than the patched version
            return $patch !== '' && version_compare($installed, $patch, '<');
        }

        // live: affected up to the latest known vulnerable version (all versions if none is given)
        if ($patch !== '' && version_compare($installed, $patch, '>=')) {
            return false;
        }

        return $vulnerable === '' || version_compare($installed, $vulnerable, '<=');
    }

    /** @return array<int,array<string,mixed>> */
    private function matchVel(array $items, array $installed): array
    {
        $matches = [];

        foreach ($items as $it) {
            $status = (int) ($it['status'] ?? 0);

            if ($status !== 1 && $status !== 2) {
                continue;
            }

            $inst = $it['install_data'] ?? [];

            if (\is_string($inst)) {
                $inst = json_decode($inst, true) ?: [];
            }

            $inst      = \is_array($inst) ? $inst : [];
            $fname     = $this->norm((string) ($inst['name'] ?? ''));
            $ftype     = strtolower((string) ($inst['type'] ?? ''));
            $fauthor   = $this->norm((string) ($inst['author'] ?? ''));
            $titleName = $this->norm(trim(explode(',', (string) ($it['title'] ?? ''))[0]));

            foreach ($installed as $e) {
                $enorm = $this->norm($e['name']);

                if ($fname !== '' && $fname === $enorm && ($ftype === '' || $ftype === $e['type'])) {
                    $eauthor = $this->norm($e['author']);

                    if ($fauthor !== '' && $eauthor !== '' && $fauthor !== $eauthor) {
                        continue;
                    }

                    $confidence = ($fauthor !== '' && $eauthor !== '') ? 'high' : 'medium';
                } elseif ($titleName !== '' && ($titleName === $enorm || $titleName === $e['elementNorm'])) {
                    $confidence = 'low';
                } else {
                    continue;
                }

                $start      = trim((string) ($it['start_version'] ?? ''));
                $vulnerable = trim((string) ($it['vulnerable_version'] ?? ''));
                $patch      = trim((string) ($it['patch_version'] ?? ''));

                if (!$this->velAffected($status, $e['version'], $start, $vulnerable, $patch)) {
                    continue;
                }

                $risk = Severity::normalize((string) ($it['risk_level'] ?? $it['risk'] ?? ''));

                if ($risk === '') {
                    $risk = $status === 1 ? 'high' : 'medium';
                }

                $matches[] = [
                    'id'          => (string) ($it['id'] ?? ''),
                    'ext_id'      => $e['extension_id'],
                    'name'        => $this->label($e),
                    'installed'   => $e['version'],
                    'status'      => $status,
                    'risk'        => $risk,
                    'patch'       => $patch,
                    'vulnerable'  => $vulnerable,
                    'cve'         => mb_substr(trim((string) ($it['cve_id'] ?? '')), 0, 120),
                    'url'         => (string) (($it['update_notice'] ?? '') ?: ($it['jed'] ?? '')),
                    'confidence'  => $confidence,
                ];
            }
        }

        return $matches;
    }

    private function velFinding(array $m): array
    {
        if ($m['status'] === 2) {
            $detail = Text::sprintf('COM_UPDATEMONITOR_F_VEL_RESOLVED', $m['patch']);
        } elseif ($m['patch'] !== '') {
            $detail = Text::sprintf('COM_UPDATEMONITOR_F_VEL_LIVE_PATCH', $m['patch']);
        } else {
            $detail = Text::_('COM_UPDATEMONITOR_F_VEL_LIVE_NOPATCH');
        }

        if ($m['cve'] !== '') {
            $detail .= ' ' . Text::sprintf('COM_UPDATEMONITOR_F_VEL_CVE', $m['cve']);
        }

        if ($m['confidence'] === 'low') {
            $detail .= ' ' . Text::_('COM_UPDATEMONITOR_F_VEL_CONF_LOW');
        }

        return $this->f(
            'vel:' . $m['id'] . ':' . $m['ext_id'],
            'vel',
            $m['risk'],
            Text::sprintf('COM_UPDATEMONITOR_F_VEL_TITLE', $m['name'], $m['installed']),
            $detail,
            $m['url'] !== '' ? $m['url'] : 'https://extensions.joomla.org/vulnerable-extensions/vulnerable'
        );
    }

    // ------------------------------------------------------------------ 2. End of life: PHP, database, Joomla

    private function platform(): array
    {
        $db     = $this->store->db;
        $dbType = (string) $db->getServerType();
        $dbVer  = preg_match('/\d+(\.\d+)*/', (string) $db->getVersion(), $m) ? $m[0] : '';

        $targets = [
            ['php', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'PHP', PHP_VERSION],
            ['joomla', implode('.', \array_slice(explode('.', JVERSION), 0, 2)), 'Joomla', JVERSION],
        ];

        if ($dbVer !== '') {
            $parts = explode('.', $dbVer);

            if ($dbType === 'postgresql') {
                $targets[] = ['postgresql', $parts[0], 'PostgreSQL', $dbVer];
            } elseif ($dbType === 'mariadb') {
                $targets[] = ['mariadb', $parts[0] . '.' . ($parts[1] ?? '0'), 'MariaDB', $dbVer];
            } else {
                $targets[] = ['mysql', $parts[0] . '.' . ($parts[1] ?? '0'), 'MySQL', $dbVer];
            }
        }

        $out   = [];
        $today = strtotime(gmdate('Y-m-d'));

        foreach ($targets as [$product, $cycle, $label, $full]) {
            $eol = $this->eolDate($product, $cycle);

            if ($eol === null) {
                continue;
            }

            $ts   = strtotime($eol);
            $days = (int) floor(($ts - $today) / 86400);
            $url  = 'https://endoflife.date/' . $product;

            if ($days <= 0) {
                $out[] = $this->f('eol:' . $product . ':' . $cycle, 'eol', 'high',
                    Text::sprintf('COM_UPDATEMONITOR_F_EOL_REACHED', $label, $full, $eol),
                    Text::_('COM_UPDATEMONITOR_F_EOL_ADVICE'), $url);
            } elseif ($days <= 180) {
                $out[] = $this->f('eol:' . $product . ':' . $cycle, 'eol', $days <= 90 ? 'medium' : 'low',
                    Text::sprintf('COM_UPDATEMONITOR_F_EOL_SOON', $label, $full, $eol, $days),
                    Text::_('COM_UPDATEMONITOR_F_EOL_ADVICE'), $url);
            }
        }

        return $out;
    }

    /** End-of-life date (Y-m-d) of a product cycle, null if unknown or still open-ended. Cached for 24 hours. */
    private function eolDate(string $product, string $cycle): ?string
    {
        $cache = json_decode($this->store->getState('eol_cache', '{}'), true) ?: [];
        $key   = $product . '|' . $cycle;

        if (isset($cache[$key]) && (time() - (int) $cache[$key]['t']) < self::EOL_RECHECK) {
            return $cache[$key]['eol'];
        }

        $eol = null;
        $ok  = false;

        // endoflife.date API v1, then the older flat format as fallback
        $body = Severity::download('https://endoflife.date/api/v1/products/' . rawurlencode($product) . '/', 1048576, 15)
            ?? Severity::download('https://endoflife.date/api/' . rawurlencode($product) . '.json', 1048576, 15);
        $json = $body !== null ? json_decode($body, true) : null;

        if (\is_array($json)) {
            $ok       = true;
            $releases = $json['result']['releases'] ?? (array_is_list($json) ? $json : []);
            $major    = explode('.', $cycle)[0];

            foreach ([$cycle, $major] as $wanted) {
                foreach ($releases as $r) {
                    if ((string) ($r['name'] ?? $r['cycle'] ?? '') !== $wanted) {
                        continue;
                    }

                    $raw = $r['eolFrom'] ?? $r['eol'] ?? null;

                    if (\is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                        $eol = $raw;
                    } elseif ($raw === true) {
                        $eol = gmdate('Y-m-d');
                    }

                    break 2;
                }
            }
        }

        if ($ok) {
            $cache[$key] = ['t' => time(), 'eol' => $eol];
            $this->store->setState('eol_cache', (string) json_encode($cache));
        } else {
            $this->store->log('security', 'End-of-life data for ' . $product . ' could not be downloaded.', 'warning');

            return $cache[$key]['eol'] ?? null;
        }

        return $eol;
    }

    // ------------------------------------------------------------------ 3. Blind spots

    private function blindSpots(): array
    {
        $db   = $this->store->db;
        $days = max(1, (int) $this->params->get('stale_server_days', 7));
        $q    = $db->getQuery(true)
            ->select($db->quoteName(['se.extension_id', 's.update_site_id', 's.enabled', 's.last_check_timestamp']))
            ->from($db->quoteName('#__update_sites_extensions', 'se'))
            ->join('INNER', $db->quoteName('#__update_sites', 's') . ' ON ' . $db->quoteName('s.update_site_id') . ' = ' . $db->quoteName('se.update_site_id'));

        $sites = [];

        foreach ($db->setQuery($q)->loadAssocList() as $r) {
            $sites[(int) $r['extension_id']][] = $r;
        }

        $none = $off = $stale = [];
        $cut  = time() - $days * 86400;

        foreach ($this->installed() as $e) {
            if ($e['package_id'] > 0) {
                continue; // updates come through the package
            }

            $list = $sites[$e['extension_id']] ?? [];

            if (!$list) {
                $none[] = $this->label($e);
                continue;
            }

            $active = array_filter($list, static fn ($s) => (int) $s['enabled'] === 1);

            if (!$active) {
                $off[] = $this->label($e);
            } elseif (!array_filter($active, static fn ($s) => (int) $s['last_check_timestamp'] >= $cut)) {
                $stale[] = $this->label($e);
            }
        }

        $out = [];
        $fmt = static fn (array $names): string => implode(', ', \array_slice($names, 0, 25)) . (\count($names) > 25 ? ' …' : '');

        if ($none) {
            $out[] = $this->f('nosrc', 'nosrc', 'low', Text::sprintf('COM_UPDATEMONITOR_F_NOSRC_TITLE', \count($none)),
                $fmt($none) . '. ' . Text::_('COM_UPDATEMONITOR_F_NOSRC_ADVICE'));
        }

        if ($off) {
            $out[] = $this->f('srvoff', 'srvoff', 'low', Text::sprintf('COM_UPDATEMONITOR_F_SRVOFF_TITLE', \count($off)),
                $fmt($off) . '. ' . Text::_('COM_UPDATEMONITOR_F_SRVOFF_ADVICE'));
        }

        if ($stale) {
            $out[] = $this->f('stale', 'stale', 'low', Text::sprintf('COM_UPDATEMONITOR_F_STALE_TITLE', \count($stale), $days),
                $fmt($stale) . '. ' . Text::_('COM_UPDATEMONITOR_F_STALE_ADVICE'));
        }

        return $out;
    }

    // ------------------------------------------------------------------ 4. Disabled but outdated

    private function disabledOutdated(): array
    {
        $db = $this->store->db;
        $q  = $db->getQuery(true)
            ->select($db->quoteName(['u.name', 'u.extension_id', 'u.installed_version', 'u.available_version', 'u.severity', 'u.infourl']))
            ->from($db->quoteName('#__updatemonitor_updates', 'u'))
            ->join('INNER', $db->quoteName('#__extensions', 'e') . ' ON ' . $db->quoteName('e.extension_id') . ' = ' . $db->quoteName('u.extension_id'))
            ->where($db->quoteName('u.status') . ' = ' . $db->quote('pending'))
            ->where($db->quoteName('u.ignored') . ' = 0')
            ->where($db->quoteName('e.enabled') . ' = 0')
            ->where($db->quoteName('u.type_label') . ' <> ' . $db->quote('Joomla Core'));

        $out = [];

        foreach ($db->setQuery($q)->loadAssocList() as $r) {
            $out[] = $this->f(
                'disabled:' . $r['extension_id'] . ':' . $r['available_version'],
                'disabled',
                $r['severity'] !== '' ? $r['severity'] : 'low',
                Text::sprintf('COM_UPDATEMONITOR_F_DISABLED_TITLE', $r['name']),
                Text::sprintf('COM_UPDATEMONITOR_F_DISABLED_DETAIL', $r['installed_version'], $r['available_version']),
                (string) $r['infourl']
            );
        }

        return $out;
    }
}
