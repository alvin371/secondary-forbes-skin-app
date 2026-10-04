# Devplan: `creator-trends` — respons kecil, detail on-demand, paginasi server, gzip JSON

Status: draft · 2026-10-04 · lanjutan dari `docs/devplan-endorse-post-daily.md`

## 1. Masalah (terukur di produksi, 2026-10-04)

Tab **Trends** di `/endorse/analytics` memanggil `ajax/creator-trends`, yang mengirim **semua creator ×
semua hari × detail lengkap** dalam satu respons.

| c27, range 30 hari | Nilai |
|---|---|
| Status HTTP dari `bkasystem.com` | **500** (c27 dan c39; c44 kecil = 200) |
| Penyebab | memori puncak PHP **222 MB** > `memory_limit` 128M |
| Creator / baris detail | 3.664 / 108.117 |
| Ukuran JSON | **16,5 MB** |
| — `daily_detail` (hanya dipakai modal 1 creator) | 13,8 MB (84%) |
| — `dates` diulang per creator | ~1,4 MB |
| Waktu tanpa limit memori | 2,6 s (setelah PR #49), 5,4 s (sebelumnya) |
| gzip nginx untuk JSON | **mati**: `gzip on`, tapi `gzip_types` dikomentari → hanya `text/html` |
| Browser | merender 3.664 baris × 1 chart Chart.js (belum diukur) |

Query DB bukan lagi bottleneck (0,40 s di `endorse_post_daily`). Masalahnya **bentuk dan ukuran respons**.

`ajax/anomalies` punya bug yang sama, tapi **tidak dipanggil** dari mana pun di repo.

## 2. Solusi (gabungan, disetujui 2026-10-04)

| Kode | Cara | Lapisan |
|---|---|---|
| #4 | gzip `application/json` (+ JS/CSS) di nginx host | jaringan, semua endpoint |
| #5 | `daily_detail` dipindah ke endpoint detail, dimuat saat modal dibuka | ukuran + memori |
| #6 | format kolumnar: `dates` sekali, `values` per creator sejajar `dates` | ukuran |
| #7 | paginasi + sort + search + filter platform di server (50 per halaman) | ukuran + browser |
| #13 | hapus `get_anomalies` bila tidak ada pemakai | kode mati |

Ukuran yang sudah diukur dari respons c27 30 hari: tanpa detail 2,68 MB → kolumnar 281 KB → 50 creator 39 KB.

## 3. Keputusan yang dibutuhkan sebelum eksekusi

| # | Keputusan | Rekomendasi |
|---|---|---|
| K1 | #7 paginasi server (tab Trends tetap) **atau** #12 gabung ke tab Performers | **#7** — tampilan tetap, bukan keputusan produk |
| K2 | Hotfix sementara `memory_limit` (opsi #1) sambil menunggu rilis? | Tidak, bila rilis ≤ 1–2 hari; ya bila lebih lama |
| K3 | Siapa mengubah nginx host (butuh sudo) | User, atau saya dengan konfirmasi eksplisit per perintah |
| K4 | Akses log nginx host untuk cek pemakai `anomalies` (butuh grup `adm`/sudo) | User jalankan 1 perintah grep (§5 P1) |

**Jawaban (2026-10-04):**
- K1 = #7, paginasi di server.
- K2 = belum diputuskan. Kalau dipasang, bentuknya `ini_set('memory_limit','512M')` khusus di `get_creator_trends()` dan ikut hilang di PR P7.
- K3 = saya jalankan, dengan konfirmasi untuk setiap perintah.
- K4 = `sudo zgrep -c "ajax/anomalies" /var/log/nginx/access.log*` → 0 di 15 file (14 hari), jadi `get_anomalies` dihapus.

## 4. Desain

### 4.1 `GET ajax/creator-trends` (kontrak baru)

Parameter: `id_campaign`, `start_date`, `until_date` (sama seperti sekarang), ditambah
`page` (default 1), `per_page` (default 50, maks 100), `sort` (`views_desc` | `views_asc` | `name_asc` | `name_desc`),
`q` (search), `platform`.

```json
{
  "dates": ["2026-09-04", "2026-09-05", "..."],
  "total": 3664,
  "page": 1,
  "per_page": 50,
  "creators": [
    {
      "id_endorse": 123, "nama_creator": "…", "platform": "Tiktok", "link_upload": "…",
      "posting_at": "…", "status_endorse": "…", "total_views_gain": 343743,
      "values": [120, null, 88, "…"]
    }
  ]
}
```

- `dates` = tanggal yang punya log untuk minimal satu creator **di halaman ini**, urut naik. Ini lebih hemat satu query
  `DISTINCT log_date` (~108 ms pada c27) dibanding mengambil semua tanggal campaign. `values[i]` sejajar `dates[i]`,
  bernilai `null` bila creator tidak punya log di tanggal itu.
- Hanya creator yang punya ≥1 log dalam range (sama dengan `INNER JOIN` sekarang).
- **Search** = sama dengan `applyRowFilter`: substring tanpa beda huruf besar/kecil pada
  `nama_creator + ' ' + platform + ' ' + link_upload` → `LIKE` dengan `escape_like_str`.
- **Platform** = sama persis tanpa beda huruf besar/kecil.
- **Sort** diberi tie-breaker `e.id` supaya paginasi stabil.

Query (semuanya memakai PK `endorse_post_daily (id_campaign, log_date, id_endorse)`):

1. Creator: `(SELECT id_endorse, SUM(views_gain) … GROUP BY id_endorse) g JOIN endorse e` + filter + `ORDER BY`.
   Semua creator yang cocok diambil sekaligus (1 baris per creator, ≤ beberapa ribu baris) lalu dipotong per halaman di PHP.
   Dengan cara ini agregasi cukup jalan sekali, tidak perlu dua kali (`COUNT` + `LIMIT`).
2. `values`: `SELECT id_endorse, log_date, views_gain … AND id_endorse IN (≤100 id halaman ini)`. `dates` diturunkan dari hasil ini.

### 4.2 `GET ajax/creator-trend-detail` (baru)

Parameter `id_endorse`, `start_date`, `until_date`. Mengembalikan array `daily_detail` dengan field yang sama
dengan yang sekarang dibaca modal: `date, views_before, views_after, views_gain, likes_gain, comment_gain, share_save_gain`.
Satu query range di `idx_epd_endorse_date`, ≤ 1 baris per hari.

### 4.3 Frontend (`views/endorse/analytics.php`, tab Trends)

- `loadTrends(page)` memanggil kontrak baru. Search (debounce 300 ms), filter platform, dan sort memicu reload ke halaman 1.
- Kontrol halaman (« Sebelumnya / Berikutnya » + "x–y dari N konten").
- Sparkline: label = `dates` yang `values`-nya bukan `null`. Tampilannya sama dengan sekarang (hari tanpa log tidak
  digambar).
- Tombol detail → `creator-trend-detail` → `showDetailModal` (fungsi lama, tidak diubah).
- `goToTrends(name)` dari tab Performers → set `q` lalu reload.
- **Perubahan kecil yang terlihat:** nomor urut (#) dihitung dari hasil yang sudah difilter
  (sebelumnya: urutan sebelum filter).

### 4.4 nginx host (#4)

Di `/etc/nginx/nginx.conf`, aktifkan baris yang sudah ada:

```nginx
gzip_vary on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
```

Lalu jalankan `nginx -t && systemctl reload nginx` (reload tanpa downtime). Rollback: komentari lagi, lalu reload.

## 5. Tahapan & gerbang

| # | Tahap | Gerbang lulus |
|---|---|---|
| P0 | Keputusan K1–K4 | Terjawab |
| P1 | **Cek pemakai `anomalies`**: `sudo zgrep -c "ajax/anomalies" /var/log/nginx/access.log*` (14 hari) | 0 → hapus di P4. >0 → biarkan, buat tiket |
| P2 | **gzip nginx** (§4.4), dengan backup `nginx.conf` | `nginx -t` OK. Ukur sebelum/sesudah dengan `curl -H 'Accept-Encoding: gzip'`: creator-trends c27 3 hari (2,68 MB), salah satu JS/CSS halaman. `Content-Encoding: gzip` muncul |
| P3 | **Backend**: kontrak baru + endpoint detail + route | Lint, phpunit hijau |
| P4 | **Frontend** + hapus `get_anomalies` & route-nya (bila P1 = 0) | — |
| P5 | **Regresi kesetaraan** (lihat §6) | 0 selisih |
| P6 | **Ukur** di sec (CLI, lalu HTTP setelah deploy) | c27 & c39 range 30 hari: halaman 1 < 300 ms, detail < 100 ms, memori puncak < 32 MB, respons < 50 KB (tanpa gzip), HTTP 200 |
| P7 | **Rilis** 1 PR (backend + frontend harus naik bersama karena kontrak berubah). User deploy | Cek di browser: tab Trends c27 terbuka, search/filter/sort/halaman/modal berfungsi |

## 6. Regresi

Kontrak berubah, jadi yang dibandingkan adalah **isi datanya**, bukan bytes. Pembandingnya adalah kode yang
ter-deploy, dijalankan tanpa limit memori. 24 campaign × range 3d / 30d / MTD:

1. **Isi:** gabungkan semua halaman (`sort=views_desc`) → untuk setiap creator, `total_views_gain` dan pasangan
   (tanggal → nilai) harus sama dengan respons lama.
2. **Detail:** `creator-trend-detail` = `daily_detail` lama. Semua creator untuk campaign kecil, 50 creator acak per
   campaign besar.
3. **Urutan:** keempat sort monoton; ties diurutkan `id`; tidak ada creator yang hilang/dobel antar halaman.
4. **Filter:** hasil `q` dan `platform` di server = `applyRowFilter` yang di-port ke PHP, dijalankan pada data lama.
   Sampel `q`: nama creator, potongan URL, huruf besar, karakter `%` dan `_`.

## 7. Rollback

- Kode: revert PR (endpoint lama kembali, masih 500 untuk campaign besar seperti sebelumnya).
- nginx: kembalikan `nginx.conf` dari backup lalu reload.
- Tidak ada perubahan schema.

## 8. Di luar cakupan (tiket terpisah)

- **Semua endpoint `ajax/*` analytics tidak memeriksa login.** Data creator bisa diambil dari internet tanpa sesi
  (terverifikasi 2026-10-04).
- `endorse_refresh_queue` / `_attempts` tanpa arsip (~1,7 GB), kolom mati di `endorse_logs`
  (lihat `devplan-endorse-post-daily.md` §8).

## 9. Hasil eksekusi (2026-10-04, DB sec, belum dirilis)

**P5 — regresi kesetaraan.** Ada 24 campaign × 3 range. Yang dicek: isi tiap creator (total, pasangan tanggal→nilai,
metadata), urutan `views_desc`, tidak ada creator ganda antar halaman, monotonic `views_asc`, detail (semua creator di
campaign kecil, 30 acak di campaign besar), dan 6 query search × 3 platform dibandingkan dengan `applyRowFilter` yang
di-port ke PHP. Hasilnya **48.249 pengecekan, 0 gagal**, dijalankan dua kali (sebelum dan sesudah sort dipindah ke PHP).

**P6 — ukuran** (CLI CI dengan `memory_limit=128M`, termasuk ~40–90 ms bootstrap, 3 run):

| c27 / c39, 30 hari | Sebelum | Sesudah |
|---|---|---|
| status | 500 (memori) | OK |
| respons | 16,5 MB | 16–21 KB |
| memori puncak | 222 MB | 6–10 MB |
| halaman 1 (views) | — | 199–367 ms (median ~260) |
| sort nama, halaman 20 | — | 187–275 ms |
| search + platform | — | 225–348 ms |
| detail modal | (di dalam 16,5 MB) | 87–95 ms (DB 3 ms) |

Komponen terbesar adalah agregasi per creator di DB (192 ms untuk c27). Awalnya sort nama memakai `ORDER BY` pada
`varchar(225)` dan menambah ~80 ms, sehingga dipindah ke PHP (`strcasecmp`, tie berdasarkan `id`).
Ekstensi `intl` tidak terpasang di container, jadi `Collator` tidak bisa dipakai.

Perubahan yang terlihat oleh pengguna: nomor urut dihitung setelah filter; sort nama tanpa membedakan huruf besar/kecil
dengan urutan byte (bisa sedikit berbeda dari `localeCompare` browser untuk huruf beraksen).
