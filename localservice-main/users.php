<?php
session_start();

// Validate admin role
if (empty($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php';

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error'] = "Invalid request";
        header("Location: users.php");
        exit;
    }

    // Handle user status update
    if (isset($_POST['update_user'])) {
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $new_role = in_array($_POST['role'], ['admin', 'provider', 'customer']) ? $_POST['role'] : 'customer';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($user_id) {
            $stmt = $conn->prepare("UPDATE users SET role = ?, is_active = ? WHERE id = ?");
            $stmt->bind_param("sii", $new_role, $is_active, $user_id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = "User updated successfully";
                
                // If changing to/from provider, update providers table
                if ($new_role === 'provider') {
                    $conn->query("INSERT IGNORE INTO providers (user_id) VALUES ($user_id)");
                } else {
                    $conn->query("DELETE FROM providers WHERE user_id = $user_id");
                }
            } else {
                $_SESSION['error'] = "Error updating user";
                error_log("User update error: " . $conn->error);
            }
        }
    }
    
    // Handle user deletion
    if (isset($_POST['delete_user'])) {
        $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        
        if ($user_id) {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Delete from dependent tables first
                $conn->query("DELETE FROM providers WHERE user_id = $user_id");
                $conn->query("DELETE FROM bookings WHERE user_id = $user_id OR provider_id = $user_id");
                $conn->query("DELETE FROM reviews WHERE user_id = $user_id");
                
                // Then delete the user
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                
                $conn->commit();
                $_SESSION['message'] = "User deleted successfully";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Error deleting user";
                error_log("User deletion error: " . $e->getMessage());
            }
        }
    }
    
    header("Location: users.php");
    exit;
}

// Handle filters
$filter_role = isset($_GET['role']) && in_array($_GET['role'], ['admin', 'provider', 'customer']) 
             ? $_GET['role'] 
             : null;
$filter_status = isset($_GET['status']) && $_GET['status'] === 'inactive' ? 0 : 1;

// Build query with prepared statements
$query = "
    SELECT 
        u.id, u.name, u.email, u.phone, u.role, u.is_active, u.created_at,
        p.avg_rating,
        (SELECT COUNT(*) FROM bookings WHERE user_id = u.id) as booking_count,
        (SELECT COUNT(*) FROM bookings WHERE provider_id = u.id) as service_count
    FROM users u
    LEFT JOIN providers p ON u.id = p.user_id
";

$where = [];
$params = [];
$types = "";

if ($filter_role) {
    $where[] = "u.role = ?";
    $params[] = $filter_role;
    $types .= "s";
}

$where[] = "u.is_active = ?";
$params[] = $filter_status;
$types .= "i";

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " ORDER BY u.created_at DESC";

// Execute query with prepared statement
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();

// Get counts for dashboard
$counts = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(role = 'admin') as admins,
        SUM(role = 'provider') as providers,
        SUM(role = 'customer') as customers,
        SUM(is_active = 0) as inactive
    FROM users
")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users | UrbanServe</title>
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
            --success: #38a169;
            --warning: #dd6b20;
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

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
        }

        /* Dashboard Cards */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card h3 {
            font-size: 14px;
            color: var(--light-text);
            margin-bottom: 10px;
        }

        .card p {
            font-size: 24px;
            font-weight: 700;
            color: var(--secondary);
        }

        /* Filter Controls */
        .filter-controls {
            background-color: var(--white);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--secondary);
        }

        select, input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
            background-color: var(--white);
        }

        /* Table Styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background-color: var(--white);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .data-table th {
            background-color: var(--accent);
            color: var(--secondary);
            font-weight: 600;
        }

        .data-table tr:hover {
            background-color: rgba(247, 109, 43, 0.05);
        }

        /* User Management Specific Styles */
        .user-role {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .role-admin { background-color: rgba(109, 40, 217, 0.1); color: #6d28d9; }
        .role-provider { background-color: rgba(56, 161, 105, 0.1); color: var(--success); }
        .role-customer { background-color: rgba(66, 153, 225, 0.1); color: #4299e1; }
        
        .status-active { color: var(--success); }
        .status-inactive { color: var(--error); }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 13px;
        }
        
        .user-details {
            display: none;
            padding: 10px;
            background-color: var(--accent);
            border-radius: 5px;
            margin-top: 10px;
        }

        /* Toggle Switch Styles */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--success);
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .data-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-controls {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Manage Users</h1>
        <div>
            <a href="admin_dashboard.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($_SESSION['message']) ?>
            <?php unset($_SESSION['message']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($_SESSION['error']) ?>
            <?php unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Filter Controls -->
    <form method="GET" class="filter-controls">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="filter-group">
            <label for="role">Role:</label>
            <select name="role" id="role">
                <option value="">All Roles</option>
                <option value="admin" <?= $filter_role === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="provider" <?= $filter_role === 'provider' ? 'selected' : '' ?>>Provider</option>
                <option value="customer" <?= $filter_role === 'customer' ? 'selected' : '' ?>>Customer</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="status">Status:</label>
            <select name="status" id="status">
                <option value="active" <?= $filter_status ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= !$filter_status ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Apply Filters
        </button>
        <a href="users.php" class="btn btn-outline">
            <i class="fas fa-times"></i> Clear Filters
        </a>
    </form>

    <!-- User Stats -->
    <div class="dashboard-cards">
        <div class="card" onclick="filterByRole('')">
            <h3>Total Users</h3>
            <p><?= $counts['total'] ?></p>
        </div>
        <div class="card" onclick="filterByRole('admin')">
            <h3>Admins</h3>
            <p><?= $counts['admins'] ?></p>
        </div>
        <div class="card" onclick="filterByRole('provider')">
            <h3>Providers</h3>
            <p><?= $counts['providers'] ?></p>
        </div>
        <div class="card" onclick="filterByRole('customer')">
            <h3>Customers</h3>
            <p><?= $counts['customers'] ?></p>
        </div>
        <div class="card" onclick="filterByStatus('inactive')">
            <h3>Inactive</h3>
            <p><?= $counts['inactive'] ?></p>
        </div>
    </div>

    <!-- Users Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Bookings</th>
                <th>Services</th>
                <th>Rating</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($user = $users->fetch_assoc()): ?>
            <tr>
                <td>#<?= $user['id'] ?></td>
                <td>
                    <?= htmlspecialchars($user['name']) ?>
                    <button onclick="toggleDetails(<?= $user['id'] ?>)" class="btn-sm btn-outline" style="margin-left:5px;">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div id="details-<?= $user['id'] ?>" class="user-details">
                        <p><strong>Phone:</strong> <?= htmlspecialchars($user['phone']) ?></p>
                        <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
                    </div>
                </td>
                <td><?= htmlspecialchars($user['email']) ?></td>
                <td>
                    <span class="user-role role-<?= $user['role'] ?>">
                        <?= ucfirst($user['role']) ?>
                    </span>
                </td>
                <td class="status-<?= $user['is_active'] ? 'active' : 'inactive' ?>">
                    <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                </td>
                <td><?= $user['booking_count'] ?></td>
                <td><?= $user['service_count'] ?? 0 ?></td>
                <td>
                    <?= $user['avg_rating'] ? number_format($user['avg_rating'], 1) . '/5' : 'N/A' ?>
                </td>
                <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                <td>
                    <div class="action-buttons">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <select name="role" style="padding: 5px; border-radius: 4px; margin-bottom: 5px;">
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="provider" <?= $user['role'] === 'provider' ? 'selected' : '' ?>>Provider</option>
                                <option value="customer" <?= $user['role'] === 'customer' ? 'selected' : '' ?>>Customer</option>
                            </select>
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:5px;">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="is_active" <?= $user['is_active'] ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                                <span><?= $user['is_active'] ? 'Active' : 'Inactive' ?></span>
                            </div>
                            <button type="submit" name="update_user" class="btn-primary btn-sm">
                                <i class="fas fa-save"></i> Update
                            </button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <button type="submit" name="delete_user" class="btn btn-outline btn-sm" 
                                    onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <script>
        // Toggle user details
        function toggleDetails(userId) {
            const details = document.getElementById(`details-${userId}`);
            details.style.display = details.style.display === 'block' ? 'none' : 'block';
            
            // Toggle chevron icon
            const button = details.previousElementSibling;
            const icon = button.querySelector('i');
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up');
        }

        // Filter by role when clicking on stats cards
        function filterByRole(role) {
            const url = new URL(window.location.href);
            if (role) {
                url.searchParams.set('role', role);
            } else {
                url.searchParams.delete('role');
            }
            // Reset status filter when changing role
            url.searchParams.delete('status');
            window.location.href = url.toString();
        }

        // Filter by status when clicking on stats cards
        function filterByStatus(status) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.location.href = url.toString();
        }

        // Initialize details toggles
        document.addEventListener('DOMContentLoaded', function() {
            // Close all details by default
            document.querySelectorAll('.user-details').forEach(detail => {
                detail.style.display = 'none';
            });
        });
    </script>
</body>
</html>