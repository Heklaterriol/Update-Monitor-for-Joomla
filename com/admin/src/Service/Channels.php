<?php

namespace Custom\Component\UpdateMonitor\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;

/**
 * Delivers the same events as the emails to chat and webhook channels:
 * Slack, Microsoft Teams (Workflows webhook), Discord, Telegram and a generic signed JSON webhook.
 *
 * Message array: title (string), lines (string[]), severity (string), url (string), data (array, for the generic webhook).
 */
final class Channels
{
    private const EVENTS = ['updates', 'findings', 'reminders', 'reports'];

    public function __construct(private Store $store, private Registry $params)
    {
    }

    /** @return string[] */
    private function events(): array
    {
        $ev = $this->params->get('chan_events', ['updates', 'findings']);

        if (\is_string($ev)) {
            $ev = explode(',', $ev);
        }

        return array_values(array_intersect(self::EVENTS, (array) $ev));
    }

    /** @return string[] keys of the configured channels */
    public function configured(): array
    {
        $p = $this->params;
        $c = [];

        foreach (['slack' => 'chan_slack_url', 'teams' => 'chan_teams_url', 'discord' => 'chan_discord_url'] as $key => $opt) {
            if (trim((string) $p->get($opt, '')) !== '') {
                $c[] = $key;
            }
        }

        if (trim((string) $p->get('chan_telegram_token', '')) !== '' && trim((string) $p->get('chan_telegram_chat', '')) !== '') {
            $c[] = 'telegram';
        }

        if (trim((string) $p->get('chan_webhook_url', '')) !== '') {
            $c[] = 'webhook';
        }

        return $c;
    }

    public function active(string $event): bool
    {
        return $this->configured() !== [] && \in_array($event, $this->events(), true);
    }

    /** Sends an event message to all configured channels. @return int number of channels that accepted it */
    public function send(string $event, array $msg): int
    {
        return $this->deliver($event, $msg, false);
    }

    /** Test message to every configured channel, regardless of the selected events. @return array<string,bool> */
    public function test(array $msg): array
    {
        $result = [];

        foreach ($this->configured() as $channel) {
            $result[$channel] = $this->deliverOne($channel, 'test', $msg);
        }

        return $result;
    }

    private function deliver(string $event, array $msg, bool $force): int
    {
        if (!$force && !$this->active($event)) {
            return 0;
        }

        $ok = 0;

        foreach ($this->configured() as $channel) {
            $ok += $this->deliverOne($channel, $event, $msg) ? 1 : 0;
        }

        return $ok;
    }

    private function deliverOne(string $channel, string $event, array $msg): bool
    {
        try {
            $good = match ($channel) {
                'slack'    => $this->slack($msg),
                'teams'    => $this->teams($msg),
                'discord'  => $this->discord($msg),
                'telegram' => $this->telegram($msg),
                'webhook'  => $this->webhook($event, $msg),
                default    => false,
            };
        } catch (\Throwable $e) {
            $good = false;
        }

        // never log URLs or tokens
        $this->store->log('channel', sprintf('%s (%s): %s', ucfirst($channel), $event, $good ? 'delivered' : 'failed'), $good ? 'info' : 'warning');

        return $good;
    }

    // ------------------------------------------------------------------ formatting

    private function text(array $msg, int $max): string
    {
        $text = $msg['title'] . "\n" . implode("\n", array_map(static fn ($l) => '• ' . $l, $msg['lines']));

        if (($msg['url'] ?? '') !== '') {
            $text .= "\n" . $msg['url'];
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    // ------------------------------------------------------------------ channels

    private function slack(array $msg): bool
    {
        // escape control characters so remote data (extension names) cannot trigger mentions or links
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $this->text($msg, 3500));

        return $this->post((string) $this->params->get('chan_slack_url'), ['text' => $text, 'unfurl_links' => false]);
    }

    private function teams(array $msg): bool
    {
        $body = [
            ['type' => 'TextBlock', 'text' => $msg['title'], 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => implode("\n\n", array_map(static fn ($l) => '• ' . $l, $msg['lines'])), 'wrap' => true],
        ];
        $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => $body];

        if (($msg['url'] ?? '') !== '') {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open', 'url' => $msg['url']]];
        }

        return $this->post((string) $this->params->get('chan_teams_url'), [
            'type'        => 'message',
            'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card]],
        ]);
    }

    private function discord(array $msg): bool
    {
        return $this->post((string) $this->params->get('chan_discord_url'), [
            'username'         => 'Update Monitor',
            'content'          => $this->text($msg, 1900),
            'allowed_mentions' => ['parse' => []],   // extension names must never ping anybody
        ]);
    }

    private function telegram(array $msg): bool
    {
        $token = trim((string) $this->params->get('chan_telegram_token'));
        $chat  = trim((string) $this->params->get('chan_telegram_chat'));

        if (!preg_match('/^\d{3,}:[A-Za-z0-9_-]{20,}$/', $token) || !preg_match('/^(-?\d+|@[A-Za-z0-9_]{4,})$/', $chat)) {
            return false;
        }

        return $this->post('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'chat_id'                  => $chat,
            'text'                     => $this->text($msg, 4000),
            'disable_web_page_preview' => true,
        ]);
    }

    private function webhook(string $event, array $msg): bool
    {
        $payload = json_encode([
            'event'     => $event,
            'site'      => (string) \Joomla\CMS\Factory::getApplication()->get('sitename'),
            'title'     => $msg['title'],
            'severity'  => $msg['severity'],
            'url'       => $msg['url'] ?? '',
            'timestamp' => gmdate('c'),
            'lines'     => $msg['lines'],
            'items'     => $msg['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = ['X-UpdateMonitor-Event' => $event];
        $secret  = (string) $this->params->get('chan_webhook_secret', '');

        if ($secret !== '') {
            $headers['X-UpdateMonitor-Signature'] = 'sha256=' . hash_hmac('sha256', (string) $payload, $secret);
        }

        return $this->post((string) $this->params->get('chan_webhook_url'), (string) $payload, $headers, (bool) $this->params->get('chan_webhook_private', 0));
    }

    // ------------------------------------------------------------------ transport

    /** POST JSON. HTTPS and public hosts only, except for the generic webhook if the admin allows private targets. */
    private function post(string $url, array|string $body, array $headers = [], bool $allowPrivate = false): bool
    {
        $url = Severity::safeUrl($url);

        if ($url === '' || (!$allowPrivate && !str_starts_with(strtolower($url), 'https://'))) {
            return false;
        }

        if (!$allowPrivate && !Severity::isPublicHost($url)) {
            return false;
        }

        $payload  = \is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $http     = HttpFactory::getHttp(['follow_location' => false]);
        $response = $http->post($url, $payload, $headers + ['Content-Type' => 'application/json'], 10);
        $code     = $response->getStatusCode();

        return $code >= 200 && $code < 300;
    }
}
