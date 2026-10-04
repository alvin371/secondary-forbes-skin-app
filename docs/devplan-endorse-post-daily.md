# Devplan: `endorse_post_daily` — read model ramping untuk analytics endorse

Status: draft · 2026-10-04 · target: setiap endpoint analytics halaman `/endorse` < 600 ms tanpa cache

## 1. Masalah

Endpoint analytics (`ajax/analytics-summary`, `missing-creators`, `performers-ranking`,
`creator-trends`, `anomalies`) menghitung ulang agregat dari `endorse_logs` di setiap request.

Ukuran nyata (DB sec, 2026-10-04, c27 = 3.698 post, range 30 hari):

| Query summary | Waktu | Setelah rewrite pakai `log_date` + index paksa |
|---|---|---|
| post belum ter-log | 0,48 s | 0,22 s |
| rata-rata views harian | 0,96 s | 0,80 s |
| top creator | 0,81 s | 0,71 s |
| anomali | 1,05 s | 0,78 s |
| **total** | **3,3 s** | **~2,5 s** |

Akar masalah (bukan query-nya, tapi bentuk tabelnya):

1. **Clustered by `id` auto-increment.** Cron menulis log semua campaign bercampur (~15,5 rb baris/hari),
   jadi 108 rb baris c27 sebulan tersebar di ~2.771 page (~43 MB). Index menemukan baris, tapi tiap baris
   tetap satu lookup acak ke PK.
2. **Baris lebar: 381 byte rata-rata.** before/delta/after untuk 5 metrik, salinan kolom `endorse`,
   tanggal `varchar(225)`. Summary hanya butuh ~12 byte.
3. **Agregasi saat baca.** Riwayat yang sama dijumlahkan ulang tiap page load.

Pembanding yang sudah diukur: scan 108 rb entri index ramping tanpa lookup PK = 0,10–0,30 s.

## 2. Fakta data (diukur global, bukan asumsi)

| Fakta | Nilai | Dampak ke desain |
|---|---|---|
| `endorse_logs` | 1.907.829 baris, 593 MB data | backfill dibagi chunk |
| post-hari dengan >1 log | **0** | 1 baris log = 1 baris read model |
| `log_date` NULL | 0 | `log_date NOT NULL` aman |
| `log_date` ≠ `DATE(date)` | 0 | `log_date` bisa ganti `DATE(el.date)` |
| `views` ≠ `views_after − views_before` | 0 (c27) | gain = after − before |
| log tanpa `endorse` (orphan) | 2 | pembaca tetap join `endorse` (sama seperti sekarang) |
| `endorse_logs.id_campaign` ≠ `endorse.id_campaign` | 2 | id_campaign diambil dari `endorse` |
| trigger yang sudah ada | 6 (`trg_endorse_*_snapshot_*`) | trigger baru terpisah; MySQL 8 mengizinkan >1 trigger per event |
| `log_bin_trust_function_creators` | 1 (sudah di-set) | trigger/procedure bisa dibuat |
| buffer pool | 2 GB, dipakai bersama prod + sec | tabel ramping lebih mungkin tetap di memori |

## 3. Desain

### 3.1 Tabel

```sql
CREATE TABLE endorse_post_daily (
  id_campaign     INT  NOT NULL,   -- dari endorse.id_campaign (fallback endorse_logs.id_campaign untuk orphan)
  log_date        DATE NOT NULL,
  id_endorse      INT  NOT NULL,
  views_before    INT  NOT NULL,   -- MAX(views_before) hari itu
  views_after     INT  NOT NULL,   -- MAX(views_after) hari itu
  views_gain      INT  NOT NULL,   -- SUM(views_after - views_before)
  likes_gain      INT  NOT NULL,
  comment_gain    INT  NOT NULL,
  share_save_gain INT  NOT NULL,
  PRIMARY KEY (id_campaign, log_date, id_endorse),  -- data 1 campaign per range tanggal berdampingan
  KEY idx_epd_endorse_date (id_endorse, log_date)   -- MAX(log_date) per post, sinkron dari trigger
) ENGINE=InnoDB;
```

Definisi kolom identik dengan ekspresi yang dipakai endpoint sekarang
(`GROUP BY e.id, DATE(el.date)` + `SUM(x_after - x_before)` / `MAX(views_before|after)`),
sehingga output endpoint tidak berubah. Perkiraan ~60 byte/baris termasuk overhead → ~115 MB total vs 818 MB.

### 3.2 Sinkronisasi: trigger (pencatatan tidak diubah)

`endorse_logs` tetap sumber kebenaran; worker & `Endorse_sync` tidak disentuh.

- Procedure `endorse_post_daily_refresh(p_endorse, p_date)`: hapus baris (p_endorse, p_date), lalu
  `INSERT ... SELECT ... GROUP BY` ulang dari `endorse_logs`. Menghitung ulang (bukan menambah) membuatnya
  benar untuk insert/update/delete dan untuk kasus >1 log per hari.
- `endorse_logs` AFTER INSERT / UPDATE / DELETE → panggil procedure untuk (id_endorse, log_date) lama dan baru.
- `endorse` AFTER UPDATE → jika `id_campaign` berubah, pindahkan baris post itu. AFTER DELETE → hapus barisnya.

### 3.3 Pembaca (Ajax.php)

| Endpoint | Sekarang | Baru |
|---|---|---|
| analytics-summary | 4 query di `endorse_logs` | 1 query MAX per post (index `id_endorse,log_date`) + 1 query range PK, agregasi di PHP |
| missing-creators | join `endorse_logs` seluruh riwayat | subquery MAX(log_date) per post di `endorse_post_daily` |
| performers-ranking | LEFT JOIN `endorse_logs` + `DATE()` | LEFT JOIN derived table `SUM ... GROUP BY id_endorse` dari range PK |
| creator-trends | JOIN `endorse_logs` + `DATE()` | JOIN range PK |
| anomalies | JOIN `endorse_logs` + `DATE()` | JOIN range PK |

Kontrak JSON tiap endpoint tidak berubah. Logika PHP setelah query (anomali, restrukturisasi trends) tidak diubah.

## 4. Tahapan & gerbang

Setiap tahap berhenti jika gerbangnya gagal.

| # | Tahap | Gerbang lulus |
|---|---|---|
| P0 | **Prototipe ukur.** Buat `endorse_post_daily_proto` di sec, isi dengan `INSERT ... SELECT` per chunk 100 rb id. Ukur ke-5 endpoint (SQL baru) untuk c27 & c39, range 3 hari / 30 hari / seluruh riwayat. | summary c27 30 hari < 300 ms; tiap endpoint < 600 ms. Durasi backfill tercatat. |
| P1 | **Uji trigger** di tabel uji terpisah (`zz_epd_logs`, `zz_epd_endorse`, `zz_epd`): insert, update nilai, update tanggal, delete, pindah campaign, delete endorse. Bandingkan hasil trigger vs rebuild penuh. | 0 selisih. Overhead trigger per insert diukur. |
| P2 | **Kode.** Migration (tabel, procedure, trigger, backfill chunk) + rewrite 5 endpoint. | Regresi: output endpoint lama (kode deploy) vs baru (salinan app di `/tmp`) untuk **24 campaign × 4 range × 5 endpoint** identik (tie top creator/urutan dianggap sama jika nilainya sama). phpunit hijau. |
| P3 | **Rilis dua PR** (lihat §5). User deploy; migration dijalankan setelah konfirmasi. | Ukur HTTP di sec port 38734: setiap endpoint < 600 ms untuk c27/c39. |
| P4 | **Bersih-bersih (terpisah, tidak dieksekusi di devplan ini).** | — |

## 5. Rilis tanpa jendela error

Kode pembaca butuh tabel yang sudah terisi. Agar tidak ada jendela di mana endpoint membaca tabel kosong:

1. **PR A — migration saja** (tabel + procedure + trigger + backfill). Deploy → `php index.php migrate run`.
   Trigger dipasang **sebelum** backfill; backfill memakai `INSERT IGNORE` per chunk, jadi baris yang
   sudah ditulis trigger tidak ditimpa nilai lama. Endpoint lama tetap jalan selama proses.
2. **PR B — pembaca.** Deploy setelah PR A termigrasi dan regresi ulang di data produksi lulus.

Rollback: PR B → revert (pembaca kembali ke `endorse_logs`). PR A → `migrate` ke versi sebelumnya
(`down()` drop trigger, procedure, tabel). `endorse_logs` tidak pernah diubah, jadi tidak ada data yang hilang.

## 6. Risiko

| Risiko | Mitigasi |
|---|---|
| Trigger menambah kerja tiap tulis `endorse_logs` (~15,5 rb/hari) | Diukur di P1; procedure hanya membaca 1 baris via index `(id_endorse, log_date)` |
| Backfill mengunci baris sumber (`INSERT ... SELECT` mengambil shared lock) | Chunk 100 rb id; durasi per chunk diukur di P0 |
| Prototipe membebani DB yang dipakai bersama prod | Chunk + dijalankan sekali; tabel proto di-drop setelah P2 |
| Drift: tulis langsung ke DB di luar trigger (mis. restore manual) | Procedure bisa dipanggil ulang; rebuild penuh = backfill yang sama |

## 7. Hasil eksekusi (2026-10-04, DB sec)

**P0 — prototipe.** Backfill 1.907.829 baris dalam 48 s (~2,5 s per chunk), 166 MB data + 64 MB index
(vs 818 MB `endorse_logs`). Join balik ke `endorse` per baris ternyata mahal (+0,4 s untuk 108 rb baris,
baris `endorse` ~3 KB), jadi tabel tidak menyimpan orphan dan summary tidak join ulang.
Query summary (DB saja, 30 hari): c27 0,31 s, c39 0,25 s.

**P1 — trigger.** 13 skenario (insert, update nilai, pindah hari, 2 log sehari, pindah post, delete,
pindah campaign, delete post, orphan, post muncul setelah log, no-op, 2.000 insert) = rebuild penuh, 0 selisih.
Overhead: 4,21 vs 4,19 ms per insert (dalam noise).

**P2 — regresi endpoint** (kode deploy vs kode baru, lewat CLI CI, termasuk ~40–90 ms bootstrap):
24 campaign × range 3d/30d/MTD/semua × 5 endpoint = **312 kasus, 312 identik**.

| c27 (3.698 post) | lama | baru |
|---|---|---|
| analytics-summary 30d | 3.414 ms | **416 ms** |
| analytics-summary MTD | 2.422 ms | 125 ms |
| analytics-summary semua riwayat | 9.772 ms | 1.316 ms |
| missing-creators | 596 ms | 324 ms |
| performers-ranking 30d | 1.137 ms | 365 ms |
| creator-trends 30d | 4.273 ms* | 2.386 ms* |
| anomalies 30d | 3.986 ms* | 2.484 ms* |

\* **Bug lama, bukan dari perubahan ini:** creator-trends & anomalies 30 hari untuk campaign besar
(c26/c27/c39) butuh ~222 MB, sedangkan `memory_limit` web 128M → di produksi keduanya sudah fatal (500),
kode lama maupun baru. Waktu di atas diukur tanpa limit. Bottleneck-nya PHP (108 rb baris → JSON), bukan DB
(query baru 0,40 s). Perlu perubahan kontrak respons (mis. agregasi per creator / paginasi) — tiket terpisah.

## 8. Di luar cakupan (dicatat untuk P4 / tiket lain)

- `endorse_refresh_queue` 1,94 jt baris completed (~1 GB) dan `_attempts` 2 jt baris (~666 MB) tanpa arsip.
- Kolom `endorse_logs.cpm_before`, `cpm` (0 di 100% baris), `is_cron` (0 di 100% baris), `stats_*` (tidak dibaca di repo; cek worker).
- `endorse_logs_daily_rollup` (708 rb baris) hanya dibaca bila `ENDORSE_ROLLUP_READ=1` (default 0).
- 10 tabel `endorse_refresh_*` / `*_archive` / `endorse_stats_snapshots` kosong.
- V2 snapshot builder bisa membaca `endorse_post_daily` untuk build lebih cepat.
- Endpoint `Ajax` analytics tidak memeriksa login.
