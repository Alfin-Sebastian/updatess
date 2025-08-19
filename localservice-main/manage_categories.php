<?php
session_start();

// Redirect if not admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

require_once 'db.php';

// Initialize messages
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_category'])) {
        // Add new category
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $icon = trim($_POST['icon']);
        
        if (empty($name)) {
            $error = "Category name is required";
        } else {
            // Check if category already exists
            $stmt = $conn->prepare("SELECT id FROM service_categories WHERE name = ?");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $error = "Category already exists";
            } else {
                $stmt = $conn->prepare("INSERT INTO service_categories (name, description, icon) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $description, $icon);
                
                if ($stmt->execute()) {
                    $message = "Category added successfully";
                } else {
                    $error = "Error adding category: " . $conn->error;
                }
            }
        }
    } elseif (isset($_POST['update_category'])) {
        // Update existing category
        $id = intval($_POST['category_id']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        $icon = trim($_POST['icon']);
        
        if (empty($name)) {
            $error = "Category name is required";
        } else {
            // Check if another category already has this name
            $stmt = $conn->prepare("SELECT id FROM service_categories WHERE name = ? AND id != ?");
            $stmt->bind_param("si", $name, $id);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $error = "Another category already has this name";
            } else {
                $stmt = $conn->prepare("UPDATE service_categories SET name = ?, description = ?, icon = ? WHERE id = ?");
                $stmt->bind_param("sssi", $name, $description, $icon, $id);
                
                if ($stmt->execute()) {
                    $message = "Category updated successfully";
                } else {
                    $error = "Error updating category: " . $conn->error;
                }
            }
        }
    } elseif (isset($_POST['delete_category'])) {
        // Delete category
        $id = intval($_POST['category_id']);
        
        // First check if any services are using this category
        $stmt = $conn->prepare("SELECT COUNT(*) FROM services WHERE category_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($service_count);
        $stmt->fetch();
        $stmt->close();
        
        if ($service_count > 0) {
            $error = "Cannot delete category - there are " . $service_count . " services associated with it";
        } else {
            $stmt = $conn->prepare("DELETE FROM service_categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                $message = "Category deleted successfully";
            } else {
                $error = "Error deleting category: " . $conn->error;
            }
        }
    }
}

// Get all categories with service counts
$categories = $conn->query("
    SELECT sc.*, COUNT(s.id) as service_count 
    FROM service_categories sc
    LEFT JOIN services s ON sc.id = s.category_id
    GROUP BY sc.id
    ORDER BY sc.name ASC
");

// Get category for editing if ID is provided
$edit_category = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM service_categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_category = $result->fetch_assoc();
    $stmt->close();
}

// Get list of available icons (Font Awesome)
$available_icons = [
    'broom', 'tools', 'scissors', 'pipe', 'bolt', 
    'home', 'building', 'user-tie', 'user-md', 'car',
    'tree', 'leaf', 'shower', 'toilet', 'couch',
    'tv', 'laptop', 'mobile', 'wifi', 'plug',
    'lightbulb', 'fan', 'snowflake', 'fire', 'water',
    'shirt', 'tshirt', 'shoe-prints', 'baby-carriage', 'dog',
    'cat', 'paw', 'utensils', 'pizza-slice', 'hamburger',
    'music', 'gamepad', 'book', 'newspaper', 'paint-brush',
    'faucet', 'faucet-drip', 'toilet-paper', 'bath', 'hammer'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Service Categories | Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            color: var(--text);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 24px;
            color: var(--secondary);
        }

        .btn {
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background-color: rgba(247, 109, 43, 0.1);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-danger {
            background-color: var(--error);
            color: white;
        }

        .card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .card h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: var(--secondary);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 5px;
            font-family: inherit;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .table th {
            background-color: var(--accent);
            color: var(--secondary);
            font-weight: 600;
        }

        .table tr:hover {
            background-color: rgba(247, 109, 43, 0.05);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--error);
        }

        .icon-preview {
            font-size: 24px;
            margin-right: 10px;
            color: var(--primary);
        }

        .icon-selector {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .icon-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .icon-option:hover {
            background-color: var(--accent);
            border-color: var(--primary);
        }

        .icon-option.selected {
            background-color: var(--primary);
            color: white;
        }

        .icon-option i {
            font-size: 20px;
            margin-bottom: 5px;
        }

        .service-count {
            background-color: var(--accent);
            color: var(--primary);
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .serial-no {
            color: var(--light-text);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .table th, .table td {
                padding: 8px 10px;
                font-size: 14px;
            }
            
            .icon-selector {
                grid-template-columns: repeat(auto-fill, minmax(50px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Manage Service Categories</h1>
            <a href="admin_dashboard.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2><?= $edit_category ? 'Edit Category' : 'Add New Category' ?></h2>
            <form method="POST">
                <?php if ($edit_category): ?>
                    <input type="hidden" name="category_id" value="<?= $edit_category['id'] ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="name">Category Name *</label>
                    <input type="text" id="name" name="name" class="form-control" 
                           value="<?= $edit_category ? htmlspecialchars($edit_category['name']) : '' ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-control"><?= 
                        $edit_category ? htmlspecialchars($edit_category['description']) : '' ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="icon">Icon</label>
                    <input type="text" id="icon" name="icon" class="form-control" 
                           value="<?= $edit_category ? htmlspecialchars($edit_category['icon']) : '' ?>" 
                           placeholder="Font Awesome icon name (e.g., 'pipe', 'tools')">
                    
                    <?php if ($edit_category && !empty($edit_category['icon'])): ?>
                        <div style="margin-top: 10px;">
                            <span>Current Icon: </span>
                            <i class="fas fa-<?= htmlspecialchars($edit_category['icon']) ?>"></i>
                            (<?= htmlspecialchars($edit_category['icon']) ?>)
                        </div>
                    <?php endif; ?>
                    
                    <div class="icon-selector">
                        <?php foreach ($available_icons as $icon): ?>
                            <div class="icon-option <?= ($edit_category && $edit_category['icon'] === $icon) ? 'selected' : '' ?>" 
                                 onclick="document.getElementById('icon').value = '<?= $icon ?>'; updateSelectedIcon(this)">
                                <i class="fas fa-<?= $icon ?>"></i>
                                <small><?= $icon ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <?php if ($edit_category): ?>
                        <button type="submit" name="update_category" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Category
                        </button>
                        <a href="manage_categories.php" class="btn btn-outline">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    <?php else: ?>
                        <button type="submit" name="add_category" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Existing Categories</h2>
            <?php if ($categories->num_rows > 0): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>SlNo</th>
                            <th>Name</th>
                            <th>Icon</th>
                            <th>Services</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $serial_no = 1;
                        while ($category = $categories->fetch_assoc()): 
                        ?>
                            <tr>
                                <td class="serial-no"><?= $serial_no++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($category['name']) ?></strong>
                                    <?php if (!empty($category['description'])): ?>
                                        <div style="font-size: 12px; color: var(--light-text); margin-top: 5px;">
                                            <?= htmlspecialchars(substr($category['description'], 0, 50)) ?>
                                            <?= strlen($category['description']) > 50 ? '...' : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($category['icon'])): ?>
                                        <i class="fas fa-<?= htmlspecialchars($category['icon']) ?>" title="<?= htmlspecialchars($category['icon']) ?>"></i>
                                    <?php else: ?>
                                        <span style="color: var(--light-text);">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="service-count"><?= $category['service_count'] ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="manage_categories.php?edit=<?= $category['id'] ?>" class="btn btn-outline">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="category_id" value="<?= $category['id'] ?>">
                                            <button type="submit" name="delete_category" class="btn btn-danger" 
                                                    onclick="return confirm('Are you sure you want to delete this category?')">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No categories found. Add your first category above.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function updateSelectedIcon(clickedElement) {
            // Remove selected class from all icons
            document.querySelectorAll('.icon-option').forEach(icon => {
                icon.classList.remove('selected');
            });
            
            // Add selected class to clicked icon
            clickedElement.classList.add('selected');
        }
    </script>
</body>
</html>