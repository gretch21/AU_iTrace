<?php
//claim-requests.php
session_start();
require_once '../config.php';

if (!isset($_SESSION['username'])) {
    // Redirect to login page
    header("Location: au_itrace_portal.php?tab=login");
    exit(); 
}

// ✅ Fetch studentID using the logged-in username from tblsystemusers
$username = $_SESSION['username'];
$stmtUser = $link->prepare("SELECT studentID FROM tblsystemusers WHERE username = ?");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();

if ($resultUser->num_rows === 0) {
    die("Student not found.");
}

$user = $resultUser->fetch_assoc();
$studentID = $user['studentID']; // ✅ This is used in the claim request insert

// Check if foundID is provided
if (!isset($_GET['foundID'])) {
    die("Found Item ID is missing.");
}

$foundID = $_GET['foundID'];

// 🔒 New Logic to Prevent Duplicate Claims 🔒
$checkQuery = "SELECT COUNT(*) FROM tblclaimrequests WHERE foundID = ? AND studentID = ?";
$checkStmt = $link->prepare($checkQuery);
$checkStmt->bind_param("ss", $foundID, $studentID);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$claimCount = $checkResult->fetch_row()[0];

if ($claimCount > 0) {
    echo "<script>alert('You have already submitted a claim for this item. You cannot submit another.'); window.location.href='found-items-student.php';</script>";
    exit; 
}

// Fetch item details
$query = "SELECT * FROM tblfounditems WHERE foundID = ?";
$stmt = $link->prepare($query);
$stmt->bind_param("s", $foundID);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();

if (!$item) {
    die("Item not found.");
}

// Generate unique Claim ID
date_default_timezone_set('Asia/Manila');
$claimID = 'CI-' . date("Ymd-His");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $description = $_POST['description'];
    $lastseenlocation = $_POST['lastseenlocation'];
    $datelost = $_POST['datelost'];
    $estimatedvalue = $_POST['estimatedvalue'];

    $uploadedFiles = []; // Corrected variable name
    if (!empty($_FILES['ownershipproof']['name'][0])) {
        $files = $_FILES['ownershipproof'];
        $fileCount = min(count($files['name']), 5);
        for ($i = 0; $i < $fileCount; $i++) {
            $fileName = time() . '-' . basename($files['name'][$i]);
            $targetPath = '../fitems-proof_student/' . $fileName;
            if (move_uploaded_file($files['tmp_name'][$i], $targetPath)) {
                $uploadedFiles[] = $fileName;
            }
        }
    }
    $proofFileName = implode(',', $uploadedFiles); // Match the lowercase variable

    // 1. Insert Claim
    $insert = $link->prepare("INSERT INTO tblclaimrequests (claimID, foundID, studentID, datesubmitted, status, description, lastseenlocation, datelost, estimatedvalue, ownershipproof) VALUES (?, ?, ?, NOW(), 'Pending', ?, ?, ?, ?, ?)");
    $insert->bind_param("ssssssss", $claimID, $foundID, $studentID, $description, $lastseenlocation, $datelost, $estimatedvalue, $proofFileName);

    if ($insert->execute()) {
        // 2. Fetch userID to send the "Pending" notification
        $userQuery = $link->prepare("SELECT userID FROM tblsystemusers WHERE studentID = ?");
        $userQuery->bind_param("s", $studentID);
        $userQuery->execute();
        $userData = $userQuery->get_result()->fetch_assoc();
        
        if ($userData) {
        $targetUID = $userData['userID'];
        $iName = $item['itemname']; // Ensure you have fetched the item name
        
        // Use [PENDING] as a keyword for the UI logic
        $notifMsg = "[PENDING]: Your claim request for '$iName' has been submitted and is currently under review by the administrator.";
        
        $notif = $link->prepare("INSERT INTO tblnotifications (userID, adminmessage, datecreated, isread) VALUES (?, ?, NOW(), 0)");
        $notif->bind_param("is", $targetUID, $notifMsg);
        $notif->execute();
    }
        
        echo "<script>alert('Claim submitted successfully!'); window.location.href='found-items-student.php';</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Claim Request</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #004ea8;
            height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .form-section {
            width: 100%;
            max-width: 980px; /* Increased size */
            background-color: #ffffff;
            border-left: 8px solid #facc15;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .form-section h4 {
            display: flex;
            align-items: center;
            font-weight: bold;
            margin-bottom: 30px;
            font-size: 1.75rem;
        }
        .form-section h4::before {
            content: "⚠️";
            margin-right: 15px;
        }
        .form-label {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 6px;
        }
        input[readonly] {
            background-color: #f3f4f6 !important;
        }
        textarea {
            resize: none;
        }
        .upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background-color: #f9fafb;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .upload-box:hover {
            border-color: #3b82f6;
            background-color: #eff6ff;
        }
        .upload-box input[type="file"] {
            display: none;
        }
        .btn-submit {
            background-color: #059669;
            color: white;
            font-weight: 600;
            padding: 12px;
        }
        .btn-submit:hover {
            background-color: #047857;
        }
        .btn-cancel {
            background-color: #dc2626;
            color: white;
            font-weight: 600;
            padding: 12px;
        }
        .btn-cancel:hover {
            background-color: #b91c1c;
        }
        #file-name {
            margin-top: 10px;
            font-size: 13px;
            color: #4b5563;
            max-height: 60px;
            overflow-y: auto;
        }
    </style>
</head>
<body>

<div class="form-section">
    <h4>Claim Request Item</h4>
    <form method="POST" enctype="multipart/form-data">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Found Item ID</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($foundID) ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Claim ID</label>
                    <input type="text" class="form-control" value="<?= $claimID ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Item Name</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($item['itemname']) ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <select class="form-select" disabled>
                        <option selected><?= htmlspecialchars($item['category']) ?></option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label">Detailed Description of Item</label>
                    <textarea class="form-control" name="description" rows="4" placeholder="Color, brand, unique marks..." required></textarea>
                </div>
            </div>

            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Last Seen Location</label>
                    <input type="text" name="lastseenlocation" class="form-control" placeholder="e.g., Library Study Area" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Date Lost</label>
                    <input type="date" name="datelost" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Estimated Value</label>
                    <input type="number" name="estimatedvalue" class="form-control" placeholder="PHP">
                </div>
                <div class="mb-0" style="height: 225px;">
                    <label class="form-label">Upload Proof of Ownership (Max 5)</label>
                    <div class="upload-box" onclick="document.getElementById('ownershipproof').click();">
                        <input type="file" name="ownershipproof[]" id="ownershipproof" required multiple onchange="updateFileName()">
                        <div style="font-size: 30px;">📤</div>
                        <div style="font-weight: 600;">Drag & Drop or Click to Upload</div>
                        <p id="file-name"></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-5">
            <div class="col-md-6">
                <a href="found-items-student.php" class="btn btn-cancel w-100">Cancel</a>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn btn-submit w-100">📥 Submit Claim</button>
            </div>
        </div>
    </form>
</div>

<script>
    function updateFileName() {
        const input = document.getElementById('ownershipproof');
        const files = input.files;
        
        if (files.length > 5) {
            alert("You can only upload a maximum of 5 images.");
            input.value = ""; 
            document.getElementById('file-name').textContent = "";
            return;
        }

        if (files.length > 0) {
            let names = [];
            for (let i = 0; i < files.length; i++) {
                names.push(files[i].name);
            }
            document.getElementById('file-name').textContent = "(" + files.length + " files selected): " + names.join(", ");
        } else {
            document.getElementById('file-name').textContent = "";
        }
    }
</script>

</body>
</html>
