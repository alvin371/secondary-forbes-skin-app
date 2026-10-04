# Tech Debt

Catatan kode yang sengaja dimatikan/dihapus sementara, beserta cara mengembalikannya.

## TD-1: /dashboard dalam mode statis (2026-10-04)

**Status:** semua fetch data di `/dashboard` dimatikan, angka tampil `-`. Nilainya tidak pernah ter-update dan query-nya berat.

**Saklar:** `window.DASHBOARD_STATIC = true` di baris paling atas `application/views/dashboard/all.php`. Set `false` untuk mengaktifkan lagi fetch AJAX berikut:

| Fetch | Endpoint |
|---|---|
| Carousel Laporan (hingga 17 card) | `ajax/get_summary` |
| Tab Grafik | `ajax/get_summary_batch` per channel + `ajax/get-chart` |
| Checkbox grafik / klik card | `ajax/checkbox`, `refresh_chart()` |
| Tooltip Order-11 / Order-8 | `dashboard/expense_data`, `dashboard/net_sales_data`, `dashboard/laba_bersih_data` |

### Kode yang dihapus dari `Dashboard.php`

Hasil query-query ini tidak pernah dipakai view, karena total spend diambil ulang via `dashboard/expense_data`:

- di `index()`: pemanggilan `calculate_ads/kol/etc_spending()` dan string `$sql_spend_ads` yang tidak dieksekusi
- method private `calculate_ads_spending`, `calculate_kol_spending`, `calculate_etc_spending`

Kode aslinya ada di commit `7ecfd08` (sudah ada di `origin/develop`):

```bash
git show 7ecfd08:application/controllers/Dashboard.php | sed -n '1398,1517p'   # 3 method
git show 7ecfd08:application/controllers/Dashboard.php | sed -n '115,196p'     # blok di index()
```

### Wajib diperbaiki sebelum dipakai lagi

1. **`calculate_ads_spending`: cartesian product.** Query ini melakukan LEFT JOIN ke `shopee_ads_data`, `meta_ads_data`, `tiktok_ads_data`, dan `advertiser_spend` tanpa join key antar tabel. Akibatnya jumlah baris berlipat (A×B×C×D) dan setiap SUM ikut terkali. Hasilnya **salah** dan query-nya **sangat berat**. Perbaikan: jumlahkan masing-masing tabel di subquery terpisah, lalu tambahkan hasilnya:
   `SELECT (SELECT COALESCE(SUM(...),0) FROM shopee_ads_data ...) + (SELECT ... FROM meta_ads_data ...) + ...`
2. **`calculate_kol_spending`: `SUM(DISTINCT e.nominal_dibayarkan)`.** Dua pembayaran dengan nominal sama hanya terhitung sekali. Ganti dengan `SUM(...)` biasa. Kalau `DISTINCT` dipakai untuk menghindari duplikasi akibat join, perbaiki join-nya.
3. **SQL injection:** `$start_date`, `$until_date`, dan `$brand_filter` dimasukkan mentah ke SQL. Gunakan query binding (`?`).
4. Filter brand memakai `LIKE 'X%'` dari huruf pertama nama brand. Brand dengan huruf awal yang sama akan tercampur.

## TD-2: Endorse campaign, sisa temuan (2026-10-04)

- `Endorse_campaign::update_endorse_parent()`: menjalankan sekitar 12 query. Enam `COUNT` bisa digabung menjadi satu query dengan conditional aggregation.
  - Bug: `$dt['now_before']` seharusnya `ci_before`, sehingga nilai `ci_now` salah.
  - Blok CPM kedua membaca `total_cost` yang tidak di-SELECT, jadi blok itu mati.
- `Endorse_campaign::edit()`: `WHERE id = '$id'` rentan SQL injection. `SELECT * FROM user` (termasuk hash password) disimpan di cache APC/file. Cukup ambil `id, full_name`.
- `delete()`: menghapus 4 tabel tanpa transaksi. Pertimbangkan FK `ON DELETE CASCADE`.
- Opsional, kalau data tumbuh besar: simpan `total_post`, `posted`, `rejected`, dan `latest_sync_at` di `endorse_campaign` agar list cukup 1 query.
