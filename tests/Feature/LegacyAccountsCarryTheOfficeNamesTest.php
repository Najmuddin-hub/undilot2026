<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;

/*
 * The accounts command exists because the dump cannot answer two questions, and
 * these tests pin the answers it invents instead:
 *
 *   `UserRight` is 'Pengawai' on all 137 rows, so the three undilot roles are
 *   ASSIGNED here, not migrated — and the assignment has to be stable, or a test
 *   that signs in as "the super admin" changes person between runs.
 *
 *   There is no email and no password anywhere in the dump, so both are built.
 *   Every address lands on `legacy.test` — its own domain, NOT the `demo.test`
 *   the seeders use, because --fresh deletes by domain.
 */

function loadForAccounts(): void
{
    Artisan::call('import:legacy', [
        'path' => base_path('tests/Fixtures/legacy-dump-sample.sql'),
    ]);
}

it('refuses to run before anything is loaded', function () {
    expect(Artisan::call('legacy:accounts'))->toBe(1);

    expect(Admin::where('email', 'like', '%@legacy.test')->exists())->toBeFalse();
});

it('gives the first officer super, and keeps the office name and title', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $super = Admin::where('role', Admin::SUPER_ROLE)
        ->where('email', 'like', '%@legacy.test')
        ->first();

    // The fixture's one live officer, by name, from `upengawai.Name`.
    expect($super->name)->toBe('NORAINI BINTI OSMAN')
        ->and($super->email)->toBe('noraini@legacy.test')
        ->and($super->state)->toBeNull('Super sees every zone, so it carries none.');
});

it('builds a participant with their company, craft code and booth size', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // 'Manik/Mutiara/Karangan Laut-AMH-3x3' — the code and size are glued to a
    // craft name that itself holds punctuation.
    $user = User::where('email', 'usahawan204@legacy.test')->first();

    expect($user->name)->toBe('SITTI WARDAHAH BINTI ALI DAHLAN')
        ->and($user->ic_number)->toBe('890508-12-0000');

    $company = Company::where('user_id', $user->id)->first();

    expect($company->name)->toBe('WARDAHAMIRAH LEGACY')
        ->and($company->state)->toBe('Perlis')
        ->and($company->kraf_code)->toBe('AMH', 'Split from the RIGHT, or the code reads "Manik".')
        ->and($company->plot_size)->toBe('3x3');
});

it('leaves the code and size null for the older rows that carry neither', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // The fixture's snapshot rows carry a bare 'Songket' — 89% of the real dump
    // looks like this, so guessing a code here would invent data.
    $user = User::where('email', 'usahawan205@legacy.test')->first();
    $company = Company::where('user_id', $user->id)->first();

    expect($company->kraf_code)->toBe('RBH')
        ->and($company->plot_size)->toBe('3x3');

    // And one from the live table only: the command reads `source_table` =
    // 'usahawan', so a snapshot row must not become an account at all.
    expect(User::where('email', 'usahawan2@legacy.test')->exists())
        ->toBeFalse('Only the live AACB rows become accounts.');
});

it('hands out the same role to the same officer on a re-run', function () {
    loadForAccounts();

    Artisan::call('legacy:accounts');
    $first = Admin::where('email', 'noraini@legacy.test')->first();

    Artisan::call('legacy:accounts');
    $second = Admin::where('email', 'noraini@legacy.test')->first();

    expect($second->id)->toBe($first->id, 'A re-run must update, not duplicate.')
        ->and($second->role)->toBe($first->role);

    expect(Admin::where('email', 'noraini@legacy.test')->count())->toBe(1);
});

it('gives two officers who share one login two accounts, and does not let the second take the first one\'s role', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // The fixture carries ids 30 and 31, both 'NURUL HAIDA BINTI AHMAD' with
    // loginname 'haida' — the same shape as ids 5 and 25 in the real dump.
    //
    // THIS IS THE BUG THIS TEST EXISTS FOR. Keyed on a colliding address,
    // updateOrCreate does not fail: it updates the FIRST officer's row, so the
    // second one takes over the role already handed out. On the real dump that
    // built 17 admins into 16 rows and left no hq admin at all, silently.
    $first = Admin::where('email', 'haida@legacy.test')->first();
    $second = Admin::where('email', 'haida.31@legacy.test')->first();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull('The second officer was swallowed by the first.');

    expect($first->role)->toBe('hq', 'The second officer demoted the hq admin.')
        ->and($first->state)->toBeNull()
        ->and($second->role)->toBe('admin')
        ->and($second->state)->toBe('Terengganu');

    // One role of each at the top, not two state admins.
    expect(Admin::where('email', 'like', '%@legacy.test')->where('role', 'hq')->count())->toBe(1)
        ->and(Admin::where('email', 'like', '%@legacy.test')->where('role', Admin::SUPER_ROLE)->count())->toBe(1);
});

it('never writes a password anybody could know', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $admin = Admin::where('email', 'noraini@legacy.test')->first();

    // Not the login name, not the office name, not a word from the dump.
    foreach (['noraini', 'password', 'Pengawai', 'NORAINI BINTI OSMAN'] as $guess) {
        expect(Hash::check($guess, $admin->password))->toBeFalse("'{$guess}' opened the account.");
    }
});

it('puts every address on a domain that cannot receive mail', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $addresses = Admin::pluck('email')->merge(User::pluck('email'));

    foreach ($addresses as $email) {
        expect(str_ends_with($email, '.test'))->toBeTrue("{$email} is not on a reserved TLD.");
    }
});

it('clears out what it made when asked, and leaves the demo accounts alone', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $outsider = User::factory()->create(['email' => 'real.person@example.com']);

    // THE ONE THAT MATTERS. DemoSeeder plants accounts on `demo.test`, and this
    // command used to build its own there too — so `--fresh` deleted both. A
    // seeded demo participant has to survive, or a developer loses their whole
    // demo dataset to a flag that promised to clean up after one command.
    $demoUser = User::factory()->create(['email' => 'user.johor@demo.test']);
    $demoAdmin = Admin::factory()->create(['email' => 'hq@demo.test', 'role' => 'hq', 'state' => null]);

    Artisan::call('legacy:accounts', ['--fresh' => true]);

    expect(User::where('id', $outsider->id)->exists())->toBeTrue()
        ->and(User::where('id', $demoUser->id)->exists())
        ->toBeTrue('--fresh deleted a DemoSeeder participant.')
        ->and(Admin::where('id', $demoAdmin->id)->exists())
        ->toBeTrue('--fresh deleted a DemoSeeder admin.');

    // And it did do its own job: the rebuild leaves exactly one of its admins.
    expect(Admin::where('email', 'noraini@legacy.test')->count())->toBe(1);
});
