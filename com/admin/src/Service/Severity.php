<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;

/**
 * Security metadata handling.
 * Source: <security><item> entries (optionally with a severity attribute) in the extension's changelog XML.
 * "unknown" = security update without a severity level.
 */
final class Severity
{
    private const RANK = ['' => 0, 'unknown' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];

    private const COLORS = ['critical' => '#b00020', 'high' => '#e65100', 'medium' => '#f9a825', 'low' => '#607d8b', 'unknown' => '#424242'];

    public static function rank(string $s): int
    {
        return self::RANK[$s] ?? 0;
    }

    public static function max(string $a, string $b): string
    {
        return self::rank($a) >= self::rank($b) ? $a : $b;
    }

    public static function normalize(string $raw): string
    {
        $map = [
            'critical' => 'critical', 'kritisch' => 'critical',
            'high' => 'high', 'hoch' => 'high', 'important' => 'high',
            'medium' => 'medium', 'moderate' => 'medium', 'mittel' => 'medium',
            'low' => 'low', 'niedrig' => 'low', 'gering' => 'low',
        ];

        return $map[strtolower(trim($raw))] ?? '';
    }

    public static function label(string $s): string
    {
        return $s === '' ? '–' : Text::_('COM_UPDATEMONITOR_SEVERITY_' . strtoupper($s));
    }

    public static function badge(string $s): string
    {
        $cls = ['critical' => 'bg-danger', 'high' => 'bg-warning text-dark', 'medium' => 'bg-info text-dark', 'low' => 'bg-secondary', 'unknown' => 'bg-dark'];

        if ($s === '') {
            return '<span class="text-muted">–</span>';
        }

        return '<span class="badge ' . ($cls[$s] ?? 'bg-secondary') . '">' . htmlspecialchars(self::label($s), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public static function emailBadge(string $s): string
    {
        if ($s === '') {
            return '–';
        }

        return '<span style="background:' . (self::COLORS[$s] ?? '#607d8b') . ';color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">'
            . htmlspecialchars(self::label($s), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /** Deadline (unix time) after which no more changelogs are fetched in this run; 0 = unlimited. */
    public static int $deadline = 0;

    /** Only http(s) URLs are accepted (blocks javascript:, data:, file: ... from remote update data). */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);

        return (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) ? $url : '';
    }

    /** SSRF guard: the host must resolve to public addresses only. */
    public static function isPublicHost(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];

            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
                $ips[] = $rec['ipv6'] ?? '';
            }
        }

        $ips = array_filter($ips);

        if (!$ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** GET with manual redirect handling so that every hop is validated (max 3 redirects, size and time limited). */
    public static function download(string $url, int $maxBytes = 1048576, int $timeout = 10): ?string
    {
        $http = HttpFactory::getHttp(['follow_location' => false]);

        for ($hop = 0; $hop <= 3; $hop++) {
            $url = self::safeUrl($url);

            if ($url === '' || !self::isPublicHost($url)) {
                return null;
            }

            $response = $http->get($url, [], $timeout);
            $code     = $response->getStatusCode();

            if (\in_array($code, [301, 302, 303, 307, 308], true)) {
                $next = trim($response->getHeaderLine('Location'));

                if ($next !== '' && $next[0] === '/') {
                    $next = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . $next;
                }

                $url = $next;
                continue;
            }

            $body = (string) $response->getBody();

            return ($code === 200 && $body !== '' && \strlen($body) <= $maxBytes) ? $body : null;
        }

        return null;
    }

    /**
     * @return array{severity:string, items:array<int,array{text:string,severity:string}>}|null  null = fetch/parse failed
     */
    public static function fetch(string $url, string $version): ?array
    {
        if (self::$deadline > 0 && time() > self::$deadline) {
            return null;
        }

        try {
            $body = self::download($url);

            // Changelogs never need a DTD: reject documents declaring one (entity expansion tricks)
            if ($body === null || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
                return null;
            }

            $prev = libxml_use_internal_errors(true);
            $xml  = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            if (!$xml) {
                return null;
            }

            $items = [];
            $max   = '';

            foreach ($xml->changelog as $entry) {
                if (trim((string) $entry->version) !== $version) {
                    continue;
                }

                foreach ($entry->security as $section) {
                    $sectionSev = self::normalize((string) ($section['severity'] ?? ''));
                    $nodes      = $section->item ? iterator_to_array($section->item, false) : [];

                    if (!$nodes && trim((string) $section) !== '') {
                        $nodes = [$section];
                    }

                    foreach ($nodes as $node) {
                        $text = trim((string) $node);
                        $sev  = self::normalize((string) ($node['severity'] ?? '')) ?: $sectionSev;

                        if ($sev === '' && preg_match('/\b(critical|high|medium|moderate|low|kritisch|hoch|mittel|niedrig)\b/i', $text, $m)) {
                            $sev = self::normalize($m[1]);
                        }

                        $items[] = ['text' => $text, 'severity' => $sev];
                        $max     = self::max($max, $sev !== '' ? $sev : 'unknown');
                    }
                }
            }

            return ['severity' => $max, 'items' => $items];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
