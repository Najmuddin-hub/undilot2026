<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Gives imported participants their real email address, from a list the office
 * supplies.
 *
 * WHY THIS IS A SEPARATE COMMAND, and why it is the one that unlocks the data.
 * The old system has NO email column and NO phone column — not empty ones,
 * absent ones. Its participants never signed in; only officers did, with a
 * `loginname`. So `legacy:accounts` builds each of the 175 participants an
 * address on `legacy.test`, a reserved TLD that resolves nowhere, precisely so
 * that nobody mistakes it for a working one.
 *
 * That leaves every imported participant locked out, and deliberately so: their
 * password is random and the reset link would be posted to a domain that cannot
 * receive it. The moment a real address lands here, the ordinary
 * forgot-password flow starts working for them. NO PASSWORD IS SET OR SENT BY
 * THIS COMMAND — the participant proves the address is theirs by using it.
 *
 * MATCHED ON IC, measured rather than assumed: across the 175 imported
 * participants the IC is unique 175 ways and never empty, and stays unique when
 * normalised to digits — which it must be, because the dump writes both
 * '890508-12-0000' and '800601100000'. `community_id` is NOT usable as a key on
 * its own: it is unique only 173 ways and one row has none.
 *
 * SAFE TO RUN ON THE SERVER, unlike `legacy:accounts`. It plants no account and
 * knows no password; it moves an address onto a row that already exists. It
 * refuses to touch a participant who already has a real address unless told
 * twice, so it can never quietly take over somebody's live account.
 */
class LegacyEmails extends Command
{
    protected $signature = 'legacy:emails
        {path : CSV with headers. Needs `ic` and `email`; `phone` is optional.}
        {--dry-run : Report what would change, write nothing}
        {--replace : Also overwrite addresses that are already real}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Set real email addresses on imported participants, matched by IC';

    private array $applied = [];
    private array $skipped = [];
    private array $failed = [];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = $this->read($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        if ($rows === []) {
            $this->error('The file has no data rows.');

            return self::FAILURE;
        }

        // Keyed on the digits of the IC, because the dump writes it two ways.
        //
        // AMBIGUOUS KEYS ARE REMOVED, not resolved. Nothing in the schema makes
        // `ic_number` unique — `users` also holds demo rows, office-entered rows
        // and anyone added by hand — and keyBy keeps whichever row it saw last.
        // A collision would therefore put the office's address on a person they
        // did not name, with nothing on either row to say so. An IC that names
        // two people names nobody, so it is dropped here and reported below.
        $byIc = User::whereNotNull('ic_number')->get()
            ->groupBy(fn (User $u) => self::digits($u->ic_number));

        $ambiguous = $byIc->filter(fn ($group) => $group->count() > 1);

        $people = $byIc->reject(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->first());

        $this->plan($rows, $people, $ambiguous);

        if ($this->applied === []) {
            $this->report(true);

            return $this->failed === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->report(true);

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            count($this->applied).' participant(s) will be given a new sign-in address. Continue?'
        )) {
            $this->line('Nothing was written.');

            return self::SUCCESS;
        }

        $this->write();
        $this->report(false);

        return $this->failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function read(string $path): ?array
    {
        $handle = fopen($path, 'r');
        $header = self::row($handle);

        if ($header === false) {
            fclose($handle);
            $this->error('The file is empty.');

            return null;
        }

        // A BOM in front of the first header is what Excel writes, and it makes
        // the column read as "\u{FEFF}ic" and match nothing.
        $header = array_map(
            fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header
        );

        foreach (['ic', 'email'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);
                $this->error("The CSV needs an `{$required}` column. Found: ".implode(', ', $header));

                return null;
            }
        }

        $rows = [];
        $line = 1;

        while (($values = self::row($handle)) !== false) {
            $line++;

            if ($values === [null] || $values === []) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = trim((string) ($values[$i] ?? ''));
            }

            if ($row['ic'] === '' && $row['email'] === '') {
                continue;
            }

            $row['_line'] = $line;
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Works out every change before making any of them.
     *
     * Deciding first means a file with one duplicated address is reported as a
     * duplicate rather than applied halfway and then stopped by the unique
     * index — a half-applied identity list is the worst outcome here, because
     * nobody can tell by looking which half it was.
     */
    private function plan(array $rows, $people, $ambiguous): void
    {
        $claimed = [];

        foreach ($rows as $row) {
            $line = $row['_line'];
            $ic = self::digits($row['ic']);
            $email = strtolower($row['email']);

            if ($ic === '') {
                $this->failed[] = "line {$line}: no IC";

                continue;
            }

            if (Validator::make(['e' => $email], ['e' => 'required|email'])->fails()) {
                $this->failed[] = "line {$line}: '{$row['email']}' is not an email address";

                continue;
            }

            if (isset($claimed[$email])) {
                // BOTH lines are dropped, not just this one. The file says two
                // different people own one address; there is nothing to say
                // which the office meant, so applying the earlier line would be
                // a coin toss written into somebody's identity.
                $this->failed[] = "line {$line}: '{$email}' is also on line {$claimed[$email]} — "
                    .'both dropped, nothing here says which is right';

                $this->applied = array_values(array_filter(
                    $this->applied,
                    fn ($change) => $change['email'] !== $email
                ));

                continue;
            }

            if (isset($ambiguous[$ic])) {
                $names = $ambiguous[$ic]->pluck('name')->implode(', ');
                $this->failed[] = "line {$line}: IC {$row['ic']} names more than one person "
                    ."({$names}) — nothing here says which, so neither was changed";

                continue;
            }

            $user = $people[$ic] ?? null;

            if ($user === null) {
                $this->failed[] = "line {$line}: no participant with IC {$row['ic']}";

                continue;
            }

            if (strtolower($user->email) === $email) {
                $this->skipped[] = "{$user->name} already has {$email}";

                continue;
            }

            // Somebody else's address. The unique index would stop it anyway;
            // saying so by name is more use than a constraint violation.
            $owner = User::where('email', $email)->where('id', '!=', $user->id)->first();

            if ($owner !== null) {
                $this->failed[] = "line {$line}: '{$email}' already belongs to {$owner->name}";

                continue;
            }

            $onImportDomain = str_ends_with(strtolower($user->email), '@'.LegacyAccounts::MAIL_DOMAIN);

            if (! $onImportDomain && ! $this->option('replace')) {
                $this->skipped[] = "{$user->name} already has a real address ({$user->email}) — "
                    .'pass --replace to change it';

                continue;
            }

            $claimed[$email] = $line;

            $this->applied[] = [
                'user' => $user,
                'email' => $email,
                'phone' => $row['phone'] ?? null,
                'was' => $user->email,
            ];
        }
    }

    private function write(): void
    {
        foreach ($this->applied as $i => $change) {
            /** @var User $user */
            $user = $change['user'];

            try {
                $user->email = $change['email'];

                if (($change['phone'] ?? '') !== '') {
                    $user->phone = $change['phone'];
                }

                // Saved through the model, not the query builder, so the
                // Auditable trait records who changed whose address and from
                // what. An identity change with no trail is the one kind this
                // system should never have.
                $user->save();
            } catch (Throwable $e) {
                unset($this->applied[$i]);
                $this->failed[] = "{$user->name}: ".explode(' (', $e->getMessage())[0];
            }
        }
    }

    private function report(bool $planOnly): void
    {
        $this->newLine();

        if ($this->applied !== []) {
            $this->line($planOnly ? 'Would change:' : 'Changed:');

            $this->table(
                ['participant', 'from', 'to'],
                array_map(fn ($c) => [$c['user']->name, $c['was'], $c['email']],
                    array_slice($this->applied, 0, 10))
            );

            if (count($this->applied) > 10) {
                $this->line('… and '.(count($this->applied) - 10).' more.');
            }
        }

        $this->line('Changed: '.count($this->applied)
            .'   skipped: '.count($this->skipped)
            .'   failed: '.count($this->failed));

        foreach (array_slice($this->skipped, 0, 10) as $line) {
            $this->line('  skip  '.$line);
        }

        foreach ($this->failed as $line) {
            $this->warn('  fail  '.$line);
        }

        if ($this->applied !== [] && ! $planOnly) {
            $this->newLine();
            $this->line('No passwords were set or sent. Those participants can now use');
            $this->line('Forgot Password, which proves the address belongs to them.');
        }
    }

    /**
     * One CSV row, with the escape character named rather than defaulted.
     *
     * `''` is the RFC 4180 reading and what Excel writes: a backslash is an
     * ordinary character. PHP's historical default is `'\\'`, under which a
     * backslash escapes whatever follows. PHP 8.4 deprecates relying on the
     * default because PHP 9 switches it to `''` — so an office file would be
     * read differently on the day the box is upgraded, with no error and no
     * failing test. Naming it fixes the reading to the correct one now.
     *
     * @param  resource  $handle
     * @return array<int, string|null>|false
     */
    private static function row($handle): array|false
    {
        return fgetcsv($handle, null, ',', '"', '');
    }

    /** Malaysian IC is written both as 890508-12-0000 and as 890508120000. */
    private static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
