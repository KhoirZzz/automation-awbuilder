<?php
// Scan for any PDF file in the instance directory
$pdfs = glob("*.pdf");
if (!empty($pdfs)) {
    $pdf = $pdfs[0];
    // Redirect the user directly to the PDF
    header("Location: /$pdf");
    exit;
}

header("Content-Type: text/plain");
echo "PDF file is not generated yet or could not be found.";
