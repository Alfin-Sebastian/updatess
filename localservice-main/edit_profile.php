<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'];
$user_id = $user['id'];
$message = "";
$message_type = "";

// Handle update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $password = trim($_POST['password']);
    $address  = ($role === 'customer') ? trim($_POST['address']) : null;
    $city     = trim($_POST['city']);
    $state    = trim($_POST['state']);
    $pincode  = trim($_POST['pincode']);

    // Handle profile image upload
    $profile_image = $user['profile_image'] ?? null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "uploads/profiles/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $file_extension;
        $target_file = $target_dir . $filename;
        
        // Validate image
        $check = getimagesize($_FILES['profile_image']['tmp_name']);
        if ($check !== false) {
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                // Delete old image if exists
                if (!empty($profile_image) && file_exists($profile_image)) {
                    unlink($profile_image);
                }
                $profile_image = $target_file;
            } else {
                $message = "Error uploading profile image.";
                $message_type = "error";
            }
        } else {
            $message = "File is not an image.";
            $message_type = "error";
        }
    }

    if (empty($message)) {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, address=?, city=?, state=?, pincode=?, password=?, profile_image=? WHERE id=?");
            $stmt->bind_param("sssssssssi", $name, $email, $phone, $address, $city, $state, $pincode, $hashed, $profile_image, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, address=?, city=?, state=?, pincode=?, profile_image=? WHERE id=?");
            $stmt->bind_param("ssssssssi", $name, $email, $phone, $address, $city, $state, $pincode, $profile_image, $user_id);
        }

        if ($stmt->execute()) {
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['user']['address'] = $address;
            $_SESSION['user']['profile_image'] = $profile_image;
            $message = "Profile updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating profile: " . $conn->error;
            $message_type = "error";
        }

        // If provider, update provider details
        if ($role === 'provider') {
            $experience = trim($_POST['experience']);
            $location   = trim($_POST['location']);
            $bio        = trim($_POST['bio']);
            $services   = $_POST['services'] ?? [];

            $check = $conn->prepare("SELECT id FROM providers WHERE user_id=?");
            $check->bind_param("i", $user_id);
            $check->execute();
            $result = $check->get_result();

            if ($result->num_rows > 0) {
                $update = $conn->prepare("UPDATE providers SET experience=?, location=?, bio=? WHERE user_id=?");
                $update->bind_param("sssi", $experience, $location, $bio, $user_id);
                $update->execute();
            } else {
                $insert = $conn->prepare("INSERT INTO providers (user_id, experience, location, bio) VALUES (?, ?, ?, ?)");
                $insert->bind_param("isss", $user_id, $experience, $location, $bio);
                $insert->execute();
            }

            // Reset services
            $conn->query("DELETE FROM provider_services WHERE provider_id = $user_id");

            if (!empty($services)) {
                $stmt = $conn->prepare("INSERT INTO provider_services (provider_id, service_id, price) VALUES (?, ?, ?)");
                foreach ($services as $service_id) {
                    $price = 500.00;
                    $stmt->bind_param("iid", $user_id, $service_id, $price);
                    $stmt->execute();
                }
            }
        }
    }
}

// Fetch current user info
$query = $conn->prepare("SELECT * FROM users WHERE id=?");
$query->bind_param("i", $user_id);
$query->execute();
$current_user = $query->get_result()->fetch_assoc();

if ($role === 'provider') {
    $prov = $conn->query("SELECT * FROM providers WHERE user_id = $user_id")->fetch_assoc();
    $services = $conn->query("SELECT id, name FROM services");
    $myServices = $conn->query("SELECT service_id FROM provider_services WHERE provider_id = $user_id");

    $service_ids = [];
    while ($row = $myServices->fetch_assoc()) {
        $service_ids[] = $row['service_id'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Profile</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; padding: 20px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { border-bottom: 2px solid #f76d2b; padding-bottom: 10px; color: #2d3748; }
        .form-group { margin-bottom: 15px; }
        label { font-weight: bold; display: block; margin-bottom: 5px; }
        input, textarea, select { width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #ccc; }
        .btn { background: #f76d2b; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; transition: background 0.3s; }
        .btn:hover { background: #e05b1a; }
        .alert { padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .profile-image-container { text-align: center; margin-bottom: 20px; }
        .profile-image { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 3px solid #f76d2b; }
        .image-upload { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .image-upload-btn { background: #f0f4f8; padding: 8px 15px; border-radius: 5px; cursor: pointer; border: 1px dashed #ccc; }
        .image-upload-btn:hover { background: #e2e8f0; }
        .services-checkbox { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-top: 10px; }
        .service-checkbox { display: flex; align-items: center; gap: 8px; }
        .back-link { display: inline-block; margin-top: 20px; color: #f76d2b; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <h2>Edit My Profile</h2>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="profile-image-container">
            <img src="<?= htmlspecialchars($current_user['profile_image'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($current_user['name']) . '&background=f76d2b&color=fff') ?>" 
                 alt="Profile Image" class="profile-image" id="profileImagePreview">
            <div class="image-upload">
                <label for="profile_image" class="image-upload-btn">Change Photo</label>
                <input type="file" name="profile_image" id="profile_image" accept="image/*" style="display: none;" 
                       onchange="document.getElementById('profileImagePreview').src = window.URL.createObjectURL(this.files[0])">
            </div>
        </div>

        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($current_user['name']) ?>">
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($current_user['email']) ?>">
        </div>

        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($current_user['phone'] ?? '') ?>">
        </div>

        <?php if ($role === 'customer'): ?>
            <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars($current_user['address'] ?? '') ?>">
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label>City</label>
            <input type="text" name="city" value="<?= htmlspecialchars($current_user['city'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>State</label>
            <input type="text" name="state" value="<?= htmlspecialchars($current_user['state'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Pincode</label>
            <input type="text" name="pincode" value="<?= htmlspecialchars($current_user['pincode'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>New Password (leave blank to keep current)</label>
            <input type="password" name="password" placeholder="••••••••">
        </div>

        <?php if ($role === 'provider'): ?>
            <hr>
            <h3>Provider Service Details</h3>

            <div class="form-group">
                <label>Experience</label>
                <input type="text" name="experience" value="<?= htmlspecialchars($prov['experience'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" value="<?= htmlspecialchars($prov['location'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Bio</label>
                <textarea name="bio" rows="4"><?= htmlspecialchars($prov['bio'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label>Services Offered</label>
                <div class="services-checkbox">
                    <?php while ($s = $services->fetch_assoc()): ?>
                        <div class="service-checkbox">
                            <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" id="s<?= $s['id'] ?>"
                                <?= in_array($s['id'], $service_ids) ? 'checked' : '' ?>>
                            <label for="s<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></label>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn">Update Profile</button>
    </form>

    <a href="index.php" class="back-link">← Back to Home</a>
</div>

<script>
    // Preview image when selected
    document.getElementById('profile_image').addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('profileImagePreview').src = e.target.result;
            }
            reader.readAsDataURL(this.files[0]);
        }
    });
</script>
</body>
</html>
