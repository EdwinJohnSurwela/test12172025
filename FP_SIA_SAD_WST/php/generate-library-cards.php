<?php
/**
 * Library Card Generator - DepEd Themed
 * For Admins to generate student library cards with QR codes
 * Features front and back card preview matching official design
 */

session_start();
require_once 'config.php';

// Check if user is admin only
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$user_type = $_SESSION['user_type'];
$user_name = $_SESSION['full_name'] ?? 'Admin';

// Library Card Secret (must match student-qr-login.php)
define('LIBRARY_CARD_SECRET', 'LibraryHub2024SecretKey');

/**
 * Generate library card QR code data
 */
function generateLibraryCardData($student_id, $user_id) {
    $hash = substr(md5($student_id . LIBRARY_CARD_SECRET . $user_id), 0, 12);
    return "LIBCARD:{$student_id}:{$hash}";
}

/**
 * Generate Library Card ID (format: YYYY-XXX)
 */
function generateLibraryCardId($user_id) {
    return date('Y') . '-' . str_pad($user_id, 3, '0', STR_PAD_LEFT);
}

// Get all students with profile pictures
$students = [];
$sql = "SELECT u.user_id, u.student_id, u.full_name, u.grade_level, u.email, u.status, u.created_at,
               pp.file_path as profile_picture
        FROM users u
        LEFT JOIN profile_pictures pp ON u.user_id = pp.user_id
        WHERE u.user_type = 'student' 
        ORDER BY u.grade_level ASC, u.full_name ASC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $row['qr_data'] = generateLibraryCardData($row['student_id'], $row['user_id']);
        $row['library_card_id'] = generateLibraryCardId($row['user_id']);
        $students[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Card Generator - Library Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Error Handler will be loaded at bottom -->
    <!-- QR Code Generator Library: try CDN, fall back to local copy if CDN fails -->
    <script>
        (function() {
            var cdnSrc = 'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js';
            var localSrc = '../js/vendor/qrcode.min.js';

            function loadScript(src, onload, onerror) {
                var s = document.createElement('script');
                s.src = src;
                s.async = true;
                s.onload = onload;
                s.onerror = onerror;
                document.head.appendChild(s);
            }

            loadScript(cdnSrc, function() {
                console.log('QR lib loaded from CDN');
            }, function() {
                console.warn('CDN QR lib failed, attempting local fallback');
                loadScript(localSrc, function() {
                    console.log('QR lib loaded from local fallback');
                }, function() {
                    console.error('Local QR lib failed to load');
                });
            });
        })();
    </script>
    <style>
        :root {
            /* DepEd Color Scheme */
            --deped-blue: #1a4480;
            --deped-blue-dark: #0d2240;
            --deped-red: #c41230;
            --deped-red-dark: #8b0a1e;
            --deped-gold: #ffc107;
            --card-bg: #fff8dc; /* Cream/beige like the sample */
            --main-gradient: linear-gradient(135deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            padding: 20px;
        }

        .page-header {
            background: var(--main-gradient);
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 4px 15px rgba(26, 68, 128, 0.3);
        }

        .page-header h1 {
            margin: 0;
            font-size: 1.6em;
            font-weight: 700;
        }

        .back-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            border: none;
        }

        .btn-generate {
            background: var(--deped-blue);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s;
        }

        .btn-generate:hover {
            background: var(--deped-blue-dark);
            transform: translateY(-1px);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }

        /* Library Card Styles - Matching the provided design */
        .card-preview-container {
            display: flex;
            gap: 40px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .card-side {
            text-align: center;
        }

        .card-side-label {
            font-weight: 700;
            color: var(--deped-blue);
            margin-bottom: 12px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Front Card Design - Matching reference exactly */
        .library-card-front {
            width: 400px;
            height: 250px;
            background: var(--card-bg);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }

        .card-front-header {
            display: flex;
            align-items: center;
            gap: 15px;
            padding-bottom: 12px;
            border-bottom: 4px solid var(--deped-red);
            margin-bottom: 15px;
        }

        .card-front-logo {
            width: 60px;
            height: 60px;
            flex-shrink: 0;
        }

        .card-front-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .card-front-title {
            flex: 1;
        }

        .card-front-title h3 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            color: var(--deped-blue);
        }

        .card-front-title p {
            margin: 4px 0 0;
            font-size: 12px;
            color: #666;
            font-style: italic;
        }

        .card-front-body {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }

        .student-photo-box {
            width: 100px;
            height: 120px;
            background: #fce4ec;
            border: 3px solid #ddd;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .student-photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-photo-placeholder {
            font-size: 50px;
            color: #ccc;
        }

        .student-info-front {
            flex: 1;
            text-align: left;
            padding-top: 5px;
        }

        .student-info-front .info-item {
            margin-bottom: 8px;
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 8px;
        }

        .student-info-front .info-label {
            font-size: 14px;
            color: #333;
            font-weight: 800;
        }

        .student-info-front .info-value {
            font-size: 14px;
            color: #333;
            font-weight: 700;
        }

        /* Back Card Design - Matching reference exactly */
        .library-card-back {
            width: 400px;
            height: 250px;
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .scan-me-label {
            background: #333;
            color: white;
            padding: 8px 30px;
            border-radius: 25px 25px 0 0;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .qr-code-container {
            background: white;
            padding: 15px;
            border: 4px solid #333;
            border-radius: 0 0 16px 16px;
        }

        .qr-code-container canvas {
            display: block;
        }

        .card-back-footer {
            margin-top: 12px;
            text-align: center;
            font-size: 11px;
            color: #666;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .action-btn.print {
            background: var(--deped-blue);
            color: white;
        }

        .action-btn.download {
            background: var(--deped-red);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }

        .modal-header h2 {
            margin: 0;
            color: var(--deped-blue);
            font-size: 1.4em;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: var(--deped-red);
        }

        /* Print Styles */
        @media print {
            body * {
                visibility: hidden;
            }
            #printArea, #printArea * {
                visibility: visible;
            }
            #printArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .library-card-front, .library-card-back {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                page-break-inside: avoid;
            }
            .no-print {
                display: none !important;
            }
        }

        /* DataTables customization */
        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 8px 12px;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--deped-blue);
            outline: none;
        }

        .dataTables_wrapper .dataTables_length select {
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            padding: 6px 10px;
        }

        table.dataTable thead th {
            background: var(--deped-blue);
            color: white;
            font-weight: 600;
            border: none;
        }

        table.dataTable tbody tr:hover {
            background: #f0f7ff !important;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="page-header">
            <div>
                <h1>🪪 Library Card Generator</h1>
                <p style="margin: 5px 0 0; opacity: 0.9; font-size: 14px;">Generate official Library Cards for Students</p>
            </div>
            <a href="admin.php" class="back-btn">← Back to Dashboard</a>
        </div>

        <div class="card">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <h5 style="margin: 0; color: var(--deped-blue);">📋 Registered Students</h5>
            </div>

            <div class="table-responsive">
                <table class="table table-striped" id="studentTable">
                    <thead>
                        <tr>
                            <th>LRN / Student ID</th>
                            <th>Full Name</th>
                            <th>Grade Level</th>
                            <th>Card ID</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($student['student_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td>Grade <?php echo htmlspecialchars($student['grade_level']); ?></td>
                            <td><code><?php echo htmlspecialchars($student['library_card_id']); ?></code></td>
                            <td>
                                <span class="status-badge status-<?php echo $student['status']; ?>">
                                    <?php echo ucfirst($student['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-generate" 
                                        data-student='<?php echo json_encode($student, JSON_HEX_APOS | JSON_HEX_QUOT); ?>'
                                        onclick="generateCard(JSON.parse(this.getAttribute('data-student')))">
                                    🪪 Generate
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Card Generation Modal -->
    <div class="modal-overlay" id="cardModal">
        <div class="modal-box">
            <div class="modal-header no-print">
                <h2>🪪 Library Card Preview</h2>
                <button class="close-modal" onclick="closeCardModal()">&times;</button>
            </div>

            <div id="printArea">
                <div class="card-preview-container">
                    <!-- Front Card -->
                    <div class="card-side">
                        <div class="card-side-label">FRONT</div>
                        <div class="library-card-front" id="cardFront">
                            <div class="card-front-header">
                                <div class="card-front-logo">
                                    <img src="../images/library_hub_logo.png" alt="Library Hub Logo" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>📚</text></svg>'">
                                </div>
                                <div class="card-front-title">
                                    <h3>Library Hub</h3>
                                    <p>Address: Tambo, Lipa City</p>
                                </div>
                            </div>
                            <div class="card-front-body">
                                <div class="student-photo-box" id="studentPhotoBox">
                                    <span class="student-photo-placeholder">👤</span>
                                </div>
                                <div class="student-info-front">
                                    <div class="info-item">
                                        <span class="info-label">Name:</span>
                                        <span class="info-value" id="cardName">Juan Dela Cruz</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Grade Level:</span>
                                        <span class="info-value" id="cardGrade">Grade 5</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Library Card ID:</span>
                                        <span class="info-value" id="cardId">2024-001</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">LRN:</span>
                                        <span class="info-value" id="cardLrn">1410 9578 6584</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Back Card -->
                    <div class="card-side">
                        <div class="card-side-label">BACK</div>
                        <div class="library-card-back" id="cardBack">
                            <div class="scan-me-label">SCAN ME</div>
                            <div class="qr-code-container">
                                <!-- Server-generated QR image will be drawn into the canvas below. -->
                                <canvas id="qrCodeCanvas" width="150" height="150"></canvas>
                                <!-- Hidden img element used to load server PNG then draw to canvas -->
                                <img id="qrImage" alt="QR" style="display:none;width:150px;height:150px;" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-buttons no-print">
                <button class="action-btn print" onclick="printCards()">
                    🖨️ Print Cards
                </button>
                <button class="action-btn download" onclick="downloadCards()">
                    💾 Download as Image
                </button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#studentTable').DataTable({
                pageLength: 10,
                lengthMenu: [[10, 25, 30, -1], [10, 25, 30, "All"]],
                order: [[2, 'asc'], [1, 'asc']],
                language: {
                    search: "🔍 Search:",
                    lengthMenu: "Show _MENU_ students",
                    info: "Showing _START_ to _END_ of _TOTAL_ students",
                    emptyTable: "No students registered yet"
                }
            });
        });

        let currentStudent = null;

        function generateCard(student) {
            currentStudent = student;
            
            // Update front card info
            document.getElementById('cardName').textContent = student.full_name;
            document.getElementById('cardGrade').textContent = 'Grade ' + student.grade_level;
            document.getElementById('cardId').textContent = student.library_card_id;
            
            // Format LRN with spaces for readability (every 4 digits)
            const lrn = student.student_id.replace(/(.{4})/g, '$1 ').trim();
            document.getElementById('cardLrn').textContent = lrn;

            // Update photo
            const photoBox = document.getElementById('studentPhotoBox');
            if (student.profile_picture) {
                photoBox.innerHTML = `<img src="../${student.profile_picture}" alt="Student Photo">`;
            } else {
                photoBox.innerHTML = '<span class="student-photo-placeholder">👤</span>';
            }

                // Show modal first (so UI appears while server generates QR image)
                const modalEl = document.getElementById('cardModal');
                modalEl.classList.add('active');
                document.body.style.overflow = 'hidden';

                // Prepare canvas and image elements
                const canvas = document.getElementById('qrCodeCanvas');
                const imgEl = document.getElementById('qrImage');
                const ctx = canvas.getContext && canvas.getContext('2d');
                if (ctx) {
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                }

                // Helper: fallback to client-side QR (or draw placeholder)
                function fallbackToClientQR() {
                    try {
                        if (typeof QRCode !== 'undefined' && QRCode.toCanvas) {
                            QRCode.toCanvas(canvas, student.qr_data, {
                                width: 150,
                                margin: 1,
                                color: { dark: '#000000', light: '#ffffff' }
                            }, function(error) {
                                if (error) {
                                    console.error('Client QR error:', error);
                                    if (ctx) {
                                        ctx.fillStyle = '#000';
                                        ctx.font = '12px sans-serif';
                                        ctx.fillText('QR unavailable', 10, 20);
                                    }
                                }
                            });
                            return;
                        }
                    } catch (e) {
                        console.error('Fallback client QR exception:', e);
                    }
                    // Final fallback: draw text
                    if (ctx) {
                        ctx.fillStyle = '#000';
                        ctx.font = '12px sans-serif';
                        ctx.fillText('QR unavailable', 10, 20);
                    }
                }

                // Request server-side PNG via AJAX (generate-qr.php)
                if (window.fetch) {
                    if (typeof Swal !== 'undefined') {
                        try {
                            Swal.fire({
                                title: 'Generating QR...',
                                text: 'Please wait',
                                allowOutsideClick: false,
                                didOpen: () => { Swal.showLoading(); }
                            });
                        } catch (s) { /* ignore */ }
                    }

                    fetch('generate-qr.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ qr_data: student.qr_data, student_id: student.student_id })
                    }).then(r => r.json()).then(data => {
                        if (typeof Swal !== 'undefined') try { Swal.close(); } catch(e){}
                        if (data && data.success && data.url) {
                            imgEl.onload = function() {
                                try {
                                    ctx.clearRect(0,0,canvas.width,canvas.height);
                                    ctx.drawImage(imgEl, 0, 0, canvas.width, canvas.height);
                                } catch (drawErr) {
                                    console.error('Draw image error:', drawErr);
                                    fallbackToClientQR();
                                }
                            };
                            imgEl.onerror = function() {
                                console.warn('Failed to load server QR image');
                                fallbackToClientQR();
                            };
                            imgEl.src = data.url + '?t=' + Date.now();
                        } else {
                            console.warn('Server QR generation failed', data);
                            fallbackToClientQR();
                        }
                    }).catch(err => {
                        if (typeof Swal !== 'undefined') try { Swal.close(); } catch(e){}
                        console.error('Server QR request error:', err);
                        fallbackToClientQR();
                    });
                } else {
                    // No fetch available — use client-side fallback
                    fallbackToClientQR();
                }
        }

        function closeCardModal() {
            document.getElementById('cardModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function printCards() {
            window.print();
        }

        async function downloadCards() {
            const printArea = document.getElementById('printArea');
            
            try {
                Swal.fire({
                    title: 'Generating Image...',
                    text: 'Please wait',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const canvas = await html2canvas(printArea, {
                    scale: 2,
                    backgroundColor: '#ffffff',
                    useCORS: true
                });
                
                const link = document.createElement('a');
                link.download = `library-card-${currentStudent.student_id}.png`;
                link.href = canvas.toDataURL('image/png');
                link.click();
                
                Swal.fire({
                    icon: 'success',
                    title: 'Downloaded!',
                    text: 'Library card saved as image',
                    timer: 2000,
                    showConfirmButton: false
                });
            } catch (err) {
                console.error('Download error:', err);
                Swal.fire('Error', 'Failed to download card: ' + err.message, 'error');
            }
        }

        // Close modal on outside click
        document.getElementById('cardModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCardModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCardModal();
            }
        });
    </script>
    <!-- Error Handler -->
    <script src="../js/error-handler.js"></script>
</body>
</html>
