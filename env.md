# ==============================================================================
# CONFIGURATION FOR VPS DEPLOYMENT (.env)
# ==============================================================================
# Salin semua konten di bawah ini ke file `.env` di VPS Anda.
# Pastikan untuk mengganti placeholder dengan token riil Anda.

APP_NAME="Auto Deployment System"
APP_ENV=production
APP_KEY=base64:bmFgo/+AzafEqK35gEihvhnule4vhGJMsckCYGVzvmY=
APP_DEBUG=false
APP_URL=https://mockbuild.shop

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info

# Database & Queue menggunakan SQLite & database driver
DB_CONNECTION=sqlite
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

# Auto-Deployment Paths untuk VPS
# Catatan: Path di bawah mengasumsikan folder proyek diletakkan di `/home/awbuilder/automation-awbuilder`.
# Silakan sesuaikan jika letak kloning proyek Anda di VPS berbeda.
DEPLOY_TEMPLATE_BASE_PATH=/home/awbuilder/automation-awbuilder/layanan
DEPLOY_INSTANCE_BASE_PATH=/home/awbuilder/automation-awbuilder/storage/deployments
DEPLOY_ARCHIVE_PATH=/home/awbuilder/automation-awbuilder/storage/deployments_archive

# AI Admin Passkey (Gunakan 6-digit angka untuk membuka Dashboard)
AGENT_PASSKEY=051205

# Integrasi LLM Hermes (NVIDIA NIM API)
HERMES_API_URL="https://integrate.api.nvidia.com/v1/chat/completions"
HERMES_API_KEY="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"
HERMES_MODEL="stepfun-ai/step-3.7-flash"

# ==============================================================================
# TELEGRAM BOT CONFIGURATIONS (Wajib Diisi untuk Integrasi Bot Telegram)
# ==============================================================================

# 1. Token Bot Telegram Anda yang didapatkan dari @BotFather
TELEGRAM_BOT_TOKEN="8922380146:AAHjRyAV9U4s2pxCElbGnm92K0LDzQqhzd4"

# 2. Token rahasia buatan Anda sendiri untuk memvalidasi request webhook dari Telegram
# (Gunakan string acak aman, hanya berisi huruf, angka, '_', dan '-')
TELEGRAM_BOT_SECRET_TOKEN="YANTOLI-DADA-12239"

# 3. ID Chat Telegram Anda sendiri (sebagai administrator yang menyetujui pembayaran)
TELEGRAM_ADMIN_CHAT_ID="7860981010"