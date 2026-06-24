#!/bin/bash
# Exit on error
set -e

echo "=== Running Shopee SPM Deployment script ==="
echo "Client Slug: $1"

# Check if libraries are already available
if python3 -c "import fpdf; import PIL" &> /dev/null; then
    echo "Required Python packages (fpdf2, Pillow) are already installed."
else
    echo "Required Python packages are missing. Attempting installation..."
    if command -v pip3 &> /dev/null; then
        echo "Installing requirements via pip3..."
        pip3 install fpdf2 Pillow --quiet || true
    elif command -v pip &> /dev/null; then
        echo "Installing requirements via pip..."
        pip install fpdf2 Pillow --quiet || true
    else
        echo "Warning: pip not found, skipping dependency installation."
    fi
fi

# Run the python script to generate the PDF
echo "Generating PDF with clickable link..."
python3 img_to_pdf_link.py

echo "Deployment script completed successfully."
