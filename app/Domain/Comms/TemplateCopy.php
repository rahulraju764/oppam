<?php

declare(strict_types=1);

namespace App\Domain\Comms;

use App\Data\Notifications\NotificationContentData;
use App\Enums\CommsChannel;
use App\Models\CommunicationTemplate;
use App\Models\CommunicationTemplateVersion;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A14 template manager → real notifications (P8.3a). Given a notification event and a channel,
 * the admin's live copy (CommunicationTemplate + its current version), or null so the code's own
 * copy is used. Read by MemberNotification (in-app), TemplatedMailChannel (email) and SmsChannel
 * (the DLT template id). Cached per event × channel; SaveTemplate forgets it.
 *
 * Placeholders are an allow-list (PLACEHOLDERS); SaveTemplate refuses any other {word}. Values come
 * from the notification's own content — the template can reword, never add data.
 */
final class TemplateCopy
{
    /** @var array<string, string> placeholder => what it means (shown in the editor) */
    public const PLACEHOLDERS = [
        'first_name' => 'The member’s first name',
        'actor_name' => 'The other member’s display name, when there is one',
        'url' => 'The page the notification opens',
        'default_title' => 'Oppam’s standard title for this notification',
        'default_body' => 'Oppam’s standard text for this notification',
    ];

    private const TTL_SECONDS = 300;

    /** @return array{subject: ?string, body: string, dlt_template_id: ?string, version: int}|null */
    public function live(string $event, CommsChannel $channel): ?array
    {
        /** @var array{subject: ?string, body: string, dlt_template_id: ?string, version: int}|false $copy */
        $copy = Cache::remember(self::cacheKey($event, $channel), self::TTL_SECONDS, function () use ($event, $channel): array|false {
            $template = CommunicationTemplate::query()
                ->where('key', CommunicationTemplate::keyFor($event, $channel))
                ->where('is_active', true)
                ->first();
            $version = $template !== null
                ? CommunicationTemplateVersion::query()->where('template_id', $template->id)->where('version', $template->current_version)->first()
                : null;

            return $version === null ? false : [
                'subject' => $version->subject,
                'body' => $version->body,
                'dlt_template_id' => $version->dlt_template_id,
                'version' => $version->version,
            ];
        });

        return $copy === false ? null : $copy;
    }

    public function forget(string $event, CommsChannel $channel): void
    {
        Cache::forget(self::cacheKey($event, $channel));
    }

    /** The in-app content with the admin's copy applied (title = first line, rest = body), or as it was. */
    public function inApp(string $event, NotificationContentData $content, object $notifiable): NotificationContentData
    {
        $copy = $this->live($event, CommsChannel::InApp);
        if ($copy === null) {
            return $content;
        }

        $text = $this->fill($copy['body'], $content, $notifiable);
        [$title, $body] = array_pad(preg_split('/\R/', trim($text), 2) ?: [], 2, '');

        return new NotificationContentData(
            title: mb_substr(trim((string) $title), 0, 150) ?: $content->title,
            body: trim((string) $body) !== '' ? mb_substr(trim((string) $body), 0, 500) : $content->body,
            url: $content->url,
            actorCode: $content->actorCode,
            actorName: $content->actorName,
            actorPhotoThumb: $content->actorPhotoThumb,
        );
    }

    /** Placeholders replaced with the notification's own values; anything else is left as typed. */
    public function fill(string $text, NotificationContentData $content, object $notifiable): string
    {
        $firstName = $notifiable instanceof User
            ? (string) Profile::query()->where('user_id', $notifiable->id)->value('first_name')
            : '';

        return strtr($text, [
            '{first_name}' => $firstName,
            '{actor_name}' => (string) $content->actorName,
            '{url}' => $content->url,
            '{default_title}' => $content->title,
            '{default_body}' => $content->body,
        ]);
    }

    /**
     * Template Markdown → HTML for emails, test-sends and the admin preview: raw HTML escaped, unsafe
     * links dropped, and images turned into their alt text — a template never loads a remote image
     * (no tracking pixel in a member's mail, none in the admin's preview).
     */
    public static function markdown(string $text): HtmlString
    {
        $html = Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        // Raw HTML is escaped, so every <img> here came from Markdown image syntax (inline or
        // reference-style): keep its alt text, drop the image.
        $html = (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $tag): string {
            return preg_match('/\balt="([^"]*)"/i', $tag[0], $alt) === 1 ? $alt[1] : '';
        }, $html);

        return new HtmlString($html);
    }

    /** The text with sample values, for the editor preview and test-sends. */
    public static function sample(string $text): string
    {
        return strtr($text, [
            '{first_name}' => 'Anjali',
            '{actor_name}' => 'Rahul K.',
            '{url}' => url('/dashboard'),
            '{default_title}' => __('Oppam’s standard title'),
            '{default_body}' => __('Oppam’s standard text for this notification.'),
        ]);
    }

    /** @return list<string> placeholders in $text that are not on the allow-list */
    public static function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys(self::PLACEHOLDERS))));
    }

    private static function cacheKey(string $event, CommsChannel $channel): string
    {
        return 'comms:template:'.CommunicationTemplate::keyFor($event, $channel);
    }
}
