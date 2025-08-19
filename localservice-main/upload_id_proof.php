<?php
session_start();
if ($_SESSION['user']['role'] !== 'provider') {
    header("Location: login.php");
    exit;
}

include 'db.php';

$provider_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['id_proof'])) {
    // Create uploads directory if it doesn't exist
    $target_dir = "uploads/id_proofs/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    // Get file extension
    $file_ext = strtolower(pathinfo($_FILES['id_proof']['name'], PATHINFO_EXTENSION));
    $filename = "id_proof_" . $provider_id . "_" . time() . "." . $file_ext;
    $target_file = $target_dir . $filename;
    
    // Check file size (max 5MB)
    if ($_FILES['id_proof']['size'] > 5000000) {
        $_SESSION['error'] = "File is too large. Maximum size is 5MB.";
        header("Location: provider_dashboard.php");
        exit;
    }
    
    // Allow certain file formats
    $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($file_ext, $allowed_types)) {
        $_SESSION['error'] = "Only JPG, JPEG, PNG & PDF files are allowed.";
        header("Location: provider_dashboard.php");
        exit;
    }
    
    // Check if file is an actual image or PDF
    $check = $file_ext === 'pdf' || getimagesize($_FILES['id_proof']['tmp_name']);
    if (!$check) {
        $_SESSION['error'] = "File is not a valid image or PDF.";
        header("Location: provider_dashboard.php");
        exit;
    }
    
    // Upload file
    if (move_uploaded_file($_FILES['id_proof']['tmp_name'], $target_file)) {
        // Update database
        $stmt = $conn->prepare("UPDATE providers SET id_proof = ?, is_verified = 0 WHERE user_id = ?");
        $stmt->bind_param("si", $target_file, $provider_id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "ID proof uploaded successfully. Your account is under review.";
        } else {
            $_SESSION['error'] = "Database error. Please try again.";
        }
    } else {
        $_SESSION['error'] = "Sorry, there was an error uploading your file.";
    }
    
    header("Location: provider_dashboard.php");
    exit;
} else {
    $_SESSION['error'] = "Invalid request.";
    header("Location: provider_dashboard.php");
    exit;
}