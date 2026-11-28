<?php

namespace Iquesters\Event\Support;

use Iquesters\Event\Enums\Module;
use Iquesters\Foundation\Support\ConfProvider;

/**
 * What people get for an event, in the app's own word: "ticket" by default,
 * or e.g. "pass" (settings ticket_word / ticket_word_plural). Text people
 * read goes through t(), which puts the word in, keeping its capital:
 *
 *     EventWords::t('Add ticket')        // "Add pass"
 *     EventWords::t('Tickets available') // "Passes available"
 */
class EventWords
{
    private static ?array $words = null;

    /** @return array{0: string, 1: string} [one, several], lower case */
    public static function words(): array
    {
        if (self::$words !== null) {
            return self::$words;
        }
        try {
            $conf = ConfProvider::from(Module::EVENT);
            $one = mb_strtolower(trim((string) $conf->ticket_word));
            $many = mb_strtolower(trim((string) $conf->ticket_word_plural));
        } catch (\Throwable $e) {
            $one = $many = '';
        }

        return self::$words = [$one !== '' ? $one : 'ticket', $many !== '' ? $many : 'tickets'];
    }

    /** The text with "ticket(s)" in the app's word ("Ticket" → "Pass", "tickets" → "passes"). */
    public static function t(string $text): string
    {
        [$one, $many] = self::words();
        if ($one === 'ticket' && $many === 'tickets') {
            return $text;
        }

        return preg_replace_callback('/\b(tickets|ticket)\b/i', function (array $m) use ($one, $many) {
            $word = strtolower($m[1]) === 'tickets' ? $many : $one;

            return ctype_upper($m[1][0]) ? mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1) : $word;
        }, $text);
    }

    /** The words in every text a form schema shows (labels, help, buttons…); ids and values stay. */
    public static function form(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = self::form($value);
            } elseif (is_string($value) && in_array($key, ['label', 'description', 'helperText', 'placeholder', 'addLabel', 'submitButtonLabel', 'submittingLabel', 'title', 'text'], true)) {
                $schema[$key] = self::t($value);
            }
        }

        return $schema;
    }

    /** For tests: read the settings again. */
    public static function flush(): void
    {
        self::$words = null;
    }
}
