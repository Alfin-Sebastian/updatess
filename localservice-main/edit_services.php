<?php
session_start();
include 'db.php';

// Authorization check
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Validate service ID
if (!isset($_GET['id'])) {
    header("Location: admin_dashboard.php?error=no_id");
    exit;
}

$service_id = intval($_GET['id']);

// Load service details
$stmt = $conn->prepare("
    SELECT s.*, sc.name AS category_name 
    FROM services s
    LEFT JOIN service_categories sc ON s.category_id = sc.id
    WHERE s.id = ?
");
$stmt->bind_param("i", $service_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: admin_dashboard.php?error=not_found");
    exit;
}
$service = $result->fetch_assoc();

// Get all categories
$categories = [];
$cat_query = $conn->query("SELECT * FROM service_categories ORDER BY name");
if ($cat_query) {
    $categories = $cat_query->fetch_all(MYSQLI_ASSOC);
}

$error = '';
$success = '';

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_service'])) {
    if (!isset($_POST['delete_token']) || $_POST['delete_token'] !== $_SESSION['csrf_token']) {
        $error = "Invalid CSRF token for deletion.";
    } else {
        $delete_stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
        $delete_stmt->bind_param("i", $service_id);
        if ($delete_stmt->execute()) {
            header("Location: admin_services.php?success=deleted");
            exit;
        } else {
            $error = "Failed to delete service: " . $conn->error;
        }
    }
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token'] && !isset($_POST['delete_service'])) {
    $name = trim($_POST['name']);
    $category_id = intval($_POST['category_id']);
    $description = trim($_POST['description']);
    $price = floatval($_POST['price']);
    $duration = intval($_POST['duration']);

    if (empty($name) || empty($category_id) || empty($description) || $price <= 0 || $duration <= 0) {
        $error = "Please fill all fields with valid values";
    } else {
        $update = $conn->prepare("UPDATE services SET name=?, category_id=?, description=?, base_price=?, duration_minutes=? WHERE id=?");
        $update->bind_param("sisdii", $name, $category_id, $description, $price, $duration, $service_id);
        
        if ($update->execute()) {
            $success = "Service updated successfully!";
            $stmt->execute();
            $result = $stmt->get_result();
            $service = $result->fetch_assoc();
        } else {
            $error = "Error updating service: " . $conn->error;
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Service | Admin - UrbanServe</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #f76d2b;
            --success: #38a169;
            --error: #e53e3e;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fa;
            padding: 30px;
        }

        .container {
            max-width: 700px;
            background: white;
            padding: 30px;
            margin: auto;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        h2 {
            margin-bottom: 20px;
            color: #2d3748;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            font-weight: 500;
            display: block;
            margin-bottom: 8px;
        }

        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 15px;
        }

        textarea {
            resize: vertical;
            min-height: 120px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-outline {
            background: white;
            color: var(--error);
            border: 1px solid var(--error);
            margin-top: 15px;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: rgba(56, 161, 105, 0.1);
            color: var(--success);
        }

        .alert-error {
            background: rgba(226, 66, 66, 0.1);
            color: var(--error);
        }
.back-link {
    display: inline-block;
    margin-top: 30px;
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
}
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-edit"></i> Edit Service</h2>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Service Name *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($service['name']) ?>">
            </div>

            <div class="form-group">
                <label>Category *</label>
                <select name="category_id" required>
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($service['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" required><?= htmlspecialchars($service['description']) ?></textarea>
            </div>

            <div class="form-group">
                <label>Base Price (₹) *</label>
                <input type="number" step="0.01" min="1" name="price" required value="<?= htmlspecialchars($service['base_price']) ?>">
            </div>

            <div class="form-group">
                <label>Duration (minutes) *</label>
                <input type="number" min="1" name="duration" required value="<?= htmlspecialchars($service['duration_minutes']) ?>">
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Service</button>
        </form>

        <!-- Delete Button -->
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this service?');">
            <input type="hidden" name="delete_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="delete_service" value="1">
            <button type="submit" class="btn btn-outline"><i class="fas fa-trash-alt"></i> Delete Service</button>
        </form>
<br>

 <a href="service_detail.php?id=<?= $service_id ?>" class="back-link">← Back to Service</a>


    </div>

</body>
</html>

