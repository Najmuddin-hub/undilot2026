<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds sign-in accounts out of the loaded dump, for testing on this machine.
 *
 * THE DUMP CANNOT ANSWER TWO THINGS, so this command answers them and says so
 * rather than pretending they came from the office:
 *
 * 1. ROLES. `upengawai.UserRight` holds ONE value across all 137 rows —
 *    'Pengawai'. The old system had no role distinction at all, so super / hq /
 *    state cannot be migrated. The first officer becomes super, the second
 *    becomes hq, and every zone that appears in the dump gets one state admin.
 *    That is an assignment for testing, not a migration of fact.
 *
 * 2. EMAIL AND PASSWORD. `upengawai` signed in with `loginname` and `usahawan`
 *    has no login at all, while `admins.email` and `users.email` are unique and
 *    required. Emails are built on `@legacy.test` — a reserved TLD that can
 *    never receive mail — and passwords are random, because /auto signs in
 *    without one. Nothing here is a credential usable off this machine.
 *
 * DEV ONLY. It refuses outside local/testing/e2e, the same fence /auto stands
 * behind, because it plants accounts whose passwords nobody knows.
 */
class LegacyAccounts extends Command
{
    protected $signature = 'legacy:accounts
        {--users=10 : How many AACB participants to build, or "all"}
        {--fresh : Delete accounts this command made before rebuilding}';

    protected $description = 'Build super/hq/state admins and participants from the loaded legacy dump';

    /**
     * A reserved TLD (RFC 6761), so it resolves nowhere — and its OWN name, not
     * the `demo.test` the seeders use.
     *
     * THAT SEPARATION IS LOAD-BEARING. `--fresh` deletes every account on this
     * domain, and DemoSeeder plants `hq@demo.test` plus one admin and one
     * participant per zone there. Sharing a domain would make `--fresh` quietly
     * delete the demo data too — which it did, until a run against the real
     * dump showed both sets of accounts answering to the same LIKE.
     */
    public const MAIL_DOMAIN = 'legacy.test';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'e2e'])) {
            $this->error('legacy:accounts is for a developer machine only.');

            return self::FAILURE;
        }

        if (DB::table('legacy_upengawai')->count() === 0) {
            $this->error('Staging is empty. Run import:legacy first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->forget();
        }

        $admins = $this->admins();
        $users = $this->participants();

        $this->newLine();
        $this->table(
            ['role', 'name', 'email', 'zone'],
            collect($admins)->map(fn (Admin $a) => [
                $a->role, $a->name, $a->email, $a->state ?? '—',
            ])->take(8)->all()
        );

        if (count($admins) > 8) {
            $this->line('… and '.(count($admins) - 8).' more state admins, one per zone in the dump.');
        }

        $this->newLine();
        $this->line('Participants built: '.count($users));
        foreach (array_slice($users, 0, 3) as $u) {
            $this->line("  {$u->email}  ({$u->company->name})");
        }

        $this->newLine();
        $this->line('Passwords are random. Sign in at /auto, which needs none.');

        return self::SUCCESS;
    }

    /** @return array<int, Admin> */
    private function admins(): array
    {
        // Ordered by the original id so a re-run hands the same officer the same
        // role — a test that signs in as "the super admin" should not change
        // person between runs.
        $officers = DB::table('legacy_upengawai')
            ->where('source_table', 'upengawai')
            ->whereNotNull('name')
            ->orderByRaw('cast(legacy_id as unsigned)')
            ->get();

        if ($officers->isEmpty()) {
            $this->warn('No rows from the live `upengawai` table.');

            return [];
        }

        $made = [];
        $roles = [Admin::SUPER_ROLE, 'hq'];
        $zonesTaken = [];
        $handlesTaken = [];

        foreach ($officers as $officer) {
            $zone = trim((string) $officer->state);
            $handle = $this->handleFor($officer, $handlesTaken);

            if ($roles !== []) {
                // Super and hq see every zone, so their own zone is dropped.
                $made[] = $this->admin($officer, $handle, array_shift($roles), null);

                continue;
            }

            if ($zone === '' || isset($zonesTaken[$zone])) {
                continue;
            }

            $zonesTaken[$zone] = true;
            $made[] = $this->admin($officer, $handle, 'admin', $zone);
        }

        return $made;
    }

    /**
     * A unique address per officer, derived from `loginname`.
     *
     * THE DUMP HAS TWO OFFICERS SHARING ONE LOGIN. Ids 5 and 25 are both
     * 'NURUL HAIDA BINTI AHMAD' with `loginname` 'haida' — the same person
     * entered twice — and `admins.email` is unique. Keying updateOrCreate on a
     * colliding address does not fail: it UPDATES the first officer's row, so
     * the second one silently takes over the first one's role. That is exactly
     * what happened on the real dump: 17 admins were built, 16 rows existed, and
     * the hq admin had been demoted to a state admin without a word.
     *
     * So the first officer to claim a slug keeps it and every later one carries
     * its legacy id. Deterministic, because the caller reads officers in id
     * order — the same officer gets the same address on every run.
     *
     * @param  array<string, string>  $taken  slug => the legacy id holding it
     */
    private function handleFor(object $officer, array &$taken): string
    {
        $login = trim((string) $officer->loginname);
        $slug = Str::slug($login !== '' ? $login : (string) $officer->name) ?: 'pegawai';
        $id = (string) $officer->legacy_id;

        if (! isset($taken[$slug])) {
            $taken[$slug] = $id;

            return $slug;
        }

        return $taken[$slug] === $id ? $slug : $slug.'.'.$id;
    }

    private function admin(object $officer, string $handle, string $role, ?string $zone): Admin
    {
        return Admin::updateOrCreate(
            ['email' => $handle.'@'.self::MAIL_DOMAIN],
            [
                // The officer's real name and title, which is the point: a test
                // screen should read like the office's own list.
                'name' => trim((string) $officer->name),
                'password' => bcrypt(Str::random(32)),
                'role' => $role,
                'state' => $zone,
            ]
        );
    }

    /** @return array<int, User> */
    private function participants(): array
    {
        $wanted = $this->option('users');

        $query = DB::table('legacy_usahawan')
            ->where('source_table', 'usahawan')
            ->orderByRaw('cast(legacy_id as unsigned)');

        if ($wanted !== 'all') {
            $query->limit(max(0, (int) $wanted));
        }

        $made = [];

        foreach ($query->get() as $row) {
            [$code, $size] = $this->codeAndSize((string) $row->crafttype);

            $user = User::updateOrCreate(
                ['email' => 'usahawan'.$row->legacy_id.'@'.self::MAIL_DOMAIN],
                [
                    'name' => trim((string) $row->name),
                    'password' => bcrypt(Str::random(32)),
                    // The dump's IC comes in two formats, dashed and bare. Kept
                    // as written: normalising it is the transform's decision and
                    // belongs where it can be tested.
                    'ic_number' => trim((string) $row->ic) ?: null,
                    'is_active' => true,
                ]
            );

            $company = Company::firstOrNew(['user_id' => $user->id]);

            $company->fill([
                'name' => trim((string) $row->companyname),
                'state' => trim((string) $row->state) ?: null,
                'is_active' => true,
            ]);

            // `kraf_code` and `plot_size` are NOT fillable, deliberately — the
            // model marks them admin-only, and `plot_size` normally moves
            // through `syncBoothSize()` so that a change clears `verified_at`
            // for every draw list the company sits on. Assigned directly here
            // because a company being created has no draw lists yet: there is no
            // verification to clear, and routing through syncBoothSize would
            // dress an import up as an admin edit.
            $company->kraf_code = $code;
            $company->plot_size = $size;
            $company->save();

            $made[] = $user->load('company');
        }

        return $made;
    }

    /**
     * `crafttype` carries the craft code and the booth size glued to the craft
     * name in the newer rows: 'Manik/Mutiara/Karangan Laut-AMH-3x3' is code AMH
     * at 3x3. SPLIT FROM THE RIGHT, because the craft name itself holds
     * punctuation — reading left to right turns that example into code 'Manik'.
     *
     * The older 89% of the dump carries a bare name with no code and no size
     * ('Songket', 'Kayu(Hiasan Ukiran & Kegunaan)'), and returns nulls.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function codeAndSize(string $crafttype): array
    {
        if (! preg_match('/-([A-Za-z]{2,6})-(\d+x\d+)\s*$/', trim($crafttype), $m)) {
            return [null, null];
        }

        return [strtoupper($m[1]), strtolower($m[2])];
    }

    private function forget(): void
    {
        $like = '%@'.self::MAIL_DOMAIN;

        $users = User::where('email', 'like', $like)->pluck('id');
        Company::whereIn('user_id', $users)->delete();
        User::whereIn('id', $users)->delete();
        Admin::where('email', 'like', $like)->delete();

        $this->line('Removed the accounts this command had made.');
    }
}
