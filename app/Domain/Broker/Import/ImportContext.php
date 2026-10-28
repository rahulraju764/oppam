<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\ProfileOwnerType;
use App\Models\Profile;
use App\Models\User;
use App\ValueObjects\PhoneNumber;

/**
 * What a batch's rows are checked against, loaded ONCE per batch (§11A B.9 duplicates): the file's
 * mobiles already on Oppam (any account, any managed profile, the ban list — three grouped queries),
 * how often each mobile appears in the file, the bureau's existing clients by name + date of birth,
 * and the photo zip's file names. (Each row still runs the single form's rules, whose master checks
 * query the database — that is what keeps the rule set identical.)
 */
final class ImportContext
{
    /**
     * @param  array<string, true>  $taken  E.164 => already on Oppam
     * @param  array<string, int>  $inFile  E.164 => rows using it
     * @param  array<string, true>  $bureauClients  "first|last|dob" (lowercase) => already a client of this bureau
     * @param  array<string, true>|null  $zipNames  lowercase file name => in the zip; null = no zip uploaded
     */
    public function __construct(
        public readonly array $taken,
        public readonly array $inFile,
        public readonly array $bureauClients,
        public readonly ?array $zipNames,
    ) {}

    /**
     * @param  list<string>  $phones  the raw mobile cells of every row
     * @param  list<string>|null  $zipEntries  the zip's file names (null without a zip)
     */
    public static function load(string $brokerId, array $phones, ?array $zipEntries): self
    {
        $e164 = [];
        foreach ($phones as $raw) {
            $parsed = PhoneNumber::tryParse($raw);
            if ($parsed !== null) {
                $e164[] = $parsed->e164();
            }
        }

        $unique = array_values(array_unique($e164));
        $taken = [];
        foreach (array_chunk($unique, 500) as $chunk) {
            foreach (User::withTrashed()->whereIn('phone', $chunk)->pluck('phone') as $p) {
                $taken[(string) $p] = true;
            }
            foreach (Profile::withTrashed()->whereIn('client_phone', $chunk)->pluck('client_phone') as $p) {
                $taken[(string) $p] = true;
            }
        }
        foreach (app(BanList::class)->bannedAmong(BannedIdentifierType::Phone, $unique) as $p) {
            $taken[$p] = true;
        }

        $clients = [];
        Profile::query()->where('managed_by_broker_id', $brokerId)->where('owner_type', ProfileOwnerType::BrokerManaged->value)
            ->whereNull('claimed_at')->toBase()->select(['first_name', 'last_name', 'dob'])->orderBy('id')
            ->each(function (object $p) use (&$clients): void {
                $clients[self::personKey((string) $p->first_name, (string) $p->last_name, $p->dob !== null ? substr((string) $p->dob, 0, 10) : '')] = true;
            });

        return new self(
            $taken,
            array_count_values($e164),
            $clients,
            $zipEntries === null ? null : array_fill_keys(array_map(fn (string $n): string => mb_strtolower(basename($n)), $zipEntries), true),
        );
    }

    public static function personKey(string $first, string $last, string $dob): string
    {
        return mb_strtolower(trim($first)).'|'.mb_strtolower(trim($last)).'|'.$dob;
    }
}
