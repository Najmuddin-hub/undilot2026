# Kiraan detik sebelum lot didedahkan

2026-11-14

## Masalah

Peserta menekan **Confirm Lot** dan lotnya muncul. Antara dua perkara itu tiada
apa — POST, ubah hala, halaman baharu — dan pada mesin pantas keseluruhannya
berlaku dalam beberapa kerangka. Mata tidak sempat menangkap peralihan itu.

Ini **bukan** tentang ketegangan. Ia jeda supaya mata sempat mendarat, dan
kiraan 3-2-1 ialah cara memberi jeda yang boleh difahami: peserta tahu berapa
lama lagi, bukan sekadar menatap skrin yang beku.

## Keputusan

Lapisan kelabu menutup skrin sebaik **Confirm Lot** ditekan, mengira 3 … 2 … 1,
dan **barulah borang dihantar**.

```
[Confirm Lot] ditekan
      │  skrip memintas — borang BELUM dihantar
      ▼
lapisan kelabu:  3 … 2 … 1          (3 saat, tepat)
      │
      ▼
borang dihantar → POST (lot diperuntuk, berkunci) → halaman lot + reveal
```

### Kenapa hantar SELEPAS kiraan, bukan serentak

Kalau borang dihantar dahulu dan lapisan dipaparkan sambil menunggu, lapisan itu
hidup selama POST mengambil masa sahaja — 200ms pada mesin pantas, empat saat
pada rangkaian perlahan. Tiada peserta mendapat jeda yang sama, dan pada mesin
pantas tiada jeda langsung. Itu tepat masalah yang ciri ini wujud untuk
menyelesaikan.

Hantar selepas kiraan memberi **tepat tiga saat kepada setiap peserta**, tanpa
mengira rangkaian. Rangkaian kemudian berlaku di belakang lapisan yang masih
terpapar: penyemak imbas mengekalkan halaman lama sehingga halaman baharu sedia,
jadi peserta tidak nampak kelipan.

Ia juga lebih selamat. Tutup tab pada "2" dan POST tidak pernah dihantar: tiada
lot diambil, peserta klik semula nanti. Hantar-dahulu boleh meninggalkan lot
diperuntuk kepada peserta yang tidak pernah melihatnya — dan lot itu tidak boleh
dikembalikan tanpa campur tangan pejabat.

## Apa yang TIDAK berubah

**`lot-reveal.blade.php` tidak disentuh.** Ia sudah main pada `session('just_drawn')`
selepas halaman tiba, dan itu kekal betul. Kiraan detik berlaku pada halaman
**sebelum** ubah hala; reveal pada halaman selepasnya. Dua perkara berasingan
pada dua halaman, bukan satu jujukan yang perlu diselaraskan.

**`LotDrawController::submit()` tidak disentuh.** Peruntukan, kunci dan
pengendalian `LotUnavailable` kekal sama. Ini perubahan paparan sahaja.

**`LotAllocator` tidak disentuh.**

## Fail

| Fail | Tindakan |
|---|---|
| `resources/views/zvote/partials/lot-countdown.blade.php` | **baharu** — lapisan, kiraan, gaya dan skripnya |
| `resources/views/zvote/vote.blade.php` | `@include` partial itu dalam blok borang |
| `tests/Feature/TheLotCountdownHoldsBeforeTheDrawTest.php` | **baharu** |
| `tests/e2e/02-lot-draw.spec.js` | tunggu kiraan sebelum menjangka ubah hala |

Satu partial memegang keseluruhan ciri: markup, gaya dan skrip. Itu corak repo
ini — tiada langkah bina, setiap stylesheet inline dalam partial Blade.

## Tingkah laku

### Pintasan berlaku pada `submit`, bukan `click`

Kotak semak persetujuan ialah `required`. Peristiwa `submit` hanya menyala
**selepas** pengesahan HTML5 lulus, manakala `click` menyala sebelumnya. Memintas
`click` bermakna peserta yang lupa menanda kotak itu melihat kiraan tiga saat
dan kemudian mesej ralat penyemak imbas — jeda untuk sesuatu yang tidak berlaku.

```js
form.addEventListener('submit', function (e) {
    if (counting) return;        // kiraan tamat; biarkan ia pergi
    e.preventDefault();
    // …tunjuk lapisan, kira, kemudian form.submit()
});
```

`form.submit()` **tidak** mencetuskan peristiwa `submit`, jadi tiada gelung.

### Butang dimatikan sebaik diklik

Klik dua kali semasa kiraan tidak boleh menghantar dua kali. `LotAllocator`
memang berkunci dan akan menolak yang kedua, tetapi bergantung pada kunci untuk
sesuatu yang boleh dihalang di skrin ialah membiarkan peserta melihat ralat yang
tidak perlu wujud.

### JavaScript mati → borang hantar terus

Lapisan dicipta **oleh skrip**, bukan ditulis dalam HTML dengan `hidden`. Kalau
skrip tidak berjalan, tiada lapisan untuk tersangkut: borang hantar seperti hari
ini, dan peserta kehilangan jeda sahaja. Cabutan tetap berfungsi.

Ini bukan pilihan gaya. Lapisan yang ditulis dalam HTML dan disorok oleh skrip
menjadi skrin kelabu kekal apabila skrip gagal — dan tiada apa pada skrin itu
untuk membuangnya.

### `prefers-reduced-motion` → jeda kekal, animasi tidak

Nombor bertukar tanpa zum, pudar atau pusing. **Tiga saat itu kekal.** Keutamaan
itu tentang gerakan yang mengganggu keseimbangan, bukan tentang rentak; mata
yang memerlukan masa masih memerlukannya.

Ini berbeza daripada `lot-reveal.blade.php`, yang **melangkau** animasinya
sepenuhnya di bawah keutamaan yang sama — betul di sana, kerana di sana animasi
itulah keseluruhan perkara dan kod lot sudah tercetak pada kad di bawahnya.

### Kebolehcapaian

- Lapisan membawa `role="status"` dan `aria-live="polite"` supaya pembaca skrin
  mengumumkan setiap nombor.
- Fokus dialihkan ke lapisan dan tidak boleh keluar ke belakangnya semasa kiraan.
- Lapisan menutup keseluruhan viewport; tiada apa di belakangnya boleh diklik.

## Ujian

### Pest

`tests/Feature/TheLotCountdownHoldsBeforeTheDrawTest.php` — satu fail, tiga
kes:

1. Partial kiraan dirender apabila cabutan terbuka dan peserta belum mengundi.
2. Ia **tidak** dirender selepas peserta mengundi — tiada lagi borang untuk
   dihantar, dan lapisan di sana akan menutup lot yang baru didedahkan.
3. Ia **tidak** dirender apabila butang mati (`code` tiada) — kiraan yang
   mendahului borang yang tidak boleh dihantar ialah jeda untuk ketiadaan.

Pest tidak boleh menguji animasi. Ia menguji kehadiran dan ketiadaan, yang
cukup untuk menangkap partial yang dirender di tempat salah.

### Playwright

`02-lot-draw.spec.js:121` ialah **satu-satunya** spec yang menekan butang
sebenar — disahkan dengan grep. `03-concurrent-confirm` memintas pelayar
sepenuhnya melalui skrip PHP, jadi ia tidak terjejas.

Baris itu kini:

```js
await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: /Confirm Lot/ }).click()]);
```

`waitForNavigation` lalai tamat masa 30 saat, jadi tiga saat tidak akan
memecahkannya. Tetapi spec itu perlu **menegaskan lapisan itu muncul**, bukan
sekadar bertahan melaluinya — kalau tidak, kiraan boleh hilang sepenuhnya dan
suite kekal hijau.

```js
await page.getByRole('button', { name: /Confirm Lot/ }).click();
await expect(page.locator('[data-lot-countdown]')).toBeVisible();
await page.waitForURL(/\/lot$/);
```

### Pengesahan tangan

Jeda tiga saat tidak boleh dibuktikan oleh ujian yang boleh dipercayai pada
mesin ini — suite pelayar sedang merosot, dan masa dinding bukan sesuatu yang
boleh ditegaskan dengan yakin di bawahnya. Jadi: buka `/events/{event}/lot`
sebagai peserta, tanda kotak, tekan butang, dan perhatikan. Lapisan mesti
muncul serta-merta, mengira tiga nombor, dan kekal sehingga halaman baharu
tiba.

## Risiko

**Peserta menekan Back semasa kiraan.** Penyemak imbas meninggalkan halaman;
tiada POST dihantar, tiada lot diambil. Sama seperti tidak menekan langsung.

**Kiraan berjalan, POST gagal** (rangkaian putus selepas tiga saat). Peserta
melihat ralat penyemak imbas biasa. Tiada keadaan separuh jadi: lot diperuntuk
dalam transaksi berkunci pada pelayan, atau tidak langsung.

**Peserta melalui 35 cabutan.** Tiga saat × 35 = hampir dua minit menunggu.
Tetapi peserta sebenar ada satu atau dua cabutan; 35 ialah data ujian pada mesin
ini. Kalau pejabat kemudian melaporkan ia menjemukan, tempoh itu satu nombor
dalam satu fail.
