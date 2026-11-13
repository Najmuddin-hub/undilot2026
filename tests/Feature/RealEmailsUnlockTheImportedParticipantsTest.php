<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * The one command that turns imported rows into people who can sign in.
 *
 * The dump has no email column and no phone column, so `legacy:accounts` puts
 * every participant on `legacy.test` — a reserved TLD that resolves nowhere,
 * chosen so nobody mistakes it for a working address. Until a real one lands,
 * the participant cannot sign in and cannot reset, which is correct: an address
 * that looks real but is not is worse than an obviously fake one.
 *
 * These tests pin the safety rails, because this command edits IDENTITIES and
 * runs on the live server.
 */

function csv(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'emails').'.csv';
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

function importedParticipant(string $ic, string $suffix = '1'): User
{
    return User::factory()->create([
        'ic_number' => $ic,
        'email' => 'usahawan'.$suffix.'@legacy.test',
    ]);
}

it('gives an imported participant their real address, matched on IC', function () {
    $user = importedParticipant('890508-12-0000');

    $exit = Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,siti@example.com']),
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($user->fresh()->email)->toBe('siti@example.com');
});

it('matches an IC written the other way, because the dump writes both', function () {
    // Stored '800601100000', supplied '800601-10-0000' — same person.
    $user = importedParticipant('800601100000');

    Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '800601-10-0000,leena@example.com']),
        '--force' => true,
    ]);

    expect($user->fresh()->email)->toBe('leena@example.com');
});

it('sets no password and sends nothing', function () {
    $user = importedParticipant('890508-12-0000');
    $before = $user->password;

    Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,siti@example.com']),
        '--force' => true,
    ]);

    // The participant proves the address is theirs through Forgot Password.
    // A password handed out with the address proves nothing and travels.
    expect($user->fresh()->password)->toBe($before)
        ->and(Hash::check('siti@example.com', $user->fresh()->password))->toBeFalse();
});

it('refuses an address that already belongs to somebody else', function () {
    $other = User::factory()->create(['name' => 'REAL PERSON', 'email' => 'taken@example.com']);
    $user = importedParticipant('890508-12-0000');

    $exit = Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,taken@example.com']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($user->fresh()->email)->toBe('usahawan1@legacy.test')
        ->and($other->fresh()->email)->toBe('taken@example.com', 'The live account must be untouched.');
});

it('refuses a file that uses one address twice, before writing any of it', function () {
    $first = importedParticipant('890508-12-0000', '1');
    $second = importedParticipant('660814-02-0000', '2');

    $exit = Artisan::call('legacy:emails', [
        'path' => csv([
            'ic,email',
            '890508-12-0000,shared@example.com',
            '660814-02-0000,shared@example.com',
        ]),
        '--force' => true,
    ]);

    // BOTH ROWS ARE DROPPED. The file says two people own one address and
    // nothing in it says which the office meant, so applying the earlier line
    // would write a coin toss into somebody's identity. Planning the whole file
    // before touching any of it is what makes dropping both possible — applied
    // row by row, the first would already be saved by the time the clash shows.
    expect($exit)->toBe(1)
        ->and($first->fresh()->email)->toBe('usahawan1@legacy.test')
        ->and($second->fresh()->email)->toBe('usahawan2@legacy.test');
});

it('leaves a participant who already has a real address alone', function () {
    $user = User::factory()->create([
        'ic_number' => '890508-12-0000',
        'email' => 'already.real@example.com',
    ]);

    Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,different@example.com']),
        '--force' => true,
    ]);

    expect($user->fresh()->email)->toBe('already.real@example.com');

    // Unless told twice, so a typo can still be corrected.
    Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,different@example.com']),
        '--replace' => true,
        '--force' => true,
    ]);

    expect($user->fresh()->email)->toBe('different@example.com');
});

it('reports an IC nobody has instead of failing silently', function () {
    importedParticipant('890508-12-0000');

    $exit = Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '999999-99-9999,ghost@example.com']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(User::where('email', 'ghost@example.com')->exists())->toBeFalse();

    expect(Artisan::output())->toContain('999999-99-9999');
});

it('refuses a value that is not an email address', function () {
    $user = importedParticipant('890508-12-0000');

    $exit = Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,not-an-address']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($user->fresh()->email)->toBe('usahawan1@legacy.test');
});

it('refuses when two people share an IC, instead of silently picking one', function () {
    // Across the 175 imported participants the IC is unique — measured. But
    // `users` also holds demo accounts, office-entered rows and anyone added by
    // hand, and NOTHING in the schema makes `ic_number` unique.
    //
    // Keyed on the IC, a collision is invisible: the lookup keeps whichever row
    // it saw last, so the office's address lands on a person they did not name
    // and the other keeps an address that cannot receive mail. Neither row says
    // anything is wrong.
    $one = User::factory()->create(['ic_number' => '890508-12-0000', 'email' => 'usahawan1@legacy.test']);
    $two = User::factory()->create(['ic_number' => '890508120000', 'email' => 'usahawan2@legacy.test']);

    $exit = Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,siti@example.com']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and($one->fresh()->email)->toBe('usahawan1@legacy.test')
        ->and($two->fresh()->email)->toBe('usahawan2@legacy.test');

    expect(Artisan::output())->toContain('890508-12-0000');
});

it('writes nothing on a dry run', function () {
    $user = importedParticipant('890508-12-0000');

    $exit = Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,siti@example.com']),
        '--dry-run' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($user->fresh()->email)->toBe('usahawan1@legacy.test');
});

it('takes a phone number when the file carries one', function () {
    $user = importedParticipant('890508-12-0000');

    Artisan::call('legacy:emails', [
        'path' => csv(['ic,email,phone', '890508-12-0000,siti@example.com,0123456789']),
        '--force' => true,
    ]);

    expect($user->fresh()->phone)->toBe('0123456789');
});

it('reads a file Excel saved, byte-order mark and all', function () {
    $user = importedParticipant('890508-12-0000');

    // Excel writes a BOM, which makes the first header read as "\u{FEFF}ic"
    // and match no column at all.
    $path = tempnam(sys_get_temp_dir(), 'bom').'.csv';
    file_put_contents($path, "\xEF\xBB\xBFic,email\n890508-12-0000,siti@example.com\n");

    expect(Artisan::call('legacy:emails', ['path' => $path, '--force' => true]))->toBe(0)
        ->and($user->fresh()->email)->toBe('siti@example.com');
});

it('records the change, because an identity edit must leave a trail', function () {
    $user = importedParticipant('890508-12-0000');

    Artisan::call('legacy:emails', [
        'path' => csv(['ic,email', '890508-12-0000,siti@example.com']),
        '--force' => true,
    ]);

    // The `updated` row specifically. Ordering on created_at alone picks the
    // factory's `created` row as often as not — they share a timestamp.
    $trail = DB::table('audit_trails')
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('action', 'updated')
        ->orderByDesc('id')
        ->first();

    expect($trail)->not->toBeNull('No audit row for an email change.')
        ->and($trail->new_values)->toContain('siti@example.com')
        ->and($trail->old_values)->toContain('usahawan1@legacy.test');

    // The password is excluded from the trail, and has to stay excluded.
    expect($trail->new_values)->not->toContain('password');
});

it('says so and stops when the file has no email column', function () {
    importedParticipant('890508-12-0000');

    $exit = Artisan::call('legacy:emails', [
        'path' => csv(['ic,name', '890508-12-0000,SITI']),
        '--force' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('email');
});
