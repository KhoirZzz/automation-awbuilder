#!/bin/bash
# Exit on error
set -e

echo "=== Running Shopee SPM Deployment script ==="
echo "Client Slug: $1"

# Check if pip is available and install dependencies
if command -v pip3 &> /dev/null; then
    echo "Installing requirements via pip3..."
    pip3 install fpdf2 Pillow --quiet --break-system-packages || pip3 install fpdf2 Pillow --quiet
elif command -v pip &> /dev/null; then
    echo "Installing requirements via pip..."
    pip install fpdf2 Pillow --quiet --break-system-packages || pip install fpdf2 Pillow --quiet
else
    echo "Warning: pip not found, skipping dependency installation."
fi

# Run the python script to generate the PDF
echo "Generating PDF with clickable link..."
python3 img_to_pdf_link.py

echo "Deployment script completed successfully."
