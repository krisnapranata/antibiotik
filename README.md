# Aplikasi Antibiotik

Aplikasi PHP sederhana untuk laporan pemakaian antibiotik (triwulan & semester) dari database SIMRS Khanza. Daftar nama obat (include/exclude) dikelola sendiri lewat menu Master sehingga bisa dipakai di semua rumah sakit.

## Menjalankan dengan Docker

```bash
docker compose up -d --build
```

Buka http://localhost:8080.

- Image: `php:8.3-apache` + ekstensi `pdo_mysql`, `mysqli`, `gd`, `zip`, `xml`.
- `composer install` dijalankan saat build (PhpSpreadsheet untuk export Excel).
- Saat container pertama kali start, `entrypoint.sh` menyalin `config.example.json` → `config.json` (hanya jika belum ada) dan memastikan seluruh folder dimiliki `www-data` — aplikasi menulis `config.json`/`master.json` saat runtime.

### Konfigurasi awal

1. Isi koneksi DB SIMRS lewat menu **Pengaturan** di web (atau edit `config.json`).
2. Database SIMRS harus bisa dijangkau dari dalam container — gunakan IP host/DB server, bukan `localhost`.

### Menyimpan config/master secara permanen

Tanpa volume, `config.json` dan `master.json` hilang saat container dihapus/dibangun ulang. Untuk menyimpannya di host, buka komentar blok `volumes:` di `docker-compose.yml`:

```yaml
volumes:
  - ./config.json:/var/www/html/config.json
  - ./master.json:/var/www/html/master.json
```

Pastikan file di host bisa ditulis user `www-data` (mis. `chmod 666`), karena aplikasi menimpanya saat Anda simpan dari menu Pengaturan/Master.

### Perintah lain

```bash
docker compose logs -f antibiotik   # lihat log
docker compose down                 # hentikan container
docker compose up -d --build        # rebuild setelah perubahan kode
```

## Menjalankan tanpa Docker

Butuh PHP 8.3 dengan `pdo_mysql`, lalu:

```bash
composer install
```

Serve lewat Apache (aplikasi menurunkan `$BASE_URL` dari `SCRIPT_NAME` di `lib/layout.php`). Hanya `vendor/autoload.php` yang dimuat otomatis, dan itu hanya dipakai saat export Excel.
