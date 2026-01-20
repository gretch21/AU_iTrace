<?php
session_start();
require_once '../config.php';

// 1. Basic Session Check
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    // Redirect for missing session
    header("Location: ../au_itrace_portal.php?tab=login&error=noaccess");
    exit;
}

if ($_SESSION['usertype'] !== 'ADMINISTRATOR') {
    die("Usertype is not ADMINISTRATOR.");
}

$username = $_SESSION['username'];

// 2. Database Validation Check
if (!$link) {
    die("Database connection failed.");
}

$sql = "SELECT username, usertype, status FROM tblsystemusers WHERE username = ?";
$stmt = $link->prepare($sql);

if (!$stmt) {
    die("Failed to prepare statement: " . $link->error);
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// --- CRITICAL FIX START ---

// Fetch the user data first
$user = $result->fetch_assoc();

if (!$user || $result->num_rows !== 1) {
    // If num_rows is 0 or fetch_assoc fails, the user is invalid/inactive.
    // If you log in with 'admin' (short username), this will fail, proving the login fix is needed.
    die("User not found or inactive in DB. (Session Username: " . htmlspecialchars($username) . ")");
}

// Now check usertype and status explicitly using the fetched $user variable
if (strtoupper($user['usertype']) !== 'ADMINISTRATOR') {
    die("User is not administrator. Usertype found: " . htmlspecialchars($user['usertype']));
}

if (strtoupper($user['status']) !== 'ACTIVE') {
    die("User is not active. Status found: " . htmlspecialchars($user['status']));
}

// --- CRITICAL FIX END ---

$stmt->close();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Composer autoload
require_once __DIR__ . '/../vendor/autoload.php';


// ensure only admin can access
if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] !== 'ADMINISTRATOR') {
    die("Access denied");
}

// Helper function: time difference in days
function days_diff($datetime_str) {
    if (!$datetime_str) {
        return PHP_INT_MAX;
    }
    try {
        $dt = new DateTime($datetime_str);
        $now = new DateTime();
        $interval = $dt->diff($now);
        return (int)$interval->format('%a');
    } catch (Exception $e) {
        return PHP_INT_MAX;
    }
}

// ---------------- CONFIG (set these) ----------------
$smtpFromEmail = 'bungagretchennnn36@gmail.com';
$smtpFromName  = 'AU iTrace Admin';
$smtpUsername  = 'bungagretchennnn36@gmail.com';
$smtpAppPass   = 'ocdr zpud upol jxkh'; // Gmail App Password
$resetBaseURL  = 'http://localhost:8012/au-itrace/password-manager.php';
// ---------------------------------------------------

// Handle Approve / Reject / Reactivate Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['application_action'])) {
        $action = $_POST['application_action']; // "approve" or "reject"
        $regID = $_POST['regID'];

        if ($action === 'approve') { 
            $stmt = $link->prepare("SELECT regID, studentID, fullname, email, password 
                                     FROM tblregistration 
                                     WHERE regID = ? AND status = 'Pending'");
            $stmt->bind_param("i", $regID);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                $regID_new     = $row['regID'];
                $studentID_new = $row['studentID'];
                $fullname_new  = $row['fullname'];
                $email_new     = $row['email'];
                $password_hash = $row['password'];
                $username_new  = 'student' . $studentID_new;
                $dateNow       = date("Y-m-d H:i:s");
                $adminUser     = $_SESSION['username'] ?? 'System';

                // Generate token
                $token = bin2hex(random_bytes(32));
                $expiration = date("Y-m-d H:i:s", strtotime('+1 hour'));

                // Save token first
                $stmtUp = $link->prepare("UPDATE tblregistration 
                                          SET resettoken = ?, tokenexpiration = ? 
                                          WHERE regID = ?");
                $stmtUp->bind_param("ssi", $token, $expiration, $regID_new);
                $stmtUp->execute();
                $stmtUp->close();

                $reset_link = $resetBaseURL . "?token=" . urlencode($token);

                // Prepare email
$subject = "AU iTrace: Account Approved - Set Your Password";
$htmlBody = "
    <p>Hello " . htmlspecialchars($fullname_new) . ",</p>
    <p>Your student account has been approved.</p>
    <p><strong>Username:</strong> {$username_new}</p>
    <p>Click below to set your password (valid for 1 hour):</p>
    <p><a href='{$reset_link}'>Set Your Password</a></p>
    <p>If the button doesn’t work, paste this link:<br>{$reset_link}</p>
";
$plainBody = "Hello {$fullname_new},\n\nYour account has been approved.\nUsername: {$username_new}\nSet your password: {$reset_link}\n\nThis link expires in 1 hour.";

// Send with PHPMailer
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUsername;
    $mail->Password   = $smtpAppPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->CharSet = 'UTF-8'; // ✅ Add this line

    $mail->setFrom($smtpFromEmail, $smtpFromName);
    $mail->addAddress($email_new, $fullname_new);
    $mail->addReplyTo($smtpFromEmail, $smtpFromName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = $plainBody;

                    if ($mail->send()) {
    // Only after email success → insert into system tables
    $stmt2 = $link->prepare("INSERT INTO tblsystemusers 
                             (studentID, username, password, usertype, status, createdby, datecreated) 
                             VALUES (?, ?, ?, 'STUDENT', 'Active', ?, ?)");
    $stmt2->bind_param("sssss", $studentID_new, $username_new, $password_hash, $adminUser, $dateNow);
    $stmt2->execute();
    $stmt2->close();

    $stmt3 = $link->prepare("INSERT INTO tblactivestudents 
                             (studentID, name, email, password, datecreated, dateapproved, lastlogin, recentactivity) 
                             VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)");
    $stmt3->bind_param("isssss", $studentID_new, $fullname_new, $email_new, $password_hash, $dateNow, $dateNow);
    $stmt3->execute();
    $stmt3->close();

    $stmt4 = $link->prepare("UPDATE tblregistration SET status = 'Approved' WHERE regID = ?");
    $stmt4->bind_param("i", $regID_new);
    $stmt4->execute();
    $stmt4->close();
    
    // START: Log to tbladminlogs for Approval
    $logMessage = "APPROVED student registration for: " . $fullname_new . " (ID: " . $studentID_new . ")";
    $logPage = "User Accounts";
    $nullFoundID = NULL; 
    $stmt_log = $link->prepare("
        INSERT INTO tbladminlogs (username, action, page, foundID, date_time)
        VALUES (?, ?, ?, ?, NOW())
    ");
    if ($stmt_log) {
        // Use 's' for foundID as it is varchar(20) and can be NULL.
        $stmt_log->bind_param("ssss", $adminUser, $logMessage, $logPage, $nullFoundID);
        $stmt_log->execute();
        $stmt_log->close();
    }
    // END: Log to tbladminlogs
    

    echo "<p style='color:green;'>✅ Email sent and account approved for {$fullname_new} ({$email_new})</p>";
}
 else {
                        echo "<p style='color:red;'>❌ Email failed to send. Account not approved.</p>";
                        exit;
                    }
                } catch (Exception $e) {
                    echo "<p style='color:red;'>❌ Email could not be sent. Mailer Error: {$mail->ErrorInfo}</p>";
                    exit;
                }
            }
            $stmt->close();
        } elseif ($action === 'reject') {
            
            // Fetch student info for logging before update
            $stmt_fetch = $link->prepare("SELECT fullname, studentID FROM tblregistration WHERE regID = ?");
            $stmt_fetch->bind_param("i", $regID);
            $stmt_fetch->execute();
            $res_fetch = $stmt_fetch->get_result();
            $row_fetch = $res_fetch->fetch_assoc();
            $stmt_fetch->close();
            
            $stmt5 = $link->prepare("UPDATE tblregistration SET status = 'Rejected' WHERE regID = ?");
            $stmt5->bind_param("i", $regID);
            $stmt5->execute();
            $stmt5->close();
            
            // START: Log to tbladminlogs for Rejection
            if ($row_fetch) {
                $logMessage = "REJECTED student registration for: " . $row_fetch['fullname'] . " (ID: " . $row_fetch['studentID'] . ")";
                $logPage = "User Accounts";
                $adminUser = $_SESSION['username'] ?? 'System';
                $nullFoundID = NULL; 
                $stmt_log = $link->prepare("
                    INSERT INTO tbladminlogs (username, action, page, foundID, date_time)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                if ($stmt_log) {
                    // Use 's' for foundID as it is varchar(20) and can be NULL.
                    $stmt_log->bind_param("ssss", $adminUser, $logMessage, $logPage, $nullFoundID);
                    $stmt_log->execute();
                    $stmt_log->close();
                }
            }
            // END: Log to tbladminlogs
            
        }
    } elseif (isset($_POST['reactivate_student_id'])) {
        $studentID_reactivate = $_POST['reactivate_student_id'];
        $username_reactivate = 'student' . $studentID_reactivate;
        $adminUser = $_SESSION['username'] ?? 'System';

        $stmt_reactivate1 = $link->prepare("UPDATE tblsystemusers SET status = 'Active' WHERE username = ?");
        $stmt_reactivate1->bind_param("s", $username_reactivate);
        $stmt_reactivate1->execute();
        $stmt_reactivate1->close();

        $stmt_reactivate2 = $link->prepare("UPDATE tblactivestudents SET status = 'Active' WHERE studentID = ?");
        $stmt_reactivate2->bind_param("i", $studentID_reactivate);
        $stmt_reactivate2->execute();
        $stmt_reactivate2->close();
        
        // START: Log to tbladminlogs for Reactivation
        $logMessage = "REACTIVATED student account for ID: " . $studentID_reactivate;
        $logPage = "User Accounts";
        $nullFoundID = NULL; 
        $stmt_log = $link->prepare("
            INSERT INTO tbladminlogs (username, action, page, foundID, date_time)
            VALUES (?, ?, ?, ?, NOW())
        ");
        if ($stmt_log) {
            $stmt_log->bind_param("ssss", $adminUser, $logMessage, $logPage, $nullFoundID);
            $stmt_log->execute();
            $stmt_log->close();
        }
        // END: Log to tbladminlogs
    }

    header("Location: user-accounts.php?search=&status=");
    exit;
}

// Filters from GET
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Function to fetch and filter data for a table
function fetchData($link, $table, $search, $statusFilter) {
    $sql = "SELECT * FROM {$table} WHERE 1=1";
    $types = '';
    $params = [];

    // 1. Explicitly filter for Pending/Rejected/Approved based on the table's purpose
    if ($table === 'tblregistration') {
        if ($statusFilter === '') {
            // CORRECTED LOGIC: Include Approved, as requested.
            $sql .= " AND status IN ('Pending', 'Rejected', 'Approved')"; 
        } elseif ($statusFilter === 'Active') {
            // If filter is 'Active', this table should show nothing
            $sql .= " AND 1=0"; // Force zero results
        } elseif ($statusFilter === 'Pending' || $statusFilter === 'Rejected' || $statusFilter === 'Approved') {
             // Explicitly requested Pending, Rejected, or Approved
            $sql .= " AND status = ?";
            $types .= "s";
            $params[] = & $statusFilter;
        }
    } 
    
    // 2. Add search condition
    if ($search !== '') {
        $sql .= " AND (";
        if ($table === 'tblregistration') {
            $sql .= "fullname LIKE ? OR studentID LIKE ?";
        } else { // tblactivestudents
            $sql .= "name LIKE ? OR studentID LIKE ?";
        }
        $sql .= ")";
        $types .= "ss";
        $search_wild = "%{$search}%";
        $params[] = & $search_wild;
        $params[] = & $search_wild;
    }

    // 3. Add $statusFilter for tblactivestudents (if not empty and not handled above)
    if ($table === 'tblactivestudents' && $statusFilter !== '' && $statusFilter !== 'Inactive') {
        $sql .= " AND status = ?";
        $types .= "s";
        $params[] = & $statusFilter;
    }


    $stmt = $link->prepare($sql);
    if (!empty($params)) {
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $params));
    }
    $stmt->execute();
    return $stmt->get_result();
}

// Fetch Pending Applications (with filters)
$resultPending = fetchData($link, 'tblregistration', $search, $statusFilter);

// Fetch Active Student Accounts (with filters)
$resultActive = fetchData($link, 'tblactivestudents', $search, $statusFilter);

// Calculate Inactive / Auto-Deactivated Accounts
$inactiveRows = [];
$activeStudents = [];
$stmtAllActive = $link->prepare("SELECT studentID, name, email, dateapproved, lastlogin FROM tblactivestudents WHERE status = 'Active'");
$stmtAllActive->execute();
$resAllActive = $stmtAllActive->get_result();
while ($rowA = $resAllActive->fetch_assoc()) {
    $activeStudents[] = $rowA;
}

foreach ($activeStudents as $rowA) {
    $studentID_a = $rowA['studentID'];
    $reason = '';
    $dateDeactivated = '';
    $eligible = false;

    $stmtX = $link->prepare("SELECT statusdate FROM tblitemstatus WHERE claimID IN (SELECT claimID FROM tblclaimrequests WHERE studentID = ?) AND status = 'Physical Verification' ORDER BY statusdate DESC LIMIT 1");
    $stmtX->bind_param("i", $studentID_a);
    $stmtX->execute();
    $resX = $stmtX->get_result();
    if ($rowX = $resX->fetch_assoc()) {
        $days = days_diff($rowX['statusdate']);
        if ($days >= 7) {
            $reason = "Failed 2FA";
            $dateDeactivated = (new DateTime($rowX['statusdate']))->modify('+7 days')->format('Y-m-d H:i:s');
            $eligible = true;
        }
    }
    $stmtX->close();

    if (empty($reason)) {
        $lastActivityDate = $rowA['lastlogin'] ?: $rowA['dateapproved'];
        $days = days_diff($lastActivityDate);
        if ($days >= 30) {
            $reason = "30 Days Inactive";
            $dateDeactivated = (new DateTime($lastActivityDate))->modify('+30 days')->format('Y-m-d H:i:s');
            $eligible = true;
        }
    }

    if ($reason !== '' && ($statusFilter === '' || $statusFilter === 'Inactive')) {
        $inactiveRows[] = [
            'username' => $rowA['name'],
            'studentID' => $studentID_a,
            'email' => $rowA['email'],
            'reason' => $reason,
            'dateDeactivated' => $dateDeactivated,
            'eligible' => $eligible
        ];
    }
}
$stmtAllActive->close();
// Note: Keeping the database connection open for display queries, although usually it's best practice to close it earlier.

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>User Accounts Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* General Styles */
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f6;
            color: #333;
        }
        
        /* Sidebar Styles */
        nav {
            width: 250px;
            background-color: #004ea8;
            color: white;
            padding: 20px 0;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            position: fixed; 
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 1000;
        }
        nav h2 {
            padding: 0 20px;
            font-size: 20px;
            margin-bottom: 30px;
        }
        nav ul {
            list-style: none;
            padding: 0;
            flex-grow: 1;
        }
        nav ul li a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        nav ul li a:hover {
            background-color: #1a6ab9;
        }
        nav ul li a.active {
            background-color: #2980b9;
        }

        /* Logout Button in Sidebar (RED) */
        .sidebar-logout {
            padding: 20px;
            border-top: 1px solid #1a6ab9;
        }
        .sidebar-logout button {
            width: 100%;
            background-color: #ff3333 !important; 
            color: white !important;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold; 
            transition: background-color 0.2s;
        }
        .sidebar-logout button:hover {
            background-color: #cc0000 !important;
        }
        
        /* Main Layout Styles */
        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .main-content {
            margin-left: 250px; 
            flex: 1;
            padding: 20px;
            min-height: calc(100vh - 120px); 
        }

        /* Blue Header Style with spacing and rounded corners */
        .page-header-blue {
            background-color: #004ea8;
            color: white;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            width: 100%; 
            box-sizing: border-box; 
        }
        .page-header-blue h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
            color: white;
        }
        
        .main-content p {
            margin-bottom: 20px;
            color: #6c757d;
        }

        /* Filter/Top Bar Styles */
        .top-bar {
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .top-bar input[type="text"],
        .top-bar select {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            width: auto;
            min-width: 200px;
        }
        .top-bar button, .top-bar a button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.2s;
            text-decoration: none;
        }

        /* User Accounts Table Specific Styles (Copied and merged) */
        .table-header-section {display: flex;justify-content: space-between;align-items: center;padding: 15px;color: white;border-radius: 8px 8px 0 0;font-weight: bold;}
        .table-header-pending { background-color: #ffc107; }
        .table-header-active { background-color: #28a745; }
        .table-header-inactive { background-color: #dc3545; }
        .table-container {border: 1px solid #dee2e6;border-radius: 0 0 8px 8px;overflow: hidden;margin-bottom: 30px;}
        .table-responsive {max-height: 500px;overflow-y: auto;}
        .custom-table th, .custom-table td {padding: 12px;vertical-align: middle;font-size: 14px;}
        .custom-table th {background-color: #e9ecef;border-bottom: 2px solid #dee2e6;text-align: left;position: sticky;top: 0;z-index: 1;}
        .custom-table tr:hover {background-color: #f1f1f1;}
        .custom-table td.actions {white-space: nowrap;}
        .status-badge {padding: 5px 10px;border-radius: 50px;font-weight: bold;color: white;font-size: 12px;}
        .status-badge.pending { background-color: #ffc107; }
        .status-badge.active { background-color: #28a745; }
        .status-badge.inactive, .status-badge.rejected { background-color: #dc3545; }
        .modal-body img {max-width: 100%;height: auto;border: 1px solid #ddd;border-radius: 4px;padding: 5px;margin-top: 10px;}

        /* --- FOOTER STYLES --- */
        .app-footer {
            background-color: #222b35; 
            color: #9ca3af;
            padding: 40px 20px 20px; 
            font-size: 14px;
            margin-left: 250px; 
            width: calc(100% - 250px); 
            box-sizing: border-box; 
        }
        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        .footer-column {
            width: 100%;
            max-width: 300px;
            margin-bottom: 30px;
        }
        .footer-column h3 {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: white;
            margin-top: 0;
        }
        .footer-column ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .footer-column ul li {
            margin-bottom: 8px;
        }
        .footer-column a {
            color: #9ca3af;
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-column a:hover {
            color: white;
        }
        .footer-copyright {
            text-align: center;
            border-top: 1px solid #374151;
            padding-top: 15px;
            margin-top: 15px;
            font-size: 0.75rem;
            color: #6b7280;
        }
        @media (min-width: 768px) {
            .footer-column {
                width: auto;
                margin-bottom: 0;
            }
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav>
        <h2>AU iTrace — Admin</h2>
        <ul>
            <li><a href="home-admin.php">🏠 Home</a></li>
            <li><a href="found-items-admin.php">📦 Found Items</a></li>
            <li><a href="manage-claim-requests.php">📄 Manage Claim Requests</a></li>
            <li><a href="status-of-items.php">ℹ️ Status of Items</a></li>
            <li><a href="user-accounts.php" class="active">🔒 User Account</a></li>
            <li><a href="admin-accounts.php">🛡️ Admin Accounts</a></li>
            <li><a href="admin-profile.php">👤 Admin Profile</a></li>
        </ul>
        <div class="sidebar-logout">
            <form method="POST" action="../logout.php">
                <button type="submit">Logout 🚪</button>
            </form>
        </div>
        </nav>
    
    <div class="main-content">
        <div class="page-header-blue">
            <h1>User Accounts</h1>
        </div>

        <p>Manage student registration applications and active user accounts.</p>

        <form action="user-accounts.php" method="GET" class="top-bar">
            <input type="text" name="search" class="form-control" placeholder="Search by name or ID..." value="<?= htmlspecialchars($search) ?>">
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending Applications</option>
                <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active Accounts</option>
                <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : '' ?>>Inactive Accounts</option>
                <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected Applications</option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i> Apply</button>
            <a href="user-accounts.php" class="btn btn-secondary"><i class="fas fa-sync-alt me-1"></i> Reset</a>
        </form>

        <div>
            <div class="table-header-section table-header-pending">
                <span>Student Registration Applications</span>
            </div>
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table custom-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Student ID</th>
                                <th>Submission Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $resultPending->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['fullname']) ?></td>
                                <td><?= htmlspecialchars($row['studentID']) ?></td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($row['submissiondate']))) ?></td>
                                <td>
    <?php if ($row['status'] === 'Pending'): ?>
        <span class="status-badge pending">Pending</span>
    <?php elseif ($row['status'] === 'Approved'): ?>
        <span class="status-badge active">Approved</span>
    <?php elseif ($row['status'] === 'Rejected'): ?>
        <span class="status-badge rejected">Rejected</span>
    <?php endif; ?>
</td>
<td class="actions">
    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#detailsAppModal"
        data-fullname="<?= htmlspecialchars($row['fullname']) ?>"
        data-studentid="<?= htmlspecialchars($row['studentID']) ?>"
        data-email="<?= htmlspecialchars($row['email']) ?>"
        data-enrollmentform="<?= htmlspecialchars($row['enrollmentform']) ?>"
        data-schoolid="<?= htmlspecialchars($row['schoolID']) ?>"
        data-validid="<?= htmlspecialchars($row['validID']) ?>"
        data-submissiondate="<?= htmlspecialchars($row['submissiondate']) ?>"
        data-status="<?= htmlspecialchars($row['status']) ?>"
        data-regid="<?= htmlspecialchars($row['regID']) ?>"
    >Details</button>
    <?php if ($row['status'] === 'Pending'): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="application_action" value="approve">
            <input type="hidden" name="regID" value="<?= htmlspecialchars($row['regID']) ?>">
            <button type="submit" class="btn btn-sm btn-success">Approve</button>
        </form>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="application_action" value="reject">
            <input type="hidden" name="regID" value="<?= htmlspecialchars($row['regID']) ?>">
            <button type="submit" class="btn btn-sm btn-danger">Reject</button>
        </form>
    <?php endif; ?>
</td>

                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div>
            <div class="table-header-section table-header-active">
                <span>Active Student Accounts</span>
            </div>
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table custom-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Student ID</th>
                                <th>Date Approved</th>
                                <th>Last Login</th>
                                <th>Status</th>
                                <th>Recent Activity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        // Re-fetch for display based on filters
                        $resultActiveFiltered = fetchData($link, 'tblactivestudents', $search, $statusFilter);
                        while ($row2 = $resultActiveFiltered->fetch_assoc()):
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($row2['name']) ?></td>
                                <td><?= htmlspecialchars($row2['studentID']) ?></td>
                                <td><?= htmlspecialchars(date('M d, Y', strtotime($row2['dateapproved']))) ?></td>
                                <td><?= $row2['lastlogin'] ? htmlspecialchars(date('M d, Y', strtotime($row2['lastlogin']))) : 'N/A' ?></td>
                                <td><span class="status-badge active"><?= htmlspecialchars($row2['status']) ?></span></td>
                                <td><?= htmlspecialchars($row2['recentactivity'] ?? 'No Recent Activity') ?></td>
                                <td class="actions">
                                    <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#detailsUserModal"
                                        data-name="<?= htmlspecialchars($row2['name']) ?>"
                                        data-studentid="<?= htmlspecialchars($row2['studentID']) ?>"
                                        data-email="<?= htmlspecialchars($row2['email']) ?>"
                                        data-dateapproved="<?= htmlspecialchars($row2['dateapproved']) ?>"
                                        data-lastlogin="<?= htmlspecialchars($row2['lastlogin']) ?>"
                                        data-status="<?= htmlspecialchars($row2['status']) ?>"
                                    >Details</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div>
            <div class="table-header-section table-header-inactive">
                <span>Inactive / Auto-Deactivated Accounts</span>
            </div>
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table custom-table">
                        <thead>
                            <tr>
                                <th>Username / Name</th>
                                <th>Student ID</th>
                                <th>Deactivation Reason</th>
                                <th>Date Deactivated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        // Filter inactive rows based on search input and status filter before display
                        $filteredInactive = array_filter($inactiveRows, function($row) use ($search, $statusFilter) {
                            $searchCondition = true;
                            if ($search !== '') {
                                $searchLower = strtolower($search);
                                $searchCondition = str_contains(strtolower($row['username']), $searchLower) || str_contains($row['studentID'], $searchLower);
                            }
                            $statusCondition = true;
                            if ($statusFilter !== '' && $statusFilter !== 'Inactive') {
                                $statusCondition = false;
                            }
                            return $searchCondition && $statusCondition;
                        });
                        
                        if (empty($filteredInactive)): ?>
                            <tr><td colspan="5">No inactive accounts matching the filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($filteredInactive as $ir): ?>
                                <tr>
                                    <td><?= htmlspecialchars($ir['username']) ?></td>
                                    <td><?= htmlspecialchars($ir['studentID']) ?></td>
                                    <td><?= htmlspecialchars($ir['reason']) ?></td>
                                    <td><?= htmlspecialchars(date('M d, Y', strtotime($ir['dateDeactivated']))) ?></td>
                                    <td class="actions">
                                        <button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#detailsUserModal"
                                            data-name="<?= htmlspecialchars($ir['username']) ?>"
                                            data-studentid="<?= htmlspecialchars($ir['studentID']) ?>"
                                            data-email="<?= htmlspecialchars($ir['email']) ?>"
                                            data-dateapproved="N/A"
                                            data-lastlogin="N/A"
                                            data-status="Inactive"
                                        >Details</button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="reactivate_student_id" value="<?= htmlspecialchars($ir['studentID']) ?>">
                                            <button type="submit" class="btn btn-sm btn-success">Reactivate</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
    </div>

<footer class="app-footer">
    <div class="footer-content">
        
        <div class="footer-column">
            <h3>AU iTrace</h3>
            <p style="margin: 0 0 10px 0;">
                A system for lost and found management for students and faculty.
            </p>
        </div>

        <div class="footer-column">
            <h3>Quick Links</h3>
            <ul>
                <li><a href="home-admin.php">Home</a></li>
                <li><a href="found-items-admin.php">Found Items</a></li>
                <li><a href="manage-claim-requests.php">Manage Claims</a></li>
                <li><a href="status-of-items.php">Status of Items</a></li>
            </ul>
        </div>

        <div class="footer-column">
            <h3>Resources</h3>
            <ul>
                <li><a href="#">User Guide</a></li>
                <li><a href="#">FAQs</a></li>
                <li><a href="#">Privacy Policy</a></li>
            </ul>
        </div>
    </div>
    
    <div class="footer-copyright">
        <p style="margin: 0;">
            &copy; 2025 AU iTrace. All Rights Reserved.
        </p>
    </div>
</footer>
<div class="modal fade" id="detailsAppModal" tabindex="-1" aria-labelledby="detailsAppLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsAppLabel">Registration Request Details (Secure)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="alert alert-warning">Password fields are hidden for enhanced security compliance.</p>
                <dl class="row">
                    <dt class="col-sm-4">Full Name</dt><dd class="col-sm-8" id="det-fullname"></dd>
                    <dt class="col-sm-4">Student ID</dt><dd class="col-sm-8" id="det-studentid"></dd>
                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8" id="det-email"></dd>
                    <dt class="col-sm-4">Submission Date</dt><dd class="col-sm-8" id="det-submissiondate"></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8" id="det-status"></dd>
                    
                    <dt class="col-sm-4">Enrollment Form</dt>
                    <dd class="col-sm-8">
                        <img src="" id="det-enrollmentform" alt="Enrollment Form">
                    </dd>
                    <dt class="col-sm-4">School ID</dt>
                    <dd class="col-sm-8">
                        <img src="" id="det-schoolid" alt="School ID">
                    </dd>
                    <dt class="col-sm-4">Valid ID</dt>
                    <dd class="col-sm-8">
                        <img src="" id="det-validid" alt="Valid ID">
                    </dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="detailsUserModal" tabindex="-1" aria-labelledby="detailsUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailsUserLabel">User Account Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row">
                    <dt class="col-sm-4">Full Name</dt><dd class="col-sm-8" id="det-user-name"></dd>
                    <dt class="col-sm-4">Student ID</dt><dd class="col-sm-8" id="det-user-studentid"></dd>
                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8" id="det-user-email"></dd>
                    <dt class="col-sm-4">Date Approved</dt><dd class="col-sm-8" id="det-user-dateapproved"></dd>
                    <dt class="col-sm-4">Last Login</dt><dd class="col-sm-8" id="det-user-lastlogin"></dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8" id="det-user-status"></dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const imagePathPrefix = '../'; // Define the prefix

    var detAppModal = document.getElementById('detailsAppModal');
    detAppModal.addEventListener('show.bs.modal', function(event) {
        var btn = event.relatedTarget;
        document.getElementById('det-fullname').textContent = btn.getAttribute('data-fullname') || 'N/A';
        document.getElementById('det-studentid').textContent = btn.getAttribute('data-studentid') || 'N/A';
        document.getElementById('det-email').textContent = btn.getAttribute('data-email') || 'N/A';
        // Password fields removed for security
        document.getElementById('det-submissiondate').textContent = btn.getAttribute('data-submissiondate') ? new Date(btn.getAttribute('data-submissiondate')).toLocaleDateString() : 'N/A';
        document.getElementById('det-status').textContent = btn.getAttribute('data-status') || 'N/A';
        
        // Prepend '../' to image paths for correct resolution
        const enrollmentFormPath = btn.getAttribute('data-enrollmentform');
        const schoolIdPath = btn.getAttribute('data-schoolid');
        const validIdPath = btn.getAttribute('data-validid');
        
        document.getElementById('det-enrollmentform').src = enrollmentFormPath ? imagePathPrefix + enrollmentFormPath : '';
        document.getElementById('det-schoolid').src = schoolIdPath ? imagePathPrefix + schoolIdPath : '';
        document.getElementById('det-validid').src = validIdPath ? imagePathPrefix + validIdPath : '';
    });

    var detUserModal = document.getElementById('detailsUserModal');
    detUserModal.addEventListener('show.bs.modal', function(event) {
        var btn = event.relatedTarget;
        document.getElementById('det-user-name').textContent = btn.getAttribute('data-name') || 'N/A';
        document.getElementById('det-user-studentid').textContent = btn.getAttribute('data-studentid') || 'N/A';
        document.getElementById('det-user-email').textContent = btn.getAttribute('data-email') || 'N/A';
        document.getElementById('det-user-dateapproved').textContent = btn.getAttribute('data-dateapproved') ? new Date(btn.getAttribute('data-dateapproved')).toLocaleDateString() : 'N/A';
        document.getElementById('det-user-lastlogin').textContent = btn.getAttribute('data-lastlogin') ? new Date(btn.getAttribute('data-lastlogin')).toLocaleDateString() : 'N/A';
        document.getElementById('det-user-status').textContent = btn.getAttribute('data-status') || 'N/A';
    });
    
    // Removed function togglePasswordVisibility()
</script>
</body>
</html>
