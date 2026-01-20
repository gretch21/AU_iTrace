<?php
//manage-claim-requests.php
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
require_once '../config.php';

// 1. Basic Session Check
if (!isset($_SESSION['username'], $_SESSION['usertype'])) {
    // Redirect for missing session
    session_destroy();
    header("Location: ../au_itrace_portal.php?tab=login&error=noaccess");
    exit;
}

if ($_SESSION['usertype'] !== 'ADMINISTRATOR') {
    http_response_code(403);
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
    session_destroy();
    header("Location: ../au_itrace_portal.php?tab=login&error=noaccess");
    exit;
}

// Now check usertype and status explicitly using the fetched $user variable
if (strtoupper($user['usertype']) !== 'ADMINISTRATOR') {
    http_response_code(403);
    die("User is not administrator. Usertype found: " . htmlspecialchars($user['usertype']));
}

if (strtoupper($user['status']) !== 'ACTIVE') {
    // Inactive user, force logout
    session_destroy();
    header("Location: ../au_itrace_portal.php?tab=login&error=noaccess");
    exit;
}

// --- CRITICAL FIX END ---

$stmt->close();

// --- LOGGING FUNCTION ---
/**
 * Logs an action by an admin into the tbladminlogs table.
 * @param mysqli $link The database connection object.
 * @param string $username The username of the administrator.
 * @param string $action The action performed.
 * @param string $page The page where the action occurred.
 * @param string|null $foundID The ID of the found item associated with the action (optional).
 */
function log_admin_action($link, $username, $action, $page, $foundID = NULL) {
    // Check if $foundID is empty and set it to NULL explicitly for the database.
    $foundID = empty($foundID) ? NULL : $foundID;

    $log_sql = "INSERT INTO tbladminlogs (username, action, page, foundID, date_time) VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $link->prepare($log_sql);

    if ($log_stmt) {
        // 's' for username, 's' for action, 's' for page, 's' for foundID (it's varchar(20) in DB)
        $log_stmt->bind_param("ssss", $username, $action, $page, $foundID);
        $log_stmt->execute();
        $log_stmt->close();
    }
}
// --- END LOGGING FUNCTION ---

// --- Configuration Constants ---
const NOTIF_TITLE = "Your Item Claim Request is scheduled for Physical Verification"; // Fixed title as used in student's home page

/**
 * Generates the automated physical verification schedule message.
 * @param string $studentName The name of the student.
 * @param string $itemName The name of the claimed item.
 * @param string $scheduledDatetime The scheduled date and time string (e.g., 'YYYY-MM-DD HH:MM:SS').
 * @return string The formatted automated message (PLAIN TEXT).
 */
function generate_automated_message($studentName, $itemName, $scheduledDatetime) {
    // Format date and time
    if (empty($scheduledDatetime) || strtotime($scheduledDatetime) === false) {
          $formattedDate = "[Date NOT SET]";
          $formattedTime = "[Time NOT SET]";
    } else {
        $formattedDate = date("F j, Y", strtotime($scheduledDatetime));
        $formattedTime = date("g:i A", strtotime($scheduledDatetime));
    }

    // *** FIX: Using plain text only. The display system (student's side) cannot render HTML. ***
    $message = <<<EOT
From: Office of Student Affairs (OSA)

Subject: Physical Verification Schedule

Dear {$studentName},

Your lost item, "{$itemName}," physical verification is scheduled for {$formattedDate}, at {$formattedTime} in the Office of Student Affairs.

Please bring:

- Valid university ID
- Proof of ownership (receipt, photos, serial numbers, etc.)
- Description of the item

Failure to bring the required proofs on the scheduled date and time may result in denial of your claim.

Thank you.

Office of Student Affairs (OSA)
EOT;

    return $message;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'approve') {
        $claimID = $_POST['claimID'];
        $scheduleddatetime = $_POST['scheduleddatetime'];
        $adminmessage = $_POST['adminmessage'];
        
        // Initialize foundID and studentID
        $foundID = null;
        $studentID = null;
        $studentName = null;
        $itemName = 'Unknown Item'; // Initialize for logging

        // 1. Update claim request status
        $stmt = $link->prepare("
            UPDATE tblclaimrequests 
            SET status = 'Approved', scheduleddatetime = ?, adminmessage = ?
            WHERE claimID = ?
        ");
        $stmt->bind_param("sss", $scheduleddatetime, $adminmessage, $claimID);
        $stmt->execute();
        $stmt->close();

        // 2. Get foundID, studentID, and item name from claim request and found items table
        // Use a JOIN to get itemname in one query
        $result = $link->prepare("
            SELECT cr.foundID, cr.studentID, fi.itemname
            FROM tblclaimrequests cr
            LEFT JOIN tblfounditems fi ON cr.foundID = fi.foundID
            WHERE cr.claimID = ?
        ");
        $result->bind_param("s", $claimID);
        $result->execute();
        $result->bind_result($foundID, $studentID, $fetchedItemName);
        $result->fetch();
        if (!empty($fetchedItemName)) {
            $itemName = $fetchedItemName;
        }
        $result->close();
        
        // --- LOGGING: Log the approval action ---
        $log_action = "Approved Claim Request (ClaimID: {$claimID}) for Item: {$itemName}";
        log_admin_action($link, $username, $log_action, "Manage Claim Requests", $foundID);
        // --- END LOGGING ---


        // Proceed only if the claimID was valid and yielded a foundID
        if (!empty($foundID)) {

            // 3. Decline other claims for same foundID except current claim
            $declineOthers = $link->prepare("
                UPDATE tblclaimrequests
                SET status = 'Declined'
                WHERE foundID = ? AND claimID != ? AND status = 'Pending'
            ");
            // NOTE: Changed 'is' to 'ss' assuming foundID is VARCHAR(20) based on tblfounditems structure
            $declineOthers->bind_param("ss", $foundID, $claimID);
            $declineOthers->execute();
            $declineOthers->close();


            // 4. Update found item status
            $updateItem = $link->prepare("UPDATE tblfounditems SET status = 'Physical Verification' WHERE foundID = ?");
            // NOTE: Changed 'i' to 's' assuming foundID is VARCHAR(20) based on tblfounditems structure
            $updateItem->bind_param("s", $foundID);
            $updateItem->execute();
            $updateItem->close();

            // 5. Update tblitemstatus
            $status = 'Physical Verification';
            $stmt2 = $link->prepare("
                INSERT INTO tblitemstatus (claimID, studentID, status, statusdate)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE studentID = VALUES(studentID), status = VALUES(status), statusdate = NOW()
            ");
            // NOTE: Changed 'iis' to 'sis' assuming claimID is VARCHAR(20) based on tblclaimrequests structure
            $stmt2->bind_param("sis", $claimID, $studentID, $status);
            $stmt2->execute();
            $stmt2->close();

            // 6. Send notification ONLY if student is active and has a system account
            if (!empty($studentID)) {
                // Check if studentID is active and get name
                $checkActive = $link->prepare("SELECT name FROM tblactivestudents WHERE studentID = ?");
                $checkActive->bind_param("i", $studentID);
                $checkActive->execute();
                $checkActive->bind_result($studentName);
                $checkActive->fetch();
                $checkActive->close();

                if (!empty($studentName)) { // Student is active and name fetched
                    
                    // *** CRITICAL FIX: Guaranteed Item Name Retrieval is already handled in step 2.
                    
                    // Get userID from tblsystemusers for this studentID
                    $getUserIDStmt = $link->prepare("SELECT userID FROM tblsystemusers WHERE studentID = ?");
                    $getUserIDStmt->bind_param("i", $studentID);
                    $getUserIDStmt->execute();
                    $getUserIDStmt->bind_result($userID);
                    $getUserIDStmt->fetch();
                    $getUserIDStmt->close();

                    if (!empty($userID)) {
    // Ensure we are using the EXACT variable from the POST data
    $finalMessage = "[APPROVED]: " . (empty(trim($adminmessage))
        ? generate_automated_message($studentName, $itemName, $scheduleddatetime) // Use $scheduleddatetime from $_POST
        : $adminmessage);
    
    // Insert into tblnotifications 
    $insertNotif = $link->prepare("
        INSERT INTO tblnotifications (userID, adminmessage, scheduleddatetime, isread, datecreated)
        VALUES (?, ?, ?, 0, NOW())
    ");
    $insertNotif->bind_param("iss", $userID, $finalMessage, $scheduleddatetime);
    $insertNotif->execute();
    $insertNotif->close();
}
                }
            }
        } // End if(!empty($foundID))

    
//DECLINED NOTIFICATION
} elseif (isset($_POST['action']) && $_POST['action'] === 'decline') {
    $claimID = $_POST['claimID'];
    $foundID = null;
    $studentID = null;
    $itemName = 'Unknown Item';

    // Get foundID, studentID, and item name
    $result = $link->prepare("
        SELECT cr.foundID, cr.studentID, fi.itemname
        FROM tblclaimrequests cr
        LEFT JOIN tblfounditems fi ON cr.foundID = fi.foundID
        WHERE cr.claimID = ?
    ");
    $result->bind_param("s", $claimID);
    $result->execute();
    $result->bind_result($foundID, $studentID, $fetchedItemName);
    $result->fetch();
    if (!empty($fetchedItemName)) {
        $itemName = $fetchedItemName;
    }
    $result->close();

    // Decline the claim
    $stmt = $link->prepare("UPDATE tblclaimrequests SET status = 'Declined' WHERE claimID = ?");
    $stmt->bind_param("s", $claimID);
    $stmt->execute();
    $stmt->close();
    
    // Logging
    $log_action = "Declined Claim Request (ClaimID: {$claimID}) for Item: {$itemName}";
    log_admin_action($link, $username, $log_action, "Manage Claim Requests", $foundID);


    // Keep the Notification logic below if you still want the student to be notified of the decline
    if (!empty($studentID)) {
         $getUserIDStmt = $link->prepare("SELECT userID FROM tblsystemusers WHERE studentID = ?");
         $getUserIDStmt->bind_param("i", $studentID);
         $getUserIDStmt->execute();
         $getUserIDStmt->bind_result($userID);
         $getUserIDStmt->fetch();
         $getUserIDStmt->close();
         
         if (!empty($userID)) {
             $declineMessage = "[DECLINED]: We regret to inform you that your claim request for '{$itemName}' has been declined. The proof of ownership submitted appeared unclear or insufficient to verify your claim. If you believe this was an error, please visit the Office of Student Affairs with additional documentation. Thank you for your understanding.";
             
             $insertNotif = $link->prepare("
                 INSERT INTO tblnotifications (userID, adminmessage, datecreated, isread)
                 VALUES (?, ?, NOW(), 0)
             ");
             $insertNotif->bind_param("is", $userID, $declineMessage);
             $insertNotif->execute();
             $insertNotif->close();
         }
    }
} // ← This closes the decline elseif block

    // Redirect after processing to avoid form resubmission
    header("Location: manage-claim-requests.php");
    exit;
} // ← This closes the if ($_SERVER['REQUEST_METHOD'] === 'POST') block

// Filters for listing
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Base SQL for claim requests with join
$sql = "
    SELECT 
        cr.*, 
        fi.itemname, 
        as_t.name AS student_name,
        (SELECT COUNT(*) FROM tblclaimrequests AS cr2 WHERE cr2.foundID = cr.foundID AND cr2.status = 'Pending') AS pending_claims_count
    FROM tblclaimrequests cr
    LEFT JOIN tblfounditems fi ON cr.foundID = fi.foundID
    LEFT JOIN tblactivestudents as_t ON cr.studentID = as_t.studentID
    WHERE 1=1
";

// SECURITY FIX: Use prepared statements for filters
$params = [];
$types = "";

if ($search !== '') {
    $sql .= " AND (
        cr.claimID LIKE ?
        OR cr.studentID LIKE ?
        OR as_t.name LIKE ?
        OR fi.itemname LIKE ?
    )";
    $likeSearch = '%' . $search . '%';
    array_push($params, $likeSearch, $likeSearch, $likeSearch, $likeSearch);
    $types .= "ssss";
}

if ($statusFilter !== '') {
    $sql .= " AND cr.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= " ORDER BY cr.datesubmitted DESC";

// Prepare the main query
$stmt_main = $link->prepare($sql);

if (!$stmt_main) {
    die("Failed to prepare main statement: " . $link->error);
}

// Bind parameters dynamically
if (!empty($types)) {
    $bind_names = [$types];
    for ($i = 0; $i < count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    // Call bind_param dynamically with the correct number of references
    call_user_func_array([$stmt_main, 'bind_param'], $bind_names);
}

$stmt_main->execute();
$result = $stmt_main->get_result();

// Data is now in $result and will be fetched in the table loop.

?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Claim Requests</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* General Styles */
        body {margin: 0;font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;background-color: #f4f7f6;color: #333;}
        /* Sidebar Styles (Unchanged from found-items-admin.php) */
        nav {width: 250px;background-color: #004ea8;color: white;padding: 20px 0;display: flex;flex-direction: column;box-shadow: 2px 0 5px rgba(0,0,0,0.1);position: fixed; top: 0;left: 0;bottom: 0;overflow-y: auto;z-index: 1000;}
        nav h2 {padding: 0 20px;font-size: 20px;margin-bottom: 30px;}
        nav ul {list-style: none;padding: 0;flex-grow: 1;}
        nav ul li a {display: flex;align-items: center;padding: 12px 20px;color: white;text-decoration: none;transition: background-color 0.2s;}
        nav ul li a:hover {background-color: #1a6ab9;}
        nav ul li a.active {background-color: #2980b9;}
        /* Logout Button in Sidebar (RED) */
        .sidebar-logout {padding: 20px;border-top: 1px solid #1a6ab9;}
        .sidebar-logout button {width: 100%;background-color: #ff3333 !important; color: white !important;padding: 10px 15px;border: none;border-radius: 4px;cursor: pointer;font-size: 16px;font-weight: bold; transition: background-color 0.2s;}
        .sidebar-logout button:hover {background-color: #cc0000 !important;}
        
        /* Main Layout Styles */
        .main-wrapper {display: flex;min-height: 100vh;}
        .main-content {margin-left: 250px; flex: 1;padding: 20px; /* Add padding to the parent container */min-height: calc(100vh - 120px);}

        /* UPDATED: Blue Header Style with spacing and rounded corners */
        .page-header-blue {background-color: #004ea8;color: white;padding: 20px;margin-bottom: 25px; /* Increased spacing below header */border-radius: 8px; /* Rounded corners */box-shadow: 0 4px 6px rgba(0,0,0,0.1); /* Subtle shadow for depth */
            /* Ensure the header does not overflow its parent padding */width: 100%; box-sizing: border-box; }
        .page-header-blue h1 {margin: 0;font-size: 28px;font-weight: 600;color: white;}
        .main-content p {margin-bottom: 20px;color: #6c757d;}

        /* Filter/Top Bar Styles (Changed from .search-bar to .top-bar) */
        .top-bar {background-color: white;padding: 15px;border-radius: 8px;box-shadow: 0 2px 4px rgba(0,0,0,0.05);margin-bottom: 30px;display: flex;gap: 10px;flex-wrap: wrap;align-items: center;}
        .top-bar input[type="text"],
        .top-bar select {padding: 10px;border: 1px solid #ccc;border-radius: 4px;font-size: 16px;width: auto;min-width: 200px;flex-grow: 1; /* Allow inputs to grow if space allows */}
        .top-bar button {padding: 10px 20px;border: none;border-radius: 4px;cursor: pointer;font-size: 16px;font-weight: 600;transition:background-color 0.2s;text-decoration: none;flex-shrink: 0;}

        .top-bar button[type="submit"],
        .top-bar button#searchButton { /* Targeting the Search/Reset button */background-color: #007bff;color: white;}
        .top-bar button[type="submit"]:hover,
        .top-bar button#searchButton:hover {background-color: #0056b3;}


        /* Table Specific Styles (Adapted from original for consistency, but keeping necessary table features) */
        .table-container { overflow-x: auto; margin-top: 20px;}
        table {width: 100%; background-color: white; border-radius: 8px; overflow: hidden;box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); /* Increased shadow for card-like look */border-collapse: separate;border-spacing: 0;}
        thead { background-color: #f1f1f1; /* Lighter header for contrast */color: #333; font-weight: bold; }
        th, td {padding: 14px; text-align: center; vertical-align: middle; border-bottom: 1px solid #e0e0e0;}
        tr:last-child td {border-bottom: none;}
        .btn-approve, .btn-decline, .btn-details {padding: 8px 16px; /* Slightly larger buttons */border: none; border-radius: 5px; margin: 2px;transition: opacity 0.2s;font-weight: 600;font-size: 0.9rem;}
        .btn-approve { background-color: #28a745; color: white; } /* Green */
        .btn-decline { background-color: #dc3545; color: white; } /* Red */
        .btn-details { background-color: #ffc107; color: black; } /* Yellow */
        .btn-approve:hover { background-color: #218838; }
        .btn-decline:hover { background-color: #c82333; }
        .btn-details:hover { background-color: #e0a800; }

        .text-pending { color: #ffc107; font-weight: bold; }
        .text-approved { color: #28a745; font-weight: bold; }
        .text-declined { color: #dc3545; font-weight: bold; }
        .duplicate-highlight { background-color: #fff3cd; border-left: 5px solid #ffc107; }
        
        /* Modal improvements for accessibility */
        .modal-body dl dt { font-weight: bold; }
        .modal-body dl dd { margin-bottom: 10px; }

        /* --- FOOTER STYLES (Copied from found-items-admin.php) --- */
        .app-footer {background-color: #222b35; color: #9ca3af;padding: 40px 20px 20px; font-size: 14px;margin-left: 250px; width: calc(100% - 250px); box-sizing: border-box; }
        .footer-content {max-width: 1200px;margin: 0 auto;display: flex;flex-wrap: wrap;justify-content: space-between;}
        .footer-column {width: 100%;max-width: 300px;margin-bottom: 30px;}
        .footer-column h3 {font-size: 1.125rem;font-weight: 700;margin-bottom: 15px;color: white;margin-top: 0;
        }
        .footer-column ul {list-style: none;padding: 0;margin: 0;}
        .footer-column ul li {margin-bottom: 8px;}
        .footer-column a {color: #9ca3af;text-decoration: none;transition: color 0.2s;}
        .footer-column a:hover {color: white;}
        .footer-copyright {text-align: center;border-top: 1px solid #374151;padding-top: 15px;margin-top: 15px;font-size: 0.75rem;color: #6b7280;}
        @media (min-width: 768px) {
            .footer-column {width: auto;margin-bottom: 0;}}
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav>
        <h2>AU iTrace — Admin</h2>
        <ul>
            <li><a href="home-admin.php">🏠 Home</a></li>
            <li><a href="found-items-admin.php">📦 Found Items</a></li>
            <li><a href="manage-claim-requests.php" class="active" aria-current="page">📄 Manage Claim Requests</a></li>
            <li><a href="status-of-items.php">ℹ️ Status of Items</a></li>
            <li><a href="user-accounts.php">🔒 User Account</a></li>
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
        <?php
            $headerText = 'All Claim Requests';
            if ($statusFilter === 'Pending') $headerText = 'Pending Claim Requests';
            else if ($statusFilter === 'Approved') $headerText = 'Approved Claim Requests';
            else if ($statusFilter === 'Declined') $headerText = 'Declined Claim Requests';
        ?>
        <div class="page-header-blue" role="heading" aria-level="1">
            <h1><?= $headerText ?></h1>
        </div>
        <form method="GET" action="manage-claim-requests.php" class="top-bar" role="search">
            <input type="text" name="search" placeholder="🔍 Search by Student Name, ID, or Item..." value="<?= htmlspecialchars($search) ?>" aria-label="Search claim requests">
            <select name="status" aria-label="Filter by claim status">
                <option value="">Claim Request Status (All)</option>
                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                <option value="Declined" <?= $statusFilter === 'Declined' ? 'selected' : '' ?>>Declined</option>
            </select>
            <button type="submit" id="searchButton" class="btn btn-primary">🔍 Search</button>
        </form>

        <div class="table-container">
        <table class="table table-hover" role="table" aria-label="List of Claim Requests">
            <thead>
                <tr>
                    <th scope="col">Claim ID</th>
                    <th scope="col">Found ID</th>
                    <th scope="col">Item Name</th>
                    <th scope="col">Student ID</th>
                    <th scope="col">Scheduled Date and Time</th>
                    <th scope="col">Claim Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php 
                        // Highlight if there are multiple pending claims for the same found item.
                        $rowClass = '';
                        // Note: The logic for pending_claims_count is retained from the original file's PHP query.
                        if (isset($row['pending_claims_count']) && $row['pending_claims_count'] > 1 && $row['status'] === 'Pending') {
                            $rowClass = 'duplicate-highlight';
                        }
                        
                        // Determine status class
                        $st = $row['status'];
                        $cls = '';
                        if ($st === 'Pending') $cls = 'text-pending';
                        elseif ($st === 'Approved') $cls = 'text-approved';
                        elseif ($st === 'Declined') $cls = 'text-declined';
                        
                        // Format scheduled date/time
                        $scheduledDateTimeDisplay = !empty($row['scheduleddatetime']) ? date("M j, Y H:i", strtotime($row['scheduleddatetime'])) : 'N/A';
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= htmlspecialchars($row['claimID']) ?></td>
                        <td><?= htmlspecialchars($row['foundID']) ?></td>
                        <td><?= htmlspecialchars($row['itemname'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($row['studentID'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($scheduledDateTimeDisplay) ?></td>
                        <td><span class="<?= $cls ?>"><?= htmlspecialchars($st) ?></span></td>
                        <td>
                            <button type="button" 
                                class="btn btn-details btn-sm" 
                                data-bs-toggle="modal" 
                                data-bs-target="#detailsModal" 
                                data-claimid="<?= htmlspecialchars($row['claimID']) ?>"
                                data-foundid="<?= htmlspecialchars($row['foundID']) ?>"
                                data-itemname="<?= htmlspecialchars($row['itemname'] ?? '') ?>"
                                data-studentid="<?= htmlspecialchars($row['studentID'] ?? '') ?>"
                                data-studentname="<?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?>"
                                data-description="<?= htmlspecialchars($row['description'] ?? '') ?>"
                                data-lastseenlocation="<?= htmlspecialchars($row['lastseenlocation'] ?? '') ?>"
                                data-datelost="<?= htmlspecialchars($row['datelost'] ?? '') ?>"
                                data-estimatedvalue="<?= htmlspecialchars($row['estimatedvalue'] ?? '') ?>"
                                data-proof="<?= htmlspecialchars($row['ownershipproof'] ?? '') ?>"
                                data-status="<?= htmlspecialchars($row['status']) ?>"
                                data-scheduleddatetime="<?= htmlspecialchars($row['scheduleddatetime'] ?? '') ?>"
                                data-adminmessage="<?= htmlspecialchars($row['adminmessage'] ?? '') ?>"
                                aria-label="View details for claim ID <?= htmlspecialchars($row['claimID']) ?>"
                            >Details</button>

                            <?php if ($row['status'] === 'Pending'): ?>
                                <button type="button"
                                    class="btn btn-approve btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#approveModal"
                                    data-claimid="<?= htmlspecialchars($row['claimID']) ?>"
                                    data-studentname="<?= htmlspecialchars($row['student_name'] ?? '') ?>"
                                    aria-label="Approve claim ID <?= htmlspecialchars($row['claimID']) ?>"
                                >Approve</button>
                            
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="action" value="decline">
                                    <input type="hidden" name="claimID" value="<?= htmlspecialchars($row['claimID']) ?>">
                                    <button type="submit" class="btn btn-decline btn-sm" 
                                        onclick="return confirm('Are you sure you want to decline claim ID <?= htmlspecialchars($row['claimID']) ?>?')"
                                        aria-label="Decline claim ID <?= htmlspecialchars($row['claimID']) ?>">Decline</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7">No claim requests found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php $stmt_main->close(); ?>

        <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="detailsModalLabel">Claim Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                  <dl class="row">
                    <dt class="col-sm-4">Claim ID</dt><dd class="col-sm-8" id="detail-claimID"></dd>
                    <dt class="col-sm-4">Found ID</dt><dd class="col-sm-8" id="detail-foundID"></dd>
                    <hr>
                    <dt class="col-sm-4">Item Name</dt><dd class="col-sm-8" id="detail-itemName"></dd>
                    <dt class="col-sm-4">Student Name</dt><dd class="col-sm-8" id="detail-studentName"></dd>
                    <dt class="col-sm-4">Student ID</dt><dd class="col-sm-8" id="detail-studentID"></dd>
                    <hr>
                    <dt class="col-sm-4">Claim Description</dt><dd class="col-sm-8" id="detail-description"></dd>
                    <dt class="col-sm-4">Last Seen Location</dt><dd class="col-sm-8" id="detail-lastSeenLocation"></dd>
                    <dt class="col-sm-4">Date Lost</dt><dd class="col-sm-8" id="detail-dateLost"></dd>
                    <dt class="col-sm-4">Estimated Value</dt><dd class="col-sm-8" id="detail-estimatedValue"></dd>
                    <hr>
                    <dt class="col-sm-4">Proof of Ownership Photo</dt>
<dd class="col-sm-8">
    <div id="detail-proof-container" class="d-flex flex-wrap"></div>
</dd>
                    <hr>
                    <dt class="col-sm-4">Claim Status</dt><dd class="col-sm-8" id="detail-status"></dd>
                    <dt class="col-sm-4">Scheduled Date & Time</dt><dd class="col-sm-8" id="detail-scheduledDateTime"></dd>
                    <dt class="col-sm-4">Admin Message</dt><dd class="col-sm-8" id="detail-adminMessage" style="white-space: pre-wrap;"></dd>
                  </dl>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>

        <div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="POST" action="">
                <div class="modal-header">
                  <h5 class="modal-title" id="approveModalLabel">Approve Claim Request</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="claimID" id="approve-claimID">
                  <p id="approve-student-info" class="alert alert-info" role="status" aria-live="polite">Approving claim for: N/A</p>
                  <div class="mb-3">
                    <label for="scheduleddatetime" class="form-label">Scheduled Date & Time <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="scheduleddatetime" id="scheduleddatetime" class="form-control" required aria-required="true">
                  </div>
                  <div class="mb-3">
                    <label for="adminmessage" class="form-label">Admin Message (Leave blank for automated message)</label>
                    <textarea name="adminmessage" id="adminmessage" class="form-control" rows="5" aria-describedby="adminMessageHelp"></textarea>
                    <div id="adminMessageHelp" class="form-text">If left empty, the automated Physical Verification Schedule message will be sent.</div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-approve" aria-label="Finalize and approve claim">Approve Claim</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </form>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// JavaScript for UI functionality and modal data handling

// 1. Fill Details Modal
var detailsModal = document.getElementById('detailsModal');
detailsModal.addEventListener('show.bs.modal', function(event) {
    var btn = event.relatedTarget;
    
    // Fill text fields
    document.getElementById('detail-claimID').textContent = btn.getAttribute('data-claimid') || 'N/A';
    document.getElementById('detail-foundID').textContent = btn.getAttribute('data-foundid') || 'N/A';
    document.getElementById('detail-itemName').textContent = btn.getAttribute('data-itemname') || 'N/A';
    document.getElementById('detail-studentID').textContent = btn.getAttribute('data-studentid') || 'N/A';
    document.getElementById('detail-studentName').textContent = btn.getAttribute('data-studentname') || 'N/A';
    document.getElementById('detail-description').textContent = btn.getAttribute('data-description') || 'N/A';
    document.getElementById('detail-lastSeenLocation').textContent = btn.getAttribute('data-lastseenlocation') || 'N/A';
    document.getElementById('detail-dateLost').textContent = btn.getAttribute('data-datelost') || 'N/A';
    document.getElementById('detail-estimatedValue').textContent = btn.getAttribute('data-estimatedvalue') || 'N/A';

    // MULTIPLE IMAGE FIX START
    var proofContainer = document.getElementById('detail-proof-container');
    var proofString = btn.getAttribute('data-proof') || '';
    
    // Clear previous images from the container
    proofContainer.innerHTML = '';

    if (proofString) {
        // Split the comma-separated string into an array of filenames
        var files = proofString.split(',');
        
        files.forEach(function(file) {
            var fileName = file.trim();
            if (fileName !== '') {
                var filePath = '../fitems-proof_student/' + fileName;
                
                // Create a link wrapper for each image
                var a = document.createElement('a');
                a.href = filePath;
                a.target = '_blank';
                a.className = 'd-inline-block me-2 mb-2';

                // Create the image element
                var img = document.createElement('img');
                img.src = filePath;
                img.className = 'img-thumbnail';
                img.style.width = '120px';
                img.style.height = '120px';
                img.style.objectFit = 'cover';
                img.alt = "Proof of ownership";

                a.appendChild(img);
                proofContainer.appendChild(a);
            }
        });
    } else {
        proofContainer.innerHTML = '<span class="text-muted">No proof image provided.</span>';
    }
    // MULTIPLE IMAGE FIX END

    document.getElementById('detail-status').textContent = btn.getAttribute('data-status') || 'N/A';
    var rawScheduledDateTime = btn.getAttribute('data-scheduleddatetime');
    var formattedScheduledDateTime = rawScheduledDateTime ? new Date(rawScheduledDateTime).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }) : 'N/A';
    document.getElementById('detail-scheduledDateTime').textContent = formattedScheduledDateTime;
    document.getElementById('detail-adminMessage').textContent = btn.getAttribute('data-adminmessage') || 'N/A (Automated message will be sent if admin message was empty on approval)';
});

// 2. Fill Approve Modal
var approveModal = document.getElementById('approveModal');
approveModal.addEventListener('show.bs.modal', function(event) {
    var btn = event.relatedTarget;
    var claimID = btn.getAttribute('data-claimid');
    var studentName = btn.getAttribute('data-studentname');

    document.getElementById('approve-claimID').value = claimID;
    document.getElementById('approve-student-info').textContent = 'Approving claim ID ' + claimID + ' for ' + studentName + '.';

    // Reset date/time and message fields on show
    document.getElementById('scheduleddatetime').value = '';
    document.getElementById('adminmessage').value = '';
});


// 3. Search/Reset Functionality (Updated to use the new submit button)
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form.top-bar');
    const searchInput = form.querySelector('input[name="search"]');
    const statusSelect = form.querySelector('select[name="status"]');
    const searchButton = document.getElementById('searchButton');
    
    // Check if filters are active
    const hasFilters = searchInput.value.trim() !== '' || statusSelect.value !== '';
    if (hasFilters) {
        // Change button text when filters are active
        searchButton.textContent = '🔄 Reset Filters';
        // Change button color for reset
        searchButton.style.backgroundColor = '#6c757d'; // Gray for reset
        searchButton.style.color = 'white';
        searchButton.onmouseover = function() { this.style.backgroundColor = '#5a6268'; };
        searchButton.onmouseout = function() { this.style.backgroundColor = '#6c757d'; };
    } else {
        // Default search button appearance
        searchButton.textContent = '🔍 Search';
        searchButton.style.backgroundColor = '#007bff'; // Blue for search
        searchButton.style.color = 'white';
        searchButton.onmouseover = function() { this.style.backgroundColor = '#0056b3'; };
        searchButton.onmouseout = function() { this.style.backgroundColor = '#007bff'; };
    }

    searchButton.addEventListener('click', function (e) {
        e.preventDefault(); // Prevent default form submission initially
        if (searchButton.textContent.includes('Reset')) {
            // Reset mode: Redirect to clean URL
            window.location.href = 'manage-claim-requests.php';
        } else {
            // Search mode: Submit the form
            form.submit();
        }
    });
});
</script>

</body>
</html>
