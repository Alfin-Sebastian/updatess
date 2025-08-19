<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'provider') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user']['id'];
$message = '';
$message_type = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle service updates
    if ($_POST['action'] === 'update') {
        $service_id = intval($_POST['service_id']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $base_price = floatval($_POST['base_price']);
        $duration_minutes = intval($_POST['duration_minutes']);
        $category_id = intval($_POST['category_id']);
        
        // Basic validation
        if (empty($name) || empty($description) || $base_price <= 0 || $duration_minutes <= 0) {
            $message = 'Please fill all required fields with valid values';
            $message_type = 'error';
        } else {
            // Check if the provider owns this service
            $stmt = $conn->prepare("
                SELECT 1 FROM provider_services 
                WHERE service_id = ? AND provider_id = ?
            ");
            $stmt->bind_param("ii", $service_id, $user_id);
            $stmt->execute();
            
            if ($stmt->get_result()->num_rows > 0) {
                // Update the service
                $stmt = $conn->prepare("
                    UPDATE services 
                    SET name = ?, description = ?, base_price = ?, 
                        duration_minutes = ?, category_id = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ssdiii", $name, $description, $base_price, 
                                  $duration_minutes, $category_id, $service_id);
                
                if ($stmt->execute()) {
                    $message = 'Service updated successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error updating service: ' . $conn->error;
                    $message_type = 'error';
                }
            } else {
                $message = 'You are not authorized to edit this service';
                $message_type = 'error';
            }
        }
    }

    // Handle service deletion
    if ($_POST['action'] === 'delete') {
        $service_id = intval($_POST['service_id']);

        // Check if the provider owns this service
        $stmt = $conn->prepare("
            SELECT 1 FROM provider_services 
            WHERE service_id = ? AND provider_id = ?
        ");
        $stmt->bind_param("ii", $service_id, $user_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            // First delete service images
            $conn->query("DELETE FROM service_images WHERE service_id = $service_id");
            
            // Then delete from provider_services
            $stmt = $conn->prepare("
                DELETE FROM provider_services 
                WHERE service_id = ? AND provider_id = ?
            ");
            $stmt->bind_param("ii", $service_id, $user_id);
            
            if ($stmt->execute()) {
                // Check if any other providers offer this service
                $stmt = $conn->prepare("
                    SELECT 1 FROM provider_services 
                    WHERE service_id = ?
                ");
                $stmt->bind_param("i", $service_id);
                $stmt->execute();
                
                if ($stmt->get_result()->num_rows === 0) {
                    // No other providers offer this service, delete it completely
                    $conn->query("DELETE FROM services WHERE id = $service_id");
                }
                
                $message = 'Service removed successfully!';
                $message_type = 'success';
            } else {
                $message = 'Error removing service: ' . $conn->error;
                $message_type = 'error';
            }
        } else {
            $message = 'You are not authorized to delete this service';
            $message_type = 'error';
        }
    }

    // Handle image uploads
    if ($_POST['action'] === 'upload_image' && !empty($_FILES['image'])) {
        $service_id = intval($_POST['service_id']);
        
        // Verify ownership
        $stmt = $conn->prepare("
            SELECT 1 FROM provider_services 
            WHERE service_id = ? AND provider_id = ?
        ");
        $stmt->bind_param("ii", $service_id, $user_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $target_dir = "uploads/services/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $file_extension;
            $target_file = $target_dir . $filename;
            
            // Basic image validation
            $check = getimagesize($_FILES['image']['tmp_name']);
            if ($check !== false) {
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    // Save to database
                    $stmt = $conn->prepare("
                        INSERT INTO service_images (service_id, image_url) 
                        VALUES (?, ?)
                    ");
                    $image_url = $target_file;
                    $stmt->bind_param("is", $service_id, $image_url);
                    
                    if ($stmt->execute()) {
                        $message = 'Image uploaded successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Error saving image: ' . $conn->error;
                        $message_type = 'error';
                    }
                } else {
                    $message = 'Sorry, there was an error uploading your file.';
                    $message_type = 'error';
                }
            } else {
                $message = 'File is not an image.';
                $message_type = 'error';
            }
        } else {
            $message = 'You are not authorized to add images to this service';
            $message_type = 'error';
        }
    }

    // Handle image deletion
    if ($_POST['action'] === 'delete_image') {
        $image_id = intval($_POST['image_id']);
        $service_id = intval($_POST['service_id']);
        
        // Verify ownership
        $stmt = $conn->prepare("
            SELECT 1 FROM provider_services 
            WHERE service_id = ? AND provider_id = ?
        ");
        $stmt->bind_param("ii", $service_id, $user_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            // Get image path first
            $stmt = $conn->prepare("
                SELECT image_url FROM service_images 
                WHERE id = ? AND service_id = ?
            ");
            $stmt->bind_param("ii", $image_id, $service_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $image = $result->fetch_assoc();
                // Delete file from server
                if (file_exists($image['image_url'])) {
                    unlink($image['image_url']);
                }
                
                // Delete from database
                $stmt = $conn->prepare("
                    DELETE FROM service_images 
                    WHERE id = ? AND service_id = ?
                ");
                $stmt->bind_param("ii", $image_id, $service_id);
                
                if ($stmt->execute()) {
                    $message = 'Image deleted successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error deleting image: ' . $conn->error;
                    $message_type = 'error';
                }
            } else {
                $message = 'Image not found';
                $message_type = 'error';
            }
        } else {
            $message = 'You are not authorized to delete images from this service';
            $message_type = 'error';
        }
    }
}

// Fetch service categories for dropdown
$categories = $conn->query("SELECT id, name FROM service_categories");

// Fetch provider's current services with full details
$sql = "
    SELECT 
        s.id, s.name, s.description, s.base_price, 
        s.duration_minutes, s.category_id, sc.name AS category_name
    FROM services s
    JOIN provider_services ps ON s.id = ps.service_id
    LEFT JOIN service_categories sc ON s.category_id = sc.id
    WHERE ps.provider_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$services = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage My Services | UrbanServe Provider</title>
    <style>
        :root {
            --primary: #f76d2b;
            --primary-dark: #e05b1a;
            --secondary: #2d3748;
            --accent: #f0f4f8;
            --text: #2d3748;
            --light-text: #718096;
            --border: #e2e8f0;
            --white: #ffffff;
            --black: #000000;
            --success: #38a169;
            --error: #e53e3e;
            --warning: #dd6b20;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            color: var(--text);
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f8f9fa;
        }

        .container {
            width: 90%;
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px 0;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        h2 {
            font-size: 28px;
            color: var(--secondary);
            margin: 0;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-success {
            background-color: rgba(56, 161, 105, 0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background-color: rgba(229, 62, 62, 0.1);
            color: var(--error);
            border-left: 4px solid var(--error);
        }

        .service-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .service-item {
            background-color: var(--white);
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .service-form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group-full {
            grid-column: span 2;
        }

        label {
            font-size: 14px;
            color: var(--light-text);
            font-weight: 500;
        }

        input, select, textarea {
            padding: 12px 15px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 15px;
            color: var(--text);
            background-color: var(--white);
            transition: border-color 0.2s;
            width: 100%;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-actions {
            grid-column: span 2;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 15px;
        }

        .btn {
            padding: 12px 20px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-danger {
            background-color: var(--error);
            color: var(--white);
        }

        .btn-danger:hover {
            background-color: #c53030;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 30px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
            padding: 10px 15px;
            border-radius: 6px;
        }

        .back-link:hover {
            color: var(--primary-dark);
            background-color: rgba(247, 109, 43, 0.1);
            text-decoration: none;
        }

        .empty-state {
            background-color: var(--white);
            padding: 40px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .empty-state p {
            color: var(--light-text);
            margin-bottom: 20px;
        }

        .add-service-btn {
            margin-top: 30px;
        }

        .image-upload-section {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid var(--border);
        }

        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .image-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            height: 150px;
        }

        .image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-actions {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            padding: 8px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .image-btn {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 14px;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .image-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .image-btn.delete {
            color: #ff6b6b;
        }

        .upload-form {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .upload-form input[type="file"] {
            flex: 1;
            padding: 10px;
            border: 1px dashed var(--border);
            border-radius: 6px;
        }

        @media (max-width: 768px) {
            .service-form {
                grid-template-columns: 1fr;
            }
            
            .form-group-full, .form-actions {
                grid-column: span 1;
            }

            .image-gallery {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }

            .upload-form {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Manage My Services</h2>
            <a href="add_service.php" class="btn btn-primary">Add New Service</a>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?>">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <?php if ($message_type === 'success'): ?>
                        <path d="M10 0C4.48 0 0 4.48 0 10C0 15.52 4.48 20 10 20C15.52 20 20 15.52 20 10C20 4.48 15.52 0 10 0ZM8 15L3 10L4.41 8.59L8 12.17L15.59 4.58L17 6L8 15Z" fill="<?= $message_type === 'success' ? '#38A169' : '#E53E3E' ?>"/>
                    <?php else: ?>
                        <path d="M10 0C4.48 0 0 4.48 0 10C0 15.52 4.48 20 10 20C15.52 20 20 15.52 20 10C20 4.48 15.52 0 10 0ZM11 15H9V13H11V15ZM11 11H9V5H11V11Z" fill="<?= $message_type === 'success' ? '#38A169' : '#E53E3E' ?>"/>
                    <?php endif; ?>
                </svg>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($services->num_rows > 0): ?>
            <ul class="service-list">
                <?php while ($service = $services->fetch_assoc()): 
                    // Fetch images for this service
                    $stmt = $conn->prepare("
                        SELECT id, image_url 
                        FROM service_images 
                        WHERE service_id = ?
                        ORDER BY id ASC
                    ");
                    $stmt->bind_param("i", $service['id']);
                    $stmt->execute();
                    $images = $stmt->get_result();
                ?>
                    <li class="service-item">
                        <form method="POST" class="service-form">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="service_id" value="<?= $service['id'] ?>">
                            
                            <div class="form-group">
                                <label for="name">Service Name</label>
                                <input type="text" id="name" name="name" value="<?= htmlspecialchars($service['name']) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="category_id">Category</label>
                                <select id="category_id" name="category_id" required>
                                    <?php while ($category = $categories->fetch_assoc()): ?>
                                        <option value="<?= $category['id'] ?>" <?= ($category['id'] == $service['category_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                    <?php $categories->data_seek(0); // Reset pointer for next iteration ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="base_price">Price (₹)</label>
                                <input type="number" id="base_price" name="base_price" step="0.01" min="0" 
                                       value="<?= $service['base_price'] ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="duration_minutes">Duration (minutes)</label>
                                <input type="number" id="duration_minutes" name="duration_minutes" min="15" step="15" 
                                       value="<?= $service['duration_minutes'] ?>" required>
                            </div>
                            
                            <div class="form-group form-group-full">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" required><?= htmlspecialchars($service['description']) ?></textarea>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Update Service</button>
                                
                                <button type="button" onclick="
                                    if(confirm('Are you sure you want to delete this service? All bookings and data will be lost.')) {
                                        this.form.action.value = 'delete';
                                        this.form.submit();
                                    }
                                " class="btn btn-danger">
                                    Delete Service
                                </button>
                            </div>
                        </form>

                        <!-- Image Management Section -->
                        <div class="image-upload-section">
                            <h3>Service Images</h3>
                            
                            <?php if ($images->num_rows > 0): ?>
                                <div class="image-gallery">
                                    <?php while ($image = $images->fetch_assoc()): ?>
                                        <div class="image-item">
                                            <img src="<?= htmlspecialchars($image['image_url']) ?>" alt="Service Image">
                                            <div class="image-actions">
                                                <button class="image-btn delete" onclick="
                                                    if(confirm('Delete this image?')) {
                                                        const form = document.createElement('form');
                                                        form.method = 'POST';
                                                        form.innerHTML = `
                                                            <input type='hidden' name='action' value='delete_image'>
                                                            <input type='hidden' name='image_id' value='<?= $image['id'] ?>'>
                                                            <input type='hidden' name='service_id' value='<?= $service['id'] ?>'>
                                                        `;
                                                        document.body.appendChild(form);
                                                        form.submit();
                                                    }
                                                ">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <p>No images uploaded yet for this service.</p>
                            <?php endif; ?>
                            
                            <form method="POST" enctype="multipart/form-data" class="upload-form">
                                <input type="hidden" name="action" value="upload_image">
                                <input type="hidden" name="service_id" value="<?= $service['id'] ?>">
                                <input type="file" name="image" accept="image/*" required>
                                <button type="submit" class="btn btn-primary">Upload Image</button>
                            </form>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <div class="empty-state">
                <p>You are not offering any services yet.</p>
                <a href="add_service.php" class="btn btn-primary">Add Your First Service</a>
            </div>
        <?php endif; ?>

    
            <a href="provider_dashboard.php" class="back-link">← Back to Dashboard</a>

    </div>

    <script>
        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>