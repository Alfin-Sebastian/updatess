<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user']['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['service_id'])) {
        $service_id = (int)$_POST['service_id'];
        
        // Check if already favorited
        $stmt = $conn->prepare("SELECT id FROM favourites WHERE user_id = ? AND service_id = ?");
        $stmt->bind_param("ii", $user_id, $service_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Remove favorite
            $stmt = $conn->prepare("DELETE FROM favourites WHERE user_id = ? AND service_id = ?");
            $stmt->bind_param("ii", $user_id, $service_id);
            $stmt->execute();
        } else {
            // Add favorite
            $stmt = $conn->prepare("INSERT INTO favourites (user_id, service_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $service_id);
            $stmt->execute();
        }
        
    } elseif (isset($_POST['provider_id'])) {
        $provider_id = (int)$_POST['provider_id'];
        
        // Check if already favorited
        $stmt = $conn->prepare("SELECT id FROM favourites WHERE user_id = ? AND provider_id = ?");
        $stmt->bind_param("ii", $user_id, $provider_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Remove favorite
            $stmt = $conn->prepare("DELETE FROM favourites WHERE user_id = ? AND provider_id = ?");
            $stmt->bind_param("ii", $user_id, $provider_id);
            $stmt->execute();
        } else {
            // Add favorite
            $stmt = $conn->prepare("INSERT INTO favourites (user_id, provider_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $provider_id);
            $stmt->execute();
        }
    }
    
    // Redirect back
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
}
?>