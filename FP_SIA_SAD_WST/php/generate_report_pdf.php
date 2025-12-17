<?php
// Server-side PDF generator endpoint.
// Expects JSON POST with structure:
// { charts: [{name: 'attempts', data: 'data:image/png;base64,...'}, ...], stats: {...}, options: {...} }
// Requires php/lib/fpdf.php (FPDF library) present.

// Log errors to file instead of displaying
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/pdf_errors.log');

// Create logs directory if needed
if (!is_dir(__DIR__ . '/logs')) {
    @mkdir(__DIR__ . '/logs', 0755, true);
}

// Helper function to send JSON error
function sendJsonError($message, $code = 400) {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode(['error' => $message]);
    error_log("PDF Generator Error: $message");
    exit;
}

// Read raw input
$input = file_get_contents('php://input');
if (!$input) {
    sendJsonError('No input received', 400);
}

$data = json_decode($input, true);
if (!$data) {
    sendJsonError('Invalid JSON: ' . json_last_error_msg(), 400);
}

$charts = $data['charts'] ?? [];
$stats = $data['stats'] ?? [];
$options = $data['options'] ?? [];

// Detect report type based on stats keys
$isTeacherReport = isset($stats['total_students']) || isset($stats['average_score']);

// Basic validation to fail early with useful message
if (!is_array($charts)) {
    sendJsonError('Invalid charts data: expected array', 400);
}

// Temp files
$tempFiles = [];
try {
    // Decode images and save to temp files
    foreach ($charts as $idx => $chart) {
        if (empty($chart['data'])) continue;
        $matches = [];
        if (preg_match('/^data:(image\/png|image\/jpeg);base64,(.*)$/', $chart['data'], $matches)) {
            $ext = ($matches[1] === 'image/png') ? 'png' : 'jpg';
            $base64 = $matches[2];
            $imgData = base64_decode($base64);
            $tmp = tempnam(sys_get_temp_dir(), 'lh_chart_') . ".{$ext}";
            file_put_contents($tmp, $imgData);
            $tempFiles[] = $tmp;
            $charts[$idx]['tmpfile'] = $tmp;
        }
    }

    // Require FPDF
    $fpdfPath = __DIR__ . '/lib/fpdf.php';
    if (!file_exists($fpdfPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'FPDF library not found. Please add php/lib/fpdf.php from http://www.fpdf.org/']);
        exit;
    }
    require_once $fpdfPath;

    // Create PDF
    $pdf = new FPDF('P', 'pt', 'A4');
    $pdf->SetAutoPageBreak(true, 40);

    // Page setup constants
    $pageW = $pdf->GetPageWidth();
    $pageH = $pdf->GetPageHeight();
    $margin = 40;

    // Add first page and header
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(26,68,128);
    
    // Dynamic title based on report type
    $reportTitle = $isTeacherReport ? 'Library Hub - Teacher Report' : 'Library Hub - Librarian Report';
    $pdf->Cell(0, 20, $reportTitle, 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0);
    $pdf->Cell(0, 14, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1);
    $pdf->Ln(6);

    // Stats - handle both Teacher and Librarian formats
    if (!empty($options['includeStats'])) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 18, 'Summary Statistics', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        
        if ($isTeacherReport) {
            // Teacher stats format
            $statsLines = [
                sprintf("Total Students: %d", $stats['total_students'] ?? 0),
                sprintf("Average Quiz Score: %.1f%%", $stats['average_score'] ?? 0),
                sprintf("Total Books Read: %d", $stats['total_books_read'] ?? 0),
                sprintf("Avg Books per Student: %.1f", $stats['avg_books_per_student'] ?? 0),
                sprintf("Pens Given: %d  |  Notebooks Given: %d", 
                    $stats['pens_given'] ?? 0,
                    $stats['notebooks_given'] ?? 0
                )
            ];
            foreach ($statsLines as $line) {
                $pdf->Cell(0, 14, $line, 0, 1);
            }
        } else {
            // Librarian stats format (original)
            $statsLine = sprintf("Total Books: %d  |  Available: %d  |  Unavailable: %d  |  Attempts: %d",
                $stats['totalBooks'] ?? 0,
                $stats['availableBooks'] ?? 0,
                $stats['unavailableBooks'] ?? 0,
                $stats['totalAttempts'] ?? 0
            );
            $pdf->MultiCell(0, 14, $statsLine, 0, 'L');
        }
        $pdf->Ln(6);
    }

    // Charts: each chart to its own section
    foreach ($charts as $chart) {
        if (empty($chart['tmpfile']) || !file_exists($chart['tmpfile'])) continue;
        $pdf->SetFont('Arial', 'B', 12);
        $label = $chart['name'] ?? 'Chart';
        $pdf->Cell(0, 18, $label, 0, 1);

        // Fit image to width
        $usableW = $pageW - $margin * 2;
        list($w, $h) = getimagesize($chart['tmpfile']);
        if ($w > 0) {
            $scale = $usableW / $w;
            $drawW = $usableW;
            $drawH = $h * $scale;
            // New page if doesn't fit
            if ($pdf->GetY() + $drawH > $pageH - $margin) {
                $pdf->AddPage();
            }
            $x = $margin;
            $y = $pdf->GetY();
            $pdf->Image($chart['tmpfile'], $x, $y, $drawW, $drawH);
            $pdf->Ln($drawH + 10);
        }
    }

    // Additional sections could be added here (inventory, performance insights)

    // Output PDF
    $pdfContent = $pdf->Output('S');

    // Send PDF as response
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="LibraryHub_Report_' . date('Y-m-d') . '.pdf"');
    echo $pdfContent;

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} finally {
    // cleanup temp files
    foreach ($tempFiles as $f) {
        if (file_exists($f)) @unlink($f);
    }
}
