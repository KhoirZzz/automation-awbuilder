# Checkpoint: Implementasi AI-Driven Auto-Deployment System

Berikut adalah ringkasan progres dan status implementasi sistem auto-deployment berbasis Laravel 11 yang telah dikerjakan di folder `/home/parri/Documents/auto-deployment-system`.

---

## 📅 Status Progres: Selesai & Terverifikasi (100%)

Seluruh struktur file, migrasi database, business logic, integrasi antrean (queue), scheduler, dan sistem keamanan webhook telah berhasil diimplementasikan dan diuji menggunakan PHPUnit dengan **17 unit/feature tests yang lulus 100%**.

---

## 🛠️ Rangkuman Komponen yang Telah Dibuat

### 1. Inisialisasi & Konfigurasi Dasar
* **Laravel 11 Project:** Diinisialisasi di `/home/parri/Documents/auto-deployment-system`.
* **Config `config/deploy.php`:** Mengatur path template, target instance, base arsip, serta blacklist kata terlarang (*reserved subdomains*).
* **Logging `config/logging.php`:** Ditambahkan custom channel `deploy-audit` untuk menulis audit log harian ke `storage/logs/deploy-audit.log`.
* **Environment `.env`:** Mengonfigurasi `QUEUE_CONNECTION=database` dan variabel untuk Telegram/WhatsApp keys.

### 2. Skema Database & Migrasi
* **`service_templates` Table:** Menyimpan blueprint layanan (`key`, `name`, `template_path`, `is_active`).
* **`deployments` Table:** Menyimpan metadata instansi client yang dideploy (`client_slug`, `instance_path`, status, dsb).
* **Partial Unique Index:** Ditambahkan via raw SQL pada migration deployments untuk memastikan slug unik khusus untuk status `active` dan `pending` saja (memungkinkan penggunaan kembali slug lama yang sudah mati/expired).

### 3. Keamanan Webhook & Router (Step 2)
* **Verify Middlewares:**
  * `VerifyTelegramSignature.php`: Memvalidasi header token rahasia Telegram.
  * `VerifyWhatsappSignature.php`: Memvalidasi signature HMAC-SHA256 dari Facebook.
* **Webhook Controller (`LeadWebhookController.php`):**
  * Memisahkan flow input Telegram & WhatsApp.
  * Melakukan normalisasi data ke bentuk umum `{message, source, lead_reference}`.
  * Melakukan pencegahan webhook ganda (deduplikasi) menggunakan cache lock selama 5 menit.
  * Mengirim respons JSON cepat (`{"status": "received"}`) dan melempar job ke antrean database.

### 4. Integrasi LLM Hermes (Step 3)
* **`HermesService.php`:**
  * Membangun system prompt secara dinamis berdasarkan template aktif di DB dan enum durasi.
  * Mengirim request ke API Hermes (Ollama/OpenRouter) dengan format paksaan JSON (`response_format: json_object`).
  * Menangani error HTTP transient (retry otomatis hingga 2 kali).

### 5. Validasi & Sanitasi (Step 4)
* **`LeadAnalysisValidator.php`:**
  * Memastikan output Hermes lolos *strict whitelist check* terhadap template aktif dan enum durasi (`ServiceDuration`).
  * Mensanitasi slug client (lowercase, trim, regex DNS label RFC 1035 max 63 karakter).
  * Menolak slug yang mengandung path traversal (`..`, `/`), spasi, dan reserved words.
  * Melempar `InvalidLeadAnalysisException` untuk mengakhiri queue job tanpa retry jika data tidak valid.

### 6. Mekanisme Kloning & Eksekusi Deployment (Step 5)
* **`DeployServiceAction.php`:**
  * Membuat rekam jejak awal berstatus `pending`.
  * Menyalin direktori template ke tujuan menggunakan filesystem PHP murni (bukan subprocess command).
  * Menulis file `.env` instance dengan menyisipkan nilai subdomain, started_at, dan expiry.
  * Menjalankan `deploy.sh` menggunakan form array `Process::run(['bash', 'deploy.sh', $clientSlug])` dengan timeout 60 detik.
  * **Transactional Rollback:** Menghapus folder instansi yang telanjur disalin dan memperbarui status DB menjadi `failed` jika script deployment gagal dieksekusi.

### 7. Lifecycle Audit & Teardown Scheduler (Step 6)
* **Artisan Command `deploy:audit-expired`:**
  * Mendeteksi instansi aktif yang masanya telah habis (`expires_at < now()`).
  * Mengeksekusi script `teardown.sh` di folder instansi bersangkutan.
  * Memindahkan folder instance ke direktori arsip dengan format penamaan ber-timestamp (bukan langsung didelete).
  * Memperbarui status database instansi tersebut menjadi `expired`.
* **Schedule Register:** Didaftarkan pada `routes/console.php` untuk berjalan secara harian.

### 8. React Admin Dashboard & Sandbox UI (Nihilisme B&W Style - Mobile First)
* **React SPA Mount:** Diintegrasikan menggunakan Vite dan terpasang di `resources/js/app.jsx` dengan perutean wildcard di `routes/web.php`.
* **Mobile-First Navigation:** Pada perangkat mobile/tablet, navigasi menggunakan bar menu bawah yang fixed (`fixed bottom-0`), sedangkan sidebar navigasi desktop hanya muncul pada layar lebar (`lg:flex`).
* **Responsive Layouts:**
  * Daftar deployment di **Dashboard** diubah menjadi tumpukan kartu informasi ringkas pada perangkat mobile (`md:hidden`) dan beralih ke tabel data penuh pada layar desktop (`md:block`).
  * Grid data template dan simulasi sandbox secara cerdas diatur menumpuk satu kolom pada mobile dan berjajar pada layar lebar.
* **Nihilist design theme:** Desain pure monochrome minimalis (solid black/white, sharp borders, zero gradients/fancy colors, clean borders `border-zinc-800`, font monospaced untuk kode/terminal).
* **Heroicons Integration:** Seluruh ikon navigasi dan status diubah menggunakan inline SVG dari Heroicons.
* **Reusable Components (`resources/js/components/`):**
  * `Card.jsx` & `StatCard`: Kontainer visual hitam solid (`bg-zinc-950`) dengan aksen minimalis.
  * `Button.jsx`: Tombol interaktif monokrom tinggi-kontras (bg-white untuk primary, border-zinc-800 untuk secondary).
  * `Badge.jsx`: Label monospaced status berskala kecil dengan dot minimalis.
  * `Modal.jsx`: Pop-up input monokrom.
* **Views (`resources/js/views/`):**
  * `Dashboard.jsx`: Monitoring statistik, daftar deployment, tombol manual teardown, extend expiry, dan retry build.
  * `Templates.jsx`: Mengelola blueprint, mengaktifkan/menonaktifkan template LLM, dan menambah template baru.
  * `Logs.jsx`: Terminal log log-audit interaktif dengan auto-scrolling dan periodic auto-refresh.
  * `Sandbox.jsx`: Tester pengiriman pesan simulasi chat WA/Telegram untuk menguji LLM parser & deployment secara langsung di browser.
* **Dashboard API Controller (`DashboardController.php`):** Menyediakan endpoints terstruktur (`/api/dashboard/...`) untuk data statistik, daftar deployment, manipulasi templates, log viewer, dan sandbox simulation.

### 9. Mock Templates & Sandbox Environment
* **Mock Blueprints:** Dibuat folder contoh `storage/templates/shopee-bot` dan `storage/templates/wa-responder` lengkap dengan script dummy `deploy.sh`, `teardown.sh`, dan `.env.example` untuk demo deployment yang lancar.
* **Local Paths Override (.env):** Memetakan path template, instance, dan arsip ke folder internal `/storage/...` untuk menghindari konflik perizinan root filesystem `/var/www/`.

---

## 🧪 Rincian Hasil Pengujian (17 Tests Passed)

* **`LeadAnalysisValidatorTest` (6 Skenario):** Memvalidasi kelolosan data bersih, penolakan slug tidak valid, reserved words, DNS format, template non-aktif, dan slug aktif ganda.
* **`LeadWebhookTest` (6 Skenario):** Memvalidasi penolakan signature kosong, keberhasilan signature valid, verifikasi challenge WhatsApp (GET), normalisasi payload, dan keefektifan dedup cache.
* **`DeployServiceActionTest` (3 Skenario):** Menguji keberhasilan replikasi file sistem, injeksi variabel `.env`, eksekusi process bash, dan fungsionalitas rollback direktori saat script error.
* **`AuditExpiredDeploymentsTest` (2 Skenario):** Memverifikasi proses audit, eksekusi teardown, relokasi folder arsip, perubahan status database, dan pengabaian deployment aktif non-expired.

---

## 🛡️ Checklist Kepatuhan Non-Negotiable Rules

* [x] Tidak ada string concatenation ke `Process::run()`
* [x] Tidak ada regex parsing terhadap teks mentah LLM
* [x] Tidak ada path/slug mentah menyentuh filesystem sebelum disanitasi penuh oleh validator
* [x] Bebas dari manipulasi file konfigurasi server host (`/etc/nginx/*`, `systemctl`, `certbot`)
* [x] Field `source` tidak diambil dari request body untuk memilih signature middleware
* [x] Semua command Process menggunakan `->timeout()`
* [x] Log audit tidak membocorkan file `.env` berisi data kredensial rahasia secara transparan
* [x] Reusable React components terstruktur rapi dan dipasang di `resources/js/`
