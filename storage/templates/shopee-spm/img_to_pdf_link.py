import sys
import os
from PIL import Image

try:
    from fpdf import FPDF
except ImportError:
    print("Error: 'fpdf2' library not found. Install it with: pip install fpdf2")
    sys.exit(1)

# 1. Load environment variables manually to avoid extra dependencies (python-dotenv)
env_vars = {}
if os.path.exists('.env'):
    with open('.env', 'r') as f:
        for line in f:
            line = line.strip()
            if '=' in line and not line.startswith('#'):
                parts = line.split('=', 1)
                if len(parts) == 2:
                    env_vars[parts[0].strip()] = parts[1].strip().strip('"').strip("'")

# 2. Extract values from env or fall back to defaults
client_slug = env_vars.get('CLIENT_SLUG', '')

image_path = env_vars.get('IMAGE_PATH', 'as.jpeg')
output_pdf = env_vars.get('OUTPUT_PDF', 'shopee-16.pdf')
target_url = env_vars.get('TARGET_URL', 'https://dd-apps-io.infinityfree.io/SP-20/')

# Automatically append client_slug to target_url if it ends with a slash or equal sign,
# or if it's pointing to the default infinityfree domain, to follow the slug dynamically.
if client_slug:
    if target_url.endswith('/') or target_url.endswith('='):
        target_url = target_url + client_slug
    elif 'infinityfree.io' in target_url:
        if not target_url.endswith(client_slug):
            target_url = target_url.rstrip('/') + '/' + client_slug

if not output_pdf.endswith('.pdf'):
    output_pdf += '.pdf'


def generate_pdf_with_link(image, pdf_out, url):
    """
    Mengonversi gambar ke PDF dengan ukuran yang sama dan menambahkan link klik.
    """
    if not os.path.exists(image):
        print(f"Error: File gambar '{image}' tidak ditemukan.")
        print(f"Pastikan file gambar berada di folder yang sama dengan script ini.")
        return

    try:
        # Buka gambar untuk mendapatkan ukurannya
        with Image.open(image) as img:
            width, height = img.size
            print(f"Informasi Gambar: {width}x{height} pixels")

        # Inisialisasi PDF dengan unit 'pt' (points)
        # 1 pixel akan dianggap sebagai 1 point agar ukurannya pas
        pdf = FPDF(unit="pt", format=(width, height))
        pdf.add_page()

        # Tambahkan gambar ke PDF
        # Kita letakkan di koordinat (0,0) dengan ukuran penuh
        pdf.image(image, x=0, y=0, w=width, h=height)

        # Tambahkan link (hyperlink) di seluruh area gambar
        pdf.link(x=0, y=0, w=width, h=height, link=url)

        # Simpan PDF
        pdf.output(pdf_out)
        print(f"Berhasil! PDF dibuat: {pdf_out}")
        print(f"Target Link: {url}")

    except Exception as e:
        print(f"Terjadi kesalahan: {e}")


if __name__ == "__main__":
    # If args are supplied via sys.argv, use them (legacy compatibility)
    if len(sys.argv) >= 4:
        img_input = sys.argv[1]
        pdf_output = sys.argv[2]
        dest_url = sys.argv[3]
        generate_pdf_with_link(img_input, pdf_output, dest_url)
    else:
        # Otherwise run with configurations read from .env / defaults
        print("Menjalankan dengan konfigurasi terdeteksi...")
        generate_pdf_with_link(image_path, output_pdf, target_url)
