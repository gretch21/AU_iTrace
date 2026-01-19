<?php
// Start the session and check authentication, copied from home-student.php
session_start();
require_once '../config.php';

// Check if the user is logged in and is a student
if (!isset($_SESSION['username']) || $_SESSION['usertype'] !== 'STUDENT') {
    // Redirect unauthenticated users
    header("Location: au_itrace_portal.php?tab=login");
    exit;
}

$username = $_SESSION['username'];

// Get user info (studentID and userID)
if (!isset($link) || !is_object($link)) {
    require_once '../config.php';
}

$stmtUser = $link->prepare("SELECT userID, studentID FROM tblsystemusers WHERE username = ?");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();

if ($resultUser->num_rows === 0) {
    // Note: In a real system, you might redirect to a login/error page.
    // For this context, we'll stop execution as per the original file's logic.
    die("Student not found.");
}

$user = $resultUser->fetch_assoc();
$studentID = $user['studentID']; // Not strictly used here, but kept for consistency
$userID = $user['userID'];

// Store userID in session for AJAX calls
$_SESSION['userID'] = $userID;

// FIXED NOTIFICATION TITLE STRING (Copied from home-student.php)
const FIXED_NOTIF_TITLE = "Your Item Claim Request is scheduled for Physical Verification";

// ======================================================================
// ⚠️ ACTION: Handle AJAX request to clear notifications (Copied from home-student.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_notifications') {
    if (isset($_SESSION['userID'])) {
        $clearUserID = $_SESSION['userID'];
        
        if (!isset($link) || !is_object($link)) {
            require_once '../config.php';
        }
        
        $stmtClear = $link->prepare("UPDATE tblnotifications SET isread = 1 WHERE userID = ? AND isread = 0");
        $stmtClear->bind_param("i", $clearUserID);
        
        if ($stmtClear->execute()) {
            echo "Success";
            $stmtClear->close();
            $link->close();
            exit;
        } else {
            http_response_code(500);
            echo "Database Error";
            $stmtClear->close();
            $link->close();
            exit;
        }
    } else {
        http_response_code(401);
        echo "Unauthorized";
        $link->close();
        exit;
    }
}
// ⚠️ ACTION: Handle AJAX request to clear notifications (End)
// ======================================================================

// --- Notification Handling Logic (Copied from home-student.php) ---
$notifCount = 0;
// 1. Get unread notification count
$sqlNotifCount = "SELECT COUNT(*) AS count FROM tblnotifications WHERE userID = ? AND isread = 0";
$stmtCount = $link->prepare($sqlNotifCount);
$stmtCount->bind_param("i", $userID);
$stmtCount->execute();
$resultCount = $stmtCount->get_result();
if ($row = $resultCount->fetch_assoc()) {
    $notifCount = $row['count'];
}
$stmtCount->close();

// 2. Get latest 5 notifications
$notifications = [];
$sqlNotifList = "
    SELECT 
        notifID, 
        adminmessage, 
        datecreated, 
        isread 
    FROM tblnotifications 
    WHERE userID = ? 
    ORDER BY datecreated DESC 
    LIMIT 5
";
$stmtList = $link->prepare($sqlNotifList);
$stmtList->bind_param("i", $userID);
$stmtList->execute();
$resultList = $stmtList->get_result();
while ($row = $resultList->fetch_assoc()) {
    $row['notif_title'] = FIXED_NOTIF_TITLE; 
    $row['notif_message'] = $row['adminmessage']; 
    $notifications[] = $row;
}
$stmtList->close();
// --- End Notification Handling ---

// Ensure the connection is closed after all queries
if (!isset($_POST['action'])) {
    $link->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>AU iTrace - Help and Info</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet' />
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* Base Styles from home-student.php */
        body { font-family: 'Poppins', sans-serif;background-color: #f3f4f6; margin: 0; }
        nav.sidebar { width: 250px; background-color: #004ea8; color: white; padding: 20px 0 70px 0; display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; overflow-y: auto; }
        nav.sidebar h2 { padding: 0 20px; font-size: 20px; margin-bottom: 30px; }
        nav.sidebar ul { list-style: none; padding: 0; margin: 0; flex-grow: 1; }
        nav.sidebar ul li a { display: flex; align-items: center; padding: 12px 20px; color: white; text-decoration: none; }
        /* Active style for Help & Info */
        nav.sidebar ul li a:hover, nav.sidebar ul li a.active { background-color: #2980b9; }
        .logout-container { position: fixed; bottom: 20px; left: 0; width: 250px; padding: 0 20px; }
        form.logout-form button { width: 100%; background-color: #ef4444; border: none; color: white; padding: 12px 0; border-radius: 0.375rem; font-size: 1rem; cursor: pointer; font-weight: 700; transition: background-color 0.2s; }
        form.logout-form button:hover { background-color: #dc2626; }
        .main-wrapper { display: flex; min-height: 100vh; }
        .main-content { margin-left: 250px; flex: 1; padding: 1rem 2rem; min-height: 100vh; display: flex; flex-direction: column; }
        
        /* Topnav and Notification Styles (Copied from home-student.php) */
        .topnav { 
    background-color: #004ea8; 
    padding: 1.5rem 2rem; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    color: white; 
    font-weight: 700; 
    font-size: 1.5rem; 
    border-radius: 0.5rem; 
    box-shadow: 0 2px 8px rgb(0 0 0 / 0.15); 
    margin-bottom: 1.5rem; 
    user-select: none;
    position: relative; /* ADD THIS */
    z-index: 10000; /* ADD THIS */
}
        .notif-btn { background: none; border: none; cursor: pointer; font-size: 1.75rem; position: relative; color: white; }
        .notif-badge { position: absolute; top: -6px; right: -10px; background-color: #ef4444; color: white; border-radius: 9999px; padding: 0 6px; font-size: 0.75rem; font-weight: 700; line-height: 1; user-select: none; }
.notif-dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: 60px;
    width: 320px;
    background: white;
    border: 1px solid #ccc;
    border-radius: 0.5rem;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    z-index: 99999 !important; /* INCREASED - was only 9999 */
    max-height: 400px;
    overflow-y: auto;
}
        .notif-dropdown.show { display: block; }
        .notif-dropdown h4 { margin: 0; padding: 0.75rem 1rem; background: #004ea8; color: white; border-radius: 0.5rem 0.5rem 0 0; font-weight: 600; font-size: 1rem; }
        .notif-item { 
    padding: 0.75rem 1rem; 
    border-bottom: 1px solid #eee; 
    font-size: 0.875rem; 
    color: #333; 
    line-height: 1.2; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    background-color: white;
    position: relative; /* ADD THIS */
    z-index: 100000; /* ADD THIS */
}
        .notif-item.unread { background-color: #f0f8ff; border-left: 3px solid #004ea8; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item small { color: #666; font-size: 0.75rem; display: block; margin-top: 4px; }
        .view-btn { 
    background-color: #10b981; 
    color: white; 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 0.75rem; 
    border: none; 
    cursor: pointer; 
    transition: background-color 0.2s;
    position: relative; /* ADD THIS */
    z-index: 100001; /* ADD THIS */
    pointer-events: auto; /* ADD THIS */
}
        .view-btn:hover { background-color: #059669; }
.modal {
    display: none; 
    position: fixed; 
    z-index: 999999 !important;
    left: 0;
    top: 0;
    width: 100%; 
    height: 100%; 
    background-color: rgba(0,0,0,0.85);
    justify-content: center;
    align-items: center;
}

.modal.show {
    display: flex !important;
}

.modal-content { 
    background: #ffffff; 
    padding: 30px; 
    border-radius: 12px; 
    width: 90%; 
    max-width: 600px; 
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3); 
    position: relative;
    z-index: 1000000;
}
        .close-btn { position: absolute; top: 10px; right: 15px; font-size: 1.5rem; font-weight: bold; color: #aaa; cursor: pointer; }
        .close-btn:hover { color: #333; }
        
        .content-wrapper { background-color: white; padding: 1.5rem 2rem; border-radius: 0.5rem; flex-grow: 1; box-shadow: 0 4px 12px rgb(0 0 0 / 0.05); overflow-y: auto; }
        
        /* Specific styles for the Help & Info content */
        .help-section { margin-bottom: 2.5rem; }
        .section-header { margin-bottom: 1.5rem; font-size: 1.875rem; font-weight: 600; color: #1f2937; }
        .faq-card { border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }

        /* Verification Steps Custom Styling */
        .verification-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .step-card {
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.06);
            background-color: #fff;
            text-align: center;
            border-top: 5px solid;
        }
        .step-card:nth-child(1) { border-color: #2563eb; } /* Blue */
        .step-card:nth-child(2) { border-color: #f59e0b; } /* Yellow */
        .step-card:nth-child(3) { border-color: #10b981; } /* Green */
        
        .step-icon-wrapper {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 1rem;
        }
        .step-icon {
            font-size: 2rem;
        }
        .step-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: #1f2937;
        }
        .step-description {
            font-size: 0.875rem;
            color: #4b5563;
        }
        
        /* Footer Fix from home-student.php */
        .app-footer {
            margin-left: 250px; /* Same width as nav.sidebar */
            width: calc(100% - 250px); 
            box-sizing: border-box; 
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav class="sidebar" role="navigation" aria-label="Sidebar Navigation">
        <h2>AU iTrace — Student</h2>
        <ul>
            <li><a href="home-student.php">🏠 Home</a></li>
            <li><a href="found-items-student.php">📦 Found Items</a></li>
            <li><a href="item-status-student.php">🔍 Item Status</a></li>
            <li><a href="help-and-info.php" class="active">❓ Help & Info</a></li>
            <li><a href="privacy-policy.php">🔒 Privacy Policy</a></li>
            <li><a href="profile-student.php"><i class='bx bxs-user' style="margin-right: 12px;"></i> Profile</a></li>
        </ul>

        <div class="logout-container">
            <form method="POST" action="../logout.php" class="logout-form" role="form">
                <button type="submit" aria-label="Logout">Logout</button>
            </form>
        </div>
    </nav>

    <div class="main-content">
        
        <div class="topnav" role="banner">
    <div>Welcome to AU iTrace</div>
    <div style="position: relative;">
        <button class="notif-btn" onclick="toggleDropdown()" aria-label="Toggle Notifications" aria-expanded="false" aria-controls="notifDropdown">
            <i class='bx bxs-bell'></i>
            <?php if ($notifCount > 0): ?>
                <span class="notif-badge" id="notifBadge" aria-live="polite" aria-atomic="true"><?php echo $notifCount; ?></span>
            <?php endif; ?>
        </button>
        
        <div class="notif-dropdown" id="notifDropdown" role="region" aria-live="polite" aria-label="Notifications List" tabindex="-1">
            <h4>🔔 Notifications</h4>
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $notif): 
    $msg = $notif['adminmessage'];
    $isUnread = $notif['isread'] == 0 ? 'unread' : '';

    // --- DYNAMIC LOGIC FOR TITLE, COLOR, AND ICON ---
    if (strpos($msg, '[PENDING]') !== false) {
        $displayTitle = "📝 Claim Pending";
        $titleColor = "#2563eb";
    } elseif (strpos($msg, '[APPROVED]') !== false) {
        $displayTitle = "✅ Claim Approved";
        $titleColor = "#10b981";
    } elseif (strpos($msg, '[DECLINED]') !== false) {
        $displayTitle = "❌ Claim Declined";
        $titleColor = "#ef4444";
    } elseif (strpos($msg, '[RETURNED]') !== false) {
        $displayTitle = "🎁 Item Returned";
        $titleColor = "#0891b2";
    } else {
        $displayTitle = "Notification Update";
        $titleColor = "#004ea8";
    }

    // Clean the message
    $cleanMsg = str_replace(['[PENDING]: ', '[APPROVED]: ', '[DECLINED]: ', '[RETURNED]: '], '', $msg);
    
    // JSON encode for safe data attribute storage
    $safeTitle = htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($cleanMsg, ENT_QUOTES, 'UTF-8');
?> 
<div class="notif-item <?= $isUnread ?>" tabindex="0">
    <div style="flex-grow: 1; padding-right: 10px;">
        <strong style="color: <?= $titleColor ?>; display: block; font-size: 0.9rem;">
            <?php echo htmlspecialchars($displayTitle); ?>
        </strong>
        <p style="margin: 2px 0; font-size: 0.8rem; color: #4b5563; line-height: 1.3;">
            <?php echo htmlspecialchars(mb_strimwidth($cleanMsg, 0, 50, "...")); ?>
        </p>
        <small style="color: #9ca3af; font-size: 0.7rem;">
            <?php echo date("M d, Y h:i A", strtotime($notif['datecreated'])); ?>
        </small>
    </div>
    
    <button 
        class="view-btn" 
        data-title="<?= $safeTitle ?>"
        data-message="<?= $safeMessage ?>"
        aria-label="View details"
    >View</button>
</div>
<?php endforeach; ?>
            <?php else: ?>
                <div class="notif-item" tabindex="0">No notifications</div>
            <?php endif; ?>
        </div>
    </div>
</div>

        <div class="content-wrapper" role="main">
            <h1 class="text-2xl font-semibold mb-3">Help and Information</h1>
            <p class="mb-6 text-gray-600">Find answers to frequently asked questions and information about the claim process.</p>
            
            <section id="about" class="help-section">
                <h2 class="section-header">About AU iTrace</h2>
                <div class="p-6 border border-gray-200 rounded-lg shadow-sm">
                    <h3 class="text-xl font-bold mb-3 text-gray-800">System Overview</h3>
                    <p class="text-gray-700 mb-6">AU iTrace is designed to revolutionize the lost and found process at Arellano University. We provide a digital platform that ensures transparency, accountability, and efficiency in handling lost property.</p>
                    
                    <h3 class="text-xl font-bold mb-3 text-gray-800">Contact Information</h3>
                    <p class="text-gray-700">Office of Student Affairs, Arellano University - Juan Sumulong Campus, 2600 Legarda St., Sampaloc, Manila</p>
                    <p class="text-gray-700">Email: <a href="mailto:osa@arellano.edu.ph" class="text-blue-600 hover:text-blue-800">osa@arellano.edu.ph</a></p>
                    <p class="text-gray-700">Phone: (02) 8-734-7371 local 123</p>
                </div>
            </section>
            
            <section id="verification" class="help-section">
                <div class="text-center mb-6">
                    <h2 class="section-header">Verification Process</h2>
                    <p class="text-gray-500 max-w-2xl mx-auto">Ensuring items are returned to their rightful owners</p>
                </div>
                
                <div class="verification-steps">
                    <div class="step-card">
                        <div class="step-icon-wrapper" style="background-color: #e0f2fe;">
                            <i class="fas fa-file-upload step-icon" style="color: #2563eb;"></i>
                        </div>
                        <h3 class="step-title">1. Submit Claim</h3>
                        <p class="step-description">Registered students can submit claim requests with proof of ownership through the system.</p>
                    </div>
                    <div class="step-card">
                        <div class="step-icon-wrapper" style="background-color: #fef3c7;">
                            <i class="fas fa-user-check step-icon" style="color: #f59e0b;"></i>
                        </div>
                        <h3 class="step-title">2. OSA Verification</h3>
                        <p class="step-description">OSA staff reviews the claim and schedules physical verification at the office.</p>
                    </div>
                    <div class="step-card">
                        <div class="step-icon-wrapper" style="background-color: #dcfce7;">
                            <i class="fas fa-check-double step-icon" style="color: #10b981;"></i>
                        </div>
                        <h3 class="step-title">3. Claim Approved</h3>
                        <p class="step-description">After successful verification, the item is marked as claimed and returned to the owner.</p>
                    </div>
                </div>
            </section>
            
            <section id="faqs" class="help-section">
                <h2 class="section-header">Frequently Asked Questions</h2>
                
                <div class="faq-card">
                    <h3 class="text-lg font-semibold mb-2 text-gray-800">How do I register as a student?</h3>
                    <p class="text-gray-700">Visit the Office of Student Affairs with your enrollment form, school ID, and valid government ID for verification and account creation.</p>
                </div>
                
                <div class="faq-card">
                    <h3 class="text-lg font-semibold mb-2 text-gray-800">What happens if I find an item?</h3>
                    <p class="text-gray-700">Bring the item to the OSA office, and our staff will help you register it in the system. You'll receive a reference code for tracking.</p>
                </div>
                
                <div class="faq-card">
                    <h3 class="text-lg font-semibold mb-2 text-gray-800">How long are unclaimed items kept?</h3>
                    <p class="text-gray-700">Items are kept for 30 days. After this period, unclaimed items may be donated or disposed of according to university policy.</p>
                </div>
            </section>
        </div>
        
    </div>
</div>

<div id="notificationModal" class="modal" role="dialog" aria-labelledby="modalTitle" aria-modal="true">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()" aria-label="Close modal">&times;</span>
        <h3 id="modalTitle" class="text-xl font-bold mb-3">Notification Details</h3>
        
        <strong class="block mb-2 text-lg" id="modal-notif-title"></strong>
        <div id="modal-notif-message" class="whitespace-pre-wrap text-gray-700 p-3 bg-gray-50 border border-gray-200 rounded"></div>
        
        <div class="mt-4 text-sm text-gray-500">
            *This is the full message sent by the Office of Student Affairs regarding your claim.
        </div>
    </div>
</div>

<footer class="app-footer" style="
        background-color: #222b35; 
        color: #f3f4f6; /* Light text color */
        padding: 30px 20px; 
        font-family: 'Poppins', sans-serif; /* Fallback font */
        box-sizing: border-box;
    ">
        <div style="
            max-width: 1200px; 
            margin-left: auto; 
            margin-right: auto; 
            display: flex; 
            flex-wrap: wrap; /* Allows wrapping on smaller screens */
            justify-content: space-between;
        ">
            
            <div style="width: 100%; max-width: 300px; margin-bottom: 30px;">
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 15px; color: white;">AU iTrace</h3>
                <p style="font-size: 0.875rem; line-height: 1.5; margin-bottom: 20px;">
                    Arellano University's Digital Lost and Found System
                </p>
                <div style="font-size: 1.25rem;">
                    <a href="#" style="color: #f3f4f6; text-decoration: none; margin-right: 15px;">
                        f 
                    </a>
                    <a href="#" style="color: #f3f4f6; text-decoration: none; margin-right: 15px;">
                        &#x1F426; 
                    </a>
                    <a href="#" style="color: #f3f4f6; text-decoration: none;">
                        &#x1F4F7;
                    </a>
                </div>
            </div>

            <div style="width: 100%; max-width: 200px; margin-bottom: 30px;">
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 15px; color: white;">Quick Links</h3>
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.875rem;">
                    <li style="margin-bottom: 8px;">
                        <a href="home-student.php" style="color: #d1d5db; text-decoration: none;">Home</a>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <a href="found-items-student.php" style="color: #d1d5db; text-decoration: none;">Found Items</a>
                    </li>
                </ul>
            </div>

            <div style="width: 100%; max-width: 200px; margin-bottom: 30px;">
                <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 15px; color: white;">Resources</h3>
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.875rem;">
                    <li style="margin-bottom: 8px;">
                        <a href="#" style="color: #d1d5db; text-decoration: none;">User Guide</a>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <a href="#" style="color: #d1d5db; text-decoration: none;">FAQs</a>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <a href="privacy-policy-student.php" style="color: #d1d5db; text-decoration: none;">Privacy Policy</a>
                    </li>
                </ul>
            </div>
        </div>
        
        <div style="text-align: center; border-top: 1px solid #374151; padding-top: 15px; margin-top: 15px; font-size: 0.75rem; color: #9ca3af;">
            <p style="margin: 0;">
                Copyright &copy; 2025 AU iTrace. All Rights Reserved.
            </p>
        </div>
    </footer>

<script>
// --- Modal and Dropdown Functions (Copied from home-student.php) ---

function toggleDropdown() {
    const dropdown = document.getElementById('notifDropdown');
    const button = document.querySelector('.notif-btn');
    const expanded = button.getAttribute('aria-expanded') === 'true';
    
    // Toggle the dropdown visibility
    button.setAttribute('aria-expanded', !expanded);
    dropdown.classList.toggle('show');

    // If opening the dropdown, clear the unread count via AJAX
    if (!expanded) {
        clearNotifCount();
        dropdown.focus();
    }
}

/**
 * Shows the notification details in a modal.
 */
// Add this event listener - replace the old showNotificationDetails approach
document.addEventListener('DOMContentLoaded', function() {
    // Event delegation for view buttons
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('view-btn')) {
            e.preventDefault();
            e.stopPropagation();
            
            const title = e.target.getAttribute('data-title');
            const message = e.target.getAttribute('data-message');
            
            console.log("View button clicked!");
            console.log("Title:", title);
            console.log("Message:", message);
            
            showNotificationDetails(null, title, message);
        }
    });
});

function showNotificationDetails(event, title, message) {
    console.log("=== Modal function called ===");
    
    const modal = document.getElementById('notificationModal');
    const modalTitle = document.getElementById('modal-notif-title');
    const modalMessage = document.getElementById('modal-notif-message');

    modalTitle.innerText = title;
    modalMessage.innerText = message;

    modal.classList.add('show');
    
    const dropdown = document.getElementById('notifDropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
    }
}

function closeModal() {
    const modal = document.getElementById('notificationModal');
    modal.classList.remove('show');
}

// Close dropdown if clicked outside
document.addEventListener('click', function (e) {
    const dropdown = document.getElementById('notifDropdown');
    const button = document.querySelector('.notif-btn');
    
    // Only close the dropdown if the click is NOT on the button and NOT inside the dropdown
    if (dropdown.classList.contains('show')) {
        if (!dropdown.contains(e.target) && !button.contains(e.target)) {
            dropdown.classList.remove('show');
            button.setAttribute('aria-expanded', 'false');
        }
    }

    // Close modal if clicking the background
    const modal = document.getElementById('notificationModal');
    if (e.target === modal) {
        closeModal();
    }
});

// --- AJAX Function to Clear Notification Count (Points to self) ---

function clearNotifCount() {
    const notifBadge = document.getElementById('notifBadge');
    if (!notifBadge) return;
    
    const xhr = new XMLHttpRequest();
    // FIX: Changed from 'help-and-info-student.php' to 'help-and-info.php'
    xhr.open('POST', 'help-and-info.php', true); 
    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
    
    xhr.onload = function () {
        if (xhr.status === 200 && xhr.responseText.trim() === 'Success') {
            notifBadge.remove();
            document.querySelectorAll('.notif-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
        } else {
            console.error("Server responded with:", xhr.responseText);
        }
    };
    xhr.send('action=clear_notifications'); 
}
</script>

</body>
</html>
