<?php
session_start();
if ($_SESSION['user']['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php';

if (!isset($_GET['id'])) {
    header("Location: admin_dashboard.php");
    exit;
}

$provider_id = $_GET['id'];
$provider = $conn->query("SELECT name, id_proof FROM users u JOIN providers p ON u.id = p.user_id WHERE u.id = $provider_id")->fetch_assoc();

if (!$provider || empty($provider['id_proof'])) {
    $_SESSION['error'] = "ID proof not found";
    header("Location: admin_dashboard.php");
    exit;
}

// Debugging - check what's actually stored in the database
error_log("ID Proof Path: " . $provider['id_proof']);

// Get just the filename without path if full path was stored
$id_proof_file = basename($provider['id_proof']);
$id_proof_path = 'uploads/id_proofs/' . $id_proof_file;

// Verify file exists
if (!file_exists($id_proof_path)) {
    error_log("File not found at: " . $id_proof_path);
    $_SESSION['error'] = "ID proof file not found on server";
    header("Location: admin_dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View ID Proof | UrbanServe</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            padding: 20px;
            text-align: center;
            background-color: #f8f9fa;
        }
        .proof-container {
            max-width: 800px;
            margin: 30px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .proof-image {
            max-width: 100%;
            max-height: 80vh;
            border: 1px solid #ddd;
            margin: 20px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #f76d2b;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        .back-btn:hover {
            background: #e05b1a;
        }
        .file-info {
            margin: 15px 0;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="proof-container">
        <h1>ID Proof for <?= htmlspecialchars($provider['name']) ?></h1>
        <div class="file-info">
            File: <?= htmlspecialchars($id_proof_file) ?>
        </div>
        
        <?php 
        $file_ext = strtolower(pathinfo($id_proof_file, PATHINFO_EXTENSION));
        if ($file_ext === 'pdf'): ?>
            <embed src="uploads/id_proofs/<?= htmlspecialchars($id_proof_file) ?>" 
                   type="application/pdf" 
                   width="100%" 
                   height="600px">
        <?php else: ?>
            <img src="uploads/id_proofs/<?= htmlspecialchars($id_proof_file) ?>" 
                 alt="ID Proof" 
                 class="proof-image"
                 onerror="this.onerror=null;this.src='assets/image-not-found.png';">
        <?php endif; ?>
        
        <br>
        <a href="admin_dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <!-- Add Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>