<?php
include 'db.php';
session_start();

// ✅ Allow both admin and provider roles
$allowedRoles = ['admin', 'provider'];
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], $allowedRoles)) {
    header("Location: login.php");
    exit;
}

// Initialize variables
$error = '';
$success = '';
$categories = [];
$current_user_id = $_SESSION['user']['id'];
$is_provider = ($_SESSION['user']['role'] === 'provider');

// Fetch categories from database
$category_query = $conn->query("SELECT * FROM service_categories ORDER BY name");
if ($category_query) {
    $categories = $category_query->fetch_all(MYSQLI_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token'])) {
        $error = "Invalid form submission";
    } else {
        $name = trim($_POST['name']);
        $category_id = intval($_POST['category_id']);
        $description = trim($_POST['description']);
        $price = floatval($_POST['price']);
        $duration = intval($_POST['duration']);

        // Validate inputs
        if (empty($name) || empty($category_id) || empty($description) || $price <= 0 || $duration <= 0) {
            $error = "Please fill all fields with valid values";
        } else {
            // Handle file upload
            $image_path = null;
            if (isset($_FILES['service_image']) && $_FILES['service_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/services/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                // Validate file
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $_FILES['service_image']['tmp_name']);
                finfo_close($file_info);

                if (!in_array($mime_type, $allowed_types)) {
                    $error = "Only JPG, PNG, and GIF images are allowed";
                } elseif ($_FILES['service_image']['size'] > 5 * 1024 * 1024) { // 5MB limit
                    $error = "Image size must be less than 5MB";
                } else {
                    // Generate unique filename
                    $extension = pathinfo($_FILES['service_image']['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('service_') . '.' . $extension;
                    $destination = $upload_dir . $filename;

                    if (move_uploaded_file($_FILES['service_image']['tmp_name'], $destination)) {
                        $image_path = $destination;
                    } else {
                        $error = "Failed to upload image";
                    }
                }
            }

            if (!$error) {
                // Begin transaction
                $conn->begin_transaction();

                try {
                    // Add service
                    $stmt = $conn->prepare("INSERT INTO services (name, category_id, description, base_price, duration_minutes, image) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sisdis", $name, $category_id, $description, $price, $duration, $image_path);

                    if (!$stmt->execute()) {
                        throw new Exception("Error adding service: " . $stmt->error);
                    }

                    $service_id = $conn->insert_id;

                    // If provider, link to profile
                    if ($is_provider) {
                        $check_provider = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'provider'");
                        $check_provider->bind_param("i", $current_user_id);
                        $check_provider->execute();
                        $check_result = $check_provider->get_result();

                        if ($check_result->num_rows === 0) {
                            throw new Exception("Provider account not found!");
                        }

                        $provider_price = isset($_POST['provider_price']) ? floatval($_POST['provider_price']) : $price;

                        $stmt = $conn->prepare("INSERT INTO provider_services (provider_id, service_id, price) VALUES (?, ?, ?)");
                        $stmt->bind_param("iid", $current_user_id, $service_id, $provider_price);

                        if (!$stmt->execute()) {
                            throw new Exception("Error linking service to provider: " . $stmt->error);
                        }
                    }

                    $conn->commit();
                    $success = $is_provider 
                        ? "Service added successfully and linked to your profile!" 
                        : "Service added successfully!";

                } catch (Exception $e) {
                    $conn->rollback();
                    // Delete uploaded file if transaction failed
                    if ($image_path && file_exists($image_path)) {
                        unlink($image_path);
                    }
                    $error = $e->getMessage();
                }
            }
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $is_provider ? 'Provider' : 'Admin' ?> Add Service | UrbanServe</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h1 { font-size: 24px; margin-bottom: 20px; color: #333; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; }
        input, textarea, select { width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #ccc; }
        .btn { background: #4CAF50; color: white; padding: 12px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #45a049; }
        .alert { padding: 12px; border-radius: 5px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .back-link { display: inline-block; margin-top: 20px; color: #007BFF; text-decoration: none; }
        .provider-price-field small { display: block; margin-top: 4px; color: #777; }
        .image-preview { max-width: 200px; max-height: 200px; margin-top: 10px; display: none; }
        .file-input-wrapper { position: relative; overflow: hidden; display: inline-block; width: 100%; }
        .file-input-wrapper input[type=file] { position: absolute; left: 0; top: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; }
        .file-input-label { display: block; padding: 10px; background: #f0f0f0; border: 1px dashed #ccc; border-radius: 6px; text-align: center; cursor: pointer; }
        .file-input-label:hover { background: #e0e0e0; }
    </style>
</head>
<body>
<div class="container">
    <h1>Add New Service</h1>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-group">
            <label for="name">Service Name *</label>
            <input type="text" id="name" name="name" required value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
        </div>

        <div class="form-group">
            <label for="category_id">Category *</label>
            <select id="category_id" name="category_id" required>
                <option value="">Select a category</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= $category['id'] ?>" <?= (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="description">Description *</label>
            <textarea id="description" name="description" required><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
        </div>

        <div class="form-group">
            <label for="price">Base Price (₹) *</label>
            <input type="number" id="price" name="price" min="0.01" step="0.01" required value="<?= isset($_POST['price']) ? htmlspecialchars($_POST['price']) : '' ?>">
        </div>

        <div class="form-group">
            <label for="duration">Duration (minutes) *</label>
            <input type="number" id="duration" name="duration" min="1" required value="<?= isset($_POST['duration']) ? htmlspecialchars($_POST['duration']) : '' ?>">
        </div>

        <div class="form-group">
            <label>Service Image</label>
            <div class="file-input-wrapper">
                <label class="file-input-label" id="fileInputLabel">
                    <i class="fas fa-cloud-upload-alt"></i> Choose an image (JPG, PNG, GIF - max 5MB)
                    <input type="file" id="service_image" name="service_image" accept="image/*" onchange="previewImage(this)">
                </label>
            </div>
            <img id="imagePreview" class="image-preview" alt="Image preview">
        </div>

        <?php if ($is_provider): ?>
            <div class="form-group provider-price-field">
                <label for="provider_price">Your Price (₹)</label>
                <input type="number" id="provider_price" name="provider_price" min="0.01" step="0.01" value="<?= isset($_POST['provider_price']) ? htmlspecialchars($_POST['provider_price']) : '' ?>">
                <small>Leave blank to use base price</small>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn">Add Service</button>
    </form>

    <a href="<?= $is_provider ? 'provider_dashboard.php' : 'admin_dashboard.php' ?>" class="back-link">
        ← Back to Dashboard
    </a>
</div>

<script>
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');
        const label = document.getElementById('fileInputLabel');
        
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
                label.innerHTML = '<i class="fas fa-check-circle"></i> ' + input.files[0].name;
            }
            
            reader.readAsDataURL(input.files[0]);
        }
    }

    document.getElementById('category_id')?.addEventListener('change', function() {
        const providerField = document.querySelector('.provider-price-field');
        if (providerField) {
            providerField.style.display = this.value ? 'block' : 'none';
        }
    });
</script>
</body>
</html>